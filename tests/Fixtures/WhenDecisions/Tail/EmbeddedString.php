<?php
declare(strict_types=1);
function consume(string $value): void
{
    echo $value, '|';
    if (getenv('FAULT') === 'consumer') { throw new Error('consumer'); }
}
consume(getenv('BRANCH') !== 'other' ? str_repeat('a', 2) : 'b');
echo 'after';
