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
        $lines = [
            'includes:',
            '    - ' . $this->quote(Path::join($this->compilerRoot, 'resources/phpstan/ppphp.neon')),
            'parameters:',
            '    phpVersion: ' . $this->resolvePhpVersion($project->targetPhpVersion),
            '    tmpDir: ' . $this->quote(Path::join($project->workspaceRoot, 'tmp')),
            '    parallel:',
            '        maximumNumberOfProcesses: 1',
        ];
        $this->appendList($lines, 'paths', array_map(static fn ($file): string => $file->analysisPath, $project->selectedFiles));
        $this->appendList($lines, 'scanFiles', [
            ...array_map(static fn ($file): string => $file->analysisPath, $project->contextFiles),
            ...$project->composerScanFiles,
            ...$project->stubFiles,
        ]);
        $this->appendList($lines, 'scanDirectories', $project->composerScanDirectories);
        $this->appendList($lines, 'stubFiles', $project->stubFiles);
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
