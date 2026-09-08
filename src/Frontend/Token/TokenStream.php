<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Frontend\Token;

use Atatusoft\Ppphp\Source\SourceFile;

/** @implements \IteratorAggregate<int, Token> */
final class TokenStream implements \Countable, \IteratorAggregate
{
    /** @var list<Token>|null */
    private ?array $materializedTokens = null;

    /** @var list<Token>|SourceFile */
    private readonly array|SourceFile $tokensSource;

    /** @param list<Token>|SourceFile $tokens */
    public function __construct(array|SourceFile $tokens)
    {
        $this->tokensSource = $tokens;
    }

    /** @var list<Token> */
    public array $tokens {
        get => is_array($this->tokensSource)
            ? $this->tokensSource
            : ($this->materializedTokens ??= (new Lexer())->tokenize($this->tokensSource)->tokens);
    }

    public function count(): int
    {
        return count($this->tokens);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->tokens;
    }

    /** @return list<Token> */
    public function resolveSignificantTokens(): array
    {
        return array_values(array_filter(
            $this->tokens,
            static fn (Token $token): bool => !$token->isTrivia,
        ));
    }
}
