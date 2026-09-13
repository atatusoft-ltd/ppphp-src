<?php
declare(strict_types=1);
function shipping(bool $express): int
{
    /** @var int $shipping */
    $shipping = $express ? 1200 : 500;
    return $shipping;
}
function choose(int $condition): int
{
    return $condition ? 1 : 2;
}
echo shipping(true), '|', shipping(false), '|', choose(0), choose(2), choose(-1);
