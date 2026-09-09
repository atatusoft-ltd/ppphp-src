<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Cli\DiagnosticOutputWriter;
use Atatusoft\Ppphp\Cli\Enumerations\OutputFormat;
use Atatusoft\Ppphp\Compiler\Compiler;
use Atatusoft\Ppphp\Diagnostics\ConsoleRenderer;
use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Diagnostics\JsonRenderer;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

function runDiagnosticProcess(array $arguments, array $environment = []): Process
{
    $process = new Process(
        [PHP_BINARY, 'bin/ppphp', ...$arguments],
        dirname(__DIR__, 3),
        $environment === [] ? null : $environment,
    );
    $process->setInput(null);
    $process->setTimeout(10);
    $process->run();

    return $process;
}

test('console diagnostics use stderr while successful data uses stdout', function (): void {
    $missing = $this->createTemporaryDirectory() . '/missing';
    $failure = runDiagnosticProcess(['check', '--working-directory=' . $missing, '--no-ansi', '--no-interaction']);
    $success = runDiagnosticProcess(['--version', '--no-ansi', '--no-interaction']);

    expect($failure->getExitCode())->not->toBe(0)
        ->and($failure->getOutput())->toBe('')
        ->and($failure->getErrorOutput())->toContain('ERROR P0011 · ')
        ->and($success->getExitCode())->toBe(0)
        ->and(trim($success->getOutput()))->toBe('ppphp ' . Compiler::VERSION)
        ->and($success->getErrorOutput())->toBe('');
});

test('console diagnostics fall back to the primary stream for embedded outputs', function (): void {
    $output = new BufferedOutput();
    $writer = new DiagnosticOutputWriter(new ConsoleRenderer(), new JsonRenderer());
    $writer->write(
        new DiagnosticBag([new Diagnostic(DiagnosticCode::InvalidInvocation, 'Bad input.')]),
        OutputFormat::Console,
        new ArrayInput([]),
        $output,
    );

    expect($output->fetch())->toBe("ERROR P0022 · Bad input.\n");
});

test('JSON diagnostics write exactly one undecorated stdout document', function (): void {
    $missing = $this->createTemporaryDirectory() . '/missing';
    $process = runDiagnosticProcess([
        'check',
        '--working-directory=' . $missing,
        '--format=json',
        '--ansi',
        '--no-interaction',
    ]);
    $payload = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getErrorOutput())->toBe('')
        ->and($process->getOutput())->not->toContain("\e[")
        ->and($process->getOutput())->toEndWith("\n")
        ->and($payload['version'])->toBe(1)
        ->and($payload['diagnostics'])->toHaveCount(1);
});

test('real syntax failures share the cause-first layout and retain editor fields', function (string $extension, string $contents, string $code, string $message): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $path = 'src/Invalid.' . $extension;
    $this->writeFile($root . '/' . $path, $contents);
    $arguments = ['check', $path, '--working-directory=' . $root, '--no-ansi'];
    $console = runDiagnosticProcess($arguments);
    $json = runDiagnosticProcess([...$arguments, '--format=json']);
    $payload = json_decode($json->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    $diagnostic = $payload['diagnostics'][0];
    $location = $diagnostic['location'];

    expect($console->getExitCode())->toBe(1)
        ->and($json->getExitCode())->toBe(1)
        ->and($console->getErrorOutput())->toStartWith(sprintf(
            "%s:%d:%d\nERROR %s · %s\n",
            $path,
            $location['range']['start']['line'],
            $location['range']['start']['column'],
            $code,
            $message,
        ))
        ->and(substr_count($console->getErrorOutput(), $message))->toBe(1)
        ->and($console->getErrorOutput())->not->toContain('Invalid PHP', 'Invalid Extension', '-->', 'Help:')
        ->and($diagnostic['title'])->toBe('Syntax Error')
        ->and($diagnostic['message'])->toBe($message)
        ->and($diagnostic['code'])->toBe($code)
        ->and($location['file'])->toBe($path)
        ->and(file_get_contents($root . '/' . $path))->toBe($contents);
})->with([
    'ordinary PHP' => ['php', "<?php\necho 1\nreturn 2;\n", 'P1001', 'Expected a semicolon before `return`.'],
    'PHP-shaped source' => ['ppphp', "<?php\necho 1\nreturn 2;\n", 'P1001', 'Expected a semicolon before `return`.'],
    'extension syntax' => ['ppphp', "<?php\nint \$value = ;\n", 'P1008', 'A typed local declaration requires an initializer.'],
]);

test('explicit ANSI flags override color-related environment variables', function (): void {
    $missing = $this->createTemporaryDirectory() . '/missing';
    $forced = runDiagnosticProcess(
        ['check', '--working-directory=' . $missing, '--ansi', '--no-interaction'],
        ['NO_COLOR' => '1', 'TERM' => 'dumb'],
    );
    $disabled = runDiagnosticProcess(
        ['check', '--working-directory=' . $missing, '--no-ansi', '--no-interaction'],
        ['NO_COLOR' => '', 'TERM' => 'xterm-256color'],
    );

    expect($forced->getErrorOutput())->toContain("\e[")
        ->and($disabled->getErrorOutput())->not->toContain("\e[");
});

test('automatic decoration respects color-related environment variables', function (array $environment): void {
    $missing = $this->createTemporaryDirectory() . '/missing';
    $process = runDiagnosticProcess(
        ['check', '--working-directory=' . $missing, '--no-interaction'],
        $environment,
    );

    expect($process->getErrorOutput())->not->toContain("\e[");
})->with([
    'NO_COLOR=1' => [['NO_COLOR' => '1', 'TERM' => 'xterm-256color']],
    'nonstandard NO_COLOR value' => [['NO_COLOR' => 'disabled', 'TERM' => 'xterm-256color']],
    'TERM=dumb' => [['NO_COLOR' => '', 'TERM' => 'dumb']],
]);

test('initialization completes with closed stdin and no interaction', function (): void {
    $root = $this->createTemporaryDirectory();
    $process = runDiagnosticProcess([
        'init',
        '--working-directory=' . $root,
        '--no-interaction',
        '--no-ansi',
    ]);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('Created ppphp.json.')
        ->and($process->getErrorOutput())->toBe('')
        ->and(is_file($root . '/ppphp.json'))->toBeTrue();
});

test('all command help remains current and publicly oriented', function (): void {
    $commands = [
        'init',
        'check',
        'build',
        'clean',
        'composer:configure',
        'dump:ast',
        'editor:definition',
        'editor:semantic-tokens',
    ];
    $combined = '';

    foreach ($commands as $command) {
        $process = runDiagnosticProcess(['help', $command, '--no-ansi', '--no-interaction']);

        expect($process->getExitCode())->toBe(0)
            ->and($process->getErrorOutput())->toBe('')
            ->and($process->getOutput())->toContain($command);
        $combined .= $process->getOutput();
    }

    foreach ([['--help'], ['--version'], ['list']] as $arguments) {
        $process = runDiagnosticProcess([...$arguments, '--no-ansi', '--no-interaction']);

        expect($process->getExitCode())->toBe(0)
            ->and($process->getErrorOutput())->toBe('');
        $combined .= $process->getOutput();
    }

    $retiredIdentity = 'ph' . 'plus';

    expect($combined)->toContain('++PHP', '.ppphp', 'ppphp')
        ->and(strtolower($combined))->not->toContain($retiredIdentity);
});

test('normal project commands terminate with closed stdin and no interaction', function (string $command): void {
    $missing = $this->createTemporaryDirectory() . '/missing';
    $process = runDiagnosticProcess([
        $command,
        '--working-directory=' . $missing,
        '--no-interaction',
        '--no-ansi',
    ]);

    expect($process->getExitCode())->not->toBeNull()
        ->and($process->getErrorOutput())->toContain('ERROR ');
})->with([
    'check',
    'build',
    'clean',
    'composer:configure',
    'dump:ast',
]);
