<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Pass;

use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\DiagnosticLabel;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Frontend\Ast\TypedLocalDeclaration;
use Atatusoft\Ppphp\Frontend\Ast\TypedForeachBinding;
use Atatusoft\Ppphp\Frontend\Ast\TypedForInitializer;
use Atatusoft\Ppphp\Frontend\Ast\WhenBranch;
use Atatusoft\Ppphp\Frontend\Ast\WhenElseBranch;
use Atatusoft\Ppphp\Frontend\Ast\WhenExpression;
use Atatusoft\Ppphp\Semantic\Binding\Enumerations\BindingMutability;
use Atatusoft\Ppphp\Semantic\Binding\LocalBinding;
use Atatusoft\Ppphp\Semantic\Pass\Interfaces\SemanticPass;
use Atatusoft\Ppphp\Semantic\Scope\Scope;
use Atatusoft\Ppphp\Semantic\SemanticContext;
use Atatusoft\Ppphp\Semantic\Symbol\ParameterSymbol;
use Atatusoft\Ppphp\Semantic\Symbol\VariableSymbol;
use Atatusoft\Ppphp\Semantic\Type\ExpressionTypeResolver;
use Atatusoft\Ppphp\Semantic\Type\AtomicType;
use Atatusoft\Ppphp\Semantic\Type\GenericType;
use Atatusoft\Ppphp\Semantic\Type\LocalType;
use Atatusoft\Ppphp\Semantic\Type\MemberTypeResolver;
use Atatusoft\Ppphp\Semantic\Type\NamedType;
use Atatusoft\Ppphp\Semantic\Type\SourceTypeResolver;
use Atatusoft\Ppphp\Semantic\Type\TypeCompatibility;
use Atatusoft\Ppphp\Semantic\Type\TypedArrayType;
use Atatusoft\Ppphp\Semantic\Type\UnionType;
use Atatusoft\Ppphp\Semantic\Type\Interfaces\Type;
use Atatusoft\Ppphp\Semantic\When\WhenBranchAnalysis;
use Atatusoft\Ppphp\Semantic\When\WhenExpressionAnalysis;
use Atatusoft\Ppphp\Semantic\When\WhenExpressionLocation;
use Atatusoft\Ppphp\Semantic\When\WhenExpressionSite;
use Atatusoft\Ppphp\Semantic\When\WhenFragmentParser;
use Atatusoft\Ppphp\Semantic\When\WhenParsedBranch;
use Atatusoft\Ppphp\Semantic\When\WhenParsedExpression;
use Atatusoft\Ppphp\Source\Span;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt;

/** @phpstan-type WhenFlow array{canComplete: bool, types: list<LocalType>, spans: list<Span>, transfers?: list<Stmt>} */
final class CheckWhenExpressionsPass implements SemanticPass
{
    private SemanticContext $context;

    /** @var array<string, WhenParsedExpression> */
    private array $parsed = [];

    /** @var array<string, WhenExpressionLocation> */
    private array $locations = [];

    /** @var array<int, TypedLocalDeclaration> */
    private array $typedLocals = [];

    /** @var array<int, TypedForInitializer> */
    private array $typedForInitializers = [];

    /** @var array<int, TypedForeachBinding> */
    private array $typedForeachBindings = [];

    private int $nestedCallableDepth = 0;

    /** @var array<int, Stmt> */
    private array $transferTargets = [];

    /** @var array<int, true> */
    private array $freshArrayResults = [];

    private ExpressionTypeResolver $expressionTypes;

    private readonly ?ExpressionTypeResolver $configuredExpressionTypes;

    private readonly SourceTypeResolver $sourceTypes;

    private MemberTypeResolver $members;

    public function __construct(
        private readonly WhenFragmentParser $fragments = new WhenFragmentParser(),
        ?ExpressionTypeResolver $expressionTypes = null,
        private readonly TypeCompatibility $compatibility = new TypeCompatibility(),
        ?SourceTypeResolver $sourceTypes = null,
    ) {
        $this->configuredExpressionTypes = $expressionTypes;
        $this->expressionTypes = $expressionTypes ?? new ExpressionTypeResolver();
        $this->sourceTypes = $sourceTypes ?? new SourceTypeResolver();
    }

    public function execute(SemanticContext $context): void
    {
        $this->context = $context;
        $this->members = new MemberTypeResolver($context->symbols);
        $this->expressionTypes = $this->configuredExpressionTypes ?? new ExpressionTypeResolver($context, $this->sourceTypes);
        $this->parsed = [];
        $this->locations = [];
        $this->typedLocals = [];
        $this->typedForInitializers = [];
        $this->typedForeachBindings = [];
        $this->nestedCallableDepth = 0;
        $this->transferTargets = [];
        $this->freshArrayResults = [];

        foreach ($context->parsedFile->extensionSyntax->typedLocals as $local) {
            $this->typedLocals[$local->variableSpan->start->offset] = $local;
        }

        foreach ($context->parsedFile->extensionSyntax->typedForInitializers as $local) {
            $this->typedForInitializers[$local->variableSpan->start->offset] = $local;
        }

        foreach ($context->parsedFile->extensionSyntax->typedForeachBindings as $binding) {
            $this->typedForeachBindings[$binding->variableSpan->start->offset] = $binding;
        }

        foreach ($context->parsedFile->extensionSyntax->whenExpressions as $when) {
            $this->parseExpression($when);
        }

        foreach ($context->parsedFile->statements as $statement) {
            $this->indexNode($statement, null, [], false, false);
        }

        foreach ($this->parsed as $expression) {
            foreach ($expression->branches as $branch) {
                if ($branch->condition !== null) {
                    $this->indexNode($branch->condition, null, [], true, true);
                }
                foreach ($branch->statements as $statement) {
                    $this->indexNode($statement, null, [], true, false);
                }
            }
        }

        foreach ($context->parsedFile->extensionSyntax->whenExpressions as $when) {
            if ($when->parentId !== null || $context->model->whenExpressions->find($when->id) !== null) {
                continue;
            }

            $location = $this->locations[$when->id->value] ?? null;
            if ($location === null) {
                $this->addDiagnostic(
                    DiagnosticCode::InternalCompilerError,
                    'A parsed `when` expression could not be associated with its normalized placeholder.',
                    $when->span,
                );
                continue;
            }

            $this->analyzeExpression($when, $this->createOuterScope($when, $location));
        }
    }

    private function parseExpression(WhenExpression $when): void
    {
        $branches = [];

        foreach ($when->branches as $branch) {
            $condition = $this->fragments->parseCondition($this->context->parsedFile, $branch->conditionSpan);
            $body = $this->fragments->parseBody($this->context->parsedFile, $branch->bodySpan);
            $this->context->model->diagnostics->addAll($condition->diagnostics);
            $this->context->model->diagnostics->addAll($body->diagnostics);
            $branches[] = new WhenParsedBranch($branch, $condition->expression, $body->statements);
        }

        $body = $this->fragments->parseBody($this->context->parsedFile, $when->elseBranch->bodySpan);
        $this->context->model->diagnostics->addAll($body->diagnostics);
        $branches[] = new WhenParsedBranch($when->elseBranch, null, $body->statements);
        $this->parsed[$when->id->value] = new WhenParsedExpression($when, $branches);
    }

    /** @param list<Node> $ancestors */
    private function indexNode(
        Node $node,
        ?Node $parent,
        array $ancestors,
        bool $fragment,
        bool $condition,
    ): void {
        if ($fragment && ($node instanceof Stmt\Break_ || $node instanceof Stmt\Continue_)) {
            $this->indexTransfer($node, $parent === null ? $ancestors : [$parent, ...$ancestors]);
        }

        $whenId = $node->getAttribute('ppphpWhenExpressionId');

        if (is_string($whenId) && $node instanceof Expr) {
            $site = $condition ? WhenExpressionSite::Unsupported : $this->resolveSite($node, $parent, $ancestors);
            $statementAncestors = $parent === null ? $ancestors : [$parent, ...$ancestors];
            $statement = $this->resolveStatement($node, $statementAncestors);
            $this->locations[$whenId] = new WhenExpressionLocation(
                $site,
                $node,
                $statement,
                $parent,
                $ancestors,
                $fragment,
            );
        }

        $nextAncestors = $parent === null ? $ancestors : [$parent, ...$ancestors];

        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->{$name};
            if ($value instanceof Node) {
                $this->indexNode($value, $node, $nextAncestors, $fragment, $condition);
            } elseif (is_array($value)) {
                foreach ($value as $child) {
                    if ($child instanceof Node) {
                        $this->indexNode($child, $node, $nextAncestors, $fragment, $condition);
                    }
                }
            }
        }
    }

    /** @param list<Node> $ancestors */
    private function indexTransfer(Stmt\Break_|Stmt\Continue_ $transfer, array $ancestors): void
    {
        // Each parsed branch is indexed separately, so no target can cross a when boundary.
        // Nested callables retain their ordinary PHP control flow.
        if (array_any($ancestors, static fn (Node $node): bool => $node instanceof Node\FunctionLike)) {
            return;
        }

        $level = $transfer->num === null ? 1
            : ($transfer->num instanceof Node\Scalar\Int_ ? $transfer->num->value : 0);
        $message = 'The transfer level must be a positive integer targeting a loop or switch inside this `when` branch.';
        if ($level > 0) {
            $message = 'This transfer would leave the `when` branch; its target must be a loop or switch inside the branch.';
            foreach ($ancestors as $ancestor) {
                if ($ancestor instanceof Stmt\Finally_) {
                    $message = 'A control transfer cannot leave a `finally` block; its target must be inside that block.';
                    break;
                }
                if ($ancestor instanceof Stmt\TryCatch && $ancestor->finally !== null) {
                    $message = 'A control transfer across `try`/`finally` inside a `when` branch is not supported yet.';
                    break;
                }
                if (
                    $ancestor instanceof Stmt\For_ || $ancestor instanceof Stmt\Foreach_
                    || $ancestor instanceof Stmt\While_ || $ancestor instanceof Stmt\Do_
                    || $ancestor instanceof Stmt\Switch_
                ) {
                    if (--$level !== 0) {
                        continue;
                    }
                    if ($transfer instanceof Stmt\Continue_ && $ancestor instanceof Stmt\Switch_) {
                        $message = '`continue` targets a switch here. Use `break` to leave it, or a higher level to continue an enclosing loop inside the branch.';
                        break;
                    }
                    $this->transferTargets[$this->span($transfer)->start->offset] = $ancestor;

                    return;
                }
            }
        }

        $this->addDiagnostic(DiagnosticCode::WhenControlTransferNotAllowed, $message, $this->span($transfer));
    }

    /** @param list<Node> $ancestors */
    private function resolveSite(Expr $placeholder, ?Node $parent, array $ancestors): WhenExpressionSite
    {
        if ($parent instanceof Expr\Assign && $parent->expr === $placeholder) {
            foreach ($this->typedLocals as $local) {
                if ($local->initializerSpan->start->offset === $this->span($placeholder)->start->offset) {
                    return WhenExpressionSite::TypedLocalInitializer;
                }
            }

            return WhenExpressionSite::Assignment;
        }

        if ($parent instanceof Stmt\Return_ && $parent->expr === $placeholder) {
            return WhenExpressionSite::ReturnOperand;
        }

        if (
            $parent instanceof Arg
            && $parent->value === $placeholder
            && !$parent->unpack
            && array_any($ancestors, static fn (Node $ancestor): bool => $ancestor instanceof Expr\CallLike)
        ) {
            return WhenExpressionSite::CallArgument;
        }

        if ($parent instanceof ArrayItem && $parent->value === $placeholder && !$parent->unpack) {
            return WhenExpressionSite::ArrayValue;
        }

        return WhenExpressionSite::Unsupported;
    }

    /** @param list<Node> $ancestors */
    private function resolveStatement(Node $node, array $ancestors): Node
    {
        if ($node instanceof Stmt) {
            return $node;
        }

        foreach ($ancestors as $ancestor) {
            if ($ancestor instanceof Stmt && !$ancestor instanceof Stmt\ElseIf_ && !$ancestor instanceof Stmt\Else_) {
                return $ancestor;
            }
        }

        return $node;
    }

    private function analyzeExpression(WhenExpression $when, Scope $outerScope): WhenExpressionAnalysis
    {
        $existing = $this->context->model->whenExpressions->find($when->id);
        if ($existing !== null) {
            return $existing;
        }

        $parsed = $this->parsed[$when->id->value] ?? null;
        $location = $this->locations[$when->id->value] ?? null;

        if ($parsed === null || $location === null) {
            throw new \LogicException('A when expression must be parsed and located before analysis.');
        }

        if ($location->site === WhenExpressionSite::Unsupported) {
            $this->addDiagnostic(
                DiagnosticCode::WhenPositionNotSupported,
                'This `when` expression is not in a supported value position.',
                $when->span,
            );
        }

        $analyses = [];
        $allTypes = [];
        $allResultSpans = [];

        foreach ($parsed->branches as $branch) {
            $scope = $this->copyScope($outerScope, 'when-branch');

            if ($branch->condition !== null) {
                $this->inspectExpression($branch->condition, $scope);
            }

            $flow = $this->analyzeStatements($branch->statements, $scope);
            if ($flow['canComplete']) {
                $this->addDiagnostic(
                    DiagnosticCode::WhenBranchDoesNotProduceValue,
                    'Every reachable path through a `when` branch must yield a value or terminate.',
                    $branch->syntax->bodySpan,
                );
            }

            array_push($allTypes, ...$flow['types']);
            array_push($allResultSpans, ...$flow['spans']);
            $analyses[] = new WhenBranchAnalysis(
                $branch->syntax,
                $branch->condition,
                $branch->statements,
                $this->mergeTypes($flow['types']),
                $flow['spans'],
                $flow['canComplete'],
            );
        }

        $resultType = $this->mergeTypes($allTypes);
        $analysis = new WhenExpressionAnalysis(
            $when,
            $location->site,
            $location->placeholder,
            $location->statement,
            $analyses,
            $resultType,
            $this->createTemporaryName($when),
            $allResultSpans !== [] && array_all($allResultSpans,
                fn (Span $span): bool => isset($this->freshArrayResults[$span->start->offset])),
        );
        $this->context->model->whenExpressions->record($analysis);
        $this->checkContextType($analysis, $outerScope);
        $this->checkByReferenceArgument($analysis, $outerScope);

        return $analysis;
    }

    /**
     * @param list<Stmt> $statements
     * @return WhenFlow
     */
    private function analyzeStatements(array $statements, Scope $scope): array
    {
        $flow = ['canComplete' => true, 'types' => [], 'spans' => [], 'transfers' => []];

        foreach ($statements as $statement) {
            if (!$flow['canComplete']) {
                break;
            }

            $next = $this->analyzeStatement($statement, $scope);
            array_push($flow['types'], ...$next['types']);
            array_push($flow['spans'], ...$next['spans']);
            array_push($flow['transfers'], ...($next['transfers'] ?? []));
            $flow['canComplete'] = $next['canComplete'];
        }

        return $flow;
    }

    /** @return WhenFlow */
    private function analyzeStatement(Stmt $statement, Scope $scope): array
    {
        if ($statement instanceof Stmt\Return_) {
            if ($statement->expr === null) {
                $this->addDiagnostic(
                    DiagnosticCode::WhenResultRequiresValue,
                    'A branch-level `return` must provide the `when` result value.',
                    $this->span($statement),
                );

                return ['canComplete' => false, 'types' => [], 'spans' => []];
            }

            $this->inspectExpression($statement->expr, $scope);
            $type = $this->resolveExpressionType($statement->expr, $scope);
            $span = $this->span($statement->expr);
            if ($this->context->model->whenExpressions->resolveArrayFreshness($statement->expr)) {
                $this->freshArrayResults[$span->start->offset] = true;
            }

            return [
                'canComplete' => false,
                'types' => [$type],
                'spans' => [$span],
            ];
        }

        if ($statement instanceof Stmt\If_) {
            $this->inspectExpression($statement->cond, $scope);
            $flows = [$this->analyzeStatements(array_values($statement->stmts), $this->copyScope($scope, 'when-if'))];
            foreach ($statement->elseifs as $elseif) {
                $this->inspectExpression($elseif->cond, $scope);
                $flows[] = $this->analyzeStatements(array_values($elseif->stmts), $this->copyScope($scope, 'when-elseif'));
            }
            $flows[] = $statement->else === null
                ? ['canComplete' => true, 'types' => [], 'spans' => []]
                : $this->analyzeStatements(array_values($statement->else->stmts), $this->copyScope($scope, 'when-else'));

            return $this->mergeFlows($flows);
        }

        if ($statement instanceof Stmt\TryCatch) {
            $flows = [$this->analyzeStatements(array_values($statement->stmts), $this->copyScope($scope, 'when-try'))];
            foreach ($statement->catches as $catch) {
                $catchScope = $this->copyScope($scope, 'when-catch');
                if ($catch->var instanceof Expr\Variable && is_string($catch->var->name)) {
                    $catchScope->declare(new VariableSymbol(
                        '$' . $catch->var->name,
                        LocalType::createUnknown(),
                        BindingMutability::Mutable,
                        $this->span($catch->var),
                    ));
                }
                $flows[] = $this->analyzeStatements(array_values($catch->stmts), $catchScope);
            }
            $combined = $this->mergeFlows($flows);
            if ($statement->finally === null) {
                return $combined;
            }
            $finally = $this->analyzeStatements(array_values($statement->finally->stmts), $this->copyScope($scope, 'when-finally'));
            if (!$finally['canComplete']) {
                return $finally;
            }
            array_push($combined['types'], ...$finally['types']);
            array_push($combined['spans'], ...$finally['spans']);
            $combined['transfers'] = [...($combined['transfers'] ?? []), ...($finally['transfers'] ?? [])];

            return $combined;
        }

        if ($statement instanceof Stmt\Foreach_) {
            $this->inspectExpression($statement->expr, $scope);
            $this->declareForeachTarget($statement->keyVar, $scope);
            $this->declareForeachTarget($statement->valueVar, $scope);
            $body = $this->analyzeStatements(array_values($statement->stmts), $this->copyScope($scope, 'when-loop'));
            $body['canComplete'] = true;

            return $this->consumeTransfers($body, $statement);
        }

        if (
            $statement instanceof Stmt\For_
            || $statement instanceof Stmt\While_
            || $statement instanceof Stmt\Do_
        ) {
            $this->inspectNode($statement, $scope, true);
            $body = $this->analyzeStatements(array_values($statement->stmts), $this->copyScope($scope, 'when-loop'));
            $body['canComplete'] = true;

            return $this->consumeTransfers($body, $statement);
        }

        if ($statement instanceof Stmt\Switch_) {
            $this->inspectExpression($statement->cond, $scope);
            $flows = [];
            $hasDefault = false;
            foreach ($statement->cases as $case) {
                $hasDefault = $hasDefault || $case->cond === null;
                if ($case->cond !== null) {
                    $this->inspectExpression($case->cond, $scope);
                }
                $flows[] = $this->analyzeStatements(array_values($case->stmts), $this->copyScope($scope, 'when-case'));
            }
            // A case that falls through continues into the next case, not past the switch.
            $canFallThrough = true;
            foreach (array_reverse($flows, true) as $index => $flow) {
                $canFallThrough = $flow['canComplete'] && $canFallThrough;
                $flow['canComplete'] = $canFallThrough;
                $flows[$index] = $flow;
            }
            if (!$hasDefault) {
                $flows[] = ['canComplete' => true, 'types' => [], 'spans' => []];
            }

            return $this->consumeTransfers($this->mergeFlows($flows), $statement);
        }

        if ($statement instanceof Stmt\Break_ || $statement instanceof Stmt\Continue_) {
            $target = $this->transferTargets[$this->span($statement)->start->offset] ?? null;

            return ['canComplete' => false, 'types' => [], 'spans' => [], 'transfers' => $target === null ? [] : [$target]];
        }

        if (($statement instanceof Stmt\Goto_ || $statement instanceof Stmt\Label) && $this->nestedCallableDepth === 0) {
            $this->addDiagnostic(
                DiagnosticCode::WhenGotoNotAllowed,
                '`goto` and labels cannot appear in a `when` branch.',
                $this->span($statement),
            );
        }

        $this->inspectNode($statement, $scope, false);
        $terminates = $statement instanceof Stmt\Expression
            && ($statement->expr instanceof Expr\Throw_ || $statement->expr instanceof Expr\Exit_ || $this->resolveTerminatingExpression($statement->expr, $scope));

        return ['canComplete' => !$terminates, 'types' => [], 'spans' => []];
    }

    private function inspectNode(Node $node, Scope $scope, bool $skipLoopBody): void
    {
        if ($node instanceof Expr) {
            $this->inspectExpression($node, $scope);

            return;
        }

        if ($node instanceof Stmt\Function_ || $node instanceof Stmt\ClassMethod) {
            $callableScope = new Scope('when-nested-callable');
            foreach ($node->params as $parameter) {
                $this->declareParameter($parameter, $callableScope);
            }
            $this->nestedCallableDepth++;
            foreach ($node->stmts ?? [] as $statement) {
                $this->inspectNode($statement, $callableScope, false);
            }
            $this->nestedCallableDepth--;

            return;
        }

        if (($node instanceof Stmt\Break_ || $node instanceof Stmt\Continue_) && $this->nestedCallableDepth === 0) {
            // Transfers are validated during indexing, including unreachable statements.
            return;
        }

        if (($node instanceof Stmt\Goto_ || $node instanceof Stmt\Label) && $this->nestedCallableDepth === 0) {
            $this->addDiagnostic(
                DiagnosticCode::WhenGotoNotAllowed,
                '`goto` and labels cannot appear in a `when` branch.',
                $this->span($node),
            );

            return;
        }

        foreach ($node->getSubNodeNames() as $name) {
            if ($skipLoopBody && $name === 'stmts') {
                continue;
            }
            $value = $node->{$name};
            if ($value instanceof Node) {
                $this->inspectNode($value, $scope, false);
            } elseif (is_array($value)) {
                foreach ($value as $child) {
                    if ($child instanceof Node) {
                        $this->inspectNode($child, $scope, false);
                    }
                }
            }
        }
    }

    private function inspectExpression(Expr $expression, Scope $scope): void
    {
        $whenId = $expression->getAttribute('ppphpWhenExpressionId');
        if (is_string($whenId)) {
            $syntax = $this->parsed[$whenId]->syntax ?? null;
            if ($syntax !== null) {
                $this->analyzeExpression($syntax, $scope);
            }

            return;
        }

        if (($expression instanceof Expr\Yield_ || $expression instanceof Expr\YieldFrom) && $this->nestedCallableDepth === 0) {
            $this->addDiagnostic(
                DiagnosticCode::WhenYieldNotAllowed,
                '`yield` and `yield from` cannot appear in a `when` branch.',
                $this->span($expression),
            );
        }

        if ($expression instanceof Expr\Assign) {
            $this->inspectExpression($expression->expr, $scope);
            $this->inspectAssignment($expression, $scope);

            return;
        }

        if ($expression instanceof Expr\Closure || $expression instanceof Expr\ArrowFunction) {
            $callableScope = new Scope('when-nested-callable');
            foreach ($expression->params as $parameter) {
                $this->declareParameter($parameter, $callableScope);
            }
            $this->nestedCallableDepth++;
            if ($expression instanceof Expr\Closure) {
                foreach ($expression->uses as $use) {
                    if (is_string($use->var->name) && ($symbol = $scope->resolve('$' . $use->var->name)) !== null) {
                        $callableScope->import($symbol);
                    }
                }
                foreach ($expression->stmts as $statement) {
                    $this->inspectNode($statement, $callableScope, false);
                }
            } else {
                foreach ($scope->symbols as $symbol) {
                    if (!$expression->static || $symbol->name !== '$this') {
                        $callableScope->import($symbol);
                    }
                }
                $this->inspectExpression($expression->expr, $callableScope);
            }
            $this->nestedCallableDepth--;

            return;
        }

        if ($expression instanceof Expr\Variable && is_string($expression->name)) {
            $name = '$' . $expression->name;
            $symbol = $scope->resolve($name);
            if ($symbol === null) {
                $this->addDiagnostic(
                    DiagnosticCode::LocalVariableNotDeclared,
                    sprintf('%s must be declared before it can be read.', $name),
                    $this->span($expression),
                );
            } else {
                $symbol->binding?->recordRead($this->span($expression));
            }

            return;
        }

        foreach ($expression->getSubNodeNames() as $name) {
            $value = $expression->{$name};
            if ($value instanceof Expr) {
                $this->inspectExpression($value, $scope);
            } elseif ($value instanceof Node) {
                $this->inspectNode($value, $scope, false);
            } elseif (is_array($value)) {
                foreach ($value as $child) {
                    if ($child instanceof Expr) {
                        $this->inspectExpression($child, $scope);
                    } elseif ($child instanceof Node) {
                        $this->inspectNode($child, $scope, false);
                    }
                }
            }
        }
    }

    private function inspectAssignment(Expr\Assign $assignment, Scope $scope): void
    {
        if (!$assignment->var instanceof Expr\Variable || !is_string($assignment->var->name)) {
            $this->inspectNode($assignment->var, $scope, false);

            return;
        }

        $name = '$' . $assignment->var->name;
        $offset = $this->span($assignment->var)->start->offset;
        $declaration = $this->typedLocals[$offset] ?? $this->typedForInitializers[$offset] ?? null;
        $actual = $this->resolveExpressionType($assignment->expr, $scope);

        if ($declaration !== null) {
            $existing = $scope->resolve($name);
            if ($existing !== null) {
                $this->addDiagnostic(
                    DiagnosticCode::DuplicateLocalDeclaration,
                    sprintf('%s cannot shadow a binding visible to this `when` branch.', $name),
                    $declaration->variableSpan,
                    [new DiagnosticLabel($existing->declarationSpan ?? $declaration->variableSpan, 'The visible binding is declared here.')],
                );

                return;
            }

            $declared = $this->resolveSourceLocalType($declaration->type);
            if (!$this->compatibility->accepts($declared, $actual, $this->context->symbols)) {
                $this->addDiagnostic(
                    DiagnosticCode::InitializerNotAssignableToDeclaredType,
                    sprintf('Initializer of type %s is not assignable to declared type %s.', $actual->text, $declared->text),
                    $declaration->initializerSpan,
                    [new DiagnosticLabel($declaration->type->span, 'The local type is declared here.')],
                );
            }

            $binding = new LocalBinding(
                $declaration->id,
                $name,
                $declared,
                $declaration->readonlySpan === null ? BindingMutability::Mutable : BindingMutability::Readonly,
                $declaration->span,
                $declaration->variableSpan,
                $declaration->initializerSpan,
                $assignment->expr,
                $actual,
            );
            $binding->recordWrite($declaration->variableSpan);
            $this->context->model->bindings->record($binding);
            $scope->declare(new VariableSymbol($name, $declared, $binding->mutability, $declaration->variableSpan, $binding));

            return;
        }

        $symbol = $scope->resolve($name);
        if ($symbol === null) {
            $this->addDiagnostic(
                DiagnosticCode::AssignmentCannotDeclareVariable,
                sprintf('%s must be declared with an explicit type before it can be assigned.', $name),
                $this->span($assignment->var),
            );

            return;
        }

        if ($symbol->mutability === BindingMutability::Readonly) {
            $this->addDiagnostic(
                DiagnosticCode::ReadonlyLocalCannotBeReassigned,
                sprintf('%s cannot be assigned because it is readonly.', $name),
                $this->span($assignment->var),
                [new DiagnosticLabel($symbol->declarationSpan ?? $this->span($assignment->var), 'The readonly binding is declared here.')],
            );

            return;
        }

        if (!$this->compatibility->accepts($symbol->type, $actual, $this->context->symbols)) {
            $this->addDiagnostic(
                DiagnosticCode::AssignmentNotAssignableToDeclaredType,
                sprintf('Value of type %s is not assignable to %s of type %s.', $actual->text, $name, $symbol->type->text),
                $this->span($assignment->expr),
            );
        }
        $symbol->binding?->recordWrite($this->span($assignment->var));
    }

    private function resolveExpressionType(Expr $expression, Scope $scope): LocalType
    {
        $when = $this->context->model->whenExpressions->findPlaceholder($expression);
        if ($when !== null) {
            return $when->resultType;
        }

        if ($expression instanceof Expr\Array_) {
            return $this->resolveArrayLiteralType($expression, $scope);
        }

        return $this->expressionTypes->resolve($expression, $scope);
    }

    private function checkContextType(WhenExpressionAnalysis $analysis, Scope $scope): void
    {
        $expected = $this->resolveExpectedType($analysis, $scope);
        if ($expected === null || $analysis->resultType->unknown || $this->compatibility->compare(
            $expected->semanticType,
            $analysis->resultType->semanticType,
            $this->context->symbols,
            $analysis->resultIsFreshArray,
        )->isAccepted()) {
            return;
        }

        $related = [];
        foreach ($analysis->branches as $branch) {
            foreach ($branch->resultSpans as $span) {
                $related[] = new DiagnosticLabel($span, sprintf('This branch contributes %s.', $branch->resultType->text));
            }
        }

        $this->addDiagnostic(
            DiagnosticCode::WhenResultTypeDoesNotMatch,
            sprintf('The `when` result type %s is not assignable to expected type %s.', $analysis->resultType->text, $expected->text),
            $analysis->syntax->span,
            $related,
        );
    }

    private function resolveExpectedType(WhenExpressionAnalysis $analysis, Scope $scope): ?LocalType
    {
        $location = $this->locations[$analysis->syntax->id->value];

        if ($analysis->site === WhenExpressionSite::TypedLocalInitializer) {
            foreach ($this->typedLocals as $local) {
                if ($local->initializerSpan->start->offset === $analysis->syntax->span->start->offset) {
                    return $this->resolveSourceLocalType($local->type);
                }
            }
        }

        if ($analysis->site === WhenExpressionSite::Assignment && $location->parent instanceof Expr\Assign && $location->parent->var instanceof Expr\Variable && is_string($location->parent->var->name)) {
            return $scope->resolve('$' . $location->parent->var->name)?->type;
        }

        if ($analysis->site === WhenExpressionSite::Assignment && $location->parent instanceof Expr\Assign) {
            return $this->resolveAssignmentTargetType($location->parent->var, $scope);
        }

        if ($analysis->site === WhenExpressionSite::ReturnOperand) {
            foreach ($location->ancestors as $ancestor) {
                if ($ancestor instanceof Stmt\Function_ && $ancestor->name->toString() === '__ppphp_when_fragment') {
                    return null;
                }
                if ($ancestor instanceof Stmt\Function_ || $ancestor instanceof Stmt\ClassMethod || $ancestor instanceof Expr\Closure || $ancestor instanceof Expr\ArrowFunction) {
                    return $ancestor->returnType === null
                        ? null
                        : LocalType::createFromSemanticType($this->sourceTypes->resolveNode(
                            $ancestor->returnType,
                            $this->context->parsedFile,
                            $this->context->resolvedNames,
                            $this->context->genericDeclarations,
                        ));
                }
            }
        }

        if ($analysis->site === WhenExpressionSite::CallArgument) {
            $parameter = $this->resolveArgumentParameter($location, $scope);

            return $parameter?->type === null
                ? null
                : LocalType::createFromSemanticType($parameter->type->semanticType);
        }

        if ($analysis->site === WhenExpressionSite::ArrayValue) {
            foreach ($location->ancestors as $ancestor) {
                if (!$ancestor instanceof Expr\Assign) {
                    continue;
                }
                $declaration = $ancestor->var instanceof Expr\Variable
                    ? $this->typedLocals[$this->span($ancestor->var)->start->offset] ?? null
                    : null;
                $type = $declaration === null ? null : $this->resolveSourceLocalType($declaration->type)->semanticType;
                if ($type instanceof TypedArrayType) {
                    return LocalType::createFromSemanticType($type->valueType);
                }
            }
        }

        return null;
    }

    private function checkByReferenceArgument(WhenExpressionAnalysis $analysis, Scope $scope): void
    {
        if ($analysis->site !== WhenExpressionSite::CallArgument) {
            return;
        }

        $location = $this->locations[$analysis->syntax->id->value];
        $parameter = $this->resolveArgumentParameter($location, $scope);
        if (($location->parent instanceof Arg && $location->parent->byRef) || $parameter?->byReference === true) {
            $this->addDiagnostic(
                DiagnosticCode::WhenByReferenceArgumentNotAllowed,
                'A `when` result cannot be passed to a known by-reference parameter.',
                $analysis->syntax->span,
                $parameter === null ? [] : [new DiagnosticLabel($parameter->declarationSpan, 'The by-reference parameter is declared here.')],
            );
        }
    }

    private function resolveArgumentParameter(WhenExpressionLocation $location, Scope $scope): ?ParameterSymbol
    {
        if (!$location->parent instanceof Arg) {
            return null;
        }

        $call = null;
        foreach ($location->ancestors as $ancestor) {
            if ($ancestor instanceof Expr\CallLike) {
                $call = $ancestor;
                break;
            }
        }
        if ($call === null) {
            return null;
        }

        $position = array_search($location->parent, $call->getArgs(), true);
        if (!is_int($position)) {
            return null;
        }

        $parameters = [];
        if ($call instanceof Expr\FuncCall && $call->name instanceof Node\Name) {
            $name = $this->context->resolvedNames->resolve($call->name) ?? $call->name->toString();
            $function = $this->context->symbols->findFunction($name) ?? $this->context->symbols->findFunction($call->name->toString());
            $parameters = $function === null ? [] : $function->parameters;
        } elseif ($call instanceof Expr\New_ && $call->class instanceof Node\Name) {
            $receiver = $this->sourceTypes->resolveNode(
                $call->class,
                $this->context->parsedFile,
                $this->context->resolvedNames,
                $this->context->genericDeclarations,
            );
            $parameters = $this->resolveMemberParameters($receiver, '__construct');
        } elseif ($call instanceof Expr\StaticCall && $call->class instanceof Node\Name && $call->name instanceof Node\Identifier) {
            $receiver = $this->sourceTypes->resolveNode(
                $call->class,
                $this->context->parsedFile,
                $this->context->resolvedNames,
                $this->context->genericDeclarations,
            );
            $parameters = $this->resolveMemberParameters($receiver, $call->name->toString());
        } elseif (($call instanceof Expr\MethodCall || $call instanceof Expr\NullsafeMethodCall) && $call->name instanceof Node\Identifier) {
            $receiver = $this->resolveExpressionType($call->var, $scope)->semanticType;
            $parameters = $this->resolveMemberParameters($receiver, $call->name->toString());
        }

        if ($location->parent->name !== null) {
            foreach ($parameters as $parameter) {
                if (strcasecmp(ltrim($parameter->name, '$'), $location->parent->name->toString()) === 0) {
                    return $parameter;
                }
            }

            return null;
        }

        return $parameters[$position] ?? null;
    }

    /** @return list<ParameterSymbol> */
    private function resolveMemberParameters(Type $receiver, string $name): array
    {
        $resolution = $this->members->resolveMethod($receiver, $name);

        if (count($resolution->targets) !== 1) {
            return [];
        }

        $target = $resolution->targets[0];
        $member = $target['member'];

        if (!$member instanceof \Atatusoft\Ppphp\Semantic\Symbol\MethodSymbol) {
            return [];
        }

        return array_map(
            fn (ParameterSymbol $parameter): ParameterSymbol => new ParameterSymbol(
                $parameter->name,
                $parameter->type === null
                    ? null
                    : new NamedType($this->members->resolveTargetType(
                        $parameter->type->semanticType,
                        $target['receiver'],
                        $target['substitutions'],
                        $target['calledReceiver'],
                    )),
                $parameter->variadic,
                $parameter->byReference,
                $parameter->promoted,
                $parameter->declarationSpan,
                $parameter->selectionSpan,
                $parameter->documentedType,
            ),
            $member->parameters,
        );
    }

    private function createOuterScope(WhenExpression $when, WhenExpressionLocation $location): Scope
    {
        $scope = new Scope('when-outer');
        $callable = null;
        foreach ($location->ancestors as $ancestor) {
            if ($ancestor instanceof Stmt\Function_ || $ancestor instanceof Stmt\ClassMethod || $ancestor instanceof Expr\Closure || $ancestor instanceof Expr\ArrowFunction) {
                if (!$ancestor instanceof Stmt\Function_ || $ancestor->name->toString() !== '__ppphp_when_fragment') {
                    $callable = $ancestor;
                    break;
                }
            }
        }

        if ($callable !== null) {
            foreach ($callable->params as $parameter) {
                $this->declareParameter($parameter, $scope);
            }
            if ($callable instanceof Stmt\ClassMethod && !$callable->isStatic()) {
                $owner = $this->resolveOwningClass($callable);
                $parameters = $owner?->genericDeclaration === null ? [] : $owner->genericDeclaration->parameters;
                $selfType = $owner === null
                    ? new AtomicType('object')
                    : ($parameters === []
                        ? new AtomicType($owner->fullyQualifiedName)
                        : new GenericType(new AtomicType($owner->fullyQualifiedName), $parameters));
                $scope->declare(new VariableSymbol(
                    '$this',
                    LocalType::createFromSemanticType($selfType),
                    BindingMutability::Mutable,
                    $this->span($callable),
                ));
            }
        }

        $callableSpan = $callable === null ? null : $this->span($callable);
        foreach ($this->context->model->bindings->bindings as $binding) {
            if ($binding->declarationSpan->start->offset >= $when->span->start->offset) {
                continue;
            }
            if ($callableSpan !== null && (
                $binding->declarationSpan->start->offset < $callableSpan->start->offset
                || $binding->declarationSpan->end->offset > $callableSpan->end->offset
            )) {
                continue;
            }
            $scope->declare(new VariableSymbol($binding->name, $binding->type, $binding->mutability, $binding->variableSpan, $binding));
        }

        return $scope;
    }

    private function declareParameter(Param $parameter, Scope $scope): void
    {
        if (!$parameter->var instanceof Expr\Variable || !is_string($parameter->var->name)) {
            return;
        }
        $scope->declare(new VariableSymbol(
            '$' . $parameter->var->name,
            $parameter->type === null
                ? LocalType::createUnknown()
                : LocalType::createFromSemanticType($this->sourceTypes->resolveNode(
                    $parameter->type,
                    $this->context->parsedFile,
                    $this->context->resolvedNames,
                    $this->context->genericDeclarations,
                )),
            BindingMutability::Mutable,
            $this->span($parameter->var),
        ));
    }

    private function copyScope(Scope $scope, string $kind): Scope
    {
        $copy = new Scope($kind);
        foreach ($scope->symbols as $symbol) {
            $copy->import($symbol);
        }

        return $copy;
    }

    private function declareForeachTarget(?Expr $target, Scope $scope): void
    {
        if (!$target instanceof Expr\Variable || !is_string($target->name)) {
            if ($target !== null) {
                $this->inspectExpression($target, $scope);
            }

            return;
        }

        $name = '$' . $target->name;
        $span = $this->span($target);
        $declaration = $this->typedForeachBindings[$span->start->offset] ?? null;

        if ($declaration === null) {
            $symbol = $scope->resolve($name);

            if ($symbol === null) {
                $this->addDiagnostic(
                    DiagnosticCode::AssignmentCannotDeclareVariable,
                    sprintf('%s must be declared with an explicit type before it can be assigned.', $name),
                    $span,
                );
            } elseif ($symbol->mutability === BindingMutability::Readonly) {
                $this->addDiagnostic(
                    DiagnosticCode::ReadonlyLocalCannotBeReassigned,
                    sprintf('%s cannot be assigned because it is readonly.', $name),
                    $span,
                );
            } else {
                $symbol->binding?->recordWrite($span);
            }

            return;
        }

        $existing = $scope->resolve($name);
        if ($existing !== null) {
            $this->addDiagnostic(
                DiagnosticCode::DuplicateLocalDeclaration,
                sprintf('%s cannot shadow a binding visible to this `when` branch.', $name),
                $declaration->variableSpan,
                [new DiagnosticLabel($existing->declarationSpan ?? $declaration->variableSpan, 'The visible binding is declared here.')],
            );

            return;
        }

        $type = $this->resolveSourceLocalType($declaration->type);
        $binding = new LocalBinding(
            $declaration->id,
            $name,
            $type,
            BindingMutability::Mutable,
            $declaration->span,
            $declaration->variableSpan,
            null,
            null,
            LocalType::createUnknown(),
        );
        $binding->recordWrite($declaration->variableSpan);
        $this->context->model->bindings->record($binding);
        $scope->declare(new VariableSymbol(
            $name,
            $type,
            BindingMutability::Mutable,
            $declaration->variableSpan,
            $binding,
        ));
    }

    private function resolveArrayLiteralType(Expr\Array_ $array, Scope $scope): LocalType
    {
        if ($array->items === []) {
            return LocalType::createAtomic('array');
        }

        $values = [];
        $keys = [];
        $list = true;

        foreach ($array->items as $item) {
            if ($item->unpack) {
                return LocalType::createAtomic('array');
            }

            $values[] = $this->resolveExpressionType($item->value, $scope);
            if ($item->key === null) {
                $keys[] = LocalType::createAtomic('int');
                continue;
            }

            $list = false;
            $keys[] = $this->resolveExpressionType($item->key, $scope);
        }

        $value = $this->mergeTypes($values);
        $key = $this->mergeTypes($keys);

        if ($value->unknown || $key->unknown) {
            return LocalType::createAtomic('array');
        }

        return LocalType::createFromSemanticType(new TypedArrayType(
            $key->semanticType,
            $value->semanticType,
            $list,
        ));
    }

    private function resolveAssignmentTargetType(Expr $target, Scope $scope): ?LocalType
    {
        if ($target instanceof Expr\ArrayDimFetch) {
            $type = $this->resolveExpressionType($target->var, $scope)->semanticType;
            $value = $this->resolveArrayValueType($type);

            return $value === null ? null : LocalType::createFromSemanticType($value);
        }

        if ($target instanceof Expr\PropertyFetch && $target->name instanceof Node\Identifier) {
            $type = $this->members->resolvePropertyType(
                $this->resolveExpressionType($target->var, $scope)->semanticType,
                $target->name->toString(),
            );

            return $type->isUnknown ? null : LocalType::createFromSemanticType($type);
        }

        if ($target instanceof Expr\StaticPropertyFetch && $target->class instanceof Node\Name && $target->name instanceof Node\VarLikeIdentifier) {
            $owner = $this->sourceTypes->resolveNode(
                $target->class,
                $this->context->parsedFile,
                $this->context->resolvedNames,
                $this->context->genericDeclarations,
            );
            $type = $this->members->resolvePropertyType($owner, $target->name->toString());

            return $type->isUnknown ? null : LocalType::createFromSemanticType($type);
        }

        return null;
    }

    private function resolveArrayValueType(Type $type): ?Type
    {
        if ($type instanceof TypedArrayType) {
            return $type->valueType;
        }

        if (!$type instanceof UnionType) {
            return null;
        }

        $values = [];
        foreach ($type->members as $member) {
            $value = $this->resolveArrayValueType($member);
            if ($value !== null) {
                $values[$value->canonical] = $value;
            }
        }

        if ($values === []) {
            return null;
        }

        return count($values) === 1 ? reset($values) : new UnionType(array_values($values));
    }

    private function resolveOwningClass(Stmt\ClassMethod $method): ?\Atatusoft\Ppphp\Semantic\Symbol\ClassSymbol
    {
        $span = $this->span($method);

        foreach ($this->context->symbols->classes as $class) {
            if (
                $class->sourceFile === $this->context->parsedFile->sourceFile
                && $class->declarationSpan->start->offset <= $span->start->offset
                && $class->declarationSpan->end->offset >= $span->end->offset
            ) {
                return $class;
            }
        }

        return null;
    }

    /**
     * @param list<WhenFlow> $flows
     * @return WhenFlow
     */
    private function mergeFlows(array $flows): array
    {
        $combined = ['canComplete' => false, 'types' => [], 'spans' => [], 'transfers' => []];
        foreach ($flows as $flow) {
            $combined['canComplete'] = $combined['canComplete'] || $flow['canComplete'];
            array_push($combined['types'], ...$flow['types']);
            array_push($combined['spans'], ...$flow['spans']);
            array_push($combined['transfers'], ...($flow['transfers'] ?? []));
        }

        return $combined;
    }

    /**
     * @param WhenFlow $flow
     * @return WhenFlow
     */
    private function consumeTransfers(array $flow, Stmt $target): array
    {
        $remaining = [];
        foreach ($flow['transfers'] ?? [] as $transferTarget) {
            if ($transferTarget === $target) {
                $flow['canComplete'] = true;
            } else {
                $remaining[] = $transferTarget;
            }
        }
        $flow['transfers'] = $remaining;

        return $flow;
    }

    /** @param list<LocalType> $types */
    private function mergeTypes(array $types): LocalType
    {
        if ($types === []) {
            return LocalType::createAtomic('never');
        }
        foreach ($types as $type) {
            if ($type->unknown) {
                return LocalType::createUnknown();
            }
        }
        $members = [];
        foreach ($types as $type) {
            if (!$type->includes('never')) {
                $members[$type->canonical] = $type->semanticType;
            }
        }

        return $members === []
            ? LocalType::createAtomic('never')
            : LocalType::createFromSemanticType(
                count($members) === 1
                    ? array_values($members)[0]
                    : new UnionType(array_values($members)),
            );
    }

    private function resolveTerminatingExpression(Expr $expression, Scope $scope): bool
    {
        if (!$expression instanceof Expr\CallLike) {
            return false;
        }

        return $this->resolveExpressionType($expression, $scope)->includes('never');
    }

    private function createTemporaryName(WhenExpression $when): string
    {
        $base = '__ppphp_when_' . $when->span->start->offset;
        $name = $base;
        $suffix = 0;
        while (preg_match('/\\$' . preg_quote($name, '/') . '\\b/', $this->context->parsedFile->sourceFile->contents) === 1) {
            $name = $base . '_' . ++$suffix;
        }

        return '$' . $name;
    }

    private function resolveSourceLocalType(\Atatusoft\Ppphp\Frontend\Ast\SourceType $type): LocalType
    {
        return LocalType::createFromSemanticType($this->sourceTypes->resolveSourceType(
            $type,
            $this->context->parsedFile,
            $this->context->genericDeclarations,
        ));
    }

    private function span(Node $node): Span
    {
        $start = $node->getAttribute('ppphpOriginalStart');
        $end = $node->getAttribute('ppphpOriginalEnd');
        if (is_int($start) && is_int($end)) {
            return $this->context->parsedFile->sourceFile->createSpan($start, $end);
        }

        $start = max(0, $node->getStartFilePos());
        $end = max($start, $node->getEndFilePos() + 1);

        return $this->context->parsedFile->sourceFile->createSpan(
            min($start, $this->context->parsedFile->sourceFile->length),
            min($end, $this->context->parsedFile->sourceFile->length),
        );
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
}
