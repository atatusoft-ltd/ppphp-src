<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Experimental;

use Atatusoft\Ppphp\Semantic\Call\CallableContractResolver;
use Atatusoft\Ppphp\Semantic\Call\CallableOrigin;
use Atatusoft\Ppphp\Semantic\Generic\GenericDeclarationIndex;
use Atatusoft\Ppphp\Semantic\Scope\ScopeStack;
use Atatusoft\Ppphp\Semantic\SemanticAnalysisResult;
use Atatusoft\Ppphp\Semantic\SemanticContext;
use Atatusoft\Ppphp\Semantic\SemanticModel;
use Atatusoft\Ppphp\Semantic\Type\ExpressionResolutionStatus;
use Atatusoft\Ppphp\Semantic\Type\TypedArrayType;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;

/** Finds generated int assertions that would widen a proven array count. */
final readonly class CountAnnotationProof
{
    /** @return array<int, array{name: string, array: string}> By declaration owner offset. */
    public function collect(SemanticAnalysisResult $analysis, SemanticModel $model): array
    {
        $owners = [];
        $resolver = null;
        $file = $model->parsedFile;
        foreach ($model->bindings->bindings as $binding) {
            $call = $binding->initializerExpression;
            if (!$call instanceof Expr\FuncCall || !$call->name instanceof Name
                || strcasecmp($call->name->toString(), 'count') !== 0 || $call->isFirstClassCallable()
                || $binding->type->semanticType->canonical !== 'int' || count($call->getArgs()) !== 1) {
                continue;
            }

            $argument = $call->getArgs()[0];
            if ($argument->byRef || $argument->unpack || $argument->name !== null
                || !$argument->value instanceof Expr\Variable || !is_string($argument->value->name)) {
                continue;
            }

            $type = $model->expressionTypes->resolve($file->sourceFile, $argument->value);
            if ($type?->status !== ExpressionResolutionStatus::Known || !$type->type instanceof TypedArrayType) {
                continue;
            }

            $resolver ??= new CallableContractResolver(new SemanticContext(
                $file,
                $model,
                new ScopeStack(),
                $analysis->symbols,
                $analysis->resolvedNames,
                new GenericDeclarationIndex(),
            ));
            $contract = $resolver->resolveFunction($call->name)->contract;
            if ($contract?->origin !== CallableOrigin::IntrinsicOverride) {
                continue;
            }

            $owners[$binding->declarationSpan->start->offset] = ['name' => $binding->name, 'array' => $argument->value->name];
        }

        return $owners;
    }
}
