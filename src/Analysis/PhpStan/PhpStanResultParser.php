<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\PhpStan;

use Atatusoft\Ppphp\Analysis\PhpStan\Exceptions\PhpStanExecutionException;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;

final class PhpStanResultParser
{
    public function parse(string $json): PhpStanParsedResult
    {
        if (trim($json) === '') {
            throw new PhpStanExecutionException('Static analysis returned an empty result.', diagnosticCode: DiagnosticCode::StaticAnalysisResultInvalid);
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new PhpStanExecutionException('Static analysis returned malformed JSON.', previous: $exception, diagnosticCode: DiagnosticCode::StaticAnalysisResultInvalid);
        }

        if (!is_array($decoded) || !isset($decoded['files'], $decoded['errors']) || !is_array($decoded['files']) || !is_array($decoded['errors'])) {
            throw new PhpStanExecutionException('Static analysis returned an unexpected result format.', diagnosticCode: DiagnosticCode::StaticAnalysisResultInvalid);
        }

        $findings = [];

        foreach ($decoded['files'] as $path => $file) {
            if (!is_string($path) || !is_array($file) || !isset($file['messages']) || !is_array($file['messages'])) {
                throw new PhpStanExecutionException('Static analysis returned an invalid file result.', diagnosticCode: DiagnosticCode::StaticAnalysisResultInvalid);
            }

            foreach ($file['messages'] as $message) {
                if (
                    !is_array($message)
                    || !isset($message['message'], $message['line'], $message['ignorable'])
                    || !is_string($message['message'])
                    || !is_int($message['line'])
                    || !is_bool($message['ignorable'])
                    || (isset($message['identifier']) && !is_string($message['identifier']))
                ) {
                    throw new PhpStanExecutionException('Static analysis returned an invalid diagnostic.', diagnosticCode: DiagnosticCode::StaticAnalysisResultInvalid);
                }

                $findings[] = new PhpStanFinding(
                    $path,
                    $message['message'],
                    max(1, $message['line']),
                    $message['identifier'] ?? null,
                    $message['ignorable'],
                );
            }
        }

        $globalErrors = [];

        foreach ($decoded['errors'] as $error) {
            if (!is_string($error)) {
                throw new PhpStanExecutionException('Static analysis returned an invalid project error.', diagnosticCode: DiagnosticCode::StaticAnalysisResultInvalid);
            }

            $globalErrors[] = $error;
        }

        return new PhpStanParsedResult($findings, $globalErrors);
    }
}
