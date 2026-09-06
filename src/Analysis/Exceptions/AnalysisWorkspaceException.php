<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Exceptions;

/** A known preparation failure with safe, actionable user-facing details. */
final class AnalysisWorkspaceException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $help)
    {
        parent::__construct($message);
    }
}
