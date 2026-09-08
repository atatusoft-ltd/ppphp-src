<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Interop\Composer\Index;

use Atatusoft\Ppphp\Cache\CompilerBuildIdentity;
use Atatusoft\Ppphp\Analysis\Declaration\DeclarationReferenceCollector;
use Atatusoft\Ppphp\Analysis\DeclarationContextEmitter;
use Atatusoft\Ppphp\Compiler\Compiler;
use Atatusoft\Ppphp\Compiler\Output\Interfaces\BuildFilesystem;
use Atatusoft\Ppphp\Compiler\Output\NativeBuildFilesystem;
use Atatusoft\Ppphp\Frontend\ParsedFile;
use Atatusoft\Ppphp\Interop\Composer\AutoloadMap;
use Atatusoft\Ppphp\Interop\Composer\ComposerPackage;
use Atatusoft\Ppphp\Interop\Composer\ComposerProject;
use Atatusoft\Ppphp\Project\ProjectParseResult;
use Atatusoft\Ppphp\Support\CanonicalJson;
use Atatusoft\Ppphp\Support\Path;

final readonly class DependencyDeclarationIndexWriter
{
    public const int FORMAT_VERSION = 2;
    public const int DECLARATION_FORMAT_VERSION = 2;
    private const int TRANSACTION_FORMAT_VERSION = 1;
    private const int MAXIMUM_MARKER_BYTES = 4_096;
    private const string TRANSACTION_MARKER = '.ppphp-index-transaction.json';

    public function __construct(
        private DeclarationContextEmitter $emitter = new DeclarationContextEmitter(),
        private DeclarationReferenceCollector $references = new DeclarationReferenceCollector(),
        private PortableDeclarationValidator $validator = new PortableDeclarationValidator(),
        private CompilerBuildIdentity $buildIdentity = new CompilerBuildIdentity(),
        private DependencyDeclarationIndexReader $reader = new DependencyDeclarationIndexReader(),
        private BuildFilesystem $filesystem = new NativeBuildFilesystem(),
    ) {}

    /** @return array<string, mixed> */
    public function write(
        ComposerProject $composer,
        ProjectParseResult $declarations,
        string $targetPhpVersion,
        string $outputDirectory,
    ): array {
        $parent = dirname($outputDirectory);
        $name = basename($outputDirectory);
        $token = bin2hex(random_bytes(12));
        $candidate = Path::join($parent, '.' . $name . '.candidate-' . $token);
        $backup = Path::join($parent, '.' . $name . '.backup-' . $token);
        $this->recoverTransactions($outputDirectory, $targetPhpVersion);

        try {
            $this->prepareOutput($candidate);
            $this->writeMarker($candidate, $name, $token, 'candidate');
            $manifest = $this->writeCandidate(
                $composer,
                $declarations,
                $targetPhpVersion,
                $candidate,
            );
            $verification = $this->reader->read(
                Path::join($candidate, 'manifest.json'),
                $targetPhpVersion,
            );

            if (!$verification->isSuccessful) {
                throw new \RuntimeException('The dependency index candidate failed independent verification.');
            }

            $this->replaceOutput($outputDirectory, $candidate, $backup, $targetPhpVersion, $token);

            return $manifest;
        } catch (\Throwable $exception) {
            try {
                $this->recoverTransactions($outputDirectory, $targetPhpVersion);
            } catch (\Throwable) {
            }

            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function writeCandidate(
        ComposerProject $composer,
        ProjectParseResult $declarations,
        string $targetPhpVersion,
        string $outputDirectory,
    ): array {
        if (!$declarations->isSuccessful) {
            throw new \InvalidArgumentException('An invalid dependency declaration context cannot be serialized.');
        }

        if (count($declarations->classAliases) !== count($declarations->classAliasProvenance)) {
            throw new \InvalidArgumentException('Every dependency class alias must retain declaration provenance.');
        }

        $this->prepareOutput($outputDirectory);
        $exclusions = $this->declarationExclusions($declarations->parsedFiles);
        $filesByPackage = [];

        foreach ($declarations->parsedFiles as $file) {
            $provenance = $file->sourceFile->dependencyProvenance;

            if ($provenance !== null) {
                $filesByPackage[strtolower($provenance->packageName)][] = $file;
            }
        }

        $packageEntries = [];
        $totals = [
            'aliases' => count($declarations->classAliases),
            'classLikes' => 0,
            'conditionalDeclarations' => 0,
            'constants' => 0,
            'documents' => 0,
            'functions' => 0,
            'staticIncludes' => 0,
        ];

        foreach ($composer->dependencies as $packageOrder => $package) {
            $files = $filesByPackage[strtolower($package->name)] ?? [];
            $documents = [];
            $counts = ['classLikes' => 0, 'constants' => 0, 'functions' => 0];
            $forms = [];

            foreach ($files as $file) {
                $provenance = $file->sourceFile->dependencyProvenance;

                if ($provenance === null) {
                    continue;
                }

                $excluded = $exclusions[spl_object_id($file)] ?? [];
                $symbols = $this->filteredSymbols(
                    $this->references->collectDeclarations([$file]),
                    $excluded,
                );
                $documentCounts = [
                    'classLikes' => count($symbols['classes']),
                    'constants' => count($symbols['constants']),
                    'functions' => count($symbols['functions']),
                ];

                if (array_sum($documentCounts) === 0) {
                    continue;
                }

                foreach ($documentCounts as $name => $count) {
                    $counts[$name] += $count;
                    $totals[$name] += $count;
                }

                $forms[$provenance->autoloadForm] = true;
                $totals['conditionalDeclarations'] += $provenance->conditional
                    ? array_sum($documentCounts)
                    : 0;
                $totals['staticIncludes'] += $provenance->autoloadForm === 'include' ? 1 : 0;
                $totals['documents']++;
                $portableSource = $this->emitter->emitPortable($file, $excluded);
                $this->validator->validateSource($portableSource);
                $documents[] = [
                    'autoloadForm' => $provenance->autoloadForm,
                    'conditional' => $provenance->conditional,
                    'counts' => $documentCounts,
                    'order' => $provenance->declarationOrder,
                    'path' => $this->relativePath($provenance->packageRelativePath),
                    'source' => $portableSource,
                ];
            }

            $aliases = $this->packageAliases(
                $package,
                $declarations->classAliases,
                $declarations->classAliasProvenance,
            );
            $stableId = substr(hash('sha256', implode("\0", [
                $package->name,
                $package->version ?? '',
                $package->reference ?? '',
            ])), 0, 24);
            $shardPath = 'packages/' . $stableId . '.json';
            $shard = [
                'aliases' => $aliases,
                'autoload' => $this->portableAutoload($package),
                'counts' => [
                    ...$counts,
                    'aliases' => count($aliases),
                    'conditionalDeclarations' => array_sum(array_map(
                        static fn (array $document): int => $document['conditional'] ? array_sum($document['counts']) : 0,
                        $documents,
                    )),
                    'documents' => count($documents),
                    'staticIncludes' => count(array_filter(
                        $documents,
                        static fn (array $document): bool => $document['autoloadForm'] === 'include',
                    )),
                ],
                'declarationFormatVersion' => self::DECLARATION_FORMAT_VERSION,
                'documents' => $documents,
                'formatVersion' => self::FORMAT_VERSION,
                'package' => $this->packageIdentity($package, $packageOrder),
                'targetPhpVersion' => $targetPhpVersion,
            ];
            $json = CanonicalJson::encode($shard);
            $this->writeFile($outputDirectory, $shardPath, $json);
            $packageEntries[] = [
                ...$this->packageIdentity($package, $packageOrder),
                'autoloadForms' => array_keys($forms),
                'counts' => $shard['counts'],
                'path' => $shardPath,
                'sha256' => hash('sha256', $json),
            ];
        }

        $compatibilityIdentity = DeclarationCompatibilityIdentity::calculate();
        $producer = [
            'buildIdentity' => $this->buildIdentity->calculate(),
            // Stable repository provenance, independent of the Composer distribution name.
            'identity' => 'atatusoft-ltd/ppphp-src',
            'version' => Compiler::VERSION,
        ];
        $identityPayload = CanonicalJson::encode([
            'compatibilityIdentity' => $compatibilityIdentity,
            'declarationFormatVersion' => self::DECLARATION_FORMAT_VERSION,
            'packages' => $packageEntries,
            'producer' => $producer,
            'targetPhpVersion' => $targetPhpVersion,
        ]);
        $manifest = [
            'composerLockSha256' => $composer->composerLockIdentity,
            'declarationCompatibilityIdentity' => $compatibilityIdentity,
            'contentIdentity' => 'sha256:' . hash('sha256', $identityPayload),
            'counts' => $totals,
            'declarationFormatVersion' => self::DECLARATION_FORMAT_VERSION,
            'formatVersion' => self::FORMAT_VERSION,
            'installedMetadataSha256' => $composer->installedMetadataIdentity,
            'packages' => $packageEntries,
            'producer' => $producer,
            'targetPhpVersion' => $targetPhpVersion,
        ];
        $this->writeFile($outputDirectory, 'manifest.json', CanonicalJson::encode($manifest));

        return $manifest;
    }

    /**
     * @param array<string, ParsedFile> $files
     * @return array<int, array<string, true>>
     */
    private function declarationExclusions(array $files): array
    {
        /** @var array<string, array{fileId: int, conditional: bool, form: string}> $owners */
        $owners = [];
        $excluded = [];

        foreach ($files as $file) {
            $fileId = spl_object_id($file);
            $provenance = $file->sourceFile->dependencyProvenance;

            if ($provenance === null) {
                continue;
            }

            foreach ($this->references->collectDeclarations([$file]) as $kind => $names) {
                foreach ($names as $name) {
                    $key = $kind . ':' . strtolower(ltrim($name, '\\'));
                    $owner = $owners[$key] ?? null;

                    if ($owner === null) {
                        $owners[$key] = [
                            'fileId' => $fileId,
                            'conditional' => $provenance->conditional,
                            'form' => $provenance->autoloadForm,
                        ];
                        continue;
                    }

                    if ($owner['conditional'] && !$provenance->conditional) {
                        $excluded[$owner['fileId']][$key] = true;
                        $owners[$key] = [
                            'fileId' => $fileId,
                            'conditional' => false,
                            'form' => $provenance->autoloadForm,
                        ];
                        continue;
                    }

                    if (!$owner['conditional'] && $provenance->conditional) {
                        $excluded[$fileId][$key] = true;
                        continue;
                    }

                    $deterministicConditional = $provenance->conditional
                        && in_array($owner['form'], ['files', 'include'], true)
                        && in_array($provenance->autoloadForm, ['files', 'include'], true);
                    $deterministicClass = $kind === 'classes'
                        && !in_array($owner['form'], ['files', 'include'], true)
                        && !in_array($provenance->autoloadForm, ['files', 'include'], true);

                    if (!$deterministicConditional && !$deterministicClass) {
                        throw new \InvalidArgumentException(sprintf(
                            'Dependency declaration "%s" has no serializable runtime authority.',
                            $name,
                        ));
                    }

                    $excluded[$fileId][$key] = true;
                }
            }
        }

        return $excluded;
    }

    /**
     * @param array{classes: list<string>, functions: list<string>, constants: list<string>} $symbols
     * @param array<string, true> $excluded
     * @return array{classes: list<string>, functions: list<string>, constants: list<string>}
     */
    private function filteredSymbols(array $symbols, array $excluded): array
    {
        foreach ($symbols as $kind => $names) {
            $symbols[$kind] = array_values(array_filter(
                $names,
                static fn (string $name): bool => !isset($excluded[$kind . ':' . strtolower(ltrim($name, '\\'))]),
            ));
        }

        return $symbols;
    }

    /** @return array<string, mixed> */
    private function packageIdentity(ComposerPackage $package, int $order): array
    {
        return [
            'developmentOnly' => $package->developmentOnly,
            'name' => $package->name,
            'order' => $order,
            'prettyVersion' => $package->prettyVersion,
            'reference' => $package->reference,
            'type' => $package->type,
            'version' => $package->version,
        ];
    }

    /** @return array<string, mixed> */
    private function portableAutoload(ComposerPackage $package): array
    {
        return [
            'classmap' => $this->relativePaths($package->autoload->classmap, $package),
            'excludeFromClassmap' => $this->relativePaths($package->autoload->excludeFromClassmap, $package),
            'files' => $this->relativePaths($package->autoload->files, $package),
            'psr0' => $this->relativeMappings($package->autoload->psr0, $package),
            'psr4' => $this->relativeMappings($package->autoload->psr4, $package),
        ];
    }

    /**
     * @param array<string, list<string>> $mappings
     * @return array<string, list<string>>
     */
    private function relativeMappings(array $mappings, ComposerPackage $package): array
    {
        $portable = [];

        foreach ($mappings as $prefix => $paths) {
            $portable[$prefix] = $this->relativePaths($paths, $package);
        }

        return $portable;
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function relativePaths(array $paths, ComposerPackage $package): array
    {
        return array_map(function (string $path) use ($package): string {
            $relative = Path::makeRelative($path, $package->installPath);

            if ($relative === null || str_starts_with($relative, '../')) {
                throw new \RuntimeException(sprintf('Dependency path for package "%s" is not portable.', $package->name));
            }

            return $this->relativePath($relative);
        }, $paths);
    }

    /**
     * @param array<string, string> $aliases
     * @param array<string, \Atatusoft\Ppphp\Interop\Composer\DependencyDeclarationProvenance> $provenance
     * @return array<string, array{autoloadForm: string, order: int, original: string, path: string}>
     */
    private function packageAliases(ComposerPackage $package, array $aliases, array $provenance): array
    {
        $result = [];

        foreach ($aliases as $alias => $original) {
            $origin = $provenance[$alias] ?? null;

            if ($origin !== null && strcasecmp($origin->packageName, $package->name) === 0) {
                $result[$alias] = [
                    'autoloadForm' => $origin->autoloadForm,
                    'order' => $origin->declarationOrder,
                    'original' => $original,
                    'path' => $this->relativePath($origin->packageRelativePath),
                ];
            }
        }

        ksort($result, SORT_STRING);

        return $result;
    }

    private function relativePath(string $path): string
    {
        $path = Path::normalize($path);

        if (Path::isAbsolute($path) || $path === '..' || str_starts_with($path, '../')) {
            throw new \RuntimeException('A dependency index path must be package-relative.');
        }

        return str_replace('\\', '/', $path);
    }

    private function prepareOutput(string $outputDirectory): void
    {
        if ($this->filesystem->checkExists($outputDirectory)
            && !$this->filesystem->checkIsDirectory($outputDirectory)) {
            throw new \RuntimeException('The dependency index output must be a regular directory.');
        }

        $this->filesystem->createDirectory(Path::join($outputDirectory, 'packages'));
    }

    private function writeFile(string $root, string $relativePath, string $contents): void
    {
        $this->filesystem->writeFile(Path::join($root, $relativePath), $contents, 0600);
    }

    private function replaceOutput(
        string $output,
        string $candidate,
        string $backup,
        string $targetPhpVersion,
        string $token,
    ): void {
        $hadOutput = $this->filesystem->checkExists($output);

        if ($hadOutput) {
            if (!$this->filesystem->checkIsDirectory($output)) {
                throw new \RuntimeException('The previous dependency index could not be backed up safely.');
            }

            $this->writeMarker($output, basename($output), $token, 'previous-output');
            $this->filesystem->move($output, $backup);
        }

        $this->filesystem->move($candidate, $output);
        $verification = $this->reader->read(Path::join($output, 'manifest.json'), $targetPhpVersion);

        if (!$verification->isSuccessful) {
            throw new \RuntimeException('The committed dependency index failed independent verification.');
        }

        if ($hadOutput) {
            if (!$this->markerMatches($backup, basename($output), $token, 'previous-output')) {
                throw new \RuntimeException('The previous dependency index backup cannot be identified safely.');
            }

            $this->filesystem->remove($backup);
        }

        $this->removeMarker($output);
    }

    private function recoverTransactions(string $output, string $targetPhpVersion): void
    {
        $parent = dirname($output);
        $name = basename($output);

        if (!$this->filesystem->checkIsDirectory($parent)) {
            return;
        }

        $tokens = [];

        foreach (new \DirectoryIterator($parent) as $entry) {
            if ($entry->isDot()
                || !$entry->isDir()
                || $entry->isLink()) {
                continue;
            }

            foreach (['candidate', 'backup'] as $role) {
                $prefix = '.' . $name . '.' . $role . '-';

                if (str_starts_with($entry->getFilename(), $prefix)) {
                    $token = substr($entry->getFilename(), strlen($prefix));

                    if (preg_match('/^[a-f0-9]{24}$/D', $token) === 1) {
                        $tokens[$token] = true;
                    }
                }
            }
        }

        $outputMarker = $this->readMarker($output);

        if ($outputMarker !== null && ($outputMarker['output'] ?? null) === $name) {
            $token = $outputMarker['token'] ?? null;

            if (is_string($token) && preg_match('/^[a-f0-9]{24}$/D', $token) === 1) {
                $tokens[$token] = true;
            }
        }

        ksort($tokens, SORT_STRING);

        foreach (array_keys($tokens) as $token) {
            $candidate = Path::join($parent, '.' . $name . '.candidate-' . $token);
            $backup = Path::join($parent, '.' . $name . '.backup-' . $token);
            $candidateOwned = $this->markerMatches($candidate, $name, $token, 'candidate');
            $backupOwned = $this->markerMatches($backup, $name, $token, 'previous-output');
            $outputCandidate = $this->markerMatches($output, $name, $token, 'candidate');
            $outputPrevious = $this->markerMatches($output, $name, $token, 'previous-output');

            if ($outputCandidate) {
                $verification = $this->reader->read(Path::join($output, 'manifest.json'), $targetPhpVersion);

                if (!$verification->isSuccessful) {
                    if (!$backupOwned) {
                        throw new \RuntimeException('An interrupted dependency index candidate is invalid and has no valid backup.');
                    }

                    $this->filesystem->remove($output);
                    $this->filesystem->move($backup, $output);
                    $this->removeMarker($output);
                } else {
                    if ($backupOwned) {
                        $this->filesystem->remove($backup);
                    }

                    $this->removeMarker($output);
                }

                if ($candidateOwned) {
                    $this->filesystem->remove($candidate);
                }

                continue;
            }

            if ($outputPrevious) {
                if ($candidateOwned) {
                    $this->filesystem->remove($candidate);
                }

                $this->removeMarker($output);
                continue;
            }

            if (!$this->filesystem->checkExists($output) && $backupOwned) {
                $this->filesystem->move($backup, $output);
                $this->removeMarker($output);

                if ($candidateOwned) {
                    $this->filesystem->remove($candidate);
                }

                continue;
            }

            if ($candidateOwned) {
                $this->filesystem->remove($candidate);
            }

            if ($backupOwned) {
                throw new \RuntimeException('An interrupted dependency index backup cannot be selected unambiguously.');
            }
        }
    }

    private function writeMarker(string $root, string $output, string $token, string $role): void
    {
        $this->filesystem->writeFileAtomically(
            Path::join($root, self::TRANSACTION_MARKER),
            CanonicalJson::encode([
                'formatVersion' => self::TRANSACTION_FORMAT_VERSION,
                'output' => $output,
                'role' => $role,
                'token' => $token,
            ]),
            0600,
        );
    }

    private function markerMatches(string $root, string $output, string $token, string $role): bool
    {
        $marker = $this->readMarker($root);

        return $marker !== null
            && ($marker['formatVersion'] ?? null) === self::TRANSACTION_FORMAT_VERSION
            && ($marker['output'] ?? null) === $output
            && ($marker['role'] ?? null) === $role
            && ($marker['token'] ?? null) === $token;
    }

    /** @return array<string, mixed>|null */
    private function readMarker(string $root): ?array
    {
        $path = Path::join($root, self::TRANSACTION_MARKER);

        try {
            if (!$this->filesystem->checkIsFile($path)) {
                return null;
            }

            $contents = $this->filesystem->readFileBounded($path, self::MAXIMUM_MARKER_BYTES);
            $marker = CanonicalJson::decode($contents);

            if (!is_array($marker)
                || array_is_list($marker)
                || array_keys($marker) !== ['formatVersion', 'output', 'role', 'token']
                || CanonicalJson::encode($marker) !== $contents) {
                return null;
            }

            return [
                'formatVersion' => $marker['formatVersion'],
                'output' => $marker['output'],
                'role' => $marker['role'],
                'token' => $marker['token'],
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private function removeMarker(string $root): void
    {
        $path = Path::join($root, self::TRANSACTION_MARKER);

        if ($this->filesystem->checkExists($path)) {
            $this->filesystem->remove($path);
        }
    }
}
