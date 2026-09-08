<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Cache;

use Atatusoft\Ppphp\Analysis\Capability\AnalysisCapabilityCatalog;
use Atatusoft\Ppphp\Compiler\Compiler;
use Atatusoft\Ppphp\Compiler\Manifest\BuildManifest;
use Atatusoft\Ppphp\Interop\Composer\Index\DependencyDeclarationIndexWriter;
use Atatusoft\Ppphp\Project\Project;
use Atatusoft\Ppphp\Project\SourceSet;
use Atatusoft\Ppphp\Support\Path;
use Atatusoft\Ppphp\Transpilation\SourceMapWriter;

final readonly class ProjectInputSnapshotBuilder
{
    public function __construct(private CompilerBuildIdentity $buildIdentity = new CompilerBuildIdentity()) {}

    public function build(Project $project, SourceSet $selectedSources): ProjectInputSnapshot
    {
        $configuration = $project->configuration;
        $sources = [];

        foreach ($project->sources as $source) {
            $sources[] = $this->fileIdentity(
                $source->path,
                $source->displayPath,
                $source->kind->value,
                true,
            );
        }

        $stubs = [];

        foreach ($project->stubs as $stub) {
            $relative = Path::resolveRelativeTo($stub->path, $configuration->projectRoot);
            $stubs[] = $this->fileIdentity($stub->path, $relative, 'stub', false);
        }

        $selected = array_map(
            static fn ($source): string => str_replace('\\', '/', $source->displayPath),
            $selectedSources->files,
        );
        $metadata = [];

        foreach ([
            'configuration' => $configuration->configurationPath,
            'composer' => $project->composer->configurationPath,
            'composerLock' => Path::join($configuration->projectRoot, 'composer.lock'),
            'installed' => Path::join($project->composer->vendorPath, 'composer/installed.json'),
        ] as $name => $path) {
            if ($path !== null && is_file($path) && !is_link($path)) {
                $metadata[$name] = $this->hashFile($path);
            }
        }

        $dependencyFiles = [];

        foreach ($project->composer->dependencies as $package) {
            foreach ($package->autoload->paths as $path) {
                foreach ($this->discoverPhpFiles($path) as $file) {
                    $relative = Path::makeRelative($file, $package->installPath);

                    if ($relative !== null && !str_starts_with($relative, '../')) {
                        $key = strtolower($package->name . "\0" . str_replace('\\', '/', $relative));
                        $dependencyFiles[$key] = [
                            'package' => $package->name,
                            'path' => str_replace('\\', '/', $relative),
                            'sha256' => $this->hashFile($file),
                        ];
                    }
                }
            }
        }

        $dependencyFiles = array_values($dependencyFiles);
        usort($dependencyFiles, static fn (array $left, array $right): int =>
            ($left['package'] <=> $right['package']) ?: ($left['path'] <=> $right['path']));
        $signatureRoot = dirname(__DIR__, 2) . '/resources/php-signatures/' . $configuration->targetPhpVersion;

        return new ProjectInputSnapshot([
            'analyzerCatalogVersion' => AnalysisCapabilityCatalog::VERSION,
            'compiler' => [
                'buildIdentity' => $this->buildIdentity->calculate(),
                'name' => Compiler::NAME,
                'version' => Compiler::VERSION,
            ],
            'composer' => [
                'dependencyFiles' => $dependencyFiles,
                'installedMetadataIdentity' => $project->composer->installedMetadataIdentity,
                'lockIdentity' => $project->composer->composerLockIdentity,
                'metadata' => $metadata,
            ],
            'configuration' => [
                'cache' => $this->relative($configuration->cachePath, $configuration->projectRoot),
                'excludedPaths' => $this->relativePaths($configuration->excludedPaths, $configuration->projectRoot),
                'output' => $this->relative($configuration->outputPath, $configuration->projectRoot),
                'sourceRoots' => $this->relativePaths($configuration->sourceRoots, $configuration->projectRoot),
                'targetPhpVersion' => $configuration->targetPhpVersion,
            ],
            'formats' => [
                'artifact' => CacheFormat::ARTIFACT,
                'buildManifest' => BuildManifest::FORMAT_VERSION,
                'cache' => CacheFormat::COMPILER,
                'declaration' => DependencyDeclarationIndexWriter::DECLARATION_FORMAT_VERSION,
                'dependencyIndex' => DependencyDeclarationIndexWriter::FORMAT_VERSION,
                'diagnostic' => CacheFormat::DIAGNOSTIC,
                'lowering' => Compiler::LOWERING_FORMAT_VERSION,
                'sourceMap' => SourceMapWriter::FORMAT_VERSION,
            ],
            'phpSignatures' => [
                'manifest' => $this->hashFile($signatureRoot . '/manifest.json'),
                'overrides' => $this->hashFile($signatureRoot . '/overrides.json'),
            ],
            'selectedSources' => $selected,
            'sources' => $sources,
            'stubs' => $stubs,
        ]);
    }

    /** @return array{kind: string, mode: string|null, path: string, sha256: string} */
    private function fileIdentity(string $path, string $displayPath, string $kind, bool $withMode): array
    {
        $permissions = $withMode && is_file($path) && !is_link($path) ? @fileperms($path) : false;

        return [
            'kind' => $kind,
            'mode' => $permissions === false ? null : sprintf('%04o', $permissions & 0777),
            'path' => str_replace('\\', '/', Path::normalize($displayPath)),
            'sha256' => $this->hashFile($path),
        ];
    }

    private function hashFile(string $path): string
    {
        if (!is_file($path) || is_link($path)) {
            throw new \RuntimeException(sprintf('Snapshot input "%s" is not a regular file.', basename($path)));
        }

        $hash = hash_file('sha256', $path);

        if (!is_string($hash)) {
            throw new \RuntimeException(sprintf('Snapshot input "%s" could not be hashed.', basename($path)));
        }

        return 'sha256:' . $hash;
    }

    /** @return list<string> */
    private function discoverPhpFiles(string $path): array
    {
        if (is_file($path) && !is_link($path)) {
            return str_ends_with(strtolower($path), '.php') ? [$path] : [];
        }

        if (!is_dir($path) || is_link($path)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $path,
            \FilesystemIterator::SKIP_DOTS,
        ));

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }

            if ($file->isFile() && !$file->isLink() && str_ends_with(strtolower($file->getFilename()), '.php')) {
                $files[] = Path::normalize($file->getPathname());
            }
        }

        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function relativePaths(array $paths, string $root): array
    {
        $relative = array_map(fn (string $path): string => $this->relative($path, $root), $paths);
        sort($relative, SORT_STRING);

        return $relative;
    }

    private function relative(string $path, string $root): string
    {
        return str_replace('\\', '/', Path::resolveRelativeTo($path, $root));
    }
}
