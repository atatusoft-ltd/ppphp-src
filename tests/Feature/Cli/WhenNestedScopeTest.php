<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Cli\Application;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Process\Process;

function runWhenNestedCommand(string $root, string $command, ?string $path = null): ApplicationTester
{
    $application = new Application();
    $application->setAutoExit(false);
    $tester = new ApplicationTester($application);
    $input = ['command' => $command, '--working-directory' => $root, '--format' => 'json'];
    if ($path !== null) {
        $input['path'] = $path;
    }
    $tester->run($input);

    return $tester;
}

test('paired nested scope examples build and execute', function (string $fixture, string $expected): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $source = file_get_contents(dirname(__DIR__, 2) . '/Fixtures/WhenDecisions/' . $fixture . '.ppphp');
    expect($source)->toBeString();
    $this->writeFile($root . '/src/main.ppphp', $source);
    $build = runWhenNestedCommand($root, 'build');
    expect($build->getStatusCode())->toBe(0, $build->getDisplay());
    $runtime = new Process([PHP_BINARY, $root . '/build/ppphp/main.php'], timeout: 5);
    $runtime->mustRun();
    expect($runtime->getOutput())->toBe($expected)->and($runtime->getErrorOutput())->toBe('');
})->with([
    ['Nested', '5|1|0'],
    ['NestedCallableLoop', '6|0'],
]);

test('nested when temporaries belong to their own consuming statement', function (string $body, string $expected): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', str_replace('BODY', $body, <<<'PPP'
<?php
function nested(int $x): int {
    int $r = when ($x > 0) {
        BODY
    } else { return 0; };
    return $r;
}
echo nested(2), '|', nested(1), '|', nested(0);
PPP));
    $build = runWhenNestedCommand($root, 'build');
    expect($build->getStatusCode())->toBe(0, $build->getDisplay());
    $runtime = new Process([PHP_BINARY, $root . '/build/ppphp/main.php'], timeout: 5);
    $runtime->mustRun();
    expect($runtime->getOutput())->toBe($expected)->and($runtime->getErrorOutput())->toBe('');
})->with([
    'loop body' => ['int $sum = 0; foreach ([1, 2] as int $n) { int $inner = when ($x > $n) { return $x * 2; } else { return 0; }; $sum += $inner; } return $sum;', '4|0|0'],
    'return operand' => ['return when ($x > 1) { return $x * 2; } else { return 1; };', '4|1|0'],
    'callable scope' => ['array<int> $items = array_map(function (int $v): int { return when ($v > 1) { return $v * 2; } else { return 1; }; }, [$x]); return $items[0];', '4|1|0'],
]);

test('typed loop bindings inside a when nested callable build and run', function (string $loop): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', str_replace('LOOP', $loop, <<<'PPP'
<?php
function nested(array<int> $values): int {
    int $r = when (count($values) > 0) {
        array<int> $found = array_map(function (int $v): int {
            LOOP
            return $v * 2;
        }, $values);
        return $found[0];
    } else { return 0; };
    return $r;
}
echo nested([3]), '|', nested([]);
PPP));
    $build = runWhenNestedCommand($root, 'build');
    expect($build->getStatusCode())->toBe(0, $build->getDisplay());
    $runtime = new Process([PHP_BINARY, $root . '/build/ppphp/main.php'], timeout: 5);
    $runtime->mustRun();
    expect($runtime->getOutput())->toBe('6|0')->and($runtime->getErrorOutput())->toBe('');
})->with([
    'foreach key and value' => ['foreach (["one" => 1, "two" => 2] as string $key => int $n) { if ($key === "two" && $n === 2) { break; } }'],
    'for initializer' => ['for (int $n = 1; $n < 3; $n++) { if ($n === 2) { break; } }'],
]);

test('nested callable loop registration preserves binding restrictions', function (string $body, string $code): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', str_replace('BODY', $body, <<<'PPP'
<?php
function nested(bool $ready): int {
    int $r = when ($ready) {
        array<int> $found = array_map(function (int $v): int { BODY return $v; }, [1]);
        return $found[0];
    } else { return 0; };
    return $r;
}
PPP));
    $build = runWhenNestedCommand($root, 'build');
    $payload = json_decode($build->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
    expect($build->getStatusCode())->toBe(1, $build->getDisplay())
        ->and(in_array($code, array_column($payload['diagnostics'], 'code'), true))->toBeTrue($build->getDisplay())
        ->and(array_column($payload['diagnostics'], 'code'))->not->toContain('P9001')
        ->and(file_exists($root . '/build/ppphp/main.php'))->toBeFalse();
})->with([
    'wrong declared iteration type' => ['foreach ([1, 2] as string $n) { $v += strlen($n); }', 'P2026'],
    'duplicate declaration' => ['int $n = 0; foreach ([1, 2] as int $n) { $v += $n; }', 'P2004'],
    'readonly existing target' => ['readonly int $n = 0; foreach ([1, 2] as $n) { $v += $n; }', 'P2005'],
    'undeclared existing target' => ['foreach ([1, 2] as $n) { $v += $n; }', 'P2002'],
    'wrong existing target type' => ['string $n = ""; foreach ([1, 2] as $n) {}', 'P2009'],
    'by-reference target' => ['array<int> $items = [1, 2]; int $n = 0; foreach ($items as &$n) {}', 'P2010'],
]);

test('focused builds retain sibling declarations without lowering invalid bodies', function (string $body, string $code): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Sibling.ppphp', str_replace('BODY', $body, <<<'PPP'
<?php
final class Sibling<T> {
    public function value(T $input): T { BODY return $input; }
    public function problem(): void throws RuntimeException { throw new RuntimeException(); }
}
PPP));
    $this->writeFile($root . '/src/clean.ppphp', '<?php function consume(Sibling<int> $s): int { return $s->value(2); }');
    $focused = runWhenNestedCommand($root, 'build', 'src/clean.ppphp');
    expect($focused->getStatusCode())->toBe(0, $focused->getDisplay())
        ->and(file_exists($root . '/build/ppphp/clean.php'))->toBeTrue()
        ->and(file_exists($root . '/build/ppphp/Sibling.php'))->toBeFalse();
    $contextFiles = glob($root . '/.ppphp-cache/analysis/context/*/Sibling.php') ?: [];
    expect($contextFiles)->toHaveCount(1);
    $context = file_get_contents($contextFiles[0]);
    expect($context)->toContain('@template T', '@param T $input', '@return T', '@throws \\RuntimeException', 'throw new \\LogicException()')
        ->not->toContain('when (')->not->toContain('$broken')->not->toContain('return $input');
    $complete = runWhenNestedCommand($root, 'check');
    $payload = json_decode($complete->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
    expect($complete->getStatusCode())->toBe(1, $complete->getDisplay())
        ->and(array_column($payload['diagnostics'], 'code'))->toContain($code)->not->toContain('P9001');
})->with([
    'unsupported when owner' => ['int $broken = 0; do { $broken++; } while ($broken = when ($input !== null) { return 1; } else { return 2; });', 'P5005'],
    'when missing result' => ['int $broken = when ($input !== null) { echo "missing"; } else { return 2; };', 'P5002'],
    'duplicate local binding' => ['int $broken = 0; int $broken = 1;', 'P2004'],
    'nested callable default is body context' => ['callable $broken = function (?int $x = when (true) { return 1; } else { return 2; }): int { return $x ?? 0; };', 'P5005'],
]);

test('focused context does not fabricate declarations from invalid when defaults', function (string $member): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Sibling.ppphp', '<?php final class Sibling { ' . $member . ' }');
    $this->writeFile($root . '/src/clean.ppphp', '<?php function consume(Sibling $s): void {}');
    $this->writeFile($root . '/src/independent.ppphp', '<?php echo "independent";');
    $dependent = runWhenNestedCommand($root, 'check', 'src/clean.ppphp');
    $payload = json_decode($dependent->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
    expect($dependent->getStatusCode())->toBe(1, $dependent->getDisplay())
        ->and(array_column($payload['diagnostics'], 'code'))->toContain('P2020')->not->toContain('P9001');
    $independent = runWhenNestedCommand($root, 'build', 'src/independent.ppphp');
    expect($independent->getStatusCode())->toBe(0, $independent->getDisplay())
        ->and(glob($root . '/.ppphp-cache/analysis/context/*/Sibling.php') ?: [])->toBe([]);
})->with([
    'parameter default' => ['public function value(?int $x = when (true) { return 1; } else { return 2; }): void {}'],
    'property default' => ['public ?int $value = when (true) { return 1; } else { return 2; };'],
    'constant value' => ['public const VALUE = when (true) { return 1; } else { return 2; };'],
    'attribute argument' => ['#[Deprecated(when (true) { return "old"; } else { return "new"; })] public function value(): void {}'],
]);
