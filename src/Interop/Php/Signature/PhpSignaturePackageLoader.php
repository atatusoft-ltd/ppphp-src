<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Interop\Php\Signature;

use Atatusoft\Ppphp\Analysis\Declaration\DeclarationOrigin;
use Atatusoft\Ppphp\Analysis\Declaration\DeclarationReferenceCollector;
use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Frontend\Enumerations\ParseMode;
use Atatusoft\Ppphp\Frontend\ParsedFile;
use Atatusoft\Ppphp\Frontend\PpphpParser;
use Atatusoft\Ppphp\Project\ProjectParseResult;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;
use Atatusoft\Ppphp\Support\CanonicalJson;
use Atatusoft\Ppphp\Support\Path;

final class PhpSignaturePackageLoader
{
    /** @var array<string, array<string, mixed>> */
    private array $verifiedManifests = [];

    /** @var array<string, string> */
    private array $packageIdentities = [];

    /**
     * @var array<string, array<string, array{
     *     parsedFiles: array<string, ParsedFile>,
     *     sourceFiles: array<string, SourceFile>,
     *     references: array{classes: list<string>, functions: list<string>, constants: list<string>}
     * }>>
     */
    private array $parsedModules = [];

    public function __construct(
        private readonly ?string $resourceRoot = null,
        private readonly PhpSignaturePackageVerifier $verifier = new PhpSignaturePackageVerifier(),
        private readonly PpphpParser $parser = new PpphpParser(),
        private readonly DeclarationReferenceCollector $references = new DeclarationReferenceCollector(),
    ) {}

    /** @param iterable<ParsedFile> $projectFiles */
    public function load(string $target, iterable $projectFiles): ProjectParseResult
    {
        $diagnostics = new DiagnosticBag();
        $package = $this->packagePath($target);

        try {
            $manifest = $this->verifiedManifests[$target] ?? null;
            if ($manifest === null || ($this->packageIdentities[$target] ?? null) !== $this->computePackageIdentity($package, $manifest)) {
                unset($this->verifiedManifests[$target], $this->packageIdentities[$target], $this->parsedModules[$target]);
                $manifest = $this->verifier->verify($package, $target);
                $this->packageIdentities[$target] = $this->computePackageIdentity($package, $manifest);
                $this->verifiedManifests[$target] = $manifest;
            }
            $symbols = $this->json($package . '/symbols.json');
            $references = $this->references->collect($projectFiles);
            $modules = ['core' => true];
            $this->addModules($modules, $symbols, $references);
            $parsedFiles = [];
            $sourceFiles = [];
            $loadedModules = [];

            while (($pending = array_values(array_diff(array_keys($modules), array_keys($loadedModules)))) !== []) {
                sort($pending, SORT_STRING);

                foreach ($pending as $module) {
                    $loadedModules[$module] = true;
                    $parsedModule = $this->parsedModules[$target][$module]
                        ??= $this->parseModule($package, $target, $module);
                    $parsedFiles = array_replace($parsedFiles, $parsedModule['parsedFiles']);
                    $sourceFiles = array_replace($sourceFiles, $parsedModule['sourceFiles']);
                    $this->addModules($modules, $symbols, $parsedModule['references']);
                }
            }

            ksort($parsedFiles, SORT_STRING);
            ksort($sourceFiles, SORT_STRING);

            return new ProjectParseResult($parsedFiles, $sourceFiles, $diagnostics);
        } catch (\Throwable $exception) {
            $diagnostics->add(new Diagnostic(
                DiagnosticCode::PhpSignaturePackageInvalid,
                sprintf('The compiler PHP %s signature package could not be trusted.', $target),
                help: 'Reinstall the compiler from a verified distribution.',
                debug: ['message' => $exception->getMessage()],
            ));

            return new ProjectParseResult([], [], $diagnostics);
        }
    }

    /**
     * @return array{parsedFiles: array<string, ParsedFile>, sourceFiles: array<string, SourceFile>, references: array{classes: list<string>, functions: list<string>, constants: list<string>}}
     */
    private function parseModule(string $package, string $target, string $module): array
    {
        $shard = $this->json($package . '/extensions/' . $module . '.json');
        $documents = $shard['documents'] ?? null;

        if (!is_array($documents) || !array_is_list($documents)) {
            throw new \RuntimeException(sprintf('PHP signature module "%s" is malformed.', $module));
        }

        $parsedFiles = [];
        $sourceFiles = [];

        foreach ($documents as $document) {
            if (!is_array($document)
                || !is_string($document['path'] ?? null)
                || !is_string($document['source'] ?? null)) {
                throw new \RuntimeException(sprintf('PHP signature module "%s" contains an invalid document.', $module));
            }

            $sourceFile = new SourceFile(
                $package . '/declarations/' . $document['path'],
                sprintf('<PHP %s platform>/%s', $target, $document['path']),
                FileKind::Stub,
                $document['source'],
                DeclarationOrigin::PhpPlatform,
            );
            $result = $this->parser->parse($sourceFile, ParseMode::Php);

            if ($result->parsedFile === null || $result->diagnostics->hasErrors) {
                throw new \RuntimeException(sprintf(
                    'Normalized PHP signature document "%s" could not be loaded.',
                    $document['path'],
                ));
            }

            $key = Path::buildComparisonKey($sourceFile->path);
            $sourceFiles[$key] = $sourceFile;
            $parsedFiles[$key] = $result->parsedFile;
        }

        return ['parsedFiles' => $parsedFiles, 'sourceFiles' => $sourceFiles, 'references' => $this->references->collect($parsedFiles)];
    }

    private function packagePath(string $target): string
    {
        $root = $this->resourceRoot ?? dirname(__DIR__, 4) . '/resources/php-signatures';

        return rtrim(str_replace('\\', '/', $root), '/') . '/' . $target;
    }

    /** @param array<string, mixed> $manifest Previously verified manifest. */
    private function computePackageIdentity(string $package, array $manifest): string
    {
        if (!is_dir($package) || is_link($package)) {
            throw new \RuntimeException('The PHP signature package directory is unavailable.');
        }
        $paths = ['manifest.json'];
        $outputs = $manifest['outputs'] ?? null;
        if (!is_array($outputs)) {
            throw new \RuntimeException('The verified signature output list is unavailable.');
        }
        foreach ($outputs as $output) {
            if (!is_array($output) || !is_string($output['path'] ?? null)) {
                throw new \RuntimeException('A verified signature output path is unavailable.');
            }
            $paths[] = $output['path'];
        }
        $hash = hash_init('sha256');
        foreach ($paths as $path) {
            $digest = @hash_file('sha256', $package . '/' . $path);
            if ($digest === false) {
                throw new \RuntimeException('A signature resource could not be read.');
            }
            hash_update($hash, $path . "\0" . $digest . "\0");
        }

        return hash_final($hash);
    }

    /** @return array<string, mixed> */
    private function json(string $path): array
    {
        $contents = file_get_contents($path);

        if (!is_string($contents)) {
            throw new \RuntimeException(sprintf('Required PHP signature resource "%s" is unavailable.', basename($path)));
        }

        $decoded = CanonicalJson::decode($contents);

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \RuntimeException(sprintf('PHP signature resource "%s" is malformed.', basename($path)));
        }

        $object = [];

        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                throw new \RuntimeException(sprintf('PHP signature resource "%s" has an invalid key.', basename($path)));
            }

            $object[$key] = $value;
        }

        return $object;
    }

    /**
     * @param array<string, true> $modules
     * @param array<string, mixed> $symbols
     * @param array{classes: list<string>, functions: list<string>, constants: list<string>} $references
     */
    private function addModules(array &$modules, array $symbols, array $references): void
    {
        foreach ($references as $bucket => $names) {
            $index = $symbols[$bucket] ?? null;

            if (!is_array($index)) {
                throw new \RuntimeException(sprintf('PHP signature %s index is malformed.', $bucket));
            }

            foreach ($names as $name) {
                $key = $bucket === 'constants' ? $name : strtolower($name);
                $locations = $index[$key] ?? [];

                if (!is_array($locations)) {
                    throw new \RuntimeException(sprintf('PHP signature index entry "%s" is malformed.', $name));
                }

                foreach ($locations as $location) {
                    if (!is_array($location) || !is_string($location['module'] ?? null)) {
                        throw new \RuntimeException(sprintf('PHP signature index location "%s" is malformed.', $name));
                    }

                    $modules[$location['module']] = true;
                }
            }
        }
    }
}
