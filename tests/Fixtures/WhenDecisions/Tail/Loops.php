<?php
declare(strict_types=1);
/**
 * @param list<int> $values
 */
function literalLoop(bool $enabled, int $needle, array $values): int
{
    if ($enabled) {
        /** @var int $found */
        $found = -1;
        /**
         * @var int $value
         */
        foreach ($values as $value) {
            if ($value === $needle) {
                $found = $value * 100;
                break;
            }
        }
    } else {
        $found = 0;
    }
    return $found;
}
function localLoop(bool $enabled, int $needle, int $fallback): int
{
    if ($enabled) {
        /** @var int $found */
        $found = $fallback;
        /**
         * @var int $index
         */
        for ($index = 0; $index < 3; $index++) {
            if ($index > $needle) {
                $found = $index;
                break;
            }
        }
    } else {
        $found = 0;
    }
    return $found;
}
/**
 * @param list<int> $values
 */
function nullableLoop(bool $enabled, int $n, array $values): ?int
{
    if ($enabled) {
        /** @var int|null $found */
        $found = null;
        $__ppphp_when_complete_0 = false;
        /**
         * @var int $value
         */
        foreach ($values as $value) {
            if ($value === $n) {
                $found = null;
                $__ppphp_when_complete_0 = true;
                break;
            }
            $n++;
        }
        if (!$__ppphp_when_complete_0) {
            $found = $n;
        }
        unset($__ppphp_when_complete_0);
    } else {
        $found = 0;
    }
    return $found;
}
/**
 * @param list<int> $values
 */
function composedLoop(bool $enabled, int $n, array $values): ?int
{
    if ($enabled) {
        /** @var int|null $found */
        $found = null;
        $__ppphp_when_complete_1 = false;
        if ($n > 0) {
            echo 'guard|';
            if ($n > 5) {
                $found = null;
                $__ppphp_when_complete_1 = true;
            }
        }
        if (!$__ppphp_when_complete_1) {
            /**
             * @var int $value
             */
            foreach ($values as $value) {
                if ($value > $n) {
                    $found = $value;
                    $__ppphp_when_complete_1 = true;
                    break;
                }
            }
        }
        if (!$__ppphp_when_complete_1) {
            echo 'tail|';
            $found = $n;
        }
        unset($__ppphp_when_complete_1);
    } else {
        $found = 0;
    }
    return $found;
}
function guaranteedLoop(bool $enabled, int $n): int
{
    if ($enabled) {
        while (true) {
            if ($n > 5) {
                $found = $n;
                break;
            }
            $n++;
        }
    } else {
        do {
            $found = 0;
            break;
        } while ($enabled);
    }
    return $found;
}
/**
 * @param list<int> $values
 */
function switchLoop(bool $enabled, int $n, array $values): int
{
    if ($enabled) {
        /** @var int|null $found */
        $found = null;
        /**
         * @var int $value
         */
        foreach ($values as $value) {
            switch ($value) {
                case 1:
                    continue 2;
                case 2:
                    $found = $n;
                    break 2;
                default:
                    break;
            }
            $n++;
        }
        if ($found === null) {
            $found = $n;
        }
    } else {
        $found = 0;
    }
    return $found;
}
echo json_encode([
    literalLoop(true, 2, [1, 2, 3]), literalLoop(true, 4, [1, 2, 3]), literalLoop(false, 2, [1]),
    localLoop(true, 0, 70), localLoop(true, 5, 70), localLoop(false, 0, 70),
    nullableLoop(true, 1, [1, 2, 3]), nullableLoop(true, 2, [1, 2, 3]), nullableLoop(true, 2, []),
    composedLoop(true, 9, [1, 2]), composedLoop(true, 2, [1, 3]), composedLoop(true, 0, []),
    guaranteedLoop(true, 3), guaranteedLoop(false, 3),
    switchLoop(true, 4, [1, 2, 3]), switchLoop(true, 4, [3]), switchLoop(true, 4, []),
]);
