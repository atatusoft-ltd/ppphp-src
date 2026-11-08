<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Project;

use Atatusoft\Ppphp\Cache\CacheStatistics;
use Atatusoft\Ppphp\Analysis\AnalysisResult;
use Atatusoft\Ppphp\Analysis\Enumerations\AnalysisCompleteness;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Semantic\SemanticAnalysisResult;

final class ProjectCheckResult
{
    public readonly bool $compilerEvidence;

    public readonly bool $supplementalEvidence;

    /** @param list<string> $uncoveredRequiredCapabilities */
    public function __construct(
        public readonly ?ProjectParseResult $parseResult,
        public readonly ?SemanticAnalysisResult $semanticResult,
        public readonly ?AnalysisResult $backendResult,
        public readonly DiagnosticBag $diagnostics,
        public readonly AnalysisCompleteness $completeness = AnalysisCompleteness::Full,
        public readonly array $uncoveredRequiredCapabilities = [],
        ?bool $compilerEvidence = null,
        ?bool $supplementalEvidence = null,
        public readonly ?CacheStatistics $cacheStatistics = null,
        public readonly ?ProjectParseResult $declarationContext = null,
    ) {
        $this->compilerEvidence = $compilerEvidence ?? $semanticResult !== null;
        $this->supplementalEvidence = $supplementalEvidence ?? $backendResult !== null;
    }

    public bool $isSuccessful {
        get => $this->compilerEvidence
            && ($this->supplementalEvidence || $this->completeness === AnalysisCompleteness::ExperimentalScalarProof)
            && !$this->diagnostics->hasErrors;
    }
}
