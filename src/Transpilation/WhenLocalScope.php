<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Transpilation;

use Atatusoft\Ppphp\Semantic\SemanticModel;
use Atatusoft\Ppphp\Semantic\SourceNameResolver;
use Atatusoft\Ppphp\Source\Span;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/** Shared lexical evidence for unobservable seeds and genuinely fresh bindings. */
final class WhenLocalScope
{
    /** @var list<Span> */
    private array $callables = [];
    /** @var list<Span> */
    private array $observingRegions = [];
    /** @var array<int, true> */
    private array $localAccess = [];
    /** @var array<int, true> */
    private array $gotos = [];

    public function __construct(SemanticModel $model)
    {
        $nodes = $model->parsedFile->statements;
        // Normalized placeholders do not contain their parsed fragments.
        foreach ($model->whenExpressions->expressions as $analysis) {
            foreach ($analysis->branches as $branch) {
                if ($branch->condition !== null) {
                    $nodes[] = $branch->condition;
                }
                array_push($nodes, ...$branch->statements);
            }
        }
        $hazards = [];
        $names = new SourceNameResolver();
        foreach ((new NodeFinder())->find($nodes, static fn (Node $node): bool =>
            $node instanceof Node\FunctionLike || $node instanceof Stmt\For_ || $node instanceof Stmt\Foreach_
            || $node instanceof Stmt\While_ || $node instanceof Stmt\Do_ || $node instanceof Stmt\TryCatch
            || $node instanceof Stmt\Goto_ || $node instanceof Expr\Include_ || $node instanceof Expr\Eval_
            || $node instanceof Expr\FuncCall) as $node) {
            $start = $node->getAttribute('ppphpOriginalStart', $node->getStartFilePos());
            $end = $node->getAttribute('ppphpOriginalEnd', $node->getEndFilePos() + 1);
            if (!is_int($start) || !is_int($end)) {
                throw new \LogicException('A source scope requires original offsets.');
            }
            $span = $model->parsedFile->sourceFile->createSpan($start, $end);
            if ($node instanceof Node\FunctionLike) {
                $this->callables[] = $span;
            } elseif ($node instanceof Stmt\For_ || $node instanceof Stmt\Foreach_
                || $node instanceof Stmt\While_ || $node instanceof Stmt\Do_ || $node instanceof Stmt\TryCatch) {
                $this->observingRegions[] = $span;
            } else {
                if ($node instanceof Expr\FuncCall && $node->name instanceof Name) {
                    $resolved = $names->resolve($model->parsedFile, $node->name->toCodeString(), $start);
                    $parts = explode('\\', strtolower($resolved));
                    // Include imported names and unqualified builtin fallback.
                    if (!in_array(end($parts), ['get_defined_vars', 'compact', 'extract'], true)) {
                        continue;
                    }
                }
                $hazards[] = [$span, $node instanceof Stmt\Goto_];
            }
        }
        usort($this->callables, static fn (Span $left, Span $right): int => $right->start->offset <=> $left->start->offset);
        foreach ($hazards as [$span, $goto]) {
            $frame = $this->findCallable($span)?->start->offset ?? -1;
            if ($goto) {
                $this->gotos[$frame] = true;
            } else {
                $this->localAccess[$frame] = true;
            }
        }
    }

    public function canIsolateBinding(Span $target): bool
    {
        $callable = $this->findCallable($target);
        // An including PHP file supplies file-scope locals; dynamic local
        // access can likewise create aliases absent from the binding table.
        return $callable !== null && !isset($this->localAccess[$callable->start->offset]);
    }

    public function canSeedLocal(Span $target): bool
    {
        $callable = $this->findCallable($target);
        return $callable !== null && $this->canIsolateBinding($target)
            && !isset($this->gotos[$callable->start->offset])
            && !array_any($this->observingRegions, fn (Span $region): bool =>
                $region->start->offset >= $callable->start->offset && $this->contains($region, $target));
    }

    private function findCallable(Span $target): ?Span
    {
        return array_find($this->callables, fn (Span $callable): bool => $this->contains($callable, $target));
    }

    private function contains(Span $owner, Span $target): bool
    {
        return $owner->start->offset <= $target->start->offset && $target->end->offset <= $owner->end->offset;
    }
}
