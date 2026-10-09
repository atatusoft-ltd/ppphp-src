<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Type;

use Atatusoft\Ppphp\Frontend\Ast\SourceType;
use Atatusoft\Ppphp\Frontend\ParsedFile;
use Atatusoft\Ppphp\Semantic\Generic\GenericDeclarationIndex;
use Atatusoft\Ppphp\Semantic\NodeSpanResolver;
use Atatusoft\Ppphp\Semantic\ResolvedNameTable;
use Atatusoft\Ppphp\Semantic\SourceNameResolver;
use Atatusoft\Ppphp\Semantic\Type\Interfaces\Type;
use PhpParser\Node;

/** Resolves source syntax directly into the compiler's authoritative type model. */
final readonly class SourceTypeResolver
{
    public function __construct(
        private CompositeTypeParser $types = new CompositeTypeParser(),
        private SourceNameResolver $names = new SourceNameResolver(),
        private NodeSpanResolver $spans = new NodeSpanResolver(),
    ) {}

    /** @param array<string, TypeParameter> $localParameters */
    public function resolveSourceType(
        SourceType $sourceType,
        ParsedFile $parsedFile,
        GenericDeclarationIndex $genericDeclarations,
        array $localParameters = [],
    ): Type {
        return $this->resolveParsedType(
            $this->types->parse($sourceType->text),
            $parsedFile,
            $sourceType->span->start->offset,
            $genericDeclarations,
            $localParameters,
        );
    }

    /** @param array<string, TypeParameter> $localParameters */
    public function resolveNode(
        Node $node,
        ParsedFile $parsedFile,
        ResolvedNameTable $resolvedNames,
        GenericDeclarationIndex $genericDeclarations,
        array $localParameters = [],
    ): Type {
        if ($node instanceof Node\Identifier) {
            $offset = $this->spans->resolve($parsedFile, $node)->start->offset;
            $applied = $this->findAppliedType($parsedFile, $offset);

            if ($applied !== null) {
                return $this->resolveParsedType(
                    $this->types->parse($applied->span->text),
                    $parsedFile,
                    $offset,
                    $genericDeclarations,
                    $localParameters,
                );
            }

            return new AtomicType($node->toString());
        }

        if ($node instanceof Node\Name) {
            $offset = $this->spans->resolve($parsedFile, $node)->start->offset;
            $applied = $this->findAppliedType($parsedFile, $offset);

            if ($applied !== null) {
                return $this->resolveParsedType(
                    $this->types->parse($applied->span->text),
                    $parsedFile,
                    $offset,
                    $genericDeclarations,
                    $localParameters,
                );
            }

            $parameter = $node->isFullyQualified() || $node->isRelative() ? null : ($localParameters[strtolower($node->toString())]
                ?? $genericDeclarations->findVisibleParameter(
                    $parsedFile->sourceFile,
                    $offset,
                    $node->toString(),
                ));

            if ($parameter !== null) {
                return $parameter;
            }

            return $this->resolveAtomicType(
                new AtomicType(
                    $resolvedNames->resolve($node) ?? $node->toCodeString(),
                    $node->isFullyQualified() || $resolvedNames->resolve($node) !== null,
                ),
                $parsedFile,
                $offset,
                $genericDeclarations,
                $localParameters,
                $resolvedNames->resolve($node) !== null,
            );
        }

        if ($node instanceof Node\NullableType) {
            return new UnionType([
                $this->resolveNode($node->type, $parsedFile, $resolvedNames, $genericDeclarations, $localParameters),
                new AtomicType('null'),
            ]);
        }

        if ($node instanceof Node\UnionType || $node instanceof Node\IntersectionType) {
            $members = array_values(array_map(
                fn (Node $member): Type => $this->resolveNode(
                    $member,
                    $parsedFile,
                    $resolvedNames,
                    $genericDeclarations,
                    $localParameters,
                ),
                $node->types,
            ));

            if ($members === []) {
                return new UnknownType();
            }

            return $node instanceof Node\UnionType
                ? new UnionType($members)
                : new IntersectionType($members);
        }

        return new UnknownType();
    }

    /** @param array<string, TypeParameter> $localParameters */
    public function resolveParsedType(
        Type $type,
        ParsedFile $parsedFile,
        int $offset,
        GenericDeclarationIndex $genericDeclarations,
        array $localParameters = [],
    ): Type {
        if ($type instanceof AtomicType) {
            return $this->resolveAtomicType(
                $type,
                $parsedFile,
                $offset,
                $genericDeclarations,
                $localParameters,
            );
        }

        if ($type instanceof TypeParameter) {
            return $type;
        }

        if ($type instanceof GenericType) {
            $base = $this->resolveAtomicType(
                $type->base,
                $parsedFile,
                $offset,
                $genericDeclarations,
                $localParameters,
            );

            return new GenericType(
                $base instanceof AtomicType ? $base : $type->base,
                array_map(
                    fn (Type $argument): Type => $this->resolveParsedType(
                        $argument,
                        $parsedFile,
                        $offset,
                        $genericDeclarations,
                        $localParameters,
                    ),
                    $type->arguments,
                ),
            );
        }

        if ($type instanceof TypedArrayType) {
            return new TypedArrayType(
                $this->resolveParsedType($type->keyType, $parsedFile, $offset, $genericDeclarations, $localParameters),
                $this->resolveParsedType($type->valueType, $parsedFile, $offset, $genericDeclarations, $localParameters),
                $type->isList,
            );
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            $members = array_map(
                fn (Type $member): Type => $this->resolveParsedType(
                    $member,
                    $parsedFile,
                    $offset,
                    $genericDeclarations,
                    $localParameters,
                ),
                $type->members,
            );

            return $type instanceof UnionType ? new UnionType($members) : new IntersectionType($members);
        }

        return $type;
    }

    /** @param array<string, TypeParameter> $localParameters */
    private function resolveAtomicType(
        AtomicType $type,
        ParsedFile $parsedFile,
        int $offset,
        GenericDeclarationIndex $genericDeclarations,
        array $localParameters,
        bool $alreadyResolved = false,
    ): Type {
        if ($type->isBuiltin || in_array($type->canonical, ['self', 'static', 'parent'], true)) {
            return $type;
        }

        if ($type->isFullyQualified) {
            return $type;
        }

        $parameter = $localParameters[strtolower($type->name)]
            ?? $genericDeclarations->findVisibleParameter(
                $parsedFile->sourceFile,
                $offset,
                $type->name,
            );

        if ($parameter !== null) {
            return $parameter;
        }

        $resolved = $alreadyResolved
            ? $type->name
            : $this->names->resolve($parsedFile, $type->name, $offset);

        return new AtomicType(
            $resolved,
            str_contains($resolved, '\\') || str_starts_with($type->renderPhpDoc(), '\\')
                || $this->names->resolveNamespaceAt($parsedFile, $offset) !== '',
        );
    }

    private function findAppliedType(ParsedFile $parsedFile, int $offset): ?\Atatusoft\Ppphp\Frontend\Ast\GenericType
    {
        foreach ($parsedFile->extensionSyntax->genericTypes as $reference) {
            if ($reference->nameSpan->start->offset === $offset) {
                return $reference;
            }
        }

        return null;
    }
}
