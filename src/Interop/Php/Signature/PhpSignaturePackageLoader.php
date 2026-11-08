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

    private ?PhpSignaturePackageVerification $immutableVerification = null;
    /** @var array<string, PhpSignaturePhpDocIndex> */
    private array $phpDocIndexes = [];

    /** @var array<string, PhpSignatureSyntaxIndex> */
    private array $syntaxIndexes = [];

    /** @var array<string, array<string, mixed>> */
    private array $symbolIndexes = [];

    /** @var array<string, array<array-key, mixed>|null> */
    private array $moduleGraphs = [];

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

    /** Retains the result of the full initial check on the browser's read-only compiler mount. */
    public function retainImmutableVerification(string $target): void
    {
        if (PHP_SAPI !== 'wasm' || $this->browserCache === null || $this->resourceRoot !== null) {
            throw new \LogicException('Retaining platform verification requires the immutable Wasm compiler installation.');
        }

        $this->immutableVerification = $this->verifiedPackages[$target]
            ?? throw new \LogicException('Platform verification must complete before it can be retained.');
    }

    public function verifyPackage(string $target): PhpSignaturePackageVerification
    {
        $verification = $this->verifiedPackages[$target] ?? null;
        if ($verification !== null && ($verification === $this->immutableVerification || $verification->matches($this->packagePath($target)))) {
            return $verification;
        }

        unset(
            $this->verifiedPackages[$target],
            $this->parsedModules[$target],
            $this->phpDocIndexes[$target],
            $this->syntaxIndexes[$target],
            $this->symbolIndexes[$target],
            $this->moduleGraphs[$target],
        );

        return $this->verifiedPackages[$target] = $this->verifier->verifyInstalledPackage($this->packagePath($target), $target);
    }

    /**
     * @param list<string> $classNames
     * @param list<string> $functionNames
     */
    public function verifyLocalDeclarations(string $target, array $classNames, array $functionNames): bool
    {
        $verification = $this->verifyPackage($target);
        if ($classNames === [] && $functionNames === []) {
            return true;
        }

        $symbols = $this->symbolIndexes[$target] ??= $this->readSymbolIndex($this->packagePath($target), $verification);
        foreach (['classes' => $classNames, 'functions' => $functionNames] as $kind => $names) {
            $platform = $symbols[$kind] ?? null;
            if (!is_array($platform)) {
                throw new \RuntimeException(sprintf('The PHP signature %s index is unavailable.', $kind));
            }
            foreach ($names as $name) {
                if (array_key_exists(strtolower(ltrim($name, '\\')), $platform)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function readSymbolIndex(string $package, PhpSignaturePackageVerification $verification): array
    {
        $symbols = $verification->readOptionalOutput($package, 'symbols.json')
            ?? throw new \RuntimeException('The PHP signature symbol index is unavailable.');

        return $this->decodeObject($symbols, 'symbols.json');
    }

    /** @param iterable<ParsedFile> $projectFiles */
    public function load(string $target, iterable $projectFiles): ProjectParseResult
    {
        $diagnostics = new DiagnosticBag();
        $package = $this->packagePath($target);

        try {
            $verification = $this->verifyPackage($target);
            $symbols = $this->symbolIndexes[$target] ??= $this->readSymbolIndex($package, $verification);
            if (!array_key_exists($target, $this->moduleGraphs)) {
                $phpDoc = $verification->readOptionalOutput($package, PhpSignaturePhpDocIndex::PATH);
                $index = $phpDoc === null ? null : PhpSignaturePhpDocIndex::decode($phpDoc, $target);
                if ($index !== null) {
                    $this->phpDocIndexes[$target] = $index;
                }
                if ($this->browserCache !== null) {
                    $bytes = $verification->readOptionalOutput($package, PhpSignatureSyntaxIndex::PATH);
                    $syntax = $bytes === null ? null : PhpSignatureSyntaxIndex::decode($bytes, $target);
                    if ($syntax !== null) {
                        $this->syntaxIndexes[$target] = $syntax;
                    }
                }
                $graph = null;
                $graphBytes = $verification->readOptionalOutput($package, PhpSignatureModuleReferences::PATH);
                if ($graphBytes !== null) {
                    $index = CanonicalJson::decode($graphBytes);
                    if (!is_array($index) || !is_array($index['modules'] ?? null)) {
                        throw new \RuntimeException('The PHP signature module reference index is malformed.');
                    }
                    $graph = $index['modules'];
                }
                $this->moduleGraphs[$target] = $graph;
            }
            $this->browserCache?->useVerifiedPackage($target, $verification->identity);
            $graph = $this->moduleGraphs[$target];
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
                        ??= $this->parseModule($package, $target, $module, $graph === null, $verification);
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
    private function parseModule(
        string $package,
        string $target,
        string $module,
        bool $collectReferences,
        PhpSignaturePackageVerification $verification,
    ): array {
        $path = 'extensions/' . $module . '.json';
        $bytes = $verification->readOptionalOutput($package, $path)
            ?? throw new \RuntimeException(sprintf('Required PHP signature resource "%s" is unavailable.', $path));
        $shard = $this->decodeObject($bytes, $path);
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
            $cached = $this->browserCache?->restore($cacheKey, ($this->syntaxIndexes[$target] ?? null)?->find($cacheKey));
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
    private function decodeObject(string $contents, string $path): array
    {
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
