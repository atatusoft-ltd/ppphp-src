<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\When;

/** Original target and the real loop levels on either side of its cleanup. */
final readonly class WhenDeferredTransfer
{
    public function __construct(
        public int $sourceOffset,
        public int $targetOffset,
        public int $barrierOffset,
        public int $exitedLevels,
        public int $remainingLevel,
        public bool $continues,
    ) {}
}
