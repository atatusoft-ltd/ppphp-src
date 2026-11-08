<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Interop\Php\Signature;

use Atatusoft\Ppphp\Analysis\Declaration\DeclarationOrigin;
use Atatusoft\Ppphp\Analysis\Declaration\DeclarationReferenceCollector;
use Atatusoft\Ppphp\Frontend\Enumerations\ParseMode;
use Atatusoft\Ppphp\Frontend\PpphpParser;
use Atatusoft\Ppphp\Interop\PhpDoc\PhpDocReader;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;

final readonly class PhpSignatureModuleReferences
{
    public const string PATH = 'module-references.json';
    private const int FORMAT_VERSION = 1;

    public function __construct(
        private PpphpParser $parser = new PpphpParser(),
        private DeclarationReferenceCollector $references = new DeclarationReferenceCollector(),
        private PhpDocReader $phpDoc = new PhpDocReader(),
    ) {}

    /**
     * @param array<string, list<array<string, mixed>>> $documentsByModule
     * @param array<string, mixed> $symbols
     * @return array{formatVersion: int, targetPhpVersion: string, modules: array<string, list<string>>}
     */
    public function build(
        string $package,
        string $target,
        array $documentsByModule,
        array $symbols,
        ?PhpSignaturePhpDocIndex $phpDoc = null,
        ?PhpSignatureSyntaxIndex $syntax = null,
    ): array {
        $modules = [];
        foreach ($documentsByModule as $module => $documents) {
            $parsed = [];
            foreach ($documents as $document) {
                if (!is_string($document['path'] ?? null) || !is_string($document['source'] ?? null)) {
                    throw new \RuntimeException(sprintf('PHP signature module "%s" contains an invalid document.', $module));
                }
                $source = self::createSource($package, $target, $document['path'], $document['source']);
                $result = $this->parser->parse($source, ParseMode::Php);
                if ($result->parsedFile === null || $result->diagnostics->hasErrors) {
                    throw new \RuntimeException(sprintf('Normalized PHP signature document "%s" could not be loaded.', $document['path']));
                }
                $parsed[] = $result->parsedFile;
                $phpDoc?->collect($result->parsedFile, $this->phpDoc);
                $syntax?->collect($module . '/' . $document['path'], $result->parsedFile->statements);
            }
            $dependencies = [];
            self::addModules($dependencies, $symbols, $this->references->collect($parsed));
            unset($dependencies[$module]);
            $names = array_keys($dependencies);
            sort($names, SORT_STRING);
            $modules[$module] = $names;
        }
        ksort($modules, SORT_STRING);

        return ['formatVersion' => self::FORMAT_VERSION, 'targetPhpVersion' => $target, 'modules' => $modules];
    }

    public static function createSource(string $package, string $target, string $path, string $contents): SourceFile
    {
        return new SourceFile(
            $package . '/declarations/' . $path,
            sprintf('<PHP %s platform>/%s', $target, $path),
            FileKind::Stub,
            $contents,
            DeclarationOrigin::PhpPlatform,
        );
    }

    /**
     * @param array<string, true> $modules
     * @param array<string, mixed> $symbols
     * @param array{classes: list<string>, functions: list<string>, constants: list<string>} $references
     */
    public static function addModules(array &$modules, array $symbols, array $references): void
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
