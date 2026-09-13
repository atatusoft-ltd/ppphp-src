<?php
declare(strict_types=1);
function pick(bool $first, bool $a, bool $b): int
{
    if ($first) {
        /** @var int|null $result */
        $result = null;
        if ($a) {
            echo 'first|';
            if ($b) {
                $result = 1;
            }
        }
        if ($result === null && ($a || $b)) {
            $result = 2;
        }
        if ($result === null) {
            echo 'rest|';
            $result = 3;
        }
    } else {
        $result = 4;
    }
    return $result;
}
function maybe(int $take): ?int
{
    if ($take !== -1) {
        /** @var int|null $result */
        $result = null;
        $__ppphp_when_complete_0 = false;
        if ($take > 0) {
            echo 'guard|';
            if ($take > 5) {
                $result = null;
                $__ppphp_when_complete_0 = true;
            }
        }
        if (!$__ppphp_when_complete_0) {
            echo 'rest|';
            $result = $take;
        }
        unset($__ppphp_when_complete_0);
    } else {
        $result = -1;
    }
    return $result;
}
function fromSwitch(int $key): string
{
    if ($key > 0) {
        /** @var string|null $result */
        $result = null;
        switch ($key) {
            case 1:
                $result = 'one';
                break;
            case 2:
                echo 'two|';
                break;
            default:
                echo 'other|';
        }
        if ($result === null) {
            echo 'rest|';
            $result = 'tail:' . $key;
        }
    } else {
        $result = 'zero';
    }
    return $result;
}
echo pick(true, true, true), '|', pick(true, false, true), '|', pick(true, false, false), '|', pick(false, true, true), '|';
echo json_encode(maybe(-1)), '|', json_encode(maybe(2)), '|', json_encode(maybe(9)), '|';
echo fromSwitch(1), '|', fromSwitch(2), '|', fromSwitch(3), '|', fromSwitch(0);
