<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Transpilation;

use Atatusoft\Ppphp\Semantic\Binding\LocalBinding;
use Atatusoft\Ppphp\Semantic\NodeSpanResolver;
use Atatusoft\Ppphp\Semantic\Type\CompositeTypeParser;
use PhpParser\Node;
use PhpParser\Node\Expr;

final class LocalBindingTypeRenderer
{
    public function render(LocalBinding $binding, TranspilationContext $context): string
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
}
