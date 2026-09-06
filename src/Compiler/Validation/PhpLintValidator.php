<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Compiler\Validation;

use Atatusoft\Ppphp\Compiler\CompilationArtifact;
use Atatusoft\Ppphp\Compiler\Validation\Interfaces\PhpLintRunner;
use Atatusoft\Ppphp\Compiler\Validation\Interfaces\PhpValidator;
use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Diagnostics\DiagnosticLabel;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticOrigin;

final readonly class PhpLintValidator implements PhpValidator
{
    public function __construct(
        private float $timeoutSeconds = 10.0,
        private PhpLintRunner $runner = new SymfonyPhpLintRunner(),
    ) {}

    public function validate(CompilationArtifact $artifact, string $candidatePath): DiagnosticBag
    {
        $diagnostics = new DiagnosticBag();
        $result = $this->runner->run($candidatePath, $this->timeoutSeconds);

        if ($result->timedOut) {
            $this->addFailure($diagnostics, $artifact, null, 'PHP lint validation timed out.', [
                'candidate' => $candidatePath,
                'failure' => $result->executionFailure,
                'stdout' => $result->stdout,
                'stderr' => $result->stderr,
            ], DiagnosticCode::PhpOutputValidationFailed);

            return $diagnostics;
        }

        if ($result->executionFailure !== null) {
            $this->addFailure($diagnostics, $artifact, null, 'PHP lint validation could not be executed.', [
                'candidate' => $candidatePath,
                'failure' => $result->executionFailure,
                'stdout' => $result->stdout,
                'stderr' => $result->stderr,
            ], DiagnosticCode::PhpOutputValidationFailed);

            return $diagnostics;
        }

        if ($result->isSuccessful) {
            return $diagnostics;
        }

        $raw = trim($result->stderr . "\n" . $result->stdout);
        $line = null;

        if (preg_match('/\bon line\s+(\d+)\b/i', $raw, $matches) === 1) {
            $line = (int) $matches[1];
        }

        $this->addFailure($diagnostics, $artifact, $line, 'The candidate PHP output did not pass php -l.', [
            'candidate' => $candidatePath,
            'exitCode' => $result->exitCode,
            'stdout' => $result->stdout,
            'stderr' => $result->stderr,
        ], $line === null ? DiagnosticCode::PhpOutputValidationFailed : DiagnosticCode::GeneratedPhpIsInvalid);

        return $diagnostics;
    }

    /** @param array<string, mixed> $debug */
    private function addFailure(
        DiagnosticBag $diagnostics,
        CompilationArtifact $artifact,
        ?int $generatedLine,
        string $message,
        array $debug,
        DiagnosticCode $code = DiagnosticCode::GeneratedPhpIsInvalid,
    ): void {
        $generatedOffset = $generatedLine === null
            ? 0
            : $this->resolveLineStart($artifact->contents, $generatedLine);
        $originalOffset = $artifact->sourceMap->resolveOriginalOffset($generatedOffset);
        $source = $artifact->sourceFile;
        $end = min($source->length, $originalOffset + ($originalOffset < $source->length ? 1 : 0));
        $diagnostics->add(new Diagnostic(
            $code,
            $message,
            $generatedLine === null ? null : new DiagnosticLabel(
                $source->createSpan($originalOffset, $end),
                'The generated PHP failed validation for this source.',
            ),
            help: sprintf('The candidate output for "%s" was rejected before the build was committed. Run with --debug for validation details.', $artifact->relativeOutputPath),
            debug: $debug,
            origin: DiagnosticOrigin::Subprocess,
        ));
    }

    private function resolveLineStart(string $contents, int $line): int
    {
        $current = 1;
        $length = strlen($contents);

        for ($offset = 0; $offset < $length; $offset++) {
            if ($current === $line) {
                return $offset;
            }

            if ($contents[$offset] === "\n") {
                $current++;
            }
        }

        return $length;
    }
}
