<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Frontend\PpphpParser;
use Atatusoft\Ppphp\Semantic\SourceNameResolver;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;
use PhpParser\Node\Stmt\Use_;

test('source imports retain namespace, order, and declaration kind across repeated lookups', function (): void {
    $contents = <<<'PHP_SOURCE'
<?php
namespace First {
    use Vendor\Group\{Type as Name, function factory as Name, const VALUE as Key};
    function first(): void {}
}
namespace Second {
    use Different\Name;
    function second(): void {}
}
namespace {
    use Root\Name;
    function outside(): void {}
}
PHP_SOURCE;
    $path = '/project/src/fixture.php';
    $parser = new PpphpParser();
    $file = $parser->parse(new SourceFile($path, 'src/fixture.php', FileKind::Php, $contents))->parsedFile;
    $replacement = $parser->parse(new SourceFile(
        $path,
        'src/fixture.php',
        FileKind::Php,
        str_replace('Different\\Name', 'OtherName\\Name', $contents),
    ))->parsedFile;

    expect($file)->not->toBeNull();
    expect($replacement)->not->toBeNull();

    $first = strpos($contents, 'function first');
    $second = strpos($contents, 'function second');
    $outside = strpos($contents, 'function outside');
    $names = new SourceNameResolver();
    expect($names->resolveNamespaceAt($file, $first))->toBe('First')
        ->and($names->resolve($file, 'Name\\Nested', $first))->toBe('Vendor\\Group\\Type\\Nested')
        ->and($names->resolve($file, 'Name', $first, Use_::TYPE_FUNCTION))->toBe('Vendor\\Group\\factory')
        ->and($names->resolve($file, 'Key', $first, Use_::TYPE_CONSTANT))->toBe('Vendor\\Group\\VALUE')
        ->and($names->resolve($file, 'Name', $first, Use_::TYPE_CONSTANT))->toBe('First\\Name')
        ->and($names->resolve($file, 'Name', $second))->toBe('Different\\Name')
        ->and($names->resolve($replacement, 'Name', $second))->toBe('OtherName\\Name')
        ->and($names->resolveNamespaceAt($file, $outside))->toBe('')
        ->and($names->resolve($file, 'Name', $outside))->toBe('Root\\Name');
});
