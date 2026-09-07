<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Versioning\DocumentationPolicy;
use Atatusoft\Ppphp\Versioning\Enumerations\DocumentationAudience;

test('documentation paths have explicit audience classifications', function (string $path, DocumentationAudience $audience): void {
    expect((new DocumentationPolicy())->classify($path))->toBe($audience);
})->with([
    ['README.md', DocumentationAudience::Public],
    ['docs/releases/2026.3.1-rc-1.md', DocumentationAudience::Public],
    ['docs/releases/2026.3.1-rc-2.md', DocumentationAudience::Public],
    ['docs/ppphp-mvp-end-to-end-plan.md', DocumentationAudience::Maintainer],
    ['docs/rfcs/0001-immutable-records.md', DocumentationAudience::Maintainer],
    ['docs/compiler-architecture.md', DocumentationAudience::Technical],
]);

test('public documentation rejects bounded process language', function (string $contents): void {
    $failures = (new DocumentationPolicy())->validatePublic('README.md', $contents);

    expect(implode("\n", $failures))->toContain('prohibited public process language');
})->with([
    'The Stage 13D work is complete.',
    'Stages 13A–13D are complete.',
    'The completion gate is complete.',
]);

test('maintainer and technical documents retain their appropriate vocabulary', function (string $path, string $contents): void {
    expect((new DocumentationPolicy())->validatePublic($path, $contents))->toBe([]);
})->with([
    ['docs/ppphp-mvp-end-to-end-plan.md', 'Stage 14A is complete.'],
    ['docs/rfcs/0001-immutable-records.md', 'Stage 15A defines the record contract.'],
    ['docs/compiler-architecture.md', 'The compiler uses a structured semantic model.'],
]);

test('public documentation rejects both retired namespace representations', function (bool $escaped): void {
    $namespace = implode('', ['Ama', 'siye', '\\', 'Ppphp']);
    $contents = $escaped ? str_replace('\\', '\\\\', $namespace) : $namespace;
    $failures = (new DocumentationPolicy())->validatePublic('README.md', $contents);

    expect($failures)->toContain('README.md:1 contains the retired compiler namespace');
})->with([false, true]);

test('future features require an explicit unavailable context', function (): void {
    $policy = new DocumentationPolicy();

    expect($policy->validatePublic('README.md', '++PHP supports record declarations.'))->not->toBe([])
        ->and($policy->validatePublic(
            'README.md',
            'Immutable Records and Native Type Members are future work and are not part of this release.',
        ))->toBe([]);
});

test('the public README is durable without current-release bookkeeping', function (): void {
    $readme = file_get_contents(dirname(__DIR__, 3) . '/README.md');

    expect($readme)->toBeString();

    if (!is_string($readme)) {
        throw new RuntimeException('README.md could not be read.');
    }

    expect((new DocumentationPolicy())->validatePublic('README.md', $readme))->toBe([])
        ->and($readme)->toContain(
            'https://ppphplang.org',
            'atatusoft-ltd/ppphp-src',
            '.ppphp',
            'composer require --dev atatusoft-ltd/ppphp-src',
            'docs/releases/README.md',
        )
        ->and($readme)->not->toContain('not yet publicly available', 'After publication', Atatusoft\Ppphp\Compiler\Compiler::VERSION);
});

test('the release runbook permits maintainer lifecycle instructions', function (): void {
    $runbook = (string) file_get_contents(dirname(__DIR__, 3) . '/docs/releasing.md');
    expect((new DocumentationPolicy())->validatePublic('docs/releasing.md', $runbook))->toBe([]);
});
