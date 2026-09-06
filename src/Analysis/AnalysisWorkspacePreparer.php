<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis;

use Atatusoft\Ppphp\Analysis\Declaration\DeclarationOrigin;
use Atatusoft\Ppphp\Analysis\Exceptions\AnalysisWorkspaceException;
use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Project\Project;
use Atatusoft\Ppphp\Project\ProjectParseResult;
use Atatusoft\Ppphp\Project\ProjectSource;
use Atatusoft\Ppphp\Semantic\SemanticAnalyzer;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Support\Path;
use Atatusoft\Ppphp\Transpilation\GeneratedPhp;
use Atatusoft\Ppphp\Transpilation\GeneratedSourceMap;
use Atatusoft\Ppphp\Transpilation\PhpLowerer;

final readonly class AnalysisWorkspacePreparer
{
    public function __construct(
        private SemanticAnalyzer $semanticAnalyzer = new SemanticAnalyzer(),
        private PhpLowerer $lowerer = new PhpLowerer(),
        private DeclarationContextEmitter $declarationEmitter = new DeclarationContextEmitter(),
    ) {}

    public function prepare(CompilerProjectAnalysis $analysis): AnalysisPreparationResult
    {
        $project = $analysis->project;
        $selectedSources = $analysis->selectedSources;
        $selectedParseResult = $analysis->parseResult;
        $selectedSemanticResult = $analysis->semanticResult;
        $declarationContext = $analysis->declarationContext;
        $diagnostics = new DiagnosticBag();
        $selectedFiles = [];
        $contextFiles = [];
        $workspace = Path::join($project->configuration->cachePath, 'analysis');
        $loweringContext = $this->contextForDeclarationLowering($declarationContext);

        try {
            $this->guardWorkspace($project, $workspace);
            $this->resetDirectory($workspace);

            foreach ($project->sources as $source) {
                $selected = $selectedSources->contains($source);

                if (!$selected && $declarationContext->findParsedFile($source->path) === null) {
                    continue;
                }

                $parseResult = $selected
                    ? $selectedParseResult
                    : $this->resolveContextParseResult($source, $declarationContext);

                if (!$parseResult->isSuccessful) {
                    continue;
                }

                $semanticResult = $selected
                    ? $selectedSemanticResult
                    : $this->semanticAnalyzer->analyze($parseResult, $loweringContext);

                if ($semanticResult === null) {
                    throw new \LogicException('Supplemental analysis requires a successful compiler analysis.');
                }

                $sourceFile = $parseResult->findSourceFile($source->path);
                $parsedFile = $parseResult->findParsedFile($source->path);

                if ($sourceFile === null || $parsedFile === null) {
                    continue;
                }

                if ($source->kind === FileKind::Ppphp) {
                    $model = $semanticResult->findModel($source->path);

                    if ($model === null) {
                        continue;
                    }

                    $loweringModel = !$selected && !$semanticResult->isSuccessful
                        ? new \Atatusoft\Ppphp\Semantic\SemanticModel(
                            $model->parsedFile,
                            $model->bindings,
                            new DiagnosticBag(),
                            $model->errorContracts,
                            $model->whenExpressions,
                        )
                        : $model;
                    $generated = $this->lowerer->lower($parsedFile, $loweringModel);

                    if (!$selected && !$semanticResult->isSuccessful) {
                        $generated = $this->declarationEmitter->emit($sourceFile, $generated->contents);
                    }
                } else {
                    $generated = new GeneratedPhp(
                        $sourceFile->contents,
                        GeneratedSourceMap::createIdentity($sourceFile),
                        [],
                    );
                }

                $analysisPath = $this->resolveAnalysisPath($workspace, $source, $selected);
                $this->writeFile($analysisPath, $generated->contents);
                $analysisFile = new AnalysisFile(
                    $sourceFile,
                    $analysisPath,
                    $generated->contents,
                    $source->kind,
                    $selected,
                    new AnalysisSourceMap($analysisPath, $generated->contents, $generated->sourceMap),
                );

                if ($selected) {
                    $selectedFiles[] = $analysisFile;
                } else {
                    $contextFiles[] = $analysisFile;
                }
            }

            $stubFiles = $this->copyStubs($project, $workspace);
            [$composerScanFiles, $composerScanDirectories] = $this->resolveComposerContext($project, $declarationContext);
            $analysisProject = new AnalysisProject(
                $project->configuration->projectRoot,
                $workspace,
                $selectedFiles,
                $contextFiles,
                $stubFiles,
                $composerScanFiles,
                $composerScanDirectories,
                $project->configuration->targetPhpVersion,
            );
            $this->writeMaps($analysisProject);
        } catch (AnalysisWorkspaceException $exception) {
            $diagnostics->add(new Diagnostic(
                DiagnosticCode::AnalysisWorkspacePreparationFailed,
                $exception->getMessage(),
                help: $exception->help,
                debug: ['exception' => $exception::class, 'message' => $exception->getMessage()],
            ));
            $analysisProject = null;
        } catch (\Throwable $exception) {
            $diagnostics->add(new Diagnostic(
                DiagnosticCode::InternalCompilerError,
                'The compiler encountered an unexpected error while preparing your code for analysis.',
                debug: ['exception' => $exception::class, 'message' => $exception->getMessage()],
            ));
            $analysisProject = null;
        }

        return new AnalysisPreparationResult(
            $analysisProject,
            $diagnostics,
        );
    }

    private function resolveContextParseResult(
        ProjectSource $source,
        ProjectParseResult $declarationContext,
    ): ProjectParseResult {
        $parsedFile = $declarationContext->findParsedFile($source->path);
        $sourceFile = $declarationContext->findSourceFile($source->path);

        if ($parsedFile === null || $sourceFile === null) {
            return new ProjectParseResult([], [], new DiagnosticBag());
        }

        $key = Path::buildComparisonKey($source->path);

        return new ProjectParseResult(
            [$key => $parsedFile],
            [$key => $sourceFile],
            new DiagnosticBag(),
        );
    }

    private function contextForDeclarationLowering(ProjectParseResult $context): ProjectParseResult
    {
        $parsedFiles = [];
        $sourceFiles = [];

        foreach ($context->sourceFiles as $key => $sourceFile) {
            if (in_array($sourceFile->declarationOrigin, [
                DeclarationOrigin::ComposerDependency,
                DeclarationOrigin::ConditionalComposerDependency,
                DeclarationOrigin::PhpPlatform,
            ], true)) {
                continue;
            }

            $parsedFile = $context->parsedFiles[$key] ?? null;

            if ($parsedFile !== null) {
                $parsedFiles[$key] = $parsedFile;
                $sourceFiles[$key] = $sourceFile;
            }
        }

        return new ProjectParseResult($parsedFiles, $sourceFiles, new DiagnosticBag());
    }

    private function guardWorkspace(Project $project, string $workspace): void
    {
        if (
            !Path::contains($project->configuration->cachePath, $workspace)
            || !Path::contains($project->configuration->projectRoot, $workspace)
            || Path::buildComparisonKey($workspace) === Path::buildComparisonKey($project->configuration->cachePath)
        ) {
            throw new AnalysisWorkspaceException('The analysis directory is outside the project cache.', 'Configure a cache directory inside the project.');
        }
    }

    private function resetDirectory(string $path): void
    {
        if (is_link($path)) {
            throw new AnalysisWorkspaceException('The analysis directory is a symbolic link.', 'Use a real directory for the project cache and its analysis subdirectory.');
        }

        if (is_dir($path)) {
            foreach (new \DirectoryIterator($path) as $entry) {
                if (!$entry->isDot()) {
                    $this->removePath($entry->getPathname());
                }
            }
        } elseif (file_exists($path)) {
            throw new AnalysisWorkspaceException('The analysis directory path is occupied by a file.', 'Move that file or configure a different cache directory.');
        } elseif (!mkdir($path, 0777, true) && !is_dir($path)) {
            throw new AnalysisWorkspaceException('The analysis directory could not be created.', 'Check free disk space and write permissions on the project cache directory.');
        }
    }

    private function removePath(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            if (!unlink($path)) {
                throw new AnalysisWorkspaceException('An old analysis file could not be removed.', 'Check ownership and write permissions on the project cache directory.');
            }

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach (new \DirectoryIterator($path) as $entry) {
            if (!$entry->isDot()) {
                $this->removePath($entry->getPathname());
            }
        }

        if (!rmdir($path)) {
            throw new AnalysisWorkspaceException('An old analysis directory could not be removed.', 'Check ownership and write permissions on the project cache directory.');
        }
    }

    private function resolveAnalysisPath(string $workspace, ProjectSource $source, bool $selected): string
    {
        $rootId = substr(hash('sha256', Path::normalize($source->sourceRoot)), 0, 16);
        $relative = $source->kind === FileKind::Ppphp
            ? substr($source->relativePath, 0, -strlen(FileKind::PPPHP_SUFFIX)) . FileKind::PHP_SUFFIX
            : $source->relativePath;

        return Path::join($workspace, $selected ? 'selected' : 'context', $rootId, $relative);
    }

    /** @return list<string> */
    private function copyStubs(Project $project, string $workspace): array
    {
        $paths = [];

        foreach ($project->stubs as $stub) {
            $rootId = substr(hash('sha256', Path::normalize($stub->stubRoot)), 0, 16);
            $relative = Path::resolveRelativeTo($stub->path, $stub->stubRoot);
            $target = Path::join($workspace, 'stubs', $rootId, $relative);
            $contents = file_get_contents($stub->path);

            if ($contents === false) {
                throw new AnalysisWorkspaceException(sprintf('The configured stub "%s" could not be read.', Path::resolveRelativeTo($stub->path, $project->configuration->projectRoot)), 'Check that the configured stub still exists and is readable.');
            }

            $this->writeFile($target, $contents);
            $paths[] = $target;
        }

        sort($paths, SORT_STRING);

        return $paths;
    }

    /** @return array{list<string>, list<string>} */
    private function resolveComposerContext(Project $project, ProjectParseResult $declarationContext): array
    {
        $files = [];
        $directories = [];
        $paths = [...$project->composer->projectAutoload->paths, ...$project->composer->dependencyAutoload->paths];

        foreach ($declarationContext->sourceFiles as $sourceFile) {
            if ($sourceFile->dependencyProvenance !== null && is_file($sourceFile->path)) {
                $files[] = Path::normalize($sourceFile->path);
            }
        }

        foreach ($paths as $path) {
            if (is_file($path) && str_ends_with(strtolower($path), '.php')) {
                if (!$project->sources->owns($path)) {
                    $files[] = Path::normalize($path);
                }
            } elseif (is_dir($path) && !is_link($path)) {
                if ($this->overlapsSourceRoot($project, $path)) {
                    array_push($files, ...$this->discoverComposerPhpFiles($project, $path, $path));
                } else {
                    $directories[] = Path::normalize($path);
                }
            }
        }

        $files = array_values(array_unique($files));
        $directories = array_values(array_unique($directories));
        sort($files, SORT_STRING);
        sort($directories, SORT_STRING);

        return [$files, $directories];
    }

    private function overlapsSourceRoot(Project $project, string $path): bool
    {
        foreach ($project->configuration->sourceRoots as $sourceRoot) {
            if (Path::overlaps($sourceRoot, $path)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function discoverComposerPhpFiles(Project $project, string $root, string $directory): array
    {
        $files = [];

        foreach (new \DirectoryIterator($directory) as $entry) {
            if ($entry->isDot() || $entry->isLink()) {
                continue;
            }

            $path = Path::normalize($entry->getPathname());

            if ($entry->isDir()) {
                if (
                    Path::buildComparisonKey($path) !== Path::buildComparisonKey($root)
                    && $this->isGeneratedOrDependencyDirectory($project, $path)
                ) {
                    continue;
                }

                array_push($files, ...$this->discoverComposerPhpFiles($project, $root, $path));
            } elseif (
                str_ends_with(strtolower($path), '.php')
                && !$project->sources->owns($path)
                && !$this->isConfiguredStub($project, $path)
            ) {
                $files[] = $path;
            }
        }

        return $files;
    }

    private function isGeneratedOrDependencyDirectory(Project $project, string $path): bool
    {
        return Path::contains($project->configuration->outputPath, $path)
            || Path::contains($project->configuration->cachePath, $path)
            || Path::contains($project->composer->vendorPath, $path);
    }

    private function isConfiguredStub(Project $project, string $path): bool
    {
        foreach ($project->stubs as $stub) {
            if (Path::buildComparisonKey($stub->path) === Path::buildComparisonKey($path)) {
                return true;
            }
        }

        return false;
    }

    private function writeFile(string $path, string $contents): void
    {
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new AnalysisWorkspaceException('A directory needed for analysis could not be created.', 'Check free disk space and write permissions on the project cache directory.');
        }

        if (file_put_contents($path, $contents) === false) {
            throw new AnalysisWorkspaceException('An analysis file could not be written.', 'Check free disk space and write permissions on the project cache directory.');
        }
    }

    private function writeMaps(AnalysisProject $project): void
    {
        $maps = [];

        foreach ([...$project->selectedFiles, ...$project->contextFiles] as $file) {
            $maps[] = [
                'analysis' => Path::resolveRelativeTo($file->analysisPath, $project->workspaceRoot),
                'source' => $file->sourceFile->displayPath,
                'selected' => $file->selected,
            ];
        }

        $json = json_encode($maps, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->writeFile(Path::join($project->workspaceRoot, 'maps.json'), $json . "\n");
    }
}
