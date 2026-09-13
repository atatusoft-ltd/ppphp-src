<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\When;

use Atatusoft\Ppphp\Frontend\Ast\WhenExpression;
use Atatusoft\Ppphp\Frontend\Ast\WhenElseBranch;
use Atatusoft\Ppphp\Semantic\Type\LocalType;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

final class WhenExpressionAnalysis
{
    /** @param list<WhenBranchAnalysis> $branches */
    public function __construct(
        public readonly WhenExpression $syntax,
        public readonly WhenExpressionSite $site,
        public readonly Expr $placeholder,
        public readonly Node $statement,
        public readonly array $branches,
        public readonly LocalType $resultType,
        public readonly string $temporaryName,
        public readonly bool $resultIsFreshArray = false,
    ) {}

    /** @var array{Expr, Expr, Expr}|null */
    public ?array $ternaryOperands {
        get {
            if (count($this->branches) !== 2) {
                return null;
            }
            [$first, $last] = $this->branches;
            if ($first->condition === null || !$last->syntax instanceof WhenElseBranch
                || count($first->statements) !== 1 || count($last->statements) !== 1) {
                return null;
            }
            $if = $first->statements[0];
            $else = $last->statements[0];
            if (!$if instanceof Stmt\Return_ || !$else instanceof Stmt\Return_
                || $if->expr === null || $else->expr === null
                // Statement comments can include branch-local assertions.
                // Their context must survive the expression simplification.
                || $if->getComments() !== [] || $else->getComments() !== []) {
                return null;
            }

            return [$first->condition, $if->expr, $else->expr];
        }
    }
}
