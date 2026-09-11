<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Support\GoldenFile;

test('inferred when result declarations accept narrower branch values in every consumer', function (string $body): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $when = 'when ($count === 0) { return "No users"; } else { return $count . " users: " . implode(", ", $names); }';
    $this->writeFile($root . '/src/main.ppphp', '<?php
function identity(string $value): string { return $value; }
function describeUsers(array<string> $names): string {
    readonly int $count = count($names);
    ' . str_replace('WHEN', $when, $body) . '
}
echo describeUsers([]), "|", describeUsers(["Maya"]);');
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json']);
    $build->run();
    expect($build->getExitCode())->toBe(0, $build->getOutput());
    $generated = file_get_contents($root . '/build/ppphp/main.php');
    expect($generated)->not->toContain('@var string $__ppphp_when_')
        ->not->toContain('do {')->not->toContain('while (true)');
    if ($body === 'return WHEN;') {
        GoldenFile::assertMatches(dirname(__DIR__, 2) . '/Golden/ProductionPhp/when-result.php.golden', $generated);
    }
    $run = new Process([PHP_BINARY, $root . '/build/ppphp/main.php']);
    $run->run();
    expect($run->getExitCode())->toBe(0)
        ->and($run->getOutput())->toBe('No users|1 users: Maya')
        ->and($run->getErrorOutput())->toBe('');
})->with([
    'return' => 'return WHEN;',
    'typed local' => 'string $value = WHEN; return $value;',
    'argument' => 'return identity(WHEN);',
    'array element' => 'array<string> $values = [WHEN]; return $values[0];',
    'nested result' => 'return when ($count >= 0) { return WHEN; } else { return "unreachable"; };',
    'ternary branch' => 'return $count >= 0 ? identity(WHEN) : "unreachable";',
    'coalesce operand' => '?string $none = null; return $none ?? identity(WHEN);',
]);

test('when result metadata never hides an adjacent authored assertion', function (string $tag): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', '<?php
function describe(bool $ready): string {
    /** ' . $tag . ' string $missing */
    return when ($ready) { return "ready"; } else { return "waiting"; };
}');
    $check = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'check', '--working-directory', $root, '--format=json']);
    $check->run();
    expect($check->getExitCode())->toBe(1)
        ->and($check->getOutput())->toContain('Variable $missing');
})->with(['@var', '@phpstan-var', '@psalm-var']);

test('when result declarations preserve numeric collection object and nullable values', function (string $type, string $empty, string $nonempty, string $output): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', '<?php
function result(array<string> $names): ' . $type . ' {
    return when ($names === []) { return ' . $empty . '; } else { return ' . $nonempty . '; };
}
echo json_encode([result([]), result(["Maya"])]);');
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json']);
    $build->run();
    expect($build->getExitCode())->toBe(0, $build->getOutput());
    $run = new Process([PHP_BINARY, $root . '/build/ppphp/main.php']);
    $run->run();
    expect($run->getExitCode())->toBe(0)
        ->and($run->getOutput())->toBe($output)
        ->and($run->getErrorOutput())->toBe('');
})->with([
    ['int', '0', 'count($names)', '[0,1]'],
    ['float', '0.0', '(float) count($names) / 2.0', '[0,0.5]'],
    ['bool', 'false', 'true', '[false,true]'],
    ['array<string>', '["none"]', '$names', '[["none"],["Maya"]]'],
    ['object', 'new stdClass()', 'new stdClass()', '[{},{}]'],
    ['?string', 'null', '"users: " . count($names)', '[null,"users: 1"]'],
]);

test('a repeated when initializer preserves its previous result until replacement', function (string $start, string $end): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $branch = 'if ($prefix) { echo "prefix|"; if ($index === 1) { return new Probe($index); } } echo "tail|"; return new Probe($index);';
    $template = <<<'PHP'
<?php
final class Probe {
    public function __construct(public int $number) { echo 'make:', $this->number, '|'; }
    public function __destruct() { echo 'destroy:', $this->number, '|'; }
}
REFERENCE
function run(int $count, bool $ready, bool $prefix): void {
    START
        Probe $result = EXPRESSION;
        echo 'after:', $result->number, '|';
    END
}
run(2, true, true);
PHP;
    $template = str_replace(['START', 'END'], [$start, $end], $template);
    $source = str_replace(['REFERENCE', 'EXPRESSION'], ['', 'when ($ready) { ' . $branch . ' } else { return new Probe(-1); }'], $template);
    $this->writeFile($root . '/src/main.ppphp', $source);
    $reference = str_replace(['REFERENCE', 'EXPRESSION', 'int $index', 'array<int> $indices', 'Probe $result ='], [
        'function selectResult(int $index, bool $ready, bool $prefix): Probe { if ($ready) { ' . $branch . ' } else { return new Probe(-1); } }',
        'selectResult($index, $ready, $prefix)', '$index', '$indices', '$result =',
    ], $template);
    $this->writeFile($root . '/reference.php', $reference);
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json']);
    $build->mustRun();
    foreach (['reference.php', 'build/ppphp/main.php'] as $file) {
        $run = new Process([PHP_BINARY, $root . '/' . $file]);
        $run->mustRun();
        expect($run->getOutput())->toBe('prefix|tail|make:0|after:0|prefix|make:1|destroy:0|after:1|destroy:1|', $file)
            ->and($run->getErrorOutput())->toBe('');
    }
})->with([
    'for' => ['for (int $index = 0; $index < $count; $index++) {', '}'],
    'foreach' => ['array<int> $indices = [0, 1]; foreach ($indices as int $index) {', '}'],
    'while' => ['int $index = 0; while ($index < $count) {', '$index++; }'],
    'do' => ['int $index = 0; do {', '$index++; } while ($index < $count);'],
    'backward goto' => ['int $index = 0; nextResult: ;', '$index++; if ($index < $count) { goto nextResult; }'],
]);

test('a nested callable starts a fresh result scope inside an outer protected loop', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', <<<'PHP'
<?php
function run(bool $ready, bool $prefix, bool $take): void {
    for (int $index = 0; $index < 2; $index++) {
        try {
            Closure $select = function (bool $ready, bool $prefix, bool $take): int {
                Closure $inspect = function (): array { return get_defined_vars(); };
                int $result = when ($ready) {
                    if ($prefix) { if ($take) { return 2; } }
                    return -1;
                } else { return 0; };
                return $result;
            };
            echo $select($ready, $prefix, $take), '|';
        } finally {
            echo 'cleanup|';
        }
    }
}
run(true, true, true);
run(true, true, false);
PHP);
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json']);
    $build->mustRun();
    $php = file_get_contents($root . '/build/ppphp/main.php');
    expect($php)->toContain('$result = -1;')->not->toContain('__ppphp_when_');
    $run = new Process([PHP_BINARY, $root . '/build/ppphp/main.php']);
    $run->mustRun();
    expect($run->getOutput())->toBe('2|cleanup|2|cleanup|-1|cleanup|-1|cleanup|')
        ->and($run->getErrorOutput())->toBe('');
});

test('a failed when initializer does not create its source local for a catching scope', function (bool $fileScope): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $branch = 'if (getenv("PREFIX") !== "0") { if (getenv("TAKE") !== "0") { return failResult(); } } return 1;';
    $condition = 'getenv("SKIP") !== "1"';
    $functions = 'function failResult(): int { throw new Error("failed"); }';
    $expression = 'when (' . $condition . ') { ' . $branch . ' } else { return 0; }';
    $source = 'int $result = ' . $expression . '; echo $result;';
    $reference = 'function selectResult(): int { if (' . $condition . ') { ' . $branch . ' } else { return 0; } } $result = selectResult(); echo $result;';
    $catch = 'catch (Error) { echo array_key_exists("result", get_defined_vars()) ? "defined" : "missing"; }';
    if (!$fileScope) {
        $source = 'function run(): void { try { ' . $source . ' } ' . $catch . ' } run();';
        $reference = 'function run(): void { try { ' . $reference . ' } ' . $catch . ' } run();';
    }
    $this->writeFile($root . '/src/main.ppphp', '<?php ' . $functions . $source);
    $this->writeFile($root . '/reference.php', '<?php ' . $functions . $reference);
    $this->writeFile($root . '/runner.php', '<?php try { require $argv[1]; } ' . $catch);
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json']);
    $build->mustRun();
    foreach (['reference.php', 'build/ppphp/main.php'] as $file) {
        $run = new Process([PHP_BINARY, $root . '/runner.php', $root . '/' . $file], env: ['PREFIX' => '1', 'TAKE' => '1', 'SKIP' => '0']);
        $run->mustRun();
        expect($run->getOutput())->toBe('missing', $file)->and($run->getErrorOutput())->toBe('');
    }
})->with(['same function' => false, 'including scope' => true]);

test('a pending when initializer is absent from the branch local symbol table', function (string $prefix, string $observation): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $inspect = 'echo array_key_exists("result", get_defined_vars()) ? "defined|" : "missing|";';
    $this->writeFile($root . '/src/inspect.php', '<?php ' . $inspect);
    $source = <<<'PHP'
<?php
PREFIX
function run(bool $ready, bool $prefix, bool $take): void {
    int $result = when ($ready) {
        if ($prefix) { if ($take) { return 2; } }
        OBSERVATION
        return 1;
    } else { return 0; };
    echo $result;
}
run(true, false, false);
PHP;
    $this->writeFile($root . '/src/main.ppphp', str_replace(['PREFIX', 'OBSERVATION'], [$prefix, $observation], $source));
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json']);
    $build->mustRun();
    $run = new Process([PHP_BINARY, $root . '/build/ppphp/main.php']);
    $run->mustRun();
    expect($run->getOutput())->toBe('missing|1')->and($run->getErrorOutput())->toBe('');
})->with([
    'direct builtin' => ['', 'echo array_key_exists("result", get_defined_vars()) ? "defined|" : "missing|";'],
    'fully qualified builtin' => ['namespace Example;', 'echo array_key_exists("result", \\get_defined_vars()) ? "defined|" : "missing|";'],
    'imported builtin' => ['namespace Example; use function get_defined_vars as inspectLocals;', 'echo array_key_exists("result", inspectLocals()) ? "defined|" : "missing|";'],
    'included PHP' => ['', 'require __DIR__ . "/inspect.php";'],
]);
