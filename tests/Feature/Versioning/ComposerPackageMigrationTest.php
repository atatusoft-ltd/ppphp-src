<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Compiler\Compiler;
use Symfony\Component\Process\Process;

test('Composer migrates an old root requirement without keeping both compiler packages', function (string $section): void {
    $root = $this->createTemporaryDirectory();
    $metadata = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $oldName = 'atatusoft-ltd/ppphp-src';
    $newName = Compiler::COMPOSER_PACKAGE;
    // Isolate Composer's real solver from network and released source. The
    // distribution gate separately installs and executes the actual compiler.
    $this->writeFile($root . '/composer.json', json_encode([
        'name' => 'example/migration',
        'license' => 'proprietary',
        'repositories' => [
            ['type' => 'package', 'package' => [
                ['name' => $oldName, 'version' => '2026.3.1-rc-1', 'type' => 'metapackage'],
                ['name' => $newName, 'version' => Compiler::VERSION, 'type' => 'metapackage', 'replace' => $metadata['replace']],
            ]],
            ['packagist.org' => false],
        ],
        $section => [$oldName => '2026.3.1-rc-1'],
    ], JSON_THROW_ON_ERROR));
    $run = static function (string ...$arguments) use ($root): Process {
        return (new Process(['composer', ...$arguments, '--no-interaction', '--no-scripts', '--no-plugins'], $root, [
            'COMPOSER_HOME' => $root . '/composer-home',
            'COMPOSER_CACHE_DIR' => $root . '/composer-cache',
        ], timeout: 60.0))->mustRun();
    };
    $run('install');
    $lockBefore = (string) file_get_contents($root . '/composer.lock');
    $run('remove', '--no-update', ...($section === 'require-dev' ? ['--dev', $oldName] : [$oldName]));
    expect(file_get_contents($root . '/composer.lock'))->toBe($lockBefore);
    $run('require', '--dev', $newName . ':' . Compiler::VERSION);

    $composer = json_decode((string) file_get_contents($root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $lock = json_decode((string) file_get_contents($root . '/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
    expect($composer['require-dev'][$newName])->toBe(Compiler::VERSION)
        ->and($composer['require'][$oldName] ?? null)->toBeNull()
        ->and($composer['require-dev'][$oldName] ?? null)->toBeNull()
        ->and(array_column([...$lock['packages'], ...$lock['packages-dev']], 'name'))->toBe([$newName]);

    // self.version satisfies an equivalent old-name dependency without a
    // duplicate installation, but does not claim to replace every old version.
    $run('require', '--dev', $oldName . ':' . Compiler::VERSION);
    $lock = json_decode((string) file_get_contents($root . '/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
    expect(array_column([...$lock['packages'], ...$lock['packages-dev']], 'name'))->toBe([$newName]);
    $lockBeforeConflict = (string) file_get_contents($root . '/composer.lock');
    expect(fn () => $run('require', '--dev', $oldName . ':2026.3.1-rc-1'))
        ->toThrow(Symfony\Component\Process\Exception\ProcessFailedException::class);
    expect(file_get_contents($root . '/composer.lock'))->toBe($lockBeforeConflict);
})->with(['require', 'require-dev']);
