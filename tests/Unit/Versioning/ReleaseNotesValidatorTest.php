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

test('current release channel claims must agree with selected metadata', function (string $identity, string $text, bool $compatible): void {
    $version = ReleaseVersion::parse($identity);
    $notes = "# ++PHP $identity\n\n$text\n";
    $failures = (new ReleaseNotesValidator())->validate($notes, $version);
    expect($failures === [])->toBe($compatible);
    if (!$compatible) {
        expect(implode("\n", $failures))->toContain('contradicting channel');
    }
})->with([
    ['2031.4.7-rc-3', 'This is the first Stable release.', false],
    ['dev-2031.4.7', 'This release is **Stable**.', false],
    ['2031.4.7-rc-3', 'The current version is now stable.', false],
    ['2031.4.7-rc-3', '++PHP 2031.4.7-rc-3 is a Stable release.', false],
    ['dev-2031.4.7', 'This Stable release fixes diagnostics.', false],
    ['2031.4.7-rc-3', '**Channel:** `Stable`.', false],
    ['dev-2031.4.7', '- Release status: Stable.', false],
    ['2031.4.7-rc-3', 'This is a Development release.', false],
    ['dev-2031.4.7', 'This is a Release Candidate.', false],
    ['2031.4.7', 'This is a Release Candidate.', false],
    ['2031.4.7', 'This is a prerelease.', false],
    ['2031.4.7', 'This is a Stable release.', true],
    ['2031.4.7-rc-3', "This is a Release\nCandidate.", true],
    ['2031.4.7-rc-3', 'Channel: RC.', true],
    ['dev-2031.4.7', 'This is a Development release.', true],
    ['2031.4.7-rc-3', 'This is a prerelease.', true],
    ['dev-2031.4.7', 'This is a pre-release.', true],
    ['2031.4.7-rc-3', 'The previous Stable release introduced this feature.', true],
    ['dev-2031.4.7', '++PHP 2031.4.6 is a Stable release; this release changes diagnostics.', true],
    ['2031.4.7-rc-3', 'Behavior may change before the first Stable release.', true],
    ['dev-2031.4.7', 'This release is not Stable. Upgrade from the previous Stable release.', true],
    ['2031.4.7-rc-3', 'This release is compatible with projects using the previous Stable release.', true],
]);

test('release rendering refuses contradictory status before producing publishable notes', function (): void {
    $metadata = (new ReleaseMetadataLoader(dirname(__DIR__, 3)))->load();
    $contradiction = $metadata->version->isStable ? 'a Release Candidate' : 'a Stable release';
    $notes = "# ++PHP {$metadata->version}\n\nThis is $contradiction.\n";
    expect(fn () => (new ReleaseNotesRenderer())->render($notes, $metadata))
        ->toThrow(UnexpectedValueException::class, 'contradicting channel');
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
