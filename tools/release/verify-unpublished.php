#!/usr/bin/env php
<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Versioning\ReleaseMetadataLoader;
use Atatusoft\Ppphp\Versioning\ReleasePublicationGuard;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

try {
    $metadata = (new ReleaseMetadataLoader())->load() ?? throw new RuntimeException('Release metadata is missing.');
    // Listing avoids interpreting a 404 (including hidden authorization failures)
    // as absence. Pagination includes every visible published or draft release.
    $process = new Process(['gh', 'api', '--paginate', '--slurp', 'repos/atatusoft-ltd/ppphp-src/releases'], timeout: 60.0);
    $process->run();
    (new ReleasePublicationGuard())->verifyAbsent($metadata->tag, $process->getExitCode() ?? 1, $process->getOutput());
    fwrite(STDOUT, "No existing release found.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'Publication preflight failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
