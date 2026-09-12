<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Transpilation;

use Atatusoft\Ppphp\Semantic\SemanticModel;
use Atatusoft\Ppphp\Semantic\Type\AtomicType;
use Atatusoft\Ppphp\Semantic\Type\Interfaces\Type;
use Atatusoft\Ppphp\Semantic\Type\TypedArrayType;
use Atatusoft\Ppphp\Semantic\When\WhenValueLifetime;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;

/** Proves value stability, not call-argument binding or referenceability. */
final class WhenOperandStability
{
    /** @param array<string, true> $scratchNames */
    public function __construct(
        private readonly array $scratchNames = [],
        private readonly ?SemanticModel $model = null,
        private readonly ?WhenLocalScope $localScope = null,
    ) {}

    /** @param list<Node> $intervening */
    public function canDelay(Expr $operand, array $intervening): bool
    {
        if ($operand instanceof Scalar\Int_ || $operand instanceof Scalar\Float_
            || $operand instanceof Scalar\String_ || $this->isLiteralConstant($operand)) {
            return true;
        }
        if (($operand instanceof Expr\UnaryMinus || $operand instanceof Expr\UnaryPlus)
            && ($operand->expr instanceof Scalar\Int_ || $operand->expr instanceof Scalar\Float_)) {
            return true;
        }
        if (!$operand instanceof Expr\Variable || !is_string($operand->name)) {
            return false;
        }
        if ($operand->name === 'this' || isset($this->scratchNames[$operand->name])) {
            return true;
        }

        $locals = $this->resolveIsolatedLocals($operand->name, $intervening);
        foreach ($intervening as $node) {
            if (!$this->preservesLocals($node, $locals)) {
                return false;
            }
        }

        return true;
    }

    private function isLiteralConstant(Expr $expression): bool
    {
        return $expression instanceof Expr\ConstFetch
            && in_array(strtolower($expression->name->toString()), ['true', 'false', 'null'], true);
    }

    /**
     * Fresh typed bindings cannot shadow visible bindings. With references,
     * calls and local-symbol-table access excluded by the traversal, writing
     * them cannot change the operand through another name.
     * @param list<Node> $intervening
     * @return array<string, Type>
     */
    private function resolveIsolatedLocals(string $operand, array $intervening): array
    {
        $locals = [];
        $scope = $this->localScope ?? ($this->model === null ? null : new WhenLocalScope($this->model));
        foreach ($this->model?->bindings->bindings ?? [] as $binding) {
            $name = ltrim($binding->name, '$');
            if ($name === $operand || !(new WhenValueLifetime())->resolveReleaseSafety($binding->type->semanticType)) {
                continue;
            }
            foreach ($intervening as $node) {
                $when = $node instanceof Expr ? $this->model?->whenExpressions->findPlaceholder($node) : null;
                $start = $when->syntax->span->start->offset ?? $node->getAttribute('ppphpOriginalStart', $node->getStartFilePos());
                $end = $when->syntax->span->end->offset ?? $node->getAttribute('ppphpOriginalEnd', $node->getEndFilePos() + 1);
                if (is_int($start) && is_int($end) && $start <= $binding->variableSpan->start->offset
                    && $binding->variableSpan->end->offset <= $end && $scope?->canIsolateBinding($binding->variableSpan)) {
                    $locals[$name] = $binding->type->semanticType;
                }
            }
        }
        return $locals;
    }

    /** @param array<string, Type> $locals */
    private function preservesLocals(Node $node, array $locals): bool
    {
        $when = $node instanceof Expr ? $this->model?->whenExpressions->findPlaceholder($node) : null;
        if ($when !== null) {
            foreach ($when->branches as $branch) {
                foreach ([...($branch->condition === null ? [] : [$branch->condition]), ...$branch->statements] as $part) {
                    if (!$this->preservesLocals($part, $locals)) {
                        return false;
                    }
                }
            }
            return true;
        }
        // A write through another name may reach this local through an alias.
        // Calls, hooks, casts and cleanup can also run code that mutates it.
        // BindingTable's explicit writes alone therefore cannot prove safety.
        if ($node instanceof Expr\Assign) {
            return $node->var instanceof Expr\Variable && is_string($node->var->name)
                && (isset($this->scratchNames[$node->var->name]) || isset($locals[$node->var->name]))
                && $this->preservesLocals($node->expr, $locals);
        }
        if ($node instanceof Stmt\Foreach_) {
            $type = $this->resolveType($node->expr, $locals);
            if ($node->byRef || !$type instanceof TypedArrayType
                || !(new WhenValueLifetime())->resolveReleaseSafety($type)) {
                return false;
            }
            foreach ([$node->keyVar, $node->valueVar] as $target) {
                if ($target !== null && (!$target instanceof Expr\Variable || !is_string($target->name)
                    || !isset($locals[$target->name]))) {
                    return false;
                }
            }
            return $this->preservesLocals($node->expr, $locals)
                && array_all($node->stmts, fn (Stmt $statement): bool => $this->preservesLocals($statement, $locals));
        }
        if ($node instanceof Expr\PreInc || $node instanceof Expr\PostInc
            || $node instanceof Expr\PreDec || $node instanceof Expr\PostDec) {
            return $node->var instanceof Expr\Variable && is_string($node->var->name)
                && isset($locals[$node->var->name])
                && in_array($locals[$node->var->name]->canonical, ['int', 'float'], true);
        }
        if ($node instanceof Expr\BinaryOp\Plus || $node instanceof Expr\BinaryOp\Minus || $node instanceof Expr\BinaryOp\Mul
            || $node instanceof Expr\BinaryOp\Smaller || $node instanceof Expr\BinaryOp\SmallerOrEqual
            || $node instanceof Expr\BinaryOp\Greater || $node instanceof Expr\BinaryOp\GreaterOrEqual) {
            return in_array($this->resolveType($node->left, $locals)?->canonical, ['int', 'float'], true)
                && in_array($this->resolveType($node->right, $locals)?->canonical, ['int', 'float'], true)
                && $this->preservesLocals($node->left, $locals) && $this->preservesLocals($node->right, $locals);
        }
        if ($node instanceof Expr\Variable) {
            return is_string($node->name);
        }
        if ($node instanceof Expr\ConstFetch) {
            return $this->isLiteralConstant($node);
        }
        if ($node instanceof Node\ArrayItem && ($node->byRef || $node->unpack)) {
            return false;
        }
        if (!$node instanceof Scalar\Int_ && !$node instanceof Scalar\Float_ && !$node instanceof Scalar\String_
            && !$node instanceof Expr\Array_ && !$node instanceof Node\ArrayItem
            && !$node instanceof Expr\BooleanNot && !$node instanceof Expr\Cast\Bool_
            && !$node instanceof Expr\BinaryOp\Identical && !$node instanceof Expr\BinaryOp\NotIdentical
            && !$node instanceof Expr\BinaryOp\BooleanAnd && !$node instanceof Expr\BinaryOp\BooleanOr
            && !$node instanceof Expr\BinaryOp\LogicalAnd && !$node instanceof Expr\BinaryOp\LogicalOr
            && !$node instanceof Stmt\If_ && !$node instanceof Stmt\ElseIf_ && !$node instanceof Stmt\Else_
            && !$node instanceof Stmt\For_ && !$node instanceof Stmt\While_ && !$node instanceof Stmt\Do_
            && !$node instanceof Stmt\Break_ && !$node instanceof Stmt\Continue_
            && !$node instanceof Stmt\Expression && !$node instanceof Stmt\Return_ && !$node instanceof Stmt\Nop) {
            return false;
        }
        foreach ($node->getSubNodeNames() as $name) {
            $children = is_array($node->{$name}) ? $node->{$name} : [$node->{$name}];
            foreach ($children as $child) {
                if ($child instanceof Node && !$this->preservesLocals($child, $locals)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param array<string, Type> $locals */
    private function resolveType(Expr $expression, array $locals): ?Type
    {
        if ($expression instanceof Scalar\Int_ || $expression instanceof Scalar\Float_) {
            return new AtomicType($expression instanceof Scalar\Int_ ? 'int' : 'float');
        }
        if ($expression instanceof Expr\Variable && is_string($expression->name) && isset($locals[$expression->name])) {
            return $locals[$expression->name];
        }
        return $this->model === null ? null
            : $this->model->expressionTypes->resolve($this->model->parsedFile->sourceFile, $expression)?->type;
    }
}
