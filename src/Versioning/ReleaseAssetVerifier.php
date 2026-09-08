<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Versioning;

use Atatusoft\Ppphp\Cache\CompilerBuildIdentity;
use Atatusoft\Ppphp\Compiler\Compiler;
use Atatusoft\Ppphp\Support\CanonicalJson;
use Atatusoft\Ppphp\Support\Path;

final readonly class ReleaseAssetVerifier
{
    private string $root;

    public function __construct(?string $root = null, private string $expectedVersion = Compiler::VERSION)
    {
        $this->root = Path::normalize($root ?? dirname(__DIR__, 2));
    }

    public function verify(string $assetDirectory, ?string $expectedCommit = null): void
    {
        $assetDirectory = Path::normalize($assetDirectory);

        if (!is_dir($assetDirectory) || is_link($assetDirectory)) {
            throw new \InvalidArgumentException('The release asset directory is unavailable or unsafe.');
        }

        $entries = array_values(array_filter(
            scandir($assetDirectory) ?: [],
            static fn (string $entry): bool => $entry !== '.' && $entry !== '..',
        ));
        sort($entries, SORT_STRING);
        $expectedEntries = ReleaseAssetBuilder::ASSET_NAMES;
        sort($expectedEntries, SORT_STRING);

        if ($entries !== $expectedEntries) {
            throw new \UnexpectedValueException('The release asset set is incomplete or contains unexpected files.');
        }

        foreach ($entries as $entry) {
            $path = Path::join($assetDirectory, $entry);

            if (!is_file($path) || is_link($path)) {
                throw new \UnexpectedValueException(sprintf('Release asset "%s" is not a regular file.', $entry));
            }
        }

        $checksums = $this->read(Path::join($assetDirectory, 'SHA256SUMS'));
        $covered = [];
        $previous = null;

        foreach (explode("\n", rtrim($checksums, "\n")) as $line) {
            if (preg_match('/\A([a-f0-9]{64})  ([A-Za-z0-9._-]+)\z/D', $line, $matches) !== 1) {
                throw new \UnexpectedValueException('SHA256SUMS contains an invalid entry.');
            }

            $name = $matches[2];

            if ($name === 'SHA256SUMS' || isset($covered[$name]) || ($previous !== null && strcmp($previous, $name) >= 0)) {
                throw new \UnexpectedValueException('SHA256SUMS contains a duplicate, self, or unsorted filename.');
            }

            $path = Path::join($assetDirectory, $name);

            if (!is_file($path) || !hash_equals($matches[1], (string) hash_file('sha256', $path))) {
                throw new \UnexpectedValueException(sprintf('Release asset "%s" does not match SHA256SUMS.', $name));
            }

            $covered[$name] = true;
            $previous = $name;
        }

        $expectedCovered = array_values(array_filter($expectedEntries, static fn (string $name): bool => $name !== 'SHA256SUMS'));
        sort($expectedCovered, SORT_STRING);
        $actualCovered = array_keys($covered);
        sort($actualCovered, SORT_STRING);

        if ($actualCovered !== $expectedCovered || !str_ends_with($checksums, "\n")) {
            throw new \UnexpectedValueException('SHA256SUMS does not cover the exact release asset set.');
        }

        $metadata = (new ReleaseMetadataLoader($this->root, expectedVersion: $this->expectedVersion))->load()
            ?? throw new \UnexpectedValueException('Committed release metadata is missing.');
        $manifestContents = $this->read(Path::join($assetDirectory, 'ppphp-release.json'));
        $manifest = CanonicalJson::decode($manifestContents);

        if (!is_array($manifest) || CanonicalJson::encode($manifest) !== $manifestContents) {
            throw new \UnexpectedValueException('The generated release manifest is not canonical JSON.');
        }

        $expectedKeys = [
            'channel', 'compiler', 'compilerBuildIdentity', 'composerPackage', 'formatVersion', 'hostPhp',
            'prerelease', 'product', 'releaseNotes', 'repository', 'schema', 'sourceCommit', 'tag',
            'targetPhpVersion', 'thirdPartyNotices', 'version', 'website',
        ];
        $keys = array_keys($manifest);
        sort($keys, SORT_STRING);
        sort($expectedKeys, SORT_STRING);

        if ($keys !== $expectedKeys) {
            throw new \UnexpectedValueException('The generated release manifest contains unexpected or missing properties.');
        }

        $sourceCommit = $manifest['sourceCommit'] ?? null;

        if (!is_string($sourceCommit) || preg_match('/\A[a-f0-9]{40}\z/D', $sourceCommit) !== 1) {
            throw new \UnexpectedValueException('The generated release manifest source commit is invalid.');
        }

        if ($expectedCommit !== null && $sourceCommit !== $expectedCommit) {
            throw new \UnexpectedValueException('The generated release manifest source commit does not match the expected commit.');
        }

        $schema = $this->read(Path::join($assetDirectory, 'ppphp.schema.json'));
        $notes = $this->read(Path::join($assetDirectory, 'RELEASE_NOTES.md'));
        $notices = $this->read(Path::join($assetDirectory, 'THIRD_PARTY_NOTICES.md'));
        $expected = [
            'channel' => $metadata->channel->value,
            'compiler' => Compiler::NAME,
            'compilerBuildIdentity' => (new CompilerBuildIdentity($this->root))->calculate(),
            'composerPackage' => 'atatusoft-ltd/ppphp-src',
            'formatVersion' => 1,
            'hostPhp' => '^8.4',
            'prerelease' => $metadata->prerelease,
            'product' => '++PHP',
            'releaseNotes' => ['asset' => 'RELEASE_NOTES.md', 'sha256' => 'sha256:' . hash('sha256', $notes)],
            'repository' => 'atatusoft-ltd/ppphp-src',
            'schema' => ['asset' => $metadata->schemaAsset, 'sha256' => 'sha256:' . hash('sha256', $schema), 'url' => $metadata->schemaUrl],
            'sourceCommit' => $sourceCommit,
            'tag' => $metadata->tag,
            'targetPhpVersion' => '8.4',
            'thirdPartyNotices' => ['asset' => 'THIRD_PARTY_NOTICES.md', 'sha256' => 'sha256:' . hash('sha256', $notices)],
            'version' => $metadata->version->canonical,
            'website' => 'https://ppphplang.org',
        ];

        if ($manifest !== $expected) {
            throw new \UnexpectedValueException('The generated release manifest does not match compiler release identity.');
        }

        if (
            $schema !== $this->read(Path::join($this->root, 'resources/schema/ppphp.schema.json'))
            || $notes !== (new ReleaseNotesRenderer())->render($this->read(Path::join($this->root, $metadata->releaseNotes)), $metadata)
            || $notices !== $this->read(Path::join($this->root, 'THIRD_PARTY_NOTICES.md'))
        ) {
            throw new \UnexpectedValueException('A release asset does not match its maintained source bytes.');
        }
    }

    private function read(string $path): string
    {
        if (!is_file($path) || is_link($path)) {
            throw new \UnexpectedValueException('A required release file is unavailable.');
        }

        $contents = file_get_contents($path);

        if (!is_string($contents)) {
            throw new \UnexpectedValueException('A required release file is unreadable.');
        }

        return $contents;
    }
}
