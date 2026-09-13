<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;
use Atatusoft\Ppphp\Transpilation\VariableWriteSourceMapper;
use Atatusoft\Ppphp\Transpilation\WhenPhpPrinter;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;

test('an identical generated seed cannot steal an authored write origin', function (): void {
    $source = new SourceFile('/project/main.ppphp', 'main.ppphp', FileKind::Ppphp, '<?php $value = null;');
    $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse($source->contents);
    array_unshift($statements, new Stmt\Expression(new Expr\Assign(new Expr\Variable('value'), new Expr\ConstFetch(new Name('null')))));
    $replacement = (new WhenPhpPrinter())->prettyPrint($statements);
    $mappings = (new VariableWriteSourceMapper())->map($statements, $replacement, $source, []);
    expect($mappings)->toHaveCount(1)
        ->and($mappings[0]->replacementStart)->toBe(strrpos($replacement, '$value'))
        ->and($mappings[0]->origin->start->offset)->toBe(strpos($source->contents, '$value'));
});

test('write origins survive multiline indentation comments and repeated names', function (): void {
    $source = new SourceFile('/project/main.ppphp', 'main.ppphp', FileKind::Ppphp, '<?php $value = choose(/* $value = choose(1); */ 1); $value = choose(1);');
    $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse($source->contents);
    $replacement = str_replace("\n", "\n    ", (new WhenPhpPrinter())->prettyPrint($statements));
    $mappings = (new VariableWriteSourceMapper())->map($statements, $replacement, $source, []);
    expect($mappings)->toHaveCount(2)
        ->and($mappings[0]->origin->start->offset)->toBe(strpos($source->contents, '$value'))
        ->and($mappings[1]->origin->start->offset)->toBe(strrpos($source->contents, '$value'));
});
