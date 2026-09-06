<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Cli\Command;

use Atatusoft\Ppphp\Cli\Command\AbstractClasses\ProjectCommand;
use Atatusoft\Ppphp\Cli\Enumerations\ExitCode;
use Atatusoft\Ppphp\Cli\Enumerations\OutputFormat;
use Atatusoft\Ppphp\Config\ProjectConfigLoader;
use Atatusoft\Ppphp\Diagnostics\ConsoleRenderer;
use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Diagnostics\JsonRenderer;
use Atatusoft\Ppphp\Frontend\AstDumper;
use Atatusoft\Ppphp\Frontend\Enumerations\ParseMode;
use Atatusoft\Ppphp\Frontend\Interfaces\Parser;
use Atatusoft\Ppphp\Frontend\PpphpParser;
use Atatusoft\Ppphp\Project\Enumerations\SelectionMode;
use Atatusoft\Ppphp\Project\ProjectLoader;
use Atatusoft\Ppphp\Project\ProjectSelector;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Support\Path;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class DumpAstCommand extends ProjectCommand
{
    public function __construct(
        ProjectConfigLoader $configLoader,
        ConsoleRenderer $consoleRenderer,
        JsonRenderer $jsonRenderer,
        private readonly ProjectLoader $projectLoader = new ProjectLoader(),
        private readonly ProjectSelector $selector = new ProjectSelector(),
        private readonly Parser $parser = new PpphpParser(),
        private readonly AstDumper $astDumper = new AstDumper(),
    ) {
        parent::__construct('dump:ast', $configLoader, $consoleRenderer, $jsonRenderer);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Display the syntax tree for one PHP or ++PHP source file.')
            ->setHelp('Writes one selected source tree to standard output. Parse and project diagnostics use standard error in console mode; JSON AST output remains one machine-readable standard-output document.')
            ->addArgument('path', InputArgument::OPTIONAL, sprintf('Explicit %s or %s source file path.', FileKind::PHP_SUFFIX, FileKind::PPPHP_SUFFIX));
        $this->addProjectOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $this->resolveOutputFormat($input, $output);

        if ($format === null) {
            return ExitCode::InvalidProject->value;
        }

        $configResult = $this->configLoader->load(
            $this->resolveWorkingDirectory($input),
            $this->resolveConfigurationPath($input),
            true,
        );

        if (!$configResult->isSuccessful || $configResult->configuration === null) {
            $this->renderDiagnostics($configResult->diagnostics, $format, $input, $output);

            return ExitCode::InvalidProject->value;
        }

        $projectResult = $this->projectLoader->load($configResult->configuration);

        if (!$projectResult->isSuccessful || $projectResult->project === null) {
            $this->renderDiagnostics($projectResult->diagnostics, $format, $input, $output);

            return ExitCode::InvalidProject->value;
        }

        $path = $input->getArgument('path');
        $selectionResult = $this->selector->select(
            $projectResult->project,
            is_string($path) ? $path : null,
            SelectionMode::DumpAst,
        );

        if (!$selectionResult->isSuccessful || $selectionResult->selection === null) {
            $this->renderDiagnostics($selectionResult->diagnostics, $format, $input, $output);

            return ExitCode::InvalidProject->value;
        }

        $source = $selectionResult->selection->analysisSources->files[0] ?? null;

        if ($source === null) {
            throw new \LogicException('A successful dump:ast selection did not contain a source file.');
        }

        try {
            $sourceFile = $projectResult->project->sourceManager->load($source->path, $source->kind);
        } catch (\RuntimeException $exception) {
            $diagnostics = new DiagnosticBag();
            $diagnostics->add(new Diagnostic(
                DiagnosticCode::SourceFileNotReadable,
                sprintf('The source file "%s" could not be read.', Path::resolveRelativeTo($source->path, $projectResult->project->configuration->projectRoot)),
                debug: ['message' => $exception->getMessage()],
            ));
            $this->renderDiagnostics($diagnostics, $format, $input, $output);

            return ExitCode::InvalidProject->value;
        }

        $parseResult = $this->parser->parse(
            $sourceFile,
            $source->kind === FileKind::Ppphp ? ParseMode::PlusPlusPhp : ParseMode::Php,
        );

        if ($parseResult->parsedFile === null) {
            $this->renderDiagnostics($parseResult->diagnostics, $format, $input, $output);

            return ExitCode::DiagnosticsReported->value;
        }

        $dump = $this->astDumper->dump($parseResult->parsedFile);

        if ($format === OutputFormat::Json) {
            $output->writeln(json_encode([
                'version' => 2,
                'file' => $sourceFile->displayPath,
                'ast' => $dump,
                'diagnostics' => array_map(
                    static fn (Diagnostic $diagnostic): array => [
                        'code' => $diagnostic->code->value,
                        'title' => $diagnostic->title,
                        'start' => $diagnostic->primary?->span->start->offset,
                        'end' => $diagnostic->primary?->span->end->offset,
                    ],
                    iterator_to_array($parseResult->diagnostics),
                ),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
        } else {
            if ($parseResult->hasErrors) {
                $this->renderDiagnostics($parseResult->diagnostics, $format, $input, $output);
            }

            $output->writeln($dump);
        }

        return $parseResult->hasErrors
            ? ExitCode::DiagnosticsReported->value
            : ExitCode::Success->value;
    }
}
