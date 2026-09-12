<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Transpilation;

/** The source observers that can distinguish a failed inner result. */
final readonly class WhenProtectedRegion
{
    public function __construct(
        public bool $canRecoverFailure = false,
    ) {}
}
