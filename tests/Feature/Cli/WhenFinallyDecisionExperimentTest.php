<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

test('finally decision experiments record the pinned gate and runtime outcomes', function (string $fixture, string $selected, string $failed, string $other, int $gateStatus = 0): void {
    $root = $this->createTemporaryDirectory();
    $repository = dirname(__DIR__, 3);
    $path = $repository . '/tests/Fixtures/WhenDecisions/Finally/' . $fixture . '.php';
    $configuration = $root . '/phpstan.neon';
    $this->writeFile($configuration, "includes:\n    - {$repository}/resources/phpstan/ppphp.neon\nparameters:\n    phpVersion: 80400\n    tmpDir: {$root}/analysis\n");
    $analysis = new Process([PHP_BINARY, $repository . '/vendor/bin/phpstan', 'analyse', '--configuration=' . $configuration, '--no-progress', '--debug', $path], timeout: 60);
    $analysis->run();
    expect($analysis->getExitCode())->toBe($gateStatus, $analysis->getOutput() . $analysis->getErrorOutput());
    if ($gateStatus !== 0) {
        // The native baseline runs, but PHPStan rejects its overridden exit points.
        expect($analysis->getOutput())->toContain('finally.exitPoint');
    }

    foreach ([['1', '0', $selected], ['1', '1', $failed], ['0', '0', $other]] as [$when, $fail, $expected]) {
        $runtime = new Process([PHP_BINARY, $path], env: ['W' => $when, 'FAIL' => $fail], timeout: 5);
        $runtime->run();
        expect($runtime->getExitCode())->toBe(0, $runtime->getErrorOutput())
            ->and($runtime->getOutput())->toBe($expected)
            ->and($runtime->getErrorOutput())->toBe('');
    }
})->with([
    ['S1-cleanup', 'cleanup|5', 'cleanup|5', 'else'],
    ['S2-supersede', 'recovered', 'recovered', 'else'],
    ['S2-propagate', 'exception:pending', 'exception:pending', 'else'],
    ['S3-replace-result', 'finally', 'finally', 'else'],
    ['S4-nested-cleanup', 'inner|outer|value', 'inner|outer|value', 'else'],
    ['S5-catch-cleanup', 'cleanup|5', 'cleanup|-1', 'else'],
    ['S6-delayed-assignment', 'compute|caught fail|10', 'compute|caught fail|10', ''],
    ['S6-unsafe-early-assignment', 'compute|caught fail|5', 'compute|caught fail|5', ''],
    ['native-baseline', 'recovered|finally', 'recovered|finally', 'recovered|finally', 1],
]);
