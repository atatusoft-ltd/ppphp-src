<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

test('closure literals remain usable as callable collection elements', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', <<<'PPP'
<?php
function callbacks(): array<callable> { return [fn (int $n): int => $n]; }
function consume(array<callable> $callbacks): int { return count($callbacks); }
array<callable> $local = [fn (int $n): int => $n];
echo consume([fn (int $n): int => $n]) + count(callbacks()) + count($local);
PPP);
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json']);
    $build->run();
    expect($build->getExitCode())->toBe(0, $build->getOutput());
    $runtime = new Process([PHP_BINARY, $root . '/build/ppphp/main.php']);
    $runtime->run();
    expect($runtime->getExitCode())->toBe(0)->and($runtime->getOutput())->toBe('3');
});

test('callable collection contracts reject invalid elements and invariant array conversions', function (string $body, string $code): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', '<?php ' . $body);
    $check = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'check', '--working-directory', $root, '--format=json']);
    $check->run();
    expect($check->getExitCode())->toBe(1)
        ->and(array_column(json_decode($check->getOutput(), true, flags: JSON_THROW_ON_ERROR)['diagnostics'], 'code'))->toContain($code);
})->with([
    'invalid local element' => ['array<callable> $items = [42];', 'P3013'],
    'invalid argument element' => ['function take(array<callable> $items): void {} take([42]);', 'P2015'],
    'invalid return element' => ['function make(): array<callable> { return [42]; }', 'P3013'],
    'existing local contract' => ['array<Closure> $items = [fn (): int => 1]; array<callable> $other = $items;', 'P3016'],
    'existing argument contract' => ['function take(array<callable> $items): void {} array<Closure> $items = [fn (): int => 1]; take($items);', 'P2015'],
    'existing return contract' => ['function make(): array<callable> { array<Closure> $items = [fn (): int => 1]; return $items; }', 'P3013'],
    'map is not a list' => ['function make(): array<callable> { return ["handler" => fn (): int => 1]; }', 'P3015'],
]);
