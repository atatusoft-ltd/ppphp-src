<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

test('a cleanup rethrow widens backend checked effects while a transparent analysis projection does not', function (
    string $body, bool $checked, string $shape,
): void {
    $root = $this->createTemporaryDirectory();
    $source = <<<'PHP'
<?php
function consume(mixed $value): void { throw new Error(); }
/** @throws RuntimeException */
function knownFailure(): mixed { throw new RuntimeException(); }
function choose(callable $factory): void {
    try {
        BODY
    } catch (Error $error) {}
}
PHP;
    $cleanup = <<<'PHP'
$pending = null;
try {
    BODY
} catch (Throwable $failure) {
    $release = [$pending];
    unset($pending);
    $pending = $failure;
    unset($failure);
    match ([$release, $release = null]) {
        default => throw ([$pending, $pending = null][0])
    };
} finally { unset($pending); }
PHP;
    // This alternative is a probe, not a production transform. Only the
    // compiler-owned exceptional cleanup is absent; the original body and
    // all authored catch/rethrow and ordinary cleanup remain analysable.
    $projection = '$pending = null; try { BODY } finally { unset($pending); }';
    $template = match ($shape) {
        'native' => 'BODY',
        'cleanup rethrow' => $cleanup,
        'analysis projection' => $projection,
    };
    $path = $root . '/Probe.php';
    $this->writeFile($path, str_replace('BODY', str_replace('BODY', $body, $template), $source));
    $compiler = dirname(__DIR__, 3);
    $this->writeFile($root . '/phpstan.neon', 'includes: [' . json_encode($compiler . '/resources/phpstan/ppphp.neon')
        . "]\nparameters:\n    tmpDir: " . json_encode($root . '/cache') . "\n");
    $run = new Process([
        PHP_BINARY, $compiler . '/vendor/bin/phpstan', 'analyse', '--debug', '--no-progress',
        '--error-format=json', '--configuration=' . $root . '/phpstan.neon', $path,
    ], timeout: 30);
    $run->run();
    $output = $run->getOutput();
    $result = json_decode(substr($output, strpos($output, '{')), true, flags: JSON_THROW_ON_ERROR);
    $identifiers = array_column($result['files'][$path]['messages'] ?? [], 'identifier');
    $expected = $checked || $shape === 'cleanup rethrow';
    expect($result['errors'])->toBe([])
        ->and($run->getExitCode())->toBe($expected ? 1 : 0, $output . $run->getErrorOutput())
        ->and($identifiers)->toBe($expected ? ['missingType.checkedException'] : []);
})->with([
    'opaque call stays an unchecked boundary' => ['$pending = $factory(); consume($pending);', false],
    'known checked call remains checked' => ['$pending = knownFailure(); consume($pending);', true],
    'authored checked throw remains checked' => ['throw new RuntimeException();', true],
    'authored broad rethrow remains checked' => [
        'try { $pending = $factory(); consume($pending); } catch (Throwable $authored) { throw $authored; }', true,
    ],
])->with(['native', 'cleanup rethrow', 'analysis projection']);
