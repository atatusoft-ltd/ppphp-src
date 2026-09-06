<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\PhpStan;

use Atatusoft\Ppphp\Analysis\AnalysisProject;
use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\DiagnosticLabel;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticOrigin;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;

final class PhpStanDiagnosticMapper
{
    public function map(PhpStanFinding $finding, AnalysisProject $project): ?Diagnostic
    {
        $file = $project->findByAnalysisPath($finding->path);

        if ($file === null) {
            return null;
        }

        if ($finding->identifier === 'throws.unusedType') {
            return null;
        }

        if (
            $file->kind === FileKind::Php
            && str_starts_with($finding->identifier ?? '', 'missingType.')
        ) {
            return null;
        }

        if (in_array($finding->identifier, [
            'missingType.iterableValue',
            'missingType.callable',
        ], true)) {
            return null;
        }

        [$code, $help] = $this->resolveStageEightCategory($finding, $file->kind)
            ?? $this->resolveCategory($finding);
        $span = $file->sourceMap->resolveSpan($finding->line);
        $message = str_replace($finding->path, $file->sourceFile->displayPath, $finding->message);

        return new Diagnostic(
            $code,
            $message,
            new DiagnosticLabel($span, $message),
            help: $help,
            debug: [
                'backendIdentifier' => $finding->identifier,
                'backendIgnorable' => $finding->ignorable,
            ],
            origin: DiagnosticOrigin::PhpStan,
            identity: $finding->identifier,
        );
    }

    /** @return array{DiagnosticCode, string} */
    private function resolveStageEightCategory(PhpStanFinding $finding, FileKind $kind): ?array
    {
        $identifier = strtolower($finding->identifier ?? '');

        if ($identifier === 'missingtype.generics' && $kind === FileKind::Ppphp) {
            return [
                DiagnosticCode::GenericTypeArgumentsAreRequired,
                'Apply the generic declaration with explicit ++PHP type arguments.',
            ];
        }

        if (str_contains($identifier, 'template') || str_starts_with($identifier, 'generics.')) {
            return [
                DiagnosticCode::GenericStaticAnalysisError,
                'Correct the generic declaration or its inferred type relationship.',
            ];
        }

        if ($kind !== FileKind::Ppphp) {
            return null;
        }

        if ($identifier === 'offsetaccess.invalidoffset') {
            return [
                DiagnosticCode::TypedArrayKeyTypeDoesNotMatch,
                'Use an offset accepted by the declared typed-array key contract.',
            ];
        }

        return null;
    }

    /** @return array{DiagnosticCode, string} */
    private function resolveCategory(PhpStanFinding $finding): array
    {
        if (
            str_starts_with($finding->identifier ?? '', 'missingType.')
            && !in_array($finding->identifier, [
                'missingType.checkedException',
                'missingType.parameter',
                'missingType.return',
                'missingType.property',
            ], true)
        ) {
            return [
                DiagnosticCode::ImplicitMixedNotAllowed,
                'Make the broad type explicit at the declaration boundary.',
            ];
        }

        return match ($finding->identifier) {
            'missingType.checkedException' => [DiagnosticCode::CheckedErrorNotHandled, 'Catch the checked error or declare it on the enclosing callable.'],
            'throws.notCovariant' => [DiagnosticCode::CheckedErrorDeclarationNotCovariant, 'Narrow the child throws contract to types allowed by every inherited contract.'],
            'throws.notThrowable',
            'catch.notThrowable' => [DiagnosticCode::ErrorTypeNotThrowable, 'Use a class or interface that implements Throwable.'],
            'catch.neverThrown' => [DiagnosticCode::CaughtErrorNeverThrown, 'Remove the unreachable catch or correct the throwing contract.'],
            'catch.alreadyCaught' => [DiagnosticCode::ErrorCatchUnreachable, 'Move the narrower catch before the broader catch or remove it.'],
            'missingType.parameter' => [DiagnosticCode::MissingParameterType, 'Add an explicit native parameter type.'],
            'missingType.return' => [DiagnosticCode::MissingReturnType, 'Add an explicit native return type.'],
            'missingType.property' => [DiagnosticCode::MissingPropertyType, 'Add an explicit native property type.'],
            'argument.type' => [DiagnosticCode::ArgumentTypeDoesNotMatch, 'Pass a value compatible with the declared parameter type.'],
            'return.type' => [DiagnosticCode::ReturnTypeDoesNotMatch, 'Return a value compatible with the callable return type.'],
            'return.missing' => [DiagnosticCode::NotAllPathsReturnValue, 'Return a compatible value on every reachable path.'],
            'method.notFound' => [DiagnosticCode::MethodDoesNotExist, 'Call a method declared by the resolved receiver type.'],
            'property.notFound' => [DiagnosticCode::PropertyDoesNotExist, 'Use a property declared by the resolved receiver type.'],
            'property.onlyWritten' => [DiagnosticCode::PropertyIsNeverRead, 'Read the stored property, remove it, or keep the warning while the implementation is incomplete.'],
            'class.notFound' => [DiagnosticCode::TypeDoesNotExist, 'Declare or import the referenced type.'],
            'function.notFound' => [DiagnosticCode::FunctionDoesNotExist, 'Declare or import the referenced function.'],
            'assign.propertyType' => [DiagnosticCode::PropertyTypeDoesNotMatch, 'Assign a value compatible with the declared property type.'],
            default => [DiagnosticCode::StaticAnalysisError, 'Correct the reported type or symbol error.'],
        };
    }

}
