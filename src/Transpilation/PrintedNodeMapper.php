<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Transpilation;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/** Matches emitted nodes structurally; no facts survive an ambiguous match. */
final class PrintedNodeMapper
{
    private ?Parser $parser = null;

    /** @param list<Stmt> $statements
     * @return list<array{Node, Node}>
     */
    public function map(array $statements, string $replacement): array
    {
        try {
            $printed = ($this->parser ??= (new ParserFactory())->createForNewestSupportedVersion())
                ->parse('<?php ' . $replacement);
        } catch (\PhpParser\Error) {
            return [];
        }
        $pairs = [];
        return $this->collectPairs($statements, $printed, $pairs) ? $pairs : [];
    }

    /** @param list<array{Node, Node}> $pairs */
    private function collectPairs(mixed $original, mixed $printed, array &$pairs): bool
    {
        if ($original instanceof Node) {
            if (!$printed instanceof Node || $original->getType() !== $printed->getType()) {
                return false;
            }
            $pairs[] = [$original, $printed];
            foreach ($original->getSubNodeNames() as $name) {
                if (!$this->collectPairs($original->$name, $printed->$name, $pairs)) {
                    return false;
                }
            }
            return true;
        }
        if (is_array($original) && is_array($printed)) {
            // Standalone comments may attach to a following statement on
            // reparse. Their executable fields are empty; output is untouched.
            $original = array_values(array_filter($original, static fn ($node): bool => !$node instanceof Stmt\Nop));
            $printed = array_values(array_filter($printed, static fn ($node): bool => !$node instanceof Stmt\Nop));
            if (count($original) !== count($printed)) {
                return false;
            }
            foreach ($original as $index => $node) {
                if (!$this->collectPairs($node, $printed[$index], $pairs)) {
                    return false;
                }
            }
            return true;
        }
        return $original === $printed;
    }
}
