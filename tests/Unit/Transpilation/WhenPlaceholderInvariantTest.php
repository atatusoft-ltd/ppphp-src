<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Frontend\PpphpParser;
use Atatusoft\Ppphp\Project\ProjectParseResult;
use Atatusoft\Ppphp\Semantic\SemanticAnalyzer;
use Atatusoft\Ppphp\Semantic\When\WhenExpressionAnalysis;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;
use Atatusoft\Ppphp\Support\Path;
use Atatusoft\Ppphp\Transpilation\Pass\LowerWhenExpressionsPass;
use Atatusoft\Ppphp\Transpilation\TranspilationContext;
use PhpParser\Node\Stmt;

test('when lowering rejects surviving markers but permits authored null values', function (bool $loseSite): void {
    $source = new SourceFile('/project/main.ppphp', 'main.ppphp', FileKind::Ppphp, <<<'PPP'
<?php
?int $value = when (getenv('READY') === '1') { return null; } else { return 2; };
PPP);
    $parse = (new PpphpParser())->parse($source);
    expect($parse->parsedFile)->not->toBeNull();
    $key = Path::buildComparisonKey($source->path);
    $analysis = (new SemanticAnalyzer())->analyze(new ProjectParseResult(
        [$key => $parse->parsedFile], [$key => $source], $parse->diagnostics,
    ));
    $model = $analysis->findModel($source->path);
    expect($analysis->isSuccessful)->toBeTrue()->and($model)->not->toBeNull();
    if ($loseSite) {
        // Simulate a future checker/lowerer mismatch after successful analysis.
        // Normal unsupported input is rejected as P5005 before reaching this pass.
        $when = $model->whenExpressions->expressions[0];
        $model->whenExpressions->record(new WhenExpressionAnalysis(
            $when->syntax, $when->site, $when->placeholder,
            new Stmt\Echo_([$when->placeholder], $when->statement->getAttributes()),
            $when->branches, $when->resultType, $when->temporaryName,
        ));
    }
    $context = new TranspilationContext($parse->parsedFile, $model);
    $lowerer = new LowerWhenExpressionsPass();
    if ($loseSite) {
        expect(fn () => $lowerer->execute($context))
            ->toThrow(LogicException::class, 'A when placeholder survived statement lowering.');
        expect($context->sourceEdits)->toBe([]);
    } else {
        $lowerer->execute($context);
        expect($context->generate()->contents)->toContain('= null;')->not->toContain('when (');
    }
})->with(['lost lowering site' => true, 'authored null' => false]);
