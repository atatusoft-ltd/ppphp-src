<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Frontend\PpphpParser;
use Atatusoft\Ppphp\Interop\Php\Signature\BrowserPhpSignatureCache;
use Atatusoft\Ppphp\Interop\Php\Signature\PhpSignaturePackageLoader;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;
use PhpParser\Node\Stmt\Nop;

test('fresh platform loaders reuse syntax and discover newly referenced modules', function (): void {
    $cachePath = $this->createTemporaryDirectory() . '/platform.cache';
    $identity = 'sha256:' . str_repeat('a', 64);
    $parse = static fn (string $source) => (new PpphpParser())->parse(
        new SourceFile('/project/index.ppphp', 'index.ppphp', FileKind::Ppphp, $source),
    )->parsedFile;
    $firstProject = [$parse('<?php json_encode([]);')];
    $editedProject = [$parse('<?php json_encode([]); curl_init();')];

    $cache = new BrowserPhpSignatureCache($identity, $cachePath);
    $first = (new PhpSignaturePackageLoader(browserCache: $cache))->load('8.4', $firstProject);
    expect($first->isSuccessful)->toBeTrue();
    $cache->save();
    $originalBytes = filesize($cachePath);
    expect($originalBytes)->toBeGreaterThan(0);

    touch($cachePath, 1);
    $freshCache = new BrowserPhpSignatureCache($identity, $cachePath);
    $fresh = (new PhpSignaturePackageLoader(browserCache: $freshCache))->load('8.4', $firstProject);
    expect($fresh->isSuccessful)->toBeTrue();
    $freshCache->save();
    clearstatcache(true, $cachePath);
    expect(filemtime($cachePath))->toBe(1);
    foreach ($fresh->parsedFiles as $key => $file) {
        expect($file->statements)->toEqual($first->parsedFiles[$key]->statements)
            ->and($file->statements[0])->not->toBe($first->parsedFiles[$key]->statements[0]);
    }

    unset($first, $fresh, $cache, $freshCache);

    $editedCache = new BrowserPhpSignatureCache($identity, $cachePath);
    $edited = (new PhpSignaturePackageLoader(browserCache: $editedCache))->load('8.4', $editedProject);
    $uncached = (new PhpSignaturePackageLoader())->load('8.4', $editedProject);
    expect($edited->isSuccessful)->toBeTrue()
        ->and($edited->parsedFiles)->toEqual($uncached->parsedFiles)
        ->and(implode("\n", array_keys($edited->parsedFiles)))->toContain('/ext/curl/');
    $editedCache->save();
    clearstatcache(true, $cachePath);
    expect(filesize($cachePath))->toBeGreaterThan($originalBytes);
});

test('platform cache misses on wrong identities, corruption, and oversized data', function (): void {
    $path = $this->createTemporaryDirectory() . '/platform.cache';
    $identity = 'sha256:' . str_repeat('a', 64);
    $package = str_repeat('b', 64);
    $open = static function (string $key, string $target = '8.4', ?string $digest = null) use ($path, $package): BrowserPhpSignatureCache {
        $cache = new BrowserPhpSignatureCache($key, $path);
        $cache->useVerifiedPackage($target, $digest ?? $package);

        return $cache;
    };

    $cold = $open($identity);
    $cold->remember('core/stub.php', [new Nop()]);
    $cold->save();
    expect($open($identity)->restore('core/stub.php')[0])->toBeInstanceOf(Nop::class)
        ->and($open('sha256:' . str_repeat('c', 64))->restore('core/stub.php'))->toBeNull()
        ->and($open($identity, '8.5')->restore('core/stub.php'))->toBeNull()
        ->and($open($identity, '8.4', str_repeat('d', 64))->restore('core/stub.php'))->toBeNull();

    $bytes = file_get_contents($path);
    file_put_contents($path, $bytes . 'changed');
    expect($open($identity)->restore('core/stub.php'))->toBeNull();

    file_put_contents($path, str_repeat('x', BrowserPhpSignatureCache::MAXIMUM_BYTES + 1), FILE_APPEND);
    expect($open($identity)->restore('core/stub.php'))->toBeNull();
});

test('a platform fingerprint rejects a different valid cache entry with the same package identity', function (): void {
    $path = $this->createTemporaryDirectory() . '/platform.cache';
    $key = 'core/stub.php';
    $open = static function () use ($path): BrowserPhpSignatureCache {
        $cache = new BrowserPhpSignatureCache('sha256:' . str_repeat('a', 64), $path);
        $cache->useVerifiedPackage('8.4', str_repeat('b', 64));

        return $cache;
    };

    $original = [new Nop(['proof' => 'original'])];
    $substitute = [new Nop(['proof' => 'substitute'])];
    $digest = BrowserPhpSignatureCache::fingerprint($original);
    expect($digest)->not->toBeNull();

    $cache = $open();
    $cache->remember($key, $original);
    $cache->save();
    expect($open()->restore($key, $digest))->toEqual($original);

    $cache = $open();
    $cache->remember($key, $substitute);
    $cache->save();
    expect($open()->restore($key))->toEqual($substitute)
        ->and($open()->restore($key, $digest))->toBeNull();
});

test('native loaders cannot retain immutable platform verification', function (): void {
    $cache = new BrowserPhpSignatureCache('sha256:' . str_repeat('a', 64), $this->createTemporaryDirectory() . '/platform.cache');
    $loader = new PhpSignaturePackageLoader(browserCache: $cache);

    expect($loader->load('8.4', [])->isSuccessful)->toBeTrue();
    expect(fn () => $loader->retainImmutableVerification('8.4'))
        ->toThrow(LogicException::class, 'Retaining platform verification requires the immutable Wasm compiler installation.');
});
