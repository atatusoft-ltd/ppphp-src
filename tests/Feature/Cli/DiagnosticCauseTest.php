<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

test('saved and unsaved commands diagnose a missing opening tag before preparing analysis', function (string $command): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $contents = "final class Box<T> {}\n";
    $this->writeFile($root . '/src/main.ppphp', $contents);
    $this->writeFile($root . '/build/ppphp/sentinel', 'last good build');
    $process = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', $command, '--working-directory', $root, '--format=json']);
    if ($command === 'editor:diagnostics') {
        $process->setInput(json_encode(['version' => 1, 'document' => ['path' => 'src/main.ppphp', 'contents' => $contents]], JSON_THROW_ON_ERROR));
    }
    $process->run();
    $response = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($process->getErrorOutput())->toBe('')
        ->and($process->getExitCode())->toBe(1)
        ->and(array_column($response['diagnostics'], 'code'))->toBe(['P1011'])
        ->and($response['diagnostics'][0]['help'])->toContain('<?php')
        ->and($response['diagnostics'][0]['location']['file'])->toBe('src/main.ppphp')
        ->and($response['diagnostics'][0]['location']['range']['start']['offset'])->toBe(0)
        ->and(file_exists($root . '/.ppphp-cache/analysis'))->toBeFalse()
        ->and(file_get_contents($root . '/build/ppphp/sentinel'))->toBe('last good build');
})->with(['check', 'build', 'editor:diagnostics']);

test('editor requests preserve the actual configuration or discovery failure', function (string $command, bool $invalidConfiguration): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root, $invalidConfiguration ? ['unrecognized' => true] : []);
    $process = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', $command, '--working-directory', $root, '--format=json']);
    $request = ['version' => 1, 'document' => ['path' => 'src/main.ppphp', 'contents' => '<?php']];
    if ($command === 'editor:definition') {
        $request['position'] = ['offset' => 0];
    }
    $process->setInput(json_encode($request, JSON_THROW_ON_ERROR));
    $process->run();
    $response = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($process->getErrorOutput())->toBe('')
        ->and($process->getExitCode())->toBe(2)
        ->and($response['error']['code'])->toBe('invalid-project')
        ->and($response['error']['message'])->toContain($invalidConfiguration ? 'P0004' : 'P0014')
        ->and($response['error']['message'])->toContain($invalidConfiguration ? 'unrecognized' : 'src')
        ->and($response['error']['message'])->not->toBe('The project configuration is invalid.');
})->with(['editor:definition', 'editor:semantic-tokens', 'editor:diagnostics'])->with([true, false]);
