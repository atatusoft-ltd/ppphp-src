<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Compiler;

use Atatusoft\Ppphp\Cache\CompilerCache;
use Atatusoft\Ppphp\Compiler\Enumerations\CompilationFailureKind;
use Atatusoft\Ppphp\Compiler\Output\AtomicBuildCommitter;
use Atatusoft\Ppphp\Compiler\Output\BuildOutputException;
use Atatusoft\Ppphp\Compiler\Output\BuildTransactionRecovery;
use Atatusoft\Ppphp\Compiler\Output\OutputPlanner;
use Atatusoft\Ppphp\Compiler\Output\ProjectBuildLock;
use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Interop\Composer\ComposerRuntimeConfigurator;
use Atatusoft\Ppphp\Project\Project;
use Atatusoft\Ppphp\Project\ProjectChecker;
use Atatusoft\Ppphp\Project\ProjectSelection;
use Atatusoft\Ppphp\Project\Enumerations\SelectionKind;
use Atatusoft\Ppphp\Transpilation\Emission\ProductionPhpEmitter;

final readonly class Compiler
{
    public const string NAME = 'ppphp';

    public const string COMPOSER_PACKAGE = 'atatusoft/ppphp';

    public const string VERSION = '2026.3.1-rc-2';

    public const int LOWERING_FORMAT_VERSION = 1;

    public function __construct(
        private ProjectChecker $checker = new ProjectChecker(),
        private OutputPlanner $outputPlanner = new OutputPlanner(),
        private ProductionPhpEmitter $emitter = new ProductionPhpEmitter(),
        private AtomicBuildCommitter $committer = new AtomicBuildCommitter(),
        private ComposerRuntimeConfigurator $composerRuntimeConfigurator = new ComposerRuntimeConfigurator(),
        private ProjectBuildLock $buildLock = new ProjectBuildLock(),
        private CompilerCache $cache = new CompilerCache(),
        private BuildTransactionRecovery $transactionRecovery = new BuildTransactionRecovery(),
    ) {}

    public function compile(Project $project, ProjectSelection $selection): CompilationResult
    {
        try {
            $acquired = $this->buildLock->acquire($project->configuration);
        } catch (\Throwable $exception) {
            return $this->createOutputFailure(new Diagnostic(
                DiagnosticCode::BuildCouldNotBeStaged,
                'The compiler could not create the stable project operation lock.',
                help: 'Check that the project root is writable and .ppphp-operation.lock is not a symbolic link.',
                debug: ['exception' => $exception::class, 'message' => $exception->getMessage()],
            ));
        }

        if (!$acquired) {
            return $this->createOutputFailure(new Diagnostic(
                DiagnosticCode::BuildIsAlreadyInProgress,
                'Another build or clean transaction already owns this project build lock.',
                help: 'Wait for the active compiler operation to finish, then run the command again.',
            ));
        }

        try {
            try {
                $this->transactionRecovery->recover($project->configuration);
            } catch (BuildOutputException $exception) {
                return $this->createOutputFailure(new Diagnostic(
                    $exception->diagnosticCode,
                    $exception->getMessage(),
                    help: $exception->diagnosticHelp,
                    debug: [
                        'exception' => $exception::class,
                        'message' => $exception->getPrevious()?->getMessage() ?? $exception->getMessage(),
                    ],
                ));
            }

            $snapshot = null;

            try {
                $snapshot = $this->cache->snapshot($project, $selection->analysisSources);
                $bundle = $this->cache->loadArtifactBundle($project, $selection, $snapshot);
            } catch (\Throwable) {
                $bundle = null;
            }

            if ($bundle !== null && $this->cache->currentOutputIsValid($project, $selection, $bundle)) {
                $this->recordBuildReuse($selection, count($bundle->artifacts));

                return new CompilationResult(
                    $bundle->artifacts,
                    $bundle->manifest,
                    0,
                    true,
                    null,
                    $bundle->diagnostics,
                    $this->cache->statistics,
                    true,
                );
            }

            if ($bundle !== null && (
                !$this->pathExists($project->configuration->outputPath)
                || $selection->kind === SelectionKind::Project
            )) {
                $commit = $this->committer->commit($project, $selection, $bundle->artifacts);
                $diagnostics = new DiagnosticBag();
                $diagnostics->addAll($bundle->diagnostics);
                $diagnostics->addAll($commit->diagnostics);

                if ($commit->committed && $commit->manifest !== null) {
                    $this->recordBuildReuse($selection, count($bundle->artifacts));

                    return new CompilationResult(
                        $bundle->artifacts,
                        $commit->manifest,
                        $commit->staleRemovalCount,
                        true,
                        null,
                        $diagnostics,
                        $this->cache->statistics,
                    );
                }
            }

            $check = $this->checker->check($project, $selection->analysisSources, false, false);
            $diagnostics = new DiagnosticBag();
            $diagnostics->addAll($check->diagnostics);

            if (!$check->isSuccessful) {
                return new CompilationResult([], null, 0, false, CompilationFailureKind::Source, $diagnostics, $this->cache->statistics);
            }

            $plan = $this->outputPlanner->plan($project, $selection->outputSources);
            $diagnostics->addAll($plan->diagnostics);

            if (!$plan->isSuccessful || $plan->plan === null) {
                return new CompilationResult([], null, 0, false, CompilationFailureKind::Output, $diagnostics, $this->cache->statistics);
            }

            $this->addComposerWarnings($project, $diagnostics);
            $reusedArtifacts = $snapshot === null
                ? []
                : $this->cache->loadReusableArtifacts($project, $snapshot, $check, $plan->plan);
            $artifacts = $this->emitter->emit($project, $check, $plan->plan, $reusedArtifacts);
            $commit = $this->committer->commit($project, $selection, $artifacts);
            $diagnostics->addAll($commit->diagnostics);

            if (!$commit->committed || $commit->manifest === null) {
                return new CompilationResult($artifacts, null, 0, false, CompilationFailureKind::Output, $diagnostics, $this->cache->statistics);
            }

            if ($snapshot !== null) {
                $this->cache->storeArtifactBundle(
                    $project,
                    $selection,
                    $snapshot,
                    $artifacts,
                    $commit->manifest,
                    $diagnostics,
                    $check,
                );
            }

            return new CompilationResult(
                $artifacts,
                $commit->manifest,
                $commit->staleRemovalCount,
                true,
                null,
                $diagnostics,
                $this->cache->statistics,
            );
        } finally {
            $this->buildLock->release();
        }
    }

    private function addComposerWarnings(Project $project, DiagnosticBag $diagnostics): void
    {
        if ($project->composer->configurationPath === null) {
            return;
        }

        $projection = $this->composerRuntimeConfigurator->project($project->configuration);

        foreach ($projection->unprojectedMappings as $mapping) {
            $diagnostics->add(new Diagnostic(
                DiagnosticCode::ComposerAutoloadDoesNotTargetBuildOutput,
                sprintf(
                    'Composer entry "%s.%s" still targets source path "%s"; its generated runtime path is "%s".',
                    $mapping->section,
                    $mapping->entry,
                    $mapping->sourcePath,
                    $mapping->expectedPath,
                ),
                help: 'Run ppphp composer:configure, then composer update --lock and composer dump-autoload.',
            ));
        }
    }

    private function createOutputFailure(Diagnostic $diagnostic): CompilationResult
    {
        $diagnostics = new DiagnosticBag();
        $diagnostics->add($diagnostic);

        return new CompilationResult([], null, 0, false, CompilationFailureKind::Output, $diagnostics, $this->cache->statistics);
    }

    private function pathExists(string $path): bool
    {
        return file_exists($path) || is_link($path);
    }

    private function recordBuildReuse(ProjectSelection $selection, int $artifactCount): void
    {
        $this->cache->statistics->parserWorkAvoided += count($selection->analysisSources);
        $this->cache->statistics->semanticWorkAvoided++;
        $this->cache->statistics->loweringWorkAvoided += $artifactCount;
        $this->cache->statistics->supplementalProcessesAvoided++;
    }
}
