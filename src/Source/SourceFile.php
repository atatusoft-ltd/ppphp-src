<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Source;

use Atatusoft\Ppphp\Analysis\Declaration\DeclarationOrigin;
use Atatusoft\Ppphp\Interop\Composer\DependencyDeclarationProvenance;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Support\Path;

final class SourceFile
{
    /** @var list<int> */
    private readonly array $lineStarts;

    public function __construct(
        string $path,
        public readonly string $displayPath,
        public readonly FileKind $kind,
        public readonly string $contents,
        ?DeclarationOrigin $declarationOrigin = null,
        public readonly ?DependencyDeclarationProvenance $dependencyProvenance = null,
    ) {
        if (!Path::isAbsolute($path)) {
            throw new \InvalidArgumentException('A source file path must be absolute.');
        }

        $this->path = Path::normalize($path);
        $this->declarationOrigin = $declarationOrigin ?? match ($kind) {
            FileKind::Ppphp => DeclarationOrigin::ProjectPpphp,
            FileKind::Php, FileKind::Configuration => DeclarationOrigin::ProjectPhp,
            FileKind::Stub => DeclarationOrigin::ConfiguredStub,
        };
        $this->lineStarts = $this->calculateLineStarts($contents);
    }

    public readonly string $path;

    public readonly DeclarationOrigin $declarationOrigin;

    public int $length {
        get => strlen($this->contents);
    }

    public int $lineCount {
        get => count($this->lineStarts);
    }

    public function resolveLineStartOffset(int $line): int
    {
        if ($line < 1 || $line > $this->lineCount) {
            throw new \OutOfBoundsException('The line is outside the source file.');
        }

        return $this->lineStarts[$line - 1];
    }

    public function resolvePositionAt(int $offset): Position
    {
        if ($offset < 0 || $offset > $this->length) {
            throw new \OutOfBoundsException('The position offset is outside the source file.');
        }

        $lineIndex = $this->resolveLineIndexAt($offset);
        $lineStart = $this->lineStarts[$lineIndex];
        $column = $this->countCodePoints($lineStart, $offset) + 1;

        return new Position($this, $offset, $lineIndex + 1, $column);
    }

    public function createSpan(int $startOffset, int $endOffset): Span
    {
        return new Span(
            $this->resolvePositionAt($startOffset),
            $this->resolvePositionAt($endOffset),
        );
    }

    public function readLineText(int $line): string
    {
        if ($line < 1 || $line > $this->lineCount) {
            throw new \OutOfBoundsException('The line is outside the source file.');
        }

        $start = $this->lineStarts[$line - 1];
        $end = $this->lineStarts[$line] ?? $this->length;
        $text = substr($this->contents, $start, $end - $start);

        if (str_ends_with($text, "\n")) {
            $text = substr($text, 0, -1);
        }

        if (str_ends_with($text, "\r")) {
            $text = substr($text, 0, -1);
        }

        return $text;
    }

    /** @return list<int> */
    private function calculateLineStarts(string $contents): array
    {
        $starts = [0];
        $length = strlen($contents);

        $offset = 0;
        while ($offset < $length) {
            $offset += strcspn($contents, "\r\n", $offset);
            if ($offset === $length) {
                break;
            }
            if ($contents[$offset] === "\r" && $offset + 1 < $length && $contents[$offset + 1] === "\n") {
                $offset++;
            }
            $starts[] = ++$offset;
        }

        return $starts;
    }

    private function resolveLineIndexAt(int $offset): int
    {
        $low = 0;
        $high = count($this->lineStarts) - 1;

        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);

            if ($this->lineStarts[$middle] <= $offset) {
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }

        return max(0, $high);
    }

    private function countCodePoints(int $start, int $end): int
    {
        $length = $end - $start;
        // Match bytes without UTF mode to retain columns for malformed input.
        $continuations = preg_match_all('/[\x80-\xBF]/', substr($this->contents, $start, $length));
        if ($continuations === false) {
            throw new \LogicException('Could not count source columns.');
        }

        return $length - $continuations;
    }
}
