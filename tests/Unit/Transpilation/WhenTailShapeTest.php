<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Transpilation\WhenTailShape;
use PhpParser\NodeDumper;
use PhpParser\Node\Expr;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

test('a common case exit requires every path to finish the result', function (string $body, bool $completes): void {
    $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse('<?php ' . $body);
    expect((new WhenTailShape())->completesCase($statements ?? []))->toBe($completes);
})->with([
    'tail result' => ['return 1;', true],
    'comment after tail result' => ['return 1; /* Explain this result. */', true],
    'comment after conditional result' => ['if ($x) { return 1; } else { return 2; } /* Explain these results. */', true],
    'conditional break before result' => ['if ($leave) { break; } return 1;', false],
    'conditional continue before result' => ['if ($leave) { continue; } return 1;', false],
    'conditional earlier result cannot share the tail exit' => ['if ($take) { return 1; } return 2;', false],
    'nested loop result cannot share the tail exit' => ['foreach ($xs as $x) { if ($take) { return 1; } } return 2;', false],
    'contained loop break' => ['foreach ($xs as $x) { if ($x) { break; } } return 1;', true],
    'contained loop continue' => ['foreach ($xs as $x) { continue; } return 1;', true],
    'outward numbered break' => ['foreach ($xs as $x) { if ($x) { break 2; } } return 1;', false],
    'callable boundary' => ['$f = function () { foreach ([1] as $x) { break; } }; return 1;', true],
    'return or throw' => ['if ($x) { return 1; } else { throw new Error(); }', true],
    'all throw' => ['if ($x) { throw new Error(); } else { throw new Error(); }', false],
    'nested guarded break' => ['switch ($x) { case 1: if ($leave) { break; } return 1; default: return 2; }', false],
    'nested case fallthrough' => ['switch ($x) { case 1: if ($take) { return 1; } default: return 2; }', true],
]);

test('guard normalization never crosses a protected or callable boundary', function (string $body): void {
    $source = (new ParserFactory())->createForNewestSupportedVersion()->parse('<?php ' . $body);
    $before = (new NodeDumper())->dump($source);
    $rewritten = (new WhenTailShape())->rewriteGuards($source);
    expect((new NodeDumper())->dump($rewritten))->toBe($before)
        ->and((new NodeDumper())->dump($source))->toBe($before);
})->with([
    'finally order' => 'try { if ($guard) { return 1; } } finally { echo "cleanup|"; } echo "rest|"; return 2;',
    'catch boundary' => 'try { if ($guard) { return 1; } } catch (Error) { echo "caught|"; } echo "rest|"; return 2;',
    'closure' => '$f = function () { if ($guard) { return 1; } return 2; }; return 3;',
]);

test('guard normalization leaves its input tree untouched', function (): void {
    $source = (new ParserFactory())->createForNewestSupportedVersion()->parse('<?php if ($a) { if ($b) { return 1; } echo "inner"; } echo "rest"; return 2;');
    $before = (new NodeDumper())->dump($source);
    $rewritten = (new WhenTailShape())->rewriteGuards($source);
    expect((new NodeDumper())->dump($rewritten))->not->toBe($before)
        ->and((new NodeDumper())->dump($source))->toBe($before);
});

test('partial guards never duplicate their shared continuation', function (int $count): void {
    $body = '';
    for ($index = 0; $index < $count; $index++) {
        $body .= 'if ($a' . $index . ') { echo "prefix|"; if ($b' . $index . ') { return ' . $index . '; } }';
    }
    $body .= 'echo "remaining|"; return -1;';
    $source = (new ParserFactory())->createForNewestSupportedVersion()->parse('<?php ' . $body);
    $shape = new WhenTailShape();
    expect($shape->requiresGuardCompletion($source))->toBeTrue();
    foreach ([null, new Expr\BooleanNot(new Expr\Variable('complete'))] as $pending) {
        $php = (new Standard())->prettyPrint($shape->rewriteGuards($source, $pending));
        expect(substr_count($php, 'remaining|'))->toBe(1)
            ->and(substr_count($php, 'prefix|'))->toBe($count)
            ->and(strlen($php))->toBeLessThan(strlen($body) * 4);
    }
})->with([1, 8, 64]);

test('a gated continuation still uses its sole continuing arm', function (string $guard, string $expected): void {
    $parser = (new ParserFactory())->createForNewestSupportedVersion();
    $prefix = 'if ($a) { echo "prefix|"; if ($b) { return 1; } }';
    $source = $parser->parse('<?php ' . $prefix . $guard . ' echo "tail|"; return 3;');
    $before = (new NodeDumper())->dump($source);
    $rewritten = (new WhenTailShape())->rewriteGuards($source, new Expr\BooleanNot(new Expr\Variable('complete')));
    $reference = $parser->parse('<?php ' . $prefix . ' if (!$complete) { ' . $expected . ' }');
    expect((new NodeDumper())->dump($rewritten))->toBe((new NodeDumper())->dump($reference))
        ->and((new NodeDumper())->dump($source))->toBe($before);
})->with([
    'continuing else' => [
        'if ($c) { return 2; } else { echo "else|"; }',
        'if ($c) { return 2; } else { echo "else|"; echo "tail|"; return 3; }',
    ],
    'continuing first arm' => [
        'if ($c) { echo "first|"; } else { return 2; }',
        'if ($c) { echo "first|"; echo "tail|"; return 3; } else { return 2; }',
    ],
    'continuing elseif' => [
        'if ($c) { return 2; } elseif ($d) { echo "middle|"; } else { return 4; }',
        'if ($c) { return 2; } elseif ($d) { echo "middle|"; echo "tail|"; return 3; } else { return 4; }',
    ],
]);

test('a preassigned guard fallback needs one self-contained partial statement and an uncommented tail', function (string $body, bool $eligible): void {
    $source = (new ParserFactory())->createForNewestSupportedVersion()->parse('<?php ' . $body);
    $before = (new NodeDumper())->dump($source);
    $fallback = (new WhenTailShape())->resolveGuardFallback($source);
    expect($fallback !== null)->toBe($eligible)
        ->and((new NodeDumper())->dump($source))->toBe($before);
    if ($eligible) {
        expect($fallback)->toBe($source[1]);
    }
})->with([
    'one partial guard' => ['if ($a) { if ($b) { return 1; } } return -1;', true],
    'partial switch' => ['switch ($key) { case 1: return 1; default: break; } return -1;', true],
    'owning continuation' => ['if ($a) { if ($b) { if ($c) { return 1; } } echo "remaining"; } return -1;', false],
    'two partial statements' => ['if ($a) { if ($b) { return 1; } } if ($c) { return 2; } return -1;', false],
    'commented fallback' => ["if (\$a) { if (\$b) { return 1; } } /** Preserve this explanation. */ return -1;", false],
    'callable result only' => ['$call = function () { return 1; }; return -1;', false],
    'protected body' => ['try { if ($a) { return 1; } } finally { echo "cleanup"; } return -1;', false],
    'no result value' => ['if ($a) { return 1; } return;', false],
]);
