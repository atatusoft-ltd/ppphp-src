<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Browser;

/** Strict JSON primitives shared by requests and persisted continuation records. */
final class WorkflowValues
{
    /**
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    public static function readObject(mixed $value, array $keys): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException('Expected a workflow object.');
        }
        $actual = array_keys($value);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            throw new \InvalidArgumentException('Workflow fields are missing or unexpected.');
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('Workflow object keys must be strings.');
            }
            $result[$key] = $item;
        }
        return $result;
    }

    public static function readString(mixed $value, int $maximum = 512): string
    {
        if (!is_string($value) || strlen($value) > $maximum || !mb_check_encoding($value, 'UTF-8')) {
            throw new \InvalidArgumentException('Invalid or oversized workflow string.');
        }
        return $value;
    }

    public static function readHash(mixed $value): string
    {
        $hash = self::readString($value, 71);
        if (preg_match('/^sha256:[a-f0-9]{64}$/D', $hash) !== 1) {
            throw new \InvalidArgumentException('Invalid workflow content hash.');
        }
        return $hash;
    }

    public static function readId(mixed $value): string
    {
        $id = self::readString($value, 128);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\/-]{0,127}$/D', $id) !== 1) {
            throw new \InvalidArgumentException('Invalid workflow operation identifier.');
        }
        return $id;
    }

    public static function readInteger(mixed $value, int $minimum = 0, int $maximum = 1_000_000): int
    {
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new \InvalidArgumentException('Invalid workflow integer.');
        }
        return $value;
    }

    public static function readBoolean(mixed $value): bool
    {
        if (!is_bool($value)) {
            throw new \InvalidArgumentException('Invalid workflow boolean.');
        }
        return $value;
    }

    public static function readPath(mixed $value): string
    {
        $path = self::readString($value);
        if ($path === '' || preg_match('/[\x00-\x1f\\\\:]/', $path) === 1
            || array_intersect(explode('/', $path), ['', '.', '..']) !== []) {
            throw new \InvalidArgumentException('Unsafe workflow relative path.');
        }
        return $path;
    }

    /** @return list<mixed> */
    public static function readList(mixed $value, int $maximum = 64): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $maximum) {
            throw new \InvalidArgumentException('Invalid or oversized workflow list.');
        }
        return $value;
    }
}
