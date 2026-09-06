<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis;

use Atatusoft\Ppphp\Frontend\ParsedFile;
use Atatusoft\Ppphp\Frontend\Ast\TypedLocalDeclaration;
use Atatusoft\Ppphp\Interop\PhpDoc\PhpDocReader;
use Atatusoft\Ppphp\Transpilation\GeneratedPhp;
use Atatusoft\Ppphp\Semantic\SemanticAnalysisResult;
use Atatusoft\Ppphp\Semantic\Type\TypeCompatibility;
use Atatusoft\Ppphp\Semantic\Type\TypeCompatibilityResult;

/** Distinguishes validated local declarations from user-written PHPDoc assertions. */
final class GeneratedTypeDeclarationIndex
{
    /** @return list<int> */
    public function collect(GeneratedPhp $generated, ParsedFile $parsed, SemanticAnalysisResult $analysis): array
    {
        $origins = [];
        $unverified = [];
        $model = $analysis->findModel($parsed->sourceFile->path);
        foreach ([...$parsed->extensionSyntax->typedLocals, ...$parsed->extensionSyntax->typedForInitializers, ...$parsed->extensionSyntax->typedForeachBindings] as $local) {
            $offset = $local instanceof TypedLocalDeclaration ? $local->span->start->offset : $local->loopKeywordSpan->start->offset;
            $binding = $model?->bindings->find($local->id);
            if ($binding !== null && (new TypeCompatibility())->compare(
                $binding->type->semanticType,
                $binding->initializerType->semanticType,
                $analysis->symbols,
                $binding->initializerExpression instanceof \PhpParser\Node\Expr\Array_,
            ) === TypeCompatibilityResult::Compatible) {
                $origins[$offset] = true;
            } else {
                $unverified[$offset] = true;
            }
        }
        $origins = array_diff_key($origins, $unverified);
        if ($origins === []) {
            return [];
        }

        $generatedStarts = [];
        foreach ($generated->sourceMap->segments as $segment) {
            if ($segment->owner !== null && isset($origins[$segment->owner->start->offset])) {
                $generatedStarts[$segment->generatedStart] = true;
            }
        }

        $lines = [];
        $authoredLines = [];
        $pending = null;
        $pendingAuthored = false;
        $phpDocReader = new PhpDocReader();
        foreach (\PhpToken::tokenize($generated->contents) as $token) {
            if ($token->id === T_DOC_COMMENT && $phpDocReader->hasVariableAssertions(new \PhpParser\Comment\Doc($token->text))) {
                $isGenerated = isset($generatedStarts[$token->pos]);
                $pending = ($pending ?? false) || $isGenerated;
                $pendingAuthored = $pendingAuthored || !$isGenerated;
                continue;
            }
            if ($token->isIgnorable()) {
                continue;
            }
            if ($pending !== null) {
                if ($pending) {
                    $lines[$token->line] = true;
                }
                if ($pendingAuthored) {
                    $authoredLines[$token->line] = true;
                }
                $pending = null;
                $pendingAuthored = false;
            }
        }

        // Findings have only a line, not a column: retain ambiguous findings.
        return array_keys(array_diff_key($lines, $authoredLines));
    }
}
