<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Transpilation\WhenTailShape;
use PhpParser\ParserFactory;

test('a common case exit requires every path to finish the result', function (string $body, bool $completes): void {
    $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse('<?php ' . $body);
    expect((new WhenTailShape())->completesCase($statements ?? []))->toBe($completes);
})->with([
    'tail result' => ['return 1;', true],
    'conditional break before result' => ['if ($leave) { break; } return 1;', false],
    'conditional continue before result' => ['if ($leave) { continue; } return 1;', false],
    'contained loop break' => ['foreach ($xs as $x) { if ($x) { break; } } return 1;', true],
    'contained loop continue' => ['foreach ($xs as $x) { continue; } return 1;', true],
    'outward numbered break' => ['foreach ($xs as $x) { if ($x) { break 2; } } return 1;', false],
    'callable boundary' => ['$f = function () { foreach ([1] as $x) { break; } }; return 1;', true],
    'return or throw' => ['if ($x) { return 1; } else { throw new Error(); }', true],
    'all throw' => ['if ($x) { throw new Error(); } else { throw new Error(); }', false],
    'nested guarded break' => ['switch ($x) { case 1: if ($leave) { break; } return 1; default: return 2; }', false],
    'nested case fallthrough' => ['switch ($x) { case 1: if ($take) { return 1; } default: return 2; }', true],
]);
