<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Compiler\Compiler;
use Atatusoft\Ppphp\Versioning\ReleaseMetadataLoader;
use Atatusoft\Ppphp\Versioning\ReleaseVersion;
use Atatusoft\Ppphp\Versioning\ReleaseSchema;

test('committed release metadata has one exact immutable identity', function (): void {
    $root = dirname(__DIR__, 3);
    $metadata = (new ReleaseMetadataLoader($root))->load();

    expect($metadata)->not->toBeNull()
        ->and($metadata?->version->canonical)->toBe(Compiler::VERSION)
        ->and($metadata?->tag)->toBe(Compiler::VERSION)
        ->and($metadata?->channel)->toBe(ReleaseVersion::parse(Compiler::VERSION)->channel)
        ->and($metadata?->prerelease)->toBe(ReleaseVersion::parse(Compiler::VERSION)->isPrerelease)
        ->and($metadata?->schemaAsset)->toBe('ppphp.schema.json')
        ->and($metadata?->schemaUrl)->toBe((new ReleaseSchema(ReleaseVersion::parse(Compiler::VERSION)))->url);
});

test('missing development release metadata is an explicit non-release state', function (): void {
    $root = dirname(__DIR__, 3);
    $missing = $this->createTemporaryDirectory() . '/missing.json';

    expect((new ReleaseMetadataLoader($root, $missing))->load())->toBeNull();
});

test('present malformed release metadata fails closed', function (): void {
    $root = dirname(__DIR__, 3);
    $manifest = $this->createTemporaryDirectory() . '/manifest.json';
    $this->writeFile($manifest, "{}\n");

    expect(fn () => (new ReleaseMetadataLoader($root, $manifest))->load())
        ->toThrow(RuntimeException::class, 'release manifest is invalid');
});
