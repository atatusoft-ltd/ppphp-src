<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Binding;

use Atatusoft\Ppphp\Diagnostics\DiagnosticLabel;
use Atatusoft\Ppphp\Semantic\Call\CallableContract;
use Atatusoft\Ppphp\Semantic\Call\CallableContractResolver;
use Atatusoft\Ppphp\Semantic\NodeSpanResolver;
use Atatusoft\Ppphp\Semantic\Scope\Scope;
use Atatusoft\Ppphp\Semantic\SemanticContext;
use Atatusoft\Ppphp\Semantic\Type\CompositeTypeValidator;
use Atatusoft\Ppphp\Semantic\Type\ExpressionTypeResolver;
use Atatusoft\Ppphp\Semantic\Type\SourceTypeRenderer;
use Atatusoft\Ppphp\Semantic\Type\SourceTypeResolver;
use Atatusoft\Ppphp\Source\Span;
use PhpParser\Node;
use PhpParser\Node\Expr;

final readonly class MissingLocalTypeReporter
{
    public function createRecovery(Expr\Assign $assignment, Scope $scope, SemanticContext $context, ?Span $statementSpan): RejectedLocalBinding
    {
        $spans = new NodeSpanResolver();
        $span = $spans->resolve($context->parsedFile, $assignment->var);
        $name = $span->text;
        $expressions = new ExpressionTypeResolver($context);
        $type = $expressions->resolve($assignment->expr, $scope)->semanticType;
        $rendered = (new SourceTypeRenderer())->render($type, $context, $span->start->offset);
        $contract = $this->resolveContract($assignment->expr, $scope, $context, $expressions);
        $evidence = $contract?->declarationSpan === null ? null
            : new DiagnosticLabel($contract->selectionSpan ?? $contract->declarationSpan, $contract->returnType === null ? 'This callable has no declared return type.' : 'The initializer uses this declared return contract.');
        $help = sprintf('Add an explicit type before %s. The compiler could not determine a suitable type from this initializer.', $name);
        $standalone = $statementSpan !== null && $statementSpan->start->offset === $span->start->offset;
        if (!$standalone) {
            $help = sprintf('Declare %s with an explicit type in a separate statement before this assignment expression; a type cannot be inserted at this position.', $name);
        } elseif ($rendered !== null && (new CompositeTypeValidator())->validateLocal($rendered) === []) {
            $corrected = $rendered . ' ' . $statementSpan->text;
            $help = $contract === null
                ? sprintf('The initializer has type %s. Write:', $rendered)
                : sprintf('%s() is declared to return %s. This suggestion uses its declaration, independently of checking its body. Write:',
                    $this->renderCallableName($contract->identity), $rendered);
            if ($contract?->returnType !== null && $contract->returnType->canonical !== $type->canonical) {
                $help = sprintf('%s() declares return type %s; this resolved initializer has type %s. This suggestion uses its declaration, independently of checking its body. Write:', $this->renderCallableName($contract->identity), $contract->returnType->renderPhpDoc(), $rendered);
            }
            $help .= "\n\n    " . str_replace("\n", "\n    ", $corrected);
        }
        return new RejectedLocalBinding($name, $span, $help, $evidence, $standalone);
    }

    private function resolveContract(Expr $value, Scope $scope, SemanticContext $context, ExpressionTypeResolver $expressions): ?CallableContract
    {
        $callables = new CallableContractResolver($context);
        if ($value instanceof Expr\FuncCall && $value->name instanceof Node\Name) {
            return $callables->resolveFunction($value->name)->contract;
        }
        if (($value instanceof Expr\MethodCall || $value instanceof Expr\NullsafeMethodCall) && $value->name instanceof Node\Identifier) {
            return $callables->resolveMethod($expressions->resolve($value->var, $scope)->semanticType, $value->name->toString())->contract;
        }
        if ($value instanceof Expr\StaticCall && $value->class instanceof Node\Name && $value->name instanceof Node\Identifier) {
            $receiver = (new SourceTypeResolver())->resolveNode($value->class, $context->parsedFile, $context->resolvedNames, $context->genericDeclarations);
            return $callables->resolveMethod($receiver, $value->name->toString())->contract;
        }
        return null;
    }

    private function renderCallableName(string $name): string
    {
        $parts = explode('\\', $name);
        return end($parts);
    }
}
