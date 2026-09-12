<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\PhpStan;

use PHPStan\Command\AnalysisResult;
use PHPStan\Command\ErrorFormatter\ErrorFormatter;
use PHPStan\Command\Output;

/** Internal transport: preserve diagnostics and carry per-tag emission advice. */
final class AnalysisJsonFormatter implements ErrorFormatter
{
    public function formatErrors(AnalysisResult $analysisResult, Output $output): int
    {
        $files = [];
        foreach ($analysisResult->getFileSpecificErrors() as $error) {
            $file = $error->getFile();
            $files[$file] ??= ['errors' => 0, 'messages' => []];
            $files[$file]['errors']++;
            $files[$file]['messages'][] = [
                'message' => $error->getMessage(),
                'line' => $error->getLine(),
                'ignorable' => $error->canBeIgnored(),
                'identifier' => $error->getIdentifier(),
            ];
        }
        $omissions = [];
        foreach ($analysisResult->getCollectedData() as $data) {
            if ($data->getCollectorType() === GeneratedAnnotationCollector::class) {
                $advice = $data->getData();
                if (!is_array($advice)) {
                    throw new \LogicException('Invalid generated annotation collection.');
                }
                foreach ($advice as $statementAdvice) {
                    if (!is_array($statementAdvice)) {
                        throw new \LogicException('Invalid generated annotation collection.');
                    }
                    foreach ($statementAdvice as $omission) {
                        if (!is_array($omission)) {
                            throw new \LogicException('Invalid generated annotation decision.');
                        }
                        $omissions[] = ['path' => $data->getFilePath(), ...$omission];
                    }
                }
            }
        }
        $output->writeRaw(json_encode([
            'totals' => ['errors' => count($analysisResult->getNotFileSpecificErrors()), 'file_errors' => count($analysisResult->getFileSpecificErrors())],
            'files' => (object) $files,
            'errors' => $analysisResult->getNotFileSpecificErrors(),
            'localAnnotationOmissions' => $omissions,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $analysisResult->hasErrors() ? 1 : 0;
    }
}
