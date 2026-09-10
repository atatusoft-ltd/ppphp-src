<?php
declare(strict_types=1);

function recoverPending(): string
{
    try {
        throw new RuntimeException('pending');
    } finally {
        return 'recovered';
    }
}

function replaceResult(): string
{
    try {
        return 'try';
    } finally {
        return 'finally';
    }
}

echo recoverPending(), '|', replaceResult();
