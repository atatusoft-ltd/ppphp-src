<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Support\GoldenFile;

test('native finally output matches its full-build golden and runtime contract', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $fixtures = dirname(__DIR__, 2) . '/Fixtures/WhenDecisions/Finally';
    $this->writeFile($root . '/src/main.ppphp', file_get_contents($fixtures . '/Native.ppphp'));
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json']);
    $build->mustRun();
    $path = $root . '/build/ppphp/main.php';
    $php = file_get_contents($path);
    GoldenFile::assertMatches($fixtures . '/Native.php', $php);
    expect($php)->not->toContain('do {')->not->toContain('while (true)')->not->toContain('while (false)')
        ->not->toContain('__ppphp_when_finally')->not->toContain('__ppphp_when_pending_error')
        ->not->toContain('catch (\\Throwable');
    expect($php)->toContain('Choose a value without recovering a pending exception.');
    $runtime = new Process([PHP_BINARY, $path], timeout: 5);
    $runtime->mustRun();
    expect($runtime->getOutput())->toBe('cleanup|5|cleanup|first|cleanup|caught:pending|final|else|tail|7|head|tail|7|head|null|inner|outer|5|inner|outer|-1|compute|5|compute|caught:cleanup|99|0')
        ->and($runtime->getErrorOutput())->toBe('');
});

test('finally results build with native exception precedence and delayed destination writes', function (
    string $body, string $type, string $initial, string $other, string $expected, string $consumer,
): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $expression = 'when ($ready) { ' . $body . ' } else { return ' . $other . '; }';
    $statement = ($consumer === 'return' ? 'return ' : '$destination = ') . $expression . ';';
    $catches = str_contains($expected, 'caught:');
    $caughtType = str_contains($body, 'new Error(') ? 'Error' : 'RuntimeException';
    $declaration = $type . ' $destination = ' . $initial . ';';
    // Only throwing cases have an outer handler; an invented dead catch
    // would fail the same analysis gate in an ordinary PHP reference.
    $functionBody = $catches
        ? $declaration . ' try { ' . $statement . ' }
            catch (' . $caughtType . ' $error) { echo "caught:" . $error->getMessage() . "|"; } return $destination;'
        : ($consumer === 'return' ? $statement : $declaration . $statement . ' return $destination;');
    $this->writeFile($root . '/src/main.ppphp', '<?php
        function choose(bool $ready, bool $fail, bool $outer = true): ' . $type . ' {
            ' . $functionBody . '
        }
        echo choose(true, false), "|", choose(false, false), "|", choose(true, true);');
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json']);
    $build->run();
    expect($build->getExitCode())->toBe(0, $build->getOutput() . $build->getErrorOutput());
    $path = $root . '/build/ppphp/main.php';
    $php = file_get_contents($path);
    expect($php)->not->toContain('do {')->not->toContain('while (true)')->not->toContain('while (false)')
        ->not->toContain('__ppphp_when_finally')->not->toContain('__ppphp_when_pending_error')
        ->not->toContain('catch (\\Throwable');
    if (str_contains($body, 'Keep this explanation.')) {
        expect(substr_count($php, 'Keep this explanation.'))->toBe(1);
    }
    $runtime = new Process([PHP_BINARY, $path], timeout: 5);
    $runtime->mustRun();
    expect($runtime->getOutput())->toBe($expected)->and($runtime->getErrorOutput())->toBe('');
})->with([
    'cleanup without result' => ['try { return 5; } finally { echo "cleanup|"; }', 'int', '99', '0', 'cleanup|5|0|cleanup|5'],
    'finally cannot recover an exception' => ['try { throw new RuntimeException("pending"); } finally { return 9; }', 'int', '99', '0', 'caught:pending|99|0|caught:pending|99'],
    'finally cannot recover an error' => ['try { throw new Error("pending"); } finally { return 9; }', 'int', '99', '0', 'caught:pending|99|0|caught:pending|99'],
    'finally replaces a successful result' => ['try { return "try"; } finally { return "finally"; }', 'string', '"old"', '"else"', 'finally|else|finally'],
    'nested cleanup' => ['try { try { return "value"; } finally { echo "inner|"; } } finally { echo "outer|"; }', 'string', '"old"', '"else"', 'inner|outer|value|else|inner|outer|value'],
    'catch and cleanup' => ['try { if ($fail) { throw new RuntimeException(); } return 5; } catch (RuntimeException $error) { return -1; } finally { echo "cleanup|"; }', 'int', '99', '0', 'cleanup|5|0|cleanup|-1'],
    'cleanup failure does not commit' => ['try { return 5; } finally { if ($fail) { throw new RuntimeException("cleanup"); } echo "cleanup|"; }', 'int', '99', '0', 'cleanup|5|0|caught:cleanup|99'],
    'a guard stays inside try' => ['try { if ($fail) { return 1; } echo "body|"; return 5; } finally { echo "cleanup|"; }', 'int', '99', '0', 'body|cleanup|5|0|cleanup|1'],
    'finally partial guard has independent completion' => ['try { return 7; } finally { if ($outer) { echo "head|"; if ($fail) { return 9; } } echo "tail|"; }', 'int', '99', '0', 'head|tail|7|0|head|9'],
    'partial try gates only its own remainder' => ['try { if ($outer) { echo "head|"; if ($fail) { return 9; } } echo "body|"; } finally { echo "cleanup|"; } echo "tail|"; return 7;', 'int', '99', '0', 'head|body|cleanup|tail|7|0|head|cleanup|9'],
    'declaration body has a tail result' => ['declare(ticks=1) { return 5; }', 'int', '99', '0', '5|0|5'],
    'declaration body has a partial result' => ['declare(ticks=1) { if ($fail) { return 2; } echo "body|"; } echo "tail|"; return 5;', 'int', '99', '0', 'body|tail|5|0|2'],
    'declaration body in finally replaces a pending result' => ['try { return 5; } finally { declare(ticks=1) { if ($fail) { return 2; } echo "body|"; } echo "cleanup|"; }', 'int', '99', '0', 'body|cleanup|5|0|2'],
    'comment after branch result' => ['return 5; /* Keep this explanation. */', 'int', '99', '0', '5|0|5'],
    'comment after conditional result' => ['if ($fail) { return 5; /* Keep this explanation. */ } else { return 7; }', 'int', '99', '0', '7|0|5'],
    'comment after protected result' => ['try { return 5; /* Keep this explanation. */ } finally { echo "cleanup|"; }', 'int', '99', '0', 'cleanup|5|0|cleanup|5'],
    'nested finally condition follows an outer write' => ['try { if ($fail) { throw new Error("pending"); } return 1; } finally { $fail = $outer; try {} finally { if ($fail) { return "kept"; } } }', 'int|string', '99', '0', 'kept|0|caught:pending|99'],
    'nested finally condition follows a protected write' => ['try { if ($fail) { throw new Error("pending"); } return 1; } finally { try { $fail = $outer; } finally { if ($fail) { return "kept"; } } }', 'int|string', '99', '0', 'kept|0|caught:pending|99'],
    'statementful nested when supplies a protected result directly' => ['try { if ($fail) { throw new Error("pending"); } return when ($outer) { echo ""; return 1; } else { return 2; }; } finally { if ($outer) { return 3; } }', 'int', '99', '0', '3|0|caught:pending|99'],
    'nested protected handoffs retain their separate contributions' => ['try { return when ($outer) { try { if ($fail) { throw new Error("pending"); } return 1; } finally { if ($fail) { return "inner"; } } } else { return 2; }; } finally { if (!$outer) { return "outer"; } }', 'int|string', '99', '0', '1|0|caught:pending|99'],
])->with(['assignment', 'return']);

test('a known when result cannot hide an unchecked later return value', function (bool $inside, bool $direct): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $source = <<<'PPP'
<?php
function choose(bool $ready, callable $factory): int {
    int $destination = when ($ready) {
        try { return 1; } finally { echo 'cleanup|'; }
    } else { return 0; };
    $destination = $factory();
    return $destination;
}
PPP;
    if ($inside) {
        $source = str_replace('try { return 1; }', 'int $member = 1; $member = $factory(); try { return $member; }', $source);
        $source = str_replace('    $destination = $factory();' . "\n", '', $source);
    }
    if ($direct) {
        $source = str_replace('int $destination = ', 'return ', $source);
        $source = str_replace('    return $destination;' . "\n", '', $source);
    }
    $this->writeFile($root . '/src/main.ppphp', $source);
    $check = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'check', '--working-directory', $root, '--format=json']);
    $check->run();
    $diagnostics = json_decode($check->getOutput(), true)['diagnostics'];
    expect($check->getExitCode())->toBe(1, $check->getOutput())
        ->and(array_column($diagnostics, 'code'))->toContain('P2009');
    if (!$inside || $direct) {
        expect(array_column($diagnostics, 'code'))->toContain('P2016');
    }
})->with([
    'write after the when' => [false, false],
    'write inside an assigned when' => [true, false],
    'write inside a returned when' => [true, true],
]);

test('protected loop results preserve native completion and cleanup order', function (string $body, string $consumer): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $type = str_contains($body, 'return null;') ? '?int' : 'int';
    $header = '<?php function choose(bool $ready, bool $select, array<int> $values): ' . $type . ' { ';
    $expression = 'when ($ready) { ' . $body . ' } else { return 0; }';
    $statement = $consumer === 'return' ? 'return ' . $expression . ';'
        : $type . ' $result = ' . $expression . '; return $result;';
    $driver = 'echo json_encode([choose(true, false, [1, 2]), choose(true, true, [1, 2]), choose(true, true, []), choose(false, true, [1])]);';
    $this->writeFile($root . '/src/main.ppphp', $header . $statement . ' } ' . $driver);
    // No finally return here cancels a pending exception. Native return is
    // therefore the reference, including internally caught cleanup failures.
    $reference = str_replace(['array<int>', 'as int $value', 'as int $other'], ['array', 'as $value', 'as $other'],
        $header . 'if ($ready) { ' . $body . ' } else { return 0; } } ' . $driver);
    $this->writeFile($root . '/reference.php', $reference);
    $native = new Process([PHP_BINARY, $root . '/reference.php'], timeout: 5);
    $native->mustRun();
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json']);
    $build->run();
    expect($build->getExitCode())->toBe(0, $build->getOutput() . $build->getErrorOutput());
    $path = $root . '/build/ppphp/main.php';
    $php = file_get_contents($path);
    expect($php)->not->toContain('do {')->not->toContain('while (true)')->not->toContain('while (false)')
        ->not->toContain('__ppphp_when_finally')->not->toContain('__ppphp_when_pending_error')
        ->not->toContain('catch (\\Throwable');
    $runtime = new Process([PHP_BINARY, $path], timeout: 5);
    $runtime->mustRun();
    expect($runtime->getOutput())->toBe($native->getOutput())
        ->and($runtime->getErrorOutput())->toBe('')->and($native->getErrorOutput())->toBe('');
})->with([
    'try result with cleanup' => 'foreach ($values as int $value) { try { if ($select) { return $value; } echo "body|"; } finally { echo "cleanup|"; } echo "next|"; } return -1;',
    'conditional finally result' => 'foreach ($values as int $value) { try { echo "body|"; } finally { if ($select) { return $value; } echo "cleanup|"; } echo "next|"; } return -1;',
    'nullable finally result' => 'foreach ($values as int $value) { try { echo "body|"; } finally { if ($select) { return null; } echo "cleanup|"; } echo "next|"; } return -1;',
    'unconditional finally result' => 'foreach ($values as int $value) { try { echo "body|"; } finally { return $value; } } return -1;',
    'try result overwritten by finally' => 'foreach ($values as int $value) { try { return $value; } finally { return 9; } } return -1;',
    'nested protected finally result' => 'foreach ($values as int $value) { try { try { echo "body|"; } finally { if ($select) { return $value; } echo "inner|"; } echo "between|"; } finally { echo "outer|"; } echo "next|"; } return -1;',
    'nested loops and finally result' => 'foreach ($values as int $value) { foreach ($values as int $other) { try { echo "body|"; } finally { if ($select) { return $value + $other; } echo "cleanup|"; } echo "inner|"; } echo "outer|"; } return -1;',
    'finally loop overrides pending result' => 'try { return 7; } finally { foreach ($values as int $value) { if ($select) { return $value; } } echo "tail|"; }',
    'nested finally loop overrides pending result' => 'foreach ($values as int $value) { try { return $value; } finally { foreach ($values as int $other) { if ($select) { return $other * 10; } } echo "tail|"; } } return -1;',
    'nested protected body inside finally' => 'try { return 7; } finally { foreach ($values as int $value) { try { echo "body|"; } finally { if ($select) { return $value; } echo "inner|"; } echo "next|"; } echo "tail|"; }',
    'nested try already completes the protected body' => 'foreach ($values as int $value) { try { try { return $value; } finally { echo "inner|"; } } finally { return 9; } } return -1;',
    'guaranteed loop already completes the protected body' => 'foreach ($values as int $value) { try { for (;;) { return $value; } } finally { return 9; } } return -1;',
    'partial guard and protected loop share branch completion' => 'if ($select) { echo "guard|"; if ($values === []) { return null; } } foreach ($values as int $value) { try { echo "body|"; } finally { if ($select) { return null; } echo "cleanup|"; } echo "next|"; } echo "tail|"; return -1;',
    'caught cleanup failure discards the abandoned result' => 'try { try { return 9; } finally { if ($select) { throw new Error("cleanup"); } } } catch (Error $error) { echo "caught|"; } echo "tail|"; return 7;',
    'caught nested cleanup preserves the outer pending result' => 'try { return 7; } finally { try { try { if ($select) { return 9; } echo "body|"; } finally { if ($select) { throw new Error("cleanup"); } } } catch (Error $error) { echo "caught|"; } echo "tail|"; }',
])->with(['assignment', 'return']);
