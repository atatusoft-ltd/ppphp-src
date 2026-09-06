<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Frontend\Enumerations\ParseMode;
use Atatusoft\Ppphp\Frontend\ParseResult;
use Atatusoft\Ppphp\Frontend\PpphpParser;
use Atatusoft\Ppphp\Frontend\PhpParserAdapter;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;
use PhpParser\Node\Stmt\Function_;

function createParserSource(string $contents, string $name = 'Example.ppphp'): SourceFile
{
    return new SourceFile(
        '/project/src/' . $name,
        'src/' . $name,
        FileKind::Ppphp,
        $contents,
    );
}

test('missing opening tags are source diagnostics before extension parsing or lowering', function (string $contents): void {
    $result = (new PpphpParser())->parse(createParserSource($contents));

    expect($result->parsedFile)->toBeNull()
        ->and($result->diagnostics->errors)->toHaveCount(1);
    $diagnostic = $result->diagnostics->errors[0];
    expect($diagnostic->code)->toBe(DiagnosticCode::MissingPhpOpeningTag)
        ->and($diagnostic->message)->toBe('This ++PHP file is missing a PHP opening tag.')
        ->and($diagnostic->help)->toContain('<?php')
        ->and($diagnostic->primary?->span->start->offset)->toBe(0)
        ->and($diagnostic->primary?->span->end->offset)->toBe(0);
})->with(['final class Box<T> {}', '', " \n\t", '<?phpfinal class Box {}']);

test('opening-tag validation retains PHP mode inline text and valid tagged sources', function (): void {
    $parser = new PpphpParser();
    expect($parser->parse(createParserSource('plain text'), ParseMode::Php)->isSuccessful)->toBeTrue()
        ->and($parser->parse(createParserSource('<?php final class Box<T> {}'))->isSuccessful)->toBeTrue()
        ->and($parser->parse(createParserSource("#!/usr/bin/env ppphp\n<?php\n"))->isSuccessful)->toBeTrue();
});

test('the ordinary frontend retains an empty PHP program and its tokens', function (): void {
    $source = createParserSource('<?php');
    $result = (new PpphpParser())->parse($source);
    $parsedFile = $result->parsedFile;

    expect($result->isSuccessful)->toBeTrue()
        ->and($result->hasErrors)->toBeFalse()
        ->and($parsedFile)->not->toBeNull()
        ->and($parsedFile?->sourceFile)->toBe($source)
        ->and($parsedFile?->mode)->toBe(ParseMode::PlusPlusPhp)
        ->and($parsedFile?->statements)->toBe([])
        ->and($parsedFile?->tokens)->not->toBeEmpty();
});

test('the ordinary frontend retains AST comments tokens and source positions', function (): void {
    $contents = "<?php\n\n// retained comment\nfunction answer(): int\n{\n    return 42;\n}\n";
    $result = (new PpphpParser())->parse(createParserSource($contents));
    $parsedFile = $result->parsedFile;
    $statement = $parsedFile?->statements[0] ?? null;

    expect($result->isSuccessful)->toBeTrue()
        ->and($statement)->toBeInstanceOf(Function_::class)
        ->and($statement?->getComments()[0]->getText())->toBe('// retained comment')
        ->and($statement?->getStartLine())->toBe(4)
        ->and($statement?->getEndLine())->toBe(7)
        ->and($statement?->getStartFilePos())->toBe(strpos($contents, 'function'))
        ->and($statement?->getEndFilePos())->toBe(strrpos($contents, '}'))
        ->and($statement?->getStartTokenPos())->toBeGreaterThanOrEqual(0)
        ->and($statement?->getEndTokenPos())->toBeGreaterThan($statement?->getStartTokenPos() ?? -1)
        ->and($parsedFile?->tokens)->not->toBeEmpty();
});

test('the adapter accepts the configured PHP 8.4 grammar', function (): void {
    $contents = (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/Parsing/Valid/ModernPhp84.ppphp');
    $result = (new PhpParserAdapter('8.4'))->parse(createParserSource($contents), ParseMode::Php);

    expect($result->isSuccessful)->toBeTrue()
        ->and($result->parsedFile?->mode)->toBe(ParseMode::Php);
});

test('the adapter rejects unsupported target versions', function (string $version): void {
    expect(fn (): PhpParserAdapter => new PhpParserAdapter($version))
        ->toThrow(InvalidArgumentException::class, 'supports only PHP 8.4');
})->with(['8.3', '8.5']);

test('the parser collects recoverable errors and never reports them as success', function (): void {
    $source = createParserSource('<?php function first() { $a = ; } function second() { $b = ; }');
    $result = (new PpphpParser())->parse($source);

    expect($result->isSuccessful)->toBeFalse()
        ->and($result->hasErrors)->toBeTrue()
        ->and(count($result->diagnostics->errors))->toBeGreaterThanOrEqual(2);
});

test('the invalid ordinary PHP parsing corpus produces syntax diagnostics', function (string $fixture): void {
    $contents = (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/Parsing/Invalid/' . $fixture);
    $result = (new PpphpParser())->parse(createParserSource($contents, $fixture));

    expect($result->isSuccessful)->toBeFalse()
        ->and($result->hasErrors)->toBeTrue()
        ->and($result->diagnostics->errors[0]->code)->toBe(DiagnosticCode::InvalidPhpSyntax);
})->with(['MissingSemicolon.ppphp', 'UnclosedBlock.ppphp']);

test('extension syntax in an unsupported declaration context uses an extension diagnostic', function (): void {
    $contents = (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/Parsing/Invalid/ExtensionSyntax.ppphp');
    $result = (new PpphpParser())->parse(createParserSource($contents, 'ExtensionSyntax.ppphp'));

    expect($result->isSuccessful)->toBeFalse()
        ->and($result->parsedFile)->toBeNull()
        ->and($result->diagnostics->errors[0]->code)->toBe(DiagnosticCode::UnsupportedExtensionSyntax);
});

test('an unrecoverable parser failure may omit the parsed file', function (): void {
    $result = (new PpphpParser())->parse(createParserSource('<?php function broken('));

    expect($result->isSuccessful)->toBeFalse()
        ->and($result->hasErrors)->toBeTrue()
        ->and($result->parsedFile)->toBeNull();
});

test('parse result invariants reject an empty failure', function (): void {
    expect(fn (): ParseResult => new ParseResult(null, new DiagnosticBag()))
        ->toThrow(InvalidArgumentException::class);
});

test('a recoverable parsed file with errors is not successful', function (): void {
    $successful = (new PpphpParser())->parse(createParserSource('<?php echo 1;'));
    $parsedFile = $successful->parsedFile;
    $diagnostics = new DiagnosticBag();
    $diagnostics->add(new Diagnostic(
        DiagnosticCode::InvalidPhpSyntax,
        'Synthetic parser failure.',
    ));

    expect($parsedFile)->not->toBeNull();

    if ($parsedFile === null) {
        return;
    }

    $result = new ParseResult($parsedFile, $diagnostics);

    expect($result->isSuccessful)->toBeFalse()
        ->and($result->parsedFile)->toBe($parsedFile)
        ->and($result->hasErrors)->toBeTrue();
});
