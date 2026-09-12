<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis;

use Atatusoft\Ppphp\Frontend\Normalization\SourceMask;
use Atatusoft\Ppphp\Transpilation\GeneratedPhp;

/** Static-checking view only: never execute this projection as runtime PHP. */
final class AnalysisSourceProjector
{
    public function project(GeneratedPhp $generated): string
    {
        $contents = $generated->contents;
        foreach ($generated->unwindCleanups as $range) {
            // Provenance comes from the lowering AST, never variable names or
            // diagnostic text. The original body, source catches and normal
            // finally remain: their throw effects must still reach callers.
            $length = $range['end'] - $range['start'];
            $contents = substr_replace($contents, SourceMask::erase(substr($contents, $range['start'], $length)),
                $range['start'], $length);
        }
        return $contents;
    }
}
