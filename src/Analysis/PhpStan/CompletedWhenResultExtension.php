<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Type\ExpressionTypeResolverExtension;
use PHPStan\Type\NeverType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/** Removes only a proven impossible pending alternative at a completed read.
 * @implements Rule<Expr\Assign>
 */
final class CompletedWhenResultExtension implements Rule, ExpressionTypeResolverExtension
{
    /** @var array<string, array<int, true>> */
    private array $definitions = [];
    /** @var array<string, array<int, bool>> */
    private array $nonNull = [];

    /** @param array<string, array<int, array{name: string, definitions: list<int>}>> $results */
    public function __construct(private readonly array $results)
    {
        foreach ($results as $path => $reads) {
            foreach ($reads as $fact) {
                foreach ($fact['definitions'] as $offset) {
                    $this->definitions[$path][$offset] = true;
                }
            }
        }
    }

    public function getNodeType(): string
    {
        return Expr\Assign::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $path = $scope->getFile();
        $offset = $node->getStartFilePos();
        if (isset($this->definitions[$path][$offset])) {
            // Evidence is monotonic across repeated visits and loop contexts:
            // one unknown/nullable contribution prevents this correction.
            $this->nonNull[$path][$offset] = ($this->nonNull[$path][$offset] ?? true)
                && $scope->getType($node->expr)->isNull()->no();
        }
        return [];
    }

    public function getType(Expr $expr, Scope $scope): ?Type
    {
        if (!$expr instanceof Expr\Variable || !is_string($expr->name)) {
            return null;
        }
        $path = $scope->getFile();
        $fact = $this->results[$path][$expr->getStartFilePos()] ?? null;
        if ($fact === null || $fact['name'] !== $expr->name || $fact['definitions'] === []) {
            return null;
        }
        foreach ($fact['definitions'] as $offset) {
            if (($this->nonNull[$path][$offset] ?? false) !== true) {
                return null;
            }
        }
        // Use current backend state, not collected RHS types: references or
        // subsequent refinements may have changed a container's element type.
        $type = TypeCombinator::removeNull($scope->getVariableType($expr->name));
        return $type instanceof NeverType ? null : $type;
    }
}
