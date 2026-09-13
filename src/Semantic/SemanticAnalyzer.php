<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic;

use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Project\ProjectParseResult;
use Atatusoft\Ppphp\Semantic\Generic\GenericDeclarationIndexer;
use Atatusoft\Ppphp\Semantic\Binding\BindingTable;
use Atatusoft\Ppphp\Semantic\Effect\CallableErrorIndex;
use Atatusoft\Ppphp\Semantic\Effect\ErrorResolver;
use Atatusoft\Ppphp\Semantic\Pass\CheckBindingsPass;
use Atatusoft\Ppphp\Semantic\Pass\CheckErrorEffectsPass;
use Atatusoft\Ppphp\Semantic\Pass\CheckGenericTypesPass;
use Atatusoft\Ppphp\Semantic\Pass\CheckStrictTypesDeclarationPass;
use Atatusoft\Ppphp\Semantic\Pass\CheckTypesPass;
use Atatusoft\Ppphp\Semantic\Pass\CheckWhenExpressionsPass;
use Atatusoft\Ppphp\Semantic\Pass\CheckWhenCallBoundariesPass;
use Atatusoft\Ppphp\Semantic\Pass\DeclareSymbolsPass;
use Atatusoft\Ppphp\Semantic\Pass\Interfaces\SemanticPass;
use Atatusoft\Ppphp\Semantic\Pass\ResolveNamesPass;
use Atatusoft\Ppphp\Semantic\Pass\AnalyzeTypeFlowPass;
use Atatusoft\Ppphp\Semantic\Scope\ScopeStack;
use Atatusoft\Ppphp\Semantic\Symbol\SymbolTable;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Support\Path;

final readonly class SemanticAnalyzer
{
    /** @var list<SemanticPass> */
    private array $passes;

    private readonly DeclareSymbolsPass $declareSymbols;

    private readonly ResolveNamesPass $resolveNames;

    private readonly ErrorResolver $errorResolver;

    /** @param list<SemanticPass>|null $passes */
    public function __construct(
        ?array $passes = null,
        ?DeclareSymbolsPass $declareSymbols = null,
        ?ResolveNamesPass $resolveNames = null,
        ?ErrorResolver $errorResolver = null,
    )
    {
        $this->passes = $passes ?? [
            new CheckBindingsPass(),
            new CheckWhenExpressionsPass(),
            new CheckStrictTypesDeclarationPass(),
            new CheckTypesPass(),
            new CheckGenericTypesPass(),
            new AnalyzeTypeFlowPass(),
            new CheckWhenCallBoundariesPass(),
            new CheckErrorEffectsPass(),
        ];
        $this->declareSymbols = $declareSymbols ?? new DeclareSymbolsPass();
        $this->resolveNames = $resolveNames ?? new ResolveNamesPass();
        $this->errorResolver = $errorResolver ?? new ErrorResolver();
    }

    public function analyze(ProjectParseResult $parseResult, ?ProjectParseResult $contextResult = null): SemanticAnalysisResult
    {
        $diagnostics = new DiagnosticBag();
        $diagnostics->addAll($parseResult->diagnostics);
        $models = [];

        if (!$parseResult->isSuccessful) {
            return new SemanticAnalysisResult($models, $diagnostics);
        }

        $projectParseResult = $this->mergeParseResults($parseResult, $contextResult);
        $preliminarySymbols = new SymbolTable();
        $preliminarySymbols->registerKnownClassPrefixes($projectParseResult->knownClassPrefixes);
        $preliminarySymbols->registerClassAliases($projectParseResult->classAliases);
        $resolvedNames = new ResolvedNameTable();
        $errorContracts = new CallableErrorIndex();
        $preliminaryContext = new ProjectSemanticContext(
            $projectParseResult,
            $preliminarySymbols,
            $resolvedNames,
            new DiagnosticBag(),
            $errorContracts,
            array_fill_keys(array_keys($parseResult->parsedFiles), true),
        );
        $this->resolveNames->execute($preliminaryContext);
        $this->declareSymbols->execute($preliminaryContext);
        $preliminaryGenerics = (new GenericDeclarationIndexer())->build($projectParseResult, $preliminarySymbols);

        $symbols = new SymbolTable();
        $symbols->registerKnownClassPrefixes($projectParseResult->knownClassPrefixes);
        $symbols->registerClassAliases($projectParseResult->classAliases);
        $projectContext = new ProjectSemanticContext(
            $projectParseResult,
            $symbols,
            $resolvedNames,
            $diagnostics,
            $errorContracts,
            array_fill_keys(array_keys($parseResult->parsedFiles), true),
        );
        $this->declareSymbols->execute($projectContext, $preliminaryGenerics);
        // The bootstrap symbol graph is no longer needed once final contracts exist.
        unset($preliminaryContext, $preliminarySymbols, $preliminaryGenerics);
        $genericDeclarations = (new GenericDeclarationIndexer())->build($projectParseResult, $symbols);
        $this->errorResolver->prepare($projectContext);
        foreach ($parseResult->parsedFiles as $parsedFile) {
            if ($parsedFile->sourceFile->kind !== FileKind::Ppphp) {
                continue;
            }

            $modelDiagnostics = new DiagnosticBag();
            $model = new SemanticModel(
                $parsedFile,
                new BindingTable(),
                $modelDiagnostics,
                $errorContracts,
            );
            $context = new SemanticContext(
                $parsedFile,
                $model,
                new ScopeStack(),
                $symbols,
                $resolvedNames,
                $genericDeclarations,
            );

            foreach ($this->passes as $pass) {
                $pass->execute($context);
            }

            $models[Path::buildComparisonKey($parsedFile->sourceFile->path)] = $model;
            $diagnostics->addAll($modelDiagnostics);
        }

        return new SemanticAnalysisResult($models, $diagnostics, $symbols, $resolvedNames);
    }

    private function mergeParseResults(
        ProjectParseResult $selected,
        ?ProjectParseResult $context,
    ): ProjectParseResult {
        if ($context === null) {
            return $selected;
        }

        return new ProjectParseResult(
            array_replace($context->parsedFiles, $selected->parsedFiles),
            array_replace($context->sourceFiles, $selected->sourceFiles),
            new DiagnosticBag(),
            array_values(array_unique([
                ...$context->knownClassPrefixes,
                ...$selected->knownClassPrefixes,
            ])),
            array_replace($context->classAliases, $selected->classAliases),
            array_replace($context->classAliasProvenance, $selected->classAliasProvenance),
        );
    }

}
