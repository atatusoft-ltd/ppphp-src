<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Effect;

use Atatusoft\Ppphp\Semantic\Type\Interfaces\Type;

final readonly class ErrorAnalysisScope
{
    /** @param array<string, Type> $variableTypes */
    public function __construct(
        public string $kind,
        public ?CallableErrorContract $contract,
        public ?string $currentClass,
        public array $variableTypes = [],
        public bool $ownsWhenResults = false,
    ) {}

    public function includeVariable(string $name, Type $type): self
    {
        $variables = $this->variableTypes;
        $variables[$name] = $type;

        return new self($this->kind, $this->contract, $this->currentClass, $variables, $this->ownsWhenResults);
    }
}
