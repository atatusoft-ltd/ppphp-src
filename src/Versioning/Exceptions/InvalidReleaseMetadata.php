<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Versioning\Exceptions;

final class InvalidReleaseMetadata extends \RuntimeException
{
    public function __construct(public readonly string $reason, \Throwable $previous)
    {
        parent::__construct('The compiler release manifest is invalid.', previous: $previous);
    }
}
