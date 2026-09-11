<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Support\GoldenFile;

test('successive when guards emit an ordinary conditional chain', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $fixtures = dirname(__DIR__, 2) . '/Fixtures/WhenDecisions/Tail';
    $this->writeFile($root . '/src/main.ppphp', file_get_contents($fixtures . '/Guards.ppphp'));
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json']);
    $build->mustRun();
    GoldenFile::assertMatches($fixtures . '/Guards.php', file_get_contents($root . '/build/ppphp/main.php'));
    $run = new Process([PHP_BINARY, $root . '/build/ppphp/main.php']);
    $run->mustRun();
    expect($run->getOutput())->toBe('invalid|zero|band 3|special')->and($run->getErrorOutput())->toBe('');
});

test('partial guard goldens retain nullable source types and explicit condition grouping', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $fixtures = dirname(__DIR__, 2) . '/Fixtures/WhenDecisions/Tail';
    $this->writeFile($root . '/src/main.ppphp', file_get_contents($fixtures . '/PartialGuards.ppphp'));
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json']);
    $build->mustRun();
    GoldenFile::assertMatches($fixtures . '/PartialGuards.php', file_get_contents($root . '/build/ppphp/main.php'));
    $run = new Process([PHP_BINARY, $root . '/build/ppphp/main.php']);
    $run->mustRun();
    expect($run->getOutput())->toBe('first|1|2|rest|3|4|-1|guard|rest|2|guard|null|one|two|rest|tail:2|other|rest|tail:3|zero')
        ->and($run->getErrorOutput())->toBe('');
});

test('when guards preserve native evaluation and greedy completion', function (string $branch, string $expected, bool $assignment): void {
    if ($assignment) {
        // Returning from the block must still reach the enclosing consumer.
        $expected = preg_replace('/(-?[0-9]+)\|/', 'after|$1|', $expected);
    }
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $when = 'when ($enabled) { ' . $branch . ' } else { return -9; }';
    $consumer = $assignment ? 'int $result = ' . $when . '; echo "after|"; return $result;' : 'return ' . $when . ';';
    $template = <<<'PHP'
<?php
function condition(int $value): bool { echo 'condition|'; return $value > 0; }
function run(bool $enabled, int $x, bool $flag): int { BODY }
echo run(true, -1, true), '|', run(true, -1, false), '|';
echo run(true, 0, true), '|', run(true, 0, false), '|';
echo run(true, 2, true), '|', run(true, 2, false), '|';
echo run(false, -1, true), '|', run(false, -1, false), '|';
echo run(false, 0, true), '|', run(false, 0, false), '|';
echo run(false, 2, true), '|', run(false, 2, false), '|';
PHP;
    $this->writeFile($root . '/src/main.ppphp', str_replace('BODY', $consumer, $template));
    $reference = 'if ($enabled) { ' . $branch . ' } else { return -9; }';
    if ($assignment) {
        $template = str_replace('function run(', 'function result(bool $enabled, int $x, bool $flag): int { ' . $reference . " }\nfunction run(", $template);
        $reference = '$result = result($enabled, $x, $flag); echo "after|"; return $result;';
    }
    $this->writeFile($root . '/reference.php', str_replace('BODY', $reference, $template));
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json']);
    $build->mustRun();
    $php = file_get_contents($root . '/build/ppphp/main.php');
    expect($php)->not->toContain('do {')->not->toContain('while (true)')
        ->not->toContain('finally {')->not->toContain('goto ');
    if (!$assignment) {
        expect($php)->not->toContain('__ppphp_when_');
    }
    foreach (['reference.php', 'build/ppphp/main.php'] as $file) {
        $run = new Process([PHP_BINARY, $root . '/' . $file]);
        $run->mustRun();
        expect($run->getOutput())->toBe($expected, $file)->and($run->getErrorOutput())->toBe('');
    }
})->with([
    'successive guards' => [
        'if ($x < 0) { return -1; } if ($x === 0) { return 0; } echo "rest|"; return $x * 10;',
        '-1|-1|0|0|rest|20|rest|20|-9|-9|-9|-9|-9|-9|',
    ],
    'existing else and partial nested guard' => [
        'if (condition($x)) { if ($flag) { return 1; } echo "inner|"; } else { echo "else|"; } echo "rest|"; return 2;',
        'condition|else|rest|2|condition|else|rest|2|condition|else|rest|2|condition|else|rest|2|condition|1|condition|inner|rest|2|-9|-9|-9|-9|-9|-9|',
    ],
    'elseif with continuing true arm' => [
        'if ($x < 0) { echo "negative|"; } elseif ($flag) { return 1; } else { echo "other|"; } echo "rest|"; return 2;',
        'negative|rest|2|negative|rest|2|1|other|rest|2|1|other|rest|2|-9|-9|-9|-9|-9|-9|',
    ],
    'guard inside a tail switch case' => [
        'switch ($x) { case 2: if ($flag) { return 1; } echo "rest|"; return 3; default: return 4; }',
        '4|4|4|4|1|rest|3|-9|-9|-9|-9|-9|-9|',
    ],
    'partial switch before its continuation' => [
        'switch ($x) { case 2: return 1; case 0: echo "zero|"; break; default: echo "negative|"; } echo "rest|"; return 2;',
        'negative|rest|2|negative|rest|2|zero|rest|2|zero|rest|2|1|1|-9|-9|-9|-9|-9|-9|',
    ],
    'folded guard condition precedence' => [
        'if ($flag) { echo "first|"; if ($x > 0) { return 1; } } if ($x > 0 || $flag) { return 2; } return 3;',
        'first|2|3|first|2|3|first|1|2|-9|-9|-9|-9|-9|-9|',
    ],
])->with(['return' => false, 'assignment' => true]);

test('shared guard completion preserves null results and failed assignments without output growth', function (bool $nullable): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $branch = '';
    for ($index = 0; $index < 8; $index++) {
        $branch .= 'if ($take >= ' . $index . ') { echo "guard' . $index . '|"; '
            . 'if ($take === ' . $index . ') { return produce(' . $index . ', $fail); } echo "continue|"; }' . "\n";
    }
    $branch .= 'echo "remaining|"; return -1;';
    $type = $nullable ? '?int' : 'int';
    $template = <<<'PHP'
<?php
function produce(int $value, bool $fail): TYPE {
    echo 'produce|';
    if ($fail) { throw new Error('result failed'); }
    return VALUE;
}
REFERENCE
function run(bool $enabled, int $take, bool $fail): void {
    TYPE $destination = 99;
    try {
        $destination = EXPRESSION;
        echo $destination === null ? 'null' : (string) $destination, '|';
    } catch (Error) {
        echo 'caught:', $destination, '|';
    }
}
CALLS
PHP;
    $calls = '';
    $expected = '';
    foreach ([false, true] as $enabled) {
        foreach ([-1, 0, 3, 7, 8] as $take) {
            foreach ([false, true] as $fail) {
                $calls .= sprintf("run(%s, %d, %s);\n", $enabled ? 'true' : 'false', $take, $fail ? 'true' : 'false');
                if (!$enabled) {
                    $expected .= '-9|';
                    continue;
                }
                for ($index = 0; $index < 8 && $index <= $take; $index++) {
                    $expected .= 'guard' . $index . '|';
                    if ($index === $take) {
                        $expected .= 'produce|' . ($fail ? 'caught:99' : ($nullable && $index === 0 ? 'null' : $index)) . '|';
                        continue 2;
                    }
                    $expected .= 'continue|';
                }
                $expected .= 'remaining|-1|';
            }
        }
    }
    $template = str_replace(['TYPE', 'VALUE', 'CALLS'], [$type, $nullable ? '$value === 0 ? null : $value' : '$value', $calls], $template);
    // A non-null result needs no nullable display branch in the control.
    if (!$nullable) {
        $template = str_replace("\$destination === null ? 'null' : (string) \$destination", '(string) $destination', $template);
    }
    $source = str_replace(['REFERENCE', 'EXPRESSION'], ['', 'when ($enabled) { ' . $branch . ' } else { return -9; }'], $template);
    $this->writeFile($root . '/src/main.ppphp', $source);
    $reference = str_replace(['REFERENCE', 'EXPRESSION'], [
        'function selectResult(bool $enabled, int $take, bool $fail): ' . $type . ' { if ($enabled) { ' . $branch . ' } else { return -9; } }',
        'selectResult($enabled, $take, $fail)',
    ], $template);
    $this->writeFile($root . '/reference.php', str_replace($type . ' $destination =', '$destination =', $reference));
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json']);
    $build->mustRun();
    $php = file_get_contents($root . '/build/ppphp/main.php');
    expect(substr_count($php, 'remaining|'))->toBe(1)
        ->and(strlen($php))->toBeLessThan(strlen($source) * 4)
        ->and($php)->not->toContain('do {')->not->toContain('while (true)')->not->toContain('goto ')
        ->and(str_contains($php, '__ppphp_when_complete'))->toBe($nullable);
    foreach (['reference.php', 'build/ppphp/main.php'] as $file) {
        $run = new Process([PHP_BINARY, $root . '/' . $file]);
        $run->mustRun();
        expect($run->getOutput())->toBe($expected, $file)->and($run->getErrorOutput())->toBe('');
    }
})->with(['non-null' => false, 'nullable' => true]);

test('shared guards retain native object lifetime at their consumer', function (bool $assignment): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $branch = <<<'PHP'
if ($prefix) {
    echo 'prefix|';
    if ($early) { return produce('early', $failResult); }
}
echo 'rest|';
return produce('tail', $failResult);
PHP;
    $template = <<<'PHP'
<?php
final class Probe {
    public function __construct(public string $label) {}
    public function __destruct() { echo 'destroy:', $this->label, '|'; }
}
function produce(string $label, bool $fail): Probe {
    echo 'produce:', $label, '|';
    if ($fail) { throw new Error('result failed'); }
    return new Probe($label);
}
function consume(Probe $value, bool $fail): void {
    echo 'consume:', $value->label, '|';
    if ($fail) { throw new Error('consumer failed'); }
}
REFERENCE
function run(bool $enabled, bool $prefix, bool $early, bool $failResult, bool $failConsumer): void {
    BEFORE
    try { CONSUMER }
    catch (Error) { echo 'caught|'; }
    AFTER
    echo 'after|';
}
run(true, true, true, false, true);
run(true, true, true, true, false);
run(true, true, false, false, false);
run(true, false, true, false, false);
run(false, true, true, false, false);
PHP;
    $template = str_replace(['BEFORE', 'CONSUMER', 'AFTER'], [
        $assignment ? "Probe \$destination = new Probe('old');" : '',
        $assignment ? '$destination = EXPRESSION; consume($destination, $failConsumer);' : 'consume(EXPRESSION, $failConsumer);',
        $assignment ? "echo 'held:', \$destination->label, '|'; unset(\$destination);" : '',
    ], $template);
    $expression = 'when ($enabled) { ' . $branch . " } else { return produce('else', \$failResult); }";
    $source = str_replace(['REFERENCE', 'EXPRESSION'], ['', $expression], $template);
    $this->writeFile($root . '/src/main.ppphp', $source);
    $reference = str_replace(['REFERENCE', 'EXPRESSION'], [
        'function selectResult(bool $enabled, bool $prefix, bool $early, bool $failResult): Probe { if ($enabled) { '
            . $branch . " } else { return produce('else', \$failResult); } }",
        'selectResult($enabled, $prefix, $early, $failResult)',
    ], $template);
    $this->writeFile($root . '/reference.php', str_replace('Probe $destination =', '$destination =', $reference));
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json']);
    $build->mustRun();
    $native = new Process([PHP_BINARY, $root . '/reference.php']);
    $native->mustRun();
    $run = new Process([PHP_BINARY, $root . '/build/ppphp/main.php']);
    $run->mustRun();
    expect($run->getOutput())->toBe($native->getOutput())
        ->and($native->getErrorOutput())->toBe('')->and($run->getErrorOutput())->toBe('');
    if ($assignment) {
        expect($run->getOutput())->toContain('produce:early|caught|held:old|destroy:old|after|');
    } else {
        expect($run->getOutput())->toContain('consume:early|destroy:early|caught|after|');
    }
})->with(['call' => false, 'reassignment' => true]);
