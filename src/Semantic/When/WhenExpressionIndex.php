<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\When;

use Atatusoft\Ppphp\Frontend\Ast\NodeId;
use PhpParser\Node\Expr;

final class WhenExpressionIndex
{
    /** @var array<string, WhenExpressionAnalysis> */
    private array $recordedExpressions = [];

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
