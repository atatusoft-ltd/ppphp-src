<?php
declare(strict_types=1);
function choose(int $x, int $y): int
{
    if ($x >= 0) {
        switch ($x) {
            case 1:
                switch ($y) {
                    case 1:
                        $value = 11;
                        break;
                    default:
                        $value = 12;
                        break;
                }
                break;
            case 2:
                if ($y > 0) {
                    $value = 21;
                } else {
                    throw new Error('negative');
                }
                break;
            default:
                $value = 99;
                break;
        }
    } else {
        $value = 0;
    }
    return $value;
}
echo choose(1, 1), '|', choose(1, 2), '|', choose(2, 1), '|', choose(0, 0), '|', choose(-1, 0), '|';
try { echo choose(2, 0); } catch (Error) { echo 'error'; }
