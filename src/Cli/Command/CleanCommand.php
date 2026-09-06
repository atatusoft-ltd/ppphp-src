<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Cli\Command;

use Atatusoft\Ppphp\Cli\Command\AbstractClasses\ProjectCommand;
use Atatusoft\Ppphp\Cli\Enumerations\ExitCode;
use Atatusoft\Ppphp\Cli\Enumerations\OutputFormat;
use Atatusoft\Ppphp\Config\ProjectConfigLoader;
use Atatusoft\Ppphp\Diagnostics\ConsoleRenderer;
use Atatusoft\Ppphp\Diagnostics\JsonRenderer;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Project\ProjectCleaner;
use Atatusoft\Ppphp\Support\Path;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class CleanCommand extends ProjectCommand
{
    public function __construct(
        ProjectConfigLoader $configLoader,
        ConsoleRenderer $consoleRenderer,
        JsonRenderer $jsonRenderer,
        private readonly ProjectCleaner $projectCleaner,
    ) {
        parent::__construct('clean', $configLoader, $consoleRenderer, $jsonRenderer);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Remove generated output and cache directories.')
            ->setHelp('Removes only validated output and cache paths beneath the project root. Use --dry-run to report paths without changing them. Diagnostics use the selected console or JSON contract.')
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Report paths without removing them.',
            );
        $this->addProjectOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $this->resolveOutputFormat($input, $output);

        if ($format === null) {
            return ExitCode::InvalidProject->value;
        }

        $loadResult = $this->configLoader->load(
            $this->resolveWorkingDirectory($input),
            $this->resolveConfigurationPath($input),
        );

        if (!$loadResult->isSuccessful || $loadResult->configuration === null) {
            $this->renderDiagnostics($loadResult->diagnostics, $format, $input, $output);

            return ExitCode::InvalidProject->value;
        }

        $dryRun = $input->getOption('dry-run') === true;
        $cleanupResult = $this->projectCleaner->clean($loadResult->configuration, $dryRun);

        if (!$cleanupResult->isSuccessful) {
            $this->renderDiagnostics($cleanupResult->diagnostics, $format, $input, $output);

            foreach ($cleanupResult->diagnostics->errors as $diagnostic) {
                if (in_array($diagnostic->code, [
                    DiagnosticCode::BuildCouldNotBeStaged,
                    DiagnosticCode::BuildIsAlreadyInProgress,
                ], true)) {
                    return ExitCode::OutputValidationFailed->value;
                }
            }

            return ExitCode::InvalidProject->value;
        }

        if ($format === OutputFormat::Json) {
            $this->renderDiagnostics($cleanupResult->diagnostics, $format, $input, $output);
        } elseif ($cleanupResult->paths === []) {
            $output->writeln('Nothing to clean.');
        } else {
            foreach ($cleanupResult->paths as $path) {
                $action = $dryRun ? 'Would remove' : 'Removed';
                $output->writeln(sprintf(
                    '%s %s.',
                    $action,
                    Path::resolveRelativeTo($path, $loadResult->configuration->projectRoot),
                ));
            }
        }

        return ExitCode::Success->value;
    }
}
