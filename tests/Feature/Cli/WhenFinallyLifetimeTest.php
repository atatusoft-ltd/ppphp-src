<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

test('an unverified parameter write cannot establish primitive when result lifetime', function (string $type): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $declarations = <<<'PHP'
<?php
final class PendingParameterValue {
    public function __destruct() { echo 'destroy|'; }
}
function consume(mixed $value): void { unset($value); throw new Error(); }
PHP;
    $body = '$member = $factory(); try { return $member; } finally { $member = 0; }';
    $template = <<<'PHP'
function choose(bool $ready, PARAMETER_TYPE $member, callable $factory): void {
    try { consume(VALUE); } catch (Error $error) { echo 'caught|'; }
    echo 'after|';
}
choose(true, 0, fn (): PendingParameterValue => new PendingParameterValue());
choose(false, 0, fn (): PendingParameterValue => new PendingParameterValue());
PHP;
    $template = str_replace('PARAMETER_TYPE', $type, $template);
    $this->writeFile($root . '/src/main.ppphp', $declarations . str_replace('VALUE',
        'when ($ready) { ' . $body . ' } else { return 0; }', $template));
    $this->writeFile($root . '/reference.php', $declarations
        . 'function nativeValue(bool $ready, int $member, callable $factory): mixed {
            if ($ready) { ' . $body . ' } else { return 0; } }'
        . str_replace('VALUE', 'nativeValue($ready, $member, $factory)', $template));
    $native = new Process([PHP_BINARY, $root . '/reference.php'], timeout: 5);
    $native->mustRun();
    expect($native->getOutput())->toBe('destroy|caught|after|caught|after|');
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json']);
    $build->run();
    if ($type === 'int') {
        // Native PHP permits this write, but ++PHP storage has a fixed type.
        // Unknown must not bypass the contract and justify primitive cleanup.
        $result = json_decode($build->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        expect($build->getExitCode())->toBe(1, $build->getOutput() . $build->getErrorOutput())
            ->and(array_column($result['diagnostics'], 'code'))->toContain('P2009');
        return;
    }
    expect($build->getExitCode())->toBe(0, $build->getOutput() . $build->getErrorOutput());
    $runtime = new Process([PHP_BINARY, $root . '/build/ppphp/main.php'], timeout: 5);
    $runtime->mustRun();
    expect($runtime->getOutput())->toBe($native->getOutput())
        ->and($runtime->getErrorOutput())->toBe('');
})->with(['int', 'mixed']);

test('finally cleanup accounts for intermediate values absent from the final result type', function (string $pending, string $consumer): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $declarations = <<<'PHP'
<?php
final class TrackedPending {
    public function __destruct() { echo 'destroy|'; }
}
PHP;
    $body = 'try { return ' . $pending . '; } finally { if ($fail) { throw new Error("cleanup"); } return 5; }';
    $expression = 'when ($ready) { ' . $body . ' } else { return 0; }';
    $template = <<<'PHP'
function choose(bool $ready, bool $fail): int {
    DESTINATION
    try { CONSUMER }
    catch (Error $error) { echo 'caught|'; }
    return $destination;
}
echo choose(true, false), '|', choose(false, false), '|', choose(true, true);
PHP;
    $statement = $consumer === 'return' ? 'return ' : '$destination = ';
    $source = $declarations . str_replace(['DESTINATION', 'CONSUMER'], [
        'int $destination = 99;', $statement . $expression . ';',
    ], $template);
    $this->writeFile($root . '/src/main.ppphp', $source);
    $reference = $declarations . 'function nativeValue(bool $ready, bool $fail): mixed {
        if ($ready) { ' . $body . ' } else { return 0; } }
        ' . str_replace(['DESTINATION', 'CONSUMER'], [
            '$destination = 99;', $statement . 'nativeValue($ready, $fail);',
        ], $template);
    $this->writeFile($root . '/reference.php', $reference);
    $native = new Process([PHP_BINARY, $root . '/reference.php'], timeout: 5);
    $native->mustRun();
    expect($native->getOutput())->toBe('destroy|5|0|destroy|caught|99');
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json']);
    $build->mustRun();
    $runtime = new Process([PHP_BINARY, $root . '/build/ppphp/main.php'], timeout: 5);
    $runtime->mustRun();
    expect($runtime->getOutput())->toBe($native->getOutput())
        ->and($runtime->getErrorOutput())->toBe('')->and($native->getErrorOutput())->toBe('');
})->with([
    'object' => 'new TrackedPending()',
    'array of objects' => '[new TrackedPending()]',
    'nested array of objects' => '[[new TrackedPending()]]',
])->with(['assignment', 'return']);

test('failed protected strings and arrays are released before outer finally observers', function (
    string $type, string $value, string $size, string $consumer,
): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $small = $type === 'string' ? "'small'" : "['small']";
    $body = 'try { try { return ' . $value . '; } finally { if ($fail) { throw new Error("cleanup"); } } }
        finally { echo memory_get_usage() - $baseline > 8 * 1024 * 1024 ? "retained|" : "released|"; }';
    $header = '<?php function choose(bool $ready, bool $fail): ' . $type . ' {
        int $baseline = memory_get_usage(); ';
    $expression = 'when ($ready) { ' . $body . ' } else { return ' . $small . '; }';
    $statement = $consumer === 'return' ? 'return ' . $expression . ';'
        : $type . ' $result = ' . $expression . '; return $result;';
    $driver = 'function consume(' . $type . ' $value): void { echo ' . $size . ' > 1024 ? "large|" : "small|"; }
        consume(choose(true, false));
        try { consume(choose(true, true)); } catch (Error $error) { echo "caught|"; }
        consume(choose(false, false));';
    $this->writeFile($root . '/src/main.ppphp', $header . $statement . ' } ' . $driver);
    $reference = str_replace(['array<string>', 'int $baseline'], ['array', '$baseline'],
        $header . 'if ($ready) { ' . $body . ' } else { return ' . $small . '; } } ' . $driver);
    $this->writeFile($root . '/reference.php', $reference);
    $native = new Process([PHP_BINARY, $root . '/reference.php'], timeout: 5);
    $native->mustRun();
    expect($native->getOutput())->toBe('retained|large|released|caught|small|');
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json']);
    $build->mustRun();
    $runtime = new Process([PHP_BINARY, $root . '/build/ppphp/main.php'], timeout: 5);
    $runtime->mustRun();
    expect($runtime->getOutput())->toBe($native->getOutput())
        ->and($runtime->getErrorOutput())->toBe('')->and($native->getErrorOutput())->toBe('');
})->with([
    'string' => ['string', "str_repeat('x', 16 * 1024 * 1024)", 'strlen($value)'],
    'array of strings' => ['array<string>', "[str_repeat('x', 16 * 1024 * 1024)]", 'strlen($value[0])'],
])->with(['assignment', 'return']);

test('a nested finally result is committed inside its surrounding source catch', function (bool $releaseFails, bool $cleanupFails): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $source = <<<'PPP'
<?php
final class PendingValue {
    public function __construct(public string $name, public bool $fail = false) {}
    public function __destruct() {
        echo 'release:', $this->name, '|';
        if ($this->fail) { throw new Error($this->name); }
    }
}
function choose(bool $ready, bool $cleanupFails, bool $releaseFails): PendingValue {
    PendingValue $result = when ($ready) {
        try { return new PendingValue('outer', $releaseFails); }
        finally {
            try {
                try { return new PendingValue('inner'); }
                finally { echo 'cleanup|'; if ($cleanupFails) { throw new Error('cleanup'); } }
            } catch (Error $error) { echo 'inner-caught:', $error->getMessage(), '|'; }
            echo 'tail|';
        }
    } else { return new PendingValue('else'); };
    return $result;
}
function show(PendingValue $value): void { echo 'value:', $value->name, '|'; }
try { show(choose(true, CLEANUP_FAILS, RELEASE_FAILS)); }
catch (Error $error) { echo 'outer-caught:', $error->getMessage(), '|'; }
PPP;
    $this->writeFile($root . '/src/main.ppphp', str_replace(['RELEASE_FAILS', 'CLEANUP_FAILS'], [
        $releaseFails ? 'true' : 'false', $cleanupFails ? 'true' : 'false',
    ], $source));
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json']);
    $build->mustRun();
    $runtime = new Process([PHP_BINARY, $root . '/build/ppphp/main.php'], timeout: 5);
    $runtime->mustRun();
    // A finally result follows assignment semantics: the store succeeds before
    // the old value's destructor throws, and the source catch can recover.
    $expected = $cleanupFails
        ? 'cleanup|release:inner|inner-caught:cleanup|tail|value:outer|release:outer|'
            . ($releaseFails ? 'outer-caught:outer|' : '')
        : ($releaseFails
        ? 'cleanup|release:outer|inner-caught:outer|tail|value:inner|release:inner|'
        : 'cleanup|release:outer|value:inner|release:inner|');
    expect($runtime->getOutput())->toBe($expected)
        ->and($runtime->getErrorOutput())->toBe('');
})->with([false, true])->with([false, true]);

test('failed iterator results are released before the source catch takes control', function (
    string $iterator, bool $insideFinally, string $consumer, bool $outerLoop,
): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $declarations = <<<'PPP'
<?php
final class PendingValue {
    public function __construct(public string $name) {}
    public function __destruct() { echo 'release:', $this->name, '|'; }
}
PPP;
    $body = <<<'PPP'
try {
    foreach (items($fail) as mixed $item) { return new PendingValue('inner'); }
} catch (Error $error) { echo 'caught|'; }
echo 'tail|';
PPP;
    $body = $insideFinally ? "try { return new PendingValue('outer'); } finally { " . $body . ' }'
        : $body . "return new PendingValue('fallback');";
    if ($outerLoop) {
        $body = 'foreach ([0] as int $iteration) { ' . $body . ' }';
    }
    $header = $declarations . $iterator . '
function choose(bool $ready, bool $fail): PendingValue {
    PendingValue|Error $error = new PendingValue("old-catch"); ';
    $expression = 'when ($ready) { ' . $body . ' } else { return new PendingValue("else"); }';
    $statement = $consumer === 'return' ? 'return ' . $expression . ';'
        : 'PendingValue $result = ' . $expression . '; return $result;';
    $driver = <<<'PHP'
function show(PendingValue $value): void { echo 'value:', $value->name, '|'; }
show(choose(true, false));
show(choose(true, true));
show(choose(false, false));
PHP;
    $this->writeFile($root . '/src/main.ppphp', $header . $statement . ' } ' . $driver);
    $reference = str_replace(['array<mixed>', 'as mixed $item', 'as int $iteration', 'PendingValue|Error $error'],
        ['array', 'as $item', 'as $iteration', '$error'],
        $header . 'if ($ready) { ' . $body . ' } else { return new PendingValue("else"); } } ' . $driver);
    $this->writeFile($root . '/reference.php', $reference);
    $native = new Process([PHP_BINARY, $root . '/reference.php'], timeout: 5);
    $native->mustRun();
    expect($native->getOutput())->toContain('cleanup|release:inner|release:old-catch|caught|tail|');
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json']);
    $build->mustRun();
    $runtime = new Process([PHP_BINARY, $root . '/build/ppphp/main.php'], timeout: 5);
    $runtime->mustRun();
    expect($runtime->getOutput())->toBe($native->getOutput())
        ->and($runtime->getErrorOutput())->toBe('')->and($native->getErrorOutput())->toBe('');
})->with([
    'generator cleanup' => <<<'PPP'
/** @return Generator<int, int, mixed, void> */
function items(bool $fail): Generator {
    try { yield 0; }
    finally { echo 'cleanup|'; if ($fail) { throw new Error('cleanup'); } }
}
PPP,
    'array cleanup' => <<<'PPP'
final class IteratorCleanup {
    public function __construct(public bool $fail) {}
    public function __destruct() { echo 'cleanup|'; if ($this->fail) { throw new Error('cleanup'); } }
}
function items(bool $fail): array<mixed> { return [0, new IteratorCleanup($fail)]; }
PPP,
])->with([false, true])->with(['assignment', 'return'])->with([
    'branch scope' => false, 'nested in an outer loop' => true,
]);
