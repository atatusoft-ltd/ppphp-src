<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Frontend;

use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Diagnostics\DiagnosticLabel;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Frontend\Enumerations\ParseMode;
use Atatusoft\Ppphp\Frontend\Extensions\ExtensionSyntaxParser;
use Atatusoft\Ppphp\Frontend\Interfaces\Parser;
use Atatusoft\Ppphp\Frontend\Token\Lexer;
use Atatusoft\Ppphp\Frontend\Token\Enumerations\TokenKind;
use Atatusoft\Ppphp\Source\SourceFile;
use PhpParser\Node;
use PhpParser\Node\Expr;

final readonly class PpphpParser implements Parser
{
    public function __construct(
        private PhpParserAdapter $phpParserAdapter = new PhpParserAdapter(),
        private Lexer $lexer = new Lexer(),
        private ExtensionSyntaxParser $extensionSyntaxParser = new ExtensionSyntaxParser(),
    ) {}

    public function parse(
        SourceFile $sourceFile,
        ParseMode $mode = ParseMode::PlusPlusPhp,
    ): ParseResult {
        if ($mode === ParseMode::Php) {
            return $this->phpParserAdapter->parse($sourceFile, $mode);
        }

        $tokens = $this->lexer->tokenize($sourceFile);
        if (!array_any($tokens->tokens, static fn ($token): bool => $token->kind === TokenKind::OpenTag)) {
            return new ParseResult(null, new DiagnosticBag([new Diagnostic(
                DiagnosticCode::MissingPhpOpeningTag,
                'This ++PHP file is missing a PHP opening tag.',
                new DiagnosticLabel($sourceFile->createSpan(0, 0), 'Add <?php here.'),
                help: 'Start this ++PHP file with <?php, followed by a newline.',
            )]));
        }
        $extensionResult = $this->extensionSyntaxParser->parse($sourceFile, $tokens);

        foreach ($extensionResult->diagnostics as $diagnostic) {
            if (in_array($diagnostic->code, [
                DiagnosticCode::InvalidExtensionSyntax,
                DiagnosticCode::UnsupportedExtensionSyntax,
                DiagnosticCode::ExtensionNormalizationFailed,
            ], true)) {
                return new ParseResult(null, $extensionResult->diagnostics);
            }
        }

        $normalizedSource = $extensionResult->normalizationPlan->normalize();
        $phpResult = $this->phpParserAdapter->parse(
            $sourceFile,
            $mode,
            $tokens,
            $extensionResult->index,
            $extensionResult->normalizationPlan,
            $normalizedSource,
        );

        if ($phpResult->parsedFile !== null) {
            $this->markWhenPlaceholders($phpResult->parsedFile);
        }
        $diagnostics = new DiagnosticBag();
        $diagnostics->addAll($extensionResult->diagnostics);
        $diagnostics->addAll($phpResult->diagnostics);

        return new ParseResult($phpResult->parsedFile, $diagnostics);
    }

    private function markWhenPlaceholders(ParsedFile $parsedFile): void
    {
        $expressions = [];

        foreach ($parsedFile->extensionSyntax->whenExpressions as $when) {
            if ($when->parentId === null) {
                $expressions[$when->span->start->offset] = $when;
            }
        }

        $visit = function (Node $node) use (&$visit, $expressions): void {
            if (
                $node instanceof Expr\ConstFetch
                && strtolower($node->name->toString()) === 'null'
                && isset($expressions[$node->getStartFilePos()])
            ) {
                $node->setAttribute('ppphpWhenExpressionId', $expressions[$node->getStartFilePos()]->id->value);
            }

            foreach ($node->getSubNodeNames() as $name) {
                $value = $node->{$name};

                if ($value instanceof Node) {
                    $visit($value);
                } elseif (is_array($value)) {
                    foreach ($value as $child) {
                        if ($child instanceof Node) {
                            $visit($child);
                        }
                    }
                }
            }
        };

        foreach ($parsedFile->statements as $statement) {
            $visit($statement);
        }
    }
}
