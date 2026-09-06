<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Diagnostics;

use Atatusoft\Ppphp\Support\Utf8;

final class DiagnosticSanitizer
{
    public function sanitize(DiagnosticBag $diagnostics): DiagnosticBag
    {
        $sanitized = [];

        foreach ($diagnostics as $diagnostic) {
            $message = $this->sanitizeText($diagnostic->message);
            $primary = $diagnostic->primary === null ? null : $this->sanitizeLabel($diagnostic->primary);
            $related = array_map($this->sanitizeLabel(...), $diagnostic->related);
            $help = $diagnostic->help === null ? null : $this->sanitizeText($diagnostic->help);

            if (
                $message === $diagnostic->message
                && $primary === $diagnostic->primary
                && $related === $diagnostic->related
                && $help === $diagnostic->help
            ) {
                $sanitized[] = $diagnostic;
                continue;
            }

            $sanitized[] = new Diagnostic(
                $diagnostic->code,
                $message,
                $primary,
                $related,
                $help,
                $diagnostic->debug,
                $diagnostic->origin,
                $diagnostic->identity,
            );
        }

        return new DiagnosticBag($sanitized);
    }

    private function sanitizeLabel(DiagnosticLabel $label): DiagnosticLabel
    {
        $message = $this->sanitizeText($label->message);

        return $message === $label->message
            ? $label
            : new DiagnosticLabel($label->span, $message);
    }

    private function sanitizeText(string $text): string
    {
        $text = Utf8::sanitize($text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // User identifiers and literal values are diagnostic evidence, not prose to rewrite.
        // Producers own wording; this layer only makes text safe to render.

        $text = preg_replace(
            '~(?:[^\s\x00-\x1F\x7F`"\']*[\\/])?\.ppphp-cache[\\/]analysis[^\s\x00-\x1F\x7F`"\']*~i',
            'compiler workspace',
            $text,
        ) ?? $text;
        $text = preg_replace(
            '~(?:selected|context)[\\/][0-9a-f]{16}[^\s\x00-\x1F\x7F`"\']*~i',
            'project source',
            $text,
        ) ?? $text;

        return preg_replace_callback(
            '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',
            static fn (array $match): string => sprintf('\\x%02X', ord($match[0])),
            $text,
        ) ?? $text;
    }
}
