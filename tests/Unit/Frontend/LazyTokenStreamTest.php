<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Frontend\Enumerations\ParseMode;
use Atatusoft\Ppphp\Frontend\PhpParserAdapter;
use Atatusoft\Ppphp\Frontend\Token\Lexer;
use Atatusoft\Ppphp\Frontend\Token\TokenStream;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;

test('deferred source tokens preserve trivia UTF-8 positions and object identity', function (): void {
    $source = new SourceFile('/project/main.php', 'main.php', FileKind::Php, "<?php\r\n// café\r\necho 'é';\r\n");
    $stream = new TokenStream($source);
    $materialized = new ReflectionProperty(TokenStream::class, 'materializedTokens');
    expect($materialized->getValue($stream))->toBeNull();

    $expected = (new Lexer())->tokenize($source);
    expect($stream->tokens)->toEqual($expected->tokens)
        ->and(count($stream))->toBe(count($expected))
        ->and(iterator_to_array($stream))->toBe($stream->tokens)
        ->and($stream->resolveSignificantTokens())->toEqual($expected->resolveSignificantTokens())
        ->and($stream->tokens[0])->toBe($stream->tokens[0]);
});

test('native tokens are deferred and retain the selected parser target', function (string $target): void {
    $source = new SourceFile('/project/main.php', 'main.php', FileKind::Php, "<?php\r\nreadonly class Value { public function __construct(public string \$text = 'é') {} }\r\n");
    $result = (new PhpParserAdapter($target))->parse($source, ParseMode::Php);
    $file = $result->parsedFile;
    expect($file)->not->toBeNull();
    $native = new ReflectionProperty($file, 'nativeTokens');
    $custom = new ReflectionProperty(TokenStream::class, 'materializedTokens');
    expect($native->getValue($file))->toBeNull()
        ->and($custom->getValue($file->tokens))->toBeNull();
    $copy = $file->withDeclarations($source, $file->statements);
    expect($native->getValue($copy))->toBeNull()
        ->and($custom->getValue($copy->tokens))->toBeNull();

    $parser = (new ParserFactory())->createForVersion(PhpVersion::fromString($target));
    $parser->parse($source->contents);
    expect($file->phpTokens)->toEqual(array_values($parser->getTokens()))
        ->and($file->phpTokens[0])->toBe($file->phpTokens[0])
        ->and($custom->getValue($file->tokens))->toBeNull();
})->with(\Atatusoft\Ppphp\Config\PhpTarget::SUPPORTED);
