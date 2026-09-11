<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Call\Enumerations;

enum ArgumentPassingMode
{
    case Value;
    case Reference;
    case Unknown;
}
