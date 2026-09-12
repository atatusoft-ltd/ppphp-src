<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Atatusoft\Ppphp\Analysis\AnalysisSourceProjector;
use Atatusoft\Ppphp\Frontend\PpphpParser;
use Atatusoft\Ppphp\Project\ProjectParseResult;
use Atatusoft\Ppphp\Semantic\SemanticAnalyzer;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;
use Atatusoft\Ppphp\Transpilation\PhpLowerer;

test('protected transfers retain their target and give finally results priority', function (string $body, string $consumer, string $type, string $replacement): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $header = <<<'PPP'
<?php
final class Result {
    public function __toString(): string { return '9'; }
    public function __destruct() { echo 'destroy|'; }
}
function failCleanup(): never throws Error { throw new Error('cleanup'); }
/** @return Generator<int, int, mixed, void> */
function items(): Generator {
    try { echo 'next:1|'; yield 1; echo 'next:2|'; yield 2; }
    finally { echo 'release|'; }
}
function choose(bool $ready, bool $select): int {
PPP;
    $header = str_replace('): int {', '): ' . $type . ' {', $header);
    $body = str_replace('return 9;', 'return ' . $replacement . ';', $body);
    $driver = 'echo choose(true, false), "|", choose(true, true), "|", choose(false, true);';
    $expression = 'when ($ready) { ' . $body . ' } else { return ' . $replacement . '; }';
    $statement = $consumer === 'return' ? 'return ' . $expression . ';'
        : $type . ' $destination = 99; $destination = ' . $expression . '; return $destination;';
    $this->writeFile($root . '/src/main.ppphp', $header . $statement . ' } ' . $driver);
    // These cases never combine a pending uncaught error with a finally
    // return. Native PHP is therefore the exact result/transfer oracle.
    $reference = str_replace(['as int $value', 'as int $outer', 'as mixed $value', 'int $index', ' throws Error'],
        ['as $value', 'as $outer', 'as $value', '$index', ''],
        $header . 'if ($ready) { ' . $body . ' } else { return ' . $replacement . '; } } ' . $driver);
    $this->writeFile($root . '/reference.php', $reference);
    $native = new Process([PHP_BINARY, $root . '/reference.php'], timeout: 5);
    $native->mustRun();
    $build = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'build', '--working-directory', $root, '--format=json']);
    $build->run();
    expect($build->getExitCode())->toBe(0, $build->getOutput() . $build->getErrorOutput());
    $path = $root . '/build/ppphp/main.php';
    $php = file_get_contents($path);
    expect($php)->not->toContain('do {')->not->toContain('while (true)')->not->toContain('while (false)')
        ->not->toContain('__ppphp_when_pending_error');
    // Q1 requires native exception-context cleanup for retained objects. The
    // old blanket catch prohibition conflated that with A2 control transfer.
    // Exclude only lowering-proven cleanup, after matching the actual output.
    $source = new SourceFile($root . '/src/main.ppphp', 'src/main.ppphp', FileKind::Ppphp,
        file_get_contents($root . '/src/main.ppphp'));
    $parsed = (new PpphpParser())->parse($source);
    $analysis = (new SemanticAnalyzer())->analyze(new ProjectParseResult(
        [$source->path => $parsed->parsedFile], [$source->path => $source], $parsed->diagnostics,
    ));
    expect($analysis->isSuccessful)->toBeTrue();
    $generated = (new PhpLowerer())->lower($parsed->parsedFile, $analysis->findModel($source->path));
    expect($generated->contents)->toBe($php);
    expect((new AnalysisSourceProjector())->project($generated))->not->toContain('catch (\\Throwable');
    $runtime = new Process([PHP_BINARY, $path], timeout: 5);
    $runtime->mustRun();
    expect($runtime->getOutput())->toBe($native->getOutput())
        ->and($runtime->getErrorOutput())->toBe('')->and($native->getErrorOutput())->toBe('');
})->with([
    'ordinary break through cleanup' => 'foreach ([1, 2] as int $value) { try { if ($select) { break; } echo "body|"; } finally { echo "cleanup|"; } echo "tail|"; } return -1;',
    'ordinary continue through cleanup' => 'foreach ([1, 2] as int $value) { try { if ($select) { continue; } echo "body|"; } finally { echo "cleanup|"; } echo "tail|"; } return -1;',
    'ordinary numbered break through nested cleanup' => 'foreach ([10, 20] as int $outer) { foreach ([1, 2] as int $value) { try { try { if ($select) { break 2; } echo "body|"; } finally { echo "inner|"; } } finally { echo "outer|"; } } echo "tail|"; } return -1;',
    'ordinary numbered continue through a switch and cleanup' => 'foreach ([1, 2] as int $value) { switch ($value) { case 1: try { if ($select) { continue 2; } } finally { echo "cleanup|"; } break; default: echo "second|"; } echo "tail|"; } return -1;',
    'ordinary catch transfer still runs cleanup' => 'foreach ([1, 2] as int $value) { try { if ($select) { throw new Error(); } echo "body|"; } catch (Error $error) { continue; } finally { echo "cleanup|"; } echo "tail|"; } return -1;',
    'ordinary for continue reaches its update' => 'for (int $index = 0; $index < 2; $index++) { try { if ($select) { continue; } echo "body|"; } finally { echo $index, "|"; } } return -1;',
    'ordinary cleanup ignores nested callable returns' => 'foreach ([1, 2] as int $value) { try { if ($select) { continue; } } finally { (function (): void { echo "callback|"; return; })(); } echo "tail|"; } return -1;',
    'ordinary inner transfer under an enclosing finally' => 'try { return 7; } finally { foreach ([1, 2] as int $value) { try { if ($select) { break; } } finally { echo "inner|"; } echo "tail|"; } }',
    'deferred continue gives finally result priority' => 'foreach (items() as mixed $value) { try { continue; } finally { if ($select) { return 9; } echo "cleanup|"; } } return -1;',
    'deferred break gives finally result priority' => 'foreach (items() as mixed $value) { try { break; } finally { if ($select) { return 9; } echo "cleanup|"; } } return -1;',
    'declaration body retains a deferred continue target' => 'foreach (items() as mixed $value) { try { declare(ticks=1) { if ($value === 1) { continue; } echo "body|"; } echo "between|"; } finally { if ($select) { return 9; } echo "cleanup|"; } echo "tail|"; } return -1;',
    'declaration body retains a deferred numbered break target' => 'foreach ([10, 20] as int $outer) { try { foreach (items() as mixed $value) { declare(ticks=1) { if ($value === 1) { break 2; } } } echo "between|"; } finally { if ($select) { return 9; } echo "cleanup|"; } echo "tail|"; } return -1;',
    'single transfer crosses nested value scopes without a discriminator' => 'foreach (items() as mixed $value) { try { try { continue; } finally { if ($select) { return 9; } echo "inner|"; } } finally { if ($value === 2) { return 7; } echo "outer|"; } } return -1;',
    'single transfer resumes from either try or catch without a discriminator' => 'foreach (items() as mixed $value) { try { try { if ($value === 1) { throw new Error(); } continue; } finally { echo "inner|"; } } catch (Error $error) { continue; } finally { if ($select) { return 9; } echo "outer|"; } } return -1;',
    'deferred transfer retains an enclosing ordinary cleanup' => 'foreach (items() as mixed $value) { try { try { if ($value === 1) { continue; } echo "body|"; } finally { if ($select) { return 9; } echo "inner|"; } echo "between|"; } finally { echo "outer|"; } echo "tail|"; } return -1;',
    'deferred transfer crosses two result capable cleanups' => 'foreach (items() as mixed $value) { try { try { if ($value === 1) { continue; } echo "body|"; } finally { if ($select) { return 9; } echo "inner|"; } echo "between|"; } finally { if (!$select) { return 7; } echo "outer|"; } echo "tail|"; } return -1;',
    'deferred numbered continue preserves its target after inner loop exit' => 'foreach ([10, 20] as int $outer) { try { foreach (items() as mixed $value) { try { if ($value === 1) { continue 2; } } finally { echo "inner|"; } } echo "between|"; } finally { if ($select) { return 9; } echo "outer|"; } echo "tail|"; } return -1;',
    'deferred alternative break and continue retain distinct targets' => 'foreach (items() as mixed $value) { try { if ($value === 1) { continue; } break; } finally { if ($select) { return 9; } echo "cleanup|"; } } return -1;',
    'deferred body result wins over a conditional transfer' => 'foreach (items() as mixed $value) { try { if ($value === 1) { continue; } return 3; } finally { if ($select) { return 9; } echo "cleanup|"; } } return -1;',
    'deferred catch origin still runs its finally' => 'foreach (items() as mixed $value) { try { if ($value === 1) { throw new Error(); } echo "body|"; } catch (Error $error) { continue; } finally { if ($select) { return 9; } echo "cleanup|"; } echo "tail|"; } return -1;',
    'deferred transfer is cancelled by a caught cleanup failure' => 'foreach (items() as mixed $value) { try { try { try { continue; } finally { if ($value === 1) { throw new Error(); } } } catch (Error $error) { echo "caught|"; } echo "between|"; } finally { if ($select) { return 9; } echo "cleanup|"; } echo "tail|"; } return -1;',
    'deferred transfer survives an unrelated catch inside finally' => 'foreach (items() as mixed $value) { try { continue; } finally { try { throw new Error(); } catch (Error $error) { echo "caught|"; } if ($select) { return 9; } echo "cleanup|"; } } return -1;',
    'deferred inner transfer has a finally local result domain' => 'try { return 7; } finally { foreach (items() as mixed $value) { try { continue; } finally { if ($select) { return 9; } echo "inner|"; } } echo "tail|"; }',
    'deferred for continue reaches the update only without a result' => 'for (int $index = 0; $index < 2; $index++) { try { continue; } finally { if ($select) { return 9; } echo $index, "|"; } } return -1;',
    'deferred switch continue retains its enclosing loop' => 'foreach (items() as mixed $value) { switch ($value) { case 1: try { continue 2; } finally { if ($select) { return 9; } echo "cleanup|"; } default: echo "second|"; } echo "tail|"; } return -1;',
    'deferred switch break leaves the same outer loop' => 'foreach (items() as mixed $value) { switch ($value) { case 1: try { break 2; } finally { if ($select) { return 9; } echo "cleanup|"; } default: echo "second|"; } echo "tail|"; } return -1;',
    'deferred transfer leaves a switch before outer cleanup' => 'foreach (items() as mixed $value) { try { switch ($value) { case 1: continue 2; default: echo "second|"; } echo "between|"; } finally { if ($select) { return 9; } echo "cleanup|"; } echo "tail|"; } return -1;',
    'terminating throw operand is not a finally result' => 'try { foreach (items() as mixed $value) { try { continue; } finally { if ($select) { return throw new Error("cleanup"); } echo "cleanup|"; } } } catch (Error $error) { echo "caught|"; } return -1;',
    'terminating call operand is not a finally result' => 'try { foreach (items() as mixed $value) { try { continue; } finally { if ($select) { return failCleanup(); } echo "cleanup|"; } } } catch (Error $error) { echo "caught|"; } return -1;',
    'terminating outer cleanup does not hide an inner result barrier' => 'try { foreach (items() as mixed $value) { try { try { continue; } finally { if ($select) { return 9; } echo "inner|"; } } finally { if ($value === 2) { return failCleanup(); } echo "outer|"; } } } catch (Error $error) { echo "caught|"; } return -1;',
])->with(['assignment', 'return'])->with([
    'non-null result' => ['int', '9'],
    'nullable result' => ['int|null', 'null'],
    'object result' => ['int|Result', 'new Result()'],
]);
