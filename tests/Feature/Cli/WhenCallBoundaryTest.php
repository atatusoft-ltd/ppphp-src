<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

test('statement when call boundaries report the unresolved binding instead of changing native behavior', function (
    string $declarations, string $body, string $invocation, string $reason, string $expected,
): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $template = '<?php ' . $declarations . "\n" . $body . "\n" . $invocation;
    $when = 'when ($take) { echo "branch|"; $value = 7; return 2; } else { return 3; }';
    $native = '$take ? [print("branch|"), $value = 7, 2][2] : 3';
    $this->writeFile($root . '/src/main.ppphp', str_replace('WHEN', $when, $template));
    $run = new Process([PHP_BINARY, '-r', substr(str_replace(['WHEN', 'array<int>'], [$native, 'array'], $template), 5)]);
    $run->mustRun();
    expect($run->getOutput())->toBe($expected)->and($run->getErrorOutput())->toBe('');

    foreach (['check', 'build'] as $command) {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', $command, '--working-directory', $root, '--format=json']);
        $process->run();
        $payload = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $errors = array_values(array_filter($payload['diagnostics'], static fn (array $diagnostic): bool => $diagnostic['code'] === 'P5005'));
        expect($errors)->toHaveCount(1, $process->getOutput())
            ->and($errors[0]['message'])->toContain($reason)
            ->and($errors[0]['message'])->not->toContain('would run after')
            ->and($process->getExitCode())->not->toBe(0)
            ->and(is_file($root . '/build/ppphp/main.php'))->toBeFalse();
    }
})->with([
    'earlier literal cannot become a referenceable temporary' => [
        '',
        'function invoke(callable $callback, int $value, bool $take): void { try { $callback(1, WHEN); } catch (Error) { echo "caught|"; } }',
        'invoke(function (int &$target, int $unused): void { echo "called|"; }, 1, true);',
        'by value or by reference', 'caught|',
    ],
    'earlier local cannot be copied for an unknown reference parameter' => [
        '',
        'function invoke(callable $callback, int $value, bool $take): void { $callback($value, WHEN); }',
        'invoke(function (int &$target, int $unused): void { echo $target; }, 1, true);',
        'by value or by reference', 'branch|7',
    ],
    'unpacking must bind the original elements' => [
        'function replace(int &$target, int $unused): void { $target = 9; }',
        'function invoke(array<int> $items, int $value, bool $take): void { replace(...$items, unused: WHEN); echo json_encode($items); }',
        'invoke([1], 1, true);',
        'unpacked', 'branch|[9]',
    ],
    'a first result is not a writable argument either' => [
        '',
        'function invoke(callable $callback, int $value, bool $take): void { try { $callback(WHEN); } catch (Error) { echo "caught|"; } }',
        'invoke(function (int &$target): void { echo "called|"; }, 1, true);',
        'by value or by reference', 'branch|caught|',
    ],
]);

test('explicit bindings remain native in anonymous constructors', function (string $parameters, string $arguments, string $read): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $template = '<?php function run(int $value, bool $take): void {
        new class(' . $arguments . ') {
            public function __construct(' . $parameters . ') { echo ' . $read . ', "|"; ' . $read . ' = $replacement; }
        };
        echo $value, ";";
    } run(1, true); run(1, false);';
    $this->writeFile($root . '/src/main.ppphp', str_replace('WHEN',
        'when ($take) { $value = 7; return 2; } else { $value = 8; return 3; }', $template));
    $native = new Process([PHP_BINARY, '-r', substr(str_replace('WHEN',
        '$take ? (($value = 7) - 5) : (($value = 8) - 5)', $template), 5)]);
    $native->mustRun();
    expect($native->getOutput())->toBe('7|2;8|3;')->and($native->getErrorOutput())->toBe('');
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json']);
    $build->mustRun();
    $compiled = new Process([PHP_BINARY, $root . '/build/ppphp/main.php']);
    $compiled->mustRun();
    expect($compiled->getOutput())->toBe($native->getOutput())->and($compiled->getErrorOutput())->toBe('');
})->with([
    'positional' => ['int &$target, int $replacement', '$value, WHEN', '$target'],
    'named' => ['int $replacement, int &$target', 'target: $value, replacement: WHEN', '$target'],
]);

test('a function alias inside a branch retains its declared reference binding', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $template = <<<'PHP'
<?php
namespace Contracts;
function replace(int &$target, int $replacement): int { echo $target, '|'; $target = $replacement; return $target; }
namespace App;
use function Contracts\replace as apply;
function run(int $value, bool $take, bool $alternate): void {
    int $result = VALUE;
    echo $value, '|', $result, ';';
}
run(1, true, true); run(1, true, false); run(1, false, false);
PHP;
    $this->writeFile($root . '/src/main.ppphp', str_replace('VALUE',
        'when ($take) { return apply($value, when ($alternate) { $value = 7; return 2; } else { return 3; }); } else { return 0; }', $template));
    $native = new Process([PHP_BINARY, '-r', substr(str_replace(['int $result', 'VALUE'],
        ['$result', '$take ? apply($value, $alternate ? (($value = 7) - 5) : 3) : 0'], $template), 5)]);
    $native->mustRun();
    expect($native->getOutput())->toBe('7|2|2;1|3|3;1|0;')->and($native->getErrorOutput())->toBe('');
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json']);
    $build->mustRun();
    $compiled = new Process([PHP_BINARY, $root . '/build/ppphp/main.php']);
    $compiled->mustRun();
    expect($compiled->getOutput())->toBe($native->getOutput())->and($compiled->getErrorOutput())->toBe('');
});
