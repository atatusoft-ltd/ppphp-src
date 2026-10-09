<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Transpilation\WhenPhpPrinter;
use PhpParser\NodeDumper;
use PhpParser\ParserFactory;

test('header comments survive printing without duplication', function (string $source): void {
    $parser = (new ParserFactory())->createForNewestSupportedVersion();
    $statements = $parser->parse('<?php ' . $source);
    $php = (new WhenPhpPrinter())->prettyPrint($statements);
    expect(substr_count($php, 'Keep this explanation.'))->toBe(1)
        ->and((new NodeDumper(['dumpComments' => true]))->dump($parser->parse('<?php ' . $php)))
        ->toBe((new NodeDumper(['dumpComments' => true]))->dump($statements));
})->with([
    'for initializer' => 'for (/* Keep this explanation. */ $n = 0; $n < 1; ++$n) {}',
    'for condition' => 'for ($n = 0; /* Keep this explanation. */ $n < 1; ++$n) {}',
    'for update' => 'for ($n = 0; $n < 1; /* Keep this explanation. */ ++$n) {}',
    'foreach binding' => 'foreach ([1] as /* Keep this explanation. */ $n) {}',
    'argument comment control' => 'show(/* Keep this explanation. */ $value);',
    'array item comment control' => '$values = [/* Keep this explanation. */ 1];',
    'parameter comment control' => 'function show(/** Keep this explanation. */ int $value): void {}',
    'return type' => 'function show(): /* Keep this explanation. */ int { return 1; }',
    'return operand' => 'function show(int $value): int { return /* Keep this explanation. */ $value; }',
    'binary operand' => '$value = $a + /* Keep this explanation. */ $b;',
    'line comment' => "foreach ([1] as // Keep this explanation.\n\$n) {}",
    'statement comment control' => '/* Keep this explanation. */ foreach ([1] as $n) {}',
]);

test('comment identity and printer reset do not discard distinct equal comments', function (): void {
    $parser = (new ParserFactory())->createForNewestSupportedVersion();
    $statements = $parser->parse('<?php show(/* Repeated text. */ $left, /* Repeated text. */ $right);');
    $printer = new WhenPhpPrinter();
    $first = $printer->prettyPrint($statements);
    expect(substr_count($first, 'Repeated text.'))->toBe(2)
        ->and($printer->prettyPrint($statements))->toBe($first)
        ->and($printer->prettyPrintExpr($statements[0]->expr))->toBe(substr($first, 0, -1));
});

test('conditional grouping preserves the parsed expression', function (string $expression, string $expected): void {
    $parser = (new ParserFactory())->createForNewestSupportedVersion();
    $source = $parser->parse('<?php return ' . $expression . ';');
    $php = (new WhenPhpPrinter())->prettyPrint($source);
    expect($php)->toBe('return ' . $expected . ';')
        ->and((new NodeDumper())->dump($parser->parse('<?php ' . $php)))
        ->toBe((new NodeDumper())->dump($source));
})->with([
    ['$a ? ($b ? 1 : 2) : 3', '$a ? ($b ? 1 : 2) : 3'],
    ['$a ? 1 : ($b ? 2 : 3)', '$a ? 1 : ($b ? 2 : 3)'],
    ['$a ? ($b ?: 2) : 3', '$a ? ($b ?: 2) : 3'],
    ['($a ? ($b ? 1 : 2) : 3) + 4', '($a ? ($b ? 1 : 2) : 3) + 4'],
    ['($a ? ($b ? 1 : 2) : 3) ? 4 : 5', '($a ? ($b ? 1 : 2) : 3) ? 4 : 5'],
]);
