<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Transpilation;

use Atatusoft\Ppphp\Semantic\When\WhenExpressionAnalysis;
use Atatusoft\Ppphp\Semantic\When\WhenExpressionIndex;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/** Identifies branches whose results need no non-local control transfer. */
final class WhenTailShape
{
    public function __construct(private readonly ?WhenExpressionIndex $expressions = null) {}

    public function accepts(WhenExpressionAnalysis $analysis, bool $allowGuards = false): bool
    {
        foreach ($analysis->branches as $branch) {
            if (!$this->acceptsStatements($this->rewriteGuards($branch->statements), $allowGuards)) {
                return false;
            }
        }

        return true;
    }

    public function canReturnNatively(WhenExpressionAnalysis $analysis): bool
    {
        foreach ($analysis->branches as $branch) {
            foreach ($branch->statements as $statement) {
                if ($this->containsFinallyResult($statement)) {
                    return false;
                }
            }
        }
        return true;
    }

    private function containsFinallyResult(Node $node): bool
    {
        if ($node instanceof Node\FunctionLike || $node instanceof Stmt\ClassLike) {
            return false;
        }
        if ($node instanceof Stmt\Finally_ && $this->containsResult($node, completingOnly: true)) {
            return true;
        }
        foreach ($node->getSubNodeNames() as $name) {
            foreach (is_array($node->$name) ? $node->$name : [$node->$name] as $child) {
                if ($child instanceof Node && $this->containsFinallyResult($child)) {
                    return true;
                }
            }
        }
        return false;
    }

    public function requiresFinallyLoopExit(Stmt\TryCatch $statement): bool
    {
        // A protected result already leaves the loop through a native break;
        // finally can replace its value without another unreachable exit.
        return $statement->finally !== null && $this->containsResult($statement->finally, completingOnly: true)
            && !($this->expressions?->resolveTryBodyTermination($statement) ?? false);
    }

    /**
     * Moves a guard's remaining siblings onto its sole continuing arm. When
     * several arms can continue, a supplied pending test shares the remainder
     * instead of copying it. No source expression or continuation is duplicated.
     * The lowerer clones this tree before rewriting expressions and results.
     *
     * @param list<Stmt> $statements
     * @return list<Stmt>
     */
    public function rewriteGuards(array $statements, ?Expr $pending = null, bool $foldPendingGuards = false): array
    {
        $rewritten = [];
        foreach ($statements as $index => $statement) {
            if ($pending !== null && $statement instanceof Stmt\Return_
                && $this->containsResult($statement, completingOnly: true)) {
                // Keep unreachable authored statements available to analysis,
                // but never let erasing this return activate their execution.
                return [...$rewritten, $statement, ...$this->buildGuardedContinuation(
                    array_slice($statements, $index + 1), $pending,
                )];
            }
            if ($statement instanceof Stmt\Declare_ && $statement->stmts !== null) {
                $body = $statement->stmts;
                $statement = clone $statement;
                $statement->stmts = $this->rewriteGuards(array_values($body), $pending);
                $remainder = array_slice($statements, $index + 1);
                if ($pending !== null && $remainder !== [] && $this->containsResult($statement, completingOnly: true)) {
                    return [...$rewritten, $statement, ...$this->buildGuardedContinuation($remainder, $pending)];
                }
            }
            if ($statement instanceof Stmt\TryCatch) {
                $statement = clone $statement;
                $statement->stmts = $this->rewriteGuards(array_values($statement->stmts));
                $statement->catches = array_map(static fn (Stmt\Catch_ $catch): Stmt\Catch_ => clone $catch, $statement->catches);
                foreach ($statement->catches as $catch) {
                    $catch->stmts = $this->rewriteGuards(array_values($catch->stmts));
                }
                if ($statement->finally !== null) {
                    $statement->finally = clone $statement->finally;
                    // Finally runs even when the protected body has a result.
                    // Its own partial continuations receive a separate state.
                    $statement->finally->stmts = $this->rewriteGuards(array_values($statement->finally->stmts));
                }
                $remainder = array_slice($statements, $index + 1);
                if ($pending !== null && $remainder !== [] && $this->containsResult($statement, completingOnly: true)) {
                    return [...$rewritten, $statement, ...$this->buildGuardedContinuation($remainder, $pending)];
                }
            }
            if ($this->resolveLoop($statement) && $pending !== null && $this->containsResult($statement, completingOnly: true)) {
                // The result breaks out of the real loops. Only statements
                // after the outer loop need a shared continuation gate.
                $remainder = array_slice($statements, $index + 1);
                if ($remainder !== []) {
                    return [...$rewritten, $statement, ...$this->buildGuardedContinuation($remainder, $pending)];
                }
            }
            if ($statement instanceof Stmt\Switch_) {
                $statement = clone $statement;
                $statement->cases = array_map(static fn (Stmt\Case_ $case): Stmt\Case_ => clone $case, $statement->cases);
                foreach ($statement->cases as $case) {
                    $case->stmts = $this->rewriteGuards(array_values($case->stmts), $pending);
                }
                $remainder = array_slice($statements, $index + 1);
                if ($pending !== null && $remainder !== [] && $this->containsResult($statement, completingOnly: true)) {
                    return [...$rewritten, $statement, ...$this->buildGuardedContinuation($remainder, $pending)];
                }
            }
            if (!$statement instanceof Stmt\If_) {
                $rewritten[] = $statement;
                continue;
            }

            $statement = clone $statement;
            $statement->elseifs = array_map(static fn (Stmt\ElseIf_ $arm): Stmt\ElseIf_ => clone $arm, $statement->elseifs);
            $statement->else = $statement->else === null ? null : clone $statement->else;
            $arms = [$statement, ...$statement->elseifs, ...($statement->else === null ? [] : [$statement->else])];
            foreach ($arms as $arm) {
                $arm->stmts = $this->rewriteGuards(array_values($arm->stmts), $pending);
            }

            $remainder = $this->containsResult($statement, completingOnly: true) ? array_slice($statements, $index + 1) : [];
            if ($remainder !== []) {
                $continuing = array_values(array_filter($arms, fn (Stmt $arm): bool =>
                    !$this->completesStatements($arm->stmts, transferCompletes: true)));
                $paths = count($continuing) + ($statement->else === null ? 1 : 0);
                // A plain if can share the pending test in its condition.
                // Existing alternative arms cannot: keep their sole continuing
                // arm available for the ordinary else-move inside the gate.
                $foldCondition = $foldPendingGuards && $statement->else === null && $statement->elseifs === [];
                // Zero continuing arms must not expose an unreachable tail
                // either. Retain it behind the completion test so authored
                // code/trivia remains available to supplemental diagnostics.
                if ($pending !== null && ($paths !== 1 || $foldCondition)) {
                    return [...$rewritten, $statement, ...$this->buildGuardedContinuation($remainder, $pending)];
                } elseif ($paths === 1) {
                    if ($continuing === []) {
                        $statement->else = new Stmt\Else_($this->rewriteGuards($remainder, $pending));
                    } else {
                        $arm = $continuing[0];
                        $arm->stmts = $this->rewriteGuards([...array_values($arm->stmts), ...$remainder], $pending);
                    }
                } else {
                    // Leave an unresolved join intact for shape selection.
                    $remainder = [];
                }
            }

            // Successive guards read naturally as one elseif chain. Keep a
            // commented nested conditional in place rather than lose its trivia.
            while ($statement->else !== null && count($statement->else->stmts) === 1
                && $statement->else->getComments() === []
                && ($nested = $statement->else->stmts[0]) instanceof Stmt\If_ && $nested->getComments() === []) {
                $statement->elseifs[] = new Stmt\ElseIf_($nested->cond, $nested->stmts, $nested->getAttributes());
                array_push($statement->elseifs, ...$nested->elseifs);
                $statement->else = $nested->else;
            }
            $rewritten[] = $statement;
            if ($remainder !== []) {
                return $rewritten;
            }
        }

        return $rewritten;
    }

    /** @param list<Stmt> $statements
     * @return list<Stmt>
     */
    private function buildGuardedContinuation(array $statements, Expr $pending): array
    {
        if ($this->filterExecutableStatements($statements) === []) {
            return $statements;
        }
        $guarded = [];
        $group = [];
        foreach ($this->rewriteGuards($statements, $pending, foldPendingGuards: true) as $statement) {
            if ($statement instanceof Stmt\If_ && $statement->else === null && $statement->elseifs === []
                && $statement->getAttribute('ppphpGuardContinuation') !== true) {
                // An else/elseif chain must retain its outer gate: folding
                // only its first condition would activate the other arms.
                $statement->cond = new Expr\BinaryOp\BooleanAnd($pending, $statement->cond);
                $statement->setAttribute('ppphpGuardContinuation', true);
            }
            if ($statement->getAttribute('ppphpGuardContinuation') === true) {
                // Generated pending tests share one state. Keep successive
                // regions at the same depth instead of nesting every guard.
                if ($group !== []) {
                    $guarded[] = new Stmt\If_($pending, ['stmts' => $group], ['ppphpGuardContinuation' => true]);
                    $group = [];
                }
                $guarded[] = $statement;
            } else {
                $group[] = $statement;
            }
        }
        if ($group !== []) {
            $guarded[] = new Stmt\If_($pending, ['stmts' => $group], ['ppphpGuardContinuation' => true]);
        }

        return $guarded;
    }

    /** @param list<Stmt> $statements */
    private function acceptsStatements(array $statements, bool $allowGuards = false, bool $insideLoop = false): bool
    {
        $statements = $this->filterExecutableStatements($statements);
        foreach ($statements as $index => $statement) {
            if (!$this->containsResult($statement, completingOnly: true)) {
                continue;
            }
            if (!$insideLoop && $index !== array_key_last($statements)
                && !($allowGuards && ($statement instanceof Stmt\If_ || $statement instanceof Stmt\Switch_
                    || $statement instanceof Stmt\TryCatch || $statement instanceof Stmt\Declare_
                    || $statement instanceof Stmt\Return_ || $this->resolveLoop($statement)))) {
                return false;
            }
            if ($statement instanceof Stmt\Return_) {
                continue;
            }
            if ($statement instanceof Stmt\Declare_ && $statement->stmts !== null) {
                if (!$this->acceptsStatements(array_values($statement->stmts), $allowGuards, $insideLoop)) {
                    return false;
                }
                continue;
            }
            if ($statement instanceof Stmt\TryCatch) {
                if (!$this->acceptsStatements(array_values($statement->stmts), $allowGuards, $insideLoop)) {
                    return false;
                }
                foreach ($statement->catches as $catch) {
                    if (!$this->acceptsStatements(array_values($catch->stmts), $allowGuards, $insideLoop)) {
                        return false;
                    }
                }
                if ($statement->finally !== null && !$this->acceptsStatements(array_values($statement->finally->stmts), true)) {
                    return false;
                }
                continue;
            }
            if ($this->resolveLoop($statement)) {
                if ((!$allowGuards && !$insideLoop && !($this->expressions?->resolveLoopTermination($statement) ?? false))
                    || !$this->acceptsStatements(array_values($statement->stmts), true, true)) {
                    return false;
                }
                continue;
            }
            if ($statement instanceof Stmt\If_) {
                foreach ([$statement, ...$statement->elseifs, ...($statement->else === null ? [] : [$statement->else])] as $arm) {
                    if (!$this->acceptsStatements(array_values($arm->stmts), $allowGuards, $insideLoop)) {
                        return false;
                    }
                }
                continue;
            }
            if ($statement instanceof Stmt\Switch_) {
                foreach ($statement->cases as $case) {
                    if (!$this->acceptsStatements(array_values($case->stmts), $allowGuards, $insideLoop)) {
                        return false;
                    }
                }
                continue;
            }

            // Protected results have a separate lowering shape. Ordinary
            // nested callables own their returns.
            return false;
        }

        return true;
    }

    /** @param list<Stmt> $statements */
    public function requiresGuardCompletion(array $statements): bool
    {
        return !$this->acceptsStatements($this->rewriteGuards($statements));
    }

    /** @param list<Stmt> $statements */
    public function resolveGuardFallback(array $statements): ?Stmt\Return_
    {
        if (count($statements) !== 2 || !$statements[1] instanceof Stmt\Return_
            || $statements[1]->expr === null || $statements[1]->getComments() !== []
            || (!$statements[0] instanceof Stmt\If_ && !$statements[0] instanceof Stmt\Switch_ && !$this->resolveLoop($statements[0]))
            || !$this->containsResult($statements[0])
            || !$this->acceptsStatements($this->rewriteGuards([$statements[0]]), $this->resolveLoop($statements[0]))) {
            return null;
        }

        // The lowerer separately proves the fallback expression stable. A
        // partial statement needing its own shared state cannot use this form.
        return $statements[1];
    }

    public function containsResult(Node $node, bool $completingOnly = false): bool
    {
        return ($this->expressions ?? new WhenExpressionIndex())->containsResult($node, $completingOnly);
    }

    /** @phpstan-assert-if-true Stmt\For_|Stmt\Foreach_|Stmt\While_|Stmt\Do_ $statement */
    private function resolveLoop(Stmt $statement): bool
    {
        return $statement instanceof Stmt\For_ || $statement instanceof Stmt\Foreach_
            || $statement instanceof Stmt\While_ || $statement instanceof Stmt\Do_;
    }

    /** @param array<Stmt> $statements */
    public function completesCase(array $statements): bool
    {
        $statements = $this->filterExecutableStatements($statements);
        $tail = $statements === [] ? null : $statements[array_key_last($statements)];

        // Only result-bearing paths need a case exit. An all-throwing subtree
        // already terminates; appending a break there would be unreachable.
        return $tail !== null && $this->containsResult($tail, completingOnly: true) && $this->completesStatements($statements);
    }

    /** @param array<Stmt> $statements */
    private function completesStatements(array $statements, bool $fallthroughCompletes = false, bool $transferCompletes = false): bool
    {
        $statements = $this->filterExecutableStatements($statements);
        foreach (array_slice($statements, 0, -1) as $statement) {
            // An earlier result must exit immediately, not overwrite the
            // destination and then run this tail. Only source transfers fully
            // consumed by an earlier loop/switch can share the final exit.
            if (!$transferCompletes && ($this->containsResult($statement) || $this->containsEscapingTransfer($statement))) {
                return false;
            }
        }
        $tail = $statements === [] ? null : $statements[array_key_last($statements)];
        if ($tail instanceof Stmt\Return_) {
            return true;
        }
        if ($tail instanceof Stmt\Declare_ && $tail->stmts !== null) {
            return $this->completesStatements($tail->stmts, $fallthroughCompletes, $transferCompletes);
        }
        if ($tail instanceof Stmt\Expression && ($tail->expr instanceof Expr\Throw_ || $tail->expr instanceof Expr\Exit_)) {
            return true;
        }
        if ($tail instanceof Stmt\Break_ || $tail instanceof Stmt\Continue_) {
            return $transferCompletes;
        }
        if ($tail instanceof Stmt\If_) {
            foreach ([$tail, ...$tail->elseifs, ...($tail->else === null ? [] : [$tail->else])] as $arm) {
                if (!$this->completesStatements($arm->stmts, $fallthroughCompletes, $transferCompletes)) {
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

    /** @param array<Stmt> $statements
     * @return list<Stmt>
     */
    private function filterExecutableStatements(array $statements): array
    {
        // Trailing comments are carried by Nop nodes. They remain in the
        // printed tree, but cannot make a result non-tail or require a gate.
        return array_values(array_filter($statements, static fn (Stmt $statement): bool => !$statement instanceof Stmt\Nop));
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
