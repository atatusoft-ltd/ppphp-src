<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Pass;

use Atatusoft\Ppphp\Analysis\Declaration\DeclarationOrigin;
use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\DiagnosticLabel;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Frontend\ParsedFile;
use Atatusoft\Ppphp\Interop\PhpDoc\PhpDocReader;
use Atatusoft\Ppphp\Semantic\NodeSpanResolver;
use Atatusoft\Ppphp\Semantic\ProjectSemanticContext;
use Atatusoft\Ppphp\Semantic\SourceNameResolver;
use Atatusoft\Ppphp\Semantic\Generic\GenericDeclarationIndex;
use Atatusoft\Ppphp\Semantic\Symbol\ClassSymbol;
use Atatusoft\Ppphp\Semantic\Symbol\ClassConstantSymbol;
use Atatusoft\Ppphp\Semantic\Symbol\FunctionSymbol;
use Atatusoft\Ppphp\Semantic\Symbol\GlobalConstantSymbol;
use Atatusoft\Ppphp\Semantic\Symbol\MethodSymbol;
use Atatusoft\Ppphp\Semantic\Symbol\ParameterSymbol;
use Atatusoft\Ppphp\Semantic\Symbol\PropertySymbol;
use Atatusoft\Ppphp\Semantic\Type\AtomicType;
use Atatusoft\Ppphp\Semantic\Type\CompositeTypeParser;
use Atatusoft\Ppphp\Semantic\Type\GenericType;
use Atatusoft\Ppphp\Semantic\Type\Interfaces\Type;
use Atatusoft\Ppphp\Semantic\Type\IntersectionType;
use Atatusoft\Ppphp\Semantic\Type\NamedType;
use Atatusoft\Ppphp\Semantic\Type\SourceTypeResolver;
use Atatusoft\Ppphp\Semantic\Type\TypedArrayType;
use Atatusoft\Ppphp\Semantic\Type\UnionType;
use Atatusoft\Ppphp\Semantic\When\WhenFragmentParser;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\Span;
use Atatusoft\Ppphp\Support\Path;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Node\Stmt;

final class DeclareSymbolsPass
{
    private ?GenericDeclarationIndex $genericDeclarations = null;

    public function __construct(
        private NodeSpanResolver $spans = new NodeSpanResolver(),
        private PhpDocReader $phpDoc = new PhpDocReader(),
        private CompositeTypeParser $compositeTypes = new CompositeTypeParser(),
        private WhenFragmentParser $whenFragments = new WhenFragmentParser(),
        private SourceNameResolver $sourceNames = new SourceNameResolver(),
        private NodeFinder $nodes = new NodeFinder(),
        private SourceTypeResolver $sourceTypes = new SourceTypeResolver(),
    ) {}

    public function execute(ProjectSemanticContext $context, ?GenericDeclarationIndex $genericDeclarations = null): void
    {
        $this->genericDeclarations = $genericDeclarations;

        try {
            $this->collectProject($context);
        } finally {
            $this->genericDeclarations = null;
        }
    }

    private function collectProject(ProjectSemanticContext $context): void
    {
        foreach ($context->parseResult->parsedFiles as $parsedFile) {
            $this->collectStatements($parsedFile->statements, $parsedFile, $context, '');

            foreach ($parsedFile->extensionSyntax->whenExpressions as $when) {
                $namespace = $this->sourceNames->resolveNamespaceAt(
                    $parsedFile,
                    $when->span->start->offset,
                );
                foreach ([...$when->branches, $when->elseBranch] as $branch) {
                    $fragment = $this->whenFragments->parseBody($parsedFile, $branch->bodySpan);

                    if ($fragment->isSuccessful) {
                        $this->collectStatements(
                            $fragment->statements,
                            $parsedFile,
                            $context,
                            $namespace,
                        );
                    }
                }
            }
        }
    }

    /** @param list<Stmt> $statements */
    private function collectStatements(
        array $statements,
        ParsedFile $parsedFile,
        ProjectSemanticContext $context,
        string $namespace,
    ): void {
        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Namespace_) {
                $this->collectStatements(
                    array_values($statement->stmts),
                    $parsedFile,
                    $context,
                    $statement->name?->toString() ?? '',
                );
                continue;
            }

            if ($statement instanceof Stmt\Function_) {
                $name = $this->qualify($namespace, $statement->name->toString());
                $typeParameters = $this->resolveGenericParameterNames($parsedFile, $statement->name);
                $metadata = $this->phpDoc->readMetadata($statement->getDocComment());
                $function = new FunctionSymbol(
                    $name,
                    $namespace,
                    $this->parameters(array_values($statement->params), $parsedFile, $context, $statement->getDocComment(), $typeParameters),
                    $this->resolveType($statement->returnType, $context, $parsedFile, $typeParameters),
                    $statement->byRef,
                    $parsedFile->sourceFile,
                    $this->spans->resolve($parsedFile, $statement),
                    $this->spans->resolve($parsedFile, $statement->name),
                    $this->resolveDocumentedReturnType($metadata->returns, $parsedFile, $statement),
                );
                $this->reportStubConflict(
                    $context,
                    $context->symbols->findFunction($name),
                    $function,
                );
                $this->reportPlatformConflict(
                    $context,
                    $context->symbols->findFunction($name),
                    $function,
                );
                $this->reportDuplicateDeclaration(
                    $context,
                    $context->symbols->findProjectFunction($name),
                    $function,
                );
                $context->symbols->declareFunction($function);
                continue;
            }

            if ($statement instanceof Stmt\Const_) {
                $documented = $this->phpDoc->readMetadata($statement->getDocComment())->variables[''] ?? null;

                foreach ($statement->consts as $constant) {
                    $name = $this->qualify($namespace, $constant->name->toString());
                    $symbol = new GlobalConstantSymbol(
                        $name,
                        $namespace,
                        $documented === null
                            ? $this->resolveDefaultType($constant->value)
                            : $this->resolveDocumentedType($documented, $parsedFile, $constant),
                        $parsedFile->sourceFile,
                        $this->spans->resolve($parsedFile, $constant),
                        $this->spans->resolve($parsedFile, $constant->name),
                    );
                    $this->reportPlatformConflict(
                        $context,
                        $context->symbols->findConstant($name),
                        $symbol,
                    );
                    $context->symbols->declareConstant($symbol);
                }

                continue;
            }

            if (!$statement instanceof Stmt\ClassLike || $statement->name === null) {
                continue;
            }

            $name = $this->qualify($namespace, $statement->name->toString());
            $classTypeParameters = $this->resolveGenericParameterNames($parsedFile, $statement->name);
            $parentType = $statement instanceof Stmt\Class_ && $statement->extends !== null
                ? $this->resolveType($statement->extends, $context, $parsedFile, $classTypeParameters)
                : null;
            $interfaceNodes = $statement instanceof Stmt\Class_
                ? $statement->implements
                : ($statement instanceof Stmt\Interface_ ? $statement->extends : []);
            $interfaceTypes = array_values(array_filter(array_map(
                fn (Node\Name $interface): ?NamedType => $this->resolveType($interface, $context, $parsedFile, $classTypeParameters),
                $interfaceNodes,
            )));
            $parent = $parentType?->resolveSingleNamedType();
            $interfaces = array_values(array_filter(array_map(
                static fn (NamedType $interface): ?string => $interface->resolveSingleNamedType(),
                $interfaceTypes,
            )));
            $traits = [];
            $traitTypes = [];

            foreach ($statement->stmts as $member) {
                if ($member instanceof Stmt\TraitUse) {
                    foreach ($member->traits as $trait) {
                        $traitType = $this->resolveType($trait, $context, $parsedFile, $classTypeParameters);

                        if ($traitType === null || $traitType->resolveSingleNamedType() === null) {
                            continue;
                        }

                        $traitTypes[] = $traitType;
                        $traits[] = $traitType->resolveSingleNamedType();
                    }
                }
            }

            $class = new ClassSymbol(
                $name,
                $namespace,
                match (true) {
                    $statement instanceof Stmt\Interface_ => 'interface',
                    $statement instanceof Stmt\Trait_ => 'trait',
                    $statement instanceof Stmt\Enum_ => 'enum',
                    default => 'class',
                },
                $parsedFile->sourceFile,
                $this->spans->resolve($parsedFile, $statement),
                $this->spans->resolve($parsedFile, $statement->name),
                $parent,
                $interfaces,
                $traits,
                $parentType,
                $interfaceTypes,
                $traitTypes,
                $statement instanceof Stmt\Class_ && $statement->isAbstract(),
                $statement instanceof Stmt\Class_ && $statement->isFinal(),
            );

            foreach ($statement->stmts as $member) {
                if ($member instanceof Stmt\ClassMethod) {
                    $methodTypeParameters = [
                        ...$classTypeParameters,
                        ...$this->resolveGenericParameterNames($parsedFile, $member->name),
                    ];
                    $class->declareMethod(new MethodSymbol(
                        $name,
                        $member->name->toString(),
                        $this->parameters(array_values($member->params), $parsedFile, $context, $member->getDocComment(), $methodTypeParameters),
                        $this->resolveType($member->returnType, $context, $parsedFile, $methodTypeParameters),
                        $this->visibility($member),
                        $member->isStatic(),
                        $member->byRef,
                        $this->spans->resolve($parsedFile, $member),
                        $this->spans->resolve($parsedFile, $member->name),
                        $this->resolveDocumentedReturnType(
                            $this->phpDoc->readMetadata($member->getDocComment())->returns,
                            $parsedFile,
                            $member,
                        ),
                        $member->stmts !== null,
                        $member->isAbstract(),
                        $member->isFinal(),
                    ));

                    if (strtolower($member->name->toString()) === '__construct') {
                        foreach ($member->params as $parameter) {
                            if (!$parameter->isPromoted() || !$parameter->var instanceof Node\Expr\Variable || !is_string($parameter->var->name)) {
                                continue;
                            }

                            $class->declareProperty(new PropertySymbol(
                                $parameter->var->name,
                                $this->resolveType($parameter->type, $context, $parsedFile, $classTypeParameters),
                                match (true) {
                                    $parameter->isPrivate() => 'private',
                                    $parameter->isProtected() => 'protected',
                                    default => 'public',
                                },
                                false,
                                $parameter->isReadonly(),
                                $this->spans->resolve($parsedFile, $parameter),
                                $this->spans->resolve($parsedFile, $parameter->var),
                                null,
                                $this->writeVisibility($parameter),
                                true,
                                true,
                                $this->resolveDefaultType($parameter->default),
                                $this->hasBackingStorage($parameter->hooks, $parameter->var->name, $parameter->default !== null),
                                $this->hasHook($parameter->hooks, 'get'),
                                $this->hasHook($parameter->hooks, 'set'),
                                false,
                                $parameter->hooks !== [] && !$this->hasBackingStorage($parameter->hooks, $parameter->var->name, $parameter->default !== null),
                                $name,
                            ));
                        }
                    }
                } elseif ($member instanceof Stmt\Property) {
                    $documentedProperties = $this->phpDoc->readMetadata($member->getDocComment())->variables;

                    foreach ($member->props as $property) {
                        $propertyName = $property->name->toString();
                        $documented = $documentedProperties['$' . $propertyName]
                            ?? $documentedProperties['']
                            ?? null;
                        $backed = $this->hasBackingStorage($member->hooks, $propertyName, $property->default !== null);
                        $class->declareProperty(new PropertySymbol(
                            $propertyName,
                            $this->resolveType($member->type, $context, $parsedFile, $classTypeParameters),
                            $this->visibility($member),
                            $member->isStatic(),
                            $member->isReadonly(),
                            $this->spans->resolve($parsedFile, $property),
                            $this->spans->resolve($parsedFile, $property->name),
                            $documented === null
                                ? null
                                : $this->resolveDocumentedType($documented, $parsedFile, $property),
                            $this->writeVisibility($member),
                            false,
                            $property->default !== null,
                            $this->resolveDefaultType($property->default),
                            $backed,
                            $this->hasHook($member->hooks, 'get'),
                            $this->hasHook($member->hooks, 'set'),
                            $member->isAbstract(),
                            $member->hooks !== [] && !$backed,
                            $name,
                        ));
                    }
                } elseif ($member instanceof Stmt\ClassConst) {
                    foreach ($member->consts as $constant) {
                        $declaredConstantType = $this->resolveType($member->type, $context, $parsedFile, $classTypeParameters);
                        $class->declareConstant(new ClassConstantSymbol(
                            $constant->name->toString(),
                            $declaredConstantType === null
                                ? $this->resolveDefaultType($constant->value)
                                : $declaredConstantType->semanticType,
                            $this->visibility($member),
                            $member->isFinal(),
                            false,
                            $name,
                            $this->spans->resolve($parsedFile, $constant),
                            $this->spans->resolve($parsedFile, $constant->name),
                        ));
                    }
                } elseif ($member instanceof Stmt\EnumCase) {
                    $class->declareConstant(new ClassConstantSymbol(
                        $member->name->toString(),
                        new AtomicType($name),
                        'public',
                        true,
                        true,
                        $name,
                        $this->spans->resolve($parsedFile, $member),
                        $this->spans->resolve($parsedFile, $member->name),
                    ));
                }
            }

            $this->reportDuplicateDeclaration(
                $context,
                $context->symbols->findProjectClass($name),
                $class,
            );
            $this->reportStubConflict(
                $context,
                $context->symbols->findClass($name),
                $class,
            );
            $this->reportPlatformConflict(
                $context,
                $context->symbols->findClass($name),
                $class,
            );
            $context->symbols->declareClass($class);
        }
    }

    private function reportDuplicateDeclaration(
        ProjectSemanticContext $context,
        ClassSymbol|FunctionSymbol|null $existing,
        ClassSymbol|FunctionSymbol $declaration,
    ): void {
        if ($existing === null
            || !$this->isProjectOrigin($existing->sourceFile->declarationOrigin)
            || !$this->isProjectOrigin($declaration->sourceFile->declarationOrigin)) {
            return;
        }

        $existingSelected = isset($context->diagnosticSourceFiles[
            Path::buildComparisonKey($existing->sourceFile->path)
        ]);
        $declarationSelected = isset($context->diagnosticSourceFiles[
            Path::buildComparisonKey($declaration->sourceFile->path)
        ]);
        $selectedReference = null;

        if (!$existingSelected && !$declarationSelected) {
            $selectedReference = $this->findSelectedReference($context, $declaration);

            if ($selectedReference === null) {
                return;
            }
        }

        $primary = $declarationSelected ? $declaration : $existing;
        $related = $primary === $declaration ? $existing : $declaration;
        $primaryLabel = $selectedReference === null
            ? new DiagnosticLabel($primary->selectionSpan, 'This project declaration conflicts with another source declaration.')
            : new DiagnosticLabel($selectedReference, 'This selected reference is ambiguous because the project contains duplicate declarations.');
        $relatedLabels = $selectedReference === null
            ? [new DiagnosticLabel($related->selectionSpan, 'The other project declaration is here.')]
            : [
                new DiagnosticLabel($existing->selectionSpan, 'One project declaration is here.'),
                new DiagnosticLabel($declaration->selectionSpan, 'The other project declaration is here.'),
            ];
        $context->diagnostics->add(new Diagnostic(
            DiagnosticCode::DuplicateProjectDeclaration,
            sprintf(
                'The project symbol "%s" is declared in both "%s" and "%s".',
                $declaration->fullyQualifiedName,
                $existing->sourceFile->displayPath,
                $declaration->sourceFile->displayPath,
            ),
            $primaryLabel,
            $relatedLabels,
            'Remove or rename one of the project declarations so the symbol has a single owner.',
        ));
    }

    private function reportStubConflict(
        ProjectSemanticContext $context,
        ClassSymbol|FunctionSymbol|null $existing,
        ClassSymbol|FunctionSymbol $declaration,
    ): void {
        if ($existing === null) {
            return;
        }

        $existingIsStub = $existing->sourceFile->declarationOrigin === DeclarationOrigin::ConfiguredStub;
        $declarationIsStub = $declaration->sourceFile->declarationOrigin === DeclarationOrigin::ConfiguredStub;

        if ($existingIsStub === $declarationIsStub) {
            return;
        }

        $conflict = $existing instanceof FunctionSymbol && $declaration instanceof FunctionSymbol
            ? $this->functionContractConflict($existing, $declaration)
            : ($existing instanceof ClassSymbol && $declaration instanceof ClassSymbol
                ? $this->classContractConflict($existing, $declaration)
                : 'declaration kind');

        if ($conflict === null) {
            return;
        }

        $stub = $existingIsStub ? $existing : $declaration;
        $native = $stub === $existing ? $declaration : $existing;
        $context->diagnostics->add(new Diagnostic(
            DiagnosticCode::StubContractConflict,
            sprintf('Configured stub contract conflicts with the native declaration for %s (%s).', $declaration->fullyQualifiedName, $conflict),
            new DiagnosticLabel($stub->selectionSpan, 'The conflicting stub contract is declared here.'),
            [new DiagnosticLabel($native->selectionSpan, 'The native declaration is here.')],
            'Align the configured stub with the native runtime declaration, or remove the contradictory metadata.',
        ));
    }

    private function reportPlatformConflict(
        ProjectSemanticContext $context,
        ClassSymbol|FunctionSymbol|GlobalConstantSymbol|null $existing,
        ClassSymbol|FunctionSymbol|GlobalConstantSymbol $declaration,
    ): void {
        if ($existing === null) {
            return;
        }

        $existingOrigin = $existing->sourceFile->declarationOrigin;
        $declarationOrigin = $declaration->sourceFile->declarationOrigin;
        $existingIsProject = $this->isProjectOrigin($existingOrigin);
        $declarationIsProject = $this->isProjectOrigin($declarationOrigin);

        if (!(($existingIsProject && $declarationOrigin === DeclarationOrigin::PhpPlatform)
            || ($declarationIsProject && $existingOrigin === DeclarationOrigin::PhpPlatform))) {
            return;
        }

        $project = $existingIsProject ? $existing : $declaration;
        $platform = $project === $existing ? $declaration : $existing;

        if (!isset($context->diagnosticSourceFiles[Path::buildComparisonKey($project->sourceFile->path)])) {
            return;
        }

        $context->diagnostics->add(new Diagnostic(
            DiagnosticCode::DeclarationConflictsWithPhpPlatform,
            sprintf('The project declaration for "%s" conflicts with the PHP platform.', $project->fullyQualifiedName),
            new DiagnosticLabel($project->selectionSpan, 'This project declaration replaces a built-in PHP symbol.'),
            [new DiagnosticLabel($platform->selectionSpan, 'The PHP platform declaration is here.')],
            'Rename or remove the project declaration so the PHP platform symbol remains unambiguous.',
        ));
    }

    private function isProjectOrigin(DeclarationOrigin $origin): bool
    {
        return in_array($origin, [DeclarationOrigin::ProjectPpphp, DeclarationOrigin::ProjectPhp], true);
    }

    private function functionContractConflict(FunctionSymbol $left, FunctionSymbol $right): ?string
    {
        $parameterConflict = $this->parameterContractConflict($left->parameters, $right->parameters);

        if ($parameterConflict !== null) {
            return $parameterConflict;
        }

        if ($left->returnType !== null && $right->returnType !== null
            && $left->returnType->canonical !== $right->returnType->canonical) {
            return 'native return type';
        }

        return null;
    }

    private function classContractConflict(ClassSymbol $left, ClassSymbol $right): ?string
    {
        if ($left->kind !== $right->kind) {
            return 'declaration kind';
        }

        foreach ($left->methods as $method) {
            $other = $right->findMethod($method->name);

            if ($other === null) {
                continue;
            }

            $parameterConflict = $this->parameterContractConflict($method->parameters, $other->parameters);

            if ($parameterConflict !== null
                || ($method->returnType !== null && $other->returnType !== null
                    && $method->returnType->canonical !== $other->returnType->canonical)
                || $method->static !== $other->static) {
                return sprintf(
                    'method %s%s',
                    $method->name,
                    $parameterConflict === null ? '' : sprintf(' (%s)', $parameterConflict),
                );
            }
        }

        foreach ($left->properties as $property) {
            $other = $right->findProperty($property->name);

            if ($other !== null && $property->type !== null && $other->type !== null
                && $property->type->canonical !== $other->type->canonical) {
                return sprintf('property $%s', $property->name);
            }
        }

        return null;
    }

    /**
     * @param list<ParameterSymbol> $left
     * @param list<ParameterSymbol> $right
     */
    private function parameterContractConflict(array $left, array $right): ?string
    {
        if (count($left) !== count($right)) {
            return 'parameter count';
        }

        foreach ($left as $position => $parameter) {
            $other = $right[$position];

            if ($parameter->name !== $other->name) {
                return sprintf('name of parameter %d', $position + 1);
            }

            if ($parameter->type !== null && $other->type !== null
                && $parameter->type->canonical !== $other->type->canonical) {
                return sprintf('native type of parameter %d', $position + 1);
            }

            if ($parameter->byReference !== $other->byReference
                || $parameter->variadic !== $other->variadic
                || $parameter->hasDefault !== $other->hasDefault) {
                return sprintf('shape of parameter %d', $position + 1);
            }
        }

        return null;
    }

    private function findSelectedReference(
        ProjectSemanticContext $context,
        ClassSymbol|FunctionSymbol $declaration,
    ): ?Span {
        $isFunction = $declaration instanceof FunctionSymbol;

        foreach ($context->parseResult->parsedFiles as $parsedFile) {
            if (
                $parsedFile->sourceFile->kind === FileKind::Stub
                || !isset($context->diagnosticSourceFiles[
                    Path::buildComparisonKey($parsedFile->sourceFile->path)
                ])
            ) {
                continue;
            }

            $reference = $this->findSymbolReference(
                $parsedFile,
                $context,
                $declaration->fullyQualifiedName,
                $isFunction,
            );

            if ($reference !== null) {
                return $this->spans->resolve($parsedFile, $reference);
            }
        }

        return null;
    }

    private function findSymbolReference(
        ParsedFile $parsedFile,
        ProjectSemanticContext $context,
        string $fullyQualifiedName,
        bool $isFunction,
    ): ?Node {
        if ($isFunction) {
            foreach ($this->nodes->findInstanceOf($parsedFile->statements, Node\Expr\FuncCall::class) as $call) {
                if (
                    $call->name instanceof Node\Name
                    && $this->nameReferencesFunction($call->name, $parsedFile, $context, $fullyQualifiedName)
                ) {
                    return $call->name;
                }
            }

            return null;
        }

        $nonClassNames = [];

        foreach ($this->nodes->findInstanceOf($parsedFile->statements, Node\Expr\FuncCall::class) as $call) {
            if ($call->name instanceof Node\Name) {
                $nonClassNames[spl_object_id($call->name)] = true;
            }
        }

        foreach ($this->nodes->findInstanceOf($parsedFile->statements, Node\Expr\ConstFetch::class) as $fetch) {
            $nonClassNames[spl_object_id($fetch->name)] = true;
        }

        foreach ($this->nodes->findInstanceOf($parsedFile->statements, Node\Name::class) as $name) {
            if (
                !isset($nonClassNames[spl_object_id($name)])
                && $this->resolvedNameMatches($name, $context, $fullyQualifiedName)
            ) {
                return $name;
            }
        }

        return null;
    }

    private function nameReferencesFunction(
        Node\Name $name,
        ParsedFile $parsedFile,
        ProjectSemanticContext $context,
        string $fullyQualifiedName,
    ): bool {
        $resolved = $context->resolvedNames->resolve($name);

        if (!$name->isUnqualified() || ($resolved !== null && str_contains($resolved, '\\'))) {
            return $resolved !== null
                && strcasecmp(ltrim($resolved, '\\'), ltrim($fullyQualifiedName, '\\')) === 0;
        }

        $namespace = $this->sourceNames->resolveNamespaceAt($parsedFile, $name->getStartFilePos());
        $namespaced = $namespace === '' ? $name->toString() : $namespace . '\\' . $name->toString();
        $resolvedTarget = $context->symbols->findFunction($namespaced) === null
            ? $name->toString()
            : $namespaced;

        return strcasecmp(ltrim($resolvedTarget, '\\'), ltrim($fullyQualifiedName, '\\')) === 0;
    }

    private function resolvedNameMatches(
        Node\Name $name,
        ProjectSemanticContext $context,
        string $fullyQualifiedName,
    ): bool {
        $resolved = $context->resolvedNames->resolve($name);

        return $resolved !== null
            && strcasecmp(ltrim($resolved, '\\'), ltrim($fullyQualifiedName, '\\')) === 0;
    }

    /**
     * @param list<Node\Param> $parameters
     * @param list<string> $typeParameters
     * @return list<ParameterSymbol>
     */
    private function parameters(
        array $parameters,
        ParsedFile $parsedFile,
        ProjectSemanticContext $context,
        ?Doc $document = null,
        array $typeParameters = [],
    ): array
    {
        $documentedParameters = $this->phpDoc->readMetadata($document)->parameters;

        $symbols = [];

        foreach ($parameters as $position => $parameter) {
            $name = $parameter->var instanceof Node\Expr\Variable && is_string($parameter->var->name)
                ? '$' . $parameter->var->name
                : '$unknown';
            $documented = $documentedParameters[$name] ?? null;

            $symbols[] = new ParameterSymbol(
                $name,
                $this->resolveType($parameter->type, $context, $parsedFile, $typeParameters),
                $parameter->variadic,
                $parameter->byRef,
                $parameter->flags !== 0,
                $this->spans->resolve($parsedFile, $parameter),
                $this->spans->resolve($parsedFile, $parameter->var),
                $documented === null
                    ? null
                    : $this->resolveDocumentedType($documented, $parsedFile, $parameter),
                $position,
                $parameter->default !== null,
                $this->resolveDefaultType($parameter->default),
            );
        }

        return $symbols;
    }

    private function resolveDocumentedType(string $type, ParsedFile $parsedFile, Node $node): Type
    {
        $parsed = $this->compositeTypes->parse($this->normalizeDocumentedType($type));

        if ($this->genericDeclarations === null) {
            return $parsed;
        }

        return $this->sourceTypes->resolveParsedType(
            $parsed,
            $parsedFile,
            $this->spans->resolve($parsedFile, $node)->start->offset,
            $this->genericDeclarations,
        );
    }

    /** @param list<string> $returns */
    private function resolveDocumentedReturnType(array $returns, ParsedFile $parsedFile, Node $node): ?Type
    {
        $return = $returns[0] ?? null;

        return $return === null ? null : $this->resolveDocumentedType($return, $parsedFile, $node);
    }

    private function resolveDefaultType(?Node\Expr $expression): ?Type
    {
        return match (true) {
            $expression instanceof Node\Scalar\Int_ => new AtomicType('int'),
            $expression instanceof Node\Scalar\Float_ => new AtomicType('float'),
            $expression instanceof Node\Scalar\String_ => new AtomicType('string'),
            $expression instanceof Node\Expr\Array_ => new AtomicType('array'),
            $expression instanceof Node\Expr\ConstFetch => match (strtolower($expression->name->toString())) {
                'null' => new AtomicType('null'),
                'true' => new AtomicType('true'),
                'false' => new AtomicType('false'),
                default => null,
            },
            default => null,
        };
    }

    private function writeVisibility(Node\Param|Stmt\Property $property): string
    {
        return match (true) {
            $property->isPrivateSet() => 'private',
            $property->isProtectedSet() => 'protected',
            $property->isPublicSet() => 'public',
            $property->isPrivate() => 'private',
            $property->isProtected() => 'protected',
            default => 'public',
        };
    }

    /** @param array<Node\PropertyHook> $hooks */
    private function hasHook(array $hooks, string $name): bool
    {
        foreach ($hooks as $hook) {
            if (strcasecmp($hook->name->toString(), $name) === 0) {
                return true;
            }
        }

        return false;
    }

    /** @param array<Node\PropertyHook> $hooks */
    private function hasBackingStorage(array $hooks, string $name, bool $hasDefault): bool
    {
        if ($hooks === [] || $hasDefault) {
            return true;
        }

        foreach ($hooks as $hook) {
            if (strcasecmp($hook->name->toString(), 'set') === 0 && $hook->body instanceof Node\Expr) {
                return true;
            }

            if ($hook->body === null) {
                continue;
            }

            $fetch = $this->nodes->findFirst($hook->body, static fn (Node $node): bool =>
                $node instanceof Node\Expr\PropertyFetch
                && $node->var instanceof Node\Expr\Variable
                && $node->var->name === 'this'
                && $node->name instanceof Node\Identifier
                && $node->name->toString() === $name
            );

            if ($fetch !== null) {
                return true;
            }
        }

        return false;
    }

    private function normalizeDocumentedType(string $type): string
    {
        $normalized = preg_replace('/\blist\s*</i', 'array<', $type) ?? $type;

        return strcasecmp(trim($normalized), 'array-key') === 0 ? 'int|string' : $normalized;
    }

    /** @param list<string> $typeParameters */
    private function resolveType(
        ?Node $type,
        ProjectSemanticContext $context,
        ParsedFile $parsedFile,
        array $typeParameters = [],
    ): ?NamedType
    {
        if ($type === null) {
            return null;
        }

        if ($this->genericDeclarations !== null) {
            return new NamedType($this->sourceTypes->resolveNode(
                $type,
                $parsedFile,
                $context->resolvedNames,
                $this->genericDeclarations,
            ));
        }

        $semanticType = $this->resolveSemanticType($type, $context, $parsedFile, $typeParameters);

        return new NamedType($semanticType);
    }

    /** @param list<string> $typeParameters */
    private function resolveSemanticType(
        Node $type,
        ProjectSemanticContext $context,
        ParsedFile $parsedFile,
        array $typeParameters,
    ): Type {
        if ($type instanceof Node\Identifier) {
            return new AtomicType($type->toString());
        }

        if ($type instanceof Node\Name) {
            $offset = $this->spans->resolve($parsedFile, $type)->start->offset;
            $applied = $this->findAppliedType($parsedFile, $offset);

            if ($applied !== null) {
                return $this->qualifyType(
                    $this->compositeTypes->parse($applied->span->text),
                    $parsedFile,
                    $offset,
                    $typeParameters,
                );
            }

            if ($this->containsTypeParameter($typeParameters, $type->toString())) {
                return new AtomicType($type->toString());
            }

            return new AtomicType($this->resolveName($type, $context, $parsedFile));
        }

        if ($type instanceof Node\NullableType) {
            return new UnionType([
                $this->resolveSemanticType($type->type, $context, $parsedFile, $typeParameters),
                new AtomicType('null'),
            ]);
        }

        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            $members = array_values(array_map(
                fn (Node $member): Type => $this->resolveSemanticType($member, $context, $parsedFile, $typeParameters),
                $type->types,
            ));

            if ($members === []) {
                throw new \LogicException('A composite type must contain at least one member.');
            }

            return $type instanceof Node\UnionType
                ? new UnionType($members)
                : new IntersectionType($members);
        }

        throw new \LogicException(sprintf('Unsupported PHP type node "%s".', $type::class));
    }

    /** @param list<string> $typeParameters */
    private function qualifyType(
        Type $type,
        ParsedFile $parsedFile,
        int $offset,
        array $typeParameters,
    ): Type {
        if ($type instanceof AtomicType) {
            if ($type->isBuiltin || $this->containsTypeParameter($typeParameters, $type->name)) {
                return $type;
            }

            return new AtomicType($this->sourceNames->resolve($parsedFile, $type->name, $offset));
        }

        if ($type instanceof GenericType) {
            $base = $this->qualifyType($type->base, $parsedFile, $offset, $typeParameters);

            return new GenericType(
                $base instanceof AtomicType ? $base : $type->base,
                array_map(
                    fn (Type $argument): Type => $this->qualifyType($argument, $parsedFile, $offset, $typeParameters),
                    $type->arguments,
                ),
            );
        }

        if ($type instanceof TypedArrayType) {
            return new TypedArrayType(
                $this->qualifyType($type->keyType, $parsedFile, $offset, $typeParameters),
                $this->qualifyType($type->valueType, $parsedFile, $offset, $typeParameters),
                $type->isList,
            );
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            $members = array_map(
                fn (Type $member): Type => $this->qualifyType($member, $parsedFile, $offset, $typeParameters),
                $type->members,
            );

            return $type instanceof UnionType ? new UnionType($members) : new IntersectionType($members);
        }

        return $type;
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

    /** @return list<string> */
    private function resolveGenericParameterNames(ParsedFile $parsedFile, Node\Identifier $owner): array
    {
        $offset = $this->spans->resolve($parsedFile, $owner)->start->offset;

        foreach ($parsedFile->extensionSyntax->genericDeclarations as $declaration) {
            if ($declaration->ownerNameSpan->start->offset === $offset) {
                return array_map(
                    static fn ($parameter): string => $parameter->nameSpan->text,
                    $declaration->parameters,
                );
            }
        }

        return [];
    }

    /** @param list<string> $typeParameters */
    private function containsTypeParameter(array $typeParameters, string $name): bool
    {
        foreach ($typeParameters as $parameter) {
            if (strcasecmp($parameter, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    private function resolveName(Node\Name $name, ProjectSemanticContext $context, ParsedFile $parsedFile): string
    {
        $resolved = $context->resolvedNames->resolve($name);

        if ($resolved !== null) {
            return $resolved;
        }

        $offset = $name->getAttribute('ppphpOriginalStart');

        return is_int($offset)
            ? $this->sourceNames->resolve($parsedFile, $name->toString(), $offset)
            : $name->toString();
    }

    private function qualify(string $namespace, string $name): string
    {
        return $namespace === '' ? $name : $namespace . '\\' . $name;
    }

    private function visibility(Stmt\ClassMethod|Stmt\Property|Stmt\ClassConst $member): string
    {
        return match (true) {
            $member->isPrivate() => 'private',
            $member->isProtected() => 'protected',
            default => 'public',
        };
    }
}
