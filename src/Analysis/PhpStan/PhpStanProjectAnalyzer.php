<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\PhpStan;

use Atatusoft\Ppphp\Analysis\AnalysisProject;
use Atatusoft\Ppphp\Analysis\AnalysisResult;
use Atatusoft\Ppphp\Analysis\Interfaces\ProjectAnalyzer;
use Atatusoft\Ppphp\Analysis\PhpStan\Exceptions\PhpStanExecutionException;
use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticOrigin;
use Atatusoft\Ppphp\Support\Path;

final readonly class PhpStanProjectAnalyzer implements ProjectAnalyzer
{
    private PhpStanAnalysisPlanBuilder $planBuilder;

    public function __construct(
        ?string $compilerRoot = null,
        private PhpStanProcessRunner $runner = new PhpStanProcessRunner(),
        private PhpStanResultParser $parser = new PhpStanResultParser(),
        private PhpStanDiagnosticMapper $mapper = new PhpStanDiagnosticMapper(),
        private float $timeout = 60.0,
        ?PhpStanAnalysisPlanBuilder $planBuilder = null,
    ) {
        $this->planBuilder = $planBuilder ?? new PhpStanAnalysisPlanBuilder($compilerRoot);
    }

    public function analyze(AnalysisProject $project): AnalysisResult
    {
        $diagnostics = new DiagnosticBag();

        if ($project->selectedFiles === []) {
            return new AnalysisResult($diagnostics, ['backend' => 'phpstan', 'skipped' => true]);
        }

        $executable = $this->planBuilder->executablePath();

        if (!is_file($executable)) {
            $this->addInfrastructureDiagnostic(
                $diagnostics,
                DiagnosticCode::StaticAnalysisBackendFailed,
                'The static analyzer required by the compiler is not installed.',
                ['executable' => $executable],
                'Reinstall the compiler and its locked dependencies.',
            );

            return new AnalysisResult($diagnostics);
        }

        try {
            $plan = $this->buildPlan($project);
            $process = $this->runner->run($plan->command, $plan->workingDirectory, $this->timeout);
        } catch (PhpStanExecutionException $exception) {
            $this->addInfrastructureDiagnostic(
                $diagnostics,
                $exception->diagnosticCode,
                $exception->getMessage(),
                ['exception' => $exception::class, 'message' => $exception->getMessage()],
                $exception->help,
            );

            return new AnalysisResult($diagnostics);
        } catch (\Throwable $exception) {
            $this->addInfrastructureDiagnostic(
                $diagnostics,
                DiagnosticCode::InternalCompilerError,
                'The compiler encountered an unexpected error while starting static analysis.',
                ['exception' => $exception::class, 'message' => $exception->getMessage()],
            );

            return new AnalysisResult($diagnostics);
        }

        return $this->complete($project, $process);
    }

    public function buildPlan(
        AnalysisProject $project,
        bool $debug = false,
        ?string $phpExecutable = null,
    ): PhpStanAnalysisPlan {
        return $this->planBuilder->build($project, $debug, $phpExecutable);
    }

    public function complete(AnalysisProject $project, PhpStanProcessResult $process): AnalysisResult
    {
        $diagnostics = new DiagnosticBag();
        @file_put_contents(Path::join($project->workspaceRoot, 'result.json'), $process->stdout);

        try {
            if ($process->timedOut) {
                throw new PhpStanExecutionException('Static analysis exceeded its time limit.', help: 'Try checking a smaller selection of files. Run with --debug if the timeout persists.');
            }

            if ($process->outputLimitExceeded) {
                throw new PhpStanExecutionException('Static analysis exceeded its output limit.', help: 'Try checking a smaller selection of files. Run with --debug if the output limit is still exceeded.');
            }

            if ($process->executionFailure !== null) {
                throw new PhpStanExecutionException('The static-analysis process failed to complete.');
            }

            if (!in_array($process->exitCode, [0, 1], true)) {
                throw new PhpStanExecutionException(sprintf('Static analysis stopped with exit status %d.', $process->exitCode));
            }

            $parsed = $this->parser->parse($process->stdout);

            if ($parsed->globalErrors !== []) {
                throw new PhpStanExecutionException('Static analysis reported a project-level execution error.');
            }

            foreach ($parsed->findings as $finding) {
                $diagnostic = $this->mapper->map($finding, $project);

                if ($diagnostic !== null) {
                    $diagnostics->add($diagnostic);
                }
            }

            return new AnalysisResult($diagnostics, [
                'backend' => 'phpstan',
                'exitCode' => $process->exitCode,
                'stderr' => $process->stderr,
                'command' => $process->command,
            ]);
        } catch (PhpStanExecutionException $exception) {
            $this->addInfrastructureDiagnostic(
                $diagnostics,
                $exception->diagnosticCode,
                $exception->getMessage(),
                ['exception' => $exception::class, 'message' => $exception->getMessage(), 'stderr' => $process->stderr, 'executionFailure' => $process->executionFailure, 'globalErrors' => $parsed->globalErrors ?? []],
                $exception->help,
            );

            return new AnalysisResult($diagnostics);
        } catch (\Throwable $exception) {
            $this->addInfrastructureDiagnostic(
                $diagnostics,
                DiagnosticCode::InternalCompilerError,
                'The compiler encountered an unexpected error while reading analysis results.',
                ['exception' => $exception::class, 'message' => $exception->getMessage()],
            );

            return new AnalysisResult($diagnostics);
        }
    }

    /** @param array<string, mixed> $debug */
    private function addInfrastructureDiagnostic(
        DiagnosticBag $diagnostics,
        DiagnosticCode $code,
        string $message,
        array $debug,
        string $help = 'Run the command again with --debug and include the details when reporting the analysis failure.',
    ): void {
        $diagnostics->add(new Diagnostic(
            $code,
            $message,
            help: $help,
            debug: $debug,
            origin: DiagnosticOrigin::Subprocess,
        ));
    }
}
