<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Cli\Application;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Process\Process;

function runWhenPositionCommand(string $root, string $command): ApplicationTester
{
    $application = new Application();
    $application->setAutoExit(false);
    $tester = new ApplicationTester($application);
    $tester->run(['command' => $command, '--working-directory' => $root, '--format' => 'json']);

    return $tester;
}

test('unsupported when statement owners fail with the source cause before output', function (string $statement): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $source = <<<'PPP'
<?php
function identity(int $value): int { return $value; }
function choose(bool $ready): int {
    int $value = 0;
    array<int, int> $values = [];
    STATEMENT
    return $value;
}
PPP;
    $when = 'when ($ready) { return 1; } else { return 2; }';
    $this->writeFile($root . '/src/main.ppphp', str_replace('STATEMENT', str_replace('WHEN', $when, $statement), $source));

    foreach (['check', 'build'] as $command) {
        $result = runWhenPositionCommand($root, $command);
        $payload = json_decode($result->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        expect($result->getStatusCode())->toBe(1, $result->getDisplay())
            ->and($payload['diagnostics'][0]['code'])->toBe('P5005')
            ->and($payload['diagnostics'][0]['location']['file'])->toBe('src/main.ppphp')
            ->and(array_column($payload['diagnostics'], 'code'))->not->toContain('P9001')
            ->not->toContain('P2099')->not->toContain('P2015')->not->toContain('P2016');
    }
    expect(file_exists($root . '/build/ppphp/main.php'))->toBeFalse();
})->with([
    'for initializer assignment' => ['for ($value = WHEN; $value < 3; $value++) {}'],
    'typed for initializer' => ['for (int $index = WHEN; $index < 3; $index++) {}'],
    'for condition assignment' => ['for (; $value = WHEN; ) { break; }'],
    'for step assignment' => ['for (; $value < 3; $value = WHEN) { break; }'],
    'while condition assignment' => ['while ($value = WHEN) { break; }'],
    'do condition assignment' => ['do { $value++; } while ($value = WHEN);'],
    'switch condition assignment' => ['switch ($value = WHEN) { default: break; }'],
    'switch case condition argument' => ['switch ($value) { case identity(WHEN): break; }'],
    'echo argument' => ['echo identity(WHEN);'],
    'foreach input array value' => ['foreach ([WHEN] as int $item) { $value = $item; }'],
    'foreach input assignment' => ['foreach ($values = when ($ready) { return [1]; } else { return [2]; } as int $item) { $value = $item; }'],
    'unset offset argument' => ['unset($values[identity(WHEN)]);'],
    'arrow nested argument' => ['callable $factory = fn (bool $ready): int => identity(WHEN);'],
    'match nested argument' => ['$value = match ($ready) { true => identity(WHEN), false => 0 };'],
]);

test('supported bodies inside an owning if edit still build and run', function (string $body, string $expected): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $source = <<<'PPP'
<?php
function choose(bool $ready): int {
    int $value = 0;
    if ($value = when ($ready) { return 1; } else { return 0; }) {
        BODY
    }
    return $value;
}
echo choose(true), '|', choose(false);
PPP;
    $assignment = '$value = when ($item > 1) { return 3; } else { return 2; };';
    $this->writeFile($root . '/src/main.ppphp', str_replace('BODY', str_replace('ASSIGN', $assignment, $body), $source));
    $build = runWhenPositionCommand($root, 'build');
    expect($build->getStatusCode())->toBe(0, $build->getDisplay());
    $runtime = new Process([PHP_BINARY, $root . '/build/ppphp/main.php'], timeout: 5);
    $runtime->mustRun();
    expect($runtime->getOutput())->toBe($expected)->and($runtime->getErrorOutput())->toBe('');
})->with([
    'foreach' => ['foreach ([1, 2] as int $item) { ASSIGN }', '3|0'],
    'for' => ['for (int $item = 1; $item < 3; $item++) { ASSIGN }', '3|0'],
    'while' => ['int $item = 0; while ($item < 2) { $item++; ASSIGN }', '3|0'],
    'do while' => ['int $item = 0; do { $item++; ASSIGN } while ($item < 2);', '3|0'],
    'switch' => ['switch ($value) { default: { int $item = 2; ASSIGN break; } }', '3|0'],
    'try finally' => ['int $item = 2; try { ASSIGN } finally { $value++; }', '4|0'],
]);
