<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Support\GoldenFile;

test('stable destinations and operands have no prerequisite or cleanup wrapper', function (string $fixture, string $expected): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $fixtures = dirname(__DIR__, 2) . '/Fixtures/WhenDecisions/Tail';
    $this->writeFile($root . '/src/main.ppphp', file_get_contents($fixtures . '/' . $fixture . '.ppphp'));
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json']);
    $build->mustRun();
    $php = file_get_contents($root . '/build/ppphp/main.php');
    GoldenFile::assertMatches($fixtures . '/' . $fixture . '.php', $php);
    expect($php)->not->toContain('__ppphp_when_prerequisite')->not->toContain('try {')->not->toContain('finally {');
    expect($php)->toContain('elseif ($tier === 2)');
    if ($fixture === 'StableDestinations') {
        expect($php)->not->toContain('__ppphp_when_')->not->toContain('unset(');
    }
    $run = new Process([PHP_BINARY, $root . '/build/ppphp/main.php']);
    $run->mustRun();
    expect($run->getOutput())->toBe($expected)->and($run->getErrorOutput())->toBe('');
})->with([
    ['StableDestinations', '90|9|100|10|110|11|90|9|100|10|110|11|'],
    ['StableOperands', '7:1|7:1|7:2|7:2|7:3|7:3|7:1|7:1|7:2|7:2|7:3|7:3|'],
]);

test('stable non-call operands and resolved references need no prerequisite', function (string $body, string $expected): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', str_replace(['BODY', 'WHEN'], [
        $body, 'when ($tier === 1) { return 1; } else when ($tier === 2) { return 2; } else { return 3; }',
    ], <<<'PPP'
<?php
function replace(int &$value, int $replacement): void { $value = $replacement; }
function choose(int $left, int $tier): mixed { BODY }
echo json_encode(choose(7, 1)), '|', json_encode(choose(7, 2));
PPP));
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json']);
    $build->mustRun();
    $php = file_get_contents($root . '/build/ppphp/main.php');
    expect($php)->not->toContain('__ppphp_when_prerequisite')->not->toContain('try {');
    $run = new Process([PHP_BINARY, $root . '/build/ppphp/main.php']);
    $run->mustRun();
    expect($run->getOutput())->toBe($expected)->and($run->getErrorOutput())->toBe('');
})->with([
    ['return [$left, WHEN];', '[7,1]|[7,2]'],
    ['return [$left => WHEN];', '{"7":1}|{"7":2}'],
    ['replace($left, WHEN); return $left;', '1|2'],
]);

test('a property result temporary does not capture its direct variable receiver', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', <<<'PPP'
<?php
class Box {
    public int $total = 7;
    public function apply(int $tier): void {
        $this->total = when ($tier === 1) { return $this->total; } else when ($tier === 2) { return 2; } else { return 3; };
        echo $this->total;
    }
}
(new Box())->apply(1);
(new Box())->apply(2);
PPP);
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json']);
    $build->mustRun();
    $php = file_get_contents($root . '/build/ppphp/main.php');
    expect($php)->toContain('$this->total = $__ppphp_when_')
        ->not->toContain('__ppphp_when_prerequisite')->not->toContain('try {');
    $run = new Process([PHP_BINARY, $root . '/build/ppphp/main.php']);
    $run->mustRun();
    expect($run->getOutput())->toBe('72')->and($run->getErrorOutput())->toBe('');
});

test('capture elision preserves aliases and all intervening operands', function (string $source, string $when, string $native, string $expected): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', '<?php ' . str_replace('WHEN', $when, $source));
    $this->writeFile($root . '/reference.php', '<?php ' . str_replace('WHEN', $native, $source));
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json']);
    $build->mustRun();
    foreach (['reference.php', 'build/ppphp/main.php'] as $file) {
        $run = new Process([PHP_BINARY, $root . '/' . $file]);
        $run->mustRun();
        expect($run->getOutput())->toBe($expected, $file)->and($run->getErrorOutput())->toBe('');
    }
})->with([
    'later ordinary argument writes earlier operand' => [
        'function show(int $a, int $b, int $c): void { echo $a, $b, $c; } function invoke(int $x, int $tier): void { show($x, $x = 2, WHEN); } invoke(1, 1); invoke(1, 2);',
        'when ($tier === 1) { return 3; } else when ($tier === 2) { return 4; } else { return 5; }', '$tier === 1 ? 3 : ($tier === 2 ? 4 : 5)', '123124',
    ],
    'later when writes an operand skipped by an earlier when' => [
        'function show(int $a, int $b, int $c): void { echo $a, $b, $c; } function invoke(int $x, int $tier): void { show($x, WHEN); } invoke(1, 1); invoke(1, 2);',
        'when ($tier === 1) { return 3; } else when ($tier === 2) { return 4; } else { return 5; }, when ($tier === 1) { $x = 7; return 5; } else { $x = 8; return 6; }',
        '($tier === 1 ? 3 : ($tier === 2 ? 4 : 5)), ($tier === 1 ? (($x = 7) - 2) : (($x = 8) - 2))', '135146',
    ],
    'write through aliased parameter' => [
        'function show(int $a, int $b): void { echo $a, $b; } function invoke(int &$value, int &$alias, bool $vip): void { show($value, WHEN); echo $value; } function start(int $value): void { invoke($value, $value, true); } start(1);',
        'when ($vip) { $alias = 7; return 2; } else { $alias = 8; return 3; }',
        '$vip ? (($alias = 7) - 5) : (($alias = 8) - 5)', '127',
    ],
    'receiver replaced through aliased parameter' => [
        'class Box { public int $total = 0; } function invoke(Box &$box, Box &$alias, bool $vip): void { $box->total = WHEN; echo $box->total; } function start(Box $box): void { invoke($box, $box, true); } start(new Box());',
        'when ($vip) { $alias = new Box(); return 2; } else { return 3; }',
        '$vip ? (($alias = new Box())->total + 2) : 3', '2',
    ],
    'direct variable receiver replaced by RHS' => [
        'class Box { public int $total = 0; } function invoke(Box $box, bool $vip): void { $box->total = WHEN; echo $box->total; } invoke(new Box(), true);',
        'when ($vip) { $box = new Box(); return 2; } else { return 3; }',
        '$vip ? (($box = new Box())->total + 2) : 3', '2',
    ],
    'receiver expression evaluated before RHS' => [
        'class Box { public int $total = 0; } function receiver(Box $box): Box { echo "receiver|"; return $box; } function branch(): int { echo "branch|"; return 2; } function invoke(Box $box, int $tier): void { receiver($box)->total = WHEN; echo $box->total; } invoke(new Box(), 1);',
        'when ($tier === 1) { return branch(); } else when ($tier === 2) { return 3; } else { return 4; }', '$tier === 1 ? branch() : ($tier === 2 ? 3 : 4)', 'receiver|branch|2',
    ],
    'by-reference call mutates earlier operand' => [
        'function replace(int &$value): int { $value = 7; return 2; } function show(int $a, int $b): void { echo $a, $b; } function invoke(int $value, int $tier): void { show($value, WHEN); echo $value; } invoke(1, 1);',
        'when ($tier === 1) { return replace($value); } else when ($tier === 2) { return 3; } else { return 4; }', '$tier === 1 ? replace($value) : ($tier === 2 ? 3 : 4)', '127',
    ],
]);
