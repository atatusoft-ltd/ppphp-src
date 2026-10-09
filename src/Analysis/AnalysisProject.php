<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis;

use Atatusoft\Ppphp\Support\Path;

final readonly class AnalysisProject
{
    /**
     * @param list<AnalysisFile> $selectedFiles
     * @param list<AnalysisFile> $contextFiles
     * @param list<string> $stubFiles
     * @param list<string> $composerScanFiles
     * @param list<string> $composerScanDirectories
     */
    public function __construct(
        public string $projectRoot,
        public string $workspaceRoot,
        public array $selectedFiles,
        public array $contextFiles,
        public array $stubFiles,
        public array $composerScanFiles,
        public array $composerScanDirectories,
        public string $targetPhpVersion,
        public bool $annotationsOnly = false,
    ) {}

    public function findByAnalysisPath(string $path): ?AnalysisFile
    {
        $key = Path::buildComparisonKey($path);

        foreach ([...$this->selectedFiles, ...$this->contextFiles] as $file) {
            if (Path::buildComparisonKey($file->analysisPath) === $key) {
                return $file;
            }
        }

        return null;
    }
}
