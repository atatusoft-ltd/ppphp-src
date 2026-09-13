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
        $generatedStarts = $this->collectDocuments($generated, $parsed, $analysis);
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

    /** @return array<int, array{owner: int, names: list<string>}> Exact generated tags and their validated source owners. */
    public function collectDocuments(GeneratedPhp $generated, ParsedFile $parsed, SemanticAnalysisResult $analysis): array
    {
        $origins = [];
        $unverified = [];
        $model = $analysis->findModel($parsed->sourceFile->path);
        foreach ([...$parsed->extensionSyntax->typedLocals, ...$parsed->extensionSyntax->typedForInitializers, ...$parsed->extensionSyntax->typedForeachBindings] as $local) {
            $offset = $local instanceof TypedLocalDeclaration ? $local->span->start->offset : $local->loopKeywordSpan->start->offset;
            $binding = $model?->bindings->find($local->id);
            $initializer = $binding?->initializerExpression;
            $initializerType = $initializer === null ? null : $model->expressionTypes->resolve($parsed->sourceFile, $initializer)?->type;
            if ($binding !== null && (new TypeCompatibility())->compare(
                $binding->type->semanticType,
                $initializerType ?? $binding->initializerType->semanticType,
                $analysis->symbols,
                $model->whenExpressions->resolveArrayFreshness($initializer),
            ) === TypeCompatibilityResult::Compatible) {
                $origins[$offset] = true;
            } else {
                $unverified[$offset] = true;
            }
        }
        foreach ($model?->whenExpressions->expressions ?? [] as $when) {
            // The emitter marks only known result contracts and fixed-type
            // control state. Unknown result annotations remain unmarked.
            $origins[$when->syntax->span->start->offset] = true;
        }
        $origins = array_diff_key($origins, $unverified);
        if ($origins === []) {
            return [];
        }

        $generatedStarts = [];
        foreach ($generated->sourceMap->segments as $segment) {
            if ($segment->owner !== null && isset($origins[$segment->owner->start->offset])) {
                $generatedStarts[$segment->generatedStart] = $segment->owner->start->offset;
            }
        }

        $documents = [];
        $phpDocReader = new PhpDocReader();
        foreach (\PhpToken::tokenize($generated->contents) as $token) {
            if ($token->id === T_DOC_COMMENT && isset($generatedStarts[$token->pos])
                && $phpDocReader->hasVariableAssertions(new \PhpParser\Comment\Doc($token->text))) {
                $documents[$token->pos] = [
                    'owner' => $generatedStarts[$token->pos],
                    'names' => array_keys($phpDocReader->readMetadata(new \PhpParser\Comment\Doc($token->text))->variables),
                ];
            }
        }
        return $documents;
    }
}
