<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Analysis\CompilerProjectAnalyzer;
use Atatusoft\Ppphp\Analysis\Enumerations\AnalysisCompleteness;
use Atatusoft\Ppphp\Analysis\Experimental\ScalarScriptProof;
use Atatusoft\Ppphp\Config\ProjectConfigLoader;
use Atatusoft\Ppphp\Project\ProjectLoader;

test('experimental scalar evidence distinguishes supported reassignments from diagnostic-sensitive cases', function (string $source, bool $admitted): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root, ['stubs' => []]);
    $this->writeFile($root . '/src/main.ppphp', "<?php\n" . $source);
    $configuration = (new ProjectConfigLoader())->load($root, requireSourceDirectories: true)->configuration;
    expect($configuration)->not->toBeNull();
    $project = (new ProjectLoader())->load($configuration)->project;
    expect($project)->not->toBeNull();

    $analysis = (new CompilerProjectAnalyzer())->analyze($project, $project->sources);
    $check = (new ScalarScriptProof())->check($analysis);
    if ($admitted) {
        expect($check?->isSuccessful)->toBeTrue()
            ->and($check?->completeness)->toBe(AnalysisCompleteness::ExperimentalScalarProof)
            ->and($check?->supplementalEvidence)->toBeFalse();
    } else {
        expect($check)->toBeNull();
    }
})->with([
    'typed counter and ordinary comment' => ["int \$count = 0;\n\$count = \$count + 1; // increment\necho \$count;", true],
    'overflow leaves integer domain' => ['int $value = 9223372036854775807; $value = $value + 1; echo $value;', false],
    'unmatched analyzer directive' => ["echo 'hello'; // @phpstan-ignore-line\n", false],
    'readonly update' => ['readonly int $count = 0; $count = $count + 1; echo $count;', false],
]);
