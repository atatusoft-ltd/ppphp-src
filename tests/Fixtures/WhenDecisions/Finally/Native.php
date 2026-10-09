<?php
declare(strict_types=1);
function plain(bool $enabled): int
{
    if ($enabled) {
        try {
            return 5;
        } finally {
            echo 'cleanup|';
        }
    } else {
        return 0;
    }
}

/**
 * Choose a value without recovering a pending exception.
 * @throws \RuntimeException
 */
function choose(bool $enabled, int $mode): string
{
    $__ppphp_when_315 = null;
    try {
        if ($enabled) {
            try {
                if ($mode === 0) {
                    $__ppphp_when_315 = 'first';
                } else {
                    if ($mode === 1) {
                        throw new RuntimeException('pending');
                    }
                    $__ppphp_when_315 = 'body';
                }
            } finally {
                if ($mode === 2) {
                    $__ppphp_when_315 = 'final';
                } else {
                    echo 'cleanup|';
                }
            }
        } else {
            $__ppphp_when_315 = 'else';
        }
        /** @var string $result */
        $result = $__ppphp_when_315;
    } finally {
        unset($__ppphp_when_315);
    }
    return $result;
}

function guarded(bool $enabled, bool $outer, bool $inner): ?int
{
    $__ppphp_when_734 = null;
    if ($enabled) {
        try {
            $__ppphp_when_734 = 7;
        } finally {
            $__ppphp_when_complete_0 = false;
            if ($outer) {
                echo 'head|';
                if ($inner) {
                    $__ppphp_when_734 = null;
                    $__ppphp_when_complete_0 = true;
                }
            }
            if (!$__ppphp_when_complete_0) {
                echo 'tail|';
            }
            unset($__ppphp_when_complete_0);
        }
    } else {
        $__ppphp_when_734 = 0;
    }
    return $__ppphp_when_734;
}

function caught(bool $enabled, bool $fail): int
{
    if ($enabled) {
        try {
            try {
                if ($fail) {
                    throw new RuntimeException();
                }
                $__ppphp_when_1044 = 5;
            } catch (RuntimeException $error) {
                $__ppphp_when_1044 = -1;
            } finally {
                echo 'inner|';
            }
        } finally {
            echo 'outer|';
        }
    } else {
        $__ppphp_when_1044 = 0;
    }
    /** @var int $result */
    $result = $__ppphp_when_1044;
    unset($__ppphp_when_1044);
    return $result;
}

function delayed(bool $enabled, bool $fail): int
{
    /** @var int $destination */ $destination = 99;
    try {
        if ($enabled) {
            try {
                echo 'compute|';
                $__ppphp_when_1478 = 5;
            } finally {
                if ($fail) {
                    throw new Error('cleanup');
                }
            }
        } else {
            $__ppphp_when_1478 = 0;
        }
        $destination = $__ppphp_when_1478;
        unset($__ppphp_when_1478);
    } catch (Error $error) { echo 'caught:' . $error->getMessage() . '|'; }
    return $destination;
}

try {
    echo plain(true), '|', choose(true, 0), '|';
    try { echo choose(true, 1); } catch (RuntimeException $error) { echo 'caught:' . $error->getMessage() . '|'; }
    echo choose(true, 2), '|', choose(false, 2), '|';
    echo guarded(true, false, false), '|', guarded(true, true, false), '|', json_encode(guarded(true, true, true)), '|';
    echo caught(true, false), '|', caught(true, true), '|';
    echo delayed(true, false), '|', delayed(true, true), '|', delayed(false, true);
} catch (RuntimeException $error) { echo 'unexpected:' . $error->getMessage(); }
