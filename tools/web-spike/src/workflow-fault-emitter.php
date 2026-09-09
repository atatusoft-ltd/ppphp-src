<?php

declare(strict_types=1);

// Qualification only. The production CLI never loads this file or accepts a fault option.
use Atatusoft\Ppphp\Analysis\Browser\WorkflowProtocol;
use Atatusoft\Ppphp\Analysis\Browser\WorkflowRequestDecoder;
use Atatusoft\Ppphp\Compiler\CompilationArtifact;
use Atatusoft\Ppphp\Compiler\Compiler;
use Atatusoft\Ppphp\Compiler\Output\OutputPlan;
use Atatusoft\Ppphp\Project\Project;
use Atatusoft\Ppphp\Project\ProjectCheckResult;
use Atatusoft\Ppphp\Transpilation\Emission\Interfaces\ProductionEmitter;
use Atatusoft\Ppphp\Transpilation\Emission\ProductionPhpEmitter;

require '/opt/ppphp/vendor/autoload.php';
chdir('/workspace');
$emitter = new class implements ProductionEmitter {
    public function emit(Project $project, ProjectCheckResult $check, OutputPlan $plan, array $reusedArtifacts = []): array
    {
        return array_map(static function (CompilationArtifact $artifact): CompilationArtifact {
            $contents = str_replace('= 1;', '= );', $artifact->contents);
            return new CompilationArtifact($artifact->projectSource, $artifact->sourceFile, $artifact->operation,
                $artifact->outputPath, $artifact->relativeOutputPath, $contents, $artifact->sourceMap,
                $artifact->sourceHash, 'sha256:' . hash('sha256', $contents), $artifact->mode);
        }, (new ProductionPhpEmitter())->emit($project, $check, $plan));
    }
};
$request = (new WorkflowRequestDecoder())->decode(file_get_contents('/workspace/.ppphp-browser/request.json'));
echo json_encode((new WorkflowProtocol(new Compiler(emitter: $emitter)))->handle($request, '/workspace'), JSON_THROW_ON_ERROR);
