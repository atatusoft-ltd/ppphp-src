<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Versioning;

use Atatusoft\Ppphp\Cache\CompilerBuildIdentity;
use Atatusoft\Ppphp\Compiler\Compiler;
use Atatusoft\Ppphp\Support\CanonicalJson;
use Atatusoft\Ppphp\Support\Path;

final readonly class ReleaseAssetBuilder
{
    /** @var list<string> */
    public const array ASSET_NAMES = [
        'ppphp.schema.json',
        'ppphp-release.json',
        'RELEASE_NOTES.md',
        'THIRD_PARTY_NOTICES.md',
        'SHA256SUMS',
    ];

    private string $root;

    public function __construct(?string $root = null, private string $expectedVersion = Compiler::VERSION)
    {
        $this->root = Path::normalize($root ?? dirname(__DIR__, 2));
    }

    public function build(string $outputPath, string $sourceCommit): void
    {
        if (preg_match('/\A[a-f0-9]{40}\z/D', $sourceCommit) !== 1) {
            throw new \InvalidArgumentException('The source commit must be exactly 40 lowercase hexadecimal characters.');
        }

        $outputPath = Path::normalize($outputPath);
        $this->validateOutputPath($outputPath);
        $metadata = (new ReleaseMetadataLoader($this->root, expectedVersion: $this->expectedVersion))->load()
            ?? throw new \RuntimeException('Release assets require committed release metadata.');
        $schema = $this->readRequiredFile('resources/schema/ppphp.schema.json');
        $releaseNotes = (new ReleaseNotesRenderer())->render($this->readRequiredFile($metadata->releaseNotes), $metadata);
        $thirdPartyNotices = $this->readRequiredFile('THIRD_PARTY_NOTICES.md');
        $assets = [
            'ppphp.schema.json' => $schema,
            'RELEASE_NOTES.md' => $releaseNotes,
            'THIRD_PARTY_NOTICES.md' => $thirdPartyNotices,
        ];
        $releaseManifest = [
            'channel' => $metadata->channel->value,
            'compiler' => Compiler::NAME,
            'compilerBuildIdentity' => (new CompilerBuildIdentity($this->root))->calculate(),
            'composerPackage' => 'atatusoft-ltd/ppphp-src',
            'formatVersion' => 1,
            'hostPhp' => '^8.4',
            'prerelease' => $metadata->prerelease,
            'product' => '++PHP',
            'releaseNotes' => [
                'asset' => 'RELEASE_NOTES.md',
                'sha256' => 'sha256:' . hash('sha256', $releaseNotes),
            ],
            'repository' => 'atatusoft-ltd/ppphp-src',
            'schema' => [
                'asset' => $metadata->schemaAsset,
                'sha256' => 'sha256:' . hash('sha256', $schema),
                'url' => $metadata->schemaUrl,
            ],
            'sourceCommit' => $sourceCommit,
            'tag' => $metadata->tag,
            'targetPhpVersion' => '8.4',
            'thirdPartyNotices' => [
                'asset' => 'THIRD_PARTY_NOTICES.md',
                'sha256' => 'sha256:' . hash('sha256', $thirdPartyNotices),
            ],
            'version' => $metadata->version->canonical,
            'website' => 'https://ppphplang.org',
        ];
        $assets['ppphp-release.json'] = CanonicalJson::encode($releaseManifest);
        ksort($assets, SORT_STRING);
        $checksumLines = [];

        foreach ($assets as $name => $contents) {
            $checksumLines[] = hash('sha256', $contents) . '  ' . $name;
        }

        $assets['SHA256SUMS'] = implode("\n", $checksumLines) . "\n";
        $this->replaceOutput($outputPath, $assets);
    }

    private function validateOutputPath(string $outputPath): void
    {
        if (!Path::isAbsolute($outputPath) || Path::isRoot($outputPath)) {
            throw new \InvalidArgumentException('The release output path must be an absolute non-root path.');
        }

        if (Path::contains($outputPath, $this->root)) {
            throw new \InvalidArgumentException('The release output path overlaps protected compiler input or state.');
        }

        foreach (['src', 'resources', 'vendor', 'build', '.ppphp-cache'] as $protected) {
            $protectedPath = Path::join($this->root, $protected);

            if (Path::contains($protectedPath, $outputPath)) {
                throw new \InvalidArgumentException('The release output path overlaps protected compiler input or state.');
            }
        }

        if (is_link($outputPath) || Path::hasSymlinkAncestor($outputPath, dirname($outputPath))) {
            throw new \InvalidArgumentException('The release output path cannot traverse a symbolic link.');
        }
    }

    private function readRequiredFile(string $relativePath): string
    {
        $path = Path::join($this->root, $relativePath);

        if (!Path::contains($this->root, $path) || !is_file($path) || is_link($path)) {
            throw new \RuntimeException(sprintf('Release input "%s" is unavailable.', $relativePath));
        }

        $contents = file_get_contents($path);

        if (!is_string($contents)) {
            throw new \RuntimeException(sprintf('Release input "%s" is unreadable.', $relativePath));
        }

        return $contents;
    }

    /** @param array<string, string> $assets */
    private function replaceOutput(string $outputPath, array $assets): void
    {
        $parent = dirname($outputPath);

        if ((!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) || is_link($parent)) {
            throw new \RuntimeException('The release output parent could not be prepared safely.');
        }

        $identity = bin2hex(random_bytes(12));
        $candidate = $outputPath . '.candidate-' . $identity;
        $backup = $outputPath . '.backup-' . $identity;

        if (!mkdir($candidate, 0700)) {
            throw new \RuntimeException('The release asset candidate could not be created.');
        }

        try {
            foreach ($assets as $name => $contents) {
                if (file_put_contents(Path::join($candidate, $name), $contents, LOCK_EX) === false) {
                    throw new \RuntimeException(sprintf('Release asset "%s" could not be written.', $name));
                }
            }

            $hadOutput = file_exists($outputPath);

            if ($hadOutput && (!is_dir($outputPath) || is_link($outputPath) || !rename($outputPath, $backup))) {
                throw new \RuntimeException('The previous release output could not be preserved.');
            }

            if (!rename($candidate, $outputPath)) {
                if ($hadOutput && is_dir($backup)) {
                    @rename($backup, $outputPath);
                }

                throw new \RuntimeException('The release asset candidate could not be committed.');
            }

            if ($hadOutput) {
                $this->removeOwnedTree($backup, $parent);
            }
        } catch (\Throwable $exception) {
            if (is_dir($candidate) && !is_link($candidate)) {
                $this->removeOwnedTree($candidate, $parent);
            }

            throw $exception;
        }
    }

    private function removeOwnedTree(string $path, string $parent): void
    {
        if (!Path::contains($parent, $path) || !is_dir($path) || is_link($path)) {
            throw new \RuntimeException('Release temporary output could not be proven safe to remove.');
        }

        foreach (new \DirectoryIterator($path) as $entry) {
            if ($entry->isDot()) {
                continue;
            }

            if (!$entry->isFile() || $entry->isLink() || !unlink($entry->getPathname())) {
                throw new \RuntimeException('Release temporary output contains an unsafe entry.');
            }
        }

        if (!rmdir($path)) {
            throw new \RuntimeException('Release temporary output could not be removed.');
        }
    }
}
