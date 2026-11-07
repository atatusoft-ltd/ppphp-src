<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Browser;

use Atatusoft\Ppphp\Analysis\CompilerProjectAnalyzer;
use Atatusoft\Ppphp\Analysis\DeclarationContextCollector;
use Atatusoft\Ppphp\Interop\Php\Signature\BrowserPhpSignatureCache;
use Atatusoft\Ppphp\Interop\Php\Signature\PhpSignaturePackageLoader;
use Atatusoft\Ppphp\Project\ProjectChecker;

/** Drives the existing protocol over the browser worker's private device. */
final readonly class WorkflowRequestPump
{
    public function __construct(
        private ?WorkflowProtocol $protocol = null,
        private WorkflowRequestDecoder $decoder = new WorkflowRequestDecoder(),
    ) {}

    /**
     * The trusted host may opt in after protecting the verified compiler from
     * writes. The optional cache identity binds the exact compiler and runtime.
     *
     * @param resource $input
     * @param resource $output
     */
    public function serve(
        $input,
        $output,
        string $root,
        bool $immutableCompiler = false,
        ?string $signatureCacheIdentity = null,
        ?string $verifiedAnalyzerIdentity = null,
    ): void {
        if ($verifiedAnalyzerIdentity !== null && (!$immutableCompiler || PHP_SAPI !== 'wasm' || $this->protocol !== null)) {
            throw new \InvalidArgumentException('An analyzer fingerprint requires the trusted immutable Wasm compiler.');
        }
        $cache = null;
        if ($signatureCacheIdentity !== null) {
            if (!$immutableCompiler || PHP_SAPI !== 'wasm' || $this->protocol !== null) {
                throw new \InvalidArgumentException('Signature reuse requires the trusted immutable Wasm compiler.');
            }
            $cache = new BrowserPhpSignatureCache($signatureCacheIdentity);
        }
        $protocol = $this->protocol ?? new WorkflowProtocol(
            checker: $cache === null ? new ProjectChecker() : new ProjectChecker(
                compilerAnalyzer: new CompilerProjectAnalyzer(
                    declarationCollector: new DeclarationContextCollector(
                        phpSignatures: new PhpSignaturePackageLoader(browserCache: $cache),
                    ),
                ),
            ),
            embeddedCompiler: true,
            immutableCompiler: $immutableCompiler,
            verifiedAnalyzerIdentity: $verifiedAnalyzerIdentity,
        );
        $progress = new WorkflowProgress();
        $deadline = hrtime(true) + 360_000_000_000;
        do {
            $header = unpack('Nlength', $this->readBytes($input, 4, $deadline));
            $length = $header['length'] ?? 0;
            if (!is_int($length) || $length < 1 || $length > WorkflowRequest::MAXIMUM_BYTES) {
                throw new \InvalidArgumentException('Invalid workflow request frame length.');
            }
            $request = $this->decoder->decode($this->readBytes($input, $length, $deadline));
            $response = $protocol->handle($request, $root, $progress);
            $json = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (strlen($json) > WorkflowProtocol::MAXIMUM_RESPONSE_BYTES) {
                throw new \RuntimeException('The workflow response exceeds its byte limit.');
            }
            if ($response['status'] === 'complete' && ($response['compilerStatus'] ?? null) === 0) {
                $cache?->save();
            }
            $frame = pack('N', strlen($json)) . $json;
            if (fwrite($output, $frame) !== strlen($frame) || !fflush($output)) {
                throw new \RuntimeException('The workflow response frame could not be sent.');
            }
        } while (in_array($response['status'], ['pending-analysis', 'pending-validation'], true));
    }

    /** @param resource $channel */
    private function readBytes($channel, int $length, int|float $deadline): string
    {
        $bytes = '';
        while (($remaining = $length - strlen($bytes)) > 0) {
            if (hrtime(true) >= $deadline || feof($channel)) {
                throw new \RuntimeException('The workflow channel closed before a complete request.');
            }
            $chunk = @fread($channel, $remaining);
            if ($chunk === false || $chunk === '') {
                // The pinned runtime's device read may time out before an
                // analyzer process does. Wait cooperatively and try again.
                sleep(1);
                continue;
            }
            $bytes .= $chunk;
        }
        return $bytes;
    }
}
