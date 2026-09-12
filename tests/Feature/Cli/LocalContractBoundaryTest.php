<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

test('erased local storage contracts check the actual assigned value', function (string $body, ?string $code): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', '<?php function choose(bool $ready, callable $factory): mixed { ' . $body . ' }');
    $check = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'check', '--working-directory', $root, '--format=json']);
    $check->run();
    $result = json_decode($check->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($check->getExitCode())->toBe($code === null ? 0 : 1, $check->getOutput() . $check->getErrorOutput());
    if ($code !== null) {
        expect(array_column($result['diagnostics'], 'code'))->toContain($code);
    }
})->with([
    'unknown initializer' => ['int $value = $factory(); return $value;', 'P2008'],
    'unknown assignment' => ['int $value = 1; $value = $factory(); return $value;', 'P2009'],
    'unknown initializer in when' => ['return when ($ready) { int $value = $factory(); return $value; } else { return 0; };', 'P2008'],
    'unknown assignment in when' => ['return when ($ready) { int $value = 1; $value = $factory(); return $value; } else { return 0; };', 'P2009'],
    'authored assertion cannot erase a storage contract' => ['/** @var int $value */ int $value = $factory(); return $value;', 'P2008'],
    'mixed storage remains allowed' => ['mixed $value = $factory(); return $value;', null],
    'nullable storage remains allowed' => ['?int $value = null; $value = 1; return $value;', null],
    'literal union initializer remains allowed' => ['int|string $value = 1; return $value;', null],
    'fresh array remains allowed' => ['array<int|string> $value = [1]; return $value;', null],
    'checked unknown value satisfies the contract' => ['mixed $candidate = $factory(); if (!is_int($candidate)) { throw new \\Error(); } int $value = $candidate; return $value;', null],
]);

test('local contract checks preserve class method and closure type parameters', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', <<<'PPP'
<?php
namespace Sample;
class Holder<T> {
    public function choose<U>(T $item, U $other, bool $ready): U {
        T $classValue = $item;
        U $methodValue = when ($ready) {
            U $copy = $other;
            echo '';
            return $copy;
        } else { return $other; };
        $item = $classValue;
        $other = $methodValue;
        return $methodValue;
    }
    public function closure(T $item): T {
        \Closure $read = function () use ($item): T {
            T $copy = $item;
            $item = $copy;
            return $copy;
        };
        return $read();
    }
}
PPP);
    $check = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'check', '--working-directory', $root, '--format=json']);
    $check->run();
    expect($check->getExitCode())->toBe(0, $check->getOutput() . $check->getErrorOutput());
});

test('direct when destinations retain their storage contract at every result write', function (bool $initialize): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $prefix = $initialize ? 'int $value = ' : 'int $value = 0; $value = ';
    $this->writeFile($root . '/src/main.ppphp', '<?php
function choose(bool $ready, callable $factory, int $member): mixed {
    ' . $prefix . 'when ($ready) {
        $member = $factory();
        echo "";
        return $member;
    } else { return 0; };
    return $value;
}');
    $check = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'check', '--working-directory', $root, '--format=json']);
    $check->run();
    $result = json_decode($check->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($check->getExitCode())->toBe(1, $check->getOutput() . $check->getErrorOutput())
        ->and(array_column($result['diagnostics'], 'code'))->toContain($initialize ? 'P2008' : 'P2009');
    expect(array_values(array_filter($result['diagnostics'], static fn (array $diagnostic): bool =>
        $diagnostic['code'] === ($initialize ? 'P2008' : 'P2009') && str_contains($diagnostic['message'], '$value'))))
        ->toHaveCount(1);
})->with([true, false]);

test('parameter storage contracts agree for known and unknown assignments inside and outside when', function (
    bool $insideWhen, string $value, bool $valid,
): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $body = '$member = ' . $value . '; return $member;';
    if ($insideWhen) {
        $body = 'return when ($ready) { ' . $body . ' } else { return 0; };';
    }
    $this->writeFile($root . '/src/main.ppphp',
        '<?php function choose(bool $ready, int $member, callable $factory): mixed { ' . $body . ' }');
    $check = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'check', '--working-directory', $root, '--format=json']);
    $check->run();
    $result = json_decode($check->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($check->getExitCode())->toBe($valid ? 0 : 1, $check->getOutput() . $check->getErrorOutput());
    if (!$valid) {
        expect(array_column($result['diagnostics'], 'code'))->toContain('P2009');
    }
})->with([false, true])->with([
    'known incompatible value' => ['"wrong"', false],
    'unverified value' => ['$factory()', false],
    'known compatible value' => ['1', true],
]);
