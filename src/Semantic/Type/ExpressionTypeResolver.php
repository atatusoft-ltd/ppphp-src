<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Type;

use Atatusoft\Ppphp\Semantic\Call\CallArgumentBinder;
use Atatusoft\Ppphp\Semantic\Call\CallableContractResolver;
use Atatusoft\Ppphp\Semantic\Call\CallableResolutionStatus;
use Atatusoft\Ppphp\Semantic\Call\GenericCallInference;
use Atatusoft\Ppphp\Semantic\Scope\Scope;
use Atatusoft\Ppphp\Semantic\SemanticContext;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;

final class ExpressionTypeResolver
{
    private readonly SourceTypeResolver $sourceTypes;

    private readonly ?MemberTypeResolver $members;

    private readonly ?CallableContractResolver $callables;

    private readonly CallArgumentBinder $argumentBinder;

    private readonly GenericCallInference $genericInference;

    public function __construct(
        private readonly ?SemanticContext $context = null,
        ?SourceTypeResolver $sourceTypes = null,
    ) {
        $this->sourceTypes = $sourceTypes ?? new SourceTypeResolver();
        $this->members = $context === null ? null : new MemberTypeResolver($context->symbols);
        $this->callables = $context === null ? null : new CallableContractResolver($context);
        $this->argumentBinder = new CallArgumentBinder();
        $this->genericInference = new GenericCallInference();
    }

    public function resolve(Expr $expression, Scope $scope): LocalType
    {
        if (is_string($expression->getAttribute('ppphpWhenExpressionId'))) {
            if ($this->context !== null) {
                $when = $this->context->model->whenExpressions->findPlaceholder($expression);

                if ($when !== null) {
                    return $when->resultType;
                }
            }

            return LocalType::createUnknown();
        }

        if ($expression instanceof Scalar\Int_) {
            return LocalType::createAtomic('int');
        }

        if ($expression instanceof Scalar\Float_) {
            return LocalType::createAtomic('float');
        }

        if ($expression instanceof Scalar\String_) {
            return LocalType::createAtomic('string');
        }

        if ($expression instanceof Expr\Array_) {
            return LocalType::createFromSemanticType($this->resolveArrayLiteral($expression, $scope));
        }

        if ($expression instanceof Expr\Closure || $expression instanceof Expr\ArrowFunction) {
            return LocalType::createAtomic('Closure');
        }

        if ($expression instanceof Expr\ConstFetch) {
            $builtin = match (strtolower($expression->name->toString())) {
                'null' => LocalType::createAtomic('null'),
                'true' => LocalType::createAtomic('true'),
                'false' => LocalType::createAtomic('false'),
                default => null,
            };

            if ($builtin !== null) {
                return $builtin;
            }

            if ($this->context !== null) {
                $resolved = $this->context->resolvedNames->resolve($expression->name)
                    ?? $expression->name->toString();
                $constant = $this->context->symbols->findConstant($resolved);

                if ($constant === null && $expression->name->isUnqualified()) {
                    $constant = $this->context->symbols->findConstant($expression->name->toString());
                }

                if ($constant?->type !== null) {
                    return LocalType::createFromSemanticType($constant->type);
                }
            }

            return LocalType::createUnknown();
        }

        if ($expression instanceof Expr\New_ && $expression->class instanceof Node\Name) {
            $receiver = $this->context === null
                ? new AtomicType($expression->class->toString())
                : $this->sourceTypes->resolveNode(
                    $expression->class,
                    $this->context->parsedFile,
                    $this->context->resolvedNames,
                    $this->context->genericDeclarations,
                );

            return LocalType::createFromSemanticType($this->resolveConstructedType($receiver, $expression, $scope));
        }

        if ($expression instanceof Expr\Assign || $expression instanceof Expr\AssignRef) {
            return $this->resolve($expression->expr, $scope);
        }

        if ($expression instanceof Expr\AssignOp\Concat) {
            return LocalType::createAtomic('string');
        }

        if ($expression instanceof Expr\AssignOp\Coalesce) {
            return LocalType::createFromSemanticType($this->combineTypes([
                $this->withoutNull($this->resolve($expression->var, $scope)->semanticType),
                $this->resolve($expression->expr, $scope)->semanticType,
            ]));
        }

        if ($expression instanceof Expr\AssignOp\Plus || $expression instanceof Expr\AssignOp\Minus
            || $expression instanceof Expr\AssignOp\Mul || $expression instanceof Expr\AssignOp\Div
            || $expression instanceof Expr\AssignOp\Mod || $expression instanceof Expr\AssignOp\Pow) {
            $left = $this->resolve($expression->var, $scope);
            $right = $this->resolve($expression->expr, $scope);

            if ($expression instanceof Expr\AssignOp\Div) {
                return LocalType::createAtomic('float');
            }

            if ($left->includes('float') || $right->includes('float')) {
                return LocalType::createAtomic('float');
            }

            return $left->includes('int') && $right->includes('int')
                ? LocalType::createAtomic('int')
                : LocalType::createUnknown();
        }

        if ($expression instanceof Expr\Variable && is_string($expression->name)) {
            return $scope->resolve('$' . $expression->name)->type ?? LocalType::createUnknown();
        }

        if ($expression instanceof Expr\ArrayDimFetch) {
            $container = $this->resolve($expression->var, $scope)->semanticType;
            $valueType = $this->resolveArrayValueType($container);

            return $valueType === null
                ? LocalType::createUnknown()
                : LocalType::createFromSemanticType($valueType);
        }

        if (
            ($expression instanceof Expr\PropertyFetch || $expression instanceof Expr\NullsafePropertyFetch)
            && $expression->name instanceof Node\Identifier
            && $this->members !== null
        ) {
            return LocalType::createFromSemanticType($this->members->resolvePropertyType(
                $this->resolve($expression->var, $scope)->semanticType,
                $expression->name->toString(),
                $expression instanceof Expr\NullsafePropertyFetch,
            ));
        }

        if (
            ($expression instanceof Expr\MethodCall || $expression instanceof Expr\NullsafeMethodCall)
            && $expression->name instanceof Node\Identifier
            && $this->members !== null
        ) {
            return $this->resolveMethodCall($expression, $scope);
        }

        if (
            $expression instanceof Expr\StaticCall
            && $expression->class instanceof Node\Name
            && $expression->name instanceof Node\Identifier
            && $this->members !== null
            && $this->context !== null
        ) {
            $receiver = $this->sourceTypes->resolveNode(
                $expression->class,
                $this->context->parsedFile,
                $this->context->resolvedNames,
                $this->context->genericDeclarations,
            );

            return $this->resolveStaticCall($expression, $receiver, $scope);
        }

        if (
            $expression instanceof Expr\StaticPropertyFetch
            && $expression->class instanceof Node\Name
            && $expression->name instanceof Node\VarLikeIdentifier
            && $this->members !== null
            && $this->context !== null
        ) {
            return LocalType::createFromSemanticType($this->members->resolvePropertyType(
                $this->sourceTypes->resolveNode(
                    $expression->class,
                    $this->context->parsedFile,
                    $this->context->resolvedNames,
                    $this->context->genericDeclarations,
                ),
                $expression->name->toString(),
            ));
        }

        if ($expression instanceof Expr\FuncCall && $expression->name instanceof Node\Name) {
            return $this->resolveFunctionCall($expression, $scope);
        }

        if ($expression instanceof Expr\Cast\Int_) {
            return LocalType::createAtomic('int');
        }

        if ($expression instanceof Expr\Cast\Double) {
            return LocalType::createAtomic('float');
        }

        if ($expression instanceof Expr\Cast\String_) {
            return LocalType::createAtomic('string');
        }

        if ($expression instanceof Expr\Cast\Bool_) {
            return LocalType::createAtomic('bool');
        }

        if ($expression instanceof Expr\Cast\Array_) {
            return LocalType::createAtomic('array');
        }

        if ($expression instanceof Expr\Cast\Object_) {
            return LocalType::createAtomic('object');
        }

        if ($expression instanceof Expr\BooleanNot || $expression instanceof Expr\Instanceof_) {
            return LocalType::createAtomic('bool');
        }

        if ($expression instanceof Expr\UnaryMinus || $expression instanceof Expr\UnaryPlus) {
            $operand = $this->resolve($expression->expr, $scope);

            if ($operand->includes('int')) {
                return LocalType::createAtomic('int');
            }

            if ($operand->includes('float')) {
                return LocalType::createAtomic('float');
            }

            return LocalType::createUnknown();
        }

        if ($expression instanceof Expr\PreInc || $expression instanceof Expr\PostInc
            || $expression instanceof Expr\PreDec || $expression instanceof Expr\PostDec) {
            return $this->resolve($expression->var, $scope);
        }

        if ($expression instanceof Expr\BinaryOp\Concat) {
            return LocalType::createAtomic('string');
        }

        if (
            $expression instanceof Expr\BinaryOp\Equal
            || $expression instanceof Expr\BinaryOp\Greater
            || $expression instanceof Expr\BinaryOp\GreaterOrEqual
            || $expression instanceof Expr\BinaryOp\Identical
            || $expression instanceof Expr\BinaryOp\LogicalAnd
            || $expression instanceof Expr\BinaryOp\LogicalOr
            || $expression instanceof Expr\BinaryOp\LogicalXor
            || $expression instanceof Expr\BinaryOp\NotEqual
            || $expression instanceof Expr\BinaryOp\NotIdentical
            || $expression instanceof Expr\BinaryOp\Smaller
            || $expression instanceof Expr\BinaryOp\SmallerOrEqual
            || $expression instanceof Expr\BinaryOp\BooleanAnd
            || $expression instanceof Expr\BinaryOp\BooleanOr
        ) {
            return LocalType::createAtomic('bool');
        }

        if ($expression instanceof Expr\BinaryOp\Spaceship) {
            return LocalType::createAtomic('int');
        }

        if (
            $expression instanceof Expr\BinaryOp\Plus
            || $expression instanceof Expr\BinaryOp\Minus
            || $expression instanceof Expr\BinaryOp\Mul
            || $expression instanceof Expr\BinaryOp\Pow
            || $expression instanceof Expr\BinaryOp\Mod
            || $expression instanceof Expr\BinaryOp\Div
        ) {
            return $this->resolveNumericBinaryOperation($expression, $scope);
        }

        if ($expression instanceof Expr\ClassConstFetch && $expression->name instanceof Node\Identifier) {
            if (strtolower($expression->name->name) === 'class') {
                return LocalType::createAtomic('string');
            }

            if ($expression->class instanceof Node\Name && $this->context !== null) {
                $receiver = $this->sourceTypes->resolveNode(
                    $expression->class,
                    $this->context->parsedFile,
                    $this->context->resolvedNames,
                    $this->context->genericDeclarations,
                );
                $constantType = $this->resolveClassConstantType($receiver, $expression->name->toString());

                if ($constantType !== null) {
                    return LocalType::createFromSemanticType($constantType);
                }
            }
        }

        if ($expression instanceof Expr\Ternary) {
            $true = $expression->if === null
                ? $this->resolve($expression->cond, $scope)
                : $this->resolve($expression->if, $scope);
            $false = $this->resolve($expression->else, $scope);

            if ($true->unknown || $false->unknown) {
                return LocalType::createUnknown();
            }

            return LocalType::createFromSemanticType($this->combineTypes([
                $true->semanticType,
                $false->semanticType,
            ]));
        }

        if ($expression instanceof Expr\BinaryOp\Coalesce) {
            $left = $this->withoutNull($this->resolve($expression->left, $scope)->semanticType);
            $right = $this->resolve($expression->right, $scope)->semanticType;

            return LocalType::createFromSemanticType($this->combineTypes([$left, $right]));
        }

        if ($expression instanceof Expr\Match_) {
            return LocalType::createFromSemanticType($this->combineTypes(array_values(array_map(
                fn (Node\MatchArm $arm): Interfaces\Type => $this->resolve($arm->body, $scope)->semanticType,
                $expression->arms,
            ))));
        }

        if ($expression instanceof Expr\Clone_) {
            return $this->resolve($expression->expr, $scope);
        }

        if ($expression instanceof Expr\Throw_ || $expression instanceof Expr\Exit_) {
            return LocalType::createAtomic('never');
        }

        return LocalType::createUnknown();
    }

    public function resolveDetailed(Expr $expression, Scope $scope): ExpressionTypeResolution
    {
        $type = $this->resolve($expression, $scope)->semanticType;

        if (!$type->isUnknown) {
            return ExpressionTypeResolution::known($type);
        }

        if (($expression instanceof Expr\FuncCall && !$expression->name instanceof Node\Name)
            || (($expression instanceof Expr\MethodCall
                || $expression instanceof Expr\NullsafeMethodCall
                || $expression instanceof Expr\StaticCall)
                && !$expression->name instanceof Node\Identifier)) {
            return ExpressionTypeResolution::unknown(
                ExpressionResolutionStatus::Dynamic,
                'dynamic-call-target',
            );
        }

        return ExpressionTypeResolution::unknown();
    }

    private function resolveFunctionCall(Expr\FuncCall $call, Scope $scope): LocalType
    {
        if (!$call->name instanceof Node\Name) {
            return LocalType::createUnknown();
        }

        $name = strtolower(ltrim($call->name->toString(), '\\'));
        $collection = $call->args[0] ?? null;

        if (($name === 'array_filter' || $name === 'array_values') && $collection instanceof Node\Arg) {
            $input = $this->resolve($collection->value, $scope)->semanticType;
            $transformed = $this->resolveCollectionFunction($name, $input);

            if ($transformed !== null) {
                return LocalType::createFromSemanticType($transformed);
            }
        }

        if ($this->context === null || $this->callables === null) {
            return LocalType::createUnknown();
        }

        $resolved = $this->callables->resolveFunction($call->name);

        if ($resolved->status !== CallableResolutionStatus::Found || $resolved->contract === null) {
            return LocalType::createUnknown();
        }

        return LocalType::createFromSemanticType(
            $this->resolveContractReturnType($resolved->contract, $call->args, $scope),
        );
    }

    private function resolveMethodCall(
        Expr\MethodCall|Expr\NullsafeMethodCall $call,
        Scope $scope,
    ): LocalType {
        if ($this->callables === null || !$call->name instanceof Node\Identifier) {
            return LocalType::createUnknown();
        }

        $resolved = $this->callables->resolveMethod(
            $this->resolve($call->var, $scope)->semanticType,
            $call->name->toString(),
        );

        if ($resolved->status !== CallableResolutionStatus::Found || $resolved->contract === null) {
            return LocalType::createUnknown();
        }

        $return = $this->resolveContractReturnType($resolved->contract, $call->args, $scope);

        if ($call instanceof Expr\NullsafeMethodCall) {
            $return = $this->combineTypes([$return, new AtomicType('null')]);
        }

        return LocalType::createFromSemanticType($return);
    }

    private function resolveStaticCall(Expr\StaticCall $call, Interfaces\Type $receiver, Scope $scope): LocalType
    {
        if ($this->callables === null || !$call->name instanceof Node\Identifier) {
            return LocalType::createUnknown();
        }

        $resolved = $this->callables->resolveMethod($receiver, $call->name->toString());

        return $resolved->status !== CallableResolutionStatus::Found || $resolved->contract === null
            ? LocalType::createUnknown()
            : LocalType::createFromSemanticType(
                $this->resolveContractReturnType($resolved->contract, $call->args, $scope),
            );
    }

    /** @param array<Node\Arg|Node\VariadicPlaceholder> $arguments */
    private function resolveContractReturnType(
        \Atatusoft\Ppphp\Semantic\Call\CallableContract $contract,
        array $arguments,
        Scope $scope,
    ): Interfaces\Type {
        if ($contract->returnType === null) {
            return new UnknownType();
        }

        $constraints = [];

        foreach ($this->argumentBinder->bind($contract, $arguments)->arguments as $bound) {
            $parameterType = $bound->parameter->effectiveType();

            if ($parameterType !== null) {
                $constraints[] = [
                    'parameter' => (new TypeSubstitution($contract->receiverSubstitutions))->substitute($parameterType),
                    'actual' => $this->resolve($bound->argument->value, $scope)->semanticType,
                ];
            }
        }

        $inference = $this->genericInference->infer(
            $contract,
            $constraints,
            symbols: $this->context?->symbols,
        );

        return (new TypeSubstitution($inference->substitutions))->substitute($contract->returnType);
    }

    private function resolveConstructedType(
        Interfaces\Type $receiver,
        Expr\New_ $expression,
        Scope $scope,
    ): Interfaces\Type {
        if ($this->callables === null || $this->context === null) {
            return $receiver;
        }

        $resolved = $this->callables->resolveConstructor($receiver);

        if ($resolved->status !== CallableResolutionStatus::Found || $resolved->contract === null) {
            return $receiver;
        }

        $constraints = [];

        foreach ($this->argumentBinder->bind($resolved->contract, $expression->args)->arguments as $bound) {
            $parameterType = $bound->parameter->effectiveType();

            if ($parameterType !== null) {
                $constraints[] = [
                    'parameter' => $parameterType,
                    'actual' => $this->resolve($bound->argument->value, $scope)->semanticType,
                ];
            }
        }

        $inference = $this->genericInference->infer(
            $resolved->contract,
            $constraints,
            symbols: $this->context->symbols,
        );
        $name = $receiver instanceof GenericType ? $receiver->base->name : ($receiver instanceof AtomicType ? $receiver->name : null);
        $class = $name === null ? null : $this->context->symbols->findClass($name);

        if ($class?->genericDeclaration === null) {
            return $receiver;
        }

        $arguments = [];

        foreach ($class->genericDeclaration->parameters as $parameter) {
            $argument = $inference->substitutions[$parameter->canonical] ?? null;

            if ($argument === null) {
                return $receiver;
            }

            $arguments[] = $argument;
        }

        if ($arguments === []) {
            return $receiver;
        }

        return new GenericType(new AtomicType($class->fullyQualifiedName), $arguments);
    }

    private function resolveCollectionFunction(string $name, Interfaces\Type $type): ?Interfaces\Type
    {
        if ($type instanceof UnionType) {
            $members = [];

            foreach ($type->members as $member) {
                if ($member instanceof AtomicType && $member->canonical === 'null') {
                    continue;
                }

                $resolved = $this->resolveCollectionFunction($name, $member);

                if ($resolved === null) {
                    return null;
                }

                $members[] = $resolved;
            }

            if ($members === []) {
                return null;
            }

            return $this->members?->combine($members)
                ?? (count($members) === 1 ? $members[0] : new UnionType($members));
        }

        if (!$type instanceof TypedArrayType) {
            return null;
        }

        return $name === 'array_values'
            ? new TypedArrayType(new AtomicType('int'), $type->valueType, true)
            : new TypedArrayType($type->keyType, $type->valueType, false);
    }

    private function resolveArrayValueType(Interfaces\Type $type): ?Interfaces\Type
    {
        if ($type instanceof TypedArrayType) {
            return $type->valueType;
        }

        if (!$type instanceof UnionType) {
            return null;
        }

        $members = [];

        foreach ($type->members as $member) {
            $valueType = $this->resolveArrayValueType($member);

            if ($valueType !== null) {
                $members[$valueType->canonical] = $valueType;
            }
        }

        if ($members === []) {
            return null;
        }

        return count($members) === 1 ? reset($members) : new UnionType(array_values($members));
    }

    private function resolveNumericBinaryOperation(Expr\BinaryOp $expression, Scope $scope): LocalType
    {
        $left = $this->resolve($expression->left, $scope);
        $right = $this->resolve($expression->right, $scope);

        if ($expression instanceof Expr\BinaryOp\Div) {
            return $left->unknown || $right->unknown
                ? LocalType::createUnknown()
                : LocalType::createAtomic('float');
        }

        if ($left->includes('float') || $right->includes('float')) {
            return LocalType::createAtomic('float');
        }

        if ($left->includes('int') && $right->includes('int')) {
            return LocalType::createAtomic('int');
        }

        return LocalType::createUnknown();
    }

    private function resolveArrayLiteral(Expr\Array_ $array, Scope $scope): Interfaces\Type
    {
        if ($array->items === []) {
            return new AtomicType('array');
        }

        $keyTypes = [];
        $valueTypes = [];
        $isList = true;
        $expectedIndex = 0;

        foreach ($array->items as $item) {
            if ($item->unpack) {
                return new AtomicType('array');
            }

            $valueTypes[] = $this->resolve($item->value, $scope)->semanticType;

            if ($item->key === null) {
                $keyTypes[] = new AtomicType('int');
                $expectedIndex++;
                continue;
            }

            if ($item->key instanceof Scalar\Int_ && $item->key->value === $expectedIndex) {
                $keyTypes[] = new AtomicType('int');
                $expectedIndex++;
                continue;
            }

            $isList = false;
            $key = $this->resolve($item->key, $scope)->semanticType;

            if ($item->key instanceof Scalar\String_ && preg_match('/^(0|-?[1-9][0-9]*)$/', $item->key->value) === 1) {
                $key = new AtomicType('int');
            }

            $keyTypes[] = $key;
        }

        return new TypedArrayType(
            $isList ? new AtomicType('int') : $this->combineTypes($keyTypes),
            $this->combineTypes($valueTypes),
            $isList,
        );
    }

    /** @param list<Interfaces\Type> $types */
    private function combineTypes(array $types): Interfaces\Type
    {
        $members = [];

        foreach ($types as $type) {
            if ($type instanceof UnionType) {
                foreach ($type->members as $member) {
                    $members[$member->canonical] = $member;
                }
                continue;
            }

            if ($type instanceof AtomicType && $type->canonical === 'never') {
                continue;
            }

            $members[$type->canonical] = $type;
        }

        return match (count($members)) {
            0 => new AtomicType('never'),
            1 => array_values($members)[0],
            default => new UnionType(array_values($members)),
        };
    }

    private function withoutNull(Interfaces\Type $type): Interfaces\Type
    {
        if ($type instanceof AtomicType && $type->canonical === 'null') {
            return new AtomicType('never');
        }

        if (!$type instanceof UnionType) {
            return $type;
        }

        return $this->combineTypes(array_values(array_filter(
            $type->members,
            static fn (Interfaces\Type $member): bool => !($member instanceof AtomicType && $member->canonical === 'null'),
        )));
    }

    private function resolveClassConstantType(Interfaces\Type $receiver, string $name): ?Interfaces\Type
    {
        if ($this->context === null) {
            return null;
        }

        $className = match (true) {
            $receiver instanceof GenericType => $receiver->base->name,
            $receiver instanceof AtomicType && !$receiver->isBuiltin => $receiver->name,
            default => null,
        };
        $visited = [];

        while ($className !== null) {
            $class = $this->context->symbols->findClass($className);

            if ($class === null || isset($visited[strtolower($className)])) {
                return null;
            }

            $visited[strtolower($className)] = true;
            $constant = $class->findConstant($name);

            if ($constant !== null) {
                return $constant->type;
            }

            $className = $class->parent;
        }

        return null;
    }
}
