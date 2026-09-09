<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Cli\Application;
use Atatusoft\Ppphp\Cli\Enumerations\ExitCode;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Process\Process;
use Tests\Support\StageElevenProject;

function runPostStageTwelveCommand(array $input): ApplicationTester
{
    $application = new Application();
    $application->setAutoExit(false);
    $tester = new ApplicationTester($application);
    $tester->run(['--no-ansi' => true, ...$input]);

    return $tester;
}

test('the maintained shopping cart fixture checks builds lints and runs', function (): void {
    $root = $this->createTemporaryDirectory();
    StageElevenProject::copyTree(
        dirname(__DIR__, 3) . '/tests/Fixtures/GenericContext/ShoppingCart',
        $root,
    );

    $check = runPostStageTwelveCommand([
        'command' => 'check',
        '--working-directory' => $root,
    ]);
    $build = runPostStageTwelveCommand([
        'command' => 'build',
        '--working-directory' => $root,
    ]);
    $generated = (string) file_get_contents($root . '/build/ppphp/ShoppingCart.php');
    $phpFiles = glob($root . '/build/ppphp/*.php') ?: [];

    foreach ($phpFiles as $phpFile) {
        $lint = new Process([PHP_BINARY, '-l', $phpFile]);
        $lint->run();
        expect($lint->isSuccessful())->toBeTrue($lint->getErrorOutput());
    }

    $run = new Process([PHP_BINARY, $root . '/build/ppphp/index.php']);
    $run->run();

    expect($check->getStatusCode())->toBe(ExitCode::Success->value, $check->getDisplay())
        ->and($check->getDisplay())->not->toContain('P2020', 'P2026', 'P2099', 'P3015', 'P4005')
        ->and($build->getStatusCode())->toBe(ExitCode::Success->value, $build->getDisplay())
        ->and($generated)->toContain('@template TItem of CartItem')
        ->toContain('@param TItem $item')
        ->toContain('fn (CartItem $item): bool')
        ->and($run->isSuccessful())->toBeTrue($run->getErrorOutput())
        ->and($run->getOutput())->toBe("Coffee\n");
});

test('focused checks preserve valid declarations while isolating an unselected body error', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/CartItem.ppphp', '<?php interface CartItem { public int $id { get; } }');
    $this->writeFile($root . '/src/Product.ppphp', '<?php final class Product {}');
    $this->writeFile($root . '/src/ShoppingCartItem.ppphp', <<<'PPP'
<?php
final class ShoppingCartItem<T> implements CartItem
{
    public function __construct(public int $id, public T $product) {}
}
PPP);
    $this->writeFile($root . '/src/ShoppingCart.ppphp', <<<'PPP'
<?php
final class ShoppingCart<TItem : CartItem>
{
    public function brokenBody(): int
    {
        int $broken = 'wrong';
        return $broken;
    }
}
PPP);
    $this->writeFile($root . '/src/User.ppphp', <<<'PPP'
<?php
final class User
{
    public function __construct(public ShoppingCart<ShoppingCartItem<Product>> $cart) {}
}
PPP);

    $focused = runPostStageTwelveCommand([
        'command' => 'check',
        'path' => 'src/User.ppphp',
        '--working-directory' => $root,
    ]);
    $contextFiles = glob($root . '/.ppphp-cache/analysis/context/*/ShoppingCart.php') ?: [];
    $context = $contextFiles === [] ? '' : (string) file_get_contents($contextFiles[0]);
    $complete = runPostStageTwelveCommand([
        'command' => 'check',
        '--working-directory' => $root,
    ]);

    expect($focused->getStatusCode())->toBe(ExitCode::Success->value, $focused->getDisplay())
        ->and($focused->getDisplay())->not->toContain('P2008', 'P2020', 'P2099')
        ->and($context)->toContain('final class ShoppingCart')
        ->toContain('throw new \LogicException()')
        ->not->toContain("'wrong'")
        ->and($complete->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and($complete->getDisplay())->toContain('ERROR P2008', 'src/ShoppingCart.ppphp:');
});

test('focused declaration context never fabricates an invalid generic header', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Broken.ppphp', '<?php class Broken<T : string> {}');
    $this->writeFile($root . '/src/Consumer.ppphp', '<?php function consume(Broken<int> $value): void {}');
    $this->writeFile($root . '/src/Independent.ppphp', '<?php echo "independent";');

    $dependent = runPostStageTwelveCommand([
        'command' => 'check',
        'path' => 'src/Consumer.ppphp',
        '--working-directory' => $root,
    ]);
    $independent = runPostStageTwelveCommand([
        'command' => 'check',
        'path' => 'src/Independent.ppphp',
        '--working-directory' => $root,
    ]);

    expect($dependent->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value, $dependent->getDisplay())
        ->and($dependent->getDisplay())->toContain('P2020')
        ->not->toContain('src/Broken.ppphp:')
        ->and($independent->getStatusCode())->toBe(ExitCode::Success->value, $independent->getDisplay())
        ->and($independent->getDisplay())->not->toContain('P3011', 'src/Broken.ppphp:');
});
