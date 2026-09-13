<?php
declare(strict_types=1);
class Box {
    public int $total = 0;
    public static int $shared = 0;
    public function apply(int $tier): void {
        if ($tier === 1) {
            $this->total = 90;
        } elseif ($tier === 2) {
            $this->total = 100;
        } else {
            $this->total = 110;
        }
        if ($tier === 1) {
            self::$shared = 9;
        } elseif ($tier === 2) {
            self::$shared = 10;
        } else {
            self::$shared = 11;
        }
        echo $this->total, '|', self::$shared, '|';
    }
}
function assignBox(Box $box, int $tier): void {
    if ($tier === 1) {
        $box->total = 90;
    } elseif ($tier === 2) {
        $box->total = 100;
    } else {
        $box->total = 110;
    }
    if ($tier === 1) {
        Box::$shared = 9;
    } elseif ($tier === 2) {
        Box::$shared = 10;
    } else {
        Box::$shared = 11;
    }
    echo $box->total, '|', Box::$shared, '|';
}
(new Box())->apply(1);
(new Box())->apply(2);
(new Box())->apply(3);
assignBox(new Box(), 1);
assignBox(new Box(), 2);
assignBox(new Box(), 3);
