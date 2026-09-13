<?php
declare(strict_types=1);

/** @throws RuntimeException */
function risky(): int
{
    if (getenv('FAIL') === '1') {
        throw new RuntimeException('failed');
    }
    return 5;
}

if (getenv('W') === '1') {
    try {
        $pending = risky();
    } catch (RuntimeException) {
        $pending = -1;
    } finally {
        echo 'cleanup|';
    }
} else {
    $pending = 'else';
}
$r = $pending;
unset($pending);
echo $r;
