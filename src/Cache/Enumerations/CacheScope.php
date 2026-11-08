<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Cache\Enumerations;

enum CacheScope
{
    case Native;
    case BrowserArtifacts;
}
