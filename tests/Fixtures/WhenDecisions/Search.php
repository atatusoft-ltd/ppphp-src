<?php
/** @var list<int> $xs */ $xs = [1, 2, 3, 4];
 do {
    if (count($xs) > 0) {
        /** @var int $hit */
        $hit = 0;
        /**
         * @var int $x
         */
        foreach ($xs as $x) {
            if ($x > 2) {
                $hit = $x;
                break;
            }
        }
        $__ppphp_when_50 = $hit;
        break;
    } else {
        $__ppphp_when_50 = 0;
        break;
    }
} while (true);
/** @var int $first
 * @var int $__ppphp_when_50
 */
$first = $__ppphp_when_50;
unset($__ppphp_when_50);
echo $first;
