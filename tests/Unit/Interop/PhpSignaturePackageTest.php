<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Analysis\Browser\CompilerAnalysisProtocol;
use Atatusoft\Ppphp\Analysis\Browser\CompilerAnalysisRequest;
use Atatusoft\Ppphp\Analysis\Declaration\DeclarationOrigin;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Frontend\PpphpParser;
use Atatusoft\Ppphp\Interop\Php\Signature\PhpSignaturePackageLoader;
use Atatusoft\Ppphp\Interop\Php\Signature\PhpSignaturePackageVerifier;
use Atatusoft\Ppphp\Interop\Php\Signature\PhpStubNormalizer;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;

test('the committed PHP 8.4 signature package has immutable verified provenance', function (): void {
    $root = dirname(__DIR__, 3) . '/resources/php-signatures/8.4';
    $manifest = (new PhpSignaturePackageVerifier())->verify($root);

    expect($manifest['formatVersion'])->toBe(1)
        ->and($manifest['generatorVersion'])->toBe('2')
        ->and($manifest['packageVersion'])->toBe('8.4.23.2')
        ->and($manifest['targetPhpVersion'])->toBe('8.4')
        ->and($manifest['upstream'])->toBe([
            'commit' => '52cee85adfeeb6f017f2ac796ab7973353702c20',
            'repository' => 'php/php-src',
            'tag' => 'php-8.4.23',
        ])
        ->and($manifest['inputs'])->toHaveCount(129)
        ->and($manifest['counts']['functions'])->toBeGreaterThan(2_000)
        ->and($manifest['counts']['classLikes'])->toBeGreaterThan(300)
        ->and($manifest['directiveAudit'])->toHaveKeys([
            'alias',
            'cvalue',
            'implementation-alias',
            'tentative-return-type',
        ]);
});

test('mutually exclusive function variants normalize to their conservative common contract', function (): void {
    $normalizer = new PhpStubNormalizer();
    $source = <<<'PHP'
<?php
#ifdef HAVE_EXAMPLE
function example(?string $uri = null, int $port = 80, string $token = UNKNOWN): string|false {}
#else
function example(?string $uri = null, int $port = 80): string|false {}
#endif
PHP;
    $normalization = $normalizer->normalize('ext/example/example.stub.php', $source);

    expect(substr_count($normalization->source, 'function example'))->toBe(1)
        ->and($normalization->source)->toContain(
            'function example(?string $uri = null, int $port = 80): string|false',
        )
        ->not->toContain('$token')
        ->and($normalization->counts['functions'])->toBe(1)
        ->and($normalization->symbols)->toBe([[
            'availability' => null,
            'kind' => 'function',
            'name' => 'example',
        ]])
        ->and(fn () => $normalizer->normalize(
            'ext/example/incompatible.stub.php',
            "<?php\n#ifdef HAVE_EXAMPLE\nfunction example(int \$value): void {}\n#else\nfunction example(string \$value): void {}\n#endif\n",
        ))->toThrow(RuntimeException::class, 'no conservative common contract');
});

test('upstream stub normalization is deterministic conditional and fail closed', function (): void {
    $normalizer = new PhpStubNormalizer();
    $source = <<<'PHP'
<?php
/** @generate-class-entries */
#ifdef HAVE_EXAMPLE
/** @return list<string> */
function example(string $value = UNKNOWN): array {}
#endif
PHP;
    $first = $normalizer->normalize('ext/example/example.stub.php', $source);
    $second = $normalizer->normalize('ext/example/example.stub.php', $source);

    expect($second)->toEqual($first)
        ->and($first->symbols[0]['availability'])->toBe('(defined(HAVE_EXAMPLE))')
        ->and($first->source)->toContain('function example(string $value = UNKNOWN): array')
        ->and(fn () => $normalizer->normalize(
            'ext/example/invalid.stub.php',
            "<?php\n/** @unknown-stage-directive */\nfunction invalid(): void {}\n",
        ))->toThrow(RuntimeException::class, '@unknown-stage-directive');
});

test('the platform loader selects referenced modules and records explicit provenance', function (): void {
    $source = new SourceFile(
        '/project/src/platform.ppphp',
        'src/platform.ppphp',
        FileKind::Ppphp,
        <<<'PHP'
<?php
function platform(DateTimeImmutable $date): void
{
    json_encode([$date], JSON_THROW_ON_ERROR);
    array_values([$date]);
}
PHP,
    );
    $parsed = (new PpphpParser())->parse($source)->parsedFile;
    expect($parsed)->not->toBeNull();

    $loader = new PhpSignaturePackageLoader();
    $result = $loader->load('8.4', [$parsed]);
    $reloaded = $loader->load('8.4', [$parsed]);
    $paths = array_map(
        static fn ($file): string => $file->sourceFile->displayPath,
        $result->parsedFiles,
    );

    expect($result->diagnostics->hasErrors)->toBeFalse()
        ->and($paths)->toContain(
            '<PHP 8.4 platform>/Zend/zend_builtin_functions.stub.php',
            '<PHP 8.4 platform>/ext/date/php_date.stub.php',
            '<PHP 8.4 platform>/ext/json/json.stub.php',
            '<PHP 8.4 platform>/ext/standard/basic_functions.stub.php',
        )
        ->and(implode("\n", $paths))->not->toContain('/ext/curl/')
        ->and(array_values(array_unique(array_map(
            static fn ($file): string => $file->sourceFile->declarationOrigin->value,
            $result->parsedFiles,
        ))))->toBe([DeclarationOrigin::PhpPlatform->value])
        ->and(array_keys($reloaded->parsedFiles))->toBe(array_keys($result->parsedFiles));

    foreach ($result->parsedFiles as $key => $file) {
        expect($reloaded->parsedFiles[$key])->toBe($file);
    }
});

test('compiler-only analysis uses platform classes functions methods and constants', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root, ['stubs' => []]);
    $this->writeFile($root . '/src/platform.ppphp', <<<'PHP'
<?php
function platform(DateTimeImmutable $date): void
{
    string $formatted = $date->format('c');
    int $flags = JSON_THROW_ON_ERROR;
    json_encode([$formatted], $flags);
    array_values([$formatted]);
}
PHP);
    $response = (new CompilerAnalysisProtocol())->analyze(
        new CompilerAnalysisRequest('platform-signatures', null),
        $root,
    )->toArray();

    expect($response['status'])->toBe('complete')
        ->and($response['diagnostics']['diagnostics'])->toBe([]);
});

test('compiler-only analysis rejects arguments unavailable in a portable platform variant', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root, ['stubs' => []]);
    $this->writeFile($root . '/src/platform.ppphp', <<<'PHP'
<?php
function platform(): void
{
    ldap_connect(null, 389, 'wallet');
}
PHP);
    $response = (new CompilerAnalysisProtocol())->analyze(
        new CompilerAnalysisRequest('portable-platform-signatures', null),
        $root,
    )->toArray();

    expect(array_column($response['diagnostics']['diagnostics'], 'code'))
        ->toContain(DiagnosticCode::ArgumentCountDoesNotMatch->value);
});

test('project declarations cannot replace PHP platform symbols', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root, ['stubs' => []]);
    $this->writeFile($root . '/src/conflicts.ppphp', <<<'PHP'
<?php
class DateTime {}
function strlen(string $value): int { return 0; }
const JSON_THROW_ON_ERROR = 0;
PHP);
    $response = (new CompilerAnalysisProtocol())->analyze(
        new CompilerAnalysisRequest('platform-conflicts', null),
        $root,
    )->toArray();
    $codes = array_column($response['diagnostics']['diagnostics'], 'code');

    expect($response['status'])->toBe('complete')
        ->and(array_count_values($codes)[DiagnosticCode::DeclarationConflictsWithPhpPlatform->value] ?? 0)->toBe(3);
});

test('an unavailable or corrupt platform package fails closed', function (): void {
    $root = $this->createTemporaryDirectory();
    $loader = new PhpSignaturePackageLoader($root);
    $result = $loader->load('8.4', []);

    expect($result->diagnostics->errors)->toHaveCount(1)
        ->and($result->diagnostics->errors[0]->code)->toBe(DiagnosticCode::PhpSignaturePackageInvalid)
        ->and($result->parsedFiles)->toBe([]);
});

test('a retained platform loader revalidates changed resources and recovers after repair', function (): void {
    $root = $this->createTemporaryDirectory();
    \Tests\Support\StageElevenProject::copyTree(dirname(__DIR__, 3) . '/resources/php-signatures/8.4', $root . '/8.4');
    $loader = new PhpSignaturePackageLoader($root);
    $first = $loader->load('8.4', []);
    expect($first->isSuccessful)->toBeTrue();
    $path = $root . '/8.4/extensions/core.json';
    $contents = file_get_contents($path);
    $this->writeFile($path, $contents . "\n");
    $broken = $loader->load('8.4', []);
    expect($broken->parsedFiles)->toBe([])
        ->and($broken->diagnostics->errors[0]->code)->toBe(DiagnosticCode::PhpSignaturePackageInvalid);
    $this->writeFile($path, $contents);
    $repaired = $loader->load('8.4', []);
    expect($repaired->isSuccessful)->toBeTrue()
        ->and(array_keys($repaired->parsedFiles))->toBe(array_keys($first->parsedFiles));
    rename($root . '/8.4', $root . '/moved');
    symlink($root . '/moved', $root . '/8.4');
    clearstatcache(true);
    expect($loader->load('8.4', [])->isSuccessful)->toBeFalse();
    unlink($root . '/8.4');
    rename($root . '/moved', $root . '/8.4');
    clearstatcache(true);
    expect($loader->load('8.4', [])->isSuccessful)->toBeTrue();
});
