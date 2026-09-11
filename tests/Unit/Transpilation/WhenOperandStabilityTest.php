<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Transpilation\WhenOperandStability;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;

test('operand stability requires a proof across the whole intervening window', function (string $operand, string $window, bool $stable): void {
    $parser = (new ParserFactory())->createForNewestSupportedVersion();
    $return = $parser->parse('<?php return ' . $operand . ';')[0];
    expect($return)->toBeInstanceOf(Stmt\Return_::class);
    expect((new WhenOperandStability(['scratch' => true]))->canDelay(
        $return->expr, $parser->parse('<?php ' . $window) ?? [],
    ))->toBe($stable);
})->with([
    'literal' => ['1', 'mutate();', true],
    'negative integer literal' => ['-1', 'mutate();', true],
    'positive signed float literal' => ['+1.5', 'mutate();', true],
    'negative float literal' => ['-0.5', 'mutate();', true],
    'negated global constant is not a literal' => ['-UNKNOWN', '', false],
    'string literal' => ["'value'", 'mutate();', true],
    'boolean literal' => ['true', 'mutate();', true],
    'null literal' => ['null', 'mutate();', true],
    'this identity' => ['$this', '$this->value = mutate();', true],
    'local read-only branches' => ['$value', 'if ($vip) { return 1; } else { return 2; }', true],
    'generated result writes' => ['$value', 'if ($vip) { $scratch = 1; } else { $scratch = 2; }', true],
    'direct write' => ['$value', '$value = 2;', false],
    'possible alias write' => ['$value', '$alias = 2;', false],
    'reference creation' => ['$value', '$alias =& $value;', false],
    'call can mutate captured alias' => ['$value', 'mutate();', false],
    'output handler can mutate alias' => ['$value', 'echo "branch";', false],
    'property hook' => ['$value', 'return $box->property;', false],
    'array access hook' => ['$value', 'return $box[0];', false],
    'cleanup invokes destructor' => ['$value', 'unset($box);', false],
    'string conversion invokes method' => ['$value', 'return (string) $box;', false],
    'dynamic variable' => ['$$name', '', false],
    'property operand' => ['$box->value', '', false],
    'global constant may throw' => ['UNKNOWN', '', false],
    'class constant may autoload' => ['Other::VALUE', '', false],
]);

test('unpacking is not a read-only intervening operand', function (): void {
    $proof = new WhenOperandStability();
    $operand = new Expr\Variable('value');
    $items = new Expr\Variable('items');
    expect($proof->canDelay($operand, [new Arg($items, unpack: true)]))->toBeFalse()
        ->and($proof->canDelay($operand, [new ArrayItem($items, unpack: true)]))->toBeFalse();
});
