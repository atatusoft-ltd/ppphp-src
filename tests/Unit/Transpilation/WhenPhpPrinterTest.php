<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Transpilation\WhenPhpPrinter;
use PhpParser\NodeDumper;
use PhpParser\ParserFactory;

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
