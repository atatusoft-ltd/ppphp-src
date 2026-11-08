<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Experimental;

use Atatusoft\Ppphp\Analysis\CompilerProjectAnalysis;
use Atatusoft\Ppphp\Compiler\Output\OutputPathResolver;
use Atatusoft\Ppphp\Project\ProjectSource;
use Atatusoft\Ppphp\Semantic\SemanticAnalysisResult;
use Atatusoft\Ppphp\Semantic\SemanticModel;
use Atatusoft\Ppphp\Semantic\Type\GenericType;
use Atatusoft\Ppphp\Semantic\Type\MemberTypeResolver;
use Atatusoft\Ppphp\Semantic\Type\TypeParameter;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Transpilation\Pass\RelocateComposerAutoloadPass;
use PhpParser\Modifiers;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;

/**
 * Positive evidence for closed scripts that construct and read final data records.
 *
 * @phpstan-import-type Records from ScriptExpressionProof
 */
final readonly class RecordScriptProof
{
    public function check(CompilerProjectAnalysis $analysis, SemanticModel $model): bool
    {
        $semantic = $analysis->semanticResult;
        $entry = $analysis->selectedSources->find($model->parsedFile->sourceFile->path);
        if ($semantic === null || $entry === null) {
            return false;
        }
        $phpFiles = $analysis->selectedSources->phpFiles;
        $included = [];
        $declarations = [];
        foreach ($model->parsedFile->extensionSyntax->typedLocals as $local) {
            $binding = $model->bindings->find($local->id);
            if ($binding === null || isset($declarations[$binding->name])) {
                return false;
            }
            $declarations[$binding->name] = $binding;
        }

        $members = new MemberTypeResolver($semantic->symbols);
        $classes = [];
        $usedClasses = [];
        /** @var Records $records */
        $records = [];
        $scalars = [];
        $reads = [];
        $steps = 8192;
        foreach ($model->parsedFile->statements as $statement) {
            if ($statement instanceof Stmt\Nop) {
                continue;
            }
            if ($statement instanceof Stmt\Class_) {
                if (!$this->registerClass($statement, $entry, $semantic, $classes)) {
                    return false;
                }
                continue;
            }
            if ($statement instanceof Stmt\Expression && $statement->expr instanceof Expr\Include_) {
                $source = $this->resolveInclude($statement->expr, $entry, $analysis);
                if ($source === null || isset($included[$source->path])) {
                    return false;
                }
                $file = $analysis->parseResult->findParsedFile($source->path);
                if ($file === null) {
                    return false;
                }
                foreach ($file->statements as $includedStatement) {
                    if ($includedStatement instanceof Stmt\Nop) {
                        continue;
                    }
                    if (!$includedStatement instanceof Stmt\Class_
                        || !$this->registerClass($includedStatement, $source, $semantic, $classes)) {
                        return false;
                    }
                }
                $included[$source->path] = true;
                continue;
            }
            if ($statement instanceof Stmt\Echo_) {
                foreach ($statement->exprs as $expression) {
                    if (ScriptExpressionProof::resolve($expression, $scalars, [], $reads, $steps, 32, $records) === null) {
                        return false;
                    }
                }
                continue;
            }

            if (!$statement instanceof Stmt\Expression || !$statement->expr instanceof Expr\Assign
                || !$statement->expr->var instanceof Expr\Variable || !is_string($statement->expr->var->name)) {
                return false;
            }
            $variable = $statement->expr->var->name;
            $initializer = $statement->expr->expr;
            $binding = $declarations['$' . $variable] ?? null;
            if ($binding === null || $binding->initializerExpression !== $initializer
                || isset($records[$variable]) || isset($scalars[$variable])
                || in_array($variable, ScriptExpressionProof::AMBIENT_VARIABLES, true)) {
                return false;
            }

            if ($initializer instanceof Expr\PropertyFetch) {
                $value = ScriptExpressionProof::resolve($initializer, $scalars, [], $reads, $steps, 32, $records);
                if ($value === null || ScriptExpressionProof::resolveType($value) !== $binding->type->semanticType->canonical) {
                    return false;
                }
                $scalars[$variable] = $value;
                continue;
            }
            if (!$initializer instanceof Expr\New_ || !$initializer->class instanceof Name) {
                return false;
            }
            $class = strtolower($initializer->class->toString());
            if (!isset($classes[$class])) {
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

        return $records !== [] && count($records) + count($scalars) === count($declarations)
            && count($reads) === count($declarations) && count($usedClasses) === count($classes)
            && count($included) === count($phpFiles);
    }

    private function resolveInclude(Expr\Include_ $include, ProjectSource $entry, CompilerProjectAnalysis $analysis): ?ProjectSource
    {
        $path = $include->expr;
        if ($include->type !== Expr\Include_::TYPE_REQUIRE_ONCE || !$path instanceof Expr\BinaryOp\Concat
            || !$path->left instanceof Scalar\MagicConst\Dir || !$path->right instanceof Scalar\String_) {
            return null;
        }

        $suffix = $path->right->value;
        if (!str_starts_with($suffix, '/') || str_contains($suffix, '\\') || str_contains($suffix, "\0")
            || array_any(explode('/', substr($suffix, 1)), static fn (string $part): bool => in_array($part, ['', '.', '..'], true))) {
            return null;
        }

        $sourcePath = dirname($entry->path) . $suffix;
        $source = $analysis->selectedSources->find($sourcePath);
        if ($source === null || $source->kind !== FileKind::Php || $source->path !== $sourcePath) {
            return null;
        }

        $outputs = new OutputPathResolver();
        $configuration = $analysis->project->configuration;
        $entryOutput = $outputs->resolve($configuration, $entry);
        $relocation = new RelocateComposerAutoloadPass($analysis->project->composer, $entryOutput);
        if ($relocation->referencesComposerAutoload($path, $entry->path)) {
            return null;
        }

        return dirname($entryOutput) . $suffix === $outputs->resolve($configuration, $source) ? $source : null;
    }

    /** @param array<string, non-empty-list<string>> $classes */
    private function registerClass(Stmt\Class_ $class, ProjectSource $source, SemanticAnalysisResult $analysis, array &$classes): bool
    {
        if ($class->name === null) {
            return false;
        }
        $name = strtolower($class->name->toString());
        $properties = $this->resolveProperties($class, $source, $analysis);
        if ($properties === null || isset($classes[$name])) {
            return false;
        }
        $classes[$name] = $properties;

        return true;
    }

    /** @return non-empty-list<string>|null */
    private function resolveProperties(Stmt\Class_ $class, ProjectSource $source, SemanticAnalysisResult $analysis): ?array
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
        if ($symbol === null || $symbol->sourceFile->path !== $source->path || array_any($symbol->genericDeclaration->parameters ?? [], static fn (TypeParameter $type): bool => $type->bound !== null)) {
            return null;
        }
        $properties = [];
        foreach ($constructor->params as $parameter) {
            $readonly = $parameter->flags === (Modifiers::PUBLIC | Modifiers::READONLY);
            $closedMutable = $source->kind === FileKind::Php && $parameter->flags === Modifiers::PUBLIC;
            if ((!$readonly && !$closedMutable) || $parameter->byRef || $parameter->variadic
                || $parameter->default !== null || $parameter->hooks !== [] || $parameter->attrGroups !== []
                || !$parameter->var instanceof Expr\Variable || !is_string($parameter->var->name)) {
                return null;
            }
            $name = $parameter->var->name;
            $property = $symbol->findProperty($name);
            if ($property === null || !$property->promoted || $property->readonly !== $readonly || !$property->hasBackingStorage
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
