<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Diagnostics;

use Atatusoft\Ppphp\Diagnostics\Enumerations\Severity;
use Atatusoft\Ppphp\Support\Utf8;

final readonly class ConsoleRenderer
{
    public function __construct(
        private DiagnosticProcessor $processor = new DiagnosticProcessor(),
        private DiagnosticDebugNormalizer $debugNormalizer = new DiagnosticDebugNormalizer(),
    ) {}

    public function render(DiagnosticBag $diagnostics, bool|ConsoleRenderOptions $options = false): string
    {
        $options = is_bool($options) ? new ConsoleRenderOptions(includeDebug: $options) : $options;
        $rendered = [];

        foreach ($this->processor->process($diagnostics) as $diagnostic) {
            $rendered[] = $this->renderDiagnostic($diagnostic, $options);
        }

        return $rendered === [] ? '' : implode("\n\n", $rendered) . "\n";
    }

    private function renderDiagnostic(Diagnostic $diagnostic, ConsoleRenderOptions $options): string
    {
        $message = explode("\n", $this->sanitize($diagnostic->message));
        $heading = sprintf('%s %s · %s', strtoupper($diagnostic->severity->value), $diagnostic->code->value, array_shift($message));
        $lines = $diagnostic->primary === null ? [] : [$this->renderLocation($diagnostic->primary, $options)];
        $lines[] = $this->style($heading, $this->severityStyle($diagnostic->severity), $options);
        array_push($lines, ...$message);

        if ($diagnostic->primary !== null) {
            $lines[] = '';
            array_push($lines, ...$this->renderLabel($diagnostic->primary, $diagnostic->severity, $options));

            if ($diagnostic->primary->message !== '' && $diagnostic->primary->message !== $diagnostic->message) {
                $lines[] = '';
                $lines[] = $this->sanitize($diagnostic->primary->message);
            }
        }

        foreach ($diagnostic->related as $related) {
            $lines[] = '';
            $lines[] = $this->renderLocation($related, $options);
            $lines[] = $this->style('NOTE · ' . $this->sanitize($related->message), $this->severityStyle(Severity::Note), $options);
            $lines[] = '';
            array_push($lines, ...$this->renderLabel($related, Severity::Note, $options));
        }

        if (
            $diagnostic->help !== null
            && $diagnostic->help !== ''
            && $diagnostic->help !== $diagnostic->message
            && $diagnostic->help !== $diagnostic->primary?->message
            && $diagnostic->help !== DiagnosticHelpProvider::resolveGeneric($diagnostic->family)
        ) {
            $lines[] = '';
            $lines[] = $this->style($this->sanitize($diagnostic->help), '32', $options);
        }

        if ($options->includeDebug) {
            $debug = $this->debugNormalizer->normalize([
                'origin' => $diagnostic->origin->value,
                ...$diagnostic->debug,
            ]);
            $lines[] = '';
            $lines[] = $this->style('Debug:', '2', $options);

            foreach ($debug as $key => $value) {
                $encoded = is_scalar($value) || $value === null
                    ? (string) $value
                    : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
                $debugLines = explode("\n", $this->sanitize($encoded));
                $lines[] = sprintf('  %s: %s', $key, array_shift($debugLines));

                foreach ($debugLines as $debugLine) {
                    $lines[] = '    ' . $debugLine;
                }
            }
        }

        return implode("\n", $lines);
    }

    private function renderLocation(DiagnosticLabel $label, ConsoleRenderOptions $options): string
    {
        return $this->style(sprintf(
            '%s:%d:%d',
            $this->sanitize(str_replace('\\', '/', $label->span->sourceFile->displayPath)),
            $label->span->start->line,
            $label->span->start->column,
        ), '1', $options);
    }

    /** @return list<string> */
    private function renderLabel(
        DiagnosticLabel $label,
        Severity $severity,
        ConsoleRenderOptions $options,
    ): array {
        $span = $label->span;
        $source = $span->sourceFile;
        $startLine = max(1, $span->start->line - $options->contextLineCount);
        $highlightEnd = $this->resolveHighlightEndLine($label);
        $endLine = min($source->lineCount, $highlightEnd + $options->contextLineCount);
        $visibleHighlightLines = $this->selectHighlightLines($span->start->line, $highlightEnd);
        $visibleLines = [];

        for ($line = $startLine; $line <= $endLine; $line++) {
            if ($line < $span->start->line || $line > $highlightEnd || in_array($line, $visibleHighlightLines, true)) {
                $visibleLines[] = $line;
            }
        }

        $gutterWidth = strlen((string) max($visibleLines === [] ? [$span->start->line] : $visibleLines));
        $lines = [];
        $previous = null;

        foreach ($visibleLines as $line) {
            if ($previous !== null && $line > $previous + 1) {
                $lines[] = str_repeat(' ', $gutterWidth + 4) . $this->style('...', '2', $options);
            }

            $sourceLine = $source->readLineText($line);
            $available = max(1, $options->terminalWidth - $gutterWidth - 4);
            [$column, $length] = $line >= $span->start->line && $line <= $highlightEnd
                ? $this->resolveUnderlineColumns($label, $line)
                : [1, 1];
            [$sourceLine, $column, $length] = $this->boundSourceLine(
                Utf8::sanitize($sourceLine),
                min(512, max(32, $available * 2)),
                $column,
                $length,
            );
            $visualStart = $this->resolveVisualColumn($sourceLine, $column);
            $visualEnd = $this->resolveVisualColumn($sourceLine, $column + $length);
            $expanded = $this->expandTabs($this->sanitize($sourceLine));
            [$expanded, $column, $length] = $this->clip(
                $expanded,
                $available,
                $visualStart,
                max(1, $visualEnd - $visualStart),
            );
            $lineNumber = str_pad((string) $line, $gutterWidth, ' ', STR_PAD_LEFT);
            $lines[] = sprintf(
                '  %s%s',
                $this->style($lineNumber, '2', $options),
                $expanded === '' ? '' : '  ' . $expanded,
            );

            if ($line >= $span->start->line && $line <= $highlightEnd) {
                $indent = min($available - 1, $column - 1);
                $underline = str_repeat(' ', $indent)
                    . str_repeat('^', max(1, min($length, $available - $indent)));

                $lines[] = str_repeat(' ', $gutterWidth + 4)
                    . $this->style($underline, $this->severityStyle($severity), $options);
            }

            $previous = $line;
        }

        return $lines;
    }

    /** @return list<int> */
    private function selectHighlightLines(int $start, int $end): array
    {
        if ($end - $start < 4) {
            return range($start, $end);
        }

        return [$start, $start + 1, $end - 1, $end];
    }

    private function resolveHighlightEndLine(DiagnosticLabel $label): int
    {
        $span = $label->span;

        if (
            !$span->isEmpty
            && $span->end->line > $span->start->line
            && $span->end->offset === $span->sourceFile->resolveLineStartOffset($span->end->line)
        ) {
            return $span->end->line - 1;
        }

        return max($span->start->line, $span->end->line);
    }

    /** @return array{int, int} */
    private function resolveUnderlineColumns(DiagnosticLabel $label, int $line): array
    {
        $span = $label->span;
        $sourceLine = $span->sourceFile->readLineText($line);
        $start = $line === $span->start->line ? $span->start->column : 1;
        $end = $line === $span->end->line
            ? $span->end->column
            : $this->countCodePoints($sourceLine) + 1;

        return [$start, max(1, $end - $start)];
    }

    private function resolveVisualColumn(string $line, int $column): int
    {
        $visual = 1;
        $logical = 1;
        $length = strlen($line);

        for ($offset = 0; $offset < $length && $logical < $column; $offset++) {
            if ((ord($line[$offset]) & 0xc0) === 0x80) {
                continue;
            }

            $character = $line[$offset];

            if ($character === "\t") {
                $visual += 4 - (($visual - 1) % 4);
                $logical++;
                continue;
            }

            $visual += preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $character) === 1 ? 4 : 1;
            $logical++;
        }

        return $visual;
    }

    private function expandTabs(string $line): string
    {
        $expanded = '';
        $column = 1;
        $start = 0;
        $length = strlen($line);

        for ($offset = 0; $offset < $length; $offset++) {
            if ($offset > $start && (ord($line[$offset]) & 0xc0) === 0x80) {
                continue;
            }

            if ($offset > $start) {
                $expanded .= substr($line, $start, $offset - $start);
                $column++;
                $start = $offset;
            }

            $character = $line[$offset];

            if ($character === "\t") {
                $width = 4 - (($column - 1) % 4);
                $expanded .= str_repeat(' ', $width);
                $column += $width;
                $start = $offset + 1;
                continue;
            }
        }

        $expanded .= substr($line, $start);

        return $expanded;
    }

    /** @return array{string, int, int} */
    private function boundSourceLine(
        string $line,
        int $maximumCharacters,
        int $highlightColumn,
        int $highlightLength,
    ): array {
        $characterCount = $this->countCodePoints($line);

        if ($characterCount <= $maximumCharacters) {
            return [$line, $highlightColumn, $highlightLength];
        }

        $highlightIndex = max(0, $highlightColumn - 1);
        $start = max(0, $highlightIndex - min(16, intdiv($maximumCharacters, 4)));
        $end = min($characterCount, $start + $maximumCharacters);

        if ($highlightIndex >= $end) {
            $start = max(0, $highlightIndex - $maximumCharacters + 1);
            $end = min($characterCount, $start + $maximumCharacters);
        }

        $byteStart = $this->byteOffsetAtCodePoint($line, $start);
        $byteEnd = $this->byteOffsetAtCodePoint($line, $end);
        $hasLeft = $start > 0;
        $hasRight = $end < $characterCount;
        $visible = substr($line, $byteStart, $byteEnd - $byteStart);
        $column = $highlightIndex - $start + 1 + ($hasLeft ? 1 : 0);
        $length = max(1, min($highlightLength, $end - max($start, $highlightIndex)));

        return [($hasLeft ? '…' : '') . $visible . ($hasRight ? '…' : ''), $column, $length];
    }

    private function byteOffsetAtCodePoint(string $value, int $index): int
    {
        if ($index <= 0) {
            return 0;
        }

        $current = 0;
        $length = strlen($value);

        for ($offset = 0; $offset < $length; $offset++) {
            if ((ord($value[$offset]) & 0xc0) !== 0x80 && $current++ === $index) {
                return $offset;
            }
        }

        return $length;
    }

    /** @return array{string, int, int} */
    private function clip(string $line, int $width, int $highlightColumn, int $highlightLength): array
    {
        $characters = preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: str_split($line);

        if (count($characters) <= $width) {
            return [$line, $highlightColumn, $highlightLength];
        }

        $highlightIndex = max(0, $highlightColumn - 1);
        $context = min(8, max(2, intdiv($width, 4)));
        $start = max(0, $highlightIndex - $context);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $hasLeft = $start > 0;
            $contentWidth = max(1, $width - ($hasLeft ? 1 : 0) - 1);
            $end = min(count($characters), $start + $contentWidth);
            $hasRight = $end < count($characters);
            $contentWidth = max(1, $width - ($hasLeft ? 1 : 0) - ($hasRight ? 1 : 0));
            $end = min(count($characters), $start + $contentWidth);

            if ($highlightIndex < $end) {
                break;
            }

            $start = max(0, $highlightIndex - $contentWidth + 1);
        }

        $hasLeft = $start > 0;
        $contentWidth = max(1, $width - ($hasLeft ? 1 : 0) - 1);
        $end = min(count($characters), $start + $contentWidth);
        $hasRight = $end < count($characters);
        $contentWidth = max(1, $width - ($hasLeft ? 1 : 0) - ($hasRight ? 1 : 0));
        $end = min(count($characters), $start + $contentWidth);
        $visible = implode('', array_slice($characters, $start, $end - $start));
        $clipped = ($hasLeft ? '…' : '') . $visible . ($hasRight ? '…' : '');
        $column = $highlightIndex - $start + 1 + ($hasLeft ? 1 : 0);
        $length = max(1, min($highlightLength, $end - max($start, $highlightIndex)));

        return [$clipped, $column, $length];
    }

    private function sanitize(string $value): string
    {
        return preg_replace_callback(
            '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',
            static fn (array $match): string => sprintf('\\x%02X', ord($match[0])),
            str_replace("\r\n", "\n", str_replace("\r", "\n", Utf8::sanitize($value))),
        ) ?? $value;
    }

    private function style(string $value, string $style, ConsoleRenderOptions $options): string
    {
        return $options->decorated ? "\033[{$style}m{$value}\033[0m" : $value;
    }

    private function severityStyle(Severity $severity): string
    {
        return match ($severity) {
            Severity::Error => '31;1',
            Severity::Warning => '33;1',
            Severity::Note => '36;1',
        };
    }

    private function countCodePoints(string $value): int
    {
        $count = 0;
        $length = strlen($value);

        for ($offset = 0; $offset < $length; $offset++) {
            $count += (ord($value[$offset]) & 0xc0) !== 0x80 ? 1 : 0;
        }

        return $count;
    }
}
