<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Versioning\ReleaseMetadataLoader;
use Atatusoft\Ppphp\Versioning\ReleaseSourceVerifier;
use Symfony\Component\Process\Process;

function releaseGit(string $root, string ...$arguments): string
{
    $process = new Process(['git', '-c', 'user.name=Release Test', '-c', 'user.email=release@example.test',
        '-c', 'commit.gpgsign=false', '-c', 'tag.gpgsign=false', ...$arguments], $root);
    $process->mustRun();

    return trim($process->getOutput());
}

test('publication accepts release-only commits and both tag forms after main advances', function (bool $annotated): void {
    $root = $this->createTemporaryDirectory();
    $metadata = (new ReleaseMetadataLoader())->load();
    releaseGit($root, 'init', '-b', 'main');
    releaseGit($root, 'commit', '--allow-empty', '-m', 'Main baseline');
    releaseGit($root, 'checkout', '-b', 'release/' . $metadata->tag);
    releaseGit($root, 'commit', '--allow-empty', '-m', 'Release-only preparation');
    $commit = releaseGit($root, 'rev-parse', 'HEAD');
    releaseGit($root, 'update-ref', 'refs/remotes/origin/release/' . $metadata->tag, $commit);
    releaseGit($root, 'tag', ...($annotated ? ['-a', $metadata->tag, '-m', 'Candidate'] : [$metadata->tag]));
    $tagObject = releaseGit($root, 'rev-parse', 'refs/tags/' . $metadata->tag);
    releaseGit($root, 'checkout', 'main');
    releaseGit($root, 'commit', '--allow-empty', '-m', 'New main work');
    releaseGit($root, 'update-ref', 'refs/remotes/origin/main', 'HEAD');
    releaseGit($root, 'checkout', '--detach', $commit);
    $before = releaseGit($root, 'show-ref');
    $verifier = new ReleaseSourceVerifier($root);

    expect($verifier->verify($metadata, $metadata->tag, $commit, true))->toBe($commit)
        ->and($verifier->verify($metadata, $metadata->tag, $tagObject, true))->toBe($commit);
    $verifier->verifyCheckout($commit);
    expect(releaseGit($root, 'show-ref'))->toBe($before);

    releaseGit($root, 'branch', '-D', 'release/' . $metadata->tag);
    releaseGit($root, 'update-ref', '-d', 'refs/remotes/origin/release/' . $metadata->tag);
    expect($verifier->verify($metadata, $metadata->tag, $commit))->toBe($commit)
        ->and(fn () => $verifier->verify($metadata, $metadata->tag, $commit, true))->toThrow(RuntimeException::class);
    $this->writeFile($root . '/uncommitted', 'Changed');
    expect(fn () => $verifier->verifyCheckout($commit))->toThrow(UnexpectedValueException::class, 'clean checkout');
})->with([false, true]);

test('publication rejects wrong tag branch channel and unrelated main histories', function (): void {
    $root = $this->createTemporaryDirectory();
    $metadata = (new ReleaseMetadataLoader())->load();
    releaseGit($root, 'init', '-b', 'main');
    releaseGit($root, 'commit', '--allow-empty', '-m', 'First');
    $first = releaseGit($root, 'rev-parse', 'HEAD');
    releaseGit($root, 'update-ref', 'refs/remotes/origin/main', $first);
    releaseGit($root, 'tag', $metadata->tag);
    releaseGit($root, 'commit', '--allow-empty', '-m', 'Second');
    $second = releaseGit($root, 'rev-parse', 'HEAD');
    $verifier = new ReleaseSourceVerifier($root);

    expect(fn () => $verifier->verify($metadata, $metadata->tag, $second))->toThrow(UnexpectedValueException::class, 'tag does not identify')
        ->and(fn () => $verifier->verify($metadata, 'dev-2031.4.7', $first))->toThrow(UnexpectedValueException::class, 'version and channel');
    releaseGit($root, 'update-ref', 'refs/remotes/origin/release/' . $metadata->tag, $second);
    expect(fn () => $verifier->verify($metadata, $metadata->tag, $first, true))->toThrow(UnexpectedValueException::class, 'branch does not identify');
    releaseGit($root, 'update-ref', 'refs/remotes/origin/release/' . $metadata->tag, $first);
    releaseGit($root, 'checkout', '--orphan', 'unrelated');
    releaseGit($root, 'commit', '--allow-empty', '-m', 'Unrelated');
    releaseGit($root, 'update-ref', 'refs/remotes/origin/main', 'HEAD');
    expect(fn () => $verifier->verify($metadata, $metadata->tag, $first, true))->toThrow(UnexpectedValueException::class, 'main-line history');
});
