<?php
declare(strict_types=1);
function middle(bool $first, bool $second): int
{
    return $first ? ($second ? 1 : 2) : 3;
}
function last(bool $first, bool $second): int
{
    return $first ? 4 : ($second ? 5 : 6);
}
echo middle(true, true), middle(true, false), middle(false, false), '|';
echo last(true, true), last(false, true), last(false, false);
