<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Transpilation\Pass;

use Atatusoft\Ppphp\Frontend\Ast\TypedForInitializer;
use Atatusoft\Ppphp\Frontend\Ast\TypedForeachBinding;
use Atatusoft\Ppphp\Frontend\Ast\Interfaces\Node;
use Atatusoft\Ppphp\Transpilation\Pass\Interfaces\TranspilationPass;
use Atatusoft\Ppphp\Transpilation\TranspilationContext;
use Atatusoft\Ppphp\Transpilation\LocalBindingTypeRenderer;

final class LowerLoopDeclarationsPass implements TranspilationPass
{
    public function execute(TranspilationContext $context): void
    {
        /** @var array<int, list<TypedForInitializer|TypedForeachBinding>> $declarationsByLoop */
        $declarationsByLoop = [];

        foreach ($context->parsedFile->extensionSyntax->typedForInitializers as $declaration) {
            $declarationsByLoop[$declaration->loopKeywordSpan->start->offset][] = $declaration;
        }

        foreach ($context->parsedFile->extensionSyntax->typedForeachBindings as $declaration) {
            $declarationsByLoop[$declaration->loopKeywordSpan->start->offset][] = $declaration;
        }

        ksort($declarationsByLoop);

        foreach ($declarationsByLoop as $declarations) {
            if ($declarations === []) {
                continue;
            }

            usort($declarations, static fn (Node $left, Node $right): int =>
                $left->span->start->offset <=> $right->span->start->offset);
            $this->insertPhpDoc($declarations, $context);

            foreach ($declarations as $declaration) {
                $binding = $context->semanticModel->bindings->find($declaration->id);

                if ($binding === null) {
                    throw new \LogicException('A typed loop declaration cannot be lowered without its semantic binding.');
                }

                $prefix = $declaration->span->sourceFile->createSpan(
                    $declaration->span->start->offset,
                    $declaration->variableSpan->start->offset,
                );
                $context->replace($prefix, $this->resolveTrivia($prefix, $context));
            }
        }
    }

    /** @param non-empty-list<TypedForInitializer|TypedForeachBinding> $declarations */
    private function insertPhpDoc(array $declarations, TranspilationContext $context): void
    {
        $lines = [];

        foreach ($declarations as $declaration) {
            $binding = $context->semanticModel->bindings->find($declaration->id);

            if ($binding === null) {
                throw new \LogicException('A typed loop declaration cannot be lowered without its semantic binding.');
            }

            if ($context->shouldEmitLocalAnnotation($declaration->loopKeywordSpan, $binding->name)) {
                $lines[] = sprintf('@var %s %s', (new LocalBindingTypeRenderer())->render($binding, $context), $binding->name);
            }
        }

        if ($lines === []) {
            return;
        }
        $newline = str_contains($context->parsedFile->sourceFile->contents, "\r\n") ? "\r\n" : "\n";
        $doc = count($lines) === 1
            ? sprintf('/** %s */', $lines[0])
            : '/**' . $newline . ' * ' . implode($newline . ' * ', $lines) . $newline . ' */';
        $keywordSpan = $declarations[0]->loopKeywordSpan;
        $indent = $this->resolveIndentation($keywordSpan->start->offset, $context);
        $context->replace(
            $keywordSpan->sourceFile->createSpan($keywordSpan->start->offset, $keywordSpan->start->offset),
            $doc . $newline . $indent,
        );
    }

    private function resolveIndentation(int $offset, TranspilationContext $context): string
    {
        $source = $context->parsedFile->sourceFile->contents;
        $lineStart = max(
            strrpos(substr($source, 0, $offset), "\n") ?: -1,
            strrpos(substr($source, 0, $offset), "\r") ?: -1,
        ) + 1;
        $prefix = substr($source, $lineStart, $offset - $lineStart);

        return trim($prefix) === '' ? $prefix : '';
    }

    private function resolveTrivia(
        \Atatusoft\Ppphp\Source\Span $prefix,
        TranspilationContext $context,
    ): string {
        $trivia = '';

        foreach ($context->parsedFile->tokens->tokens as $token) {
            if (
                $token->end <= $prefix->start->offset
                || $token->start >= $prefix->end->offset
                || !$token->isTrivia
            ) {
                continue;
            }

            $overlapStart = max($prefix->start->offset, $token->start);
            $overlapEnd = min($prefix->end->offset, $token->end);
            $trivia .= substr($token->text, $overlapStart - $token->start, $overlapEnd - $overlapStart);
        }

        return ltrim($trivia, " \t");
    }
}
