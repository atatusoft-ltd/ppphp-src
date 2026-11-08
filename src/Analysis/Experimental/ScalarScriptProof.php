<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Experimental;

use Atatusoft\Ppphp\Analysis\CompilerProjectAnalysis;
use Atatusoft\Ppphp\Analysis\Declaration\DeclarationReferenceCollector;
use Atatusoft\Ppphp\Analysis\Enumerations\AnalysisCompleteness;
use Atatusoft\Ppphp\Analysis\Experimental\Enumerations\UntrackedString;
use Atatusoft\Ppphp\Frontend\ParsedFile;
use Atatusoft\Ppphp\Project\Project;
use Atatusoft\Ppphp\Project\ProjectParseResult;
use Atatusoft\Ppphp\Project\SourceSet;
use Atatusoft\Ppphp\Project\ProjectCheckResult;
use Atatusoft\Ppphp\Semantic\Binding\Enumerations\BindingMutability;
use Atatusoft\Ppphp\Semantic\Binding\LocalBinding;
use Atatusoft\Ppphp\Semantic\Type\TypedArrayType;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;

/**
 * Local browser experiment for a closed subset of top-level scripts.
 *
 * @phpstan-type ScalarValue bool|float|int|string|UntrackedString
 * @phpstan-type Locals array<string, ScalarValue>
 * @phpstan-type Collections array<string, non-empty-array<int|string, ScalarValue>>
 */
final readonly class ScalarScriptProof
{
    private const array AMBIENT_VARIABLES = [
        'GLOBALS', '_SERVER', '_GET', '_POST', '_FILES', '_COOKIE', '_SESSION', '_REQUEST', '_ENV',
        'this', 'argc', 'argv', 'http_response_header', 'php_errormsg',
    ];

    public function check(CompilerProjectAnalysis $analysis): ?ProjectCheckResult
    {
        $omissions = [];
        if (!$this->qualifies($analysis, $omissions)) {
            return null;
        }

        return new ProjectCheckResult(
            $analysis->parseResult,
            $analysis->semanticResult,
            null,
            $analysis->diagnostics,
            completeness: AnalysisCompleteness::ExperimentalScalarProof,
            declarationContext: $analysis->declarationContext,
            compilerAnnotationOmissions: $omissions,
        );
    }

    public function canAnalyzeLocally(Project $project, SourceSet $selected, ProjectParseResult $parsed): bool
    {
        $file = $this->findCandidate($project, $selected, $parsed);
        if ($file === null) {
            return false;
        }

        foreach ($file->statements as $statement) {
            if (!$statement instanceof Stmt\Nop && !$statement instanceof Stmt\Echo_ && !$statement instanceof Stmt\Foreach_
                && (!$statement instanceof Stmt\Expression || !$statement->expr instanceof Expr\Assign)) {
                return false;
            }
        }

        $references = (new DeclarationReferenceCollector())->collect([$file]);

        return $references['classes'] === [] && $references['constants'] === []
            && array_diff(array_map(strtolower(...), $references['functions']), ['count']) === [];
    }

    private function findCandidate(Project $project, SourceSet $selected, ProjectParseResult $result): ?ParsedFile
    {
        if (!$result->isSuccessful || count($project->sources) !== 1 || count($selected->ppphpFiles) !== 1
            || $project->configuration->targetPhpVersion !== '8.4'
            || count($project->stubs) !== 0 || $project->composer->dependencies !== []) {
            return null;
        }

        $file = $result->findParsedFile($selected->ppphpFiles->files[0]->path);

        return $file !== null && strlen($file->sourceFile->contents) <= 16_384 && count($file->statements) <= 64 ? $file : null;
    }

    /** @param array<string, array<int, list<string>>> $omissions */
    private function qualifies(CompilerProjectAnalysis $analysis, array &$omissions): bool
    {
        if (!$analysis->isSuccessful || $analysis->uncoveredRequiredCapabilities !== []) {
            return false;
        }

        $parsed = $this->findCandidate($analysis->project, $analysis->selectedSources, $analysis->parseResult);
        $semantic = $analysis->semanticResult;
        $model = $parsed === null ? null : $semantic?->findModel($parsed->sourceFile->path);
        if ($parsed === null || $semantic === null || $model === null) {
            return false;
        }

        foreach (\PhpToken::tokenize($parsed->normalizedSource->contents) as $token) {
            if ($token->id === T_DOC_COMMENT || ($token->id === T_COMMENT && str_contains($token->text, '@'))) {
                return false;
            }
        }

        $declarations = [];
        foreach ($parsed->extensionSyntax->typedLocals as $local) {
            $binding = $model->bindings->find($local->id);
            if ($binding === null || $binding->initializerExpression === null || isset($declarations[$binding->name])) {
                return false;
            }
            $declarations[$binding->name] = $binding;
        }

        $loopBindings = [];
        foreach ($parsed->extensionSyntax->typedForeachBindings as $declaration) {
            $binding = $model->bindings->find($declaration->id);
            if ($binding === null) {
                return false;
            }
            $loopBindings[$declaration->variableSpan->start->offset] = $binding;
        }

        $countOwners = null;
        $assigned = [];
        $arrays = [];
        $steps = 8192;
        $reads = [];
        $printed = false;
        $appended = false;
        foreach ($parsed->statements as $statement) {
            if ($statement instanceof Stmt\Nop) {
                continue;
            }
            if ($statement instanceof Stmt\Echo_) {
                if (!$this->checkEcho($statement, $assigned, $arrays, $reads, $steps)) {
                    return false;
                }
                $printed = true;
                continue;
            }
            if ($statement instanceof Stmt\Foreach_) {
                if (!$this->checkLoop($statement, $loopBindings, $assigned, $arrays, $reads, $steps)) {
                    return false;
                }
                $printed = true;
                continue;
            }
            if (!$statement instanceof Stmt\Expression || !$statement->expr instanceof Expr\Assign) {
                return false;
            }

            $assignment = $statement->expr;
            $append = $assignment->var instanceof Expr\ArrayDimFetch && $assignment->var->dim === null;
            $destination = $append ? $assignment->var->var : $assignment->var;
            if (!$destination instanceof Expr\Variable || !is_string($destination->name)) {
                return false;
            }
            $name = $destination->name;
            if (in_array($name, self::AMBIENT_VARIABLES, true)) {
                return false;
            }
            $binding = $declarations['$' . $name] ?? null;
            if ($binding === null) {
                return false;
            }

            $type = $binding->type->semanticType;
            if ($type instanceof TypedArrayType) {
                if ($append) {
                    if (!isset($arrays[$name]) || !$type->isList || $binding->mutability !== BindingMutability::Mutable) {
                        return false;
                    }
                    $value = $this->resolveExpression($assignment->expr, $assigned, $arrays, $reads, $steps, 32);
                    if ($value === null || $type->valueType->canonical !== $this->resolveType($value)) {
                        return false;
                    }
                    $arrays[$name][] = $value;
                    $appended = true;
                } else {
                    if (isset($arrays[$name]) || $binding->initializerExpression !== $assignment->expr
                        || !$assignment->expr instanceof Expr\Array_) {
                        return false;
                    }
                    $values = $this->resolveArrayLiteral($assignment->expr, $type);
                    if ($values === null) {
                        return false;
                    }
                    $arrays[$name] = $values;
                }
                continue;
            }

            if ($append) {
                return false;
            }
            if (isset($assigned[$name])) {
                if ($binding->mutability !== BindingMutability::Mutable) {
                    return false;
                }
                $value = $this->resolveExpression($assignment->expr, $assigned, $arrays, $reads, $steps, 32);
            } else {
                // Initializers also determine the generated @var annotation.
                // Only the already verified exact literal cases are admitted.
                if ($binding->initializerExpression !== $assignment->expr) {
                    return false;
                }
                $value = $this->resolveLiteral($assignment->expr);
                if ($assignment->expr instanceof Expr\FuncCall) {
                    $countOwners ??= (new CountAnnotationProof())->collect($semantic, $model);
                    $owner = $binding->declarationSpan->start->offset;
                    $count = $countOwners[$owner] ?? null;
                    if ($count === null || !isset($arrays[$count['array']])) {
                        return false;
                    }
                    $value = count($arrays[$count['array']]);
                    $omissions[$parsed->sourceFile->path][$owner][] = $count['name'];
                }
            }
            if ($value === null || $type->canonical !== $this->resolveType($value)) {
                return false;
            }
            $assigned[$name] = $value;
        }

        return ($printed || $appended) && count($assigned) + count($arrays) === count($declarations)
            && count($reads) === count($assigned);
    }

    /**
     * @param array<int, LocalBinding> $bindings
     * @param Locals $assigned
     * @param Collections $arrays
     * @param array<string, true> $reads
     */
    private function checkLoop(Stmt\Foreach_ $loop, array $bindings, array $assigned, array $arrays, array &$reads, int &$steps): bool
    {
        if ($loop->byRef || !$loop->expr instanceof Expr\Variable || !is_string($loop->expr->name)
            || !isset($arrays[$loop->expr->name])) {
            return false;
        }

        $targets = [];
        foreach ([$loop->valueVar, ...($loop->keyVar === null ? [] : [$loop->keyVar])] as $target) {
            $binding = $bindings[$target->getStartFilePos()] ?? null;
            if (!$target instanceof Expr\Variable || !is_string($target->name) || $binding?->name !== '$' . $target->name
                || isset($assigned[$target->name]) || isset($arrays[$target->name])
                || in_array($target->name, self::AMBIENT_VARIABLES, true)) {
                return false;
            }
            $targets[] = $target->name;
        }

        $body = [];
        foreach ($loop->stmts as $statement) {
            if ($statement instanceof Stmt\Nop) {
                continue;
            }
            if (!$statement instanceof Stmt\Echo_) {
                return false;
            }
            $body[] = $statement;
        }
        if ($body === []) {
            return false;
        }

        $loopReads = [];
        $scope = $assigned;
        foreach ($arrays[$loop->expr->name] as $key => $value) {
            $scope[$targets[0]] = $value;
            if (isset($targets[1])) {
                $scope[$targets[1]] = $key;
            }
            foreach ($body as $statement) {
                if (!$this->checkEcho($statement, $scope, $arrays, $loopReads, $steps)) {
                    return false;
                }
            }
        }

        foreach ($targets as $name) {
            if (!isset($loopReads[$name])) {
                return false;
            }
            unset($loopReads[$name]);
        }
        $reads += $loopReads;

        return true;
    }

    /**
     * @param Locals $assigned
     * @param Collections $arrays
     * @param array<string, true> $reads
     */
    private function checkEcho(Stmt\Echo_ $echo, array $assigned, array $arrays, array &$reads, int &$steps): bool
    {
        foreach ($echo->exprs as $expression) {
            if ($this->resolveExpression($expression, $assigned, $arrays, $reads, $steps, 32) === null) {
                return false;
            }
        }

        return true;
    }

    /** @return non-empty-array<int|string, ScalarValue>|null */
    private function resolveArrayLiteral(Expr\Array_ $literal, TypedArrayType $type): ?array
    {
        if ($literal->items === [] || count($literal->items) > 256) {
            return null;
        }

        $values = [];
        foreach ($literal->items as $item) {
            if ($item->byRef || $item->unpack) {
                return null;
            }
            $value = $this->resolveLiteral($item->value);
            if ($value === null || $type->valueType->canonical !== get_debug_type($value)) {
                return null;
            }
            if ($type->isList) {
                if ($item->key !== null) {
                    return null;
                }
                $values[] = $value;
                continue;
            }

            $key = match (true) {
                $item->key instanceof Scalar\Int_, $item->key instanceof Scalar\String_ => $item->key->value,
                default => null,
            };
            // PHP normalizes some string keys to integers. Only admit an exact
            // key type and a map where every literal contributes an entry.
            $keyType = $type->keyType->canonical;
            if ($key === null || (is_int($key) ? $keyType !== 'int' : $keyType !== 'string')
                || (is_string($key) && (string) (int) $key === $key)
                || isset($values[$key])) {
                return null;
            }
            $values[$key] = $value;
        }

        return $values;
    }

    /**
     * @param Locals $assigned
     * @param Collections $arrays
     * @param array<string, true> $reads
     */
    private function resolveExpression(
        Expr $expression,
        array $assigned,
        array $arrays,
        array &$reads,
        int &$steps,
        int $depth,
    ): bool|float|int|string|UntrackedString|null {
        if ($depth === 0 || --$steps < 0) {
            return null;
        }
        if ($expression instanceof Expr\Variable && is_string($expression->name) && isset($assigned[$expression->name])) {
            $reads[$expression->name] = true;
            return $assigned[$expression->name];
        }
        if ($expression instanceof Expr\ArrayDimFetch && $expression->var instanceof Expr\Variable
            && is_string($expression->var->name) && $expression->dim !== null && isset($arrays[$expression->var->name])) {
            $key = $this->resolveExpression($expression->dim, $assigned, $arrays, $reads, $steps, $depth - 1);

            return is_int($key) || is_string($key) ? ($arrays[$expression->var->name][$key] ?? null) : null;
        }
        if (!$expression instanceof Expr\BinaryOp) {
            return $this->resolveLiteral($expression);
        }

        $left = $this->resolveExpression($expression->left, $assigned, $arrays, $reads, $steps, $depth - 1);
        $right = $this->resolveExpression($expression->right, $assigned, $arrays, $reads, $steps, $depth - 1);
        if ($expression instanceof Expr\BinaryOp\Concat) {
            // Do not materialize strings that could grow exponentially. The
            // result still has a known type but cannot prove an exact key.
            return (is_string($left) || is_int($left) || $left instanceof UntrackedString)
                && (is_string($right) || is_int($right) || $right instanceof UntrackedString)
                ? UntrackedString::Value : null;
        }
        if (!is_int($left) || !is_int($right)) {
            return null;
        }

        $result = match (true) {
            $expression instanceof Expr\BinaryOp\Plus => $left + $right,
            $expression instanceof Expr\BinaryOp\Minus => $left - $right,
            $expression instanceof Expr\BinaryOp\Mul => $left * $right,
            default => null,
        };

        // PHP promotes an overflowing integer operation to a float.
        return is_int($result) ? $result : null;
    }

    private function resolveType(bool|float|int|string|UntrackedString $value): string
    {
        return $value instanceof UntrackedString ? 'string' : get_debug_type($value);
    }

    private function resolveLiteral(Expr $expression): bool|float|int|string|null
    {
        return match (true) {
            $expression instanceof Scalar\String_ => $expression->value,
            $expression instanceof Scalar\Int_ => $expression->value,
            $expression instanceof Scalar\Float_ && is_finite($expression->value) => $expression->value,
            $expression instanceof Expr\ConstFetch && $expression->name->isUnqualified()
                && in_array(strtolower($expression->name->toString()), ['true', 'false'], true) =>
                    strtolower($expression->name->toString()) === 'true',
            default => null,
        };
    }
}
