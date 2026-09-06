<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Analysis\Browser\AnalysisContinuation;
use Atatusoft\Ppphp\Analysis\Browser\BrowserAnalysisProtocol;
use Atatusoft\Ppphp\Analysis\Browser\PrepareAnalysisRequest;
use Atatusoft\Ppphp\Analysis\Browser\PrepareAnalysisRequestDecoder;
use Atatusoft\Ppphp\Compiler\Compiler;

function writeBrowserAnalysisProject(string $root, ?string $source): void
{
    mkdir($root . '/src', 0777, true);
    mkdir($root . '/stubs', 0777, true);
    file_put_contents($root . '/ppphp.json', json_encode([
        'source' => ['src'],
        'output' => 'build/ppphp',
        'cache' => '.ppphp-cache',
        'targetPhpVersion' => '8.4',
        'stubs' => ['stubs'],
        'exclude' => ['vendor', 'build', '.ppphp-cache'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    if ($source !== null) {
        file_put_contents($root . '/src/main.ppphp', $source);
    }
}

test('prepare requests reject unsupported protocol versions and malformed actions', function (): void {
    $decoder = new PrepareAnalysisRequestDecoder();

    expect(fn () => $decoder->decode('{"version":2,"requestId":"one","action":"prepare","operation":"check"}'))
        ->toThrow(InvalidArgumentException::class, 'version is unsupported')
        ->and(fn () => $decoder->decode('{"version":1,"requestId":"one","action":"complete","operation":"check"}'))
        ->toThrow(InvalidArgumentException::class, 'action must be prepare')
        ->and(fn () => new PrepareAnalysisRequest('one', 'run', null))
        ->toThrow(InvalidArgumentException::class, 'operation must be check or build');
});

test('prepare analysis materializes the native workspace and a content-addressed continuation', function (): void {
    $root = $this->createTemporaryDirectory();
    writeBrowserAnalysisProject($root, <<<'PHP'
<?php

int $total = 240;
echo 'Order total: ' . $total;
PHP);
    $request = new PrepareAnalysisRequest('prepare-positive', 'check', null);
    $protocol = new BrowserAnalysisProtocol();
    $prepared = $protocol->prepare($request, $root);
    $repeated = $protocol->prepare($request, $root);
    $continuation = $prepared->continuation;
    $projectRoot = realpath($root);

    expect($projectRoot)->toBeString();

    expect($prepared->status)->toBe('prepared')
        ->and($prepared->diagnostics['summary'])->toBe(['errors' => 0, 'warnings' => 0, 'notes' => 0])
        ->and($continuation)->not->toBeNull()
        ->and($prepared->phpStanCommand[0] ?? null)->toBe('php')
        ->and($prepared->phpStanCommand)->toContain('--error-format=json', '--no-progress', '--memory-limit=1G', '--debug')
        ->and($prepared->phpStanWorkingDirectory)->toBe($projectRoot . '/.ppphp-cache/analysis')
        ->and($prepared->phpStanResultPath)->toBe($projectRoot . '/.ppphp-cache/analysis/result.json')
        ->and($continuation?->compiler)->toBe([
            'name' => Compiler::NAME,
            'version' => Compiler::VERSION,
            'loweringFormatVersion' => Compiler::LOWERING_FORMAT_VERSION,
        ])
        ->and($continuation?->sources)->toHaveCount(1)
        ->and($continuation?->sources[0]['path'] ?? null)->toBe('src/main.ppphp')
        ->and($continuation?->selectedSources)->toBe(['src/main.ppphp'])
        ->and($continuation?->expectedResult)->toBe([
            'path' => 'result.json',
            'format' => 'phpstan-json-v1',
            'maximumBytes' => 2_097_152,
        ])
        ->and(array_column($continuation?->workspaceManifest ?? [], 'path'))->toContain('maps.json', 'phpstan.neon')
        ->and($continuation?->contentHash)->toStartWith('sha256:')
        ->and($repeated->continuation?->contentHash)->toBe($continuation?->contentHash);
});

test('prepare analysis completes empty selections without planning a backend invocation', function (?string $path): void {
    $root = $this->createTemporaryDirectory();
    writeBrowserAnalysisProject($root, null);
    mkdir($root . '/src/Empty');

    $prepared = (new BrowserAnalysisProtocol())->prepare(
        new PrepareAnalysisRequest('prepare-empty', 'check', $path),
        $root,
    );

    expect($prepared->status)->toBe('diagnostics')
        ->and($prepared->diagnostics['summary'])->toBe(['errors' => 0, 'warnings' => 0, 'notes' => 0])
        ->and($prepared->continuation)->toBeNull()
        ->and($prepared->phpStanCommand)->toBeNull()
        ->and($prepared->phpStanWorkingDirectory)->toBeNull()
        ->and($prepared->phpStanResultPath)->toBeNull();
})->with([
    'complete empty project' => null,
    'focused empty directory' => 'src/Empty',
]);

test('a continuation rejects content changed without a matching hash', function (): void {
    $root = $this->createTemporaryDirectory();
    writeBrowserAnalysisProject($root, "<?php\nint \$value = 1;\n");
    $continuation = (new BrowserAnalysisProtocol())
        ->prepare(new PrepareAnalysisRequest('prepare-tamper', 'check', null), $root)
        ->continuation;

    expect($continuation)->not->toBeNull();

    $sources = $continuation->sources;
    $sources[0]['hash'] = 'sha256:' . str_repeat('0', 64);

    expect(fn () => new AnalysisContinuation(
        $continuation->version,
        $continuation->prepareRequestId,
        $continuation->operation,
        $continuation->selectedPath,
        $continuation->compiler,
        $sources,
        $continuation->projectConfigurationHash,
        $continuation->selectedSources,
        $continuation->workspaceManifest,
        $continuation->phpStanConfigurationHash,
        $continuation->expectedResult,
        $continuation->contentHash,
    ))->toThrow(InvalidArgumentException::class, 'content hash is invalid');
});

test('prepare analysis stops before PHPStan on syntax and compiler-owned semantic failures', function (string $source, string $code): void {
    $root = $this->createTemporaryDirectory();
    writeBrowserAnalysisProject($root, $source);
    $prepared = (new BrowserAnalysisProtocol())->prepare(
        new PrepareAnalysisRequest('prepare-failure', 'check', null),
        $root,
    );

    expect($prepared->status)->toBe('diagnostics')
        ->and($prepared->continuation)->toBeNull()
        ->and(array_column($prepared->diagnostics['diagnostics'], 'code'))->toContain($code);
})->with([
    'syntax' => ["<?php\nfunction broken(\n", 'P1001'],
    'missing opening tag' => ['final class Box<T> {}', 'P1011'],
    'semantic' => ["<?php\n\$value = 1;\n", 'P2002'],
]);

test('browser preparation preserves the missing analyzer cause and recovery advice', function (): void {
    $root = $this->createTemporaryDirectory();
    writeBrowserAnalysisProject($root, '<?php final class Box<T> {}');
    $protocol = new BrowserAnalysisProtocol(phpStan: new \Atatusoft\Ppphp\Analysis\PhpStan\PhpStanProjectAnalyzer($root . '/missing-compiler'));
    $prepared = $protocol->prepare(new PrepareAnalysisRequest('missing-analyzer', 'check', null), $root);
    expect($prepared->status)->toBe('diagnostics')
        ->and($prepared->diagnostics['diagnostics'][0]['message'])->toContain('not installed')
        ->and($prepared->diagnostics['diagnostics'][0]['help'])->toContain('Reinstall')
        ->and($prepared->diagnostics['diagnostics'][0]['location'])->toBeNull();
});
