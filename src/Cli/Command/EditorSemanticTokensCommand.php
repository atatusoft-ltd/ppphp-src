<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Cli\Command;

use Atatusoft\Ppphp\Cli\Command\AbstractClasses\ProjectCommand;
use Atatusoft\Ppphp\Cli\Enumerations\ExitCode;
use Atatusoft\Ppphp\Cli\Enumerations\OutputFormat;
use Atatusoft\Ppphp\Config\ProjectConfigLoader;
use Atatusoft\Ppphp\Diagnostics\ConsoleRenderer;
use Atatusoft\Ppphp\Diagnostics\JsonRenderer;
use Atatusoft\Ppphp\Editor\EditorSemanticToken;
use Atatusoft\Ppphp\Editor\EditorSemanticTokenResolver;
use Atatusoft\Ppphp\Editor\EditorSemanticTokensRequest;
use Atatusoft\Ppphp\Editor\EditorSemanticTokensRequestDecoder;
use Atatusoft\Ppphp\Frontend\Enumerations\ParseMode;
use Atatusoft\Ppphp\Frontend\PpphpParser;
use Atatusoft\Ppphp\Project\ProjectLoader;
use Atatusoft\Ppphp\Source\SourceFile;
use Atatusoft\Ppphp\Support\Path;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class EditorSemanticTokensCommand extends ProjectCommand
{
    public function __construct(
        ProjectConfigLoader $configLoader,
        ConsoleRenderer $consoleRenderer,
        JsonRenderer $jsonRenderer,
        private readonly ProjectLoader $projectLoader = new ProjectLoader(),
        private readonly PpphpParser $parser = new PpphpParser(),
        private readonly EditorSemanticTokensRequestDecoder $requestDecoder = new EditorSemanticTokensRequestDecoder(),
        private readonly EditorSemanticTokenResolver $tokenResolver = new EditorSemanticTokenResolver(),
    ) {
        parent::__construct('editor:semantic-tokens', $configLoader, $consoleRenderer, $jsonRenderer);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Classify one editor document into PHP and ++PHP semantic tokens.')
            ->setHelp('This bounded, versioned protocol is intended for ++PHP editor integrations.');
        $this->addProjectOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $this->resolveOutputFormat($input, $output);

        if ($format === null) {
            return ExitCode::InvalidProject->value;
        }

        $requestJson = stream_get_contents(STDIN, EditorSemanticTokensRequest::MAXIMUM_CONTENT_BYTES + 16_385);

        if ($requestJson === false) {
            return $this->renderError('request-read-failed', 'The editor semantic tokens request could not be read.', $format, $output);
        }

        try {
            $request = $this->requestDecoder->decode($requestJson);
        } catch (\InvalidArgumentException $exception) {
            return $this->renderError('invalid-request', $exception->getMessage(), $format, $output);
        }

        $configResult = $this->configLoader->load(
            $this->resolveWorkingDirectory($input),
            $this->resolveConfigurationPath($input),
            true,
        );

        if (!$configResult->isSuccessful || $configResult->configuration === null) {
            return $this->renderError('invalid-project', $this->describeProjectFailure($configResult->diagnostics), $format, $output);
        }

        $projectResult = $this->projectLoader->load($configResult->configuration);

        if (!$projectResult->isSuccessful || $projectResult->project === null) {
            return $this->renderError('invalid-project', $this->describeProjectFailure($projectResult->diagnostics), $format, $output);
        }

        $project = $projectResult->project;
        $documentPath = Path::resolveAbsolute($request->path, $project->configuration->projectRoot);
        $projectSource = $project->sources->find($documentPath);

        if ($projectSource === null) {
            return $this->renderError('document-not-owned', 'The editor document is outside the configured source roots.', $format, $output);
        }

        $sourceFile = new SourceFile(
            $projectSource->path,
            Path::resolveRelativeTo($projectSource->path, $project->configuration->projectRoot),
            $projectSource->kind,
            $request->contents,
        );
        $mode = $projectSource->kind->value === 'ppphp'
            ? ParseMode::PlusPlusPhp
            : ParseMode::Php;
        $parsedFile = $this->parser->parse($sourceFile, $mode)->parsedFile;
        $tokens = $parsedFile === null ? [] : $this->tokenResolver->resolve($parsedFile);

        return $this->renderTokens($tokens, $format, $output);
    }

    /** @param list<EditorSemanticToken> $tokens */
    private function renderTokens(array $tokens, OutputFormat $format, OutputInterface $output): int
    {
        if ($format === OutputFormat::Console) {
            $output->writeln(sprintf('%d semantic tokens.', count($tokens)));

            return ExitCode::Success->value;
        }

        $output->writeln(json_encode([
            'version' => EditorSemanticTokensRequest::VERSION,
            'tokens' => array_map(static fn (EditorSemanticToken $token): array => [
                'type' => $token->type,
                'modifiers' => $token->modifiers,
                'range' => [
                    'start' => ['offset' => $token->range->start->offset],
                    'end' => ['offset' => $token->range->end->offset],
                ],
            ], $tokens),
            'error' => null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return ExitCode::Success->value;
    }

    private function renderError(
        string $code,
        string $message,
        OutputFormat $format,
        OutputInterface $output,
    ): int {
        if ($format === OutputFormat::Console) {
            $output->writeln(sprintf('<error>%s</error>', $message));
        } else {
            $output->writeln(json_encode([
                'version' => EditorSemanticTokensRequest::VERSION,
                'tokens' => [],
                'error' => [
                    'code' => $code,
                    'message' => $message,
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }

        return ExitCode::InvalidProject->value;
    }
}
