<?php

declare(strict_types=1);

// BP-3 test-only orchestration. No new public command, protocol or serialized semantic model.
use Atatusoft\Ppphp\Analysis\Browser\BrowserAnalysisProtocol;
use Atatusoft\Ppphp\Analysis\Browser\PrepareAnalysisRequest;
use Atatusoft\Ppphp\Analysis\Browser\ProtocolJson;
use Atatusoft\Ppphp\Analysis\PhpStan\PhpStanProcessResult;
use Atatusoft\Ppphp\Analysis\PhpStan\PhpStanProjectAnalyzer;
use Atatusoft\Ppphp\Config\ProjectConfigLoader;
use Atatusoft\Ppphp\Diagnostics\JsonRenderer;
use Atatusoft\Ppphp\Project\Enumerations\SelectionMode;
use Atatusoft\Ppphp\Project\ProjectChecker;
use Atatusoft\Ppphp\Project\ProjectLoader;
use Atatusoft\Ppphp\Project\ProjectSelector;

try {
    if ($argc !== 3) throw new RuntimeException('Expected compiler root and test input path.');
    require $argv[1] . '/vendor/autoload.php';
    if (filesize($argv[2]) > 4_194_304) throw new RuntimeException('Oversized test completion input.');
    $input = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
    // PHP-WASM CLI does not reliably inherit the host option's cwd across invocations.
    // The fixed test-input file lives directly in the frozen project root on both hosts.
    $root = dirname(realpath($argv[2]));
    if (!chdir($root)) throw new RuntimeException('Unable to select the frozen project root.');
    $prepared = $input['prepared'];
    $request = new PrepareAnalysisRequest($prepared['requestId'], 'check', $input['selection']);
    // Reparse the current frozen source/configuration and verify the entire compiler-owned identity.
    $reconstructed = (new BrowserAnalysisProtocol())->prepare($request, $root)->toArray();
    if ($reconstructed['status'] !== 'prepared'
        || ProtocolJson::encodeCanonical($reconstructed) !== ProtocolJson::encodeCanonical($prepared)) {
        $changed = [];
        foreach ($reconstructed as $key => $value) {
            if (json_encode($value) !== json_encode($prepared[$key] ?? null)) $changed[$key] = $value;
        }
        throw new RuntimeException('Frozen preparation changed before test completion: ' . json_encode($changed));
    }
    $config = (new ProjectConfigLoader())->load($root, null, true)->configuration;
    $project = (new ProjectLoader())->load($config)->project;
    $selection = (new ProjectSelector())->select($project, $input['selection'], SelectionMode::Check)->selection;
    $checker = new ProjectChecker();
    $preparation = $checker->prepare($project, $selection->analysisSources);
    if (!$preparation->isSuccessful || $preparation->analysisProject === null) throw new RuntimeException('Reconstruction failed.');
    $analyzer = new PhpStanProjectAnalyzer();
    $plan = $analyzer->buildPlan($preparation->analysisProject, true, 'php');
    if ($plan->command !== $prepared['phpStan']['command']) throw new RuntimeException('Reconstructed analyzer command changed.');
    $observed = $input['process'];
    $process = new PhpStanProcessResult(
        $observed['command'], $observed['stdout'], $observed['stderr'], $observed['exitCode'],
        $observed['timedOut'], $observed['outputLimitExceeded'], $observed['executionFailure'],
    );
    $backend = $analyzer->complete($preparation->analysisProject, $process);
    $completed = $checker->complete($preparation, $backend);
    $renderer = new JsonRenderer();
    echo json_encode([
        'status' => $completed->isSuccessful ? 0 : 1,
        'diagnostics' => json_decode($renderer->render($completed->diagnostics), true, flags: JSON_THROW_ON_ERROR),
        'mapped' => json_decode($renderer->render($backend->diagnostics), true, flags: JSON_THROW_ON_ERROR),
        'mappedDebug' => json_decode($renderer->render($backend->diagnostics, true), true, flags: JSON_THROW_ON_ERROR),
        'analysisFiles' => array_map(static fn ($file): array => [
            'source' => $file->sourceFile->displayPath, 'sha256' => hash('sha256', $file->contents),
        ], $preparation->analysisProject->selectedFiles),
        'analysisPaths' => array_map(static fn ($file): array => [
            'path' => $file->analysisPath, 'source' => $file->sourceFile->displayPath,
        ], $preparation->analysisProject->selectedFiles),
        'identities' => array_map(static fn ($diagnostic): array => [
            'code' => $diagnostic->code->value, 'origin' => $diagnostic->origin->value,
            'identity' => $diagnostic->identity,
        ], iterator_to_array($completed->diagnostics)),
        'backendMetadata' => $backend->metadata,
        'reconstructed' => true,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(70);
}
