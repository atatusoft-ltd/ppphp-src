<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Atatusoft\Ppphp\Frontend\PpphpParser;
use Atatusoft\Ppphp\Project\ProjectParseResult;
use Atatusoft\Ppphp\Semantic\SemanticAnalyzer;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;
use Atatusoft\Ppphp\Transpilation\PhpLowerer;

test('variadic reference writeback matches native execution despite the pinned backend rejecting native PHP', function (): void {
    $root = $this->createTemporaryDirectory();
    $path = $root . '/Native.php';
    $native = '<?php function run(int $value, bool $take): void {
        new class(target: $value, replacement: $take ? (($value = 7) - 5) : (($value = 8) - 5)) {
            public function __construct(int $replacement, int &...$targets) {
                echo $targets["target"], "|";
                $targets["target"] = $replacement;
            }
        };
        echo $value, ";";
    } run(1, true); run(1, false);';
    $this->writeFile($path, $native);
    $runtime = new Process([PHP_BINARY, $path]);
    $runtime->mustRun();
    expect($runtime->getOutput())->toBe('7|2;8|3;')->and($runtime->getErrorOutput())->toBe('');
    $source = new SourceFile($root . '/Native.ppphp', 'Native.ppphp', FileKind::Ppphp, str_replace(
        '$take ? (($value = 7) - 5) : (($value = 8) - 5)',
        'when ($take) { $value = 7; return 2; } else { $value = 8; return 3; }', $native,
    ));
    $parsed = (new PpphpParser())->parse($source);
    $analysis = (new SemanticAnalyzer())->analyze(new ProjectParseResult(
        [$source->path => $parsed->parsedFile], [$source->path => $source], $parsed->diagnostics,
    ));
    expect($analysis->isSuccessful)->toBeTrue();
    $generated = (new PhpLowerer())->lower($parsed->parsedFile, $analysis->findModel($source->path));
    $lowered = new Process([PHP_BINARY, '-r', substr($generated->contents, 5)]);
    $lowered->mustRun();
    expect($lowered->getOutput())->toBe($runtime->getOutput())->and($lowered->getErrorOutput())->toBe('');
    $this->writeFile($root . '/phpstan.neon', "parameters:\n    level: max\n    tmpDir: " . json_encode($root . '/cache') . "\n");
    $backend = new Process([
        PHP_BINARY, dirname(__DIR__, 3) . '/vendor/bin/phpstan', 'analyse', '--debug', '--no-progress',
        '--error-format=json', '--configuration=' . $root . '/phpstan.neon', $path,
    ], timeout: 30);
    $backend->run();
    $output = $backend->getOutput();
    $result = json_decode(substr($output, strpos($output, '{')), true, flags: JSON_THROW_ON_ERROR);
    expect($result['errors'])->toBe([])
        ->and($backend->getExitCode())->toBe(1, $output . $backend->getErrorOutput())
        ->and(array_column($result['files'][$path]['messages'] ?? [], 'identifier'))
        ->toBe(['parameterByRef.type']);
});
