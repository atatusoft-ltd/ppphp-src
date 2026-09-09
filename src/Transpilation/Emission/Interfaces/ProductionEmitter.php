<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Transpilation\Emission\Interfaces;

use Atatusoft\Ppphp\Compiler\CompilationArtifact;
use Atatusoft\Ppphp\Compiler\Output\OutputPlan;
use Atatusoft\Ppphp\Project\Project;
use Atatusoft\Ppphp\Project\ProjectCheckResult;

interface ProductionEmitter
{
    /**
     * @param array<string, CompilationArtifact> $reusedArtifacts
     * @return list<CompilationArtifact>
     */
    public function emit(Project $project, ProjectCheckResult $check, OutputPlan $plan, array $reusedArtifacts = []): array;
}
