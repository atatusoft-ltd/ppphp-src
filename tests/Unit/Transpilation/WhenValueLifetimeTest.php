<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Semantic\Type\LocalType;
use Atatusoft\Ppphp\Transpilation\WhenValueLifetime;

test('release safety includes every possible nested value', function (string $type, bool $safe): void {
    expect((new WhenValueLifetime())->resolveReleaseSafety(LocalType::createFromText($type)->semanticType))->toBe($safe);
})->with([
    ['int', true], ['float', true], ['bool', true], ['null', true], ['string', true],
    ['int|string|null', true], ['array<int>', true], ['array<string, array<int|string>>', true],
    ['array<int>|array<string>', true], ['array<array<resource>>', false],
    ['resource', false], ['mixed', false], ['array', false], ['iterable', false],
    ['object', false], ['SomeClass', false], ['SomeClass|null', false],
    ['array<mixed>', false], ['array<int|SomeClass>', false],
]);
