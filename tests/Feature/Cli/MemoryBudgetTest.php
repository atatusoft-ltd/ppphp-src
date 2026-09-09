<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Support\StageElevenProject;

test('cold and warm mixed Composer checks and builds respect the memory policy', function (string $firstCommand, bool $developmentTools): void {
    $root = $this->createTemporaryDirectory();
    $repository = dirname(__DIR__, 3);
    $this->writeConfiguration($root);
    $this->writeFile($root . '/composer.json', json_encode([
        'name' => 'example/memory-budget',
        'require' => ['symfony/console' => '^8.1'],
    ], JSON_THROW_ON_ERROR));
    $installed = json_decode(file_get_contents($repository . '/vendor/composer/installed.json'), true, flags: JSON_THROW_ON_ERROR);
    $packages = [];
    foreach ($installed['packages'] as $package) {
        if (!$developmentTools && !str_starts_with($package['name'], 'symfony/') && $package['name'] !== 'psr/container') {
            continue;
        }
        StageElevenProject::copyTree($repository . '/vendor/' . $package['name'], $root . '/vendor/' . $package['name']);
        $package['install-path'] = '../' . $package['name'];
        $packages[] = $package;
    }
    $this->writeFile($root . '/vendor/composer/installed.json', json_encode(['packages' => $packages], JSON_THROW_ON_ERROR));
    $this->writeFile($root . '/src/BuildCommand.ppphp', <<<'PHP'
<?php
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
final class BuildCommand extends Command {
    public function execute(InputInterface $input, OutputInterface $output): int {
        $output->writeln('Building project...');
        return Command::SUCCESS;
    }
}
PHP);
    $this->writeFile($root . '/src/index.php', "<?php\n// Ordinary PHP is copied unchanged.\n");
    $before = StageElevenProject::captureTree($root . '/src');

    // The large fixture includes the real installed Pest/PHPUnit classmaps and
    // autoload files. Small fixtures keep their explicit 128 MiB acceptance gate.
    $environment = ['PPPHP_COMPILER_MEMORY_LIMIT_MEGABYTES' => $developmentTools ? false : '128'];
    $editor = new Process([PHP_BINARY, '-d', 'memory_limit=128M', $repository . '/bin/ppphp', 'editor:diagnostics'], $root, $environment);
    $editor->setInput(json_encode(['version' => 1, 'document' => [
        'path' => 'src/BuildCommand.ppphp', 'contents' => $before['BuildCommand.ppphp'],
    ]], JSON_THROW_ON_ERROR));
    $editor->run();
    expect($editor->getExitCode())->toBe(0, $editor->getOutput() . $editor->getErrorOutput())
        ->and(is_dir($root . '/.ppphp-cache'))->toBeFalse();

    $commands = $firstCommand === 'check' ? ['check', 'check', 'build', 'build'] : ['build', 'build', 'check', 'check'];
    foreach ($commands as $command) {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=128M', $repository . '/bin/ppphp', $command, '--format=json'], $root, $environment);
        $process->setTimeout(120);
        $process->run();
        expect($process->getExitCode())->toBe(0, $process->getOutput() . $process->getErrorOutput());
        $response = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        expect($response['summary']['errors'])->toBe(0);
    }

    expect(StageElevenProject::captureTree($root . '/src'))->toBe($before)
        ->and(file_get_contents($root . '/build/ppphp/index.php'))->toBe($before['index.php']);
    $lint = new Process([PHP_BINARY, '-n', '-l', $root . '/build/ppphp/BuildCommand.php']);
    $lint->run();
    expect($lint->getExitCode())->toBe(0, $lint->getOutput() . $lint->getErrorOutput());

    $built = StageElevenProject::captureTree($root . '/build/ppphp');
    $this->writeFile($root . '/src/BuildCommand.ppphp', str_replace('return Command::SUCCESS;', "return 'wrong';", $before['BuildCommand.ppphp']));
    foreach (['check', 'build'] as $command) {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=128M', $repository . '/bin/ppphp', $command, '--format=json'], $root, $environment);
        $process->run();
        expect($process->getExitCode())->toBe(1, $process->getOutput() . $process->getErrorOutput());
        $response = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        expect($response['summary']['errors'])->toBeGreaterThan(0);
    }
    expect(StageElevenProject::captureTree($root . '/build/ppphp'))->toBe($built);
})->with([
    'small check first at 128 MiB' => ['check', false],
    'small build first at 128 MiB' => ['build', false],
    'installed development tools at the compiler default' => ['check', true],
]);
