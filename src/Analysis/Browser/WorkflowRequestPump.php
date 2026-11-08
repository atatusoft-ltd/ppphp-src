<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Browser;

use Atatusoft\Ppphp\Analysis\CompilerProjectAnalyzer;
use Atatusoft\Ppphp\Analysis\DeclarationContextCollector;
use Atatusoft\Ppphp\Interop\Php\Signature\BrowserPhpSignatureCache;
use Atatusoft\Ppphp\Interop\Php\Signature\PhpSignaturePackageLoader;
use Atatusoft\Ppphp\Project\ProjectChecker;
use Atatusoft\Ppphp\Semantic\Pass\DeclareSymbolsPass;
use Atatusoft\Ppphp\Semantic\Pass\ResolveNamesPass;
use Atatusoft\Ppphp\Semantic\SemanticAnalyzer;

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
        bool $preparePlatform = false,
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
        $signatures = $cache === null ? null : new PhpSignaturePackageLoader(browserCache: $cache);
        if ($preparePlatform && $signatures === null) {
            throw new \InvalidArgumentException('Platform preparation requires a verified signature cache identity.');
        }
        $semanticAnalyzer = new SemanticAnalyzer(
            declareSymbols: new DeclareSymbolsPass(reusePlatformDeclarations: $preparePlatform),
            resolveNames: new ResolveNamesPass(reusePlatformNames: $preparePlatform),
        );
        $protocol = $this->protocol ?? new WorkflowProtocol(
            checker: $signatures === null ? new ProjectChecker() : new ProjectChecker(
                compilerAnalyzer: new CompilerProjectAnalyzer(
                    declarationCollector: new DeclarationContextCollector(
                        phpSignatures: $signatures,
                    ),
                    semanticAnalyzer: $semanticAnalyzer,
                ),
            ),
            embeddedCompiler: true,
            immutableCompiler: $immutableCompiler,
            verifiedAnalyzerIdentity: $verifiedAnalyzerIdentity,
        );
        $progress = new WorkflowProgress();
        $deadline = hrtime(true) + 360_000_000_000;
        if ($preparePlatform) {
            (new WorkflowPlatformPreparation())->prepare($signatures, $protocol, $semanticAnalyzer);
            $this->writeResponse($output, ['prepared' => true]);
        }

        do {
            $header = unpack('Nlength', $this->readBytes($input, 4, $deadline));
            $length = $header['length'] ?? 0;
            if (!is_int($length) || $length < 1 || $length > WorkflowRequest::MAXIMUM_BYTES) {
                throw new \InvalidArgumentException('Invalid workflow request frame length.');
            }
            $request = $this->decoder->decode($this->readBytes($input, $length, $deadline));
            $response = $protocol->handle($request, $root, $progress);
            $this->writeResponse($output, $response, $cache);
        } while (in_array($response['status'], ['pending-analysis', 'pending-validation'], true));
    }

    /**
     * @param resource $output
     * @param array<string, mixed> $response
     */
    private function writeResponse($output, array $response, ?BrowserPhpSignatureCache $cache = null): void
    {
        $json = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($json) > WorkflowProtocol::MAXIMUM_RESPONSE_BYTES) {
            throw new \RuntimeException('The workflow response exceeds its byte limit.');
        }
        if (($response['status'] ?? null) === 'complete' && ($response['compilerStatus'] ?? null) === 0) {
            $cache?->save();
        }
        $frame = pack('N', strlen($json)) . $json;
        if (fwrite($output, $frame) !== strlen($frame) || !fflush($output)) {
            throw new \RuntimeException('The workflow response frame could not be sent.');
        }
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
