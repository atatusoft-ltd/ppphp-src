<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Frontend\PpphpParser;
use Atatusoft\Ppphp\Project\ProjectParseResult;
use Atatusoft\Ppphp\Semantic\Generic\GenericDeclarationIndexer;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;

test('the generic bootstrap recognizes both compiler and imported declaration syntax', function (
    FileKind $kind,
    string $contents,
    bool $expected,
): void {
    $relative = $kind === FileKind::Ppphp ? 'src/fixture.ppphp' : 'src/fixture.php';
    $source = new SourceFile('/project/' . $relative, $relative, $kind, $contents);
    $result = (new PpphpParser())->parse($source);
    expect($result->parsedFile)->not->toBeNull();
    $project = new ProjectParseResult([$source->path => $result->parsedFile], [$source->path => $source], $result->diagnostics);
    expect((new GenericDeclarationIndexer())->hasCandidates($project))->toBe($expected);
})->with([
    'native declarations only' => [FileKind::Php, '<?php function keep(int $value): int { return $value; }', false],
    'ordinary PHP function template' => [FileKind::Php, '<?php /** @template T */ function keep(mixed $value): mixed { return $value; }', true],
    'ordinary PHP class template' => [FileKind::Php, '<?php /** @template T */ final class Box {}', true],
    'imported namespaced method template' => [FileKind::Php, '<?php namespace Vendor; final class Box { /** @template T */ public function keep(mixed $value): mixed { return $value; } }', true],
    'compiler-declared type parameter' => [FileKind::Ppphp, '<?php final class Box<T> {}', true],
    'invalid template tag' => [FileKind::Php, '<?php /** @template */ function keep(int $value): int { return $value; }', false],
]);
