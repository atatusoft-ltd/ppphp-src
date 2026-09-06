<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Transpilation\Pass;

use Atatusoft\Ppphp\Frontend\Ast\TypedLocalDeclaration;
use Atatusoft\Ppphp\Semantic\Binding\LocalBinding;
use Atatusoft\Ppphp\Semantic\NodeSpanResolver;
use Atatusoft\Ppphp\Semantic\Type\CompositeTypeParser;
use Atatusoft\Ppphp\Transpilation\Pass\Interfaces\TranspilationPass;
use Atatusoft\Ppphp\Transpilation\TranspilationContext;
use PhpParser\Node;
use PhpParser\Node\Expr;

final class LowerLocalDeclarationsPass implements TranspilationPass
{
    public function execute(TranspilationContext $context): void
    {
        foreach ($context->parsedFile->extensionSyntax->typedLocals as $declaration) {
            $binding = $context->semanticModel->bindings->find($declaration->id);

            if ($binding === null) {
                throw new \LogicException('A typed local cannot be lowered without its semantic binding.');
            }

            if ($this->containsWhenExpression($declaration, $context)) {
                $prefix = $declaration->span->sourceFile->createSpan(
                    $declaration->span->start->offset,
                    $declaration->variableSpan->start->offset,
                );
                $context->replace($prefix, $this->resolveTrivia($declaration, $context));

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
                    $this->renderType($binding, $context),
                    $binding->name,
                    $this->resolveTrivia($declaration, $context),
                ),
            );
        }
    }

    private function renderType(LocalBinding $binding, TranspilationContext $context): string
    {
        $type = $binding->type->semanticType->renderPhpDoc();
        $initializer = $binding->initializerExpression;
        if ((!$initializer instanceof Expr\Closure && !$initializer instanceof Expr\ArrowFunction)
            || !in_array($binding->type->canonical, ['closure', 'callable'], true)) {
            return $type;
        }

        $parameters = [];
        foreach ($initializer->params as $parameter) {
            $parameters[] = $this->renderSignatureType($parameter->type, $context)
                . ' ' . ($parameter->byRef ? '&' : '')
                . ($parameter->variadic ? '...' : '')
                . ($parameter->var instanceof Expr\Variable && is_string($parameter->var->name) ? '$' . $parameter->var->name : '')
                . ($parameter->default !== null ? '=' : '');
        }

        return $type . '(' . implode(', ', $parameters) . '): '
            . $this->renderSignatureType($initializer->returnType, $context);
    }

    private function renderSignatureType(?Node $node, TranspilationContext $context): string
    {
        if ($node === null) {
            return 'mixed';
        }

        $span = (new NodeSpanResolver())->resolve($context->parsedFile, $node);
        $end = $span->end->offset;
        foreach ($context->parsedFile->extensionSyntax->genericTypes as $reference) {
            if ($reference->nameSpan->start->offset >= $span->start->offset
                && $reference->nameSpan->start->offset < $span->end->offset) {
                $end = max($end, $reference->span->end->offset);
            }
        }

        return (new CompositeTypeParser())->parse(
            $span->sourceFile->createSpan($span->start->offset, $end)->text,
        )->renderPhpDoc();
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
