<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Experimental;

use Atatusoft\Ppphp\Semantic\SemanticAnalysisResult;
use Atatusoft\Ppphp\Semantic\SemanticModel;
use Atatusoft\Ppphp\Semantic\Type\GenericType;
use Atatusoft\Ppphp\Semantic\Type\MemberTypeResolver;
use Atatusoft\Ppphp\Semantic\Type\TypeParameter;
use PhpParser\Modifiers;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;

/**
 * Positive evidence for closed scripts that construct and read final data records.
 *
 * @phpstan-import-type Records from ScriptExpressionProof
 */
final readonly class RecordScriptProof
{
    public function check(SemanticAnalysisResult $analysis, SemanticModel $model): bool
    {
        $declarations = [];
        foreach ($model->parsedFile->extensionSyntax->typedLocals as $local) {
            $binding = $model->bindings->find($local->id);
            if ($binding === null || isset($declarations[$binding->name])) {
                return false;
            }
            $declarations[$binding->name] = $binding;
        }

        $members = new MemberTypeResolver($analysis->symbols);
        $classes = [];
        $usedClasses = [];
        /** @var Records $records */
        $records = [];
        $reads = [];
        $steps = 8192;
        foreach ($model->parsedFile->statements as $statement) {
            if ($statement instanceof Stmt\Nop) {
                continue;
            }
            if ($statement instanceof Stmt\Class_ && $statement->name !== null) {
                $name = strtolower($statement->name->toString());
                $properties = $this->resolveProperties($statement, $analysis);
                if ($properties === null || isset($classes[$name])) {
                    return false;
                }
                $classes[$name] = $properties;
                continue;
            }
            if ($statement instanceof Stmt\Echo_) {
                foreach ($statement->exprs as $expression) {
                    if (ScriptExpressionProof::resolve($expression, [], [], $reads, $steps, 32, $records) === null) {
                        return false;
                    }
                }
                continue;
            }

            if (!$statement instanceof Stmt\Expression || !$statement->expr instanceof Expr\Assign
                || !$statement->expr->var instanceof Expr\Variable || !is_string($statement->expr->var->name)
                || !$statement->expr->expr instanceof Expr\New_) {
                return false;
            }
            $variable = $statement->expr->var->name;
            $initializer = $statement->expr->expr;
            if (!$initializer->class instanceof Name) {
                return false;
            }
            $class = strtolower($initializer->class->toString());
            $binding = $declarations['$' . $variable] ?? null;
            if ($binding === null || $binding->initializerExpression !== $initializer
                || !isset($classes[$class]) || isset($records[$variable])
                || in_array($variable, ScriptExpressionProof::AMBIENT_VARIABLES, true)) {
                return false;
            }

            $receiver = $binding->type->semanticType;
            $base = $receiver instanceof GenericType ? $receiver->base->canonical : $receiver->canonical;
            if ($base !== $class || $receiver->canonical !== $binding->initializerType->semanticType->canonical
                || $initializer->isFirstClassCallable()) {
                return false;
            }
            $arguments = $initializer->getArgs();
            $properties = $classes[$class];
            if (count($arguments) !== count($properties)) {
                return false;
            }
            $values = [];
            foreach ($arguments as $index => $argument) {
                $value = ScriptExpressionProof::resolveLiteral($argument->value);
                $property = $properties[$index];
                if ($argument->name !== null || $argument->byRef || $argument->unpack || $value === null
                    || $members->resolvePropertyType($receiver, $property)->canonical !== get_debug_type($value)) {
                    return false;
                }
                $values[$property] = $value;
            }
            $records[$variable] = $values;
            $usedClasses[$class] = true;
        }

        return $records !== [] && count($records) === count($declarations)
            && count($reads) === count($records) && count($usedClasses) === count($classes);
    }

    /** @return non-empty-list<string>|null */
    private function resolveProperties(Stmt\Class_ $class, SemanticAnalysisResult $analysis): ?array
    {
        if ($class->name === null || $class->flags !== Modifiers::FINAL || $class->extends !== null
            || $class->implements !== [] || $class->attrGroups !== [] || count($class->stmts) !== 1) {
            return null;
        }
        $constructor = $class->stmts[0];
        if (!$constructor instanceof Stmt\ClassMethod || strcasecmp($constructor->name->toString(), '__construct') !== 0
            || !$constructor->isPublic() || $constructor->isStatic() || $constructor->isAbstract() || $constructor->byRef
            || $constructor->returnType !== null || $constructor->attrGroups !== [] || $constructor->stmts !== []
            || $constructor->params === []) {
            return null;
        }

        $symbol = $analysis->symbols->findClass($class->name->toString());
        if ($symbol === null || array_any($symbol->genericDeclaration->parameters ?? [], static fn (TypeParameter $type): bool => $type->bound !== null)) {
            return null;
        }
        $properties = [];
        foreach ($constructor->params as $parameter) {
            if ($parameter->flags !== (Modifiers::PUBLIC | Modifiers::READONLY) || $parameter->byRef || $parameter->variadic
                || $parameter->default !== null || $parameter->hooks !== [] || $parameter->attrGroups !== []
                || !$parameter->var instanceof Expr\Variable || !is_string($parameter->var->name)) {
                return null;
            }
            $name = $parameter->var->name;
            $property = $symbol->findProperty($name);
            if ($property === null || !$property->promoted || !$property->readonly || !$property->hasBackingStorage
                || $property->hasGetter || $property->hasSetter || $property->static || $property->visibility !== 'public') {
                return null;
            }
            $type = $property->effectiveType();
            if ($type instanceof TypeParameter) {
                if ($symbol->genericDeclaration?->findParameter($type->name)?->canonical !== $type->canonical) {
                    return null;
                }
            } elseif (!in_array($type?->canonical, ['int', 'string', 'bool', 'float'], true)) {
                return null;
            }
            $properties[] = $name;
        }

        return $properties;
    }
}
