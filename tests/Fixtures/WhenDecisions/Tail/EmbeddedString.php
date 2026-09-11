<?php
declare(strict_types=1);
function consume(string $value): void
{
    echo $value, '|';
    if (getenv('FAULT') === 'consumer') { throw new Error('consumer'); }
}
$__ppphp_when_151 = null;
try {
    if (getenv('BRANCH') !== 'other') {
        $__ppphp_when_151 = str_repeat('a', 2);
    } else {
        $__ppphp_when_151 = 'b';
    }
    consume($__ppphp_when_151);
} finally {
    unset($__ppphp_when_151);
}
echo 'after';
