<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Browser;

/** Drives the existing protocol over the browser worker's private device. */
final readonly class WorkflowRequestPump
{
    public function __construct(
        private WorkflowProtocol $protocol = new WorkflowProtocol(embeddedCompiler: true),
        private WorkflowRequestDecoder $decoder = new WorkflowRequestDecoder(),
    ) {}

    /** @param resource $input
     *  @param resource $output
     */
    public function serve($input, $output, string $root): void
    {
        $progress = new WorkflowProgress();
        $deadline = hrtime(true) + 360_000_000_000;
        do {
            $header = unpack('Nlength', $this->readBytes($input, 4, $deadline));
            $length = $header['length'] ?? 0;
            if (!is_int($length) || $length < 1 || $length > WorkflowRequest::MAXIMUM_BYTES) {
                throw new \InvalidArgumentException('Invalid workflow request frame length.');
            }
            $request = $this->decoder->decode($this->readBytes($input, $length, $deadline));
            $response = $this->protocol->handle($request, $root, $progress);
            $json = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (strlen($json) > WorkflowProtocol::MAXIMUM_RESPONSE_BYTES) {
                throw new \RuntimeException('The workflow response exceeds its byte limit.');
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
