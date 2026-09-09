<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Cli\Application;
use Atatusoft\Ppphp\Cli\Enumerations\ExitCode;
use Symfony\Component\Console\Tester\ApplicationTester;

function runBrowserAnalysisCommand(array $input): ApplicationTester
{
    $application = new Application();
    $application->setAutoExit(false);
    $tester = new ApplicationTester($application);
    $tester->run(['--no-ansi' => true, ...$input]);

    return $tester;
}

test('the hidden browser command returns a prepared compiler-owned protocol response', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', "<?php\nint \$total = 240;\n");
    $this->writeFile($root . '/request.json', json_encode([
        'version' => 1,
        'requestId' => 'cli-prepare',
        'action' => 'prepare',
        'operation' => 'check',
        'selection' => ['path' => null],
    ], JSON_THROW_ON_ERROR));
    $tester = runBrowserAnalysisCommand([
        'command' => 'browser:analysis',
        'request' => 'request.json',
        '--working-directory' => $root,
    ]);
    $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

    expect($tester->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($payload['version'])->toBe(1)
        ->and($payload['requestId'])->toBe('cli-prepare')
        ->and($payload['action'])->toBe('prepare')
        ->and($payload['status'])->toBe('prepared')
        ->and($payload['phpStan']['command'][0])->toBe('php')
        ->and($payload['continuation']['contentHash'])->toStartWith('sha256:');
});

test('the browser command rejects unsupported versions and request symlinks outside the project', function (): void {
    $container = $this->createTemporaryDirectory();
    $root = $container . '/project';
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', "<?php\nint \$total = 240;\n");
    $this->writeFile($root . '/unsupported.json', json_encode([
        'version' => 99,
        'requestId' => 'unsupported',
        'action' => 'prepare',
        'operation' => 'check',
    ], JSON_THROW_ON_ERROR));
    $unsupported = runBrowserAnalysisCommand([
        'command' => 'browser:analysis',
        'request' => 'unsupported.json',
        '--working-directory' => $root,
    ]);
    $unsupportedPayload = json_decode($unsupported->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
    $this->writeFile($container . '/outside.json', '{}');
    symlink($container . '/outside.json', $root . '/linked.json');
    $linked = runBrowserAnalysisCommand([
        'command' => 'browser:analysis',
        'request' => 'linked.json',
        '--working-directory' => $root,
    ]);
    $linkedPayload = json_decode($linked->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

    expect($unsupported->getStatusCode())->toBe(ExitCode::InvalidProject->value)
        ->and($unsupportedPayload['status'])->toBe('protocolError')
        ->and($unsupportedPayload['error']['message'])->toContain('version is unsupported')
        ->and($linked->getStatusCode())->toBe(ExitCode::InvalidProject->value)
        ->and($linkedPayload['status'])->toBe('protocolError')
        ->and($linkedPayload['error']['message'])->toContain('project-contained file');
});

test('the browser command executes protocol version two entirely in process', function (string $source, array $codes): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root, ['stubs' => []]);
    $this->writeFile($root . '/src/main.ppphp', $source);
    $this->writeFile($root . '/request.json', json_encode([
        'version' => 2,
        'requestId' => 'compiler-analysis',
        'action' => 'analyze',
        'operation' => 'check',
        'analysis' => ['engine' => 'compiler'],
        'selection' => ['path' => null],
    ], JSON_THROW_ON_ERROR));
    $tester = runBrowserAnalysisCommand([
        'command' => 'browser:analysis',
        'request' => 'request.json',
        '--working-directory' => $root,
    ]);
    $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

    expect($tester->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($payload['version'])->toBe(2)
        ->and($payload['action'])->toBe('analyze')
        ->and($payload['status'])->toBe('complete')
        ->and($payload['engine'])->toBe('compiler')
        ->and($payload['completeness'])->toBe('compilerCore')
        ->and($payload['catalogVersion'])->toBe(4)
        ->and($payload['fullParity'])->toBeTrue()
        ->and($payload['uncoveredRequiredCapabilities'])->toBe([])
        ->and(array_column($payload['diagnostics']['diagnostics'], 'code'))->toBe($codes)
        ->and($payload)->not->toHaveKeys(['phpStan', 'continuation', 'command'])
        ->and(is_dir($root . '/.ppphp-cache/analysis'))->toBeFalse();
})->with([
    'valid' => ["<?php\nfunction value(): int { return 1; }\n", []],
    'invalid' => ["<?php\nfunction invalid(): void { int \$value = 'wrong'; }\n", ['P2008']],
]);
