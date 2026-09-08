<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Frontend;

use PhpParser\Error;
use PhpParser\ErrorHandler\Collecting;
use PhpParser\Parser;

/** Translates parser expectations, never arbitrary user identifiers or quoted values. */
final class PhpSyntaxMessage
{
    public static function format(Error $error, ?string $sourceToken = null, bool $missingSemicolon = false): string
    {
        $raw = $error->getRawMessage();
        if (preg_match('/^Syntax error, unexpected (.+?)(?:, expecting (.+))?$/D', $raw, $matches) !== 1) {
            return $raw;
        }

        $unexpected = $matches[1] === 'EOF'
            ? 'end of file'
            : ($sourceToken !== null && $sourceToken !== '' && strlen($sourceToken) <= 80
                && !str_contains($sourceToken, "\n") ? '`' . $sourceToken . '`' : self::describe($matches[1]));
        $expected = $missingSemicolon ? "';'" : ($matches[2] ?? null);

        if ($expected !== null) {
            $expected = implode(' or ', array_map(self::describe(...), explode(' or ', $expected)));

            return sprintf('Expected %s before %s.', $expected, $unexpected);
        }

        return sprintf('Unexpected %s.', $unexpected);
    }

    /** One bounded diagnostic-only probe; callers retain the original AST and tokens. */
    public static function checkMissingSemicolon(Error $error, string $contents, Parser $parser): bool
    {
        if (!str_starts_with($error->getRawMessage(), 'Syntax error, unexpected ')) {
            return false;
        }
        $offset = $error->getAttributes()['startFilePos'] ?? null;
        if (!is_int($offset) || $offset < 0 || $offset > strlen($contents)) {
            return false;
        }
        $handler = new Collecting();
        try {
            $statements = $parser->parse(substr_replace($contents, ';', $offset, 0), $handler);
        } catch (Error) {
            return false;
        }

        return $statements !== null && !$handler->hasErrors();
    }

    private static function describe(string $token): string
    {
        return match ($token) {
            "';'" => 'a semicolon',
            "')'" => 'a closing parenthesis',
            "']'" => 'a closing bracket',
            "'}'" => 'a closing brace',
            'EOF' => 'end of file',
            'T_STRING', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_QUALIFIED', 'T_NAME_RELATIVE' => 'an identifier',
            'T_VARIABLE' => 'a variable',
            'T_LNUMBER', 'T_DNUMBER', 'T_NUM_STRING' => 'a number',
            'T_CONSTANT_ENCAPSED_STRING', 'T_ENCAPSED_AND_WHITESPACE' => 'a string',
            default => str_starts_with($token, 'T_')
                ? str_replace('_', ' ', strtolower(substr($token, 2)))
                : $token,
        };
    }
}
