<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Cli\Application;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Process\Process;
use Tests\Support\GoldenFile;

test('loop output golden shares completion state and uses only source loops', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $fixtures = dirname(__DIR__, 2) . '/Fixtures/WhenDecisions/Tail';
    $this->writeFile($root . '/src/main.ppphp', file_get_contents($fixtures . '/Loops.ppphp'));
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json']);
    $build->mustRun();
    $php = file_get_contents($root . '/build/ppphp/main.php');
    GoldenFile::assertMatches($fixtures . '/Loops.php', $php);
    expect($php)->not->toContain('__ppphp_when_finally')->not->toContain('__ppphp_when_pending')
        ->not->toContain('catch (\\Throwable')->not->toContain('while (false)');
    expect(substr_count($php, 'do {'))->toBe(1)
        ->and(substr_count($php, 'while (true)'))->toBe(1);
    $runtime = new Process([PHP_BINARY, $root . '/build/ppphp/main.php'], timeout: 5);
    $runtime->mustRun();
    expect($runtime->getOutput())->toBe('guard|guard|tail|[200,-1,0,1,70,0,null,5,2,null,3,0,6,0,4,5,4]')
        ->and($runtime->getErrorOutput())->toBe('');
});

test('loop results build as native control flow and preserve every tested path', function (string $body, bool $needsState, string $consumer): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $type = str_contains($body, 'return null;') ? '?int' : 'int';
    $expression = 'when ($ready) { ' . $body . ' } else { return 0; }';
    $statement = $consumer === 'return' ? 'return ' . $expression . ';'
        : $type . ' $result = ' . $expression . '; return $result;';
    $header = '<?php function choose(bool $ready, int $n, array<int> $values): ' . $type . ' { ';
    $driver = 'echo json_encode([choose(true, 0, []), choose(true, 2, [1, 2, 3]), choose(true, 8, [1, 2, 3]), choose(false, 0, [1])]);';
    $source = $header . $statement . '} ' . $driver;
    $this->writeFile($root . '/src/main.ppphp', $source);
    // Native return is the oracle for a when branch. Only compile-time local
    // and loop binding types are erased here; its control flow is unchanged.
    $nativeBody = str_replace(['as int $value', 'as int $other', 'int $index'], ['as $value', 'as $other', '$index'], $body);
    $reference = str_replace('array<int>', 'array', $header)
        . 'if ($ready) { ' . $nativeBody . ' } else { return 0; } } ' . $driver;
    $this->writeFile($root . '/reference.php', $reference);
    $application = new Application();
    $application->setAutoExit(false);
    $build = new ApplicationTester($application);
    $build->run(['command' => 'build', '--working-directory' => $root, '--no-ansi' => true]);
    expect($build->getStatusCode())->toBe(0, $build->getDisplay());
    $output = file_get_contents($root . '/build/ppphp/main.php');
    expect($output)->toBeString();
    expect(substr_count($output, 'do {'))->toBe(substr_count($body, 'do {'))
        ->and(substr_count($output, 'while (true)'))->toBe(substr_count($body, 'while (true)'))
        ->and($output)->not->toContain('__ppphp_when_finally')
        ->not->toContain('__ppphp_when_pending');
    if (!$needsState || $consumer === 'return') {
        expect($output)->not->toContain('= null;')->not->toContain('__ppphp_when_complete');
        if ($consumer === 'assignment') {
            expect($output)->not->toContain('__ppphp_when_');
        }
    }
    $native = new Process([PHP_BINARY, $root . '/reference.php'], timeout: 5);
    $native->mustRun();
    $runtime = new Process([PHP_BINARY, $root . '/build/ppphp/main.php'], timeout: 5);
    $runtime->mustRun();
    expect($runtime->getOutput())->toBe($native->getOutput())
        ->and($runtime->getErrorOutput())->toBe('')
        ->and($native->getErrorOutput())->toBe('');
})->with([
    'do result without fallback' => ['do { return $n; } while ($ready);', false],
    'while result without fallback' => ['while (true) { if ($n > 5) { return $n; } $n++; }', false],
    'truthy literal while without fallback' => ['while (1) { if ($n > 5) { return $n; } $n++; }', false],
    'truthy literal for without fallback' => ['for (; 1;) { return $n; }', false],
    'for result without fallback' => ['for (;;) { return $n; }', false],
    'nonempty foreach without fallback' => ['foreach ([4] as int $value) { return $value; }', false],
    'literal loop fallback' => ['foreach ($values as int $value) { if ($value > $n) { return $value; } } return -1;', false],
    'unchanged local foreach fallback' => ['foreach ($values as int $value) { if ($value > $n) { return $value * 100; } } return $n;', false],
    'unchanged local for fallback' => ['for (int $index = 0; $index < 3; $index++) { if ($index > $n) { return $index; } } return $n;', false],
    'changed fallback needs sentinel' => ['foreach ($values as int $value) { if ($value > $n) { return $value; } $n++; } return $n;', true],
    'nullable result needs completion bit' => ['foreach ($values as int $value) { if ($value === $n) { return null; } $n++; } return $n;', true],
    'nested loops exit together' => ['foreach ($values as int $value) { foreach ($values as int $other) { if ($other > $n) { return $value + $other; } } } return -1;', false],
    'switch return and user continue two inside loop' => ['foreach ($values as int $value) { switch ($value) { case 1: continue 2; case 2: return $n; default: break; } $n++; } return $n;', true],
    'loop return within a conditional arm' => ['foreach ($values as int $value) { if ($value > $n) { foreach ($values as int $other) { if ($other > 1) { return 10; } } return 20; } } return -1;', false],
    'earlier conditional result within a loop arm' => ['foreach ($values as int $value) { if ($value > $n) { if ($value > 2) { return 10; } return 20; } } return -1;', false],
    'partial guard then loop shares completion state' => ['if ($n > 0) { echo "guard|"; if ($n > 5) { return null; } } foreach ($values as int $value) { if ($value > $n) { return $value; } } echo "tail|"; return $n;', true],
])->with(['assignment', 'return']);

test('a throwing iterator cleanup leaves an existing destination untouched', function (string $declarations): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', str_replace('DECLARATIONS', $declarations, <<<'PPP'
<?php
DECLARATIONS
function choose(bool $ready): int {
    int $destination = 99;
    try {
        $destination = when ($ready) {
            while (true) {
                foreach (items() as mixed $item) { return 1; }
            }
        } else { return 0; };
    } catch (Error $error) { echo 'caught:' . $destination . '|'; }
    return $destination;
}
echo choose(true);
PPP));
    $application = new Application();
    $application->setAutoExit(false);
    $build = new ApplicationTester($application);
    $build->run(['command' => 'build', '--working-directory' => $root, '--no-ansi' => true]);
    expect($build->getStatusCode())->toBe(0, $build->getDisplay());
    $runtime = new Process([PHP_BINARY, $root . '/build/ppphp/main.php'], timeout: 5);
    $runtime->mustRun();
    expect($runtime->getOutput())->toBe('cleanup|caught:99|99')
        ->and($runtime->getErrorOutput())->toBe('');
})->with([
    'array element destruction' => <<<'PPP'
final class CleanupFailure {
    public function __destruct() { echo 'cleanup|'; throw new Error('cleanup failed'); }
}
function items(): array<mixed> { return [0, new CleanupFailure()]; }
PPP,
    'generator finally' => <<<'PPP'
/** @return Generator<int, int, mixed, void> */
function items(): Generator {
    try { yield 0; }
    finally { echo 'cleanup|'; throw new Error('cleanup failed'); }
}
PPP,
]);

test('a loop cannot preassign a fallback mutated through another parameter', function (string $loop): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $header = '<?php function mutate(int &$value): void { $value++; }
        function choose(array<int> $values, int &$fallback, int &$alias): int { ';
    $body = 'if ($values !== []) { ' . $loop . ' return $fallback; } else { return -1; }';
    $driver = 'int $shared = 99; echo choose([1, 2], $shared, $shared), "|", $shared;';
    $source = $header . 'int $result = when ($values !== []) { ' . $loop
        . ' return $fallback; } else { return -1; }; return $result; } ' . $driver;
    $this->writeFile($root . '/src/main.ppphp', $source);
    $reference = str_replace(['array<int>', 'as int $item', 'int $shared'], ['array', 'as $item', '$shared'],
        $header . $body . ' } ' . $driver);
    $this->writeFile($root . '/reference.php', $reference);
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json']);
    $build->mustRun();
    $php = file_get_contents($root . '/build/ppphp/main.php');
    $assignment = strpos($php, '$result = $fallback;');
    $iteration = strpos($php, 'foreach (');
    expect($assignment)->not->toBeFalse()->and($iteration)->not->toBeFalse()
        ->and($assignment)->toBeGreaterThan($iteration);
    $native = new Process([PHP_BINARY, $root . '/reference.php'], timeout: 5);
    $native->mustRun();
    $runtime = new Process([PHP_BINARY, $root . '/build/ppphp/main.php'], timeout: 5);
    $runtime->mustRun();
    expect($runtime->getOutput())->toBe($native->getOutput())
        ->and($runtime->getErrorOutput())->toBe('')->and($native->getErrorOutput())->toBe('');
})->with([
    'foreach target alias' => 'foreach ($values as $alias) { if ($alias === 5) { return 100; } }',
    'counter alias' => 'foreach ($values as int $item) { $alias++; if ($item === 5) { return 100; } }',
    'call alias' => 'foreach ($values as int $item) { mutate($alias); if ($item === 5) { return 100; } }',
]);

test('dynamically created local aliases invalidate a fresh loop binding proof', function (string $imports, string $setup, string $condition): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/helpers.php', <<<'PHP'
<?php
/** @return array<string, int> */
function aliases(int &$fallback): array { return ['item' => &$fallback]; }
PHP);
    $header = <<<'PPP'
<?php
IMPORTS
require __DIR__ . '/helpers.php';
function choose(array<int> $values, int &$fallback): int {
    array<string, int> $locals = aliases($fallback);
SETUP
PPP;
    $header = str_replace(['IMPORTS', 'SETUP'], [$imports, $setup], $header);
    $branch = 'foreach ($values as int $item) { if ($item > 5) { return 100; } } return $fallback;';
    $driver = 'int $shared = 99; echo choose([1, 2], $shared), "|", $shared;';
    $this->writeFile($root . '/src/main.ppphp', $header . 'int $result = when (' . $condition . ') { '
        . $branch . ' } else { return -1; }; return $result; } ' . $driver);
    $reference = str_replace(
        ["'/helpers.php'", 'array<int>', 'array<string, int> $locals', 'as int $item', 'int $shared'],
        ["'/src/helpers.php'", 'array', '$locals', 'as $item', '$shared'],
        $header . 'if (' . $condition . ') { ' . $branch . ' } else { return -1; } } ' . $driver,
    );
    $this->writeFile($root . '/reference.php', $reference);
    $native = new Process([PHP_BINARY, $root . '/reference.php'], timeout: 5);
    $native->mustRun();
    expect($native->getOutput())->toBe('2|2');
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json']);
    $build->mustRun();
    $runtime = new Process([PHP_BINARY, $root . '/build/ppphp/main.php'], timeout: 5);
    $runtime->mustRun();
    expect($runtime->getOutput())->toBe($native->getOutput())
        ->and($runtime->getErrorOutput())->toBe('')->and($native->getErrorOutput())->toBe('');
})->with([
    'before when' => ['', 'extract($locals, EXTR_REFS);', '$values !== []'],
    'imported builtin' => ['use function extract as restore;', 'restore($locals, EXTR_REFS);', '$values !== []'],
    'inside when condition' => ['', '', 'extract($locals, EXTR_REFS) > 0'],
]);
