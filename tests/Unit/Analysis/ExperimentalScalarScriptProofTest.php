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
    'known-map-offset' => ['array<string, int> $scores = ["Noor" => 87]; echo $scores["Noor"];', true],
    'missing-map-offset' => ['array<string, int> $scores = ["Noor" => 87]; echo $scores["Maya"];', false],
    'loop-complete-map' => ['array<string> $names = ["Maya", "Noor"]; array<string, int> $scores = ["Maya" => 92, "Noor" => 87]; foreach ($names as string $name) { echo $name . ": " . $scores[$name]; }', true],
    'loop-incomplete-map' => ['array<string> $names = ["Maya", "Noor"]; array<string, int> $scores = ["Maya" => 92]; foreach ($names as string $name) { echo $name . ": " . $scores[$name]; }', false],
    'loop-typed-key-and-value' => ['array<string, int> $scores = ["Maya" => 92]; foreach ($scores as string $name => int $score) { echo $name, $score; }', true],
    'loop-with-assignment' => ['array<int> $xs = [1]; foreach ($xs as int $value) { $xs[] = $value; }', false],
    'loop-untracked-string-key' => ['string $key = "M"; $key = $key . "aya"; array<string> $names = ["Maya"]; $names[] = $key; array<string, int> $scores = ["Maya" => 92, "" => 1]; foreach ($names as string $name) { echo $scores[$name]; }', false],
    'verified intrinsic count' => ['array<int> $weights = [2, 5, 3]; int $size = count($weights); echo $size;', true],
    'count with named argument' => ['array<int> $weights = [2, 5, 3]; int $size = count(value: $weights); echo $size;', false],
    'count of non-array' => ['int $weight = 3; int $size = count($weight); echo $size;', false],
    'typed when checks both branches' => ['bool $flag = false; int $size = when ($flag) { return 1; } else { return 2; }; echo $size;', true],
    'when accumulation and intrinsic sibling' => ['array<int> $items = [2, 5, 3]; bool $flag = true; readonly int $size = when ($flag) { int $sum = 0; foreach ($items as int $item) { $sum += $item; } return $sum; } else { int $n = count($items); return $n; }; echo $size;', true],
    'literal when condition' => ['int $size = when (true) { return 1; } else { return 2; }; echo $size;', false],
    'reassigned when condition' => ['bool $flag = true; $flag = false; int $size = when ($flag) { return 1; } else { return 2; }; echo $size;', false],
    'unchecked when sibling call' => ['bool $flag = true; int $size = when ($flag) { return 1; } else { return missingCall(); }; echo $size;', false],
    'when branch analyzer directive' => ['bool $flag = true; int $size = when ($flag) { return 1; } else {' . "\n" . '// @phpstan-ignore-next-line' . "\n" . 'return 2; }; echo $size;', false],
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
