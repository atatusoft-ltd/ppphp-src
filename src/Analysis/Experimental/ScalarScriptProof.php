<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Experimental;

use Atatusoft\Ppphp\Analysis\CompilerProjectAnalysis;
use Atatusoft\Ppphp\Analysis\Declaration\DeclarationReferenceCollector;
use Atatusoft\Ppphp\Analysis\Enumerations\AnalysisCompleteness;
use Atatusoft\Ppphp\Frontend\ParsedFile;
use Atatusoft\Ppphp\Project\Project;
use Atatusoft\Ppphp\Project\ProjectParseResult;
use Atatusoft\Ppphp\Project\SourceSet;
use Atatusoft\Ppphp\Project\ProjectCheckResult;
use Atatusoft\Ppphp\Semantic\Binding\Enumerations\BindingMutability;
use Atatusoft\Ppphp\Semantic\Type\TypedArrayType;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;

/** Local browser experiment for a closed subset of top-level scripts. */
final readonly class ScalarScriptProof
{
    private const array AMBIENT_VARIABLES = [
        'GLOBALS', '_SERVER', '_GET', '_POST', '_FILES', '_COOKIE', '_SESSION', '_REQUEST', '_ENV',
        'this', 'argc', 'argv', 'http_response_header', 'php_errormsg',
    ];

    public function check(CompilerProjectAnalysis $analysis): ?ProjectCheckResult
    {
        if (!$this->qualifies($analysis)) {
            return null;
        }

        return new ProjectCheckResult(
            $analysis->parseResult,
            $analysis->semanticResult,
            null,
            $analysis->diagnostics,
            completeness: AnalysisCompleteness::ExperimentalScalarProof,
            declarationContext: $analysis->declarationContext,
        );
    }

    public function canAnalyzeLocally(Project $project, SourceSet $selected, ProjectParseResult $parsed): bool
    {
        $file = $this->findCandidate($project, $selected, $parsed);
        if ($file === null) {
            return false;
        }

        foreach ($file->statements as $statement) {
            if (!$statement instanceof Stmt\Nop && !$statement instanceof Stmt\Echo_
                && (!$statement instanceof Stmt\Expression || !$statement->expr instanceof Expr\Assign)) {
                return false;
            }
        }

        $references = (new DeclarationReferenceCollector())->collect([$file]);

        return $references['classes'] === [] && $references['functions'] === [] && $references['constants'] === [];
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

    private function qualifies(CompilerProjectAnalysis $analysis): bool
    {
        if (!$analysis->isSuccessful || $analysis->uncoveredRequiredCapabilities !== []) {
            return false;
        }

        $parsed = $this->findCandidate($analysis->project, $analysis->selectedSources, $analysis->parseResult);
        $model = $parsed === null ? null : $analysis->semanticResult?->findModel($parsed->sourceFile->path);
        if ($parsed === null || $model === null) {
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

        $assigned = [];
        $arrays = [];
        $reads = [];
        $printed = false;
        $appended = false;
        foreach ($parsed->statements as $statement) {
            if ($statement instanceof Stmt\Nop) {
                continue;
            }
            if ($statement instanceof Stmt\Echo_) {
                foreach ($statement->exprs as $expression) {
                    if ($this->resolveExpression($expression, $assigned, $reads, 32) === null) {
                        return false;
                    }
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
                    $value = $this->resolveExpression($assignment->expr, $assigned, $reads, 32);
                    if ($value === null || $type->valueType->canonical !== get_debug_type($value)) {
                        return false;
                    }
                    $appended = true;
                } else {
                    if (isset($arrays[$name]) || $binding->initializerExpression !== $assignment->expr
                        || !$assignment->expr instanceof Expr\Array_ || !$this->qualifiesArrayLiteral($assignment->expr, $type)) {
                        return false;
                    }
                    $arrays[$name] = true;
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
                $value = $this->resolveExpression($assignment->expr, $assigned, $reads, 32);
            } else {
                // Initializers also determine the generated @var annotation.
                // Only the already verified exact literal cases are admitted.
                if ($binding->initializerExpression !== $assignment->expr) {
                    return false;
                }
                $value = $this->resolveLiteral($assignment->expr);
            }
            if ($value === null || $type->canonical !== get_debug_type($value)) {
                return false;
            }
            $assigned[$name] = $value;
        }

        return ($printed || $appended) && count($assigned) + count($arrays) === count($declarations)
            && count($reads) === count($assigned);
    }

    private function qualifiesArrayLiteral(Expr\Array_ $literal, TypedArrayType $type): bool
    {
        if ($literal->items === [] || count($literal->items) > 256) {
            return false;
        }

        $keys = [];
        foreach ($literal->items as $item) {
            if ($item->byRef || $item->unpack) {
                return false;
            }
            $value = $this->resolveLiteral($item->value);
            if ($value === null || $type->valueType->canonical !== get_debug_type($value)) {
                return false;
            }
            if ($type->isList) {
                if ($item->key !== null) {
                    return false;
                }
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
                || isset($keys[$key])) {
                return false;
            }
            $keys[$key] = true;
        }

        return true;
    }

    /**
     * @param array<string, bool|float|int|string> $assigned
     * @param array<string, true> $reads
     */
    private function resolveExpression(Expr $expression, array $assigned, array &$reads, int $depth): bool|float|int|string|null
    {
        if ($depth === 0) {
            return null;
        }
        if ($expression instanceof Expr\Variable && is_string($expression->name) && isset($assigned[$expression->name])) {
            $reads[$expression->name] = true;
            return $assigned[$expression->name];
        }
        if (!$expression instanceof Expr\BinaryOp) {
            return $this->resolveLiteral($expression);
        }

        $left = $this->resolveExpression($expression->left, $assigned, $reads, $depth - 1);
        $right = $this->resolveExpression($expression->right, $assigned, $reads, $depth - 1);
        if ($expression instanceof Expr\BinaryOp\Concat) {
            // String contents are irrelevant to the proof and can grow
            // exponentially with reassignment. Retain their type only.
            return is_string($left) && is_string($right) ? '' : null;
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

    private function resolveLiteral(Expr $expression): bool|float|int|string|null
    {
        return match (true) {
            $expression instanceof Scalar\String_ => '',
            $expression instanceof Scalar\Int_ => $expression->value,
            $expression instanceof Scalar\Float_ && is_finite($expression->value) => $expression->value,
            $expression instanceof Expr\ConstFetch && $expression->name->isUnqualified()
                && in_array(strtolower($expression->name->toString()), ['true', 'false'], true) =>
                    strtolower($expression->name->toString()) === 'true',
            default => null,
        };
    }
}
