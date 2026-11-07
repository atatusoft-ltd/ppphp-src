<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Interop\Php\Signature;

use Atatusoft\Ppphp\Support\CanonicalJson;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;

/**
 * @phpstan-type SymbolLocation array{availability: string|null, document: string, module: string, name: string}
 * @phpstan-type SymbolIndex array{classes: array<string, list<SymbolLocation>>, functions: array<string, list<SymbolLocation>>, constants: array<string, list<SymbolLocation>>}
 */
final readonly class PhpSignaturePackageVerifier
{
    /** Manifests of shipped packages that pass the full structural audit. */
    public const array REVIEWED_MANIFESTS = [
        '8.4' => 'ecf9a0a52df05f8709ef2360653bb4a92e0c5a0aabd19da1c0b5c66b197f7ba3',
    ];

    private const array LEGACY_REVIEWED_MANIFESTS = [
        '8.4' => [
            'cba33a3fff60a74e6f6b7a40ac7f914ae014a1c9df8fec555be3e522448d87ff',
            'fc8acea749a472694be41c0d2a61a1778f6f127ecbfcabc0c00efa90a01acec2',
            'c36db35fbc15be24a03fe88fce73c5fcf9c03bfe9037465a7443c83511e225d3',
        ],
    ];

    public function __construct(private ParserFactory $parsers = new ParserFactory()) {}

    /** @return array<string, mixed> */
    public function verify(string $packageDirectory, string $expectedTarget = '8.4'): array
    {
        return $this->inspect($packageDirectory, $expectedTarget, [])->manifest;
    }

    /** @return array<string, mixed> */
    public function verifyInstalled(string $packageDirectory, string $expectedTarget): array
    {
        return $this->verifyInstalledPackage($packageDirectory, $expectedTarget)->manifest;
    }

    public function verifyInstalledPackage(string $packageDirectory, string $expectedTarget): PhpSignaturePackageVerification
    {
        return $this->inspect($packageDirectory, $expectedTarget, [
            self::REVIEWED_MANIFESTS[$expectedTarget] ?? null,
            ...(self::LEGACY_REVIEWED_MANIFESTS[$expectedTarget] ?? []),
        ]);
    }

    /** @param list<string|null> $reviewedDigests */
    private function inspect(string $packageDirectory, string $expectedTarget, array $reviewedDigests): PhpSignaturePackageVerification
    {
        $root = realpath($packageDirectory);

        if ($root === false || !is_dir($root) || is_link($packageDirectory)) {
            throw new \RuntimeException('The PHP signature package directory is unavailable.');
        }

        $root = rtrim(str_replace('\\', '/', $root), '/');
        $manifestPath = $root . '/manifest.json';
        $manifestBytes = $this->read($manifestPath);
        $manifest = $this->decode($manifestBytes, $manifestPath);
        $digests = ['manifest.json' => hash('sha256', $manifestBytes)];
        $reviewed = in_array($digests['manifest.json'], $reviewedDigests, true);
        $this->requireInteger($manifest, 'formatVersion', PhpSignaturePackageGenerator::FORMAT_VERSION);
        $this->requireString($manifest, 'generatorVersion', PhpSignaturePackageGenerator::GENERATOR_VERSION);
        $this->requireString($manifest, 'packageVersion', PhpSignaturePackageGenerator::PACKAGE_VERSION);
        $this->requireString($manifest, 'targetPhpVersion', $expectedTarget);

        $upstream = $manifest['upstream'] ?? null;

        if (!is_array($upstream)
            || ($upstream['repository'] ?? null) !== 'php/php-src'
            || !is_string($upstream['tag'] ?? null)
            || !is_string($upstream['commit'] ?? null)
            || preg_match('/^[0-9a-f]{40}$/', $upstream['commit']) !== 1) {
            throw new \RuntimeException('The PHP signature manifest has invalid upstream provenance.');
        }

        $this->assertPortable($manifest);
        $outputs = $manifest['outputs'] ?? null;

        if (!is_array($outputs) || !array_is_list($outputs) || $outputs === []) {
            throw new \RuntimeException('The PHP signature manifest has no ordered output list.');
        }

        $previousOutput = null;
        /** @var list<array{string, string}> $shards */
        $shards = [];

        foreach ($outputs as $output) {
            if (!is_array($output)) {
                throw new \RuntimeException('A PHP signature output record is malformed.');
            }

            $path = $this->relativePath($output['path'] ?? null);

            if ($previousOutput !== null && $path <= $previousOutput) {
                throw new \RuntimeException('PHP signature outputs are not in deterministic path order.');
            }

            $previousOutput = $path;
            $digest = @hash_file('sha256', $root . '/' . $path);

            if ($digest === false || $digest !== ($output['sha256'] ?? null)) {
                throw new \RuntimeException(sprintf('PHP signature output "%s" is missing or has been modified.', $path));
            }

            $digests[$path] = $digest;

            if (isset($output['module'])) {
                if (!is_string($output['module']) || $path !== 'extensions/' . $output['module'] . '.json') {
                    throw new \RuntimeException(sprintf('PHP signature shard "%s" has invalid module ownership.', $path));
                }

                $shards[] = [$output['module'], $path];
            }
        }

        // The exact reviewed manifest binds the contents of every output.
        // Runtime loads still check all bytes before using its symbol index.
        if ($reviewed) {
            return new PhpSignaturePackageVerification($manifest, $digests);
        }

        $hasModuleReferences = isset($digests[PhpSignatureModuleReferences::PATH]);
        $phpDoc = isset($digests[PhpSignaturePhpDocIndex::PATH]) ? new PhpSignaturePhpDocIndex() : null;
        $syntax = isset($digests[PhpSignatureSyntaxIndex::PATH]) ? new PhpSignatureSyntaxIndex() : null;
        $requiresDerivedData = $hasModuleReferences || $phpDoc !== null || $syntax !== null;
        /** @var array<string, list<array{path: string, source: string}>> $moduleDocuments */
        $moduleDocuments = [];
        $inputs = $this->inputHashes($manifest['inputs'] ?? null);
        $counts = $this->emptyCounts();
        $directiveAudit = [];
        /** @var SymbolIndex $index */
        $index = ['classes' => [], 'functions' => [], 'constants' => []];
        /** @var list<array{declaration: string, target: string, kind: string}> $aliases */
        $aliases = [];
        /** @var array<string, true> $members */
        $members = [];

        foreach ($shards as [$module, $path]) {
            $shard = $this->json($root . '/' . $path);
            $this->requireInteger($shard, 'formatVersion', PhpSignaturePackageGenerator::FORMAT_VERSION);
            $this->requireString($shard, 'targetPhpVersion', $expectedTarget);
            $this->requireString($shard, 'module', $module);
            $documents = $shard['documents'] ?? null;

            if (!is_array($documents) || !array_is_list($documents)) {
                throw new \RuntimeException(sprintf('PHP signature shard "%s" has invalid documents.', $path));
            }

            $previousDocument = null;
            if ($requiresDerivedData) {
                $moduleDocuments[$module] = [];
            }

            foreach ($documents as $document) {
                if (!is_array($document)) {
                    throw new \RuntimeException(sprintf('PHP signature shard "%s" contains a malformed document.', $path));
                }

                $documentPath = $this->relativePath($document['path'] ?? null);

                if ($previousDocument !== null && $documentPath <= $previousDocument) {
                    throw new \RuntimeException(sprintf('Documents in PHP signature shard "%s" are not ordered.', $path));
                }

                $previousDocument = $documentPath;

                if (($document['sha256'] ?? null) !== ($inputs[$documentPath] ?? null)) {
                    throw new \RuntimeException(sprintf('PHP signature input identity for "%s" is inconsistent.', $documentPath));
                }

                $source = $document['source'] ?? null;

                if (!is_string($source) || !str_ends_with($source, "\n")) {
                    throw new \RuntimeException(sprintf('Normalized PHP signature source "%s" is invalid.', $documentPath));
                }

                if ($requiresDerivedData) {
                    $moduleDocuments[$module][] = ['path' => $documentPath, 'source' => $source];
                }
                try {
                    $statements = $this->parsers
                        ->createForVersion(PhpVersion::fromString($expectedTarget))
                        ->parse($source);
                } catch (\Throwable $exception) {
                    throw new \RuntimeException(sprintf(
                        'Normalized PHP signature source "%s" could not be parsed: %s',
                        $documentPath,
                        $exception->getMessage(),
                    ), previous: $exception);
                }

                if ($statements === null) {
                    throw new \RuntimeException(sprintf('Normalized PHP signature source "%s" is empty.', $documentPath));
                }

                $symbols = $document['symbols'] ?? null;

                if (!is_array($symbols) || !array_is_list($symbols)) {
                    throw new \RuntimeException(sprintf('PHP signature symbols for "%s" are malformed.', $documentPath));
                }

                $this->collect($symbols, $module, $documentPath, $counts, $index, $members);
                $documentAliases = $document['aliases'] ?? null;

                if (!is_array($documentAliases) || !array_is_list($documentAliases)) {
                    throw new \RuntimeException(sprintf('PHP signature aliases for "%s" are malformed.', $documentPath));
                }

                foreach ($documentAliases as $alias) {
                    if (!is_array($alias)
                        || !is_string($alias['declaration'] ?? null)
                        || !is_string($alias['target'] ?? null)
                        || !in_array($alias['kind'] ?? null, ['alias', 'implementation-alias'], true)) {
                        throw new \RuntimeException(sprintf('PHP signature alias in "%s" is malformed.', $documentPath));
                    }

                    $aliases[] = [
                        'declaration' => $alias['declaration'],
                        'target' => $alias['target'],
                        'kind' => $alias['kind'],
                    ];
                }

                $this->addDirectiveAudit($directiveAudit, $document['directives'] ?? null, $documentPath);
            }
        }

        $counts['aliases'] = count($aliases);
        $this->sortIndex($index);
        $symbols = $this->json($root . '/symbols.json');

        foreach (['classes', 'functions', 'constants'] as $bucket) {
            if (($symbols[$bucket] ?? null) !== $index[$bucket]) {
                throw new \RuntimeException(sprintf('The PHP signature %s index does not match its shards.', $bucket));
            }
        }

        if ($requiresDerivedData) {
            $expectedReferences = (new PhpSignatureModuleReferences())->build($root, $expectedTarget, $moduleDocuments, $index, $phpDoc, $syntax);
            if ($hasModuleReferences
                && hash('sha256', CanonicalJson::encode($expectedReferences)) !== $digests[PhpSignatureModuleReferences::PATH]) {
                throw new \RuntimeException('The PHP signature module reference index does not match its shards.');
            }
            if ($phpDoc !== null && hash('sha256', $phpDoc->encode($expectedTarget)) !== $digests[PhpSignaturePhpDocIndex::PATH]) {
                throw new \RuntimeException('The platform PHPDoc metadata index does not match its shards.');
            }
            if ($syntax !== null && hash('sha256', $syntax->encode($expectedTarget)) !== $digests[PhpSignatureSyntaxIndex::PATH]) {
                throw new \RuntimeException('The platform syntax fingerprint index does not match its shards.');
            }
        }

        $expectedCounts = $manifest['counts'] ?? null;

        if (!is_array($expectedCounts)) {
            throw new \RuntimeException('The PHP signature manifest counts are malformed.');
        }

        foreach ($counts as $name => $count) {
            if (($expectedCounts[$name] ?? null) !== $count) {
                throw new \RuntimeException(sprintf('The PHP signature %s count does not match its shards.', $name));
            }
        }

        ksort($directiveAudit, SORT_STRING);

        if (($manifest['directiveAudit'] ?? null) !== $directiveAudit) {
            throw new \RuntimeException('The PHP signature directive audit does not match its shards.');
        }

        $overrides = $this->json($root . '/overrides.json');
        $overrideFunctions = $overrides['functions'] ?? null;

        if (!is_array($overrideFunctions) || !array_is_list($overrideFunctions)) {
            throw new \RuntimeException('The PHP signature intrinsic override list is malformed.');
        }

        /** @var list<string> $validatedOverrides */
        $validatedOverrides = [];

        foreach ($overrideFunctions as $function) {
            if (!is_string($function) || $function === '') {
                throw new \RuntimeException('The PHP signature intrinsic override list is malformed.');
            }

            $validatedOverrides[] = $function;
        }

        $sortedOverrides = $validatedOverrides;
        sort($sortedOverrides, SORT_STRING);

        if ($validatedOverrides !== array_values(array_unique($validatedOverrides))
            || $validatedOverrides !== $sortedOverrides) {
            throw new \RuntimeException('The PHP signature intrinsic override list is not deterministic.');
        }

        if (($expectedCounts['overrides'] ?? null) !== count($validatedOverrides)) {
            throw new \RuntimeException('The PHP signature override count does not match its data.');
        }

        foreach ($aliases as $alias) {
            $target = strtolower(ltrim($alias['target'], '\\'));

            if (!isset($index['functions'][$target]) && !isset($members[$target])) {
                throw new \RuntimeException(sprintf(
                    'PHP signature alias "%s" has unknown target "%s".',
                    $alias['declaration'],
                    $alias['target'],
                ));
            }
        }

        return new PhpSignaturePackageVerification($manifest, $digests);
    }

    /** @return array<string, mixed> */
    private function json(string $path): array
    {
        return $this->decode($this->read($path), $path);
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($path);

        if (!is_string($contents)) {
            throw new \RuntimeException(sprintf('Required PHP signature file "%s" is unavailable.', basename($path)));
        }

        return $contents;
    }

    /** @return array<string, mixed> */
    private function decode(string $contents, string $path): array
    {
        try {
            $decoded = CanonicalJson::decode($contents);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(sprintf('PHP signature file "%s" is invalid JSON.', basename($path)), previous: $exception);
        }

        if (!is_array($decoded) || array_is_list($decoded) || CanonicalJson::encode($decoded) !== $contents) {
            throw new \RuntimeException(sprintf('PHP signature file "%s" is not canonical JSON.', basename($path)));
        }

        $object = [];

        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                throw new \RuntimeException(sprintf('PHP signature file "%s" has a non-string object key.', basename($path)));
            }

            $object[$key] = $value;
        }

        return $object;
    }

    /** @param array<string, mixed> $data */
    private function requireInteger(array $data, string $key, int $expected): void
    {
        if (($data[$key] ?? null) !== $expected) {
            throw new \RuntimeException(sprintf('PHP signature field "%s" is unsupported.', $key));
        }
    }

    /** @param array<string, mixed> $data */
    private function requireString(array $data, string $key, string $expected): void
    {
        if (($data[$key] ?? null) !== $expected) {
            throw new \RuntimeException(sprintf('PHP signature field "%s" is unsupported.', $key));
        }
    }

    private function relativePath(mixed $path): string
    {
        if (!is_string($path) || $path === '' || str_contains($path, '\\')
            || str_starts_with($path, '/') || preg_match('/(^|\/)\.\.?($|\/)/', $path) === 1) {
            throw new \RuntimeException('A PHP signature package path is unsafe.');
        }

        return $path;
    }

    private function assertPortable(mixed $value): void
    {
        if (is_string($value)) {
            if (str_starts_with($value, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $value) === 1) {
                throw new \RuntimeException('The PHP signature manifest contains an absolute path.');
            }

            return;
        }

        if (!is_array($value)) {
            return;
        }

        foreach ($value as $entry) {
            $this->assertPortable($entry);
        }
    }

    /** @return array<string, string> */
    private function inputHashes(mixed $inputs): array
    {
        if (!is_array($inputs) || !array_is_list($inputs) || $inputs === []) {
            throw new \RuntimeException('The PHP signature input list is malformed.');
        }

        $hashes = [];
        $previous = null;

        foreach ($inputs as $input) {
            if (!is_array($input)) {
                throw new \RuntimeException('A PHP signature input record is malformed.');
            }

            $path = $this->relativePath($input['path'] ?? null);
            $hash = $input['sha256'] ?? null;

            if (($previous !== null && $path <= $previous) || !is_string($hash) || preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
                throw new \RuntimeException('The PHP signature input list is not deterministic.');
            }

            $previous = $path;
            $hashes[$path] = $hash;
        }

        return $hashes;
    }

    /** @return array{functions: int, classLikes: int, methods: int, properties: int, constants: int, aliases: int} */
    private function emptyCounts(): array
    {
        return ['functions' => 0, 'classLikes' => 0, 'methods' => 0, 'properties' => 0, 'constants' => 0, 'aliases' => 0];
    }

    /**
     * @param list<mixed> $symbols
     * @param array{functions: int, classLikes: int, methods: int, properties: int, constants: int, aliases: int} $counts
     * @param SymbolIndex $index
     * @param-out SymbolIndex $index
     * @param array<string, true> $members
     * @param-out array<string, true> $members
     */
    private function collect(array $symbols, string $module, string $document, array &$counts, array &$index, array &$members): void
    {
        foreach ($symbols as $symbol) {
            if (!is_array($symbol) || !is_string($symbol['kind'] ?? null) || !is_string($symbol['name'] ?? null)) {
                throw new \RuntimeException(sprintf('PHP signature symbol in "%s" is malformed.', $document));
            }

            $kind = $symbol['kind'];
            $name = $symbol['name'];
            $availability = $symbol['availability'] ?? null;

            if ($availability !== null && !is_string($availability)) {
                throw new \RuntimeException(sprintf('PHP signature availability in "%s" is malformed.', $document));
            }

            $bucket = match ($kind) {
                'function' => 'functions',
                'class', 'enum', 'interface', 'trait' => 'classes',
                'constant' => 'constants',
                default => throw new \RuntimeException(sprintf('PHP signature symbol kind "%s" is unsupported.', $kind)),
            };
            $countName = match ($bucket) {
                'functions' => 'functions',
                'classes' => 'classLikes',
                'constants' => 'constants',
            };
            $counts[$countName]++;
            $key = $bucket === 'constants' ? $name : strtolower($name);
            $index[$bucket][$key][] = [
                'availability' => $availability,
                'document' => $document,
                'module' => $module,
                'name' => $name,
            ];

            $symbolMembers = $symbol['members'] ?? [];

            if (!is_array($symbolMembers) || !array_is_list($symbolMembers)) {
                throw new \RuntimeException(sprintf('PHP signature members in "%s" are malformed.', $document));
            }

            foreach ($symbolMembers as $member) {
                if (!is_array($member) || !is_string($member['kind'] ?? null) || !is_string($member['name'] ?? null)) {
                    throw new \RuntimeException(sprintf('PHP signature member in "%s" is malformed.', $document));
                }

                match ($member['kind']) {
                    'method' => $counts['methods']++,
                    'property' => $counts['properties']++,
                    'class-constant', 'enum-case' => $counts['constants']++,
                    default => throw new \RuntimeException(sprintf('PHP signature member kind "%s" is unsupported.', $member['kind'])),
                };
                $members[strtolower(ltrim($member['name'], '\\'))] = true;
            }
        }
    }

    /**
     * @param array<string, array{count: int, disposition: string}> $total
     */
    private function addDirectiveAudit(array &$total, mixed $addition, string $document): void
    {
        if ($addition === []) {
            return;
        }

        if (!is_array($addition) || array_is_list($addition)) {
            throw new \RuntimeException(sprintf('PHP signature directives for "%s" are malformed.', $document));
        }

        foreach ($addition as $name => $entry) {
            if (!is_string($name) || !is_array($entry) || !is_int($entry['count'] ?? null)
                || !is_string($entry['disposition'] ?? null)) {
                throw new \RuntimeException(sprintf('PHP signature directive audit for "%s" is malformed.', $document));
            }

            if (isset($total[$name]) && $total[$name]['disposition'] !== $entry['disposition']) {
                throw new \RuntimeException(sprintf('PHP signature directive @%s has contradictory dispositions.', $name));
            }

            $total[$name] = [
                'count' => ($total[$name]['count'] ?? 0) + $entry['count'],
                'disposition' => $entry['disposition'],
            ];
        }
    }

    /**
     * @param SymbolIndex $index
     * @param-out SymbolIndex $index
     */
    private function sortIndex(array &$index): void
    {
        foreach (['classes', 'functions', 'constants'] as $bucket) {
            ksort($index[$bucket], SORT_STRING);

            foreach ($index[$bucket] as $key => $locations) {
                usort($locations, static fn (array $left, array $right): int =>
                    [$left['module'], $left['document'], $left['name']]
                    <=> [$right['module'], $right['document'], $right['name']]);
                $index[$bucket][$key] = $locations;
            }
        }
    }
}
