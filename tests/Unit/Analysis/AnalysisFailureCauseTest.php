<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Analysis\AnalysisWorkspacePreparer;
use Atatusoft\Ppphp\Analysis\CompilerProjectAnalyzer;
use Atatusoft\Ppphp\Config\ProjectConfigLoader;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Diagnostics\JsonRenderer;
use Atatusoft\Ppphp\Project\ProjectLoader;
use Atatusoft\Ppphp\Transpilation\Pass\Interfaces\TranspilationPass;
use Atatusoft\Ppphp\Transpilation\PhpLowerer;
use Atatusoft\Ppphp\Transpilation\TranspilationContext;

test('analysis preparation preserves known environmental causes without blaming source code', function (bool $link): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', '<?php final class Box<T> {}');
    $configuration = (new ProjectConfigLoader())->load($root)->configuration;
    $project = (new ProjectLoader())->load($configuration)->project;
    $analysis = (new CompilerProjectAnalyzer())->analyze($project, $project->sources);
    expect($analysis->diagnostics->hasErrors)->toBeFalse();
    $this->createDirectory($root . '/.ppphp-cache');
    if ($link) {
        symlink($root . '/src', $root . '/.ppphp-cache/analysis');
    } else {
        $this->writeFile($root . '/.ppphp-cache/analysis', 'preserve this file');
    }

    $result = (new AnalysisWorkspacePreparer())->prepare($analysis);
    $diagnostic = $result->diagnostics->errors[0];
    expect($diagnostic->code)->toBe(DiagnosticCode::AnalysisWorkspacePreparationFailed)
        ->and($diagnostic->primary)->toBeNull()
        ->and($diagnostic->message)->toContain($link ? 'symbolic link' : 'occupied by a file')
        ->and($diagnostic->help)->not->toContain('permissions')
        ->and(file_get_contents($root . '/src/main.ppphp'))->toBe('<?php final class Box<T> {}');
    if (!$link) {
        expect(file_get_contents($root . '/.ppphp-cache/analysis'))->toBe('preserve this file');
    }
})->with([true, false]);

test('unexpected preparation failures remain compiler bugs with details only in debug output', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', '<?php final class Box<T> {}');
    $configuration = (new ProjectConfigLoader())->load($root)->configuration;
    $project = (new ProjectLoader())->load($configuration)->project;
    $analysis = (new CompilerProjectAnalyzer())->analyze($project, $project->sources);
    $pass = new class implements TranspilationPass {
        public function execute(TranspilationContext $context): void
        {
            throw new LogicException('private implementation failure');
        }
    };
    $result = (new AnalysisWorkspacePreparer(lowerer: new PhpLowerer([$pass])))->prepare($analysis);
    $diagnostic = $result->diagnostics->errors[0];
    expect($diagnostic->code)->toBe(DiagnosticCode::InternalCompilerError)
        ->and($diagnostic->primary)->toBeNull()
        ->and($diagnostic->help)->toContain('--debug')
        ->and($diagnostic->help)->not->toContain('permissions', 'symbolic link');
    $renderer = new JsonRenderer();
    expect($renderer->render($result->diagnostics))->not->toContain('private implementation failure')
        ->and($renderer->render($result->diagnostics, true))->toContain('private implementation failure');
});

test('unreadable root and nested analysis directories are environmental failures', function (bool $nested): void {
    if (DIRECTORY_SEPARATOR !== '/' || !function_exists('chmod')) {
        $this->markTestSkipped('Reliable POSIX permission assertions are unavailable.');
    }
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', '<?php final class Box<T> {}');
    $configuration = (new ProjectConfigLoader())->load($root)->configuration;
    $project = (new ProjectLoader())->load($configuration)->project;
    $analysis = (new CompilerProjectAnalyzer())->analyze($project, $project->sources);
    $blocked = $root . '/.ppphp-cache/analysis' . ($nested ? '/locked' : '');
    $this->writeFile($blocked . '/sentinel', 'preserve inaccessible evidence');
    chmod($blocked, 0000);
    clearstatcache(true, $blocked);

    try {
        if (is_readable($blocked)) {
            $this->markTestSkipped('The current filesystem does not enforce the requested unreadable mode.');
        }
        $result = (new AnalysisWorkspacePreparer())->prepare($analysis);
        $diagnostic = $result->diagnostics->errors[0];
        expect($diagnostic->code)->toBe(DiagnosticCode::AnalysisWorkspacePreparationFailed)
            ->and($diagnostic->message)->toContain('could not be opened')
            ->and($diagnostic->help)->toContain('reading and traversal')
            ->and($diagnostic->primary)->toBeNull();
        $renderer = new JsonRenderer();
        expect($renderer->render($result->diagnostics))->not->toContain($root, 'DirectoryIterator')
            ->and($renderer->render($result->diagnostics, true))->toContain('Permission denied');
    } finally {
        chmod($blocked, 0755);
    }
    expect(file_get_contents($blocked . '/sentinel'))->toBe('preserve inaccessible evidence');
})->with([true, false]);
