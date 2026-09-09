<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Cli\Application;
use Atatusoft\Ppphp\Cli\Enumerations\ExitCode;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Process\Process;

function runStageNineCommand(array $input): ApplicationTester
{
    $application = new Application();
    $application->setAutoExit(false);
    $tester = new ApplicationTester($application);
    $tester->run(['--no-ansi' => true, ...$input]);

    return $tester;
}

test('when projects check build lint and run with exact evaluation order', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $bootstrap = "<?php\ndeclare(strict_types=1);\n";
    $this->writeFile($root . '/src/bootstrap.php', $bootstrap);
    $this->writeFile($root . '/src/application.ppphp', <<<'PPP'
<?php
require_once __DIR__ . '/bootstrap.php';

function record(array &$trace, string $value): string
{
    $trace[] = $value;
    echo $value, '|';

    return $value;
}

function consume(string $before, string $selected, string $after): void {}

array<string> $trace = [];
consume(
    record($trace, 'before'),
    when (record($trace, 'condition') === 'condition') {
        return when ($trace !== []) {
            return record($trace, 'selected');
        } else {
            return record($trace, 'nested-fallback');
        };
    } else {
        return record($trace, 'fallback');
    },
    record($trace, 'after'),
);
PPP);
    $check = runStageNineCommand([
        'command' => 'check',
        '--working-directory' => $root,
    ]);
    $build = runStageNineCommand([
        'command' => 'build',
        '--working-directory' => $root,
    ]);
    $generatedPath = $root . '/build/ppphp/application.php';
    $generated = file_get_contents($generatedPath);
    $lint = new Process([PHP_BINARY, '-l', $generatedPath]);
    $lint->run();
    $runtime = new Process([PHP_BINARY, $generatedPath], $root);
    $runtime->run();

    expect($check->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($build->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($generated)->toBeString()
        ->not->toContain('when (')
        ->not->toContain('function () use')
        ->and(file_get_contents($root . '/build/ppphp/bootstrap.php'))->toBe($bootstrap)
        ->and($lint->isSuccessful())->toBeTrue()
        ->and($runtime->getExitCode())->toBe(0)
        ->and($runtime->getOutput())->toBe('before|condition|selected|after|')
        ->and($runtime->getErrorOutput())->toBe('');
});

test('invalid when projects fail checking and building without output or implementation leaks', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Invalid.ppphp', <<<'PPP'
<?php
function invalid(bool $ready): string
{
    return when ($ready) {
        echo 'missing';
    } else {
        return 'fallback';
    };
}
PPP);
    $check = runStageNineCommand([
        'command' => 'check',
        '--working-directory' => $root,
    ]);
    $build = runStageNineCommand([
        'command' => 'build',
        '--working-directory' => $root,
    ]);

    expect($check->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and($check->getDisplay())->toContain('ERROR P5002 · ')
        ->toContain('src/Invalid.ppphp:')
        ->not->toContain('.ppphp-cache')
        ->not->toContain('$__ppphp_when_')
        ->and($build->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and(file_exists($root . '/build/ppphp/Invalid.php'))->toBeFalse();
});

test('focused when checks ignore invalid unselected source', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Selected.ppphp', <<<'PPP'
<?php
function selected(bool $ready): string
{
    return when ($ready) { return 'ready'; } else { return 'waiting'; };
}
PPP);
    $this->writeFile($root . '/src/Unselected.ppphp', <<<'PPP'
<?php
function unselected(bool $ready): string
{
    return when ($ready) { echo 'missing'; } else { return 'waiting'; };
}
PPP);
    $focused = runStageNineCommand([
        'command' => 'check',
        'path' => 'src/Selected.ppphp',
        '--working-directory' => $root,
    ]);
    $complete = runStageNineCommand([
        'command' => 'check',
        '--working-directory' => $root,
    ]);

    expect($focused->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($complete->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and($complete->getDisplay())->toContain('src/Unselected.ppphp:');
});

test('backend findings inside when branches map to original source', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Backend.ppphp', <<<'PPP'
<?php
final class Service {}
function invalid(Service $service): string
{
    return when (true) {
        $service->missingMethod();
        return 'selected';
    } else {
        return 'fallback';
    };
}
PPP);
    $check = runStageNineCommand([
        'command' => 'check',
        '--working-directory' => $root,
    ]);

    expect($check->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and($check->getDisplay())->toContain('ERROR P2018 · ')
        ->toContain('src/Backend.ppphp:6:')
        ->not->toContain('.ppphp-cache')
        ->not->toContain('$__ppphp_when_');
});
