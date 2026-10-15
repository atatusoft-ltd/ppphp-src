<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Cache\CompilerCache;
use Atatusoft\Ppphp\Compiler\Compiler;
use Atatusoft\Ppphp\Compiler\Output\OutputPlanner;
use Atatusoft\Ppphp\Compiler\Output\AtomicBuildCommitter;
use Atatusoft\Ppphp\Compiler\Output\NativeBuildFilesystem;
use Atatusoft\Ppphp\Config\ProjectConfigLoader;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Interop\Composer\ComposerRuntimeConfigurator;
use Atatusoft\Ppphp\Project\Enumerations\SelectionMode;
use Atatusoft\Ppphp\Project\ProjectChecker;
use Atatusoft\Ppphp\Project\ProjectLoader;
use Atatusoft\Ppphp\Project\ProjectSelector;
use Atatusoft\Ppphp\Support\CanonicalJson;
use Atatusoft\Ppphp\Transpilation\Emission\ProductionPhpEmitter;

/** @return array{Atatusoft\Ppphp\Project\Project, Atatusoft\Ppphp\Project\ProjectSelection} */
function loadStageThirteenDProject(string $root): array
{
    $configuration = (new ProjectConfigLoader())->load($root, null, true)->configuration;
    expect($configuration)->not->toBeNull();
    $project = (new ProjectLoader())->load($configuration)->project;
    expect($project)->not->toBeNull();
    $selection = (new ProjectSelector())->select($project, null, SelectionMode::Build)->selection;
    expect($selection)->not->toBeNull();

    return [$project, $selection];
}

function stageThirteenDCacheRecord(string $root, string $kind): string
{
    $cache = $root . '/.ppphp-cache';
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $cache,
        FilesystemIterator::SKIP_DOTS,
    ));

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || $file->isLink() || $file->getExtension() !== 'json') {
            continue;
        }

        try {
            $record = json_decode((string) file_get_contents($file->getPathname()), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            continue;
        }

        if (is_array($record) && ($record['recordKind'] ?? null) === $kind) {
            return $file->getPathname();
        }
    }

    throw new RuntimeException(sprintf('The cache record "%s" was not found.', $kind));
}

test('CLI reports verified unchanged builds without claiming fresh compilation', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', '<?php int $value = 1; echo $value;');
    $application = new Atatusoft\Ppphp\Cli\Application();
    $application->setAutoExit(false);
    $tester = new Symfony\Component\Console\Tester\ApplicationTester($application);
    $arguments = ['command' => 'build', '--working-directory' => $root, '--no-ansi' => true, '--debug' => true];
    $status = $tester->run($arguments);
    expect($status)->toBe(0, $tester->getDisplay());
    expect($tester->getDisplay())->toContain('Built 1 File Atomically.');
    $before = file_get_contents($root . '/build/ppphp/main.php');
    expect($tester->run($arguments))->toBe(0);
    expect($tester->getDisplay())->toContain('Build Is Up To Date (1 File Verified).');
    expect($tester->getDisplay())->not->toContain('Compiled');
    expect($tester->getDisplay())->not->toContain('Atomically');
    expect(file_get_contents($root . '/build/ppphp/main.php'))->toBe($before);

    $this->writeFile($root . '/build/ppphp/main.php', '<?php echo "altered";');
    expect($tester->run($arguments))->toBe(0);
    expect($tester->getDisplay())->not->toContain('Build Is Up To Date');
    expect(file_get_contents($root . '/build/ppphp/main.php'))->toBe($before);
});

test('exact warm checks and builds reuse complete verified evidence', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Value.ppphp', "<?php\nfunction cachedValue(): int { return 1; }\n");
    [$project, $selection] = loadStageThirteenDProject($root);
    $cache = new CompilerCache();
    $checker = new ProjectChecker(cache: $cache);
    $firstCheck = $checker->check($project, $selection->analysisSources);
    $secondCheck = $checker->check($project, $selection->analysisSources);

    expect($firstCheck->isSuccessful)->toBeTrue()
        ->and($secondCheck->isSuccessful)->toBeTrue()
        ->and($secondCheck->parseResult)->toBeNull()
        ->and($secondCheck->semanticResult)->toBeNull()
        ->and($secondCheck->backendResult)->toBeNull()
        ->and($secondCheck->compilerEvidence)->toBeTrue()
        ->and($secondCheck->supplementalEvidence)->toBeTrue()
        ->and($cache->statistics->parserWorkAvoided)->toBe(count($selection->analysisSources))
        ->and($cache->statistics->semanticWorkAvoided)->toBe(1)
        ->and($cache->statistics->supplementalProcessesAvoided)->toBe(1);

    $buildCache = new CompilerCache();
    $compiler = new Compiler(
        new ProjectChecker(cache: $buildCache),
        new OutputPlanner(),
        new ProductionPhpEmitter(),
        new AtomicBuildCommitter(),
        new ComposerRuntimeConfigurator(),
        cache: $buildCache,
    );
    $cold = $compiler->compile($project, $selection);
    $output = $root . '/build/ppphp/Value.php';
    $mtime = filemtime($output);
    $warm = $compiler->compile($project, $selection);

    expect($cold->isSuccessful)->toBeTrue()
        ->and($warm->isSuccessful)->toBeTrue()
        ->and($warm->upToDate)->toBeTrue()
        ->and(filemtime($output))->toBe($mtime)
        ->and($buildCache->statistics->loweringWorkAvoided)->toBe(1)
        ->and($buildCache->statistics->supplementalProcessesAvoided)->toBeGreaterThanOrEqual(1);

    (new NativeBuildFilesystem())->remove($root . '/build/ppphp');
    $reconstructed = $compiler->compile($project, $selection);

    expect($reconstructed->isSuccessful)->toBeTrue()
        ->and($reconstructed->upToDate)->toBeFalse()
        ->and(file_get_contents($output))->toContain('return 1;')
        ->and($buildCache->statistics->loweringWorkAvoided)->toBe(2);
});

test('complete warm builds replace output files outside the cached manifest', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Value.ppphp', "<?php\nfunction exactCachedTree(): int { return 1; }\n");
    [$project, $selection] = loadStageThirteenDProject($root);
    $cache = new CompilerCache();
    $compiler = new Compiler(
        checker: new ProjectChecker(cache: $cache),
        cache: $cache,
    );

    expect($compiler->compile($project, $selection)->isSuccessful)->toBeTrue();
    $this->writeFile($root . '/build/ppphp/Stale.php', "<?php\nfunction staleCachedOutput(): void {}\n");
    $repaired = $compiler->compile($project, $selection);

    expect($repaired->isSuccessful)->toBeTrue()
        ->and($repaired->upToDate)->toBeFalse()
        ->and($repaired->staleRemovalCount)->toBe(1)
        ->and(file_exists($root . '/build/ppphp/Stale.php'))->toBeFalse()
        ->and(file_get_contents($root . '/build/ppphp/Value.php'))->toContain('return 1;');
});

test('exact compiler source failures are replayed without supplemental work', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Invalid.ppphp', "<?php\nint \$value = 'invalid';\n");
    [$project, $selection] = loadStageThirteenDProject($root);
    $cache = new CompilerCache();
    $checker = new ProjectChecker(cache: $cache);
    $cold = $checker->check($project, $selection->analysisSources);
    $warm = $checker->check($project, $selection->analysisSources);

    expect($cold->isSuccessful)->toBeFalse()
        ->and($warm->isSuccessful)->toBeFalse()
        ->and($warm->parseResult)->toBeNull()
        ->and($warm->semanticResult)->toBeNull()
        ->and($warm->backendResult)->toBeNull()
        ->and($warm->compilerEvidence)->toBeTrue()
        ->and($warm->supplementalEvidence)->toBeFalse()
        ->and($warm->diagnostics->errors[0]->code->value ?? null)->toBe('P2008')
        ->and($cache->statistics->parserWorkAvoided)->toBe(1)
        ->and($cache->statistics->semanticWorkAvoided)->toBe(1)
        ->and($cache->statistics->supplementalProcessesAvoided)->toBe(0);
});

test('checks fall back to normal diagnostics when an input cannot be snapshotted', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $source = $root . '/src/Vanished.ppphp';
    $this->writeFile($source, "<?php\nfunction vanishedCacheInput(): void {}\n");
    [$project, $selection] = loadStageThirteenDProject($root);
    unlink($source);

    $result = (new ProjectChecker())->check($project, $selection->analysisSources);

    expect($result->isSuccessful)->toBeFalse()
        ->and($result->diagnostics->errors[0]->code ?? null)->toBe(DiagnosticCode::SourceFileNotReadable);
});

test('body edits reuse unchanged artifacts while declaration edits invalidate the project boundary', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/First.ppphp', "<?php\nfunction firstCachedValue(): int { return 1; }\n");
    $this->writeFile($root . '/src/Second.ppphp', "<?php\nfunction secondCachedValue(): int { return 2; }\n");
    [$project, $selection] = loadStageThirteenDProject($root);
    $cache = new CompilerCache();
    $compiler = new Compiler(
        new ProjectChecker(cache: $cache),
        new OutputPlanner(),
        new ProductionPhpEmitter(),
        new AtomicBuildCommitter(),
        new ComposerRuntimeConfigurator(),
        cache: $cache,
    );

    expect($compiler->compile($project, $selection)->isSuccessful)->toBeTrue();

    $this->writeFile($root . '/src/First.ppphp', "<?php\nfunction firstCachedValue(): int { return 3; }\n");
    [$bodyProject, $bodySelection] = loadStageThirteenDProject($root);
    $bodyEdit = $compiler->compile($bodyProject, $bodySelection);

    expect($bodyEdit->isSuccessful)->toBeTrue()
        ->and($cache->statistics->loweringWorkAvoided)->toBe(1)
        ->and($cache->statistics->hits)->toBeGreaterThanOrEqual(4)
        ->and(file_get_contents($root . '/build/ppphp/First.php'))->toContain('return 3;')
        ->and(file_get_contents($root . '/build/ppphp/Second.php'))->toContain('return 2;');

    $this->writeFile($root . '/src/First.ppphp', "<?php\nfunction firstCachedValue(): string { return 'three'; }\n");
    [$declarationProject, $declarationSelection] = loadStageThirteenDProject($root);
    $declarationEdit = $compiler->compile($declarationProject, $declarationSelection);

    expect($declarationEdit->isSuccessful)->toBeTrue()
        ->and($cache->statistics->loweringWorkAvoided)->toBe(1)
        ->and(file_get_contents($root . '/build/ppphp/First.php'))->toContain("return 'three';");
});

test('declaration-shape edits conservatively invalidate reusable artifact units', function (
    string $before,
    string $after,
): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Contract.ppphp', $before);
    $this->writeFile($root . '/src/Consumer.ppphp', "<?php\nfunction unrelatedCachedConsumer(): int { return 2; }\n");
    [$project, $selection] = loadStageThirteenDProject($root);
    $cache = new CompilerCache();
    $compiler = new Compiler(
        new ProjectChecker(cache: $cache),
        new OutputPlanner(),
        new ProductionPhpEmitter(),
        new AtomicBuildCommitter(),
        new ComposerRuntimeConfigurator(),
        cache: $cache,
    );

    expect($compiler->compile($project, $selection)->isSuccessful)->toBeTrue();
    $this->writeFile($root . '/src/Contract.ppphp', $after);
    [$changedProject, $changedSelection] = loadStageThirteenDProject($root);
    $changed = $compiler->compile($changedProject, $changedSelection);

    expect($changed->isSuccessful)->toBeTrue()
        ->and($cache->statistics->loweringWorkAvoided)->toBe(0);
})->with([
    'checked error' => [
        "<?php\nclass FirstCacheFailure extends Exception {}\nclass SecondCacheFailure extends Exception {}\nfunction cachedRisk(): void throws FirstCacheFailure {}\n",
        "<?php\nclass FirstCacheFailure extends Exception {}\nclass SecondCacheFailure extends Exception {}\nfunction cachedRisk(): void throws SecondCacheFailure {}\n",
    ],
    'generic bound' => [
        "<?php\nclass FirstCacheEntity {}\nclass SecondCacheEntity {}\nclass CachedBox<T : FirstCacheEntity> {}\n",
        "<?php\nclass FirstCacheEntity {}\nclass SecondCacheEntity {}\nclass CachedBox<T : SecondCacheEntity> {}\n",
    ],
    'property type' => [
        "<?php\nclass CachedProperty { public int \$value = 1; }\n",
        "<?php\nclass CachedProperty { public string \$value = 'one'; }\n",
    ],
    'namespace and import' => [
        "<?php\nnamespace FirstCacheNamespace;\nuse DateTimeImmutable as CachedClock;\nfunction cachedClock(CachedClock \$clock): CachedClock { return \$clock; }\n",
        "<?php\nnamespace SecondCacheNamespace;\nuse DateTime as CachedClock;\nfunction cachedClock(CachedClock \$clock): CachedClock { return \$clock; }\n",
    ],
]);

test('source addition deletion and rename update complete build output', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/First.ppphp', "<?php\nfunction firstSourceValue(): int { return 1; }\n");
    [$project, $selection] = loadStageThirteenDProject($root);
    $cache = new CompilerCache();
    $compiler = new Compiler(
        new ProjectChecker(cache: $cache),
        new OutputPlanner(),
        new ProductionPhpEmitter(),
        new AtomicBuildCommitter(),
        new ComposerRuntimeConfigurator(),
        cache: $cache,
    );

    expect($compiler->compile($project, $selection)->isSuccessful)->toBeTrue();

    $this->writeFile($root . '/src/Added.ppphp', "<?php\nfunction addedSourceValue(): int { return 2; }\n");
    [$addedProject, $addedSelection] = loadStageThirteenDProject($root);
    expect($compiler->compile($addedProject, $addedSelection)->isSuccessful)->toBeTrue()
        ->and(file_exists($root . '/build/ppphp/Added.php'))->toBeTrue();

    unlink($root . '/src/First.ppphp');
    [$deletedProject, $deletedSelection] = loadStageThirteenDProject($root);
    expect($compiler->compile($deletedProject, $deletedSelection)->isSuccessful)->toBeTrue()
        ->and(file_exists($root . '/build/ppphp/First.php'))->toBeFalse();

    rename($root . '/src/Added.ppphp', $root . '/src/Renamed.ppphp');
    [$renamedProject, $renamedSelection] = loadStageThirteenDProject($root);
    expect($compiler->compile($renamedProject, $renamedSelection)->isSuccessful)->toBeTrue()
        ->and(file_exists($root . '/build/ppphp/Added.php'))->toBeFalse()
        ->and(file_exists($root . '/build/ppphp/Renamed.php'))->toBeTrue();
});

test('corrupt compiler supplemental bundle and artifact evidence recompute safely', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Value.ppphp', "<?php\nfunction corruptionValue(): int { return 1; }\n");
    [$project, $selection] = loadStageThirteenDProject($root);
    $cache = new CompilerCache();
    $checker = new ProjectChecker(cache: $cache);
    $compiler = new Compiler(
        $checker,
        new OutputPlanner(),
        new ProductionPhpEmitter(),
        new AtomicBuildCommitter(),
        new ComposerRuntimeConfigurator(),
        cache: $cache,
    );
    $cold = $compiler->compile($project, $selection);
    $expectedOutput = file_get_contents($root . '/build/ppphp/Value.php');

    expect($cold->isSuccessful)->toBeTrue()
        ->and($expectedOutput)->toBeString();

    foreach (['compiler-check', 'phpstan-check'] as $kind) {
        $this->writeFile(stageThirteenDCacheRecord($root, $kind), '{');
        $check = $checker->check($project, $selection->analysisSources);
        expect($check->isSuccessful)->toBeTrue();
    }

    $this->writeFile(stageThirteenDCacheRecord($root, 'artifact-bundle'), '{');
    expect($compiler->compile($project, $selection)->isSuccessful)->toBeTrue();

    $bundlePath = stageThirteenDCacheRecord($root, 'artifact-bundle');
    $bundle = json_decode((string) file_get_contents($bundlePath), true, flags: JSON_THROW_ON_ERROR);
    $contentBlob = $bundle['payload']['artifacts'][0]['contentBlob'] ?? null;
    expect($contentBlob)->toBeString();

    if (!is_string($contentBlob)) {
        throw new RuntimeException('The cached artifact blob identity is unavailable.');
    }

    $hash = substr($contentBlob, 7);
    $blobPath = $root . '/.ppphp-cache/compiler/v1/blobs/' . substr($hash, 0, 2) . '/' . $hash . '.blob';
    $this->writeFile($blobPath, 'corrupt');
    $recomputed = $compiler->compile($project, $selection);

    expect($recomputed->isSuccessful)->toBeTrue()
        ->and(file_get_contents($root . '/build/ppphp/Value.php'))->toBe($expectedOutput)
        ->and($cache->statistics->corruptEntries)->toBeGreaterThanOrEqual(4);
});

test('cached artifact bundles require every selected output artifact', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/First.ppphp', "<?php\nfunction firstCompleteBundleValue(): int { return 1; }\n");
    $this->writeFile($root . '/src/Second.ppphp', "<?php\nfunction secondCompleteBundleValue(): int { return 2; }\n");
    [$project, $selection] = loadStageThirteenDProject($root);
    $cache = new CompilerCache();
    $compiler = new Compiler(
        checker: new ProjectChecker(cache: $cache),
        cache: $cache,
    );

    expect($compiler->compile($project, $selection)->isSuccessful)->toBeTrue();
    $recordPath = stageThirteenDCacheRecord($root, 'artifact-bundle');
    $record = json_decode((string) file_get_contents($recordPath), true, flags: JSON_THROW_ON_ERROR);
    array_pop($record['payload']['artifacts']);
    $this->writeFile($recordPath, CanonicalJson::encode($record));
    (new NativeBuildFilesystem())->remove($root . '/build/ppphp');
    $recomputed = $compiler->compile($project, $selection);

    expect($recomputed->isSuccessful)->toBeTrue()
        ->and(file_exists($root . '/build/ppphp/First.php'))->toBeTrue()
        ->and(file_exists($root . '/build/ppphp/Second.php'))->toBeTrue()
        ->and($cache->statistics->corruptEntries)->toBeGreaterThanOrEqual(1);
});
