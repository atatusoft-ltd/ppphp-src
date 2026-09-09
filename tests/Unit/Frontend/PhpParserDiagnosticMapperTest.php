<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Frontend\PhpParserDiagnosticMapper;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;
use PhpParser\Error;

function createMappedSource(string $contents): SourceFile
{
    return new SourceFile(
        '/project/src/Invalid.ppphp',
        'src/Invalid.ppphp',
        FileKind::Ppphp,
        $contents,
    );
}

test('exact parser offsets become a half-open source span', function (): void {
    $source = createMappedSource("<?php\nreturn value\n");
    $start = strpos($source->contents, 'value');
    $error = new Error('Unexpected token', [
        'startLine' => 2,
        'endLine' => 2,
        'startFilePos' => $start,
        'endFilePos' => $start + 4,
    ]);
    $diagnostic = (new PhpParserDiagnosticMapper())->map($error, $source);
    $span = $diagnostic->primary?->span;

    expect($span?->start->offset)->toBe($start)
        ->and($span?->end->offset)->toBe($start + 5)
        ->and($span?->text)->toBe('value')
        ->and($span?->start->line)->toBe(2)
        ->and($span?->start->column)->toBe(8);
});

test('EOF parser positions remain an empty span at EOF', function (): void {
    $source = createMappedSource("<?php\nif (true) {");
    $end = $source->length;
    $error = new Error('Unexpected end of file', [
        'startLine' => 2,
        'endLine' => 2,
        'startFilePos' => $end,
        'endFilePos' => $end,
    ]);
    $span = (new PhpParserDiagnosticMapper())->map($error, $source)->primary?->span;

    expect($span?->start->offset)->toBe($end)
        ->and($span?->end->offset)->toBe($end)
        ->and($span?->isEmpty)->toBeTrue();
});

test('line-only parser errors use the start of the reported original line', function (): void {
    $source = createMappedSource("<?php\r\nfirst();\r\nsecond();\r\n");
    $error = new Error('Line-level error', ['startLine' => 3, 'endLine' => 3]);
    $span = (new PhpParserDiagnosticMapper())->map($error, $source)->primary?->span;

    expect($span?->start->offset)->toBe(strpos($source->contents, 'second'))
        ->and($span?->start->line)->toBe(3)
        ->and($span?->start->column)->toBe(1)
        ->and($span?->isEmpty)->toBeTrue();
});

test('multibyte text before an exact byte offset produces a code-point column', function (): void {
    $source = createMappedSource("<?php\né \$broken\n");
    $start = strpos($source->contents, '$broken');
    $error = new Error('Unexpected variable', [
        'startLine' => 2,
        'endLine' => 2,
        'startFilePos' => $start,
        'endFilePos' => $start + strlen('$broken') - 1,
    ]);
    $span = (new PhpParserDiagnosticMapper())->map($error, $source)->primary?->span;

    expect($span?->start->column)->toBe(3)
        ->and($span?->text)->toBe('$broken');
});

test('ordinary parser errors point to the original ppphp path without internal details', function (): void {
    $source = createMappedSource("<?php\nreturn 'missing'\n");
    $result = (new Atatusoft\Ppphp\Frontend\PpphpParser())->parse($source);
    $diagnostic = $result->diagnostics->errors[0] ?? null;

    expect($diagnostic?->code->value)->toBe('P1001')
        ->and($diagnostic?->title)->toBe('Syntax Error')
        ->and($diagnostic?->primary?->span->sourceFile->displayPath)->toBe('src/Invalid.ppphp')
        ->and($diagnostic?->message)->not->toContain('PhpParser\\');
});

test('real parser locations remain accurate for first-line CRLF multibyte and EOF errors', function (
    string $contents,
    int $line,
    int $column,
): void {
    $source = createMappedSource($contents);
    $result = (new Atatusoft\Ppphp\Frontend\PpphpParser())->parse($source);
    $start = $result->diagnostics->errors[0]->primary?->span->start;

    expect($result->hasErrors)->toBeTrue()
        ->and($start?->line)->toBe($line)
        ->and($start?->column)->toBe($column);
})->with([
    'first line' => ['<?php echo ;', 1, 12],
    'CRLF after multibyte source' => ["<?php\r\n\$value = 'é'\r\necho 1;\r\n", 3, 1],
    'error at EOF' => ["<?php\nif (true) {", 2, 12],
]);
