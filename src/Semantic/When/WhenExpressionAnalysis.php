<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\When;

use Atatusoft\Ppphp\Frontend\Ast\WhenExpression;
use Atatusoft\Ppphp\Semantic\Type\LocalType;
use PhpParser\Node;
use PhpParser\Node\Expr;

final readonly class WhenExpressionAnalysis
{
    /** @param list<WhenBranchAnalysis> $branches */
    public function __construct(
        public WhenExpression $syntax,
        public WhenExpressionSite $site,
        public Expr $placeholder,
        public Node $statement,
        public array $branches,
        public LocalType $resultType,
        public string $temporaryName,
        public bool $resultIsFreshArray = false,
    ) {}
}
