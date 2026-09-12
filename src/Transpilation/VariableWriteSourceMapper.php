<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Transpilation;

use Atatusoft\Ppphp\Source\SourceFile;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;

/** Carries authored write identities through a regenerated statement body. */
final class VariableWriteSourceMapper
{
    /** @param list<Node> $statements
     * @param list<SourceEditMapping> $mappings
     * @return list<SourceEditMapping>
     */
    public function map(array $statements, string $replacement, SourceFile $source, array $mappings): array
    {
        $tokens = $this->tokenize($replacement);
        $used = [];
        $printer = new WhenPhpPrinter();
        foreach ((new NodeFinder())->find($statements, static fn (Node $node): bool => $node instanceof Expr\Assign) as $assignment) {
            if (!$assignment instanceof Expr\Assign || !$assignment->var instanceof Expr\Variable
                || !is_string($assignment->var->name)) {
                continue;
            }
            $variable = $assignment->var;
            $name = $assignment->var->name;
            $start = $variable->getAttribute('ppphpOriginalStart', $variable->getStartFilePos());
            $end = $variable->getAttribute('ppphpOriginalEnd', $variable->getEndFilePos() + 1);
            $authored = is_int($start) && is_int($end) && $start >= 0 && $end > $start
                && substr($source->contents, $start, $end - $start) === '$' . $name;
            $needle = $this->tokenize($printer->prettyPrintExpr($assignment));
            foreach ($tokens as $index => $token) {
                if (isset($used[$index]) || $token->id !== T_VARIABLE || $token->text !== '$' . $name) {
                    continue;
                }
                foreach ($needle as $part => $expected) {
                    $actual = $tokens[$index + $part] ?? null;
                    if ($actual === null || $actual->id !== $expected->id || $actual->text !== $expected->text) {
                        continue 2;
                    }
                }
                $used[$index] = true;
                if (!$authored) {
                    break; // Reserve this occurrence without inventing a source contract.
                }
                $from = $token->pos - strlen('<?php ');
                $to = $from + strlen($token->text);
                $split = [];
                foreach ($mappings as $mapping) {
                    if ($mapping->replacementEnd <= $from || $mapping->replacementStart >= $to) {
                        $split[] = $mapping;
                        continue;
                    }
                    if ($mapping->replacementStart < $from) {
                        $split[] = new SourceEditMapping($mapping->replacementStart, $from, $mapping->origin);
                    }
                    if ($mapping->replacementEnd > $to) {
                        $split[] = new SourceEditMapping($to, $mapping->replacementEnd, $mapping->origin);
                    }
                }
                $split[] = new SourceEditMapping($from, $to, $source->createSpan($start, $end));
                $mappings = $split;
                break;
            }
        }
        usort($mappings, static fn (SourceEditMapping $a, SourceEditMapping $b): int => $a->replacementStart <=> $b->replacementStart);
        return $mappings;
    }

    /** @return list<\PhpToken> */
    private function tokenize(string $code): array
    {
        return array_values(array_filter(\PhpToken::tokenize('<?php ' . $code),
            static fn (\PhpToken $token): bool => $token->id !== T_OPEN_TAG && !$token->isIgnorable()));
    }
}
