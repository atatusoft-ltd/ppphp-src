<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Versioning;

use Atatusoft\Ppphp\Versioning\Enumerations\DocumentationAudience;

final readonly class DocumentationPolicy
{
    /** @var list<string> */
    private const array PUBLIC_DOCUMENTS = [
        'README.md',
        'CHANGELOG.md',
        'SECURITY.md',
        'THIRD_PARTY_NOTICES.md',
        'RELEASE_NOTES.md',
        'ppphp-release.json',
        'composer.json',
        'docs/getting-started.md',
        'docs/migrating-from-php.md',
        'docs/language.md',
        'docs/cli.md',
        'docs/interoperability.md',
        'docs/mixed-projects.md',
        'docs/composer-runtime.md',
        'docs/typed-local-bindings.md',
        'docs/typed-loop-bindings.md',
        'docs/typed-arrays.md',
        'docs/generics.md',
        'docs/checked-errors.md',
        'docs/when-expressions.md',
    ];

    /** @var list<string> */
    private const array MAINTAINER_DOCUMENTS = [
        'AGENTS.md',
        'CONTRIBUTING.md',
        'docs/releasing.md',
    ];

    /** @var array<string, string> */
    private const array PROCESS_PATTERNS = [
        'numbered development stage' => '/\bStages?\s+[0-9]+[A-Z]?\b/i',
        'completion gate' => '/\bcompletion[ -]gate\b/i',
        'promotion gate' => '/\bpromotion[ -]gate\b/i',
        'acceptance criteria' => '/\bacceptance criteria\b/i',
        'implementation stage' => '/\bimplementation stage\b/i',
        'implementing agent' => '/\bimplementing agent\b/i',
        'Codex' => '/\bCodex\b/i',
        'internal prompt' => '/\b(?:agent|implementation|internal) prompt\b|\bprompt history\b/i',
        'next development step' => '/\bnext (?:beat|stage)\b/i',
        'develop branch history' => '/\blanded on develop\b/i',
        'merge history' => '/\bmerge commit\b|\b(?:PR|pull request) sequence\b/i',
        'internal capability count' => '/\b[0-9]+[- ]capabilit(?:y|ies)\b/i',
        'internal scenario count' => '/\b[0-9]+\s+(?:executable\s+)?(?:parity\s+)?scenarios?\b/i',
        'promotion deliberation' => '/\b(?:technical\s+)?promotion deliberation\b/i',
    ];

    /** @var array<string, string> */
    private const array FUTURE_FEATURE_PATTERNS = [
        'record declarations' => '/\brecord declarations?\b|\b(?:supports?|includes?|provides?|implements?|adds?|ships?)\b[^.]{0,80}\b(?:immutable\s+)?records?\b/i',
        'postfix list syntax' => '/\bT\[\]|\bpostfix list (?:type )?syntax\b/i',
        'Native Type Members' => '/\bNative Type Members?\b/i',
        'attribute factory expressions' => '/\battribute factory expressions?\b/i',
    ];

    private const string UNAVAILABLE_CONTEXT_PATTERN = '/\b(?:future|planned|roadmap|post-MVP|unavailable|not yet|not (?:part of|included in|available in|implemented in|supported by)|does not (?:include|support))\b/i';

    public function isInternalPath(string $relativePath): bool
    {
        return preg_match(
            '~\Adocs/(?:spikes/|experiments/|ppphp-|analyzer-promotion-readiness\.md\z|browser/(?:bp[0-9]|baseline\.md\z|runtime-fiber-repair\.md\z))~D',
            $relativePath,
        ) === 1;
    }

    public function classify(string $relativePath): DocumentationAudience
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), './');

        if (
            in_array($relativePath, self::PUBLIC_DOCUMENTS, true)
            || (str_starts_with($relativePath, 'docs/releases/') && str_ends_with($relativePath, '.md'))
            || preg_match('/\Aexamples\/[^\/]+\/README\.md\z/D', $relativePath) === 1
        ) {
            return DocumentationAudience::Public;
        }

        if (
            in_array($relativePath, self::MAINTAINER_DOCUMENTS, true)
            || str_starts_with($relativePath, 'docs/decisions/')
            || str_starts_with($relativePath, 'docs/rfcs/')
        ) {
            return DocumentationAudience::Maintainer;
        }

        return DocumentationAudience::Technical;
    }

    /** @return list<string> */
    public function validatePublic(string $relativePath, string $contents): array
    {
        if ($this->classify($relativePath) !== DocumentationAudience::Public) {
            return [];
        }

        $failures = $this->findRetiredNamespaceReferences($relativePath, $contents);
        $lines = preg_split('/\r\n|\n|\r/', $contents) ?: [];

        foreach ($lines as $index => $line) {
            foreach (self::PROCESS_PATTERNS as $description => $pattern) {
                if (preg_match($pattern, $line) === 1) {
                    $failures[] = sprintf(
                        '%s:%d contains prohibited public process language (%s)',
                        $relativePath,
                        $index + 1,
                        $description,
                    );
                }
            }

            if (preg_match(self::UNAVAILABLE_CONTEXT_PATTERN, $line) === 1) {
                continue;
            }

            foreach (self::FUTURE_FEATURE_PATTERNS as $feature => $pattern) {
                if (preg_match($pattern, $line) === 1) {
                    $failures[] = sprintf(
                        '%s:%d presents unavailable %s without an explicit future or unavailable marker',
                        $relativePath,
                        $index + 1,
                        $feature,
                    );
                }
            }
        }

        return $failures;
    }

    /** @return list<string> */
    public function findRetiredNamespaceReferences(string $relativePath, string $contents): array
    {
        $namespace = implode('', ['Ama', 'siye', '\\', 'Ppphp']);
        $escapedNamespace = str_replace('\\', '\\\\', $namespace);
        $failures = [];
        $lines = preg_split('/\r\n|\n|\r/', $contents) ?: [];

        foreach ($lines as $index => $line) {
            if (stripos($line, $namespace) !== false || stripos($line, $escapedNamespace) !== false) {
                $failures[] = sprintf('%s:%d contains the retired compiler namespace', $relativePath, $index + 1);
            }
        }

        return $failures;
    }
}
