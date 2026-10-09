<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\PhpStan;

final readonly class PhpStanParsedResult
{
    /**
     * @param list<PhpStanFinding> $findings
     * @param list<string> $globalErrors
     * @param list<array{path: string, offset: int, name: string}> $localAnnotationOmissions
     */
    public function __construct(
        public array $findings,
        public array $globalErrors,
        public array $localAnnotationOmissions = [],
    ) {}
}
