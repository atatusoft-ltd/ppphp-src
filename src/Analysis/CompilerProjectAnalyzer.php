<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis;

use Atatusoft\Ppphp\Analysis\Capability\AnalysisCapabilityCatalog;
use Atatusoft\Ppphp\Analysis\Enumerations\AnalysisCompleteness;
use Atatusoft\Ppphp\Diagnostics\DiagnosticProcessor;
use Atatusoft\Ppphp\Interop\Composer\Declaration\DependencyDeclarationProvider;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Project\Project;
use Atatusoft\Ppphp\Project\ProjectParseResult;
use Atatusoft\Ppphp\Project\ProjectSyntaxChecker;
use Atatusoft\Ppphp\Project\SourceSet;
use Atatusoft\Ppphp\Semantic\SemanticAnalyzer;

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
        $parseResult = $this->syntaxChecker->check($project, $selectedSources);

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
