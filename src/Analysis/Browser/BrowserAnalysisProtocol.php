<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Browser;

use Atatusoft\Ppphp\Analysis\PhpStan\PhpStanProjectAnalyzer;
use Atatusoft\Ppphp\Analysis\PhpStan\Exceptions\PhpStanExecutionException;
use Atatusoft\Ppphp\Compiler\Compiler;
use Atatusoft\Ppphp\Config\ProjectConfigLoader;
use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticOrigin;
use Atatusoft\Ppphp\Project\Enumerations\SelectionMode;
use Atatusoft\Ppphp\Project\Project;
use Atatusoft\Ppphp\Project\ProjectChecker;
use Atatusoft\Ppphp\Project\ProjectLoader;
use Atatusoft\Ppphp\Project\ProjectSelection;
use Atatusoft\Ppphp\Project\ProjectSelector;
use Atatusoft\Ppphp\Support\Path;

final readonly class BrowserAnalysisProtocol
{
    private const int MAXIMUM_PHPSTAN_RESULT_BYTES = 2_097_152;

    public function __construct(
        private ProjectConfigLoader $configLoader = new ProjectConfigLoader(),
        private ProjectLoader $projectLoader = new ProjectLoader(),
        private ProjectSelector $selector = new ProjectSelector(),
        private ProjectChecker $checker = new ProjectChecker(),
        private ?PhpStanProjectAnalyzer $phpStan = null,
        private BrowserDiagnosticRenderer $diagnosticRenderer = new BrowserDiagnosticRenderer(),
    ) {}

    public function prepare(
        PrepareAnalysisRequest $request,
        string $workingDirectory,
        ?string $configurationPath = null,
    ): PreparedAnalysis {
        $configResult = $this->configLoader->load($workingDirectory, $configurationPath, true);

        if (!$configResult->isSuccessful || $configResult->configuration === null) {
            return $this->createDiagnosticResult($request, $configResult->diagnostics);
        }

        $projectResult = $this->projectLoader->load($configResult->configuration);

        if (!$projectResult->isSuccessful || $projectResult->project === null) {
            return $this->createDiagnosticResult($request, $projectResult->diagnostics);
        }

        $selectionResult = $this->selector->select(
            $projectResult->project,
            $request->path,
            $request->operation === 'build' ? SelectionMode::Build : SelectionMode::Check,
        );

        if (!$selectionResult->isSuccessful || $selectionResult->selection === null) {
            return $this->createDiagnosticResult($request, $selectionResult->diagnostics);
        }

        $preparation = $this->checker->prepare(
            $projectResult->project,
            $selectionResult->selection->analysisSources,
        );

        if (!$preparation->isSuccessful || $preparation->analysisProject === null) {
            return $this->createDiagnosticResult($request, $preparation->diagnostics);
        }

        if ($preparation->analysisProject->selectedFiles === []) {
            return $this->createDiagnosticResult($request, $preparation->diagnostics);
        }

        try {
            $plan = ($this->phpStan ?? new PhpStanProjectAnalyzer())->buildPlan($preparation->analysisProject, true, 'php');
            $continuation = $this->createContinuation(
                $request,
                $projectResult->project,
                $selectionResult->selection,
                $plan->configurationPath,
                $plan->resultPath,
                $preparation->analysisProject->workspaceRoot,
            );
        } catch (PhpStanExecutionException $exception) {
            return $this->createDiagnosticResult($request, new DiagnosticBag([new Diagnostic(
                $exception->diagnosticCode,
                $exception->getMessage(),
                help: $exception->help,
                debug: ['exception' => $exception::class, 'message' => $exception->getMessage()],
                origin: DiagnosticOrigin::Subprocess,
            )]));
        } catch (\Throwable $exception) {
            $diagnostics = new DiagnosticBag();
            $diagnostics->add(new Diagnostic(
                DiagnosticCode::InternalCompilerError,
                'The compiler encountered an unexpected error while preparing analysis.',
                debug: ['exception' => $exception::class, 'message' => $exception->getMessage()],
                origin: DiagnosticOrigin::Subprocess,
            ));

            return $this->createDiagnosticResult($request, $diagnostics);
        }

        return new PreparedAnalysis(
            $request->requestId,
            'prepared',
            $this->renderDiagnostics($preparation->diagnostics),
            $continuation,
            $plan->command,
            $plan->workingDirectory,
            $plan->resultPath,
        );
    }

    private function createDiagnosticResult(
        PrepareAnalysisRequest $request,
        DiagnosticBag $diagnostics,
    ): PreparedAnalysis {
        return new PreparedAnalysis(
            $request->requestId,
            'diagnostics',
            $this->renderDiagnostics($diagnostics),
        );
    }

    /** @return array{version: int, diagnostics: list<mixed>, summary: array{errors: int, warnings: int, notes: int}} */
    private function renderDiagnostics(DiagnosticBag $diagnostics): array
    {
        return $this->diagnosticRenderer->render($diagnostics);
    }

    private function createContinuation(
        PrepareAnalysisRequest $request,
        Project $project,
        ProjectSelection $selection,
        string $phpStanConfigurationPath,
        string $resultPath,
        string $workspaceRoot,
    ): AnalysisContinuation {
        $sources = [];

        foreach ($project->sources as $source) {
            $contents = file_get_contents($source->path);

            if ($contents === false) {
                throw new PhpStanExecutionException('A project source changed while analysis was being prepared.', diagnosticCode: DiagnosticCode::AnalysisWorkspacePreparationFailed, help: 'Run the command again after saving your changes.');
            }

            $sources[] = [
                'path' => $source->displayPath,
                'hash' => ProtocolJson::hash($contents),
            ];
        }

        usort($sources, static fn (array $left, array $right): int => $left['path'] <=> $right['path']);

        $selectedSources = array_map(
            static fn ($source): string => $source->displayPath,
            $selection->analysisSources->files,
        );
        sort($selectedSources, SORT_STRING);
        $configurationHash = ProtocolJson::hash(ProtocolJson::encodeCanonical(
            $this->buildProjectConfigurationPayload($project),
        ));
        $workspaceManifest = $this->buildWorkspaceManifest($workspaceRoot);
        $configurationContents = file_get_contents($phpStanConfigurationPath);

        if ($configurationContents === false) {
            throw new PhpStanExecutionException('A file needed for analysis could not be read.', diagnosticCode: DiagnosticCode::AnalysisWorkspacePreparationFailed, help: 'Check that the project cache files still exist and are readable.');
        }

        $payload = [
            'version' => PrepareAnalysisRequest::VERSION,
            'prepareRequestId' => $request->requestId,
            'operation' => $request->operation,
            'selectedPath' => $selection->selectedPath === null
                ? null
                : Path::resolveRelativeTo($selection->selectedPath, $project->configuration->projectRoot),
            'compiler' => [
                'name' => Compiler::NAME,
                'version' => Compiler::VERSION,
                'loweringFormatVersion' => Compiler::LOWERING_FORMAT_VERSION,
            ],
            'sources' => $sources,
            'projectConfigurationHash' => $configurationHash,
            'selectedSources' => $selectedSources,
            'workspaceManifest' => $workspaceManifest,
            'phpStanConfigurationHash' => ProtocolJson::hash($configurationContents),
            'expectedResult' => [
                'path' => Path::resolveRelativeTo($resultPath, $workspaceRoot),
                'format' => 'phpstan-json-v1',
                'maximumBytes' => self::MAXIMUM_PHPSTAN_RESULT_BYTES,
            ],
        ];

        return new AnalysisContinuation(
            $payload['version'],
            $payload['prepareRequestId'],
            $payload['operation'],
            $payload['selectedPath'],
            $payload['compiler'],
            $payload['sources'],
            $payload['projectConfigurationHash'],
            $payload['selectedSources'],
            $payload['workspaceManifest'],
            $payload['phpStanConfigurationHash'],
            $payload['expectedResult'],
            AnalysisContinuation::calculateHash($payload),
        );
    }

    /** @return array<string, mixed> */
    private function buildProjectConfigurationPayload(Project $project): array
    {
        $configuration = $project->configuration;

        return [
            'sourceRoots' => array_map(
                fn (string $path): string => Path::resolveRelativeTo($path, $configuration->projectRoot),
                $configuration->sourceRoots,
            ),
            'output' => Path::resolveRelativeTo($configuration->outputPath, $configuration->projectRoot),
            'cache' => Path::resolveRelativeTo($configuration->cachePath, $configuration->projectRoot),
            'targetPhpVersion' => $configuration->targetPhpVersion,
            'stubs' => array_map(
                fn (string $path): string => Path::resolveRelativeTo($path, $configuration->projectRoot),
                $configuration->stubPaths,
            ),
            'exclude' => array_map(
                fn (string $path): string => Path::resolveRelativeTo($path, $configuration->projectRoot),
                $configuration->excludedPaths,
            ),
            'configurationFile' => $this->hashFile($configuration->configurationPath),
            'composerFile' => $project->composer->configurationPath === null
                ? null
                : $this->hashFile($project->composer->configurationPath),
            'composerLock' => $this->hashFile(Path::join($configuration->projectRoot, 'composer.lock'), false),
            'projectAutoload' => $this->hashPaths($project, $project->composer->projectAutoload->paths),
            'dependencyAutoload' => $this->hashPaths($project, $project->composer->dependencyAutoload->paths),
        ];
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function hashPaths(Project $project, array $paths): array
    {
        $identities = [];

        foreach ($paths as $path) {
            $identities[] = Path::contains($project->configuration->projectRoot, $path)
                ? Path::resolveRelativeTo($path, $project->configuration->projectRoot)
                : ProtocolJson::hash(Path::normalize($path));
        }

        sort($identities, SORT_STRING);

        return $identities;
    }

    private function hashFile(string $path, bool $required = true): ?string
    {
        if (!is_file($path)) {
            if ($required) {
                throw new PhpStanExecutionException('Project configuration changed while analysis was being prepared.', diagnosticCode: DiagnosticCode::AnalysisWorkspacePreparationFailed, help: 'Run the command again after saving your configuration changes.');
            }

            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new PhpStanExecutionException('A project configuration input could not be read.', diagnosticCode: DiagnosticCode::AnalysisWorkspacePreparationFailed, help: 'Check that the project configuration files still exist and are readable.');
        }

        return ProtocolJson::hash($contents);
    }

    /** @return list<array{path: string, bytes: int, hash: string}> */
    private function buildWorkspaceManifest(string $workspaceRoot): array
    {
        $manifest = [];
        $this->appendWorkspaceFiles($workspaceRoot, $workspaceRoot, $manifest);
        usort($manifest, static fn (array $left, array $right): int => $left['path'] <=> $right['path']);

        return $manifest;
    }

    /** @param list<array{path: string, bytes: int, hash: string}> $manifest */
    private function appendWorkspaceFiles(string $workspaceRoot, string $directory, array &$manifest): void
    {
        foreach (new \DirectoryIterator($directory) as $entry) {
            if ($entry->isDot() || $entry->isLink()) {
                continue;
            }

            $path = Path::normalize($entry->getPathname());

            if ($entry->isDir()) {
                $this->appendWorkspaceFiles($workspaceRoot, $path, $manifest);
                continue;
            }

            if (!$entry->isFile()) {
                continue;
            }

            $contents = file_get_contents($path);

            if ($contents === false) {
                throw new PhpStanExecutionException('A file needed for analysis could not be read.', diagnosticCode: DiagnosticCode::AnalysisWorkspacePreparationFailed, help: 'Check that the project cache files still exist and are readable.');
            }

            $manifest[] = [
                'path' => Path::resolveRelativeTo($path, $workspaceRoot),
                'bytes' => strlen($contents),
                'hash' => ProtocolJson::hash($contents),
            ];
        }
    }
}
