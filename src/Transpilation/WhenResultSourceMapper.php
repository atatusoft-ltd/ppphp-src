<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Transpilation;

use Atatusoft\Ppphp\Semantic\When\WhenExpressionIndex;
use Atatusoft\Ppphp\Semantic\When\WhenExpressionAnalysis;
use Atatusoft\Ppphp\Source\Span;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/** Carries completed-result provenance, never an asserted result type. */
final class WhenResultSourceMapper
{
    /**
     * @param list<array{Node, Node}> $pairs
     * @param \WeakMap<Expr\Assign, Span> $origins
     * @return array<int, array{name: string, definitions: list<int>}>
     */
    public function map(array $pairs, \WeakMap $origins, WhenExpressionIndex $expressions): array
    {
        $definitions = $reads = [];
        foreach ($pairs as [$node, $emitted]) {
            $offset = $emitted->getStartFilePos() - strlen('<?php ');
            if ($node instanceof Expr\Assign && isset($origins[$node])) {
                $span = $origins[$node];
                $definitions[$span->start->offset . ':' . $span->end->offset][] = $offset;
            }
            $id = $node->getAttribute('ppphpCompletedWhenId');
            if ($node instanceof Expr\Variable && is_string($node->name) && is_string($id)) {
                $reads[$offset] = [$node->name, $id];
            }
        }
        $aliases = [];
        foreach ($expressions->expressions as $analysis) {
            $span = $analysis->syntax->span;
            $keys = $this->collectResultKeys($analysis, $expressions);
            $aliases[$span->start->offset . ':' . $span->end->offset] = $keys;
            $aliases[$this->resolveSpanKey($analysis->placeholder)] = $keys;
        }
        $facts = [];
        foreach ($reads as $offset => [$name, $id]) {
            $analysis = $expressions->find($id);
            if ($analysis === null) {
                continue;
            }
            $positions = $this->resolveDefinitions($this->collectResultKeys($analysis, $expressions), $definitions, $aliases);
            if ($positions === null || $positions === []) {
                continue;
            }
            $facts[$offset] = ['name' => $name, 'definitions' => array_values(array_unique($positions))];
        }
        return $facts;
    }

    /** @return list<string> */
    private function collectResultKeys(WhenExpressionAnalysis $analysis, WhenExpressionIndex $expressions): array
    {
        $terminating = $required = [];
        foreach ($analysis->branches as $branch) {
            foreach ((new NodeFinder())->findInstanceOf($branch->statements, Stmt\Return_::class) as $return) {
                if ($return->expr !== null && $expressions->resolveResultTermination($return)) {
                    $terminating[$this->resolveSpanKey($return->expr)] = true;
                }
            }
            foreach ($branch->resultSpans as $span) {
                $required[$span->start->offset . ':' . $span->end->offset] = true;
            }
        }
        return array_keys(array_diff_key($required, $terminating));
    }

    /** @param list<string> $keys
     * @param array<string, list<int>> $definitions
     * @param array<string, list<string>> $aliases
     * @param array<string, true> $visited
     * @return list<int>|null
     */
    private function resolveDefinitions(array $keys, array $definitions, array $aliases, array $visited = []): ?array
    {
        $positions = [];
        foreach ($keys as $key) {
            if (isset($definitions[$key])) {
                array_push($positions, ...$definitions[$key]);
                continue;
            }
            if (!isset($aliases[$key]) || isset($visited[$key])) {
                return null;
            }
            // A statementful nested when can assign directly to the parent's
            // result slot. Its exact source expression then aliases its results.
            $nested = $this->resolveDefinitions($aliases[$key], $definitions, $aliases, $visited + [$key => true]);
            if ($nested === null) {
                return null;
            }
            array_push($positions, ...$nested);
        }
        return $positions;
    }

    private function resolveSpanKey(Node $node): string
    {
        $start = $node->getAttribute('ppphpOriginalStart', $node->getStartFilePos());
        $end = $node->getAttribute('ppphpOriginalEnd', $node->getEndFilePos() + 1);
        if (!is_int($start) || !is_int($end)) {
            throw new \LogicException('A source result requires original offsets.');
        }
        return $start . ':' . $end;
    }
}
