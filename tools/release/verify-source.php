#!/usr/bin/env php
<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Versioning\ReleaseMetadataLoader;
use Atatusoft\Ppphp\Versioning\ReleaseSourceVerifier;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$options = getopt('', ['tag:', 'commit:', 'publication']);

try {
    if (!is_string($options['tag'] ?? null) || !is_string($options['commit'] ?? null)) {
        throw new InvalidArgumentException('Usage: verify-source.php --tag=<canonical-tag> --commit=<40-hex> [--publication]');
    }

    $root = dirname(__DIR__, 2);
    $metadata = (new ReleaseMetadataLoader($root))->load() ?? throw new RuntimeException('Release metadata is missing.');
    $verifier = new ReleaseSourceVerifier($root);
    $commit = $verifier->verify($metadata, $options['tag'], $options['commit'], isset($options['publication']));
    $verifier->verifyCheckout($commit);
    fwrite(STDOUT, $commit . "\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'Release source verification failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
