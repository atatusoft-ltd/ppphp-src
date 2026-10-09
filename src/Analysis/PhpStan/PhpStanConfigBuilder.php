<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\PhpStan;

use Atatusoft\Ppphp\Analysis\AnalysisProject;
use Atatusoft\Ppphp\Analysis\PhpStan\Exceptions\PhpStanExecutionException;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Support\Path;

final class PhpStanConfigBuilder
{
    private readonly string $compilerRoot;

    public function __construct(string $compilerRoot)
    {
        $this->compilerRoot = Path::normalize($compilerRoot);
    }

    public function build(AnalysisProject $project): string
    {
        $configurationPath = Path::join($project->workspaceRoot, 'phpstan.neon');
        $lines = $project->annotationsOnly ? [] : [
            'includes:',
            '    - ' . $this->quote(Path::join($this->compilerRoot, 'resources/phpstan/ppphp.neon')),
        ];
        $lines = [...$lines,
            'parameters:',
            '    phpVersion: ' . $this->resolvePhpVersion($project->targetPhpVersion),
            '    tmpDir: ' . $this->quote(Path::join($project->workspaceRoot, 'tmp')),
            '    parallel:',
            '        maximumNumberOfProcesses: 1',
        ];
        if ($project->annotationsOnly) {
            // A separate validation purpose, not a relaxed source check. The
            // authoritative full check has already run on its unchanged view.
            $lines[] = '    level: null';
            $lines[] = '    customRulesetUsed: true';
        }
        $this->appendList($lines, 'paths', array_map(static fn ($file): string => $file->analysisPath, $project->selectedFiles));
        $this->appendList($lines, 'scanFiles', [
            ...array_map(static fn ($file): string => $file->analysisPath, $project->contextFiles),
            ...$project->composerScanFiles,
            ...$project->stubFiles,
        ]);
        $this->appendList($lines, 'scanDirectories', $project->composerScanDirectories);
        $this->appendList($lines, 'stubFiles', $project->stubFiles);
        $contracts = $completedResults = $annotationOrigins = [];
        foreach ($project->selectedFiles as $file) {
            if ($file->generatedAnnotationOrigins !== []) {
                $annotationOrigins[$file->analysisPath] = $file->generatedAnnotationOrigins;
            }
            if ($file->localContracts !== []) {
                $contracts[$file->analysisPath] = $file->localContracts;
            }
            if ($file->completedResults !== []) {
                $completedResults[$file->analysisPath] = $file->completedResults;
            }
        }
        $lines[] = 'services:';
        if (!$project->annotationsOnly) {
            $lines[] = '    -';
            $lines[] = '        class: ' . GeneratedLocalContractRule::class;
            $lines[] = '        arguments:';
            $lines[] = '            contracts: ' . json_encode($contracts, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $lines[] = '        tags: [phpstan.rules.rule]';
            $lines[] = '    -';
            $lines[] = '        class: ' . CompletedWhenResultExtension::class;
            $lines[] = '        arguments:';
            $lines[] = '            results: ' . json_encode($completedResults, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $lines[] = '        tags: [phpstan.rules.rule, phpstan.broker.expressionTypeResolverExtension]';
        } else {
            $lines[] = '    annotationRule:';
            $lines[] = '        class: PHPStan\Rules\PhpDoc\WrongVariableNameInVarTagRule';
            $lines[] = '        tags: [phpstan.rules.rule]';
        }
        $lines[] = '    -';
        $lines[] = '        class: ' . GeneratedAnnotationCollector::class;
        $lines[] = '        arguments:';
        $lines[] = '            rule: ' . ($project->annotationsOnly ? '@annotationRule' : '@PHPStan\Rules\PhpDoc\WrongVariableNameInVarTagRule');
        $lines[] = '            origins: ' . json_encode($annotationOrigins, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $lines[] = '        tags: [phpstan.collector]';
        $lines[] = '    errorFormatter.json:';
        $lines[] = '        class: ' . AnalysisJsonFormatter::class;
        $lines[] = '        arguments!: []';
        $contents = implode("\n", $lines) . "\n";

        if (@file_put_contents($configurationPath, $contents) === false) {
            throw new PhpStanExecutionException(
                'A file needed for analysis could not be written.',
                diagnosticCode: DiagnosticCode::AnalysisWorkspacePreparationFailed,
                help: 'Check free disk space and write permissions on the project cache directory.',
            );
        }

        return $configurationPath;
    }

    /**
     * @param list<string> $lines
     * @param list<string> $values
     */
    private function appendList(array &$lines, string $name, array $values): void
    {
        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);

        if ($values === []) {
            $lines[] = '    ' . $name . ': []';

            return;
        }

        $lines[] = '    ' . $name . ':';

        foreach ($values as $value) {
            $lines[] = '        - ' . $this->quote($value);
        }
    }

    private function quote(string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function resolvePhpVersion(string $version): int
    {
        [$major, $minor] = array_map(intval(...), explode('.', $version, 2));

        return ($major * 10000) + ($minor * 100);
    }
}
