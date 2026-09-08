<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Editor;

use Atatusoft\Ppphp\Cache\CompilerBuildIdentity;
use Atatusoft\Ppphp\Compiler\Compiler;

/** Serial, bounded transport; diagnostic semantics remain in the single-shot handler. */
final readonly class EditorDiagnosticsServer
{
    public const int VERSION = 1;
    public const int MAXIMUM_FRAME_BYTES = EditorDiagnosticsRequest::MAXIMUM_REQUEST_BYTES + 1024;
    public const int MAXIMUM_REQUESTS = 1000;
    public const int RECYCLE_MEMORY_BYTES = 268_435_456;

    public function __construct(
        private int $maximumRequests = self::MAXIMUM_REQUESTS,
        private int $recycleMemoryBytes = self::RECYCLE_MEMORY_BYTES,
    ) {
        if ($maximumRequests < 1 || $recycleMemoryBytes < 1) {
            throw new \InvalidArgumentException('The worker request and memory limits must be positive.');
        }
    }

    /**
     * @param resource $input
     * @param resource $output
     * @param callable(string): string $diagnose Returns the existing single-shot JSON response.
     * @param (callable(): string)|null $readInstallationIdentity Reads current on-disk identity without memoization.
     */
    public function run($input, $output, callable $diagnose, ?callable $readInstallationIdentity = null): int
    {
        $readInstallationIdentity ??= static fn (): string => (new CompilerBuildIdentity())->calculate();
        $installationIdentity = $readInstallationIdentity();
        $this->write($output, [
            'version' => self::VERSION,
            'type' => 'ready',
            'compilerVersion' => Compiler::VERSION,
            'compilerBuildIdentity' => $installationIdentity,
            'capabilities' => [
                'diagnosticsVersion' => EditorDiagnosticsRequest::VERSION,
                'maxInFlight' => 1,
                'cancellation' => 'client-discard',
                'maxFrameBytes' => self::MAXIMUM_FRAME_BYTES,
                'maxResponseBytes' => EditorDiagnosticsRequest::MAXIMUM_RESPONSE_BYTES + 1024,
                'maxRequests' => $this->maximumRequests,
                'singleShotFallback' => true,
            ],
        ]);
        $lastId = 0;
        for ($count = 1; $count <= $this->maximumRequests; $count++) {
            $line = fgets($input, self::MAXIMUM_FRAME_BYTES + 2);
            if ($line === false) {
                return feof($input) ? 0 : 2;
            }
            $id = null;
            try {
                if (strlen($line) > self::MAXIMUM_FRAME_BYTES || !str_ends_with($line, "\n")) {
                    throw new \InvalidArgumentException('Worker frames must be bounded JSON lines terminated by a newline.');
                }
                $frame = json_decode($line, false, 64, JSON_THROW_ON_ERROR);
                $id = $frame instanceof \stdClass && is_int($frame->id ?? null) ? $frame->id : null;
                if (!$frame instanceof \stdClass || ($frame->version ?? null) !== self::VERSION
                    || $id === null || $id <= $lastId || $id > 9_007_199_254_740_991
                    || !in_array($frame->method ?? null, ['diagnostics', 'shutdown'], true)) {
                    throw new \InvalidArgumentException('Use worker version 1, an increasing positive integer id, and method diagnostics or shutdown.');
                }
                $lastId = $id;
                if ($frame->method === 'shutdown') {
                    $this->write($output, ['version' => self::VERSION, 'id' => $id, 'result' => null, 'recycle' => true]);

                    return 0;
                }
                if (!($frame->params ?? null) instanceof \stdClass) {
                    throw new \InvalidArgumentException('Worker diagnostics params must contain a single-shot diagnostic request object.');
                }
                $request = json_encode($frame->params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } catch (\JsonException|\InvalidArgumentException $error) {
                $this->write($output, ['version' => self::VERSION, 'id' => $id,
                    'error' => ['code' => 'invalid-frame', 'message' => $error instanceof \JsonException
                        ? 'Worker frames must contain valid UTF-8 JSON.' : $error->getMessage()], 'recycle' => true]);

                return 2;
            }

            clearstatcache(true);
            try {
                $installationChanged = $readInstallationIdentity() !== $installationIdentity;
            } catch (\Throwable) {
                $installationChanged = true;
            }
            if ($installationChanged) {
                $this->write($output, ['version' => self::VERSION, 'id' => $id,
                    'error' => ['code' => 'installation-changed', 'message' => 'The compiler installation changed or became unavailable. Start a new worker.'], 'recycle' => true]);

                return 0;
            }

            $json = $diagnose($request);
            if (strlen($json) > EditorDiagnosticsRequest::MAXIMUM_RESPONSE_BYTES) {
                throw new \RuntimeException('The diagnostic handler exceeded its response limit.');
            }
            $result = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
            gc_collect_cycles();
            $recycle = $count === $this->maximumRequests || memory_get_usage(true) >= $this->recycleMemoryBytes
                || (is_array($result) && is_array($result['error'] ?? null) && ($result['error']['code'] ?? null) === 'internal-error');
            $this->write($output, ['version' => self::VERSION, 'id' => $id, 'result' => $result, 'recycle' => $recycle]);
            unset($result, $json, $request, $frame, $line);
            if ($recycle) {
                return 0;
            }
        }

        return 0;
    }

    /**
     * @param resource $output
     * @param array<string, mixed> $frame
     */
    private function write($output, array $frame): void
    {
        $json = json_encode($frame, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        for ($offset = 0, $length = strlen($json); $offset < $length; $offset += $written) {
            $written = fwrite($output, substr($json, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('The editor worker output is unavailable.');
            }
        }
        fflush($output);
    }
}
