<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Experimental;

use Atatusoft\Ppphp\Analysis\CompilerProjectAnalysis;
use Atatusoft\Ppphp\Analysis\Enumerations\AnalysisCompleteness;
use Atatusoft\Ppphp\Project\ProjectCheckResult;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;

/** Local browser experiment for a closed subset of top-level scalar scripts. */
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

    private function qualifies(CompilerProjectAnalysis $analysis): bool
    {
        $project = $analysis->project;
        if (!$analysis->isSuccessful || $analysis->uncoveredRequiredCapabilities !== []
            || count($project->sources) !== 1 || count($analysis->selectedSources->ppphpFiles) !== 1
            || $project->configuration->targetPhpVersion !== '8.4'
            || count($project->stubs) !== 0 || $project->composer->dependencies !== []) {
            return false;
        }

        $source = $analysis->selectedSources->ppphpFiles->files[0];
        $parsed = $analysis->parseResult->findParsedFile($source->path);
        $model = $analysis->semanticResult?->findModel($source->path);
        if ($parsed === null || $model === null || strlen($parsed->sourceFile->contents) > 16_384
            || count($parsed->statements) > 64) {
            return false;
        }

        foreach (\PhpToken::tokenize($parsed->normalizedSource->contents) as $token) {
            if ($token->id === T_DOC_COMMENT) {
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
        $reads = [];
        $printed = false;
        foreach ($parsed->statements as $statement) {
            if ($statement instanceof Stmt\Echo_) {
                foreach ($statement->exprs as $expression) {
                    if ($this->resolveEchoType($expression, $assigned, $reads, 32) === null) {
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
            if (!$assignment->var instanceof Expr\Variable || !is_string($assignment->var->name)) {
                return false;
            }
            $name = $assignment->var->name;
            if (in_array($name, self::AMBIENT_VARIABLES, true)) {
                return false;
            }
            $binding = $declarations['$' . $name] ?? null;
            $literal = $this->resolveLiteralType($assignment->expr);
            if (isset($assigned[$name]) || $binding === null || $literal === null
                || $binding->initializerExpression !== $assignment->expr || $binding->type->semanticType->canonical !== $literal) {
                return false;
            }
            $assigned[$name] = $literal;
        }

        return $printed && count($assigned) === count($declarations) && count($reads) === count($assigned);
    }

    /** @param array<string,string> $assigned @param array<string,true> $reads */
    private function resolveEchoType(Expr $expression, array $assigned, array &$reads, int $depth): ?string
    {
        if ($depth === 0) {
            return null;
        }
        if ($expression instanceof Expr\Variable && is_string($expression->name) && isset($assigned[$expression->name])) {
            $reads[$expression->name] = true;
            return $assigned[$expression->name];
        }
        if ($expression instanceof Expr\BinaryOp\Concat) {
            return $this->resolveEchoType($expression->left, $assigned, $reads, $depth - 1) === 'string'
                && $this->resolveEchoType($expression->right, $assigned, $reads, $depth - 1) === 'string' ? 'string' : null;
        }
        return $this->resolveLiteralType($expression);
    }

    private function resolveLiteralType(Expr $expression): ?string
    {
        return match (true) {
            $expression instanceof Scalar\String_ => 'string',
            $expression instanceof Scalar\Int_ => 'int',
            $expression instanceof Scalar\Float_ && is_finite($expression->value) => 'float',
            $expression instanceof Expr\ConstFetch && $expression->name->isUnqualified()
                && in_array(strtolower($expression->name->toString()), ['true', 'false'], true) => 'bool',
            default => null,
        };
    }
}
