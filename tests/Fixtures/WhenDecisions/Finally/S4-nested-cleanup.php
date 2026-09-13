<?php
declare(strict_types=1);

if (getenv('W') === '1') {
    try {
        try {
            $pending = 'value';
        } finally {
            echo 'inner|';
        }
    } finally {
        echo 'outer|';
    }
} else {
    $pending = 'else';
}
$r = $pending;
unset($pending);
echo $r;
