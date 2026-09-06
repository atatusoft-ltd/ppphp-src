<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Project;

use Atatusoft\Ppphp\Compiler\Output\ProjectBuildLock;
use Atatusoft\Ppphp\Compiler\Output\BuildOutputException;
use Atatusoft\Ppphp\Compiler\Output\BuildTransactionRecovery;
use Atatusoft\Ppphp\Config\ProjectConfig;
use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Support\Path;

final class ProjectCleaner
{
    public function __construct(
        private readonly ProjectBuildLock $buildLock = new ProjectBuildLock(),
        private readonly BuildTransactionRecovery $transactionRecovery = new BuildTransactionRecovery(),
    ) {}

    public function clean(ProjectConfig $configuration, bool $dryRun = false): ProjectCleanupResult
    {
        $diagnostics = new DiagnosticBag();
        $ownedPaths = [$configuration->outputPath];
        $cacheExisted = file_exists($configuration->cachePath) || is_link($configuration->cachePath);
        $stagedCachePath = null;

        $this->validate($configuration, $diagnostics);

        if ($diagnostics->hasErrors) {
            return new ProjectCleanupResult([], $diagnostics);
        }

        try {
            $acquired = $this->buildLock->acquire($configuration);
        } catch (\Throwable $exception) {
            $diagnostics->add(new Diagnostic(
                DiagnosticCode::BuildCouldNotBeStaged,
                'The compiler could not create the stable project operation lock for cleanup.',
                help: 'Check that the project root is writable and .ppphp-operation.lock is not a symbolic link.',
                debug: ['exception' => $exception::class, 'message' => $exception->getMessage()],
            ));

            return new ProjectCleanupResult([], $diagnostics);
        }

        if (!$acquired) {
            $diagnostics->add(new Diagnostic(
                DiagnosticCode::BuildIsAlreadyInProgress,
                'Cleanup cannot remove output or cache directories while a build is active.',
                help: 'Wait for the active compiler operation to finish, then run clean again.',
            ));

            return new ProjectCleanupResult([], $diagnostics);
        }

        $paths = [];

        try {
            try {
                $this->transactionRecovery->recover($configuration);
            } catch (BuildOutputException $exception) {
                $diagnostics->add(new Diagnostic(
                    $exception->diagnosticCode,
                    $exception->getMessage(),
                    help: $exception->diagnosticHelp,
                    debug: [
                        'exception' => $exception::class,
                        'message' => $exception->getPrevious()?->getMessage() ?? $exception->getMessage(),
                    ],
                ));

                return new ProjectCleanupResult([], $diagnostics);
            }

            foreach ($ownedPaths as $path) {
                if (!file_exists($path) && !is_link($path)) {
                    continue;
                }

                $paths[] = $path;

                if ($dryRun) {
                    continue;
                }

                try {
                    $this->remove($path);
                } catch (\RuntimeException $exception) {
                    $diagnostics->add(new Diagnostic(
                        DiagnosticCode::ProjectCleanupFailed,
                        sprintf(
                            'The output or cache path "%s" could not be removed.',
                            Path::resolveRelativeTo($path, $configuration->projectRoot),
                        ),
                        help: 'Check the path permissions and ownership, then run clean again.',
                        debug: ['exception' => $exception::class, 'message' => $exception->getMessage()],
                    ));
                }
            }

            if ($cacheExisted) {
                $paths[] = $configuration->cachePath;
            }

            if ((!$dryRun || !$cacheExisted) && is_dir($configuration->cachePath)) {
                try {
                    $stagedCachePath = $this->stageCacheRemoval($configuration->cachePath);
                } catch (\RuntimeException $exception) {
                    $diagnostics->add(new Diagnostic(
                        DiagnosticCode::ProjectCleanupFailed,
                        'The compiler cache could not be detached safely for removal.',
                        help: 'Check the cache directory permissions, then run clean again.',
                        debug: ['exception' => $exception::class, 'message' => $exception->getMessage()],
                    ));
                }
            }
        } finally {
            $this->buildLock->release();

            if ($stagedCachePath !== null) {
                try {
                    $this->remove($stagedCachePath);
                } catch (\RuntimeException $exception) {
                    $diagnostics->add(new Diagnostic(
                        DiagnosticCode::ProjectCleanupFailed,
                        'The detached compiler cache could not be removed after cleanup.',
                        help: 'Check the cache directory permissions and remove the detached cache after confirming no compiler operation is active.',
                        debug: ['exception' => $exception::class, 'message' => $exception->getMessage()],
                    ));
                }
            }
        }

        return new ProjectCleanupResult($paths, $diagnostics);
    }

    private function stageCacheRemoval(string $cachePath): string
    {
        $stagedPath = Path::join(
            dirname($cachePath),
            '.' . basename($cachePath) . '-cleanup-' . bin2hex(random_bytes(12)),
        );

        if (file_exists($stagedPath) || is_link($stagedPath) || !@rename($cachePath, $stagedPath)) {
            throw new \RuntimeException('The compiler cache could not be renamed for safe cleanup.');
        }

        return $stagedPath;
    }

    private function validate(ProjectConfig $configuration, DiagnosticBag $diagnostics): void
    {
        $ownedPaths = [
            'output' => $configuration->outputPath,
            'cache' => $configuration->cachePath,
        ];

        foreach ($ownedPaths as $name => $path) {
            if (
                !Path::contains($configuration->projectRoot, $path)
                || Path::buildComparisonKey($configuration->projectRoot) === Path::buildComparisonKey($path)
                || Path::hasSymlinkAncestor($path, $configuration->projectRoot)
            ) {
                $this->addUnsafeDiagnostic($name, $diagnostics);
            }

            if (Path::contains($path, $configuration->configurationPath)) {
                $this->addOverlapDiagnostic($name, 'configuration', $diagnostics);
            }

            foreach ($configuration->sourceRoots as $sourceRoot) {
                if (Path::overlaps($path, $sourceRoot)) {
                    $this->addOverlapDiagnostic($name, 'source', $diagnostics);
                }
            }

            foreach ($configuration->stubPaths as $stubPath) {
                if (Path::overlaps($path, $stubPath)) {
                    $this->addOverlapDiagnostic($name, 'stubs', $diagnostics);
                }
            }
        }

        if (Path::overlaps($configuration->outputPath, $configuration->cachePath)) {
            $this->addOverlapDiagnostic('output', 'cache', $diagnostics);
        }
    }

    private function remove(string $path): void
    {
        if (is_link($path) || (file_exists($path) && !is_dir($path))) {
            if (!@unlink($path)) {
                throw new \RuntimeException(sprintf('Unable to unlink "%s".', $path));
            }

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $directory = new \DirectoryIterator($path);

        foreach ($directory as $entry) {
            if ($entry->isDot()) {
                continue;
            }

            $entryPath = $entry->getPathname();

            if ($entry->isLink() || $entry->isFile()) {
                if (!@unlink($entryPath)) {
                    throw new \RuntimeException(sprintf('Unable to unlink "%s".', $entryPath));
                }

                continue;
            }

            if ($entry->isDir()) {
                $this->remove($entryPath);
            }
        }

        if (!@rmdir($path)) {
            throw new \RuntimeException(sprintf('Unable to remove directory "%s".', $path));
        }
    }

    private function addUnsafeDiagnostic(string $pathName, DiagnosticBag $diagnostics): void
    {
        $diagnostics->add(new Diagnostic(
            DiagnosticCode::UnsafeProjectPath,
            sprintf('The configured %s path is not safe to remove.', $pathName),
        ));
    }

    private function addOverlapDiagnostic(
        string $first,
        string $second,
        DiagnosticBag $diagnostics,
    ): void {
        $diagnostics->add(new Diagnostic(
            DiagnosticCode::ConfiguredPathsOverlap,
            sprintf('The configured %s and %s paths overlap.', $first, $second),
        ));
    }
}
