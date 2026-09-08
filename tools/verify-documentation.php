#!/usr/bin/env php
<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Compiler\Compiler;
use Atatusoft\Ppphp\Support\Path;
use Atatusoft\Ppphp\Versioning\DocumentationPolicy;
use Atatusoft\Ppphp\Versioning\Enumerations\DocumentationAudience;
use Atatusoft\Ppphp\Versioning\ReleaseNotesValidator;
use Atatusoft\Ppphp\Versioning\ReleaseMetadataLoader;
use Atatusoft\Ppphp\Versioning\ReleaseNotesRenderer;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = Path::normalize(dirname(__DIR__));
$failures = [];
$metadata = (new ReleaseMetadataLoader($root))->load()
    ?? throw new RuntimeException('Committed release metadata is missing.');
$required = [
    'README.md',
    'CHANGELOG.md',
    'SECURITY.md',
    'THIRD_PARTY_NOTICES.md',
    'docs/getting-started.md',
    'docs/migrating-from-php.md',
    'docs/releases/README.md',
    $metadata->releaseNotes,
    'docs/releasing.md',
    'docs/decisions/0004-mvp-native-analysis-retains-phpstan.md',
];

foreach ($required as $relativePath) {
    if (!is_file(Path::join($root, $relativePath))) {
        $failures[] = sprintf('required document "%s" is missing', $relativePath);
    }
}

$documents = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $entry): bool {
            return !in_array($entry->getFilename(), ['.git', 'vendor', 'node_modules', 'dist', '.ppphp-cache', 'build'], true);
        },
    ),
);

foreach ($iterator as $entry) {
    if (!$entry instanceof SplFileInfo || !$entry->isFile() || strtolower($entry->getExtension()) !== 'md') {
        continue;
    }

    $path = Path::normalize($entry->getPathname());
    $contents = file_get_contents($path);

    if (!is_string($contents)) {
        $failures[] = sprintf('Markdown document "%s" is unreadable', Path::resolveRelativeTo($path, $root));
        continue;
    }

    $documents[$path] = $contents;
}

ksort($documents, SORT_STRING);
$policy = new DocumentationPolicy();
$audienceCounts = array_fill_keys(array_column(DocumentationAudience::cases(), 'value'), 0);

foreach ($documents as $path => $contents) {
    $relativePath = Path::resolveRelativeTo($path, $root);
    $audience = $policy->classify($relativePath);
    ++$audienceCounts[$audience->value];

    if ($audience === DocumentationAudience::Public) {
        $failures = [...$failures, ...$policy->validatePublic($relativePath, $contents)];
    }

    $linkText = preg_replace('/```.*?```|~~~.*?~~~/s', '', $contents) ?? $contents;

    if (preg_match_all('/!?\[[^\]]*\]\(([^)]+)\)/', $linkText, $matches) !== false) {
        foreach ($matches[1] as $target) {
            $target = trim((string) $target, " <>\t\n\r\0\x0B");

            if ($target === '' || $target[0] === '#' || preg_match('/\A[a-z][a-z0-9+.-]*:/i', $target) === 1) {
                continue;
            }

            $target = rawurldecode(explode('#', $target, 2)[0]);
            $resolved = Path::join(dirname($path), $target);

            if (!Path::contains($root, $resolved) || (!is_file($resolved) && !is_dir($resolved))) {
                $failures[] = sprintf('%s contains an unresolved repository link: %s', $relativePath, $target);
            }
        }
    }
}

$composerPath = Path::join($root, 'composer.json');
$composerContents = file_get_contents($composerPath);

if (!is_string($composerContents)) {
    $failures[] = 'composer.json is unreadable';
} else {
    $failures = [...$failures, ...$policy->validatePublic('composer.json', $composerContents)];
}

$readme = $documents[Path::join($root, 'README.md')] ?? '';
$releaseNotes = $documents[Path::join($root, $metadata->releaseNotes)] ?? '';
$changelog = $documents[Path::join($root, 'CHANGELOG.md')] ?? '';
$plan = $documents[Path::join($root, 'docs/ppphp-mvp-end-to-end-plan.md')] ?? '';
$decision = $documents[Path::join($root, 'docs/decisions/0004-mvp-native-analysis-retains-phpstan.md')] ?? '';

$expectations = [
    [str_contains($readme, 'https://ppphplang.org'), 'README does not link to the canonical website'],
    [str_contains($readme, 'atatusoft-ltd/ppphp-src'), 'README does not state the Composer package'],
    [str_contains($readme, '.ppphp'), 'README does not state the canonical source extension'],
    [str_contains($readme, 'supplemental PHPStan analysis'), 'README does not disclose supplemental PHPStan analysis'],
    [str_contains($changelog, '## Unreleased') && str_contains($changelog, '### Known limitations'), 'changelog does not retain release-oriented sections'],
    [str_contains($plan, 'Stage 14A') && str_contains($plan, 'Stage 14B') && str_contains($plan, 'Stage 14C'), 'MVP plan does not preserve the Stage 14 release split'],
    [str_contains($plan, 'Stage 15') && str_contains($plan, 'post-MVP'), 'MVP plan does not classify Stage 15 as post-MVP'],
    [str_contains($decision, 'PHPStan') && stripos($decision, 'retain') !== false, 'analyzer decision does not record the retained PHPStan backend'],
];

foreach ($expectations as [$condition, $message]) {
    if (!$condition) {
        $failures[] = $message;
    }
}

$failures = [
    ...$failures,
    ...(new ReleaseNotesValidator())->validate($releaseNotes, $metadata->version),
];

if ($failures === []) {
    // Validate the same content that the asset builder and publisher consume.
    $rendered = (new ReleaseNotesRenderer())->render($releaseNotes, $metadata);
    $failures = (new ReleaseNotesValidator())->validate($rendered, $metadata->version);
}

$retiredIdentity = 'ph' . 'plus';
$retiredProduct = 'Do' . 'ria';

foreach ($documents as $path => $contents) {
    if (
        stripos($contents, $retiredIdentity) !== false
        || stripos($contents, $retiredProduct) !== false
        || preg_match('/\.ppp\b/i', $contents) === 1
    ) {
        $failures[] = sprintf('%s contains retired public identity', Path::resolveRelativeTo($path, $root));
    }
}

$identityTargets = [
    'src',
    'tests',
    'tools',
    'docs',
    'resources',
    '.github',
    'bin',
    'examples',
    'composer.json',
    'phpstan.neon.dist',
    'ppphp.json.dist',
    'README.md',
    'CHANGELOG.md',
    'SECURITY.md',
];
$identityFiles = [];

foreach ($identityTargets as $relativeTarget) {
    $target = Path::join($root, $relativeTarget);

    if (is_file($target) && !is_link($target)) {
        $identityFiles[$target] = true;
        continue;
    }

    if (!is_dir($target) || is_link($target)) {
        continue;
    }

    $identityIterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $entry): bool {
                return !in_array($entry->getFilename(), ['.git', 'vendor', 'node_modules', 'dist', '.ppphp-cache', 'build'], true);
            },
        ),
    );

    foreach ($identityIterator as $entry) {
        if ($entry instanceof SplFileInfo && $entry->isFile() && !$entry->isLink()) {
            $identityFiles[Path::normalize($entry->getPathname())] = true;
        }
    }
}

ksort($identityFiles, SORT_STRING);

foreach (array_keys($identityFiles) as $path) {
    $contents = file_get_contents($path);
    $relativePath = Path::resolveRelativeTo($path, $root);

    if (!is_string($contents)) {
        $failures[] = sprintf('first-party identity file "%s" is unreadable', $relativePath);
        continue;
    }

    $failures = [...$failures, ...$policy->findRetiredNamespaceReferences($relativePath, $contents)];
}

if ($failures !== []) {
    foreach (array_values(array_unique($failures)) as $failure) {
        fwrite(STDERR, 'Documentation verification failed: ' . $failure . ".\n");
    }

    exit(1);
}

fwrite(STDOUT, sprintf(
    "Verified offline documentation for ++PHP %s (%d public, %d maintainer, %d technical).\n",
    Compiler::VERSION,
    $audienceCounts[DocumentationAudience::Public->value],
    $audienceCounts[DocumentationAudience::Maintainer->value],
    $audienceCounts[DocumentationAudience::Technical->value],
));
