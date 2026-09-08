#!/usr/bin/env php
<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Config\ProjectConfigLoader;
use Atatusoft\Ppphp\Project\ProjectLoader;
use Atatusoft\Ppphp\Support\Path;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt('', ['project:', 'document:', 'iterations:']);
$projectPath = $options['project'] ?? null;
$documentPath = $options['document'] ?? null;
$iterations = $options['iterations'] ?? '20';
if (!is_string($projectPath) || !is_string($documentPath) || !is_string($iterations)
    || !ctype_digit($iterations) || (int) $iterations < 1 || (int) $iterations > 100) {
    throw new InvalidArgumentException('Use --project=<root> --document=<source> [--iterations=1..100].');
}
$configuration = (new ProjectConfigLoader())->load($projectPath)->configuration;
if ($configuration === null) {
    throw new RuntimeException('The benchmark project configuration is invalid.');
}
$project = (new ProjectLoader())->load($configuration)->project;
if ($project === null || count($project->sources->files) > 32) {
    throw new RuntimeException('Use a valid benchmark project with at most 32 source buffers.');
}
$target = Path::resolveAbsolute($documentPath, $configuration->projectRoot);
$documents = [];
foreach ($project->sources->files as $source) {
    $contents = file_get_contents($source->path);
    if ($contents === false) {
        throw new RuntimeException('A benchmark source could not be read.');
    }
    $documents[$source->path] = ['path' => $source->displayPath, 'contents' => $contents];
}
$document = $documents[$target] ?? null;
if ($document === null) {
    throw new InvalidArgumentException('Select a project-owned benchmark source.');
}
unset($documents[$target]);
$request = ['version' => 1, 'document' => $document, 'overlays' => array_values($documents)];
$input = new InputStream();
$process = new Process([PHP_BINARY, dirname(__DIR__) . '/bin/ppphp', 'editor:diagnostics', '--server', '--working-directory', $configuration->projectRoot], timeout: 300);
$process->setInput($input);
$buffer = '';
$read = static function () use ($process, &$buffer): array {
    $response = null;
    $received = $process->waitUntil(static function (string $type, string $output) use (&$buffer, &$response): bool {
        if ($type === Process::ERR) {
            throw new RuntimeException($output);
        }
        $buffer .= $output;
        $end = strpos($buffer, "\n");
        if ($end === false) {
            return false;
        }
        $response = json_decode(substr($buffer, 0, $end), true, flags: JSON_THROW_ON_ERROR);
        $buffer = substr($buffer, $end + 1);
        return true;
    });
    $process->clearOutput();
    if (!$received || !is_array($response)) {
        throw new RuntimeException('The benchmark worker ended without a response.');
    }
    return $response;
};
$samples = ['cold' => [], 'syntax' => [], 'repair' => []];
try {
    $started = hrtime(true);
    $process->start();
    $ready = $read();
    $startupMs = (hrtime(true) - $started) / 1e6;
    if (($ready['type'] ?? null) !== 'ready' || ($ready['version'] ?? null) !== 1) {
        throw new RuntimeException('The benchmark worker handshake is incompatible.');
    }
    for ($id = 1; $id <= 1 + 2 * (int) $iterations; $id++) {
        $kind = $id === 1 ? 'cold' : ($id % 2 === 0 ? 'syntax' : 'repair');
        $payload = $request;
        $payload['document']['version'] = $id;
        if ($kind === 'syntax') {
            $payload['document']['contents'] = '<?php function (';
        }
        $started = hrtime(true);
        $input->write(json_encode(['version' => 1, 'id' => $id, 'method' => 'diagnostics', 'params' => $payload], JSON_THROW_ON_ERROR) . "\n");
        $response = $read();
        $elapsed = (hrtime(true) - $started) / 1e6;
        if (($response['id'] ?? null) !== $id || ($response['recycle'] ?? true)
            || ($response['result']['error'] ?? null) !== null
            || (($response['result']['summary']['errors'] ?? 0) > 0) !== ($kind === 'syntax')) {
            throw new RuntimeException('Benchmark diagnostic expectations failed or the worker requested recycling.');
        }
        $samples[$kind][] = $elapsed;
    }
    $input->write(json_encode(['version' => 1, 'id' => $id, 'method' => 'shutdown'], JSON_THROW_ON_ERROR) . "\n");
    $input->close();
    $process->wait();
    if ($process->getExitCode() !== 0) {
        throw new RuntimeException('The benchmark worker did not shut down successfully.');
    }
} finally {
    $process->stop();
}
$statistics = [];
foreach ($samples as $kind => $times) {
    sort($times, SORT_NUMERIC);
    $statistics[$kind] = ['samplesMs' => $times, 'p50Ms' => $times[(int) ceil(count($times) * 0.50) - 1], 'p95Ms' => $times[(int) ceil(count($times) * 0.95) - 1]];
}
echo json_encode(['startupMs' => $startupMs, 'compilerVersion' => $ready['compilerVersion'],
    'buffers' => count($documents) + 1, 'methodology' => 'Wall-clock worker transport samples; excludes LSP debounce and UI. Not a CI timing threshold.',
    'results' => $statistics], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
