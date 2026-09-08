<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/** @return array{int|null, array<string, mixed>} */
function requestEditorDiagnostics(string $root, array|string $request): array
{
    $process = new Process([
        PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'editor:diagnostics',
        '--working-directory', $root, '--format=json', '--no-ansi',
    ], timeout: 30);
    $process->setInput(is_string($request) ? $request : json_encode($request, JSON_THROW_ON_ERROR));
    $process->run();
    expect($process->getErrorOutput())->toBe('');
    $response = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($response)->toBeArray();

    return [$process->getExitCode(), $response];
}

test('saved check build and unsaved diagnostics use the same plain syntax cause', function (string $extension): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $path = 'src/main.' . $extension;
    $contents = "<?php function run(): int { \$lines = [1]\nreturn 1; }";
    $this->writeFile($root . '/' . $path, $contents);
    [$exit, $response] = requestEditorDiagnostics($root, [
        'version' => 1, 'document' => ['path' => $path, 'contents' => $contents],
    ]);
    expect($exit)->toBe(1)
        ->and($response['diagnostics'][0]['message'])->toBe('Expected a semicolon before `return`.');
    foreach (['check', 'build'] as $command) {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', $command,
            '--working-directory', $root, '--format=json']);
        $process->run();
        $saved = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        expect($process->getExitCode())->toBe(1)
            ->and($saved['diagnostics'])->toBe($response['diagnostics'])
            ->and($process->getOutput())->not->toContain('T_RETURN', 'PHP 8.4');
    }
    expect(file_get_contents($root . '/' . $path))->toBe($contents);
})->with(['php', 'ppphp']);

test('all source-checking commands reject Composer target conflicts before analyzing files', function (string $command): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', '<?php echo ;');
    $this->writeFile($root . '/composer.json', json_encode(['config' => ['platform' => ['php' => '99.1']]]));
    $process = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', $command,
        '--working-directory', $root, '--format=json']);
    if ($command === 'editor:diagnostics') {
        $process->setInput(json_encode(['version' => 1, 'document' => ['path' => 'src/main.ppphp', 'contents' => '<?php echo ;']]));
    }
    $process->run();
    expect($process->getExitCode())->toBe(2)
        ->and($process->getOutput())->toContain('99.1', 'config.platform.php')
        ->and($process->getOutput())->not->toContain('P1001')
        ->and(file_exists($root . '/.ppphp-cache/analysis'))->toBeFalse();
})->with(['check', 'build', 'editor:diagnostics']);

test('editor diagnostics report unsaved errors and clear them without touching disk or compiler state', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $saved = '<?php int $value = 1;';
    $this->writeFile($root . '/src/main.ppphp', $saved);
    $this->writeFile($root . '/build/ppphp/sentinel', 'last good build');
    $this->writeFile($root . '/.ppphp-cache/sentinel', 'saved evidence');
    $contents = "<?php\r\n// café 😀\r\nint \$value = 'wrong';\r\n";
    [$exit, $response] = requestEditorDiagnostics($root, [
        'version' => 1, 'document' => ['path' => $root . '/src/main.ppphp', 'contents' => $contents, 'version' => 7],
    ]);

    expect($exit)->toBe(1)
        ->and($response['document'])->toBe(['path' => 'src/main.ppphp', 'version' => 7])
        ->and($response['error'])->toBeNull()
        ->and($response['analysis'])->toMatchArray(['completeness' => 'compilerCore', 'fullParity' => true, 'supplemental' => false])
        ->and($response['summary']['errors'])->toBe(1);
    $diagnostic = $response['diagnostics'][0];
    expect($diagnostic['code'])->toBe('P2008')
        ->and($diagnostic['severity'])->toBe('error')
        ->and($diagnostic['message'])->toContain('not assignable')
        ->and($diagnostic['help'])->toBeString()
        ->and($diagnostic['location']['file'])->toBe('src/main.ppphp')
        ->and($diagnostic['location']['range']['start']['line'])->toBe(3)
        ->and($diagnostic['location']['range']['start']['offset'])->toBe(strpos($contents, "'wrong'"))
        ->and($diagnostic['related'])->not->toBeEmpty();

    [$exit, $response] = requestEditorDiagnostics($root, [
        'version' => 1, 'document' => ['path' => 'src/main.ppphp', 'contents' => $saved, 'version' => 8],
    ]);
    expect($exit)->toBe(0)->and($response['diagnostics'])->toBe([])
        ->and($response['document']['version'])->toBe(8)
        ->and(file_get_contents($root . '/src/main.ppphp'))->toBe($saved)
        ->and(scandir($root . '/.ppphp-cache'))->toBe(['.', '..', 'sentinel'])
        ->and(scandir($root . '/build/ppphp'))->toBe(['.', '..', 'sentinel'])
        ->and(file_exists($root . '/.ppphp-operation.lock'))->toBeFalse();
});

test('editor diagnostics use other unsaved generic declarations as focused context', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Box.ppphp', '<?php class Box {}');
    $this->writeFile($root . '/src/unrelated.ppphp', '<?php function broken(');
    $target = '<?php function unwrap(Box<int> $box): int { return $box->value; }';
    $overlay = '<?php class Box<T> { public function __construct(public T $value) {} }';
    [$exit, $response] = requestEditorDiagnostics($root, [
        'version' => 1, 'document' => ['path' => 'src/new/main.ppphp', 'contents' => $target],
        'overlays' => [['path' => 'src/Box.ppphp', 'contents' => $overlay]],
    ]);
    expect($exit)->toBe(0)->and($response['diagnostics'])->toBe([])
        ->and(file_exists($root . '/src/new'))->toBeFalse()
        ->and(file_get_contents($root . '/src/Box.ppphp'))->toBe('<?php class Box {}');

    [$exit, $response] = requestEditorDiagnostics($root, [
        'version' => 1, 'document' => ['path' => 'src/new/main.ppphp', 'contents' => $target],
    ]);
    expect($exit)->toBe(1)->and(array_column($response['diagnostics'], 'code'))->toContain('P3007');
});

test('editor diagnostics keep an open deleted file in memory and permit native PHP context', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/deleted.ppphp', '<?php');
    unlink($root . '/src/deleted.ppphp');
    [$exit, $response] = requestEditorDiagnostics($root, [
        'version' => 1,
        'document' => ['path' => 'src/deleted.ppphp', 'contents' => '<?php int $value = nativeValue();'],
        'overlays' => [['path' => 'src/native.php', 'contents' => '<?php function nativeValue(): string { return "wrong"; }']],
    ]);
    expect($exit)->toBe(1)->and(array_column($response['diagnostics'], 'code'))->toContain('P2008')
        ->and(file_exists($root . '/src/deleted.ppphp'))->toBeFalse()
        ->and(file_exists($root . '/src/native.php'))->toBeFalse();
});

test('editor diagnostics return compiler syntax errors for incomplete buffers', function (string $contents): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->createDirectory($root . '/src');
    [$exit, $response] = requestEditorDiagnostics($root, [
        'version' => 1, 'document' => ['path' => 'src/new.ppphp', 'contents' => $contents],
    ]);
    expect($exit)->toBe(1)->and($response['error'])->toBeNull()
        ->and($response['diagnostics'])->not->toBeEmpty()
        ->and($response['diagnostics'][0]['location']['file'])->toBe('src/new.ppphp');
})->with(['<?php function unfinished(', '<?php int $value = ', '<?php class Box<T {']);

test('editor diagnostics reject unsafe ownership and duplicate normalized paths', function (string $path): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root, ['exclude' => ['src/excluded']]);
    $this->createDirectory($root . '/src/directory.php');
    [$exit, $response] = requestEditorDiagnostics($root, [
        'version' => 1, 'document' => ['path' => $path, 'contents' => '<?php'],
    ]);
    expect($exit)->toBe(2)->and($response['error']['code'])->toBe('document-not-owned')
        ->and($response['analysis'])->toBeNull()->and($response['diagnostics'])->toBe([]);
})->with(['outside.ppphp', 'src/excluded/new.ppphp', 'stubs/new.php', 'build/ppphp/new.php',
    '.ppphp-cache/new.php', 'src/new.txt', 'src/directory.php']);

test('editor diagnostics reject duplicate overlays and symlinked buffers', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', '<?php');
    [$exit, $response] = requestEditorDiagnostics($root, [
        'version' => 1, 'document' => ['path' => 'src/main.ppphp', 'contents' => '<?php'],
        'overlays' => [['path' => $root . '/src/main.ppphp', 'contents' => '<?php']],
    ]);
    expect($exit)->toBe(2)->and($response['error']['code'])->toBe('document-not-owned');
    symlink($root . '/stubs', $root . '/src/linked');
    symlink($root . '/src/main.ppphp', $root . '/src/alias.ppphp');
    foreach (['src/linked/new.ppphp', 'src/alias.ppphp'] as $path) {
        [$exit, $response] = requestEditorDiagnostics($root, [
            'version' => 1, 'document' => ['path' => $path, 'contents' => '<?php'],
        ]);
        expect($exit)->toBe(2)->and($response['error']['code'])->toBe('document-not-owned');
    }
});

test('editor diagnostics fail closed for malformed protocol and unavailable project', function (): void {
    $root = $this->createTemporaryDirectory();
    foreach (['{', '{"version":2}', '{"version":1,"document":{"path":"src/a.ppphp"}}'] as $request) {
        [$exit, $response] = requestEditorDiagnostics($root, $request);
        expect($exit)->toBe(2)->and($response['error']['code'])->toBe('invalid-request');
    }
    [$exit, $response] = requestEditorDiagnostics($root, [
        'version' => 1, 'document' => ['path' => 'src/new.ppphp', 'contents' => '<?php'],
    ]);
    expect($exit)->toBe(2)->and($response['error']['code'])->toBe('invalid-project');
});

test('editor diagnostics return an explicit error instead of truncating excessive findings', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->createDirectory($root . '/src');
    $contents = "<?php\n";
    for ($index = 0; $index < 1001; ++$index) {
        $contents .= 'int $v' . $index . ' = "wrong";' . "\n";
    }
    [$exit, $response] = requestEditorDiagnostics($root, [
        'version' => 1, 'document' => ['path' => 'src/new.ppphp', 'contents' => $contents],
    ]);
    expect($exit)->toBe(2)->and($response['error']['code'])->toBe('response-limit')
        ->and($response['diagnostics'])->toBe([])->and($response['analysis'])->toBeNull();
});

test('native PHP buffers receive syntax diagnostics without claiming supplemental body analysis', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->createDirectory($root . '/src');
    [$exit, $response] = requestEditorDiagnostics($root, [
        'version' => 1, 'document' => ['path' => 'src/native.php', 'contents' => '<?php function broken('],
    ]);
    expect($exit)->toBe(1)->and(array_column($response['diagnostics'], 'code'))->toContain('P1001')
        ->and($response['analysis']['supplemental'])->toBeFalse();
});

test('non-owned open buffers neither disable target diagnostics nor replace trusted disk context', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root, ['exclude' => ['vendor', 'build', '.ppphp-cache', 'src/excluded']]);
    $this->createDirectory($root . '/src');
    $this->writeFile($root . '/stubs/value.stub.php', '<?php function externalValue(): string {}');
    symlink($root . '/stubs', $root . '/src/linked');
    $overlays = array_map(static fn (string $path): array => [
        'path' => $path, 'contents' => '<?php function externalValue(): int { return 1; }',
    ], [
        'vendor/package/value.php', 'build/ppphp/value.php', '.ppphp-cache/value.php',
        'stubs/value.stub.php', 'src/excluded/value.php', 'src/linked/value.stub.php', 'outside.php',
    ]);
    [$exit, $response] = requestEditorDiagnostics($root, [
        'version' => 1,
        'document' => ['path' => 'src/new.ppphp', 'contents' => '<?php int $value = externalValue();'],
        'overlays' => $overlays,
    ]);
    expect($exit)->toBe(1)->and($response['error'])->toBeNull()
        ->and(array_column($response['diagnostics'], 'code'))->toBe(['P2008'])
        ->and(file_get_contents($root . '/stubs/value.stub.php'))->toBe('<?php function externalValue(): string {}');
});
