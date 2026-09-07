<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Versioning\ReleaseMetadataLoader;
use Atatusoft\Ppphp\Versioning\ReleaseNotesRenderer;
use Atatusoft\Ppphp\Versioning\ReleaseNotesValidator;
use Atatusoft\Ppphp\Versioning\ReleaseVersion;

test('authored notes require no workflow status namespace or feature inventory', function (string $identity): void {
    $notes = "# ++PHP $identity\n\nFixed nullable assignment diagnostics.\n";
    expect((new ReleaseNotesValidator())->validate($notes, ReleaseVersion::parse($identity)))->toBe([]);
})->with(['2031.4.7-rc-3', '2031.4.7', 'dev-2031.4.7']);

test('notes preserve legitimate historical publication and migration prose', function (): void {
    $version = ReleaseVersion::parse('2031.4.7');
    $notes = "# ++PHP $version\n\nBefore publication of the older release, configurations used a local schema. Regenerate them when upgrading.\n";
    expect((new ReleaseNotesValidator())->validate($notes, $version))->toBe([]);
});

test('notes reject internal process content and unresolved substitutions', function (string $text, string $message): void {
    $version = ReleaseVersion::parse('2031.4.7-rc-3');
    expect(implode("\n", (new ReleaseNotesValidator())->validate("# ++PHP $version\n\n$text\n", $version)))
        ->toContain($message);
})->with([
    ['Stage 13D completed the cache.', 'prohibited public process language'],
    ['The completion gate passed.', 'prohibited public process language'],
    ['Install {{version}}.', 'unresolved generated substitutions'],
    ['Download /<release-tag>/schema.', 'unresolved generated substitutions'],
]);

test('notes reject missing or mismatched historical identity', function (): void {
    $validator = new ReleaseNotesValidator();
    $version = ReleaseVersion::parse('2031.4.7');
    expect($validator->validate("# ++PHP 2031.4.6\n\nChanges.\n", $version))->not->toBe([])
        ->and($validator->validate("# ++PHP $version\n", $version))->not->toBe([]);
});

test('active authored notes and rendered assets use metadata without publication bookkeeping', function (): void {
    $root = dirname(__DIR__, 3);
    $metadata = (new ReleaseMetadataLoader($root))->load();
    expect($metadata)->not->toBeNull();
    $notes = (string) file_get_contents($root . '/' . $metadata->releaseNotes);
    $renderer = new ReleaseNotesRenderer();
    $rendered = $renderer->render($notes, $metadata);

    expect((new ReleaseNotesValidator())->validate($rendered, $metadata->version))->toBe([])
        ->and($notes)->not->toContain('After publication', 'Until then', 'has not yet been published', '## Major Features')
        ->and($rendered)->toBe($renderer->render($notes, $metadata))
        ->and($rendered)->toContain(
            'composer require --dev atatusoft-ltd/ppphp-src:' . $metadata->version->canonical,
            '/blob/' . $metadata->tag . '/SECURITY.md',
            $metadata->schemaUrl,
        );
});
