<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

test('missing types are explained without changing the spelling of the name', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', '<?php namespace App\Inventory; class Product {} function find(): void { new StockRepository(); }');
    $check = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'check', '--working-directory', $root, '--format=json']);
    $check->run();
    $diagnostics = json_decode($check->getOutput(), true)['diagnostics'];
    expect($check->getExitCode())->toBe(1)
        ->and(array_column($diagnostics, 'code'))->toBe(['P2020'])
        ->and($diagnostics[0]['message'])->toBe('Type App\Inventory\StockRepository is not defined in this project or its dependencies.');
});
