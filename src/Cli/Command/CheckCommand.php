<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Cli\Command;

use Atatusoft\Ppphp\Cli\Command\AbstractClasses\ProjectCommand;
use Atatusoft\Ppphp\Cli\Enumerations\ExitCode;
use Atatusoft\Ppphp\Cli\Enumerations\OutputFormat;
use Atatusoft\Ppphp\Config\ProjectConfigLoader;
use Atatusoft\Ppphp\Diagnostics\ConsoleRenderer;
use Atatusoft\Ppphp\Diagnostics\JsonRenderer;
use Atatusoft\Ppphp\Project\Enumerations\SelectionMode;
use Atatusoft\Ppphp\Project\ProjectLoader;
use Atatusoft\Ppphp\Project\ProjectSelector;
use Atatusoft\Ppphp\Project\ProjectChecker;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class CheckCommand extends ProjectCommand
{
    public function __construct(
        ProjectConfigLoader $configLoader,
        ConsoleRenderer $consoleRenderer,
        JsonRenderer $jsonRenderer,
        private readonly ProjectLoader $projectLoader = new ProjectLoader(),
        private readonly ProjectSelector $selector = new ProjectSelector(),
        private readonly ProjectChecker $checker = new ProjectChecker(),
    ) {
        parent::__construct('check', $configLoader, $consoleRenderer, $jsonRenderer);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Check this project\'s PHP and ++PHP sources for syntax and type errors.')
            ->setHelp('Without a path, checks every file under the configured source roots. A file or directory limits reported diagnostics while the remaining valid project supplies context. Diagnostics produce a nonzero exit status.')
            ->addArgument('path', InputArgument::OPTIONAL, 'Optional file or directory within the configured source roots.');
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
            SelectionMode::Check,
        );

        if (!$selectionResult->isSuccessful || $selectionResult->selection === null) {
            $this->renderDiagnostics($selectionResult->diagnostics, $format, $input, $output);

            return ExitCode::InvalidProject->value;
        }

        $checkResult = $this->checker->check(
            $projectResult->project,
            $selectionResult->selection->analysisSources,
        );

        $this->renderDiagnostics($checkResult->diagnostics, $format, $input, $output);

        if (!$checkResult->isSuccessful) {
            return ExitCode::DiagnosticsReported->value;
        }

        if ($format === OutputFormat::Console) {
            $sources = $selectionResult->selection->analysisSources;
            $output->writeln(sprintf(
                'Checked %d Files: %d ++PHP, %d PHP.',
                count($sources),
                count($sources->filterByKind(FileKind::Ppphp)),
                count($sources->filterByKind(FileKind::Php)),
            ));
        }

        return ExitCode::Success->value;
    }
}
