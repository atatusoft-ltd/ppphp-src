<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Transpilation\WhenOperandStability;
use Atatusoft\Ppphp\Transpilation\WhenLocalScope;
use Atatusoft\Ppphp\Frontend\PpphpParser;
use Atatusoft\Ppphp\Project\ProjectParseResult;
use Atatusoft\Ppphp\Semantic\SemanticAnalyzer;
use Atatusoft\Ppphp\Semantic\Binding\LocalBinding;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;
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

test('loop operand stability uses contextual types and fresh binding identities', function (string $loop, bool $stable): void {
    $source = new SourceFile('/project/src/Loop.ppphp', 'src/Loop.ppphp', FileKind::Ppphp, '<?php
        function mutate(int &$value): void { $value++; }
        function unrelated(): void { get_defined_vars(); }
        function pick(bool $enabled, int &$fallback, int &$alias, array<int> $values, array<mixed> $unknown, array<string> $labels): int {
            return when ($enabled) { ' . $loop . ' return $fallback; } else { return 0; };
        }');
    $parsed = (new PpphpParser())->parse($source);
    expect($parsed->parsedFile)->not->toBeNull();
    $analysis = (new SemanticAnalyzer())->analyze(new ProjectParseResult(
        [$source->path => $parsed->parsedFile], [$source->path => $source], $parsed->diagnostics,
    ));
    expect($analysis->isSuccessful)->toBeTrue();
    $model = $analysis->findModel($source->path);
    expect($model)->not->toBeNull();
    $statements = $model->whenExpressions->expressions[0]->branches[0]->statements;
    $fallback = $statements[1];
    expect($fallback)->toBeInstanceOf(Stmt\Return_::class)
        ->and((new WhenOperandStability(model: $model))->canDelay($fallback->expr, [$statements[0]]))->toBe($stable);
})->with([
    'fresh value' => ['foreach ($values as int $item) { if ($item > 5) { return $item * 100; } }', true],
    'fresh key and value' => ['foreach ($values as int $key => int $item) { if ($key === $fallback) { return $item; } }', true],
    'array literal' => ['foreach ([1, 2] as int $item) { if ($item === $fallback) { return $item; } }', true],
    'string array no executable cleanup' => ['foreach ($labels as string $label) { if ($label === "hit") { return 1; } }', true],
    'fresh counter' => ['for (int $index = 0; $index < 3; $index++) { if ($index > $fallback) { return $index; } }', true],
    'nested fresh bindings' => ['foreach ($values as int $item) { foreach ($values as int $other) { if ($item === $other) { return $item; } } }', true],
    'internal break and continue' => ['foreach ($values as int $item) { if ($item === 0) { continue; } if ($item === 1) { break; } if ($item > 5) { return $item; } }', true],
    'loop writes fallback' => ['foreach ($values as $fallback) { if ($fallback > 5) { return 1; } }', false],
    'loop target can alias fallback' => ['foreach ($values as $alias) { if ($alias > 5) { return 1; } }', false],
    'counter can alias fallback' => ['while ($alias < 3) { $alias++; if ($enabled) { return 1; } }', false],
    'call can mutate fallback through alias' => ['foreach ($values as int $item) { mutate($alias); if ($item > 5) { return 1; } }', false],
    'output handler can mutate fallback' => ['foreach ($values as int $item) { echo "item|"; if ($item > 5) { return 1; } }', false],
    'iterator can release an object' => ['foreach ($unknown as mixed $item) { if ($enabled) { return 1; } }', false],
]);

test('lexical local safety distinguishes isolation from early declaration visibility', function (string $code, bool $isolated, bool $seeded): void {
    $source = new SourceFile('/project/src/Scope.ppphp', 'src/Scope.ppphp', FileKind::Ppphp, '<?php ' . $code);
    $parsed = (new PpphpParser())->parse($source);
    expect($parsed->parsedFile)->not->toBeNull();
    $analysis = (new SemanticAnalyzer())->analyze(new ProjectParseResult(
        [$source->path => $parsed->parsedFile], [$source->path => $source], $parsed->diagnostics,
    ));
    expect($analysis->isSuccessful)->toBeTrue();
    $model = $analysis->findModel($source->path);
    expect($model)->not->toBeNull();
    $binding = array_find($model->bindings->bindings, static fn (LocalBinding $local): bool => $local->name === '$target');
    expect($binding)->not->toBeNull();
    $scope = new WhenLocalScope($model);
    expect($scope->canIsolateBinding($binding->variableSpan))->toBe($isolated)
        ->and($scope->canSeedLocal($binding->variableSpan))->toBe($seeded);
})->with([
    'including PHP can supply aliases' => ['int $target = 1; echo $target;', false, false],
    'fresh function local' => ['function run(): int { int $target = 1; return $target; }', true, true],
    'same frame can observe local storage' => ['function run(): int { int $target = 1; get_defined_vars(); return $target; }', false, false],
    'fresh loop binding is isolated but can be repeated' => ['function run(array<int> $values): void { foreach ($values as int $target) { echo $target; } }', true, false],
    'protected scope can observe a failed initializer' => ['function run(): int { try { int $target = 1; return $target; } finally { echo "done"; } }', true, false],
    'nested callable starts a separate frame' => ['function run(): Closure { get_defined_vars(); return function (): int { int $target = 1; return $target; }; }', true, true],
]);
