<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic;

use Atatusoft\Ppphp\Frontend\ParsedFile;
use Atatusoft\Ppphp\Source\Span;
use Atatusoft\Ppphp\Source\SourceFile;
use PhpParser\Node;

final class NodeSpanResolver
{
    /** @var \WeakMap<Node, Span>|null */
    private static ?\WeakMap $spans = null;

    public function resolve(ParsedFile $parsedFile, Node $node): Span
    {
        $originalStart = $node->getAttribute('ppphpOriginalStart');
        $originalEnd = $node->getAttribute('ppphpOriginalEnd');

        if (is_int($originalStart) && is_int($originalEnd)) {
            return $this->createSpan($parsedFile->sourceFile, $node, $originalStart, $originalEnd);
        }

        $start = $node->getStartFilePos();
        $end = $node->getEndFilePos();

        if ($start < 0 || $end < $start) {
            return $this->createSpan($parsedFile->sourceFile, $node, 0, 0);
        }

        $normalizedStart = min($start, $parsedFile->sourceFile->length);
        $normalizedEnd = min($end + 1, $parsedFile->sourceFile->length);
        $originalStart = $parsedFile->sourceMap->resolveOriginalOffset($normalizedStart);
        $originalEnd = $parsedFile->sourceMap->resolveOriginalOffset($normalizedEnd);

        return $this->createSpan($parsedFile->sourceFile, $node, $originalStart, max($originalStart, $originalEnd));
    }

    private function createSpan(SourceFile $source, Node $node, int $start, int $end): Span
    {
        self::$spans ??= new \WeakMap();
        $cached = self::$spans[$node] ?? null;
        if ($cached !== null && $cached->sourceFile === $source
            && $cached->start->offset === $start && $cached->end->offset === $end) {
            return $cached;
        }

        return self::$spans[$node] = $source->createSpan($start, $end);
    }
}
