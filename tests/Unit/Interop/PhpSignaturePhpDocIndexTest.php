<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Interop\Php\Signature\PhpSignaturePackageLoader;
use Atatusoft\Ppphp\Interop\Php\Signature\PhpSignaturePackageVerifier;
use Atatusoft\Ppphp\Interop\Php\Signature\PhpSignaturePhpDocIndex;
use Atatusoft\Ppphp\Interop\PhpDoc\PhpDocReader;
use Atatusoft\Ppphp\Support\CanonicalJson;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\NodeFinder;
use Tests\Support\StageElevenProject;

test('indexed platform comment metadata matches fresh parsing and stays bound to the platform comment object', function (): void {
    $result = (new PhpSignaturePackageLoader())->load('8.4', []);
    expect($result->isSuccessful)->toBeTrue();
    $reader = new PhpDocReader();
    $finder = new NodeFinder();
    $count = 0;
    foreach ($result->parsedFiles as $file) {
        foreach ($finder->find($file->statements, static fn (Node $node): bool => $node->getDocComment() !== null) as $node) {
            $comment = $node->getDocComment();
            $fromPlatform = $reader->readMetadata($comment);
            $independent = $reader->readMetadata(new Doc($comment->getText()));
            expect($fromPlatform)->toEqual($independent)->not->toBe($independent);
            $count++;
        }
    }
    expect($count)->toBeGreaterThan(1000);
});

test('a different PHPDoc parser reference falls back to ordinary parsing', function (): void {
    $path = dirname(__DIR__, 3) . '/resources/php-signatures/8.4/' . PhpSignaturePhpDocIndex::PATH;
    $index = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $index['parserReference'] = str_repeat('0', 40);
    expect(PhpSignaturePhpDocIndex::decode(json_encode($index, JSON_THROW_ON_ERROR), '8.4'))->toBeNull();
});

test('modified platform metadata fails verification even with an updated manifest hash', function (): void {
    $root = $this->createTemporaryDirectory();
    $package = $root . '/8.4';
    StageElevenProject::copyTree(dirname(__DIR__, 3) . '/resources/php-signatures/8.4', $package);
    $loader = new PhpSignaturePackageLoader($root);
    expect($loader->load('8.4', [])->isSuccessful)->toBeTrue();

    $path = $package . '/' . PhpSignaturePhpDocIndex::PATH;
    $index = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    array_pop($index['comments']);
    $bytes = json_encode($index, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    file_put_contents($path, $bytes);
    $rejected = $loader->load('8.4', []);
    expect($rejected->parsedFiles)->toBe([])
        ->and($rejected->diagnostics->errors[0]->code)->toBe(DiagnosticCode::PhpSignaturePackageInvalid);

    $manifestPath = $package . '/manifest.json';
    $manifest = CanonicalJson::decode(file_get_contents($manifestPath));
    foreach ($manifest['outputs'] as $offset => $output) {
        if ($output['path'] === PhpSignaturePhpDocIndex::PATH) {
            $manifest['outputs'][$offset]['sha256'] = hash('sha256', $bytes);
        }
    }
    file_put_contents($manifestPath, CanonicalJson::encode($manifest));
    expect(fn () => (new PhpSignaturePackageVerifier())->verifyInstalled($package, '8.4'))
        ->toThrow(RuntimeException::class, 'PHPDoc metadata index does not match its shards');
});
