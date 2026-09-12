<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Frontend\Normalization;

/** Erases syntax without changing byte offsets or losing authored comments. */
final class SourceMask
{
    public static function erase(string $text, bool $preserveComments = true): string
    {
        $masked = preg_replace('/[^\r\n]/', ' ', $text) ?? $text;
        if (!$preserveComments || (!str_contains($text, '/') && !str_contains($text, '#'))) {
            return $masked;
        }
        $prefix = '<?php ';
        foreach (\PhpToken::tokenize($prefix . $text) as $token) {
            if ($token->id === T_COMMENT || $token->id === T_DOC_COMMENT) {
                $masked = substr_replace($masked, $token->text, $token->pos - strlen($prefix), strlen($token->text));
            }
        }
        return $masked;
    }
}
