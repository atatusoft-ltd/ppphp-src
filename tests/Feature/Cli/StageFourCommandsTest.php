<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Cli\Application;
use Atatusoft\Ppphp\Cli\Enumerations\ExitCode;
use Symfony\Component\Console\Tester\ApplicationTester;

function runStageFourCommand(array $input): ApplicationTester
{
    $application = new Application();
    $application->setAutoExit(false);
    $tester = new ApplicationTester($application);
    $tester->run(['--no-ansi' => true, ...$input]);

    return $tester;
}

test('generic extension syntax checks and builds without raw PHP errors', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Feature.ppphp', '<?php final class Box<T> {}');
    $check = runStageFourCommand([
        'command' => 'check',
        'path' => 'src/Feature.ppphp',
        '--working-directory' => $root,
    ]);
    $build = runStageFourCommand([
        'command' => 'build',
        'path' => 'src/Feature.ppphp',
        '--working-directory' => $root,
    ]);

    expect($check->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($check->getDisplay())->not->toContain('ERROR P3001')
        ->and($check->getDisplay())->not->toContain('ERROR P1001')
        ->and($build->getStatusCode())->toBe(ExitCode::Success->value)
        ->and(file_exists($root . '/build/ppphp/Feature.php'))->toBeTrue();
});

test('dump ast exposes active when nodes normalized PHP and hierarchical source mapping', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile(
        $root . '/src/Feature.ppphp',
        '<?php function f(): int { return when (true) { return 1; } else { return 0; }; }',
    );
    $dump = runStageFourCommand([
        'command' => 'dump:ast',
        'path' => 'src/Feature.ppphp',
        '--working-directory' => $root,
    ]);

    expect($dump->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($dump->getDisplay())->not->toContain('ERROR P5001 · ')
        ->and($dump->getDisplay())->toContain('WhenExpression')
        ->and($dump->getDisplay())->toContain('depth=0 parent=none')
        ->and($dump->getDisplay())->toContain('Normalized PHP AST:')
        ->and($dump->getDisplay())->toContain('Normalization:')
        ->and($dump->getDisplay())->not->toContain('ERROR P1001');
});
