<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Cli\Application;
use Atatusoft\Ppphp\Cli\Enumerations\ExitCode;
use Atatusoft\Ppphp\Compiler\CompilationArtifact;
use Atatusoft\Ppphp\Compiler\Compiler;
use Atatusoft\Ppphp\Compiler\Manifest\ConfigurationFingerprint;
use Atatusoft\Ppphp\Compiler\Output\AtomicBuildCommitter;
use Atatusoft\Ppphp\Compiler\Output\NativeBuildFilesystem;
use Atatusoft\Ppphp\Compiler\Output\ProjectBuildLock;
use Atatusoft\Ppphp\Compiler\Validation\Interfaces\PhpValidator;
use Atatusoft\Ppphp\Config\ProjectConfigLoader;
use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Project\Enumerations\SelectionMode;
use Atatusoft\Ppphp\Project\ProjectLoader;
use Atatusoft\Ppphp\Project\ProjectSelector;
use Symfony\Component\Console\Tester\ApplicationTester;

function runStageTenCommand(array $input): ApplicationTester
{
    $application = new Application();
    $application->setAutoExit(false);
    $tester = new ApplicationTester($application);
    $tester->run(['--no-ansi' => true, ...$input]);

    return $tester;
}

/** @return array<string, string> */
function captureStageTenTree(string $root): array
{
    if (!is_dir($root)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && !$file->isLink()) {
            $path = str_replace('\\', '/', $file->getPathname());
            $relative = ltrim(substr($path, strlen(str_replace('\\', '/', $root))), '/');
            $contents = file_get_contents($file->getPathname());
            $files[$relative] = $contents === false ? '' : $contents;
        }
    }

    ksort($files, SORT_STRING);

    return $files;
}

/** @return array{Atatusoft\Ppphp\Project\Project, Atatusoft\Ppphp\Project\ProjectSelection} */
function loadStageTenCompilationInputs(string $root): array
{
    $configuration = (new ProjectConfigLoader())->load($root, null, true)->configuration;
    expect($configuration)->not->toBeNull();
    $project = (new ProjectLoader())->load($configuration)->project;
    expect($project)->not->toBeNull();
    $selection = (new ProjectSelector())->select($project, null, SelectionMode::Build)->selection;
    expect($selection)->not->toBeNull();

    return [$project, $selection];
}

test('pathless builds commit deterministic manifests maps strict PHP and byte-identical copies', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $plain = "<?php\necho 'plain';\n";
    $this->writeFile($root . '/src/bootstrap.php', $plain);
    $this->writeFile($root . '/src/Core/Value.ppphp', "<?php\nfunction value(): int { int \$value = 1; return \$value; }\n");

    $first = runStageTenCommand(['command' => 'build', '--working-directory' => $root]);
    $firstTree = captureStageTenTree($root . '/build/ppphp');
    $manifest = json_decode($firstTree['.ppphp/manifest.json'] ?? '', true, 512, JSON_THROW_ON_ERROR);
    $second = runStageTenCommand(['command' => 'build', '--working-directory' => $root]);
    $secondTree = captureStageTenTree($root . '/build/ppphp');
    [$project] = loadStageTenCompilationInputs($root);
    $currentFingerprint = (new ConfigurationFingerprint())->calculate($project);
    $differentVersionFingerprint = (new ConfigurationFingerprint('dev-2026.3.2'))->calculate($project);

    expect($first->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($first->getDisplay())->toContain('Built 2 Files Atomically.')
        ->and($second->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($secondTree)->toBe($firstTree)
        ->and($firstTree['bootstrap.php'] ?? null)->toBe($plain)
        ->and($firstTree['Core/Value.php'] ?? '')->toContain('declare(strict_types=1);')
        ->and($manifest['formatVersion'] ?? null)->toBe(\Atatusoft\Ppphp\Compiler\Manifest\BuildManifest::FORMAT_VERSION)
        ->and($manifest['compiler']['buildIdentity'] ?? null)->toMatch('/^sha256:[a-f0-9]{64}$/')
        ->and($manifest['loweringFormatVersion'] ?? null)->toBe(Compiler::LOWERING_FORMAT_VERSION)
        ->and($manifest['completeProject'] ?? null)->toBeTrue()
        ->and($manifest['compiler']['version'] ?? null)->toBe(Compiler::VERSION)
        ->and($manifest['compiler']['version'] ?? null)->not->toBe('development')
        ->and($manifest['configurationFingerprint'] ?? null)->toBe($currentFingerprint)
        ->and($manifest['configurationFingerprint'] ?? null)->not->toBe($differentVersionFingerprint)
        ->and($manifest['files'] ?? null)->toHaveCount(2)
        ->and($firstTree)->toHaveKeys([
            '.ppphp/source-maps/bootstrap.php.map.json',
            '.ppphp/source-maps/Core/Value.php.map.json',
        ]);

    foreach ($manifest['files'] as $entry) {
        expect('sha256:' . hash('sha256', $firstTree[$entry['output']]))->toBe($entry['outputHash'])
            ->and($firstTree)->toHaveKey($entry['sourceMap']);
    }
});

test('failed and subsequent pathless builds preserve then replace the complete tree', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $valid = "<?php\nfunction stageTenCurrent(): int { return 1; }\n";
    $this->writeFile($root . '/src/Current.ppphp', $valid);
    $this->writeFile($root . '/src/Stale.ppphp', "<?php\nfunction stale(): int { return 1; }\n");
    runStageTenCommand(['command' => 'build', '--working-directory' => $root]);
    $this->writeFile($root . '/build/ppphp/unmanaged.txt', 'remove me');
    $beforeFailure = captureStageTenTree($root . '/build/ppphp');
    $this->writeFile($root . '/src/Current.ppphp', '<?php function broken(');

    $failed = runStageTenCommand(['command' => 'build', '--working-directory' => $root]);

    expect($failed->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and(captureStageTenTree($root . '/build/ppphp'))->toBe($beforeFailure);

    $this->writeFile($root . '/src/Current.ppphp', $valid);
    unlink($root . '/src/Stale.ppphp');
    $successful = runStageTenCommand(['command' => 'build', '--working-directory' => $root]);
    $after = captureStageTenTree($root . '/build/ppphp');

    expect($successful->getStatusCode())->toBe(ExitCode::Success->value, $successful->getDisplay())
        ->and($after)->not->toHaveKey('Stale.php')
        ->not->toHaveKey('.ppphp/source-maps/Stale.php.map.json')
        ->not->toHaveKey('unmanaged.txt');
});

test('pathless builds replace output trees containing unsafe interior entries', function (): void {
    if (!function_exists('symlink')) {
        $this->markTestSkipped('Symbolic links are unavailable.');
    }

    $root = $this->createTemporaryDirectory();
    $outside = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/One.ppphp', "<?php\nfunction unsafeReplacement(): int { return 1; }\n");
    runStageTenCommand(['command' => 'build', '--working-directory' => $root]);
    $this->writeFile($outside . '/preserved.txt', 'preserved');
    symlink($outside . '/preserved.txt', $root . '/build/ppphp/linked.txt');
    $staleEntries = 1;

    if (function_exists('posix_mkfifo') && posix_mkfifo($root . '/build/ppphp/queue', 0600)) {
        $staleEntries++;
    }

    $this->writeFile($root . '/src/One.ppphp', "<?php\nfunction unsafeReplacement(): int { return 2; }\n");
    $build = runStageTenCommand(['command' => 'build', '--working-directory' => $root]);

    expect($build->getStatusCode())->toBe(ExitCode::Success->value, $build->getDisplay())
        ->and($build->getDisplay())->toContain(sprintf('Removed %d Stale', $staleEntries))
        ->and(is_link($root . '/build/ppphp/linked.txt'))->toBeFalse()
        ->and(file_exists($root . '/build/ppphp/queue'))->toBeFalse()
        ->and(file_get_contents($outside . '/preserved.txt'))->toBe('preserved')
        ->and(file_get_contents($root . '/build/ppphp/One.php'))->toContain('return 2;');
});

test('focused and directory builds merge compatible manifests and remove stale selected scope', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Feature/One.ppphp', "<?php\nfunction one(): int { return 1; }\n");
    $this->writeFile($root . '/src/Feature/Old.ppphp', "<?php\nfunction old(): int { return 1; }\n");
    $this->writeFile($root . '/src/Other/Two.ppphp', "<?php\nfunction two(): int { return 2; }\n");
    runStageTenCommand(['command' => 'build', '--working-directory' => $root]);
    $two = file_get_contents($root . '/build/ppphp/Other/Two.php');
    $this->writeFile($root . '/src/Feature/One.ppphp', "<?php\nfunction one(): int { return 10; }\n");

    $focused = runStageTenCommand([
        'command' => 'build',
        'path' => 'src/Feature/One.ppphp',
        '--working-directory' => $root,
    ]);
    unlink($root . '/src/Feature/Old.ppphp');
    $directory = runStageTenCommand([
        'command' => 'build',
        'path' => 'src/Feature',
        '--working-directory' => $root,
    ]);
    $manifest = json_decode(
        file_get_contents($root . '/build/ppphp/.ppphp/manifest.json') ?: '',
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($focused->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($directory->getStatusCode())->toBe(ExitCode::Success->value)
        ->and(file_get_contents($root . '/build/ppphp/Other/Two.php'))->toBe($two)
        ->and(file_exists($root . '/build/ppphp/Feature/Old.php'))->toBeFalse()
        ->and($manifest['completeProject'] ?? null)->toBeTrue();
});

test('a first focused build creates a partial manifest', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/One.ppphp', "<?php\nfunction one(): int { return 1; }\n");
    $this->writeFile($root . '/src/Two.ppphp', "<?php\nfunction two(): int { return 2; }\n");
    $build = runStageTenCommand([
        'command' => 'build',
        'path' => 'src/One.ppphp',
        '--working-directory' => $root,
    ]);
    $manifest = json_decode(
        file_get_contents($root . '/build/ppphp/.ppphp/manifest.json') ?: '',
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($build->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($manifest['completeProject'] ?? null)->toBeFalse()
        ->and($manifest['files'] ?? null)->toHaveCount(1)
        ->and(file_exists($root . '/build/ppphp/Two.php'))->toBeFalse();
});

test('partial builds reject modified manifest-owned output without changing it', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/One.ppphp', "<?php\nfunction one(): int { return 1; }\n");
    $this->writeFile($root . '/src/Two.ppphp', "<?php\nfunction two(): int { return 2; }\n");
    runStageTenCommand(['command' => 'build', '--working-directory' => $root]);
    $this->writeFile($root . '/build/ppphp/Two.php', "<?php\n// manual change\n");
    $before = captureStageTenTree($root . '/build/ppphp');
    $build = runStageTenCommand([
        'command' => 'build',
        'path' => 'src/One.ppphp',
        '--working-directory' => $root,
    ]);

    expect($build->getStatusCode())->toBe(ExitCode::OutputValidationFailed->value)
        ->and($build->getDisplay())->toContain('ERROR P7012 · ')
        ->and(captureStageTenTree($root . '/build/ppphp'))->toBe($before);
});

test('build locking prevents same-process concurrent build transactions', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/One.ppphp', "<?php\nfunction one(): int { return 1; }\n");
    $configuration = (new ProjectConfigLoader())->load($root, null, true)->configuration;
    expect($configuration)->not->toBeNull();
    $lock = new ProjectBuildLock();
    expect($lock->acquire($configuration))->toBeTrue();

    try {
        $build = runStageTenCommand(['command' => 'build', '--working-directory' => $root]);
    } finally {
        $lock->release();
    }

    expect($build->getStatusCode())->toBe(ExitCode::OutputValidationFailed->value)
        ->and($build->getDisplay())->toContain('ERROR P7009 · ');
});

test('operation locking permits concurrent checks and excludes build or clean mutations', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Lock.ppphp', "<?php\nfunction lockValue(): int { return 1; }\n");
    $configuration = (new ProjectConfigLoader())->load($root, null, true)->configuration;
    expect($configuration)->not->toBeNull();
    $firstCheck = new ProjectBuildLock();
    $secondCheck = new ProjectBuildLock();
    $mutation = new ProjectBuildLock();

    expect($firstCheck->acquire($configuration, false))->toBeTrue()
        ->and($secondCheck->acquire($configuration, false))->toBeTrue()
        ->and($mutation->acquire($configuration))->toBeFalse();

    $firstCheck->release();
    $secondCheck->release();

    expect($mutation->acquire($configuration))->toBeTrue();
    $blockedCheck = new ProjectBuildLock();
    expect($blockedCheck->acquire($configuration, false))->toBeFalse();
    $mutation->release();

    (new NativeBuildFilesystem())->remove($root . '/.ppphp-cache');

    expect(file_exists($root . '/.ppphp-operation.lock'))->toBeTrue()
        ->and(file_exists($root . '/.ppphp-cache'))->toBeFalse();
});

test('a failed final candidate rename restores the previous output tree', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/One.ppphp', "<?php\nfunction one(): int { return 1; }\n");
    runStageTenCommand(['command' => 'build', '--working-directory' => $root]);
    $before = captureStageTenTree($root . '/build/ppphp');
    $this->writeFile($root . '/src/One.ppphp', "<?php\nfunction one(): int { return 2; }\n");
    $configuration = (new ProjectConfigLoader())->load($root, null, true)->configuration;
    expect($configuration)->not->toBeNull();
    $project = (new ProjectLoader())->load($configuration)->project;
    expect($project)->not->toBeNull();
    $selection = (new ProjectSelector())->select($project, null, SelectionMode::Build)->selection;
    expect($selection)->not->toBeNull();
    $filesystem = new class extends NativeBuildFilesystem {
        private int $moves = 0;

        public function move(string $from, string $to): void
        {
            $this->moves++;

            if ($this->moves === 2) {
                throw new RuntimeException('Injected candidate commit failure.');
            }

            parent::move($from, $to);
        }
    };
    $compiler = new Compiler(committer: new AtomicBuildCommitter(filesystem: $filesystem));
    $result = $compiler->compile($project, $selection);

    expect($result->isSuccessful)->toBeFalse()
        ->and($result->diagnostics->errors[0]->code->value ?? null)->toBe('P7006')
        ->and(captureStageTenTree($root . '/build/ppphp'))->toBe($before)
        ->and(glob($root . '/build/.ppphp-stage-*') ?: [])->toBe([])
        ->and(glob($root . '/build/.ppphp-backup-*') ?: [])->toBe([]);
});

test('strict types cannot be disabled by a source build', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Strict.ppphp', "<?php\ndeclare(strict_types=0);\necho 1;\n");
    $build = runStageTenCommand(['command' => 'build', '--working-directory' => $root]);

    expect($build->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and($build->getDisplay())->toContain('ERROR P2033 · ')
        ->and(file_exists($root . '/build/ppphp'))->toBeFalse();
});

test('an empty full project replaces all previous output with an empty complete manifest', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Old.ppphp', "<?php\nfunction oldStageTen(): int { return 1; }\n");
    runStageTenCommand(['command' => 'build', '--working-directory' => $root]);
    unlink($root . '/src/Old.ppphp');

    $build = runStageTenCommand(['command' => 'build', '--working-directory' => $root]);
    $tree = captureStageTenTree($root . '/build/ppphp');
    $manifest = json_decode($tree['.ppphp/manifest.json'] ?? '', true, 512, JSON_THROW_ON_ERROR);

    expect($build->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($manifest['completeProject'] ?? null)->toBeTrue()
        ->and($manifest['files'] ?? null)->toBe([])
        ->and($tree)->toHaveCount(1)
        ->and(glob($root . '/build/.ppphp-stage-*') ?: [])->toBe([])
        ->and(glob($root . '/build/.ppphp-backup-*') ?: [])->toBe([]);
});

test('an empty directory build removes only manifest-owned output from that scope', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Feature/Old.ppphp', "<?php\nfunction oldFeatureStageTen(): int { return 1; }\n");
    $this->writeFile($root . '/src/Other/Keep.ppphp', "<?php\nfunction keepStageTen(): int { return 1; }\n");
    runStageTenCommand(['command' => 'build', '--working-directory' => $root]);
    unlink($root . '/src/Feature/Old.ppphp');

    $build = runStageTenCommand([
        'command' => 'build',
        'path' => 'src/Feature',
        '--working-directory' => $root,
    ]);
    $manifest = json_decode(
        file_get_contents($root . '/build/ppphp/.ppphp/manifest.json') ?: '',
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($build->getStatusCode())->toBe(ExitCode::Success->value)
        ->and(file_exists($root . '/build/ppphp/Feature/Old.php'))->toBeFalse()
        ->and(file_exists($root . '/build/ppphp/Other/Keep.php'))->toBeTrue()
        ->and($manifest['completeProject'] ?? null)->toBeTrue()
        ->and($manifest['files'] ?? null)->toHaveCount(1);
});

test('partial builds reject invalid manifests while pathless builds replace them', function (string $replacement): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/One.ppphp', "<?php\nfunction manifestOneStageTen(): int { return 1; }\n");
    runStageTenCommand(['command' => 'build', '--working-directory' => $root]);
    $manifestPath = $root . '/build/ppphp/.ppphp/manifest.json';
    $this->writeFile($manifestPath, $replacement);
    $before = captureStageTenTree($root . '/build/ppphp');

    $partial = runStageTenCommand([
        'command' => 'build',
        'path' => 'src/One.ppphp',
        '--working-directory' => $root,
    ]);

    expect($partial->getStatusCode())->toBe(ExitCode::OutputValidationFailed->value)
        ->and($partial->getDisplay())->toContain('ERROR P7004 · ')
        ->and(captureStageTenTree($root . '/build/ppphp'))->toBe($before);

    $complete = runStageTenCommand(['command' => 'build', '--working-directory' => $root]);
    $manifest = json_decode(file_get_contents($manifestPath) ?: '', true, 512, JSON_THROW_ON_ERROR);

    expect($complete->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($manifest['formatVersion'] ?? null)->toBe(\Atatusoft\Ppphp\Compiler\Manifest\BuildManifest::FORMAT_VERSION)
        ->and($manifest['completeProject'] ?? null)->toBeTrue();
})->with([
    'invalid JSON' => '{',
    'unsupported version' => "{\n    \"formatVersion\": 99\n}\n",
]);

test('partial builds reject incompatible manifest identity', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/One.ppphp', "<?php\nfunction incompatibleStageTen(): int { return 1; }\n");
    runStageTenCommand(['command' => 'build', '--working-directory' => $root]);
    $manifestPath = $root . '/build/ppphp/.ppphp/manifest.json';
    $manifest = json_decode(file_get_contents($manifestPath) ?: '', true, 512, JSON_THROW_ON_ERROR);
    $manifest['compiler']['version'] = 'dev-2026.3.2';
    $this->writeFile($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

    $partial = runStageTenCommand([
        'command' => 'build',
        'path' => 'src/One.ppphp',
        '--working-directory' => $root,
    ]);

    expect($partial->getStatusCode())->toBe(ExitCode::OutputValidationFailed->value)
        ->and($partial->getDisplay())->toContain('ERROR P7011 · ');
});

test('partial builds reject manifests created with different source exclusions', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Keep.ppphp', "<?php\nfunction keepStageTen(): int { return 1; }\n");
    $this->writeFile($root . '/src/Excluded.ppphp', "<?php\nfunction excludeStageTen(): int { return 1; }\n");
    runStageTenCommand(['command' => 'build', '--working-directory' => $root]);
    $this->writeConfiguration($root, [
        'exclude' => ['.ppphp-cache', 'build', 'vendor'],
    ]);
    $reordered = runStageTenCommand([
        'command' => 'build',
        'path' => 'src/Keep.ppphp',
        '--working-directory' => $root,
    ]);
    $this->writeConfiguration($root, [
        'exclude' => ['src/Excluded.ppphp', '.ppphp-cache', 'build', 'vendor'],
    ]);

    $partial = runStageTenCommand([
        'command' => 'build',
        'path' => 'src/Keep.ppphp',
        '--working-directory' => $root,
    ]);
    $complete = runStageTenCommand(['command' => 'build', '--working-directory' => $root]);

    expect($reordered->getStatusCode())->toBe(ExitCode::Success->value, $reordered->getDisplay())
        ->and($partial->getStatusCode())->toBe(ExitCode::OutputValidationFailed->value)
        ->and($partial->getDisplay())->toContain('ERROR P7011 · ')
        ->and($complete->getStatusCode())->toBe(ExitCode::Success->value, $complete->getDisplay())
        ->and(file_exists($root . '/build/ppphp/Keep.php'))->toBeTrue()
        ->and(file_exists($root . '/build/ppphp/Excluded.php'))->toBeFalse();
});

test('reserved metadata output paths are rejected globally', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/.ppphp/Internal.ppphp', "<?php\necho 1;\n");
    $build = runStageTenCommand(['command' => 'build', '--working-directory' => $root]);

    expect($build->getStatusCode())->toBe(ExitCode::OutputValidationFailed->value)
        ->and($build->getDisplay())->toContain('ERROR P7008 · ')
        ->and(file_exists($root . '/build/ppphp'))->toBeFalse();
});

test('partial builds reject symlinks inside the output tree without following them', function (): void {
    if (!function_exists('symlink')) {
        $this->markTestSkipped('Symbolic links are unavailable.');
    }

    $root = $this->createTemporaryDirectory();
    $outside = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/One.ppphp', "<?php\nfunction linkedStageTen(): int { return 1; }\n");
    runStageTenCommand(['command' => 'build', '--working-directory' => $root]);
    $this->writeFile($outside . '/untouched.txt', 'outside');
    symlink($outside . '/untouched.txt', $root . '/build/ppphp/linked.txt');

    $build = runStageTenCommand([
        'command' => 'build',
        'path' => 'src/One.ppphp',
        '--working-directory' => $root,
    ]);

    expect($build->getStatusCode())->toBe(ExitCode::OutputValidationFailed->value)
        ->and($build->getDisplay())->toContain('ERROR P7005 · ')
        ->and(file_get_contents($outside . '/untouched.txt'))->toBe('outside');
});

test('production artifacts preserve supported source permission modes', function (): void {
    if (DIRECTORY_SEPARATOR !== '/' || !function_exists('chmod')) {
        $this->markTestSkipped('Reliable POSIX permission assertions are unavailable.');
    }

    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Executable.ppphp', "<?php\necho 1;\n");
    $this->writeFile($root . '/src/bootstrap.php', "<?php\necho 2;\n");
    chmod($root . '/src/Executable.ppphp', 0755);
    chmod($root . '/src/bootstrap.php', 0640);
    $build = runStageTenCommand(['command' => 'build', '--working-directory' => $root]);
    $manifest = json_decode(
        file_get_contents($root . '/build/ppphp/.ppphp/manifest.json') ?: '',
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $modes = array_column($manifest['files'], 'mode', 'output');

    expect($build->getStatusCode())->toBe(ExitCode::Success->value)
        ->and(fileperms($root . '/build/ppphp/Executable.php') & 0777)->toBe(0755)
        ->and(fileperms($root . '/build/ppphp/bootstrap.php') & 0777)->toBe(0640)
        ->and($modes['Executable.php'] ?? null)->toBe('0755')
        ->and($modes['bootstrap.php'] ?? null)->toBe('0640');
});

test('handled candidate failures preserve the complete live tree and release the build lock', function (string $phase): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/One.ppphp', "<?php\nfunction failureStageTen(): int { return 1; }\n");
    runStageTenCommand(['command' => 'build', '--working-directory' => $root]);
    $before = captureStageTenTree($root . '/build/ppphp');
    $this->writeFile($root . '/src/One.ppphp', "<?php\nfunction failureStageTen(): int { return 2; }\n");
    [$project, $selection] = loadStageTenCompilationInputs($root);

    $filesystem = new class($phase) extends NativeBuildFilesystem {
        public function __construct(private readonly string $phase) {}

        public function createDirectory(string $path): void
        {
            if ($this->phase === 'candidate' && str_contains($path, '.ppphp-stage-')) {
                throw new RuntimeException('Injected candidate-directory failure.');
            }

            parent::createDirectory($path);
        }

        public function writeFile(string $path, string $contents, ?int $mode = null): void
        {
            if (
                str_contains($path, '.ppphp-stage-')
                && (
                    ($this->phase === 'artifact' && str_ends_with($path, '/One.php'))
                    || ($this->phase === 'map' && str_ends_with($path, '.map.json'))
                    || ($this->phase === 'manifest' && str_ends_with($path, '/manifest.json'))
                )
            ) {
                throw new RuntimeException('Injected candidate-write failure.');
            }

            parent::writeFile($path, $contents, $mode);
        }
    };
    $compiler = new Compiler(committer: new AtomicBuildCommitter(filesystem: $filesystem));
    $result = $compiler->compile($project, $selection);
    $lock = new ProjectBuildLock();

    expect($result->isSuccessful)->toBeFalse()
        ->and(captureStageTenTree($root . '/build/ppphp'))->toBe($before)
        ->and(glob($root . '/build/.ppphp-stage-*') ?: [])->toBe([])
        ->and($lock->acquire($project->configuration))->toBeTrue();
    $lock->release();
})->with(['candidate', 'artifact', 'map', 'manifest']);

test('lint rejection preserves prior output and exposes no transaction path normally', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/One.ppphp', "<?php\nfunction lintStageTen(): int { return 1; }\n");
    runStageTenCommand(['command' => 'build', '--working-directory' => $root]);
    $before = captureStageTenTree($root . '/build/ppphp');
    $this->writeFile($root . '/src/One.ppphp', "<?php\nfunction lintStageTen(): int { return 2; }\n");
    [$project, $selection] = loadStageTenCompilationInputs($root);
    $validator = new class implements PhpValidator {
        public function validate(CompilationArtifact $artifact, string $candidatePath): DiagnosticBag
        {
            $diagnostics = new DiagnosticBag();
            $diagnostics->add(new Diagnostic(
                DiagnosticCode::GeneratedPhpIsInvalid,
                'Injected lint rejection.',
            ));

            return $diagnostics;
        }
    };
    $compiler = new Compiler(committer: new AtomicBuildCommitter(phpValidator: $validator));
    $result = $compiler->compile($project, $selection);
    $rendered = (new Atatusoft\Ppphp\Diagnostics\ConsoleRenderer())->render($result->diagnostics);

    expect($result->isSuccessful)->toBeFalse()
        ->and($result->diagnostics->errors[0]->code)->toBe(DiagnosticCode::GeneratedPhpIsInvalid)
        ->and(captureStageTenTree($root . '/build/ppphp'))->toBe($before)
        ->and($rendered)->not->toContain('.ppphp-stage-');
});

test('post lint mutations cannot publish known or additional candidate files', function (string $mutation): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/One.ppphp', '<?php function guardedCandidate(): int { return 1; }');
    runStageTenCommand(['command' => 'build', '--working-directory' => $root]);
    $before = captureStageTenTree($root . '/build/ppphp');
    $this->writeFile($root . '/src/One.ppphp', '<?php function guardedCandidate(): int { return 2; }');
    [$project, $selection] = loadStageTenCompilationInputs($root);
    $validator = new class($mutation) implements PhpValidator {
        public function __construct(private readonly string $mutation) {}

        public function validate(CompilationArtifact $artifact, string $candidatePath): DiagnosticBag
        {
            $diagnostics = (new Atatusoft\Ppphp\Compiler\Validation\PhpLintValidator())->validate($artifact, $candidatePath);
            $path = $this->mutation === 'known' ? $candidatePath : dirname($candidatePath) . '/unvalidated.php';
            file_put_contents($path, '<?php echo "changed after actual lint";');
            return $diagnostics;
        }
    };
    $result = (new Compiler(committer: new AtomicBuildCommitter(phpValidator: $validator)))->compile($project, $selection);
    expect($result->isSuccessful)->toBeFalse()
        ->and(captureStageTenTree($root . '/build/ppphp'))->toBe($before)
        ->and(glob($root . '/build/.ppphp-stage-*') ?: [])->toBe([])
        ->and(glob($root . '/build/.ppphp-backup-*') ?: [])->toBe([]);
    expect(runStageTenCommand(['command' => 'build', '--working-directory' => $root])->getStatusCode())->toBe(0);
})->with(['known', 'additional']);

test('backup cleanup failure keeps the committed output and reports a warning', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/One.ppphp', "<?php\nfunction cleanupStageTen(): int { return 1; }\n");
    runStageTenCommand(['command' => 'build', '--working-directory' => $root]);
    $this->writeFile($root . '/src/One.ppphp', "<?php\nfunction cleanupStageTen(): int { return 2; }\n");
    [$project, $selection] = loadStageTenCompilationInputs($root);
    $filesystem = new class extends NativeBuildFilesystem {
        public function remove(string $path): void
        {
            if (str_contains($path, '.ppphp-backup-')) {
                throw new RuntimeException('Injected backup-cleanup failure.');
            }

            parent::remove($path);
        }
    };
    $result = (new Compiler(committer: new AtomicBuildCommitter(filesystem: $filesystem)))
        ->compile($project, $selection);

    expect($result->isSuccessful)->toBeTrue()
        ->and($result->diagnostics->warnings[0]->code ?? null)->toBe(DiagnosticCode::PreviousBuildBackupCouldNotBeRemoved)
        ->and(file_get_contents($root . '/build/ppphp/One.php'))->toContain('return 2;')
        ->and(glob($root . '/build/.ppphp-stage-*') ?: [])->toBe([]);

    foreach (glob($root . '/build/.ppphp-backup-*') ?: [] as $backup) {
        (new NativeBuildFilesystem())->remove($backup);
    }
});

test('a failed backup restoration reports the dedicated recovery diagnostic', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/One.ppphp', "<?php\nfunction restoreStageTen(): int { return 1; }\n");
    runStageTenCommand(['command' => 'build', '--working-directory' => $root]);
    $this->writeFile($root . '/src/One.ppphp', "<?php\nfunction restoreStageTen(): int { return 2; }\n");
    [$project, $selection] = loadStageTenCompilationInputs($root);
    $filesystem = new class extends NativeBuildFilesystem {
        private int $moves = 0;

        public function move(string $from, string $to): void
        {
            $this->moves++;

            if ($this->moves >= 2) {
                throw new RuntimeException('Injected commit and restoration failure.');
            }

            parent::move($from, $to);
        }
    };
    $result = (new Compiler(committer: new AtomicBuildCommitter(filesystem: $filesystem)))
        ->compile($project, $selection);
    $backups = glob($root . '/build/.ppphp-backup-*') ?: [];

    $stages = glob($root . '/build/.ppphp-stage-*') ?: [];

    expect($result->isSuccessful)->toBeFalse()
        ->and($result->diagnostics->errors[0]->code ?? null)->toBe(DiagnosticCode::PreviousBuildCouldNotBeRestored)
        ->and(file_exists($root . '/build/ppphp'))->toBeFalse()
        ->and($backups)->toHaveCount(1)
        ->and($stages)->toHaveCount(1);

    (new NativeBuildFilesystem())->move($backups[0], $root . '/build/ppphp');
    (new NativeBuildFilesystem())->remove($stages[0]);
});
