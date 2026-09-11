<?php
declare(strict_types=1);
class Box {
    public function consume(int $literal, int $flag): void { echo $literal, ':', $flag, '|'; }
    public function apply(bool $vip): void {
        if ($vip) {
            $__ppphp_when_184 = 1;
        } else {
            $__ppphp_when_184 = 2;
        }
        consume($this, 7, $__ppphp_when_184);
        unset($__ppphp_when_184);
        if ($vip) {
            $__ppphp_when_257 = 1;
        } else {
            $__ppphp_when_257 = 2;
        }
        $this->consume(7, $__ppphp_when_257);
        unset($__ppphp_when_257);
    }
}
function consume(Box $box, int $literal, int $flag): void { $box->consume($literal, $flag); }
function apply(Box $box, bool $vip): void {
    if ($vip) {
        $__ppphp_when_471 = 1;
    } else {
        $__ppphp_when_471 = 2;
    }
    consume($box, 7, $__ppphp_when_471);
    unset($__ppphp_when_471);
    if ($vip) {
        $__ppphp_when_539 = 1;
    } else {
        $__ppphp_when_539 = 2;
    }
    $box->consume(7, $__ppphp_when_539);
    unset($__ppphp_when_539);
}
(new Box())->apply(true);
(new Box())->apply(false);
apply(new Box(), true);
apply(new Box(), false);
