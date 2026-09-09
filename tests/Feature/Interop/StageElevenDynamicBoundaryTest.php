<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Cli\Enumerations\ExitCode;
use Symfony\Component\Process\Process;
use Tests\Support\StageElevenProject;

test('genuine dynamic boundaries warn without blocking checks builds or runtime', function (): void {
    $root = $this->createTemporaryDirectory();
    StageElevenProject::copyTree(dirname(__DIR__, 2) . '/Fixtures/MixedProjects/DynamicBoundary', $root);

    $check = StageElevenProject::runCommand(['command' => 'check', '--working-directory' => $root]);
    $json = StageElevenProject::runCommand([
        'command' => 'check',
        '--working-directory' => $root,
        '--format' => 'json',
    ]);
    $build = StageElevenProject::runCommand(['command' => 'build', '--working-directory' => $root]);
    $payload = json_decode($json->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
    $runtime = new Process([PHP_BINARY, $root . '/build/ppphp/Dynamic.php'], $root);
    $runtime->setTimeout(10);
    $runtime->run();

    expect($check->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($check->getDisplay())->toContain('WARNING P4005 · ')
        ->toContain('src/Dynamic.ppphp:')
        ->and($json->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($payload['summary']['warnings'])->toBeGreaterThanOrEqual(1)
        ->and(array_column($payload['diagnostics'], 'code'))->toContain('P4005')
        ->and($build->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($runtime->getExitCode())->toBe(0)
        ->and($runtime->getOutput())->toBe('dynamic')
        ->and($runtime->getErrorOutput())->toBe('');
});
