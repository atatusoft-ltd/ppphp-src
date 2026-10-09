<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Analysis\AnalysisSourceProjector;
use Atatusoft\Ppphp\Frontend\PpphpParser;
use Atatusoft\Ppphp\Project\ProjectParseResult;
use Atatusoft\Ppphp\Semantic\SemanticAnalyzer;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;
use Atatusoft\Ppphp\Transpilation\PhpLowerer;
use Atatusoft\Ppphp\Transpilation\TranspilationContext;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

test('unwind projection preserves authored handlers and runtime bytes with exact cleanup ownership', function (): void {
    $source = new SourceFile('/project/main.ppphp', 'main.ppphp', FileKind::Ppphp, <<<'PHP'
<?php
function consume(mixed $value): mixed { return $value; }
function choose(bool $ready, callable $factory): mixed throws Throwable {
    try {
        return consume(when ($ready) {
            // Keep this explanation in the runtime and analysis views.
            try { return consume(when ($ready) { echo ''; return $factory(); } else { return null; }); }
            catch (Throwable $authored) { throw $authored; }
        } else { return null; });
    } catch (Throwable $__ppphp_cleanup_error_0) { throw $__ppphp_cleanup_error_0; }
}
PHP);
    $parsed = (new PpphpParser())->parse($source);
    $analysis = (new SemanticAnalyzer())->analyze(new ProjectParseResult(
        [$source->path => $parsed->parsedFile], [$source->path => $source], $parsed->diagnostics,
    ));
    expect($analysis->isSuccessful)->toBeTrue();
    $generated = (new PhpLowerer())->lower($parsed->parsedFile, $analysis->findModel($source->path));
    $runtime = $generated->contents;
    $projection = (new AnalysisSourceProjector())->project($generated);
    expect(count($generated->unwindCleanups))->toBeGreaterThanOrEqual(2)
        ->and(strlen($projection))->toBe(strlen($runtime))
        ->and(substr_count($projection, "\n"))->toBe(substr_count($runtime, "\n"))
        ->and($generated->contents)->toBe($runtime)
        ->and($projection)->toContain('throw $authored;', 'throw $__ppphp_cleanup_error_0;', 'Keep this explanation');
    $parser = (new ParserFactory())->createForNewestSupportedVersion();
    $finder = new NodeFinder();
    $runtimeCatches = $finder->findInstanceOf($parser->parse($runtime), Stmt\Catch_::class);
    $sourceCatches = $finder->findInstanceOf($parser->parse($projection), Stmt\Catch_::class);
    expect($sourceCatches)->toHaveCount(2)
        ->and(count($runtimeCatches) - count($sourceCatches))->toBe(count($generated->unwindCleanups));
    $cursor = 0;
    foreach ($generated->unwindCleanups as $range) {
        expect(substr($projection, $cursor, $range['start'] - $cursor))
            ->toBe(substr($runtime, $cursor, $range['start'] - $cursor));
        $cursor = $range['end'];
    }
    expect(substr($projection, $cursor))->toBe(substr($runtime, $cursor));
});

test('unwind provenance follows only retained edits and is absent without a lowering proof', function (bool $retained): void {
    $source = new SourceFile('/project/main.ppphp', 'main.ppphp', FileKind::Ppphp, '<?php /* outer inner */ echo 1;');
    $parsed = (new PpphpParser())->parse($source);
    $analysis = (new SemanticAnalyzer())->analyze(new ProjectParseResult(
        [$source->path => $parsed->parsedFile], [$source->path => $source], $parsed->diagnostics,
    ));
    $context = new TranspilationContext($parsed->parsedFile, $analysis->findModel($source->path));
    $catch = 'catch (Throwable $error) { throw $error; }';
    $php = 'try {} ' . $catch . ' finally {}';
    $context->replace($source->createSpan(15, 20), $php, unwindCleanups: [
        ['start' => strpos($php, 'catch'), 'end' => strpos($php, ' finally')],
    ]);
    $ranges = $retained ? [['start' => strpos($php, 'catch'), 'end' => strpos($php, ' finally')]] : [];
    $context->replace($source->createSpan(6, 23), $php, unwindCleanups: $ranges);
    $context->replace($source->createSpan(0, 5), '<?php declare(strict_types=1);');
    $generated = $context->generate();
    if (!$retained) {
        expect($generated->unwindCleanups)->toBe([])
            ->and((new AnalysisSourceProjector())->project($generated))->toBe($generated->contents);
        return;
    }
    expect($generated->unwindCleanups)->toBe([
        ['start' => strpos($generated->contents, 'catch'), 'end' => strpos($generated->contents, ' finally')],
    ])->and($generated->contents)->toContain($catch);
    expect((new AnalysisSourceProjector())->project($generated))->not->toContain($catch);
})->with([false, true]);

test('unwind provenance rejects invalid or overlapping ranges', function (array $ranges): void {
    $source = new SourceFile('/project/main.ppphp', 'main.ppphp', FileKind::Ppphp, '<?php echo 1;');
    $parsed = (new PpphpParser())->parse($source);
    $analysis = (new SemanticAnalyzer())->analyze(new ProjectParseResult(
        [$source->path => $parsed->parsedFile], [$source->path => $source], $parsed->diagnostics,
    ));
    $context = new TranspilationContext($parsed->parsedFile, $analysis->findModel($source->path));
    expect(fn () => $context->replace($source->createSpan(6, 13), 'try {} catch (Throwable $e) {} finally {}', unwindCleanups: $ranges))
        ->toThrow(InvalidArgumentException::class);
    expect($context->sourceEdits)->toBe([]);
})->with([
    'negative start' => [[['start' => -1, 'end' => 29]]],
    'outside edit' => [[['start' => 7, 'end' => 100]]],
    'not a catch' => [[['start' => 0, 'end' => 6]]],
    'overlapping catches' => [[['start' => 7, 'end' => 29], ['start' => 7, 'end' => 29]]],
]);
