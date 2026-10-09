<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\When;

use Atatusoft\Ppphp\Frontend\Ast\NodeId;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

final class WhenExpressionIndex
{
    /** @var array<string, WhenExpressionAnalysis> */
    private array $recordedExpressions = [];

    /** @var array<int, true> Loops with no exit except an owning result or termination. */
    private array $terminatingLoops = [];

    /** @var array<int, true> Try/catch bodies that cannot fall through, before their finally runs. */
    private array $terminatingTryBodies = [];

    /** @var array<int, array{canComplete: bool, producesResult: bool, hasTransfers: bool}> */
    private array $protectedResultFlows = [];

    /** @var array<int, array<int, WhenDeferredTransfer>> */
    private array $deferredTransfers = [];

    /** @var array<int, true> Returns whose operand terminates without producing a value. */
    private array $terminatingResults = [];

    public function recordDeferredTransfer(WhenDeferredTransfer $transfer): void
    {
        $this->deferredTransfers[$transfer->barrierOffset][$transfer->sourceOffset] = $transfer;
    }

    /** @return array<int, WhenDeferredTransfer> */
    public function resolveDeferredTransfers(Stmt\TryCatch $statement): array
    {
        $offset = $statement->getAttribute('ppphpOriginalStart');
        if (!is_int($offset) || ($this->deferredTransfers[$offset] ?? []) === []) {
            return [];
        }
        $transfers = $this->deferredTransfers[$offset];
        foreach ($this->deferredTransfers as $barrier => $candidates) {
            if ($barrier < $offset) {
                // All candidates are lexical ancestors of their transfer;
                // the earliest surviving barrier is therefore the outermost.
                $transfers = array_diff_key($transfers, $candidates);
            }
        }
        return $transfers;
    }

    public function recordTerminatingResult(int $originalOffset): void
    {
        $this->terminatingResults[$originalOffset] = true;
    }

    public function resolveResultTermination(Stmt\Return_ $statement): bool
    {
        $offset = $statement->getAttribute('ppphpOriginalStart');
        return is_int($offset) && isset($this->terminatingResults[$offset]);
    }

    /** @param list<int> $sourceOffsets */
    public function retainDeferredTransfers(int $originalOffset, array $sourceOffsets): void
    {
        // Inner cleanup may always cancel a syntactically crossing transfer.
        // Such a transfer stays native: no continuation can observe it here.
        if (isset($this->deferredTransfers[$originalOffset])) {
            $this->deferredTransfers[$originalOffset] = array_intersect_key(
                $this->deferredTransfers[$originalOffset], array_fill_keys($sourceOffsets, true),
            );
        }
    }

    public function recordProtectedResultFlow(int $originalOffset, bool $canComplete, bool $producesResult, bool $hasTransfers = false): void
    {
        $this->protectedResultFlows[$originalOffset] = ['canComplete' => $canComplete, 'producesResult' => $producesResult, 'hasTransfers' => $hasTransfers];
    }

    /** @return array{canComplete: bool, producesResult: bool, hasTransfers: bool}|null */
    public function resolveProtectedResultFlow(Stmt\TryCatch|Stmt\Foreach_ $statement): ?array
    {
        $offset = $statement->getAttribute('ppphpOriginalStart');
        return is_int($offset) ? ($this->protectedResultFlows[$offset] ?? null) : null;
    }

    public function recordTerminatingTryBody(int $originalOffset): void
    {
        $this->terminatingTryBodies[$originalOffset] = true;
    }

    public function resolveTryBodyTermination(Stmt\TryCatch $statement): bool
    {
        $offset = $statement->getAttribute('ppphpOriginalStart');
        return is_int($offset) && isset($this->terminatingTryBodies[$offset]);
    }

    public function recordTerminatingLoop(int $originalOffset): void
    {
        $this->terminatingLoops[$originalOffset] = true;
    }

    public function resolveLoopTermination(Stmt $loop): bool
    {
        $offset = $loop->getAttribute('ppphpOriginalStart');
        return is_int($offset) && isset($this->terminatingLoops[$offset]);
    }

    public function containsResult(Node $node, bool $completingOnly = false): bool
    {
        if ($completingOnly && $node instanceof Stmt\TryCatch) {
            $flow = $this->resolveProtectedResultFlow($node);
            if ($flow !== null) {
                return $flow['producesResult'];
            }
        }
        if ($node instanceof Stmt\Return_) {
            return !$completingOnly || !$this->resolveResultTermination($node);
        }
        if ($node instanceof Node\FunctionLike || $node instanceof Stmt\ClassLike) {
            return false;
        }
        foreach ($node->getSubNodeNames() as $name) {
            foreach (is_array($node->$name) ? $node->$name : [$node->$name] as $child) {
                if ($child instanceof Node && $this->containsResult($child, $completingOnly)) {
                    return true;
                }
            }
        }
        return false;
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
