<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Analysis\GeneratedTypeDeclarationIndex;
use Atatusoft\Ppphp\Frontend\PpphpParser;
use Atatusoft\Ppphp\Project\ProjectParseResult;
use Atatusoft\Ppphp\Semantic\SemanticAnalyzer;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;
use Atatusoft\Ppphp\Transpilation\PhpLowerer;

test('assertion policy requires verified compatibility and unambiguous generated provenance', function (): void {
    $source = new SourceFile('/project/main.ppphp', 'main.ppphp', FileKind::Ppphp, <<<'PPP'
<?php
function describe(callable $factory): void
{
    string $unknown = $factory();
    string $known = $unknown . ': done';
    object $value = new stdClass();
    string $sameLine = 'text'; /** @var string $value */ $value = new stdClass();
    echo $known;
}
PPP);
    $parsed = (new PpphpParser())->parse($source);
    $analysis = (new SemanticAnalyzer())->analyze(new ProjectParseResult(
        [$source->path => $parsed->parsedFile], [$source->path => $source], $parsed->diagnostics,
    ));
    expect($analysis->isSuccessful)->toBeTrue();
    $generated = (new PhpLowerer())->lower($parsed->parsedFile, $analysis->findModel($source->path));
    $lines = (new GeneratedTypeDeclarationIndex())->collect($generated, $parsed->parsedFile, $analysis);
    foreach (explode("\n", $generated->contents) as $index => $line) {
        if (str_contains($line, '@var string $unknown') || str_contains($line, '@var string $sameLine')) {
            expect($lines)->not->toContain($index + 1);
        }
        if (str_contains($line, '@var string $known') || str_contains($line, '@var object $value')) {
            expect($lines)->toContain($index + 1);
        }
    }
});

test('combined when annotations retain unknown assertion checks in either order', function (bool $unknownFirst): void {
    $unknown = 'when ($ready) { return $factory(); } else { return "unknown"; }';
    $known = 'when ($ready) { return "ready"; } else { return "waiting"; }';
    $values = $unknownFirst ? [$unknown, $known] : [$known, $unknown];
    $source = new SourceFile('/project/main.ppphp', 'main.ppphp', FileKind::Ppphp, '<?php
function combine(mixed $left, mixed $right): string { return "ok"; }
function describe(bool $ready, callable $factory): string { return combine(' . implode(', ', $values) . '); }');
    $parsed = (new PpphpParser())->parse($source);
    $analysis = (new SemanticAnalyzer())->analyze(new ProjectParseResult(
        [$source->path => $parsed->parsedFile], [$source->path => $source], $parsed->diagnostics,
    ));
    expect($analysis->isSuccessful)->toBeTrue();
    $generated = (new PhpLowerer())->lower($parsed->parsedFile, $analysis->findModel($source->path));
    expect((new GeneratedTypeDeclarationIndex())->collect($generated, $parsed->parsedFile, $analysis))->toBe([]);
})->with([true, false]);
