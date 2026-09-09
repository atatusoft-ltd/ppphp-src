<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Analysis\Browser\WorkflowRequestDecoder;
use Atatusoft\Ppphp\Analysis\Browser\WorkflowValues;
use Atatusoft\Ppphp\Cli\Application;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Process\Process;

function workflowStart(string $id, int $sequence = 1, string $operation = 'build', ?string $path = null): array
{
    return ['version' => 3, 'action' => 'start', 'operationId' => $id, 'sequence' => $sequence,
        'operation' => $operation, 'selection' => ['path' => $path], 'runtime' => [
            'phpVersion' => PHP_VERSION, 'sapi' => PHP_SAPI, 'intSize' => PHP_INT_SIZE,
            'artifact' => 'sha256:' . hash_file('sha256', PHP_BINARY), 'loader' => 'sha256:' . hash_file('sha256', PHP_BINARY),
        ]];
}

function workflowRequest(string $root, array $request): array
{
    $cwd = getcwd();
    $root = realpath($root);
    if (!is_dir($root . '/.ppphp-browser')) mkdir($root . '/.ppphp-browser');
    file_put_contents($root . '/.ppphp-browser/request.json', json_encode($request, JSON_THROW_ON_ERROR));
    chdir($root);
    try {
        $app = new Application();
        $app->setAutoExit(false);
        $tester = new ApplicationTester($app);
        $tester->run(['command' => 'browser:analysis', 'request' => '.ppphp-browser/request.json', '--no-ansi' => true]);
        $response = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        return ['transport' => $tester->getStatusCode(), ...$response];
    } finally { chdir($cwd); }
}

function workflowContinue(array $response, ?array $results = null): array
{
    if ($results === null) {
        $results = [];
        foreach ($response['invocations'] as $invocation) {
            $command = $invocation['command'];
            $command[0] = PHP_BINARY;
            $process = new Process($command, $invocation['workingDirectory'], timeout: 60);
            $process->run();
            $results[] = ['invocation' => $invocation['identity'], 'stdout' => $process->getOutput(), 'stderr' => $process->getErrorOutput(),
                'exitCode' => $process->getExitCode(), 'complete' => true, 'timedOut' => false,
                'outputLimitExceeded' => false, 'executionFailure' => null];
        }
    }
    return ['version' => 3, 'action' => $response['status'] === 'pending-analysis' ? 'complete-analysis' : 'complete-lint',
        'operationId' => $response['operationId'], 'sequence' => $response['sequence'],
        'continuation' => $response['continuation'], 'results' => $results];
}

function workflowFinish(string $root, array $request): array
{
    $response = workflowRequest($root, $request);
    for ($i = 0; $i < 3 && str_starts_with($response['status'], 'pending-'); $i++) {
        $response = workflowRequest($root, workflowContinue($response));
    }
    return $response;
}

function workflowOutput(string $root): array
{
    $files = [];
    foreach ((new Atatusoft\Ppphp\Compiler\Output\NativeBuildFilesystem())->listFiles($root . '/build/ppphp') as $path) {
        $files[$path] = file_get_contents($root . '/build/ppphp/' . $path);
    }
    return $files;
}

test('full workflow publishes A preserves it on B publishes C and rejects replay', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', "<?php\nint \$value = 1;\necho \$value;\n");
    $this->writeFile($root . '/src/old.php', "<?php\n// copied exactly\necho 'old';\n");
    $prepared = workflowRequest($root, workflowStart('A'));
    expect($prepared['status'])->toBe('pending-analysis', json_encode($prepared));
    $analysis = workflowContinue($prepared);
    $pending = workflowRequest($root, $analysis);
    expect($pending['status'])->toBe('pending-validation', json_encode($pending));
    expect(workflowOutput($root))->toBe([]);
    $validation = workflowContinue($pending);
    $a = workflowRequest($root, $validation);
    expect($a['status'])->toBe('complete', json_encode($a))->and($a['compilerStatus'])->toBe(0);
    $outputA = workflowOutput($root);
    expect($outputA['old.php'])->toBe(file_get_contents($root . '/src/old.php'));
    $this->writeFile($root . '/src/main.ppphp', "<?php\nint \$value = 'wrong';\n");
    $b = workflowFinish($root, workflowStart('B', 2));
    expect($b['status'])->toBe('diagnostics')->and($b['compilerStatus'])->toBe(1)
        ->and($b['currentOutput'])->toBeNull()->and(workflowOutput($root))->toBe($outputA);
    expect(json_decode(file_get_contents($root . '/.ppphp-browser/published.json'), true)['snapshot'])->toBe($a['snapshot']);
    unlink($root . '/src/old.php');
    $this->writeFile($root . '/src/new.php', "<?php\n// new copied bytes\necho 'new';\n");
    $this->writeFile($root . '/src/main.ppphp', "<?php\nint \$value = 3;\necho \$value;\n");
    $c = workflowFinish($root, workflowStart('C', 3));
    expect($c['status'])->toBe('complete', json_encode($c))->and($c['compilerStatus'])->toBe(0);
    $outputC = workflowOutput($root);
    expect($outputC)->not->toHaveKey('old.php')->and($outputC['new.php'])->toBe(file_get_contents($root . '/src/new.php'));
    expect(workflowRequest($root, $analysis)['status'])->toBe('rejected')
        ->and(workflowRequest($root, $validation)['status'])->toBe('rejected')
        ->and(workflowOutput($root))->toBe($outputC);
    $repeat = workflowFinish($root, workflowStart('repeat', 4));
    expect($repeat['status'])->toBe('complete')->and(workflowOutput($root))->toBe($outputC);
});

test('workflow Check never publishes and lint never executes top level code', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/sentinel.php', "<?php\nfile_put_contents(__DIR__ . '/executed', 'BAD');\necho 'DO NOT EXECUTE';\n");
    $check = workflowFinish($root, workflowStart('check', operation: 'check'));
    expect($check['status'])->toBe('complete', json_encode($check))->and(workflowOutput($root))->toBe([]);
    $build = workflowFinish($root, workflowStart('build', 2));
    expect($build['status'])->toBe('complete', json_encode($build));
    expect(file_exists($root . '/src/executed'))->toBeFalse()
        ->and(file_exists($root . '/build/ppphp/executed'))->toBeFalse()
        ->and(file_exists($root . '/.ppphp-browser/pending'))->toBeFalse();
});

test('workflow rejects missing duplicated and mutated validation then recovers', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', "<?php\nint \$value = 1;\n");
    $prepared = workflowRequest($root, workflowStart('mutate'));
    $pending = workflowRequest($root, workflowContinue($prepared));
    expect($pending['status'])->toBe('pending-validation', json_encode($pending));
    $validation = workflowContinue($pending);
    expect(workflowRequest($root, [...$validation, 'results' => []])['status'])->toBe('rejected');
    expect(workflowRequest($root, [...$validation, 'results' => [...$validation['results'], ...$validation['results']]])['status'])->toBe('rejected');
    file_put_contents($root . '/.ppphp-browser/pending/main.php', '<?php echo "mutated";');
    expect(workflowRequest($root, $validation)['status'])->toBe('rejected')->and(workflowOutput($root))->toBe([]);
    expect(workflowFinish($root, workflowStart('recover', 2))['status'])->toBe('complete');
});

test('workflow rejects input set mutations and competing starts invalidate old completion', function (string $mutation): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', "<?php\nint \$value = 1;\n");
    $prepared = workflowRequest($root, workflowStart('old', path: 'src/main.ppphp'));
    $completion = workflowContinue($prepared);
    match ($mutation) {
        'add' => $this->writeFile($root . '/src/added.php', '<?php'),
        'remove' => unlink($root . '/src/main.ppphp'),
        'rename' => rename($root . '/src/main.ppphp', $root . '/src/renamed.ppphp'),
        'unselected' => $this->writeFile($root . '/src/context.php', '<?php class ChangedContext {}'),
        'config' => file_put_contents($root . '/ppphp.json', "\n", FILE_APPEND),
        'stub' => $this->writeFile($root . '/stubs/new.php', '<?php'),
        'dependency' => $this->writeFile($root . '/composer.lock', '{}'),
    };
    expect(workflowRequest($root, $completion)['status'])->toBe('rejected')->and(workflowOutput($root))->toBe([]);
})->with(['add', 'remove', 'rename', 'unselected', 'config', 'stub', 'dependency']);

test('workflow decoder rejects inappropriate fields unsafe paths and malformed hashes', function (): void {
    $decoder = new WorkflowRequestDecoder();
    $start = workflowStart('valid');
    foreach ([['extra' => true], ['operation' => 'run'], ['sequence' => '1'], ['selection' => ['path' => '../outside']],
        ['runtime' => [...$start['runtime'], 'artifact' => str_repeat('a', 64)]]] as $edit) {
        expect(fn () => $decoder->decode(json_encode([...$start, ...$edit])))->toThrow(InvalidArgumentException::class);
    }
    expect(fn () => $decoder->decode('{'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => WorkflowValues::readPath('/absolute'))->toThrow(InvalidArgumentException::class);
});

test('workflow continuation results must be JSON arrays rather than numeric-keyed objects', function (): void {
    $record = ['invocation' => 'sha256:' . str_repeat('a', 64), 'stdout' => '', 'stderr' => '',
        'exitCode' => 0, 'complete' => true, 'timedOut' => false, 'outputLimitExceeded' => false, 'executionFailure' => null];
    foreach (['complete-analysis', 'complete-lint', 'abort'] as $action) {
        $request = ['version' => 3, 'action' => $action, 'operationId' => 'shape', 'sequence' => 1,
            'continuation' => 'sha256:' . str_repeat('b', 64), 'results' => []];
        expect((new WorkflowRequestDecoder())->decode(json_encode($request))->results)->toBe([]);
        foreach ([new stdClass(), (object) [$record]] as $object) {
            $request['results'] = $object;
            expect(fn () => (new WorkflowRequestDecoder())->decode(json_encode($request)))
                ->toThrow(InvalidArgumentException::class);
        }
    }
});

test('workflow duplicate-key detection handles large plain and escaped strings', function (): void {
    foreach ([str_repeat('x', 1_000_000), str_repeat('\\\\"', 300_000)] as $value) {
        $json = json_encode(['value' => $value, 'phase' => 1], JSON_THROW_ON_ERROR);
        expect(Atatusoft\Ppphp\Analysis\Browser\WorkflowJson::decode($json))->toBe(['value' => $value, 'phase' => 1]);
        $duplicate = substr($json, 0, -1) . ',"\\u0070hase":2}';
        expect(fn () => Atatusoft\Ppphp\Analysis\Browser\WorkflowJson::decode($duplicate))
            ->toThrow(InvalidArgumentException::class, 'Duplicate');
    }
});

test('workflow rejects escaped duplicate JSON keys and malformed result primitives', function (): void {
    $json = json_encode(workflowStart('duplicates'));
    $duplicate = str_replace('"version":3', '"version":3,"\\u0076ersion":3', $json);
    expect(fn () => (new WorkflowRequestDecoder())->decode($duplicate))->toThrow(InvalidArgumentException::class, 'Duplicate');
    $record = ['invocation' => 'sha256:' . str_repeat('a', 64), 'stdout' => '', 'stderr' => '', 'exitCode' => 0,
        'complete' => true, 'timedOut' => false, 'outputLimitExceeded' => false, 'executionFailure' => null];
    foreach ([['exitCode' => '0'], ['complete' => 1], ['stdout' => []], ['unknown' => true],
        ['stdout' => str_repeat('x', 2_097_153)]] as $edit) {
        expect(fn () => Atatusoft\Ppphp\Analysis\Browser\WorkflowProcessResult::decode([...$record, ...$edit]))->toThrow(InvalidArgumentException::class);
    }
});

test('competing workflow starts and explicit abort revoke outstanding continuations', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', '<?php int $value = 1;');
    $first = workflowRequest($root, workflowStart('first'));
    $delayed = workflowContinue($first);
    $second = workflowRequest($root, workflowStart('second', 2));
    expect(workflowRequest($root, $delayed)['status'])->toBe('rejected');
    $abort = ['version' => 3, 'action' => 'abort', 'operationId' => $second['operationId'], 'sequence' => 2,
        'continuation' => $second['continuation'], 'results' => []];
    expect(workflowRequest($root, $abort)['status'])->toBe('aborted')
        ->and(workflowRequest($root, $abort)['status'])->toBe('rejected')
        ->and(workflowFinish($root, workflowStart('recovered', 3))['status'])->toBe('complete');
});

test('labelled lint transport failures preserve published output and remain recoverable', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', '<?php int $value = 1;');
    expect(workflowFinish($root, workflowStart('baseline'))['status'])->toBe('complete');
    $before = workflowOutput($root);
    $sequence = 1;
    foreach ([
        'timeout' => ['timedOut' => true, 'exitCode' => null],
        'overflow' => ['outputLimitExceeded' => true],
        'abnormal exit' => ['exitCode' => 7],
        'incomplete' => ['complete' => false],
        'truncated success' => ['stdout' => 'No syntax'],
        'stderr noise' => ['stderr' => 'unexpected warning'],
        'launch failure' => ['executionFailure' => 'synthetic launch failure'],
    ] as $label => $fault) {
        $start = workflowRequest($root, workflowStart('fault-' . (++$sequence), $sequence));
        $pending = workflowRequest($root, workflowContinue($start));
        $validation = workflowContinue($pending);
        $validation['results'][0] = [...$validation['results'][0], ...$fault];
        $result = workflowRequest($root, $validation);
        expect($result['status'])->toBe('output-failure', $label . ': ' . json_encode($result))
            ->and($result['compilerStatus'])->toBe(3)->and(workflowOutput($root))->toBe($before);
    }
    expect(workflowFinish($root, workflowStart('final-recovery', ++$sequence))['status'])->toBe('complete');
});

test('real nonfatal PHP lint warnings retain native mixed-build success', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $source = "<?php\nuse DateTime;\nfunction useful(): DateTime { return new DateTime(); }\n";
    $this->writeFile($root . '/src/legacy.php', $source);
    $start = workflowRequest($root, workflowStart('lint-warning'));
    $pending = workflowRequest($root, workflowContinue($start));
    expect($pending['status'])->toBe('pending-validation', json_encode($pending));
    $lint = workflowContinue($pending);
    expect($lint['results'][0]['exitCode'])->toBe(0)
        ->and($lint['results'][0]['stdout'])->toContain('Warning:', 'No syntax errors detected');
    $complete = workflowRequest($root, $lint);
    expect($complete['status'])->toBe('complete', json_encode($complete))
        ->and(workflowOutput($root)['legacy.php'])->toBe($source);
});

test('partial workflows preserve valid unselected outputs and reject incompatible manifests', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Context/Value.ppphp', '<?php namespace App; final class Value { public function __construct(public string $name) {} }');
    $this->writeFile($root . '/src/Use/main.ppphp', '<?php namespace Consumer; function read(\App\Value $value): string { return $value->name; }');
    $this->writeFile($root . '/src/copy.php', "<?php\r\n// café\r\necho 'copy';\r\n");
    expect(workflowFinish($root, workflowStart('full'))['status'])->toBe('complete');
    $before = workflowOutput($root);
    $this->writeFile($root . '/src/copy.php', "<?php\r\n// café\r\necho 'changed';\r\n");
    $partial = workflowFinish($root, workflowStart('copy', 2, path: 'src/copy.php'));
    expect($partial['status'])->toBe('complete', json_encode($partial));
    $after = workflowOutput($root);
    expect($after['Context/Value.php'])->toBe($before['Context/Value.php'])
        ->and($after['Use/main.php'])->toBe($before['Use/main.php'])
        ->and($after['copy.php'])->toBe(file_get_contents($root . '/src/copy.php'));
    file_put_contents($root . '/build/ppphp/.ppphp/manifest.json', '{}');
    $invalid = workflowOutput($root);
    $rejected = workflowFinish($root, workflowStart('bad-manifest', 3, path: 'src/Use'));
    expect($rejected['status'])->toBe('output-failure')->and(workflowOutput($root))->toBe($invalid);
    expect(workflowFinish($root, workflowStart('repair', 4))['status'])->toBe('complete');
});

test('analyzer framing faults cannot authorize production and a fresh operation recovers', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', '<?php int $value = 1;');
    $sequence = 0;
    foreach (['missing progress', 'duplicate progress', 'trailing noise', 'totals', 'stderr', 'incomplete', 'abnormal exit'] as $fault) {
        $start = workflowRequest($root, workflowStart('framing-' . (++$sequence), $sequence));
        $completion = workflowContinue($start);
        $record = $completion['results'][0];
        $progress = $start['invocations'][0]['progressPaths'][0] . "\n";
        $record = match ($fault) {
            'missing progress' => [...$record, 'stdout' => substr($record['stdout'], strlen($progress))],
            'duplicate progress' => [...$record, 'stdout' => $progress . $record['stdout']],
            'trailing noise' => [...$record, 'stdout' => $record['stdout'] . 'unexpected'],
            'totals' => [...$record, 'stdout' => str_replace('"file_errors":0', '"file_errors":1', $record['stdout'])],
            'stderr' => [...$record, 'stderr' => 'unexpected diagnostic noise'],
            'incomplete' => [...$record, 'complete' => false],
            'abnormal exit' => [...$record, 'exitCode' => 7],
        };
        expect($record)->not->toBe($completion['results'][0], $fault . ' did not inject a fault');
        $completion['results'][0] = $record;
        $result = workflowRequest($root, $completion);
        expect($result['status'])->toBe('infrastructure-failure', $fault . ': ' . json_encode($result))
            ->and($result['currentOutput'])->toBeNull()->and(workflowOutput($root))->toBe([]);
    }
    expect(workflowFinish($root, workflowStart('framing-recovery', ++$sequence))['status'])->toBe('complete');
});

test('source and artifact budgets reject before publication and recover', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    for ($i = 0; $i < 65; $i++) $this->writeFile($root . '/src/file' . $i . '.php', '<?php');
    expect(workflowRequest($root, workflowStart('too-many'))['status'])->toBe('rejected');
    for ($i = 0; $i < 65; $i++) unlink($root . '/src/file' . $i . '.php');
    $this->writeFile($root . '/src/main.php', '<?php /*' . str_repeat('x', 1_048_560) . '*/');
    $result = workflowFinish($root, workflowStart('artifact-limit', 2));
    expect($result['status'])->toBe('rejected', json_encode($result))
        ->and($result['error']['message'])->toContain('artifacts exceed')
        ->and(workflowOutput($root))->toBe([]);
    $this->writeFile($root . '/src/main.php', '<?php');
    expect(workflowFinish($root, workflowStart('budget-recovery', 3))['status'])->toBe('complete');
});

test('partial continuation rejects previous generation drift and mismatched runtime', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', '<?php int $value = 1;');
    expect(workflowFinish($root, workflowStart('published'))['status'])->toBe('complete');
    $start = workflowRequest($root, workflowStart('partial-pending', 2, path: 'src/main.ppphp'));
    $completion = workflowContinue($start);
    file_put_contents($root . '/build/ppphp/.ppphp/manifest.json', "\n", FILE_APPEND);
    $changed = workflowOutput($root);
    expect(workflowRequest($root, $completion)['status'])->toBe('rejected')->and(workflowOutput($root))->toBe($changed);
    $wrong = workflowStart('wrong-runtime', 3);
    $wrong['runtime']['phpVersion'] = '0.0.0';
    expect(workflowRequest($root, $wrong)['status'])->toBe('rejected')->and(workflowOutput($root))->toBe($changed);
    expect(workflowFinish($root, workflowStart('generation-recovery', 4))['status'])->toBe('complete');
});
