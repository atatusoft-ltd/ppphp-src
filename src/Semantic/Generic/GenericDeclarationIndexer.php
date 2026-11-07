<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Generic;

use Atatusoft\Ppphp\Frontend\Ast\GenericDeclaration;
use Atatusoft\Ppphp\Frontend\ParsedFile;
use Atatusoft\Ppphp\Interop\PhpDoc\PhpDocTypeImporter;
use Atatusoft\Ppphp\Project\ProjectParseResult;
use Atatusoft\Ppphp\Semantic\Symbol\ClassSymbol;
use Atatusoft\Ppphp\Semantic\Symbol\FunctionSymbol;
use Atatusoft\Ppphp\Semantic\Symbol\MethodSymbol;
use Atatusoft\Ppphp\Semantic\Symbol\SymbolTable;
use Atatusoft\Ppphp\Semantic\Type\SourceTypeResolver;
use Atatusoft\Ppphp\Semantic\Type\TypeParameter;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Support\Path;
use PhpParser\Node\Stmt;

final readonly class GenericDeclarationIndexer
{
    public function __construct(
        private PhpDocTypeImporter $phpDoc = new PhpDocTypeImporter(),
        private SourceTypeResolver $sourceTypes = new SourceTypeResolver(),
    ) {}

    public function hasCandidates(ProjectParseResult $parseResult): bool
    {
        foreach ($parseResult->parsedFiles as $parsedFile) {
            if ($parsedFile->extensionSyntax->genericDeclarations !== []
                || ($parsedFile->sourceFile->kind !== FileKind::Ppphp
                    && $this->hasImportedTemplates($parsedFile->statements))) {
                return true;
            }
        }

        return false;
    }

    public function build(ProjectParseResult $parseResult, SymbolTable $symbols): GenericDeclarationIndex
    {
        $index = new GenericDeclarationIndex();

        foreach ($parseResult->parsedFiles as $parsedFile) {
            foreach ($parsedFile->extensionSyntax->genericDeclarations as $declaration) {
                $owner = $this->resolveOwner($parsedFile, $declaration, $symbols);

                if ($owner === null) {
                    continue;
                }

                $key = Path::buildComparisonKey($parsedFile->sourceFile->path)
                    . ':' . $declaration->ownerNameSpan->start->offset;
                $parameters = [];
                $localParameters = [];

                foreach ($declaration->parameters as $parameter) {
                    $resolved = new TypeParameter(
                        $parameter->nameSpan->text,
                        $parameter->bound === null
                            ? null
                            : $this->sourceTypes->resolveSourceType(
                                $parameter->bound,
                                $parsedFile,
                                $index,
                                $localParameters,
                            ),
                        $key,
                        $parameter->span,
                    );
                    $parameters[] = $resolved;
                    $localParameters[strtolower($resolved->name)] = $resolved;
                }
                $name = $owner instanceof MethodSymbol
                    ? $owner->owner . '::' . $owner->name
                    : $owner->fullyQualifiedName;
                $methodOwner = $owner instanceof MethodSymbol ? $symbols->findClass($owner->owner) : null;
                $namespace = $owner instanceof MethodSymbol
                    ? ($methodOwner === null ? '' : $methodOwner->namespace)
                    : $owner->namespace;
                $entry = new GenericDeclarationEntry(
                    $key,
                    $name,
                    $declaration->declarationKind,
                    $namespace,
                    $parsedFile->sourceFile,
                    $declaration->span,
                    $owner->declarationSpan,
                    $parameters,
                    $owner,
                );
                $owner->attachGenericDeclaration($entry);
                $index->record($entry);
            }

            if ($parsedFile->sourceFile->kind !== FileKind::Ppphp) {
                $this->recordImportedDeclarations($parsedFile, $symbols, $index);
            }
        }

        return $index;
    }

    /** @param array<Stmt> $statements */
    private function hasImportedTemplates(array $statements): bool
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Namespace_) {
                if ($this->hasImportedTemplates($statement->stmts)) {
                    return true;
                }
                continue;
            }

            if (($statement instanceof Stmt\Function_ || $statement instanceof Stmt\ClassLike)
                && $this->phpDoc->hasTemplates($statement->getDocComment())) {
                return true;
            }

            if ($statement instanceof Stmt\ClassLike) {
                foreach ($statement->getMethods() as $method) {
                    if ($this->phpDoc->hasTemplates($method->getDocComment())) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function recordImportedDeclarations(
        ParsedFile $parsedFile,
        SymbolTable $symbols,
        GenericDeclarationIndex $index,
    ): void {
        $this->recordImportedStatements($parsedFile->statements, $parsedFile, $symbols, $index, '');
    }

    /** @param list<Stmt> $statements */
    private function recordImportedStatements(
        array $statements,
        ParsedFile $parsedFile,
        SymbolTable $symbols,
        GenericDeclarationIndex $index,
        string $namespace,
    ): void {
        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Namespace_) {
                $this->recordImportedStatements(
                    array_values($statement->stmts),
                    $parsedFile,
                    $symbols,
                    $index,
                    $statement->name?->toString() ?? '',
                );

                continue;
            }

            if ($statement instanceof Stmt\Function_) {
                $name = $this->qualify($namespace, $statement->name->toString());
                $owner = $symbols->findFunction($name);

                if ($owner !== null) {
                    $this->recordImportedOwner(
                        $parsedFile,
                        $index,
                        $owner,
                        $name,
                        'function',
                        $namespace,
                        $statement->name->getStartFilePos(),
                        $statement->getDocComment(),
                    );
                }

                continue;
            }

            if (!$statement instanceof Stmt\ClassLike || $statement->name === null) {
                continue;
            }

            $name = $this->qualify($namespace, $statement->name->toString());
            $class = $symbols->findClass($name);

            if ($class === null) {
                continue;
            }

            $this->recordImportedOwner(
                $parsedFile,
                $index,
                $class,
                $name,
                match (true) {
                    $statement instanceof Stmt\Interface_ => 'interface',
                    $statement instanceof Stmt\Trait_ => 'trait',
                    default => 'class',
                },
                $namespace,
                $statement->name->getStartFilePos(),
                $statement->getDocComment(),
            );

            foreach ($statement->getMethods() as $method) {
                $owner = $class->findMethod($method->name->toString());

                if ($owner !== null) {
                    $this->recordImportedOwner(
                        $parsedFile,
                        $index,
                        $owner,
                        $name . '::' . $method->name->toString(),
                        'method',
                        $namespace,
                        $method->name->getStartFilePos(),
                        $method->getDocComment(),
                    );
                }
            }
        }
    }

    private function recordImportedOwner(
        ParsedFile $parsedFile,
        GenericDeclarationIndex $index,
        ClassSymbol|FunctionSymbol|MethodSymbol $owner,
        string $name,
        string $kind,
        string $namespace,
        int $nameOffset,
        ?\PhpParser\Comment\Doc $document,
    ): void {
        $key = Path::buildComparisonKey($parsedFile->sourceFile->path) . ':' . $nameOffset;
        $parameters = $this->phpDoc->importTemplates($document, $key, $owner->declarationSpan);

        if ($parameters === []) {
            return;
        }

        $entry = new GenericDeclarationEntry(
            $key,
            $name,
            $kind,
            $namespace,
            $parsedFile->sourceFile,
            $owner->declarationSpan,
            $owner->declarationSpan,
            $parameters,
            $owner,
        );
        $owner->attachGenericDeclaration($entry);
        $index->record($entry);
    }

    private function qualify(string $namespace, string $name): string
    {
        return $namespace === '' ? $name : $namespace . '\\' . $name;
    }

    private function resolveOwner(
        ParsedFile $parsedFile,
        GenericDeclaration $declaration,
        SymbolTable $symbols,
    ): ClassSymbol|FunctionSymbol|MethodSymbol|null {
        $pathKey = Path::buildComparisonKey($parsedFile->sourceFile->path);
        $offset = $declaration->ownerNameSpan->start->offset;

        foreach ($symbols->classes as $class) {
            if (Path::buildComparisonKey($class->sourceFile->path) !== $pathKey) {
                continue;
            }

            foreach ($class->methods as $method) {
                if ($this->contains($method->declarationSpan, $offset)) {
                    return $method;
                }
            }

            if ($this->contains($class->declarationSpan, $offset)) {
                return $class;
            }
        }

        foreach ($symbols->functions as $function) {
            if (Path::buildComparisonKey($function->sourceFile->path) === $pathKey
                && $this->contains($function->declarationSpan, $offset)) {
                return $function;
            }
        }

        return null;
    }

    private function contains(\Atatusoft\Ppphp\Source\Span $span, int $offset): bool
    {
        return $offset >= $span->start->offset && $offset < $span->end->offset;
    }
}
