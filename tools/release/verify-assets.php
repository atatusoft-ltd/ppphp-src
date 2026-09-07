#!/usr/bin/env php
<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Versioning\ReleaseAssetVerifier;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$options = getopt('', ['commit::', 'input:']);
$commit = $options['commit'] ?? null;
$input = $options['input'] ?? null;

if (!is_string($input) || ($commit !== null && !is_string($commit))) {
    fwrite(STDERR, "Usage: verify-assets.php --input=<directory> [--commit=<40-hex-commit>]\n");
    exit(2);
}

if (!Atatusoft\Ppphp\Support\Path::isAbsolute($input)) {
    $input = Atatusoft\Ppphp\Support\Path::join((string) getcwd(), $input);
}

try {
    (new ReleaseAssetVerifier(dirname(__DIR__, 2)))->verify($input, $commit);
    fwrite(STDOUT, "Verified deterministic ++PHP release assets.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'Release asset verification failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
