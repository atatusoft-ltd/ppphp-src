<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Type;

use Atatusoft\Ppphp\Semantic\Type\Interfaces\Type;

final class CompositeTypeParser
{
    public function parse(string $text): Type
    {
        $text = trim($text);

        if ($text === '') {
            return new UnknownType();
        }

        $text = $this->stripWrappingParentheses($text);
        $union = $this->splitAtTopLevel($text, '|');

        if (count($union) > 1) {
            return new UnionType(array_map($this->parse(...), $union));
        }

        $intersection = $this->splitAtTopLevel($text, '&');

        if (count($intersection) > 1) {
            return new IntersectionType(array_map($this->parse(...), $intersection));
        }

        if (str_starts_with($text, '?')) {
            return new UnionType([$this->parse(substr($text, 1)), new AtomicType('null')]);
        }

        $genericOpen = str_contains($text, '<') ? $this->resolveGenericOpen($text) : null;

        if ($genericOpen !== null && str_ends_with($text, '>')) {
            $base = trim(substr($text, 0, $genericOpen));
            $argumentSource = substr($text, $genericOpen + 1, -1);
            $arguments = array_map($this->parse(...), $this->splitAtTopLevel($argumentSource, ','));

            if ($arguments !== []) {
                if (strtolower(ltrim($base, '\\')) === 'array' && count($arguments) === 1) {
                    return new TypedArrayType(new AtomicType('int'), $arguments[0], true);
                }

                if (strtolower(ltrim($base, '\\')) === 'array' && count($arguments) === 2) {
                    return new TypedArrayType($arguments[0], $arguments[1], false);
                }

                return new GenericType(new AtomicType($base), $arguments);
            }
        }

        return new AtomicType($text);
    }

    private function resolveGenericOpen(string $text): ?int
    {
        $parenthesisDepth = 0;

        for ($offset = 0, $length = strlen($text); $offset < $length; $offset++) {
            if ($text[$offset] === '(') {
                $parenthesisDepth++;
            } elseif ($text[$offset] === ')') {
                $parenthesisDepth--;
            } elseif ($text[$offset] === '<' && $parenthesisDepth === 0) {
                return $offset;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function splitAtTopLevel(string $text, string $separator): array
    {
        if (!str_contains($text, $separator)) {
            return [trim($text)];
        }

        $parts = [];
        $start = 0;
        $parenthesisDepth = 0;
        $genericDepth = 0;
        $length = strlen($text);

        for ($offset = 0; $offset < $length; $offset++) {
            $character = $text[$offset];

            if ($character === '(') {
                $parenthesisDepth++;
            } elseif ($character === ')') {
                $parenthesisDepth--;
            } elseif ($character === '<') {
                $genericDepth++;
            } elseif ($character === '>') {
                $genericDepth--;
            } elseif ($character === $separator && $parenthesisDepth === 0 && $genericDepth === 0) {
                $parts[] = trim(substr($text, $start, $offset - $start));
                $start = $offset + 1;
            }
        }

        $parts[] = trim(substr($text, $start));

        return $parts;
    }

    public function hasWrappingParentheses(string $text): bool
    {
        $text = trim($text);

        return $text !== $this->stripWrappingParentheses($text);
    }

    private function stripWrappingParentheses(string $text): string
    {
        while (strlen($text) >= 2 && $text[0] === '(' && $text[strlen($text) - 1] === ')') {
            $depth = 0;
            $wraps = true;
            $length = strlen($text);

            for ($offset = 0; $offset < $length; $offset++) {
                if ($text[$offset] === '(') {
                    $depth++;
                } elseif ($text[$offset] === ')') {
                    $depth--;

                    if ($depth === 0 && $offset !== $length - 1) {
                        $wraps = false;
                        break;
                    }
                }
            }

            if (!$wraps || $depth !== 0) {
                break;
            }

            $text = trim(substr($text, 1, -1));
        }

        return $text;
    }
}
