<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Cli\Enumerations\ExitCode;
use Tests\Support\StageElevenProject;

test('the canonical mixed application checks and certifies a complete atomic output', function (): void {
    $root = $this->createTemporaryDirectory();
    StageElevenProject::copyTree(dirname(__DIR__, 3) . '/examples/mixed-application', $root);

    $check = StageElevenProject::runCommand(['command' => 'check', '--working-directory' => $root]);
    $build = StageElevenProject::runCommand(['command' => 'build', '--working-directory' => $root]);
    $manifestPath = $root . '/build/ppphp/.ppphp/manifest.json';
    $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
    $operations = array_column($manifest['files'], 'operation');

    expect($check->getStatusCode())->toBe(ExitCode::Success->value, $check->getDisplay())
        ->and($check->getDisplay())->not->toContain('P4005')
        ->and($build->getStatusCode())->toBe(ExitCode::Success->value, $build->getDisplay())
        ->and($build->getDisplay())->toContain('WARNING P6008 · ')
        ->and($manifest['completeProject'])->toBeTrue()
        ->and($manifest['files'])->toHaveCount(12)
        ->and($operations)->toContain('compile', 'copy');

    foreach ($manifest['files'] as $entry) {
        $source = $root . '/' . $entry['source'];
        $output = $root . '/build/ppphp/' . $entry['output'];
        $map = $root . '/build/ppphp/' . $entry['sourceMap'];

        expect(is_file($output))->toBeTrue()
            ->and(is_file($map))->toBeTrue()
            ->and('sha256:' . hash_file('sha256', $source))->toBe($entry['sourceHash'])
            ->and('sha256:' . hash_file('sha256', $output))->toBe($entry['outputHash']);

        if ($entry['operation'] === 'copy') {
            expect(file_get_contents($output))->toBe(file_get_contents($source));
        } else {
            $generated = (string) file_get_contents($output);
            expect($generated)->toContain('declare(strict_types=1);')
                ->not->toContain(' throws ')
                ->not->toContain('when (');
        }
    }

    expect(array_column($manifest['files'], 'source'))
        ->not->toContain('stubs/LegacyGateway.stub.php', 'public/index.php')
        ->and(file_get_contents($root . '/build/ppphp/Domain/Box.php'))->toContain('@template T')
        ->and(file_get_contents($root . '/build/ppphp/Infrastructure/PersonRepository.php'))
        ->toContain('@implements Repository<Person>')
        ->and(file_get_contents($root . '/build/ppphp/Service/PersonService.php'))
        ->toContain('@throws \\Example\\Mixed\\Infrastructure\\LegacyUnavailable')
        ->and(file_get_contents($root . '/build/ppphp/console.php'))
        ->toContain("__DIR__ . '/../../vendor/autoload.php'");
});

test('focused mixed checks use cross-language context and isolate unrelated invalid sources', function (): void {
    $root = $this->createTemporaryDirectory();
    StageElevenProject::copyTree(dirname(__DIR__, 3) . '/examples/mixed-application', $root);

    $ppphp = StageElevenProject::runCommand([
        'command' => 'check',
        'path' => 'src/Application.ppphp',
        '--working-directory' => $root,
    ]);
    $php = StageElevenProject::runCommand([
        'command' => 'check',
        'path' => 'legacy/Http/LegacyController.php',
        '--working-directory' => $root,
    ]);
    $this->writeFile($root . '/src/Unrelated.ppphp', '<?php function invalid(');
    $this->writeFile($root . '/legacy/Unrelated.php', '<?php function invalidPhp(');
    $focused = StageElevenProject::runCommand([
        'command' => 'check',
        'path' => 'src/Application.ppphp',
        '--working-directory' => $root,
    ]);
    $focusedBuild = StageElevenProject::runCommand([
        'command' => 'build',
        'path' => 'src/Application.ppphp',
        '--working-directory' => $root,
    ]);
    $complete = StageElevenProject::runCommand(['command' => 'check', '--working-directory' => $root]);

    expect($ppphp->getStatusCode())->toBe(ExitCode::Success->value, $ppphp->getDisplay())
        ->and($php->getStatusCode())->toBe(ExitCode::Success->value, $php->getDisplay())
        ->and($focused->getStatusCode())->toBe(ExitCode::Success->value, $focused->getDisplay())
        ->and($focused->getDisplay())->not->toContain('Unrelated')
        ->and($focusedBuild->getStatusCode())->toBe(ExitCode::Success->value, $focusedBuild->getDisplay())
        ->and($focusedBuild->getDisplay())->not->toContain('Unrelated')
        ->and(is_file($root . '/build/ppphp/Application.php'))->toBeTrue()
        ->and(is_file($root . '/build/ppphp/Domain/Box.php'))->toBeFalse()
        ->and($complete->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and($complete->getDisplay())->toContain('Unrelated.ppphp', 'Unrelated.php');
});

test('focused directory and pathless builds retain their distinct mixed output scopes', function (): void {
    $root = $this->createTemporaryDirectory();
    StageElevenProject::copyTree(dirname(__DIR__, 3) . '/examples/mixed-application', $root);

    $ppphp = StageElevenProject::runCommand([
        'command' => 'build',
        'path' => 'src/Domain/Box.ppphp',
        '--working-directory' => $root,
    ]);
    $first = json_decode(
        (string) file_get_contents($root . '/build/ppphp/.ppphp/manifest.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $php = StageElevenProject::runCommand([
        'command' => 'build',
        'path' => 'legacy/Support/functions.php',
        '--working-directory' => $root,
    ]);
    $second = json_decode(
        (string) file_get_contents($root . '/build/ppphp/.ppphp/manifest.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $directory = StageElevenProject::runCommand([
        'command' => 'build',
        'path' => 'src/Support',
        '--working-directory' => $root,
    ]);
    $third = json_decode(
        (string) file_get_contents($root . '/build/ppphp/.ppphp/manifest.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $complete = StageElevenProject::runCommand(['command' => 'build', '--working-directory' => $root]);
    $final = json_decode(
        (string) file_get_contents($root . '/build/ppphp/.ppphp/manifest.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($ppphp->getStatusCode())->toBe(ExitCode::Success->value, $ppphp->getDisplay())
        ->and($first['completeProject'])->toBeFalse()
        ->and($first['files'])->toHaveCount(1)
        ->and($first['files'][0]['operation'])->toBe('compile')
        ->and($php->getStatusCode())->toBe(ExitCode::Success->value, $php->getDisplay())
        ->and($second['files'])->toHaveCount(2)
        ->and(array_column($second['files'], 'operation'))->toContain('compile', 'copy')
        ->and($directory->getStatusCode())->toBe(ExitCode::Success->value, $directory->getDisplay())
        ->and($third['files'])->toHaveCount(3)
        ->and(is_file($root . '/build/ppphp/Support/generated_functions.php'))->toBeTrue()
        ->and($complete->getStatusCode())->toBe(ExitCode::Success->value, $complete->getDisplay())
        ->and($final['completeProject'])->toBeTrue()
        ->and($final['files'])->toHaveCount(12);
});

test('mixed source failures preserve the previous complete output for both source kinds', function (): void {
    $root = $this->createTemporaryDirectory();
    StageElevenProject::copyTree(dirname(__DIR__, 3) . '/examples/mixed-application', $root);
    $successful = StageElevenProject::runCommand(['command' => 'build', '--working-directory' => $root]);
    $before = StageElevenProject::captureTree($root . '/build/ppphp');
    $application = (string) file_get_contents($root . '/src/Application.ppphp');
    $gateway = (string) file_get_contents($root . '/legacy/Infrastructure/LegacyGateway.php');

    $this->writeFile($root . '/src/Application.ppphp', '<?php function broken(');
    $ppphpFailure = StageElevenProject::runCommand(['command' => 'build', '--working-directory' => $root]);
    $afterPpphp = StageElevenProject::captureTree($root . '/build/ppphp');
    $this->writeFile($root . '/src/Application.ppphp', $application);
    $this->writeFile($root . '/legacy/Infrastructure/LegacyGateway.php', '<?php function brokenPhp(');
    $phpFailure = StageElevenProject::runCommand(['command' => 'build', '--working-directory' => $root]);

    expect($successful->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($ppphpFailure->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and($afterPpphp)->toBe($before)
        ->and($phpFailure->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and(StageElevenProject::captureTree($root . '/build/ppphp'))->toBe($before);

    $this->writeFile($root . '/legacy/Infrastructure/LegacyGateway.php', $gateway);
});
