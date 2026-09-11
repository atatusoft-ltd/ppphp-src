<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Transpilation;

use Atatusoft\Ppphp\Semantic\When\WhenExpressionIndex;
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
        private readonly ?WhenExpressionIndex $whenExpressions = null,
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

        foreach ($intervening as $node) {
            if (!$this->preservesLocals($node)) {
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

    private function preservesLocals(Node $node): bool
    {
        $when = $node instanceof Expr ? $this->whenExpressions?->findPlaceholder($node) : null;
        if ($when !== null) {
            foreach ($when->branches as $branch) {
                foreach ([...($branch->condition === null ? [] : [$branch->condition]), ...$branch->statements] as $part) {
                    if (!$this->preservesLocals($part)) {
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
                && isset($this->scratchNames[$node->var->name])
                && $this->preservesLocals($node->expr);
        }
        if ($node instanceof Expr\Variable) {
            return is_string($node->name);
        }
        if ($node instanceof Expr\ConstFetch) {
            return $this->isLiteralConstant($node);
        }
        if (!$node instanceof Scalar\Int_ && !$node instanceof Scalar\Float_ && !$node instanceof Scalar\String_
            && !$node instanceof Expr\BooleanNot && !$node instanceof Expr\Cast\Bool_
            && !$node instanceof Expr\BinaryOp\Identical && !$node instanceof Expr\BinaryOp\NotIdentical
            && !$node instanceof Expr\BinaryOp\BooleanAnd && !$node instanceof Expr\BinaryOp\BooleanOr
            && !$node instanceof Expr\BinaryOp\LogicalAnd && !$node instanceof Expr\BinaryOp\LogicalOr
            && !$node instanceof Stmt\If_ && !$node instanceof Stmt\ElseIf_ && !$node instanceof Stmt\Else_
            && !$node instanceof Stmt\Expression && !$node instanceof Stmt\Return_ && !$node instanceof Stmt\Nop) {
            return false;
        }
        foreach ($node->getSubNodeNames() as $name) {
            $children = is_array($node->{$name}) ? $node->{$name} : [$node->{$name}];
            foreach ($children as $child) {
                if ($child instanceof Node && !$this->preservesLocals($child)) {
                    return false;
                }
            }
        }

        return true;
    }
}
