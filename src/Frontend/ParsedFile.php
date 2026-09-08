<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Frontend;

use Atatusoft\Ppphp\Frontend\Ast\ExtensionSyntaxIndex;
use Atatusoft\Ppphp\Frontend\Enumerations\ParseMode;
use Atatusoft\Ppphp\Frontend\Normalization\NormalizationPlan;
use Atatusoft\Ppphp\Frontend\Normalization\NormalizedSource;
use Atatusoft\Ppphp\Frontend\Normalization\SourceMap;
use Atatusoft\Ppphp\Frontend\Token\TokenStream;
use Atatusoft\Ppphp\Source\SourceFile;
use PhpParser\Node\Stmt;
use PhpParser\Lexer\Emulative;
use PhpParser\ErrorHandler\Collecting;
use PhpParser\PhpVersion;
use PhpParser\Token as PhpParserToken;

final class ParsedFile
{
    /** @var list<PhpParserToken>|null */
    private ?array $nativeTokens = null;

    /** @var list<PhpParserToken>|PhpVersion */
    private readonly array|PhpVersion $tokensSource;

    /**
     * @param list<Stmt> $statements
     * @param list<PhpParserToken>|PhpVersion $phpTokens
     */
    public function __construct(
        public readonly SourceFile $sourceFile,
        public readonly ParseMode $mode,
        public readonly TokenStream $tokens,
        public readonly ExtensionSyntaxIndex $extensionSyntax,
        public readonly NormalizationPlan $normalizationPlan,
        public readonly NormalizedSource $normalizedSource,
        public readonly SourceMap $sourceMap,
        public readonly array $statements,
        array|PhpVersion $phpTokens,
    ) {
        $this->tokensSource = $phpTokens;
    }

    /** @var list<PhpParserToken> */
    public array $phpTokens {
        get => is_array($this->tokensSource)
            ? $this->tokensSource
            : ($this->nativeTokens ??= array_values((new Emulative($this->tokensSource))->tokenize(
                $this->normalizedSource->contents,
                new Collecting(),
            )));
    }

    /** @param list<Stmt> $statements */
    public function withDeclarations(SourceFile $sourceFile, array $statements): self
    {
        return new self(
            $sourceFile,
            $this->mode,
            $this->tokens,
            $this->extensionSyntax,
            $this->normalizationPlan,
            $this->normalizedSource,
            $this->sourceMap,
            $statements,
            $this->tokensSource,
        );
    }
}
