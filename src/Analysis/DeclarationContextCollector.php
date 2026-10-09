<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis;

use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Frontend\ParsedFile;
use Atatusoft\Ppphp\Interop\Composer\Declaration\DependencyDeclarationProvider;
use Atatusoft\Ppphp\Interop\Composer\Declaration\InstalledComposerDeclarationProvider;
use Atatusoft\Ppphp\Interop\Php\Signature\PhpSignaturePackageLoader;
use Atatusoft\Ppphp\Project\Project;
use Atatusoft\Ppphp\Project\ProjectParseResult;
use Atatusoft\Ppphp\Project\ProjectSource;
use Atatusoft\Ppphp\Project\ProjectSyntaxChecker;
use Atatusoft\Ppphp\Project\SourceSet;
use Atatusoft\Ppphp\Semantic\SemanticAnalyzer;
use Atatusoft\Ppphp\Support\Path;
use PhpParser\Node;
use PhpParser\Node\Stmt;

final readonly class DeclarationContextCollector
{
    public function __construct(
        private ProjectSyntaxChecker $syntaxChecker = new ProjectSyntaxChecker(),
        private SemanticAnalyzer $semanticAnalyzer = new SemanticAnalyzer(),
        private DependencyDeclarationProvider $composerDependencies = new InstalledComposerDeclarationProvider(),
        private PhpSignaturePackageLoader $phpSignatures = new PhpSignaturePackageLoader(),
    ) {}

    public function collect(
        Project $project,
        SourceSet $selectedSources,
        ?ProjectParseResult $selectedResult = null,
        ?DependencyDeclarationProvider $dependencyProvider = null,
    ): ProjectParseResult
    {
        $unselected = new SourceSet(array_filter(
            $project->sources->files,
            static fn (ProjectSource $source): bool => !$selectedSources->contains($source),
        ));
        $result = $this->syntaxChecker->check($project, $unselected);

        $invalidSources = [];

        if ($result->parsedFiles !== []) {
            $analysis = $this->semanticAnalyzer->analyze(new ProjectParseResult(
                $result->parsedFiles,
                $result->sourceFiles,
                new DiagnosticBag(),
            ));

            foreach ($analysis->diagnostics->errors as $diagnostic) {
                $span = $diagnostic->primary?->span;

                if ($span === null || !$this->isInvalidDeclarationDiagnostic($diagnostic)) {
                    continue;
                }

                $parsedFile = $result->findParsedFile($span->sourceFile->path);

                if ($parsedFile === null || !$this->isDeclarationHeaderSpan($parsedFile, $span->start->offset)) {
                    continue;
                }

                $invalidSources[Path::buildComparisonKey($span->sourceFile->path)] = true;
            }
        }

        $contextFiles = array_diff_key($result->parsedFiles, $invalidSources);
        $contextSources = array_diff_key($result->sourceFiles, $invalidSources);
        $selectedFiles = $selectedResult === null ? [] : array_values($selectedResult->parsedFiles);
        $dependencies = ($dependencyProvider ?? $this->composerDependencies)->provide(
            $project,
            [
                ...$selectedFiles,
                ...array_values($contextFiles),
            ],
        );
        $platform = $this->phpSignatures->load(
            $project->configuration->targetPhpVersion,
            [
                ...$selectedFiles,
                ...array_values($contextFiles),
                ...array_values($dependencies->parsedFiles),
            ],
        );
        $diagnostics = new DiagnosticBag();
        $diagnostics->addAll($dependencies->diagnostics);
        $diagnostics->addAll($platform->diagnostics);

        return new ProjectParseResult(
            array_replace($contextFiles, $dependencies->parsedFiles, $platform->parsedFiles),
            array_replace($contextSources, $dependencies->sourceFiles, $platform->sourceFiles),
            $diagnostics,
            $dependencies->knownClassPrefixes,
            $dependencies->classAliases,
            $dependencies->classAliasProvenance,
        );
    }

    private function isInvalidDeclarationDiagnostic(Diagnostic $diagnostic): bool
    {
        return in_array($diagnostic->code, [
            DiagnosticCode::DuplicateTypeParameter,
            DiagnosticCode::UnknownTypeParameter,
            DiagnosticCode::GenericTypeArgumentCountDoesNotMatch,
            DiagnosticCode::TypeArgumentDoesNotSatisfyBound,
            DiagnosticCode::GenericTypeArgumentsAreRequired,
            DiagnosticCode::TypeIsNotGeneric,
            DiagnosticCode::GenericDocumentationConflictsWithNativeSyntax,
            DiagnosticCode::InvalidGenericBound,
            DiagnosticCode::InvalidCompositeType,
            DiagnosticCode::WhenPositionNotSupported,
        ], true);
    }

    private function isDeclarationHeaderSpan(ParsedFile $parsedFile, int $offset): bool
    {
        foreach ($parsedFile->extensionSyntax->genericDeclarations as $declaration) {
            if ($offset >= $declaration->span->start->offset && $offset <= $declaration->span->end->offset) {
                return true;
            }
        }

        foreach ($parsedFile->statements as $statement) {
            if ($this->containsHeaderInitializer($statement, $offset)) {
                return true;
            }
        }

        return false;
    }

    private function containsHeaderInitializer(Node $node, int $offset): bool
    {
        if ($node instanceof Stmt && !$node instanceof Stmt\Namespace_
            && !$node instanceof Stmt\ClassLike && !$node instanceof Stmt\ClassMethod
            && !$node instanceof Stmt\Function_ && !$node instanceof Stmt\Property
            && !$node instanceof Stmt\ClassConst && !$node instanceof Stmt\Const_) {
            return false;
        }
        $initializer = match (true) {
            $node instanceof Node\Param, $node instanceof Node\PropertyItem => $node->default,
            $node instanceof Node\Const_ => $node->value,
            $node instanceof Node\Attribute => $node,
            default => null,
        };
        if ($initializer !== null && $offset >= $initializer->getStartFilePos() && $offset <= $initializer->getEndFilePos()) {
            return true;
        }
        foreach ($node->getSubNodeNames() as $name) {
            // Nested callable and hook implementations are unrelated body context.
            if ($name === 'body' || ($name === 'stmts' && !$node instanceof Stmt\Namespace_ && !$node instanceof Stmt\ClassLike)) {
                continue;
            }
            $value = $node->{$name};
            foreach (is_array($value) ? $value : [$value] as $child) {
                if ($child instanceof Node && $this->containsHeaderInitializer($child, $offset)) {
                    return true;
                }
            }
        }

        return false;
    }
}
