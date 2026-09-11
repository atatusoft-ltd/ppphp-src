<?php
declare(strict_types=1);
function selectGuardedValue(int $x): int
{
    if ($x >= 0) {
        switch ($x) {
            case 2:
                switch (getenv('INNER_CASE')) {
                    case 'a':
                        if (getenv('LEAVE_CASE') === 'yes') {
                            break;
                        }
                        $value = 20;
                        break 2;
                    default:
                        $value = 30;
                        break 2;
                }
            case 1:
                $value = 10;
                break;
            default:
                $value = 0;
                break;
        }
    } else {
        $value = -1;
    }
    return $value;
}
echo selectGuardedValue(2), '|', selectGuardedValue(1), '|', selectGuardedValue(0), '|', selectGuardedValue(-1);
