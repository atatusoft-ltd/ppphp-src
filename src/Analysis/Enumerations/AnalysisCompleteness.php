<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Enumerations;

enum AnalysisCompleteness: string
{
    case CompilerCore = 'compilerCore';
    case Full = 'full';
    case ExperimentalScalarProof = 'experimentalScalarProof';
}
