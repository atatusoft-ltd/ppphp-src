<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Scope;

use Atatusoft\Ppphp\Semantic\Symbol\VariableSymbol;

final class Scope
{
    /** @var array<string, VariableSymbol> */
    private array $declaredSymbols = [];

    public function __construct(public readonly string $kind) {}

    /** @var array<string, VariableSymbol> */
    public array $symbols {
        get => $this->declaredSymbols;
    }

    /** @var array<string, VariableSymbol> */
    public array $recoverySymbols {
        get => array_filter($this->declaredSymbols, static fn (VariableSymbol $symbol): bool => $symbol->recovery !== null);
    }

    /** @param array<string, VariableSymbol> $symbols */
    public function restoreRecoveries(array $symbols): void
    {
        $this->declaredSymbols = array_filter($this->declaredSymbols, static fn (VariableSymbol $symbol): bool => $symbol->recovery === null);
        $this->declaredSymbols += $symbols;
    }

    public function declare(VariableSymbol $symbol): bool
    {
        if (isset($this->declaredSymbols[$symbol->name]) && $this->declaredSymbols[$symbol->name]->recovery === null) {
            return false;
        }

        $this->declaredSymbols[$symbol->name] = $symbol;

        return true;
    }

    public function import(VariableSymbol $symbol): void
    {
        $this->declaredSymbols[$symbol->name] ??= $symbol;
    }

    public function resolve(string $name): ?VariableSymbol
    {
        return $this->declaredSymbols[$name] ?? null;
    }
}
