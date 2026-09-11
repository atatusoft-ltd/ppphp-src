<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Cli\Application;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Process\Process;
use Tests\Support\GoldenFile;

function buildWhenTailProject(string $root): ApplicationTester
{
    $application = new Application();
    $application->setAutoExit(false);
    $tester = new ApplicationTester($application);
    $tester->run(['command' => 'build', '--working-directory' => $root, '--format' => 'json']);

    return $tester;
}

test('tail when branches build as ordinary conditional statements', function (string $body, string $expected, array $variants = []): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', str_replace('BODY', $body, <<<'PPP'
<?php
function choose(int $x): int {
    BODY
}
echo choose(2), '|', choose(1), '|', choose(0), '|', choose(-1);
PPP));
    $build = buildWhenTailProject($root);
    expect($build->getStatusCode())->toBe(0, $build->getDisplay());
    $php = file_get_contents($root . '/build/ppphp/main.php');
    expect($php)->not->toContain('do {')->not->toContain('while (true)')
        ->not->toContain('$__ppphp_when_')->not->toContain('break 2');
    foreach ([[[], $expected], ...$variants] as [$env, $output]) {
        $run = new Process([PHP_BINARY, $root . '/build/ppphp/main.php'], env: $env);
        $run->mustRun();
        expect($run->getOutput())->toBe($output)->and($run->getErrorOutput())->toBe('');
    }
})->with([
    'local destination' => ['int $value = when ($x > 1) { return 20; } else when ($x > 0) { return 10; } else { return 0; }; return $value;', '20|10|0|0'],
    'real return operand' => ['return when ($x > 1) { return 20; } else when ($x > 0) { return 10; } else { return 0; };', '20|10|0|0'],
    'tail conditional' => ['int $value = when ($x > 0) { if ($x > 1) { return 20; } else { return 10; } } else { return 0; }; return $value;', '20|10|0|0'],
    'tail switch' => ['int $value = when ($x >= 0) { switch ($x) { case 2: return 20; case 1: if (getenv("TAIL_SWITCH") !== "other") { return 10; } else { return 5; } default: return 0; } } else { return -1; }; return $value;', '20|10|0|-1'],
    'nested tail switch' => ['int $value = when ($x >= 0) { switch ($x) { case 2: switch (getenv("INNER_CASE")) { case "a": return 21; default: return 20; } case 1: return 10; default: return 0; } } else { return -1; }; return $value;', '20|10|0|-1', [[['INNER_CASE' => 'a'], '21|10|0|-1']]],
    'return or throw case' => ['int $value = when ($x >= 0) { switch ($x) { case 2: return 20; case 1: if (getenv("TAIL_SWITCH") !== "other") { return 10; } else { throw new Error("case"); } default: return 0; } } else { return -1; }; return $value;', '20|10|0|-1'],
    'nested switch fallthrough' => ['int $value = when ($x >= 0) { switch ($x) { case 2: switch (getenv("INNER_CASE")) { case "a": if (getenv("TAIL_SWITCH") !== "other") { return 21; } default: return 20; } case 1: return 10; default: return 0; } } else { return -1; }; return $value;', '20|10|0|-1', [[['INNER_CASE' => 'a'], '21|10|0|-1'], [['INNER_CASE' => 'a', 'TAIL_SWITCH' => 'other'], '20|10|0|-1']]],
    'conditional nested switch' => ['int $value = when ($x >= 0) { switch ($x) { case 2: if (getenv("TAIL_SWITCH") !== "other") { switch (getenv("INNER_CASE")) { case "a": return 21; default: return 20; } } case 1: return 10; default: return 0; } } else { return -1; }; return $value;', '20|10|0|-1', [[['INNER_CASE' => 'a'], '21|10|0|-1'], [['TAIL_SWITCH' => 'other'], '10|10|0|-1']]],
    'nested when result' => ['int $value = when ($x > 0) { return when ($x > 1) { return 20; } else { return 10; }; } else { return 0; }; return $value;', '20|10|0|0'],
]);

test('homepage when build uses the approved shipping shape', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $fixtures = dirname(__DIR__, 2) . '/Fixtures/WhenDecisions/Tail';
    $source = file_get_contents($fixtures . '/Shipping.ppphp');
    expect($source)->toBeString();
    $this->writeFile($root . '/src/main.ppphp', $source);
    $build = buildWhenTailProject($root);
    expect($build->getStatusCode())->toBe(0, $build->getDisplay());
    $php = file_get_contents($root . '/build/ppphp/main.php');
    expect($php)->toBe(file_get_contents($fixtures . '/Shipping.php'));
    $run = new Process([PHP_BINARY, $root . '/build/ppphp/main.php']);
    $run->mustRun();
    expect($run->getOutput())->toBe('1450|700')->and($run->getErrorOutput())->toBe('');
});

test('tail result temporaries preserve native lifetime on success and exceptions', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', <<<'PPP'
<?php
ini_set('zend.exception_ignore_args', getenv('TRACE_ARGS') === '1' ? '0' : '1');
final class LifetimeProbe {
    public function __construct() throws RuntimeException {
        echo 'create|';
        if (getenv('FAULT') === 'constructor') { throw new RuntimeException('constructor'); }
    }
    public function __destruct() { echo 'destroy|'; }
}
function consume(LifetimeProbe $value): void throws RuntimeException {
    echo 'consume|';
    if (getenv('FAULT') === 'error') { throw new Error('consumer'); }
    if (getenv('FAULT') === 'exception') { throw new RuntimeException('consumer'); }
}
try {
    consume(when (getenv('BRANCH') !== 'other') { return new LifetimeProbe(); } else { return new LifetimeProbe(); });
} catch (Throwable $error) { echo 'caught:', $error::class, '|'; }
echo 'after|';
PPP);
    $build = buildWhenTailProject($root);
    expect($build->getStatusCode())->toBe(0, $build->getDisplay());
    $php = file_get_contents($root . '/build/ppphp/main.php');
    expect($php)->toContain('finally {', 'unset(')->not->toContain('do {')
        ->not->toContain('@var LifetimeProbe $__ppphp_when_');
    $reference = str_replace("consume(when (getenv('BRANCH') !== 'other') { return new LifetimeProbe(); } else { return new LifetimeProbe(); });", 'consume(new LifetimeProbe());', file_get_contents($root . '/src/main.ppphp'));
    $reference = str_replace(' throws RuntimeException', '', $reference);
    $this->writeFile($root . '/reference.php', $reference);
    foreach (['0', '1'] as $trace) {
        foreach (['none', 'constructor', 'exception', 'error'] as $fault) {
            $env = ['FAULT' => $fault, 'TRACE_ARGS' => $trace];
            $native = new Process([PHP_BINARY, $root . '/reference.php'], env: $env);
            $native->mustRun();
            $compiled = new Process([PHP_BINARY, $root . '/build/ppphp/main.php'], env: $env);
            $compiled->mustRun();
            expect($compiled->getOutput())->toBe($native->getOutput(), $trace . ':' . $fault)
                ->and($compiled->getErrorOutput())->toBe('');
        }
    }
});

test('nested consumers release their own argument before the next operation', function (string $body): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $template = <<<'PHP'
<?php
ini_set('zend.exception_ignore_args', '1');
final class Probe {
    public function __construct(public string $name) { echo 'create:', $name, '|'; }
    public function __destruct() { echo 'destroy:', $this->name, '|'; }
    public function consume(Probe $p): void { echo 'method|'; }
    public function next(): Probe { echo 'next|'; return new Probe('next'); }
    public function step(Probe $p): Probe { echo 'step|'; return new Probe('next'); }
    public function optional(Probe $p): ?Probe { return getenv('NEXT') === 'yes' ? $this->step($p) : null; }
    public function measure(Probe $p): int { echo 'measure|'; return 1; }
    public ?Probe $following {
        get { echo 'get|'; return getenv('NEXT') === 'yes' ? new Probe('next') : null; }
    }
}
function inner(Probe $p): int { echo 'inner|'; return 1; }
function outer(int $n, Probe $p): void { echo 'outer|'; }
function make(Probe $p): Probe { echo 'make|'; return new Probe('receiver'); }
function maybe(bool $ready): ?Probe { return $ready ? new Probe('receiver') : null; }
BODY
echo 'after|';
PHP;
    $template = str_replace('BODY', $body, $template);
    $source = str_replace(['FIRST', 'SECOND'], [
        'when (getenv("BRANCH") !== "other") { return new Probe("first"); } else { return new Probe("first"); }',
        'when (getenv("BRANCH") !== "other") { return new Probe("second"); } else { return new Probe("second"); }',
    ], $template);
    $this->writeFile($root . '/src/main.ppphp', $source);
    $this->writeFile($root . '/reference.php', str_replace(['FIRST', 'SECOND', 'int $sum'], ['new Probe("first")', 'new Probe("second")', '$sum'], $template));
    $build = buildWhenTailProject($root);
    expect($build->getStatusCode())->toBe(0, $build->getDisplay());
    foreach ([['READY' => 'yes', 'NEXT' => 'yes'], ['READY' => 'yes', 'NEXT' => 'no'], ['READY' => 'no', 'NEXT' => 'yes']] as $env) {
        $native = new Process([PHP_BINARY, $root . '/reference.php'], env: $env);
        $native->mustRun();
        $compiled = new Process([PHP_BINARY, $root . '/build/ppphp/main.php'], env: $env);
        $compiled->mustRun();
        expect($compiled->getOutput())->toBe($native->getOutput(), implode(':', $env))->and($compiled->getErrorOutput())->toBe('');
    }
})->with([
    'call arguments' => ['outer(inner(FIRST), SECOND);'],
    'operator operands' => ['int $sum = inner(FIRST) + inner(SECOND); echo $sum, "|";'],
    'condition' => ['if (inner(FIRST) > 0) { echo "branch|"; } else { echo "else|"; }'],
    'method receiver' => ['make(FIRST)->consume(SECOND);'],
    'array values' => ['int $sum = count([inner(FIRST), SECOND]); echo $sum, "|";'],
    'short-circuit operands' => ['if (inner(FIRST) > 0 && inner(SECOND) > 0) { echo "branch|"; }'],
    'returning conditional' => ['function result(): int { if (inner(FIRST) > 0) { return 1; } else { return 0; } } echo result(), "|";'],
    'returning elseif' => ['function result(): int { if (getenv("READY") === "no") { return 2; } elseif (inner(FIRST) > 0) { return 1; } else { return 0; } } echo result(), "|";'],
    'ternary consumers' => ['outer(inner(FIRST) > 0 ? inner(SECOND) : 0, new Probe("outer"));'],
    'nullsafe receiver' => ['maybe(getenv("READY") === "yes")?->consume(FIRST);'],
    'nullsafe chain' => ['maybe(getenv("READY") === "yes")?->next()->consume(FIRST);'],
    'nullsafe chain arguments' => ['maybe(getenv("READY") === "yes")?->step(FIRST)->consume(SECOND);'],
    'long nullsafe chain' => ['maybe(getenv("READY") === "yes")?->next()->next()->consume(FIRST);'],
    'second nullsafe link' => ['maybe(getenv("READY") === "yes")?->optional(FIRST)?->consume(SECOND);'],
    'nullsafe return value' => ['function result(): ?int { return maybe(getenv("READY") === "yes")?->step(FIRST)->measure(SECOND); } echo result() ?? 0, "|";'],
    'nullsafe property chain' => ['maybe(getenv("READY") === "yes")?->following?->consume(FIRST);'],
]);

test('a throwing argument destructor does not defer later cleanup past the catch', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $template = <<<'PHP'
<?php
ini_set('zend.exception_ignore_args', '1');
final class Probe {
    public function __construct(public string $name) {}
    public function __destruct() {
        echo $this->name, '|';
        if ($this->name === 'first') { throw new Error('release'); }
    }
}
function consume(Probe $first, Probe $second): void { echo 'consume|'; }
try { consume(FIRST, SECOND); } catch (Throwable) { echo 'catch|'; }
echo 'after';
PHP;
    $this->writeFile($root . '/src/main.ppphp', str_replace(['FIRST', 'SECOND'], [
        'when (getenv("BRANCH") !== "other") { return new Probe("first"); } else { return new Probe("first"); }',
        'when (getenv("BRANCH") !== "other") { return new Probe("second"); } else { return new Probe("second"); }',
    ], $template));
    $this->writeFile($root . '/reference.php', str_replace(['FIRST', 'SECOND'], ['new Probe("first")', 'new Probe("second")'], $template));
    $build = buildWhenTailProject($root);
    expect($build->getStatusCode())->toBe(0, $build->getDisplay());
    $native = new Process([PHP_BINARY, $root . '/reference.php']);
    $native->mustRun();
    $compiled = new Process([PHP_BINARY, $root . '/build/ppphp/main.php']);
    $compiled->mustRun();
    expect($native->getOutput())->toBe('consume|first|second|catch|after')
        ->and($compiled->getOutput())->toBe($native->getOutput())->and($compiled->getErrorOutput())->toBe('');
});

test('access-chain cleanup preserves receiver order when a release throws', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $template = <<<'PHP'
<?php
ini_set('zend.exception_ignore_args', '1');
class Probe {
    public function __construct(public string $name) { echo 'create:', $name, '|'; }
    public function __destruct() {
        echo 'destroy:', $this->name, '|';
        if ($this->name === getenv('FAULT')) { throw new Error('release'); }
    }
    public function step(Probe $arg): Probe { echo 'step|'; return new Probe('next'); }
    public function consume(Probe $arg): void { echo 'consume|'; }
}
function maybe(bool $ready): ?Probe { return $ready ? new Probe('receiver') : null; }
try { maybe(getenv('READY') !== 'no')?->step(FIRST)->consume(SECOND); }
catch (Throwable) { echo 'caught|'; }
echo 'after';
PHP;
    $this->writeFile($root . '/src/main.ppphp', str_replace(['FIRST', 'SECOND'], [
        'when (getenv("BRANCH") !== "other") { return new Probe("first"); } else { return new Probe("first"); }',
        'when (getenv("BRANCH") !== "other") { return new Probe("second"); } else { return new Probe("second"); }',
    ], $template));
    $this->writeFile($root . '/reference.php', str_replace(['FIRST', 'SECOND'], ['new Probe("first")', 'new Probe("second")'], $template));
    $build = buildWhenTailProject($root);
    expect($build->getStatusCode())->toBe(0, $build->getDisplay());
    foreach (['none', 'receiver', 'first', 'next', 'second'] as $fault) {
        $native = new Process([PHP_BINARY, $root . '/reference.php'], env: ['FAULT' => $fault]);
        $native->mustRun();
        $compiled = new Process([PHP_BINARY, $root . '/build/ppphp/main.php'], env: ['FAULT' => $fault]);
        $compiled->mustRun();
        expect($compiled->getOutput())->toBe($native->getOutput(), $fault)->and($compiled->getErrorOutput())->toBe('');
    }
});

test('tail result golden preserves the required emitted shape', function (string $fixture, string $expected, array $variants = []): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $fixtures = dirname(__DIR__, 2) . '/Fixtures/WhenDecisions/Tail';
    $source = file_get_contents($fixtures . '/' . $fixture . '.ppphp');
    expect($source)->toBeString();
    $this->writeFile($root . '/src/main.ppphp', $source);
    $build = buildWhenTailProject($root);
    expect($build->getStatusCode())->toBe(0, $build->getDisplay());
    $php = file_get_contents($root . '/build/ppphp/main.php');
    GoldenFile::assertMatches($fixtures . '/' . $fixture . '.php', $php);
    expect($php)->not->toContain('do {')->not->toContain('@var')->not->toContain('while (true)');
    foreach ([[[], $expected], ...$variants] as [$env, $output]) {
        $run = new Process([PHP_BINARY, $root . '/build/ppphp/main.php'], env: $env);
        $run->mustRun();
        expect($run->getOutput())->toBe($output)->and($run->getErrorOutput())->toBe('');
    }
})->with([
    ['EmbeddedObjects', 'consume|first|second|after'],
    ['EmbeddedString', 'aa|after'],
    ['NestedSwitch', '11|12|21|99|0|error'],
    // The numbered exit crosses two real source switches, not a synthetic
    // when boundary. The user's inner break must still fall through normally.
    ['NativeSwitchBreak', '20|10|0|-1', [[['INNER_CASE' => 'a'], '10|10|0|-1']]],
    ['ConditionalSwitchBreak', '30|10|0|-1', [
        [['INNER_CASE' => 'a'], '20|10|0|-1'],
        [['INNER_CASE' => 'a', 'LEAVE_CASE' => 'yes'], '10|10|0|-1'],
    ]],
]);

test('refcounted tail arguments release retained memory before an outer catch', function (string $type, string $value): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $source = str_replace(['TYPE', 'VALUE'], [$type, $value], <<<'PPP'
<?php
ini_set('zend.exception_ignore_args', '1');
function consume(TYPE $value): void { throw new Error('consumer'); }
int $before = memory_get_usage(true);
try {
    consume(when (getenv('BRANCH') !== 'other') { return VALUE; } else { return VALUE; });
} catch (Error) {
    echo memory_get_usage(true) - $before < 8 * 1024 * 1024 ? 'released' : 'retained';
}
PPP);
    $this->writeFile($root . '/src/main.ppphp', $source);
    $build = buildWhenTailProject($root);
    expect($build->getStatusCode())->toBe(0, $build->getDisplay());
    $reference = str_replace(
        ["when (getenv('BRANCH') !== 'other') { return $value; } else { return $value; }", 'int $before', 'array<array<string>>', 'array<string>'],
        [$value, '$before', 'array', 'array'],
        $source,
    );
    $this->writeFile($root . '/reference.php', $reference);
    $native = new Process([PHP_BINARY, $root . '/reference.php']);
    $native->mustRun();
    $compiled = new Process([PHP_BINARY, $root . '/build/ppphp/main.php']);
    $compiled->mustRun();
    expect($native->getOutput())->toBe('released')->and($compiled->getOutput())->toBe($native->getOutput())
        ->and($compiled->getErrorOutput())->toBe('');
})->with([
    'string' => ['string', 'str_repeat("x", 16 * 1024 * 1024)'],
    'array' => ['array<string>', '[str_repeat("x", 16 * 1024 * 1024)]'],
    'nested array' => ['array<array<string>>', '[[str_repeat("x", 16 * 1024 * 1024)]]'],
]);
