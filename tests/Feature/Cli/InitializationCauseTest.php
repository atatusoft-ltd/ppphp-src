<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Cli\Command\InitCommand;
use Atatusoft\Ppphp\Config\ProjectConfigLoader;
use Atatusoft\Ppphp\Diagnostics\ConsoleRenderer;
use Atatusoft\Ppphp\Diagnostics\JsonRenderer;
use Atatusoft\Ppphp\Versioning\ReleaseMetadataLoader;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

test('init works from the current source checkout in a fresh Composer project', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeFile($root . '/composer.json', '{"name":"example/project"}');
    $process = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'init', '--no-interaction'], $root);
    $process->run();
    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toContain('Created ppphp.json.')
        ->and(file_get_contents($root . '/composer.json'))->toBe('{"name":"example/project"}');
});

test('init preserves the specific invalid release metadata cause', function (string $fault, string $reason): void {
    $root = $this->createTemporaryDirectory();
    $repository = dirname(__DIR__, 3);
    $manifest = json_decode(file_get_contents($repository . '/resources/release/manifest.json'), true);
    if ($fault === 'schema') {
        $manifest['schema']['sha256'] = 'sha256:' . str_repeat('0', 64);
    } elseif ($fault === 'version') {
        $manifest['version'] = '2026.3.1-rc-1';
    } else {
        $manifest['releaseNotes'] = 'docs/releases/missing.md';
    }
    $this->writeFile($root . '/manifest.json', json_encode($manifest));
    $tester = new CommandTester(new InitCommand(
        new ProjectConfigLoader(), new ConsoleRenderer(), new JsonRenderer(),
        $repository . '/ppphp.json.dist', new ReleaseMetadataLoader($repository, $root . '/manifest.json'),
    ));
    $tester->execute(['--working-directory' => $root, '--format' => 'json']);
    $diagnostics = json_decode($tester->getDisplay(), true)['diagnostics'];
    expect($tester->getStatusCode())->toBe(2)
        ->and($diagnostics[0]['code'])->toBe('P0021')
        ->and($diagnostics[0]['message'])->toContain($reason)
        ->and($diagnostics[0]['help'])->not->toContain('Reinstall')
        ->and(file_exists($root . '/ppphp.json'))->toBeFalse();
})->with([
    ['schema', 'The release schema hash does not match the bundled schema.'],
    ['version', 'The release manifest version does not match the compiler.'],
    ['notes', 'The release notes file is unavailable.'],
]);

test('multiple path errors explain the one-path contract without replacing option errors', function (string $command, string $format): void {
    $root = $this->createTemporaryDirectory();
    $process = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', $command, 'src/a.ppphp', 'src/b.ppphp', '--format=' . $format], $root);
    $process->run();
    expect($process->getExitCode())->toBe(2)
        ->and($process->getOutput() . $process->getErrorOutput())->toContain($command . ' accepts at most one path')
        ->toContain('run it once per path')
        ->and(file_exists($root . '/.ppphp-cache'))->toBeFalse();
})->with(['check', 'build'])->with(['console', 'json']);

test('unknown options retain their own cause even after a valid path', function (): void {
    $process = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'check', 'src/a.ppphp', '--unknown-option', '--format=json']);
    $process->run();
    $diagnostic = json_decode($process->getOutput(), true)['diagnostics'][0];
    expect($process->getExitCode())->toBe(2)
        ->and($diagnostic['message'])->toContain('--unknown-option')
        ->not->toContain('at most one path');
});

test('init names missing and invalid template files before writing configuration', function (bool $missing): void {
    $root = $this->createTemporaryDirectory();
    $template = $root . '/ppphp.json.dist';
    if (!$missing) {
        $this->writeFile($template, '{');
    }
    $tester = new CommandTester(new InitCommand(
        new ProjectConfigLoader(), new ConsoleRenderer(), new JsonRenderer(), $template,
    ));
    $tester->execute(['--working-directory' => $root, '--format' => 'json']);
    $diagnostic = json_decode($tester->getDisplay(), true)['diagnostics'][0];
    expect($tester->getStatusCode())->toBe(2)
        ->and($diagnostic['message'])->toContain('ppphp.json.dist')
        ->toContain($missing ? 'missing or unreadable' : 'invalid')
        ->and($diagnostic['help'])->not->toContain('Reinstall')
        ->and(file_exists($root . '/ppphp.json'))->toBeFalse();
})->with([true, false]);
