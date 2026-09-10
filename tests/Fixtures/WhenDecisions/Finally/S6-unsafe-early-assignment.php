<?php
declare(strict_types=1);

function compute(): int { echo 'compute|'; return 5; }

$r = 10;
try {
    if (getenv('W') === '1') {
        try {
            $r = compute();
        } finally {
            throw new RuntimeException('fail');
        }
    } else {
        $r = 0;
    }
} catch (RuntimeException $error) {
    echo 'caught ', $error->getMessage(), '|', $r;
}
