<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Cli\Application;
use Atatusoft\Ppphp\Cli\Enumerations\ExitCode;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Process\Process;

function runStageSevenCommand(array $input): ApplicationTester
{
    $application = new Application();
    $application->setAutoExit(false);
    $tester = new ApplicationTester($application);
    $tester->run(['--no-ansi' => true, ...$input]);

    return $tester;
}

test('checked-error projects check build lint and run as ordinary PHP', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $bootstrap = <<<'PHP'
<?php
final class UserNotFound extends RuntimeException {}
final class StorageFailure extends RuntimeException {}
final readonly class User
{
    public function __construct(public string $id) {}
}
PHP;
    $this->writeFile($root . '/src/bootstrap.php', $bootstrap);
    $this->writeFile($root . '/src/index.ppphp', <<<'PPP'
<?php
require_once __DIR__ . '/bootstrap.php';

function loadUser(string $id): User throws UserNotFound, StorageFailure
{
    if ($id === '') {
        throw new UserNotFound('missing');
    }

    return new User($id);
}

try {
    User $user = loadUser('1');
    echo $user->id;
} catch (UserNotFound|StorageFailure $error) {
    echo $error->getMessage();
}
PPP);
    $check = runStageSevenCommand([
        'command' => 'check',
        '--working-directory' => $root,
    ]);
    $build = runStageSevenCommand([
        'command' => 'build',
        '--working-directory' => $root,
    ]);
    $generated = file_get_contents($root . '/build/ppphp/index.php');
    $lint = new Process([PHP_BINARY, '-l', $root . '/build/ppphp/index.php']);
    $lint->run();
    $runtime = new Process([PHP_BINARY, $root . '/build/ppphp/index.php']);
    $runtime->run();

    expect($check->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($build->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($generated)->toBeString()
        ->toContain('@throws \\UserNotFound|\\StorageFailure')
        ->not->toContain('throws UserNotFound')
        ->and(file_get_contents($root . '/build/ppphp/bootstrap.php'))->toBe($bootstrap)
        ->and($lint->isSuccessful())->toBeTrue()
        ->and($runtime->isSuccessful())->toBeTrue()
        ->and($runtime->getOutput())->toBe('1');
});

test('unhandled called errors block checks and builds without exposing analysis paths', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Invalid.ppphp', <<<'PPP'
<?php
final class Failure extends RuntimeException {}
function load(): void throws Failure
{
    throw new Failure();
}
function main(): void
{
    load();
}
PPP);
    $check = runStageSevenCommand([
        'command' => 'check',
        '--working-directory' => $root,
    ]);
    $build = runStageSevenCommand([
        'command' => 'build',
        '--working-directory' => $root,
    ]);

    expect($check->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and($check->getDisplay())->toContain('ERROR P4003 · ')
        ->toContain('src/Invalid.ppphp:')
        ->not->toContain('.ppphp-cache/analysis')
        ->and($build->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and(file_exists($root . '/build/ppphp/Invalid.php'))->toBeFalse();
});

test('dynamic call warnings do not fail checks or block builds', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Dynamic.ppphp', <<<'PPP'
<?php
function invoke(callable $callback): void
{
    $callback();
}
PPP);
    $check = runStageSevenCommand([
        'command' => 'check',
        '--working-directory' => $root,
    ]);
    $build = runStageSevenCommand([
        'command' => 'build',
        '--working-directory' => $root,
    ]);

    expect($check->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($check->getDisplay())->toContain('WARNING P4005 · ')
        ->and($build->getStatusCode())->toBe(ExitCode::Success->value)
        ->and(file_exists($root . '/build/ppphp/Dynamic.php'))->toBeTrue();
});

test('focused checks use valid unselected checked-error contracts as context', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Api.ppphp', <<<'PPP'
<?php
final class ApiFailure extends RuntimeException {}
function callApi(): void throws ApiFailure
{
    throw new ApiFailure();
}
PPP);
    $this->writeFile($root . '/src/Caller.ppphp', <<<'PPP'
<?php
function caller(): void
{
    callApi();
}
PPP);
    $this->writeFile($root . '/src/Unrelated.ppphp', '<?php function broken(: void {}');
    $tester = runStageSevenCommand([
        'command' => 'check',
        'path' => 'src/Caller.ppphp',
        '--working-directory' => $root,
    ]);

    expect($tester->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and($tester->getDisplay())->toContain('ERROR P4003 · ')
        ->toContain('src/Caller.ppphp:')
        ->not->toContain('Unrelated.ppphp')
        ->not->toContain('.ppphp-cache/analysis');
});

test('project PHPDoc checked-error contracts participate in focused commands', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Boundary.php', <<<'PHP'
<?php
final class BoundaryFailure extends RuntimeException {}
/** @throws BoundaryFailure */
function boundary(): void {}
PHP);
    $this->writeFile($root . '/src/Caller.ppphp', <<<'PPP'
<?php
function caller(): void
{
    boundary();
}
PPP);
    $tester = runStageSevenCommand([
        'command' => 'check',
        'path' => 'src/Caller.ppphp',
        '--working-directory' => $root,
    ]);

    expect($tester->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and($tester->getDisplay())->toContain('ERROR P4003 · ')
        ->toContain('BoundaryFailure');
});

test('focused checks do not report checked-error failures from unselected semantic context', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Selected.ppphp', <<<'PPP'
<?php
function selected(): void {}
PPP);
    $this->writeFile($root . '/src/ContextContract.ppphp', <<<'PPP'
<?php
function contextOnly(): void throws MissingType {}
PPP);
    $this->writeFile($root . '/src/ContextType.ppphp', <<<'PPP'
<?php
class MissingType {}
PPP);
    $tester = runStageSevenCommand([
        'command' => 'check',
        'path' => 'src/Selected.ppphp',
        '--working-directory' => $root,
    ]);

    expect($tester->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($tester->getDisplay())
        ->not->toContain('ContextContract.ppphp')
        ->not->toContain('ContextType.ppphp')
        ->not->toContain('ERROR P4002');
});

test('focused and complete checks resolve hello-shaped generic call boundaries consistently', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Core/Person.ppphp', <<<'PPP'
<?php
namespace My\App\Core;
readonly class Person
{
    public function __construct(public string $firstName) {}
}
PPP);
    $this->writeFile($root . '/src/Core/Box.ppphp', <<<'PPP'
<?php
namespace My\App\Core;
class Box<T>
{
    public function __construct(public T $value) {}
    public function getValue(): T { return $this->value; }
}
PPP);
    $this->writeFile($root . '/src/index.ppphp', <<<'PPP'
<?php
use My\App\Core\Box;
use My\App\Core\Person;
Person $person = new Person('John');
Box<Person> $box = new Box($person);
echo 'Hello ' . $box->getValue()->firstName;
PPP);

    $complete = runStageSevenCommand([
        'command' => 'check',
        '--working-directory' => $root,
    ]);
    $focused = runStageSevenCommand([
        'command' => 'check',
        'path' => 'src/index.ppphp',
        '--working-directory' => $root,
    ]);
    $build = runStageSevenCommand([
        'command' => 'build',
        '--working-directory' => $root,
    ]);

    expect($complete->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($focused->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($build->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($complete->getDisplay())->not->toContain('P4005')
        ->and($focused->getDisplay())->not->toContain('P4005')
        ->and($build->getDisplay())->not->toContain('P4005')
        ->and(file_exists($root . '/build/ppphp/index.php'))->toBeTrue();
});
