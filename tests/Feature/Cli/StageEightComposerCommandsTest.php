<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Cli\Application;
use Atatusoft\Ppphp\Cli\Enumerations\ExitCode;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Process\Process;

function runStageEightComposerCommand(array $input): ApplicationTester
{
    $application = new Application();
    $application->setAutoExit(false);
    $tester = new ApplicationTester($application);
    $tester->run(['--no-ansi' => true, ...$input]);

    return $tester;
}

test('composer configure supports dry runs explicit writes follow-up guidance and JSON output', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->createDirectory($root . '/src');
    $original = json_encode([
        'name' => 'example/application',
        'autoload' => ['psr-4' => ['App\\' => 'src/']],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $this->writeFile($root . '/composer.json', $original);

    $dryRun = runStageEightComposerCommand([
        'command' => 'composer:configure',
        '--working-directory' => $root,
        '--dry-run' => true,
    ]);

    expect($dryRun->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($dryRun->getDisplay())->toContain('Would update composer.json')
        ->toContain('composer update --lock')
        ->toContain('composer dump-autoload')
        ->and(file_get_contents($root . '/composer.json'))->toBe($original);

    $write = runStageEightComposerCommand([
        'command' => 'composer:configure',
        '--working-directory' => $root,
    ]);
    $configured = (string) file_get_contents($root . '/composer.json');

    expect($write->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($write->getDisplay())->toContain('Updated composer.json')
        ->and($configured)->not->toBe($original);

    $json = runStageEightComposerCommand([
        'command' => 'composer:configure',
        '--working-directory' => $root,
        '--format' => 'json',
    ]);
    $payload = json_decode($json->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

    expect($json->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($payload['version'])->toBe(1)
        ->and($payload['diagnostics'])->toBe([])
        ->and($payload['summary'])->toBe(['errors' => 0, 'warnings' => 0, 'notes' => 0]);
});

test('build warns until root Composer application mappings target generated PHP', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Thing.ppphp', '<?php namespace App; final class Thing {}');
    $this->writeFile($root . '/composer.json', json_encode([
        'name' => 'example/application',
        'autoload' => ['psr-4' => ['App\\' => 'src/']],
    ], JSON_THROW_ON_ERROR));

    $before = runStageEightComposerCommand([
        'command' => 'build',
        '--working-directory' => $root,
    ]);

    expect($before->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($before->getDisplay())->toContain('WARNING P6008 · ')
        ->toContain('autoload.psr-4.App\\')
        ->toContain('src/')
        ->toContain('build/ppphp/');

    $configure = runStageEightComposerCommand([
        'command' => 'composer:configure',
        '--working-directory' => $root,
    ]);
    $after = runStageEightComposerCommand([
        'command' => 'build',
        '--working-directory' => $root,
    ]);

    expect($configure->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($after->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($after->getDisplay())->not->toContain('P6008')
        ->and(file_exists($root . '/build/ppphp/Thing.php'))->toBeTrue();
});

test('generated entry scripts relocate the project Composer bootstrap and remain executable', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root, ['output' => 'build/src']);
    $this->writeFile($root . '/composer.json', json_encode([
        'name' => 'example/application',
        'autoload' => ['psr-4' => []],
    ], JSON_THROW_ON_ERROR));
    $this->writeFile($root . '/vendor/autoload.php', "<?php\n");
    $this->writeFile($root . '/src/bootstrap.php', "<?php\necho 'boot:';\n");
    $this->writeFile($root . '/src/index.ppphp', <<<'PPP'
<?php
require_once __DIR__ . '/vendor/autoload.php';
include_once __DIR__ . '/bootstrap.php';
string $message = 'ran';
echo $message;
PPP);

    $build = runStageEightComposerCommand([
        'command' => 'build',
        '--working-directory' => $root,
    ]);
    $generatedPath = $root . '/build/src/index.php';
    $generated = (string) file_get_contents($generatedPath);
    $runtime = new Process([PHP_BINARY, $generatedPath], $root);
    $runtime->run();

    expect($build->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($generated)->toContain("require_once __DIR__ . '/../../vendor/autoload.php';")
        ->toContain("include_once __DIR__ . '/bootstrap.php';")
        ->and($runtime->getExitCode())->toBe(0)
        ->and($runtime->getOutput())->toBe('boot:ran')
        ->and($runtime->getErrorOutput())->toBe('');
});

test('Composer bootstrap relocation follows custom output and vendor directories', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root, ['output' => 'artifacts/runtime']);
    $this->writeFile($root . '/composer.json', json_encode([
        'name' => 'example/application',
        'config' => ['vendor-dir' => 'dependencies/php'],
        'autoload' => ['psr-4' => []],
    ], JSON_THROW_ON_ERROR));
    $this->writeFile($root . '/dependencies/php/autoload.php', "<?php\n");
    $this->writeFile($root . '/src/bin/index.ppphp', <<<'PPP'
<?php
require_once __DIR__ . '/dependencies/php/autoload.php';
echo 'custom';
PPP);

    $build = runStageEightComposerCommand([
        'command' => 'build',
        '--working-directory' => $root,
    ]);
    $generatedPath = $root . '/artifacts/runtime/bin/index.php';
    $generated = (string) file_get_contents($generatedPath);
    $runtime = new Process([PHP_BINARY, $generatedPath], $root);
    $runtime->run();

    expect($build->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($generated)->toContain(
            "require_once __DIR__ . '/../../../dependencies/php/autoload.php';",
        )
        ->and($runtime->getExitCode())->toBe(0)
        ->and($runtime->getOutput())->toBe('custom')
        ->and($runtime->getErrorOutput())->toBe('');
});
