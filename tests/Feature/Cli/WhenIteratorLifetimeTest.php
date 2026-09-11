<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

test('destination destruction observes released iterator storage', function (string $consumer): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $declarations = <<<'PPP'
<?php
final class MemoryObserver {
    public static int $baseline = 0;
    public function __construct(public string $label) {}
    public function __destruct() {
        if ($this->label === 'old') {
            echo memory_get_usage() - self::$baseline > 8 * 1024 * 1024 ? 'retained|' : 'released|';
        }
    }
}
function strings(): array<string> { return ['first', str_repeat('x', 16 * 1024 * 1024)]; }
PPP;
    $branch = 'while (true) { foreach (strings() as string $item) { return new MemoryObserver("new"); } }';
    $template = $consumer === 'property' ? <<<'PPP'
REFERENCE
final class Holder {
    public function __construct(public MemoryObserver $destination) {}
    public function run(bool $enabled): void {
        MemoryObserver::$baseline = memory_get_usage();
        $this->destination = RESULT;
        echo $this->destination->label, '|';
    }
}
(new Holder(new MemoryObserver('old')))->run(true);
PPP : <<<'PPP'
REFERENCE
function run(bool $enabled): void {
    MemoryObserver $destination = new MemoryObserver('old');
    MemoryObserver::$baseline = memory_get_usage();
    $destination = RESULT;
    echo $destination->label, '|';
}
run(true);
PPP;
    if ($consumer === 'fresh local') {
        $template = str_replace([
            "MemoryObserver \$destination = new MemoryObserver('old');", '$destination = RESULT;',
        ], ['', 'MemoryObserver $destination = RESULT;'], $template);
    }
    $source = $declarations . str_replace(['REFERENCE', 'RESULT'], [
        '', 'when ($enabled) { ' . $branch . ' } else { return new MemoryObserver("other"); }',
    ], $template);
    $this->writeFile($root . '/src/main.ppphp', $source);
    $reference = $declarations . str_replace(['REFERENCE', 'RESULT'], [
        'function nativeResult(bool $enabled): MemoryObserver { if ($enabled) { ' . $branch
            . ' } else { return new MemoryObserver("other"); } }', 'nativeResult($enabled)',
    ], $template);
    $reference = str_replace(['array<string>', 'as string $item', 'MemoryObserver $destination ='],
        ['array', 'as $item', '$destination ='], $reference);
    $this->writeFile($root . '/reference.php', $reference);
    $native = new Process([PHP_BINARY, $root . '/reference.php'], timeout: 5);
    $native->mustRun();
    expect($native->getOutput())->toBe($consumer === 'fresh local' ? 'new|' : 'released|new|');
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json']);
    $build->mustRun();
    if ($consumer === 'fresh local') {
        expect(file_get_contents($root . '/build/ppphp/main.php'))->not->toContain('__ppphp_when_');
    }
    $runtime = new Process([PHP_BINARY, $root . '/build/ppphp/main.php'], timeout: 5);
    $runtime->mustRun();
    expect($runtime->getOutput())->toBe($native->getOutput())
        ->and($runtime->getErrorOutput())->toBe('')->and($native->getErrorOutput())->toBe('');
})->with(['existing local', 'property', 'fresh local']);
