<?php
declare(strict_types=1);
class Box {
    public function consume(int $literal, int $flag): void { echo $literal, ':', $flag, '|'; }
    public function apply(int $tier): void {
        if ($tier === 1) {
            $__ppphp_when_184 = 1;
        } elseif ($tier === 2) {
            $__ppphp_when_184 = 2;
        } else {
            $__ppphp_when_184 = 3;
        }
        consume($this, 7, $__ppphp_when_184);
        unset($__ppphp_when_184);
        if ($tier === 1) {
            $__ppphp_when_302 = 1;
        } elseif ($tier === 2) {
            $__ppphp_when_302 = 2;
        } else {
            $__ppphp_when_302 = 3;
        }
        $this->consume(7, $__ppphp_when_302);
        unset($__ppphp_when_302);
    }
}
function consume(Box $box, int $literal, int $flag): void { $box->consume($literal, $flag); }
function apply(Box $box, int $tier): void {
    if ($tier === 1) {
        $__ppphp_when_561 = 1;
    } elseif ($tier === 2) {
        $__ppphp_when_561 = 2;
    } else {
        $__ppphp_when_561 = 3;
    }
    consume($box, 7, $__ppphp_when_561);
    unset($__ppphp_when_561);
    if ($tier === 1) {
        $__ppphp_when_674 = 1;
    } elseif ($tier === 2) {
        $__ppphp_when_674 = 2;
    } else {
        $__ppphp_when_674 = 3;
    }
    $box->consume(7, $__ppphp_when_674);
    unset($__ppphp_when_674);
}
(new Box())->apply(1);
(new Box())->apply(2);
(new Box())->apply(3);
apply(new Box(), 1);
apply(new Box(), 2);
apply(new Box(), 3);
