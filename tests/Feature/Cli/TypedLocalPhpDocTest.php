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

function assertPlainLocalPhp(string $root): void
{
    file_put_contents($root . '/plain.neon', "parameters:\n    level: max\n    tmpDir: " . $root . "/plain-cache\n");
    $process = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/vendor/phpstan/phpstan/phpstan.phar',
        'analyse', '--configuration=' . $root . '/plain.neon', '--error-format=json', '--no-progress', $root . '/build/ppphp/main.php']);
    $process->run();
    expect($process->getExitCode())->toBe(0, $process->getOutput() . $process->getErrorOutput());
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
    assertPlainLocalPhp($root);
    $runtime = new Process([PHP_BINARY, $root . '/build/ppphp/main.php']);
    $runtime->run();
    expect($runtime->getExitCode())->toBe(0)
        ->and($runtime->getOutput())->toBe('Maya: 5|Maya: done|Maya: done')
        ->and($runtime->getErrorOutput())->toBe('');
});

test('closure and callable locals preserve literal signatures', function (string $type, string $literal, string $context): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $declaration = $type . ' $label = ' . $literal;
    $body = match ($context) {
        'local' => $declaration . '; echo $label(number: 7);',
        'for' => 'for (' . $declaration . '; true;) { echo $label(number: 7); break; }',
        'when-local' => 'string $result = when ($enabled) { ' . $declaration . '; return $label(number: 7); } else { return ""; }; echo $result;',
        'when-for', 'when-documented-for' => 'string $result = when ($enabled) { '
            . ($context === 'when-documented-for' ? '/** Handler setup. */ ' : '')
            . 'for (' . $declaration . '; $iterate;) { return $label(number: 7); } return ""; } else { return ""; }; echo $result;',
    };
    $this->writeFile($root . '/src/main.ppphp', '<?php function show(bool $enabled, bool $iterate): void {' . "\nreadonly string \$prefix = 'Order';\n" . $body . "\n} show(true, true);\n");
    $build = runTypedLocalCommand($root, 'build');
    $response = json_decode($build->getOutput(), true);
    expect(array_filter($response['diagnostics'], static fn (array $diagnostic): bool => $diagnostic['severity'] === 'error'))->toBe([])
        ->and($build->getExitCode())->toBe(0, $build->getOutput());
    $generated = file_get_contents($root . '/build/ppphp/main.php');
    // Native callable inference can be more precise than its declared return
    // type; require valid standalone output instead of a widening assertion.
    expect($generated)->toContain('int $number');
    assertPlainLocalPhp($root);
    $runtime = new Process([PHP_BINARY, $root . '/build/ppphp/main.php']);
    $runtime->run();
    expect($runtime->getExitCode())->toBe(0)
        ->and($runtime->getOutput())->toBe('Order #7');
})->with(['Closure', 'callable'])->with([
    'function (int $number) use ($prefix): string { return $prefix . " #" . $number; }',
    'fn (int $number): string => $prefix . " #" . $number',
])->with(['local', 'for', 'when-local', 'when-for', 'when-documented-for']);

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

test('regenerated when declarations never hide authored variable assertions', function (string $assertion, string $message): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', '<?php function show(bool $enabled): void {
        string $result = when ($enabled) {
            object $value = new stdClass();
            ' . $assertion . '
            return "ok";
        } else { return ""; };
        echo $result;
    } show(true);');
    $check = runTypedLocalCommand($root);
    $errors = array_values(array_filter(json_decode($check->getOutput(), true)['diagnostics'],
        static fn (array $diagnostic): bool => $diagnostic['severity'] === 'error'));
    expect($check->getExitCode())->toBe(1, $check->getOutput())
        ->and($errors)->toHaveCount(1)
        ->and($errors[0]['code'])->toBe('P2099')
        ->and($errors[0]['message'])->toContain($message);
})->with([
    'identical tag text is not provenance' => ['/** @var object $value */ $value = new stdClass();', 'not subtype'],
    'authored missing variable remains an error' => ['/** @var string $missing */ echo "marker";', 'Variable $missing'],
]);

test('authored assertions immediately before generated declarations remain checked', function (string $declaration, string $tag): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', '<?php function show(): void {
        /** ' . $tag . ' string $missing */
        ' . $declaration . '
    } show();');
    $check = runTypedLocalCommand($root);
    expect($check->getExitCode())->toBe(1, $check->getOutput())
        ->and($check->getOutput())->toContain('Variable $missing');
})->with([
    'local' => 'string $message = "ok"; echo $message;',
    'for' => 'for (int $n = 0; $n < 1; ++$n) { echo $n; }',
])->with(['@var', '@phpstan-var', '@psalm-var']);

test('when local PHPDoc preserves authored assertions beside its generated type', function (string $tag): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', '<?php function show(bool $enabled): int {
        return when ($enabled) {
            /** ' . $tag . ' string $missing */
            int $value = 7;
            return $value;
        } else { return 0; };
    } echo show(true);');
    $check = runTypedLocalCommand($root);
    expect($check->getExitCode())->toBe(1, $check->getOutput())
        ->and($check->getOutput())->toContain('Variable $missing');
})->with(['@var', '@phpstan-var', '@psalm-var']);

test('when builds retain local and loop comments with their generated type contracts', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', <<<'PPP'
<?php
function total(bool $ready): int {
    return when ($ready) {
        /** Accumulate each value. */
        int $sum = 0;
        for (int /* Visit once. */ $index = 0; $index < 1; ++$index) {
            foreach ([7] as int /* Add this item. */ $item) {
                $sum += $item;
            }
        }
        return $sum;
    } else { return 0; };
}
echo total(true), '|', total(false);
PPP);
    $build = runTypedLocalCommand($root, 'build');
    expect($build->getExitCode())->toBe(0, $build->getOutput());
    $path = $root . '/build/ppphp/main.php';
    $php = file_get_contents($path);
    foreach (['Accumulate each value.', 'Visit once.', 'Add this item.', '@var int $sum'] as $text) {
        expect(substr_count($php, $text))->toBe(1);
    }
    assertPlainLocalPhp($root);
    $runtime = new Process([PHP_BINARY, $path], timeout: 5);
    $runtime->mustRun();
    expect($runtime->getOutput())->toBe('7|0')->and($runtime->getErrorOutput())->toBe('');
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
    expect($generated)->toContain('@param list<T>|null $values')
        ->toContain('@return list<T>|null')
        ->toContain('int &$number, string ...$labels');
    assertPlainLocalPhp($root);
});
