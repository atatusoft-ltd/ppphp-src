<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Transpilation\Pass;

use Atatusoft\Ppphp\Frontend\Ast\GenericDeclaration;
use Atatusoft\Ppphp\Frontend\Ast\GenericType as SourceGenericType;
use Atatusoft\Ppphp\Frontend\Ast\ThrowsClause;
use Atatusoft\Ppphp\Semantic\Type\AtomicType;
use Atatusoft\Ppphp\Semantic\Type\CompositeTypeParser;
use Atatusoft\Ppphp\Semantic\Type\GenericType;
use Atatusoft\Ppphp\Semantic\Type\IntersectionType;
use Atatusoft\Ppphp\Semantic\Type\TypeParameter;
use Atatusoft\Ppphp\Semantic\Type\TypedArrayType;
use Atatusoft\Ppphp\Semantic\Type\UnionType;
use Atatusoft\Ppphp\Semantic\Type\Interfaces\Type;
use Atatusoft\Ppphp\Source\Span;
use Atatusoft\Ppphp\Transpilation\PhpDocEmitter;
use Atatusoft\Ppphp\Transpilation\Pass\Interfaces\TranspilationPass;
use Atatusoft\Ppphp\Transpilation\TranspilationContext;
use Atatusoft\Ppphp\Transpilation\ThrowsClauseEraser;
use PhpParser\Node;
use PhpParser\Node\Stmt;

final class EraseGenericTypesPass implements TranspilationPass
{
    /** @var array<int, GenericDeclaration> */
    private array $declarations = [];

    /** @var array<int, SourceGenericType> */
    private array $references = [];

    /** @var array<int, ThrowsClause> */
    private array $throwsClauses = [];

    public function __construct(
        private readonly CompositeTypeParser $types = new CompositeTypeParser(),
        private readonly PhpDocEmitter $phpDoc = new PhpDocEmitter(),
    ) {}

    public function execute(TranspilationContext $context): void
    {
        $this->declarations = [];
        $this->references = [];
        $this->throwsClauses = [];

        foreach ($context->parsedFile->extensionSyntax->genericDeclarations as $declaration) {
            $this->declarations[$declaration->ownerNameSpan->start->offset] = $declaration;
            $context->replace($declaration->span, '');
        }

        foreach ($context->parsedFile->extensionSyntax->genericTypes as $reference) {
            $this->references[$reference->nameSpan->start->offset] = $reference;
        }

        foreach ($context->parsedFile->extensionSyntax->throwsClauses as $clause) {
            $this->throwsClauses[$clause->ownerNameSpan->start->offset] = $clause;
        }

        foreach ($context->parsedFile->statements as $statement) {
            $this->processStatement($statement, $context, []);
        }
    }

    /** @param array<string, TypeParameter> $visibleParameters */
    private function processStatement(
        Stmt $statement,
        TranspilationContext $context,
        array $visibleParameters,
    ): void {
        if ($statement instanceof Stmt\Namespace_) {
            foreach ($statement->stmts as $nested) {
                $this->processStatement($nested, $context, $visibleParameters);
            }

            return;
        }

        if ($statement instanceof Stmt\Function_) {
            $this->processCallable($statement, $context, $visibleParameters);

            return;
        }

        if (!$statement instanceof Stmt\ClassLike || $statement->name === null) {
            $this->processNestedCallables($statement, $context, $visibleParameters);

            return;
        }

        $declaration = $this->declarations[$statement->name->getStartFilePos()] ?? null;
        $classParameters = array_replace($visibleParameters, $this->resolveParameters($declaration));
        $tags = $this->renderTemplateTags($declaration);

        if ($statement instanceof Stmt\Class_ && $statement->extends !== null) {
            $this->processInheritanceType($statement->extends, '@extends', $tags, $context, $classParameters);
        }

        if ($statement instanceof Stmt\Class_) {
            foreach ($statement->implements as $interface) {
                $this->processInheritanceType($interface, '@implements', $tags, $context, $classParameters);
            }
        } elseif ($statement instanceof Stmt\Interface_) {
            foreach ($statement->extends as $interface) {
                $this->processInheritanceType($interface, '@extends', $tags, $context, $classParameters);
            }
        }

        if ($tags !== []) {
            $this->phpDoc->emitTags($context, $statement, $tags);
        }

        foreach ($statement->stmts as $member) {
            if ($member instanceof Stmt\ClassMethod) {
                $this->processCallable($member, $context, $classParameters);
            } elseif ($member instanceof Stmt\Property) {
                $this->processProperty($member, $context, $classParameters);
                $this->processNestedCallables($member, $context, $classParameters);
            } elseif ($member instanceof Stmt\TraitUse) {
                $this->processTraitUse($member, $context, $classParameters);
            }
        }
    }

    /** @param array<string, TypeParameter> $outerParameters */
    private function processCallable(
        Stmt\Function_|Stmt\ClassMethod|Node\Expr\Closure|Node\Expr\ArrowFunction $callable,
        TranspilationContext $context,
        array $outerParameters,
    ): void {
        $declaration = $callable instanceof Stmt\Function_ || $callable instanceof Stmt\ClassMethod
            ? $this->declarations[$callable->name->getStartFilePos()] ?? null
            : null;
        $parameters = array_replace($outerParameters, $this->resolveParameters($declaration));
        $tags = $this->renderTemplateTags($declaration);

        foreach ($callable->params as $parameter) {
            if ($parameter->type === null || !$parameter->var instanceof Node\Expr\Variable || !is_string($parameter->var->name)) {
                continue;
            }

            $type = $this->resolveType($parameter->type, $parameters);

            if (!$this->containsExtensionType($type)) {
                continue;
            }

            $tags[] = sprintf('@param %s $%s', $type->renderPhpDoc(), $parameter->var->name);
            $context->replace($this->resolveTypeSpan($parameter->type, $context), $this->eraseType($type));
        }

        if ($callable->returnType !== null) {
            $returnType = $this->resolveType($callable->returnType, $parameters);

            if ($this->containsExtensionType($returnType)) {
                $tags[] = '@return ' . $returnType->renderPhpDoc();
                $context->replace($this->resolveTypeSpan($callable->returnType, $context), $this->eraseType($returnType));
            }
        }

        $clause = $callable instanceof Stmt\Function_ || $callable instanceof Stmt\ClassMethod
            ? $this->throwsClauses[$callable->name->getStartFilePos()] ?? null
            : null;

        if ($clause !== null) {
            $tags[] = $this->renderThrowsTag($clause, $context);
            (new ThrowsClauseEraser())->erase($clause, $context);
        }

        if ($tags !== []) {
            $this->phpDoc->emitTags($context, $callable, $tags);
        }

        if ($callable instanceof Node\Expr\ArrowFunction) {
            $this->processNestedCallables($callable->expr, $context, $parameters);

            return;
        }

        foreach ($callable->stmts ?? [] as $statement) {
            $this->processNestedCallables($statement, $context, $parameters);
        }
    }

    /** @param array<string, TypeParameter> $parameters */
    private function processNestedCallables(
        Node $node,
        TranspilationContext $context,
        array $parameters,
    ): void {
        if ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
            $this->processCallable($node, $context, $parameters);

            return;
        }

        foreach ($node->getSubNodeNames() as $subNodeName) {
            $value = $node->{$subNodeName};

            if ($value instanceof Node) {
                $this->processNestedCallables($value, $context, $parameters);
            } elseif (is_array($value)) {
                foreach ($value as $child) {
                    if ($child instanceof Node) {
                        $this->processNestedCallables($child, $context, $parameters);
                    }
                }
            }
        }
    }

    /** @param array<string, TypeParameter> $parameters */
    private function processProperty(
        Stmt\Property $property,
        TranspilationContext $context,
        array $parameters,
    ): void {
        $propertyType = $property->type;

        if ($propertyType === null) {
            return;
        }

        $type = $this->resolveType($propertyType, $parameters);

        if (!$this->containsExtensionType($type)) {
            return;
        }

        $this->phpDoc->emitTags($context, $property, ['@var ' . $type->renderPhpDoc()]);
        $context->replace($this->resolveTypeSpan($propertyType, $context), $this->eraseType($type));
    }

    /** @param array<string, TypeParameter> $parameters */
    private function processTraitUse(
        Stmt\TraitUse $use,
        TranspilationContext $context,
        array $parameters,
    ): void {
        $tags = [];

        foreach ($use->traits as $trait) {
            $this->processInheritanceType($trait, '@use', $tags, $context, $parameters);
        }

        if ($tags !== []) {
            $this->phpDoc->emitTags($context, $use, $tags);
        }
    }

    /**
     * @param list<string> $tags
     * @param array<string, TypeParameter> $parameters
     */
    private function processInheritanceType(
        Node\Name $node,
        string $tag,
        array &$tags,
        TranspilationContext $context,
        array $parameters,
    ): void {
        $type = $this->resolveType($node, $parameters);

        if (!$this->containsExtensionType($type)) {
            return;
        }

        $tags[] = $tag . ' ' . $type->renderPhpDoc();
        $context->replace($this->resolveTypeSpan($node, $context), $this->eraseType($type));
    }

    /** @return array<string, TypeParameter> */
    private function resolveParameters(?GenericDeclaration $declaration): array
    {
        if ($declaration === null) {
            return [];
        }

        $parameters = [];

        foreach ($declaration->parameters as $parameter) {
            $name = $parameter->nameSpan->text;
            $parameters[strtolower($name)] = new TypeParameter(
                $name,
                $parameter->bound === null ? null : $this->types->parse($parameter->bound->text),
                $declaration->id->value,
                $parameter->span,
            );
        }

        return $parameters;
    }

    /** @return list<string> */
    private function renderTemplateTags(?GenericDeclaration $declaration): array
    {
        if ($declaration === null) {
            return [];
        }

        return array_map(
            fn ($parameter): string => '@template ' . $parameter->nameSpan->text
                . ($parameter->bound === null ? '' : ' of ' . $this->types->parse($parameter->bound->text)->renderPhpDoc()),
            $declaration->parameters,
        );
    }

    /** @param array<string, TypeParameter> $parameters */
    private function resolveType(Node $node, array $parameters): Type
    {
        if ($node instanceof Node\Name || $node instanceof Node\Identifier) {
            $reference = $this->references[$node->getStartFilePos()] ?? null;

            if ($reference !== null) {
                return $this->resolveParsedType($this->types->parse($reference->span->text), $parameters);
            }

            return $parameters[strtolower($node->toString())] ?? new AtomicType($node->toString());
        }

        if ($node instanceof Node\NullableType) {
            return new UnionType([$this->resolveType($node->type, $parameters), new AtomicType('null')]);
        }

        if ($node instanceof Node\UnionType) {
            $members = array_map(
                fn (Node $member): Type => $this->resolveType($member, $parameters),
                $node->types,
            );

            return $members === []
                ? new \Atatusoft\Ppphp\Semantic\Type\UnknownType()
                : new UnionType(array_values($members));
        }

        if ($node instanceof Node\IntersectionType) {
            $members = array_map(
                fn (Node $member): Type => $this->resolveType($member, $parameters),
                $node->types,
            );

            return $members === []
                ? new \Atatusoft\Ppphp\Semantic\Type\UnknownType()
                : new IntersectionType(array_values($members));
        }

        throw new \LogicException(sprintf('Unsupported native type node %s.', $node::class));
    }

    /** @param array<string, TypeParameter> $parameters */
    private function resolveParsedType(Type $type, array $parameters): Type
    {
        if ($type instanceof AtomicType) {
            return $parameters[strtolower($type->name)] ?? $type;
        }

        if ($type instanceof GenericType) {
            return new GenericType(
                $type->base,
                array_map(
                    fn (Type $argument): Type => $this->resolveParsedType($argument, $parameters),
                    $type->arguments,
                ),
            );
        }

        if ($type instanceof TypedArrayType) {
            return new TypedArrayType(
                $this->resolveParsedType($type->keyType, $parameters),
                $this->resolveParsedType($type->valueType, $parameters),
                $type->isList,
            );
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            $members = array_map(
                fn (Type $member): Type => $this->resolveParsedType($member, $parameters),
                $type->members,
            );

            return $type instanceof UnionType ? new UnionType($members) : new IntersectionType($members);
        }

        return $type;
    }

    private function containsExtensionType(Type $type): bool
    {
        if ($type instanceof GenericType || $type instanceof TypedArrayType || $type instanceof TypeParameter) {
            return true;
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            foreach ($type->members as $member) {
                if ($this->containsExtensionType($member)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function eraseType(Type $type): string
    {
        if ($type instanceof TypeParameter) {
            return $type->bound === null ? 'mixed' : $this->eraseType($type->bound);
        }

        if ($type instanceof GenericType) {
            return $type->base->eraseToNative();
        }

        if ($type instanceof TypedArrayType) {
            return 'array';
        }

        if ($type instanceof UnionType) {
            $members = [];

            foreach ($type->members as $member) {
                $erased = $this->eraseType($member);

                if ($erased === 'mixed') {
                    return 'mixed';
                }

                $members[strtolower($erased)] = $member instanceof IntersectionType ? '(' . $erased . ')' : $erased;
            }

            ksort($members, SORT_STRING);

            return implode('|', $members);
        }

        if ($type instanceof IntersectionType) {
            $members = [];

            foreach ($type->members as $member) {
                $erased = $this->eraseType($member);

                if ($erased === 'mixed') {
                    return 'mixed';
                }

                $members[strtolower($erased)] = $erased;
            }

            ksort($members, SORT_STRING);

            return implode('&', $members);
        }

        return $type->eraseToNative();
    }

    private function resolveTypeSpan(Node $type, TranspilationContext $context): Span
    {
        $start = $type->getStartFilePos();
        $end = $type->getEndFilePos() + 1;

        foreach ($this->references as $reference) {
            if ($reference->nameSpan->start->offset >= $start && $reference->nameSpan->start->offset < $end) {
                $end = max($end, $reference->span->end->offset);
            }
        }

        return $context->parsedFile->sourceFile->createSpan($start, $end);
    }

    private function renderThrowsTag(ThrowsClause $clause, TranspilationContext $context): string
    {
        $contract = $context->semanticModel->errorContracts->find($context->parsedFile->sourceFile, $clause);

        if ($contract === null) {
            throw new \LogicException(sprintf('The throws clause owned by %s has no validated semantic contract.', $clause->ownerNameSpan->text));
        }

        $types = [];

        foreach ($contract->declaredErrors as $error) {
            $canonical = '\\' . ltrim($error->canonicalType, '\\');
            $types[strtolower($canonical)] = $canonical;
        }

        return '@throws ' . implode('|', array_values($types));
    }

}
