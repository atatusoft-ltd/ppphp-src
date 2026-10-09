<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Transpilation;

use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\PrettyPrinter\Standard;

/** Preserves authored comments and visibly groups nested conditionals. */
final class WhenPhpPrinter extends Standard
{
    /** @var \WeakMap<Comment, true> */
    private \WeakMap $printedComments;

    protected function resetState(): void
    {
        parent::resetState();
        $this->printedComments = new \WeakMap();
    }

    protected function pComments(array $comments): string
    {
        $remaining = [];
        foreach ($comments as $comment) {
            if (!isset($this->printedComments[$comment])) {
                $remaining[] = $comment;
                $this->printedComments[$comment] = true;
            }
        }
        return parent::pComments($remaining);
    }

    protected function p(
        Node $node, int $precedence = self::MAX_PRECEDENCE, int $lhsPrecedence = self::MAX_PRECEDENCE,
        bool $parentFormatPreserved = false,
    ): string {
        // The base printer handles statement and multiline-list comments,
        // but omits comments on other nodes (including loop bindings). Print
        // each comment at its owning node unless its container already did.
        $comments = $this->pComments($node->getComments());
        return ($comments === '' ? '' : $comments . $this->nl)
            . parent::p($node, $precedence, $lhsPrecedence, $parentFormatPreserved);
    }

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
