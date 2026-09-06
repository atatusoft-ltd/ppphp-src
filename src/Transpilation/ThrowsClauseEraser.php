<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Transpilation;

use Atatusoft\Ppphp\Frontend\Ast\ThrowsClause;

final class ThrowsClauseEraser
{
    public function erase(ThrowsClause $clause, TranspilationContext $context): void
    {
        $trivia = '';
        foreach ($context->parsedFile->tokens->tokens as $token) {
            if ($token->end <= $clause->span->start->offset || $token->start >= $clause->span->end->offset || !$token->isTrivia) {
                continue;
            }
            $start = max($clause->span->start->offset, $token->start);
            $end = min($clause->span->end->offset, $token->end);
            $trivia .= substr($token->text, $start - $token->start, $end - $start);
        }

        // Comments inside a contract are source content, not disposable layout.
        if (trim($trivia) !== '') {
            $context->replace($clause->span, $trivia);
            return;
        }

        $source = $clause->span->sourceFile;
        $start = $clause->span->start->offset;
        $end = $clause->span->end->offset;
        while ($start > 0 && str_contains(" \t", $source->contents[$start - 1])) {
            $start--;
        }
        $lineStart = $source->resolveLineStartOffset($clause->span->start->line);
        if ($start === $lineStart) {
            while ($end < $source->length && str_contains(" \t", $source->contents[$end])) {
                $end++;
            }
            if (substr($source->contents, $end, 2) === "\r\n") {
                $end += 2;
            } elseif (substr($source->contents, $end, 1) === "\n") {
                $end++;
            }
        }

        $context->replace($source->createSpan($start, $end), '');
    }
}
