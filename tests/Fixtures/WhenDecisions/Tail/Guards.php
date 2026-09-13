<?php
declare(strict_types=1);
function label(int $score): string
{
    if ($score !== 42) {
        if ($score < 0) {
            $label = 'invalid';
        } elseif ($score === 0) {
            $label = 'zero';
        } else {
            /** @var int $band */
            $band = intdiv($score, 10);
            $label = 'band ' . $band;
        }
    } else {
        $label = 'special';
    }
    return $label;
}
echo label(-1), '|', label(0), '|', label(30), '|', label(42);
