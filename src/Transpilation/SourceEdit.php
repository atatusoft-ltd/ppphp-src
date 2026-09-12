<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Transpilation;

use Atatusoft\Ppphp\Source\Span;

final readonly class SourceEdit
{
    /** @param list<SourceEditMapping> $mappings
     * @param array<int, array{name: string, definitions: list<int>}> $completedResults
     * @param list<array{start: int, end: int}> $unwindCleanups
     */
    public function __construct(
        public Span $span,
        public string $replacement,
        public array $mappings = [],
        public array $completedResults = [],
        public array $unwindCleanups = [],
    ) {}
}
