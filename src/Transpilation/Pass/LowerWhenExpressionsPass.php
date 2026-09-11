<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Transpilation\Pass;

use Atatusoft\Ppphp\Frontend\Ast\WhenElseBranch;
use Atatusoft\Ppphp\Interop\PhpDoc\PhpDocReader;
use Atatusoft\Ppphp\Semantic\Binding\LocalBinding;
use Atatusoft\Ppphp\Semantic\Call\Enumerations\ArgumentPassingMode;
use Atatusoft\Ppphp\Semantic\When\WhenExpressionAnalysis;
use Atatusoft\Ppphp\Semantic\Type\LocalType;
use Atatusoft\Ppphp\Semantic\Type\UnionType;
use Atatusoft\Ppphp\Semantic\Type\TypedArrayType;
use Atatusoft\Ppphp\Source\Span;
use Atatusoft\Ppphp\Transpilation\Pass\Interfaces\TranspilationPass;
use Atatusoft\Ppphp\Transpilation\SourceEditMapping;
use Atatusoft\Ppphp\Transpilation\TranspilationContext;
use Atatusoft\Ppphp\Transpilation\LocalBindingTypeRenderer;
use Atatusoft\Ppphp\Transpilation\WhenTailShape;
use Atatusoft\Ppphp\Transpilation\WhenOperandStability;
use Atatusoft\Ppphp\Transpilation\WhenValueLifetime;
use Atatusoft\Ppphp\Transpilation\WhenLocalScope;
use Atatusoft\Ppphp\Transpilation\WhenPhpPrinter;
use PhpParser\Comment;
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

    private WhenTailShape $tailShape;

    private WhenLocalScope $localScope;

    private int $prerequisiteSequence = 0;

    /** @var array<string, true> */
    private array $reservedNames = [];

    /** @var array<string, true> */
    private array $generatedNames = [];

    /** @var array<string, true> */
    private array $tailResultNames = [];

    /** @var array<string, true> */
    private array $receiverNames = [];

    /** @var array<string, true> */
    private array $referenceNames = [];

    /** @var array<string, true> */
    private array $primitiveNames = [];

    /** @var array<string, LocalType> */
    private array $temporaryTypes = [];

    /** @var \WeakMap<Expr, Expr> */
    private \WeakMap $sourceExpressions;

    /** @var \WeakMap<Arg, ArgumentPassingMode> */
    private \WeakMap $argumentPassingModes;

    /** @var \WeakMap<Doc, Span> */
    private \WeakMap $bindingDocumentOrigins;

    /** @var \WeakMap<Expr\Assign, Span> */
    private \WeakMap $resultAssignmentOrigins;

    public function __construct(private readonly Standard $printer = new WhenPhpPrinter()) {}

    public function execute(TranspilationContext $context): void
    {
        if ($context->semanticModel->whenExpressions->expressions === []) {
            return;
        }
        $this->context = $context;
        $this->tailShape = new WhenTailShape($context->semanticModel->whenExpressions);
        $this->localScope = new WhenLocalScope($context->semanticModel);
        $this->prerequisiteSequence = 0;
        $this->reservedNames = [];
        $this->generatedNames = [];
        $this->tailResultNames = [];
        $this->receiverNames = [];
        $this->referenceNames = [];
        $this->primitiveNames = [];
        $this->temporaryTypes = [];
        $this->sourceExpressions = new \WeakMap();
        $this->argumentPassingModes = new \WeakMap();
        $this->bindingDocumentOrigins = new \WeakMap();
        $this->resultAssignmentOrigins = new \WeakMap();

        foreach (token_get_all($context->parsedFile->sourceFile->contents) as $token) {
            if (is_array($token) && $token[0] === T_VARIABLE) {
                $this->reservedNames[ltrim($token[1], '$')] = true;
            }
        }

        foreach ($context->semanticModel->whenExpressions->expressions as $analysis) {
            $name = ltrim($analysis->temporaryName, '$');
            $this->reservedNames[$name] = true;
            $this->generatedNames[$name] = true;
            $this->temporaryTypes[$name] = $analysis->resultType;
        }
        $statements = [];

        foreach ($context->semanticModel->whenExpressions->expressions as $analysis) {
            if ($analysis->syntax->parentId !== null) {
                continue;
            }

            $span = $this->span($analysis->statement);
            foreach ($context->parsedFile->extensionSyntax->typedLocals as $declaration) {
                if ($declaration->variableSpan->start->offset === $span->start->offset) {
                    $span = $span->sourceFile->createSpan($declaration->span->start->offset, $span->end->offset);
                    break;
                }
            }
            $statements[$span->start->offset . ':' . $span->end->offset] = [$span, $analysis->statement];
        }

        foreach ($statements as [$span, $statement]) {
            if (!$statement instanceof Stmt) {
                throw new \LogicException("A when lowering site must belong to a statement.");
            }
            $copy = $this->copyStatement($statement);
            // Leading comments outside this edit survive in the source text.
            // Do not print them again; comments within the edit keep their context.
            $copy->setAttribute('comments', array_values(array_filter(
                $copy->getComments(),
                static fn (Comment $comment): bool => $comment->getEndFilePos() < 0
                    || $comment->getEndFilePos() >= $span->start->offset,
            )));
            $lowered = $this->lowerOrdinaryStatement($copy);
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
        // Result assignments can move (for example a preassigned fallback).
        // Map them in emitted order, anchored by their whole assignment, so
        // identical result spellings retain their distinct source identities.
        $results = [];
        foreach ((new NodeFinder())->find($statements, fn (Node $node): bool =>
            $node instanceof Expr\Assign && isset($this->resultAssignmentOrigins[$node])) as $assignment) {
            if ($assignment instanceof Expr\Assign) {
                $origin = $this->resultAssignmentOrigins[$assignment];
                if (array_any($this->context->semanticModel->whenExpressions->expressions,
                    static fn (WhenExpressionAnalysis $nested): bool =>
                        $nested->syntax->span->start->offset >= $origin->start->offset
                        && $nested->syntax->span->start->offset < $origin->end->offset)) {
                    // The nested conditions and results own their finer spans;
                    // the outer expression may retain only a placeholder span.
                    continue;
                }
                $results[] = [
                    $this->printer->prettyPrintExpr($assignment->expr),
                    $origin,
                    $this->printer->prettyPrintExpr($assignment),
                ];
            }
        }
        $mappedOrigins = [];
        foreach ([...$results, ...$candidates] as $candidate) {
            [$text, $origin] = $candidate;
            $key = $origin->start->offset . ':' . $origin->end->offset;
            if (isset($mappedOrigins[$key])) {
                continue;
            }
            $needle = $candidate[2] ?? $text;
            $offset = $this->findUnmappedText($replacement, $needle, $occupied);
            if ($offset === null) {
                continue;
            }
            $offset += strlen($needle) - strlen($text);
            $start = $this->resolveGeneratedLineStart($replacement, $offset);
            $prefix = substr($replacement, $start, $offset - $start);
            foreach ($this->generatedNames as $name => $_) {
                if (str_starts_with(ltrim($prefix), '$' . $name . ' = ')
                    || str_starts_with(ltrim($prefix), '$' . $name . ' =& ')) {
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
                // Another expression on this line already owns the prefix.
                // findUnmappedText proved the expression itself is still free.
                $start = $offset;
            }
            $occupied[] = [$start, $end];
            $mappings[] = new SourceEditMapping($start, $end, $origin);
            $mappedOrigins[$key] = true;
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
            $analysis = $this->context->semanticModel->whenExpressions->findPlaceholder($statement->expr);
            if ($analysis !== null && $this->tailShape->accepts($analysis, allowGuards: true)) {
                $conditional = $this->buildWhenStatement($analysis, returnResult: true);
                $conditional->setAttribute('comments', $statement->getComments());
                return [$conditional];
            }
            [$prelude, $expression] = $this->lowerExpression($statement->expr);
            $statement->expr = $expression;
            $this->decorateTemporaryTypes($statement, $prelude);

            return $this->completeConsumer($prelude, $statement);
        }

        if ($statement instanceof Stmt\Expression) {
            $nullsafe = $this->lowerNullsafeChain($statement->expr, false);
            if ($nullsafe !== null) {
                return $nullsafe[0];
            }
            $assignment = $statement->expr;
            if ($assignment instanceof Expr\Assign) {
                $analysis = $this->context->semanticModel->whenExpressions->findPlaceholder($assignment->expr);
                if ($analysis !== null && $this->tailShape->accepts($analysis, allowGuards: true)
                    && $this->canAssignDirectly($analysis, $assignment->var)
                    && ($this->tailShape->accepts($analysis) || $this->canSeedResultLocal($assignment->var))) {
                    $conditional = $this->buildWhenStatement($analysis, $assignment->var);
                    $conditional->setAttribute('comments', $statement->getComments());
                    return [$conditional];
                }
            }
            [$prelude, $expression] = $this->lowerExpression($statement->expr);
            $statement->expr = $expression;
            $this->decorateTypedLocal($statement);
            $this->decorateTemporaryTypes($statement, $prelude);

            return $this->completeConsumer($prelude, $statement);
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
        $nullsafe = $this->lowerNullsafeChain($expression, true);
        if ($nullsafe !== null) {
            return $nullsafe;
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
            [$receiverPrelude, $receiver] = $this->captureConsumer($receiverPrelude, $receiver);
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
                [$targetPrelude, $target] = $this->hoistAssignmentTarget($expression->var, [...$prelude, $value]);
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
                [$nestedPrelude, $nested] = $this->captureConsumer($nestedPrelude, $nested);
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
        [$leftPrelude, $expression->left] = $this->captureConsumer($leftPrelude, $expression->left);
        [$rightPrelude, $expression->right] = $this->captureConsumer($rightPrelude, $expression->right);

        if ($rightPrelude === [] || $this->canDelayOperand($expression->left, [...$rightPrelude, $expression->right])) {
            return [[...$leftPrelude, ...$rightPrelude], $expression];
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
        [$leftPrelude, $expression->left] = $this->captureConsumer($leftPrelude, $expression->left);
        [$rightPrelude, $expression->right] = $this->captureConsumer($rightPrelude, $expression->right);

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
        [$leftPrelude, $expression->left] = $this->captureConsumer($leftPrelude, $expression->left);
        [$rightPrelude, $expression->right] = $this->captureConsumer($rightPrelude, $expression->right);

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
        [$conditionPrelude, $condition] = $expression->if === null
            ? $this->captureConsumer($conditionPrelude, $condition)
            : $this->captureCondition($conditionPrelude, $condition);
        $expression->cond = $condition;
        $ifPrelude = [];
        if ($expression->if !== null) {
            [$ifPrelude, $expression->if] = $this->lowerExpression($expression->if);
            [$ifPrelude, $expression->if] = $this->captureConsumer($ifPrelude, $expression->if);
        }
        [$elsePrelude, $expression->else] = $this->lowerExpression($expression->else);
        [$elsePrelude, $expression->else] = $this->captureConsumer($elsePrelude, $expression->else);

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
        // Check the entire remaining evaluation window, including later when
        // blocks and ordinary arguments that have not yet been hoisted.
        $effects = [];
        foreach ($arguments as $position => $argument) {
            if ($argument instanceof Arg) {
                $effects[$position] = $argument->unpack ? $argument : $argument->value;
            }
        }
        $calleePending = $callee !== null && !$this->canDelayOperand($callee, array_values($effects));
        $delayArguments = [];
        foreach ($arguments as $position => $argument) {
            if ($argument instanceof Arg) {
                $delayArguments[$position] = !$argument->unpack
                    && ($this->argumentPassingModes[$argument] ?? ArgumentPassingMode::Unknown) !== ArgumentPassingMode::Unknown
                    && $this->canDelayOperand($argument->value, array_values(array_filter(
                        $effects, static fn (int $index): bool => $index > $position, ARRAY_FILTER_USE_KEY,
                    )));
            }
        }
        if ($callee instanceof Expr\Variable && is_string($callee->name) && isset($this->generatedNames[$callee->name])) {
            $this->receiverNames[$callee->name] = true;
            $calleePending = false;
        }

        foreach ($arguments as $position => $argument) {
            if (!$argument instanceof Arg) {
                continue;
            }
            [$nestedPrelude, $value] = $this->lowerExpression($argument->value);
            [$nestedPrelude, $value] = $this->captureConsumer(
                $nestedPrelude, $value,
                ($this->argumentPassingModes[$argument] ?? ArgumentPassingMode::Unknown) === ArgumentPassingMode::Reference,
            );
            if ($nestedPrelude !== []) {
                if ($calleePending) {
                    if ($callee instanceof Expr\Variable && is_string($callee->name) && isset($this->generatedNames[$callee->name])) {
                        $this->receiverNames[$callee->name] = true;
                    }
                    [$assignment, $temporary] = $this->hoist($callee);
                    if (is_string($temporary->name)) {
                        $this->receiverNames[$temporary->name] = true;
                    }
                    $prelude[] = $assignment;
                    $callee = $temporary;
                    $calleePending = false;
                }
                foreach ($pending as $pendingPosition) {
                    $pendingArgument = $arguments[$pendingPosition];
                    if (!$pendingArgument instanceof Arg) {
                        continue;
                    }
                    if ($pendingArgument->value instanceof Expr\Variable
                        && is_string($pendingArgument->value->name)
                        && isset($this->generatedNames[$pendingArgument->value->name])) {
                        continue;
                    }
                    $mode = $this->argumentPassingModes[$pendingArgument] ?? ArgumentPassingMode::Unknown;
                    if ($delayArguments[$pendingPosition]) {
                        continue;
                    }
                    [$assignment, $temporary] = $this->hoist(
                        $pendingArgument->value,
                        $mode === ArgumentPassingMode::Reference,
                    );
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
        $effects = [];
        foreach ($array->items as $item) {
            if ($item->key !== null) {
                $effects[] = $item->key;
            }
            $effects[] = $item->unpack ? $item : $item->value;
        }
        $delayKeys = $delayValues = [];
        foreach ($array->items as $position => $item) {
            $delayKeys[$position] = $item->key === null || $this->canDelayOperand($item->key, $effects);
            $delayValues[$position] = !$item->unpack && $this->canDelayOperand($item->value, $effects);
        }

        foreach ($array->items as $position => $item) {
            $keyPrelude = [];
            if ($item->key !== null) {
                [$keyPrelude, $item->key] = $this->lowerExpression($item->key);
            }
            [$valuePrelude, $item->value] = $this->lowerExpression($item->value);
            [$valuePrelude, $item->value] = $this->captureConsumer($valuePrelude, $item->value, $item->byRef);
            $nestedPrelude = [...$keyPrelude, ...$valuePrelude];
            if ($nestedPrelude !== []) {
                foreach ($pending as $pendingPosition) {
                    $pendingItem = $array->items[$pendingPosition];
                    if ($pendingItem->key !== null && !$delayKeys[$pendingPosition]) {
                        [$assignment, $pendingItem->key] = $this->hoist($pendingItem->key);
                        $prelude[] = $assignment;
                    }
                    if (!$delayValues[$pendingPosition]) {
                        [$assignment, $pendingItem->value] = $this->hoist($pendingItem->value, $pendingItem->byRef);
                        $prelude[] = $assignment;
                    }
                }
                $pending = [];
                if ($item->key !== null && $valuePrelude !== [] && !$delayKeys[$position]) {
                    [$assignment, $item->key] = $this->hoist($item->key);
                    $prelude[] = $assignment;
                }
                array_push($prelude, ...$nestedPrelude);
            }
            $pending[] = $position;
        }

        return [$prelude, $array];
    }

    private function buildWhenStatement(
        WhenExpressionAnalysis $analysis,
        ?Expr $destination = null,
        bool $returnResult = false,
    ): Stmt
    {
        $shape = $this->tailShape;
        $tail = $shape->accepts($analysis, allowGuards: true);
        if ($tail && $destination === null && !$returnResult) {
            $name = ltrim($analysis->temporaryName, '$');
            $this->tailResultNames[$name] = true;
            $destination = new Expr\Variable($name);
        }
        $if = null;
        $elseifs = [];
        $else = null;

        foreach ($analysis->branches as $branch) {
            $branchStatements = [];
            $sourceStatements = $branch->statements;
            $initializers = [];
            $completionFlag = null;
            $pending = null;
            if ($tail && !$returnResult && $shape->requiresGuardCompletion($branch->statements)) {
                if (!$destination instanceof Expr\Variable || !is_string($destination->name)) {
                    throw new \LogicException('A shared guard continuation requires a result local.');
                }
                $fallback = $shape->resolveGuardFallback($sourceStatements);
                if ($fallback?->expr !== null && !$this->canDelayOperand($fallback->expr, [$sourceStatements[0]])) {
                    $fallback = null;
                }
                $value = $fallback?->expr === null
                    ? new Expr\ConstFetch(new Name('null')) : $this->copyExpression($fallback->expr);
                $assignment = new Expr\Assign(new Expr\Variable($destination->name), $value);
                if ($fallback?->expr !== null) {
                    $this->resultAssignmentOrigins[$assignment] = $this->span($fallback->expr);
                }
                $initializer = new Stmt\Expression($assignment);
                $binding = $this->findResultDeclaration($destination);
                if ($binding !== null) {
                    $type = $binding->type->semanticType;
                    $members = $type instanceof UnionType ? $type->members : [$type];
                    $nonNull = array_values(array_filter($members, static fn ($member): bool => $member->canonical !== 'null'));
                    $rendered = $nonNull === [] ? 'null' : (new UnionType($nonNull))->renderPhpDoc();
                    if ($nonNull !== [] && (count($nonNull) < count($members) || ($fallback === null && !$type->isNullable))) {
                        // Keep the pending alternative last without changing
                        // semantic type identity or generic argument rendering.
                        $rendered .= '|null';
                    }
                    $document = new Doc(sprintf('/** @var %s %s */', $rendered, $binding->name));
                    $initializer->setDocComment($document);
                    $this->bindingDocumentOrigins[$document] = $binding->declarationSpan;
                }
                $initializers[] = $initializer;
                if ($fallback !== null) {
                    $sourceStatements = [$sourceStatements[0]];
                } elseif ($analysis->resultType->semanticType->isNullable) {
                    $completionFlag = $this->allocateName('__ppphp_when_complete');
                    $this->primitiveNames[$completionFlag] = true;
                    $initializers[] = new Stmt\Expression(new Expr\Assign(
                        new Expr\Variable($completionFlag), new Expr\ConstFetch(new Name('false')),
                    ));
                    $pending = new Expr\BooleanNot(new Expr\Variable($completionFlag));
                } else {
                    $pending = new Expr\BinaryOp\Identical(
                        new Expr\Variable($destination->name), new Expr\ConstFetch(new Name('null')),
                    );
                }
            }
            // An enclosing return already provides native early completion.
            $sourceStatements = $tail && !$returnResult
                ? $shape->rewriteGuards($sourceStatements, $pending)
                : $sourceStatements;
            foreach ($sourceStatements as $statement) {
                $branchStatements[] = $this->copyStatement($statement);
            }
            $statements = $tail
                ? [...$initializers, ...$this->lowerOrdinaryStatements($this->rewriteTailResults(
                    $branchStatements, $destination, completionFlag: $completionFlag,
                ))]
                : $this->lowerBranchStatements($branchStatements, $analysis, 1);
            if ($completionFlag !== null) {
                // The completion bit belongs to this branch, not to the outer
                // consumer (which can also run after a different branch).
                $statements[] = new Stmt\Unset_([new Expr\Variable($completionFlag)]);
            }
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

        if ($tail) {
            return $if;
        }

        // Branches leave through an explicit result break or termination.
        // There is no condition exit that can fabricate an unassigned result.
        return new Stmt\Do_(new Expr\ConstFetch(new Name('true')), [$if]);
    }

    private function readsDestination(WhenExpressionAnalysis $analysis, string $name): bool
    {
        foreach ($this->context->semanticModel->bindings->bindings as $binding) {
            if ($binding->name !== '$' . $name) {
                continue;
            }
            foreach ($binding->reads as $read) {
                if ($this->contains($analysis->syntax->span, $read)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function findResultDeclaration(Expr $destination): ?LocalBinding
    {
        if (!$destination instanceof Expr\Variable || !is_string($destination->name)
            || isset($this->generatedNames[$destination->name])) {
            return null;
        }
        $offset = $this->span($destination)->start->offset;
        foreach ($this->context->semanticModel->bindings->bindings as $binding) {
            if ($binding->initializerSpan !== null && $binding->variableSpan->start->offset === $offset) {
                return $binding;
            }
        }

        return null;
    }

    private function canSeedResultLocal(Expr $destination): bool
    {
        return $this->findResultDeclaration($destination) !== null
            && $this->localScope->canSeedLocal($this->span($destination));
    }

    private function canAssignDirectly(WhenExpressionAnalysis $analysis, Expr $destination): bool
    {
        $commitMayExecute = !$this->canCommitLocalWithoutCode($destination);
        foreach ($analysis->branches as $branch) {
            foreach ($branch->statements as $statement) {
                if (!$this->canFinishResultWithoutCleanup($statement, $commitMayExecute)) {
                    return false;
                }
            }
        }
        if ($destination instanceof Expr\Variable && is_string($destination->name)) {
            return !$this->readsDestination($analysis, $destination->name);
        }
        $nodes = [];
        foreach ($analysis->branches as $branch) {
            if ($branch->condition !== null) {
                $nodes[] = $branch->condition;
            }
            array_push($nodes, ...$branch->statements);
        }
        if ($destination instanceof Expr\PropertyFetch && $destination->name instanceof Node\Identifier) {
            if (!$this->canDelayOperand($destination->var, $nodes)) {
                return false;
            }
        } elseif (!$destination instanceof Expr\StaticPropertyFetch || !$destination->class instanceof Name
            || !$destination->name instanceof Node\VarLikeIdentifier) {
            return false;
        }

        // Include reads through possible aliases and dynamic member names.
        $property = $destination->name->toString();
        return (new NodeFinder())->findFirst($nodes, static fn (Node $node): bool =>
            ($node instanceof Expr\PropertyFetch || $node instanceof Expr\NullsafePropertyFetch
                || $node instanceof Expr\StaticPropertyFetch)
            && (!$node->name instanceof Node\Identifier || $node->name->toString() === $property)) === null;
    }

    private function canCommitLocalWithoutCode(Expr $destination): bool
    {
        if (!$destination instanceof Expr\Variable || !is_string($destination->name)) {
            return false;
        }
        if ($this->canSeedResultLocal($destination)) {
            return true;
        }
        $offset = $this->span($destination)->start->offset;
        foreach ($this->context->semanticModel->bindings->bindings as $binding) {
            if ($binding->name !== '$' . $destination->name
                || !$this->localScope->canIsolateBinding($binding->variableSpan)
                || !(new WhenValueLifetime())->resolveReleaseSafety($binding->type->semanticType)) {
                continue;
            }
            if ($binding->variableSpan->start->offset === $offset
                || array_any($binding->writes, static fn (Span $write): bool => $write->start->offset === $offset)) {
                return true;
            }
        }
        return false;
    }

    private function canFinishResultWithoutCleanup(Node $node, bool $commitMayExecute, bool $pendingCleanup = false): bool
    {
        if ($node instanceof Node\FunctionLike || $node instanceof Stmt\ClassLike) {
            return true;
        }
        if ($node instanceof Stmt\Foreach_) {
            $type = $this->context->semanticModel->expressionTypes->resolve($this->context->parsedFile->sourceFile, $node->expr)?->type;
            // Either side can make the order observable: iterator cleanup may
            // execute code, and a destination hook/old-value destructor can
            // observe iterator storage even when releasing it cannot throw.
            $pendingCleanup = $pendingCleanup || $commitMayExecute || !$type instanceof TypedArrayType
                || !(new WhenValueLifetime())->resolveReleaseSafety($type);
        }
        if ($node instanceof Stmt\Return_) {
            return !$pendingCleanup;
        }
        foreach ($node->getSubNodeNames() as $name) {
            foreach (is_array($node->$name) ? $node->$name : [$node->$name] as $child) {
                if ($child instanceof Node && !$this->canFinishResultWithoutCleanup($child, $commitMayExecute, $pendingCleanup)) {
                    return false;
                }
            }
        }
        return true;
    }

    /** @param list<Node> $intervening */
    private function canDelayOperand(Expr $operand, array $intervening): bool
    {
        return (new WhenOperandStability(
            array_diff_key($this->generatedNames, $this->referenceNames), $this->context->semanticModel, $this->localScope,
        ))
            ->canDelay($operand, $intervening);
    }

    /**
     * @param list<Stmt> $statements
     * @return list<Stmt>
     */
    private function rewriteTailResults(
        array $statements,
        ?Expr $destination,
        int $breakDepth = 0,
        ?string $completionFlag = null,
        bool $completionReadAfter = false,
    ): array
    {
        $completionWrites = [];
        if ($completionFlag !== null) {
            for ($index = count($statements) - 1; $index >= 0; $index--) {
                $completionWrites[$index] = $completionReadAfter;
                $completionReadAfter = $completionReadAfter
                    || (new NodeFinder())->findFirst([$statements[$index]], static fn (Node $node): bool =>
                        $node instanceof Expr\Variable && $node->name === $completionFlag) !== null;
            }
        }
        $rewritten = [];
        foreach ($statements as $index => $statement) {
            if ($statement instanceof Stmt\Return_ && $destination !== null) {
                if ($statement->expr === null) {
                    throw new \LogicException('A checked when result must have a value.');
                }
                $assignment = new Expr\Assign(
                    $destination instanceof Expr\Variable
                        ? new Expr\Variable($destination->name)
                        : $this->copyExpression($destination),
                    $statement->expr,
                );
                $this->resultAssignmentOrigins[$assignment] = $this->span($statement->expr);
                $rewritten[] = new Stmt\Expression($assignment, $statement->getAttributes());
                if ($completionFlag !== null && $completionWrites[$index]) {
                    $rewritten[] = new Stmt\Expression(new Expr\Assign(
                        new Expr\Variable($completionFlag), new Expr\ConstFetch(new Name('true')),
                    ));
                }
                if ($breakDepth > 0) {
                    $rewritten[] = new Stmt\Break_($breakDepth === 1 ? null : new Scalar\Int_($breakDepth));
                }
                continue;
            }
            if ($statement instanceof Stmt\If_) {
                foreach ([$statement, ...$statement->elseifs, ...($statement->else === null ? [] : [$statement->else])] as $arm) {
                    $commonExit = $destination !== null && $breakDepth > 0
                        && $this->tailShape->completesCase($arm->stmts);
                    $arm->stmts = $this->rewriteTailResults(
                        array_values($arm->stmts), $destination, $commonExit ? 0 : $breakDepth,
                        $completionFlag, $completionWrites[$index] ?? false,
                    );
                    if ($commonExit) {
                        $arm->stmts[] = new Stmt\Break_($breakDepth === 1 ? null : new Scalar\Int_($breakDepth));
                    }
                }
            } elseif ($statement instanceof Stmt\Switch_) {
                foreach ($statement->cases as $case) {
                    // A common case exit keeps ordinary conditional assignment
                    // visible to PHP flow analysis. Preserve conditional exits
                    // when an arm must still fall through to the next case.
                    $commonExit = $destination !== null && $this->tailShape->completesCase($case->stmts);
                    $case->stmts = $this->rewriteTailResults(
                        array_values($case->stmts), $destination, $commonExit ? 0 : $breakDepth + 1,
                        $completionFlag, $completionWrites[$index] ?? false,
                    );
                    if ($commonExit) {
                        $case->stmts[] = new Stmt\Break_($breakDepth === 0 ? null : new Scalar\Int_($breakDepth + 1));
                    }
                }
            } elseif ($statement instanceof Stmt\For_ || $statement instanceof Stmt\Foreach_
                || $statement instanceof Stmt\While_ || $statement instanceof Stmt\Do_) {
                $statement->stmts = $this->rewriteTailResults(
                    array_values($statement->stmts), $destination, $breakDepth + 1,
                    $completionFlag, $completionWrites[$index] ?? false,
                );
            }
            $rewritten[] = $statement;
        }

        return $rewritten;
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

        $nextElseifs = [];
        foreach (array_reverse($statement->elseifs) as $elseif) {
            [$prelude, $condition] = $this->lowerExpression($elseif->cond);
            [$prelude, $condition] = $this->captureCondition($prelude, $condition);
            $body = $this->lowerConditionalStatements(
                array_values($elseif->stmts),
                $analysis,
                $breakDepth,
                $completionFlag,
            );
            if ($prelude === []) {
                array_unshift($nextElseifs, new Stmt\ElseIf_($condition, $body, $elseif->getAttributes()));
                continue;
            }
            $nested = new Stmt\If_($condition, [
                'stmts' => $body,
                'elseifs' => $nextElseifs,
                'else' => $nextElse,
            ], $elseif->getAttributes());
            $this->decorateTemporaryTypes($nested, $prelude);
            $nextElse = new Stmt\Else_($this->completeConditional($prelude, $nested), $elseif->getAttributes());
            $nextElseifs = [];
        }

        [$prelude, $statement->cond] = $this->lowerExpression($statement->cond);
        [$prelude, $statement->cond] = $this->captureCondition($prelude, $statement->cond);
        $statement->elseifs = $nextElseifs;
        $statement->else = $nextElse;
        $this->decorateTemporaryTypes($statement, $prelude);

        return $this->completeConditional($prelude, $statement);
    }

    /** @param list<Stmt> $prelude
     * @return list<Stmt>
     */
    private function completeConditional(array $prelude, Stmt\If_ $statement): array
    {
        $cleanup = $this->buildCleanup($prelude);
        if ($cleanup !== []) {
            // Release condition scratch names on entry to either path, including
            // paths that return, throw or transfer before the end of the if.
            $statement->stmts = [...$cleanup, ...$statement->stmts];
            $statement->else ??= new Stmt\Else_();
            $statement->else->stmts = [...$cleanup, ...$statement->else->stmts];
        }

        return [...$prelude, $statement];
    }

    /**
     * Guard the entire remaining access chain, not just the nullsafe call's
     * arguments. A null receiver also skips ordinary links farther out.
     *
     * @return array{list<Stmt>, Expr}|null
     */
    private function lowerNullsafeChain(Expr $expression, bool $needsValue): ?array
    {
        $site = null;
        $chain = [];
        $links = [];
        $node = $expression;
        while ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall
            || $node instanceof Expr\PropertyFetch || $node instanceof Expr\NullsafePropertyFetch
            || $node instanceof Expr\ArrayDimFetch) {
            $chain[] = $node;
            if ($node instanceof Expr\NullsafeMethodCall || $node instanceof Expr\NullsafePropertyFetch) {
                $site = $node;
                $links = array_reverse($chain);
            }
            $node = $node->var;
        }
        if ($site === null || $links === [] || (new NodeFinder())->findFirst($expression, static fn (Node $node): bool =>
            is_string($node->getAttribute('ppphpWhenExpressionId'))) === null) {
            return null;
        }

        [$prelude, $receiver] = $this->lowerExpression($site->var);
        [$prelude, $receiver] = $this->captureConsumer($prelude, $receiver);
        if (!$receiver instanceof Expr\Variable || !is_string($receiver->name) || !isset($this->generatedNames[$receiver->name])) {
            [$assignment, $receiver] = $this->hoist($receiver);
            $prelude[] = $assignment;
        }
        if (!is_string($receiver->name)) {
            throw new \LogicException('A nullsafe receiver needs a static scratch name.');
        }
        $this->receiverNames[$receiver->name] = true;
        $this->tailResultNames[$receiver->name] = true;
        $result = null;
        $prefix = [];
        if ($needsValue) {
            $name = $this->allocateName('__ppphp_when_nullsafe');
            $this->tailResultNames[$name] = true;
            $result = new Expr\Variable($name);
            $prefix[] = new Stmt\Expression(new Expr\Assign(clone $result, new Expr\ConstFetch(new Name('null'))));
        }
        $body = $this->lowerAccessChain($links, $receiver, $result);

        return [[...$prefix, ...$this->completeConsumer($prelude, $body)], $result ?? new Expr\ConstFetch(new Name('null'))];
    }

    /**
     * Each link consumes its receiver before the next link's arguments run.
     * Nullsafe links guard the remaining chain, including ordinary links.
     *
     * @param non-empty-list<Expr\MethodCall|Expr\NullsafeMethodCall|Expr\PropertyFetch|Expr\NullsafePropertyFetch|Expr\ArrayDimFetch> $links
     * @return list<Stmt>
     */
    private function lowerAccessChain(array $links, Expr\Variable $receiver, ?Expr\Variable $result): array
    {
        $link = array_shift($links);
        $nullsafe = $link instanceof Expr\NullsafeMethodCall || $link instanceof Expr\NullsafePropertyFetch;
        if ($link instanceof Expr\NullsafeMethodCall) {
            $link = new Expr\MethodCall(clone $receiver, $link->name, $link->args, $link->getAttributes());
        } elseif ($link instanceof Expr\NullsafePropertyFetch) {
            $link = new Expr\PropertyFetch(clone $receiver, $link->name, $link->getAttributes());
        } else {
            $link->var = clone $receiver;
        }
        if ($links === []) {
            $body = $this->lowerOrdinaryStatement(new Stmt\Expression(
                $result === null ? $link : new Expr\Assign(clone $result, $link),
            ));
        } else {
            [$prelude, $value] = $this->lowerExpression($link);
            [$assignment, $next] = $this->hoist($value);
            if (!is_string($next->name) || !is_string($receiver->name)) {
                throw new \LogicException('Access-chain temporaries must have static names.');
            }
            $this->tailResultNames[$next->name] = true;
            $this->receiverNames[$next->name] = true;
            // Release the old receiver after its arguments. Its enclosing
            // finally still owns the name, but now holds no retained value.
            // This release must run even if an argument's destructor throws.
            $prelude = [new Stmt\TryCatch(
                $this->completeConsumer($prelude, $assignment), [],
                new Stmt\Finally_([new Stmt\Expression(new Expr\Assign(
                    clone $receiver, new Expr\ConstFetch(new Name('null')),
                ))]),
            )];
            $body = $this->completeConsumer(
                $prelude,
                $this->lowerAccessChain($links, $next, $result),
                [$receiver->name => true],
            );
        }

        return $nullsafe ? [new Stmt\If_(
            new Expr\BinaryOp\NotIdentical(clone $receiver, new Expr\ConstFetch(new Name('null'))),
            ['stmts' => $body],
        )] : $body;
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
        if (in_array($type, ['int', 'float', 'bool', 'null'], true)) {
            $this->primitiveNames[$name] = true;
        }
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

    /**
     * @param list<Node> $intervening
     * @return array{list<Stmt>, Expr}
     */
    private function hoistAssignmentTarget(Expr $target, array $intervening): array
    {
        if ($target instanceof Expr\PropertyFetch || $target instanceof Expr\NullsafePropertyFetch) {
            // Native assignment reads a direct variable receiver at the write,
            // after the RHS, even when the RHS replaces it through an alias.
            // A receiver-producing expression, in contrast, is evaluated first.
            if (($target->var instanceof Expr\Variable && is_string($target->var->name))
                || $this->canDelayOperand($target->var, $intervening)) {
                return [[], $target];
            }
            [$assignment, $receiver] = $this->hoist($target->var);
            if (is_string($receiver->name)) {
                $this->receiverNames[$receiver->name] = true;
            }
            $target->var = $receiver;

            return [[$assignment], $target];
        }

        if ($target instanceof Expr\ArrayDimFetch) {
            $prelude = [];
            if (!$target->var instanceof Expr\Variable) {
                [$assignment, $target->var] = $this->hoist($target->var);
                $prelude[] = $assignment;
            }
            if ($target->dim !== null && !$this->canDelayOperand($target->dim, $intervening)) {
                [$assignment, $target->dim] = $this->hoist($target->dim);
                $prelude[] = $assignment;
            }

            return [$prelude, $target];
        }

        return [[], $target];
    }

    /** @return array{Stmt\Expression, Expr\Variable} */
    private function hoist(Expr $expression, bool $byReference = false): array
    {
        $name = $this->allocateName('__ppphp_when_prerequisite');
        if ($byReference) {
            $this->referenceNames[$name] = true;
        }
        if ($expression instanceof Expr\Cast\Bool_ || $expression instanceof Expr\Cast\Int_
            || $expression instanceof Expr\Cast\Double || $expression instanceof Scalar\Int_ || $expression instanceof Scalar\Float_) {
            $this->primitiveNames[$name] = true;
        }
        $original = $this->sourceExpressions[$expression] ?? $expression;
        $type = $this->context->semanticModel->expressionTypes->resolve($this->context->parsedFile->sourceFile, $original);
        if ($type !== null) {
            $this->temporaryTypes[$name] = LocalType::createFromSemanticType($type->type);
        } elseif ($expression instanceof Expr\Variable && is_string($expression->name)
            && isset($this->temporaryTypes[$expression->name])) {
            $this->temporaryTypes[$name] = $this->temporaryTypes[$expression->name];
        }
        $variable = new Expr\Variable($name);

        $assignment = $byReference
            ? new Expr\AssignRef(clone $variable, $expression)
            : new Expr\Assign(clone $variable, $expression);

        return [new Stmt\Expression($assignment), $variable];
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
            if (isset($names[$name]) && !isset($this->tailResultNames[$name])) {
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
     * @param Stmt|list<Stmt> $consumer
     * @param array<string, true> $borrowed
     * @return list<Stmt>
     */
    private function completeConsumer(array $prelude, Stmt|array $consumer, array $borrowed = []): array
    {
        $names = [];
        foreach ($prelude as $statement) {
            $this->collectLiveGeneratedNames($statement, $names);
        }
        $names = array_diff_key($names, $borrowed);
        $body = is_array($consumer) ? $consumer : [$consumer];
        if (array_intersect_key($names, $this->tailResultNames) !== []) {
            $nonRefcounted = $this->resolvePrimitiveNames();
            $retained = array_diff_key($names, $nonRefcounted);
            if ($retained !== []) {
                // A prerequisite or branch can throw before a scratch local is
                // assigned. Initialize the cleanup set so it is valid on every
                // exit, without overriding the inferred result type.
                $initializers = [];
                foreach ($names as $name => $_) {
                    $initializers[] = new Stmt\Expression(new Expr\Assign(
                        new Expr\Variable($name), new Expr\ConstFetch(new Name('null')),
                    ));
                }

                return [...$initializers, new Stmt\TryCatch(
                    [...$prelude, ...$body], [],
                    new Stmt\Finally_($this->buildCleanup($prelude, $borrowed)),
                )];
            }
        }

        return [...$prelude, ...$body, ...($consumer instanceof Stmt\Return_ ? [] : $this->buildCleanup($prelude, $borrowed))];
    }

    /**
     * Finish a nested consumer before evaluating its parent's later operands.
     * Its result belongs to the parent; its arguments must not live that long.
     *
     * @param list<Stmt> $prelude
     * @return array{list<Stmt>, Expr}
     */
    private function captureConsumer(array $prelude, Expr $value, bool $byReference = false): array
    {
        if ($value instanceof Expr\Variable) {
            return [$prelude, $value];
        }
        $names = [];
        foreach ($prelude as $statement) {
            $this->collectLiveGeneratedNames($statement, $names);
        }
        if (array_intersect_key($names, $this->tailResultNames) === []) {
            return [$prelude, $value];
        }
        [$assignment, $result] = $this->hoist($value, $byReference);
        if (!is_string($result->name)) {
            throw new \LogicException('A generated consumer result must have a static name.');
        }
        $this->tailResultNames[$result->name] = true;

        return [$this->completeConsumer($prelude, $assignment), $result];
    }

    /** @param list<Stmt> $prelude
     * @return array{list<Stmt>, Expr}
     */
    private function captureCondition(array $prelude, Expr $condition): array
    {
        [$completed, $result] = $this->captureConsumer($prelude, new Expr\Cast\Bool_($condition));

        return $result instanceof Expr\Cast\Bool_ ? [$prelude, $condition] : [$completed, $result];
    }

    /** @return array<string, true> */
    private function resolvePrimitiveNames(bool $includeStrings = false): array
    {
        $names = $this->primitiveNames;
        $atoms = ['int', 'float', 'bool', 'true', 'false', 'null', ...($includeStrings ? ['string'] : [])];
        foreach ($this->temporaryTypes as $name => $type) {
            if ($includeStrings && (new WhenValueLifetime())->resolveReleaseSafety($type->semanticType)) {
                $names[$name] = true;
                continue;
            }
            $safe = true;
            foreach ($type->variants as $variant) {
                if (count($variant) !== 1 || !in_array($variant[0], $atoms, true)) {
                    $safe = false;
                }
            }
            if ($safe) {
                $names[$name] = true;
            }
        }

        // Even a reference to an int can keep an array element aliased after
        // an exception. It must be released at the native argument boundary.
        return array_diff_key($names, $this->referenceNames);
    }

    /**
     * @param list<Stmt> $prelude
     * @param array<string, true> $borrowed
     * @return list<Stmt>
     */
    private function buildCleanup(array $prelude, array $borrowed = []): array
    {
        $names = [];

        foreach ($prelude as $statement) {
            $this->collectLiveGeneratedNames($statement, $names);
        }
        $names = array_diff_key($names, $borrowed);

        // PHP releases call arguments before the receiver/callee temporary.
        $names = array_diff_key($names, $this->receiverNames) + array_intersect_key($names, $this->receiverNames);
        $nonThrowing = $this->resolvePrimitiveNames(includeStrings: true);
        $cleanup = [];
        foreach (array_reverse(array_keys($names)) as $name) {
            $unset = new Stmt\Unset_([new Expr\Variable($name)]);
            // An earlier destructor may throw. Later retained values must still
            // be released during unwinding, before an outer catch can run.
            if ($cleanup === []) {
                $cleanup = [$unset];
            } elseif (isset($nonThrowing[$name])) {
                if ($cleanup[0] instanceof Stmt\Unset_) {
                    array_unshift($cleanup[0]->vars, new Expr\Variable($name));
                } else {
                    array_unshift($cleanup, $unset);
                }
            } else {
                $cleanup = [new Stmt\TryCatch([$unset], [], new Stmt\Finally_($cleanup))];
            }
        }

        return $cleanup;
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
        // Reading an enclosing consumer's scratch local does not transfer its
        // ownership. Only definitions in this region belong to its cleanup.
        $defined = $node instanceof Expr\Assign || $node instanceof Expr\AssignRef || $node instanceof Stmt\Catch_ ? $node->var : null;
        if ($defined instanceof Expr\Variable && is_string($defined->name) && isset($this->generatedNames[$defined->name])) {
            $names[$defined->name] = true;
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
        if ($node instanceof Expr) {
            $analysis = $this->context->semanticModel->whenExpressions->findPlaceholder($node);
            $operands = $analysis === null ? null : $this->tailShape->resolveTernaryOperands($analysis);
            if ($operands !== null) {
                // Expose native expressions before deciding whether a parent
                // call or nullsafe chain needs any statement-level lowering.
                $copy = new Expr\Ternary(
                    ...$operands,
                    attributes: array_diff_key($node->getAttributes(), ['ppphpWhenExpressionId' => true]),
                );
            }
        }
        if ($copy instanceof Expr && $node instanceof Expr) {
            $this->sourceExpressions[$copy] = $node;
        }
        if ($copy instanceof Arg && $node instanceof Arg) {
            $this->argumentPassingModes[$copy] = $this->context->semanticModel->argumentPassing->resolve($node);
        }
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
