<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis;

use Atatusoft\Ppphp\Analysis\Capability\AnalysisCapabilityCatalog;
use Atatusoft\Ppphp\Analysis\Enumerations\AnalysisCompleteness;
use Atatusoft\Ppphp\Analysis\Experimental\ScalarScriptProof;
use Atatusoft\Ppphp\Diagnostics\DiagnosticProcessor;
use Atatusoft\Ppphp\Interop\Composer\Declaration\DependencyDeclarationProvider;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Project\Project;
use Atatusoft\Ppphp\Project\ProjectCheckResult;
use Atatusoft\Ppphp\Project\ProjectParseResult;
use Atatusoft\Ppphp\Project\ProjectSyntaxChecker;
use Atatusoft\Ppphp\Project\SourceSet;
use Atatusoft\Ppphp\Semantic\SemanticAnalyzer;
use Atatusoft\Ppphp\Semantic\Symbol\ClassSymbol;

final readonly class CompilerProjectAnalyzer
{
    public function __construct(
        private ProjectSyntaxChecker $syntaxChecker = new ProjectSyntaxChecker(),
        private SemanticAnalyzer $semanticAnalyzer = new SemanticAnalyzer(),
        private DeclarationContextCollector $declarationCollector = new DeclarationContextCollector(),
        private DiagnosticProcessor $diagnosticProcessor = new DiagnosticProcessor(),
        private AnalysisCapabilityCatalog $capabilityCatalog = new AnalysisCapabilityCatalog(),
    ) {}

    public function analyze(
        Project $project,
        SourceSet $selectedSources,
        ?DependencyDeclarationProvider $dependencyProvider = null,
    ): CompilerProjectAnalysis
    {
        return $this->analyzeParsed(
            $project,
            $selectedSources,
            $this->syntaxChecker->check($project, $selectedSources),
            $dependencyProvider,
        );
    }

    /** A speculative result may leave this method only after proof and signature verification. */
    public function analyzeWithLocalProof(
        Project $project,
        SourceSet $selectedSources,
    ): ProjectCheckResult|CompilerProjectAnalysis
    {
        $parseResult = $this->syntaxChecker->check($project, $selectedSources);
        $proof = new ScalarScriptProof();
        if ($proof->canAnalyzeLocally($project, $selectedSources, $parseResult)) {
            $local = $this->analyzeSemantics($project, $selectedSources, $parseResult, $this->emptyContext());
            $check = $proof->check($local);
            if ($check !== null && $local->semanticResult !== null) {
                $names = array_map(static fn (ClassSymbol $symbol): string => $symbol->fullyQualifiedName, $local->semanticResult->symbols->classes);
                if ($this->declarationCollector->verifyPlatform($project, $names)) {
                    return $check;
                }

                return $this->analyzeParsed($project, $selectedSources, $parseResult);
            }
        }

        $full = $this->analyzeParsed($project, $selectedSources, $parseResult);

        return $proof->check($full) ?? $full;
    }

    private function analyzeParsed(
        Project $project,
        SourceSet $selectedSources,
        ProjectParseResult $parseResult,
        ?DependencyDeclarationProvider $dependencyProvider = null,
    ): CompilerProjectAnalysis
    {

        if (!$parseResult->isSuccessful) {
            return new CompilerProjectAnalysis(
                $project,
                $selectedSources,
                $parseResult,
                $this->emptyContext(),
                null,
                $this->diagnosticProcessor->process($parseResult->diagnostics),
                AnalysisCompleteness::CompilerCore,
                $this->capabilityCatalog->uncoveredRequiredCapabilityIds,
            );
        }

        $declarationContext = $this->declarationCollector->collect(
            $project,
            $selectedSources,
            $parseResult,
            $dependencyProvider,
        );

        if (!$declarationContext->isSuccessful) {
            $diagnostics = new DiagnosticBag();
            $diagnostics->addAll($parseResult->diagnostics);
            $diagnostics->addAll($declarationContext->diagnostics);

            return new CompilerProjectAnalysis(
                $project,
                $selectedSources,
                $parseResult,
                $declarationContext,
                null,
                $this->diagnosticProcessor->process($diagnostics),
                AnalysisCompleteness::CompilerCore,
                $this->capabilityCatalog->uncoveredRequiredCapabilityIds,
            );
        }

        return $this->analyzeSemantics($project, $selectedSources, $parseResult, $declarationContext);
    }

    private function analyzeSemantics(
        Project $project,
        SourceSet $selectedSources,
        ProjectParseResult $parseResult,
        ProjectParseResult $declarationContext,
    ): CompilerProjectAnalysis
    {
        $semanticResult = $this->semanticAnalyzer->analyze($parseResult, $declarationContext);

        return new CompilerProjectAnalysis(
            $project,
            $selectedSources,
            $parseResult,
            $declarationContext,
            $semanticResult,
            $this->diagnosticProcessor->process($semanticResult->diagnostics),
            AnalysisCompleteness::CompilerCore,
            $this->capabilityCatalog->uncoveredRequiredCapabilityIds,
        );
    }

    private function emptyContext(): ProjectParseResult
    {
        return new ProjectParseResult([], [], new DiagnosticBag());
    }
}
