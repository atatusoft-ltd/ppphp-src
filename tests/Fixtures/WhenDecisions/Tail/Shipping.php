<?php
declare(strict_types=1);
/**
 * @param list<int> $weights
 */
function shipping(bool $express, array $weights): int
{
    if ($express) {
        /** @var int $weight */
        $weight = 0;
        /**
         * @var int $parcel
         */
        foreach ($weights as $parcel) {
            $weight += $parcel;
        }
        $shipping = 1200 + $weight * 50;
    } else {
        $parcels = count($weights);
        $shipping = 500 + $parcels * 100;
    }
    return $shipping;
}
echo shipping(true, [2, 3]), '|', shipping(false, [2, 3]);
