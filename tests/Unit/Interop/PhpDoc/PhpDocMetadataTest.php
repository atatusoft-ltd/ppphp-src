<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Interop\PhpDoc\PhpDocReader;
use PhpParser\Comment\Doc;

test('metadata reuse follows exact comment text and does not retain discarded comments', function (): void {
    $document = new class('/** @return int */') extends Doc {
        public function replaceText(string $text): void { $this->text = $text; }
    };
    $first = (new PhpDocReader())->readMetadata($document);
    expect((new PhpDocReader())->readMetadata($document))->toBe($first);
    $document->replaceText('/** @return string */');
    expect((new PhpDocReader())->readMetadata($document)->returns)->toBe(['string'])
        ->and($first->returns)->toBe(['int']);
    $reference = WeakReference::create($document);
    unset($document);
    expect($reference->get())->toBeNull();
});

test('variable assertion detection includes supported aliases and malformed values', function (string $tag): void {
    $reader = new PhpDocReader();
    expect($reader->hasVariableAssertions(new Doc('/** ' . $tag . ' string $value */')))->toBeTrue()
        ->and($reader->hasVariableAssertions(new Doc('/** ' . $tag . ' */')))->toBeTrue()
        ->and($reader->hasVariableAssertions(new Doc('/** Handler setup. */')))->toBeFalse()
        ->and($reader->hasVariableAssertions(null))->toBeFalse();
})->with(['@var', '@phpstan-var', '@psalm-var']);

test('PHPDoc metadata reader exposes the supported generic contract tags', function (): void {
    $metadata = (new PhpDocReader())->readMetadata(new Doc(<<<'DOC'
/**
 * Summary.
 * @template T of Entity
 * @extends Base<T>
 * @implements Contract<T>
 * @use Stores<T>
 * @param list<T> $items Description.
 * @return array<string, T>
 * @var T $value
 * @throws Failure
 */
DOC));

    expect($metadata->templates)->toBe([['name' => 'T', 'bound' => 'Entity']])
        ->and($metadata->extends)->toBe(['Base<T>'])
        ->and($metadata->implements)->toBe(['Contract<T>'])
        ->and($metadata->uses)->toBe(['Stores<T>'])
        ->and($metadata->parameters)->toBe(['$items' => 'list<T>'])
        ->and($metadata->returns)->toBe(['array<string, T>'])
        ->and($metadata->variables)->toBe(['$value' => 'T'])
        ->and($metadata->throws)->toBe(['Failure']);
});
