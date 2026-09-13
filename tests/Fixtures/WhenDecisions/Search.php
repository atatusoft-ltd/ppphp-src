<?php
/** @var list<int> $xs */ $xs = [1, 2, 3, 4];
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
    $first = $hit;
} else {
    $first = 0;
}
echo $first;
