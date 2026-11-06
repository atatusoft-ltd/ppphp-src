<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Cli\Command;

use Atatusoft\Ppphp\Cli\Command\AbstractClasses\ProjectCommand;
use Atatusoft\Ppphp\Cli\Enumerations\ExitCode;
use Atatusoft\Ppphp\Cli\Enumerations\OutputFormat;
use Atatusoft\Ppphp\Compiler\Compiler;
use Atatusoft\Ppphp\Compiler\Enumerations\CompilationFailureKind;
use Atatusoft\Ppphp\Compiler\Output\Enumerations\OutputOperation;
use Atatusoft\Ppphp\Config\ProjectConfigLoader;
use Atatusoft\Ppphp\Diagnostics\ConsoleRenderer;
use Atatusoft\Ppphp\Diagnostics\JsonRenderer;
use Atatusoft\Ppphp\Project\Enumerations\SelectionMode;
use Atatusoft\Ppphp\Project\ProjectLoader;
use Atatusoft\Ppphp\Project\ProjectSelector;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Support\Path;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class BuildCommand extends ProjectCommand
{
    public function __construct(
        ProjectConfigLoader $configLoader,
        ConsoleRenderer $consoleRenderer,
        JsonRenderer $jsonRenderer,
        private readonly ProjectLoader $projectLoader = new ProjectLoader(),
        private readonly ProjectSelector $selector = new ProjectSelector(),
        private readonly Compiler $compiler = new Compiler(),
    ) {
        parent::__construct('build', $configLoader, $consoleRenderer, $jsonRenderer);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Check selected project sources and commit an atomic mixed PHP output tree.')
            ->setHelp('Without a path, validates and builds the complete project. A file or directory performs a manifest-aware partial build. Console diagnostics use standard error; committed artifact summaries use standard output.')
            ->addArgument('path', InputArgument::OPTIONAL, sprintf('Optional %s or %s file or source subtree.', FileKind::PHP_SUFFIX, FileKind::PPPHP_SUFFIX));
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
            SelectionMode::Build,
        );

        if (!$selectionResult->isSuccessful || $selectionResult->selection === null) {
            $this->renderDiagnostics($selectionResult->diagnostics, $format, $input, $output);

            return ExitCode::InvalidProject->value;
        }

        $result = $this->compiler->compile($projectResult->project, $selectionResult->selection);

        if (!$result->isSuccessful) {
            $this->renderDiagnostics($result->diagnostics, $format, $input, $output);

            return match ($result->failureKind) {
                CompilationFailureKind::Source => ExitCode::DiagnosticsReported->value,
                CompilationFailureKind::Output => ExitCode::OutputValidationFailed->value,
                null => throw new \LogicException('An unsuccessful compilation requires a failure kind.'),
            };
        }

        if ($format === OutputFormat::Json) {
            $this->renderDiagnostics($result->diagnostics, $format, $input, $output);

            return ExitCode::Success->value;
        }

        if (!$result->diagnostics->isEmpty) {
            $this->renderDiagnostics($result->diagnostics, $format, $input, $output);
            $output->writeln('');
        }

        if ($result->upToDate) {
            $count = count($result->artifacts);
            $output->writeln(sprintf('Build Is Up To Date (%d %s Verified).', $count, $count === 1 ? 'File' : 'Files'));

            return ExitCode::Success->value;
        }

        $compiled = 0;
        $copied = 0;

        foreach ($result->artifacts as $artifact) {
            $compiled += $artifact->operation === OutputOperation::Compile ? 1 : 0;
            $copied += $artifact->operation === OutputOperation::Copy ? 1 : 0;
            $output->writeln(sprintf(
                '%s %s -> %s',
                $artifact->operation === OutputOperation::Compile ? 'Compiled' : 'Copied',
                $artifact->sourceFile->displayPath,
                Path::resolveRelativeTo($artifact->outputPath, $projectResult->project->configuration->projectRoot),
            ));
        }

        if ($result->artifacts !== []) {
            $output->writeln('');
        }

        $output->writeln(sprintf('Compiled %d ++PHP %s.', $compiled, $compiled === 1 ? 'File' : 'Files'));
        $output->writeln(sprintf('Copied %d PHP %s.', $copied, $copied === 1 ? 'File' : 'Files'));

        if ($result->staleRemovalCount > 0) {
            $output->writeln(sprintf('Removed %d Stale %s.', $result->staleRemovalCount, $result->staleRemovalCount === 1 ? 'File' : 'Files'));
        }

        $count = count($result->artifacts);
        $output->writeln(sprintf('Built %d %s Atomically.', $count, $count === 1 ? 'File' : 'Files'));

        return ExitCode::Success->value;
    }
}
