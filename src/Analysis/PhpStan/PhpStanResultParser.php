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
            $shape = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
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

        $omissions = $decoded['localAnnotationOmissions'] ?? null;
        if (!$shape instanceof \stdClass || !is_array($shape->localAnnotationOmissions ?? null)
            || !is_array($omissions) || !array_is_list($omissions)) {
            throw new PhpStanExecutionException('Static analysis returned invalid annotation advice.', diagnosticCode: DiagnosticCode::StaticAnalysisResultInvalid);
        }
        foreach ($omissions as $omission) {
            if (!is_array($omission) || count($omission) !== 3 || !isset($omission['path'], $omission['offset'], $omission['name'])
                || !is_string($omission['path']) || !is_int($omission['offset']) || $omission['offset'] < 0
                || !is_string($omission['name']) || preg_match('/^\$[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/D', $omission['name']) !== 1) {
                throw new PhpStanExecutionException('Static analysis returned invalid annotation advice.', diagnosticCode: DiagnosticCode::StaticAnalysisResultInvalid);
            }
        }
        return new PhpStanParsedResult($findings, $globalErrors, $omissions);
    }
}
