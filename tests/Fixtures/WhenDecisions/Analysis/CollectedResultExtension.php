<?php

declare(strict_types=1);

namespace Tests\WhenDecisions;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Type\ExpressionTypeResolverExtension;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/** Rejected whole-type override: a negative control for lost later refinements.
 * @implements Rule<Expr\Assign>
 */
final class CollectedResultExtension implements Rule, ExpressionTypeResolverExtension
{
    /** @var array<int, Type> */
    private array $types = [];

    /** @param list<int> $definitions */
    public function __construct(private string $path, private array $definitions, private int $read) {}

    public function getNodeType(): string
    {
        return Expr\Assign::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $offset = $node->getStartFilePos();
        if ($scope->getFile() === $this->path && in_array($offset, $this->definitions, true)) {
            $type = $scope->getType($node->expr);
            $this->types[$offset] = isset($this->types[$offset]) ? TypeCombinator::union($this->types[$offset], $type) : $type;
        }
        return [];
    }

    public function getType(Expr $expr, Scope $scope): ?Type
    {
        if ($scope->getFile() !== $this->path || $expr->getStartFilePos() !== $this->read
            || !$expr instanceof Expr\Variable || count($this->types) !== count($this->definitions)) {
            return null;
        }
        return TypeCombinator::union(...array_values($this->types));
    }
}
