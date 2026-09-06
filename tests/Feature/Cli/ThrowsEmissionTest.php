<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Support\GoldenFile;

test('throws erasure removes clause-only lines and retains body source coordinates', function (string $newline): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $source = str_replace("\n", $newline, <<<'PPP'
<?php
class Failure extends Exception {}
function inline(): void throws Failure { throw new Failure('inline'); }
function multiline(): void
    throws Failure
{
    throw new Failure('function');
}
function commented(): void throws /* keep this comment */ Failure { throw new Failure('commented'); }
class InlineConstructor
{
    public function __construct() throws Failure { throw new Failure('inline constructor'); }
}
interface Contract
{
    public function inline(): void throws Failure;
    public function multiline(): void
        throws Failure
    ;
}
abstract class Service implements Contract
{
    public function __construct()
        throws Failure
    {
        throw new Failure('constructor');
    }
    public function inline(): void throws Failure { throw new Failure('method'); }
    public function multilineBody(): void
        throws Failure
    {
        throw new Failure('multiline method');
    }
    abstract public function abstractInline(): void throws Failure;
    abstract public function multiline(): void
        throws Failure
    ;
}
PPP);
    $this->writeFile($root . '/src/main.ppphp', $source);
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json']);
    $build->run();
    expect($build->getExitCode())->toBe(0, $build->getOutput());
    $generated = file_get_contents($root . '/build/ppphp/main.php');
    expect($generated)->toContain('function multiline(): void' . $newline . '{')
        ->toContain('function inline(): void {')
        ->toContain('/* keep this comment */')
        ->not->toMatch('/^[\t ]+\r?$/m');
    GoldenFile::assertMatches(dirname(__DIR__, 2) . '/Golden/ProductionPhp/throws.php.golden', str_replace("\r\n", "\n", $generated));
    $maps = glob($root . '/build/ppphp/.ppphp/source-maps/*.json');
    expect($maps)->toHaveCount(1);
    $map = json_decode(file_get_contents($maps[0]), true);
    foreach (['inline', 'function', 'commented', 'inline constructor', 'constructor', 'method', 'multiline method'] as $message) {
        $body = "throw new Failure('" . $message . "')";
        $offset = strpos($generated, $body);
        $matches = array_values(array_filter($map['segments'], static fn (array $segment): bool =>
            $segment['generatedStart'] <= $offset && $segment['generatedEnd'] > $offset));
        expect($matches)->toHaveCount(1);
        $original = $matches[0]['originalStart'] + $offset - $matches[0]['generatedStart'];
        expect($original)->toBe(strpos($source, $body));
    }
})->with(["\n", "\r\n"]);
