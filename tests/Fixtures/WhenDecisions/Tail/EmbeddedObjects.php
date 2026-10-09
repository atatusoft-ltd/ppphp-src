<?php
declare(strict_types=1);
final class Item
{
    public function __construct(public string $name) {}
    public function __destruct() { echo $this->name, '|'; }
}
function consume(Item $first, Item $second): void { echo 'consume|'; }
consume(getenv('BRANCH') !== 'other' ? new Item('first') : new Item('first'), getenv('BRANCH') !== 'other' ? new Item('second') : new Item('second'));
echo 'after';
