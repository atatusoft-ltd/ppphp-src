#!/usr/bin/env php
<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Compiler\Compiler;
use Atatusoft\Ppphp\Support\CanonicalJson;
use Atatusoft\Ppphp\Support\Path;
use Atatusoft\Ppphp\Versioning\ReleaseMetadataLoader;
use Symfony\Component\Process\Process;

require dirname(__DIR__) . '/vendor/autoload.php';

$sourceRoot = Path::normalize(dirname(__DIR__));
$temporaryRoot = Path::join(sys_get_temp_dir(), 'ppphp-distribution-' . bin2hex(random_bytes(10)));
$package = Path::join($temporaryRoot, 'package');
$consumer = Path::join($temporaryRoot, 'consumer');
$composerHome = Path::join($temporaryRoot, 'composer-home');
$composerCache = Path::join($temporaryRoot, 'composer-cache');
$exitCode = 0;

function distributionWrite(string $path, string $contents): void
{
    $parent = dirname($path);

    if ((!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) || file_put_contents($path, $contents) === false) {
        throw new RuntimeException(sprintf('Could not write distribution fixture %s.', $path));
    }
}

/** @param list<string> $command */
function distributionRun(array $command, string $workingDirectory, array $environment = []): string
{
    $process = new Process($command, $workingDirectory, $environment, timeout: 240.0);
    $process->run();

    if (!$process->isSuccessful()) {
        throw new RuntimeException(sprintf(
            "Command failed (%s):\n%s%s",
            implode(' ', $command),
            $process->getOutput(),
            $process->getErrorOutput(),
        ));
    }

    return $process->getOutput();
}

function distributionRemoveTree(string $path, string $ownedRoot): void
{
    if (!Path::contains($ownedRoot, $path)) {
        return;
    }

    if (is_link($path)) {
        if (!unlink($path)) {
            throw new RuntimeException(sprintf('Could not remove temporary link %s.', $path));
        }

        return;
    }

    if (!file_exists($path)) {
        return;
    }

    if (is_dir($path)) {
        foreach (new FilesystemIterator($path) as $entry) {
            distributionRemoveTree(Path::normalize($entry->getPathname()), $ownedRoot);
        }

        if (!rmdir($path)) {
            throw new RuntimeException(sprintf('Could not remove temporary directory %s.', $path));
        }

        return;
    }

    if (!unlink($path)) {
        throw new RuntimeException(sprintf('Could not remove temporary file %s.', $path));
    }
}

try {
    $metadata = (new ReleaseMetadataLoader($sourceRoot))->load()
        ?? throw new RuntimeException('Release metadata is missing.');
    foreach ([$temporaryRoot, $package, $consumer, $composerHome, $composerCache, Path::join($consumer, 'src')] as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Could not create temporary directory %s.', $directory));
        }
    }

    $environment = [
        'COMPOSER_CACHE_DIR' => $composerCache,
        'COMPOSER_HOME' => $composerHome,
        'COMPOSER_NO_INTERACTION' => '1',
    ];
    $packageFiles = distributionRun(
        ['git', 'ls-files', '-z', '--cached', '--others', '--exclude-standard'],
        $sourceRoot,
        $environment,
    );

    foreach (array_filter(explode("\0", $packageFiles), static fn (string $path): bool => $path !== '') as $relativePath) {
        $relativePath = Path::normalize($relativePath);
        $sourcePath = Path::join($sourceRoot, $relativePath);
        $packagePath = Path::join($package, $relativePath);

        if (
            $relativePath === '.'
            || str_starts_with($relativePath, '../')
            || !Path::contains($sourceRoot, $sourcePath)
            || !Path::contains($package, $packagePath)
            || !is_file($sourcePath)
            || is_link($sourcePath)
        ) {
            throw new RuntimeException(sprintf('Package candidate contains unsafe source path %s.', $relativePath));
        }

        $parent = dirname($packagePath);

        if ((!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) || !copy($sourcePath, $packagePath)) {
            throw new RuntimeException(sprintf('Could not stage package source %s.', $relativePath));
        }
    }

    $consumerComposer = [
        'autoload' => ['psr-4' => ['Consumer\\' => 'src/']],
        'license' => 'proprietary',
        'name' => 'ppphp/release-consumer',
        'repositories' => [[
            'options' => [
                'symlink' => false,
                'versions' => ['atatusoft-ltd/ppphp-src' => Compiler::VERSION],
            ],
            'type' => 'path',
            'url' => $package,
        ]],
        'require' => ['php' => '^8.4'],
        'type' => 'project',
    ];
    distributionWrite(Path::join($consumer, 'composer.json'), CanonicalJson::encode($consumerComposer));
    distributionRun(
        ['composer', 'require', '--dev', 'atatusoft-ltd/ppphp-src:' . Compiler::VERSION, '--no-scripts', '--no-progress', '--no-ansi'],
        $consumer,
        $environment,
    );

    $installedPackage = Path::join($consumer, 'vendor/atatusoft-ltd/ppphp-src');
    $installedRealPath = realpath($installedPackage);

    if (
        !is_dir($installedPackage)
        || is_link($installedPackage)
        || $installedRealPath === false
        || Path::buildComparisonKey(Path::normalize($installedRealPath)) === Path::buildComparisonKey($package)
    ) {
        throw new RuntimeException('Composer did not install an isolated package copy.');
    }

    foreach ([
        'bin/ppphp',
        'ppphp.json.dist',
        'resources/release/manifest.json',
        'resources/schema/ppphp.schema.json',
        'resources/php-signatures/8.4/manifest.json',
        $metadata->releaseNotes,
        'THIRD_PARTY_NOTICES.md',
    ] as $relativePath) {
        if (!is_file(Path::join($installedPackage, $relativePath))) {
            throw new RuntimeException(sprintf('Installed package is missing %s.', $relativePath));
        }
    }

    if (is_dir(Path::join($consumer, 'vendor/pestphp'))) {
        throw new RuntimeException('A package development dependency leaked into the consumer installation.');
    }

    $compiler = Path::join($consumer, 'vendor/bin/ppphp');
    $compilerCommand = static fn (string ...$arguments): array => [
        PHP_BINARY,
        '-d',
        'memory_limit=512M',
        $compiler,
        ...$arguments,
    ];
    $versionOutput = distributionRun($compilerCommand('--version'), $consumer, $environment);

    if (!str_contains($versionOutput, Compiler::VERSION)) {
        throw new RuntimeException('Installed compiler proxy does not report the exact release version.');
    }

    distributionRun($compilerCommand('init'), $consumer, $environment);
    $configuration = CanonicalJson::decode((string) file_get_contents(Path::join($consumer, 'ppphp.json')));
    $schemaUrl = is_array($configuration) ? ($configuration['$schema'] ?? null) : null;

    if ($schemaUrl !== $metadata->schemaUrl) {
        throw new RuntimeException('Installed compiler init did not write the immutable release schema identity.');
    }

    distributionWrite(Path::join($consumer, 'src/Greeter.php'), <<<'PHP'
<?php

declare(strict_types=1);

namespace Consumer;

final class Greeter
{
    public function greet(string $name): string
    {
        return 'Hello, ' . $name;
    }
}
PHP
    );
    distributionWrite(Path::join($consumer, 'src/index.ppphp'), <<<'PHP'
#!/usr/bin/env php
<?php

use Consumer\Greeter;

require_once __DIR__ . '/vendor/autoload.php';

Greeter $greeter = new Greeter();
echo $greeter->greet('World'), "\n";
PHP
    );

    distributionRun($compilerCommand('composer:configure', '--dry-run'), $consumer, $environment);
    distributionRun($compilerCommand('composer:configure'), $consumer, $environment);
    distributionRun(['composer', 'update', '--lock', '--no-scripts', '--no-progress', '--no-ansi'], $consumer, $environment);
    distributionRun(['composer', 'dump-autoload', '--optimize', '--strict-psr', '--no-ansi'], $consumer, $environment);
    distributionRun($compilerCommand('check'), $consumer, $environment);
    distributionRun($compilerCommand('build'), $consumer, $environment);

    $buildRoot = Path::join($consumer, 'build/ppphp');

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($buildRoot, FilesystemIterator::SKIP_DOTS)) as $entry) {
        if ($entry instanceof SplFileInfo && $entry->isFile() && strtolower($entry->getExtension()) === 'php') {
            distributionRun([PHP_BINARY, '-l', $entry->getPathname()], $consumer, $environment);
        }
    }

    $entrypoint = Path::join($buildRoot, 'index.php');
    $runtimeOutput = distributionRun([PHP_BINARY, $entrypoint], $consumer, $environment);

    if ($runtimeOutput !== "Hello, World\n") {
        throw new RuntimeException('Generated consumer application produced unexpected output.');
    }

    distributionRemoveTree(Path::join($consumer, 'src'), $temporaryRoot);

    if (distributionRun([PHP_BINARY, $entrypoint], $consumer, $environment) !== "Hello, World\n") {
        throw new RuntimeException('Generated application is not source-free at runtime.');
    }

    distributionRun($compilerCommand('clean', '--dry-run'), $consumer, $environment);
    distributionRun($compilerCommand('clean'), $consumer, $environment);

    if (file_exists($buildRoot) || file_exists(Path::join($consumer, '.ppphp-cache'))) {
        throw new RuntimeException('Installed compiler clean left compiler-owned state behind.');
    }

    fwrite(STDOUT, sprintf("Verified installed ++PHP %s distribution and source-free runtime.\n", Compiler::VERSION));
} catch (Throwable $exception) {
    fwrite(STDERR, 'Distribution verification failed: ' . $exception->getMessage() . "\n");
    $exitCode = 1;
} finally {
    if (file_exists($temporaryRoot)) {
        distributionRemoveTree($temporaryRoot, $temporaryRoot);
    }
}

exit($exitCode);
