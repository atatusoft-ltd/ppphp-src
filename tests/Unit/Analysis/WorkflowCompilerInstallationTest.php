<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Analysis\Browser\ProtocolJson;
use Atatusoft\Ppphp\Analysis\Browser\WorkflowCompilerInstallation;
use Atatusoft\Ppphp\Compiler\Output\NativeBuildFilesystem;

test('only the immutable workflow installation retains the exact analyzer fingerprint', function (): void {
    $root = $this->createTemporaryDirectory();
    $path = $root . '/vendor/phpstan/phpstan/phpstan.phar';
    $filesystem = new NativeBuildFilesystem();
    $mutable = new WorkflowCompilerInstallation($filesystem, $root, false);
    $immutable = new WorkflowCompilerInstallation($filesystem, $root, true);

    $this->writeFile($path, "original\x00analyzer");
    $original = ProtocolJson::hash("original\x00analyzer");
    expect($immutable->readAnalyzerIdentity())->toBe($original);
    expect($mutable->readAnalyzerIdentity())->toBe($original);

    // Deliberately violate the fixture's mount contract to expose re-reads.
    $this->writeFile($path, 'replacement analyzer');
    expect($mutable->readAnalyzerIdentity())->toBe(ProtocolJson::hash('replacement analyzer'));
    expect($immutable->readAnalyzerIdentity())->toBe($original);
    expect((new WorkflowCompilerInstallation($filesystem, $root, true))->readAnalyzerIdentity())
        ->toBe(ProtocolJson::hash('replacement analyzer'));
});

test('an immutable workflow does not cache a missing or linked analyzer', function (): void {
    $root = $this->createTemporaryDirectory();
    $path = $root . '/vendor/phpstan/phpstan/phpstan.phar';
    $filesystem = new NativeBuildFilesystem();
    $installation = new WorkflowCompilerInstallation($filesystem, $root, true);
    $this->writeFile($path . '.target', 'analyzer bytes');

    expect(fn () => $installation->readAnalyzerIdentity())->toThrow(RuntimeException::class);
    expect(symlink($path . '.target', $path))->toBeTrue();
    expect(fn () => $installation->readAnalyzerIdentity())->toThrow(RuntimeException::class);
    unlink($path);
    $this->writeFile($path, 'analyzer bytes');
    expect($installation->readAnalyzerIdentity())->toBe(ProtocolJson::hash('analyzer bytes'));
});

test('the host analyzer fingerprint requires an immutable installation and canonical digest', function (): void {
    $root = $this->createTemporaryDirectory();
    $filesystem = new NativeBuildFilesystem();
    $identity = ProtocolJson::hash('archive member verified by the host');

    expect(fn () => new WorkflowCompilerInstallation($filesystem, $root, false, $identity))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new WorkflowCompilerInstallation($filesystem, $root, true, 'unverified'))
        ->toThrow(InvalidArgumentException::class);
    expect((new WorkflowCompilerInstallation($filesystem, $root, true, $identity))->readAnalyzerIdentity())
        ->toBe($identity);
});
