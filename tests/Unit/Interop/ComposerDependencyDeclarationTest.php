<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Analysis\Browser\CompilerAnalysisProtocol;
use Atatusoft\Ppphp\Analysis\Browser\CompilerAnalysisRequest;
use Atatusoft\Ppphp\Analysis\Declaration\DeclarationOrigin;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Frontend\PpphpParser;
use Atatusoft\Ppphp\Interop\Composer\ComposerDependencyDeclarationLoader;
use Atatusoft\Ppphp\Interop\Composer\ComposerResolver;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;

test('Composer dependency declarations load lazily with package provenance and no execution', function (): void {
    $root = $this->createTemporaryDirectory();
    foreach (portableComposerFixture() as $path => $contents) {
        $this->writeFile($root . '/' . $path, $contents);
    }
    $composer = (new ComposerResolver())->resolve($root)->project;
    expect($composer)->not->toBeNull();

    $source = new SourceFile(
        $root . '/src/main.ppphp',
        'src/main.ppphp',
        FileKind::Ppphp,
        <<<'PHP'
<?php
use Acme\Contracts\Clock;
function consume(Clock $clock): string { return acme_normalize($clock->value()->text()); }
PHP,
    );
    $parsed = (new PpphpParser())->parse($source)->parsedFile;
    expect($parsed)->not->toBeNull();

    $result = (new ComposerDependencyDeclarationLoader())->load($composer, [$parsed]);
    $displayPaths = array_map(
        static fn ($file): string => $file->sourceFile->displayPath,
        $result->parsedFiles,
    );

    expect($result->diagnostics->hasErrors)->toBeFalse()
        ->and($displayPaths)->toContain(
            '<Composer acme/contracts>/functions.php',
            '<Composer acme/contracts>/legacy/LegacyClock.php',
            '<Composer acme/contracts>/src/Clock.php',
            '<Composer acme/support>/src/Value.php',
        )
        ->and(implode("\n", $displayPaths))->not->toContain('unused/package')
        ->and(array_values(array_unique(array_map(
            static fn ($file): string => $file->sourceFile->declarationOrigin->value,
            $result->parsedFiles,
        ))))->toBe([DeclarationOrigin::ComposerDependency->value]);
});

test('dependency discovery follows contracts but not implementation-only references', function (): void {
    $root = $this->createTemporaryDirectory();
    foreach (portableComposerFixture() as $path => $contents) {
        $this->writeFile($root . '/' . $path, $contents);
    }
    $this->writeFile($root . '/vendor/acme/contracts/src/Clock.php', <<<'PHP'
<?php
namespace Acme\Contracts;
class Clock {
    public function value(): object { return new \Acme\Support\Value(); }
}
PHP);
    $composer = (new ComposerResolver())->resolve($root)->project;
    $source = new SourceFile($root . '/src/main.ppphp', 'src/main.ppphp', FileKind::Ppphp,
        '<?php function consume(\Acme\Contracts\Clock $clock): object { return $clock->value(); }');
    $parsed = (new PpphpParser())->parse($source)->parsedFile;
    $result = (new ComposerDependencyDeclarationLoader())->load($composer, [$parsed]);

    expect($result->isSuccessful)->toBeTrue()
        ->and($result->findParsedFile($root . '/vendor/acme/support/src/Value.php'))->toBeNull();
    $clock = $result->findParsedFile($root . '/vendor/acme/contracts/src/Clock.php');
    expect($clock->statements[0]->stmts[0]->getMethod('value')->stmts)->toBe([])
        ->and($clock->sourceFile->contents)->toContain('new \Acme\Support\Value()');

    // The same reference in project code must still load its dependency contract.
    $direct = new SourceFile($source->path, $source->displayPath, FileKind::Ppphp,
        '<?php function consume(): object { return new \Acme\Support\Value(); }');
    $directResult = (new ComposerDependencyDeclarationLoader())->load($composer, [(new PpphpParser())->parse($direct)->parsedFile]);
    expect($directResult->isSuccessful)->toBeTrue()
        ->and($directResult->findParsedFile($root . '/vendor/acme/support/src/Value.php'))->not->toBeNull();
});

test('guarded dependency bodies are released from original and conditional trees after discovery', function (): void {
    $root = $this->createTemporaryDirectory();
    foreach (portableComposerFixture() as $path => $contents) {
        $this->writeFile($root . '/' . $path, $contents);
    }
    $this->writeFile($root . '/vendor/acme/contracts/functions.php', <<<'PHP'
<?php
if (!function_exists('acme_guarded')) {
    function acme_guarded(): \Acme\Support\Value { return new \Acme\Support\Value(); }
}
if (!class_exists('GuardedClock')) {
    class GuardedClock {
        public function value(): object { return new \Acme\Support\Value(); }
    }
}
PHP);
    $composer = (new ComposerResolver())->resolve($root)->project;
    $result = (new ComposerDependencyDeclarationLoader())->load($composer, []);
    expect($result->isSuccessful)->toBeTrue()
        ->and($result->findParsedFile($root . '/vendor/acme/support/src/Value.php'))->not->toBeNull();
    $conditional = 0;
    foreach ($result->parsedFiles as $file) {
        $conditional += $file->sourceFile->declarationOrigin === DeclarationOrigin::ConditionalComposerDependency ? 1 : 0;
        $bodies = (new \PhpParser\NodeFinder())->find($file->statements, static fn ($node): bool =>
            ($node instanceof \PhpParser\Node\Stmt\Function_ || $node instanceof \PhpParser\Node\Stmt\ClassMethod)
            && $node->stmts !== null && $node->stmts !== []);
        expect($bodies)->toBe([]);
    }
    expect($conditional)->toBeGreaterThan(0);
});

test('compiler-only analysis consumes dependency classes methods functions and constants', function (): void {
    $root = $this->createTemporaryDirectory();
    foreach (portableComposerFixture() as $path => $contents) {
        $this->writeFile($root . '/' . $path, $contents);
    }
    $this->writeConfiguration($root, ['stubs' => []]);
    $this->writeFile($root . '/src/main.ppphp', <<<'PHP'
<?php
use Acme\Contracts\Clock;
function consume(Clock $clock): string
{
    string $mode = ACME_MODE;
    string $text = $clock->value()->text();
    return acme_normalize($mode . $text);
}
PHP);

    $response = (new CompilerAnalysisProtocol())->analyze(
        new CompilerAnalysisRequest('composer-dependencies', null),
        $root,
    )->toArray();

    expect($response['status'])->toBe('complete')
        ->and($response['diagnostics']['diagnostics'])->toBe([])
        ->and($response['uncoveredRequiredCapabilities'])->toBe([])
        ->and($response['fullParity'])->toBeTrue();
});

test('dependency call contracts retain original package locations', function (): void {
    $root = $this->createTemporaryDirectory();
    foreach (portableComposerFixture() as $path => $contents) {
        $this->writeFile($root . '/' . $path, $contents);
    }
    $this->writeConfiguration($root, ['stubs' => []]);
    $this->writeFile($root . '/src/main.ppphp', <<<'PHP'
<?php
function invalid(): void { acme_normalize(42); }
PHP);

    $response = (new CompilerAnalysisProtocol())->analyze(
        new CompilerAnalysisRequest('composer-contract', null),
        $root,
    )->toArray();
    $diagnostics = $response['diagnostics']['diagnostics'];

    expect(array_column($diagnostics, 'code'))->toBe([DiagnosticCode::ArgumentTypeDoesNotMatch->value])
        ->and($diagnostics[0]['related'][0]['location']['file'])->toBe('<Composer acme/contracts>/functions.php');
});

test('missing symbols beneath an installed mapping report unavailable dependency context', function (): void {
    $root = $this->createTemporaryDirectory();
    foreach (portableComposerFixture() as $path => $contents) {
        $this->writeFile($root . '/' . $path, $contents);
    }
    $this->writeConfiguration($root, ['stubs' => []]);
    $this->writeFile($root . '/src/main.ppphp', <<<'PHP'
<?php
function invalid(Acme\Contracts\Missing $value): void { Acme\Contracts\missing_function(); }
PHP);

    $response = (new CompilerAnalysisProtocol())->analyze(
        new CompilerAnalysisRequest('composer-missing', null),
        $root,
    )->toArray();

    expect(array_column($response['diagnostics']['diagnostics'], 'code'))->toBe([
        DiagnosticCode::DependencyDeclarationContextUnavailable->value,
    ]);
});

test('unreadable declared Composer files remain irrelevant until selected source needs them', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeFile($root . '/composer.json', '{}');
    $this->writeFile($root . '/vendor/composer/installed.json', json_encode([
        'packages' => [[
            'name' => 'broken/package',
            'install_path' => '../broken/package',
            'autoload' => ['files' => ['missing.php']],
        ]],
    ], JSON_THROW_ON_ERROR));
    $composer = (new ComposerResolver())->resolve($root)->project;
    expect($composer)->not->toBeNull();

    $result = (new ComposerDependencyDeclarationLoader())->load($composer, []);

    expect($result->parsedFiles)->toBe([])
        ->and($result->diagnostics->errors)->toBe([]);
});

test('invalid dependency declarations fail closed', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeFile($root . '/composer.json', '{}');
    $this->writeFile($root . '/vendor/composer/installed.json', json_encode([
        'packages' => [[
            'name' => 'broken/package',
            'install_path' => '../broken/package',
            'autoload' => ['files' => ['broken.php']],
        ]],
    ], JSON_THROW_ON_ERROR));
    $this->writeFile($root . '/vendor/broken/package/broken.php', '<?php function broken(');
    $composer = (new ComposerResolver())->resolve($root)->project;
    expect($composer)->not->toBeNull();

    $result = (new ComposerDependencyDeclarationLoader())->load($composer, []);

    expect($result->parsedFiles)->toBe([])
        ->and($result->diagnostics->errors)->toHaveCount(1)
        ->and($result->diagnostics->errors[0]->code)->toBe(DiagnosticCode::ComposerDependencyDeclarationInvalid);
});

test('excessive dependency declaration metadata fails before source loading', function (): void {
    $root = $this->createTemporaryDirectory();
    $files = array_map(
        static fn (int $index): string => sprintf('missing-%04d.php', $index),
        range(0, 2_048),
    );
    $this->writeFile($root . '/composer.json', '{}');
    $this->writeFile($root . '/vendor/composer/installed.json', json_encode([
        'packages' => [[
            'name' => 'large/package',
            'install_path' => '../large/package',
            'autoload' => ['files' => $files],
        ]],
    ], JSON_THROW_ON_ERROR));
    $composer = (new ComposerResolver())->resolve($root)->project;
    expect($composer)->not->toBeNull();

    $result = (new ComposerDependencyDeclarationLoader())->load($composer, []);

    expect($result->parsedFiles)->toBe([])
        ->and($result->diagnostics->errors)->toHaveCount(1)
        ->and($result->diagnostics->errors[0]->code)->toBe(DiagnosticCode::ComposerDependencyIndexLimitExceeded)
        ->and($result->diagnostics->errors[0]->message)->toContain('2,049 files', '2,048');
});

/** @return array<string, string> */
function portableComposerFixture(): array
{
    $installed = json_encode([
        'packages' => [
            [
                'name' => 'acme/contracts',
                'version' => '1.2.3',
                'install_path' => '../acme/contracts',
                'autoload' => [
                    'psr-4' => ['Acme\\Contracts\\' => ['missing', 'src']],
                    'classmap' => ['legacy'],
                    'files' => ['functions.php'],
                ],
            ],
            [
                'name' => 'acme/support',
                'install_path' => '../acme/support',
                'autoload' => ['psr-4' => ['Acme\\Support\\' => 'src']],
            ],
            [
                'name' => 'unused/package',
                'install_path' => '../unused/package',
                'autoload' => ['psr-4' => ['Unused\\' => 'src']],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    return [
        'composer.json' => '{}',
        'vendor/composer/installed.json' => $installed,
        'vendor/acme/contracts/functions.php' => <<<'PHP'
<?php
throw new LogicException('autoload files must never execute');
const ACME_MODE = 'portable:';
function acme_normalize(string $value): string { throw new LogicException('declaration body'); }
PHP,
        'vendor/acme/contracts/src/Clock.php' => <<<'PHP'
<?php
namespace Acme\Contracts;
final class Clock
{
    /** @return \Acme\Support\Value */
    public function value() { throw new \LogicException('declaration body'); }
}
PHP,
        'vendor/acme/contracts/legacy/LegacyClock.php' => <<<'PHP'
<?php
namespace Legacy;
throw new \LogicException('classmap sources must never execute');
final class LegacyClock {}
PHP,
        'vendor/acme/support/src/Value.php' => <<<'PHP'
<?php
namespace Acme\Support;
final class Value
{
    public function text(): string { throw new \LogicException('declaration body'); }
}
PHP,
        'vendor/unused/package/src/Unused.php' => '<?php namespace Unused; final class Unused {}',
    ];
}
