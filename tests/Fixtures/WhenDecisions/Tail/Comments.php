<?php
declare(strict_types=1);
function message(bool $ready): string
{
    // Explain the whole expression.
    /* Keep the block comment too. */
    /** Preserve this documentation. */
    if ($ready) {
        // This comment belongs inside the branch.
        /** @var string $detail */
        $detail = 'ready';
        $message = $detail;
    } else {
        $message = 'waiting';
    }
    return $message;
}
function bare(bool $ready): int
{
    // Explain this initializer.
    /** Keep the authored explanation beside the generated type. */
    /** @var int $value */
    $value = $ready ? 1 : 2;
    return $value;
}
echo message(true), '|', message(false), '|', bare(true), bare(false);
