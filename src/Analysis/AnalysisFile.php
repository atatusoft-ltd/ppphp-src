<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis;

use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;

final readonly class AnalysisFile
{
    /** @param list<int> $generatedTypeDeclarationLines
     * @param array<int, array{name: string, type: string, initializer: bool}> $localContracts
     * @param array<int, array{name: string, definitions: list<int>}> $completedResults
     * @param array<int, array{owner: int, names: list<string>}> $generatedAnnotationOrigins
     */
    public function __construct(
        public SourceFile $sourceFile,
        public string $analysisPath,
        public string $contents,
        public FileKind $kind,
        public bool $selected,
        public AnalysisSourceMap $sourceMap,
        public array $generatedTypeDeclarationLines = [],
        public array $localContracts = [],
        public array $completedResults = [],
        public array $generatedAnnotationOrigins = [],
    ) {}
}
