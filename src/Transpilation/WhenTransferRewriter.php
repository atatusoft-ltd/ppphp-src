<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Transpilation;

use Atatusoft\Ppphp\Semantic\When\WhenDeferredTransfer;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/** Defer source transfers without introducing a loop or crossing cleanup. */
final class WhenTransferRewriter
{
    /** @var array<int, int> */
    private array $codes = [];

    /** @var array<int, WhenDeferredTransfer> */
    public private(set) array $resumptions = [];

    public private(set) bool $requiresDiscriminator = false;

    /** @param array<int, WhenDeferredTransfer> $transfers */
    public function __construct(private readonly array $transfers, private readonly string $flag)
    {
        $targets = [];
        foreach ($transfers as $offset => $transfer) {
            $key = $transfer->targetOffset . ':' . (int) $transfer->continues;
            $code = $targets[$key] ??= count($targets) + 1;
            $this->codes[$offset] = $code;
            $this->resumptions[$code] = $transfer;
        }
    }

    public function rewrite(Stmt\TryCatch $statement, bool $alwaysResumes): void
    {
        $this->rewriteStatement($statement);
        $this->requiresDiscriminator = $this->requiresDiscriminator || !$alwaysResumes || count($this->resumptions) > 1;
        if (!$this->requiresDiscriminator) {
            // No continuation reads this state, and the sole transfer is the
            // unconditional alternative to a result. Keep authored comments
            // at the transfer site, but omit writes with no possible reader.
            (new NodeTraverser(new class($this->flag) extends NodeVisitorAbstract {
                public function __construct(private readonly string $flag) {}

                public function leaveNode(Node $node): ?Node
                {
                    if ($node instanceof Stmt\Expression && $node->expr instanceof Expr\Assign
                        && $node->expr->var instanceof Expr\Variable && $node->expr->var->name === $this->flag) {
                        return new Stmt\Nop($node->getAttributes());
                    }
                    return null;
                }
            }))->traverse([$statement]);
        }
        $statement->setAttribute('ppphpDeferredTransfersPrepared', true);
    }

    /** @return array<array-key, mixed> */
    public static function resolveScopes(Stmt $statement): array
    {
        $scopes = $statement->getAttribute('ppphpDeferredTransferScopes', []);
        if (!is_array($scopes)) {
            throw new \LogicException('Deferred transfer scopes must be an array.');
        }
        return $scopes;
    }

    /** @param list<Stmt> $statements
     * @return array{list<Stmt>, bool}
     */
    private function rewriteStatements(array $statements): array
    {
        $result = $group = [];
        $afterTransfer = false;
        foreach ($statements as $statement) {
            [$replacement, $transfers] = $this->rewriteStatement($statement);
            if (!$afterTransfer) {
                array_push($result, ...$replacement);
            } else {
                array_push($group, ...$replacement);
                if ($transfers) {
                    $result[] = $this->guard($group);
                    $group = [];
                }
            }
            $afterTransfer = $afterTransfer || $transfers;
        }
        if ($group !== []) {
            $result[] = $this->guard($group);
        }
        return [$result, $afterTransfer];
    }

    /** @return array{non-empty-list<Stmt>, bool} */
    private function rewriteStatement(Stmt $statement): array
    {
        $offset = $statement->getAttribute('ppphpOriginalStart');
        if (($statement instanceof Stmt\Break_ || $statement instanceof Stmt\Continue_)
            && is_int($offset) && isset($this->transfers[$offset])) {
            $transfer = $this->transfers[$offset];
            $replacement = [new Stmt\Expression(new Expr\Assign(
                new Expr\Variable($this->flag), new Scalar\Int_($this->codes[$offset]),
            ), $statement->getAttributes())];
            if ($transfer->exitedLevels > 0) {
                $replacement[] = new Stmt\Break_($transfer->exitedLevels === 1 ? null : new Scalar\Int_($transfer->exitedLevels));
            }
            return [$replacement, true];
        }
        $transfers = false;
        if ($statement instanceof Stmt\TryCatch) {
            [$statement->stmts, $bodyTransfers] = $this->rewriteStatements(array_values($statement->stmts));
            $transfers = $bodyTransfers;
            foreach ($statement->catches as $catch) {
                [$catch->stmts, $catchTransfers] = $this->rewriteStatements(array_values($catch->stmts));
                if ($bodyTransfers) {
                    // A handled failure cancels the transfer from this try's
                    // body. A catch inside finally must not cancel one that
                    // was already pending on entry to that finally.
                    array_unshift($catch->stmts, new Stmt\Expression(new Expr\Assign(
                        new Expr\Variable($this->flag), new Scalar\Int_(0),
                    )));
                }
                $transfers = $transfers || $catchTransfers;
            }
            // Finally always runs. Any valid transfer inside it has its own
            // target and deferral scope wholly inside that finally.
        } elseif ($statement instanceof Stmt\If_) {
            foreach ([$statement, ...$statement->elseifs, ...($statement->else === null ? [] : [$statement->else])] as $arm) {
                [$arm->stmts, $armTransfers] = $this->rewriteStatements(array_values($arm->stmts));
                $transfers = $transfers || $armTransfers;
            }
        } elseif ($statement instanceof Stmt\Switch_) {
            foreach ($statement->cases as $case) {
                [$case->stmts, $caseTransfers] = $this->rewriteStatements(array_values($case->stmts));
                $transfers = $transfers || $caseTransfers;
            }
        } elseif ($statement instanceof Stmt\Declare_ && $statement->stmts !== null) {
            [$statement->stmts, $transfers] = $this->rewriteStatements(array_values($statement->stmts));
        } elseif ($statement instanceof Stmt\For_ || $statement instanceof Stmt\Foreach_
            || $statement instanceof Stmt\While_ || $statement instanceof Stmt\Do_) {
            [$statement->stmts, $transfers] = $this->rewriteStatements(array_values($statement->stmts));
        }
        if ($transfers && ($statement instanceof Stmt\TryCatch || $statement instanceof Stmt\Foreach_)) {
            // Value handoffs need to know which outer scope resumes a
            // transfer even when that transfer needs no runtime discriminator.
            $scopes = self::resolveScopes($statement);
            $scopes[$this->flag] = true;
            $statement->setAttribute('ppphpDeferredTransferScopes', $scopes);
        }
        return [[$statement], $transfers];
    }

    /** @param non-empty-list<Stmt> $statements */
    private function guard(array $statements): Stmt\If_
    {
        $this->requiresDiscriminator = true;
        $pending = new Expr\BinaryOp\Identical(new Expr\Variable($this->flag), new Scalar\Int_(0));
        if (count($statements) === 1 && $statements[0] instanceof Stmt\If_
            && $statements[0]->else === null && $statements[0]->elseifs === []) {
            $statements[0]->cond = new Expr\BinaryOp\BooleanAnd($pending, $statements[0]->cond);
            return $statements[0];
        }
        return new Stmt\If_($pending, ['stmts' => $statements]);
    }
}
