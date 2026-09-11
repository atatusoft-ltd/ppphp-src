<?php
declare(strict_types=1);
class Box {
    public int $total = 0;
    public static int $shared = 0;
    public function apply(bool $vip): void {
        if ($vip) {
            $this->total = 90;
        } else {
            $this->total = 100;
        }
        if ($vip) {
            self::$shared = 9;
        } else {
            self::$shared = 10;
        }
        echo $this->total, '|', self::$shared, '|';
    }
}
function assignBox(Box $box, bool $vip): void {
    if ($vip) {
        $box->total = 90;
    } else {
        $box->total = 100;
    }
    if ($vip) {
        Box::$shared = 9;
    } else {
        Box::$shared = 10;
    }
    echo $box->total, '|', Box::$shared, '|';
}
(new Box())->apply(true);
(new Box())->apply(false);
assignBox(new Box(), true);
assignBox(new Box(), false);
