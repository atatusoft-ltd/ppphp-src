<?php

declare(strict_types=1);

// Inspect the installed distribution as data. Never include or evaluate it.
$path = $argv[1] ?? '';
if ($path === '' || !is_file($path) || filesize($path) > 134217728) {
    fwrite(STDERR, "Expected a PHPStan PHAR smaller than 128 MiB.\n");
    exit(1);
}

try {
    $archive = new Phar($path);
    $files = 0;
    $totalBytes = 0;
    $references = [];
    $referenceCount = 0;
    foreach (new RecursiveIteratorIterator($archive) as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        ++$files;
        $size = $file->getSize();
        $totalBytes += $size;
        if ($files > 20000 || $size > 8388608 || $totalBytes > 268435456) {
            throw new RuntimeException('PHPStan inspection size limit exceeded.');
        }
        $contents = $file->getContent();
        foreach (token_get_all($contents) as $token) {
            if (!is_array($token) || in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
                continue;
            }
            if (stripos($token[1], 'Fiber') === false) {
                continue;
            }
            ++$referenceCount;
            if (count($references) < 64) {
                $references[] = [
                    'file' => substr($file->getPathname(), strlen('phar://' . $archive->getPath()) + 1),
                    'line' => $token[2],
                    'token' => token_name($token[0]),
                    'text' => substr($token[1], 0, 256),
                ];
            }
        }
    }
    echo json_encode([
        'pharSha256' => hash_file('sha256', $path),
        'phpVersion' => PHP_VERSION,
        'filesInspected' => $files,
        'referenceCount' => $referenceCount,
        'references' => $references,
        'referencesTruncated' => $referenceCount > count($references),
        'interpretation' => 'Static references only, not evidence that a call site executed.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
