<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Symbol;

use Atatusoft\Ppphp\Semantic\Binding\Enumerations\BindingMutability;
use Atatusoft\Ppphp\Semantic\Binding\Enumerations\BindingInitialization;
use Atatusoft\Ppphp\Semantic\Binding\LocalBinding;
use Atatusoft\Ppphp\Semantic\Type\LocalType;
use Atatusoft\Ppphp\Source\Span;

final class VariableSymbol
{
    public function __construct(
        public readonly string $name,
        public readonly LocalType $type,
        public readonly BindingMutability $mutability,
        public readonly ?Span $declarationSpan = null,
        public readonly ?LocalBinding $binding = null,
        public readonly ?\Atatusoft\Ppphp\Semantic\Binding\RejectedLocalBinding $recovery = null,
    ) {}

    public BindingInitialization $initialization {
        get => $this->binding === null
            ? BindingInitialization::Initialized
            : $this->binding->initialization;
    }
}
