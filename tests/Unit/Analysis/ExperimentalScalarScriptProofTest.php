<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Analysis\CompilerProjectAnalysis;
use Atatusoft\Ppphp\Analysis\CompilerProjectAnalyzer;
use Atatusoft\Ppphp\Analysis\DeclarationContextCollector;
use Atatusoft\Ppphp\Analysis\Enumerations\AnalysisCompleteness;
use Atatusoft\Ppphp\Analysis\Experimental\ScalarScriptProof;
use Atatusoft\Ppphp\Config\ProjectConfigLoader;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Interop\Php\Signature\PhpSignaturePackageLoader;
use Atatusoft\Ppphp\Project\ProjectCheckResult;
use Atatusoft\Ppphp\Project\ProjectLoader;
use Tests\Support\StageElevenProject;

test('experimental scalar evidence distinguishes supported scripts from diagnostic-sensitive cases', function (string $source, bool $admitted): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root, ['stubs' => []]);
    $this->writeFile($root . '/src/main.ppphp', "<?php\n" . $source);
    $configuration = (new ProjectConfigLoader())->load($root, requireSourceDirectories: true)->configuration;
    expect($configuration)->not->toBeNull();
    $project = (new ProjectLoader())->load($configuration)->project;
    expect($project)->not->toBeNull();

    $analyzer = new CompilerProjectAnalyzer();
    $analysis = $analyzer->analyze($project, $project->sources);
    $local = $analyzer->analyzeWithLocalProof($project, $project->sources);
    $check = (new ScalarScriptProof())->check($analysis);
    if ($admitted) {
        expect($check?->isSuccessful)->toBeTrue()
            ->and($check?->completeness)->toBe(AnalysisCompleteness::ExperimentalScalarProof)
            ->and($check?->supplementalEvidence)->toBeFalse()
            ->and($local)->toBeInstanceOf(ProjectCheckResult::class)
            ->and($local->declarationContext?->parsedFiles)->toBe([])
            ->and($local->completeness)->toBe(AnalysisCompleteness::ExperimentalScalarProof);
    } else {
        expect($check)->toBeNull()
            ->and($local)->toBeInstanceOf(CompilerProjectAnalysis::class)
            ->and($local->declarationContext->parsedFiles)->not->toBeEmpty();
    }
})->with([
    'typed counter and ordinary comment' => ["int \$count = 0;\n\$count = \$count + 1; // increment\necho \$count;", true],
    'overflow leaves integer domain' => ['int $value = 9223372036854775807; $value = $value + 1; echo $value;', false],
    'unmatched analyzer directive' => ["echo 'hello'; // @phpstan-ignore-line\n", false],
    'typed list append with trailing comment' => ["array<int> \$ids = [1, 2, 3];\narray<string, int> \$scores = ['Maya' => 92];\n\$ids[] = 4; // fixed\n", true],
    'invalid typed list append' => ['array<int> $ids = [1]; $ids[] = "x";', false],
    'numeric string key may change type' => ['array<int> $ids = [1]; array<string, int> $scores = ["1" => 92]; $ids[] = 4;', false],
    'duplicate map key' => ['array<int> $ids = [1]; array<string, int> $scores = ["Maya" => 92, "Maya" => 1]; $ids[] = 4;', false],
    'builtin function' => ['echo strlen("hello");', false],
    'builtin class' => ['echo (new DateTimeImmutable())->format("c");', false],
    'readonly update' => ['readonly int $count = 0; $count = $count + 1; echo $count;', false],
]);

test('a closed script requires the verified platform package even when it does not use declarations', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root, ['stubs' => []]);
    $this->writeFile($root . '/src/main.ppphp', "<?php echo 'hello';");
    $configuration = (new ProjectConfigLoader())->load($root, requireSourceDirectories: true)->configuration;
    expect($configuration)->not->toBeNull();
    $project = (new ProjectLoader())->load($configuration)->project;
    expect($project)->not->toBeNull();

    $signatures = $root . '/platform';
    StageElevenProject::copyTree(dirname(__DIR__, 3) . '/resources/php-signatures/8.4', $signatures . '/8.4');
    $analyzer = new CompilerProjectAnalyzer(declarationCollector: new DeclarationContextCollector(
        phpSignatures: new PhpSignaturePackageLoader($signatures),
    ));
    expect($analyzer->analyzeWithLocalProof($project, $project->sources))->toBeInstanceOf(ProjectCheckResult::class);

    $shard = $signatures . '/8.4/extensions/zlib.json';
    $contents = file_get_contents($shard);
    $this->writeFile($shard, ' ' . $contents);
    $broken = $analyzer->analyzeWithLocalProof($project, $project->sources);
    expect($broken)->toBeInstanceOf(CompilerProjectAnalysis::class)
        ->and($broken->isSuccessful)->toBeFalse()
        ->and($broken->diagnostics->errors[0]->code)->toBe(DiagnosticCode::PhpSignaturePackageInvalid);

    $this->writeFile($shard, $contents);
    expect($analyzer->analyzeWithLocalProof($project, $project->sources))->toBeInstanceOf(ProjectCheckResult::class);
});
