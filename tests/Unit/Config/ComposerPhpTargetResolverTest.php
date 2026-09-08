<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Config\ComposerPhpTargetResolver;
use Atatusoft\Ppphp\Config\ProjectConfigLoader;
use Atatusoft\Ppphp\Cache\ProjectInputSnapshotBuilder;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Project\ProjectLoader;

test('Composer platform overrides the fallback without consulting host PHP', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeFile($root . '/composer.json', json_encode([
        'config' => ['platform' => ['php' => '8.4.23']], 'require' => ['php' => '^8.4'],
        'scripts' => ['post-install-cmd' => 'exit 99'],
    ]));
    $diagnostics = new DiagnosticBag();
    expect((new ComposerPhpTargetResolver())->resolve($root, '99.1', $diagnostics))->toBe('8.4')
        ->and($diagnostics->isEmpty)->toBeTrue();
});

test('PHP requirement constraints are honored without guessing an exact runtime', function (string $constraint, bool $compatible): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/composer.json', json_encode(['require' => ['php' => $constraint]]));
    $result = (new ProjectConfigLoader())->load($root);
    expect($result->isSuccessful)->toBe($compatible);
    if ($compatible) {
        expect($result->configuration?->targetPhpVersion)->toBe('8.4');
    } else {
        expect($result->diagnostics->errors[0]->code)->toBe($constraint === 'not a constraint'
            ? DiagnosticCode::InvalidComposerConfiguration : DiagnosticCode::ConflictingPhpTargetConfiguration)
            ->and($result->diagnostics->errors[0]->message)->toContain('require.php')
            ->and($result->diagnostics->errors[0]->primary?->span->sourceFile->displayPath)->toBe('composer.json');
    }
})->with([
    ['^8.4', true], ['>=8.4.10 <8.5', true], ['~8.4.0 !=8.4.1', true],
    ['^7.4 || ^8.4', true], ['8.4.*', true], ['8.4 - 8.5', true],
    ['>=8.5', false], ['<8.4', false], ['^7.4 || >=9.0', false], ['not a constraint', false],
]);

test('invalid contradictory and unsupported Composer targets never silently fall back', function (array $metadata, DiagnosticCode $code, string $message): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/composer.json', json_encode($metadata));
    $result = (new ProjectConfigLoader())->load($root);
    expect($result->configuration)->toBeNull()
        ->and($result->diagnostics->errors[0]->code)->toBe($code)
        ->and($result->diagnostics->errors[0]->message)->toContain($message)
        ->and($result->diagnostics->errors[0]->primary?->span->sourceFile->displayPath)->toBe('composer.json');
})->with([
    [['config' => ['platform' => ['php' => '99.1.0']]], DiagnosticCode::UnsupportedTargetPhpVersion, '99.1.0'],
    [['config' => ['platform' => ['php' => '8.5']]], DiagnosticCode::UnsupportedTargetPhpVersion, '8.5'],
    [['config' => ['platform' => ['php' => '8.4.1']], 'require' => ['php' => '>=8.4.2']], DiagnosticCode::ConflictingPhpTargetConfiguration, 'does not satisfy'],
    [['config' => ['platform' => ['php' => '^8.4']]], DiagnosticCode::InvalidComposerConfiguration, 'exact PHP version'],
    [['config' => ['platform' => ['php' => false]]], DiagnosticCode::InvalidComposerConfiguration, 'exact PHP version'],
    [['require' => ['php' => null]], DiagnosticCode::InvalidComposerConfiguration, 'version constraint'],
    [['config' => ['platform' => null]], DiagnosticCode::InvalidComposerConfiguration, 'must be objects'],
]);

test('Composer target reads reject malformed files and symbolic links without executing project code', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/composer.json', '{');
    $result = (new ProjectConfigLoader())->load($root);
    expect($result->diagnostics->errors[0]->message)->toContain('valid JSON');
    unlink($root . '/composer.json');
    $this->writeFile($root . '/external.json', '{}');
    symlink($root . '/external.json', $root . '/composer.json');
    expect((new ProjectConfigLoader())->load($root)->diagnostics->errors[0]->message)->toContain('symbolic link');
});

test('Composer platform edits invalidate cached evidence even within the same syntax target', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', '<?php int $value = 1;');
    $builder = new ProjectInputSnapshotBuilder();
    $snapshots = [];
    foreach (['8.4.23', '8.4.24'] as $platform) {
        $this->writeFile($root . '/composer.json', json_encode(['config' => ['platform' => ['php' => $platform]]]));
        $configuration = (new ProjectConfigLoader())->load($root, requireSourceDirectories: true)->configuration;
        expect($configuration)->not->toBeNull();
        $project = (new ProjectLoader())->load($configuration)->project;
        expect($project)->not->toBeNull();
        $snapshots[] = $builder->build($project, $project->sources);
    }
    expect($snapshots[0]->identity)->not->toBe($snapshots[1]->identity)
        ->and($snapshots[0]->inputs['configuration']['targetPhpVersion'])->toBe('8.4')
        ->and($snapshots[1]->inputs['configuration']['targetPhpVersion'])->toBe('8.4');
});
