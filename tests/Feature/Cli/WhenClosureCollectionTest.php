<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

test('fresh callback arrays remain fresh through when consumers', function (string $body): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $when = 'when ($ready) { return [fn (): int => 1]; } else { return [fn (): int => 2]; }';
    $this->writeFile($root . '/src/main.ppphp', '<?php
class Holder { public array<callable> $items = []; }
function pass(array<callable> $items): array<callable> { return $items; }
function callbacks(bool $ready, bool $alternate = true): array<callable> {
    ' . str_replace('WHEN', $when, $body) . '
}
echo count(callbacks(true)), count(callbacks(false));');
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json']);
    $build->run();
    expect($build->getExitCode())->toBe(0, $build->getOutput());
    $run = new Process([PHP_BINARY, $root . '/build/ppphp/main.php']);
    $run->run();
    expect($run->getExitCode())->toBe(0)
        ->and($run->getOutput())->toBe('11')
        ->and($run->getErrorOutput())->toBe('');
})->with([
    'return' => 'return WHEN;',
    'local' => 'array<callable> $items = WHEN; return $items;',
    'argument' => 'return pass(WHEN);',
    'property' => 'Holder $holder = new Holder(); $holder->items = WHEN; return $holder->items;',
    'nested' => 'return when ($alternate) { return WHEN; } else { return [fn (): int => 3]; };',
    'finally overrides an existing array' => 'array<Closure> $existing = [fn (): int => 3]; return when ($ready) { try { return $existing; } finally { return [fn (): int => 1]; } } else { return [fn (): int => 2]; };',
]);

test('when never makes existing or invalid callback arrays assignable', function (string $body): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', '<?php function callbacks(bool $ready): array<callable> {
        array<Closure> $existing = [fn (): int => 3];
        ' . $body . '
    }');
    $check = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'check', '--working-directory', $root, '--format=json']);
    $check->run();
    expect($check->getExitCode())->toBe(1)
        ->and(array_column(json_decode($check->getOutput(), true)['diagnostics'], 'code'))->toContain('P5004');
})->with([
    'one existing branch' => 'return when ($ready) { return $existing; } else { return [fn (): int => 2]; };',
    'nested existing array' => 'return when ($ready) { return when ($ready) { return $existing; } else { return [fn (): int => 1]; }; } else { return [fn (): int => 2]; };',
    'finally overrides a fresh array' => 'return when ($ready) { try { return [fn (): int => 1]; } finally { return $existing; } } else { return [fn (): int => 2]; };',
    'invalid fresh element' => 'return when ($ready) { return [42]; } else { return [fn (): int => 2]; };',
]);
