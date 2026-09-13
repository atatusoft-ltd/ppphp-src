<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Browser;

use Atatusoft\Ppphp\Analysis\AnalysisResult;
use Atatusoft\Ppphp\Analysis\AnalysisProject;
use Atatusoft\Ppphp\Analysis\PhpStan\PhpStanProcessResult;
use Atatusoft\Ppphp\Analysis\PhpStan\PhpStanProjectAnalyzer;
use Atatusoft\Ppphp\Cache\CompilerBuildIdentity;
use Atatusoft\Ppphp\Compiler\CompilationArtifact;
use Atatusoft\Ppphp\Compiler\Compiler;
use Atatusoft\Ppphp\Compiler\Manifest\BuildManifestCodec;
use Atatusoft\Ppphp\Compiler\Output\AtomicBuildCommitter;
use Atatusoft\Ppphp\Compiler\Output\BuildTransactionRecovery;
use Atatusoft\Ppphp\Compiler\Output\NativeBuildFilesystem;
use Atatusoft\Ppphp\Compiler\Output\ProjectBuildLock;
use Atatusoft\Ppphp\Compiler\Validation\PhpLintValidator;
use Atatusoft\Ppphp\Config\ProjectConfigLoader;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticOrigin;
use Atatusoft\Ppphp\Project\Enumerations\SelectionMode;
use Atatusoft\Ppphp\Project\Project;
use Atatusoft\Ppphp\Project\ProjectCheckResult;
use Atatusoft\Ppphp\Project\ProjectChecker;
use Atatusoft\Ppphp\Project\ProjectLoader;
use Atatusoft\Ppphp\Project\ProjectSelection;
use Atatusoft\Ppphp\Project\ProjectSelector;
use Atatusoft\Ppphp\Project\SupplementalAnalysisRun;
use Atatusoft\Ppphp\Support\Path;
use Atatusoft\Ppphp\Transpilation\SourceMapWriter;

/** Internal resumable full Check/Build. The host owns one serialized workspace. */
final readonly class WorkflowProtocol
{
    public const string CONTROL = '.ppphp-browser';
    public const int MAXIMUM_RESPONSE_BYTES = 2_097_152;

    public function __construct(
        private Compiler $compiler = new Compiler(),
        private ProjectChecker $checker = new ProjectChecker(),
        private NativeBuildFilesystem $filesystem = new NativeBuildFilesystem(),
        private WorkflowSnapshot $snapshots = new WorkflowSnapshot(),
        private BrowserDiagnosticRenderer $renderer = new BrowserDiagnosticRenderer(),
    ) {}

    /** @return array<string, mixed> */
    public function handle(WorkflowRequest $request, string $root): array
    {
        $lock = new ProjectBuildLock();
        $session = null;
        try {
            clearstatcache();
            if (realpath($root) !== $root || realpath((string) getcwd()) !== $root || is_link($root)
                || Path::hasSymlinkAncestor(Path::join($root, self::CONTROL), $root)) {
                throw new \InvalidArgumentException('Workflow processing requires the actual owned working directory.');
            }
            $configuration = (new ProjectConfigLoader())->load($root, null, true);
            if (!$configuration->isSuccessful || $configuration->configuration === null) {
                return $this->diagnosticResult($request, null, $configuration->diagnostics, 2);
            }
            if (!$lock->acquire($configuration->configuration)) {
                throw new \InvalidArgumentException('Another compiler phase owns this workspace.');
            }
            (new BuildTransactionRecovery($this->filesystem))->recover($configuration->configuration);
            $loaded = (new ProjectLoader())->load($configuration->configuration);
            if (!$loaded->isSuccessful || $loaded->project === null) {
                return $this->diagnosticResult($request, null, $loaded->diagnostics, 2);
            }
            $project = $loaded->project;
            $this->guardRoots($project);
            $control = Path::join($root, self::CONTROL);
            $this->filesystem->createDirectory($control);
            $saved = $this->readSession($control);
            if ($request->action === 'start') {
                if ($saved !== null && ($request->sequence <= $saved->start->sequence || in_array($request->operationId, $saved->usedIds, true))) {
                    throw new \InvalidArgumentException('A workflow start must have a fresh ID and increasing sequence.');
                }
                $usedIds = [...($saved->usedIds ?? []), $request->operationId];
                if (count($usedIds) > 256) {
                    throw new \InvalidArgumentException('This bounded session is exhausted; create a fresh workspace.');
                }
                $this->validateRuntime($request, $project);
                $previousOutput = $this->snapshots->identifyTree($project->configuration->outputPath);
                $publicationPath = Path::join($control, 'published.json');
                $publication = $this->filesystem->checkExists($publicationPath)
                    ? WorkflowSession::decodePublication(WorkflowJson::decode($this->filesystem->readFileBounded($publicationPath, 4096))) : null;
                $session = new WorkflowSession($request, $this->snapshots->identifyProject($project),
                    $previousOutput, (new CompilerBuildIdentity())->calculate(), usedIds: $usedIds,
                    previousPublication: $publication !== null && $publication['tree'] === $previousOutput ? $publication : null);
                $this->discardPending($control);
                $this->saveSession($control, $session);
            } else {
                if ($saved === null || $saved->phase === 'terminal' || $saved->start->operationId !== $request->operationId
                    || $saved->start->sequence !== $request->sequence || $saved->continuation !== $request->continuation) {
                    throw new \InvalidArgumentException('The workflow continuation is stale, replayed or out of order.');
                }
                $session = $saved;
                if ($request->action === 'abort') {
                    $session->phase = 'terminal';
                    $this->discardPending($control);
                    $this->saveSession($control, $session);
                    return $this->respond($session, 'aborted', null);
                }
                $this->validateFreshness($session, $project);
                if (($session->phase === 'analysis') !== ($request->action === 'complete-analysis')) {
                    throw new \InvalidArgumentException('The workflow phase is out of order.');
                }
                $actual = array_map(static fn (WorkflowProcessResult $result): string => $result->invocation, $request->results);
                if ($actual !== $session->invocations) {
                    throw new \InvalidArgumentException('The workflow result set is incomplete, duplicated, mismatched or out of order.');
                }
            }
            $selection = (new ProjectSelector())->select($project, $session->start->path,
                $session->start->operation === 'build' ? SelectionMode::Build : SelectionMode::Check);
            if (!$selection->isSuccessful || $selection->selection === null) {
                $this->validateFreshness($session, $project);
                return $this->finishDiagnostics($control, $session, $selection->diagnostics, 2);
            }
            $preparation = $this->checker->prepare($project, $selection->selection->analysisSources);
            if (!$preparation->isSuccessful || $preparation->analysisProject === null) {
                $this->validateFreshness($session, $project);
                return $this->finishDiagnostics($control, $session, $preparation->diagnostics, 1);
            }
            $analyzer = new PhpStanProjectAnalyzer();
            if ($preparation->analysisProject->selectedFiles === []) {
                $check = $this->checker->complete($preparation, new AnalysisResult(new DiagnosticBag(), ['backend' => 'phpstan', 'skipped' => true]));
            } else {
                $run = new SupplementalAnalysisRun($preparation);
                $observations = $session->analysis === null ? [] : [$session->analysis, ...$session->annotationResults];
                $incoming = $request->action === 'complete-analysis' ? ($request->results[0] ?? null) : null;
                $round = 0;
                while ($run->result === null) {
                    $invocation = $this->prepareAnalyzerInvocation($session, $run->pendingProject, $root, array_slice($observations, 0, $round));
                    $observed = $observations[$round] ?? $incoming;
                    if ($observed === null) {
                        if ($request->action === 'complete-lint') {
                            throw new \InvalidArgumentException('Annotation validation was not completed before lint.');
                        }
                        $session->invocations = [$invocation['identity']];
                        $this->validateFreshness($session, $project);
                        $this->saveSession($control, $session);
                        return $this->respond($session, 'pending-analysis', null, ['invocations' => [$invocation]]);
                    }
                    if ($observed->invocation !== $invocation['identity']) {
                        throw new \InvalidArgumentException('Analyzer invocation identity changed before completion.');
                    }
                    if (!isset($observations[$round])) {
                        $observations[] = $observed;
                        $incoming = null;
                        if ($round === 0) {
                            $session->analysis = $observed;
                        } else {
                            $session->annotationResults[] = $observed;
                        }
                    }
                    $process = $this->frameAnalysis($observed, $invocation['command'], $invocation['progressPaths']);
                    $run->advance($analyzer->complete($run->pendingProject, $process));
                    $round++;
                }
                if ($round !== count($observations) || $incoming !== null) {
                    throw new \InvalidArgumentException('Unexpected analysis evidence after completion.');
                }
                $check = $this->checker->complete($preparation, $run->result);
            }
            if (!$check->isSuccessful || $session->start->operation === 'check') {
                $this->validateFreshness($session, $project);
                return $this->finishDiagnostics($control, $session, $check->diagnostics, $check->isSuccessful ? 0 : 1);
            }
            return $this->build($request, $session, $project, $selection->selection, $check, $control);
        } catch (\InvalidArgumentException $error) {
            return ['version' => 3, 'operationId' => $request->operationId, 'sequence' => $request->sequence,
                'status' => 'rejected', 'compilerStatus' => 2, 'currentOutput' => null,
                'error' => ['code' => 'invalid-continuation-or-request', 'message' => $error->getMessage()]];
        } catch (\Throwable $error) {
            return ['version' => 3, 'operationId' => $request->operationId, 'sequence' => $request->sequence,
                'status' => 'infrastructure-failure', 'compilerStatus' => 70, 'currentOutput' => null,
                'error' => ['code' => 'workflow-failure', 'message' => 'The compiler workflow could not complete.'],
                'debug' => ['exception' => $error::class, 'message' => $error->getMessage()]];
        } finally {
            $lock->release();
        }
    }

    /** @return array<string, mixed> */
    private function build(WorkflowRequest $request, WorkflowSession $session, Project $project, ProjectSelection $selection, ProjectCheckResult $check, string $control): array
    {
        $production = $this->compiler->prepareProduction($project, $selection, $check);
        $diagnostics = new DiagnosticBag([...iterator_to_array($check->diagnostics), ...iterator_to_array($production->diagnostics)]);
        if ($diagnostics->hasErrors) {
            return $this->finishDiagnostics($control, $session, $diagnostics, 3);
        }
        $pending = Path::join($control, 'pending');
        $expected = $this->describeArtifacts($production->artifacts);
        $candidate = ProtocolJson::hash(ProtocolJson::encodeCanonical(['artifacts' => $expected]));
        if ($request->action !== 'complete-lint') {
            $this->discardPending($control);
            $this->filesystem->createDirectory($pending);
            foreach ($production->artifacts as $artifact) {
                $this->filesystem->writeFile(Path::join($pending, $artifact->relativeOutputPath), $artifact->contents, $artifact->mode);
                $this->filesystem->writeFile(Path::join($pending, $artifact->sourceMapPath), (new SourceMapWriter())->serialize($artifact));
            }
            $session->candidate = $candidate;
            $session->phase = 'lint';
            $invocations = $this->prepareLintInvocations($session, $production->artifacts, $pending);
            $session->invocations = array_column($invocations, 'identity');
            $this->validateFreshness($session, $project);
            $this->saveSession($control, $session);
            return $this->respond($session, 'pending-validation', null, ['invocations' => $invocations, 'candidates' => $expected]);
        }
        if ($candidate !== $session->candidate) {
            throw new \InvalidArgumentException('Reconstructed production bytes changed after lint.');
        }
        $invocations = $this->prepareLintInvocations($session, $production->artifacts, $pending);
        if (array_column($invocations, 'identity') !== $session->invocations) {
            throw new \InvalidArgumentException('Lint invocation identities changed.');
        }
        $files = [];
        $records = [];
        foreach ($production->artifacts as $index => $artifact) {
            $path = Path::join($pending, $artifact->relativeOutputPath);
            $mapPath = Path::join($pending, $artifact->sourceMapPath);
            if ($this->filesystem->readFile($path) !== $artifact->contents
                || $this->filesystem->readFile($mapPath) !== (new SourceMapWriter())->serialize($artifact)) {
                throw new \InvalidArgumentException('A staged artifact changed after validation.');
            }
            $files[] = $artifact->relativeOutputPath;
            $files[] = $artifact->sourceMapPath;
            $records[$artifact->relativeOutputPath] = ['hash' => $artifact->outputHash, 'result' => $request->results[$index], 'original' => $path];
        }
        sort($files);
        if ($this->filesystem->listFiles($pending) !== $files) {
            throw new \InvalidArgumentException('The staged artifact set changed after validation.');
        }
        $this->validateFreshness($session, $project);
        // Reserve the terminal state before publication: no replay can publish twice,
        // including if publication succeeds but the response is interrupted.
        $session->phase = 'terminal';
        $this->saveSession($control, $session);
        $commit = (new AtomicBuildCommitter(filesystem: $this->filesystem,
            phpValidator: new PhpLintValidator(runner: new WorkflowLintRunner($records, dirname($project->configuration->outputPath)))))->commit($project, $selection, $production->artifacts);
        $diagnostics->addAll($commit->diagnostics);
        $this->discardPending($control);
        if (!$commit->committed || $commit->manifest === null) {
            return $this->diagnosticResult($session->start, $session, $diagnostics, 3);
        }
        $manifest = (new BuildManifestCodec())->serialize($commit->manifest);
        $published = ['snapshot' => $session->snapshot, 'operationId' => $session->start->operationId,
            'sequence' => $session->start->sequence, 'tree' => $this->snapshots->identifyTree($project->configuration->outputPath),
            'manifestHash' => ProtocolJson::hash($manifest)];
        $this->filesystem->writeFileAtomically(Path::join($control, 'published.json'), ProtocolJson::encodeCanonical($published));
        $descriptors = [];
        foreach ($commit->manifest->files as $entry) {
            $descriptors[] = ['path' => $entry->output, 'hash' => $entry->outputHash, 'operation' => $entry->operation->value,
                'sourceMap' => $entry->sourceMap, 'sourceMapHash' => ProtocolJson::hash($this->filesystem->readFile(Path::join($project->configuration->outputPath, $entry->sourceMap)))];
        }
        return $this->respond($session, 'complete', 0, ['diagnostics' => $this->renderer->render($diagnostics),
            'currentOutput' => [...$published, 'root' => Path::resolveRelativeTo($project->configuration->outputPath, $project->configuration->projectRoot),
                'artifacts' => $descriptors, 'manifest' => json_decode($manifest, true, flags: JSON_THROW_ON_ERROR)], 'staleRemovalCount' => $commit->staleRemovalCount]);
    }

    /** @param list<WorkflowProcessResult> $observations
     * @return array{identity: string, kind: string, command: list<string>, workingDirectory: string, progressPaths: list<string>, binding: string}
     */
    private function prepareAnalyzerInvocation(WorkflowSession $session, AnalysisProject $analysis, string $root, array $observations): array
    {
        $plan = (new PhpStanProjectAnalyzer())->buildPlan($analysis, true, 'php');
        $paths = array_map(static fn ($file): string => $file->analysisPath, $analysis->selectedFiles);
        $files = $this->snapshots->identifyTree($analysis->workspaceRoot, [Path::join($analysis->workspaceRoot, 'result.json'), Path::join($analysis->workspaceRoot, 'tmp')]);
        $evidence = ProtocolJson::encodeCanonical(['files' => $files,
            'observations' => array_map(static fn (WorkflowProcessResult $result): array => $result->toArray(), $observations)]);
        $data = ['kind' => 'phpstan', 'command' => $plan->command, 'workingDirectory' => $root,
            'progressPaths' => $paths, 'binding' => $this->bind($session, 'analysis', ProtocolJson::hash($evidence))];
        return ['identity' => ProtocolJson::hash(ProtocolJson::encodeCanonical($data)), ...$data];
    }

    /**
     * @param list<CompilationArtifact> $artifacts
     * @return list<array{identity: string, kind: string, command: list<string>, workingDirectory: string, path: string, hash: string, binding: string}>
     */
    private function prepareLintInvocations(WorkflowSession $session, array $artifacts, string $pending): array
    {
        $result = [];
        foreach ($artifacts as $artifact) {
            $data = ['kind' => 'php-lint', 'command' => ['php', '-n', '-l', Path::join($pending, $artifact->relativeOutputPath)],
                'workingDirectory' => dirname(dirname($pending)), 'path' => $artifact->relativeOutputPath, 'hash' => $artifact->outputHash,
                'binding' => $this->bind($session, 'lint', $session->candidate ?? throw new \LogicException('Missing candidate identity.'))];
            $result[] = ['identity' => ProtocolJson::hash(ProtocolJson::encodeCanonical($data)), ...$data];
        }
        return $result;
    }

    private function bind(WorkflowSession $session, string $phase, string $bytes): string
    {
        return ProtocolJson::hash(ProtocolJson::encodeCanonical(['version' => 3, 'start' => $session->start->toArray(),
            'snapshot' => $session->snapshot, 'compiler' => $session->compiler, 'previousOutput' => $session->previousOutput,
            'phase' => $phase, 'bytes' => $bytes, 'analyzer' => ProtocolJson::hash($this->filesystem->readFile(dirname(__DIR__, 3) . '/vendor/phpstan/phpstan/phpstan.phar'))]));
    }

    /**
     * @param list<string> $command
     * @param list<string> $paths
     */
    private function frameAnalysis(WorkflowProcessResult $result, array $command, array $paths): PhpStanProcessResult
    {
        $stdout = $result->stdout;
        $failure = $result->executionFailure;
        if (!$result->complete) {
            $failure = 'The analyzer result is incomplete.';
        }
        if ($stdout !== '' && $result->complete && !$result->timedOut && !$result->outputLimitExceeded && $failure === null) {
            try {
                $remaining = array_fill_keys($paths, true);
                while ($remaining !== []) {
                    $end = strpos($stdout, "\n");
                    $line = $end === false ? '' : rtrim(substr($stdout, 0, $end), "\r");
                    if ($end === false || !isset($remaining[$line])) {
                        throw new \InvalidArgumentException('Unexpected or incomplete analyzer progress output.');
                    }
                    unset($remaining[$line]);
                    $stdout = substr($stdout, $end + 1);
                }
                $parsed = WorkflowValues::readObject(WorkflowJson::decode($stdout, 512), ['totals', 'files', 'errors', 'localAnnotationOmissions']);
                if (!is_array($parsed['files']) || !is_array($parsed['errors']) || !array_is_list($parsed['errors'])) {
                    throw new \InvalidArgumentException('Malformed analyzer file results.');
                }
                foreach (array_keys($parsed['files']) as $path) {
                    if (!in_array($path, $paths, true)) {
                        throw new \InvalidArgumentException('Analyzer returned an unselected file.');
                    }
                }
                $totals = WorkflowValues::readObject($parsed['totals'], ['errors', 'file_errors']);
                $count = 0;
                foreach ($parsed['files'] as $file) {
                    $file = WorkflowValues::readObject($file, ['errors', 'messages']);
                    $messages = WorkflowValues::readList($file['messages'], 1000);
                    if (WorkflowValues::readInteger($file['errors']) !== count($messages)) {
                        throw new \InvalidArgumentException('Incomplete analyzer file findings.');
                    }
                    $count += count($messages);
                }
                if (WorkflowValues::readInteger($totals['errors']) !== count($parsed['errors'])
                    || WorkflowValues::readInteger($totals['file_errors']) !== $count
                    || ($result->exitCode === 0) !== ($count === 0 && $parsed['errors'] === [])) {
                    throw new \InvalidArgumentException('Analyzer totals or exit status do not match the findings.');
                }
                if ($result->stderr !== '') {
                    throw new \InvalidArgumentException('Analyzer returned unexpected error output.');
                }
            } catch (\Throwable $error) {
                $failure = $error->getMessage();
                $stdout = $result->stdout;
            }
        }
        return new PhpStanProcessResult($command, $stdout, $result->stderr, $result->exitCode ?? -1,
            $result->timedOut, $result->outputLimitExceeded, $failure);
    }

    private function validateFreshness(WorkflowSession $session, Project $project): void
    {
        clearstatcache();
        $this->validateRuntime($session->start, $project);
        if ($session->snapshot !== $this->snapshots->identifyProject($project)
            || $session->previousOutput !== $this->snapshots->identifyTree($project->configuration->outputPath)
            || $session->compiler !== (new CompilerBuildIdentity())->calculate()) {
            throw new \InvalidArgumentException('The project snapshot, compiler or previous output changed during this operation.');
        }
    }

    private function validateRuntime(WorkflowRequest $start, Project $project): void
    {
        $runtime = $start->runtime;
        if ($runtime === null || $runtime['phpVersion'] !== PHP_VERSION || $runtime['sapi'] !== PHP_SAPI
            || $runtime['intSize'] !== PHP_INT_SIZE || $project->configuration->targetPhpVersion !== '8.4') {
            throw new \InvalidArgumentException('This workflow requires a matching qualified PHP 8.4 runtime and target.');
        }
    }

    private function guardRoots(Project $project): void
    {
        $config = $project->configuration;
        $control = Path::join($config->projectRoot, self::CONTROL);
        foreach ([...$config->sourceRoots, ...$config->stubPaths, $config->outputPath, $config->cachePath, $project->composer->vendorPath] as $path) {
            if (!Path::contains($config->projectRoot, $path) || Path::overlaps($control, $path)
                || Path::hasSymlinkAncestor($path, $config->projectRoot)) {
                throw new \InvalidArgumentException('Workflow roots must be contained and separate from control state.');
            }
        }
    }

    /**
     * @param list<CompilationArtifact> $artifacts
     * @return list<array{path: string, hash: string, bytes: int, operation: string, sourceMapHash: string}>
     */
    private function describeArtifacts(array $artifacts): array
    {
        $bytes = 0;
        $result = [];
        foreach ($artifacts as $artifact) {
            $map = (new SourceMapWriter())->serialize($artifact);
            $bytes += strlen($artifact->contents) + strlen($map);
            $result[] = ['path' => $artifact->relativeOutputPath, 'hash' => $artifact->outputHash, 'bytes' => strlen($artifact->contents),
                'operation' => $artifact->operation->value, 'sourceMapHash' => ProtocolJson::hash($map)];
        }
        if (count($artifacts) > 64 || $bytes > 1_048_576) {
            throw new \InvalidArgumentException('Production artifacts exceed the workflow budget.');
        }
        return $result;
    }

    private function readSession(string $control): ?WorkflowSession
    {
        $path = Path::join($control, 'session.json');
        if (!$this->filesystem->checkExists($path)) {
            return null;
        }
        $bytes = $this->filesystem->readFileBounded($path, WorkflowSession::MAXIMUM_BYTES);
        $data = WorkflowValues::readObject(WorkflowJson::decode($bytes), ['state', 'hash']);
        $session = WorkflowSession::decode($data['state']);
        if ($session->continuation !== $data['hash']) {
            throw new \InvalidArgumentException('The stored workflow continuation is corrupt.');
        }
        return $session;
    }

    private function saveSession(string $control, WorkflowSession $session): void
    {
        $bytes = ProtocolJson::encodeCanonical(['state' => $session->toArray(), 'hash' => $session->continuation]);
        if (strlen($bytes) > WorkflowSession::MAXIMUM_BYTES) {
            throw new \InvalidArgumentException('Analysis evidence exceeds the workflow budget.');
        }
        $this->filesystem->writeFileAtomically(Path::join($control, 'session.json'),
            $bytes, 0600);
    }

    private function discardPending(string $control): void
    {
        $this->filesystem->remove(Path::join($control, 'pending'));
    }

    /** @return array<string, mixed> */
    private function finishDiagnostics(string $control, WorkflowSession $session, DiagnosticBag $diagnostics, int $status): array
    {
        $session->phase = 'terminal';
        $session->invocations = [];
        $this->saveSession($control, $session);
        $this->discardPending($control);
        return $this->diagnosticResult($session->start, $session, $diagnostics, $status);
    }

    /** @return array<string, mixed> */
    private function diagnosticResult(WorkflowRequest $request, ?WorkflowSession $session, DiagnosticBag $diagnostics, int $status): array
    {
        if (count($diagnostics) > 1000) {
            throw new \InvalidArgumentException('Workflow diagnostic count exceeds its limit.');
        }
        $infrastructure = false;
        foreach ($diagnostics as $diagnostic) {
            $infrastructure = $infrastructure || $diagnostic->origin === DiagnosticOrigin::Subprocess;
        }
        $outcome = $status === 0 ? 'complete' : ($status === 3 ? 'output-failure' : ($infrastructure ? 'infrastructure-failure' : 'diagnostics'));
        $data = ['diagnostics' => $this->renderer->render($diagnostics), 'identities' => array_map(static fn ($diagnostic): array =>
            ['code' => $diagnostic->code->value, 'origin' => $diagnostic->origin->value, 'identity' => $diagnostic->identity], iterator_to_array($diagnostics))];
        return $session !== null ? $this->respond($session, $outcome, $status, $data)
            : ['version' => 3, 'operationId' => $request->operationId, 'sequence' => $request->sequence, 'status' => $outcome,
                'compilerStatus' => $status, 'currentOutput' => null, ...$data];
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function respond(WorkflowSession $session, string $status, ?int $compilerStatus, array $extra = []): array
    {
        $data = ['version' => 3, 'operationId' => $session->start->operationId, 'sequence' => $session->start->sequence,
            'operation' => $session->start->operation, 'selection' => ['path' => $session->start->path], 'snapshot' => $session->snapshot,
            'compiler' => ['name' => Compiler::NAME, 'version' => Compiler::VERSION, 'buildIdentity' => $session->compiler],
            'runtime' => $session->start->runtime, 'status' => $status, 'compilerStatus' => $compilerStatus,
            'continuation' => $session->phase === 'terminal' ? null : $session->continuation,
            'currentOutput' => null, 'previousOutputIdentity' => $session->previousOutput,
            'previousOutput' => $session->previousPublication, ...$extra];
        if (strlen(ProtocolJson::encodeCanonical($data)) > self::MAXIMUM_RESPONSE_BYTES) {
            throw new \InvalidArgumentException('The workflow response exceeds its byte limit.');
        }
        return $data;
    }
}
