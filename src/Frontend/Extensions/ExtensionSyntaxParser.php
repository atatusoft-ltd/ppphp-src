<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Frontend\Extensions;

use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Diagnostics\DiagnosticLabel;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Frontend\Ast\ExtensionSyntaxIndex;
use Atatusoft\Ppphp\Frontend\Ast\Enumerations\ForeachBindingPosition;
use Atatusoft\Ppphp\Frontend\Ast\GenericDeclaration;
use Atatusoft\Ppphp\Frontend\Ast\GenericParameter;
use Atatusoft\Ppphp\Frontend\Ast\GenericType;
use Atatusoft\Ppphp\Frontend\Ast\Interfaces\Node;
use Atatusoft\Ppphp\Frontend\Ast\NodeId;
use Atatusoft\Ppphp\Frontend\Ast\SourceType;
use Atatusoft\Ppphp\Frontend\Ast\ThrowsClause;
use Atatusoft\Ppphp\Frontend\Ast\TypedLocalDeclaration;
use Atatusoft\Ppphp\Frontend\Ast\TypedForInitializer;
use Atatusoft\Ppphp\Frontend\Ast\TypedForeachBinding;
use Atatusoft\Ppphp\Frontend\Ast\WhenBranch;
use Atatusoft\Ppphp\Frontend\Ast\WhenElseBranch;
use Atatusoft\Ppphp\Frontend\Ast\WhenExpression;
use Atatusoft\Ppphp\Frontend\Normalization\NormalizationEdit;
use Atatusoft\Ppphp\Frontend\Normalization\NormalizationPlan;
use Atatusoft\Ppphp\Frontend\Normalization\SourceMask;
use Atatusoft\Ppphp\Frontend\Token\Token;
use Atatusoft\Ppphp\Frontend\Token\TokenStream;
use Atatusoft\Ppphp\Source\SourceFile;
use Atatusoft\Ppphp\Source\Span;

final class ExtensionSyntaxParser
{
    private SourceFile $sourceFile;

    private TokenStream $tokenStream;

    /** @var list<Token> */
    private array $tokens = [];

    /** @var list<TypedLocalDeclaration> */
    private array $typedLocals = [];

    /** @var list<TypedForInitializer> */
    private array $typedForInitializers = [];

    /** @var list<TypedForeachBinding> */
    private array $typedForeachBindings = [];

    /** @var list<GenericDeclaration> */
    private array $genericDeclarations = [];

    /** @var array<string, GenericType> */
    private array $genericTypes = [];

    /** @var list<ThrowsClause> */
    private array $throwsClauses = [];

    /** @var list<WhenExpression> */
    private array $whenExpressions = [];

    /** @var list<NormalizationEdit> */
    private array $edits = [];

    /** @var list<Diagnostic> */
    private array $diagnostics = [];

    private readonly SourceTypeParser $typeParser;

    public function __construct(?SourceTypeParser $typeParser = null)
    {
        $this->typeParser = $typeParser ?? new SourceTypeParser();
    }

    public function parse(SourceFile $sourceFile, TokenStream $tokenStream): ExtensionParseResult
    {
        $this->sourceFile = $sourceFile;
        $this->tokenStream = $tokenStream;
        $this->tokens = $tokenStream->resolveSignificantTokens();
        $this->typedLocals = [];
        $this->typedForInitializers = [];
        $this->typedForeachBindings = [];
        $this->genericDeclarations = [];
        $this->genericTypes = [];
        $this->throwsClauses = [];
        $this->whenExpressions = [];
        $this->edits = [];
        $this->diagnostics = [];

        $this->parseDeclarationsAndThrows();
        $this->parseLoopBindings();
        $this->parseTypedLocals();
        $this->parseWhenExpressions();
        $this->parseGenericReferences();

        $nodes = [
            ...$this->typedLocals,
            ...$this->typedForInitializers,
            ...$this->typedForeachBindings,
            ...$this->genericDeclarations,
            ...array_values($this->genericTypes),
            ...$this->throwsClauses,
            ...$this->whenExpressions,
        ];
        usort($nodes, static fn (Node $left, Node $right): int =>
            ($left->span->start->offset <=> $right->span->start->offset)
                ?: ($left->span->end->offset <=> $right->span->end->offset)
                ?: ($left->id->value <=> $right->id->value));

        $diagnostics = new DiagnosticBag();
        usort($this->diagnostics, static fn (Diagnostic $left, Diagnostic $right): int =>
            (($left->primary?->span->start->offset ?? PHP_INT_MAX) <=> ($right->primary?->span->start->offset ?? PHP_INT_MAX))
                ?: ($left->code->value <=> $right->code->value));
        $diagnostics->addAll($this->diagnostics);

        try {
            $plan = new NormalizationPlan($sourceFile, $this->resolveNonOverlappingEdits());
        } catch (\Throwable $exception) {
            $this->addDiagnostic(
                DiagnosticCode::ExtensionNormalizationFailed,
                'The extension syntax could not be normalized safely.',
                $sourceFile->createSpan(0, 0),
            );
            $diagnostics = new DiagnosticBag();
            $diagnostics->addAll($this->diagnostics);
            $plan = new NormalizationPlan($sourceFile);
        }

        return new ExtensionParseResult(
            new ExtensionSyntaxIndex(
                $this->typedLocals,
                $this->typedForInitializers,
                $this->typedForeachBindings,
                $this->genericDeclarations,
                array_values($this->genericTypes),
                $this->throwsClauses,
                $this->whenExpressions,
                $nodes,
            ),
            $plan,
            $diagnostics,
        );
    }

    private function parseLoopBindings(): void
    {
        foreach ($this->tokens as $index => $token) {
            if ($token->lexicalId === T_FOR) {
                $this->parseTypedForInitializer($index);
            } elseif ($token->lexicalId === T_FOREACH) {
                $this->parseTypedForeachBindings($index);
            }
        }
    }

    private function parseTypedForInitializer(int $forIndex): void
    {
        $keyword = $this->tokens[$forIndex];
        $openIndex = $forIndex + 1;

        if (($this->tokens[$openIndex] ?? null)?->text !== '(') {
            return;
        }

        $closeIndex = $this->resolveMatching($openIndex, '(', ')');

        if ($closeIndex === null) {
            return;
        }

        $separatorIndex = $this->resolveTopLevelTokenIndex($openIndex + 1, $closeIndex, [';']);

        if ($separatorIndex === null || $separatorIndex === $openIndex + 1) {
            return;
        }

        $first = $this->tokens[$openIndex + 1];

        if ($first->lexicalId === T_VARIABLE) {
            return;
        }

        $variableIndex = $this->resolveTokenIndexByLexicalId($openIndex + 1, $separatorIndex, T_VARIABLE);
        $equalsIndex = $this->resolveTopLevelTokenIndex($openIndex + 1, $separatorIndex, ['=']);

        if ($variableIndex === null || $equalsIndex === null || $variableIndex >= $equalsIndex) {
            return;
        }

        $readonly = null;
        $typeStartIndex = $openIndex + 1;

        if (strtolower($this->tokens[$typeStartIndex]->text) === 'readonly') {
            $readonly = $this->tokens[$typeStartIndex]->span;
            $typeStartIndex++;
        }

        try {
            $type = $this->typeParser->parse(
                $this->sourceFile,
                $this->tokenStream,
                $this->tokens[$typeStartIndex]->start,
                $this->tokens[$variableIndex]->start,
            );
        } catch (ExtensionSyntaxException $exception) {
            $this->addMalformed($exception->getMessage(), $exception->span, $exception->isUnsupported);

            return;
        }

        $commaIndex = $this->resolveTopLevelTokenIndex($equalsIndex + 1, $separatorIndex, [',']);
        $initializerEndIndex = $commaIndex ?? $separatorIndex;
        $initializerStart = $this->tokens[$equalsIndex + 1] ?? null;

        if ($initializerStart === null || $initializerStart->start >= $this->tokens[$initializerEndIndex]->start) {
            $this->addMalformed('A typed for initializer requires a value after `=`.', $this->tokens[$equalsIndex]->span);

            return;
        }

        $initializerEnd = $this->tokens[$initializerEndIndex - 1]->end;
        $span = $this->sourceFile->createSpan($first->start, $initializerEnd);
        $node = new TypedForInitializer(
            NodeId::create('typed-for-initializer', $span),
            $span,
            $keyword->span,
            $readonly,
            $type,
            $this->tokens[$variableIndex]->span,
            $this->tokens[$equalsIndex]->span,
            $this->sourceFile->createSpan($initializerStart->start, $initializerEnd),
        );
        $this->typedForInitializers[] = $node;
        $this->recordGenericTypes($type);
        $prefix = $this->sourceFile->createSpan($first->start, $this->tokens[$variableIndex]->start);
        $this->edits[] = new NormalizationEdit($prefix, $this->mask($prefix->text), $node->id);

        if ($commaIndex !== null && $this->rangeContainsTypedBinding($commaIndex + 1, $separatorIndex)) {
            $this->addDiagnostic(
                DiagnosticCode::MultipleTypedForInitializersNotSupported,
                'A for initializer may contain only one new typed declaration.',
                $this->tokens[$commaIndex]->span,
            );
            $this->normalizeAdditionalTypedForInitializers($commaIndex + 1, $separatorIndex, $node);
        }
    }

    private function parseTypedForeachBindings(int $foreachIndex): void
    {
        $keyword = $this->tokens[$foreachIndex];
        $openIndex = $foreachIndex + 1;

        if (($this->tokens[$openIndex] ?? null)?->text !== '(') {
            return;
        }

        $closeIndex = $this->resolveMatching($openIndex, '(', ')');

        if ($closeIndex === null) {
            return;
        }

        $asIndex = $this->resolveTopLevelTokenIndex($openIndex + 1, $closeIndex, ['as']);

        if ($asIndex === null) {
            return;
        }

        $arrowIndex = $this->resolveTopLevelTokenIndex($asIndex + 1, $closeIndex, ['=>']);

        if ($arrowIndex !== null) {
            $this->parseTypedForeachBinding($keyword, $asIndex + 1, $arrowIndex, ForeachBindingPosition::Key);
            $this->parseTypedForeachBinding($keyword, $arrowIndex + 1, $closeIndex, ForeachBindingPosition::Value);

            return;
        }

        $this->parseTypedForeachBinding($keyword, $asIndex + 1, $closeIndex, ForeachBindingPosition::Value);
    }

    private function parseTypedForeachBinding(
        Token $keyword,
        int $startIndex,
        int $endIndex,
        ForeachBindingPosition $position,
    ): void {
        if ($startIndex >= $endIndex) {
            return;
        }

        $cursor = $startIndex;

        if (($this->tokens[$cursor] ?? null)?->text === '&') {
            $cursor++;
        }

        $first = $this->tokens[$cursor] ?? null;

        if ($first === null || $first->lexicalId === T_VARIABLE) {
            return;
        }

        if (in_array($first->text, ['[', 'list'], true)) {
            $this->parseUnsupportedTypedDestructuring($cursor, $endIndex);

            return;
        }

        $readonly = null;

        if (strtolower($first->text) === 'readonly') {
            $readonly = $first->span;
            $cursor++;
        }

        $variableIndex = $this->resolveTokenIndexByLexicalId($cursor, $endIndex, T_VARIABLE);

        if ($variableIndex === null || $variableIndex !== $endIndex - 1 || $cursor >= $variableIndex) {
            return;
        }

        try {
            $type = $this->typeParser->parse(
                $this->sourceFile,
                $this->tokenStream,
                $this->tokens[$cursor]->start,
                $this->tokens[$variableIndex]->start,
            );
        } catch (ExtensionSyntaxException $exception) {
            $this->addMalformed($exception->getMessage(), $exception->span, $exception->isUnsupported);

            return;
        }

        $span = $this->sourceFile->createSpan($first->start, $this->tokens[$variableIndex]->end);
        $node = new TypedForeachBinding(
            NodeId::create('typed-foreach-binding-' . $position->value, $span),
            $span,
            $keyword->span,
            $type,
            $this->tokens[$variableIndex]->span,
            $position,
        );
        $this->typedForeachBindings[] = $node;
        $this->recordGenericTypes($type);
        $prefix = $this->sourceFile->createSpan($first->start, $this->tokens[$variableIndex]->start);
        $this->edits[] = new NormalizationEdit($prefix, $this->mask($prefix->text), $node->id);

        if ($readonly !== null) {
            $this->addDiagnostic(
                DiagnosticCode::ReadonlyForeachBindingNotSupported,
                'A foreach declaration is assigned on every iteration and cannot be readonly.',
                $readonly,
            );
        }

    }

    private function parseUnsupportedTypedDestructuring(int $startIndex, int $endIndex): void
    {
        $found = false;

        for ($index = $startIndex + 1; $index < $endIndex; $index++) {
            if ($this->tokens[$index]->lexicalId !== T_VARIABLE) {
                continue;
            }

            $prefixStart = $index - 1;

            while ($prefixStart >= $startIndex && !in_array($this->tokens[$prefixStart]->text, ['[', '(', ','], true)) {
                $prefixStart--;
            }

            $prefixStart++;

            if ($prefixStart >= $index) {
                continue;
            }

            try {
                $this->typeParser->parse(
                    $this->sourceFile,
                    $this->tokenStream,
                    $this->tokens[$prefixStart]->start,
                    $this->tokens[$index]->start,
                );
            } catch (ExtensionSyntaxException) {
                continue;
            }

            $span = $this->sourceFile->createSpan($this->tokens[$prefixStart]->start, $this->tokens[$index]->start);
            $owner = NodeId::create('typed-foreach-destructuring', $span);
            $this->edits[] = new NormalizationEdit($span, $this->mask($span->text), $owner);
            $found = true;
        }

        if ($found) {
            $this->addMalformed(
                'Typed foreach destructuring declarations are not supported in the MVP.',
                $this->sourceFile->createSpan($this->tokens[$startIndex]->start, $this->tokens[$endIndex - 1]->end),
                true,
            );
        }
    }

    private function normalizeAdditionalTypedForInitializers(
        int $startIndex,
        int $endIndex,
        TypedForInitializer $owner,
    ): void {
        $variableIndex = $this->resolveTokenIndexByLexicalId($startIndex, $endIndex, T_VARIABLE);

        if ($variableIndex === null || $variableIndex <= $startIndex) {
            return;
        }

        $span = $this->sourceFile->createSpan($this->tokens[$startIndex]->start, $this->tokens[$variableIndex]->start);
        $this->edits[] = new NormalizationEdit($span, $this->mask($span->text), $owner->id);
    }

    private function rangeContainsTypedBinding(int $startIndex, int $endIndex): bool
    {
        $variableIndex = $this->resolveTokenIndexByLexicalId($startIndex, $endIndex, T_VARIABLE);

        return $variableIndex !== null
            && $variableIndex > $startIndex
            && $this->tokens[$startIndex]->lexicalId !== T_VARIABLE;
    }

    private function parseDeclarationsAndThrows(): void
    {
        foreach ($this->tokens as $index => $token) {
            if (in_array($token->lexicalId, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                $this->parseClassLikeDeclaration($index);
            }

            if ($token->lexicalId === T_FUNCTION) {
                $this->parseFunctionDeclaration($index);
            }
        }

        $this->parseUnsupportedThrowsPlacements();
    }

    private function parseUnsupportedThrowsPlacements(): void
    {
        foreach ($this->tokens as $index => $token) {
            if (strtolower($token->text) !== 'throws' || $this->containsRecordedThrowsKeyword($token->start)) {
                continue;
            }

            $previous = $this->tokens[$index - 1] ?? null;
            $next = $this->tokens[$index + 1] ?? null;

            if (
                $previous?->lexicalId === T_FUNCTION
                || in_array($previous?->text, ['->', '?->', '::'], true)
                || in_array($next?->text, ['(', ':', ';', '='], true)
            ) {
                continue;
            }

            if ($next !== null && $this->matchesTypeName($next)) {
                $this->addMalformed(
                    'A `throws` clause is supported only after a named function or method signature.',
                    $token->span,
                    true,
                );
            }
        }
    }

    private function containsRecordedThrowsKeyword(int $offset): bool
    {
        foreach ($this->throwsClauses as $clause) {
            if ($clause->keywordSpan->start->offset === $offset) {
                return true;
            }
        }

        return false;
    }

    private function parseClassLikeDeclaration(int $keywordIndex): void
    {
        $keyword = $this->tokens[$keywordIndex];
        $nameIndex = $keywordIndex + 1;

        $name = $this->tokens[$nameIndex] ?? null;
        $angle = $this->tokens[$nameIndex + 1] ?? null;

        if ($keyword->lexicalId === T_CLASS && $name?->text === '<') {
            $this->addMalformed('Generic anonymous classes are not supported.', $name->span, true);

            return;
        }

        if ($angle?->text !== '<') {
            return;
        }

        if ($keyword->lexicalId === T_ENUM) {
            $this->addMalformed('Generic enum declarations are not supported.', $angle->span, true);

            return;
        }

        if ($name === null || !$this->matchesName($name)) {
            $this->addMalformed('Generic anonymous classes are not supported.', $angle->span, true);

            return;
        }

        $this->parseGenericDeclaration(
            strtolower($keyword->text),
            $name,
            $nameIndex + 1,
        );
    }

    private function parseFunctionDeclaration(int $functionIndex): void
    {
        $cursor = $functionIndex + 1;

        if (isset($this->tokens[$cursor]) && $this->tokens[$cursor]->text === '&') {
            $cursor++;
        }

        $name = $this->tokens[$cursor] ?? null;

        if ($name === null || !$this->matchesName($name)) {
            return;
        }

        $cursor++;

        if (isset($this->tokens[$cursor]) && $this->tokens[$cursor]->text === '<') {
            $close = $this->parseGenericDeclaration($this->resolveCallableKindAt($functionIndex), $name, $cursor);

            if ($close === null) {
                return;
            }

            $cursor = $close + 1;
        }

        if (!isset($this->tokens[$cursor]) || $this->tokens[$cursor]->text !== '(') {
            return;
        }

        $parameterClose = $this->resolveMatching($cursor, '(', ')');

        if ($parameterClose === null) {
            return;
        }

        $end = $parameterClose + 1;

        while (isset($this->tokens[$end]) && !in_array($this->tokens[$end]->text, ['{', ';'], true)) {
            if (strtolower($this->tokens[$end]->text) === 'throws') {
                $this->parseThrowsClause(
                    $end,
                    $this->resolveCallableKindAt($functionIndex),
                    $name,
                    $functionIndex,
                );

                return;
            }

            $end++;
        }
    }

    private function parseGenericDeclaration(string $kind, Token $ownerName, int $angleIndex): ?int
    {
        $close = $this->resolveAngleClose($angleIndex);

        if ($close === null) {
            $this->addMalformed('A generic parameter list must end with `>`.', $this->tokens[$angleIndex]->span);

            return null;
        }

        [$closeIndex, $closeEnd] = $close;
        $open = $this->tokens[$angleIndex];
        $contentStart = $open->end;
        $contentEnd = $closeEnd - 1;
        $parts = $this->splitRange($contentStart, $contentEnd, ',');

        if ($parts === [] || ($parts[0][0] === $parts[0][1])) {
            $this->addMalformed('A generic parameter list cannot be empty.', $open->span);

            return $closeIndex;
        }

        $parameters = [];

        foreach ($parts as [$start, $end, $separator]) {
            $partTokens = $this->resolveTokensInRange($start, $end);

            if ($partTokens === []) {
                $this->addMalformed('A generic separator must be followed by a parameter.', $separator ?? $open->span);

                return $closeIndex;
            }

            $parameterName = $partTokens[0];

            if (!$this->matchesName($parameterName)) {
                $this->addMalformed('A generic parameter requires a name.', $parameterName->span);

                return $closeIndex;
            }

            $colon = $partTokens[1] ?? null;
            $bound = null;
            $colonSpan = null;

            if ($colon !== null) {
                if ($colon->text !== ':') {
                    $this->addMalformed('A generic parameter supports only a single `:` upper bound.', $colon->span);

                    return $closeIndex;
                }

                $colonSpan = $colon->span;
                $boundStart = $partTokens[2]->start ?? $end;

                try {
                    $bound = $this->typeParser->parse($this->sourceFile, $this->tokenStream, $boundStart, $end);
                    $this->recordGenericTypes($bound);
                } catch (ExtensionSyntaxException $exception) {
                    $this->addMalformed($exception->getMessage(), $exception->span, $exception->isUnsupported);

                    return $closeIndex;
                }
            }

            $parameterEnd = $bound?->span->end->offset ?? $parameterName->end;
            $span = $this->sourceFile->createSpan($parameterName->start, $parameterEnd);
            $parameters[] = new GenericParameter(
                NodeId::create('generic-parameter', $span),
                $span,
                $parameterName->span,
                $colonSpan,
                $bound,
            );
        }

        $span = $this->sourceFile->createSpan($open->start, $closeEnd);
        $node = new GenericDeclaration(
            NodeId::create('generic-declaration', $span),
            $span,
            $kind,
            $ownerName->span,
            $parameters,
        );
        $this->genericDeclarations[] = $node;
        $this->edits[] = new NormalizationEdit($span, $this->mask($span->text), $node->id);
        return $closeIndex;
    }

    private function parseThrowsClause(
        int $throwsIndex,
        string $ownerKind,
        Token $ownerName,
        int $ownerStartIndex,
    ): void
    {
        $keyword = $this->tokens[$throwsIndex];
        $endIndex = $throwsIndex + 1;

        while (isset($this->tokens[$endIndex]) && !in_array($this->tokens[$endIndex]->text, ['{', ';'], true)) {
            $endIndex++;
        }

        $endToken = $this->tokens[$endIndex] ?? null;
        $contentEnd = $endToken === null ? $this->sourceFile->length : $endToken->start;
        $parts = $this->splitRange($keyword->end, $contentEnd, ',');

        if ($parts === [] || $parts[0][0] === $parts[0][1]) {
            $this->addMalformed('A `throws` clause requires at least one error type.', $keyword->span);

            return;
        }

        $types = [];
        $separators = [];

        foreach ($parts as [$start, $end, $separator]) {
            try {
                $type = $this->typeParser->parse($this->sourceFile, $this->tokenStream, $start, $end);
                $types[] = $type;
                $this->recordGenericTypes($type);
            } catch (ExtensionSyntaxException $exception) {
                $this->addMalformed($exception->getMessage(), $exception->span, $exception->isUnsupported);

                return;
            }

            if ($separator !== null) {
                $separators[] = $separator;
            }
        }

        $lastType = $types[array_key_last($types)];
        $span = $this->sourceFile->createSpan($keyword->start, $lastType->span->end->offset);
        $ownerEnd = $endToken === null ? $lastType->span->end->offset : $endToken->end;

        if ($endToken?->text === '{') {
            $ownerClose = $this->resolveMatching($endIndex, '{', '}');
            $ownerEnd = $ownerClose === null ? $ownerEnd : $this->tokens[$ownerClose]->end;
        }

        $node = new ThrowsClause(
            NodeId::create('throws-clause', $span),
            $span,
            $keyword->span,
            $ownerKind,
            $ownerName->span,
            $this->sourceFile->createSpan($this->tokens[$ownerStartIndex]->start, $ownerEnd),
            $types,
            $separators,
        );
        $this->throwsClauses[] = $node;
        $this->edits[] = new NormalizationEdit($span, $this->mask($span->text), $node->id);
    }

    private function parseTypedLocals(): void
    {
        $callableIntervals = $this->resolveCallableBodyIntervals();
        $classIntervals = $this->resolveClassBodyIntervals();

        foreach ($this->tokens as $variableIndex => $variable) {
            if (
                $variable->lexicalId !== T_VARIABLE
                || !$this->resolvesExecutableContextAt($variableIndex, $callableIntervals, $classIntervals)
            ) {
                continue;
            }

            $startIndex = $this->resolveStatementStart($variableIndex);

            if ($startIndex >= $variableIndex || $this->containsOpenDelimiter($startIndex, $variableIndex)) {
                continue;
            }

            $readonly = null;
            $typeStartIndex = $startIndex;
            $first = $this->tokens[$typeStartIndex];

            if (strtolower($first->text) === 'readonly') {
                $readonly = $first->span;
                $typeStartIndex++;
            }

            if ($typeStartIndex >= $variableIndex) {
                if (
                    $readonly !== null
                    && ($this->tokens[$variableIndex + 1] ?? null)?->text === '='
                ) {
                    $this->addMalformed(
                        'A readonly local declaration requires an explicit written type.',
                        $variable->span,
                    );
                }

                continue;
            }

            if (in_array(strtolower($this->tokens[$typeStartIndex]->text), ['val', 'var'], true)) {
                $this->addMalformed('Inferred `val` and `var` declarations are not supported; write an explicit type.', $this->tokens[$typeStartIndex]->span, true);
                continue;
            }

            if (
                in_array(strtolower($this->tokens[$typeStartIndex]->text), ['static', 'global'], true)
                && $typeStartIndex + 1 === $variableIndex
            ) {
                continue;
            }

            if (in_array(strtolower($this->tokens[$typeStartIndex]->text), ['static', 'global'], true)) {
                $this->addMalformed('Typed locals are not supported in this binding position.', $this->tokens[$typeStartIndex]->span, true);
                continue;
            }

            try {
                $type = $this->typeParser->parse(
                    $this->sourceFile,
                    $this->tokenStream,
                    $this->tokens[$typeStartIndex]->start,
                    $variable->start,
                );
            } catch (ExtensionSyntaxException $exception) {
                if (
                    isset($this->tokens[$variableIndex + 1])
                    && $this->tokens[$variableIndex + 1]->text === '='
                    && $this->matchesPlausibleWrittenTypeRange($typeStartIndex, $variableIndex)
                ) {
                    $this->addMalformed($exception->getMessage(), $exception->span, $exception->isUnsupported);
                }

                continue;
            }

            $equals = $this->tokens[$variableIndex + 1] ?? null;

            if ($equals?->text !== '=') {
                $this->addMalformed('A typed local declaration requires `=` and an initializer.', $variable->span);
                continue;
            }

            $semicolonIndex = $this->resolveStatementTerminator($variableIndex + 2);

            if ($semicolonIndex === null) {
                $this->addMalformed('A typed local declaration must end with `;`.', $equals->span);
                continue;
            }

            $initializerStart = $this->tokens[$variableIndex + 2] ?? null;
            $semicolon = $this->tokens[$semicolonIndex];

            if ($initializerStart === null || $initializerStart->start >= $semicolon->start) {
                $this->addMalformed('A typed local declaration requires an initializer.', $equals->span);
                continue;
            }

            $statementSpan = $this->sourceFile->createSpan($this->tokens[$startIndex]->start, $semicolon->end);
            $initializerEnd = $this->tokens[$semicolonIndex - 1]->end;
            $initializerSpan = $this->sourceFile->createSpan($initializerStart->start, $initializerEnd);
            $node = new TypedLocalDeclaration(
                NodeId::create('typed-local', $statementSpan),
                $statementSpan,
                $readonly,
                $type,
                $variable->span,
                $equals->span,
                $initializerSpan,
                $semicolon->span,
            );
            $this->typedLocals[] = $node;
            $this->recordGenericTypes($type);

            $prefix = $this->sourceFile->createSpan($this->tokens[$startIndex]->start, $variable->start);
            $this->edits[] = new NormalizationEdit($prefix, $this->mask($prefix->text), $node->id);
        }

        $this->parseTypedLocalsMissingVariables($callableIntervals, $classIntervals);
    }

    /**
     * @param list<array{int, int}> $callableIntervals
     * @param list<array{int, int}> $classIntervals
     */
    private function parseTypedLocalsMissingVariables(array $callableIntervals, array $classIntervals): void
    {
        foreach ($this->tokens as $equalsIndex => $equals) {
            if (
                $equals->text !== '='
                || !$this->resolvesExecutableContextAt($equalsIndex, $callableIntervals, $classIntervals)
            ) {
                continue;
            }

            $startIndex = $this->resolveStatementStart($equalsIndex);

            if ($startIndex >= $equalsIndex) {
                continue;
            }

            $hasVariable = false;

            for ($index = $startIndex; $index < $equalsIndex; $index++) {
                $hasVariable = $hasVariable || $this->tokens[$index]->lexicalId === T_VARIABLE;
            }

            if ($hasVariable) {
                continue;
            }

            try {
                $this->typeParser->parse(
                    $this->sourceFile,
                    $this->tokenStream,
                    $this->tokens[$startIndex]->start,
                    $equals->start,
                );
            } catch (ExtensionSyntaxException) {
                continue;
            }

            $this->addMalformed('A typed local declaration requires a variable after its written type.', $equals->span);
        }
    }

    private function parseWhenExpressions(): void
    {
        foreach ($this->tokens as $index => $token) {
            if (strtolower($token->text) !== 'when') {
                continue;
            }

            if (!$this->matchesWhenExpressionPosition($index) && !$this->matchesUnsupportedWhenPosition($index)) {
                continue;
            }

            $openCondition = $this->tokens[$index + 1] ?? null;

            if ($openCondition?->text !== '(') {
                $this->addMalformed('A `when` expression requires a parenthesized condition.', $token->span);
                continue;
            }

            $conditionClose = $this->resolveMatching($index + 1, '(', ')');

            if ($conditionClose === null) {
                $this->addMalformed('A `when` condition must end with `)`.', $openCondition->span);
                continue;
            }

            $bodyOpenIndex = $conditionClose + 1;

            if (!isset($this->tokens[$bodyOpenIndex]) || $this->tokens[$bodyOpenIndex]->text !== '{') {
                // A normal function call named `when` remains ordinary PHP.
                continue;
            }

            $bodyCloseIndex = $this->resolveMatching($bodyOpenIndex, '{', '}');

            if ($bodyCloseIndex === null) {
                $this->addMalformed('A `when` branch body must end with `}`.', $this->tokens[$bodyOpenIndex]->span);
                continue;
            }

            $branches = [];
            $branchSpan = $this->sourceFile->createSpan($token->start, $this->tokens[$bodyCloseIndex]->end);
            $branches[] = new WhenBranch(
                NodeId::create('when-branch', $branchSpan),
                $branchSpan,
                $token->span,
                $this->sourceFile->createSpan($openCondition->end, $this->tokens[$conditionClose]->start),
                $this->sourceFile->createSpan($this->tokens[$bodyOpenIndex]->start, $this->tokens[$bodyCloseIndex]->end),
            );
            $cursor = $bodyCloseIndex + 1;
            $elseBranch = null;

            while (isset($this->tokens[$cursor]) && $this->tokens[$cursor]->lexicalId === T_ELSE) {
                $elseToken = $this->tokens[$cursor];
                $next = $this->tokens[$cursor + 1] ?? null;

                if ($next !== null && strtolower($next->text) === 'when') {
                    $nextConditionOpen = $this->tokens[$cursor + 2] ?? null;

                    if ($nextConditionOpen?->text !== '(') {
                        $this->addMalformed('An `else when` branch requires a parenthesized condition.', $next->span);
                        break 2;
                    }

                    $nextConditionClose = $this->resolveMatching($cursor + 2, '(', ')');
                    if ($nextConditionClose === null) {
                        $this->addMalformed('Every `else when` branch requires a braced body.', $next->span);
                        break 2;
                    }

                    $nextBodyOpen = $nextConditionClose + 1;

                    if (!isset($this->tokens[$nextBodyOpen]) || $this->tokens[$nextBodyOpen]->text !== '{') {
                        $this->addMalformed('Every `else when` branch requires a braced body.', $next->span);
                        break 2;
                    }

                    $nextBodyClose = $this->resolveMatching($nextBodyOpen, '{', '}');

                    if ($nextBodyClose === null) {
                        $this->addMalformed('An `else when` body must end with `}`.', $this->tokens[$nextBodyOpen]->span);
                        break 2;
                    }

                    $nextSpan = $this->sourceFile->createSpan($elseToken->start, $this->tokens[$nextBodyClose]->end);
                    $branches[] = new WhenBranch(
                        NodeId::create('when-branch', $nextSpan),
                        $nextSpan,
                        $next->span,
                        $this->sourceFile->createSpan($nextConditionOpen->end, $this->tokens[$nextConditionClose]->start),
                        $this->sourceFile->createSpan($this->tokens[$nextBodyOpen]->start, $this->tokens[$nextBodyClose]->end),
                        $elseToken->span,
                    );
                    $cursor = $nextBodyClose + 1;
                    continue;
                }

                if ($next?->text !== '{') {
                    $this->addMalformed('The final `else` branch requires a braced body.', $elseToken->span);
                    break 2;
                }

                $elseClose = $this->resolveMatching($cursor + 1, '{', '}');

                if ($elseClose === null) {
                    $this->addMalformed('The final `else` body must end with `}`.', $next->span);
                    break 2;
                }

                $elseSpan = $this->sourceFile->createSpan($elseToken->start, $this->tokens[$elseClose]->end);
                $elseBranch = new WhenElseBranch(
                    NodeId::create('when-else', $elseSpan),
                    $elseSpan,
                    $elseToken->span,
                    $this->sourceFile->createSpan($next->start, $this->tokens[$elseClose]->end),
                );
                $cursor = $elseClose + 1;
                break;
            }

            if ($elseBranch === null) {
                $this->addMalformed('A `when` expression requires a final `else` branch.', $token->span);
                continue;
            }

            $span = $this->sourceFile->createSpan($token->start, $elseBranch->span->end->offset);
            $parent = $this->resolveContainingWhenExpression($token->start);
            $node = new WhenExpression(
                NodeId::create('when-expression', $span),
                $span,
                $branches,
                $elseBranch,
                $parent?->id,
                $parent === null ? 0 : $parent->depth + 1,
            );
            $this->whenExpressions[] = $node;
            $this->edits[] = new NormalizationEdit($span, $this->placeholder($span->text), $node->id);
        }
    }

    private function parseGenericReferences(): void
    {
        foreach ($this->tokens as $angleIndex => $angle) {
            if ($this->containsAttributeIndex($angleIndex)) {
                continue;
            }

            if ($angle->text === '<>') {
                $base = $this->tokens[$angleIndex - 1] ?? null;
                $suffix = $this->tokens[$angleIndex + 1] ?? null;

                if ($base !== null && $this->matchesTypeName($base) && $this->matchesTypeContextSuffix($suffix)) {
                    $this->addMalformed('A generic argument list cannot be empty.', $angle->span);
                }

                continue;
            }

            if ($angle->text !== '<') {
                continue;
            }

            $base = $this->tokens[$angleIndex - 1] ?? null;

            if (
                $base === null
                || !$this->matchesTypeName($base)
                || $this->containsGenericDeclarationOffset($angle->start)
                || $this->containsGenericTypeOffset($angle->start)
            ) {
                continue;
            }

            $close = $this->resolveAngleClose($angleIndex);

            if ($close === null) {
                if (strtolower($base->text) === 'array') {
                    $this->addMalformed('A generic type reference must end with `>`.', $angle->span);
                }

                continue;
            }

            [$closeIndex, $closeEnd] = $close;
            $suffix = $this->tokens[$closeIndex + 1] ?? null;
            $prefix = $this->tokens[$angleIndex - 2] ?? null;

            if (
                in_array($suffix?->text, ['(', '::'], true)
                || in_array($prefix?->lexicalId, [T_NEW, T_NAMESPACE], true)
            ) {
                $this->addMalformed('Runtime and call-site generic arguments are not supported.', $angle->span, true);
                continue;
            }

            if (!$this->matchesTypeContextSuffix($suffix)) {
                continue;
            }

            try {
                $type = $this->typeParser->parse($this->sourceFile, $this->tokenStream, $base->start, $closeEnd);
            } catch (ExtensionSyntaxException $exception) {
                $this->addMalformed($exception->getMessage(), $exception->span, $exception->isUnsupported);
                continue;
            }

            $before = count($this->genericTypes);
            $this->recordGenericTypes($type);

            if (count($this->genericTypes) === $before) {
                continue;
            }

            $outerKey = array_key_last($type->genericReferences);
            $outer = $outerKey === null ? null : $type->genericReferences[$outerKey];

            if ($outer !== null) {
                $this->edits[] = new NormalizationEdit(
                    $outer->argumentListSpan,
                    $this->mask($outer->argumentListSpan->text),
                    $outer->id,
                );
            }
        }
    }

    /** @return list<array{int, int}> */
    private function resolveCallableBodyIntervals(): array
    {
        $intervals = [];

        foreach ($this->tokens as $index => $token) {
            if ($token->lexicalId !== T_FUNCTION) {
                continue;
            }

            $open = $index + 1;

            while (isset($this->tokens[$open]) && $this->tokens[$open]->text !== '(') {
                $open++;
            }

            if (!isset($this->tokens[$open])) {
                continue;
            }

            $close = $this->resolveMatching($open, '(', ')');

            if ($close === null) {
                continue;
            }

            $body = $close + 1;

            while (isset($this->tokens[$body]) && !in_array($this->tokens[$body]->text, ['{', ';'], true)) {
                $body++;
            }

            if (!isset($this->tokens[$body]) || $this->tokens[$body]->text !== '{') {
                continue;
            }

            $bodyClose = $this->resolveMatching($body, '{', '}');

            if ($bodyClose !== null) {
                $intervals[] = [$body + 1, $bodyClose];
            }
        }

        foreach ($this->tokens as $index => $token) {
            if ($token->lexicalId !== T_STRING || !in_array(strtolower($token->text), ['get', 'set'], true)) {
                continue;
            }

            $body = $index + 1;

            if (($this->tokens[$body] ?? null)?->text === '(') {
                $close = $this->resolveMatching($body, '(', ')');

                if ($close === null) {
                    continue;
                }

                $body = $close + 1;
            }

            if (($this->tokens[$body] ?? null)?->text !== '{') {
                continue;
            }

            $bodyClose = $this->resolveMatching($body, '{', '}');

            if ($bodyClose !== null) {
                $intervals[] = [$body + 1, $bodyClose];
            }
        }

        return $intervals;
    }

    /** @return list<array{int, int}> */
    private function resolveClassBodyIntervals(): array
    {
        $intervals = [];

        foreach ($this->tokens as $index => $token) {
            if (!in_array($token->lexicalId, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                continue;
            }

            if (($this->tokens[$index - 1] ?? null)?->text === '::') {
                continue;
            }

            $body = $index + 1;

            while (isset($this->tokens[$body]) && $this->tokens[$body]->text !== '{') {
                $body++;
            }

            if (!isset($this->tokens[$body])) {
                continue;
            }

            $close = $this->resolveMatching($body, '{', '}');

            if ($close !== null) {
                $intervals[] = [$body + 1, $close];
            }
        }

        return $intervals;
    }

    /**
     * @param list<array{int, int}> $callableIntervals
     * @param list<array{int, int}> $classIntervals
     */
    private function resolvesExecutableContextAt(int $index, array $callableIntervals, array $classIntervals): bool
    {
        $callableStart = -1;
        $classStart = -1;

        foreach ($callableIntervals as [$start, $end]) {
            if ($index >= $start && $index < $end) {
                $callableStart = max($callableStart, $start);
            }
        }

        foreach ($classIntervals as [$start, $end]) {
            if ($index >= $start && $index < $end) {
                $classStart = max($classStart, $start);
            }
        }

        return $classStart < 0 || $callableStart > $classStart;
    }

    private function resolveCallableKindAt(int $index): string
    {
        $classStart = -1;
        $callableStart = -1;

        foreach ($this->resolveClassBodyIntervals() as [$start, $end]) {
            if ($index >= $start && $index < $end) {
                $classStart = max($classStart, $start);
            }
        }

        foreach ($this->resolveCallableBodyIntervals() as [$start, $end]) {
            if ($index >= $start && $index < $end) {
                $callableStart = max($callableStart, $start);
            }
        }

        return $classStart > $callableStart ? 'method' : 'function';
    }

    private function resolveStatementStart(int $index): int
    {
        for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
            if (in_array($this->tokens[$cursor]->text, [';', '{', '}'], true)) {
                return $cursor + 1;
            }
        }

        return isset($this->tokens[0]) && $this->tokens[0]->lexicalId === T_OPEN_TAG ? 1 : 0;
    }

    private function containsOpenDelimiter(int $start, int $end): bool
    {
        $depth = 0;

        for ($index = $start; $index < $end; $index++) {
            $text = $this->tokens[$index]->text;
            $depth += in_array($text, ['(', '['], true) ? 1 : 0;
            $depth -= in_array($text, [')', ']'], true) ? 1 : 0;
        }

        return $depth !== 0;
    }

    private function matchesPlausibleWrittenTypeRange(int $start, int $end): bool
    {
        for ($index = $start; $index < $end; $index++) {
            if (in_array($this->tokens[$index]->text, [
                '::',
                '->',
                '?->',
                '[',
                ']',
                '+',
                '-',
                '*',
                '/',
                '%',
                '!',
                '==',
                '===',
            ], true)) {
                return false;
            }
        }

        return true;
    }

    private function resolveStatementTerminator(int $start): ?int
    {
        $round = 0;
        $square = 0;
        $curly = 0;

        for ($index = $start; isset($this->tokens[$index]); $index++) {
            $text = $this->tokens[$index]->text;
            $round += $text === '(' ? 1 : ($text === ')' ? -1 : 0);
            $square += $text === '[' ? 1 : ($text === ']' ? -1 : 0);
            $curly += $text === '{' ? 1 : ($text === '}' ? -1 : 0);

            if ($text === ';' && $round === 0 && $square === 0 && $curly === 0) {
                return $index;
            }

            if ($curly < 0) {
                return null;
            }
        }

        return null;
    }

    /** @param list<string> $targets */
    private function resolveTopLevelTokenIndex(int $start, int $end, array $targets): ?int
    {
        $round = 0;
        $square = 0;
        $curly = 0;

        for ($index = $start; $index < $end; $index++) {
            $text = $this->tokens[$index]->text;

            if ($round === 0 && $square === 0 && $curly === 0 && in_array(strtolower($text), $targets, true)) {
                return $index;
            }

            $round += $text === '(' ? 1 : ($text === ')' ? -1 : 0);
            $square += $text === '[' ? 1 : ($text === ']' ? -1 : 0);
            $curly += $text === '{' ? 1 : ($text === '}' ? -1 : 0);
        }

        return null;
    }

    private function resolveTokenIndexByLexicalId(int $start, int $end, int $lexicalId): ?int
    {
        for ($index = $start; $index < $end; $index++) {
            if ($this->tokens[$index]->lexicalId === $lexicalId) {
                return $index;
            }
        }

        return null;
    }

    private function matchesWhenExpressionPosition(int $index): bool
    {
        $previous = $this->tokens[$index - 1] ?? null;
        $next = $this->tokens[$index + 1] ?? null;

        if ($previous === null) {
            return false;
        }

        $positionMatches = $previous->lexicalId === T_RETURN
            || in_array($previous->text, ['=', '(', '[', ',', '=>'], true);

        if (!$positionMatches) {
            return false;
        }

        if ($next?->text === '(') {
            return true;
        }

        for ($cursor = $index + 1; isset($this->tokens[$cursor]); $cursor++) {
            if ($this->tokens[$cursor]->text === '{') {
                return true;
            }

            if (in_array($this->tokens[$cursor]->text, [';', '}', ','], true)) {
                return false;
            }
        }

        return false;
    }

    private function matchesUnsupportedWhenPosition(int $index): bool
    {
        $previous = $this->tokens[$index - 1] ?? null;
        $next = $this->tokens[$index + 1] ?? null;

        if (
            $previous === null
            || $next?->text !== '('
            || $previous->lexicalId === T_FUNCTION
            || $previous->lexicalId === T_ELSE
            || in_array($previous->text, ['->', '?->', '::'], true)
        ) {
            return false;
        }

        $conditionClose = $this->resolveMatching($index + 1, '(', ')');

        return $conditionClose !== null
            && isset($this->tokens[$conditionClose + 1])
            && $this->tokens[$conditionClose + 1]->text === '{';
    }

    private function resolveMatching(int $openIndex, string $open, string $close): ?int
    {
        $depth = 0;

        for ($index = $openIndex; isset($this->tokens[$index]); $index++) {
            $text = $this->tokens[$index]->text;

            if ($text === $open) {
                $depth++;
            } elseif ($text === $close) {
                $depth--;

                if ($depth === 0) {
                    return $index;
                }
            }
        }

        return null;
    }

    /** @return array{int, int}|null */
    private function resolveAngleClose(int $openIndex): ?array
    {
        $depth = 0;

        for ($index = $openIndex; isset($this->tokens[$index]); $index++) {
            $text = $this->tokens[$index]->text;

            if ($text === '<') {
                $depth++;
                continue;
            }

            if ($text === '>') {
                $depth--;

                if ($depth === 0) {
                    return [$index, $this->tokens[$index]->end];
                }

                continue;
            }

            if ($text === '>>') {
                for ($byte = 1; $byte <= 2; $byte++) {
                    $depth--;

                    if ($depth === 0) {
                        return [$index, $this->tokens[$index]->start + $byte];
                    }
                }
            }

            if ($depth > 0 && in_array($text, [';', '{'], true)) {
                return null;
            }
        }

        return null;
    }

    /**
     * @return list<array{int, int, ?Span}>
     */
    private function splitRange(int $start, int $end, string $separator): array
    {
        $tokens = $this->resolveTokensInRange($start, $end);
        $parts = [];
        $partStart = $tokens[0]->start ?? $start;
        $angle = 0;
        $round = 0;

        foreach ($tokens as $token) {
            $angle += $token->text === '<' ? 1 : 0;
            $angle -= $token->text === '>' ? 1 : ($token->text === '>>' ? 2 : 0);
            $round += $token->text === '(' ? 1 : ($token->text === ')' ? -1 : 0);

            if ($token->text === $separator && $angle === 0 && $round === 0) {
                $parts[] = [$partStart, $token->start, $token->span];
                $partStart = $token->end;
            }
        }

        $parts[] = [$partStart, $tokens === [] ? $start : $end, null];

        return $parts;
    }

    /** @return list<Token> */
    private function resolveTokensInRange(int $start, int $end): array
    {
        $tokens = array_values(array_filter(
            $this->tokens,
            static fn (Token $token): bool => $token->start >= $start && $token->end <= $end,
        ));

        return $tokens;
    }

    private function recordGenericTypes(SourceType $type): void
    {
        foreach ($type->genericReferences as $generic) {
            $this->genericTypes[$generic->id->value] = $generic;
        }
    }

    private function containsGenericDeclarationOffset(int $offset): bool
    {
        foreach ($this->genericDeclarations as $declaration) {
            if ($offset >= $declaration->span->start->offset && $offset < $declaration->span->end->offset) {
                return true;
            }
        }

        return false;
    }

    private function containsGenericTypeOffset(int $offset): bool
    {
        foreach ($this->genericTypes as $genericType) {
            if ($offset > $genericType->span->start->offset && $offset < $genericType->span->end->offset) {
                return true;
            }
        }

        return false;
    }

    private function resolveContainingWhenExpression(int $offset): ?WhenExpression
    {
        $containing = null;

        foreach ($this->whenExpressions as $expression) {
            if ($offset <= $expression->span->start->offset || $offset >= $expression->span->end->offset) {
                continue;
            }

            if ($containing === null || $expression->span->start->offset > $containing->span->start->offset) {
                $containing = $expression;
            }
        }

        return $containing;
    }

    private function containsAttributeIndex(int $targetIndex): bool
    {
        $depth = 0;

        for ($index = 0; $index <= $targetIndex; $index++) {
            $token = $this->tokens[$index];

            if ($token->lexicalId === T_ATTRIBUTE) {
                $depth++;
                continue;
            }

            if ($depth > 0 && $token->text === '[') {
                $depth++;
            } elseif ($depth > 0 && $token->text === ']') {
                $depth--;
            }
        }

        return $depth > 0;
    }

    private function matchesTypeContextSuffix(?Token $token): bool
    {
        return $token !== null && (
            $token->lexicalId === T_VARIABLE
            || in_array($token->text, [')', ',', ';', '{', '=', '=>', '|', '&', ']', ':'], true)
            || strtolower($token->text) === 'throws'
            || in_array($token->lexicalId, [T_EXTENDS, T_IMPLEMENTS], true)
        );
    }

    private function matchesName(Token $token): bool
    {
        return in_array($token->lexicalId, [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE], true);
    }

    private function matchesTypeName(Token $token): bool
    {
        return $this->matchesName($token) || in_array($token->lexicalId, [T_ARRAY, T_CALLABLE, T_STATIC], true);
    }

    /** @return list<NormalizationEdit> */
    private function resolveNonOverlappingEdits(): array
    {
        $edits = $this->edits;
        usort($edits, static fn (NormalizationEdit $left, NormalizationEdit $right): int =>
            ($left->span->start->offset <=> $right->span->start->offset)
                ?: ($right->span->end->offset <=> $left->span->end->offset));
        $resolved = [];

        foreach ($edits as $edit) {
            $previousKey = array_key_last($resolved);
            $previous = $previousKey === null ? null : $resolved[$previousKey];

            if ($previous !== null && $edit->span->start->offset < $previous->span->end->offset) {
                if ($edit->span->end->offset <= $previous->span->end->offset) {
                    continue;
                }

                throw new \DomainException('Normalization edits overlap without an owning outer node.');
            }

            $resolved[] = $edit;
        }

        return $resolved;
    }

    private function mask(string $text): string
    {
        return SourceMask::erase($text);
    }

    private function placeholder(string $text): string
    {
        // An owning when is parsed separately; its comments belong to its
        // branches, not to the outer placeholder expression.
        $replacement = SourceMask::erase($text, preserveComments: false);

        return substr_replace($replacement, 'null', 0, 4);
    }

    private function addMalformed(string $message, Span $span, bool $unsupported = false): void
    {
        $this->addDiagnostic(
            $unsupported ? DiagnosticCode::UnsupportedExtensionSyntax : DiagnosticCode::InvalidExtensionSyntax,
            $message,
            $span,
        );
    }

    private function addDiagnostic(DiagnosticCode $code, string $message, Span $span): void
    {
        $this->diagnostics[] = new Diagnostic(
            $code,
            $message,
            new DiagnosticLabel($span, 'Extension syntax appears here.'),
        );
    }
}
