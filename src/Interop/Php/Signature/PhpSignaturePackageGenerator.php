<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Interop\Php\Signature;

use Atatusoft\Ppphp\Interop\Php\Intrinsic\IntrinsicFunctionRepository;
use Atatusoft\Ppphp\Support\CanonicalJson;
use Symfony\Component\Process\Process;

/**
 * @phpstan-type SymbolLocation array{availability: string|null, document: string, module: string, name: string}
 * @phpstan-type SymbolIndex array{classes: array<string, list<SymbolLocation>>, functions: array<string, list<SymbolLocation>>, constants: array<string, list<SymbolLocation>>}
 */
final readonly class PhpSignaturePackageGenerator
{
    public const int FORMAT_VERSION = 1;
    public const string GENERATOR_VERSION = '2';
    public const string PACKAGE_VERSION = '8.4.23.2';

    /** @var list<string> */
    private const array INTRINSIC_OVERRIDES = IntrinsicFunctionRepository::FUNCTION_NAMES;

    public function __construct(
        private PhpStubNormalizer $normalizer = new PhpStubNormalizer(),
        private PhpSignatureModuleReferences $moduleReferences = new PhpSignatureModuleReferences(),
    ) {}

    /** @return array<string, mixed> */
    public function generate(
        string $phpSrc,
        string $outputDirectory,
        string $target,
        string $expectedRef,
        string $expectedCommit,
    ): array {
        if ($target !== '8.4') {
            throw new \InvalidArgumentException(sprintf('Unsupported PHP signature target "%s".', $target));
        }

        $root = realpath($phpSrc);

        if ($root === false || !is_dir($root) || is_link($phpSrc)) {
            throw new \InvalidArgumentException('The php-src path must be a readable local checkout directory.');
        }

        $root = rtrim(str_replace('\\', '/', $root), '/');
        $actualCommit = $this->git($root, ['rev-parse', 'HEAD']);
        $peeledCommit = $this->git($root, ['rev-parse', $expectedRef . '^{commit}']);

        if ($actualCommit !== $expectedCommit || $peeledCommit !== $expectedCommit) {
            throw new \RuntimeException(sprintf(
                'The php-src checkout does not match %s at %s (HEAD %s, peeled %s).',
                $expectedRef,
                $expectedCommit,
                $actualCommit,
                $peeledCommit,
            ));
        }

        if ($this->git($root, ['status', '--porcelain', '--untracked-files=no']) !== '') {
            throw new \RuntimeException('The php-src checkout contains tracked modifications.');
        }

        $inputs = $this->stubInputs($root);
        /** @var array<string, list<array<string, mixed>>> $modules */
        $modules = [];
        $counts = $this->emptyCounts();
        $directiveAudit = [];
        /** @var SymbolIndex $symbolIndex */
        $symbolIndex = ['classes' => [], 'functions' => [], 'constants' => []];

        foreach ($inputs as $input) {
            $contents = file_get_contents($root . '/' . $input);

            if (!is_string($contents)) {
                throw new \RuntimeException(sprintf('Could not read tracked upstream stub "%s".', $input));
            }

            $normalization = $this->normalizer->normalize($input, $contents);
            $module = $this->module($input);
            $document = [
                'aliases' => $normalization->aliases,
                'directives' => $normalization->directives,
                'path' => $input,
                'sha256' => hash('sha256', $contents),
                'source' => $normalization->source,
                'symbols' => $normalization->symbols,
            ];
            $modules[$module][] = $document;
            $this->addCounts($counts, $normalization->counts);
            $this->addDirectiveAudit($directiveAudit, $normalization->directives);
            $this->addSymbolIndex($symbolIndex, $normalization->symbols, $module, $input);
        }

        ksort($modules, SORT_STRING);
        $this->prepareOutputDirectory($outputDirectory);
        $outputs = [];

        foreach ($modules as $module => $documents) {
            usort($documents, static fn (array $left, array $right): int => $left['path'] <=> $right['path']);
            $relativePath = 'extensions/' . $module . '.json';
            $json = CanonicalJson::encode([
                'documents' => $documents,
                'formatVersion' => self::FORMAT_VERSION,
                'module' => $module,
                'targetPhpVersion' => $target,
            ]);
            $this->write($outputDirectory, $relativePath, $json);
            $outputs[] = [
                'module' => $module,
                'path' => $relativePath,
                'sha256' => hash('sha256', $json),
            ];
        }

        $this->sortSymbolIndex($symbolIndex);
        $symbolsJson = CanonicalJson::encode([
            ...$symbolIndex,
            'formatVersion' => self::FORMAT_VERSION,
            'targetPhpVersion' => $target,
        ]);
        $this->write($outputDirectory, 'symbols.json', $symbolsJson);
        $outputs[] = [
            'path' => 'symbols.json',
            'sha256' => hash('sha256', $symbolsJson),
        ];

        $phpDoc = new PhpSignaturePhpDocIndex();
        $references = CanonicalJson::encode($this->moduleReferences->build($outputDirectory, $target, $modules, $symbolIndex, $phpDoc));
        $this->write($outputDirectory, PhpSignatureModuleReferences::PATH, $references);
        $outputs[] = [
            'path' => PhpSignatureModuleReferences::PATH,
            'sha256' => hash('sha256', $references),
        ];

        $metadata = $phpDoc->encode($target);
        $this->write($outputDirectory, PhpSignaturePhpDocIndex::PATH, $metadata);
        $outputs[] = [
            'path' => PhpSignaturePhpDocIndex::PATH,
            'sha256' => hash('sha256', $metadata),
        ];

        $overridesJson = CanonicalJson::encode([
            'formatVersion' => self::FORMAT_VERSION,
            'functions' => self::INTRINSIC_OVERRIDES,
        ]);
        $this->write($outputDirectory, 'overrides.json', $overridesJson);
        $outputs[] = [
            'path' => 'overrides.json',
            'sha256' => hash('sha256', $overridesJson),
        ];

        $notice = $this->notice($expectedRef, $expectedCommit);
        $this->write($outputDirectory, 'NOTICE.md', $notice);
        $outputs[] = [
            'path' => 'NOTICE.md',
            'sha256' => hash('sha256', $notice),
        ];
        usort($outputs, static fn (array $left, array $right): int => $left['path'] <=> $right['path']);
        ksort($directiveAudit, SORT_STRING);

        $manifest = [
            'counts' => [
                'aliases' => $counts['aliases'],
                'classLikes' => $counts['classLikes'],
                'constants' => $counts['constants'],
                'functions' => $counts['functions'],
                'methods' => $counts['methods'],
                'overrides' => count(self::INTRINSIC_OVERRIDES),
                'properties' => $counts['properties'],
            ],
            'directiveAudit' => $directiveAudit,
            'formatVersion' => self::FORMAT_VERSION,
            'generatorVersion' => self::GENERATOR_VERSION,
            'inputs' => array_map(static fn (string $path): array => [
                'path' => $path,
                'sha256' => hash_file('sha256', $root . '/' . $path),
            ], $inputs),
            'license' => 'PHP License 3.01',
            'outputs' => $outputs,
            'packageVersion' => self::PACKAGE_VERSION,
            'targetPhpVersion' => $target,
            'upstream' => [
                'commit' => $expectedCommit,
                'repository' => 'php/php-src',
                'tag' => $expectedRef,
            ],
        ];
        $this->write($outputDirectory, 'manifest.json', CanonicalJson::encode($manifest));

        return $manifest;
    }

    /** @param list<string> $arguments */
    private function git(string $root, array $arguments): string
    {
        $process = new Process(['git', '-C', $root, ...$arguments]);
        $process->setTimeout(30.0);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(sprintf(
                'Could not verify php-src identity: %s',
                trim($process->getErrorOutput() . "\n" . $process->getOutput()),
            ));
        }

        return trim($process->getOutput());
    }

    /** @return list<string> */
    private function stubInputs(string $root): array
    {
        $tree = $this->git($root, ['ls-tree', '-r', '--name-only', 'HEAD']);
        $inputs = array_values(array_filter(
            explode("\n", $tree),
            static fn (string $path): bool => str_ends_with($path, '.stub.php')
                && preg_match('#^(Zend|ext|main|sapi)/#', $path) === 1,
        ));
        sort($inputs, SORT_STRING);

        if ($inputs === []) {
            throw new \RuntimeException('The verified php-src checkout contains no declaration stubs.');
        }

        foreach ($inputs as $path) {
            if (!is_file($root . '/' . $path)) {
                throw new \RuntimeException(sprintf(
                    'Tracked upstream stub "%s" is absent from the local checkout.',
                    $path,
                ));
            }
        }

        return $inputs;
    }

    private function module(string $path): string
    {
        $parts = explode('/', $path);

        return match ($parts[0]) {
            'Zend', 'main' => 'core',
            'ext' => strtolower($parts[1]),
            'sapi' => 'sapi-' . strtolower($parts[1]),
            default => throw new \LogicException(sprintf('No module owner for upstream stub "%s".', $path)),
        };
    }

    /** @return array{functions: int, classLikes: int, methods: int, properties: int, constants: int, aliases: int} */
    private function emptyCounts(): array
    {
        return [
            'functions' => 0,
            'classLikes' => 0,
            'methods' => 0,
            'properties' => 0,
            'constants' => 0,
            'aliases' => 0,
        ];
    }

    /**
     * @param array{functions: int, classLikes: int, methods: int, properties: int, constants: int, aliases: int} $total
     * @param array{functions: int, classLikes: int, methods: int, properties: int, constants: int, aliases: int} $addition
     */
    private function addCounts(array &$total, array $addition): void
    {
        foreach ($addition as $name => $count) {
            $total[$name] += $count;
        }
    }

    /**
     * @param array<string, array{count: int, disposition: string}> $total
     * @param array<string, array{count: int, disposition: string}> $addition
     */
    private function addDirectiveAudit(array &$total, array $addition): void
    {
        foreach ($addition as $name => $entry) {
            if (isset($total[$name]) && $total[$name]['disposition'] !== $entry['disposition']) {
                throw new \LogicException(sprintf('Directive @%s has contradictory dispositions.', $name));
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
     * @param list<array<string, mixed>> $symbols
     */
    private function addSymbolIndex(array &$index, array $symbols, string $module, string $document): void
    {
        foreach ($symbols as $symbol) {
            $kind = $symbol['kind'] ?? null;
            $name = $symbol['name'] ?? null;
            $availability = $symbol['availability'] ?? null;

            if (!is_string($kind) || !is_string($name)
                || ($availability !== null && !is_string($availability))) {
                throw new \LogicException(sprintf('Normalizer produced an invalid symbol for "%s".', $document));
            }

            $bucket = match ($kind) {
                'class', 'enum', 'interface', 'trait' => 'classes',
                'function' => 'functions',
                'constant' => 'constants',
                default => null,
            };

            if ($bucket === null) {
                continue;
            }

            $key = $bucket === 'constants' ? $name : strtolower($name);
            $index[$bucket][$key][] = [
                'availability' => $availability,
                'document' => $document,
                'module' => $module,
                'name' => $name,
            ];
        }
    }

    /**
     * @param SymbolIndex $index
     * @param-out SymbolIndex $index
     */
    private function sortSymbolIndex(array &$index): void
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

    private function prepareOutputDirectory(string $outputDirectory): void
    {
        if (is_link($outputDirectory)) {
            throw new \RuntimeException('The signature output directory cannot be a symbolic link.');
        }

        if (is_dir($outputDirectory)) {
            $manifest = $outputDirectory . '/manifest.json';

            if (!is_file($manifest)) {
                throw new \RuntimeException('An existing signature output directory must contain a package manifest.');
            }

            $this->removeGeneratedDirectory($outputDirectory);
        }

        if (!mkdir($outputDirectory . '/extensions', 0o755, true) && !is_dir($outputDirectory . '/extensions')) {
            throw new \RuntimeException('Could not create the signature output directory.');
        }
    }

    private function removeGeneratedDirectory(string $directory): void
    {
        $entries = scandir($directory);

        if ($entries === false) {
            throw new \RuntimeException('Could not inspect the existing signature output directory.');
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;

            if (is_link($path)) {
                throw new \RuntimeException('The signature output directory contains a symbolic link.');
            }

            if (is_dir($path)) {
                $this->removeGeneratedDirectory($path);
            } elseif (!unlink($path)) {
                throw new \RuntimeException(sprintf('Could not replace generated signature file "%s".', $entry));
            }
        }

        if (!rmdir($directory)) {
            throw new \RuntimeException('Could not replace the generated signature directory.');
        }
    }

    private function write(string $outputDirectory, string $relativePath, string $contents): void
    {
        $path = $outputDirectory . '/' . $relativePath;
        $parent = dirname($path);

        if (!is_dir($parent) && !mkdir($parent, 0o755, true) && !is_dir($parent)) {
            throw new \RuntimeException(sprintf('Could not create signature directory for "%s".', $relativePath));
        }

        if (file_put_contents($path, $contents, LOCK_EX) !== strlen($contents)) {
            throw new \RuntimeException(sprintf('Could not write signature output "%s".', $relativePath));
        }
    }

    private function notice(string $tag, string $commit): string
    {
        return <<<NOTICE
# PHP signature data notice

The normalized declaration metadata in this directory is derived from the
official `php/php-src` project at tag `{$tag}` (peeled commit `{$commit}`).

PHP is distributed under the PHP License 3.01. ++PHP distributes normalized
signature metadata, not PHP implementation code. The upstream license remains
available at <https://www.php.net/license/3_01.txt>.
NOTICE . "\n";
    }
}
