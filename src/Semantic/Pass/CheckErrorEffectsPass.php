<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Pass;

use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\DiagnosticLabel;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Semantic\Call\CallableContractResolver;
use Atatusoft\Ppphp\Semantic\Call\CallableResolutionStatus;
use Atatusoft\Ppphp\Semantic\Effect\CallableErrorContract;
use Atatusoft\Ppphp\Semantic\Effect\EffectCompatibility;
use Atatusoft\Ppphp\Semantic\Effect\Enumerations\ThrowableKind;
use Atatusoft\Ppphp\Semantic\Effect\ErrorAnalysisScope;
use Atatusoft\Ppphp\Semantic\Effect\ErrorFlow;
use Atatusoft\Ppphp\Semantic\Effect\ErrorOccurrence;
use Atatusoft\Ppphp\Semantic\Effect\ErrorSet;
use Atatusoft\Ppphp\Semantic\Effect\ThrowableHierarchy;
use Atatusoft\Ppphp\Semantic\Pass\Interfaces\SemanticPass;
use Atatusoft\Ppphp\Semantic\SemanticContext;
use Atatusoft\Ppphp\Semantic\SourceNameResolver;
use Atatusoft\Ppphp\Semantic\Symbol\ClassSymbol;
use Atatusoft\Ppphp\Semantic\Symbol\FunctionSymbol;
use Atatusoft\Ppphp\Semantic\Symbol\MethodSymbol;
use Atatusoft\Ppphp\Semantic\Symbol\PropertySymbol;
use Atatusoft\Ppphp\Semantic\Type\AtomicType;
use Atatusoft\Ppphp\Semantic\Type\GenericType;
use Atatusoft\Ppphp\Semantic\Type\Interfaces\Type;
use Atatusoft\Ppphp\Semantic\Type\IntersectionType;
use Atatusoft\Ppphp\Semantic\Type\MemberTypeResolver;
use Atatusoft\Ppphp\Semantic\Type\SourceTypeResolver;
use Atatusoft\Ppphp\Semantic\Type\TypeParameter;
use Atatusoft\Ppphp\Semantic\Type\TypedArrayType;
use Atatusoft\Ppphp\Semantic\Type\UnionType;
use Atatusoft\Ppphp\Semantic\Type\UnknownType;
use Atatusoft\Ppphp\Semantic\When\WhenExpressionAnalysis;
use Atatusoft\Ppphp\Source\Span;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

final class CheckErrorEffectsPass implements SemanticPass
{
    private SemanticContext $context;

    private ThrowableHierarchy $hierarchy;

    private MemberTypeResolver $memberTypes;

    private CallableContractResolver $callables;

    public function __construct(
        private readonly SourceNameResolver $sourceNames = new SourceNameResolver(),
        private readonly EffectCompatibility $effectCompatibility = new EffectCompatibility(),
        private readonly SourceTypeResolver $sourceTypes = new SourceTypeResolver(),
    ) {}

    public function execute(SemanticContext $context): void
    {
        $this->context = $context;
        $this->hierarchy = new ThrowableHierarchy($context->symbols);
        $this->memberTypes = new MemberTypeResolver($context->symbols);
        $this->callables = new CallableContractResolver($context);
        $scope = new ErrorAnalysisScope('file', null, null, $this->resolveFileVariableTypes());
        $fileFlow = $this->analyzeFileStatements($context->parsedFile->statements, $scope, '');
        $this->diagnoseEscapes($fileFlow->escapingErrors, $scope);
    }

    /** @param array<Stmt> $statements */
    private function analyzeFileStatements(array $statements, ErrorAnalysisScope $scope, string $namespace): ErrorFlow
    {
        $flow = ErrorFlow::createEmpty();

        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Namespace_) {
                $nested = $this->analyzeFileStatements(
                    array_values($statement->stmts),
                    $scope,
                    $statement->name?->toString() ?? '',
                );
                $flow = $flow->continueWith($nested);
                continue;
            }

            if ($statement instanceof Stmt\Function_) {
                $this->analyzeFunction($statement, $namespace);
                continue;
            }

            if ($statement instanceof Stmt\ClassLike && $statement->name !== null) {
                $this->analyzeClass($statement, $namespace);
                continue;
            }

            if ($statement instanceof Stmt\Use_ || $statement instanceof Stmt\GroupUse) {
                continue;
            }

            $flow = $flow->continueWith($this->analyzeNode($statement, $scope));
        }

        return $flow;
    }

    private function analyzeFunction(Stmt\Function_ $function, string $namespace): void
    {
        $name = $namespace === ''
            ? $function->name->toString()
            : $namespace . '\\' . $function->name->toString();
        $symbol = $this->context->symbols->findFunction($name);

        if ($symbol === null || $symbol->sourceFile !== $this->context->parsedFile->sourceFile) {
            return;
        }

        $scope = new ErrorAnalysisScope(
            'callable',
            $symbol->errorContract,
            null,
            $this->resolveVariableTypes($function, null),
        );
        $flow = $this->analyzeStatements($function->stmts, $scope);
        $this->diagnoseEscapes($flow->escapingErrors, $scope);
    }

    private function analyzeClass(Stmt\ClassLike $classNode, string $namespace): void
    {
        $name = $namespace === ''
            ? $classNode->name?->toString()
            : $namespace . '\\' . $classNode->name?->toString();
        $class = $name === null ? null : $this->context->symbols->findClass($name);

        if ($class === null || $class->sourceFile !== $this->context->parsedFile->sourceFile) {
            return;
        }

        foreach ($classNode->getMethods() as $methodNode) {
            $method = $class->findMethod($methodNode->name->toString());

            if ($method === null) {
                continue;
            }

            $destructor = strtolower($method->name) === '__destruct';
            $scope = new ErrorAnalysisScope(
                $destructor ? 'destructor' : 'callable',
                $method->errorContract,
                $class->fullyQualifiedName,
                $this->resolveVariableTypes($methodNode, $class->fullyQualifiedName),
            );
            $flow = $this->analyzeStatements($methodNode->stmts ?? [], $scope);
            $this->diagnoseEscapes($flow->escapingErrors, $scope);
            $this->checkOverride($class, $method);
        }
    }

    /** @param array<Stmt> $statements */
    private function analyzeStatements(array $statements, ErrorAnalysisScope $scope): ErrorFlow
    {
        $flow = ErrorFlow::createEmpty();

        foreach ($statements as $statement) {
            $flow = $flow->continueWith($this->analyzeNode($statement, $scope));
        }

        return $flow;
    }

    private function analyzeNode(Node $node, ErrorAnalysisScope $scope): ErrorFlow
    {
        if ($node instanceof Expr) {
            $when = $this->context->model->whenExpressions->findPlaceholder($node);

            if ($when !== null) {
                return $this->analyzeWhenExpression($when, $scope);
            }
        }

        if ($node instanceof Stmt\Function_) {
            $namespace = $this->sourceNames->resolveNamespaceAt(
                $this->context->parsedFile,
                $this->resolveSpan($node)->start->offset,
            );
            $this->analyzeFunction($node, $namespace);

            return ErrorFlow::createEmpty();
        }

        if ($node instanceof Stmt\ClassLike && $node->name !== null) {
            $namespace = $this->sourceNames->resolveNamespaceAt(
                $this->context->parsedFile,
                $this->resolveSpan($node)->start->offset,
            );
            $this->analyzeClass($node, $namespace);

            return ErrorFlow::createEmpty();
        }

        if ($node instanceof Stmt\TryCatch) {
            return $this->analyzeTry($node, $scope);
        }

        if ($node instanceof Stmt\If_) {
            return $this->analyzeIf($node, $scope);
        }
        if (
            $node instanceof Stmt\For_
            || $node instanceof Stmt\Foreach_
            || $node instanceof Stmt\While_
            || $node instanceof Stmt\Do_
        ) {
            return $this->analyzeLoop($node, $scope);
        }

        if ($node instanceof Expr\Closure) {
            $anonymous = new ErrorAnalysisScope(
                'anonymous',
                null,
                $scope->currentClass,
                $this->resolveVariableTypes($node, $scope->currentClass),
            );
            $flow = $this->analyzeStatements($node->stmts, $anonymous);
            $this->diagnoseEscapes($flow->escapingErrors, $anonymous);

            return ErrorFlow::createEmpty();
        }

        if ($node instanceof Expr\ArrowFunction) {
            $anonymous = new ErrorAnalysisScope(
                'anonymous',
                null,
                $scope->currentClass,
                $this->resolveVariableTypes($node, $scope->currentClass),
            );
            $flow = $this->analyzeNode($node->expr, $anonymous);
            $this->diagnoseEscapes($flow->escapingErrors, $anonymous);

            return ErrorFlow::createEmpty();
        }

        if ($node instanceof Expr\Throw_) {
            $nested = $this->analyzeNode($node->expr, $scope);
            $errors = $nested->escapingErrors;

            foreach ($this->resolveThrowableNames($this->resolveExpressionType($node->expr, $scope)) as $type) {
                if ($this->hierarchy->classify($type) === ThrowableKind::Checked) {
                    $errors = $errors->combine($this->createSingleErrorSet($type, $this->resolveSpan($node)));
                }
            }

            return new ErrorFlow($errors, false);
        }

        if ($node instanceof Expr\FuncCall) {
            return $this->analyzeFunctionCall($node, $scope);
        }

        if ($node instanceof Expr\StaticCall) {
            return $this->analyzeStaticCall($node, $scope);
        }

        if ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) {
            return $this->analyzeMethodCall($node, $scope);
        }

        if ($node instanceof Expr\New_) {
            return $this->analyzeNew($node, $scope);
        }

        if ($node instanceof Stmt\Return_ || $node instanceof Expr\Exit_) {
            $nested = ErrorFlow::createEmpty();

            foreach ($this->resolveChildren($node) as $child) {
                $nested = $nested->continueWith($this->analyzeNode($child, $scope));
            }

            return new ErrorFlow($nested->escapingErrors, false);
        }

        $flow = ErrorFlow::createEmpty();

        foreach ($this->resolveChildren($node) as $child) {
            $flow = $flow->continueWith($this->analyzeNode($child, $scope));
        }

        return $flow;
    }

    private function analyzeWhenExpression(
        WhenExpressionAnalysis $when,
        ErrorAnalysisScope $scope,
    ): ErrorFlow {
        $errors = new ErrorSet();

        foreach ($when->branches as $branch) {
            if ($branch->condition !== null) {
                $errors = $errors->combine(
                    $this->analyzeNode($branch->condition, $scope)->escapingErrors,
                );
            }

            $errors = $errors->combine(
                $this->analyzeStatements($branch->statements, $scope)->escapingErrors,
            );
        }

        // Branch-level returns yield the expression value. They do not terminate
        // the enclosing PHP statement, so the lowered expression completes here.
        return new ErrorFlow($errors, true);
    }

    private function analyzeFunctionCall(Expr\FuncCall $call, ErrorAnalysisScope $scope): ErrorFlow
    {
        $flow = $this->analyzeArguments($call->args, $scope);

        if (!$call->name instanceof Node\Name) {
            $this->addDynamicBoundary($this->resolveSpan($call));

            return $flow;
        }

        $name = strtolower(ltrim($call->name->toString(), '\\'));

        if (in_array($name, ['call_user_func', 'call_user_func_array'], true)) {
            $this->addDynamicBoundary($this->resolveSpan($call));

            return $flow;
        }

        $contract = $this->callables->resolveFunction($call->name)->contract?->errorContract;

        if ($contract === null) {
            return $flow;
        }

        return $flow->continueWith($this->flowFromContract($contract, $this->resolveSpan($call)));
    }

    private function analyzeStaticCall(Expr\StaticCall $call, ErrorAnalysisScope $scope): ErrorFlow
    {
        $flow = $this->analyzeArguments($call->args, $scope);

        if (!$call->class instanceof Node\Name || !$call->name instanceof Node\Identifier) {
            $this->addDynamicBoundary($this->resolveSpan($call));

            return $flow;
        }

        $className = $this->resolveClassName($call->class, $scope);
        $contract = $className === null
            ? null
            : $this->callables->resolveMethod(new AtomicType($className), $call->name->toString())->contract;

        if ($contract === null) {
            return $flow;
        }

        return $flow->continueWith($this->flowFromContract($contract->errorContract, $this->resolveSpan($call)));
    }

    private function analyzeMethodCall(
        Expr\MethodCall|Expr\NullsafeMethodCall $call,
        ErrorAnalysisScope $scope,
    ): ErrorFlow {
        $flow = $this->analyzeNode($call->var, $scope)->continueWith($this->analyzeArguments($call->args, $scope));

        if (!$call->name instanceof Node\Identifier) {
            $this->addDynamicBoundary($this->resolveSpan($call));

            return $flow;
        }

        $receiver = $this->resolveExpressionType($call->var, $scope);
        $resolved = $this->callables->resolveMethod($receiver, $call->name->toString());

        if ($resolved->contract !== null) {
            $flow = $flow->continueWith($this->flowFromContract($resolved->contract->errorContract, $this->resolveSpan($call)));
        } elseif ($resolved->status !== CallableResolutionStatus::Missing && !$this->isStaticallyNamedReceiver($receiver)) {
            $this->addDynamicBoundary($this->resolveSpan($call));
        }

        return $flow;
    }

    private function analyzeNew(Expr\New_ $new, ErrorAnalysisScope $scope): ErrorFlow
    {
        $flow = $this->analyzeArguments($new->args, $scope);

        if (!$new->class instanceof Node\Name) {
            $this->addDynamicBoundary($this->resolveSpan($new));

            return $flow;
        }

        $className = $this->resolveClassName($new->class, $scope);

        if ($className === null) {
            $this->addDynamicBoundary($this->resolveSpan($new));

            return $flow;
        }

        $contract = $this->callables->resolveConstructor(new AtomicType($className))->contract;

        return $contract === null ? $flow : $flow->continueWith($this->flowFromContract($contract->errorContract, $this->resolveSpan($new)));
    }

    private function analyzeTry(Stmt\TryCatch $try, ErrorAnalysisScope $scope): ErrorFlow
    {
        $tryFlow = $this->analyzeStatements($try->stmts, $scope);
        $remaining = $tryFlow->escapingErrors;
        $catchErrors = new ErrorSet();
        $catchMayComplete = false;
        $previousCaught = [];

        foreach ($try->catches as $catch) {
            $caught = [];

            foreach ($catch->types as $type) {
                $resolved = $this->context->resolvedNames->resolve($type) ?? $type->toString();
                $caught[] = $resolved;

                if ($this->hierarchy->classify($resolved) === ThrowableKind::NotThrowable) {
                    $this->addDiagnostic(
                        DiagnosticCode::ErrorTypeNotThrowable,
                        sprintf('%s is not a valid catch type.', $resolved),
                        $this->resolveSpan($type),
                    );
                }

                foreach ($previousCaught as $earlier) {
                    if ($this->hierarchy->matchesSubtype($resolved, $earlier)) {
                        $this->addDiagnostic(
                            DiagnosticCode::ErrorCatchUnreachable,
                            sprintf('%s is already handled by an earlier catch.', $resolved),
                            $this->resolveSpan($type),
                        );
                        break;
                    }
                }
            }

            $remaining = $remaining->excludeCaught($caught, $this->hierarchy);
            array_push($previousCaught, ...$caught);
            $catchScope = $scope;

            if ($catch->var instanceof Expr\Variable && is_string($catch->var->name)) {
                $catchScope = $scope->includeVariable(
                    '$' . $catch->var->name,
                    $this->combineTypes(array_map(static fn (string $type): Type => new AtomicType($type), $caught)),
                );
            }

            $catchFlow = $this->analyzeStatements($catch->stmts, $catchScope);
            $catchErrors = $catchErrors->combine($catchFlow->escapingErrors);
            $catchMayComplete = $catchMayComplete || $catchFlow->mayCompleteNormally;
        }

        $beforeFinally = $remaining->combine($catchErrors);
        $beforeFinallyMayComplete = $tryFlow->mayCompleteNormally || $catchMayComplete;

        if ($try->finally === null) {
            return new ErrorFlow($beforeFinally, $beforeFinallyMayComplete);
        }

        $finallyFlow = $this->analyzeStatements($try->finally->stmts, $scope);

        return $finallyFlow->mayCompleteNormally
            ? new ErrorFlow($beforeFinally->combine($finallyFlow->escapingErrors), $beforeFinallyMayComplete)
            : $finallyFlow;
    }

    private function analyzeIf(Stmt\If_ $if, ErrorAnalysisScope $scope): ErrorFlow
    {
        $errors = $this->analyzeNode($if->cond, $scope)->escapingErrors;
        $branches = [$this->analyzeStatements($if->stmts, $scope)];

        foreach ($if->elseifs as $elseif) {
            $condition = $this->analyzeNode($elseif->cond, $scope);
            $branch = $this->analyzeStatements($elseif->stmts, $scope);
            $errors = $errors->combine($condition->escapingErrors)->combine($branch->escapingErrors);
            $branches[] = $branch;
        }

        if ($if->else === null) {
            return new ErrorFlow($errors->combine($branches[0]->escapingErrors), true);
        }

        $else = $this->analyzeStatements($if->else->stmts, $scope);
        $errors = $errors->combine($branches[0]->escapingErrors)->combine($else->escapingErrors);
        $mayComplete = $else->mayCompleteNormally;

        foreach ($branches as $branch) {
            $mayComplete = $mayComplete || $branch->mayCompleteNormally;
        }

        return new ErrorFlow($errors, $mayComplete);
    }

    private function analyzeLoop(
        Stmt\For_|Stmt\Foreach_|Stmt\While_|Stmt\Do_ $loop,
        ErrorAnalysisScope $scope,
    ): ErrorFlow {
        if ($loop instanceof Stmt\Do_) {
            $body = $this->analyzeStatements($loop->stmts, $scope);
            $condition = $this->analyzeNode($loop->cond, $scope);

            return new ErrorFlow(
                $body->escapingErrors->combine($condition->escapingErrors),
                $body->mayCompleteNormally,
            );
        }

        $errors = new ErrorSet();

        foreach ($this->resolveChildren($loop) as $child) {
            $errors = $errors->combine($this->analyzeNode($child, $scope)->escapingErrors);
        }

        return new ErrorFlow($errors, true);
    }

    /** @param array<Node\Arg|Node\VariadicPlaceholder> $arguments */
    private function analyzeArguments(array $arguments, ErrorAnalysisScope $scope): ErrorFlow
    {
        $flow = ErrorFlow::createEmpty();

        foreach ($arguments as $argument) {
            if ($argument instanceof Node\Arg) {
                $flow = $flow->continueWith($this->analyzeNode($argument->value, $scope));
            }
        }

        return $flow;
    }

    private function flowFromContract(CallableErrorContract $contract, Span $callSpan): ErrorFlow
    {
        $errors = new ErrorSet();

        foreach ($contract->filterCheckedErrors($this->hierarchy) as $declared) {
            $errors->add(new ErrorOccurrence($declared->canonicalType, $callSpan, $declared->span));
        }

        return new ErrorFlow($errors);
    }

    private function resolveExpressionType(Expr $expression, ErrorAnalysisScope $scope): Type
    {
        if ($expression instanceof Expr\New_ && $expression->class instanceof Node\Name) {
            return $this->resolveNodeType($expression->class, $scope);
        }

        if ($expression instanceof Expr\Variable && is_string($expression->name)) {
            return $scope->variableTypes['$' . $expression->name] ?? new UnknownType();
        }

        if (
            ($expression instanceof Expr\PropertyFetch || $expression instanceof Expr\NullsafePropertyFetch)
            && $expression->name instanceof Node\Identifier
        ) {
            return $this->memberTypes->resolvePropertyType(
                $this->resolveExpressionType($expression->var, $scope),
                $expression->name->toString(),
                $expression instanceof Expr\NullsafePropertyFetch,
            );
        }

        if ($expression instanceof Expr\FuncCall && $expression->name instanceof Node\Name) {
            $contract = $this->callables->resolveFunction($expression->name)->contract;

            return $contract === null || $contract->returnType === null
                ? new UnknownType()
                : $contract->returnType;
        }

        if (
            $expression instanceof Expr\StaticCall
            && $expression->class instanceof Node\Name
            && $expression->name instanceof Node\Identifier
        ) {
            $resolution = $this->memberTypes->resolveMethod(
                $this->resolveNodeType($expression->class, $scope),
                $expression->name->toString(),
            );

            return $this->resolveMethodReturnType($resolution->targets, false);
        }

        if (
            ($expression instanceof Expr\MethodCall || $expression instanceof Expr\NullsafeMethodCall)
            && $expression->name instanceof Node\Identifier
        ) {
            $resolution = $this->memberTypes->resolveMethod(
                $this->resolveExpressionType($expression->var, $scope),
                $expression->name->toString(),
            );

            return $this->resolveMethodReturnType(
                $resolution->targets,
                $expression instanceof Expr\NullsafeMethodCall,
            );
        }

        return new UnknownType();
    }

    /**
     * @param list<array{
     *     member: MethodSymbol|PropertySymbol,
     *     owner: \Atatusoft\Ppphp\Semantic\Symbol\ClassSymbol,
     *     receiver: Type,
     *     calledReceiver: Type,
     *     substitutions: array<string, Type>
     * }> $targets
     */
    private function resolveMethodReturnType(array $targets, bool $nullable): Type
    {
        $types = [];

        foreach ($targets as $target) {
            $method = $target['member'];

            if (!$method instanceof MethodSymbol || $method->effectiveReturnType === null) {
                continue;
            }

            $types[] = $this->memberTypes->resolveTargetType(
                $method->effectiveReturnType,
                $target['receiver'],
                $target['substitutions'],
                $target['calledReceiver'],
            );
        }

        if ($nullable && $types !== []) {
            $types[] = new AtomicType('null');
        }

        return $this->combineTypes($types);
    }

    /** @param list<Type> $types */
    private function combineTypes(array $types): Type
    {
        $unique = [];

        foreach ($types as $type) {
            if (!$type->isUnknown) {
                $unique[$type->canonical] = $type;
            }
        }

        return match (count($unique)) {
            0 => new UnknownType(),
            1 => array_values($unique)[0],
            default => new UnionType(array_values($unique)),
        };
    }

    private function resolveClassName(Node\Name $name, ErrorAnalysisScope $scope): ?string
    {
        $lower = strtolower($name->toString());

        if (in_array($lower, ['self', 'static'], true)) {
            return $scope->currentClass;
        }

        if ($lower === 'parent') {
            return $scope->currentClass === null
                ? null
                : $this->context->symbols->findClass($scope->currentClass)?->parent;
        }

        $resolved = $this->context->resolvedNames->resolve($name);

        if ($resolved !== null) {
            return $resolved;
        }

        $originalOffset = $name->getAttribute('ppphpOriginalStart');

        return is_int($originalOffset)
            ? $this->sourceNames->resolve(
                $this->context->parsedFile,
                $name->toString(),
                $originalOffset,
            )
            : $name->toString();
    }

    private function resolveNodeType(Node $type, ErrorAnalysisScope $scope): Type
    {
        return $this->resolveContextualScopeType(
            $this->sourceTypes->resolveNode(
                $type,
                $this->context->parsedFile,
                $this->context->resolvedNames,
                $this->context->genericDeclarations,
            ),
            $scope->currentClass,
        );
    }

    private function resolveContextualScopeType(Type $type, ?string $currentClass): Type
    {
        if ($type instanceof AtomicType) {
            $lower = $type->canonical;

            if (in_array($lower, ['self', 'static'], true) && $currentClass !== null) {
                return new AtomicType($currentClass);
            }

            if ($lower === 'parent' && $currentClass !== null) {
                $parent = $this->context->symbols->findClass($currentClass)?->parent;

                return $parent === null ? new UnknownType() : new AtomicType($parent);
            }

            return $type;
        }

        if ($type instanceof TypeParameter) {
            return $type;
        }

        if ($type instanceof GenericType) {
            $base = $this->resolveContextualScopeType($type->base, $currentClass);

            return new GenericType(
                $base instanceof AtomicType ? $base : $type->base,
                array_map(fn (Type $argument): Type => $this->resolveContextualScopeType($argument, $currentClass), $type->arguments),
            );
        }

        if ($type instanceof TypedArrayType) {
            return new TypedArrayType(
                $this->resolveContextualScopeType($type->keyType, $currentClass),
                $this->resolveContextualScopeType($type->valueType, $currentClass),
                $type->isList,
            );
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            $members = array_map(fn (Type $member): Type => $this->resolveContextualScopeType($member, $currentClass), $type->members);

            return $type instanceof UnionType ? new UnionType($members) : new IntersectionType($members);
        }

        return $type;
    }

    private function checkOverride(ClassSymbol $class, MethodSymbol $method): void
    {
        if ($method->visibility === 'private' || strtolower($method->name) === '__construct') {
            return;
        }

        foreach ($this->resolveInheritedMethods($class, $method->name) as $inherited) {
            if ($inherited->visibility === 'private') {
                continue;
            }

            $incompatible = $this->effectCompatibility->filterIncompatibleErrors(
                $method->errorContract,
                $inherited->errorContract,
                $this->hierarchy,
            );

            foreach ($incompatible as $childError) {
                $implementation = $this->renderMethodName($class->fullyQualifiedName, $method->name);
                $parent = $this->renderMethodName($inherited->owner, $inherited->name);
                // Disambiguate equal short class names across namespaces.
                if ($implementation === $parent) {
                    $implementation = $class->fullyQualifiedName . '::' . $method->name . '()';
                    $parent = $inherited->owner . '::' . $inherited->name . '()';
                }
                $permitted = [];
                foreach ($inherited->errorContract->filterCheckedErrors($this->hierarchy) as $error) {
                    $permitted[] = '\\' . $error->canonicalType;
                }
                $permission = $permitted === [] ? 'does not declare any checked exceptions'
                    : 'permits only ' . implode(', ', $permitted) . ' and their subclasses';
                $exception = '\\' . $childError->canonicalType;
                $origin = $inherited->declarationSpan->sourceFile->declarationOrigin;
                $editable = in_array($origin, [
                    \Atatusoft\Ppphp\Analysis\Declaration\DeclarationOrigin::ProjectPpphp,
                    \Atatusoft\Ppphp\Analysis\Declaration\DeclarationOrigin::ProjectPhp,
                ], true);
                $help = sprintf('Handle %s inside %s so it does not escape. Removing the throws declaration alone does not handle an escaping exception.', $exception, $implementation);
                if ($editable) {
                    $syntax = $origin === \Atatusoft\Ppphp\Analysis\Declaration\DeclarationOrigin::ProjectPpphp
                        ? 'throws ' . $exception : '@throws ' . $exception;
                    $help = sprintf('If this exception belongs to the public contract, add `%s` to %s%s. This changes the public API; callers must handle or declare the exception. Every other inherited contract must also permit it.',
                        $syntax, $parent, $origin === \Atatusoft\Ppphp\Analysis\Declaration\DeclarationOrigin::ProjectPhp ? "'s PHPDoc" : '')
                        . "\n\nOtherwise, " . lcfirst($help);
                } else {
                    $help .= sprintf(' %s is dependency-owned; changing that boundary requires a dependency API change, not a local vendor edit.', $parent);
                }
                $this->context->model->diagnostics->add(new Diagnostic(
                    DiagnosticCode::CheckedErrorDeclarationNotCovariant,
                    sprintf('%s declares %s, but %s %s.', $implementation, $exception, $parent, $permission),
                    new DiagnosticLabel($childError->span, sprintf('%s is not permitted by %s.', $exception, $parent)),
                    [
                        new DiagnosticLabel($this->createContractSpan($inherited), sprintf('%s %s.', $parent, $permission)),
                        new DiagnosticLabel($this->createContractSpan($method), sprintf('%s declares the additional exception here.', $implementation)),
                    ],
                    $help,
                    identity: strtolower($class->fullyQualifiedName . '::' . $method->name . ':' . $inherited->owner . ':' . $childError->canonicalType),
                ));
            }
        }
    }

    private function createContractSpan(MethodSymbol $method): Span
    {
        $clause = $method->errorContract->nativeClause;
        if ($clause !== null) {
            return $method->declarationSpan->sourceFile->createSpan(
                $method->declarationSpan->start->offset, $clause->span->end->offset,
            );
        }
        return $method->hasBody ? $method->selectionSpan : $method->declarationSpan;
    }

    private function renderMethodName(string $owner, string $method): string
    {
        $parts = explode('\\', $owner);
        return end($parts) . '::' . $method . '()';
    }

    /** @return list<MethodSymbol> */
    private function resolveInheritedMethods(ClassSymbol $class, string $methodName): array
    {
        $methods = [];

        foreach ([...$class->interfaces, ...($class->parent === null ? [] : [$class->parent])] as $ancestorName) {
            $ancestor = $this->context->symbols->findClass($ancestorName);

            if ($ancestor === null) {
                continue;
            }

            $method = $ancestor->findMethod($methodName);

            if ($method !== null) {
                $methods[] = $method;
            }

            array_push($methods, ...$this->resolveInheritedMethods($ancestor, $methodName));
        }

        return $methods;
    }

    private function diagnoseEscapes(ErrorSet $errors, ErrorAnalysisScope $scope): void
    {
        foreach ($errors as $error) {
            if ($scope->contract?->covers($error, $this->hierarchy) === true) {
                continue;
            }

            [$code, $message] = match ($scope->kind) {
                'file' => [
                    DiagnosticCode::CheckedErrorCannotEscapeFileScope,
                    sprintf('%s must be caught before it escapes executable file scope.', $error->canonicalType),
                ],
                'anonymous' => [
                    DiagnosticCode::CheckedErrorCannotEscapeAnonymousCallable,
                    sprintf('%s must be caught inside this anonymous callable.', $error->canonicalType),
                ],
                'destructor' => [
                    DiagnosticCode::CheckedErrorCannotEscapeDestructor,
                    sprintf('%s must be caught before it escapes this destructor.', $error->canonicalType),
                ],
                default => [
                    DiagnosticCode::CheckedErrorNotHandled,
                    sprintf('%s is not caught and is not covered by the enclosing callable throws clause.', $error->canonicalType),
                ],
            };
            $related = [];

            if ($error->declarationSpan !== null) {
                $related[] = new DiagnosticLabel(
                    $error->declarationSpan,
                    'The called error contract is declared here.',
                );
            }

            if ($scope->contract !== null) {
                $related[] = new DiagnosticLabel(
                    $scope->contract->ownerSpan,
                    'The enclosing callable is declared here.',
                );
            }

            $help = sprintf('Catch \\%s around this operation so it cannot escape.', $error->canonicalType);
            if ($code === DiagnosticCode::CheckedErrorNotHandled) {
                $help .= sprintf(' If propagation is part of this callable\'s contract, add `throws \\%s` to its declaration and handle the exception at its callers.', $error->canonicalType);
            }
            $this->addDiagnostic($code, $message, $error->span, $related, $help);
        }
    }

    /**
     * @param Stmt\Function_|Stmt\ClassMethod|Expr\Closure|Expr\ArrowFunction $owner
     * @return array<string, Type>
     */
    private function resolveVariableTypes(Node $owner, ?string $currentClass): array
    {
        $variables = [];
        $typeScope = new ErrorAnalysisScope('types', null, $currentClass);

        foreach ($owner->params as $parameter) {
            if (!$parameter->var instanceof Expr\Variable || !is_string($parameter->var->name)) {
                continue;
            }

            if ($parameter->type !== null) {
                $variables['$' . $parameter->var->name] = $this->resolveNodeType($parameter->type, $typeScope);
            }
        }

        if ($currentClass !== null) {
            $class = $this->context->symbols->findClass($currentClass);
            $parameters = $class === null || $class->genericDeclaration === null
                ? []
                : $class->genericDeclaration->parameters;
            $variables['$this'] = $parameters === []
                ? new AtomicType($currentClass)
                : new GenericType(new AtomicType($currentClass), $parameters);
        }

        $ownerSpan = $this->resolveSpan($owner);

        foreach ($this->context->model->bindings->bindings as $binding) {
            if (
                $binding->declarationSpan->start->offset < $ownerSpan->start->offset
                || $binding->declarationSpan->end->offset > $ownerSpan->end->offset
                || !$this->belongsToCallable($owner, $binding->variableSpan)
            ) {
                continue;
            }

            $variables[$binding->name] = $this->resolveContextualScopeType(
                $binding->type->semanticType,
                $currentClass,
            );
        }

        return $variables;
    }

    /** @return array<string, Type> */
    private function resolveFileVariableTypes(): array
    {
        $variables = [];

        foreach ($this->context->model->bindings->bindings as $binding) {
            $insideCallable = false;

            foreach ($this->context->parsedFile->statements as $statement) {
                if ($this->isInsideCallable($statement, $binding->variableSpan)) {
                    $insideCallable = true;
                    break;
                }
            }

            if (!$insideCallable) {
                $variables[$binding->name] = $this->resolveContextualScopeType(
                    $binding->type->semanticType,
                    null,
                );
            }
        }

        return $variables;
    }

    private function isInsideCallable(Node $node, Span $variableSpan): bool
    {
        $span = $this->resolveSpan($node);

        if ($span->end->offset <= $variableSpan->start->offset || $span->start->offset >= $variableSpan->end->offset) {
            return false;
        }

        if (
            $node instanceof Stmt\Function_
            || $node instanceof Stmt\ClassMethod
            || $node instanceof Expr\Closure
            || $node instanceof Expr\ArrowFunction
        ) {
            return true;
        }

        foreach ($this->resolveChildren($node) as $child) {
            if ($this->isInsideCallable($child, $variableSpan)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function resolveThrowableNames(Type $type): array
    {
        if ($type instanceof AtomicType) {
            return $type->isBuiltin ? [] : [$type->name];
        }

        if ($type instanceof GenericType) {
            return [$type->base->name];
        }

        if ($type instanceof TypeParameter) {
            return $type->bound === null ? [] : $this->resolveThrowableNames($type->bound);
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            $names = [];

            foreach ($type->members as $member) {
                foreach ($this->resolveThrowableNames($member) as $name) {
                    $names[strtolower(ltrim($name, '\\'))] = $name;
                }
            }

            return array_values($names);
        }

        return [];
    }

    private function belongsToCallable(Node $owner, Span $variableSpan): bool
    {
        foreach ($this->resolveChildren($owner) as $child) {
            if (
                $this->resolveSpan($child)->end->offset <= $variableSpan->start->offset
                || $this->resolveSpan($child)->start->offset >= $variableSpan->end->offset
            ) {
                continue;
            }

            if ($child instanceof Expr\Closure || $child instanceof Expr\ArrowFunction) {
                return false;
            }

            return $this->belongsToCallable($child, $variableSpan);
        }

        return true;
    }

    /** @return list<Node> */
    private function resolveChildren(Node $node): array
    {
        $children = [];

        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->{$name};

            if ($value instanceof Node) {
                $children[] = $value;
            } elseif (is_array($value)) {
                foreach ($value as $child) {
                    if ($child instanceof Node) {
                        $children[] = $child;
                    }
                }
            }
        }

        return $children;
    }

    private function createSingleErrorSet(string $type, Span $span): ErrorSet
    {
        $set = new ErrorSet();
        $set->add(new ErrorOccurrence($type, $span));

        return $set;
    }

    private function isStaticallyNamedReceiver(Type $type): bool
    {
        if ($type instanceof GenericType) {
            return true;
        }

        if ($type instanceof AtomicType) {
            return !$type->isBuiltin;
        }

        if ($type instanceof TypeParameter) {
            return $type->bound !== null && $this->isStaticallyNamedReceiver($type->bound);
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            $named = false;

            foreach ($type->members as $member) {
                if ($member instanceof AtomicType && $member->canonical === 'null') {
                    continue;
                }

                if (!$this->isStaticallyNamedReceiver($member)) {
                    return false;
                }

                $named = true;
            }

            return $named;
        }

        return false;
    }

    private function addDynamicBoundary(Span $span): void
    {
        $this->addDiagnostic(
            DiagnosticCode::UncheckedCallBoundary,
            'The checked-error contract cannot be determined for this invocation.',
            $span,
        );
    }

    /** @param list<DiagnosticLabel> $related */
    private function addDiagnostic(
        DiagnosticCode $code,
        string $message,
        Span $span,
        array $related = [],
        ?string $help = null,
    ): void {
        $this->context->model->diagnostics->add(new Diagnostic(
            $code,
            $message,
            new DiagnosticLabel($span, $message),
            $related,
            $help ?? ($code === DiagnosticCode::CheckedErrorNotHandled
                ? 'Catch the checked error or add it to the enclosing callable throws clause.'
                : null),
        ));
    }

    private function resolveSpan(Node $node): Span
    {
        $originalStart = $node->getAttribute('ppphpOriginalStart');
        $originalEnd = $node->getAttribute('ppphpOriginalEnd');

        if (is_int($originalStart) && is_int($originalEnd)) {
            return $this->context->parsedFile->sourceFile->createSpan($originalStart, $originalEnd);
        }

        $start = $node->getStartFilePos();
        $end = $node->getEndFilePos();

        return $start < 0 || $end < $start
            ? $this->context->parsedFile->sourceFile->createSpan(0, 0)
            : $this->context->parsedFile->sourceFile->createSpan($start, $end + 1);
    }
}
