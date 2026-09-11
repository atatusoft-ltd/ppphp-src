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
        if ($tail instanceof Stmt\Return_) {
            return true;
        }
        if (!$tail instanceof Stmt\If_ || $tail->else === null) {
            return false;
        }
        foreach ([$tail, ...$tail->elseifs, $tail->else] as $arm) {
            if (!$this->completesCase($arm->stmts)) {
                return false;
            }
        }

        return true;
    }
}
