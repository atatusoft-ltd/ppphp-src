<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Cli\Application;
use Atatusoft\Ppphp\Cli\Command\InitCommand;
use Atatusoft\Ppphp\Cli\Enumerations\ExitCode;
use Atatusoft\Ppphp\Config\ProjectConfigLoader;
use Atatusoft\Ppphp\Diagnostics\ConsoleRenderer;
use Atatusoft\Ppphp\Diagnostics\JsonRenderer;
use Atatusoft\Ppphp\Versioning\ReleaseMetadataLoader;
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\ApplicationTester;

function runStageOneCommand(array $input): ApplicationTester
{
    $application = new Application();
    $application->setAutoExit(false);
    $tester = new ApplicationTester($application);
    $tester->run(['--no-ansi' => true, ...$input]);

    return $tester;
}

function runStageOneIsolatedInit(string $root, string $manifestPath): ApplicationTester
{
    $repository = dirname(__DIR__, 3);
    $application = new SymfonyApplication('ppphp');
    $application->setAutoExit(false);
    $application->addCommand(new InitCommand(
        new ProjectConfigLoader(),
        new ConsoleRenderer(),
        new JsonRenderer(),
        $repository . '/ppphp.json.dist',
        new ReleaseMetadataLoader($repository, $manifestPath),
    ));
    $tester = new ApplicationTester($application);
    $tester->run([
        'command' => 'init',
        '--working-directory' => $root,
        '--no-interaction' => true,
        '--no-ansi' => true,
    ]);

    return $tester;
}

test('init creates a valid configuration and compiler-owned directories without source files', function (): void {
    $root = $this->createTemporaryDirectory();
    $tester = runStageOneCommand([
        'command' => 'init',
        '--working-directory' => $root,
        '--no-interaction' => true,
    ]);
    $configuration = json_decode(
        (string) file_get_contents($root . '/ppphp.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($tester->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($tester->getDisplay())->toContain('Created ppphp.json.')
        ->and(array_key_first($configuration))->toBe('$schema')
        ->and($configuration['$schema'])->toBe((new ReleaseMetadataLoader())->load()?->schemaUrl)
        ->and($configuration['targetPhpVersion'])->toBe('8.4')
        ->and(is_dir($root . '/build/ppphp'))->toBeTrue()
        ->and(is_dir($root . '/.ppphp-cache'))->toBeTrue()
        ->and(is_dir($root . '/stubs'))->toBeTrue()
        ->and(file_exists($root . '/src'))->toBeFalse();
});

test('init omits schema identity without release metadata and fails closed on corrupt metadata', function (): void {
    $container = $this->createTemporaryDirectory();
    $development = $container . '/development';
    $corrupt = $container . '/corrupt';
    $this->createDirectory($development);
    $this->createDirectory($corrupt);
    $missingManifest = $container . '/missing-manifest.json';
    $developmentResult = runStageOneIsolatedInit($development, $missingManifest);
    $developmentConfiguration = json_decode(
        (string) file_get_contents($development . '/ppphp.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($developmentResult->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($developmentConfiguration)->not->toHaveKey('$schema');

    $invalidManifest = $container . '/invalid-manifest.json';
    $this->writeFile($invalidManifest, "{}\n");
    $corruptResult = runStageOneIsolatedInit($corrupt, $invalidManifest);

    expect($corruptResult->getStatusCode())->toBe(ExitCode::InvalidProject->value)
        ->and($corruptResult->getDisplay())->toContain('Error[P0021]: Project Initialization Failed')
        ->and(file_exists($corrupt . '/ppphp.json'))->toBeFalse();
});

test('init honors Composer platform settings before writing project files', function (string $platform, int $exit): void {
    $root = $this->createTemporaryDirectory();
    $metadata = json_encode(['config' => ['platform' => ['php' => $platform]]]);
    $this->writeFile($root . '/composer.json', $metadata);
    $tester = runStageOneCommand(['command' => 'init', '--working-directory' => $root, '--no-interaction' => true]);
    expect($tester->getStatusCode())->toBe($exit)
        ->and(file_get_contents($root . '/composer.json'))->toBe($metadata)
        ->and(file_exists($root . '/ppphp.json'))->toBe($exit === 0)
        ->and(is_dir($root . '/build/ppphp'))->toBe($exit === 0);
    if ($exit === 0) {
        expect((new ProjectConfigLoader())->load($root)->configuration?->targetPhpVersion)->toBe('8.4');
    } else {
        expect($tester->getDisplay())->toContain('config.platform.php', $platform);
    }
})->with([['8.4.23', 0], ['99.1.0', 2]]);

test('init refuses overwrite unless force is supplied and never prompts', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeFile($root . '/ppphp.json', "sentinel\n");
    $refused = runStageOneCommand([
        'command' => 'init',
        '--working-directory' => $root,
        '--no-interaction' => true,
    ]);

    expect($refused->getStatusCode())->toBe(ExitCode::InvalidProject->value)
        ->and($refused->getDisplay())->toContain('Error[P0009]: Project Configuration Already Exists')
        ->and(file_get_contents($root . '/ppphp.json'))->toBe("sentinel\n");

    $forced = runStageOneCommand([
        'command' => 'init',
        '--working-directory' => $root,
        '--force' => true,
        '--no-interaction' => true,
    ]);

    expect($forced->getStatusCode())->toBe(ExitCode::Success->value)
        ->and(json_decode((string) file_get_contents($root . '/ppphp.json'), true))->toBeArray();
});

test('init refuses configuration and owned-directory symlinks', function (string $linkPath): void {
    $container = $this->createTemporaryDirectory();
    $root = $container . '/project';
    $target = $container . '/outside';
    $this->createDirectory($root);
    $this->createDirectory($target);

    if ($linkPath === 'ppphp.json') {
        $this->writeFile($target . '/configuration.json', "preserved\n");
        symlink($target . '/configuration.json', $root . '/ppphp.json');
    } else {
        symlink($target, $root . '/.ppphp-cache');
    }

    $tester = runStageOneCommand([
        'command' => 'init',
        '--working-directory' => $root,
        '--force' => true,
        '--no-interaction' => true,
    ]);

    expect($tester->getStatusCode())->toBe(ExitCode::InvalidProject->value)
        ->and($tester->getDisplay())->toContain('Error[P0008]: Unsafe Project Path');

    if ($linkPath === 'ppphp.json') {
        expect(file_get_contents($target . '/configuration.json'))->toBe("preserved\n");
    } else {
        expect(is_dir($target))->toBeTrue();
    }
})->with(['ppphp.json', '.ppphp-cache']);

test('clean removes only configured output and cache directories', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.php', '<?php');
    $this->writeFile($root . '/build/ppphp/nested/output.php', '<?php');
    $this->writeFile($root . '/.ppphp-cache/cache.bin', 'cache');
    $this->writeFile($root . '/stubs/library.stub.php', '<?php');
    $this->writeFile($root . '/keep.txt', 'keep');

    $tester = runStageOneCommand([
        'command' => 'clean',
        '--working-directory' => $root,
        '--no-interaction' => true,
    ]);

    expect($tester->getStatusCode())->toBe(ExitCode::Success->value)
        ->and(file_exists($root . '/build/ppphp'))->toBeFalse()
        ->and(file_exists($root . '/.ppphp-cache'))->toBeFalse()
        ->and(file_exists($root . '/src/main.php'))->toBeTrue()
        ->and(file_exists($root . '/stubs/library.stub.php'))->toBeTrue()
        ->and(file_exists($root . '/ppphp.json'))->toBeTrue()
        ->and(file_exists($root . '/keep.txt'))->toBeTrue()
        ->and(glob($root . '/.*-cleanup-*') ?: [])->toBe([]);
});

test('clean accepts missing owned directories and dry-run preserves existing paths', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $missing = runStageOneCommand([
        'command' => 'clean',
        '--working-directory' => $root,
    ]);

    expect($missing->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($missing->getDisplay())->toContain('Nothing to clean.');

    $this->writeFile($root . '/build/ppphp/output.php', '<?php');
    $this->writeFile($root . '/.ppphp-cache/cache.bin', 'cache');
    $dryRun = runStageOneCommand([
        'command' => 'clean',
        '--working-directory' => $root,
        '--dry-run' => true,
    ]);

    expect($dryRun->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($dryRun->getDisplay())->toContain('Would remove build/ppphp.')
        ->and(file_exists($root . '/build/ppphp/output.php'))->toBeTrue()
        ->and(file_exists($root . '/.ppphp-cache/cache.bin'))->toBeTrue();
});

test('clean refuses project-root source-overlapping and outside owned paths', function (array $overrides, string $code): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root, $overrides);
    $tester = runStageOneCommand([
        'command' => 'clean',
        '--working-directory' => $root,
    ]);

    expect($tester->getStatusCode())->toBe(ExitCode::InvalidProject->value)
        ->and($tester->getDisplay())->toContain('Error[' . $code . ']');
})->with([
    'project root' => [['output' => '.'], 'P0008'],
    'source overlap' => [['cache' => 'src/cache'], 'P0013'],
    'outside root' => [['output' => '../output'], 'P0008'],
]);

test('clean unlinks an owned directory symlink without following its target', function (): void {
    $container = $this->createTemporaryDirectory();
    $root = $container . '/project';
    $target = $container . '/outside';
    $this->createDirectory($root);
    $this->writeConfiguration($root, ['output' => 'owned-link']);
    $this->writeFile($target . '/preserved.txt', 'preserved');
    symlink($target, $root . '/owned-link');

    $tester = runStageOneCommand([
        'command' => 'clean',
        '--working-directory' => $root,
    ]);

    expect($tester->getStatusCode())->toBe(ExitCode::Success->value)
        ->and(is_link($root . '/owned-link'))->toBeFalse()
        ->and(file_exists($target . '/preserved.txt'))->toBeTrue();
});

test('debug controls internal exception details at the CLI boundary', function (): void {
    $application = new Application();
    $application->setAutoExit(false);
    $application->addCommand(new class extends Command {
        public function __construct()
        {
            parent::__construct('explode');
        }

        protected function configure(): void
        {
            $this
                ->addOption('debug', null, InputOption::VALUE_NONE)
                ->addOption('format', null, InputOption::VALUE_REQUIRED, default: 'console');
        }

        protected function execute(InputInterface $input, OutputInterface $output): int
        {
            throw new RuntimeException('private failure detail');
        }
    });
    $tester = new ApplicationTester($application);
    $tester->run(['command' => 'explode', '--no-ansi' => true]);

    expect($tester->getStatusCode())->toBe(ExitCode::InternalCompilerFailure->value)
        ->and($tester->getDisplay())->toContain('Error[P9001]: Internal Compiler Error')
        ->and($tester->getDisplay())->not->toContain('private failure detail');

    $debugTester = new ApplicationTester($application);
    $debugTester->run(['command' => 'explode', '--debug' => true, '--no-ansi' => true]);

    expect($debugTester->getStatusCode())->toBe(ExitCode::InternalCompilerFailure->value)
        ->and($debugTester->getDisplay())->toContain('private failure detail')
        ->and($debugTester->getDisplay())->toContain(RuntimeException::class);
});
