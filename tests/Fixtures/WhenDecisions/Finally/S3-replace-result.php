<?php
declare(strict_types=1);

if (getenv('W') === '1') {
    try {
        $pending = 'try';
    } finally {
        $pending = 'finally';
    }
} else {
    $pending = 'else';
}
$r = $pending;
unset($pending);
echo $r;
