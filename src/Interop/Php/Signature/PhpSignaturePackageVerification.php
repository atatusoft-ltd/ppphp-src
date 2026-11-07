<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Interop\Php\Signature;

/** The manifest and exact file digests accepted during package verification. */
final readonly class PhpSignaturePackageVerification
{
    public string $identity;

    /**
     * @param array<string, mixed> $manifest
     * @param array<string, string> $digests Manifest first, then its ordered outputs.
     */
    public function __construct(
        public array $manifest,
        private array $digests,
    ) {
        $hash = hash_init('sha256');
        foreach ($digests as $path => $digest) {
            hash_update($hash, $path . "\0" . $digest . "\0");
        }
        $this->identity = hash_final($hash);
    }

    public function readOptionalOutput(string $directory, string $path): ?string
    {
        if (!isset($this->digests[$path])) {
            return null;
        }
        $bytes = @file_get_contents($directory . '/' . $path);
        if ($bytes === false || hash('sha256', $bytes) !== $this->digests[$path]) {
            throw new \RuntimeException(sprintf('PHP signature output "%s" changed after verification.', $path));
        }

        return $bytes;
    }

    public function matches(string $packageDirectory): bool
    {
        clearstatcache(true, $packageDirectory);
        if (!is_dir($packageDirectory) || is_link($packageDirectory)) {
            return false;
        }

        foreach ($this->digests as $path => $digest) {
            if (@hash_file('sha256', $packageDirectory . '/' . $path) !== $digest) {
                return false;
            }
        }

        return true;
    }
}
