<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Transpilation\Pass;

use Atatusoft\Ppphp\Frontend\Ast\WhenElseBranch;
use Atatusoft\Ppphp\Interop\PhpDoc\PhpDocReader;
use Atatusoft\Ppphp\Semantic\When\WhenExpressionAnalysis;
use Atatusoft\Ppphp\Source\Span;
use Atatusoft\Ppphp\Transpilation\Pass\Interfaces\TranspilationPass;
use Atatusoft\Ppphp\Transpilation\SourceEditMapping;
use Atatusoft\Ppphp\Transpilation\TranspilationContext;
use Atatusoft\Ppphp\Transpilation\LocalBindingTypeRenderer;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;

final class LowerWhenExpressionsPass implements TranspilationPass
{
    private TranspilationContext $context;

    private int $prerequisiteSequence = 0;

    /** @var array<string, true> */
    private array $reservedNames = [];

    /** @var array<string, true> */
    private array $generatedNames = [];

    /** @var \WeakMap<Doc, Span> */
    private \WeakMap $bindingDocumentOrigins;

    public function __construct(private readonly Standard $printer = new Standard()) {}

    public function execute(TranspilationContext $context): void
    {
        $this->context = $context;
        $this->prerequisiteSequence = 0;
        $this->reservedNames = [];
        $this->generatedNames = [];
        $this->bindingDocumentOrigins = new \WeakMap();

        foreach (token_get_all($context->parsedFile->sourceFile->contents) as $token) {
            if (is_array($token) && $token[0] === T_VARIABLE) {
                $this->reservedNames[ltrim($token[1], '$')] = true;
            }
        }

        foreach ($context->semanticModel->whenExpressions->expressions as $analysis) {
            $name = ltrim($analysis->temporaryName, '$');
            $this->reservedNames[$name] = true;
            $this->generatedNames[$name] = true;
        }
        $statements = [];

        foreach ($context->semanticModel->whenExpressions->expressions as $analysis) {
            if ($analysis->syntax->parentId !== null) {
                continue;
            }

            $span = $this->span($analysis->statement);
            $statements[$span->start->offset . ':' . $span->end->offset] = [$span, $analysis->statement];
        }

        foreach ($statements as [$span, $statement]) {
            if (!$statement instanceof Stmt) {
                throw new \LogicException("A when lowering site must belong to a statement.");
            }
            $lowered = $this->lowerOrdinaryStatement($this->copyStatement($statement));
            if ((new NodeFinder())->findFirst($lowered, static fn (Node $node): bool =>
                $node instanceof Expr && is_string($node->getAttribute('ppphpWhenExpressionId'))) !== null) {
                throw new \LogicException('A when placeholder survived statement lowering.');
            }
            $php = $this->printer->prettyPrint($lowered);
            $replacement = $this->formatForSource($php, $span->start->offset);
            $context->replace($span, $replacement, $this->buildSourceMappings($span, $replacement, $lowered));
        }
    }

    /**
     * @param list<Stmt> $statements
     * @return list<SourceEditMapping>
     */
    private function buildSourceMappings(Span $owner, string $replacement, array $statements): array
    {
        /** @var list<array{string, Span}> $candidates */
        $candidates = [];
        /** @var list<array{int, int}> $occupied */
        $occupied = [];
        /** @var list<SourceEditMapping> $mappings */
        $mappings = $this->mapBindingDocuments($statements, $replacement);
        foreach ($mappings as $mapping) {
            $occupied[] = [$mapping->replacementStart, $mapping->replacementEnd];
        }

        foreach ($this->context->semanticModel->whenExpressions->expressions as $analysis) {
            if (!$this->contains($owner, $analysis->syntax->span)) {
                continue;
            }
            foreach ($analysis->branches as $branch) {
                if ($branch->condition !== null) {
                    $candidates[] = [$this->printer->prettyPrintExpr($branch->condition), $this->span($branch->condition)];
                }

                $results = [];
                foreach ($branch->statements as $statement) {
                    $this->collectResultExpressions($statement, $results);
                }
                foreach ($branch->resultSpans as $index => $span) {
                    $expression = $results[$index] ?? null;
                    if ($expression !== null && $this->context->semanticModel->whenExpressions->findPlaceholder($expression) === null) {
                        $candidates[] = [$this->printer->prettyPrintExpr($expression), $span];
                    }
                }
                foreach ($branch->statements as $statement) {
                    $this->collectMappableStatements($statement, $candidates);
                }
            }
        }

        usort($candidates, static fn (array $left, array $right): int =>
            $left[1]->start->offset <=> $right[1]->start->offset);
        foreach ($candidates as [$text, $origin]) {
            $offset = $this->findUnmappedText($replacement, $text, $occupied);
            if ($offset === null) {
                continue;
            }
            $start = $this->resolveGeneratedLineStart($replacement, $offset);
            $prefix = substr($replacement, $start, $offset - $start);
            foreach ($this->generatedNames as $name => $_) {
                if (str_starts_with(ltrim($prefix), '$' . $name . ' = ')) {
                    // Line-only findings belong to the source result, while
                    // the generated variable itself belongs to its when.
                    $indentEnd = $start + strlen($prefix) - strlen(ltrim($prefix));
                    if ($indentEnd > $start && !$this->overlaps($start, $indentEnd, $occupied)) {
                        $occupied[] = [$start, $indentEnd];
                        $mappings[] = new SourceEditMapping($start, $indentEnd, $origin);
                    }
                    $start = $offset;
                    break;
                }
            }
            $end = $offset + strlen($text);
            if ($this->overlaps($start, $end, $occupied)) {
                continue;
            }
            $occupied[] = [$start, $end];
            $mappings[] = new SourceEditMapping($start, $end, $origin);
        }

        foreach ($this->context->semanticModel->whenExpressions->expressions as $analysis) {
            if (!$this->contains($owner, $analysis->syntax->span)) {
                continue;
            }
            $needle = '$' . ltrim($analysis->temporaryName, '$');
            $offset = 0;
            while (($offset = strpos($replacement, $needle, $offset)) !== false) {
                $start = $offset;
                $end = $offset + strlen($needle);
                if (!$this->overlaps($start, $end, $occupied)) {
                    $occupied[] = [$start, $end];
                    $mappings[] = new SourceEditMapping($start, $end, $analysis->syntax->span);
                }
                $offset = $end;
            }
        }

        usort($mappings, static fn (SourceEditMapping $left, SourceEditMapping $right): int =>
            $left->replacementStart <=> $right->replacementStart);

        return $mappings;
    }

    /**
     * @param list<Stmt> $statements
     * @return list<SourceEditMapping>
     */
    private function mapBindingDocuments(array $statements, string $replacement): array
    {
        $documents = [];
        foreach ($statements as $statement) {
            $this->collectDocuments($statement, $documents);
        }
        $tokens = array_values(array_filter(\PhpToken::tokenize('<?php ' . $replacement),
            static fn (\PhpToken $token): bool => $token->id === T_DOC_COMMENT));
        if (count($tokens) !== count($documents)) {
            return [];
        }

        $mappings = [];
        foreach ($documents as $index => $document) {
            $token = $tokens[$index];
            // Match the complete emitted comment sequence, not a tag-text search:
            // an authored assertion can have the same text as a generated tag.
            if (preg_replace('/\s+/', '', $token->text) !== preg_replace('/\s+/', '', $document->getText())) {
                return [];
            }
            $origin = $this->bindingDocumentOrigins[$document] ?? null;
            if ($origin !== null) {
                $start = $token->pos - strlen('<?php ');
                $mappings[] = new SourceEditMapping($start, $start + strlen($token->text), $origin);
            }
        }

        return $mappings;
    }

    /** @param list<Doc> $documents */
    private function collectDocuments(Node $node, array &$documents): void
    {
        foreach ($node->getComments() as $comment) {
            if ($comment instanceof Doc) {
                $documents[] = $comment;
            }
        }
        foreach ($node->getSubNodeNames() as $name) {
            foreach (is_array($node->$name) ? $node->$name : [$node->$name] as $child) {
                if ($child instanceof Node) {
                    $this->collectDocuments($child, $documents);
                }
            }
        }
    }

    /** @param list<Expr> $expressions */
    private function collectResultExpressions(Node $node, array &$expressions): void
    {
        if ($node instanceof Stmt\Return_) {
            if ($node->expr !== null) {
                $expressions[] = $node->expr;
            }
            return;
        }
        if ($node instanceof Stmt\Function_ || $node instanceof Stmt\ClassLike || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            return;
        }
        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->{$name};
            if ($value instanceof Node) {
                $this->collectResultExpressions($value, $expressions);
            } elseif (is_array($value)) {
                foreach ($value as $child) {
                    if ($child instanceof Node) {
                        $this->collectResultExpressions($child, $expressions);
                    }
                }
            }
        }
    }

    /** @param list<array{string, Span}> $candidates */
    private function collectMappableStatements(Stmt $statement, array &$candidates): void
    {
        if ($statement instanceof Stmt\Return_ || $statement instanceof Stmt\Function_ || $statement instanceof Stmt\ClassLike) {
            return;
        }
        if ($statement instanceof Stmt\Expression) {
            $candidates[] = [$this->printer->prettyPrint([$statement]), $this->span($statement)];
            return;
        }
        foreach ($statement->getSubNodeNames() as $name) {
            $value = $statement->{$name};
            if ($value instanceof Stmt) {
                $this->collectMappableStatements($value, $candidates);
            } elseif (is_array($value)) {
                foreach ($value as $child) {
                    if ($child instanceof Stmt) {
                        $this->collectMappableStatements($child, $candidates);
                    }
                }
            }
        }
    }

    private function resolveGeneratedLineStart(string $replacement, int $offset): int
    {
        $lineStart = strrpos(substr($replacement, 0, $offset), "\n");

        return $lineStart === false ? 0 : $lineStart + 1;
    }

    /** @param list<array{int, int}> $occupied */
    private function findUnmappedText(string $haystack, string $needle, array $occupied): ?int
    {
        if ($needle === '') {
            return null;
        }
        $offset = 0;
        while (($offset = strpos($haystack, $needle, $offset)) !== false) {
            $end = $offset + strlen($needle);
            if (!$this->overlaps($offset, $end, $occupied)) {
                return $offset;
            }
            $offset++;
        }

        return null;
    }

    /** @param list<array{int, int}> $occupied */
    private function overlaps(int $start, int $end, array $occupied): bool
    {
        foreach ($occupied as [$occupiedStart, $occupiedEnd]) {
            if ($start < $occupiedEnd && $end > $occupiedStart) {
                return true;
            }
        }

        return false;
    }

    private function contains(Span $owner, Span $candidate): bool
    {
        return $candidate->start->offset >= $owner->start->offset
            && $candidate->end->offset <= $owner->end->offset;
    }

    /** @return list<Stmt> */
    private function lowerOrdinaryStatement(Stmt $statement): array
    {
        $this->decorateNestedExtensions($statement);

        if ($statement instanceof Stmt\Return_ && $statement->expr !== null) {
            [$prelude, $expression] = $this->lowerExpression($statement->expr);
            $statement->expr = $expression;
            $this->decorateTemporaryTypes($statement, $prelude);

            return [...$prelude, $statement];
        }

        if ($statement instanceof Stmt\Expression) {
            [$prelude, $expression] = $this->lowerExpression($statement->expr);
            $statement->expr = $expression;
            $this->decorateTypedLocal($statement);
            $this->decorateTemporaryTypes($statement, $prelude);

            return [...$prelude, $statement, ...$this->buildCleanup($prelude)];
        }

        return $this->lowerNestedStatement($statement, false, 0);
    }

    /** @return array{list<Stmt>, Expr} */
    private function lowerExpression(Expr $expression): array
    {
        $analysis = $this->context->semanticModel->whenExpressions->findPlaceholder($expression);
        if ($analysis !== null) {
            return [[$this->buildWhenStatement($analysis)], new Expr\Variable(ltrim($analysis->temporaryName, '$'))];
        }

        if ($expression instanceof Expr\FuncCall) {
            $callee = $expression->name instanceof Expr ? $expression->name : null;
            [$prelude, $arguments, $loweredCallee] = $this->lowerArguments($expression->args, $callee);
            if ($loweredCallee instanceof Expr) {
                $expression->name = $loweredCallee;
            }
            $expression->args = $arguments;

            return [$prelude, $expression];
        }

        if ($expression instanceof Expr\MethodCall || $expression instanceof Expr\NullsafeMethodCall) {
            [$receiverPrelude, $receiver] = $this->lowerExpression($expression->var);
            $expression->var = $receiver;
            [$argumentPrelude, $arguments, $receiver] = $this->lowerArguments($expression->args, $expression->var);
            if (!$receiver instanceof Expr) {
                throw new \LogicException('A method-call receiver cannot disappear during when lowering.');
            }
            $expression->var = $receiver;
            $expression->args = $arguments;

            return [[...$receiverPrelude, ...$argumentPrelude], $expression];
        }

        if ($expression instanceof Expr\StaticCall || $expression instanceof Expr\New_) {
            $dynamic = $expression->class instanceof Expr ? $expression->class : null;
            [$prelude, $arguments, $loweredDynamic] = $this->lowerArguments($expression->args, $dynamic);
            if ($loweredDynamic instanceof Expr) {
                $expression->class = $loweredDynamic;
            }
            $expression->args = $arguments;

            return [$prelude, $expression];
        }

        if ($expression instanceof Expr\Array_) {
            return $this->lowerArray($expression);
        }

        if (
            $expression instanceof Expr\BinaryOp\BooleanAnd
            || $expression instanceof Expr\BinaryOp\LogicalAnd
            || $expression instanceof Expr\BinaryOp\BooleanOr
            || $expression instanceof Expr\BinaryOp\LogicalOr
        ) {
            return $this->lowerShortCircuitExpression($expression);
        }

        if ($expression instanceof Expr\BinaryOp\Coalesce) {
            return $this->lowerCoalesceExpression($expression);
        }

        if ($expression instanceof Expr\Ternary) {
            return $this->lowerTernaryExpression($expression);
        }

        if ($expression instanceof Expr\BinaryOp) {
            return $this->lowerBinaryExpression($expression);
        }

        if ($expression instanceof Expr\Assign) {
            [$prelude, $value] = $this->lowerExpression($expression->expr);
            if ($prelude !== []) {
                [$targetPrelude, $target] = $this->hoistAssignmentTarget($expression->var);
                $expression->var = $target;
                $prelude = [...$targetPrelude, ...$prelude];
            }
            $expression->expr = $value;

            return [$prelude, $expression];
        }

        if ($expression instanceof Expr\Closure) {
            $statements = [];
            foreach ($expression->stmts as $statement) {
                array_push($statements, ...$this->lowerOrdinaryStatement($statement));
            }
            $expression->stmts = $statements;

            return [[], $expression];
        }

        $prelude = [];
        foreach ($expression->getSubNodeNames() as $name) {
            $value = $expression->{$name};
            if ($value instanceof Expr) {
                [$nestedPrelude, $nested] = $this->lowerExpression($value);
                array_push($prelude, ...$nestedPrelude);
                $expression->{$name} = $nested;
            } elseif (is_array($value)) {
                foreach ($value as $index => $child) {
                    if (!$child instanceof Expr) {
                        continue;
                    }
                    [$nestedPrelude, $nested] = $this->lowerExpression($child);
                    array_push($prelude, ...$nestedPrelude);
                    $value[$index] = $nested;
                }
                $expression->{$name} = $value;
            }
        }

        return [$prelude, $expression];
    }

    /** @return array{list<Stmt>, Expr\BinaryOp} */
    private function lowerBinaryExpression(Expr\BinaryOp $expression): array
    {
        [$leftPrelude, $expression->left] = $this->lowerExpression($expression->left);
        [$rightPrelude, $expression->right] = $this->lowerExpression($expression->right);

        if ($rightPrelude === []) {
            return [$leftPrelude, $expression];
        }

        [$leftAssignment, $expression->left] = $this->hoist($expression->left);
        $this->decorateTemporaryTypes($leftAssignment, $leftPrelude);

        return [[...$leftPrelude, $leftAssignment, ...$rightPrelude], $expression];
    }

    /** @return array{list<Stmt>, Expr} */
    private function lowerShortCircuitExpression(Expr\BinaryOp $expression): array
    {
        [$leftPrelude, $expression->left] = $this->lowerExpression($expression->left);
        [$rightPrelude, $expression->right] = $this->lowerExpression($expression->right);

        if ($rightPrelude === []) {
            return [$leftPrelude, $expression];
        }

        $and = $expression instanceof Expr\BinaryOp\BooleanAnd
            || $expression instanceof Expr\BinaryOp\LogicalAnd;
        $name = $this->allocateName('__ppphp_when_lazy');
        $result = new Expr\Variable($name);
        $assignment = new Stmt\Expression(new Expr\Assign(
            clone $result,
            new Expr\Cast\Bool_($expression->right),
        ));
        $this->decorateTemporaryTypes($assignment, $rightPrelude);
        $condition = $and
            ? $expression->left
            : new Expr\BooleanNot($expression->left);

        return [[
            ...$leftPrelude,
            new Stmt\Expression(new Expr\Assign(
                clone $result,
                new Expr\ConstFetch(new Name($and ? 'false' : 'true')),
            )),
            new Stmt\If_($condition, ['stmts' => [...$rightPrelude, $assignment]]),
        ], $result];
    }

    /** @return array{list<Stmt>, Expr} */
    private function lowerCoalesceExpression(Expr\BinaryOp\Coalesce $expression): array
    {
        [$leftPrelude, $expression->left] = $this->lowerExpression($expression->left);
        [$rightPrelude, $expression->right] = $this->lowerExpression($expression->right);

        if ($rightPrelude === []) {
            return [$leftPrelude, $expression];
        }

        [$leftAssignment, $left] = $this->hoist(new Expr\BinaryOp\Coalesce(
            $expression->left,
            new Expr\ConstFetch(new Name('null')),
        ));
        $this->decorateTemporaryTypes($leftAssignment, $leftPrelude);
        $result = new Expr\Variable($this->allocateName('__ppphp_when_lazy'));
        $rightAssignment = new Stmt\Expression(new Expr\Assign(clone $result, $expression->right));
        $this->decorateTemporaryTypes($rightAssignment, $rightPrelude);

        return [[
            ...$leftPrelude,
            $leftAssignment,
            new Stmt\If_(new Expr\BinaryOp\NotIdentical(
                clone $left,
                new Expr\ConstFetch(new Name('null')),
            ), [
                'stmts' => [new Stmt\Expression(new Expr\Assign(clone $result, $left))],
                'else' => new Stmt\Else_([...$rightPrelude, $rightAssignment]),
            ]),
        ], $result];
    }

    /** @return array{list<Stmt>, Expr} */
    private function lowerTernaryExpression(Expr\Ternary $expression): array
    {
        [$conditionPrelude, $condition] = $this->lowerExpression($expression->cond);
        $expression->cond = $condition;
        $ifPrelude = [];
        if ($expression->if !== null) {
            [$ifPrelude, $expression->if] = $this->lowerExpression($expression->if);
        }
        [$elsePrelude, $expression->else] = $this->lowerExpression($expression->else);

        if ($ifPrelude === [] && $elsePrelude === []) {
            return [$conditionPrelude, $expression];
        }

        $ifValue = $expression->if;
        if ($ifValue === null) {
            [$conditionAssignment, $condition] = $this->hoist($condition);
            $conditionPrelude[] = $conditionAssignment;
            $ifValue = clone $condition;
        }

        $result = new Expr\Variable($this->allocateName('__ppphp_when_lazy'));
        $ifAssignment = new Stmt\Expression(new Expr\Assign(clone $result, $ifValue));
        $elseAssignment = new Stmt\Expression(new Expr\Assign(clone $result, $expression->else));
        $this->decorateTemporaryTypes($ifAssignment, $ifPrelude);
        $this->decorateTemporaryTypes($elseAssignment, $elsePrelude);

        return [[
            ...$conditionPrelude,
            new Stmt\If_($condition, [
                'stmts' => [...$ifPrelude, $ifAssignment],
                'else' => new Stmt\Else_([...$elsePrelude, $elseAssignment]),
            ]),
        ], $result];
    }

    /**
     * @param array<Arg|Node\VariadicPlaceholder> $arguments
     * @return array{list<Stmt>, array<Arg|Node\VariadicPlaceholder>, Expr|null}
     */
    private function lowerArguments(array $arguments, ?Expr $callee = null): array
    {
        $prelude = [];
        $pending = [];
        $calleePending = $callee !== null;

        foreach ($arguments as $position => $argument) {
            if (!$argument instanceof Arg) {
                continue;
            }
            [$nestedPrelude, $value] = $this->lowerExpression($argument->value);
            if ($nestedPrelude !== []) {
                if ($calleePending) {
                    [$assignment, $temporary] = $this->hoist($callee);
                    $prelude[] = $assignment;
                    $callee = $temporary;
                    $calleePending = false;
                }
                foreach ($pending as $pendingPosition) {
                    $pendingArgument = $arguments[$pendingPosition];
                    if (!$pendingArgument instanceof Arg) {
                        continue;
                    }
                    [$assignment, $temporary] = $this->hoist($pendingArgument->value);
                    $prelude[] = $assignment;
                    $pendingArgument->value = $temporary;
                }
                $pending = [];
                array_push($prelude, ...$nestedPrelude);
            }
            $argument->value = $value;
            $pending[] = $position;
        }

        return [$prelude, $arguments, $callee];
    }

    /** @return array{list<Stmt>, Expr\Array_} */
    private function lowerArray(Expr\Array_ $array): array
    {
        $prelude = [];
        $pending = [];

        foreach ($array->items as $position => $item) {
            $keyPrelude = [];
            if ($item->key !== null) {
                [$keyPrelude, $item->key] = $this->lowerExpression($item->key);
            }
            [$valuePrelude, $item->value] = $this->lowerExpression($item->value);
            $nestedPrelude = [...$keyPrelude, ...$valuePrelude];
            if ($nestedPrelude !== []) {
                foreach ($pending as $pendingPosition) {
                    $pendingItem = $array->items[$pendingPosition];
                    if ($pendingItem->key !== null) {
                        [$assignment, $pendingItem->key] = $this->hoist($pendingItem->key);
                        $prelude[] = $assignment;
                    }
                    [$assignment, $pendingItem->value] = $this->hoist($pendingItem->value);
                    $prelude[] = $assignment;
                }
                $pending = [];
                if ($item->key !== null && $valuePrelude !== []) {
                    [$assignment, $item->key] = $this->hoist($item->key);
                    $prelude[] = $assignment;
                }
                array_push($prelude, ...$nestedPrelude);
            }
            $pending[] = $position;
        }

        return [$prelude, $array];
    }

    private function buildWhenStatement(WhenExpressionAnalysis $analysis): Stmt\Do_
    {
        $if = null;
        $elseifs = [];
        $else = null;

        foreach ($analysis->branches as $branch) {
            $branchStatements = [];
            foreach ($branch->statements as $statement) {
                $branchStatements[] = $this->copyStatement($statement);
            }
            $statements = $this->lowerBranchStatements(
                $branchStatements,
                $analysis,
                1,
            );
            if ($branch->syntax instanceof WhenElseBranch) {
                $else = new Stmt\Else_($statements);
                continue;
            }
            if ($branch->condition === null) {
                continue;
            }
            $condition = $this->copyExpression($branch->condition);
            if ($if === null) {
                $if = new Stmt\If_($condition, ['stmts' => $statements]);
            } else {
                $elseifs[] = new Stmt\ElseIf_($condition, $statements);
            }
        }

        if (!$if instanceof Stmt\If_) {
            throw new \LogicException('A when expression requires at least one conditional branch.');
        }
        $if->elseifs = $elseifs;
        $if->else = $else;

        // Branches leave through an explicit result break or termination.
        // There is no condition exit that can fabricate an unassigned result.
        return new Stmt\Do_(new Expr\ConstFetch(new Name('true')), [$if]);
    }

    /**
     * @param list<Stmt> $statements
     * @return list<Stmt>
     */
    private function lowerBranchStatements(
        array $statements,
        WhenExpressionAnalysis $analysis,
        int $breakDepth,
        ?string $completionFlag = null,
    ): array
    {
        /** @var list<Stmt> $lowered */
        $lowered = [];
        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Return_) {
                if ($statement->expr === null) {
                    continue;
                }
                [$prelude, $value] = $this->lowerExpression($statement->expr);
                array_push($lowered, ...$prelude);
                $assignment = new Stmt\Expression(new Expr\Assign(
                    new Expr\Variable(ltrim($analysis->temporaryName, '$')),
                    $value,
                ));
                $this->decorateTemporaryTypes($assignment, $prelude);
                $lowered[] = $assignment;
                array_push($lowered, ...$this->buildCleanup($prelude));
                if ($completionFlag !== null) {
                    $lowered[] = new Stmt\Expression(new Expr\Assign(
                        new Expr\Variable($completionFlag),
                        new Expr\ConstFetch(new Name('true')),
                    ));
                }
                $lowered[] = new Stmt\Break_($breakDepth === 1 ? null : new Scalar\Int_($breakDepth));
                continue;
            }

            if ($statement instanceof Stmt\TryCatch) {
                array_push($lowered, ...$this->lowerTryCatch($statement, $analysis, $breakDepth, $completionFlag));
                continue;
            }

            if (
                $statement instanceof Stmt\If_
                || $statement instanceof Stmt\For_
                || $statement instanceof Stmt\Foreach_
                || $statement instanceof Stmt\While_
                || $statement instanceof Stmt\Do_
                || $statement instanceof Stmt\Switch_
            ) {
                $this->decorateNestedExtensions($statement);
                array_push($lowered, ...$this->lowerNestedStatement($statement, true, $breakDepth, $completionFlag));
            } else {
                array_push($lowered, ...$this->lowerOrdinaryStatement($statement));
            }
        }

        return $lowered;
    }

    /** @return list<Stmt> */
    private function lowerNestedStatement(
        Stmt $statement,
        bool $branchReturn,
        int $breakDepth,
        ?string $completionFlag = null,
    ): array
    {
        if ($statement instanceof Stmt\Function_) {
            $statement->stmts = $this->lowerOrdinaryStatements(array_values($statement->stmts));

            return [$statement];
        }

        if ($statement instanceof Stmt\ClassLike) {
            foreach ($statement->getMethods() as $method) {
                $this->decorateNestedExtensions($method);
                $method->stmts = $this->lowerOrdinaryStatements(array_values($method->stmts ?? []));
            }

            return [$statement];
        }

        if ($statement instanceof Stmt\If_) {
            return $this->lowerIfStatement($statement, $branchReturn, $breakDepth, $completionFlag);
        }

        if (
            $branchReturn
            && ($statement instanceof Stmt\For_ || $statement instanceof Stmt\Foreach_ || $statement instanceof Stmt\While_ || $statement instanceof Stmt\Do_ || $statement instanceof Stmt\Switch_)
        ) {
            $analysis = $this->resolveOwningAnalysis($statement);
            if ($statement instanceof Stmt\Switch_) {
                foreach ($statement->cases as $case) {
                    $case->stmts = $this->lowerBranchStatements(array_values($case->stmts), $analysis, $breakDepth + 1, $completionFlag);
                }
            } else {
                $statement->stmts = $this->lowerBranchStatements(array_values($statement->stmts), $analysis, $breakDepth + 1, $completionFlag);
            }

            return [$statement];
        }

        // A larger owning edit may contain other ordinary statement lists.
        // Lower those bodies too: their independent source edits would be
        // covered by the outer replacement (for example a loop inside an if).
        foreach ($statement->getSubNodeNames() as $name) {
            $value = $statement->{$name};
            if ($value instanceof Stmt) {
                $nested = $this->lowerNestedStatement($value, false, 0);
                if (count($nested) !== 1) {
                    throw new \LogicException('A nested statement container must retain one node.');
                }
                $statement->{$name} = $nested[0];
            } elseif (is_array($value)) {
                $children = array_filter($value, static fn (mixed $child): bool => $child instanceof Stmt);
                if ($children !== [] && count($children) === count($value)) {
                    $statement->{$name} = $this->lowerOrdinaryStatements(array_values($children));
                }
            }
        }

        return [$statement];
    }

    /** @return list<Stmt> */
    private function lowerIfStatement(
        Stmt\If_ $statement,
        bool $branchReturn,
        int $breakDepth,
        ?string $completionFlag,
    ): array {
        $analysis = $branchReturn ? $this->resolveOwningAnalysis($statement) : null;

        $statement->stmts = $this->lowerConditionalStatements(
            array_values($statement->stmts),
            $analysis,
            $breakDepth,
            $completionFlag,
        );
        $nextElse = $statement->else;
        if ($nextElse !== null) {
            $nextElse->stmts = $this->lowerConditionalStatements(
                array_values($nextElse->stmts),
                $analysis,
                $breakDepth,
                $completionFlag,
            );
        }

        foreach (array_reverse($statement->elseifs) as $elseif) {
            [$prelude, $condition] = $this->lowerExpression($elseif->cond);
            $nested = new Stmt\If_($condition, [
                'stmts' => $this->lowerConditionalStatements(
                    array_values($elseif->stmts),
                    $analysis,
                    $breakDepth,
                    $completionFlag,
                ),
                'else' => $nextElse,
            ], $elseif->getAttributes());
            $this->decorateTemporaryTypes($nested, $prelude);
            $nextElse = new Stmt\Else_([
                ...$prelude,
                $nested,
                ...$this->buildCleanup($prelude),
            ], $elseif->getAttributes());
        }

        [$prelude, $statement->cond] = $this->lowerExpression($statement->cond);
        $statement->elseifs = [];
        $statement->else = $nextElse;
        $this->decorateTemporaryTypes($statement, $prelude);

        return [...$prelude, $statement, ...$this->buildCleanup($prelude)];
    }

    /**
     * @param list<Stmt> $statements
     * @return list<Stmt>
     */
    private function lowerConditionalStatements(
        array $statements,
        ?WhenExpressionAnalysis $analysis,
        int $breakDepth,
        ?string $completionFlag,
    ): array {
        return $analysis === null
            ? $this->lowerOrdinaryStatements($statements)
            : $this->lowerBranchStatements($statements, $analysis, $breakDepth, $completionFlag);
    }

    /** @return list<Stmt> */
    private function lowerTryCatch(
        Stmt\TryCatch $statement,
        WhenExpressionAnalysis $analysis,
        int $breakDepth,
        ?string $completionFlag = null,
    ): array {
        if ($statement->finally === null) {
            $statement->stmts = $this->lowerBranchStatements(array_values($statement->stmts), $analysis, $breakDepth, $completionFlag);
            foreach ($statement->catches as $catch) {
                $catch->stmts = $this->lowerBranchStatements(array_values($catch->stmts), $analysis, $breakDepth, $completionFlag);
            }
            return [$statement];
        }

        $sourceFinally = $statement->finally;
        $statement->finally = null;
        $flag = $this->allocateName('__ppphp_when_finally');
        $statement->stmts = $this->lowerBranchStatements(array_values($statement->stmts), $analysis, 1, $flag);
        foreach ($statement->catches as $catch) {
            $catch->stmts = $this->lowerBranchStatements(array_values($catch->stmts), $analysis, 1, $flag);
        }
        $protectedStatements = $statement->catches === [] ? $statement->stmts : [$statement];
        $pending = $this->allocateName('__ppphp_when_pending_error');
        $caught = $this->allocateName('__ppphp_when_caught_error');
        $finally = $this->lowerBranchStatements(
            array_values($sourceFinally->stmts),
            $analysis,
            1,
            $flag,
        );
        $wrapper = new Stmt\TryCatch(
            $protectedStatements,
            [new Stmt\Catch_(
                [new Name\FullyQualified('Throwable')],
                new Expr\Variable($caught),
                [new Stmt\Expression(new Expr\Assign(
                    new Expr\Variable($pending),
                    new Expr\Variable($caught),
                ))],
            )],
            new Stmt\Finally_([new Stmt\Do_(
                new Expr\ConstFetch(new Name('false')),
                $finally,
            )]),
        );

        return [
            $this->declareTemporary($pending, '\\Throwable|null', new Expr\ConstFetch(new Name('null')), $analysis->syntax->span),
            $this->declareTemporary($flag, 'bool', new Expr\ConstFetch(new Name('false')), $analysis->syntax->span),
            new Stmt\Do_(new Expr\ConstFetch(new Name('false')), [$wrapper]),
            new Stmt\If_(new Expr\Variable($flag), [
                'stmts' => [
                    ...($completionFlag === null ? [] : [new Stmt\Expression(new Expr\Assign(
                        new Expr\Variable($completionFlag), new Expr\ConstFetch(new Name('true')),
                    ))]),
                    new Stmt\Break_($breakDepth === 1 ? null : new Scalar\Int_($breakDepth)),
                ],
            ]),
            new Stmt\If_(new Expr\BinaryOp\NotIdentical(
                new Expr\Variable($pending),
                new Expr\ConstFetch(new Name('null')),
            ), [
                'stmts' => [new Stmt\Expression(new Expr\Throw_(new Expr\Variable($pending)))],
            ]),
        ];
    }

    private function declareTemporary(string $name, string $type, Expr $value, Span $owner): Stmt\Expression
    {
        $statement = new Stmt\Expression(new Expr\Assign(new Expr\Variable($name), $value));
        $document = new Doc(sprintf('/** @var %s $%s */', $type, $name));
        $statement->setDocComment($document);
        $this->bindingDocumentOrigins[$document] = $owner;

        return $statement;
    }

    /**
     * @param list<Stmt> $statements
     * @return list<Stmt>
     */
    private function lowerOrdinaryStatements(array $statements): array
    {
        $lowered = [];
        foreach ($statements as $statement) {
            array_push($lowered, ...$this->lowerOrdinaryStatement($statement));
        }

        return $lowered;
    }

    private function resolveOwningAnalysis(Node $node): WhenExpressionAnalysis
    {
        foreach ($this->context->semanticModel->whenExpressions->expressions as $analysis) {
            foreach ($analysis->branches as $branch) {
                foreach ($branch->statements as $statement) {
                    $span = $this->span($statement);
                    $target = $this->span($node);
                    if ($target->start->offset >= $span->start->offset && $target->end->offset <= $span->end->offset) {
                        return $analysis;
                    }
                }
            }
        }

        throw new \LogicException('A generated branch statement has no owning when expression.');
    }

    /** @return array{list<Stmt>, Expr} */
    private function hoistAssignmentTarget(Expr $target): array
    {
        if ($target instanceof Expr\PropertyFetch || $target instanceof Expr\NullsafePropertyFetch) {
            [$assignment, $receiver] = $this->hoist($target->var);
            $target->var = $receiver;

            return [[$assignment], $target];
        }

        if ($target instanceof Expr\ArrayDimFetch) {
            $prelude = [];
            if (!$target->var instanceof Expr\Variable) {
                [$assignment, $target->var] = $this->hoist($target->var);
                $prelude[] = $assignment;
            }
            if ($target->dim !== null) {
                [$assignment, $target->dim] = $this->hoist($target->dim);
                $prelude[] = $assignment;
            }

            return [$prelude, $target];
        }

        return [[], $target];
    }

    /** @return array{Stmt\Expression, Expr\Variable} */
    private function hoist(Expr $expression): array
    {
        $name = $this->allocateName('__ppphp_when_prerequisite');
        $variable = new Expr\Variable($name);

        return [new Stmt\Expression(new Expr\Assign(clone $variable, $expression)), $variable];
    }

    private function decorateTypedLocal(Stmt\Expression $statement): void
    {
        if (!$statement->expr instanceof Expr\Assign || !$statement->expr->var instanceof Expr\Variable) {
            return;
        }
        $offset = $this->span($statement->expr->var)->start->offset;
        foreach ($this->context->parsedFile->extensionSyntax->typedLocals as $declaration) {
            if ($declaration->variableSpan->start->offset !== $offset) {
                continue;
            }
            $binding = $this->context->semanticModel->bindings->find($declaration->id);
            if ($binding !== null) {
                $document = new Doc(sprintf('/** @var %s %s */', (new LocalBindingTypeRenderer())->render($binding, $this->context), $binding->name));
                $statement->setDocComment($document);
                $this->bindingDocumentOrigins[$document] = $declaration->span;
            }
        }
    }

    /** @param list<Stmt> $prelude */
    private function decorateTemporaryTypes(Stmt $consumer, array $prelude): void
    {
        $names = [];
        foreach ($prelude as $statement) {
            $this->collectLiveGeneratedNames($statement, $names);
        }
        foreach ($this->context->semanticModel->whenExpressions->expressions as $analysis) {
            $name = ltrim($analysis->temporaryName, '$');
            if (isset($names[$name])) {
                $previous = $consumer->getDocComment();
                $origin = $previous === null ? null : ($this->bindingDocumentOrigins[$previous] ?? null);
                $generated = $origin !== null || !(new PhpDocReader())->hasVariableAssertions($previous);
                $this->addPhpDocTag($consumer, sprintf(
                    '@var %s $%s',
                    $analysis->resultType->semanticType->renderPhpDoc(),
                    $name,
                ));
                $document = $consumer->getDocComment();
                if ($generated && !$analysis->resultType->unknown && $document !== null) {
                    // Preserve the original declaration's eligibility when
                    // several generated tags share a comment. Authored or
                    // unknown assertions make the whole comment ineligible.
                    $this->bindingDocumentOrigins[$document] = $origin ?? $analysis->syntax->span;
                }
            }
        }
    }

    private function decorateNestedExtensions(Stmt $statement): void
    {
        if ($statement instanceof Stmt\For_ || $statement instanceof Stmt\Foreach_) {
            $tags = [];
            $origin = null;
            $authoredDocument = $statement->getDocComment();
            $offset = $this->span($statement)->start->offset;
            foreach ([
                ...$this->context->parsedFile->extensionSyntax->typedForInitializers,
                ...$this->context->parsedFile->extensionSyntax->typedForeachBindings,
            ] as $declaration) {
                if ($declaration->loopKeywordSpan->start->offset !== $offset) {
                    continue;
                }
                $binding = $this->context->semanticModel->bindings->find($declaration->id);
                if ($binding !== null) {
                    $tags[] = sprintf('@var %s %s', (new LocalBindingTypeRenderer())->render($binding, $this->context), $binding->name);
                    $origin = $declaration->loopKeywordSpan;
                }
            }
            foreach ($tags as $tag) {
                $this->addPhpDocTag($statement, $tag);
            }
            $document = $statement->getDocComment();
            if (!(new PhpDocReader())->hasVariableAssertions($authoredDocument)
                && $document !== null && $origin !== null) {
                $this->bindingDocumentOrigins[$document] = $origin;
            }
        }

        if (!$statement instanceof Stmt\Function_ && !$statement instanceof Stmt\ClassMethod) {
            return;
        }

        $nameOffset = $this->span($statement->name)->start->offset;
        foreach ($this->context->parsedFile->extensionSyntax->genericDeclarations as $declaration) {
            if ($declaration->ownerNameSpan->start->offset !== $nameOffset) {
                continue;
            }
            foreach ($declaration->parameters as $parameter) {
                $tag = '@template ' . $parameter->nameSpan->text;
                if ($parameter->bound !== null) {
                    $tag .= ' of ' . $parameter->bound->text;
                }
                $this->addPhpDocTag($statement, $tag);
            }
        }

        foreach ($statement->params as $parameter) {
            if (!$parameter->var instanceof Expr\Variable || !is_string($parameter->var->name) || $parameter->type === null) {
                continue;
            }
            $documented = $this->resolveDocumentedType($parameter->type);
            if ($documented !== null) {
                $this->addPhpDocTag($statement, sprintf('@param %s $%s', $documented, $parameter->var->name));
                $this->eraseNestedType($parameter->type);
            }
        }

        $returnType = $statement->returnType;
        if ($returnType !== null && ($documented = $this->resolveDocumentedType($returnType)) !== null) {
            $this->addPhpDocTag($statement, '@return ' . $documented);
            $this->eraseNestedType($returnType);
        }

        foreach ($this->context->parsedFile->extensionSyntax->throwsClauses as $clause) {
            if ($clause->ownerNameSpan->start->offset !== $nameOffset) {
                continue;
            }
            $contract = $this->context->semanticModel->errorContracts->find(
                $this->context->parsedFile->sourceFile,
                $clause,
            );
            if ($contract === null) {
                throw new \LogicException('A nested throws clause has no validated semantic contract.');
            }
            $types = [];
            foreach ($contract->declaredErrors as $error) {
                $canonical = '\\' . ltrim($error->canonicalType, '\\');
                $types[strtolower($canonical)] = $canonical;
            }
            $this->addPhpDocTag($statement, '@throws ' . implode('|', array_values($types)));
        }
    }

    private function resolveDocumentedType(Node $type): ?string
    {
        $offset = $this->span($type)->start->offset;
        foreach ($this->context->parsedFile->extensionSyntax->genericTypes as $reference) {
            if ($reference->nameSpan->start->offset === $offset) {
                return $reference->span->text;
            }
        }

        if ($type instanceof Node\Name || $type instanceof Node\Identifier) {
            foreach ($this->context->parsedFile->extensionSyntax->genericDeclarations as $declaration) {
                if (
                    $declaration->ownerNameSpan->start->offset < $offset
                    && $declaration->span->sourceFile === $this->context->parsedFile->sourceFile
                ) {
                    foreach ($declaration->parameters as $parameter) {
                        if (strcasecmp($parameter->nameSpan->text, $type->toString()) === 0) {
                            return $parameter->nameSpan->text;
                        }
                    }
                }
            }
        }

        return null;
    }

    private function eraseNestedType(Node $type): void
    {
        if (!$type instanceof Node\Name && !$type instanceof Node\Identifier) {
            return;
        }

        if ($this->resolveDocumentedType($type) === $type->toString()) {
            $type->name = 'mixed';
        }
    }

    private function addPhpDocTag(Stmt $statement, string $tag): void
    {
        $document = $statement->getDocComment();
        if ($document === null) {
            $statement->setDocComment(new Doc('/**' . "\n" . ' * ' . $tag . "\n" . ' */'));

            return;
        }

        $text = $document->getText();
        $close = strrpos($text, '*/');
        $replacement = $close === false
            ? $text . "\n" . $tag
            : rtrim(substr($text, 0, $close)) . "\n * " . $tag . "\n */";
        $statement->setDocComment(new Doc($replacement));
    }

    /**
     * @param list<Stmt> $prelude
     * @return list<Stmt>
     */
    private function buildCleanup(array $prelude): array
    {
        $names = [];

        foreach ($prelude as $statement) {
            $this->collectLiveGeneratedNames($statement, $names);
        }

        return $names === []
            ? []
            : [new Stmt\Unset_(array_map(
                static fn (string $name): Expr\Variable => new Expr\Variable($name),
                array_keys($names),
            ))];
    }

    /** @param array<string, true> $names */
    private function collectLiveGeneratedNames(Node $node, array &$names): void
    {
        // A nested consumer owns its cleanup; its released temporaries must not
        // be documented or released again by an enclosing consuming statement.
        if ($node instanceof Stmt\Unset_) {
            foreach ($node->vars as $variable) {
                if ($variable instanceof Expr\Variable && is_string($variable->name)) {
                    unset($names[$variable->name]);
                }
            }

            return;
        }
        if ($node instanceof Stmt\Function_ || $node instanceof Stmt\ClassLike
            || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            return;
        }
        if ($node instanceof Expr\Variable && is_string($node->name) && isset($this->generatedNames[$node->name])) {
            $names[$node->name] = true;
        }

        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->{$name};
            if ($value instanceof Node) {
                $this->collectLiveGeneratedNames($value, $names);
            } elseif (is_array($value)) {
                foreach ($value as $child) {
                    if ($child instanceof Node) {
                        $this->collectLiveGeneratedNames($child, $names);
                    }
                }
            }
        }
    }

    private function allocateName(string $prefix): string
    {
        do {
            $name = $prefix . '_' . $this->prerequisiteSequence++;
        } while (isset($this->reservedNames[$name]));

        $this->reservedNames[$name] = true;
        $this->generatedNames[$name] = true;

        return $name;
    }

    private function copyNode(Node $node): Node
    {
        $copy = clone $node;
        foreach ($copy->getSubNodeNames() as $name) {
            $value = $copy->{$name};
            if ($value instanceof Node) {
                $copy->{$name} = $this->copyNode($value);
            } elseif (is_array($value)) {
                foreach ($value as $index => $child) {
                    if ($child instanceof Node) {
                        $value[$index] = $this->copyNode($child);
                    }
                }
                $copy->{$name} = $value;
            }
        }

        return $copy;
    }

    private function copyStatement(Stmt $statement): Stmt
    {
        $copy = $this->copyNode($statement);

        return $copy instanceof Stmt ? $copy : throw new \LogicException('A statement clone changed node kind.');
    }

    private function copyExpression(Expr $expression): Expr
    {
        $copy = $this->copyNode($expression);

        return $copy instanceof Expr ? $copy : throw new \LogicException('An expression clone changed node kind.');
    }

    private function span(Node $node): \Atatusoft\Ppphp\Source\Span
    {
        $start = $node->getAttribute('ppphpOriginalStart');
        $end = $node->getAttribute('ppphpOriginalEnd');
        if (!is_int($start) || !is_int($end)) {
            $start = max(0, $node->getStartFilePos());
            $end = max($start, $node->getEndFilePos() + 1);
        }

        return $this->context->parsedFile->sourceFile->createSpan($start, $end);
    }

    private function formatForSource(string $php, int $offset): string
    {
        $source = $this->context->parsedFile->sourceFile->contents;
        $lineStart = max(strrpos(substr($source, 0, $offset), "\n") ?: -1, strrpos(substr($source, 0, $offset), "\r") ?: -1) + 1;
        $prefix = substr($source, $lineStart, $offset - $lineStart);
        $indent = trim($prefix) === '' ? $prefix : '';
        $php = str_replace("\n", "\n" . $indent, $php);

        return str_contains($source, "\r\n") ? str_replace("\n", "\r\n", $php) : $php;
    }
}
