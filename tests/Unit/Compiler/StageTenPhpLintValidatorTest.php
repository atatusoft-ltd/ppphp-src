<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Compiler\CompilationArtifact;
use Atatusoft\Ppphp\Compiler\Output\Enumerations\OutputOperation;
use Atatusoft\Ppphp\Compiler\Validation\Interfaces\PhpLintRunner;
use Atatusoft\Ppphp\Compiler\Validation\PhpLintResult;
use Atatusoft\Ppphp\Compiler\Validation\PhpLintValidator;
use Atatusoft\Ppphp\Diagnostics\ConsoleRenderer;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Project\ProjectSource;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;
use Atatusoft\Ppphp\Transpilation\GeneratedSourceMap;

function stageTenLintArtifact(FileKind $kind = FileKind::Ppphp): CompilationArtifact
{
    $sourceContents = "<?php\necho 1;\n";
    $generatedContents = $kind === FileKind::Ppphp
        ? "<?php\ndeclare(strict_types=1);\necho 1;\n"
        : $sourceContents;
    $sourcePath = $kind === FileKind::Ppphp ? '/project/src/One.ppphp' : '/project/src/One.php';
    $source = new SourceFile($sourcePath, 'src/' . basename($sourcePath), $kind, $sourceContents);
    $projectSource = new ProjectSource($sourcePath, '/project/src', $kind, '/project');
    $map = $kind === FileKind::Ppphp
        ? new GeneratedSourceMap($source, strlen($generatedContents), [
            new Atatusoft\Ppphp\Transpilation\GeneratedSourceMapSegment(0, strlen($generatedContents), 0, $source->length),
        ])
        : GeneratedSourceMap::createIdentity($source);

    return new CompilationArtifact(
        $projectSource,
        $source,
        $kind === FileKind::Ppphp ? OutputOperation::Compile : OutputOperation::Copy,
        '/project/build/ppphp/One.php',
        'One.php',
        $generatedContents,
        $map,
        'sha256:' . hash('sha256', $sourceContents),
        'sha256:' . hash('sha256', $generatedContents),
        0644,
    );
}

function stageTenLintRunner(PhpLintResult $result): PhpLintRunner
{
    return new class($result) implements PhpLintRunner {
        public function __construct(private readonly PhpLintResult $result) {}

        public function run(string $path, float $timeoutSeconds): PhpLintResult
        {
            return $this->result;
        }
    };
}

test('lint validator accepts successful generated and copied PHP', function (FileKind $kind): void {
    $validator = new PhpLintValidator(runner: stageTenLintRunner(new PhpLintResult(0, 'No syntax errors detected')));

    expect($validator->validate(stageTenLintArtifact($kind), '/hidden/candidate.php')->isEmpty)->toBeTrue();
})->with([FileKind::Ppphp, FileKind::Php]);

test('lint failures map generated and copied lines to their original source', function (FileKind $kind): void {
    $result = new PhpLintResult(255, stderr: 'PHP Parse error: syntax error in candidate.php on line 2');
    $diagnostics = (new PhpLintValidator(runner: stageTenLintRunner($result)))
        ->validate(stageTenLintArtifact($kind), '/project/build/.ppphp-stage-secret/One.php');
    $diagnostic = $diagnostics->errors[0];
    $normal = (new ConsoleRenderer())->render($diagnostics);
    $debug = (new ConsoleRenderer())->render($diagnostics, true);

    expect($diagnostic->code)->toBe(DiagnosticCode::GeneratedPhpIsInvalid)
        ->and($diagnostic->primary)->not->toBeNull()
        ->and($diagnostic->primary->span->start->sourceFile->displayPath)->toBe('src/' . ($kind === FileKind::Ppphp ? 'One.ppphp' : 'One.php'))
        ->and($normal)->not->toContain('.ppphp-stage-secret')
        ->and($normal)->not->toContain('PHP Parse error')
        ->and($debug)->toContain('.ppphp-stage-secret')
        ->and($debug)->toContain('PHP Parse error');
})->with([FileKind::Ppphp, FileKind::Php]);

test('lint timeout and execution failures become structured diagnostics', function (PhpLintResult $result, string $message): void {
    $diagnostics = (new PhpLintValidator(runner: stageTenLintRunner($result)))
        ->validate(stageTenLintArtifact(), '/hidden/candidate.php');

    expect($diagnostics->errors[0]->code)->toBe(DiagnosticCode::PhpOutputValidationFailed)
        ->and($diagnostics->errors[0]->message)->toBe($message)
        ->and($diagnostics->errors[0]->primary)->toBeNull()
        ->and($diagnostics->errors[0]->help)->toContain('--debug')
        ->and((new ConsoleRenderer())->render($diagnostics))->not->toContain('/hidden/candidate.php', 'src/One.ppphp');
})->with([
    'timeout' => [new PhpLintResult(null, timedOut: true, executionFailure: 'timeout'), 'PHP lint validation timed out.'],
    'execution failure' => [new PhpLintResult(null, executionFailure: 'PHP executable unavailable'), 'PHP lint validation could not be executed.'],
    'unlocated failure' => [new PhpLintResult(2, stderr: 'unknown failure'), 'The candidate PHP output did not pass php -l.'],
]);
