<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Browser;

use Atatusoft\Ppphp\Cache\CompilerBuildIdentity;
use Atatusoft\Ppphp\Compiler\Output\NativeBuildFilesystem;

/** Fingerprints the executable inputs bound to a browser workflow. */
final class WorkflowCompilerInstallation
{
    public readonly ?CompilerBuildIdentity $mountedBuildIdentity;

    private ?string $mountedAnalyzerIdentity = null;

    public function __construct(
        private readonly NativeBuildFilesystem $filesystem,
        private readonly string $root,
        bool $immutable,
        ?string $verifiedAnalyzerIdentity = null,
    ) {
        if ($verifiedAnalyzerIdentity !== null && (!$immutable || preg_match('/\Asha256:[a-f0-9]{64}\z/', $verifiedAnalyzerIdentity) !== 1)) {
            throw new \InvalidArgumentException('Invalid analyzer fingerprint for the immutable compiler.');
        }
        $this->mountedBuildIdentity = $immutable ? new CompilerBuildIdentity($root) : null;
        $this->mountedAnalyzerIdentity = $verifiedAnalyzerIdentity;
    }

    public function readBuildIdentity(): string
    {
        return ($this->mountedBuildIdentity ?? new CompilerBuildIdentity($this->root))->calculate();
    }

    public function readAnalyzerIdentity(): string
    {
        $path = $this->root . '/vendor/phpstan/phpstan/phpstan.phar';

        if ($this->mountedBuildIdentity === null) {
            return ProtocolJson::hash($this->filesystem->readFile($path));
        }

        if ($this->mountedAnalyzerIdentity !== null) {
            return $this->mountedAnalyzerIdentity;
        }

        // Hash the verified regular file without allocating a second PHAR in
        // PHP memory. Only the host's read-only mount permits reuse.
        $digest = $this->filesystem->checkIsFile($path) ? @hash_file('sha256', $path) : false;

        if ($digest === false) {
            throw new \RuntimeException('The installed analyzer could not be fingerprinted.');
        }

        return $this->mountedAnalyzerIdentity = 'sha256:' . $digest;
    }
}
