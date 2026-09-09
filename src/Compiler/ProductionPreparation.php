<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Compiler;

use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;

final readonly class ProductionPreparation
{
    /** @param list<CompilationArtifact> $artifacts */
    public function __construct(public array $artifacts, public DiagnosticBag $diagnostics) {}
}
