<?php
declare(strict_types=1);

try {
    if (getenv('W') === '1') {
        try {
            throw new RuntimeException('pending');
        } finally {
            $pending = 'recovered';
        }
    } else {
        $pending = 'else';
    }
    $r = $pending;
    unset($pending);
    echo $r;
} catch (RuntimeException $error) {
    echo 'exception:', $error->getMessage();
}
