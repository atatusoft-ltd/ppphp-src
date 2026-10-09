<?php
declare(strict_types=1);
function replaceValue(int &$target, int $replacement): void { $target = $replacement; }
function invoke(int $value): int
{
    replaceValue($value, getenv('BRANCH') !== 'other' ? 2 : 3);
    return $value;
}
echo invoke(1);
