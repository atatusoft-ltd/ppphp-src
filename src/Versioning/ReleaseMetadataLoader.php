<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Versioning;

use Atatusoft\Ppphp\Compiler\Compiler;
use Atatusoft\Ppphp\Support\CanonicalJson;
use Atatusoft\Ppphp\Support\Path;
use Atatusoft\Ppphp\Versioning\Enumerations\ReleaseChannel;
use Atatusoft\Ppphp\Versioning\Exceptions\InvalidReleaseMetadata;

final readonly class ReleaseMetadataLoader
{
    private string $root;

    private string $manifestPath;

    private string $schemaPath;

    public function __construct(
        ?string $root = null,
        ?string $manifestPath = null,
        ?string $schemaPath = null,
        private string $expectedVersion = Compiler::VERSION,
    ) {
        $this->root = Path::normalize($root ?? dirname(__DIR__, 2));
        $this->manifestPath = Path::normalize(
            $manifestPath ?? Path::join($this->root, 'resources/release/manifest.json'),
        );
        $this->schemaPath = Path::normalize(
            $schemaPath ?? Path::join($this->root, 'resources/schema/ppphp.schema.json'),
        );
    }

    public function load(): ?ReleaseMetadata
    {
        if (!file_exists($this->manifestPath)) {
            return null;
        }

        try {
            if (!is_file($this->manifestPath) || is_link($this->manifestPath)) {
                throw new \UnexpectedValueException('The release manifest is not a regular file.');
            }

            $contents = @file_get_contents($this->manifestPath);

            if (!is_string($contents)) {
                throw new \UnexpectedValueException('The release manifest is not readable.');
            }

            $document = $this->requireObject(CanonicalJson::decode($contents), 'release manifest');

            $this->requireExactKeys(
                $document,
                ['channel', 'formatVersion', 'prerelease', 'releaseNotes', 'schema', 'tag', 'version'],
                'release manifest',
            );

            if (($document['formatVersion'] ?? null) !== 1) {
                throw new \UnexpectedValueException('The release manifest format is unsupported.');
            }

            $versionValue = $this->requireString($document, 'version');
            $version = ReleaseVersion::parse($versionValue);

            if ($version->canonical !== $this->expectedVersion) {
                throw new \UnexpectedValueException('The release manifest version does not match the compiler.');
            }

            $channelValue = $this->requireString($document, 'channel');
            $channel = ReleaseChannel::tryFrom($channelValue)
                ?? throw new \UnexpectedValueException('The release manifest channel is unsupported.');

            if ($channel !== $version->channel) {
                throw new \UnexpectedValueException('The release manifest channel does not match its version.');
            }

            $tag = $this->requireString($document, 'tag');

            if ($tag !== $version->canonical) {
                throw new \UnexpectedValueException('The release manifest tag does not match its version.');
            }

            $prerelease = $document['prerelease'] ?? null;

            if (!is_bool($prerelease) || $prerelease !== $version->isPrerelease) {
                throw new \UnexpectedValueException('The release manifest prerelease flag does not match its channel.');
            }

            $schema = $this->requireObject($document['schema'] ?? null, 'release schema metadata');

            $this->requireExactKeys($schema, ['asset', 'sha256', 'url'], 'release schema metadata');
            $schemaAsset = $this->requireString($schema, 'asset');
            $schemaUrl = $this->requireString($schema, 'url');
            $schemaSha256 = $this->requireString($schema, 'sha256');
            $releaseSchema = new ReleaseSchema($version);

            if ($schemaAsset !== ReleaseSchema::ARTIFACT_NAME) {
                throw new \UnexpectedValueException('The release schema asset name is invalid.');
            }

            $releaseSchema->validatePublishedIdentity($tag, $schemaUrl);

            if (!is_file($this->schemaPath) || is_link($this->schemaPath)) {
                throw new \UnexpectedValueException('The bundled release schema is unavailable.');
            }

            $actualSchemaHash = @hash_file('sha256', $this->schemaPath);

            if (!is_string($actualSchemaHash)) {
                throw new \UnexpectedValueException('The bundled release schema could not be read.');
            }

            if ($schemaSha256 !== 'sha256:' . $actualSchemaHash) {
                throw new \UnexpectedValueException('The release schema hash does not match the bundled schema.');
            }

            $schemaContents = @file_get_contents($this->schemaPath);
            $schemaDocument = is_string($schemaContents) ? CanonicalJson::decode($schemaContents) : null;

            if (!is_array($schemaDocument) || ($schemaDocument['$id'] ?? null) !== $schemaUrl) {
                throw new \UnexpectedValueException('The bundled schema identity does not match release metadata.');
            }

            $releaseNotes = $this->requireString($document, 'releaseNotes');
            $this->validateRepositoryRelativePath($releaseNotes);
            $releaseNotesPath = Path::join($this->root, $releaseNotes);

            if (!Path::contains($this->root, $releaseNotesPath) || !is_file($releaseNotesPath) || is_link($releaseNotesPath)) {
                throw new \UnexpectedValueException('The release notes file is unavailable.');
            }

            return new ReleaseMetadata(
                1,
                $version,
                $channel,
                $tag,
                $prerelease,
                $schemaAsset,
                $schemaUrl,
                $schemaSha256,
                $releaseNotes,
            );
        } catch (\JsonException $exception) {
            throw new InvalidReleaseMetadata('The release metadata contains invalid JSON.', $exception);
        } catch (\UnexpectedValueException|\InvalidArgumentException $exception) {
            throw new InvalidReleaseMetadata($exception->getMessage(), $exception);
        }
    }

    /** @param array<string, mixed> $document */
    private function requireString(array $document, string $key): string
    {
        $value = $document[$key] ?? null;

        if (!is_string($value) || $value === '') {
            throw new \UnexpectedValueException(sprintf('The release metadata property "%s" must be a non-empty string.', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $document
     * @param list<string> $expected
     */
    private function requireExactKeys(array $document, array $expected, string $label): void
    {
        $keys = array_keys($document);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);

        if ($keys !== $expected) {
            throw new \UnexpectedValueException(sprintf('The %s contains unexpected or missing properties.', $label));
        }
    }

    /** @return array<string, mixed> */
    private function requireObject(mixed $value, string $label): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \UnexpectedValueException(sprintf('The %s must be a JSON object.', $label));
        }

        $object = [];

        foreach ($value as $key => $entry) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(sprintf('The %s must use string property names.', $label));
            }

            $object[$key] = $entry;
        }

        return $object;
    }

    private function validateRepositoryRelativePath(string $path): void
    {
        if (
            Path::isAbsolute($path)
            || str_contains($path, '\\')
            || Path::normalize($path) !== $path
            || $path === '.'
            || str_starts_with($path, '../')
        ) {
            throw new \UnexpectedValueException('The release notes path must be repository-relative.');
        }
    }
}
