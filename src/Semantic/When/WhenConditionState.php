<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\When;

use Atatusoft\Ppphp\Semantic\Scope\Scope;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;

/** Conservative boolean facts, with entry requirements kept across effects. */
final readonly class WhenConditionState
{
    /** @param array<string, bool> $truths
     * @param array<string, bool> $entry
     */
    public function __construct(
        public array $truths = [],
        public array $entry = [],
        public bool $preservesEntry = true,
        public bool $reachable = true,
    ) {}

    public function forget(): self
    {
        return new self([], $this->entry, false, $this->reachable);
    }

    public function advance(Node $node): self
    {
        return $this->preservesTruths($node) ? $this : $this->forget();
    }

    public function assume(Expr $condition, bool $positive, Scope $scope): self
    {
        if (!$this->preservesTruths($condition)) {
            return $this;
        }
        if ($condition instanceof Expr\BooleanNot) {
            return $this->assume($condition->expr, !$positive, $scope);
        }
        if ($condition instanceof Expr\BinaryOp\BooleanAnd && $positive) {
            return $this->assume($condition->left, true, $scope)->assume($condition->right, true, $scope);
        }
        if ($condition instanceof Expr\BinaryOp\BooleanOr && !$positive) {
            return $this->assume($condition->left, false, $scope)->assume($condition->right, false, $scope);
        }
        if ($condition instanceof Expr\BinaryOp\Identical || $condition instanceof Expr\BinaryOp\NotIdentical) {
            foreach ([[$condition->left, $condition->right], [$condition->right, $condition->left]] as [$operand, $literal]) {
                if (!$literal instanceof Expr\ConstFetch || !in_array(strtolower($literal->name->toString()), ['true', 'false'], true)) {
                    continue;
                }
                $value = strtolower($literal->name->toString()) === 'true';
                $same = $condition instanceof Expr\BinaryOp\Identical ? $positive : !$positive;
                return $this->assume($operand, $same ? $value : !$value, $scope);
            }
        }
        if (!$condition instanceof Expr\Variable || !is_string($condition->name)) {
            return $this;
        }
        $name = '$' . $condition->name;
        if (!in_array($scope->resolve($name)?->type->canonical, ['bool', 'true', 'false'], true)) {
            return $this;
        }
        $reachable = $this->reachable && (!isset($this->truths[$name]) || $this->truths[$name] === $positive);
        $truths = $this->truths;
        $entry = $this->entry;
        $truths[$name] = $positive;
        if ($this->preservesEntry) {
            $entry[$name] = $positive;
        }
        return new self($truths, $entry, $this->preservesEntry, $reachable);
    }

    /** Whether this successful path can enter the later path. */
    public function acceptsEntry(self $later): bool
    {
        foreach ($later->entry as $name => $truth) {
            if (isset($this->truths[$name]) && $this->truths[$name] !== $truth) {
                return false;
            }
        }
        return $this->reachable && $later->reachable;
    }

    /** Apply a separately analysed region without confusing its entry with ours. */
    public function continueWith(self $later): self
    {
        return new self(
            $later->truths + ($later->preservesEntry ? $this->truths : []),
            $this->entry + ($this->preservesEntry ? $later->entry : []),
            $this->preservesEntry && $later->preservesEntry,
            $this->acceptsEntry($later),
        );
    }

    /** Joining keeps only shared facts; it never enumerates path combinations.
     * @param list<self> $states
     */
    public static function join(array $states): self
    {
        $states = array_values(array_filter($states, static fn (self $state): bool => $state->reachable));
        if ($states === []) {
            return new self(reachable: false);
        }
        $first = $states[0];
        $truths = $first->truths;
        $entry = $first->entry;
        $preserves = $first->preservesEntry;
        foreach (array_slice($states, 1) as $state) {
            $truths = array_filter($truths, static fn (bool $truth, string $name): bool =>
                isset($state->truths[$name]) && $state->truths[$name] === $truth, ARRAY_FILTER_USE_BOTH);
            $entry = array_filter($entry, static fn (bool $truth, string $name): bool =>
                isset($state->entry[$name]) && $state->entry[$name] === $truth, ARRAY_FILTER_USE_BOTH);
            $preserves = $preserves && $state->preservesEntry;
        }
        return new self($truths, $entry, $preserves);
    }

    private function preservesTruths(Node $node): bool
    {
        if ($node instanceof Expr\Variable) {
            return is_string($node->name);
        }
        if ($node instanceof Expr\ConstFetch) {
            return in_array(strtolower($node->name->toString()), ['true', 'false', 'null'], true);
        }
        // Assignments may write an alias; calls, output handlers, properties,
        // operators and cleanup may execute code. Forget before using a later
        // condition as evidence about a value evaluated earlier.
        if (!$node instanceof Scalar\Int_ && !$node instanceof Scalar\Float_ && !$node instanceof Scalar\String_
            && !$node instanceof Expr\BooleanNot && !$node instanceof Expr\BinaryOp\BooleanAnd
            && !$node instanceof Expr\BinaryOp\BooleanOr && !$node instanceof Expr\BinaryOp\Identical
            && !$node instanceof Expr\BinaryOp\NotIdentical && !$node instanceof Stmt\Expression
            && !$node instanceof Stmt\Nop) {
            return false;
        }
        foreach ($node->getSubNodeNames() as $name) {
            foreach (is_array($node->$name) ? $node->$name : [$node->$name] as $child) {
                if ($child instanceof Node && !$this->preservesTruths($child)) {
                    return false;
                }
            }
        }
        return true;
    }
}
