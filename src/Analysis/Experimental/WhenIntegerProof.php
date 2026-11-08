<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Experimental;

use Atatusoft\Ppphp\Semantic\Binding\Enumerations\BindingMutability;
use Atatusoft\Ppphp\Semantic\Binding\LocalBinding;
use Atatusoft\Ppphp\Semantic\SemanticModel;
use Atatusoft\Ppphp\Semantic\When\WhenExpressionAnalysis;
use Atatusoft\Ppphp\Semantic\When\WhenExpressionSite;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;

/**
 * Validates both taken and untaken paths of a bounded local integer expression.
 *
 * @phpstan-import-type Locals from ScriptExpressionProof
 * @phpstan-import-type Collections from ScriptExpressionProof
 */
final readonly class WhenIntegerProof
{
    /** @var array<int, LocalBinding> */
    private array $bindings;

    /**
     * @param Locals $outer
     * @param Collections $arrays
     * @param array<string, true> $pristine
     * @param array<int, array{name: string, array: string}> $counts
     */
    public function __construct(
        SemanticModel $model,
        private array $outer,
        private array $arrays,
        private array $pristine,
        private array $counts,
    ) {
        $bindings = [];
        foreach ($model->bindings->bindings as $binding) {
            $bindings[$binding->variableSpan->start->offset] = $binding;
        }
        $this->bindings = $bindings;
    }

    /**
     * @param array<string, true> $reads
     * @param array<int, list<string>> $omissions
     */
    public function resolve(WhenExpressionAnalysis $when, array &$reads, array &$omissions, int &$steps): ?int
    {
        if ($when->site !== WhenExpressionSite::TypedLocalInitializer || $when->resultType->semanticType->canonical !== 'int') {
            return null;
        }

        $value = null;
        foreach ($when->branches as $branch) {
            $taken = $branch->condition === null ? true : $this->resolveCondition($branch->condition, $reads);
            if ($taken === null || $branch->canComplete || $branch->resultType->semanticType->canonical !== 'int') {
                return null;
            }

            $branchReads = [];
            $result = $this->resolveBranch($branch->statements, $branchReads, $omissions, $steps);
            if ($result === null) {
                return null;
            }
            $reads += array_intersect_key($branchReads, $this->outer);
            if ($taken && $value === null) {
                $value = $result;
            }
        }

        return $value;
    }

    /** @param array<string, true> $reads */
    private function resolveCondition(Expr $condition, array &$reads): ?bool
    {
        if ($condition instanceof Expr\BinaryOp\NotIdentical && $condition->left instanceof Expr\Variable
            && is_string($condition->left->name) && isset($this->pristine[$condition->left->name], $this->arrays[$condition->left->name])
            && $condition->right instanceof Expr\Array_ && $condition->right->items === []) {
            // The input is a proved nonempty array with its original typed declaration.
            return true;
        }

        $operand = $condition instanceof Expr\BooleanNot ? $condition->expr : $condition;
        if (!$operand instanceof Expr\Variable || !is_string($operand->name) || !isset($this->pristine[$operand->name])) {
            return null;
        }
        $value = $this->outer[$operand->name] ?? null;
        if (!is_bool($value)) {
            return null;
        }
        $reads[$operand->name] = true;

        return $condition instanceof Expr\BooleanNot ? !$value : $value;
    }

    /**
     * @param list<Stmt> $statements
     * @param array<string, true> $reads
     * @param array<int, list<string>> $omissions
     */
    private function resolveBranch(array $statements, array &$reads, array &$omissions, int &$steps): ?int
    {
        $locals = [];
        $mutable = [];
        $statements = array_values(array_filter($statements, static fn (Stmt $node): bool => !$node instanceof Stmt\Nop));
        $return = array_pop($statements);
        if (!$return instanceof Stmt\Return_ || $return->expr === null) {
            return null;
        }

        foreach ($statements as $statement) {
            if (--$steps < 0) {
                return null;
            }
            if ($statement instanceof Stmt\Foreach_) {
                if (!$this->checkLoop($statement, $locals, $mutable, $reads, $steps)) {
                    return null;
                }
                continue;
            }
            if (!$statement instanceof Stmt\Expression || !$statement->expr instanceof Expr\Assign
                || !$statement->expr->var instanceof Expr\Variable || !is_string($statement->expr->var->name)) {
                return null;
            }
            $assign = $statement->expr;
            $name = $statement->expr->var->name;
            $binding = $this->findBinding($statement->expr->var);
            if ($binding === null || $binding->initializerExpression !== $assign->expr
                || $binding->type->semanticType->canonical !== 'int'
                || in_array($name, ScriptExpressionProof::AMBIENT_VARIABLES, true)
                || isset($locals[$name]) || isset($this->outer[$name]) || isset($this->arrays[$name])) {
                return null;
            }

            if ($assign->expr instanceof Expr\FuncCall) {
                $owner = $binding->declarationSpan->start->offset;
                $count = $this->counts[$owner] ?? null;
                if ($count === null || !isset($this->arrays[$count['array']])) {
                    return null;
                }
                $value = count($this->arrays[$count['array']]);
                $omissions[$owner][] = $count['name'];
            } else {
                // Preserve the emitted assertion for literal local declarations.
                if (!$assign->expr instanceof Scalar\Int_) {
                    return null;
                }
                $value = $assign->expr->value;
            }
            $locals[$name] = $value;
            if ($binding->mutability === BindingMutability::Mutable) {
                $mutable[$name] = true;
            }
        }

        $value = ScriptExpressionProof::resolve($return->expr, $locals + $this->outer, $this->arrays, $reads, $steps, 32);

        return is_int($value) && array_diff_key($locals, $reads) === [] ? $value : null;
    }

    /**
     * @param array<string, int> $locals
     * @param array<string, true> $mutable
     * @param array<string, true> $reads
     */
    private function checkLoop(Stmt\Foreach_ $loop, array &$locals, array $mutable, array &$reads, int &$steps): bool
    {
        if ($loop->byRef || $loop->keyVar !== null || !$loop->expr instanceof Expr\Variable || !is_string($loop->expr->name)
            || !$loop->valueVar instanceof Expr\Variable || !is_string($loop->valueVar->name)
            || !isset($this->arrays[$loop->expr->name])) {
            return false;
        }
        $name = $loop->valueVar->name;
        $binding = $this->findBinding($loop->valueVar);
        if ($binding === null || $binding->initializerExpression !== null || $binding->type->semanticType->canonical !== 'int'
            || in_array($name, ScriptExpressionProof::AMBIENT_VARIABLES, true)
            || isset($locals[$name]) || isset($this->outer[$name]) || isset($this->arrays[$name])) {
            return false;
        }

        $loopReads = [];
        foreach ($this->arrays[$loop->expr->name] as $item) {
            if (--$steps < 0 || !is_int($item)) {
                return false;
            }
            foreach ($loop->stmts as $statement) {
                if ($statement instanceof Stmt\If_) {
                    $condition = $statement->cond;
                    if ($statement->else !== null || $statement->elseifs !== [] || !$condition instanceof Expr\BinaryOp\Greater
                        || !$condition->left instanceof Expr\Variable || $condition->left->name !== $name
                        || !$condition->right instanceof Expr\Variable || !is_string($condition->right->name)
                        || !isset($locals[$condition->right->name])) {
                        return false;
                    }
                    $previous = $locals[$condition->right->name];
                    $loopReads[$name] = $loopReads[$condition->right->name] = true;
                    $proposed = $locals;
                    if (!$this->checkMutations($statement->stmts, $proposed, $mutable, [$name => $item], $loopReads, $steps)) {
                        return false;
                    }
                    if ($item > $previous) {
                        $locals = $proposed;
                    }
                } elseif (!$this->checkMutations([$statement], $locals, $mutable, [$name => $item], $loopReads, $steps)) {
                    return false;
                }
            }
        }

        if (!isset($loopReads[$name])) {
            return false;
        }
        unset($loopReads[$name]);
        $reads += $loopReads;

        return true;
    }

    /**
     * @param array<Stmt> $statements
     * @param array<string, int> $locals
     * @param array<string, true> $mutable
     * @param array<string, int> $iteration
     * @param array<string, true> $reads
     */
    private function checkMutations(
        array $statements,
        array &$locals,
        array $mutable,
        array $iteration,
        array &$reads,
        int &$steps,
    ): bool {
        foreach ($statements as $statement) {
            if (--$steps < 0) {
                return false;
            }
            if ($statement instanceof Stmt\Nop) {
                continue;
            }
            if (!$statement instanceof Stmt\Expression
                || (!$statement->expr instanceof Expr\Assign && !$statement->expr instanceof Expr\AssignOp\Plus)
                || !$statement->expr->var instanceof Expr\Variable || !is_string($statement->expr->var->name)) {
                return false;
            }
            $assign = $statement->expr;
            $name = $statement->expr->var->name;
            if (!isset($mutable[$name], $locals[$name])) {
                return false;
            }
            $value = ScriptExpressionProof::resolve($assign->expr, $iteration + $locals + $this->outer, $this->arrays, $reads, $steps, 32);
            if (!is_int($value)) {
                return false;
            }
            if ($assign instanceof Expr\AssignOp\Plus) {
                $reads[$name] = true;
                $sum = ScriptExpressionProof::addIntegers($locals[$name], $value);
                if ($sum === null) {
                    return false;
                }
                $value = $sum;
            }
            $locals[$name] = $value;
        }

        return true;
    }

    private function findBinding(Expr\Variable $variable): ?LocalBinding
    {
        $offset = $variable->getAttribute('ppphpOriginalStart');
        $binding = $this->bindings[is_int($offset) ? $offset : $variable->getStartFilePos()] ?? null;

        return is_string($variable->name) && $binding?->name === '$' . $variable->name ? $binding : null;
    }
}
