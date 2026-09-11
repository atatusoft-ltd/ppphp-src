<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\When;

use Atatusoft\Ppphp\Frontend\Ast\NodeId;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

final class WhenExpressionIndex
{
    /** @var array<string, WhenExpressionAnalysis> */
    private array $recordedExpressions = [];

    /** @var array<int, true> Loops with no exit except an owning result or termination. */
    private array $terminatingLoops = [];

    public function recordTerminatingLoop(int $originalOffset): void
    {
        $this->terminatingLoops[$originalOffset] = true;
    }

    public function resolveLoopTermination(Stmt $loop): bool
    {
        $offset = $loop->getAttribute('ppphpOriginalStart');
        return is_int($offset) && isset($this->terminatingLoops[$offset]);
    }

    public function record(WhenExpressionAnalysis $analysis): void
    {
        $this->recordedExpressions[$analysis->syntax->id->value] = $analysis;
    }

    public function find(NodeId|string $id): ?WhenExpressionAnalysis
    {
        $key = $id instanceof NodeId ? $id->value : $id;

        return $this->recordedExpressions[$key] ?? null;
    }

    public function findPlaceholder(Expr $expression): ?WhenExpressionAnalysis
    {
        $id = $expression->getAttribute('ppphpWhenExpressionId');

        return is_string($id) ? $this->find($id) : null;
    }

    public function resolveArrayFreshness(?Expr $expression): bool
    {
        return $expression instanceof Expr\Array_
            || ($expression !== null && $this->findPlaceholder($expression)?->resultIsFreshArray === true);
    }

    /** @var list<WhenExpressionAnalysis> */
    public array $expressions {
        get => array_values($this->recordedExpressions);
    }
}
