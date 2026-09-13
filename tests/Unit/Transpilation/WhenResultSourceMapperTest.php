<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Frontend\PpphpParser;
use Atatusoft\Ppphp\Project\ProjectParseResult;
use Atatusoft\Ppphp\Semantic\SemanticAnalyzer;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;
use Atatusoft\Ppphp\Transpilation\PhpLowerer;
use Atatusoft\Ppphp\Transpilation\TranspilationContext;
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

test('completed result metadata identifies actual surviving definitions and excludes generated seeds', function (
    string $body, array $expected, string $consumer = 'return VALUE;',
): void {
    $source = new SourceFile('/project/main.ppphp', 'main.ppphp', FileKind::Ppphp, '<?php
function choose(bool $ready, bool $fail, bool $replace, callable $factory): mixed {
    ' . str_replace('VALUE', 'when ($ready) { ' . $body . ' } else { return 0; }', $consumer) . '
}');
    $parsed = (new PpphpParser())->parse($source);
    $analysis = (new SemanticAnalyzer())->analyze(new ProjectParseResult(
        [$source->path => $parsed->parsedFile], [$source->path => $source], $parsed->diagnostics,
    ));
    expect($analysis->isSuccessful)->toBeTrue();
    $generated = (new PhpLowerer())->lower($parsed->parsedFile, $analysis->findModel($source->path));
    expect($generated->completedResults)->toHaveCount(1, $generated->contents);
    $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse($generated->contents);
    $assignments = [];
    foreach ((new NodeFinder())->findInstanceOf($statements, Expr\Assign::class) as $assignment) {
        $assignments[$assignment->getStartFilePos()] = $assignment;
    }
    $printer = new Standard();
    foreach ($generated->completedResults as $offset => $fact) {
        expect(substr($generated->contents, $offset, strlen($fact['name']) + 1))->toBe('$' . $fact['name']);
        $values = array_map(fn (int $definition): string => $printer->prettyPrintExpr($assignments[$definition]->expr), $fact['definitions']);
        expect($values)->toBe($expected);
    }
})->with([
    'throwing path does not contribute its seed' => [
        'try { if ($fail) { throw new Error(); } return 1; } finally { if ($replace) { return "replacement"; } }',
        ['1', '"replacement"', '0'],
    ],
    'a real nullable contribution is retained' => [
        'try { if ($fail) { throw new Error(); } return $replace ? 1 : null; } finally { if ($replace) { return "replacement"; } }',
        ['$replace ? 1 : null', '"replacement"', '0'],
    ],
    'an unknown contribution is retained' => [
        'try { if ($fail) { throw new Error(); } return $factory(); } finally { if ($replace) { return "replacement"; } }',
        ['$factory()', '"replacement"', '0'],
    ],
    'an overwritten value is not a completed contribution' => [
        'try { return new stdClass(); } finally { return 7; }', ['7', '0'],
    ],
    'anonymous class arguments precede their class body in emitted PHP' => [
        'try { if ($fail) { throw new Error(); } return 1; } finally { if ($replace) { return "replacement"; } }',
        ['1', '"replacement"', '0'],
        'return new class(VALUE) { public function __construct(public mixed $item) {} };',
    ],
    'an inlined nested result retains its effective contributions' => [
        'try { if ($fail) { throw new Error(); } return when ($replace) { return 1; } else { return 2; }; }
            finally { if ($replace) { return 3; } }', ['$replace ? 1 : 2', '3', '0'],
    ],
    'a statementful nested result retains its effective contributions' => [
        'try { if ($fail) { throw new Error(); } return when ($replace) { echo ""; return 1; } else { return 2; }; }
            finally { if ($replace) { return 3; } }', ['1', '2', '3', '0'],
    ],
]);

test('completed result metadata follows retained edits and not discarded nested replacements', function (): void {
    $source = new SourceFile('/project/main.ppphp', 'main.ppphp', FileKind::Ppphp, '<?php /* outer inner */ echo 1;');
    $parsed = (new PpphpParser())->parse($source);
    $analysis = (new SemanticAnalyzer())->analyze(new ProjectParseResult(
        [$source->path => $parsed->parsedFile], [$source->path => $source], $parsed->diagnostics,
    ));
    $context = new TranspilationContext($parsed->parsedFile, $analysis->findModel($source->path));
    $discarded = '$discarded = 2; echo $discarded;';
    $kept = '$kept = 1; echo $kept;';
    $context->replace($source->createSpan(15, 20), $discarded, completedResults: [
        strrpos($discarded, '$') => ['name' => 'discarded', 'definitions' => [0]],
    ]);
    $context->replace($source->createSpan(6, 23), $kept, completedResults: [
        strrpos($kept, '$') => ['name' => 'kept', 'definitions' => [0]],
    ]);
    $context->replace($source->createSpan(0, 5), '<?php declare(strict_types=1);');
    $generated = $context->generate();
    expect($generated->contents)->not->toContain('$discarded');
    expect($generated->completedResults)->toBe([
        strrpos($generated->contents, '$kept') => [
            'name' => 'kept', 'definitions' => [strpos($generated->contents, '$kept')],
        ],
    ]);
});

test('completed result metadata rejects positions outside its emitted edit', function (int $position): void {
    $source = new SourceFile('/project/main.ppphp', 'main.ppphp', FileKind::Ppphp, '<?php echo 1;');
    $parsed = (new PpphpParser())->parse($source);
    $analysis = (new SemanticAnalyzer())->analyze(new ProjectParseResult(
        [$source->path => $parsed->parsedFile], [$source->path => $source], $parsed->diagnostics,
    ));
    $context = new TranspilationContext($parsed->parsedFile, $analysis->findModel($source->path));
    expect(fn () => $context->replace($source->createSpan(6, 13), '$value = 1;', completedResults: [
        0 => ['name' => 'value', 'definitions' => [$position]],
    ]))->toThrow(InvalidArgumentException::class);
    expect($context->sourceEdits)->toBe([]);
})->with(['before edit' => [-1], 'not a variable' => [1], 'after edit' => [11]]);
