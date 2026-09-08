<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Diagnostics;

use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticFamily;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticStatus;
use Atatusoft\Ppphp\Diagnostics\Enumerations\Severity;

final class DiagnosticCatalog
{
    /** @var array<string, DiagnosticDefinition>|null */
    private static ?array $definitions = null;

    public static function definition(DiagnosticCode $code): DiagnosticDefinition
    {
        return self::definitions()[$code->value];
    }

    /** @return array<string, DiagnosticDefinition> */
    public static function definitions(): array
    {
        if (self::$definitions !== null) {
            return self::$definitions;
        }

        $definitions = [];

        foreach (DiagnosticCode::cases() as $code) {
            $definitions[$code->value] = new DiagnosticDefinition(
                $code,
                self::resolveFamily($code),
                self::resolveStatus($code),
                self::resolveSeverity($code),
                self::resolveTitle($code),
            );
        }

        return self::$definitions = $definitions;
    }

    private static function resolveFamily(DiagnosticCode $code): DiagnosticFamily
    {
        return match ($code->value[1]) {
            '0' => DiagnosticFamily::Project,
            '1' => DiagnosticFamily::Syntax,
            '2' => DiagnosticFamily::Type,
            '3' => DiagnosticFamily::Generic,
            '4' => DiagnosticFamily::CheckedError,
            '5' => DiagnosticFamily::When,
            '6' => DiagnosticFamily::Interop,
            '7' => DiagnosticFamily::Emission,
            '9' => DiagnosticFamily::Internal,
        };
    }

    private static function resolveStatus(DiagnosticCode $code): DiagnosticStatus
    {
        return match ($code) {
            DiagnosticCode::CompilerFrontendNotAvailable,
            DiagnosticCode::DirectoryCompilationUnavailable,
            DiagnosticCode::PhpSourceIsNotBuildTarget,
            DiagnosticCode::TypedLocalSyntaxNotActive,
            DiagnosticCode::CompositeTypeIsNotAssignable,
            DiagnosticCode::GenericSyntaxNotActive,
            DiagnosticCode::ThrowsSyntaxNotActive,
            DiagnosticCode::WhenSyntaxNotActive,
            DiagnosticCode::GeneratedPhpCouldNotBeWritten => DiagnosticStatus::Reserved,
            default => DiagnosticStatus::Active,
        };
    }

    private static function resolveSeverity(DiagnosticCode $code): Severity
    {
        return match ($code) {
            DiagnosticCode::UncheckedCallBoundary,
            DiagnosticCode::PropertyIsNeverRead,
            DiagnosticCode::ComposerAutoloadDoesNotTargetBuildOutput,
            DiagnosticCode::PreviousBuildBackupCouldNotBeRemoved => Severity::Warning,
            default => Severity::Error,
        };
    }

    private static function resolveTitle(DiagnosticCode $code): string
    {
        $canonical = match ($code) {
            DiagnosticCode::AssignmentCannotDeclareVariable => 'Missing Local Variable Type',
            DiagnosticCode::AnalysisWorkspacePreparationFailed => 'Code Could Not Be Prepared For Analysis',
            DiagnosticCode::DependencyDeclarationContextUnavailable => 'Dependency Source Unavailable',
            DiagnosticCode::AssignmentNotAssignableToDeclaredType => 'Assignment Is Not Assignable To Declared Type',
            DiagnosticCode::CaughtErrorNeverThrown => 'Caught Error Is Never Thrown',
            DiagnosticCode::CheckedErrorDeclarationNotCovariant => 'Exception Not Permitted By Inherited Contract',
            DiagnosticCode::CheckedErrorNotHandled => 'Checked Error Is Not Handled',
            DiagnosticCode::CompilerFrontendNotAvailable => 'Compiler Frontend Is Not Available',
            DiagnosticCode::ConfiguredStubPathInvalid => 'Configured Stub Path Is Invalid',
            DiagnosticCode::DynamicPropertyNotAllowed => 'Dynamic Property Is Not Allowed',
            DiagnosticCode::ErrorCatchUnreachable => 'Error Catch Is Unreachable',
            DiagnosticCode::ErrorTypeNotThrowable => 'Error Type Is Not Throwable',
            DiagnosticCode::ExplicitSourceFileRequired => 'Explicit Source File Is Required',
            DiagnosticCode::FileOutsideProjectRoot => 'File Is Outside Project Root',
            DiagnosticCode::ImplicitMixedNotAllowed => 'Implicit Mixed Is Not Allowed',
            DiagnosticCode::InitializerNotAssignableToDeclaredType => 'Initializer Is Not Assignable To Declared Type',
            DiagnosticCode::InputFileDoesNotExist => 'Input Path Does Not Exist',
            DiagnosticCode::InputPathNotFile => 'Input Path Is Not A File',
            DiagnosticCode::InvalidProjectConfigurationJson => 'Invalid Project Configuration JSON',
            DiagnosticCode::LocalVariableNotDeclared => 'Local Variable Is Not Declared',
            DiagnosticCode::MultipleTypedForInitializersNotSupported => 'Multiple Typed For Initializers Are Not Supported',
            DiagnosticCode::NativeThrowsClauseRequired => 'Native Throws Clause Is Required',
            DiagnosticCode::NotAllPathsReturnValue => 'Not All Paths Return A Value',
            DiagnosticCode::NullNotAssignable => 'Null Is Not Assignable',
            DiagnosticCode::OutputPathCollision => 'Generated PHP Output Path Collision',
            DiagnosticCode::ProjectConfigurationNotReadable => 'Project Configuration Is Not Readable',
            DiagnosticCode::ProjectPathNotDirectory => 'Project Path Is Not A Directory',
            DiagnosticCode::SourceFileOutsideConfiguredRoots => 'Selected Path Is Outside Configured Source Roots',
            DiagnosticCode::SourcePathNotDirectory => 'Source Path Is Not A Directory',
            DiagnosticCode::StaticAnalysisBackendFailed => 'Static Analysis Failed',
            DiagnosticCode::StaticAnalysisResultInvalid => 'Static Analysis Result Is Invalid',
            DiagnosticCode::UnsupportedTargetPhpVersion => 'Unsupported Target PHP Version',
            DiagnosticCode::ConflictingPhpTargetConfiguration => 'Conflicting PHP Target Configuration',
            DiagnosticCode::WhenBranchDoesNotProduceValue => 'When Branch Does Not Produce A Value',
            DiagnosticCode::WhenByReferenceArgumentNotAllowed => 'When By-Reference Argument Is Not Allowed',
            DiagnosticCode::WhenControlTransferNotAllowed => 'When Control Transfer Is Not Allowed',
            DiagnosticCode::WhenGotoNotAllowed => 'When Goto Is Not Allowed',
            DiagnosticCode::WhenPositionNotSupported => 'When Position Is Not Supported',
            DiagnosticCode::WhenResultRequiresValue => 'When Result Requires A Value',
            DiagnosticCode::WhenResultTypeDoesNotMatch => 'When Result Type Does Not Match',
            DiagnosticCode::WhenYieldNotAllowed => 'When Yield Is Not Allowed',
            default => null,
        };

        if ($canonical !== null) {
            return $canonical;
        }

        $title = preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', ' ', $code->name);

        if (!is_string($title)) {
            throw new \LogicException(sprintf('Could not derive the title for diagnostic %s.', $code->value));
        }

        return str_replace(
            ['Php', 'Json'],
            ['PHP', 'JSON'],
            $title,
        );
    }
}
