<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Analysis\CompilerProjectAnalyzer;
use Atatusoft\Ppphp\Config\ProjectConfigLoader;
use Atatusoft\Ppphp\Diagnostics\JsonRenderer;
use Atatusoft\Ppphp\Editor\EditorDiagnosticsAnalyzer;
use Atatusoft\Ppphp\Editor\EditorDiagnosticsRequest;
use Atatusoft\Ppphp\Project\ProjectLoader;
use Atatusoft\Ppphp\Project\SourceSet;

test('reused editor analysis sees changed and restored PHPDoc contracts in other buffers', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', '<?php int $value = supplied();');
    $saved = '<?php /** @return int */ function supplied() {}';
    $this->writeFile($root . '/src/native.php', $saved);
    $configuration = (new ProjectConfigLoader())->load($root)->configuration;
    $project = (new ProjectLoader())->load($configuration)->project;
    $analyzer = new EditorDiagnosticsAnalyzer();
    foreach (['int', 'string', 'int'] as $version => $type) {
        $result = $analyzer->analyze($project, new EditorDiagnosticsRequest([
            ['path' => 'src/main.ppphp', 'contents' => '<?php int $value = supplied();'],
            ['path' => 'src/native.php', 'contents' => '<?php /** @return ' . $type . ' */ function supplied() {}'],
        ], $version));
        expect(array_map(static fn ($d) => $d->code->value, $result->diagnostics->errors))
            ->toBe($type === 'int' ? [] : ['P2008']);
    }
    expect(file_get_contents($root . '/src/native.php'))->toBe($saved)
        ->and(file_exists($root . '/.ppphp-cache'))->toBeFalse()
        ->and(file_exists($root . '/.ppphp-operation.lock'))->toBeFalse();
});

test('retained analysis isolates projects and bounds memory across source generations', function (): void {
    $projects = [];
    foreach (['int', 'string'] as $type) {
        $root = $this->createTemporaryDirectory();
        $this->writeConfiguration($root);
        $this->writeFile($root . '/src/native.php', '<?php /** @return ' . $type . ' */ function supplied() {}');
        $configuration = (new ProjectConfigLoader())->load($root)->configuration;
        $projects[] = (new ProjectLoader())->load($configuration)->project;
    }
    $analyzer = new EditorDiagnosticsAnalyzer();
    $settled = 0;
    for ($version = 0; $version < 60; $version++) {
        $result = $analyzer->analyze($projects[$version % 2], new EditorDiagnosticsRequest([
            ['path' => 'src/main.ppphp', 'contents' => '<?php /* generation ' . $version . ' */ int $value = supplied();'],
        ], $version));
        expect(array_map(static fn ($d) => $d->code->value, $result->diagnostics->errors))
            ->toBe($version % 2 === 0 ? [] : ['P2008']);
        unset($result);
        gc_collect_cycles();
        if ($version === 9) {
            $settled = memory_get_usage();
        }
    }
    expect(memory_get_usage() - $settled)->toBeLessThan(8 * 1024 * 1024);
});

test('editor diagnostics reuse normal compiler analysis without mutating a previously loaded project', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $saved = '<?php int $value = 1;';
    $unsaved = '<?php int $value = "wrong";';
    $this->writeFile($root . '/src/main.ppphp', $saved);
    $configuration = (new ProjectConfigLoader())->load($root)->configuration;
    $project = (new ProjectLoader())->load($configuration)->project;
    $source = $project->sources->files[0];
    $project->sourceManager->load($source->path);
    $analyzer = new EditorDiagnosticsAnalyzer();
    $result = $analyzer->analyze($project, new EditorDiagnosticsRequest([
        ['path' => 'src/main.ppphp', 'contents' => $unsaved],
    ], 1));
    expect($project->sourceManager->get($source->path)->contents)->toBe($saved)
        ->and(file_get_contents($source->path))->toBe($saved)
        ->and(file_exists($root . '/.ppphp-cache'))->toBeFalse()
        ->and(file_exists($root . '/.ppphp-operation.lock'))->toBeFalse();

    $this->writeFile($source->path, $unsaved);
    $diskProject = (new ProjectLoader())->load($configuration)->project;
    $disk = (new CompilerProjectAnalyzer())->analyze($diskProject, new SourceSet([$source]));
    $renderer = new JsonRenderer();
    expect($renderer->render($result->diagnostics))->toBe($renderer->render($disk->diagnostics));

    $again = $analyzer->analyze($project, new EditorDiagnosticsRequest([
        ['path' => 'src/main.ppphp', 'contents' => $saved],
    ], 2));
    expect($again->diagnostics->hasErrors)->toBeFalse();
});

test('editor buffers share configured stub and nonexecuted project PHP context', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->createDirectory($root . '/src');
    $this->writeFile($root . '/stubs/external.stub.php', '<?php function externalValue(): string {}');
    $this->writeFile($root . '/src/native.php', '<?php throw new \\RuntimeException("Must not execute"); function nativeValue(): int { return 1; }');
    $configuration = (new ProjectConfigLoader())->load($root)->configuration;
    $project = (new ProjectLoader())->load($configuration)->project;
    $result = (new EditorDiagnosticsAnalyzer())->analyze($project, new EditorDiagnosticsRequest([
        ['path' => 'src/new.ppphp', 'contents' => '<?php int $value = externalValue(); int $native = nativeValue();'],
    ], null));
    expect(array_map(static fn ($diagnostic) => $diagnostic->code->value, $result->diagnostics->errors))->toBe(['P2008'])
        ->and(file_exists($root . '/src/new.ppphp'))->toBeFalse();
});
