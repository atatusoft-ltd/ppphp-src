<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Cli\Enumerations\ExitCode;
use Tests\Support\StageElevenProject;

test('duplicate project classes and functions receive compiler-owned diagnostics', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root, ['source' => ['src', 'legacy']]);
    $this->writeFile($root . '/src/Duplicate.ppphp', <<<'PPP'
<?php
namespace Example;
final class Duplicate {}
function duplicate(): void {}
PPP);
    $this->writeFile($root . '/legacy/Duplicate.php', <<<'PHP'
<?php
namespace Example;
final class Duplicate {}
function duplicate(): void {}
PHP);

    $check = StageElevenProject::runCommand(['command' => 'check', '--working-directory' => $root]);
    $build = StageElevenProject::runCommand(['command' => 'build', '--working-directory' => $root]);

    expect($check->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and(substr_count($check->getDisplay(), 'ERROR P2034 · '))->toBe(2)
        ->and($check->getDisplay())->toContain('Example\\Duplicate', 'Example\\duplicate')
        ->toContain('src/Duplicate.ppphp', 'legacy/Duplicate.php', 'NOTE · ')
        ->and($build->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and(file_exists($root . '/build/ppphp/Duplicate.php'))->toBeFalse();
});

test('stubs cannot hide duplicate project declarations through source ordering', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root, [
        'source' => ['a-source', 'z-source'],
        'stubs' => ['m-stubs'],
    ]);
    $this->writeFile($root . '/a-source/Boundary.php', "<?php\nfinal class Boundary {}\n");
    $this->writeFile($root . '/m-stubs/Boundary.stub.php', "<?php\nfinal class Boundary {}\n");
    $this->writeFile($root . '/z-source/Boundary.php', "<?php\nfinal class Boundary {}\n");

    $check = StageElevenProject::runCommand(['command' => 'check', '--working-directory' => $root]);

    expect($check->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and(substr_count($check->getDisplay(), 'ERROR P2034 · '))->toBe(1)
        ->and($check->getDisplay())->toContain('a-source/Boundary.php', 'z-source/Boundary.php')
        ->not->toContain('m-stubs/Boundary.stub.php');
});

test('focused checks report only duplicate context declarations used by the target', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/ContextA.php', <<<'PHP'
<?php
namespace Example;
final class Duplicate {}
function duplicate(): void {}
PHP);
    $this->writeFile($root . '/src/ContextB.php', <<<'PHP'
<?php
namespace Example;
final class Duplicate {}
function duplicate(): void {}
PHP);
    $this->writeFile($root . '/src/Independent.ppphp', <<<'PPP'
<?php
namespace Example;
function independent(): string { return 'ok'; }
PPP);
    $this->writeFile($root . '/src/Caller.ppphp', <<<'PPP'
<?php
namespace Example;
function consume(Duplicate $value): Duplicate { duplicate(); return $value; }
PPP);

    $independent = StageElevenProject::runCommand([
        'command' => 'check',
        'path' => 'src/Independent.ppphp',
        '--working-directory' => $root,
    ]);
    $caller = StageElevenProject::runCommand([
        'command' => 'check',
        'path' => 'src/Caller.ppphp',
        '--working-directory' => $root,
    ]);

    expect($independent->getStatusCode())->toBe(ExitCode::Success->value, $independent->getDisplay())
        ->and($independent->getDisplay())->not->toContain('P2034')
        ->and($caller->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and(substr_count($caller->getDisplay(), 'ERROR P2034 · '))->toBe(2, $caller->getDisplay())
        ->and($caller->getDisplay())->toContain(
            'src/Caller.ppphp',
            'src/ContextA.php',
            'src/ContextB.php',
            'This selected reference is ambiguous',
        );
});

test('configured stubs may intentionally describe project declarations', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Boundary.php', <<<'PHP'
<?php
final class Boundary { public function value(): string { return 'ok'; } }
PHP);
    $this->writeFile($root . '/stubs/Boundary.stub.php', <<<'PHP'
<?php
final class Boundary { public function value(): string {} }
PHP);
    $this->writeFile($root . '/src/Caller.ppphp', <<<'PPP'
<?php
function callBoundary(Boundary $boundary): string { return $boundary->value(); }
PPP);

    $check = StageElevenProject::runCommand(['command' => 'check', '--working-directory' => $root]);

    expect($check->getStatusCode())->toBe(ExitCode::Success->value, $check->getDisplay())
        ->and($check->getDisplay())->not->toContain('P2034');
});

test('existing cross-boundary conflict diagnostics remain specific and atomic', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Conflict.ppphp', <<<'PPP'
<?php
class Box<T> {}
/** @template U */
class DocumentedBox<T> {}
class Failure extends RuntimeException {}
/** @throws LogicException */
function conflict(): void throws Failure {}
PPP);

    $check = StageElevenProject::runCommand(['command' => 'check', '--working-directory' => $root]);
    $build = StageElevenProject::runCommand(['command' => 'build', '--working-directory' => $root]);

    expect($check->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and($check->getDisplay())->toContain(
            'ERROR P3010 · ',
            'ERROR P4007 · ',
        )
        ->and($build->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and(file_exists($root . '/build/ppphp'))->toBeFalse();
});

test('mixed generic array and checked-error contract violations fail at original sources', function (): void {
    $cases = [
        [
            'src/Infrastructure/PersonRepository.ppphp',
            'implements Repository<Person>',
            'implements Repository<string>',
        ],
        [
            'legacy/Presentation/LegacyPresenter.php',
            '@param Box<Person> $box',
            '@param Box<string> $box',
        ],
        [
            'legacy/Http/LegacyController.php',
            "return \$tags[0] . ':' . \$scores['quality'];",
            "return \$tags[0] . ':' . strtoupper(\$scores['quality']);",
        ],
        [
            'src/Service/PersonService.ppphp',
            'public function tags(): array<string>',
            'public function tags(): array<int>',
            'src/Application.ppphp',
        ],
        [
            'src/Service/PersonService.ppphp',
            'public function load(string $id): Person throws LegacyUnavailable',
            'public function load(string $id): Person',
        ],
    ];

    $temporary = $this->createTemporaryDirectory();

    foreach ($cases as $index => $case) {
        [$file, $search, $replacement] = $case;
        $diagnosticFile = $case[3] ?? $file;
        $root = $temporary . '/case-' . $index;
        StageElevenProject::copyTree(dirname(__DIR__, 3) . '/examples/mixed-application', $root);
        $path = $root . '/' . $file;
        $source = (string) file_get_contents($path);
        $changed = str_replace($search, $replacement, $source);
        expect($changed)->not->toBe($source);
        $this->writeFile($path, $changed);

        $check = StageElevenProject::runCommand(['command' => 'check', '--working-directory' => $root]);

        expect($check->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value, $file . "\n" . $check->getDisplay())
            ->and($check->getDisplay())->toContain($diagnosticFile)
            ->not->toContain('.ppphp-cache/analysis', 'build/ppphp/');
    }
});

test('compiled and copied output collisions preserve the previous complete output', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Foo.php', "<?php\nfinal class LegacyFoo {}\n");
    $successful = StageElevenProject::runCommand(['command' => 'build', '--working-directory' => $root]);
    $before = StageElevenProject::captureTree($root . '/build/ppphp');
    $this->writeFile($root . '/src/Foo.ppphp', "<?php\nfinal class GeneratedFoo {}\n");

    $collision = StageElevenProject::runCommand(['command' => 'build', '--working-directory' => $root]);

    expect($successful->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($collision->getStatusCode())->toBe(ExitCode::OutputValidationFailed->value)
        ->and($collision->getDisplay())->toContain('ERROR P7002 · ')
        ->and(StageElevenProject::captureTree($root . '/build/ppphp'))->toBe($before);
});

test('Composer projection conflicts leave composer json byte-identical', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->createDirectory($root . '/src');
    $original = json_encode([
        'autoload' => ['psr-4' => ['Example\\' => 'somewhere-else/']],
        'extra' => ['ppphp' => [
            'source-autoload' => [
                'psr-4' => ['Example\\' => 'src/'],
                'classmap' => [],
                'files' => [],
            ],
            'source-autoload-dev' => [
                'psr-4' => new stdClass(),
                'classmap' => [],
                'files' => [],
            ],
        ]],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $this->writeFile($root . '/composer.json', $original);

    $configure = StageElevenProject::runCommand([
        'command' => 'composer:configure',
        '--working-directory' => $root,
    ]);

    expect($configure->getStatusCode())->toBe(ExitCode::InvalidProject->value)
        ->and($configure->getDisplay())->toContain('ERROR P6011 · ')
        ->and(file_get_contents($root . '/composer.json'))->toBe($original);
});
