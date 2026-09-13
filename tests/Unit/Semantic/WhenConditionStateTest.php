<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Semantic\Binding\Enumerations\BindingMutability;
use Atatusoft\Ppphp\Semantic\Scope\Scope;
use Atatusoft\Ppphp\Semantic\Symbol\VariableSymbol;
use Atatusoft\Ppphp\Semantic\Type\LocalType;
use Atatusoft\Ppphp\Semantic\When\WhenConditionState;
use PhpParser\Node\Expr;
use PhpParser\ParserFactory;

test('condition effects forget current facts and cannot establish replacement facts', function (string $source): void {
    $scope = new Scope('function');
    $scope->declare(new VariableSymbol('$select', LocalType::createAtomic('bool'), BindingMutability::Mutable));
    $variable = new Expr\Variable('select');
    $state = (new WhenConditionState())->assume($variable, true, $scope);
    $expression = (new ParserFactory())->createForNewestSupportedVersion()->parse('<?php ' . $source . ';')[0]->expr;
    $after = $state->advance($expression)->assume($expression, true, $scope);

    expect($after->truths)->toBe([])->and($after->entry)->toBe(['$select' => true])
        ->and($after->preservesEntry)->toBeFalse()
        ->and($after->assume($variable, false, $scope)->reachable)->toBeTrue();
})->with([
    'write through a possible alias' => '$alias = false',
    'create a reference alias' => '$alias =& $select',
    'symbol table extraction' => 'extract($values, EXTR_REFS)',
    'variable variable write' => '$$name = false',
    'variable variable read' => '$$name',
    'property read may invoke a hook' => '$object->select',
    'static property read may autoload' => 'Flags::$select',
    'global array access is not a local condition' => '$GLOBALS["select"]',
    'call may mutate and throw' => 'mutateThenThrow($select)',
    'effectful right operand' => '$select && mutate($select)',
    'effectful left operand' => 'mutate($select) || $select',
    'assignment in a condition' => '($select = true) === true',
]);
