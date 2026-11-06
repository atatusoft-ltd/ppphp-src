<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Browser;

use Atatusoft\Ppphp\Cache\ProjectInputSnapshot;
use Atatusoft\Ppphp\Compiler\ProductionPreparation;
use Atatusoft\Ppphp\Project\SupplementalAnalysisPreparation;
use Atatusoft\Ppphp\Project\SupplementalAnalysisRun;

/** Live state of one browser operation. It is never serialized. */
final class WorkflowProgress
{
    public private(set) ?string $continuation = null;
    public private(set) ?SupplementalAnalysisPreparation $preparation = null;
    public private(set) ?SupplementalAnalysisRun $analysis = null;
    public private(set) ?ProductionPreparation $production = null;
    public private(set) ?ProjectInputSnapshot $cacheSnapshot = null;

    public function retain(WorkflowSession $session, SupplementalAnalysisPreparation $preparation, ?SupplementalAnalysisRun $analysis): void
    {
        $this->continuation = $session->continuation;
        $this->preparation = $preparation;
        $this->analysis = $analysis;
    }

    public function retainProduction(ProductionPreparation $production, ?ProjectInputSnapshot $cacheSnapshot): void
    {
        $this->production = $production;
        $this->cacheSnapshot = $cacheSnapshot;
    }

    public function discard(): void
    {
        $this->continuation = null;
        $this->preparation = null;
        $this->analysis = null;
        $this->production = null;
        $this->cacheSnapshot = null;
    }
}
