<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Frontend;

use Atatusoft\Ppphp\Config\PhpTarget;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Frontend\Ast\ExtensionSyntaxIndex;
use Atatusoft\Ppphp\Frontend\Enumerations\ParseMode;
use Atatusoft\Ppphp\Frontend\Normalization\NormalizationPlan;
use Atatusoft\Ppphp\Frontend\Normalization\NormalizedSource;
use Atatusoft\Ppphp\Frontend\Token\TokenStream;
use Atatusoft\Ppphp\Source\SourceFile;
use PhpParser\ErrorHandler\Collecting;
use PhpParser\Node\Stmt;
use PhpParser\Parser as NativeParser;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;

final readonly class PhpParserAdapter
{
    private NativeParser $parser;

    private PhpVersion $phpVersion;

    public function __construct(
        string $targetPhpVersion = PhpTarget::DEFAULT,
        private PhpParserDiagnosticMapper $diagnosticMapper = new PhpParserDiagnosticMapper(),
    ) {
        if (!in_array($targetPhpVersion, PhpTarget::SUPPORTED, true)) {
            throw new \InvalidArgumentException('The ordinary PHP frontend does not support the selected project target.');
        }

        $this->phpVersion = PhpVersion::fromString($targetPhpVersion);
        $this->parser = (new ParserFactory())->createForVersion($this->phpVersion);
    }

    public function parse(
        SourceFile $sourceFile,
        ParseMode $mode,
        ?TokenStream $tokens = null,
        ?ExtensionSyntaxIndex $extensionSyntax = null,
        ?NormalizationPlan $normalizationPlan = null,
        ?NormalizedSource $normalizedSource = null,
    ): ParseResult {
        $tokens ??= new TokenStream($sourceFile);
        $extensionSyntax ??= ExtensionSyntaxIndex::createEmpty();
        $normalizationPlan ??= new NormalizationPlan($sourceFile);
        $normalizedSource ??= $normalizationPlan->normalize();
        $errorHandler = new Collecting();
        $diagnostics = new DiagnosticBag();

        try {
            $statements = $this->parser->parse($normalizedSource->contents, $errorHandler);
        } catch (\PhpParser\Error $error) {
            $statements = null;
            $errorHandler->handleError($error);
        }

        foreach ($errorHandler->getErrors() as $index => $error) {
            $missingSemicolon = $index === 0 && PhpSyntaxMessage::checkMissingSemicolon($error, $normalizedSource->contents, $this->parser);
            $diagnostics->add($this->diagnosticMapper->map($error, $sourceFile, $normalizedSource->sourceMap, $missingSemicolon));
        }

        $parsedFile = $statements === null ? null : $this->assemble(
            $sourceFile, $mode, array_values($statements), $tokens, $extensionSyntax, $normalizationPlan, $normalizedSource,
        );

        return new ParseResult($parsedFile, $diagnostics);
    }

    /** @param list<Stmt> $statements */
    public function restorePhp(SourceFile $sourceFile, array $statements): ParsedFile
    {
        $normalizationPlan = new NormalizationPlan($sourceFile);

        return $this->assemble(
            $sourceFile, ParseMode::Php, $statements, new TokenStream($sourceFile),
            ExtensionSyntaxIndex::createEmpty(), $normalizationPlan, $normalizationPlan->normalize(),
        );
    }

    /** @param list<Stmt> $statements */
    private function assemble(
        SourceFile $sourceFile,
        ParseMode $mode,
        array $statements,
        TokenStream $tokens,
        ExtensionSyntaxIndex $extensionSyntax,
        NormalizationPlan $normalizationPlan,
        NormalizedSource $normalizedSource,
    ): ParsedFile {
        return new ParsedFile(
            $sourceFile,
            $mode,
            $tokens,
            $extensionSyntax,
            $normalizationPlan,
            $normalizedSource,
            $normalizedSource->sourceMap,
            $statements,
            $this->phpVersion,
        );
    }
}
