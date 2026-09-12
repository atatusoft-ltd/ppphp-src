<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Transpilation\Pass;

use Atatusoft\Ppphp\Frontend\Ast\TypedLocalDeclaration;
use Atatusoft\Ppphp\Transpilation\LocalBindingTypeRenderer;
use Atatusoft\Ppphp\Transpilation\Pass\Interfaces\TranspilationPass;
use Atatusoft\Ppphp\Transpilation\TranspilationContext;

final class LowerLocalDeclarationsPass implements TranspilationPass
{
    public function execute(TranspilationContext $context): void
    {
        foreach ($context->parsedFile->extensionSyntax->typedLocals as $declaration) {
            $binding = $context->semanticModel->bindings->find($declaration->id);

            if ($binding === null) {
                throw new \LogicException('A typed local cannot be lowered without its semantic binding.');
            }

            if ($this->containsWhenExpression($declaration, $context)
                || !$context->shouldEmitLocalAnnotation($declaration->span, $binding->name)) {
                $prefix = $declaration->span->sourceFile->createSpan(
                    $declaration->span->start->offset,
                    $declaration->variableSpan->start->offset,
                );
                $trivia = $this->resolveTrivia($declaration, $context);
                $context->replace($prefix, !$context->shouldEmitLocalAnnotation($declaration->span, $binding->name)
                    ? ltrim($trivia, " \t") : $trivia);

                continue;
            }

            $prefix = $declaration->span->sourceFile->createSpan(
                $declaration->span->start->offset,
                $declaration->variableSpan->start->offset,
            );
            $context->replace(
                $prefix,
                sprintf(
                    '/** @var %s %s */%s',
                    (new LocalBindingTypeRenderer())->render($binding, $context),
                    $binding->name,
                    $this->resolveTrivia($declaration, $context),
                ),
            );
        }
    }

    private function containsWhenExpression(
        TypedLocalDeclaration $declaration,
        TranspilationContext $context,
    ): bool {
        foreach ($context->semanticModel->whenExpressions->expressions as $analysis) {
            if (
                $analysis->syntax->span->start->offset >= $declaration->initializerSpan->start->offset
                && $analysis->syntax->span->end->offset <= $declaration->initializerSpan->end->offset
            ) {
                return true;
            }
        }

        return false;
    }

    private function resolveTrivia(
        TypedLocalDeclaration $declaration,
        TranspilationContext $context,
    ): string {
        $start = $declaration->span->start->offset;
        $end = $declaration->variableSpan->start->offset;
        $trivia = '';

        foreach ($context->parsedFile->tokens->tokens as $token) {
            if ($token->end <= $start || $token->start >= $end || !$token->isTrivia) {
                continue;
            }

            $overlapStart = max($start, $token->start);
            $overlapEnd = min($end, $token->end);
            $trivia .= substr($token->text, $overlapStart - $token->start, $overlapEnd - $overlapStart);
        }

        return trim($trivia) === '' ? ' ' : $trivia;
    }
}
