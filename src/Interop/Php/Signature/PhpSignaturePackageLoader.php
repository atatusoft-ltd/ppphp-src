<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Interop\Php\Signature;

use Atatusoft\Ppphp\Analysis\Declaration\DeclarationReferenceCollector;
use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Frontend\Enumerations\ParseMode;
use Atatusoft\Ppphp\Frontend\ParsedFile;
use Atatusoft\Ppphp\Frontend\PpphpParser;
use Atatusoft\Ppphp\Project\ProjectParseResult;
use Atatusoft\Ppphp\Source\SourceFile;
use Atatusoft\Ppphp\Support\CanonicalJson;
use Atatusoft\Ppphp\Support\Path;

final class PhpSignaturePackageLoader
{
    /** @var array<string, PhpSignaturePackageVerification> */
    private array $verifiedPackages = [];
    /** @var array<string, PhpSignaturePhpDocIndex> */
    private array $phpDocIndexes = [];

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
        private readonly ?BrowserPhpSignatureCache $browserCache = null,
    ) {}

    /** @param iterable<ParsedFile> $projectFiles */
    public function load(string $target, iterable $projectFiles): ProjectParseResult
    {
        $diagnostics = new DiagnosticBag();
        $package = $this->packagePath($target);

        try {
            $verification = $this->verifiedPackages[$target] ?? null;
            if ($verification === null || !$verification->matches($package)) {
                unset($this->verifiedPackages[$target], $this->parsedModules[$target], $this->phpDocIndexes[$target]);
                $verification = $this->verifier->verifyInstalledPackage($package, $target);
                $phpDoc = $verification->readOptionalOutput($package, PhpSignaturePhpDocIndex::PATH);
                $index = $phpDoc === null ? null : PhpSignaturePhpDocIndex::decode($phpDoc, $target);
                if ($index !== null) {
                    $this->phpDocIndexes[$target] = $index;
                }
                $this->verifiedPackages[$target] = $verification;
            }
            $this->browserCache?->useVerifiedPackage($target, $verification->identity);
            $symbols = $this->json($package . '/symbols.json');
            $graphBytes = $verification->readOptionalOutput($package, PhpSignatureModuleReferences::PATH);
            $graph = null;
            if ($graphBytes !== null) {
                $index = CanonicalJson::decode($graphBytes);
                if (!is_array($index) || !is_array($index['modules'] ?? null)) {
                    throw new \RuntimeException('The PHP signature module reference index is malformed.');
                }
                $graph = $index['modules'];
            }
            $references = $this->references->collect($projectFiles);
            $modules = ['core' => true];
            PhpSignatureModuleReferences::addModules($modules, $symbols, $references);
            $parsedFiles = [];
            $sourceFiles = [];
            $loadedModules = [];

            while (($pending = array_values(array_diff(array_keys($modules), array_keys($loadedModules)))) !== []) {
                sort($pending, SORT_STRING);

                foreach ($pending as $module) {
                    $loadedModules[$module] = true;
                    $parsedModule = $this->parsedModules[$target][$module]
                        ??= $this->parseModule($package, $target, $module, $graph === null);
                    $parsedFiles = array_replace($parsedFiles, $parsedModule['parsedFiles']);
                    $sourceFiles = array_replace($sourceFiles, $parsedModule['sourceFiles']);
                    if ($graph === null) {
                        PhpSignatureModuleReferences::addModules($modules, $symbols, $parsedModule['references']);
                    } else {
                        $dependencies = $graph[$module] ?? null;
                        if (!is_array($dependencies) || !array_is_list($dependencies)) {
                            throw new \RuntimeException(sprintf('PHP signature module "%s" has invalid references.', $module));
                        }
                        foreach ($dependencies as $dependency) {
                            if (!is_string($dependency)) {
                                throw new \RuntimeException(sprintf('PHP signature module "%s" has an invalid dependency.', $module));
                            }
                            $modules[$dependency] = true;
                        }
                    }
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
    private function parseModule(string $package, string $target, string $module, bool $collectReferences): array
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

            $sourceFile = PhpSignatureModuleReferences::createSource($package, $target, $document['path'], $document['source']);
            $cacheKey = $module . '/' . $document['path'];
            $cached = $this->browserCache?->restore($cacheKey);
            if ($cached === null) {
                $result = $this->parser->parse($sourceFile, ParseMode::Php);
                if ($result->parsedFile === null || $result->diagnostics->hasErrors) {
                    throw new \RuntimeException(sprintf(
                        'Normalized PHP signature document "%s" could not be loaded.',
                        $document['path'],
                    ));
                }
                $parsed = $result->parsedFile;
                $this->browserCache?->remember($cacheKey, $parsed->statements);
            } else {
                $parsed = $this->parser->restorePhp($sourceFile, $cached);
            }

            ($this->phpDocIndexes[$target] ?? null)?->prime($parsed);
            $key = Path::buildComparisonKey($sourceFile->path);
            $sourceFiles[$key] = $sourceFile;
            $parsedFiles[$key] = $parsed;
        }

        return [
            'parsedFiles' => $parsedFiles,
            'sourceFiles' => $sourceFiles,
            'references' => $collectReferences ? $this->references->collect($parsedFiles) : ['classes' => [], 'functions' => [], 'constants' => []],
        ];
    }

    private function packagePath(string $target): string
    {
        $root = $this->resourceRoot ?? dirname(__DIR__, 4) . '/resources/php-signatures';

        return rtrim(str_replace('\\', '/', $root), '/') . '/' . $target;
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
}
