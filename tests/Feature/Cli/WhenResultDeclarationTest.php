<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Support\GoldenFile;

test('inferred when result declarations accept narrower branch values in every consumer', function (string $body): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $when = 'when ($count === 0) { return "No users"; } else { return $count . " users: " . implode(", ", $names); }';
    $this->writeFile($root . '/src/main.ppphp', '<?php
function identity(string $value): string { return $value; }
function describeUsers(array<string> $names): string {
    readonly int $count = count($names);
    ' . str_replace('WHEN', $when, $body) . '
}
echo describeUsers([]), "|", describeUsers(["Maya"]);');
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json']);
    $build->run();
    expect($build->getExitCode())->toBe(0, $build->getOutput());
    $generated = file_get_contents($root . '/build/ppphp/main.php');
    expect($generated)->toContain('@var string $__ppphp_when_')
        ->not->toMatch('/\$__ppphp_when_\d+(?:_\d+)? = null;/');
    if ($body === 'return WHEN;') {
        GoldenFile::assertMatches(dirname(__DIR__, 2) . '/Golden/ProductionPhp/when-result.php.golden', $generated);
    }
    $run = new Process([PHP_BINARY, $root . '/build/ppphp/main.php']);
    $run->run();
    expect($run->getExitCode())->toBe(0)
        ->and($run->getOutput())->toBe('No users|1 users: Maya')
        ->and($run->getErrorOutput())->toBe('');
})->with([
    'return' => 'return WHEN;',
    'typed local' => 'string $value = WHEN; return $value;',
    'argument' => 'return identity(WHEN);',
    'array element' => 'array<string> $values = [WHEN]; return $values[0];',
    'nested result' => 'return when ($count >= 0) { return WHEN; } else { return "unreachable"; };',
    'ternary branch' => 'return $count >= 0 ? identity(WHEN) : "unreachable";',
    'coalesce operand' => '?string $none = null; return $none ?? identity(WHEN);',
]);

test('when result metadata never hides an adjacent authored assertion', function (string $tag): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', '<?php
function describe(bool $ready): string {
    /** ' . $tag . ' string $missing */
    return when ($ready) { return "ready"; } else { return "waiting"; };
}');
    $check = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'check', '--working-directory', $root, '--format=json']);
    $check->run();
    expect($check->getExitCode())->toBe(1)
        ->and($check->getOutput())->toContain('Variable $missing');
})->with(['@var', '@phpstan-var', '@psalm-var']);

test('when result declarations preserve numeric collection object and nullable values', function (string $type, string $empty, string $nonempty, string $output): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', '<?php
function result(array<string> $names): ' . $type . ' {
    return when ($names === []) { return ' . $empty . '; } else { return ' . $nonempty . '; };
}
echo json_encode([result([]), result(["Maya"])]);');
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json']);
    $build->run();
    expect($build->getExitCode())->toBe(0, $build->getOutput());
    $run = new Process([PHP_BINARY, $root . '/build/ppphp/main.php']);
    $run->run();
    expect($run->getExitCode())->toBe(0)
        ->and($run->getOutput())->toBe($output)
        ->and($run->getErrorOutput())->toBe('');
})->with([
    ['int', '0', 'count($names)', '[0,1]'],
    ['float', '0.0', '(float) count($names) / 2.0', '[0,0.5]'],
    ['bool', 'false', 'true', '[false,true]'],
    ['array<string>', '["none"]', '$names', '[["none"],["Maya"]]'],
    ['object', 'new stdClass()', 'new stdClass()', '[{},{}]'],
    ['?string', 'null', '"users: " . count($names)', '[null,"users: 1"]'],
]);
