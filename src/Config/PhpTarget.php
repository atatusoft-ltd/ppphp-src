<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Config;

final class PhpTarget
{
    public const string DEFAULT = '8.4';
    /** Qualified project capabilities, independent of compiler-host versions. */
    public const array SUPPORTED = [self::DEFAULT];
}
