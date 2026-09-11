<?php
declare(strict_types=1);
final class Item
{
    public function __construct(public string $name) {}
    public function __destruct() { echo $this->name, '|'; }
}
function consume(Item $first, Item $second): void { echo 'consume|'; }
$__ppphp_when_227 = null;
$__ppphp_when_333 = null;
try {
    if (getenv('BRANCH') !== 'other') {
        $__ppphp_when_227 = new Item('first');
    } else {
        $__ppphp_when_227 = new Item('first');
    }
    if (getenv('BRANCH') !== 'other') {
        $__ppphp_when_333 = new Item('second');
    } else {
        $__ppphp_when_333 = new Item('second');
    }
    consume($__ppphp_when_227, $__ppphp_when_333);
} finally {
    try {
        unset($__ppphp_when_227);
    } finally {
        unset($__ppphp_when_333);
    }
}
echo 'after';
