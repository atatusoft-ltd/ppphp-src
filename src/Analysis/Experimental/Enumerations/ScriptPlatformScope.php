<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Experimental\Enumerations;

enum ScriptPlatformScope
{
    case Empty;
    case CheckedErrors;

    public function selectPlatformClass(): ?string
    {
        return match ($this) {
            self::Empty => null,
            self::CheckedErrors => 'Exception',
        };
    }
}
