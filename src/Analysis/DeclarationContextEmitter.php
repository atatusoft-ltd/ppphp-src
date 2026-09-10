<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis;

use Atatusoft\Ppphp\Source\SourceFile;
use Atatusoft\Ppphp\Frontend\ParsedFile;
use Atatusoft\Ppphp\Semantic\SemanticModel;
use Atatusoft\Ppphp\Transpilation\GeneratedPhp;
use Atatusoft\Ppphp\Transpilation\GeneratedSourceMap;
use Atatusoft\Ppphp\Transpilation\Pass\EraseGenericTypesPass;
use Atatusoft\Ppphp\Transpilation\SourceEdit;
use Atatusoft\Ppphp\Transpilation\TranspilationContext;
use PhpParser\Node;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;

/** Produces analysis-only declarations without retaining implementation bodies. */
final readonly class DeclarationContextEmitter
{
    public function __construct(
        private ParserFactory $parsers = new ParserFactory(),
        private Standard $printer = new Standard(),
    ) {}

    /** Retains declaration metadata without requiring valid implementation bodies. */
    public function emitContext(SemanticModel $model): GeneratedPhp
    {
        $file = $model->parsedFile;
        $context = new TranspilationContext($file, $model);
        (new EraseGenericTypesPass())->execute($context);

        // Header erasure owns its edits and PHPDoc. All other extension syntax
        // needs only parser normalization: these bodies are discarded, not run.
        $headerEdits = $context->sourceEdits;
        foreach ($file->normalizationPlan->edits as $edit) {
            if (array_any($headerEdits, static fn (SourceEdit $header): bool =>
                $header->span->start->offset <= $edit->span->start->offset
                && $header->span->end->offset >= $edit->span->end->offset)) {
                continue;
            }
            $context->replace($edit->span, $edit->replacement);
        }

        return $this->emit($file->sourceFile, $context->generate()->contents);
    }

    public function emit(SourceFile $sourceFile, string $loweredPhp): GeneratedPhp
    {
        $statements = $this->parsers->createForNewestSupportedVersion()->parse($loweredPhp);

        if ($statements === null) {
            throw new \RuntimeException('The lowered declaration context could not be parsed.');
        }

        $contents = $this->printer->prettyPrintFile($this->retainDeclarations(array_values($statements), false)) . "\n";

        return new GeneratedPhp(
            $contents,
            new GeneratedSourceMap($sourceFile, strlen($contents), []),
            [],
        );
    }

    /** Produces source-free declaration syntax suitable for a portable dependency shard. */
    /** @param array<string, true> $excludedDeclarations */
    public function emitPortable(ParsedFile $parsedFile, array $excludedDeclarations = []): string
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new CloningVisitor());
        $statements = array_filter(
            $traverser->traverse($parsedFile->statements),
            static fn (Node $node): bool => $node instanceof Node\Stmt,
        );

        return $this->printer->prettyPrintFile($this->retainDeclarations(
            array_values($statements),
            true,
            $excludedDeclarations,
        )) . "\n";
    }

    /**
     * @param list<Node\Stmt> $statements
     * @param array<string, true> $excludedDeclarations
     * @return list<Node\Stmt>
     */
    private function retainDeclarations(
        array $statements,
        bool $emptyBodies,
        array $excludedDeclarations = [],
        string $namespace = '',
    ): array
    {
        $declarations = [];

        foreach ($statements as $statement) {
            if ($statement instanceof Node\Stmt\Namespace_) {
                $statement->stmts = $this->retainDeclarations(
                    array_values($statement->stmts),
                    $emptyBodies,
                    $excludedDeclarations,
                    $statement->name?->toString() ?? '',
                );
                $declarations[] = $statement;
                continue;
            }

            if ($statement instanceof Node\Stmt\Use_
                || $statement instanceof Node\Stmt\GroupUse) {
                $declarations[] = $statement;
                continue;
            }

            if ($statement instanceof Node\Stmt\Const_) {
                $statement->consts = array_values(array_filter(
                    $statement->consts,
                    fn (Node\Const_ $constant): bool => !isset($excludedDeclarations[$this->declarationKey(
                        'constants',
                        $namespace,
                        $constant->name->toString(),
                    )]),
                ));

                if ($statement->consts !== []) {
                    $declarations[] = $statement;
                }

                continue;
            }

            if ($statement instanceof Node\Stmt\Function_) {
                if (isset($excludedDeclarations[$this->declarationKey(
                    'functions',
                    $namespace,
                    $statement->name->toString(),
                )])) {
                    continue;
                }

                $statement->stmts = $emptyBodies ? [] : $this->placeholderBody($statement->returnType);
                $declarations[] = $statement;
                continue;
            }

            if (!$statement instanceof Node\Stmt\ClassLike) {
                continue;
            }

            if ($statement->name !== null && isset($excludedDeclarations[$this->declarationKey(
                'classes',
                $namespace,
                $statement->name->toString(),
            )])) {
                continue;
            }

            foreach ($statement->getMethods() as $method) {
                if ($method->stmts !== null) {
                    $method->stmts = $emptyBodies ? [] : $this->placeholderBody($method->returnType);
                }

                if ($emptyBodies) {
                    $this->stripParameterHookBodies($method->params);
                }
            }

            if ($emptyBodies) {
                foreach ($statement->getProperties() as $property) {
                    foreach ($property->hooks as $hook) {
                        if ($hook->body !== null) {
                            $hook->body = [];
                        }
                    }
                }
            }

            $declarations[] = $statement;
        }

        return $declarations;
    }

    private function declarationKey(string $kind, string $namespace, string $name): string
    {
        $qualified = $namespace === '' ? $name : $namespace . '\\' . $name;

        return $kind . ':' . strtolower(ltrim($qualified, '\\'));
    }

    /** @return list<Node\Stmt> */
    private function placeholderBody(Node\Identifier|Node\Name|Node\ComplexType|null $returnType): array
    {
        if ($returnType instanceof Node\Identifier
            && in_array(strtolower($returnType->toString()), ['void', 'never'], true)) {
            return $returnType->toString() === 'never'
                ? [$this->throwPlaceholder()]
                : [];
        }

        return [$this->throwPlaceholder()];
    }

    private function throwPlaceholder(): Node\Stmt\Expression
    {
        return new Node\Stmt\Expression(new Node\Expr\Throw_(
            new Node\Expr\New_(new Node\Name\FullyQualified('LogicException')),
        ));
    }

    /** @param array<int|string, Node\Param> $parameters */
    private function stripParameterHookBodies(array $parameters): void
    {
        foreach ($parameters as $parameter) {
            foreach ($parameter->hooks as $hook) {
                if ($hook->body !== null) {
                    $hook->body = [];
                }
            }
        }
    }
}
