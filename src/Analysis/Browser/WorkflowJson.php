<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Browser;

/** JSON decoding that also rejects duplicate object keys, including escaped keys. */
final class WorkflowJson
{
    /** @param int<1, 512> $depth */
    public static function decode(string $json, int $depth = 32): mixed
    {
        $value = json_decode($json, true, $depth, JSON_THROW_ON_ERROR);
        // Syntax is already validated. Walk strings and structural marks in
        // linear time; regex backtracking limits must never skip key validation.
        $stack = [];
        $length = strlen($json);
        for ($offset = 0; $offset < $length; $offset++) {
            $token = $json[$offset];
            if ($token === '"') {
                $start = $offset;
                for ($offset++; $offset < $length; $offset++) {
                    if ($json[$offset] === '\\') {
                        $offset++;
                    } elseif ($json[$offset] === '"') {
                        break;
                    }
                }
                $index = array_key_last($stack);
                if ($index !== null && $stack[$index]['object'] && $stack[$index]['key']) {
                    $key = json_decode(substr($json, $start, $offset - $start + 1), true, flags: JSON_THROW_ON_ERROR);
                    if (!is_string($key) || isset($stack[$index]['keys'][$key])) {
                        throw new \InvalidArgumentException('Duplicate workflow JSON object key.');
                    }
                    $stack[$index]['keys'][$key] = true;
                }
                continue;
            }
            if ($token === '{' || $token === '[') {
                $stack[] = ['object' => $token === '{', 'key' => true, 'keys' => []];
                continue;
            }
            if ($token === '}' || $token === ']') {
                array_pop($stack);
                continue;
            }
            $index = array_key_last($stack);
            if ($index === null || !$stack[$index]['object']) {
                continue;
            }
            if ($token === ',') {
                $stack[$index]['key'] = true;
            } elseif ($token === ':') {
                $stack[$index]['key'] = false;
            }
        }
        return $value;
    }
}
