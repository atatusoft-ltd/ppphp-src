<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

test('a match subject preserves native pending-value cleanup during rethrow', function (string $kind, string $trace): void {
    $source = <<<'PHP'
ini_set('zend.exception_ignore_args', getenv('TRACE_ARGS') === 'yes' ? '0' : '1');
final class StreamValue {
    public mixed $context = null;
    private string $name = '';
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
        $this->name = substr($path, strlen('unwindprobe://'));
        echo 'open:', $this->name, '|';
        return true;
    }
    public function stream_close(): void {
        echo 'close:', $this->name, '|';
        echo json_encode(array_column(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 'function')), '|';
        if (str_contains(getenv('FAULT'), $this->name)) { throw new Error('close:' . $this->name); }
    }
}
final class ObjectValue {
    public function __construct(public string $name, public mixed $held = null) { echo 'create:', $name, '|'; }
    public function __destruct() {
        echo 'destroy:', $this->name, '|';
        echo json_encode(array_column(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 'function')), '|';
        if (str_contains(getenv('FAULT'), $this->name)) { throw new Error('destroy:' . $this->name); }
    }
}
stream_wrapper_register('unwindprobe', StreamValue::class);
function createValue(string $name): mixed {
    if ($name === 'second' && str_contains(getenv('FAULT'), 'prerequisite')) { throw new Error('prerequisite'); }
    return match (getenv('KIND')) {
        'object' => new ObjectValue($name),
        'object resource' => new ObjectValue($name, fopen('unwindprobe://' . $name, 'r')),
        'array' => [[fopen('unwindprobe://' . $name, 'r')]],
        'mixed' => $name === 'first' ? new ObjectValue($name) : fopen('unwindprobe://' . $name, 'r'),
        default => fopen('unwindprobe://' . $name, 'r'),
    };
}
function consume(mixed $first, mixed $second): void {
    echo 'consume|';
    if (str_contains(getenv('FAULT'), 'consumer')) { throw new Error('consumer'); }
}
try {
    try {
        BODY
    } catch (Throwable $caught) {
        echo 'caught:', $caught->getMessage(), '|';
        if ($caught->getPrevious() !== null) { echo 'previous:', $caught->getPrevious()->getMessage(), '|'; }
        unset($caught);
    }
} catch (Throwable $later) { echo 'trace-release:', $later->getMessage(), '|'; }
echo 'after|';
PHP;
    $bridge = <<<'PHP'
$first = $second = null;
try {
    try {
        $first = createValue('first');
        $second = createValue('second');
        consume($first, $second);
    } catch (Throwable $failure) {
        $release = [$first, $second];
        unset($first, $second);
        $first = $failure;
        unset($failure);
        $second = null;
        match ([$release, $release = null]) { default => throw ([$first, $first = null][0]) };
    }
} finally {
    $release = [$first, $second];
    unset($first, $second);
    unset($release);
}
PHP;
    foreach (['none', 'first', 'second', 'consumer', 'prerequisite', 'first+second', 'prerequisite+first', 'consumer+first+second'] as $fault) {
        $env = ['KIND' => $kind, 'TRACE_ARGS' => $trace, 'FAULT' => $fault];
        $native = new Process([PHP_BINARY, '-r', str_replace('BODY', "consume(createValue('first'), createValue('second'));", $source)], env: $env, timeout: 5);
        $native->run();
        $candidate = new Process([PHP_BINARY, '-r', str_replace('BODY', $bridge, $source)], env: $env, timeout: 5);
        $candidate->run();
        expect($native->getExitCode())->toBe(0, $native->getOutput() . $native->getErrorOutput())
            ->and($candidate->getOutput())->toBe($native->getOutput(), $kind . ':' . $trace . ':' . $fault)
            ->and($candidate->getExitCode())->toBe($native->getExitCode())
            ->and($candidate->getErrorOutput())->toBe($native->getErrorOutput());
    }
})->with(['resource', 'object', 'object resource', 'array', 'mixed'])->with(['no', 'yes']);

test('a cleanup collection must preserve argument references instead of copying their current value', function (
    string $body, string $expected,
): void {
    $source = <<<'PHP'
final class Value {
    public function __construct(public string $name) {}
    public function __destruct() { echo 'destroy:', $this->name, '|'; }
}
final class Replacer {
    private mixed $target;
    public function __construct(mixed &$target) { $this->target =& $target; }
    public function __destruct() {
        echo 'replace|';
        $this->target = new Value('new');
        echo 'replaced|';
    }
}
function consume(Replacer $first, mixed &$second): void { echo 'consume|'; }
$target = new Value('old');
BODY
echo 'after|';
unset($target);
PHP;
    $run = new Process([PHP_BINARY, '-r', str_replace('BODY', $body, $source)], timeout: 5);
    $run->mustRun();
    expect($run->getOutput())->toBe($expected)->and($run->getErrorOutput())->toBe('');
})->with([
    'native binding' => [
        'consume(new Replacer($target), $target);',
        'consume|replace|destroy:old|replaced|after|destroy:new|',
    ],
    'a copied value is a rejected lifetime change' => [
        '$first = new Replacer($target); $second =& $target; consume($first, $second);
            $release = [$first, $second]; unset($first, $second); unset($release);',
        'consume|replace|replaced|destroy:old|after|destroy:new|',
    ],
    'a retained reference follows the native binding' => [
        '$first = new Replacer($target); $second =& $target; consume($first, $second);
            $release = [$first, &$second]; unset($first, $second); unset($release);',
        'consume|replace|destroy:old|replaced|after|destroy:new|',
    ],
]);
