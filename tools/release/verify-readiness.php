#!/usr/bin/env php
<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Compiler\Compiler;
use Atatusoft\Ppphp\Support\CanonicalJson;
use Atatusoft\Ppphp\Support\Path;
use Atatusoft\Ppphp\Versioning\ReleaseAssetBuilder;
use Atatusoft\Ppphp\Versioning\ReleaseAssetVerifier;
use Atatusoft\Ppphp\Versioning\ReleaseMetadataLoader;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = Path::normalize(dirname(__DIR__, 2));
$temporaryRoot = Path::join(sys_get_temp_dir(), 'ppphp-release-readiness-' . bin2hex(random_bytes(10)));
$sourceCommit = str_repeat('0', 40);
$exitCode = 0;

/** @return array<string, mixed> */
function readinessJson(string $path): array
{
    $contents = file_get_contents($path);
    $document = is_string($contents) ? CanonicalJson::decode($contents) : null;

    if (!is_array($document) || array_is_list($document)) {
        throw new RuntimeException(sprintf('Expected a JSON object at %s.', $path));
    }

    return $document;
}

function readinessProcess(array $command, string $workingDirectory): string
{
    $process = new Process($command, $workingDirectory, timeout: 30.0);
    $process->run();

    if (!$process->isSuccessful()) {
        throw new RuntimeException(trim($process->getErrorOutput() . "\n" . $process->getOutput()));
    }

    return $process->getOutput();
}

function removeReadinessTree(string $path, string $ownedRoot): void
{
    if (!Path::contains($ownedRoot, $path) || !file_exists($path) || is_link($path)) {
        return;
    }

    if (is_dir($path)) {
        foreach (new FilesystemIterator($path) as $entry) {
            removeReadinessTree(Path::normalize($entry->getPathname()), $ownedRoot);
        }

        if (!rmdir($path)) {
            throw new RuntimeException('Could not remove release-readiness temporary directory.');
        }

        return;
    }

    if (!unlink($path)) {
        throw new RuntimeException('Could not remove release-readiness temporary file.');
    }
}

try {
    $before = readinessProcess(['git', 'status', '--porcelain=v1', '--untracked-files=all'], $root);
    $metadata = (new ReleaseMetadataLoader($root))->load()
        ?? throw new RuntimeException('Committed release metadata is missing.');

    if (
        $metadata->version->canonical !== Compiler::VERSION
        || $metadata->tag !== Compiler::VERSION
        || $metadata->channel !== $metadata->version->channel
        || $metadata->prerelease !== $metadata->version->isPrerelease
    ) {
        throw new RuntimeException('The compiler and release metadata do not identify the selected canonical release.');
    }

    $composer = readinessJson(Path::join($root, 'composer.json'));

    if (
        ($composer['name'] ?? null) !== 'atatusoft-ltd/ppphp-src'
        || ($composer['homepage'] ?? null) !== 'https://ppphplang.org'
        || ($composer['license'] ?? null) !== 'Apache-2.0'
        || ($composer['autoload']['psr-4'] ?? null) !== ['Atatusoft\\Ppphp\\' => 'src/']
        || ($composer['require']['php'] ?? null) !== '^8.4'
        || ($composer['require-dev']['pestphp/pest'] ?? null) !== '^5.1'
        || isset($composer['version'])
        || isset($composer['minimum-stability'])
        || isset($composer['extra']['branch-alias'])
    ) {
        throw new RuntimeException('Composer distribution metadata does not match the release contract.');
    }

    foreach (['nikic/php-parser', 'phpstan/phpdoc-parser', 'phpstan/phpstan', 'symfony/console', 'symfony/process'] as $dependency) {
        if (!isset($composer['require'][$dependency])) {
            throw new RuntimeException(sprintf('Required runtime package %s is missing.', $dependency));
        }
    }

    $lock = readinessJson(Path::join($root, 'composer.lock'));
    $notices = file_get_contents(Path::join($root, 'THIRD_PARTY_NOTICES.md'));

    if (!is_string($notices)) {
        throw new RuntimeException('Third-party notices are unreadable.');
    }

    foreach ($lock['packages'] ?? [] as $package) {
        if (!is_array($package)) {
            throw new RuntimeException('Composer lock production package metadata is invalid.');
        }

        $name = $package['name'] ?? null;
        $version = $package['pretty_version'] ?? ($package['version'] ?? null);
        $licenses = $package['license'] ?? null;

        if (
            !is_string($name)
            || !is_string($version)
            || !is_array($licenses)
            || $licenses === []
            || !str_contains($notices, sprintf('| `%s` | `%s` | %s |', $name, $version, implode(', ', $licenses)))
        ) {
            throw new RuntimeException(sprintf('Third-party notices do not match locked production package %s.', is_string($name) ? $name : '<invalid>'));
        }
    }

    if (!mkdir($temporaryRoot, 0700)) {
        throw new RuntimeException('Could not create release-readiness temporary directory.');
    }

    $first = Path::join($temporaryRoot, 'first');
    $second = Path::join($temporaryRoot, 'second');
    $builder = new ReleaseAssetBuilder($root);
    $builder->build($first, $sourceCommit);
    $builder->build($second, $sourceCommit);
    $verifier = new ReleaseAssetVerifier($root);
    $verifier->verify($first, $sourceCommit);
    $verifier->verify($second, $sourceCommit);

    foreach (ReleaseAssetBuilder::ASSET_NAMES as $asset) {
        $firstContents = file_get_contents(Path::join($first, $asset));
        $secondContents = file_get_contents(Path::join($second, $asset));

        if (!is_string($firstContents) || !is_string($secondContents) || !hash_equals($firstContents, $secondContents)) {
            throw new RuntimeException(sprintf('Release asset %s is not reproducible.', $asset));
        }
    }

    $ci = file_get_contents(Path::join($root, '.github/workflows/php.yml'));
    $release = file_get_contents(Path::join($root, '.github/workflows/release.yml'));

    if (!is_string($ci) || substr_count($ci, 'run: composer check') !== 1 || !str_contains($ci, 'composer verify:distribution')) {
        throw new RuntimeException('CI must run the aggregate gate exactly once and verify the installed distribution.');
    }

    if (
        !is_string($release)
        || !str_contains($release, "tags:\n      - '*'")
        || !str_contains($release, 'tools/release/verify-source.php')
        || !str_contains($release, 'composer check')
        || !str_contains($release, 'composer verify:distribution')
        || !str_contains($release, 'contents: write')
        || !str_contains($release, 'gh release create')
        || str_contains($release, 'packagist')
    ) {
        throw new RuntimeException('The tag-driven release workflow does not satisfy the guarded publication contract.');
    }

    $after = readinessProcess(['git', 'status', '--porcelain=v1', '--untracked-files=all'], $root);

    if ($after !== $before) {
        throw new RuntimeException('Release readiness modified repository state.');
    }

    fwrite(STDOUT, sprintf("Verified offline preparation readiness for ++PHP %s (%s). Public installation is a separate post-publication check.\n", Compiler::VERSION, $metadata->channel->value));
} catch (Throwable $exception) {
    fwrite(STDERR, 'Release readiness failed: ' . $exception->getMessage() . "\n");
    $exitCode = 1;
} finally {
    if (file_exists($temporaryRoot)) {
        removeReadinessTree($temporaryRoot, $temporaryRoot);
    }
}

exit($exitCode);
