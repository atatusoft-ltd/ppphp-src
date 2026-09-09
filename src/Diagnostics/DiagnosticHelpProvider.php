<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Diagnostics;

use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticFamily;

final class DiagnosticHelpProvider
{
    public static function resolve(DiagnosticCode $code): string
    {
        if ($code === DiagnosticCode::LocalVariableNotDeclared) {
            return 'Declare and initialize this local with an explicit type before the read, or correct its name if you intended an existing variable.';
        }
        if ($code === DiagnosticCode::AssignmentCannotDeclareVariable) {
            return 'Add an explicit type to the local declaration. An assignment expression requires a separate declaration before it.';
        }

        return self::resolveGeneric(DiagnosticCatalog::definition($code)->family)
            ?? 'Run the command again with --debug and include the resulting details when reporting the issue.';
    }

    /** Generic protocol guidance is retained for compatibility, but adds no detail to console output. */
    public static function resolveGeneric(DiagnosticFamily $family): ?string
    {
        return match ($family) {
            DiagnosticFamily::Project => 'Correct the project path, configuration, or command input described above, then run the command again.',
            DiagnosticFamily::Syntax => 'Correct the highlighted source syntax, then run the command again.',
            DiagnosticFamily::Type => 'Correct the highlighted declaration or expression so it satisfies the stated type contract.',
            DiagnosticFamily::Generic => 'Correct the highlighted generic declaration or application so it satisfies its type-parameter contract.',
            DiagnosticFamily::CheckedError => 'Catch, declare, or correct the checked error contract described above.',
            DiagnosticFamily::When => 'Correct the highlighted `when` expression so every branch is valid in this context.',
            DiagnosticFamily::Interop => 'Correct the Composer, stub, or static-analysis input described above, then run the command again.',
            DiagnosticFamily::Emission => 'Correct the output condition described above, then run a pathless `ppphp build`.',
            DiagnosticFamily::Internal => null,
        };
    }
}
