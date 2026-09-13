<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Binding;

use Atatusoft\Ppphp\Frontend\Ast\NodeId;
use Atatusoft\Ppphp\Semantic\Symbol\VariableSymbol;
use Atatusoft\Ppphp\Semantic\Type\LocalType;
use Atatusoft\Ppphp\Source\Span;

final class BindingTable
{
    /** @var array<string, LocalBinding> */
    private array $recordedBindings = [];

    /** @var array<int, array{name: string, type: LocalType, span: Span}> */
    private array $recordedWriteContracts = [];

    /** @var list<array{name: string, type: LocalType, span: Span}> */
    public array $writeContracts {
        get => array_values($this->recordedWriteContracts);
    }

    public function recordWriteContract(VariableSymbol $symbol, Span $span): void
    {
        // Explicit locals already own their write history. Parameters, catch
        // bindings and other existing symbols need the same contract evidence.
        if ($symbol->binding !== null || $symbol->recovery !== null) {
            return;
        }
        $this->recordedWriteContracts[$span->start->offset] = [
            'name' => $symbol->name, 'type' => $symbol->type, 'span' => $span,
        ];
    }

    /** @var list<LocalBinding> */
    public array $bindings {
        get => array_values($this->recordedBindings);
    }

    public function record(LocalBinding $binding): void
    {
        $this->recordedBindings[$binding->id->value] = $binding;
    }

    public function find(NodeId|string $id): ?LocalBinding
    {
        $key = $id instanceof NodeId ? $id->value : $id;

        return $this->recordedBindings[$key] ?? null;
    }
}
