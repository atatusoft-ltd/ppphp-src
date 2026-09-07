<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\When;

use Atatusoft\Ppphp\Config\PhpTarget;
use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Diagnostics\DiagnosticLabel;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Frontend\Ast\WhenExpression;
use Atatusoft\Ppphp\Frontend\ParsedFile;
use Atatusoft\Ppphp\Frontend\PhpSyntaxMessage;
use Atatusoft\Ppphp\Source\Span;
use PhpParser\Error;
use PhpParser\ErrorHandler\Collecting;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;

final readonly class WhenFragmentParser
{
    private Parser $parser;

    public function __construct(string $targetPhpVersion = PhpTarget::DEFAULT)
    {
        if (!in_array($targetPhpVersion, PhpTarget::SUPPORTED, true)) {
            throw new \InvalidArgumentException('The when parser does not support the selected project target.');
        }
        $this->parser = (new ParserFactory())->createForVersion(PhpVersion::fromString($targetPhpVersion));
    }

    public function parseCondition(ParsedFile $file, Span $span): WhenFragmentParseResult
    {
        $prefix = '<?php return ';
        $suffix = ';';
        $result = $this->parse($file, $span, $prefix, $suffix);
        $return = $result->statements[0] ?? null;

        return new WhenFragmentParseResult(
            $return instanceof Stmt\Return_ ? $return->expr : null,
            [],
            $result->diagnostics,
        );
    }

    public function parseBody(ParsedFile $file, Span $bodySpan): WhenFragmentParseResult
    {
        $start = min($bodySpan->end->offset, $bodySpan->start->offset + 1);
        $end = max($start, $bodySpan->end->offset - 1);
        $span = $file->sourceFile->createSpan($start, $end);
        $prefix = '<?php function __ppphp_when_fragment(): void {';
        $result = $this->parse($file, $span, $prefix, '}');
        $function = $result->statements[0] ?? null;

        return new WhenFragmentParseResult(
            null,
            $function instanceof Stmt\Function_ ? array_values($function->stmts) : [],
            $result->diagnostics,
        );
    }

    private function parse(
        ParsedFile $file,
        Span $span,
        string $prefix,
        string $suffix,
    ): WhenFragmentParseResult {
        $normalized = $this->normalize($file, $span);
        $handler = new Collecting();
        $contents = $prefix . $normalized . $suffix;
        $statements = $this->parser->parse($contents, $handler);
        $diagnostics = new DiagnosticBag();

        foreach ($handler->getErrors() as $index => $error) {
            $missingSemicolon = $index === 0 && PhpSyntaxMessage::checkMissingSemicolon($error, $contents, $this->parser);
            $diagnostics->add($this->mapError($file, $span, strlen($prefix), $error, $missingSemicolon));
        }

        $statements = $statements === null ? [] : array_values($statements);

        foreach ($statements as $statement) {
            $this->mapNodeOffsets($statement, $file, $span, strlen($prefix));
        }

        return new WhenFragmentParseResult(null, $statements, $diagnostics);
    }

    private function normalize(ParsedFile $file, Span $span): string
    {
        $edits = [];

        foreach ($file->extensionSyntax->whenExpressions as $when) {
            if ($this->isContained($when->span, $span)) {
                $edits[] = [$when->span, $this->placeholder($when->span->text)];
            }
        }

        foreach ($file->extensionSyntax->typedLocals as $local) {
            $prefix = $file->sourceFile->createSpan($local->span->start->offset, $local->variableSpan->start->offset);
            if ($this->isContained($prefix, $span)) {
                $edits[] = [$prefix, $this->mask($prefix->text)];
            }
        }

        foreach ($file->extensionSyntax->typedForInitializers as $local) {
            $prefix = $file->sourceFile->createSpan($local->type->span->start->offset, $local->variableSpan->start->offset);
            if ($local->readonlySpan !== null) {
                $prefix = $file->sourceFile->createSpan($local->readonlySpan->start->offset, $local->variableSpan->start->offset);
            }
            if ($this->isContained($prefix, $span)) {
                $edits[] = [$prefix, $this->mask($prefix->text)];
            }
        }

        foreach ($file->extensionSyntax->typedForeachBindings as $binding) {
            $prefix = $file->sourceFile->createSpan($binding->type->span->start->offset, $binding->variableSpan->start->offset);
            if ($this->isContained($prefix, $span)) {
                $edits[] = [$prefix, $this->mask($prefix->text)];
            }
        }

        foreach ($file->extensionSyntax->genericDeclarations as $generic) {
            if ($this->isContained($generic->span, $span)) {
                $edits[] = [$generic->span, $this->mask($generic->span->text)];
            }
        }

        foreach ($file->extensionSyntax->genericTypes as $generic) {
            if ($this->isContained($generic->argumentListSpan, $span)) {
                $edits[] = [$generic->argumentListSpan, $this->mask($generic->argumentListSpan->text)];
            }
        }

        foreach ($file->extensionSyntax->throwsClauses as $throws) {
            if ($this->isContained($throws->span, $span)) {
                $edits[] = [$throws->span, $this->mask($throws->span->text)];
            }
        }

        usort($edits, static fn (array $left, array $right): int =>
            ($left[0]->start->offset <=> $right[0]->start->offset)
                ?: ($right[0]->end->offset <=> $left[0]->end->offset));
        $resolved = [];

        foreach ($edits as $edit) {
            $ownerKey = array_key_last($resolved);
            $owner = $ownerKey === null ? null : $resolved[$ownerKey];
            if ($owner !== null && $edit[0]->start->offset < $owner[0]->end->offset) {
                continue;
            }
            $resolved[] = $edit;
        }

        $contents = $span->text;
        foreach (array_reverse($resolved) as [$editSpan, $replacement]) {
            $relative = $editSpan->start->offset - $span->start->offset;
            $contents = substr_replace($contents, $replacement, $relative, strlen($replacement));
        }

        return $contents;
    }

    private function mapNodeOffsets(Node $node, ParsedFile $file, Span $span, int $prefixLength): void
    {
        $start = $node->getStartFilePos();
        $end = $node->getEndFilePos();

        if ($start >= $prefixLength && $start < $prefixLength + $span->end->offset - $span->start->offset) {
            $originalStart = $span->start->offset + $start - $prefixLength;
            $originalEnd = min($span->end->offset, $span->start->offset + $end + 1 - $prefixLength);
            $node->setAttribute('ppphpOriginalStart', $originalStart);
            $node->setAttribute('ppphpOriginalEnd', max($originalStart, $originalEnd));

            foreach ($file->extensionSyntax->whenExpressions as $when) {
                if ($when->span->start->offset === $originalStart) {
                    $node->setAttribute('ppphpWhenExpressionId', $when->id->value);
                    break;
                }
            }
        }

        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->{$name};
            if ($value instanceof Node) {
                $this->mapNodeOffsets($value, $file, $span, $prefixLength);
            } elseif (is_array($value)) {
                foreach ($value as $child) {
                    if ($child instanceof Node) {
                        $this->mapNodeOffsets($child, $file, $span, $prefixLength);
                    }
                }
            }
        }
    }

    private function mapError(ParsedFile $file, Span $span, int $prefixLength, Error $error, bool $missingSemicolon): Diagnostic
    {
        $position = $error->getAttributes()['startFilePos'] ?? null;
        $position = is_int($position) ? $position : $prefixLength;
        $offset = $position < $prefixLength
            ? $span->start->offset
            : min($span->end->offset, $span->start->offset + $position - $prefixLength);
        $end = $error->getAttributes()['endFilePos'] ?? $position;
        $length = is_int($end) ? max(1, $end - $position + 1) : 1;
        $errorSpan = $file->sourceFile->createSpan($offset, min($span->end->offset, $offset + $length));
        $message = PhpSyntaxMessage::format($error, $errorSpan->text, $missingSemicolon);

        return new Diagnostic(
            DiagnosticCode::WhenBranchCouldNotBeParsed,
            $message,
            new DiagnosticLabel($errorSpan, $message),
            debug: ['parserMessage' => $error->getRawMessage(), 'parserAttributes' => $error->getAttributes()],
        );
    }

    private function isContained(Span $candidate, Span $container): bool
    {
        return $candidate->start->offset >= $container->start->offset
            && $candidate->end->offset <= $container->end->offset;
    }

    private function mask(string $text): string
    {
        return preg_replace('/[^\r\n]/', ' ', $text) ?? $text;
    }

    private function placeholder(string $text): string
    {
        return substr_replace($this->mask($text), 'null', 0, 4);
    }
}
