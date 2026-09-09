<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Cli\Application;
use Atatusoft\Ppphp\Cli\Enumerations\ExitCode;
use Symfony\Component\Console\Tester\ApplicationTester;

function runStageSixCommand(array $input): ApplicationTester
{
    $application = new Application();
    $application->setAutoExit(false);
    $tester = new ApplicationTester($application);
    $tester->run(['--no-ansi' => true, ...$input]);

    return $tester;
}

test('strict declaration failures block builds and use original ppphp paths', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Invalid.ppphp', <<<'PPP'
<?php
final class Invalid
{
    public $value;
    public function transform($input) { return $input; }
}
PPP);
    $tester = runStageSixCommand([
        'command' => 'build',
        '--working-directory' => $root,
    ]);

    expect($tester->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and($tester->getDisplay())->toContain('ERROR P2011 · ')
        ->and($tester->getDisplay())->toContain('ERROR P2012 · ')
        ->and($tester->getDisplay())->toContain('ERROR P2013 · ')
        ->and($tester->getDisplay())->toContain('src/Invalid.ppphp:')
        ->and($tester->getDisplay())->not->toContain('.ppphp-cache')
        ->and(file_exists($root . '/build/ppphp/Invalid.php'))->toBeFalse();
});

test('ordinary php omissions are not treated as ppphp declaration errors', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Legacy.php', <<<'PHP'
<?php
class Legacy
{
    public $value;
    public function transform($input) { return $input; }
}
PHP);
    $tester = runStageSixCommand([
        'command' => 'check',
        'path' => 'src/Legacy.php',
        '--working-directory' => $root,
    ]);

    expect($tester->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($tester->getDisplay())->not->toContain('P2011')
        ->and($tester->getDisplay())->not->toContain('P2012')
        ->and($tester->getDisplay())->not->toContain('P2013');
});

test('phpstan findings map to stable type and symbol diagnostics', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Types.ppphp', <<<'PPP'
<?php
namespace App;
function accepts(int $value): void {}
function invalid(bool $flag): string
{
    accepts('wrong');
    \App\missing_function();
    MissingType $unknown = new MissingType();
    if ($flag) {
        return 1;
    }
}
PPP);
    $tester = runStageSixCommand([
        'command' => 'check',
        '--working-directory' => $root,
    ]);

    expect($tester->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and($tester->getDisplay())->toContain('ERROR P2015 · ')
        ->and($tester->getDisplay())->toContain('ERROR P2016 · ')
        ->and($tester->getDisplay())->toContain('ERROR P2017 · ')
        ->and($tester->getDisplay())->toContain('ERROR P2020 · ')
        ->and($tester->getDisplay())->toContain('ERROR P2021 · ')
        ->and($tester->getDisplay())->not->toContain('argument.type')
        ->and($tester->getDisplay())->not->toContain('.ppphp-cache');
});

test('a private property that is stored but not read reports a non-blocking warning', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Service.ppphp', <<<'PPP'
<?php
final class Service
{
    public function __construct(private string $value) {}
}
PPP);
    $tester = runStageSixCommand([
        'command' => 'build',
        '--working-directory' => $root,
    ]);

    expect($tester->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($tester->getDisplay())->toContain('WARNING P2046 · ')
        ->and($tester->getDisplay())->toContain('Service::$value is never read, only written.')
        ->and($tester->getDisplay())->not->toContain('ERROR P2099')
        ->and(file_exists($root . '/build/ppphp/Service.php'))->toBeTrue();
});

test('focused analysis uses valid context and omits unrelated invalid sources', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Api.ppphp', <<<'PPP'
<?php
function provide(): string { return 'ready'; }
PPP);
    $this->writeFile($root . '/src/Caller.ppphp', <<<'PPP'
<?php
function callApi(): string { return provide(); }
PPP);
    $this->writeFile($root . '/src/Unrelated.ppphp', '<?php function broken(: void {}');
    $this->writeFile($root . '/src/Unrelated.php', '<?php function also_broken(: void {}');
    $tester = runStageSixCommand([
        'command' => 'build',
        'path' => 'src/Caller.ppphp',
        '--working-directory' => $root,
    ]);

    expect($tester->getStatusCode())->toBe(ExitCode::Success->value)
        ->and(file_exists($root . '/build/ppphp/Caller.php'))->toBeTrue()
        ->and(file_exists($root . '/build/ppphp/Api.php'))->toBeFalse()
        ->and(file_exists($root . '/.ppphp-cache/analysis/context'))->toBeTrue()
        ->and(file_exists($root . '/.ppphp-cache/analysis/maps.json'))->toBeTrue();
});

test('composer directories containing source roots retain php context outside those roots', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root, ['source' => ['app/ppphp']]);
    $this->writeFile($root . '/composer.json', json_encode([
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $this->writeFile($root . '/app/Support/BaseFeature.php', <<<'PHP'
<?php
namespace App\Support;
class BaseFeature
{
    protected string $name;
}
PHP);
    $this->writeFile($root . '/app/ppphp/Feature.ppphp', <<<'PPP'
<?php
namespace App;
use App\Support\BaseFeature;
final class Feature extends BaseFeature
{
    public function rename(): void
    {
        $this->name = 'ready';
    }
}
PPP);
    $tester = runStageSixCommand([
        'command' => 'check',
        '--working-directory' => $root,
    ]);

    expect($tester->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($tester->getDisplay())->not->toContain('P2020')
        ->and($tester->getDisplay())->not->toContain('P2022');
});

test('member property nullability and fallback findings use dedicated diagnostics', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Members.ppphp', <<<'PPP'
<?php
function acceptsText(string $value): void {}
final class Members
{
    public string $name;

    public function invalid(): void
    {
        $this->missingMethod();
        echo $this->missingProperty;
        $this->name = 1;
        acceptsText(null);
        echo [];
    }
}
PPP);
    $tester = runStageSixCommand([
        'command' => 'check',
        '--working-directory' => $root,
    ]);

    expect($tester->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and($tester->getDisplay())->toContain('ERROR P2018 · ')
        ->and($tester->getDisplay())->toContain('ERROR P2019 · ')
        ->and($tester->getDisplay())->toContain('ERROR P2024 · ')
        ->and($tester->getDisplay())->toContain('ERROR P2025 · ')
        ->and($tester->getDisplay())->not->toContain('P2099');
});

test('php and stub metadata participate in analysis without ppphp strictness leaking into php', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Legacy.php', <<<'PHP'
<?php
/** @param int $value */
function legacy($value) {}
PHP);
    $this->writeFile($root . '/stubs/external.stub.php', <<<'PHP'
<?php
function external_service(int $value): void {}
PHP);
    $this->writeFile($root . '/src/Caller.ppphp', <<<'PPP'
<?php
function callDependencies(): void
{
    legacy('wrong');
    external_service('wrong');
}
PPP);
    $tester = runStageSixCommand([
        'command' => 'check',
        'path' => 'src/Caller.ppphp',
        '--working-directory' => $root,
    ]);

    expect($tester->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and(substr_count($tester->getDisplay(), 'ERROR P2015 · '))->toBe(2)
        ->and($tester->getDisplay())->not->toContain('ERROR P2011');
});

test('selected php is checked against valid generated ppphp context', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Contract.ppphp', <<<'PPP'
<?php
function strict_contract(int $value): void {}
PPP);
    $this->writeFile($root . '/src/Caller.php', "<?php\nstrict_contract('wrong');\n");
    $tester = runStageSixCommand([
        'command' => 'check',
        'path' => 'src/Caller.php',
        '--working-directory' => $root,
    ]);

    expect($tester->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and($tester->getDisplay())->toContain('ERROR P2015 · ')
        ->and($tester->getDisplay())->toContain('src/Caller.php:');
});

test('analysis workspaces isolate duplicate relative paths across source roots', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root, ['source' => ['src', 'packages']]);
    $this->writeFile($root . '/src/Feature.ppphp', <<<'PPP'
<?php
namespace App;
function feature(): string { return 'app'; }
PPP);
    $this->writeFile($root . '/packages/Feature.ppphp', <<<'PPP'
<?php
namespace Package;
function feature(): string { return 'package'; }
PPP);
    $tester = runStageSixCommand([
        'command' => 'check',
        '--working-directory' => $root,
    ]);
    $files = glob($root . '/.ppphp-cache/analysis/selected/*/Feature.php');

    expect($tester->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($files)->toBeArray()
        ->and($files)->toHaveCount(2)
        ->and(dirname($files[0]))->not->toBe(dirname($files[1]));
});

test('analysis does not execute composer application or project phpstan bootstrap files', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $autoloadMarker = $root . '/autoload-executed';
    $vendorMarker = $root . '/vendor-autoload-executed';
    $phpstanMarker = $root . '/phpstan-bootstrap-executed';
    $this->writeFile($root . '/src/Safe.ppphp', "<?php\nfunction safe(): string { return 'safe'; }\n");
    $this->writeFile($root . '/danger.php', '<?php file_put_contents(' . var_export($autoloadMarker, true) . ", 'executed');\n");
    $this->writeFile($root . '/vendor/autoload.php', '<?php file_put_contents(' . var_export($vendorMarker, true) . ", 'executed');\n");
    $this->writeFile($root . '/phpstan-bootstrap.php', '<?php file_put_contents(' . var_export($phpstanMarker, true) . ", 'executed');\n");
    $this->writeFile($root . '/composer.json', json_encode([
        'autoload' => ['files' => ['danger.php']],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $this->writeFile($root . '/phpstan.neon', "parameters:\n    bootstrapFiles:\n        - phpstan-bootstrap.php\n");
    $tester = runStageSixCommand([
        'command' => 'check',
        '--working-directory' => $root,
    ]);

    expect($tester->getStatusCode())->toBe(ExitCode::Success->value)
        ->and(file_exists($autoloadMarker))->toBeFalse()
        ->and(file_exists($vendorMarker))->toBeFalse()
        ->and(file_exists($phpstanMarker))->toBeFalse();
});
