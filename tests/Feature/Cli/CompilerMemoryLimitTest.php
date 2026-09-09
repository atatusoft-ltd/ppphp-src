<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Cli\CompilerMemoryLimit;
use Symfony\Component\Process\Process;

test('native memory policy has explicit reusable precedence without changing embedded runtimes', function (
    string $hostLimit,
    string|false $setting,
    string $mode,
    string $expected,
): void {
    $root = $this->createTemporaryDirectory();
    $autoload = var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true);
    $this->writeFile($root . '/probe.php', '<?php require ' . $autoload . ';' . <<<'PHP'
$application = new \Atatusoft\Ppphp\Cli\Application(configureMemoryLimit: $argv[1] !== 'embedded');
$application->setAutoExit(false);
$name = $argv[1] === 'browser' ? 'browser:analysis' : 'memory:probe';
$application->addCommand((new \Symfony\Component\Console\Command\Command($name))->setCode(
    function (\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output): int {
        $output->write(ini_get('memory_limit'));
        return 0;
    }
));
exit($application->run(new \Symfony\Component\Console\Input\ArrayInput(['command' => $name])));
PHP);
    $process = new Process([PHP_BINARY, '-d', 'memory_limit=' . $hostLimit, $root . '/probe.php', $mode], $root, [
        CompilerMemoryLimit::ENVIRONMENT_VARIABLE => $setting,
    ]);
    $process->run();
    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toBe($expected)
        ->and($process->getErrorOutput())->toBe('');
})->with([
    'native default' => ['128M', false, 'native', '512M'],
    'higher host limit' => ['1G', false, 'native', '1G'],
    'unlimited host' => ['-1', false, 'native', '-1'],
    'explicit low memory regression' => ['128M', '128', 'native', '128M'],
    'editor setting below default' => ['1G', '256', 'native', '256M'],
    'persistent terminal setting' => ['128M', '768', 'native', '768M'],
    'embedding' => ['128M', '512', 'embedded', '128M'],
    'browser owns its allowance' => ['128M', 'invalid', 'browser', '128M'],
]);

test('invalid memory settings fail before project analysis with a useful diagnostic', function (string $setting): void {
    $root = $this->createTemporaryDirectory();
    $process = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'check', '--format=json'], $root, [
        CompilerMemoryLimit::ENVIRONMENT_VARIABLE => $setting,
    ]);
    $process->run();
    expect($process->getExitCode())->toBe(2, $process->getOutput() . $process->getErrorOutput());
    $response = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($response['diagnostics'][0]['code'])->toBe('P0022')
        ->and($response['diagnostics'][0]['message'])->toContain(CompilerMemoryLimit::ENVIRONMENT_VARIABLE, 'whole number of MiB')
        ->and($process->getErrorOutput())->toBe('')
        ->and(is_dir($root . '/.ppphp-cache'))->toBeFalse();
})->with(['', '0', '-1', '512M', '1.5', ' 512', '2147483648', '999999999999999999999999999']);
