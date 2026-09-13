<?php
declare(strict_types=1);

function compute(): int { return 5; }

if (getenv('W') === '1') {
    try {
        $pending = compute();
    } finally {
        echo 'cleanup|';
    }
} else {
    $pending = 'else';
}
$r = $pending;
unset($pending);
echo $r;
