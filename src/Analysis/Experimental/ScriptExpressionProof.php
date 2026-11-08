<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Experimental;

use Atatusoft\Ppphp\Analysis\Experimental\Enumerations\UntrackedString;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;

/**
 * @phpstan-type ScalarValue bool|float|int|string|UntrackedString
 * @phpstan-type Locals array<string, ScalarValue>
 * @phpstan-type Collections array<string, non-empty-array<int|string, ScalarValue>>
 */
final class ScriptExpressionProof
{
    public const array AMBIENT_VARIABLES = [
        'GLOBALS', '_SERVER', '_GET', '_POST', '_FILES', '_COOKIE', '_SESSION', '_REQUEST', '_ENV',
        'this', 'argc', 'argv', 'http_response_header', 'php_errormsg',
    ];

    public static function addIntegers(int $left, int $right): ?int
    {
        if (($right > 0 && $left > PHP_INT_MAX - $right) || ($right < 0 && $left < PHP_INT_MIN - $right)) {
            return null;
        }

        return $left + $right;
    }

    /**
     * @param Locals $assigned
     * @param Collections $arrays
     * @param array<string, true> $reads
     */
    public static function resolve(
        Expr $expression,
        array $assigned,
        array $arrays,
        array &$reads,
        int &$steps,
        int $depth,
    ): bool|float|int|string|UntrackedString|null {
        if ($depth === 0 || --$steps < 0) {
            return null;
        }
        if ($expression instanceof Expr\Variable && is_string($expression->name) && isset($assigned[$expression->name])) {
            $reads[$expression->name] = true;
            return $assigned[$expression->name];
        }
        if ($expression instanceof Expr\ArrayDimFetch && $expression->var instanceof Expr\Variable
            && is_string($expression->var->name) && $expression->dim !== null && isset($arrays[$expression->var->name])) {
            $key = self::resolve($expression->dim, $assigned, $arrays, $reads, $steps, $depth - 1);

            return is_int($key) || is_string($key) ? ($arrays[$expression->var->name][$key] ?? null) : null;
        }
        if (!$expression instanceof Expr\BinaryOp) {
            return self::resolveLiteral($expression);
        }

        $left = self::resolve($expression->left, $assigned, $arrays, $reads, $steps, $depth - 1);
        $right = self::resolve($expression->right, $assigned, $arrays, $reads, $steps, $depth - 1);
        if ($expression instanceof Expr\BinaryOp\Concat) {
            // Do not materialize strings that could grow exponentially. The
            // result still has a known type but cannot prove an exact key.
            return (is_string($left) || is_int($left) || $left instanceof UntrackedString)
                && (is_string($right) || is_int($right) || $right instanceof UntrackedString)
                ? UntrackedString::Value : null;
        }
        if (!is_int($left) || !is_int($right)) {
            return null;
        }

        $result = match (true) {
            $expression instanceof Expr\BinaryOp\Plus => $left + $right,
            $expression instanceof Expr\BinaryOp\Minus => $left - $right,
            $expression instanceof Expr\BinaryOp\Mul => $left * $right,
            default => null,
        };

        // PHP promotes an overflowing integer operation to a float.
        return is_int($result) ? $result : null;
    }

    public static function resolveType(bool|float|int|string|UntrackedString $value): string
    {
        return $value instanceof UntrackedString ? 'string' : get_debug_type($value);
    }

    public static function resolveLiteral(Expr $expression): bool|float|int|string|null
    {
        return match (true) {
            $expression instanceof Scalar\String_ => $expression->value,
            $expression instanceof Scalar\Int_ => $expression->value,
            $expression instanceof Scalar\Float_ && is_finite($expression->value) => $expression->value,
            $expression instanceof Expr\ConstFetch && $expression->name->isUnqualified()
                && in_array(strtolower($expression->name->toString()), ['true', 'false'], true) =>
                    strtolower($expression->name->toString()) === 'true',
            default => null,
        };
    }
}
