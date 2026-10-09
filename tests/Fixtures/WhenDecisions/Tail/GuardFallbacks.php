<?php
declare(strict_types=1);
function literal(bool $enabled, bool $prefix, bool $take): int
{
    if ($enabled) {
        /** @var int $result */
        $result = -1;
        if ($prefix) {
            if ($take) {
                $result = 10;
            }
        }
    } else {
        $result = -9;
    }
    return $result;
}
function local(bool $enabled, bool $prefix, bool $take, int $fallback): int
{
    if ($enabled) {
        /** @var int $result */
        $result = $fallback;
        if ($prefix) {
            if ($take) {
                $result = 20;
            }
        }
    } else {
        $result = -9;
    }
    return $result;
}
function nullable(bool $enabled, bool $prefix, bool $take): ?int
{
    if ($enabled) {
        /** @var int|null $result */
        $result = 4;
        if ($prefix) {
            if ($take) {
                $result = null;
            }
        }
    } else {
        $result = -9;
    }
    return $result;
}
function nullFallback(bool $enabled, bool $prefix, bool $take): ?int
{
    if ($enabled) {
        /** @var int|null $result */
        $result = null;
        if ($prefix) {
            if ($take) {
                $result = 30;
            }
        }
    } else {
        $result = -9;
    }
    return $result;
}
echo json_encode([
    literal(true, true, true), literal(true, true, false), literal(false, true, true),
    local(true, true, true, 7), local(true, false, false, 7),
    nullable(true, true, true), nullable(true, true, false),
    nullFallback(true, true, true), nullFallback(true, true, false),
]);
