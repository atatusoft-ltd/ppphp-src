<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Cli\Application;
use Atatusoft\Ppphp\Cli\Enumerations\ExitCode;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Process\Process;

function runStageEightCommand(array $input): ApplicationTester
{
    $application = new Application();
    $application->setAutoExit(false);
    $tester = new ApplicationTester($application);
    $tester->run(['--no-ansi' => true, ...$input]);

    return $tester;
}

test('Stage 8 projects check build lint and run with erased generics and typed arrays', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Main.ppphp', <<<'PPP'
<?php
class Box<T>
{
    public function __construct(public T $value) {}
    public function get(): T { return $this->value; }
}
function identity<T>(T $value): T { return $value; }
function main(): void
{
    Box<string> $box = new Box('ready');
    string $value = identity($box->get());
    array<string> $names = ['Andrew'];
    array<string, int> $scores = ['Andrew' => 100];
    foreach ($names as int $index => string $name) {}
    foreach ($scores as string $person => int $score) {}
    echo $value;
}
main();
PPP);
    $check = runStageEightCommand([
        'command' => 'check',
        '--working-directory' => $root,
    ]);
    $build = runStageEightCommand([
        'command' => 'build',
        '--working-directory' => $root,
    ]);
    $output = file_get_contents($root . '/build/ppphp/Main.php');
    $lint = new Process([PHP_BINARY, '-l', $root . '/build/ppphp/Main.php']);
    $lint->run();
    $run = new Process([PHP_BINARY, $root . '/build/ppphp/Main.php']);
    $run->run();

    expect($check->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($build->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($output)->toBeString()
        ->toContain('@template T')
        ->toContain('@var Box<string> $box')
        ->toContain('@var list<string> $names')
        ->toContain('@var array<string, int> $scores')
        ->not->toContain('class Box<T>')
        ->and($lint->isSuccessful())->toBeTrue()
        ->and($run->isSuccessful())->toBeTrue()
        ->and($run->getOutput())->toBe('ready');
});

test('generic return and array findings surface through compiler-owned diagnostics', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Invalid.ppphp', <<<'PPP'
<?php
function identity<T>(T $value): T { return $value; }
final class Mapper
{
    public function preserve<T>(T $value): T { return $value; }
}
function invalidIdentity(): int { return identity('wrong'); }
function invalidMethod(): int { return (new Mapper())->preserve('wrong'); }
function produce<T>(): T { return 1; }
function invalidList(): array<string> { return ['key' => 'value']; }
function invalidMap(): array<string, int> { return ['key' => 'wrong']; }
PPP);
    $tester = runStageEightCommand([
        'command' => 'check',
        '--working-directory' => $root,
    ]);

    expect($tester->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and($tester->getDisplay())->toContain('ERROR P2016 · ')
        ->toContain('ERROR P3015 · ')
        ->toContain('ERROR P3013 · ')
        ->not->toContain('P3099')
        ->not->toContain('method.templateTypeNotInParameter')
        ->not->toContain('return.type')
        ->not->toContain('.ppphp-cache')
        ->and(substr_count($tester->getDisplay(), 'ERROR P2016 · '))->toBeGreaterThanOrEqual(2);
});

test('generic construction mismatch blocks checking and building before backend lowering', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/InvalidConstruction.ppphp', <<<'PPP'
<?php
final class Box<T>
{
    public function __construct(public T $value) {}
}
function invalid(): void { Box<string> $box = new Box(1); }
PPP);
    $check = runStageEightCommand([
        'command' => 'check',
        '--working-directory' => $root,
    ]);
    $build = runStageEightCommand([
        'command' => 'build',
        '--working-directory' => $root,
    ]);

    expect($check->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and($check->getDisplay())->toContain('ERROR P3016 · ')
        ->not->toContain('.ppphp-cache')
        ->and($build->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and(file_exists($root . '/build/ppphp/InvalidConstruction.php'))->toBeFalse();
});

test('focused checks resolve generic declarations from unselected project context', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Box.ppphp', <<<'PPP'
<?php
class Box<T>
{
    public function __construct(public T $value) {}
}
PPP);
    $this->writeFile($root . '/src/Consumer.ppphp', <<<'PPP'
<?php
function consume(): void { Box<string> $box = new Box('ready'); }
PPP);
    $tester = runStageEightCommand([
        'command' => 'check',
        'path' => 'src/Consumer.ppphp',
        '--working-directory' => $root,
    ]);

    expect($tester->getStatusCode())->toBe(ExitCode::Success->value, $tester->getDisplay())
        ->and($tester->getDisplay())->not->toContain('P2020')
        ->not->toContain('P3007');
});

test('ordinary PHP template declarations are valid focused-check boundaries', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Boundary.php', <<<'PHP'
<?php
/** @template T */
class LegacyBox
{
    /** @param T $value */
    public function __construct(public mixed $value) {}
}
PHP);
    $this->writeFile($root . '/src/Consumer.ppphp', <<<'PPP'
<?php
function consumeLegacy(): void { LegacyBox<string> $box = new LegacyBox('ready'); }
PPP);
    $tester = runStageEightCommand([
        'command' => 'check',
        'path' => 'src/Consumer.ppphp',
        '--working-directory' => $root,
    ]);

    expect($tester->getStatusCode())->toBe(ExitCode::Success->value, $tester->getDisplay())
        ->and($tester->getDisplay())->not->toContain('P2020')
        ->not->toContain('P3007');
});

test('raw references to known native generic declarations remain compiler errors', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Raw.ppphp', <<<'PPP'
<?php
class Box<T> {}
function invalid(Box $box): void {}
PPP);
    $tester = runStageEightCommand([
        'command' => 'check',
        '--working-directory' => $root,
    ]);

    expect($tester->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and($tester->getDisplay())->toContain('ERROR P3006 · ');
});
