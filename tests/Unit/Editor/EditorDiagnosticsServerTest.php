<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Editor\EditorDiagnosticsServer;

function runDiagnosticFrames(string $frames, int $maximumRequests = 1000, string $result = '{"diagnostics":[],"error":null}', int $memoryLimit = PHP_INT_MAX, ?Closure $readIdentity = null): array
{
    $input = fopen('php://temp', 'w+');
    $output = fopen('php://temp', 'w+');
    fwrite($input, $frames);
    rewind($input);
    $calls = [];
    $exit = (new EditorDiagnosticsServer($maximumRequests, $memoryLimit))->run($input, $output,
        static function (string $json) use (&$calls, $result): string {
            $calls[] = json_decode($json, true);
            return $result;
        }, $readIdentity ?? static fn (): string => 'test-installation');
    rewind($output);
    $responses = array_map(static fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
        explode("\n", trim(stream_get_contents($output))));
    fclose($input);
    fclose($output);

    return [$exit, $responses, $calls];
}

test('worker advertises its serial cancellation contract and preserves ordered responses', function (): void {
    [$exit, $responses, $calls] = runDiagnosticFrames(
        "{\"version\":1,\"id\":1,\"method\":\"diagnostics\",\"params\":{\"version\":1}}\n"
        . "{\"version\":1,\"id\":2,\"method\":\"shutdown\"}\n",
    );
    expect($exit)->toBe(0)->and($calls)->toBe([['version' => 1]])
        ->and($responses[0]['type'])->toBe('ready')
        ->and($responses[0]['capabilities'])->toMatchArray(['maxInFlight' => 1, 'cancellation' => 'client-discard', 'singleShotFallback' => true])
        ->and($responses[1])->toMatchArray(['id' => 1, 'result' => ['diagnostics' => [], 'error' => null], 'recycle' => false])
        ->and($responses[2])->toMatchArray(['id' => 2, 'result' => null, 'recycle' => true]);
});

test('worker retires before analysis when its same-version installation changes or disappears', function (bool $unavailable): void {
    $reads = 0;
    $readIdentity = static function () use (&$reads, $unavailable): string {
        if (++$reads === 1) {
            return 'original-installation';
        }
        if ($unavailable) {
            throw new RuntimeException('Installation unreadable');
        }
        return 'replacement-installation';
    };
    [$exit, $responses, $calls] = runDiagnosticFrames(
        "{\"version\":1,\"id\":1,\"method\":\"diagnostics\",\"params\":{}}\n", readIdentity: $readIdentity,
    );
    expect($exit)->toBe(0)->and($calls)->toBe([])
        ->and($responses[0]['compilerBuildIdentity'])->toBe('original-installation')
        ->and($responses[1]['error']['code'])->toBe('installation-changed')
        ->and($responses[1]['recycle'])->toBeTrue();
})->with([false, true]);

test('worker rejects malformed ambiguous and oversized framing before diagnostics', function (string $frame): void {
    [$exit, $responses, $calls] = runDiagnosticFrames($frame);
    expect($exit)->toBe(2)->and($calls)->toBe([])
        ->and($responses[1]['error']['code'])->toBe('invalid-frame')
        ->and($responses[1]['recycle'])->toBeTrue();
})->with([
    "{\n", "[]\n", "{\"version\":2,\"id\":1,\"method\":\"shutdown\"}\n",
    "{\"version\":1,\"id\":0,\"method\":\"shutdown\"}\n",
    "{\"version\":1,\"id\":9007199254740992,\"method\":\"shutdown\"}\n",
    "{\"version\":1,\"id\":1,\"method\":\"diagnostics\",\"params\":[]}\n",
    "{\"version\":1,\"id\":1,\"method\":\"shutdown\"}",
    fn () => str_repeat(' ', EditorDiagnosticsServer::MAXIMUM_FRAME_BYTES) . "{}\n",
]);

test('worker rejects reused request ids and recycles at its declared request limit', function (): void {
    $line = "{\"version\":1,\"id\":1,\"method\":\"diagnostics\",\"params\":{}}\n";
    [$exit, $responses, $calls] = runDiagnosticFrames($line . $line);
    expect($exit)->toBe(2)->and($calls)->toHaveCount(1)
        ->and($responses[2]['error']['code'])->toBe('invalid-frame');
    [$exit, $responses, $calls] = runDiagnosticFrames($line . $line, 1);
    expect($exit)->toBe(0)->and($calls)->toHaveCount(1)->and($responses[1]['recycle'])->toBeTrue();
    [$exit, $responses, $calls] = runDiagnosticFrames($line . $line, memoryLimit: 1);
    expect($exit)->toBe(0)->and($calls)->toHaveCount(1)->and($responses[1]['recycle'])->toBeTrue();
});

test('worker exits quietly on idle EOF and recycles after an internal diagnostic failure', function (): void {
    [$exit, $responses, $calls] = runDiagnosticFrames('');
    expect($exit)->toBe(0)->and($responses)->toHaveCount(1)->and($calls)->toBe([]);
    $line = "{\"version\":1,\"id\":1,\"method\":\"diagnostics\",\"params\":{}}\n";
    [$exit, $responses, $calls] = runDiagnosticFrames($line . $line, result: '{"error":{"code":"internal-error"}}');
    expect($exit)->toBe(0)->and($calls)->toHaveCount(1)
        ->and($responses[1]['result']['error']['code'])->toBe('internal-error')
        ->and($responses[1]['recycle'])->toBeTrue();
});
