<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Transpilation;

final readonly class GeneratedPhp
{
    /** @param list<SourceEdit> $appliedEdits
     * @param array<int, array{name: string, definitions: list<int>}> $completedResults
     * @param list<array{start: int, end: int}> $unwindCleanups
     */
    public function __construct(
        public string $contents,
        public GeneratedSourceMap $sourceMap,
        public array $appliedEdits,
        public array $completedResults = [],
        public array $unwindCleanups = [],
    ) {}
}
