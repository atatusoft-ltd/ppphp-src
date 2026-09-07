<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Versioning;

use Symfony\Component\Process\Process;

/** Read-only Git evidence; never creates or fetches refs. */
final readonly class ReleaseSourceVerifier
{
    public function __construct(private string $root) {}

    public function verify(
        ReleaseMetadata $metadata,
        string $tag,
        string $selectedCommit,
        bool $publication = false,
    ): string {
        if (ReleaseVersion::parse($tag)->canonical !== $metadata->version->canonical || $tag !== $metadata->tag) {
            throw new \UnexpectedValueException('The selected tag does not match the release version and channel.');
        }

        if (preg_match('/\A[a-f0-9]{40}\z/D', $selectedCommit) !== 1) {
            throw new \InvalidArgumentException('Select an explicit 40-hex source commit.');
        }

        // GitHub tag events may supply a tag object. Peel both identities consistently.
        $commit = $this->resolveCommit($selectedCommit);

        if ($this->resolveCommit('refs/tags/' . $tag) !== $commit) {
            throw new \UnexpectedValueException('The release tag does not identify the selected source commit.');
        }

        if ($publication) {
            if ($this->resolveCommit('refs/remotes/origin/release/' . $tag) !== $commit) {
                throw new \UnexpectedValueException('The release branch does not identify the selected source commit.');
            }

            // A shared first-parent main-line ancestor is graph evidence, not a
            // branch-creation record. Main may have advanced since the branch was cut.
            $mainHistory = explode("\n", $this->run(['rev-list', '--first-parent', 'refs/remotes/origin/main']));
            $releaseHistory = explode("\n", $this->run(['rev-list', '--first-parent', $commit]));

            if (array_intersect($mainHistory, $releaseHistory) === []) {
                throw new \UnexpectedValueException('The release source has no shared first-parent main-line history.');
            }
        }

        return $commit;
    }

    public function verifyCheckout(string $commit): void
    {
        if ($this->resolveCommit('HEAD') !== $commit || $this->run(['status', '--porcelain=v1', '--untracked-files=all']) !== '') {
            throw new \UnexpectedValueException('Release assets require a clean checkout of the verified source commit.');
        }
    }

    private function resolveCommit(string $reference): string
    {
        return $this->run(['rev-parse', '--verify', '--end-of-options', $reference . '^{commit}']);
    }

    /** @param list<string> $arguments */
    private function run(array $arguments): string
    {
        $process = new Process(['git', ...$arguments], $this->root, timeout: 30.0);
        $process->mustRun();

        return trim($process->getOutput());
    }
}
