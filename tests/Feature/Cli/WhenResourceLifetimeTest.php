<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

test('when resources and resource containers keep native release timing', function (string $value, bool $bare): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $template = <<<'PHP'
<?php
ini_set('zend.exception_ignore_args', getenv('TRACE_ARGS') === 'yes' ? '0' : '1');
final class ReleaseStream {
    public mixed $context = null;
    private string $name = '';
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
        $this->name = substr($path, strlen('whenresource://'));
        echo 'open:', $this->name, '|';
        return true;
    }
    public function stream_close(): void {
        echo 'close:', $this->name, '|';
        if (getenv('FAULT') === $this->name) { throw new Error('release'); }
    }
}
stream_wrapper_register('whenresource', ReleaseStream::class);
final class ResourceHolder {
    public function __construct(public mixed $stream) {}
}
function createValue(string $name): mixed {
    if ($name === 'second' && getenv('FAULT') === 'branch') { throw new Error('branch'); }
    return VALUE;
}
function consume(mixed $first, mixed $second): void {
    echo 'consume|';
    if (getenv('FAULT') === 'consumer') { throw new Error('consumer'); }
}
try {
    consume(FIRST, SECOND);
} catch (Throwable $error) { echo 'caught|'; }
echo 'after|';
stream_wrapper_unregister('whenresource');
PHP;
    $template = str_replace('VALUE', $value, $template);
    // The else-when case forces statement lowering without changing what the
    // branch does. Both forms must agree with the same native expression.
    $when = static function (string $name) use ($bare): string {
        $result = 'createValue("' . $name . '")';
        return 'when (getenv("BRANCH") === "first") { return ' . $result . '; } '
            . ($bare ? '' : 'else when (getenv("BRANCH") === "third") { return ' . $result . '; } ')
            . 'else { return ' . $result . '; }';
    };
    $this->writeFile($root . '/src/main.ppphp', str_replace(['FIRST', 'SECOND'], [$when('first'), $when('second')], $template));
    $this->writeFile($root . '/reference.php', str_replace(['FIRST', 'SECOND'], ['createValue("first")', 'createValue("second")'], $template));
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json']);
    $build->run();
    expect($build->getExitCode())->toBe(0, $build->getOutput() . $build->getErrorOutput());
    $differences = [];
    foreach (['no', 'yes'] as $trace) {
        foreach (['none', 'first', 'second', 'consumer', 'branch'] as $fault) {
            $env = ['FAULT' => $fault, 'TRACE_ARGS' => $trace];
            $native = new Process([PHP_BINARY, $root . '/reference.php'], env: $env, timeout: 5);
            $native->mustRun();
            $compiled = new Process([PHP_BINARY, $root . '/build/ppphp/main.php'], env: $env, timeout: 5);
            $compiled->mustRun();
            if ($fault === 'none') {
                expect($native->getOutput())->toBe('open:first|open:second|consume|close:first|close:second|after|');
            }
            if ($compiled->getOutput() !== $native->getOutput()) {
                $differences[$trace . ':' . $fault] = ['native' => $native->getOutput(), 'compiled' => $compiled->getOutput()];
            }
            expect($native->getErrorOutput())->toBe('')
                ->and($compiled->getErrorOutput())->toBe('');
        }
    }
    expect($differences)->toBe([]);
})->with([
    'resource' => ['fopen("whenresource://" . $name, "r")'],
    'resource array' => ['[fopen("whenresource://" . $name, "r")]'],
    'nested resource array' => ['[[fopen("whenresource://" . $name, "r")]]'],
    'resource in an object' => ['new ResourceHolder(fopen("whenresource://" . $name, "r"))'],
])->with(['native ternary' => [true], 'statement lowering' => [false]]);
