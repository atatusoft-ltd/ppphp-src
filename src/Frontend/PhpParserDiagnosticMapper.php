<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Frontend;

use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\DiagnosticLabel;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticOrigin;
use Atatusoft\Ppphp\Frontend\Normalization\SourceMap;
use Atatusoft\Ppphp\Source\SourceFile;
use Atatusoft\Ppphp\Source\Span;
use PhpParser\Error;

final class PhpParserDiagnosticMapper
{
    public function map(Error $error, SourceFile $sourceFile, ?SourceMap $sourceMap = null, bool $missingSemicolon = false): Diagnostic
    {
        $attributes = $error->getAttributes();
        $span = $this->resolveSpan($attributes, $error->getStartLine(), $sourceFile, $sourceMap);

        $message = PhpSyntaxMessage::format($error, $span->text, $missingSemicolon);

        return new Diagnostic(
            DiagnosticCode::InvalidPhpSyntax,
            $message,
            new DiagnosticLabel($span, $message),
            debug: [
                'parserError' => $error::class,
                'parserMessage' => $error->getRawMessage(),
                'parserAttributes' => $attributes,
            ],
            origin: DiagnosticOrigin::PhpParser,
        );
    }

    /** @param array<string, mixed> $attributes */
    private function resolveSpan(
        array $attributes,
        int $reportedLine,
        SourceFile $sourceFile,
        ?SourceMap $sourceMap,
    ): Span
    {
        $startAttribute = $attributes['startFilePos'] ?? null;
        $endAttribute = $attributes['endFilePos'] ?? null;

        if (is_int($startAttribute)) {
            $normalizedStart = max(0, min($sourceFile->length, $startAttribute));
            $owningSpan = $sourceMap?->resolveOwningSpan($normalizedStart);

            if ($owningSpan !== null) {
                return $owningSpan;
            }

            $start = $sourceMap?->resolveOriginalOffset($normalizedStart) ?? $normalizedStart;
            $end = $start;

            if (is_int($endAttribute) && $start < $sourceFile->length) {
                $normalizedEnd = max(0, min($sourceFile->length, $endAttribute + 1));
                $end = max($start, $sourceMap?->resolveOriginalOffset($normalizedEnd) ?? $normalizedEnd);
            }

            return $sourceFile->createSpan($start, $end);
        }

        $line = max(1, min($sourceFile->lineCount, $reportedLine));
        $offset = $sourceFile->resolveLineStartOffset($line);

        return $sourceFile->createSpan($offset, $offset);
    }
}
