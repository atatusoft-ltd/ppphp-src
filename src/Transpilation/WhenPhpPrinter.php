<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Transpilation;

use PhpParser\Node\Expr;
use PhpParser\PrettyPrinter\Standard;

/** Keeps nested conditional expressions visibly grouped. */
final class WhenPhpPrinter extends Standard
{
    protected function pExpr_Ternary(Expr\Ternary $node, int $precedence, int $lhsPrecedence): string
    {
        if (!$node->if instanceof Expr\Ternary) {
            return parent::pExpr_Ternary($node, $precedence, $lhsPrecedence);
        }

        return $this->pInfixOp(
            Expr\Ternary::class,
            $node->cond,
            ' ? (' . $this->p($node->if) . ') : ',
            $node->else,
            $precedence,
            $lhsPrecedence,
        );
    }
}
