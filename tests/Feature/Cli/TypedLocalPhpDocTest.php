<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Support\GoldenFile;

function runTypedLocalCommand(string $root, string $command = 'check'): Process
{
    $process = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', $command, '--working-directory', $root, '--format=json']);
    $process->run();

    return $process;
}

test('declared locals may be wider than literal arithmetic cast and object initializers', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', <<<'PPP'
<?php
class Person {}
final class Member extends Person {}
string $name = 'Maya';
string $summary = $name . ': done';
int $count = 1 + 2;
float $ratio = (float) $count / 2.0;
bool $ready = (bool) $count;
array<int> $counts = [1, 2];
Person $person = new Member();
object $object = new Person();
function describe(string $name): string
{
    string $summary = $name . ': done';
    return $summary;
}
array<string, int> $scores = ['Maya' => 5];
foreach ($scores as string $key => int $score) {
    string $line = $key . ': ' . $score;
    echo $line;
}
echo '|' . describe($name) . '|' . $summary;
PPP);
    $check = runTypedLocalCommand($root);
    expect($check->getOutput())->toBeJson()
        ->and(json_decode($check->getOutput(), true)['diagnostics'])->toBe([]);
    $build = runTypedLocalCommand($root, 'build');
    expect($build->getExitCode())->toBe(0, $build->getOutput());
    GoldenFile::assertMatches(dirname(__DIR__, 2) . '/Golden/ProductionPhp/typed-locals.php.golden', file_get_contents($root . '/build/ppphp/main.php'));
    $runtime = new Process([PHP_BINARY, $root . '/build/ppphp/main.php']);
    $runtime->run();
    expect($runtime->getExitCode())->toBe(0)
        ->and($runtime->getOutput())->toBe('Maya: 5|Maya: done|Maya: done')
        ->and($runtime->getErrorOutput())->toBe('');
});

test('closure and callable locals preserve literal signatures', function (string $type, string $literal): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', '<?php' . "\nreadonly string \$prefix = 'Order';\n" . $type . ' $label = ' . $literal . ";\necho \$label(number: 7);\n");
    $build = runTypedLocalCommand($root, 'build');
    $response = json_decode($build->getOutput(), true);
    expect(array_filter($response['diagnostics'], static fn (array $diagnostic): bool => $diagnostic['severity'] === 'error'))->toBe([])
        ->and($build->getExitCode())->toBe(0, $build->getOutput());
    $generated = file_get_contents($root . '/build/ppphp/main.php');
    expect($generated)->toContain('@var ' . $type . '(int $number): string $label');
    $runtime = new Process([PHP_BINARY, $root . '/build/ppphp/main.php']);
    $runtime->run();
    expect($runtime->getExitCode())->toBe(0)
        ->and($runtime->getOutput())->toBe('Order #7');
})->with(['Closure', 'callable'])->with([
    'function (int $number) use ($prefix): string { return $prefix . " #" . $number; }',
    'fn (int $number): string => $prefix . " #" . $number',
]);

test('local declarations still reject incompatible initializers', function (string $statement, string $code): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', "<?php\n" . $statement);
    $check = runTypedLocalCommand($root);
    $response = json_decode($check->getOutput(), true);
    expect($check->getExitCode())->toBe(1)
        ->and(array_column($response['diagnostics'], 'code'))->toContain($code);
})->with([
    ['string $value = 12;', 'P2008'],
    ['int $value = "wrong";', 'P2008'],
    ['float $value = false;', 'P2008'],
    ['bool $value = 4;', 'P2008'],
    ['array<int> $value = ["wrong"];', 'P3013'],
    ['Closure $value = "strlen";', 'P2008'],
]);

test('ordinary PHP variable assertions are still checked', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.php', "<?php\n/** @var string \$value */\n\$value = new stdClass();\necho \$value;\n");
    $check = runTypedLocalCommand($root);
    expect($check->getExitCode())->toBe(1)
        ->and(array_column(json_decode($check->getOutput(), true)['diagnostics'], 'code'))->toContain('P2099');
});

test('authored assertions on the same line as generated declarations still fail', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', <<<'PPP'
<?php
object $value = new stdClass();
string $name = 'Maya'; /** @var string $value */ $value = new stdClass();
PPP);
    $check = runTypedLocalCommand($root);
    expect($check->getExitCode())->toBe(1)
        ->and(array_column(json_decode($check->getOutput(), true)['diagnostics'], 'code'))->toContain('P2099');
});

test('fixed local types still reject later writes and bad calls', function (string $body, string $code): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', '<?php string $name = "Maya"; string $summary = $name . ": done"; ' . $body);
    $check = runTypedLocalCommand($root);
    expect($check->getExitCode())->toBe(1)
        ->and(array_column(json_decode($check->getOutput(), true)['diagnostics'], 'code'))->toContain($code);
})->with([
    ['$summary = 42;', 'P2009'],
    ['function consume(int $n): void {} consume($summary);', 'P2015'],
]);

test('closure metadata retains imported nested nullable optional reference and variadic types', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', <<<'PPP'
<?php
namespace Example;
use Closure as Callback;
function make<T>(T $item): callable
{
    Callback $transform = fn (array<T>|null $values = null): array<T>|null => $values;
    callable $adjust = function (int &$number, string ...$labels): int { return $number; };
    return $adjust;
}
PPP);
    $build = runTypedLocalCommand($root, 'build');
    expect($build->getExitCode())->toBe(0, $build->getOutput());
    $generated = file_get_contents($root . '/build/ppphp/main.php');
    expect($generated)->toContain('Closure(list<T>|null $values=): list<T>|null')
        ->toContain('callable(int &$number, string ...$labels): int');
});
