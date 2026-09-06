<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Cli\Command;

use Atatusoft\Ppphp\Cli\Command\AbstractClasses\ProjectCommand;
use Atatusoft\Ppphp\Cli\Enumerations\ExitCode;
use Atatusoft\Ppphp\Cli\Enumerations\OutputFormat;
use Atatusoft\Ppphp\Config\ProjectConfigLoader;
use Atatusoft\Ppphp\Diagnostics\ConsoleRenderer;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Diagnostics\JsonRenderer;
use Atatusoft\Ppphp\Editor\EditorDefinition;
use Atatusoft\Ppphp\Editor\EditorDefinitionRequest;
use Atatusoft\Ppphp\Editor\EditorDefinitionRequestDecoder;
use Atatusoft\Ppphp\Editor\EditorDefinitionResolver;
use Atatusoft\Ppphp\Project\ProjectLoader;
use Atatusoft\Ppphp\Project\ProjectParseResult;
use Atatusoft\Ppphp\Project\ProjectSyntaxChecker;
use Atatusoft\Ppphp\Semantic\SemanticAnalyzer;
use Atatusoft\Ppphp\Source\SourceFile;
use Atatusoft\Ppphp\Support\Path;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class EditorDefinitionCommand extends ProjectCommand
{
    public function __construct(
        ProjectConfigLoader $configLoader,
        ConsoleRenderer $consoleRenderer,
        JsonRenderer $jsonRenderer,
        private readonly ProjectLoader $projectLoader = new ProjectLoader(),
        private readonly ProjectSyntaxChecker $syntaxChecker = new ProjectSyntaxChecker(),
        private readonly SemanticAnalyzer $semanticAnalyzer = new SemanticAnalyzer(),
        private readonly EditorDefinitionRequestDecoder $requestDecoder = new EditorDefinitionRequestDecoder(),
        private readonly EditorDefinitionResolver $definitionResolver = new EditorDefinitionResolver(),
    ) {
        parent::__construct('editor:definition', $configLoader, $consoleRenderer, $jsonRenderer);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Resolve one editor definition request from standard input.')
            ->setHelp('This bounded, versioned protocol is intended for ++PHP editor integrations.');
        $this->addProjectOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $this->resolveOutputFormat($input, $output);

        if ($format === null) {
            return ExitCode::InvalidProject->value;
        }

        $requestJson = stream_get_contents(STDIN, EditorDefinitionRequest::MAXIMUM_TRANSPORT_BYTES + 1);

        if ($requestJson === false) {
            return $this->renderError('request-read-failed', 'The editor definition request could not be read.', $format, $output);
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
            return $this->renderError('document-not-owned', 'The editor document is not a project-owned source file.', $format, $output);
        }

        $sourceFile = new SourceFile(
            $projectSource->path,
            Path::resolveRelativeTo($projectSource->path, $project->configuration->projectRoot),
            $projectSource->kind,
            $request->contents,
        );
        $parseResult = $this->syntaxChecker->check($project, $project->sources, $sourceFile);
        $parsedFile = $parseResult->findParsedFile($documentPath);

        if ($parsedFile === null) {
            return $this->renderDefinition(null, $format, $output);
        }

        $editorParseResult = $parseResult->isSuccessful
            ? $parseResult
            : new ProjectParseResult(
                $parseResult->parsedFiles,
                $parseResult->sourceFiles,
                new DiagnosticBag(),
            );

        try {
            $analysis = $this->semanticAnalyzer->analyze($editorParseResult);
            $definition = $this->definitionResolver->resolve($parsedFile, $analysis, $request->offset);
        } catch (\Throwable) {
            return $this->renderError('internal-error', 'The compiler encountered an unexpected error while resolving the definition. Report this compiler error with a reproducing example.', $format, $output);
        }

        return $this->renderDefinition($definition, $format, $output);
    }

    private function renderDefinition(
        ?EditorDefinition $definition,
        OutputFormat $format,
        OutputInterface $output,
    ): int {
        if ($format === OutputFormat::Console) {
            $output->writeln($definition === null
                ? 'No definition found.'
                : sprintf(
                    '%s:%d:%d',
                    $definition->selectionSpan->sourceFile->displayPath,
                    $definition->selectionSpan->start->line,
                    $definition->selectionSpan->start->column,
                ));

            return ExitCode::Success->value;
        }

        $output->writeln(json_encode([
            'version' => EditorDefinitionRequest::VERSION,
            'definition' => $definition === null ? null : [
                'symbolId' => $definition->symbolId,
                'kind' => $definition->kind,
                'location' => [
                    'file' => $definition->selectionSpan->sourceFile->displayPath,
                    'range' => $this->renderSpan($definition->declarationSpan),
                    'selectionRange' => $this->renderSpan($definition->selectionSpan),
                ],
            ],
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
                'version' => EditorDefinitionRequest::VERSION,
                'definition' => null,
                'error' => [
                    'code' => $code,
                    'message' => $message,
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }

        return ExitCode::InvalidProject->value;
    }

    /** @return array{start: array{offset: int, line: int, column: int}, end: array{offset: int, line: int, column: int}} */
    private function renderSpan(\Atatusoft\Ppphp\Source\Span $span): array
    {
        return [
            'start' => [
                'offset' => $span->start->offset,
                'line' => $span->start->line,
                'column' => $span->start->column,
            ],
            'end' => [
                'offset' => $span->end->offset,
                'line' => $span->end->line,
                'column' => $span->end->column,
            ],
        ];
    }
}
