<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis;

use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;

final readonly class AnalysisFile
{
    /** @param list<int> $generatedTypeDeclarationLines */
    public function __construct(
        public SourceFile $sourceFile,
        public string $analysisPath,
        public string $contents,
        public FileKind $kind,
        public bool $selected,
        public AnalysisSourceMap $sourceMap,
        public array $generatedTypeDeclarationLines = [],
    ) {}
}
