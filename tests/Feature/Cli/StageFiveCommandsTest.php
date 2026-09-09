<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Cli\Application;
use Atatusoft\Ppphp\Cli\Enumerations\ExitCode;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Process\Process;

function runStageFiveCommand(array $input): ApplicationTester
{
    $application = new Application();
    $application->setAutoExit(false);
    $tester = new ApplicationTester($application);
    $tester->run(['--no-ansi' => true, ...$input]);

    return $tester;
}

test('check and build analyze and lower typed locals across a selected project', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $source = <<<'PPP'
<?php
function calculate(): int
{
    int $count = 1;
    readonly string $label = 'items';
    $count += 2;
    echo $label;
    return $count;
}
PPP;
    $this->writeFile($root . '/src/Feature.ppphp', $source);
    $this->writeFile($root . '/src/Context.php', '<?php final class Context {}');

    $check = runStageFiveCommand([
        'command' => 'check',
        '--working-directory' => $root,
    ]);
    $build = runStageFiveCommand([
        'command' => 'build',
        '--working-directory' => $root,
    ]);
    $outputPath = $root . '/build/ppphp/Feature.php';
    $generated = is_file($outputPath) ? (string) file_get_contents($outputPath) : '';
    $lint = new Process([PHP_BINARY, '-l', $outputPath]);

    expect($check->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($build->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($build->getDisplay())->toContain('Compiled 1 ++PHP File.')
        ->and($build->getDisplay())->toContain('Copied 1 PHP File.')
        ->and($generated)->toContain('/** @var int $count */')
        ->toContain('/** @var string $label */')
        ->not->toContain('readonly string')
        ->and(file_get_contents($root . '/src/Feature.ppphp'))->toBe($source)
        ->and($lint->run())->toBe(0);
});

test('semantic failure prevents every output write in a project build', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile(
        $root . '/src/AValid.ppphp',
        '<?php function valid(): int { int $value = 1; return $value; }',
    );
    $this->writeFile(
        $root . '/src/ZInvalid.ppphp',
        '<?php function invalid(): void { int $value = 1; $value = "wrong"; }',
    );

    $build = runStageFiveCommand([
        'command' => 'build',
        '--working-directory' => $root,
    ]);

    expect($build->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and($build->getDisplay())->toContain('ERROR P2009 · ')
        ->and(file_exists($root . '/build/ppphp/AValid.php'))->toBeFalse()
        ->and(file_exists($root . '/build/ppphp/ZInvalid.php'))->toBeFalse();
});

test('semantic diagnostics retain stable JSON codes and original source ranges', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile(
        $root . '/src/Feature.ppphp',
        "<?php\nfunction invalid(): void\n{\n    int \$value = 'wrong';\n}\n",
    );

    $check = runStageFiveCommand([
        'command' => 'check',
        'path' => 'src/Feature.ppphp',
        '--working-directory' => $root,
        '--format' => 'json',
    ]);
    $json = json_decode($check->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
    $diagnostic = $json['diagnostics'][0];

    expect($check->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and($diagnostic['code'])->toBe('P2008')
        ->and($diagnostic['title'])->toBe('Initializer Is Not Assignable To Declared Type')
        ->and($diagnostic['location']['file'])->toBe('src/Feature.ppphp')
        ->and($diagnostic['location']['range']['start']['line'])->toBe(4)
        ->and($diagnostic['related'])->not->toBeEmpty();
});

test('pathless semantic analysis aggregates deterministic project diagnostics', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile(
        $root . '/src/AInvalid.ppphp',
        '<?php function first(): void { int $value = "wrong"; }',
    );
    $this->writeFile(
        $root . '/src/ZInvalid.ppphp',
        '<?php function second(): void { readonly int $value = 1; $value = 2; }',
    );

    $check = runStageFiveCommand([
        'command' => 'check',
        '--working-directory' => $root,
        '--format' => 'json',
    ]);
    $json = json_decode($check->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

    expect($check->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and(array_column($json['diagnostics'], 'code'))->toBe(['P2008', 'P2005'])
        ->and(array_column(array_column($json['diagnostics'], 'location'), 'file'))
        ->toBe(['src/AInvalid.ppphp', 'src/ZInvalid.ppphp']);
});

test('directory and focused selection isolate Stage 5 semantic errors', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile(
        $root . '/src/selected/Valid.ppphp',
        '<?php function valid(): int { int $value = 1; return $value; }',
    );
    $this->writeFile(
        $root . '/src/unselected/Invalid.ppphp',
        '<?php function invalid(): void { int $value = "wrong"; }',
    );

    $directory = runStageFiveCommand([
        'command' => 'build',
        'path' => 'src/selected',
        '--working-directory' => $root,
    ]);
    $focused = runStageFiveCommand([
        'command' => 'check',
        'path' => 'src/selected/Valid.ppphp',
        '--working-directory' => $root,
    ]);

    expect($directory->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($focused->getStatusCode())->toBe(ExitCode::Success->value)
        ->and(file_exists($root . '/build/ppphp/selected/Valid.php'))->toBeTrue()
        ->and(file_exists($root . '/build/ppphp/unselected/Invalid.php'))->toBeFalse();
});
