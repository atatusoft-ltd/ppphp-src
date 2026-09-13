<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Cache\CacheKey;
use Atatusoft\Ppphp\Cache\CacheLimits;
use Atatusoft\Ppphp\Cache\CacheStatistics;
use Atatusoft\Ppphp\Cache\CacheStore;
use Atatusoft\Ppphp\Cache\CompilerBuildIdentity;
use Atatusoft\Ppphp\Support\CanonicalJson;

function stageThirteenDCacheRecordPath(string $cache, string $kind, CacheKey $key): string
{
    return sprintf(
        '%s/compiler/v1/operations/%s/%s/%s.json',
        $cache,
        $kind,
        substr($key->hex, 0, 2),
        $key->hex,
    );
}

test('compiler build identity is path independent content based and memoized', function (): void {
    $first = $this->createTemporaryDirectory();
    $second = $this->createTemporaryDirectory();
    $writeInstallation = function (string $root, string $source): void {
        foreach ([
            'bin/ppphp' => '#!/usr/bin/env php',
            'composer.lock' => '{}',
            'resources/php-signatures/8.4/manifest.json' => '{}',
            'resources/php-signatures/8.4/overrides.json' => '{}',
            'resources/phpstan/ppphp.neon' => 'parameters: {}',
            'resources/phpstan/extensions.php' => '<?php',
            'resources/release/manifest.json' => '{}',
            'resources/schema/ppphp.schema.json' => '{}',
            'src/Compiler.php' => $source,
        ] as $path => $contents) {
            $this->writeFile($root . '/' . $path, $contents . "\n");
        }
    };
    $writeInstallation($first, '<?php final class Compiler {}');
    $writeInstallation($second, '<?php final class Compiler {}');
    $identity = new CompilerBuildIdentity($first);
    $expected = $identity->calculate();

    expect($expected)->toMatch('/^sha256:[a-f0-9]{64}$/')
        ->and((new CompilerBuildIdentity($second))->calculate())->toBe($expected)
        ->and($identity->calculate())->toBe($expected);

    $this->writeFile($second . '/src/Compiler.php', "<?php final class Compiler { public const int FORMAT = 2; }\n");

    expect((new CompilerBuildIdentity($second))->calculate())->not->toBe($expected);
});

test('compiler build identity includes executable inputs and excludes non-executable repository files', function (): void {
    $root = $this->createTemporaryDirectory();
    $files = [
        'bin/ppphp' => '#!/usr/bin/env php',
        'composer.lock' => '{"packages":[]}',
        'resources/php-signatures/8.4/manifest.json' => '{}',
        'resources/php-signatures/8.4/overrides.json' => '{}',
        'resources/phpstan/ppphp.neon' => 'parameters: {}',
        'resources/phpstan/extensions.php' => '<?php',
        'resources/release/manifest.json' => '{}',
        'resources/schema/ppphp.schema.json' => '{}',
        'src/Compiler.php' => '<?php final class Compiler {}',
        'README.md' => 'first documentation',
        'tests/CompilerTest.php' => '<?php test("not production", fn () => true);',
    ];

    foreach ($files as $path => $contents) {
        $this->writeFile($root . '/' . $path, $contents . "\n");
    }

    $baseline = (new CompilerBuildIdentity($root))->calculate();
    $this->writeFile($root . '/README.md', "second documentation\n");
    $this->writeFile($root . '/tests/CompilerTest.php', "<?php throw new RuntimeException();\n");

    expect((new CompilerBuildIdentity($root))->calculate())->toBe($baseline);

    $this->writeFile($root . '/resources/phpstan/extensions.php', "<?php /* changed extension loader */\n");
    expect((new CompilerBuildIdentity($root))->calculate())->not->toBe($baseline);
    $this->writeFile($root . '/resources/phpstan/extensions.php', $files['resources/phpstan/extensions.php'] . "\n");

    $this->writeFile($root . '/composer.lock', "{\"packages\":[{\"name\":\"changed\"}]}\n");
    $lockChanged = (new CompilerBuildIdentity($root))->calculate();
    $this->writeFile($root . '/composer.lock', $files['composer.lock'] . "\n");
    $this->writeFile($root . '/resources/schema/ppphp.schema.json', "{\"type\":\"object\"}\n");

    expect($lockChanged)->not->toBe($baseline)
        ->and((new CompilerBuildIdentity($root))->calculate())->not->toBe($baseline)
        ->and(file_exists($root . '/.git'))->toBeFalse();
});

test('cache records and blobs are canonical atomic hash validated and corruption safe', function (): void {
    $root = $this->createTemporaryDirectory();
    $cache = $root . '/.ppphp-cache';
    $statistics = new CacheStatistics();
    $store = new CacheStore($root, $cache, $statistics);
    $key = CacheKey::create('test', ['source' => 'sha256:' . str_repeat('a', 64)]);

    expect($store->writeBlob('generated php'))->toBe($blob = 'sha256:' . hash('sha256', 'generated php'))
        ->and($store->writeRecord('compiler', 'test-record', $key, ['blob' => $blob]))->toBeTrue()
        ->and($store->readRecord('compiler', 'test-record', $key))->toBe(['blob' => $blob])
        ->and($store->readBlob($blob))->toBe('generated php');

    $blobPath = $cache . '/compiler/v1/blobs/' . substr($blob, 7, 2) . '/' . substr($blob, 7) . '.blob';
    $this->writeFile($blobPath, 'corrupt');

    expect($store->readBlob($blob))->toBeNull()
        ->and(file_exists($blobPath))->toBeFalse()
        ->and($statistics->corruptEntries)->toBe(1)
        ->and($statistics->invalidatedEntries)->toBe(1);
});

test('malformed cache records become safe misses and are removed', function (string $case): void {
    $root = $this->createTemporaryDirectory();
    $cache = $root . '/.ppphp-cache';
    $statistics = new CacheStatistics();
    $store = new CacheStore($root, $cache, $statistics);
    $key = CacheKey::create('corruption', ['case' => $case]);
    expect($store->writeRecord('compiler', 'corruption', $key, ['value' => 'valid']))->toBeTrue();
    $path = stageThirteenDCacheRecordPath($cache, 'corruption', $key);
    $record = CanonicalJson::decode(file_get_contents($path) ?: '');

    match ($case) {
        'invalid JSON' => $this->writeFile($path, '{'),
        'unsupported format' => $this->writeFile($path, CanonicalJson::encode([...$record, 'formatVersion' => 999])),
        'wrong kind' => $this->writeFile($path, CanonicalJson::encode([...$record, 'recordKind' => 'other'])),
        'wrong key' => $this->writeFile($path, CanonicalJson::encode([...$record, 'cacheKey' => 'sha256:' . str_repeat('f', 64)])),
        'unknown property' => $this->writeFile($path, CanonicalJson::encode([...$record, 'unknown' => true])),
        'truncated write' => $this->writeFile($path, substr(file_get_contents($path) ?: '', 0, -1)),
        'oversized record' => $this->writeFile($path, str_repeat('x', 2_097_153)),
        default => throw new LogicException('Unknown cache corruption case.'),
    };

    expect($store->readRecord('compiler', 'corruption', $key))->toBeNull()
        ->and(file_exists($path))->toBeFalse()
        ->and($statistics->corruptEntries)->toBe(1);
})->with([
    'invalid JSON',
    'unsupported format',
    'wrong kind',
    'wrong key',
    'unknown property',
    'truncated write',
    'oversized record',
]);

test('cache links are never followed', function (string $entry): void {
    if (!function_exists('symlink')) {
        $this->markTestSkipped('Symbolic links are unavailable.');
    }

    $root = $this->createTemporaryDirectory();
    $outside = $this->createTemporaryDirectory();
    $cache = $root . '/.ppphp-cache';
    $statistics = new CacheStatistics();
    $store = new CacheStore($root, $cache, $statistics);
    $key = CacheKey::create('links', ['entry' => $entry]);
    $blob = $store->writeBlob('safe blob');
    expect($blob)->toBeString()
        ->and($store->writeRecord('compiler', 'links', $key, ['blob' => $blob]))->toBeTrue();
    $path = $entry === 'record'
        ? stageThirteenDCacheRecordPath($cache, 'links', $key)
        : $cache . '/compiler/v1/blobs/' . substr($blob, 7, 2) . '/' . substr($blob, 7) . '.blob';
    $this->writeFile($outside . '/target', 'outside');
    unlink($path);
    symlink($outside . '/target', $path);

    $result = $entry === 'record'
        ? $store->readRecord('compiler', 'links', $key)
        : $store->readBlob($blob);

    expect($result)->toBeNull()
        ->and(file_get_contents($outside . '/target'))->toBe('outside')
        ->and(is_link($path))->toBeTrue();
})->with(['record', 'blob']);

test('cache limits evict old unreachable evidence and preserve the committing record', function (): void {
    $root = $this->createTemporaryDirectory();
    $cache = $root . '/.ppphp-cache';
    $statistics = new CacheStatistics();
    $store = new CacheStore($root, $cache, $statistics, new CacheLimits(
        maximumRecordBytes: 4_096,
        maximumBlobBytes: 1_024,
        maximumCacheBytes: 16_384,
        maximumRecordCount: 1,
        maximumBlobCount: 1,
        activeWriteGraceSeconds: 0,
    ));
    $firstKey = CacheKey::create('eviction', ['generation' => 1]);
    $secondKey = CacheKey::create('eviction', ['generation' => 2]);
    $firstBlob = $store->writeBlob('first generation');
    expect($firstBlob)->toBeString()
        ->and($store->writeRecord('compiler', 'eviction', $firstKey, ['blob' => $firstBlob]))->toBeTrue();
    $secondBlob = $store->writeBlob('second generation');
    expect($secondBlob)->toBeString()
        ->and($store->writeRecord('compiler', 'eviction', $secondKey, ['blob' => $secondBlob]))->toBeTrue()
        ->and($store->readRecord('compiler', 'eviction', $firstKey))->toBeNull()
        ->and($store->readBlob($firstBlob))->toBeNull()
        ->and($store->readRecord('compiler', 'eviction', $secondKey))->toBe(['blob' => $secondBlob])
        ->and($store->readBlob($secondBlob))->toBe('second generation');
});

test('cache records reject project-absolute data and never capture environment secrets', function (): void {
    $root = $this->createTemporaryDirectory();
    $cache = $root . '/.ppphp-cache';
    $store = new CacheStore($root, $cache, new CacheStatistics());
    $key = CacheKey::create('privacy', ['stable' => true]);
    putenv('PPPHP_CACHE_SECRET=must-not-persist');

    try {
        expect($store->writeRecord('compiler', 'privacy', $key, [
            'path' => 'src/Value.ppphp',
            'value' => 'safe',
        ]))->toBeTrue()
            ->and($store->writeRecord('compiler', 'privacy', $key, ['path' => $root . '/src/Value.ppphp']))
            ->toBeFalse();
    } finally {
        putenv('PPPHP_CACHE_SECRET');
    }

    $contents = file_get_contents(stageThirteenDCacheRecordPath($cache, 'privacy', $key));
    expect($contents)->toBeString()
        ->and($contents)->not->toContain($root, 'must-not-persist')
        ->and(glob($cache . '/**/*.tmp-*') ?: [])->toBe([]);
});
