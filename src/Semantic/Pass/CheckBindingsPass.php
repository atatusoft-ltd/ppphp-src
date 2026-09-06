<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Pass;

use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\DiagnosticLabel;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Frontend\Ast\TypedForInitializer;
use Atatusoft\Ppphp\Frontend\Ast\TypedForeachBinding;
use Atatusoft\Ppphp\Frontend\Ast\TypedLocalDeclaration;
use Atatusoft\Ppphp\Frontend\Ast\Enumerations\ForeachBindingPosition;
use Atatusoft\Ppphp\Semantic\Binding\Enumerations\BindingInitialization;
use Atatusoft\Ppphp\Semantic\Binding\Enumerations\BindingMutability;
use Atatusoft\Ppphp\Semantic\Binding\LocalBinding;
use Atatusoft\Ppphp\Semantic\Call\CallArgumentBinder;
use Atatusoft\Ppphp\Semantic\Call\CallableContract;
use Atatusoft\Ppphp\Semantic\Call\CallableContractResolver;
use Atatusoft\Ppphp\Semantic\Call\CallableResolutionStatus;
use Atatusoft\Ppphp\Semantic\Pass\Interfaces\SemanticPass;
use Atatusoft\Ppphp\Semantic\Scope\Scope;
use Atatusoft\Ppphp\Semantic\SemanticContext;
use Atatusoft\Ppphp\Semantic\Symbol\VariableSymbol;
use Atatusoft\Ppphp\Semantic\Symbol\ClassSymbol;
use Atatusoft\Ppphp\Semantic\Type\ExpressionTypeResolver;
use Atatusoft\Ppphp\Semantic\Type\AtomicType;
use Atatusoft\Ppphp\Semantic\Type\CompositeTypeValidator;
use Atatusoft\Ppphp\Semantic\Type\GenericType;
use Atatusoft\Ppphp\Semantic\Type\IntersectionType;
use Atatusoft\Ppphp\Semantic\Type\LocalType;
use Atatusoft\Ppphp\Semantic\Type\TypeCompatibility;
use Atatusoft\Ppphp\Semantic\Type\TypedArrayType;
use Atatusoft\Ppphp\Semantic\Type\TypeParameter;
use Atatusoft\Ppphp\Semantic\Type\SourceTypeResolver;
use Atatusoft\Ppphp\Semantic\Type\UnionType;
use Atatusoft\Ppphp\Semantic\Type\Interfaces\Type;
use Atatusoft\Ppphp\Source\Span;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt;

final class CheckBindingsPass implements SemanticPass
{
    private const MUTATING_FUNCTIONS = [
        'array_multisort',
        'array_pop',
        'array_push',
        'array_shift',
        'array_splice',
        'array_unshift',
        'arsort',
        'asort',
        'end',
        'krsort',
        'ksort',
        'next',
        'prev',
        'reset',
        'rsort',
        'shuffle',
        'sort',
    ];

    private SemanticContext $context;

    /** @var array<int, TypedLocalDeclaration> */
    private array $declarationsByVariableOffset = [];

    /** @var array<int, TypedForInitializer> */
    private array $forInitializersByVariableOffset = [];

    /** @var array<int, TypedForeachBinding> */
    private array $foreachBindingsByVariableOffset = [];

    private ExpressionTypeResolver $expressionTypes;

    private readonly ?ExpressionTypeResolver $configuredExpressionTypes;

    private readonly TypeCompatibility $compatibility;

    private readonly CompositeTypeValidator $compositeTypes;

    private readonly SourceTypeResolver $sourceTypes;

    private CallableContractResolver $callables;

    private readonly CallArgumentBinder $argumentBinder;

    public function __construct(
        ?ExpressionTypeResolver $expressionTypes = null,
        ?TypeCompatibility $compatibility = null,
        ?CompositeTypeValidator $compositeTypes = null,
        ?SourceTypeResolver $sourceTypes = null,
        ?CallArgumentBinder $argumentBinder = null,
    ) {
        $this->configuredExpressionTypes = $expressionTypes;
        $this->expressionTypes = $expressionTypes ?? new ExpressionTypeResolver();
        $this->compatibility = $compatibility ?? new TypeCompatibility();
        $this->compositeTypes = $compositeTypes ?? new CompositeTypeValidator();
        $this->sourceTypes = $sourceTypes ?? new SourceTypeResolver();
        $this->argumentBinder = $argumentBinder ?? new CallArgumentBinder();
    }

    public function execute(SemanticContext $context): void
    {
        $this->context = $context;
        $this->callables = new CallableContractResolver($context);
        $this->expressionTypes = $this->configuredExpressionTypes ?? new ExpressionTypeResolver($context, $this->sourceTypes);
        $this->declarationsByVariableOffset = [];
        $this->forInitializersByVariableOffset = [];
        $this->foreachBindingsByVariableOffset = [];

        foreach ($context->parsedFile->extensionSyntax->typedLocals as $declaration) {
            $this->declarationsByVariableOffset[$declaration->variableSpan->start->offset] = $declaration;
            $this->validateCompositeType($declaration->type->text, $declaration->type->span);
        }

        foreach ($context->parsedFile->extensionSyntax->typedForInitializers as $declaration) {
            $this->forInitializersByVariableOffset[$declaration->variableSpan->start->offset] = $declaration;
            $this->validateCompositeType($declaration->type->text, $declaration->type->span);
        }

        foreach ($context->parsedFile->extensionSyntax->typedForeachBindings as $binding) {
            $this->foreachBindingsByVariableOffset[$binding->variableSpan->start->offset] = $binding;
            $this->validateCompositeType($binding->type->text, $binding->type->span);
        }

        $scope = $this->createScope('file');
        $this->enterScope($scope);

        foreach ($context->parsedFile->statements as $statement) {
            $this->processNode($statement, $scope);
        }

        $this->leaveScope();
    }

    private function processNode(Node $node, Scope $scope): void
    {
        if ($node instanceof Stmt\Function_) {
            $this->processNamedFunction($node);

            return;
        }

        if ($node instanceof Stmt\ClassMethod) {
            $this->processClassMethod($node);

            return;
        }

        if ($node instanceof Node\PropertyHook) {
            $this->processPropertyHook($node);

            return;
        }

        if ($node instanceof Expr\Closure) {
            $this->processClosure($node, $scope);

            return;
        }

        if ($node instanceof Expr\ArrowFunction) {
            $this->processArrowFunction($node, $scope);

            return;
        }

        if ($node instanceof Stmt\Foreach_) {
            $this->processForeach($node, $scope);

            return;
        }

        if ($node instanceof Stmt\For_) {
            $this->processFor($node, $scope);

            return;
        }

        if ($node instanceof Stmt\If_) {
            $this->processIf($node, $scope);

            return;
        }

        if ($node instanceof Stmt\Catch_) {
            $this->processCatch($node, $scope);

            return;
        }

        if ($node instanceof Stmt\Global_ || $node instanceof Stmt\Static_) {
            $this->addDiagnostic(
                DiagnosticCode::UnsupportedLocalBindingPosition,
                'Global and static local declarations are not supported in ++PHP.',
                $this->createNodeSpan($node),
            );

            return;
        }

        if ($node instanceof Stmt\Unset_) {
            foreach ($node->vars as $variable) {
                $this->processUnsetTarget($variable, $scope);
            }

            return;
        }

        if ($node instanceof Expr\AssignRef) {
            $this->processReferenceAssignment($node, $scope);

            return;
        }

        if ($node instanceof Expr\Assign) {
            $this->processAssignment($node, $scope);

            return;
        }

        if ($node instanceof Expr\AssignOp) {
            $this->processCompoundAssignment($node, $scope);

            return;
        }

        if (
            $node instanceof Expr\PreInc
            || $node instanceof Expr\PreDec
            || $node instanceof Expr\PostInc
            || $node instanceof Expr\PostDec
        ) {
            $this->processIncrementOrDecrement($node->var, $scope);

            return;
        }

        if ($node instanceof Expr\FuncCall) {
            $this->processFunctionCall($node, $scope);

            return;
        }

        if ($node instanceof Expr\StaticCall) {
            $this->processStaticCall($node, $scope);

            return;
        }

        if ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) {
            $this->processMethodCall($node, $scope);

            return;
        }

        if ($node instanceof Expr\Variable) {
            $this->processVariableRead($node, $scope);

            return;
        }

        foreach ($node->getSubNodeNames() as $subNodeName) {
            $value = $node->{$subNodeName};

            if ($value instanceof Node) {
                $this->processNode($value, $scope);
            } elseif (is_array($value)) {
                foreach ($value as $child) {
                    if ($child instanceof Node) {
                        $this->processNode($child, $scope);
                    }
                }
            }
        }
    }

    private function processNamedFunction(Stmt\Function_ $function): void
    {
        $scope = $this->createScope('function');
        $this->declareParameters($scope, $function->params);
        $this->enterScope($scope);

        foreach ($function->stmts as $statement) {
            $this->processNode($statement, $scope);
        }

        $this->leaveScope();
    }

    private function processClassMethod(Stmt\ClassMethod $method): void
    {
        $scope = $this->createScope('method');
        $class = $this->resolveOwningClass($method);

        if (!$method->isStatic() && $class !== null) {
            $parameters = $class->genericDeclaration === null ? [] : $class->genericDeclaration->parameters;
            $selfType = $parameters === []
                ? new AtomicType($class->fullyQualifiedName)
                : new GenericType(new AtomicType($class->fullyQualifiedName), $parameters);
            $scope->declare(new VariableSymbol(
                '$this',
                LocalType::createFromSemanticType($selfType),
                BindingMutability::Mutable,
                $this->createNodeSpan($method),
            ));
        }
        $this->declareParameters($scope, $method->params);
        $this->enterScope($scope);

        foreach ($method->stmts ?? [] as $statement) {
            $this->processNode($statement, $scope);
        }

        $this->leaveScope();
    }

    private function processPropertyHook(Node\PropertyHook $hook): void
    {
        $scope = $this->createScope('property-hook');
        $scope->declare(new VariableSymbol(
            '$this',
            LocalType::createAtomic('object'),
            BindingMutability::Mutable,
            $this->createNodeSpan($hook),
        ));
        $this->declareParameters($scope, $hook->params);

        if (strtolower($hook->name->toString()) === 'set') {
            $scope->declare(new VariableSymbol(
                '$value',
                LocalType::createUnknown(),
                BindingMutability::Mutable,
                $this->createNodeSpan($hook),
            ));
        }

        $this->enterScope($scope);

        if ($hook->body instanceof Expr) {
            $this->processNode($hook->body, $scope);
        } else {
            foreach ($hook->body ?? [] as $statement) {
                $this->processNode($statement, $scope);
            }
        }

        $this->leaveScope();
    }

    private function processClosure(Expr\Closure $closure, Scope $outerScope): void
    {
        $scope = $this->createScope('closure');
        $this->declareParameters($scope, $closure->params);

        foreach ($closure->uses as $use) {
            $name = $this->resolveVariableName($use->var);
            $symbol = $name === null ? null : $outerScope->resolve($name);
            $span = $this->createNodeSpan($use->var);

            if ($symbol === null) {
                $this->addDiagnostic(
                    DiagnosticCode::LocalVariableNotDeclared,
                    sprintf('Closure capture %s does not refer to a declared local variable.', $name ?? 'variable'),
                    $span,
                );

                continue;
            }

            $symbol->binding?->recordRead($span);

            if ($use->byRef && $symbol->mutability === BindingMutability::Readonly) {
                $this->addReadonlyDiagnostic(
                    DiagnosticCode::ReadonlyLocalCannotBeReferenced,
                    sprintf('%s cannot be captured by reference because it is readonly.', $symbol->name),
                    $span,
                    $symbol,
                );
            }

            $scope->import($symbol);
        }

        if (!$closure->static && ($thisSymbol = $outerScope->resolve('$this')) !== null) {
            $scope->import($thisSymbol);
        }

        $this->enterScope($scope);

        foreach ($closure->stmts as $statement) {
            $this->processNode($statement, $scope);
        }

        $this->leaveScope();
    }

    private function processArrowFunction(Expr\ArrowFunction $function, Scope $outerScope): void
    {
        $scope = $this->createScope('arrow-function');
        $this->declareParameters($scope, $function->params);

        foreach ($outerScope->symbols as $symbol) {
            if (!$function->static || $symbol->name !== '$this') {
                $scope->import($symbol);
            }
        }

        $this->enterScope($scope);
        $this->processNode($function->expr, $scope);
        $this->leaveScope();
    }

    private function processCatch(Stmt\Catch_ $catch, Scope $scope): void
    {
        if ($catch->var instanceof Expr\Variable && is_string($catch->var->name)) {
            $span = $this->createNodeSpan($catch->var);
            $scope->declare(new VariableSymbol(
                '$' . $catch->var->name,
                LocalType::createUnknown(),
                BindingMutability::Mutable,
                $span,
            ));
        }

        foreach ($catch->stmts as $statement) {
            $this->processNode($statement, $scope);
        }
    }

    private function processForeach(Stmt\Foreach_ $foreach, Scope $scope): void
    {
        $this->processNode($foreach->expr, $scope);
        $declaredBindings = [];
        [$keyType, $valueType] = $this->resolveIterationTypes(
            $this->expressionTypes->resolve($foreach->expr, $scope),
        );

        if ($foreach->byRef) {
            $this->addDiagnostic(
                DiagnosticCode::UnsupportedLocalBindingPosition,
                'By-reference foreach targets are not supported in ++PHP.',
                $this->createNodeSpan($foreach->valueVar),
            );
        }

        if ($foreach->keyVar instanceof Expr) {
            $binding = $this->processForeachBinding($foreach->keyVar, ForeachBindingPosition::Key, $keyType, $scope);

            if ($binding !== null) {
                $declaredBindings[] = $binding;
            }
        }

        $binding = $this->processForeachBinding($foreach->valueVar, ForeachBindingPosition::Value, $valueType, $scope);

        if ($binding !== null) {
            $declaredBindings[] = $binding;
        }

        foreach ($foreach->stmts as $statement) {
            $this->processNode($statement, $scope);
        }

        foreach ($declaredBindings as $declaredBinding) {
            $declaredBinding->markMaybeUninitialized();
        }
    }

    private function processFor(Stmt\For_ $for, Scope $scope): void
    {
        foreach ($for->init as $initializer) {
            $this->processNode($initializer, $scope);
        }

        foreach ($for->cond as $condition) {
            $this->processNode($condition, $scope);
        }

        foreach ($for->stmts as $statement) {
            $this->processNode($statement, $scope);
        }

        foreach ($for->loop as $update) {
            $this->processNode($update, $scope);
        }
    }

    private function processIf(Stmt\If_ $if, Scope $scope): void
    {
        $guarded = $this->resolvePositiveIssetBinding($if->cond, $scope);

        if ($guarded === null) {
            $this->processNode($if->cond, $scope);
        } else {
            $guarded->recordRead($this->createNodeSpan($if->cond));
            $guarded->markInitialized();
        }

        foreach ($if->stmts as $statement) {
            $this->processNode($statement, $scope);
        }

        if ($guarded !== null) {
            $guarded->markMaybeUninitialized();
        }

        foreach ($if->elseifs as $elseif) {
            $this->processNode($elseif->cond, $scope);

            foreach ($elseif->stmts as $statement) {
                $this->processNode($statement, $scope);
            }
        }

        $elseStatements = $if->else === null ? [] : $if->else->stmts;

        foreach ($elseStatements as $statement) {
            $this->processNode($statement, $scope);
        }
    }

    private function resolvePositiveIssetBinding(Expr $condition, Scope $scope): ?LocalBinding
    {
        if (!$condition instanceof Expr\Isset_ || count($condition->vars) !== 1) {
            return null;
        }

        $variable = $condition->vars[0];

        if (!$variable instanceof Expr\Variable) {
            return null;
        }

        $name = $this->resolveVariableName($variable);
        $binding = $name === null ? null : $scope->resolve($name)?->binding;

        return $binding?->initialization === BindingInitialization::MaybeUninitialized
            ? $binding
            : null;
    }

    private function processForeachBinding(
        Expr $target,
        ForeachBindingPosition $position,
        LocalType $assignedType,
        Scope $scope,
    ): ?LocalBinding {
        $declaration = $target instanceof Expr\Variable
            ? $this->foreachBindingsByVariableOffset[$target->getStartFilePos()] ?? null
            : null;

        if ($declaration === null) {
            $this->processIterationTarget($target, $scope, $assignedType);

            return null;
        }

        if ($declaration->position !== $position) {
            $this->addDiagnostic(
                DiagnosticCode::InternalCompilerError,
                'A typed foreach binding was associated with the wrong header position.',
                $declaration->span,
            );

            return null;
        }

        $name = $this->resolveVariableName($target);

        if ($name === null) {
            return null;
        }

        $declaredType = $this->resolveSourceLocalType($declaration->type);

        if (!$declaredType->equalsCanonical($assignedType)) {
            $this->addDiagnostic(
                DiagnosticCode::LoopBindingTypeDoesNotMatch,
                sprintf('The %s binding type %s must exactly match the collection contract %s.', $position->value, $declaredType->text, $assignedType->text),
                $declaration->type->span,
            );
        }

        $existing = $scope->resolve($name);

        if ($existing !== null) {
            $this->addDiagnostic(
                DiagnosticCode::DuplicateLocalDeclaration,
                sprintf('%s is already declared in this variable scope.', $name),
                $declaration->variableSpan,
                $this->resolveDeclarationLabels($existing),
            );

            return null;
        }

        $binding = new LocalBinding(
            $declaration->id,
            $name,
            $declaredType,
            BindingMutability::Mutable,
            $declaration->span,
            $declaration->variableSpan,
            null,
            null,
            $assignedType,
        );
        $binding->recordWrite($declaration->variableSpan);
        $this->context->model->bindings->record($binding);
        $scope->declare(new VariableSymbol(
            $name,
            $declaredType,
            BindingMutability::Mutable,
            $declaration->variableSpan,
            $binding,
        ));

        return $binding;
    }

    /** @return array{LocalType, LocalType} */
    private function resolveIterationTypes(LocalType $collection): array
    {
        $contract = $this->resolveTypedArrayContract($collection->semanticType);

        if ($contract !== null) {
            return [
                LocalType::createFromSemanticType($contract->keyType),
                LocalType::createFromSemanticType($contract->valueType),
            ];
        }

        return [LocalType::createAtomic('mixed'), LocalType::createAtomic('mixed')];
    }

    private function processIterationTarget(Expr $target, Scope $scope, ?LocalType $assignedType = null): void
    {
        if ($target instanceof Expr\Variable) {
            $name = $this->resolveVariableName($target);
            $symbol = $name === null ? null : $scope->resolve($name);
            $span = $this->createNodeSpan($target);

            if ($symbol === null) {
                $this->addDiagnostic(
                    DiagnosticCode::UnsupportedLocalBindingPosition,
                    sprintf('Foreach target %s must be an existing mutable local variable.', $name ?? 'variable'),
                    $span,
                );

                return;
            }

            if ($symbol->mutability === BindingMutability::Readonly) {
                $this->addReadonlyDiagnostic(
                    DiagnosticCode::ReadonlyLocalCannotBeMutated,
                    sprintf('%s cannot be used as a foreach target because it is readonly.', $symbol->name),
                    $span,
                    $symbol,
                );

                return;
            }

            if ($assignedType !== null && !$this->acceptsCollectionType($symbol->type->semanticType, $assignedType->semanticType)) {
                $this->addDiagnostic(
                    DiagnosticCode::AssignmentNotAssignableToDeclaredType,
                    sprintf('The loop value of type %s is not assignable to %s of type %s.', $assignedType->text, $symbol->name, $symbol->type->text),
                    $span,
                    $this->resolveDeclarationLabels($symbol),
                );
            }

            $symbol->binding?->recordWrite($span);

            return;
        }

        if ($target instanceof Expr\List_ || $target instanceof Expr\Array_) {
            foreach ($target->items as $item) {
                if ($item !== null) {
                    $this->processIterationTarget($item->value, $scope, $assignedType);
                }
            }

            return;
        }

        $this->addDiagnostic(
            DiagnosticCode::UnsupportedLocalBindingPosition,
            'This foreach target is not supported; use a variable or destructuring assignment.',
            $this->createNodeSpan($target),
        );
    }

    private function processAssignment(Expr\Assign $assignment, Scope $scope): void
    {
        $forDeclaration = $assignment->var instanceof Expr\Variable
            ? $this->resolveTypedForInitializer($assignment)
            : null;

        if ($forDeclaration !== null) {
            $this->processTypedForInitializer($forDeclaration, $assignment, $scope);

            return;
        }

        $declaration = $assignment->var instanceof Expr\Variable
            ? $this->resolveTypedDeclaration($assignment)
            : null;

        if ($declaration !== null) {
            $this->processTypedDeclaration($declaration, $assignment, $scope);

            return;
        }

        $this->processNode($assignment->expr, $scope);
        $this->processAssignmentTarget($assignment->var, $assignment->expr, $scope);
    }

    private function processTypedForInitializer(
        TypedForInitializer $declaration,
        Expr\Assign $assignment,
        Scope $scope,
    ): void {
        $name = $this->resolveVariableName($assignment->var);

        if ($name === null) {
            return;
        }

        $this->processNode($assignment->expr, $scope);
        $initializerType = $this->expressionTypes->resolve($assignment->expr, $scope);
        $declaredType = $this->resolveSourceLocalType($declaration->type);

        $this->validateStructuredValue(
            $declaredType->semanticType,
            $assignment->expr,
            $scope,
            $declaration->type->span,
        );

        $existing = $scope->resolve($name);

        if ($existing !== null) {
            $this->addDiagnostic(
                DiagnosticCode::DuplicateLocalDeclaration,
                sprintf('%s is already declared in this variable scope.', $name),
                $declaration->variableSpan,
                $this->resolveDeclarationLabels($existing),
            );

            return;
        }

        $binding = new LocalBinding(
            $declaration->id,
            $name,
            $declaredType,
            $declaration->readonlySpan === null ? BindingMutability::Mutable : BindingMutability::Readonly,
            $declaration->span,
            $declaration->variableSpan,
            $declaration->initializerSpan,
            $assignment->expr,
            $initializerType,
        );
        $binding->recordWrite($declaration->variableSpan);
        $this->context->model->bindings->record($binding);
        $scope->declare(new VariableSymbol(
            $name,
            $declaredType,
            $binding->mutability,
            $declaration->variableSpan,
            $binding,
        ));
    }

    private function processTypedDeclaration(
        TypedLocalDeclaration $declaration,
        Expr\Assign $assignment,
        Scope $scope,
    ): void {
        $name = $this->resolveVariableName($assignment->var);

        if ($name === null) {
            $this->addInternalAssociationDiagnostic($declaration);

            return;
        }

        $this->processNode($assignment->expr, $scope);
        $initializerType = $this->expressionTypes->resolve($assignment->expr, $scope);
        $declaredType = $this->resolveSourceLocalType($declaration->type);

        $this->validateStructuredValue(
            $declaredType->semanticType,
            $assignment->expr,
            $scope,
            $declaration->type->span,
        );

        $existing = $scope->resolve($name);

        if ($existing !== null) {
            $related = $existing->declarationSpan === null
                ? []
                : [new DiagnosticLabel($existing->declarationSpan, 'The existing binding is declared here.')];
            $this->addDiagnostic(
                DiagnosticCode::DuplicateLocalDeclaration,
                sprintf('%s is already declared in this variable scope.', $name),
                $declaration->variableSpan,
                $related,
            );

            return;
        }

        $binding = new LocalBinding(
            $declaration->id,
            $name,
            $declaredType,
            $declaration->readonlySpan === null ? BindingMutability::Mutable : BindingMutability::Readonly,
            $declaration->span,
            $declaration->variableSpan,
            $declaration->initializerSpan,
            $assignment->expr,
            $initializerType,
        );
        $binding->recordWrite($declaration->variableSpan);
        $this->context->model->bindings->record($binding);
        $scope->declare(new VariableSymbol(
            $name,
            $declaredType,
            $binding->mutability,
            $declaration->variableSpan,
            $binding,
        ));
    }

    private function processAssignmentTarget(Expr $target, Expr $value, Scope $scope): void
    {
        if ($target instanceof Expr\Variable) {
            $name = $this->resolveVariableName($target);
            $symbol = $name === null ? null : $scope->resolve($name);
            $span = $this->createNodeSpan($target);

            if ($symbol === null) {
                $this->addDiagnostic(
                    DiagnosticCode::AssignmentCannotDeclareVariable,
                    sprintf('%s must be declared with an explicit type before it can be assigned.', $name ?? 'The variable'),
                    $span,
                );

                return;
            }

            if ($symbol->mutability === BindingMutability::Readonly) {
                $this->addReadonlyDiagnostic(
                    DiagnosticCode::ReadonlyLocalCannotBeReassigned,
                    sprintf('%s cannot be assigned after its declaration.', $symbol->name),
                    $span,
                    $symbol,
                );

                return;
            }

            $actualType = $this->expressionTypes->resolve($value, $scope);

            $this->validateStructuredValue(
                $symbol->type->semanticType,
                $value,
                $scope,
                $symbol->declarationSpan ?? $span,
            );

            $symbol->binding?->recordWrite($span);
            $symbol->binding?->markInitialized();

            return;
        }

        if ($target instanceof Expr\ArrayDimFetch) {
            $this->processStructuralWrite($target, $scope, $value);

            return;
        }

        if ($target instanceof Expr\List_ || $target instanceof Expr\Array_) {
            foreach ($target->items as $item) {
                if ($item !== null) {
                    $this->processIterationTarget($item->value, $scope);
                }
            }

            return;
        }

        if ($target instanceof Expr\PropertyFetch || $target instanceof Expr\NullsafePropertyFetch) {
            $this->processNode($target->var, $scope);

            if ($target->name instanceof Expr) {
                $this->processNode($target->name, $scope);
            }

            return;
        }

        $this->processNode($target, $scope);
    }

    private function processCompoundAssignment(Expr\AssignOp $assignment, Scope $scope): void
    {
        $this->processNode($assignment->expr, $scope);

        if (!$assignment->var instanceof Expr\Variable) {
            if ($assignment->var instanceof Expr\ArrayDimFetch) {
                $this->processStructuralWrite($assignment->var, $scope);
            } else {
                $this->processNode($assignment->var, $scope);
            }

            return;
        }

        $name = $this->resolveVariableName($assignment->var);
        $symbol = $name === null ? null : $scope->resolve($name);
        $span = $this->createNodeSpan($assignment->var);

        if ($symbol === null) {
            $this->addDiagnostic(
                DiagnosticCode::LocalVariableNotDeclared,
                sprintf('%s must be declared before it can be updated.', $name ?? 'The variable'),
                $span,
            );

            return;
        }

        if ($symbol->mutability === BindingMutability::Readonly) {
            $this->addReadonlyDiagnostic(
                DiagnosticCode::ReadonlyLocalCannotBeReassigned,
                sprintf('%s cannot be updated because it is readonly.', $symbol->name),
                $span,
                $symbol,
            );

            return;
        }

        $resultType = $this->resolveCompoundAssignmentType($assignment, $symbol, $scope);

        if (!$this->compatibility->accepts($symbol->type, $resultType, $this->context->symbols)) {
            $this->addDiagnostic(
                DiagnosticCode::AssignmentNotAssignableToDeclaredType,
                sprintf('The compound assignment produces %s, which is not assignable to %s.', $resultType->text, $symbol->type->text),
                $this->createNodeSpan($assignment),
                $this->resolveDeclarationLabels($symbol),
            );
        }

        $symbol->binding?->recordRead($span);
        $symbol->binding?->recordWrite($span);
    }

    private function processIncrementOrDecrement(Expr $target, Scope $scope): void
    {
        if (!$target instanceof Expr\Variable) {
            if ($target instanceof Expr\ArrayDimFetch) {
                $this->processStructuralWrite($target, $scope);
            } else {
                $this->processNode($target, $scope);
            }

            return;
        }

        $name = $this->resolveVariableName($target);
        $symbol = $name === null ? null : $scope->resolve($name);
        $span = $this->createNodeSpan($target);

        if ($symbol === null) {
            $this->addDiagnostic(
                DiagnosticCode::LocalVariableNotDeclared,
                sprintf('%s must be declared before it can be updated.', $name ?? 'The variable'),
                $span,
            );

            return;
        }

        if ($symbol->mutability === BindingMutability::Readonly) {
            $this->addReadonlyDiagnostic(
                DiagnosticCode::ReadonlyLocalCannotBeReassigned,
                sprintf('%s cannot be updated because it is readonly.', $symbol->name),
                $span,
                $symbol,
            );

            return;
        }

        if (!$symbol->type->includes('int') && !$symbol->type->includes('float')) {
            $this->addDiagnostic(
                DiagnosticCode::AssignmentNotAssignableToDeclaredType,
                sprintf('Increment and decrement require a numeric local, but %s has type %s.', $symbol->name, $symbol->type->text),
                $span,
                $this->resolveDeclarationLabels($symbol),
            );
        }

        $symbol->binding?->recordRead($span);
        $symbol->binding?->recordWrite($span);
    }

    private function processStructuralWrite(
        Expr\ArrayDimFetch $target,
        Scope $scope,
        ?Expr $value = null,
        bool $unset = false,
    ): void
    {
        foreach ($this->resolveArrayDimensions($target) as $dimension) {
            if ($dimension instanceof Expr) {
                $this->processNode($dimension, $scope);
            }
        }

        $root = $this->resolveRootVariable($target);

        if ($root === null) {
            $this->processNode($target->var, $scope);

            return;
        }

        $name = $this->resolveVariableName($root);
        $symbol = $name === null ? null : $scope->resolve($name);
        $span = $this->createNodeSpan($root);

        if ($symbol === null) {
            $this->addDiagnostic(
                DiagnosticCode::LocalVariableNotDeclared,
                sprintf('%s must be declared before its structure can be changed.', $name ?? 'The variable'),
                $span,
            );

            return;
        }

        if ($symbol->mutability === BindingMutability::Readonly) {
            $this->addReadonlyDiagnostic(
                DiagnosticCode::ReadonlyLocalCannotBeMutated,
                sprintf('%s cannot be mutated because it is readonly.', $symbol->name),
                $this->createNodeSpan($target),
                $symbol,
            );

            return;
        }

        $this->validateTypedArrayStructuralWrite($symbol, $target, $scope, $value, $unset);

        $symbol->binding?->recordRead($span);
        $symbol->binding?->recordWrite($span);
    }

    private function processUnsetTarget(Expr $target, Scope $scope): void
    {
        if ($target instanceof Expr\ArrayDimFetch) {
            $this->processStructuralWrite($target, $scope, null, true);

            return;
        }

        if (!$target instanceof Expr\Variable) {
            $this->processNode($target, $scope);

            return;
        }

        $name = $this->resolveVariableName($target);
        $symbol = $name === null ? null : $scope->resolve($name);
        $span = $this->createNodeSpan($target);

        if ($symbol === null) {
            $this->addDiagnostic(
                DiagnosticCode::LocalVariableNotDeclared,
                sprintf('%s must be declared before it can be unset.', $name ?? 'The variable'),
                $span,
            );

            return;
        }

        if ($symbol->mutability === BindingMutability::Readonly) {
            $this->addReadonlyDiagnostic(
                DiagnosticCode::ReadonlyLocalCannotBeReassigned,
                sprintf('%s cannot be unset because it is readonly.', $symbol->name),
                $span,
                $symbol,
            );

            return;
        }

        $symbol->binding?->recordWrite($span);
    }

    private function validateTypedArrayStructuralWrite(
        VariableSymbol $symbol,
        Expr\ArrayDimFetch $target,
        Scope $scope,
        ?Expr $value,
        bool $unset,
    ): void {
        $currentType = $symbol->type->semanticType;
        $dimensions = $this->resolveArrayDimensions($target);
        $last = array_key_last($dimensions);

        foreach ($dimensions as $index => $dimension) {
            $contract = $this->resolveTypedArrayContract($currentType);

            if ($contract === null) {
                return;
            }

            $this->validateTypedArrayKey($contract, $dimension, $scope, $target);

            if ($index === $last) {
                if ($unset && $contract->isList) {
                    $this->addDiagnostic(
                        DiagnosticCode::OperationWouldBreakListShape,
                        sprintf('Unsetting an offset would break the contiguous shape of %s.', $symbol->type->text),
                        $this->createNodeSpan($target),
                        $this->resolveDeclarationLabels($symbol),
                    );
                }

                if ($value !== null) {
                    $this->validateTypedArrayElement(
                        $contract->valueType,
                        $value,
                        $scope,
                        $symbol->declarationSpan ?? $this->createNodeSpan($target),
                    );
                }

                return;
            }

            $currentType = $contract->valueType;
        }
    }

    private function validateTypedArrayKey(
        TypedArrayType $contract,
        ?Expr $dimension,
        Scope $scope,
        Expr\ArrayDimFetch $target,
    ): void {
        $span = $dimension === null ? $this->createNodeSpan($target) : $this->createNodeSpan($dimension);
        $actual = $dimension === null
            ? LocalType::createAtomic('int')
            : $this->resolveArrayKeyType($dimension, $scope);

        if ($contract->isList) {
            if ($dimension !== null && !$this->acceptsCollectionType(new AtomicType('int'), $actual->semanticType)) {
                $this->addDiagnostic(
                    DiagnosticCode::OperationWouldBreakListShape,
                    sprintf('A typed list requires integer offsets, but this offset has type %s.', $actual->text),
                    $span,
                );
            }

            return;
        }

        if (!$this->acceptsCollectionType($contract->keyType, $actual->semanticType)) {
            $this->addDiagnostic(
                DiagnosticCode::TypedArrayKeyTypeDoesNotMatch,
                sprintf('Expected key type %s, received %s.', $contract->keyType->renderPhpDoc(), $actual->text),
                $span,
            );
        }
    }

    private function validateTypedArrayValue(
        Type $expected,
        Expr $value,
        Scope $scope,
        Span $declarationSpan,
    ): void {
        $contract = $this->resolveTypedArrayContract($expected);

        if ($contract !== null && $value instanceof Expr\Array_) {
            $this->validateTypedArrayLiteral($contract, $value, $scope, $declarationSpan);

            return;
        }

        $actual = $this->expressionTypes->resolve($value, $scope);

        if ($actual->unknown || $this->acceptsCollectionType($expected, $actual->semanticType)) {
            return;
        }

        $expectedArray = $this->resolveTypedArrayContract($expected);
        $actualArray = $this->resolveTypedArrayContract($actual->semanticType);
        $losesListShape = $expectedArray?->isList === true && $actualArray !== null && !$actualArray->isList;
        $isWholeArray = $this->containsTypedArray($expected) && $this->containsTypedArray($actual->semanticType);
        $this->addDiagnostic(
            $losesListShape
                ? DiagnosticCode::OperationWouldBreakListShape
                : ($isWholeArray ? DiagnosticCode::GenericTypeIsInvariant : DiagnosticCode::TypedArrayValueTypeDoesNotMatch),
            sprintf('Expected %s, received %s.', $expected->renderPhpDoc(), $actual->text),
            $this->createNodeSpan($value),
            [new DiagnosticLabel($declarationSpan, 'The typed array contract is declared here.')],
        );
    }

    private function validateStructuredValue(
        Type $expected,
        Expr $value,
        Scope $scope,
        Span $declarationSpan,
    ): void {
        $this->validateGenericConstruction($expected, $value, $scope, $declarationSpan);

        if ($this->containsTypedArray($expected)) {
            $this->validateTypedArrayValue($expected, $value, $scope, $declarationSpan);
        }
    }

    private function validateTypedArrayElement(
        Type $expected,
        Expr $value,
        Scope $scope,
        Span $declarationSpan,
    ): void {
        $this->validateGenericConstruction($expected, $value, $scope, $declarationSpan);
        $this->validateTypedArrayValue($expected, $value, $scope, $declarationSpan);
    }

    private function validateGenericConstruction(
        Type $expected,
        Expr $value,
        Scope $scope,
        Span $declarationSpan,
    ): void {
        $application = $this->resolveGenericApplication($expected);

        if ($application === null || !$value instanceof Expr\New_ || !$value->class instanceof Node\Name) {
            return;
        }

        $resolvedClass = $this->context->resolvedNames->resolve($value->class) ?? $value->class->toString();
        $declaration = $this->context->genericDeclarations->findType($resolvedClass);
        $expectedDeclaration = $this->context->genericDeclarations->findType($application->base->name);

        if (
            $declaration === null
            || $expectedDeclaration === null
            || $declaration->key !== $expectedDeclaration->key
            || !$declaration->owner instanceof \Atatusoft\Ppphp\Semantic\Symbol\ClassSymbol
        ) {
            return;
        }

        $constructor = $declaration->owner->findMethod('__construct');

        if ($constructor === null || count($application->arguments) !== count($declaration->parameters)) {
            return;
        }

        $argumentsByParameter = [];

        foreach ($declaration->parameters as $index => $parameter) {
            $argumentsByParameter[$parameter->canonical] = $application->arguments[$index];
        }

        $position = 0;

        foreach ($value->args as $argument) {
            if (!$argument instanceof Arg || $argument->unpack) {
                continue;
            }

            $parameter = $argument->name === null
                ? ($constructor->parameters[$position] ?? null)
                : $this->findConstructorParameter($constructor->parameters, $argument->name->toString());
            $position++;

            if ($parameter === null) {
                continue;
            }

            $parameterType = $parameter->documentedType
                ?? $parameter->type?->semanticType;

            if ($parameterType === null) {
                continue;
            }

            $substituted = $this->substituteConstructionParameters($parameterType, $argumentsByParameter);
            $actual = $this->expressionTypes->resolve($argument->value, $scope);

            $this->validateStructuredValue($substituted, $argument->value, $scope, $declarationSpan);

            if ($this->containsTypedArray($substituted) || $actual->unknown || $this->compatibility->accepts(
                LocalType::createFromSemanticType($substituted),
                $actual,
                $this->context->symbols,
            )) {
                continue;
            }

            $this->addDiagnostic(
                DiagnosticCode::GenericTypeIsInvariant,
                sprintf(
                    'Constructor argument of type %s does not satisfy applied generic parameter type %s.',
                    $actual->text,
                    $substituted->renderPhpDoc(),
                ),
                $this->createNodeSpan($argument->value),
                [new DiagnosticLabel($declarationSpan, 'The applied generic type is declared here.')],
            );
        }
    }

    private function resolveGenericApplication(Type $type): ?GenericType
    {
        if ($type instanceof GenericType) {
            return $type;
        }

        if (!$type instanceof UnionType) {
            return null;
        }

        $applications = array_values(array_filter(
            $type->members,
            static fn (Type $member): bool => $member instanceof GenericType,
        ));

        return count($applications) === 1 ? $applications[0] : null;
    }

    /** @param list<\Atatusoft\Ppphp\Semantic\Symbol\ParameterSymbol> $parameters */
    private function findConstructorParameter(array $parameters, string $name): ?\Atatusoft\Ppphp\Semantic\Symbol\ParameterSymbol
    {
        foreach ($parameters as $parameter) {
            if (strcasecmp(ltrim($parameter->name, '$'), $name) === 0) {
                return $parameter;
            }
        }

        return null;
    }

    /** @param array<string, Type> $argumentsByParameter */
    private function substituteConstructionParameters(Type $type, array $argumentsByParameter): Type
    {
        return (new \Atatusoft\Ppphp\Semantic\Type\TypeSubstitution($argumentsByParameter))->substitute($type);
    }

    private function validateTypedArrayLiteral(
        TypedArrayType $contract,
        Expr\Array_ $literal,
        Scope $scope,
        Span $declarationSpan,
    ): void {
        $nextListKey = 0;

        foreach ($literal->items as $item) {
            if ($item->unpack) {
                $this->validateTypedArrayUnpack($contract, $item->value, $scope, $declarationSpan);

                continue;
            }

            if ($contract->isList) {
                $literalKey = $item->key === null ? $nextListKey : $this->resolveLiteralIntegerKey($item->key);

                if ($literalKey !== $nextListKey) {
                    $this->addDiagnostic(
                        DiagnosticCode::OperationWouldBreakListShape,
                        'A typed list literal must use contiguous integer keys beginning at zero.',
                        $item->key === null ? $this->createNodeSpan($item) : $this->createNodeSpan($item->key),
                        [new DiagnosticLabel($declarationSpan, 'The list contract is declared here.')],
                    );
                } else {
                    $nextListKey++;
                }
            } else {
                $this->validateTypedArrayKey($contract, $item->key, $scope, new Expr\ArrayDimFetch($literal, $item->key));
            }

            $this->validateTypedArrayElement($contract->valueType, $item->value, $scope, $declarationSpan);
        }
    }

    private function validateTypedArrayUnpack(
        TypedArrayType $contract,
        Expr $value,
        Scope $scope,
        Span $declarationSpan,
    ): void {
        if ($value instanceof Expr\Array_) {
            $this->validateTypedArrayLiteral($contract, $value, $scope, $declarationSpan);

            return;
        }

        $actual = $this->expressionTypes->resolve($value, $scope);

        if ($actual->unknown) {
            return;
        }

        $unpacked = $this->resolveTypedArrayContract($actual->semanticType);

        if ($unpacked === null) {
            $this->addDiagnostic(
                DiagnosticCode::TypedArrayValueTypeDoesNotMatch,
                sprintf('Expected an unpacked collection compatible with %s, received %s.', $contract->renderPhpDoc(), $actual->text),
                $this->createNodeSpan($value),
                [new DiagnosticLabel($declarationSpan, 'The typed array contract is declared here.')],
            );

            return;
        }

        if ($contract->isList && !$unpacked->isList) {
            $this->addDiagnostic(
                DiagnosticCode::OperationWouldBreakListShape,
                'A typed list can only unpack another typed list.',
                $this->createNodeSpan($value),
                [new DiagnosticLabel($declarationSpan, 'The list contract is declared here.')],
            );

            return;
        }

        if (!$contract->isList) {
            $unpackedKey = $unpacked->isList ? new AtomicType('int') : $unpacked->keyType;

            if (!$this->acceptsCollectionType($contract->keyType, $unpackedKey)) {
                $this->addDiagnostic(
                    DiagnosticCode::TypedArrayKeyTypeDoesNotMatch,
                    sprintf('Expected unpacked key type %s, received %s.', $contract->keyType->renderPhpDoc(), $unpackedKey->renderPhpDoc()),
                    $this->createNodeSpan($value),
                    [new DiagnosticLabel($declarationSpan, 'The map contract is declared here.')],
                );
            }
        }

        if (!$this->acceptsCollectionType($contract->valueType, $unpacked->valueType)) {
            $this->addDiagnostic(
                DiagnosticCode::TypedArrayValueTypeDoesNotMatch,
                sprintf('Expected unpacked value type %s, received %s.', $contract->valueType->renderPhpDoc(), $unpacked->valueType->renderPhpDoc()),
                $this->createNodeSpan($value),
                [new DiagnosticLabel($declarationSpan, 'The typed array contract is declared here.')],
            );
        }
    }

    private function resolveArrayKeyType(Expr $expression, Scope $scope): LocalType
    {
        if ($expression instanceof Node\Scalar\String_) {
            return LocalType::createAtomic($this->normalizeLiteralArrayKey($expression->value) === null ? 'string' : 'int');
        }

        return $this->expressionTypes->resolve($expression, $scope);
    }

    private function resolveLiteralIntegerKey(Expr $expression): ?int
    {
        if ($expression instanceof Node\Scalar\Int_) {
            return $expression->value;
        }

        if ($expression instanceof Node\Scalar\String_) {
            return $this->normalizeLiteralArrayKey($expression->value);
        }

        return null;
    }

    private function normalizeLiteralArrayKey(string $value): ?int
    {
        if (preg_match('/^(?:0|-[1-9][0-9]*|[1-9][0-9]*)$/D', $value) !== 1) {
            return null;
        }

        $normalized = (int) $value;

        return (string) $normalized === $value ? $normalized : null;
    }

    /** @return list<Expr|null> */
    private function resolveArrayDimensions(Expr\ArrayDimFetch $target): array
    {
        $dimensions = [];
        $current = $target;

        while ($current instanceof Expr\ArrayDimFetch) {
            array_unshift($dimensions, $current->dim);
            $current = $current->var;
        }

        return $dimensions;
    }

    private function resolveTypedArrayContract(Type $type): ?TypedArrayType
    {
        if ($type instanceof TypedArrayType) {
            return $type;
        }

        if (!$type instanceof UnionType) {
            return null;
        }

        $contracts = array_values(array_filter(
            $type->members,
            static fn (Type $member): bool => $member instanceof TypedArrayType,
        ));

        return count($contracts) === 1 ? $contracts[0] : null;
    }

    private function containsTypedArray(Type $type): bool
    {
        if ($type instanceof TypedArrayType) {
            return true;
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            foreach ($type->members as $member) {
                if ($this->containsTypedArray($member)) {
                    return true;
                }
            }
        }

        if ($type instanceof GenericType) {
            foreach ($type->arguments as $argument) {
                if ($this->containsTypedArray($argument)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function acceptsCollectionType(Type $expected, Type $actual): bool
    {
        if ($expected->isUnknown || $actual->isUnknown) {
            return true;
        }

        if ($actual instanceof UnionType) {
            foreach ($actual->members as $member) {
                if (!$this->acceptsCollectionType($expected, $member)) {
                    return false;
                }
            }

            return true;
        }

        if ($expected instanceof UnionType) {
            foreach ($expected->members as $member) {
                if ($this->acceptsCollectionType($member, $actual)) {
                    return true;
                }
            }

            return false;
        }

        if ($expected instanceof AtomicType && $expected->canonical === 'mixed') {
            return true;
        }

        if ($expected instanceof AtomicType && in_array($expected->canonical, ['bool', 'callable'], true)) {
            return $this->compatibility->compare($expected, $actual, $this->context->symbols)->isAccepted();
        }

        if ($expected instanceof GenericType && $actual instanceof AtomicType) {
            return $expected->base->canonical === $actual->canonical;
        }

        return $expected->canonical === $actual->canonical;
    }

    private function processReferenceAssignment(Expr\AssignRef $assignment, Scope $scope): void
    {
        $this->processNode($assignment->expr, $scope);
        $source = $this->resolveRootVariable($assignment->expr);

        if ($source !== null) {
            $name = $this->resolveVariableName($source);
            $symbol = $name === null ? null : $scope->resolve($name);

            if ($symbol?->mutability === BindingMutability::Readonly) {
                $this->addReadonlyDiagnostic(
                    DiagnosticCode::ReadonlyLocalCannotBeReferenced,
                    sprintf('%s cannot be used to create a reference because it is readonly.', $symbol->name),
                    $this->createNodeSpan($source),
                    $symbol,
                );
            }
        }

        $this->addDiagnostic(
            DiagnosticCode::UnsupportedLocalBindingPosition,
            'Explicit reference creation is not supported in ++PHP.',
            $this->createNodeSpan($assignment),
        );

        $this->processAssignmentTarget($assignment->var, $assignment->expr, $scope);
    }

    private function processFunctionCall(Expr\FuncCall $call, Scope $scope): void
    {
        $resolution = $call->name instanceof Node\Name
            ? $this->callables->resolveFunction($call->name)
            : null;
        $contract = $resolution?->status === CallableResolutionStatus::Found
            ? $resolution->contract
            : null;

        if ($call->name instanceof Node\Name && in_array(strtolower($call->name->toString()), self::MUTATING_FUNCTIONS, true)) {
            $firstArgument = $call->args[0] ?? null;

            if ($firstArgument instanceof Arg) {
                $root = $this->resolveRootVariable($firstArgument->value);

                if ($root !== null) {
                    $name = $this->resolveVariableName($root);
                    $symbol = $name === null ? null : $scope->resolve($name);

                    if ($symbol?->mutability === BindingMutability::Readonly) {
                        $this->addReadonlyDiagnostic(
                            DiagnosticCode::ReadonlyLocalCannotBeMutated,
                            sprintf('%s cannot be passed to a mutating function because it is readonly.', $symbol->name),
                            $this->createNodeSpan($firstArgument->value),
                            $symbol,
                        );
                    }
                }
            }
        }

        if ($call->name instanceof Expr) {
            $this->processNode($call->name, $scope);
        }

        $this->processCallArguments($call->args, $contract, $scope);
    }

    private function processStaticCall(Expr\StaticCall $call, Scope $scope): void
    {
        $resolution = $call->class instanceof Node\Name && $call->name instanceof Node\Identifier
            ? $this->callables->resolveMethod(
                $this->sourceTypes->resolveNode(
                    $call->class,
                    $this->context->parsedFile,
                    $this->context->resolvedNames,
                    $this->context->genericDeclarations,
                ),
                $call->name->toString(),
            )
            : null;
        $contract = $resolution?->status === CallableResolutionStatus::Found
            ? $resolution->contract
            : null;

        if ($call->class instanceof Expr) {
            $this->processNode($call->class, $scope);
        }

        if ($call->name instanceof Expr) {
            $this->processNode($call->name, $scope);
        }

        $this->processCallArguments($call->args, $contract, $scope);
    }

    private function processMethodCall(
        Expr\MethodCall|Expr\NullsafeMethodCall $call,
        Scope $scope,
    ): void {
        $resolution = $call->name instanceof Node\Identifier
            ? $this->callables->resolveMethod(
                $this->expressionTypes->resolve($call->var, $scope)->semanticType,
                $call->name->toString(),
            )
            : null;
        $contract = $resolution?->status === CallableResolutionStatus::Found
            ? $resolution->contract
            : null;

        $this->processNode($call->var, $scope);

        if ($call->name instanceof Expr) {
            $this->processNode($call->name, $scope);
        }

        $this->processCallArguments($call->args, $contract, $scope);
    }

    /**
     * @param array<Arg|Node\VariadicPlaceholder> $arguments
     */
    private function processCallArguments(
        array $arguments,
        ?CallableContract $contract,
        Scope $scope,
    ): void {
        $byReferenceArguments = [];

        if ($contract !== null) {
            foreach ($this->argumentBinder->bind($contract, $arguments)->arguments as $bound) {
                if ($bound->parameter->byReference) {
                    $byReferenceArguments[spl_object_id($bound->argument)] = true;
                }
            }
        }

        foreach ($arguments as $argument) {
            if (!$argument instanceof Arg) {
                continue;
            }

            if (isset($byReferenceArguments[spl_object_id($argument)])) {
                $root = $this->resolveRootVariable($argument->value);

                if ($root !== null) {
                    $name = $this->resolveVariableName($root);
                    $symbol = $name === null ? null : $scope->resolve($name);
                    $span = $this->createNodeSpan($argument->value);

                    if ($symbol?->mutability === BindingMutability::Readonly) {
                        $this->addReadonlyDiagnostic(
                            DiagnosticCode::ReadonlyLocalCannotBeReferenced,
                            sprintf('%s cannot be passed to a by-reference parameter because it is readonly.', $symbol->name),
                            $span,
                            $symbol,
                        );
                    } else {
                        $symbol?->binding?->recordWrite($span);
                    }
                }
            }

            $this->processNode($argument->value, $scope);
        }
    }

    private function processVariableRead(Expr\Variable $variable, Scope $scope): void
    {
        $name = $this->resolveVariableName($variable);

        if ($name === null) {
            return;
        }

        $symbol = $scope->resolve($name);
        $span = $this->createNodeSpan($variable);

        if ($symbol === null) {
            $this->addDiagnostic(
                DiagnosticCode::LocalVariableNotDeclared,
                sprintf('%s is read before an explicit declaration is in scope.', $name),
                $span,
            );

            return;
        }

        if ($symbol->initialization === BindingInitialization::MaybeUninitialized) {
            $this->addDiagnostic(
                DiagnosticCode::LocalVariableMayBeUninitialized,
                sprintf('%s may be uninitialized because its foreach loop may execute zero times.', $name),
                $span,
                $this->resolveDeclarationLabels($symbol),
            );
        }

        $symbol->binding?->recordRead($span);
    }

    /** @param array<Param> $parameters */
    private function declareParameters(Scope $scope, array $parameters): void
    {
        foreach ($parameters as $parameter) {
            if (!$parameter->var instanceof Expr\Variable || !is_string($parameter->var->name)) {
                continue;
            }

            $scope->declare(new VariableSymbol(
                '$' . $parameter->var->name,
                $this->resolveDeclaredPhpType($parameter->type),
                BindingMutability::Mutable,
                $this->createNodeSpan($parameter->var),
            ));
        }
    }

    private function createScope(string $kind): Scope
    {
        $scope = new Scope($kind);

        foreach ([
            '$GLOBALS' => 'array',
            '$_SERVER' => 'array',
            '$_GET' => 'array',
            '$_POST' => 'array',
            '$_FILES' => 'array',
            '$_COOKIE' => 'array',
            '$_SESSION' => 'array',
            '$_REQUEST' => 'array',
            '$_ENV' => 'array',
            '$argc' => 'int',
            '$argv' => 'array',
        ] as $name => $type) {
            $scope->declare(new VariableSymbol(
                $name,
                LocalType::createAtomic($type),
                BindingMutability::Mutable,
            ));
        }

        return $scope;
    }

    private function resolveDeclaredPhpType(Node\Identifier|Node\Name|Node\ComplexType|null $type): LocalType
    {
        if ($type === null) {
            return LocalType::createUnknown();
        }

        return LocalType::createFromSemanticType($this->sourceTypes->resolveNode(
            $type,
            $this->context->parsedFile,
            $this->context->resolvedNames,
            $this->context->genericDeclarations,
        ));
    }

    private function resolveSourceLocalType(\Atatusoft\Ppphp\Frontend\Ast\SourceType $type): LocalType
    {
        return LocalType::createFromSemanticType($this->sourceTypes->resolveSourceType(
            $type,
            $this->context->parsedFile,
            $this->context->genericDeclarations,
        ));
    }

    private function resolveOwningClass(Stmt\ClassMethod $method): ?ClassSymbol
    {
        $methodSpan = $this->createNodeSpan($method);

        foreach ($this->context->symbols->classes as $class) {
            if ($class->sourceFile !== $this->context->parsedFile->sourceFile
                || $methodSpan->start->offset < $class->declarationSpan->start->offset
                || $methodSpan->end->offset > $class->declarationSpan->end->offset) {
                continue;
            }

            return $class;
        }

        return null;
    }

    private function resolveTypedDeclaration(Expr\Assign $assignment): ?TypedLocalDeclaration
    {
        $variableStart = $assignment->var->getStartFilePos();
        $declaration = $this->declarationsByVariableOffset[$variableStart] ?? null;

        if ($declaration === null) {
            return null;
        }

        $initializerStart = $assignment->expr->getStartFilePos();
        $initializerEnd = $assignment->expr->getEndFilePos() + 1;
        $whenId = $assignment->expr->getAttribute('ppphpWhenExpressionId');

        if (is_string($whenId)) {
            foreach ($this->context->parsedFile->extensionSyntax->whenExpressions as $when) {
                if ($when->id->value === $whenId) {
                    $initializerStart = $when->span->start->offset;
                    $initializerEnd = $when->span->end->offset;
                    break;
                }
            }
        }

        if (
            $declaration->initializerSpan->start->offset !== $initializerStart
            || $declaration->initializerSpan->end->offset !== $initializerEnd
        ) {
            $this->addInternalAssociationDiagnostic($declaration);

            return null;
        }

        return $declaration;
    }

    private function resolveTypedForInitializer(Expr\Assign $assignment): ?TypedForInitializer
    {
        $variableStart = $assignment->var->getStartFilePos();
        $declaration = $this->forInitializersByVariableOffset[$variableStart] ?? null;

        if ($declaration === null) {
            return null;
        }

        if (
            $declaration->initializerSpan->start->offset !== $assignment->expr->getStartFilePos()
            || $declaration->initializerSpan->end->offset !== $assignment->expr->getEndFilePos() + 1
        ) {
            $this->addDiagnostic(
                DiagnosticCode::InternalCompilerError,
                'The normalized PHP assignment could not be associated with its typed for initializer.',
                $declaration->span,
            );

            return null;
        }

        return $declaration;
    }

    private function addInternalAssociationDiagnostic(TypedLocalDeclaration $declaration): void
    {
        $this->addDiagnostic(
            DiagnosticCode::InternalCompilerError,
            'The normalized PHP assignment could not be associated with its typed local declaration.',
            $declaration->span,
        );
    }

    private function resolveCompoundAssignmentType(
        Expr\AssignOp $assignment,
        VariableSymbol $symbol,
        Scope $scope,
    ): LocalType {
        if ($assignment instanceof Expr\AssignOp\Concat) {
            return LocalType::createAtomic('string');
        }

        if ($assignment instanceof Expr\AssignOp\Div) {
            return LocalType::createAtomic('float');
        }

        $right = $this->expressionTypes->resolve($assignment->expr, $scope);

        if ($assignment instanceof Expr\AssignOp\Plus && $symbol->type->includes('array') && $right->includes('array')) {
            return LocalType::createAtomic('array');
        }

        if (
            $assignment instanceof Expr\AssignOp\Plus
            || $assignment instanceof Expr\AssignOp\Minus
            || $assignment instanceof Expr\AssignOp\Mul
            || $assignment instanceof Expr\AssignOp\Pow
            || $assignment instanceof Expr\AssignOp\Mod
        ) {
            if ($symbol->type->includes('float') || $right->includes('float')) {
                return LocalType::createAtomic('float');
            }

            if ($symbol->type->includes('int') && $right->includes('int')) {
                return LocalType::createAtomic('int');
            }
        }

        return LocalType::createUnknown();
    }

    private function resolveRootVariable(Expr $expression): ?Expr\Variable
    {
        while ($expression instanceof Expr\ArrayDimFetch) {
            $expression = $expression->var;
        }

        return $expression instanceof Expr\Variable ? $expression : null;
    }

    private function resolveVariableName(Expr $variable): ?string
    {
        return $variable instanceof Expr\Variable && is_string($variable->name)
            ? '$' . $variable->name
            : null;
    }

    private function createNodeSpan(Node $node): Span
    {
        $start = $node->getStartFilePos();
        $end = $node->getEndFilePos();

        if ($start < 0 || $end < $start) {
            return $this->context->parsedFile->sourceFile->createSpan(0, 0);
        }

        return $this->context->parsedFile->sourceFile->createSpan($start, $end + 1);
    }

    /** @return list<DiagnosticLabel> */
    private function resolveDeclarationLabels(VariableSymbol $symbol): array
    {
        return $symbol->declarationSpan === null
            ? []
            : [new DiagnosticLabel($symbol->declarationSpan, sprintf('%s is declared here.', $symbol->name))];
    }

    private function addReadonlyDiagnostic(
        DiagnosticCode $code,
        string $message,
        Span $span,
        VariableSymbol $symbol,
    ): void {
        $this->addDiagnostic($code, $message, $span, $this->resolveDeclarationLabels($symbol));
    }

    /** @param list<DiagnosticLabel> $related */
    private function addDiagnostic(
        DiagnosticCode $code,
        string $message,
        Span $span,
        array $related = [],
    ): void {
        $this->context->model->diagnostics->add(new Diagnostic(
            $code,
            $message,
            new DiagnosticLabel($span, $message),
            $related,
        ));
    }

    private function validateCompositeType(string $type, Span $span): void
    {
        foreach ($this->compositeTypes->validateLocal($type) as $message) {
            $this->addDiagnostic(
                DiagnosticCode::InvalidCompositeType,
                $message,
                $span,
            );
        }
    }

    private function enterScope(Scope $scope): void
    {
        $this->context->scopes->push($scope);
    }

    private function leaveScope(): void
    {
        $this->context->scopes->pop();
    }
}
