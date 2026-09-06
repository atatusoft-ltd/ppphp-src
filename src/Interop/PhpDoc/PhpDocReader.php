<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Interop\PhpDoc;

use Atatusoft\Ppphp\Source\SourceFile;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\ParserException;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use PhpParser\Comment\Doc;

final class PhpDocReader
{
    private readonly Lexer $lexer;

    private readonly PhpDocParser $parser;

    public function __construct()
    {
        $config = new ParserConfig(['indexes' => true, 'lines' => true]);
        $constantExpressions = new ConstExprParser($config);
        $this->lexer = new Lexer($config);
        $this->parser = new PhpDocParser(
            $config,
            new TypeParser($config, $constantExpressions),
            $constantExpressions,
        );
    }

    /** Includes invalid assertion values so authored checks are never discarded. */
    public function hasVariableAssertions(?Doc $document): bool
    {
        if ($document === null) {
            return false;
        }
        $node = $this->parse($document);
        if ($node === null) {
            return true;
        }
        foreach (['@var', '@phpstan-var', '@psalm-var'] as $name) {
            if ($node->getTagsByName($name) !== []) {
                return true;
            }
        }

        return false;
    }

    public function readMetadata(?Doc $document): PhpDocMetadata
    {
        $node = $this->parse($document);

        if ($node === null) {
            return new PhpDocMetadata();
        }

        $templates = array_values(array_map(
            static fn ($tag): array => [
                'name' => $tag->name,
                'bound' => $tag->bound === null ? null : (string) $tag->bound,
            ],
            $node->getTemplateTagValues(),
        ));
        $parameters = [];

        foreach ($node->getParamTagValues() as $tag) {
            $parameters[$tag->parameterName] = (string) $tag->type;
        }

        $variables = [];

        foreach ($node->getVarTagValues() as $tag) {
            $variables[$tag->variableName] = (string) $tag->type;
        }

        return new PhpDocMetadata(
            $templates,
            $parameters,
            array_values(array_map(static fn ($tag): string => (string) $tag->type, $node->getReturnTagValues())),
            $variables,
            array_values(array_map(static fn ($tag): string => (string) $tag->type, $node->getExtendsTagValues())),
            array_values(array_map(static fn ($tag): string => (string) $tag->type, $node->getImplementsTagValues())),
            array_values(array_map(static fn ($tag): string => (string) $tag->type, $node->getUsesTagValues())),
            array_values(array_map(static fn ($tag): string => (string) $tag->type, $node->getThrowsTagValues())),
        );
    }

    /** @return list<PhpDocThrowsTag> */
    public function readThrows(?Doc $document, SourceFile $sourceFile): array
    {
        if ($document === null) {
            return [];
        }

        $text = $document->getText();
        $documentStart = $document->getStartFilePos();
        $documentSpan = $sourceFile->createSpan($documentStart, $document->getEndFilePos() + 1);
        $tags = [];
        $cursor = 0;

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $tagOffset = strpos($line, '@throws');

            if ($tagOffset !== false) {
                $afterTag = substr($line, $tagOffset + strlen('@throws'));
                $leading = strlen($afterTag) - strlen(ltrim($afterTag));
                $content = ltrim($afterTag);
                $parts = preg_split('/\s+/', $content, 2) ?: [];
                $type = $parts[0] ?? '';

                if ($type !== '') {
                    $typeStart = $documentStart + $cursor + $tagOffset + strlen('@throws') + $leading;
                    $tags[] = new PhpDocThrowsTag(
                        $type,
                        $sourceFile->createSpan($typeStart, $typeStart + strlen($type)),
                        $documentSpan,
                        $parts[1] ?? '',
                    );
                }
            }

            $cursor += strlen($line);
            $cursor += str_starts_with(substr($text, $cursor), "\r\n") ? 2 : 1;
        }

        return $tags;
    }

    private function parse(?Doc $document): ?PhpDocNode
    {
        if ($document === null) {
            return null;
        }

        try {
            return $this->parser->parse(new TokenIterator($this->lexer->tokenize($document->getText())));
        } catch (ParserException) {
            return null;
        }
    }
}
