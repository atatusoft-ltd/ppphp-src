<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Support\GoldenFile;

test('stable guard fallback goldens need no sentinel or completion bit', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $fixtures = dirname(__DIR__, 2) . '/Fixtures/WhenDecisions/Tail';
    $this->writeFile($root . '/src/main.ppphp', file_get_contents($fixtures . '/GuardFallbacks.ppphp'));
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json']);
    $build->mustRun();
    $php = file_get_contents($root . '/build/ppphp/main.php');
    GoldenFile::assertMatches($fixtures . '/GuardFallbacks.php', $php);
    expect($php)->not->toContain('__ppphp_when_')->not->toContain('do {');
    $run = new Process([PHP_BINARY, $root . '/build/ppphp/main.php']);
    $run->mustRun();
    expect($run->getOutput())->toBe('[10,-1,-9,20,7,null,4,30,null]')
        ->and($run->getErrorOutput())->toBe('');
});

test('guard fallbacks are evaluated early only when stable', function (string $type, string $prefix, string $early, string $fallback, bool $preassigned, string $expected): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $branch = 'if ($prefix) { ' . $prefix . ' if ($take) { return ' . $early . '; } } return ' . $fallback . ';';
    $template = <<<'PHP'
<?php
REFERENCE
function run(bool $enabled, bool $prefix, bool $take, int $baseline): TYPE {
    TYPE $result = EXPRESSION;
    return $result;
}
echo json_encode([run(true, true, true, 0), run(true, true, false, 7), run(true, false, false, 7), run(false, true, true, 7)]);
PHP;
    $template = str_replace('TYPE', $type, $template);
    $source = str_replace(['REFERENCE', 'EXPRESSION'], ['', 'when ($enabled) { ' . $branch . ' } else { return -9; }'], $template);
    $this->writeFile($root . '/src/main.ppphp', $source);
    $reference = str_replace(['REFERENCE', 'EXPRESSION', $type . ' $result ='], [
        'function selectResult(bool $enabled, bool $prefix, bool $take, int $baseline): ' . $type . ' { if ($enabled) { ' . $branch . ' } else { return -9; } }',
        'selectResult($enabled, $prefix, $take, $baseline)', '$result =',
    ], $template);
    $this->writeFile($root . '/reference.php', $reference);
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json']);
    $build->mustRun();
    $php = file_get_contents($root . '/build/ppphp/main.php');
    $assignment = strpos($php, '$result = ' . $fallback . ';');
    $guard = strpos($php, 'if ($prefix)');
    expect($assignment)->not->toBeFalse()->and($guard)->not->toBeFalse()
        ->and($assignment < $guard)->toBe($preassigned)
        ->and($php)->not->toContain('__ppphp_when_')->not->toContain('do {');
    foreach (['reference.php', 'build/ppphp/main.php'] as $file) {
        $run = new Process([PHP_BINARY, $root . '/' . $file]);
        $run->mustRun();
        expect($run->getOutput())->toBe($expected, $file)->and($run->getErrorOutput())->toBe('');
    }
})->with([
    'positive literal' => ['int', '', '123', '4', true, '[123,4,4,-9]'],
    'negative literal' => ['int', '', '123', '-1', true, '[123,-1,-1,-9]'],
    'unchanged local' => ['int', '', '123', '$baseline', true, '[123,7,7,-9]'],
    'nullable fallback' => ['?int', '', '123', 'null', true, '[123,null,null,-9]'],
    'nullable early result' => ['?int', '', 'null', '4', true, '[null,4,4,-9]'],
    'changed local' => ['int', '$baseline += 1;', '123', '$baseline', false, '[123,8,7,-9]'],
    'throwing operator' => ['int', '', '123', '10 % $baseline', false, '[123,3,3,-9]'],
]);
