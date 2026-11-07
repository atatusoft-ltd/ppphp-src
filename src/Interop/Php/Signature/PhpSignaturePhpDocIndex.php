<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Interop\Php\Signature;

use Atatusoft\Ppphp\Frontend\ParsedFile;
use Atatusoft\Ppphp\Interop\PhpDoc\PhpDocMetadata;
use Atatusoft\Ppphp\Interop\PhpDoc\PhpDocReader;
use Composer\InstalledVersions;
use PhpParser\Comment\Doc;
use PhpParser\Node;

/** Parsed, scope-independent metadata from verified platform comments only. */
final class PhpSignaturePhpDocIndex
{
    public const string PATH = 'phpdoc-metadata.json';
    private const int FORMAT_VERSION = 1;

    /** @var array<string, PhpDocMetadata|array<string, mixed>> */
    private array $comments = [];

    public static function decode(string $bytes, string $target): ?self
    {
        $decoded = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || ($decoded['formatVersion'] ?? null) !== self::FORMAT_VERSION
            || ($decoded['targetPhpVersion'] ?? null) !== $target || !is_array($decoded['comments'] ?? null)
            || !is_string($decoded['parserReference'] ?? null)) {
            throw new \RuntimeException('The platform PHPDoc metadata index is malformed.');
        }

        if ($decoded['parserReference'] !== InstalledVersions::getReference('phpstan/phpdoc-parser')) {
            return null;
        }

        $index = new self();
        foreach ($decoded['comments'] as $digest => $record) {
            if (!is_string($digest) || !is_array($record)) {
                throw new \RuntimeException('The platform PHPDoc metadata index contains an invalid record.');
            }
            $fields = [];
            foreach ($record as $name => $value) {
                if (!is_string($name)) {
                    throw new \RuntimeException('The platform PHPDoc metadata index contains an invalid field.');
                }
                $fields[$name] = $value;
            }
            $index->comments[$digest] = $fields;
        }

        return $index;
    }

    public function collect(ParsedFile $file, PhpDocReader $reader): void
    {
        foreach (self::readComments($file->statements) as $document) {
            $digest = hash('sha256', $document->getText());
            $this->comments[$digest] ??= $reader->readMetadata($document);
        }
    }

    public function encode(string $target): string
    {
        $comments = $this->comments;
        ksort($comments, SORT_STRING);
        foreach ($comments as $digest => $metadata) {
            if ($metadata instanceof PhpDocMetadata) {
                $comments[$digest] = array_filter(get_object_vars($metadata));
            }
        }

        return json_encode([
            'formatVersion' => self::FORMAT_VERSION,
            'targetPhpVersion' => $target,
            'parserReference' => InstalledVersions::getReference('phpstan/phpdoc-parser'),
            'comments' => $comments,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    public function prime(ParsedFile $file): void
    {
        foreach (self::readComments($file->statements) as $document) {
            $digest = hash('sha256', $document->getText());
            $record = $this->comments[$digest] ?? null;
            if (is_array($record)) {
                $record = $this->comments[$digest] = self::restoreMetadata($record);
            }
            if ($record !== null) {
                PhpDocReader::rememberMetadata($document, $record);
            }
        }
    }

    /** @param array<string, mixed> $record */
    private static function restoreMetadata(array $record): PhpDocMetadata
    {
        $templates = [];
        $entries = $record['templates'] ?? [];
        if (!is_array($entries) || !array_is_list($entries)) {
            throw new \RuntimeException('Invalid platform PHPDoc template list.');
        }
        foreach ($entries as $entry) {
            if (!is_array($entry) || !is_string($entry['name'] ?? null)
                || !array_key_exists('bound', $entry) || ($entry['bound'] !== null && !is_string($entry['bound']))) {
                throw new \RuntimeException('Invalid platform PHPDoc template.');
            }
            $templates[] = ['name' => $entry['name'], 'bound' => $entry['bound']];
        }

        return new PhpDocMetadata(
            $templates,
            self::readMap($record['parameters'] ?? []),
            self::readList($record['returns'] ?? []),
            self::readMap($record['variables'] ?? []),
            self::readList($record['extends'] ?? []),
            self::readList($record['implements'] ?? []),
            self::readList($record['uses'] ?? []),
            self::readList($record['throws'] ?? []),
        );
    }

    /** @return array<string, string> */
    private static function readMap(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \RuntimeException('Invalid platform PHPDoc type map.');
        }
        $map = [];
        foreach ($value as $key => $type) {
            if (!is_string($key) || !is_string($type)) {
                throw new \RuntimeException('Invalid platform PHPDoc type entry.');
            }
            $map[$key] = $type;
        }

        return $map;
    }

    /** @return list<string> */
    private static function readList(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \RuntimeException('Invalid platform PHPDoc type list.');
        }
        $types = [];
        foreach ($value as $type) {
            if (!is_string($type)) {
                throw new \RuntimeException('Invalid platform PHPDoc type.');
            }
            $types[] = $type;
        }

        return $types;
    }

    /** @param array<mixed> $nodes
     * @return iterable<Doc>
     */
    private static function readComments(array $nodes): iterable
    {
        foreach ($nodes as $node) {
            if (!$node instanceof Node) {
                continue;
            }
            $document = $node->getDocComment();
            if ($document !== null) {
                yield $document;
            }
            foreach ($node->getSubNodeNames() as $name) {
                $child = $node->{$name};
                if ($child instanceof Node) {
                    yield from self::readComments([$child]);
                } elseif (is_array($child)) {
                    yield from self::readComments($child);
                }
            }
        }
    }
}
