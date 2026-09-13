<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Frontend\PpphpParser;
use Atatusoft\Ppphp\Project\ProjectParseResult;
use Atatusoft\Ppphp\Semantic\SemanticAnalysisResult;
use Atatusoft\Ppphp\Semantic\SemanticAnalyzer;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;
use Atatusoft\Ppphp\Support\Path;
use Atatusoft\Ppphp\Transpilation\GeneratedPhp;
use Atatusoft\Ppphp\Transpilation\PhpLowerer;
use Symfony\Component\Process\Process;

/** @return array{Atatusoft\Ppphp\Frontend\ParseResult, SemanticAnalysisResult} */
function analyzeStageNineSource(string $contents): array
{
    $path = '/project/src/When.ppphp';
    $source = new SourceFile($path, 'src/When.ppphp', FileKind::Ppphp, $contents);
    $parse = (new PpphpParser())->parse($source);
    $key = Path::buildComparisonKey($path);
    $project = new ProjectParseResult(
        $parse->parsedFile === null ? [] : [$key => $parse->parsedFile],
        [$key => $source],
        $parse->diagnostics,
    );

    return [$parse, (new SemanticAnalyzer())->analyze($project)];
}

function lowerStageNineSource(string $contents): GeneratedPhp
{
    [$parse, $analysis] = analyzeStageNineSource($contents);
    $model = $analysis->findModel('/project/src/When.ppphp');

    expect($parse->parsedFile)->not->toBeNull()
        ->and($analysis->isSuccessful)->toBeTrue()
        ->and($model)->not->toBeNull();

    return (new PhpLowerer())->lower($parse->parsedFile, $model);
}

test('when syntax errors share actionable parser messages and preserve raw details only for debug', function (): void {
    [, $analysis] = analyzeStageNineSource(<<<'PPP'
<?php
function choose(): int {
    return when (true) {
        echo 'value'
        return 1;
    } else { return 2; };
}
PPP);
    $errors = array_values(array_filter(iterator_to_array($analysis->diagnostics),
        static fn (Diagnostic $diagnostic): bool => $diagnostic->code === DiagnosticCode::WhenBranchCouldNotBeParsed));
    expect($errors)->toHaveCount(1)
        ->and($errors[0]->message)->toBe('Expected a semicolon before `return`.')
        ->and($errors[0]->primary?->span->text)->toBe('return')
        ->and($errors[0]->debug['parserMessage'])->toBe("Syntax error, unexpected T_RETURN, expecting ';'");
});

/** @return list<string> */
function resolveStageNineCodes(SemanticAnalysisResult $analysis): array
{
    return array_map(
        static fn (Diagnostic $diagnostic): string => $diagnostic->code->value,
        iterator_to_array($analysis->diagnostics),
    );
}

test('a declare body retains when result ownership and completion', function (string $body, bool $complete): void {
    $source = '<?php function choose(bool $ready, bool $select): int {
        int $value = when ($ready) { ' . $body . ' } else { return 0; };
        echo "after|"; return $value;
    } echo choose(true, true), "|", choose(false, false);';
    [, $analysis] = analyzeStageNineSource($source);
    expect(resolveStageNineCodes($analysis))->toBe($complete ? [] : ['P5002']);
    if ($complete) {
        $generated = lowerStageNineSource($source);
        $run = new Process([PHP_BINARY, '-r', substr($generated->contents, 5)]);
        $run->mustRun();
        expect($run->getOutput())->toBe('after|5|after|0')
            ->and($generated->contents)->not->toContain('while (true)');
    }
})->with([
    'tail declaration block' => ['declare(ticks=1) { return 5; }', true],
    'declaration block with fallthrough' => ['declare(ticks=1) { if ($select) { return 5; } }', false],
    'declaration block with continuation' => ['declare(ticks=1) { if ($select) { return 5; } } return 7;', true],
]);

test('trailing branch comments do not require a control flow wrapper', function (string $body): void {
    $source = '<?php function choose(bool $ready, bool $select): int {
        int $value = when ($ready) { ' . $body . ' } else { return 0; };
        echo "after|"; return $value;
    } echo choose(true, true), "|", choose(false, false);';
    $generated = lowerStageNineSource($source);
    expect($generated->contents)->not->toContain('do {')->not->toContain('while (true)')
        ->and(substr_count($generated->contents, 'Keep this explanation.'))->toBe(1);
    $run = new Process([PHP_BINARY, '-r', substr($generated->contents, 5)]);
    $run->mustRun();
    expect($run->getOutput())->toBe('after|5|after|0');
})->with([
    'branch tail' => 'return 5; /* Keep this explanation. */',
    'conditional arm tail' => 'if ($select) { return 5; /* Keep this explanation. */ } else { return 6; }',
    'loop tail' => 'foreach ([5] as int $item) { return $item; /* Keep this explanation. */ }',
    'protected tail' => 'try { return 5; /* Keep this explanation. */ } finally { echo ""; }',
]);

test('a fully returning conditional cannot run a following when continuation', function (): void {
    $generated = lowerStageNineSource(<<<'PPP'
<?php
function choose(bool $ready, bool $select): int {
    int $value = when ($ready) {
        if ($select) { return 5; } else { return 6; }
        echo 'unreachable|';
        return 7;
    } else { return 0; };
    echo 'after|';
    return $value;
}
echo choose(true, true), '|', choose(true, false), '|', choose(false, false);
PPP);
    $run = new Process([PHP_BINARY, '-r', substr($generated->contents, 5)]);
    $run->mustRun();
    expect($run->getOutput())->toBe('after|5|after|6|after|0');
});

test('unreachable result tails need no synthetic loop', function (string $body, string $expected): void {
    $generated = lowerStageNineSource('<?php function choose(bool $ready): int {
        int $value = when ($ready) { ' . $body . ' } else { return 0; };
        echo "after|"; return $value;
    } echo choose(true), "|", choose(false);');
    expect($generated->contents)->not->toContain('do {')->not->toContain('while (true)')
        ->not->toContain('__ppphp_when_pending_error')->not->toContain('catch (\\Throwable')
        ->and(substr_count($generated->contents, 'Retain the unreachable source.'))->toBe(1);
    $run = new Process([PHP_BINARY, '-r', substr($generated->contents, 5)]);
    $run->mustRun();
    expect($run->getOutput())->toBe($expected);
})->with([
    'branch' => ['return 5; /* Retain the unreachable source. */ echo "wrong|";', 'after|5|after|0'],
    'declare body' => ['declare(ticks=1) { return 5; /* Retain the unreachable source. */ echo "wrong|"; }', 'after|5|after|0'],
    'protected body' => ['try { return 5; /* Retain the unreachable source. */ echo "wrong|"; } finally { echo "cleanup|"; }', 'cleanup|after|5|after|0'],
    'finally body' => ['try { return 7; } finally { return 5; /* Retain the unreachable source. */ echo "wrong|"; }', 'after|5|after|0'],
]);

test('when loop completion follows entry and the actual transfer target', function (string $body, bool $complete): void {
    [, $analysis] = analyzeStageNineSource('<?php
        function pick(bool $ready, bool $stop, bool $again, int $n, array<int> $values): int {
            return when ($ready) { ' . $body . ' } else { return 0; };
        }');
    expect(resolveStageNineCodes($analysis))->toBe($complete ? [] : ['P5002']);
})->with([
    'do always enters' => ['do { return 1; } while ($again);', true],
    'true while always enters' => ['while (true) { return 1; }', true],
    'truthy integer while always enters' => ['while (1) { return 1; }', true],
    'truthy signed float while always enters' => ['while (-0.5) { return 1; }', true],
    'truthy string while always enters' => ['while ("run") { return 1; }', true],
    'negated false while always enters' => ['while (!false) { return 1; }', true],
    'zero while can skip' => ['while (0) { return 1; }', false],
    'zero string while can skip' => ['while ("0") { return 1; }', false],
    'empty string while can skip' => ['while ("") { return 1; }', false],
    'negated unknown condition can skip' => ['while (!$again) { return 1; }', false],
    'for without condition always enters' => ['for (;;) { return 1; }', true],
    'last for condition determines entry' => ['for (; $again, true;) { return 1; }', true],
    'nonempty literal foreach always enters' => ['foreach ([4] as int $value) { return $value; }', true],
    'unpacked iterable plus literal item enters' => ['foreach ([...$values, 4] as mixed $value) { return 1; }', true],
    'possibly empty foreach needs fallback' => ['foreach ($values as int $value) { return $value; }', false],
    'unpacking alone does not guarantee entry' => ['foreach ([...$values] as mixed $value) { return 1; }', false],
    'unknown while can skip its body' => ['while ($again) { return 1; }', false],
    'last unknown for condition can skip body' => ['for (; true, $again;) { return 1; }', false],
    'do break reaches fallthrough' => ['do { if ($stop) { break; } return 1; } while ($again);', false],
    'do continue can reach false condition' => ['do { if ($stop) { continue; } return 1; } while ($again);', false],
    'nonempty foreach continue can exhaust input' => ['foreach ([4] as int $value) { if ($stop) { continue; } return $value; }', false],
    'while continue cannot leave true loop' => ['while (true) { if ($stop) { continue; } return 1; }', true],
    'for continue cannot leave unconditional loop' => ['for (;;) { if ($stop) { continue; } return 1; }', true],
    'do continue cannot leave true loop' => ['do { if ($stop) { continue; } return 1; } while (true);', true],
    'true loop normal iteration repeats' => ['while (true) { if ($n > 5) { return 1; } $n++; }', true],
    'true loop break still needs fallback' => ['while (true) { if ($stop) { break; } return 1; }', false],
    'switch consumes its own break' => ['do { switch ($n) { case 0: break; default: break; } return 1; } while ($again);', true],
    'inner loop consumes its own break' => ['do { while ($again) { break; } return 1; } while ($again);', true],
    'inner loop consumes its own continue' => ['do { foreach ($values as int $value) { continue; } return 1; } while ($again);', true],
    'switch break two reaches outer loop' => ['do { switch ($n) { case 0: break 2; } return 1; } while ($again);', false],
    'switch continue two reaches outer condition' => ['do { switch ($n) { case 0: continue 2; } return 1; } while ($again);', false],
    'switch continue two cannot leave true loop' => ['while (true) { switch ($n) { case 0: continue 2; } return 1; }', true],
    'nested loop break two reaches outer loop' => ['do { foreach ($values as int $value) { break 2; } return 1; } while ($again);', false],
    'unreachable break does not add fallthrough' => ['do { return 1; break; } while ($again);', true],
]);

test('when expressions produce typed values and lower without synthetic closures', function (): void {
    $generated = lowerStageNineSource(<<<'PPP'
<?php
function label(int $score): string
{
    string $label = when ($score >= 80) {
        return 'high';
    } else when ($score >= 50) {
        return 'mid';
    } else {
        return 'low';
    };

    return $label;
}
echo label(70);
PPP);
    $path = $this->createTemporaryDirectory() . '/When.php';
    $this->writeFile($path, $generated->contents);
    $lint = new Process([PHP_BINARY, '-l', $path]);
    $lint->run();
    $runtime = new Process([PHP_BINARY, $path]);
    $runtime->run();

    expect($generated->contents)
        ->toContain('if ($score >= 80)', 'elseif ($score >= 50)', '$label =')
        ->not->toContain('do {')->not->toContain('$__ppphp_when_')
        ->not->toContain('function () use')->not->toContain('@var string $label')
        ->and($lint->isSuccessful())->toBeTrue()
        ->and($runtime->getOutput())->toBe('mid');
});

test('nested when expressions preserve call and array evaluation order', function (): void {
    $generated = lowerStageNineSource(<<<'PPP'
<?php
function mark(string $value): string { echo $value; return $value; }
function joinValues(string $left, string $middle, string $right): string { return $left . $middle . $right; }
function run(bool $condition): string
{
    string $result = joinValues(
        mark('A'),
        when ($condition) {
            return when (true) { return mark('B'); } else { return mark('X'); };
        } else {
            return mark('C');
        },
        mark('D'),
    );
    array<string> $values = [mark('E'), when (true) { return mark('F'); } else { return mark('X'); }, mark('G')];
    return $result . $values[1];
}
echo ':' . run(true);
PPP);
    $path = $this->createTemporaryDirectory() . '/Order.php';
    $this->writeFile($path, $generated->contents);
    $runtime = new Process([PHP_BINARY, $path]);
    $runtime->run();

    expect($runtime->isSuccessful())->toBeTrue()
        ->and($runtime->getOutput())->toBe('ABDEFG:ABDF');
});

test('nested when expressions remain inside lazy boolean ternary and coalesce branches', function (): void {
    $generated = lowerStageNineSource(<<<'PPP'
<?php
function mark(string $value): string { echo $value; return $value; }
function accept(string $value): bool { return $value !== ''; }
function pass(string $value): string { return $value; }
function choose(bool $ready): string
{
    bool $accepted = $ready && accept(when (true) { return mark('A'); } else { return mark('X'); });
    string $ternary = $ready
        ? mark('B') . pass(when (true) { return mark('C'); } else { return mark('X'); })
        : mark('D');
    ?string $missing = null;
    string $coalesced = $missing ?? pass(when (true) { return mark('E'); } else { return mark('X'); });
    return ($accepted ? 'yes' : 'no') . ':' . $ternary . ':' . $coalesced;
}
echo '[' . choose(false) . '][' . choose(true) . ']';
PPP);
    $path = $this->createTemporaryDirectory() . '/Lazy.php';
    $this->writeFile($path, $generated->contents);
    $runtime = new Process([PHP_BINARY, $path]);
    $runtime->run();

    expect($runtime->isSuccessful())->toBeTrue()
        ->and($runtime->getOutput())->toBe('DEABCE[no:D:E][yes:BC:E]');
});

test('when preludes are emitted for if and lazy elseif conditions', function (): void {
    $generated = lowerStageNineSource(<<<'PPP'
<?php
function mark(string $value): bool { echo $value; return $value === 'B'; }
function choose(): string
{
    if (mark(when (true) { return 'A'; } else { return 'X'; })) {
        return 'first';
    } elseif (mark(when (true) { return 'B'; } else { return 'X'; })) {
        return 'second';
    }

    return 'none';
}
echo ':' . choose();
PPP);
    $path = $this->createTemporaryDirectory() . '/Conditions.php';
    $this->writeFile($path, $generated->contents);
    $runtime = new Process([PHP_BINARY, $path]);
    $runtime->run();

    expect($runtime->isSuccessful())->toBeTrue()
        ->and($runtime->getOutput())->toBe('AB:second')
        ->and($runtime->getErrorOutput())->toBe('');
});

test('when branch control flow requires a value on every reachable path', function (string $body, DiagnosticCode $code): void {
    [, $analysis] = analyzeStageNineSource(sprintf(
        '<?php function invalid(bool $value): int { return when ($value) { %s } else { return 0; }; }',
        $body,
    ));

    expect(resolveStageNineCodes($analysis))->toContain($code->value);
})->with([
    'fallthrough' => ['if (true) { return 1; }', DiagnosticCode::WhenBranchDoesNotProduceValue],
    'empty result' => ['return;', DiagnosticCode::WhenResultRequiresValue],
    'break' => ['break;', DiagnosticCode::WhenControlTransferNotAllowed],
    'continue' => ['continue;', DiagnosticCode::WhenControlTransferNotAllowed],
    'yield' => ['yield 1;', DiagnosticCode::WhenYieldNotAllowed],
    'goto' => ['goto done; done: return 1;', DiagnosticCode::WhenGotoNotAllowed],
]);

test('when result types are checked against their context', function (): void {
    [, $analysis] = analyzeStageNineSource(<<<'PPP'
<?php
function invalid(bool $value): string
{
    return when ($value) { return 'ok'; } else { return 1; };
}
PPP);

    expect(resolveStageNineCodes($analysis))->toContain(DiagnosticCode::WhenResultTypeDoesNotMatch->value);
});

test('unsupported expression sites and known by-reference arguments are rejected', function (): void {
    [, $unsupported] = analyzeStageNineSource(<<<'PPP'
<?php
function invalid(bool $value): int
{
    int $result = 1 + when ($value) { return 1; } else { return 0; };
    return $result;
}
PPP);
    [, $byReference] = analyzeStageNineSource(<<<'PPP'
<?php
function mutate(int &$value): void {}
function invalid(bool $value): void
{
    mutate(when ($value) { return 1; } else { return 0; });
}
PPP);

    expect(resolveStageNineCodes($unsupported))->toContain(DiagnosticCode::WhenPositionNotSupported->value)
        ->and(resolveStageNineCodes($byReference))->toContain(DiagnosticCode::WhenByReferenceArgumentNotAllowed->value);
});

test('A2 finally results preserve pending checked errors', function (string $body): void {
    [, $analysis] = analyzeStageNineSource('<?php
        function load(): int throws RuntimeException { throw new RuntimeException(); }
        function invalid(bool $ready): int {
            return when ($ready) { ' . $body . ' } else { return 0; };
        }');
    expect(resolveStageNineCodes($analysis))->toContain('P4003');
})->with([
    'explicit throw' => 'try { throw new RuntimeException(); } finally { return 9; }',
    'throwing result operand' => 'try { return load(); } finally { return 9; }',
    'nested finally result' => 'try { throw new RuntimeException(); } finally { try { return 9; } finally { echo "cleanup|"; } }',
    'declaration block in finally' => 'try { throw new RuntimeException(); } finally { declare(ticks=1) { return 9; } }',
]);

test('A2 finally result types require a successful protected path', function (string $body, string $type): void {
    [, $analysis] = analyzeStageNineSource('<?php
        function choose(bool $ready, bool $select): int throws RuntimeException {
            return when ($ready) { ' . $body . ' } else { return 0; };
        }');
    expect($analysis->isSuccessful)->toBeTrue()
        ->and($analysis->findModel('/project/src/When.ppphp')->whenExpressions->expressions[0]->branches[0]->resultType->canonical)
        ->toBe($type);
})->with([
    'unconditional finally result after throw' => ['try { throw new RuntimeException(); } finally { return 9; }', 'never'],
    'declaration result after throw' => ['try { throw new RuntimeException(); } finally { declare(ticks=1) { return 9; } }', 'never'],
    'conditional finally result after throw' => ['try { throw new RuntimeException(); } finally { if ($select) { return 9; } }', 'never'],
    'overridden successful result' => ['try { return "old"; } finally { return 9; }', 'int'],
    'result supplied on fallthrough' => ['try { echo "body|"; } finally { return 9; }', 'int'],
    'catch supplies successful path' => ['try { throw new RuntimeException(); } catch (RuntimeException $error) { return 1; } finally { return 9; }', 'int'],
]);

test('A2 leaves native callable finally returns and replacement throws intact', function (): void {
    [, $analysis] = analyzeStageNineSource(<<<'PPP'
<?php
function replaced(bool $ready): int throws LogicException {
    return when ($ready) {
        try { throw new RuntimeException(); } finally { throw new LogicException(); }
    } else { return 0; };
}
function native(bool $ready): int {
    return when ($ready) {
        Closure $callback = function (): int {
            try { throw new RuntimeException(); } finally { return 9; }
        };
        return 1;
    } else { return 0; };
}
PPP);
    expect(resolveStageNineCodes($analysis))->toBe([]);
});

test('when result types exclude only values cancelled by correlated finally paths', function (string $body, string $type): void {
    [, $analysis] = analyzeStageNineSource('<?php
        function resetFlag(bool &$flag): string { $flag = false; return "kept"; }
        function choose(bool $ready, bool $select, bool $other): int|string {
            return when ($ready) { ' . $body . ' return 7; } else { return 0; };
        }');
    expect(resolveStageNineCodes($analysis))->toBe([])
        ->and($analysis->findModel('/project/src/When.ppphp')->whenExpressions->expressions[0]->resultType->canonical)
        ->toBe($type);
})->with([
    'same true condition' => ['try { if ($select) { return "cancelled"; } } finally { if ($select) { throw new Error(); } }', 'int'],
    'same false condition' => ['try { if (!$select) { return "cancelled"; } } finally { if (!$select) { throw new Error(); } }', 'int'],
    'equivalent boolean condition' => ['try { if ($select === true) { return "cancelled"; } } finally { if ($select) { throw new Error(); } }', 'int'],
    'else result condition' => ['try { if ($select) { echo "body|"; } else { return "cancelled"; } } finally { if (!$select) { throw new Error(); } }', 'int'],
    'condition written before result' => ['try { if ($select) { $select = false; return "kept"; } } finally { if ($select) { throw new Error(); } }', 'int|string'],
    'result operand changes condition by reference' => ['try { if ($select) { return resetFlag($select); } } finally { if ($select) { throw new Error(); } }', 'int|string'],
    'finally writes condition before testing it' => ['try { if ($select) { return "kept"; } } finally { $select = false; if ($select) { throw new Error(); } }', 'int|string'],
    'independent cleanup condition' => ['try { if ($select) { return "kept"; } } finally { if ($other) { throw new Error(); } }', 'int|string'],
    'conjunction implies cleanup condition' => ['try { if ($select && $other) { return "cancelled"; } } finally { if ($select) { throw new Error(); } }', 'int'],
    'continuing disjunction requires both false' => ['try { if ($select) { return "cancelled"; } } finally { if ($select || $other) { throw new Error(); } }', 'int'],
    'cleanup test precedes a write' => ['try { if ($select) { return "cancelled"; } } finally { if ($select) { throw new Error(); } $select = true; }', 'int'],
    'finally value cannot recover a correlated throw' => ['try { if ($select) { throw new Error(); } return 1; } finally { if ($select) { return "cancelled"; } }', 'int'],
    'finally replaces a correlated result' => ['try { if ($select) { return "cancelled"; } } finally { if ($select) { return 1; } }', 'int'],
    'compound condition call can change an earlier operand' => ['try { if ($select && resetFlag($select) !== "") { return "kept"; } } finally { if ($select) { throw new Error(); } }', 'int|string'],
    'nested finally condition follows an outer write' => ['try { if ($select) { throw new Error(); } return 1; } finally { $select = true; try {} finally { if ($select) { return "kept"; } } }', 'int|string'],
    'nested finally result follows a protected write' => ['try { if ($select) { throw new Error(); } return 1; } finally { try { $select = true; } finally { if ($select) { return "kept"; } } }', 'int|string'],
    'nested protected result keeps its outer entry requirement' => ['try { if ($select) { return "cancelled"; } } finally { if ($select) { try {} finally { return 1; } } }', 'int'],
    'unrelated nested cleanup retains stable conditions' => ['try { if ($select) { return "cancelled"; } } finally { try {} finally {} if ($select) { throw new Error(); } }', 'int'],
]);

test('replacing a pending result can change a condition through its destructor', function (): void {
    [$parse, $analysis] = analyzeStageNineSource(<<<'PPP'
<?php
final class ResetOnRelease {
    public function __construct(private bool &$flag) {}
    public function __destruct() { $this->flag = false; }
}
function choose(bool $ready, bool $select): int|string|ResetOnRelease {
    return when ($ready) {
        try {
            try { return new ResetOnRelease($select); }
            finally { if ($select) { return "kept"; } }
        } finally { if ($select) { throw new Error(); } }
    } else { return 0; };
}
PPP);
    expect(resolveStageNineCodes($analysis))->toBe([])
        ->and($analysis->findModel('/project/src/When.ppphp')->whenExpressions->expressions[0]->resultType->includes('string'))
        ->toBeTrue();
    $generated = (new PhpLowerer())->lower($parse->parsedFile, $analysis->findModel('/project/src/When.ppphp'));
    $path = $this->createTemporaryDirectory() . '/ReleaseCondition.php';
    $this->writeFile($path, $generated->contents . <<<'PHP'

foreach ([[true, true], [true, false], [false, true]] as $arguments) {
    $value = choose(...$arguments);
    echo is_object($value) ? $value::class : $value, '|';
}
PHP);
    $runtime = new Process([PHP_BINARY, $path], timeout: 5);
    $runtime->mustRun();
    expect($runtime->getOutput())->toBe('kept|ResetOnRelease|0|')->and($runtime->getErrorOutput())->toBe('');
});

test('a transfer cancelled before its result capable cleanup needs no deferred state', function (): void {
    $generated = lowerStageNineSource(<<<'PPP'
<?php
function choose(bool $ready, bool $select): int {
    return when ($ready) {
        foreach ([1, 2] as int $value) {
            try {
                try {
                    try { continue; } finally { throw new Error(); }
                } catch (Error $error) { echo 'caught|'; }
                echo 'between|';
            } finally { if ($select) { return 9; } echo 'cleanup|'; }
            echo 'tail|';
        }
        return -1;
    } else { return 0; };
}
echo choose(true, false), '|', choose(true, true), '|', choose(false, true);
PPP);
    expect($generated->contents)->not->toContain('__ppphp_when_transfer');
    $path = $this->createTemporaryDirectory() . '/CancelledTransfer.php';
    $this->writeFile($path, $generated->contents);
    $runtime = new Process([PHP_BINARY, $path], timeout: 5);
    $runtime->mustRun();
    expect($runtime->getOutput())->toBe('caught|between|cleanup|tail|caught|between|cleanup|tail|-1|caught|between|9|0')
        ->and($runtime->getErrorOutput())->toBe('');
});

test('a single terminal transfer needs no transfer discriminator', function (string $transfer, string $expected, string $type): void {
    $generated = lowerStageNineSource('<?php
        function choose(bool $ready, bool $select): ' . $type . ' {
            return when ($ready) {
                foreach ([1, 2] as int $value) {
                    try {
                        // Keep this transfer explanation.
                        ' . $transfer . '
                    } finally {
                        if ($select) { return 9; }
                        echo "cleanup|";
                    }
                }
                return -1;
            } else { return 0; };
        }
        echo choose(true, false), "|", choose(true, true), "|", choose(false, true);');
    expect($generated->contents)->not->toContain('__ppphp_when_transfer')
        ->and(substr_count($generated->contents, '// Keep this transfer explanation.'))->toBe(1);
    $path = $this->createTemporaryDirectory() . '/SingleTransfer.php';
    $this->writeFile($path, $generated->contents);
    $runtime = new Process([PHP_BINARY, $path], timeout: 5);
    $runtime->mustRun();
    expect($runtime->getOutput())->toBe($expected)->and($runtime->getErrorOutput())->toBe('');
})->with([
    'continue' => ['continue;', 'cleanup|cleanup|-1|9|0', 'int'],
    'break' => ['break;', 'cleanup|-1|9|0', 'int'],
    'nested nullable result scope' => [
        'try { continue; } finally { if (!$select) { return null; } echo "inner|"; }',
        'cleanup||inner|9|0', 'int|null',
    ],
    'nested string result scope' => [
        'try { continue; } finally { if (!$select) { return "inner-result"; } echo "inner|"; }',
        'cleanup|inner-result|inner|9|0', 'int|string',
    ],
]);

test('when local lowering preserves authored declaration comments', function (string $declaration, string $comment): void {
    $generated = lowerStageNineSource('<?php
        function choose(bool $ready): int {
            return when ($ready) { ' . $declaration . ' return $local; } else { return 0; };
        }
        echo choose(true), "|", choose(false);');
    expect(substr_count($generated->contents, $comment))->toBe(1)
        ->and($generated->contents)->toContain('@var int $local');
    $path = $this->createTemporaryDirectory() . '/DeclarationComments.php';
    $this->writeFile($path, $generated->contents);
    $runtime = new Process([PHP_BINARY, $path], timeout: 5);
    $runtime->mustRun();
    expect($runtime->getOutput())->toBe('7|0')->and($runtime->getErrorOutput())->toBe('');
})->with([
    'leading PHPDoc' => ['/** Keep this local explanation. */ int $local = 7;', 'Keep this local explanation.'],
    'inside typed prefix' => ['int /* Keep this inline explanation. */ $local = 7;', 'Keep this inline explanation.'],
    'inside readonly prefix' => ['readonly /* Keep this readonly explanation. */ int $local = 7;', 'Keep this readonly explanation.'],
    'line comment inside typed prefix' => ["int // Keep this line explanation.\r\n" . '$local = 7;', 'Keep this line explanation.'],
    'for binding prefix' => ['int $local = 7; for (int /* Keep this for explanation. */ $n = 0; $n < 1; ++$n) {}', 'Keep this for explanation.'],
    'foreach binding prefix' => ['int $local = 7; foreach ([1] as int /* Keep this foreach explanation. */ $n) {}', 'Keep this foreach explanation.'],
]);

test('finally results override earlier branch results', function (): void {
    $generated = lowerStageNineSource(<<<'PPP'
<?php
function run(): string
{
    return when (true) {
        try { return 'try'; } finally { return 'finally'; }
    } else {
        return 'else';
    };
}
echo run();
PPP);
    $path = $this->createTemporaryDirectory() . '/Finally.php';
    $this->writeFile($path, $generated->contents);
    $runtime = new Process([PHP_BINARY, $path]);
    $runtime->run();

    expect($runtime->isSuccessful())->toBeTrue()
        ->and($runtime->getOutput())->toBe('finally');
});

test('nested finally completion reaches the owning when without losing overrides', function (string $body, string $output): void {
    $generated = lowerStageNineSource('<?php function result(): string throws RuntimeException {
        return when (true) { ' . $body . ' } else { return "else"; };
    } try { echo result(); } catch (RuntimeException $error) { echo "exception:" . $error->getMessage(); }');
    $path = $this->createTemporaryDirectory() . '/NestedFinally.php';
    $this->writeFile($path, $generated->contents);
    $runtime = new Process([PHP_BINARY, $path]);
    $runtime->run();
    expect($runtime->getExitCode())->toBe(0)
        ->and($runtime->getOutput())->toBe($output)
        ->and($runtime->getErrorOutput())->toBe('');
})->with([
    ['try { try { return "value"; } finally { echo "inner|"; } } finally { echo "outer|"; }', 'inner|outer|value'],
    ['try { return "original"; } finally { try { return "replacement"; } finally { echo "inner|"; } }', 'inner|replacement'],
    ['try { throw new RuntimeException("pending"); } finally { try { return "recovered"; } finally { echo "inner|"; } }', 'inner|exception:pending'],
]);

test('finally results preserve pending exceptions while finally throws supersede pending results', function (): void {
    $generated = lowerStageNineSource(<<<'PPP'
<?php
function recovered(): string throws RuntimeException
{
    return when (true) {
        try { throw new RuntimeException('pending'); } finally { return 'recovered'; }
    } else { return 'else'; };
}
function replaced(): string throws RuntimeException
{
    return when (true) {
        try { return 'pending'; } finally { throw new RuntimeException('final'); }
    } else { return 'else'; };
}
try { recovered(); } catch (RuntimeException $error) { echo $error->getMessage(); }
try { replaced(); } catch (RuntimeException $error) { echo ':' . $error->getMessage(); }
PPP);
    $path = $this->createTemporaryDirectory() . '/FinallyExceptions.php';
    $this->writeFile($path, $generated->contents);
    $runtime = new Process([PHP_BINARY, $path]);
    $runtime->run();

    expect($runtime->isSuccessful())->toBeTrue()
        ->and($runtime->getOutput())->toBe('pending:final');
});

test('all required value positions lower and compiler temporaries are collision safe and cleaned up', function (): void {
    $generated = lowerStageNineSource(<<<'PPP'
<?php
final class Sink
{
    public string $value = '';
    public function consume(string $value): string { return $value; }
    public static function consumeStatic(string $value): string { return $value; }
}
final class Box
{
    public function __construct(public string $value) {}
}
function consumeNamed(string $label): string { return $label; }
function pair(string $left, string $right): string { return $left . $right; }
function run(Sink $sink): string
{
    string $__ppphp_when_prerequisite_0 = 'user';
    string $local = '';
    $local = when (true) { return 'assignment'; } else { return 'x'; };
    $sink->value = when (true) { return 'property'; } else { return 'x'; };
    array<string> $values = [''];
    $values[0] = when (true) { return 'offset'; } else { return 'x'; };
    string $method = $sink->consume(when (true) { return 'method'; } else { return 'x'; });
    string $static = Sink::consumeStatic(when (true) { return 'static'; } else { return 'x'; });
    string $named = consumeNamed(label: when (true) { return 'named'; } else { return 'x'; });
    Box $box = new Box(when (true) { return 'constructor'; } else { return 'x'; });
    string $ordered = pair(trim($__ppphp_when_prerequisite_0), when (true) { string $suffix = '!'; return $suffix; } else { return '?'; });

    return implode(':', [$__ppphp_when_prerequisite_0, $local, $sink->value, $values[0], $method, $static, $named, $box->value, $ordered]);
}
echo run(new Sink());
PPP);
    $path = $this->createTemporaryDirectory() . '/Positions.php';
    $this->writeFile($path, $generated->contents);
    $runtime = new Process([PHP_BINARY, $path]);
    $runtime->run();

    expect($runtime->isSuccessful())->toBeTrue()
        ->and($runtime->getOutput())->toBe('user:assignment:property:offset:method:static:named:constructor:user!')
        ->and($generated->contents)->toContain('unset($__ppphp_when_')
        ->toContain('$__ppphp_when_prerequisite_1')
        ->not->toContain('when (');
});

test('generic composite and typed-array branch results retain structured types', function (): void {
    [, $analysis] = analyzeStageNineSource(<<<'PPP'
<?php
final class Box<T>
{
    public function __construct(public mixed $value) {}
}
function values(bool $condition): int|string
{
    int|string $value = when ($condition) { return 1; } else { return 'one'; };
    array<string> $list = when ($condition) { return ['one']; } else { return ['two']; };
    array<string, int> $map = when ($condition) { return ['one' => 1]; } else { return ['two' => 2]; };
    Box<string> $box = when ($condition) { return new Box('one'); } else { return new Box('two'); };

    return $value;
}
PPP);

    expect($analysis->isSuccessful)->toBeTrue()
        ->and(resolveStageNineCodes($analysis))->not->toContain(DiagnosticCode::WhenResultTypeDoesNotMatch->value);
});

test('checked errors from when conditions branches nested expressions and finally reach the enclosing flow', function (string $when): void {
    [, $analysis] = analyzeStageNineSource(<<<PPP
<?php
final class StorageFailure extends RuntimeException {}
function load(): string throws StorageFailure { throw new StorageFailure(); }
function invalid(bool \$condition): string
{
    return {$when};
}
PPP);

    expect(resolveStageNineCodes($analysis))->toContain(DiagnosticCode::CheckedErrorNotHandled->value);
})->with([
    'condition' => ["when (load()) { return 'yes'; } else { return 'no'; }"],
    'branch result' => ["when (\$condition) { return load(); } else { return 'no'; }"],
    'branch statement' => ["when (\$condition) { load(); return 'yes'; } else { return 'no'; }"],
    'nested when' => ["when (\$condition) { return when (true) { return load(); } else { return 'x'; }; } else { return 'no'; }"],
    'finally' => ["when (\$condition) { try { return 'yes'; } finally { load(); } } else { return 'no'; }"],
]);

test('caught checked errors inside a branch do not escape', function (): void {
    [, $analysis] = analyzeStageNineSource(<<<'PPP'
<?php
final class StorageFailure extends RuntimeException {}
function load(): string throws StorageFailure { throw new StorageFailure(); }
function valid(bool $condition): string
{
    return when ($condition) {
        try { return load(); } catch (StorageFailure $error) { return 'caught'; }
    } else { return 'no'; };
}
PPP);

    expect(resolveStageNineCodes($analysis))->not->toContain(DiagnosticCode::CheckedErrorNotHandled->value);
});

test('branch scopes isolate locals permit sibling reuse and preserve outer mutability', function (): void {
    [, $valid] = analyzeStageNineSource(<<<'PPP'
<?php
function valid(bool $condition): string
{
    int $count = 0;
    string $value = when ($condition) {
        $count = 1;
        string $prefix = 'A';
        callable $read = function () use ($prefix): string { return $prefix; };
        return $read();
    } else {
        $count = 2;
        string $prefix = 'B';
        return $prefix;
    };
    return $value;
}
PPP);
    [, $sibling] = analyzeStageNineSource(<<<'PPP'
<?php
function invalid(bool $condition): string
{
    return when ($condition) { string $only = 'x'; return $only; } else { return $only; };
}
PPP);
    [, $shadow] = analyzeStageNineSource(<<<'PPP'
<?php
function invalid(bool $condition): string
{
    string $name = 'outer';
    return when ($condition) { string $name = 'inner'; return $name; } else { return $name; };
}
PPP);
    [, $readonly] = analyzeStageNineSource(<<<'PPP'
<?php
function invalid(bool $condition): string
{
    readonly int $count = 0;
    return when ($condition) { $count = 1; return 'x'; } else { return 'y'; };
}
PPP);

    expect($valid->isSuccessful)->toBeTrue()
        ->and(resolveStageNineCodes($sibling))->toContain(DiagnosticCode::LocalVariableNotDeclared->value)
        ->and(resolveStageNineCodes($shadow))->toContain(DiagnosticCode::DuplicateLocalDeclaration->value)
        ->and(resolveStageNineCodes($readonly))->toContain(DiagnosticCode::ReadonlyLocalCannotBeReassigned->value);
});

test('known instance static and constructor by-reference parameters reject when results', function (string $source): void {
    [, $analysis] = analyzeStageNineSource($source);

    expect(resolveStageNineCodes($analysis))->toContain(DiagnosticCode::WhenByReferenceArgumentNotAllowed->value);
})->with([
    'instance method' => [<<<'PPP'
<?php
final class Sink { public function take(string &$value): void {} }
function invalid(Sink $sink): void { $sink->take(when (true) { return 'x'; } else { return 'y'; }); }
PPP],
    'static method' => [<<<'PPP'
<?php
final class Sink { public static function take(string &$value): void {} }
function invalid(): void { Sink::take(when (true) { return 'x'; } else { return 'y'; }); }
PPP],
    'constructor' => [<<<'PPP'
<?php
final class Sink { public function __construct(string &$value) {} }
function invalid(): void { new Sink(when (true) { return 'x'; } else { return 'y'; }); }
PPP],
]);

test('invalid branch fragments receive P5010 against original source', function (): void {
    [, $analysis] = analyzeStageNineSource(<<<'PPP'
<?php
function invalid(): int
{
    return when (true) { return 1 + ; } else { return 0; };
}
PPP);
    $diagnostic = array_values(array_filter(
        iterator_to_array($analysis->diagnostics),
        static fn (Diagnostic $diagnostic): bool => $diagnostic->code === DiagnosticCode::WhenBranchCouldNotBeParsed,
    ))[0] ?? null;

    expect($diagnostic)->not->toBeNull()
        ->and($diagnostic?->primary->span->sourceFile->displayPath)->toBe('src/When.ppphp');
});

test('unsupported expression positions receive P5005', function (string $body): void {
    [, $analysis] = analyzeStageNineSource("<?php\n" . $body);

    expect(resolveStageNineCodes($analysis))->toContain(DiagnosticCode::WhenPositionNotSupported->value);
})->with([
    'standalone statement' => ["function f(): void { when (true) { return 1; } else { return 0; }; }"],
    'property default' => ["final class C { public int \$value = when (true) { return 1; } else { return 0; }; }"],
    'class constant' => ["final class C { public const int VALUE = when (true) { return 1; } else { return 0; }; }"],
    'global constant' => ["const VALUE = when (true) { return 1; } else { return 0; };"],
    'parameter default' => ["function f(int \$value = when (true) { return 1; } else { return 0; }): void {}"],
    'attribute argument' => ["#[Attribute] final class Marker {} #[Marker(when (true) { return 1; } else { return 0; })] final class C {}"],
    'match arm' => ["function f(int \$v): int { return match (\$v) { 1 => when (true) { return 1; } else { return 0; }, default => 0 }; }"],
    'arrow body' => ["function f(): callable { return fn (): int => when (true) { return 1; } else { return 0; }; }"],
    'array key' => ["function f(): array { return [when (true) { return 1; } else { return 0; } => 'x']; }"],
    'array unpack' => ["function f(): array { return [...when (true) { return []; } else { return []; }]; }"],
    'call unpack' => ["function take(mixed ...\$v): void {} function f(): void { take(...when (true) { return []; } else { return []; }); }"],
    'binary operand' => ["function f(): int { return 1 + when (true) { return 1; } else { return 0; }; }"],
    'unary operand' => ["function f(): int { return -when (true) { return 1; } else { return 0; }; }"],
    'ternary arm' => ["function f(bool \$v): int { return \$v ? when (true) { return 1; } else { return 0; } : 0; }"],
    'coalesce operand' => ["function f(): int { return when (true) { return 1; } else { return 0; } ?? 0; }"],
    'when condition' => ["function f(): int { return when (when (true) { return true; } else { return false; }) { return 1; } else { return 0; }; }"],
]);

test('nested branch extensions keep local loop generic and checked-error metadata', function (): void {
    $generated = lowerStageNineSource(<<<'PPP'
<?php
final class StorageFailure extends RuntimeException {}
final class Box<T>
{
    public function __construct(public mixed $value) {}
}
function run(bool $condition): string
{
    return when ($condition) {
        array<string> $parts = ['value'];
        foreach ($parts as string $part) { $parts[0] = $part; }
        function nestedLoad<T>(T $value): T throws StorageFailure { return $value; }
        return $parts[0];
    } else {
        return 'fallback';
    };
}
echo run(true);
PPP);
    $path = $this->createTemporaryDirectory() . '/NestedExtensions.php';
    $this->writeFile($path, $generated->contents);
    $lint = new Process([PHP_BINARY, '-l', $path]);
    $lint->run();
    $runtime = new Process([PHP_BINARY, $path]);
    $runtime->run();

    expect($generated->contents)
        ->toContain('@var list<string> $parts')
        ->toContain('@var string $part')
        ->toContain('@template T')
        ->toContain('@param T $value')
        ->toContain('@return T')
        ->toContain('@throws \\StorageFailure')
        ->not->toContain('throws StorageFailure')
        ->not->toContain('function nestedLoad<T>')
        ->and($lint->isSuccessful())->toBeTrue()
        ->and($runtime->isSuccessful())->toBeTrue()
        ->and($runtime->getOutput())->toBe('value');
});

test('generated condition result and temporary spans map to the original when source', function (bool $bare): void {
    $source = str_replace('EXTRA', $bare ? '' : 'else when ($score >= 50) { return "Pass"; }', <<<'PPP'
<?php
function normalizeLabel(string $value): string { return $value; }
function label(int $score): string
{
    return normalizeLabel(when ($score >= 80) {
        return 'Excellent';
    } EXTRA else {
        return 'Fail';
    });
}
PPP);
    $generated = lowerStageNineSource($source);
    $condition = strpos($generated->contents, '$score >= 80');
    $result = strpos($generated->contents, "'Excellent'");
    preg_match_all('/\$__ppphp_when_[A-Za-z0-9_]+/', $generated->contents, $matches, PREG_OFFSET_CAPTURE);
    $temporary = $matches[0][0][1] ?? null;

    expect($condition)->toBeInt()
        ->and($result)->toBeInt()
        ->and($generated->sourceMap->resolveOriginalOffset($condition))->toBe(strpos($source, '$score >= 80'))
        ->and($generated->sourceMap->resolveOriginalOffset($result))->toBe(strpos($source, "'Excellent'"));
    $fallback = strpos($generated->contents, "'Fail'");
    expect($fallback)->toBeInt()
        ->and($generated->sourceMap->resolveOriginalOffset($fallback))->toBe(strpos($source, "'Fail'"));
    if ($bare) {
        expect($temporary)->toBeNull()->and($generated->contents)->toContain(' ? ');
    } else {
        expect($temporary)->toBeInt()
            ->and($generated->sourceMap->resolveOriginalOffset($temporary))->toBe(strpos($source, 'when'));
    }
})->with(['native ternary' => true, 'statement-level result' => false]);

test('nested ternary parentheses preserve exact condition and result origins', function (): void {
    $source = <<<'PPP'
<?php
function label(bool $ready, bool $member): string {
    // Keep this comment outside the replaced statement.
    return when ($ready) {
        return when ($member) { return 'member'; } else { return 'guest'; };
    } else { return 'waiting'; };
}
PPP;
    $generated = lowerStageNineSource($source);
    expect($generated->contents)->toContain("return \$ready ? (\$member ? 'member' : 'guest') : 'waiting';")
        ->and(substr_count($generated->contents, '// Keep this comment'))->toBe(1);
    foreach (['$ready ?', '$member ?', "'member'", "'guest'", "'waiting'", '// Keep this comment'] as $text) {
        $offset = strpos($generated->contents, $text);
        $original = str_ends_with($text, ' ?') ? strpos($source, '(' . substr($text, 0, -2) . ')') + 1 : strpos($source, $text);
        expect($offset)->toBeInt()
            ->and($generated->sourceMap->resolveOriginalOffset($offset))->toBe($original, $text);
    }
});

test('a shared guard continuation is emitted once and maps to its original result', function (bool $assignment): void {
    $source = <<<'PPP'
<?php
function label(bool $ready, bool $member, bool $positive): string {
    return when ($ready) {
        if ($member) {
            if ($positive) { return 'early'; }
            echo 'member|';
        }
        return 'remaining';
    } else { return 'waiting'; };
}
PPP;
    if ($assignment) {
        $source = str_replace('return when', 'string $result = when', $source);
        $source = str_replace("};\n}", "};\nreturn \$result;\n}", $source);
    }
    $generated = lowerStageNineSource($source);
    $offset = 0;
    $copies = 0;
    while (($offset = strpos($generated->contents, "'remaining'", $offset)) !== false) {
        expect($generated->sourceMap->resolveOriginalOffset($offset))->toBe(strpos($source, "'remaining'"));
        $copies++;
        $offset++;
    }
    expect($copies)->toBe(1);
})->with([false, true]);

test('preassigned and early results with identical spelling retain distinct source mappings', function (): void {
    $source = <<<'PPP'
<?php
function choose(bool $ready, bool $take, bool $positive, int $fallback): int {
    int $result = when ($ready) {
        if ($take) { if ($positive) { return $fallback; } }
        return $fallback;
    } else { return 0; };
    return $result;
}
PPP;
    $generated = lowerStageNineSource($source);
    expect(substr_count($generated->contents, '$result = $fallback;'))->toBe(2)
        ->and(strpos($generated->contents, '$result = $fallback;'))->toBeLessThan(strpos($generated->contents, 'if ($take)'));
    $early = strpos($source, 'return $fallback') + strlen('return ');
    $fallback = strpos($source, 'return $fallback', $early) + strlen('return ');
    $first = strpos($generated->contents, '$result = $fallback;') + strlen('$result = ');
    $second = strpos($generated->contents, '$result = $fallback;', $first) + strlen('$result = ');
    expect($generated->sourceMap->resolveOriginalOffset($first))->toBe($fallback)
        ->and($generated->sourceMap->resolveOriginalOffset($second))->toBe($early);
});

test('statement-level results retain precise nested when condition and result mappings', function (): void {
    $source = <<<'PPP'
<?php
function label(bool $ready, bool $member): string {
    string $result = when ($ready) {
        echo 'branch|';
        return when ($member) { return 'member'; } else { return 'guest'; };
    } else { return 'waiting'; };
    return $result;
}
PPP;
    $generated = lowerStageNineSource($source);
    foreach (['$member ?', "'member'", "'guest'", "'waiting'"] as $text) {
        $offset = strpos($generated->contents, $text);
        $original = $text === '$member ?' ? strpos($source, '($member)') + 1 : strpos($source, $text);
        expect($offset)->toBeInt()
            ->and($generated->sourceMap->resolveOriginalOffset($offset))->toBe($original, $text);
    }
});
