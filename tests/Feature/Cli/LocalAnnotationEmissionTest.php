<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

test('annotation validation never replaces the source checked-error contract', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', '<?php
function fail(): int throws Exception { throw new Exception(); }
function inspect(): int {
    int $value = fail();
    return $value;
}');
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json']);
    $build->run();
    expect($build->getExitCode())->toBe(1)
        ->and($build->getOutput())->toContain('P4003')
        ->and(is_file($root . '/build/ppphp/main.php'))->toBeFalse();
});

test('generated local annotations satisfy the plain analyzer without changing execution', function (string $body, string $output, bool $nested): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $statements = $nested
        ? 'return when ($enabled) { ' . $body . ' return 7; } else { return 0; };'
        : $body . ' return 7;';
    $this->writeFile($root . '/src/main.ppphp', '<?php
final class First {}
final class Second {}
function record(int $value): int { echo $value, ":"; return $value; }
/** @param list<int> $weights */
function inspect(array $weights, bool $enabled, bool $iterate): int {
    ' . $statements . '
}
echo inspect([2, 4], true, false);');
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json', '--debug']);
    $build->run();
    expect($build->getExitCode())->toBe(0, $build->getOutput() . $build->getErrorOutput());
    $path = $root . '/build/ppphp/main.php';
    if (str_contains($body, 'foreach')) {
        $php = file_get_contents($path);
        expect($php)->toContain('@var int $key');
        expect($php)->not->toContain('@var int $item');
    }
    $runtime = new Process([PHP_BINARY, $path]);
    $runtime->run();
    expect($runtime->getExitCode())->toBe(0)
        ->and($runtime->getOutput())->toBe($output . '7')
        ->and($runtime->getErrorOutput())->toBe('');
    $this->writeFile($root . '/plain.neon', "parameters:\n    level: max\n    tmpDir: " . $root . "/plain-cache\n");
    $plain = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/vendor/phpstan/phpstan/phpstan.phar',
        'analyse', '--configuration=' . $root . '/plain.neon', '--error-format=json', '--no-progress', $path]);
    $plain->run();
    expect($plain->getExitCode())->toBe(0, $plain->getOutput() . $plain->getErrorOutput());
})->with([
    'range inference' => ['int $parcels = count($weights); echo $parcels, "|";', '2|'],
    'narrow object initializer' => ['First|Second $item = new First(); echo get_class($item), "|";', 'First|'],
    'for initializer' => ['for (int $index = 0; $index < count($weights); ++$index) { echo $index; } echo "|";', '01|'],
    'same-line declarations need distinct decisions' => ['int $parcels = count($weights); int $literal = 1; echo $parcels, $literal, "|";', '21|'],
    'zero-iteration headers evaluate once in order' => ['int $other = 0; for (int $index = record(2), $other = record(4); $iterate; ++$index) { echo $other; } echo $index, $other, "|";', '2:4:24|'],
    'foreach tags have individual decisions' => ['foreach ([count($weights)] as int $key => int $item) { echo $key, $item; } echo "|";', '02|'],
])->with(['ordinary scope' => false, 'when scope' => true]);

test('valid generated annotations and authored comments are retained', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', '<?php
/**
 * Count the incoming weights.
 * @param list<int> $weights
 */
function countWeights(array $weights): int {
    int $parcels = count($weights); int $literal = 1;
    /** Collect the totals. */
    array<int> $totals = [];
    $totals[] = $parcels + $literal;
    return $totals[0];
}
echo countWeights([2, 4]);');
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json', '--debug']);
    $build->run();
    expect($build->getExitCode())->toBe(0, $build->getOutput() . $build->getErrorOutput());
    $php = file_get_contents($root . '/build/ppphp/main.php');
    expect($php)->toContain('@var int $literal')
        ->toContain('@var list<int> $totals')
        ->toContain('Collect the totals.')
        ->toContain('Count the incoming weights.');
    expect($php)->not->toContain('@var int $parcels');
});

test('annotation validation converges through dependent bindings in every callable scope', function (string $scope): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $body = '
        readonly int $parcels = count($weights);
        int $second = $parcels;
        int $third = $second;
        int $fourth = $third;
        int $result = when ($enabled) {
            if ($outer) { if ($inner) { return 99; } }
            return $parcels;
        } else { return 0; };
        int $literal = 1;
        return $fourth + $result + $literal;';
    $signature = '(array $weights, bool $enabled, bool $outer, bool $inner): int';
    $doc = "/** Count every incoming weight.\n * @param list<int> \$weights\n */";
    $source = match ($scope) {
        'function' => $doc . ' function countWeights' . $signature . '{' . $body . '}
            echo countWeights([2, 4], true, false, false);',
        'closure' => 'Closure $counter = ' . $doc . ' function' . $signature . '{' . $body . '};
            echo $counter([2, 4], true, false, false);',
        'method' => 'final class Counter { ' . $doc . ' public function countWeights' . $signature . '{' . $body . '} }
            echo (new Counter())->countWeights([2, 4], true, false, false);',
        'trait' => 'trait CountsWeights { ' . $doc . ' public function countWeights' . $signature . '{' . $body . '} }
            final class FirstCounter { use CountsWeights; }
            final class SecondCounter { use CountsWeights; }
            echo (new FirstCounter())->countWeights([2, 4], true, false, false);
            echo (new SecondCounter())->countWeights([2, 4], true, false, false);',
    };
    $this->writeFile($root . '/src/main.ppphp', '<?php ' . $source);
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json', '--debug']);
    $build->run();
    expect($build->getExitCode())->toBe(0, $build->getOutput() . $build->getErrorOutput());
    $path = $root . '/build/ppphp/main.php';
    $php = file_get_contents($path);
    foreach (['parcels', 'second', 'third', 'fourth', 'result'] as $name) {
        expect($php)->not->toContain('@var int $' . $name);
    }
    expect($php)->toContain('@var int $literal')->toContain('Count every incoming weight.');
    $runtime = new Process([PHP_BINARY, $path]);
    $runtime->run();
    expect($runtime->getExitCode())->toBe(0)
        ->and($runtime->getOutput())->toBe($scope === 'trait' ? '55' : '5');
    $this->writeFile($root . '/plain.neon', "parameters:\n    level: max\n    tmpDir: " . $root . "/plain-cache\n");
    $plain = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/vendor/phpstan/phpstan/phpstan.phar',
        'analyse', '--configuration=' . $root . '/plain.neon', '--error-format=json', '--no-progress', $path]);
    $plain->run();
    expect($plain->getExitCode())->toBe(0, $plain->getOutput() . $plain->getErrorOutput());
})->with(['function', 'closure', 'method', 'trait']);
