<?php

declare(strict_types=1);

namespace Tests\WhenDecisions;

use PhpParser\Node\Expr;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ExpressionTypeResolverExtension;
use PHPStan\Type\IntegerType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/** Experimental boundary probe, not a production type assertion or mapper. */
final readonly class CompletedValueExtension implements ExpressionTypeResolverExtension
{
    public function __construct(private string $path, private int $offset) {}

    public function getType(Expr $expr, Scope $scope): ?Type
    {
        if ($scope->getFile() !== $this->path || $expr->getStartFilePos() !== $this->offset
            || !$expr instanceof Expr\Variable || $expr->name !== 'pending') {
            return null;
        }

        return TypeCombinator::union(new IntegerType(), new StringType());
    }
}
