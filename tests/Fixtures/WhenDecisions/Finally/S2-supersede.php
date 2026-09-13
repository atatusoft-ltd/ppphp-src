<?php
declare(strict_types=1);

if (getenv('W') === '1') {
    try {
        throw new RuntimeException('pending');
    } catch (Throwable) {
        // The unconditional finally result supersedes any pending exception.
    } finally {
        $pending = 'recovered';
    }
} else {
    $pending = 'else';
}
$r = $pending;
unset($pending);
echo $r;
