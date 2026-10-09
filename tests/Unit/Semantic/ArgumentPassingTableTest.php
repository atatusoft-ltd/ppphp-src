<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Semantic\Call\ArgumentPassingTable;
use Atatusoft\Ppphp\Semantic\Call\Enumerations\ArgumentPassingMode;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Variable;

test('argument passing modes conservatively merge repeated flow observations', function (array $observations, ArgumentPassingMode $expected): void {
    $table = new ArgumentPassingTable();
    $argument = new Arg(new Variable('value'));
    expect($table->resolve($argument))->toBe(ArgumentPassingMode::Unknown);
    foreach ($observations as $observation) {
        $table->record($argument, $observation);
    }
    expect($table->resolve($argument))->toBe($expected)
        ->and($table->resolve(clone $argument))->toBe(ArgumentPassingMode::Unknown);
})->with([
    [[ArgumentPassingMode::Value, ArgumentPassingMode::Value], ArgumentPassingMode::Value],
    [[ArgumentPassingMode::Reference, ArgumentPassingMode::Reference], ArgumentPassingMode::Reference],
    [[ArgumentPassingMode::Value, ArgumentPassingMode::Reference, ArgumentPassingMode::Value], ArgumentPassingMode::Unknown],
    [[ArgumentPassingMode::Reference, ArgumentPassingMode::Unknown], ArgumentPassingMode::Unknown],
    [[ArgumentPassingMode::Unknown, ArgumentPassingMode::Reference], ArgumentPassingMode::Unknown],
]);
