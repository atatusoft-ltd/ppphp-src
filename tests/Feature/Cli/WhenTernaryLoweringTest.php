<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Support\GoldenFile;

test('bare when branches emit the exact native ternary shape', function (string $fixture, string $expected): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $fixtures = dirname(__DIR__, 2) . '/Fixtures/WhenDecisions/Tail';
    $this->writeFile($root . '/src/main.ppphp', file_get_contents($fixtures . '/' . $fixture . '.ppphp'));
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json']);
    $build->mustRun();
    GoldenFile::assertMatches($fixtures . '/' . $fixture . '.php', file_get_contents($root . '/build/ppphp/main.php'));
    $run = new Process([PHP_BINARY, $root . '/build/ppphp/main.php']);
    $run->mustRun();
    expect($run->getOutput())->toBe($expected)->and($run->getErrorOutput())->toBe('');
})->with([
    ['BareBranches', '1200|500|211'],
    ['NestedBareBranches', '123|456'],
    ['Comments', 'ready|waiting|12'],
]);

test('bare when branches preserve native expression evaluation and binding', function (string $template, string $when, string $native, string $expected): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', '<?php ' . str_replace('WHEN', $when, $template));
    $this->writeFile($root . '/reference.php', '<?php ' . str_replace(['WHEN', 'array<int>'], [$native, 'array'], $template));
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json']);
    $build->mustRun();
    $php = file_get_contents($root . '/build/ppphp/main.php');
    expect($php)->toContain(' ? ')->not->toContain('__ppphp_when_')->not->toContain('finally {')
        ->not->toContain('do {')->not->toContain('match (');
    foreach (['reference.php', 'build/ppphp/main.php'] as $file) {
        $run = new Process([PHP_BINARY, $root . '/' . $file]);
        $run->mustRun();
        expect($run->getOutput())->toBe($expected, $file)->and($run->getErrorOutput())->toBe('');
    }
})->with([
    'return operand and truthiness' => [
        'function run(int $condition): int { return WHEN; } echo run(0), run(2), run(-1);',
        'when ($condition) { return 1; } else { return 2; }', '$condition ? 1 : 2', '211',
    ],
    'nested reference receiver' => [
        'class Box { public int $field = 0; } function keep(Box $box, int $flag): Box { echo $flag; return $box; } function write(int &$field, int $value): void { $field = $value; } function run(Box $box, bool $vip): void { write(keep($box, WHEN)->field, 9); echo $box->field; } run(new Box(), true); run(new Box(), false);',
        'when ($vip) { return 1; } else { return 2; }', '$vip ? 1 : 2', '1929',
    ],
    'earlier side-effecting argument' => [
        'function load(): int { echo "load|"; return 1; } function condition(bool $vip): bool { echo "condition|"; return $vip; } function save(int $value, int $flag): void { echo $value, $flag, "|"; } function run(bool $vip): void { save(load(), WHEN); } run(true); run(false);',
        'when (condition($vip)) { return 2; } else { return 3; }', 'condition($vip) ? 2 : 3', 'load|condition|12|load|condition|13|',
    ],
    'known reference parameter binding' => [
        'function show(int &$value, int $flag): void { echo $value, $flag; $value = 9; } function run(int $value, bool $vip): void { show($value, WHEN); echo $value; } run(1, true); run(1, false);',
        'when ($vip) { return ($value = 2); } else { return ($value = 3); }', '$vip ? ($value = 2) : ($value = 3)', '229339',
    ],
    'unresolved literal binding rejects before the condition' => [
        'function condition(bool $vip): bool { echo "condition|"; return $vip; } function run(callable $callback, bool $vip): void { try { $callback(1, WHEN); } catch (Error) { echo "caught|"; } } run(function (int &$value, int $unused): void { echo "called|"; }, true); run(function (int &$value, int $unused): void { echo "called|"; }, false);',
        'when (condition($vip)) { return 2; } else { return 3; }', 'condition($vip) ? 2 : 3', 'caught|caught|',
    ],
    'unresolved callbacks retain their native value or reference mode' => [
        'function run(callable $callback, int $value, bool $vip): void { $callback($value, WHEN); echo $value, ";"; } run(function (int $value, int $unused): void { echo $value, "|"; }, 1, true); run(function (int $value, int $unused): void { echo $value, "|"; }, 1, false); run(function (int &$value, int $unused): void { echo $value, "|"; }, 1, true); run(function (int &$value, int $unused): void { echo $value, "|"; }, 1, false);',
        'when ($vip) { return ($value = 2); } else { return ($value = 3); }', '$vip ? ($value = 2) : ($value = 3)', '1|2;1|3;2|2;3|3;',
    ],
    'unpack binds old elements before replacing the container' => [
        'function accept(int &$target, int $unused): void { $target = 9; } function replace(array &$items): int { $items = [7]; return 2; } function run(array<int> $items, bool $vip): void { accept(...$items, unused: WHEN); echo json_encode($items); } run([1], true); run([1], false);',
        'when ($vip) { return replace($items); } else { return 3; }', '$vip ? replace($items) : 3', '[7][9]',
    ],
    'nullsafe chain stays native' => [
        'class Box { public function show(int $value): int { echo "show|"; return $value; } } function condition(bool $vip): bool { echo "condition|"; return $vip; } function run(?Box $box, bool $vip): ?int { return $box?->show(WHEN); } echo run(null, true) ?? 0; echo run(new Box(), true); echo run(new Box(), false);',
        'when (condition($vip)) { return 1; } else { return 2; }', 'condition($vip) ? 1 : 2', '0condition|show|1condition|show|2',
    ],
    'nested bare branches' => [
        'function run(bool $vip, bool $member): int { return WHEN; } echo run(true, true), run(true, false), run(false, true);',
        'when ($vip) { return when ($member) { return 1; } else { return 2; }; } else { return 3; }', '$vip ? ($member ? 1 : 2) : 3', '123',
    ],
]);

test('branch statement comments retain their context instead of disappearing into a ternary', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', <<<'PPP'
<?php
function run(bool $vip): int {
    return when ($vip) {
        // The member rate applies here.
        return 1;
    } else { return 2; };
}
echo run(true), run(false);
PPP);
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json']);
    $build->mustRun();
    $php = file_get_contents($root . '/build/ppphp/main.php');
    expect($php)->toContain('if ($vip)', '// The member rate applies here.', 'return 1;');
    $run = new Process([PHP_BINARY, $root . '/build/ppphp/main.php']);
    $run->mustRun();
    expect($run->getOutput())->toBe('12');
});
