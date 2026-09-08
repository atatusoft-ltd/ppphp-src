<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Compiler\Compiler;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

const STAGE_ELEVEN_OUTPUT = "Ada:36|php:100|Ada|php,ppphp|legacy-file|generated-file|verified\n";

function failStageElevenVerification(string $message): never
{
    fwrite(STDERR, "Mixed application verification failed: {$message}\n");
    exit(1);
}

/** @param list<string> $command */
function runStageElevenProcess(array $command, string $workingDirectory): Process
{
    $process = new Process($command, $workingDirectory);
    $process->setTimeout(120);
    $process->run();

    if ($process->getExitCode() !== 0) {
        failStageElevenVerification(sprintf(
            "command exited with %s.\nCommand: %s\nSTDOUT:\n%s\nSTDERR:\n%s",
            (string) $process->getExitCode(),
            $process->getCommandLine(),
            $process->getOutput(),
            $process->getErrorOutput(),
        ));
    }

    return $process;
}

function assertStageEleven(bool $condition, string $message): void
{
    if (!$condition) {
        failStageElevenVerification($message);
    }
}

/** @param list<string> $excluded */
function copyStageElevenDirectory(string $source, string $target, array $excluded = []): void
{
    if (!is_dir($target) && !mkdir($target, 0777, true) && !is_dir($target)) {
        failStageElevenVerification(sprintf('could not create directory "%s".', $target));
    }

    foreach (new DirectoryIterator($source) as $entry) {
        if ($entry->isDot() || in_array($entry->getFilename(), $excluded, true)) {
            continue;
        }

        $destination = $target . '/' . $entry->getFilename();

        if ($entry->isDir() && !$entry->isLink()) {
            copyStageElevenDirectory($entry->getPathname(), $destination, $excluded);
            continue;
        }

        if ($entry->isLink() || !copy($entry->getPathname(), $destination)) {
            failStageElevenVerification(sprintf('could not copy "%s".', $entry->getPathname()));
        }

        chmod($destination, $entry->getPerms() & 0777);
    }
}

function removeStageElevenPath(string $path): void
{
    if (is_link($path) || is_file($path)) {
        if (!unlink($path)) {
            failStageElevenVerification(sprintf('could not remove "%s".', $path));
        }

        return;
    }

    if (!is_dir($path)) {
        return;
    }

    foreach (new DirectoryIterator($path) as $entry) {
        if (!$entry->isDot()) {
            removeStageElevenPath($entry->getPathname());
        }
    }

    if (!rmdir($path)) {
        failStageElevenVerification(sprintf('could not remove directory "%s".', $path));
    }
}

/** @return array<string, mixed> */
function decodeStageElevenJson(string $path): array
{
    $contents = file_get_contents($path);

    if ($contents === false) {
        failStageElevenVerification(sprintf('could not read JSON file "%s".', $path));
    }

    try {
        $document = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        failStageElevenVerification(sprintf('invalid JSON in "%s": %s', $path, $exception->getMessage()));
    }

    if (!is_array($document)) {
        failStageElevenVerification(sprintf('JSON file "%s" is not an object.', $path));
    }

    return $document;
}

function verifyStageElevenRuntime(Process $process, string $expected, string $label): void
{
    assertStageEleven($process->getOutput() === $expected, sprintf('%s stdout did not match.', $label));
    assertStageEleven($process->getErrorOutput() === '', sprintf('%s wrote to stderr.', $label));
}

/** @param array<string, mixed> $manifest */
function verifyStageElevenArtifacts(string $project, array $manifest): void
{
    assertStageEleven(($manifest['completeProject'] ?? null) === true, 'manifest is not complete.');
    $files = $manifest['files'] ?? null;
    assertStageEleven(is_array($files) && count($files) === 12, 'manifest does not contain all 12 project sources.');
    $operations = [];

    foreach ($files as $entry) {
        assertStageEleven(is_array($entry), 'manifest contains an invalid entry.');
        $source = $project . '/' . ($entry['source'] ?? '');
        $output = $project . '/build/ppphp/' . ($entry['output'] ?? '');
        $map = $project . '/build/ppphp/' . ($entry['sourceMap'] ?? '');
        assertStageEleven(is_file($source), sprintf('manifest source "%s" is missing.', $source));
        assertStageEleven(is_file($output), sprintf('manifest output "%s" is missing.', $output));
        assertStageEleven(is_file($map), sprintf('source map "%s" is missing.', $map));
        assertStageEleven('sha256:' . hash_file('sha256', $source) === ($entry['sourceHash'] ?? null), 'source hash mismatch.');
        assertStageEleven('sha256:' . hash_file('sha256', $output) === ($entry['outputHash'] ?? null), 'output hash mismatch.');
        $mapDocument = decodeStageElevenJson($map);
        assertStageEleven(($mapDocument['sourceHash'] ?? null) === $entry['sourceHash'], 'source-map source hash mismatch.');
        assertStageEleven(($mapDocument['generatedHash'] ?? null) === $entry['outputHash'], 'source-map output hash mismatch.');
        $operation = $entry['operation'] ?? '';
        $operations[$operation] = true;

        if ($operation === 'copy') {
            assertStageEleven(file_get_contents($source) === file_get_contents($output), 'ordinary PHP copy is not byte-identical.');
            $segments = $mapDocument['segments'] ?? [];
            assertStageEleven(is_array($segments) && count($segments) === 1, 'copied PHP does not use an identity map.');
        } elseif ($operation === 'compile') {
            $generated = (string) file_get_contents($output);
            assertStageEleven(str_contains($generated, 'declare(strict_types=1);'), 'generated ++PHP is not strict.');
            assertStageEleven(!str_contains($generated, ' throws '), 'generated output retains a throws clause.');
            assertStageEleven(!str_contains($generated, 'when ('), 'generated output retains a when expression.');
            verifyStageElevenRuntime(
                runStageElevenProcess([PHP_BINARY, '-l', $output], $project),
                "No syntax errors detected in {$output}\n",
                'PHP lint',
            );
        } else {
            failStageElevenVerification('manifest contains an unknown operation.');
        }
    }

    assertStageEleven(isset($operations['compile'], $operations['copy']), 'manifest does not contain both operation kinds.');
    $sources = array_column($files, 'source');
    assertStageEleven(!in_array('stubs/LegacyGateway.stub.php', $sources, true), 'stub was included in compiler output.');
    assertStageEleven(!in_array('public/index.php', $sources, true), 'public entry point was included in compiler output.');
    assertStageEleven(str_contains((string) file_get_contents($project . '/build/ppphp/Domain/Box.php'), '@template T'), 'generic metadata is missing.');
    assertStageEleven(str_contains((string) file_get_contents($project . '/build/ppphp/Service/PersonService.php'), '@throws'), 'checked-error metadata is missing.');
    assertStageEleven(str_contains((string) file_get_contents($project . '/build/ppphp/Service/PersonService.php'), 'list<string>'), 'typed-list metadata is missing.');
    assertStageEleven(str_contains((string) file_get_contents($project . '/build/ppphp/Service/PersonService.php'), 'array<string, int>'), 'typed-map metadata is missing.');
    assertStageEleven(str_contains((string) file_get_contents($project . '/build/ppphp/console.php'), "__DIR__ . '/../../vendor/autoload.php'"), 'Composer bootstrap was not relocated.');
}

function verifyStageElevenComposerProjection(string $project): void
{
    $composer = decodeStageElevenJson($project . '/composer.json');
    $lock = decodeStageElevenJson($project . '/composer.lock');
    $runtimePsr4 = $composer['autoload']['psr-4']['Example\\Mixed\\'] ?? null;
    assertStageEleven($runtimePsr4 === ['build/ppphp/'], 'runtime PSR-4 mapping is not the single generated root.');
    assertStageEleven(
        ($composer['autoload']['files'] ?? null) === [
            'build/ppphp/Support/functions.php',
            'build/ppphp/Support/generated_functions.php',
        ],
        'runtime files mappings do not target generated output.',
    );
    $source = $composer['extra']['ppphp']['source-autoload'] ?? null;
    assertStageEleven(
        is_array($source)
        && ($source['psr-4']['Example\\Mixed\\'] ?? null) === ['src/', 'legacy/']
        && ($source['files'] ?? null) === [
            'legacy/Support/functions.php',
            'src/Support/generated_functions.ppphp',
        ],
        'source Composer metadata was not preserved.',
    );
    $compilerPackages = [Compiler::COMPOSER_PACKAGE, 'atatusoft-ltd/ppphp-src'];
    foreach ($compilerPackages as $compilerPackage) {
        assertStageEleven(
            !isset(($composer['require'] ?? [])[$compilerPackage])
            && !isset(($composer['require-dev'] ?? [])[$compilerPackage]),
            'application runtime metadata depends on the ++PHP compiler.',
        );
    }
    foreach ([...($lock['packages'] ?? []), ...($lock['packages-dev'] ?? [])] as $package) {
        assertStageEleven(
            !is_array($package) || !in_array($package['name'] ?? null, $compilerPackages, true),
            'application lock metadata contains the ++PHP compiler.',
        );
    }

    foreach (['autoload_psr4.php', 'autoload_files.php', 'autoload_static.php'] as $file) {
        $contents = (string) file_get_contents($project . '/vendor/composer/' . $file);
        assertStageEleven(!str_contains($contents, "'/src/"), sprintf('%s retains a source fallback.', $file));
        assertStageEleven(!str_contains($contents, "'/legacy/"), sprintf('%s retains a legacy fallback.', $file));
    }
}

function verifyStageElevenClassmap(string $project): void
{
    $classmap = (string) file_get_contents($project . '/vendor/composer/autoload_classmap.php');
    assertStageEleven(
        str_contains($classmap, 'Example\\\\Mixed\\\\Application'),
        'optimized classmap does not contain a compiled ++PHP class.',
    );
    assertStageEleven(
        str_contains($classmap, 'Example\\\\Mixed\\\\Infrastructure\\\\LegacyGateway'),
        'optimized classmap does not contain a copied PHP class.',
    );
}

$repository = dirname(__DIR__, 2);
$example = $repository . '/examples/mixed-application';
$composerOverride = getenv('COMPOSER_BINARY');
$composer = is_string($composerOverride) && $composerOverride !== ''
    ? $composerOverride
    : (new ExecutableFinder())->find('composer');

if (!is_string($composer) || $composer === '') {
    failStageElevenVerification('Composer is required; set COMPOSER_BINARY or add composer to PATH.');
}

$project = sys_get_temp_dir() . '/ppphp-stage-eleven-' . bin2hex(random_bytes(8));
$deployment = sys_get_temp_dir() . '/ppphp-stage-eleven-deployment-' . bin2hex(random_bytes(8));

try {
    copyStageElevenDirectory($example, $project, ['vendor', 'build', '.ppphp-cache']);
    runStageElevenProcess([
        $composer,
        'install',
        '--working-dir=' . $project,
        '--no-interaction',
        '--no-progress',
        '--no-scripts',
    ], $repository);

    $originalComposer = (string) file_get_contents($project . '/composer.json');
    runStageElevenProcess([
        PHP_BINARY,
        $repository . '/bin/ppphp',
        'composer:configure',
        '--working-directory=' . $project,
        '--dry-run',
    ], $repository);
    assertStageEleven(file_get_contents($project . '/composer.json') === $originalComposer, 'dry run modified composer.json.');

    runStageElevenProcess([
        PHP_BINARY,
        $repository . '/bin/ppphp',
        'composer:configure',
        '--working-directory=' . $project,
    ], $repository);
    $projectedComposer = (string) file_get_contents($project . '/composer.json');
    runStageElevenProcess([
        PHP_BINARY,
        $repository . '/bin/ppphp',
        'composer:configure',
        '--working-directory=' . $project,
    ], $repository);
    assertStageEleven(file_get_contents($project . '/composer.json') === $projectedComposer, 'Composer projection is not idempotent.');

    runStageElevenProcess([
        PHP_BINARY,
        $repository . '/bin/ppphp',
        'check',
        '--working-directory=' . $project,
    ], $repository);
    runStageElevenProcess([
        PHP_BINARY,
        $repository . '/bin/ppphp',
        'build',
        '--working-directory=' . $project,
    ], $repository);
    runStageElevenProcess([
        $composer,
        'update',
        '--lock',
        '--working-dir=' . $project,
        '--no-interaction',
        '--no-progress',
        '--no-scripts',
    ], $repository);
    runStageElevenProcess([
        $composer,
        'dump-autoload',
        '--working-dir=' . $project,
        '--no-interaction',
    ], $repository);

    verifyStageElevenComposerProjection($project);
    $manifest = decodeStageElevenJson($project . '/build/ppphp/.ppphp/manifest.json');
    verifyStageElevenArtifacts($project, $manifest);
    verifyStageElevenRuntime(
        runStageElevenProcess([PHP_BINARY, $project . '/public/index.php'], $project),
        STAGE_ELEVEN_OUTPUT,
        'normal public runtime',
    );
    verifyStageElevenRuntime(
        runStageElevenProcess([PHP_BINARY, $project . '/build/ppphp/console.php'], $project),
        'CLI|' . STAGE_ELEVEN_OUTPUT,
        'normal CLI runtime',
    );

    runStageElevenProcess([
        $composer,
        'dump-autoload',
        '--working-dir=' . $project,
        '--optimize',
        '--no-interaction',
    ], $repository);
    verifyStageElevenClassmap($project);
    verifyStageElevenRuntime(
        runStageElevenProcess([PHP_BINARY, $project . '/public/index.php'], $project),
        STAGE_ELEVEN_OUTPUT,
        'optimized public runtime',
    );

    runStageElevenProcess([
        $composer,
        'dump-autoload',
        '--working-dir=' . $project,
        '--classmap-authoritative',
        '--no-interaction',
    ], $repository);
    verifyStageElevenClassmap($project);
    verifyStageElevenRuntime(
        runStageElevenProcess([PHP_BINARY, $project . '/public/index.php'], $project),
        STAGE_ELEVEN_OUTPUT,
        'authoritative public runtime',
    );

    if (!mkdir($deployment, 0777, true) && !is_dir($deployment)) {
        failStageElevenVerification('could not create deployment directory.');
    }
    foreach (['composer.json', 'composer.lock'] as $file) {
        if (!copy($project . '/' . $file, $deployment . '/' . $file)) {
            failStageElevenVerification(sprintf('could not copy deployment %s.', $file));
        }
    }
    copyStageElevenDirectory($project . '/vendor', $deployment . '/vendor');
    copyStageElevenDirectory($project . '/public', $deployment . '/public');
    copyStageElevenDirectory($project . '/build', $deployment . '/build');
    assertStageEleven(!is_dir($deployment . '/src'), 'deployment contains ++PHP source.');
    assertStageEleven(!is_dir($deployment . '/legacy'), 'deployment contains ordinary source.');
    assertStageEleven(!is_dir($deployment . '/stubs'), 'deployment contains stubs.');
    assertStageEleven(!is_dir($deployment . '/.ppphp-cache'), 'deployment contains compiler cache.');
    $deploymentFiles = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($deployment, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($deploymentFiles as $deploymentFile) {
        assertStageEleven(
            !$deploymentFile->isFile() || !str_ends_with(strtolower($deploymentFile->getFilename()), '.ppphp'),
            'deployment contains ++PHP source.',
        );
    }
    verifyStageElevenRuntime(
        runStageElevenProcess([PHP_BINARY, $deployment . '/public/index.php'], $deployment),
        STAGE_ELEVEN_OUTPUT,
        'source-free public runtime',
    );
    verifyStageElevenRuntime(
        runStageElevenProcess([PHP_BINARY, $deployment . '/build/ppphp/console.php'], $deployment),
        'CLI|' . STAGE_ELEVEN_OUTPUT,
        'source-free CLI runtime',
    );
} finally {
    removeStageElevenPath($project);
    removeStageElevenPath($deployment);
}

fwrite(STDOUT, "Mixed application interoperability verified.\n");
