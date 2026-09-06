<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\PhpStan\Exceptions;

use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;

final class PhpStanExecutionException extends \RuntimeException
{
    public function __construct(
        string $message,
        ?\Throwable $previous = null,
        public readonly DiagnosticCode $diagnosticCode = DiagnosticCode::StaticAnalysisBackendFailed,
        public readonly string $help = 'Run the command again with --debug and include the details when reporting the analysis failure.',
    ) {
        parent::__construct($message, 0, $previous);
    }
}
