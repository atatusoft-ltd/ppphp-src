<?php

declare(strict_types=1);

namespace Tests\WhenDecisions;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Rules\RuleLevelHelper;
use PHPStan\Type\IntegerType;

/** @implements Rule<Stmt\Expression> */
final readonly class InitializerContractRule implements Rule
{
    public function __construct(private string $path, private int $offset, private RuleLevelHelper $ruleLevelHelper) {}

    public function getNodeType(): string
    {
        return Stmt\Expression::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if ($scope->getFile() !== $this->path || $node->getStartFilePos() !== $this->offset
            || !$node->expr instanceof Expr\Assign) {
            return [];
        }
        // The experiment supplies one int storage contract. Ask for the RHS
        // at the statement boundary, before @var asserts the destination type.
        return $this->ruleLevelHelper->accepts(new IntegerType(), $scope->getType($node->expr->expr), true)->result ? [] : [
            RuleErrorBuilder::message('The initializer does not satisfy its storage contract.')
                ->identifier('ppphp.initializerType')->build(),
        ];
    }
}
