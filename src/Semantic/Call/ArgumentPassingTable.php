<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Call;

use Atatusoft\Ppphp\Semantic\Call\Enumerations\ArgumentPassingMode;
use PhpParser\Node\Arg;

final class ArgumentPassingTable
{
    /** @var \WeakMap<Arg, ArgumentPassingMode> */
    private \WeakMap $entries;

    public function __construct()
    {
        $this->entries = new \WeakMap();
    }

    public function record(Arg $argument, ArgumentPassingMode $mode): void
    {
        // A site visited with different flow states must not acquire the
        // calling convention of whichever state happened to be checked last.
        $previous = $this->entries[$argument] ?? $mode;
        $this->entries[$argument] = $previous === $mode ? $mode : ArgumentPassingMode::Unknown;
    }

    public function resolve(Arg $argument): ArgumentPassingMode
    {
        return $this->entries[$argument] ?? ArgumentPassingMode::Unknown;
    }
}
