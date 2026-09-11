<?php
declare(strict_types=1);
function selectValue(int $x): int
{
    if ($x >= 0) {
        switch ($x) {
            case 2:
                switch (getenv('INNER_CASE')) {
                    case 'a':
                        break;
                    default:
                        $value = 20;
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
echo selectValue(2), '|', selectValue(1), '|', selectValue(0), '|', selectValue(-1);
