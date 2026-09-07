<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Versioning\ReleaseAssetBuilder;
use Atatusoft\Ppphp\Versioning\ReleaseAssetVerifier;
use Atatusoft\Ppphp\Versioning\ReleaseMetadataLoader;
use Atatusoft\Ppphp\Versioning\ReleaseNotesRenderer;
use Atatusoft\Ppphp\Compiler\Compiler;
use Symfony\Component\Process\Process;
use Tests\Support\ReleasePreparationFixture;

test('offline preparation and documentation work across channels without release refs', function (string $identity): void {
    $root = $this->createTemporaryDirectory();
    ReleasePreparationFixture::create($root, $identity);
    $git = new Process(['git', 'init', '-b', 'develop'], $root);
    $git->mustRun();
    $refsBefore = (new Process(['git', 'for-each-ref'], $root))->mustRun()->getOutput();

    foreach (['tools/verify-documentation.php', 'tools/release/verify-readiness.php'] as $tool) {
        $process = new Process([PHP_BINARY, $tool], $root, timeout: 60.0);
        $process->run();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput() . $process->getOutput());
    }

    $metadata = (new ReleaseMetadataLoader($root, expectedVersion: $identity))->load();
    expect($metadata->releaseNotes)->toBe('docs/releases/selected-notes.md');
    $commit = str_repeat('a', 40);
    $output = $this->createTemporaryDirectory() . '/assets';
    (new ReleaseAssetBuilder($root, $identity))->build($output, $commit);
    (new ReleaseAssetVerifier($root, $identity))->verify($output, $commit);
    $rendered = (string) file_get_contents($output . '/RELEASE_NOTES.md');
    expect($rendered)->toBe((new ReleaseNotesRenderer())->render(
        (string) file_get_contents($root . '/' . $metadata->releaseNotes), $metadata,
    ))->toContain('atatusoft-ltd/ppphp-src:' . $identity, '/blob/' . $identity . '/SECURITY.md');
    expect((new Process(['git', 'for-each-ref'], $root))->mustRun()->getOutput())->toBe($refsBefore)->toBe('');
})->with(['2031.4.7-rc-3', '2031.4.7', 'dev-2031.4.7']);

test('release metadata rejects a channel mismatch before asset generation', function (): void {
    $root = $this->createTemporaryDirectory();
    ReleasePreparationFixture::create($root, '2031.4.7');
    $manifestPath = $root . '/resources/release/manifest.json';
    $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
    $manifest['channel'] = 'rc';
    $this->writeFile($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));
    expect(fn () => (new ReleaseAssetBuilder($root, '2031.4.7'))->build($root . '/dist/assets', str_repeat('a', 40)))
        ->toThrow(RuntimeException::class, 'release manifest is invalid');
});

test('clean commit assets remain reproducible after the release branch is deleted', function (): void {
    $root = $this->createTemporaryDirectory();
    ReleasePreparationFixture::create($root, Compiler::VERSION);
    $git = static function (string ...$arguments) use ($root): string {
        return trim((new Process(['git', '-c', 'user.name=Release Test', '-c', 'user.email=release@example.test',
            '-c', 'commit.gpgsign=false', '-c', 'tag.gpgsign=false', ...$arguments], $root))->mustRun()->getOutput());
    };
    $git('init', '-b', 'main');
    $git('add', '.');
    $git('commit', '-m', 'Source snapshot');
    $commit = $git('rev-parse', 'HEAD');
    $git('update-ref', 'refs/remotes/origin/main', $commit);
    $git('branch', 'release/' . Compiler::VERSION);
    $git('update-ref', 'refs/remotes/origin/release/' . Compiler::VERSION, $commit);
    $git('tag', '-a', Compiler::VERSION, '-m', 'Test release');
    $assets = $this->createTemporaryDirectory();
    $run = static function (string $tool, string ...$options) use ($root): Process {
        return (new Process([PHP_BINARY, 'tools/release/' . $tool . '.php', ...$options], $root))->mustRun();
    };
    $tagOption = '--tag=' . Compiler::VERSION;
    $commitOption = '--commit=' . $commit;
    expect(trim($run('verify-source', $tagOption, $commitOption, '--publication')->getOutput()))->toBe($commit);
    $run('build-assets', $commitOption, '--output=' . $assets . '/before');
    $git('branch', '-D', 'release/' . Compiler::VERSION);
    $git('update-ref', '-d', 'refs/remotes/origin/release/' . Compiler::VERSION);
    $refs = $git('show-ref');
    $run('verify-source', $tagOption, $commitOption);
    $run('verify-assets', $commitOption, '--input=' . $assets . '/before');
    $run('build-assets', $commitOption, '--output=' . $assets . '/after');
    $run('verify-assets', $commitOption, '--input=' . $assets . '/after');

    foreach (ReleaseAssetBuilder::ASSET_NAMES as $asset) {
        expect(file_get_contents($assets . '/before/' . $asset))->toBe(file_get_contents($assets . '/after/' . $asset));
    }

    expect($git('show-ref'))->toBe($refs);
    $this->writeFile($root . '/uncommitted-input', 'Changed');
    expect(fn () => $run('build-assets', $commitOption, '--output=' . $assets . '/dirty'))
        ->toThrow(RuntimeException::class, 'clean checkout');
    expect(is_dir($assets . '/dirty'))->toBeFalse();
});

test('CI covers release branches while publication remains tag driven and commit bound', function (): void {
    $root = dirname(__DIR__, 3);
    $ci = (string) file_get_contents($root . '/.github/workflows/php.yml');
    $release = (string) file_get_contents($root . '/.github/workflows/release.yml');
    $events = explode('permissions:', $release, 2)[0];
    expect($ci)->toContain("branches: [develop, main, 'release/**']", "php: ['8.4', '8.5']", 'macos-15', 'windows-2025', 'workflow_dispatch:', 'Lowest supported dependencies')
        ->and(substr_count($ci, 'run: composer check'))->toBe(1)
        ->and($events)->toContain("tags:\n      - '*'")->not->toContain('branches:', 'workflow_dispatch:')
        ->and($release)->toContain('needs: verify', 'ref: $' . '{{ needs.verify.outputs.commit }}', '--commit="$RELEASE_COMMIT"', 'tools/release/verify-unpublished.php', '--verify-tag')
        ->and(substr_count($release, '--publication'))->toBe(1)
        ->and(substr_count($release, 'tools/release/verify-source.php'))->toBe(2)
        ->and(substr_count($release, 'contents: write'))->toBe(1)
        ->and($release)->toContain('--notes-file "dist/release/$GITHUB_REF_NAME/RELEASE_NOTES.md"')
        ->not->toContain('gh release upload', '--clobber', 'git merge-base --is-ancestor', '--notes-file "$release_notes"');
});
