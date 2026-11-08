<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Experimental\Enumerations;

/** A proven string whose contents cannot be used as an exact array key. */
enum UntrackedString
{
    case Value;
}
