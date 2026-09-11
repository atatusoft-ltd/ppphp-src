<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Transpilation;

use Atatusoft\Ppphp\Semantic\When\WhenExpressionAnalysis;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/** Identifies branches whose results need no non-local control transfer. */
final class WhenTailShape
{
    public function accepts(WhenExpressionAnalysis $analysis): bool
    {
        foreach ($analysis->branches as $branch) {
            if (!$this->acceptsStatements($branch->statements)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<Stmt> $statements */
    private function acceptsStatements(array $statements): bool
    {
        foreach ($statements as $index => $statement) {
            if (!$this->containsResult($statement)) {
                continue;
            }
            if ($index !== array_key_last($statements)) {
                return false;
            }
            if ($statement instanceof Stmt\Return_) {
                continue;
            }
            if ($statement instanceof Stmt\If_) {
                foreach ([$statement, ...$statement->elseifs, ...($statement->else === null ? [] : [$statement->else])] as $arm) {
                    if (!$this->acceptsStatements(array_values($arm->stmts))) {
                        return false;
                    }
                }
                continue;
            }
            if ($statement instanceof Stmt\Switch_) {
                foreach ($statement->cases as $case) {
                    if (!$this->acceptsStatements(array_values($case->stmts))) {
                        return false;
                    }
                }
                continue;
            }

            // Returns in loops, guards and protected regions have separate
            // lowering shapes. Ordinary nested callables own their returns.
            return false;
        }

        return true;
    }

    private function containsResult(Node $node): bool
    {
        if ($node instanceof Stmt\Return_) {
            return true;
        }
        if ($node instanceof Stmt\Function_ || $node instanceof Stmt\ClassLike
            || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            return false;
        }
        foreach ($node->getSubNodeNames() as $name) {
            foreach (is_array($node->$name) ? $node->$name : [$node->$name] as $child) {
                if ($child instanceof Node && $this->containsResult($child)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<Stmt> $statements */
    public function completesCase(array $statements): bool
    {
        $tail = $statements === [] ? null : $statements[array_key_last($statements)];

        // Only result-bearing paths need a case exit. An all-throwing subtree
        // already terminates; appending a break there would be unreachable.
        return $tail !== null && $this->containsResult($tail) && $this->completesStatements($statements);
    }

    /** @param array<Stmt> $statements */
    private function completesStatements(array $statements, bool $fallthroughCompletes = false): bool
    {
        foreach (array_slice($statements, 0, -1) as $statement) {
            // A conditional transfer can bypass the tail result. Transfers
            // contained in an earlier loop/switch do not leave this list.
            if ($this->containsEscapingTransfer($statement)) {
                return false;
            }
        }
        $tail = $statements === [] ? null : $statements[array_key_last($statements)];
        if ($tail instanceof Stmt\Return_) {
            return true;
        }
        if ($tail instanceof Stmt\Expression && ($tail->expr instanceof Expr\Throw_ || $tail->expr instanceof Expr\Exit_)) {
            return true;
        }
        if ($tail instanceof Stmt\Break_ || $tail instanceof Stmt\Continue_) {
            return false;
        }
        if ($tail instanceof Stmt\If_) {
            foreach ([$tail, ...$tail->elseifs, ...($tail->else === null ? [] : [$tail->else])] as $arm) {
                if (!$this->completesStatements($arm->stmts, $fallthroughCompletes)) {
                    return false;
                }
            }

            return $tail->else !== null || $fallthroughCompletes;
        }
        if ($tail instanceof Stmt\Switch_) {
            $hasDefault = false;
            $nextCompletes = $fallthroughCompletes;
            foreach (array_reverse($tail->cases) as $case) {
                $hasDefault = $hasDefault || $case->cond === null;
                // An empty or partially returning case may fall through to a
                // later case. Account for every possible entry, not just the
                // last case or the presence of a default label.
                $nextCompletes = $this->completesStatements($case->stmts, $nextCompletes);
                if (!$nextCompletes) {
                    return false;
                }
            }

            return $hasDefault || $fallthroughCompletes;
        }

        return $fallthroughCompletes;
    }

    private function containsEscapingTransfer(Node $node, int $depth = 0): bool
    {
        if ($node instanceof Node\FunctionLike || $node instanceof Stmt\ClassLike) {
            return false;
        }
        if ($node instanceof Stmt\Break_ || $node instanceof Stmt\Continue_) {
            return ($node->num instanceof Node\Scalar\Int_ ? $node->num->value : 1) > $depth;
        }
        if ($node instanceof Stmt\For_ || $node instanceof Stmt\Foreach_
            || $node instanceof Stmt\While_ || $node instanceof Stmt\Do_ || $node instanceof Stmt\Switch_) {
            $depth++;
        }
        foreach ($node->getSubNodeNames() as $name) {
            foreach (is_array($node->$name) ? $node->$name : [$node->$name] as $child) {
                if ($child instanceof Node && $this->containsEscapingTransfer($child, $depth)) {
                    return true;
                }
            }
        }

        return false;
    }
}
