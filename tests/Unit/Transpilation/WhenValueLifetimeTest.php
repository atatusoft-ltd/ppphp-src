<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Semantic\Type\LocalType;
use Atatusoft\Ppphp\Semantic\When\WhenValueLifetime;
use Symfony\Component\Process\Process;

test('release safety includes every possible nested value', function (string $type, bool $safe): void {
    expect((new WhenValueLifetime())->resolveReleaseSafety(LocalType::createFromText($type)->semanticType))->toBe($safe);
})->with([
    ['int', true], ['float', true], ['bool', true], ['null', true], ['string', true],
    ['int|string|null', true], ['array<int>', true], ['array<string, array<int|string>>', true],
    ['array<int>|array<string>', true], ['array<array<resource>>', false],
    ['resource', false], ['mixed', false], ['array', false], ['iterable', false],
    ['object', false], ['SomeClass', false], ['SomeClass|null', false],
    ['array<mixed>', false], ['array<int|SomeClass>', false],
]);

test('a native value handoff preserves stream consumer unwinding but not an earlier failing prerequisite', function (
    string $body, array $expected,
): void {
    $root = $this->createTemporaryDirectory();
    $source = <<<'PHP'
<?php
ini_set('zend.exception_ignore_args', '1');
final class StreamProbe {
    public mixed $context = null;
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool { return true; }
    public function stream_close(): void { echo 'close|'; }
}
stream_wrapper_register('handoffprobe', StreamProbe::class);
function nextValue(): int {
    if (getenv('FAULT') === 'prerequisite') { throw new Error(); }
    return 1;
}
function consume(mixed $resource, int $other): void {
    echo 'consume|';
    if (getenv('FAULT') === 'consumer') { throw new Error(); }
}
try { BODY } catch (Throwable) { echo 'caught|'; }
echo 'after|';
PHP;
    $this->writeFile($root . '/probe.php', str_replace('BODY', $body, $source));
    foreach (['none', 'consumer', 'prerequisite'] as $index => $fault) {
        $run = new Process([PHP_BINARY, $root . '/probe.php'], env: ['FAULT' => $fault], timeout: 5);
        $run->mustRun();
        expect($run->getOutput())->toBe($expected[$index])->and($run->getErrorOutput())->toBe('');
    }
})->with([
    'native expression oracle' => [
        'consume(fopen("handoffprobe://value", "r"), nextValue());',
        ['consume|close|after|', 'consume|caught|after|', 'caught|after|'],
    ],
    'finally cleanup changes exception context' => [
        '$value = null; try { $value = fopen("handoffprobe://value", "r"); $next = nextValue(); consume($value, $next); }
            finally { unset($value, $next); }',
        ['consume|close|after|', 'consume|close|caught|after|', 'close|caught|after|'],
    ],
    'ownership handoff corrects only the consumer boundary' => [
        '$value = null; try { $value = fopen("handoffprobe://value", "r"); $next = nextValue(); consume([$value, $value = null][0], $next); }
            finally { unset($value, $next); }',
        ['consume|close|after|', 'consume|caught|after|', 'close|caught|after|'],
    ],
]);
