<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

test('when argument hoisting preserves known parameter bindings', function (string $declarations, string $setup, string $call, string $output, array $expected): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $template = '<?php ' . $declarations . "\n" . $setup . "\n" . $call . "\n" . $output;
    $when = 'when (getenv("BRANCH") !== "other") { $value = 7; return 2; } else { $value = 8; return 3; }';
    $this->writeFile($root . '/src/main.ppphp', str_replace('WHEN', $when, $template));
    $reference = str_replace(
        ['WHEN', 'int $value', 'Receiver $receiver', 'array<int> $values'],
        ['(getenv("BRANCH") !== "other" ? (($value = 7) - 5) : (($value = 8) - 5))', '$value', '$receiver', '$values'],
        $template,
    );
    $this->writeFile($root . '/reference.php', $reference);
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json']);
    $build->mustRun();
    foreach (['first', 'other'] as $index => $branch) {
        $native = new Process([PHP_BINARY, $root . '/reference.php'], env: ['BRANCH' => $branch]);
        $native->mustRun();
        $compiled = new Process([PHP_BINARY, $root . '/build/ppphp/main.php'], env: ['BRANCH' => $branch]);
        $compiled->mustRun();
        expect($native->getOutput())->toBe($expected[$index])
            ->and($compiled->getOutput())->toBe($native->getOutput())
            ->and($compiled->getErrorOutput())->toBe('');
    }
})->with([
    'function reference' => [
        'function replaceValue(int &$target, int $replacement): void { $target = $replacement; }',
        'int $value = 1;', 'replaceValue($value, WHEN);', 'echo $value;', ['2', '3'],
    ],
    'function value snapshot' => [
        'function showValue(int $target, int $unused): void { echo $target, "|"; }',
        'int $value = 1;', 'showValue($value, WHEN);', 'echo $value;', ['1|7', '1|8'],
    ],
    'named reference' => [
        'function replaceValue(int $replacement, int &$target): void { $target = $replacement; }',
        'int $value = 1;', 'replaceValue(target: $value, replacement: WHEN);', 'echo $value;', ['2', '3'],
    ],
    'method reference' => [
        'class Receiver { public function replaceValue(int &$target, int $replacement): void { $target = $replacement; } }',
        'Receiver $receiver = new Receiver(); int $value = 1;', '$receiver->replaceValue($value, WHEN);', 'echo $value;', ['2', '3'],
    ],
    'static reference' => [
        'class Receiver { public static function replaceValue(int &$target, int $replacement): void { $target = $replacement; } }',
        'int $value = 1;', 'Receiver::replaceValue($value, WHEN);', 'echo $value;', ['2', '3'],
    ],
    'constructor reference' => [
        'class Receiver { public function __construct(int &$target, int $replacement) { $target = $replacement; } }',
        'int $value = 1;', 'new Receiver($value, WHEN);', 'echo $value;', ['2', '3'],
    ],
    'property location' => [
        'class Receiver { public int $target = 1; } function replaceValue(int &$target, int $replacement): void { $target = $replacement; }',
        'Receiver $receiver = new Receiver(); int $value = 1;', 'replaceValue($receiver->target, WHEN);', 'echo $receiver->target;', ['2', '3'],
    ],
    'array location' => [
        'function replaceValue(int &$target, int $replacement): void { $target = $replacement; }',
        'array<int> $values = [1]; int $value = 1;', 'replaceValue($values[0], WHEN);', 'echo $values[0];', ['2', '3'],
    ],
    'named variadic reference' => [
        'function inspectTargets(int $unused, int &...$targets): void { echo $targets["target"], "|"; }',
        'int $value = 1;', 'inspectTargets(target: $value, unused: WHEN);', 'echo $value;', ['7|7', '8|8'],
    ],
    'nested reference location' => [
        'class Receiver { public int $target = 1; } function identity(Receiver $receiver, int $unused): Receiver { return $receiver; } function replaceValue(int &$target, int $replacement): void { $target = $replacement; }',
        'Receiver $receiver = new Receiver(); int $value = 1;', 'replaceValue(identity($receiver, WHEN)->target, 9);', 'echo $receiver->target;', ['9', '9'],
    ],
    'nested reference before another when' => [
        'class Receiver { public int $target = 1; } function identity(Receiver $receiver, int $unused): Receiver { return $receiver; } function replaceValue(int &$target, int $replacement): void { $target = $replacement; }',
        'Receiver $receiver = new Receiver(); int $value = 1;', 'replaceValue(identity($receiver, WHEN)->target, WHEN);', 'echo $receiver->target;', ['2', '3'],
    ],
    'temporary reference receiver' => [
        'class Receiver { public int $target = 1; public function __destruct() { echo "destroy:", $this->target, "|"; } } function createReceiver(int $unused): Receiver { return new Receiver(); } function replaceValue(int &$target, int $replacement): void { echo "call|"; $target = $replacement; }',
        'int $value = 1;', 'replaceValue(createReceiver(WHEN)->target, 9);', 'echo "after";', ['destroy:1|call|after', 'destroy:1|call|after'],
    ],
    'array reference element' => [
        '', 'int $value = 1;', 'array<int> $values = [&$value, WHEN];', '$value = 9; echo $values[0], "|", $values[1];', ['9|2', '9|3'],
    ],
    'nested array reference element' => [
        'class Receiver { public int $target = 1; } function identity(Receiver $receiver, int $unused): Receiver { return $receiver; }',
        'Receiver $receiver = new Receiver(); int $value = 1;', 'array<int> $values = [&identity($receiver, WHEN)->target, WHEN];', '$receiver->target = 9; echo $values[0], "|", $values[1];', ['9|2', '9|3'],
    ],
]);

test('a hoisted scalar reference is released before an outer catch can copy its array', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $template = <<<'PHP'
<?php
function replaceElement(int &$target, int $replacement): void {
    if (getenv('FAULT') === 'consumer') { throw new Error('consumer'); }
    $target = $replacement;
}
array<int> $items = [1];
try { replaceElement($items[0], WHEN); } catch (Error) {}
array<int> $copy = $items;
$copy[0] = 9;
echo $items[0];
PHP;
    $this->writeFile($root . '/src/main.ppphp', str_replace('WHEN',
        'when (getenv("FAULT") !== "branch") { return 2; } else { throw new Error("branch"); }', $template));
    $this->writeFile($root . '/reference.php', str_replace(
        ['WHEN', 'array<int> $items', 'array<int> $copy'],
        ['(getenv("FAULT") !== "branch" ? 2 : throw new Error("branch"))', '$items', '$copy'], $template,
    ));
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json']);
    $build->mustRun();
    foreach (['none', 'branch', 'consumer'] as $fault) {
        $native = new Process([PHP_BINARY, $root . '/reference.php'], env: ['FAULT' => $fault]);
        $native->mustRun();
        $compiled = new Process([PHP_BINARY, $root . '/build/ppphp/main.php'], env: ['FAULT' => $fault]);
        $compiled->mustRun();
        expect($native->getOutput())->toBe($fault === 'none' ? '2' : '1')
            ->and($compiled->getOutput())->toBe($native->getOutput())
            ->and($compiled->getErrorOutput())->toBe('');
    }
});
