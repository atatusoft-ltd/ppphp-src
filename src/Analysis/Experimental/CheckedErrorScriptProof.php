<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Experimental;

use Atatusoft\Ppphp\Analysis\Declaration\DeclarationOrigin;
use Atatusoft\Ppphp\Semantic\SemanticModel;
use Atatusoft\Ppphp\Semantic\Symbol\SymbolTable;
use PhpParser\Modifiers;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;

/**
 * Positive evidence for a closed script with a guarded scalar callable and
 * local checked errors. Ordinary semantic analysis must already have passed.
 */
final readonly class CheckedErrorScriptProof
{
    public function check(SemanticModel $model, SymbolTable $symbols): bool
    {
        $syntax = $model->parsedFile->extensionSyntax;
        if (count($syntax->throwsClauses) !== 1 || count($syntax->typedLocals) !== 1
            || $syntax->typedForeachBindings !== [] || $syntax->typedForInitializers !== []
            || $syntax->genericDeclarations !== [] || $syntax->genericTypes !== []
            || $model->whenExpressions->expressions !== []) {
            return false;
        }

        $parent = $symbols->findClass('Exception');
        $constructor = $parent?->findMethod('__construct');
        if ($parent?->sourceFile->declarationOrigin !== DeclarationOrigin::PhpPlatform
            || $constructor === null || $constructor->visibility !== 'public'
            || array_any($constructor->parameters, static fn ($parameter): bool => !$parameter->hasDefault)) {
            return false;
        }

        $statements = array_values(array_filter($model->parsedFile->statements, static fn (Stmt $stmt): bool => !$stmt instanceof Stmt\Nop));
        $try = array_pop($statements);
        $function = array_pop($statements);
        if (!$try instanceof Stmt\TryCatch || !$function instanceof Stmt\Function_ || $statements === []) {
            return false;
        }

        $exceptions = [];
        foreach ($statements as $class) {
            if (!$class instanceof Stmt\Class_ || $class->name === null || $class->flags !== Modifiers::FINAL
                || $class->extends?->toCodeString() !== 'Exception' || $class->implements !== []
                || $class->attrGroups !== [] || $class->stmts !== []) {
                return false;
            }
            $exceptions[] = $class->name->toString();
        }

        $clause = $syntax->throwsClauses[0];
        $contract = $model->errorContracts->find($model->parsedFile->sourceFile, $clause);
        $declared = $contract?->declaredErrors->types ?? [];
        if ($clause->ownerNameSpan->text !== $function->name->toString() || $declared === []
            || array_diff($declared, $exceptions) !== [] || array_diff($exceptions, $declared) !== []) {
            return false;
        }

        $returnType = $this->proveCallable($function, $declared);

        return $returnType !== null && $this->proveCall($try, $function->name->toString(), $returnType, $declared, $model);
    }

    /** @param list<string> $declared */
    private function proveCallable(Stmt\Function_ $function, array $declared): ?string
    {
        if ($function->byRef || $function->attrGroups !== [] || count($function->params) !== 1
            || count($function->stmts) !== 2 || !$function->returnType instanceof Identifier) {
            return null;
        }
        $parameter = $function->params[0];
        $type = $function->returnType->toString();
        $name = $this->resolveLocalName($parameter->var);
        if ($name === null || !$parameter->type instanceof Identifier || $parameter->type->toString() !== $type
            || $parameter->byRef || $parameter->variadic || $parameter->default !== null || $parameter->attrGroups !== []) {
            return null;
        }

        [$guard, $return] = $function->stmts;
        if (!$guard instanceof Stmt\If_ || !$guard->cond instanceof Expr\BinaryOp\Identical
            || $guard->else !== null || $guard->elseifs !== [] || count($guard->stmts) !== 1
            || $this->resolveLocalName($guard->cond->left) !== $name
            || !$return instanceof Stmt\Return_ || $this->resolveLocalName($return->expr) !== $name) {
            return null;
        }

        $compared = ScriptExpressionProof::resolveLiteral($guard->cond->right);
        $body = $guard->stmts[0];
        if ($compared === null || get_debug_type($compared) !== $type
            || !$body instanceof Stmt\Expression || !$body->expr instanceof Expr\Throw_
            || !$body->expr->expr instanceof Expr\New_) {
            return null;
        }
        $exception = $body->expr->expr;

        return $exception->class instanceof Name && $exception->args === []
            && in_array($exception->class->toCodeString(), $declared, true) ? $type : null;
    }

    /** @param list<string> $declared */
    private function proveCall(Stmt\TryCatch $try, string $function, string $type, array $declared, SemanticModel $model): bool
    {
        if ($try->finally !== null || count($try->stmts) < 2) {
            return false;
        }
        $body = $try->stmts;
        $assignment = array_shift($body);
        if (!$assignment instanceof Stmt\Expression || !$assignment->expr instanceof Expr\Assign
            || !$assignment->expr->expr instanceof Expr\FuncCall) {
            return false;
        }
        $name = $this->resolveLocalName($assignment->expr->var);
        $binding = $model->bindings->find($model->parsedFile->extensionSyntax->typedLocals[0]->id);
        $call = $assignment->expr->expr;
        if ($name === null || $binding === null || $binding->name !== '$' . $name
            || $binding->type->semanticType->canonical !== $type || $binding->initializerExpression !== $call
            || !$call->name instanceof Name || $call->name->toCodeString() !== $function || count($call->args) !== 1) {
            return false;
        }
        $arg = $call->args[0];
        if (!$arg instanceof Arg || $arg->name !== null || $arg->unpack || $arg->byRef) {
            return false;
        }
        $value = ScriptExpressionProof::resolveLiteral($arg->value);
        if ($value === null || get_debug_type($value) !== $type || !$this->proveEchoes($body, $name)) {
            return false;
        }

        // Callers must catch the full declared contract, including conservative
        // declarations. A catch outside that contract is a separate diagnostic.
        $remaining = array_fill_keys($declared, true);
        foreach ($try->catches as $catch) {
            $catchName = $this->resolveLocalName($catch->var);
            if (($catch->var !== null && ($catchName === null || $catchName === $name))
                || !$this->proveEchoes($catch->stmts, null)) {
                return false;
            }
            foreach ($catch->types as $error) {
                $caught = $error->toCodeString();
                if (!isset($remaining[$caught])) {
                    return false;
                }
                unset($remaining[$caught]);
            }
        }

        return $remaining === [];
    }

    /** @param array<Stmt> $statements */
    private function proveEchoes(array $statements, ?string $name): bool
    {
        foreach ($statements as $statement) {
            if (!$statement instanceof Stmt\Echo_) {
                return false;
            }
            foreach ($statement->exprs as $expression) {
                if (ScriptExpressionProof::resolveLiteral($expression) === null
                    && ($name === null || $this->resolveLocalName($expression) !== $name)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function resolveLocalName(?Expr $expression): ?string
    {
        return $expression instanceof Expr\Variable && is_string($expression->name)
            && !in_array($expression->name, ScriptExpressionProof::AMBIENT_VARIABLES, true) ? $expression->name : null;
    }
}
