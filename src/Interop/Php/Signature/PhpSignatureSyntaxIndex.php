<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Interop\Php\Signature;

use Atatusoft\Ppphp\Support\CanonicalJson;
use Composer\InstalledVersions;
use PhpParser\Node\Stmt;

/** Fingerprints the syntax produced from verified platform sources and a pinned parser. */
final class PhpSignatureSyntaxIndex
{
    public const string PATH = 'syntax-fingerprints.json';
    private const int FORMAT_VERSION = 1;
    /** @var array<string, string> */
    private array $documents = [];

    /** @param list<Stmt> $statements */
    public function collect(string $key, array $statements): void
    {
        $digest = BrowserPhpSignatureCache::fingerprint($statements);
        if ($digest !== null) {
            $this->documents[$key] = $digest;
        }
    }

    public function find(string $key): ?string
    {
        return $this->documents[$key] ?? null;
    }

    public function encode(string $target): string
    {
        $documents = $this->documents;
        ksort($documents, SORT_STRING);

        return CanonicalJson::encode([
            'formatVersion' => self::FORMAT_VERSION,
            'targetPhpVersion' => $target,
            'parserReference' => InstalledVersions::getReference('nikic/php-parser'),
            'documents' => $documents,
        ]);
    }

    public static function decode(string $bytes, string $target): ?self
    {
        $index = CanonicalJson::decode($bytes);
        if (!is_array($index) || ($index['formatVersion'] ?? null) !== self::FORMAT_VERSION
            || ($index['targetPhpVersion'] ?? null) !== $target || !is_string($index['parserReference'] ?? null)
            || !is_array($index['documents'] ?? null)) {
            throw new \RuntimeException('The platform syntax fingerprint index is malformed.');
        }
        if ($index['parserReference'] !== InstalledVersions::getReference('nikic/php-parser')) {
            return null;
        }

        $syntax = new self();
        foreach ($index['documents'] as $key => $digest) {
            if (!is_string($key) || !is_string($digest) || preg_match('/\A[a-f0-9]{64}\z/', $digest) !== 1) {
                throw new \RuntimeException('The platform syntax fingerprint index contains an invalid record.');
            }
            $syntax->documents[$key] = $digest;
        }

        return $syntax;
    }
}
