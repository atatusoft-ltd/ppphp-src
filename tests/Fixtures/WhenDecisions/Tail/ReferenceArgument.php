<?php
declare(strict_types=1);
function replaceValue(int &$target, int $replacement): void { $target = $replacement; }
function invoke(int $value): int
{
    $__ppphp_when_prerequisite_0 = null;
    $__ppphp_when_154 = null;
    try {
        $__ppphp_when_prerequisite_0 =& $value;
        if (getenv('BRANCH') !== 'other') {
            $__ppphp_when_154 = 2;
        } else {
            $__ppphp_when_154 = 3;
        }
        replaceValue($__ppphp_when_prerequisite_0, $__ppphp_when_154);
    } finally {
        try {
            unset($__ppphp_when_prerequisite_0);
        } finally {
            unset($__ppphp_when_154);
        }
    }
    return $value;
}
echo invoke(1);
