<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Frontend\Enumerations\ParseMode;
use Atatusoft\Ppphp\Frontend\PhpSyntaxMessage;
use Atatusoft\Ppphp\Frontend\PpphpParser;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;
use PhpParser\Error;

test('syntax expectations use plain language without rewriting user text', function (string $raw, ?string $token, string $expected): void {
    expect(PhpSyntaxMessage::format(new Error($raw), $token))->toBe($expected);
})->with([
    ['Syntax error, unexpected EOF, expecting \';\'', null, 'Expected a semicolon before end of file.'],
    ['Syntax error, unexpected T_RETURN', 'return', 'Unexpected `return`.'],
    ['Syntax error, unexpected T_STRING', 'AndrewMasiye', 'Unexpected `AndrewMasiye`.'],
    ['Syntax error, unexpected T_STRING', 'T_RETURN', 'Unexpected `T_RETURN`.'],
    ['Syntax error, unexpected T_STRING, expecting T_VARIABLE', 'value', 'Expected a variable before `value`.'],
    ['Syntax error, unexpected T_STRING, expecting \')\' or \',\'', 'value', "Expected a closing parenthesis or ',' before `value`."],
    ['Syntax error, unexpected EOF', null, 'Unexpected end of file.'],
    ['Cannot use T_RETURN as class name as it is reserved', null, 'Cannot use T_RETURN as class name as it is reserved'],
]);

test('real syntax errors explain the cause across native and extension source', function (string $contents, string $expected): void {
    foreach ([FileKind::Php, FileKind::Ppphp] as $kind) {
        $source = new SourceFile('/project/main.' . $kind->value, 'main.' . $kind->value, $kind, $contents);
        $result = (new PpphpParser())->parse($source, $kind === FileKind::Php ? ParseMode::Php : ParseMode::PlusPlusPhp);
        $diagnostic = $result->diagnostics->errors[0];
        expect($diagnostic->message)->toBe($expected)
            ->and($diagnostic->primary?->message)->toBe($expected)
            ->and($diagnostic->debug['parserMessage'])->toStartWith('Syntax error')
            ->and($source->contents)->toBe($contents);

        // Diagnostic-only correction must not replace the original token stream.
        if ($result->parsedFile !== null) {
            $tokens = array_filter($result->parsedFile->phpTokens, static fn ($token): bool => $token->id !== 0);
            expect(implode('', array_map(static fn ($token): string => $token->text, $tokens)))->toBe($contents);
        }
    }
})->with([
    ['<?php echo 1', 'Expected a semicolon before end of file.'],
    ["<?php function run(): int { \$lines = [1]\nreturn 1; }", 'Expected a semicolon before `return`.'],
    ["<?php echo 'é'\r\necho 'next';", 'Expected a semicolon before `echo`.'],
    ['<?php function run(): int { return 1 }', 'Expected a semicolon before `}`.'],
    ['<?php function run( {', 'Expected a variable before `{`.'],
    ['<?php echo ;', 'Unexpected `;`.'],
    ['<?php echo [1 return 2;', "Expected ',' or a closing bracket or a closing parenthesis before `return`."],
]);
