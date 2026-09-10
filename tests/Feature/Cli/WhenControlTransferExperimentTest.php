<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Cli\Application;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Process\Process;

function buildWhenTransferExperiment(string $root): ApplicationTester
{
    $application = new Application();
    $application->setAutoExit(false);
    $tester = new ApplicationTester($application);
    $tester->run(['command' => 'build', '--working-directory' => $root, '--no-ansi' => true]);

    return $tester;
}

test('the exact paired search example builds matches its emitted golden and runs', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $fixtures = dirname(__DIR__, 2) . '/Fixtures/WhenDecisions';
    $source = file_get_contents($fixtures . '/Search.ppphp');
    expect($source)->toBeString();
    $this->writeFile($root . '/src/main.ppphp', $source);
    $build = buildWhenTransferExperiment($root);
    expect($build->getStatusCode())->toBe(0, $build->getDisplay());
    $path = $root . '/build/ppphp/main.php';
    expect(file_get_contents($path))->toBe(file_get_contents($fixtures . '/Search.php'));
    $runtime = new Process([PHP_BINARY, $path], timeout: 5);
    $runtime->mustRun();
    expect($runtime->getOutput())->toBe('3')->and($runtime->getErrorOutput())->toBe('');
});

test('transfer identities use original offsets rather than colliding fragment offsets', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', <<<'PPP'
<?php
function first(bool $ready, int $input): int {
    return when ($ready) { switch ($input) { default: break; } } else { return 0; };
}
function second(bool $ready, int $input): int {
    return when ($ready) { switch ($input) { default: break; } return 1; } else { return 0; };
}
PPP);
    $build = buildWhenTransferExperiment($root);
    expect($build->getStatusCode())->toBe(1, $build->getDisplay())
        ->and($build->getDisplay())->toContain('P5002')->toContain('src/main.ppphp:3:')
        ->and(file_exists($root . '/build/ppphp/main.php'))->toBeFalse();
});

test('experimental internal transfer builds expose runtime success or known finally limitations', function (string $body, string $expected, bool $knownFinallyFailure = false): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $source = <<<'PPP'
<?php
function choose(bool $ready, array<int> $values): int
{
    int $result = 0;
    int $selected = when ($ready) {
        BODY
    } else { return -1; };
    return $selected;
}
echo choose(true, [1, 2, 3, 4]), '|', choose(true, []), '|', choose(false, [1]);
PPP;
    $this->writeFile($root . '/src/main.ppphp', str_replace('BODY', $body, $source));
    $build = buildWhenTransferExperiment($root);
    if ($knownFinallyFailure) {
        // The checker accepts these internal targets. Existing finally lowering still fails its gate.
        expect($build->getStatusCode())->toBe(1, $build->getDisplay())
            ->and($build->getDisplay())->toContain('P2099')->not->toContain('P5006');
        return;
    }
    expect($build->getStatusCode())->toBe(0, $build->getDisplay());
    $path = $root . '/build/ppphp/main.php';
    $lint = new Process([PHP_BINARY, '-l', $path]);
    $lint->mustRun();
    $runtime = new Process([PHP_BINARY, $path], timeout: 5);
    $runtime->run();
    expect($runtime->getExitCode())->toBe(0, $runtime->getErrorOutput())
        ->and($runtime->getOutput())->toBe($expected)
        ->and($runtime->getErrorOutput())->toBe('');
})->with([
    'search break and empty fallback' => [
        'foreach ($values as int $value) { if ($value > 2) { $result = $value; break; } } return $result;',
        '3|0|-1',
    ],
    'continue skips only the current iteration' => [
        'foreach ($values as int $value) { if ($value < 3) { continue; } $result += $value; } return $result;',
        '7|0|-1',
    ],
    'switch break reaches the branch tail' => [
        'switch (count($values)) { case 4: { $result = 3; break; } default: { $result = 9; break; } } return $result;',
        '3|9|-1',
    ],
    'switch fallthrough reaches a yielding case' => [
        'switch (count($values)) { case 4: default: return 3; }',
        '3|3|-1',
    ],
    'continue two crosses switch to internal foreach' => [
        'foreach ($values as int $value) { switch ($value) { case 1: break; case 2: continue 2; default: $result += $value; } } return $result;',
        '7|0|-1',
    ],
    'break two reaches internal outer loop' => [
        'foreach ($values as int $value) { foreach ($values as int $other) { if ($other > 2) { $result = $other; break 2; } } } return $result;',
        '3|0|-1',
    ],
    'for continue and break' => [
        'for (int $index = 0; $index < count($values); $index++) { if ($index < 2) { continue; } $result = $values[$index]; break; } return $result;',
        '3|0|-1',
    ],
    'while continue and break' => [
        'int $index = 0; while ($index < count($values)) { $index++; if ($index < 3) { continue; } $result = $index; break; } return $result;',
        '3|0|-1',
    ],
    'do continue and break' => [
        'int $index = 0; do { $index++; if ($index < 3) { continue; } $result = $index; break; } while ($index < count($values)); return $result;',
        '3|0|-1',
    ],
    'try catch without finally does not intercept break' => [
        'foreach ($values as int $value) { try { if ($value < 0) { throw new RuntimeException(); } if ($value > 2) { $result = $value; break; } } catch (RuntimeException $error) { $result = -10; } } return $result;',
        '3|0|-1',
    ],
    'a loop wholly inside try does not cross its finally wrapper' => [
        'try { foreach ($values as int $value) { if ($value > 2) { $result = $value; break; } } return $result; } finally { return 9; }',
        '9|9|-1',
        true,
    ],
    'a loop wholly inside finally can break locally' => [
        'try { return 0; } finally { foreach ($values as int $value) { if ($value > 2) { $result = $value; break; } } return $result; }',
        '3|0|-1',
        true,
    ],
    'continue two crosses an inner foreach to an internal outer foreach' => [
        'foreach ($values as int $value) { foreach ($values as int $other) { if ($other < 2) { continue 2; } $result += $value; } } return $result;',
        '0|0|-1',
    ],
    'a no match loop preserves the tail result' => [
        'foreach ($values as int $value) { if ($value > 10) { $result = $value; break; } } return $result;',
        '0|0|-1',
    ],
]);

test('experimental transfer rejection identifies the boundary before writing output', function (string $body, string $code, string $message): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $source = <<<'PPP'
<?php
function choose(bool $ready, array<int> $values): int
{
    foreach ($values as int $outer) {
        int $selected = when ($ready) {
            BODY
        } else { return -1; };
        return $selected;
    }
    return 0;
}
PPP;
    $this->writeFile($root . '/src/main.ppphp', str_replace('BODY', $body, $source));
    $build = buildWhenTransferExperiment($root);
    expect($build->getStatusCode())->toBe(1, $build->getDisplay())
        ->and($build->getDisplay())->toContain($code)->toContain($message)
        ->and(file_exists($root . '/build/ppphp/main.php'))->toBeFalse();
})->with([
    'break two escapes one internal loop' => ['foreach ($values as int $value) { break 2; } return 1;', 'P5006', 'would leave the `when` branch'],
    'bare break cannot target an outer loop' => ['break;', 'P5006', 'would leave the `when` branch'],
    'continue two cannot target an outer loop' => ['foreach ($values as int $value) { continue 2; } return 1;', 'P5006', 'would leave the `when` branch'],
    'break cannot leave finally' => ['foreach ($values as int $value) { try { echo $value; } finally { break; } } return 1;', 'P5006', 'cannot leave a `finally` block'],
    'continue cannot leave finally' => ['foreach ($values as int $value) { try { echo $value; } finally { continue; } } return 1;', 'P5006', 'cannot leave a `finally` block'],
    'break across a protected try is deferred' => ['foreach ($values as int $value) { try { break; } finally { echo $value; } } return 1;', 'P5006', 'across `try`/`finally`'],
    'continue across a protected try is deferred' => ['foreach ($values as int $value) { try { continue; } finally { echo $value; } } return 1;', 'P5006', 'across `try`/`finally`'],
    'continue targeting switch is not silently interpreted as break' => ['switch (count($values)) { default: continue; } return 1;', 'P5006', '`continue` targets a switch'],
    'switch break without branch result is incomplete' => ['switch (count($values)) { case 4: return 4; default: break; }', 'P5002', 'Every reachable path'],
    'nested break two still requires a branch tail' => ['switch (count($values)) { default: switch ($outer) { default: break 2; } return 1; }', 'P5002', 'Every reachable path'],
    'nested when has an independent transfer boundary' => ['foreach ($values as int $value) { int $inner = when ($value > 0) { break; } else { return 0; }; } return 1;', 'P5006', 'would leave the `when` branch'],
    'unreachable escaping transfer remains invalid' => ['return 1; break;', 'P5006', 'would leave the `when` branch'],
    'zero transfer level is invalid' => ['foreach ($values as int $value) { break 0; } return 1;', 'P5006', 'positive integer'],
]);
