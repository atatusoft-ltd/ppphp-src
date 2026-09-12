<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Compiler\Compiler;
use Symfony\Component\Process\Process;

/** @param list<string> $arguments */
function runVersionVerifier(array $arguments = []): Process
{
    $root = dirname(__DIR__, 3);
    $process = new Process([PHP_BINARY, 'tools/verify-version.php', ...$arguments], $root);
    $process->run();

    return $process;
}

test('the version verifier confirms current source and release metadata', function (): void {
    $process = runVersionVerifier();
    $composer = json_decode(
        (string) file_get_contents(dirname(__DIR__, 3) . '/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($process->getOutput())->toContain(Compiler::VERSION)
        ->and($composer['scripts']['check'])->toBe(['@check:contracts', '@test'])
        ->and(array_count_values($composer['scripts']['check:contracts'])['@verify:version'] ?? 0)->toBe(1);
});

test('release validation accepts an exact matching expected version and tag', function (): void {
    $process = runVersionVerifier([
        '--expected=' . Compiler::VERSION,
        '--tag=' . Compiler::VERSION,
    ]);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
});

test('release validation rejects noncanonical and mismatched identities', function (array $arguments, string $message): void {
    $process = runVersionVerifier($arguments);

    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getErrorOutput())->toContain($message);
})->with([
    'noncanonical expected version' => [['--expected=development'], 'not a canonical ++PHP release version'],
    'mismatched expected version' => [['--expected=dev-2026.3.2'], 'expected version does not match'],
    'mismatched tag' => [[
        '--expected=' . Compiler::VERSION,
        '--tag=2026.3.1',
    ], 'Git tag does not exactly match'],
    'empty option' => [['--tag='], 'must be a non-empty string'],
]);
