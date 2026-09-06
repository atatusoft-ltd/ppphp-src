<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Cli\Command;

use Atatusoft\Ppphp\Cli\Command\AbstractClasses\ProjectCommand;
use Atatusoft\Ppphp\Cli\Enumerations\ExitCode;
use Atatusoft\Ppphp\Cli\Enumerations\OutputFormat;
use Atatusoft\Ppphp\Config\ProjectConfigLoader;
use Atatusoft\Ppphp\Diagnostics\ConsoleRenderer;
use Atatusoft\Ppphp\Diagnostics\JsonRenderer;
use Atatusoft\Ppphp\Interop\Composer\ComposerConfigurationWriter;
use Atatusoft\Ppphp\Interop\Composer\ComposerRuntimeConfigurator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ComposerConfigureCommand extends ProjectCommand
{
    public function __construct(
        ProjectConfigLoader $configLoader,
        ConsoleRenderer $consoleRenderer,
        JsonRenderer $jsonRenderer,
        private readonly ComposerRuntimeConfigurator $configurator = new ComposerRuntimeConfigurator(),
        private readonly ComposerConfigurationWriter $writer = new ComposerConfigurationWriter(),
    ) {
        parent::__construct('composer:configure', $configLoader, $consoleRenderer, $jsonRenderer);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Project root Composer autoload mappings to generated PHP output.')
            ->setHelp('Validates this project\'s Composer mappings and updates composer.json to load generated PHP. Use --dry-run to inspect the result without writing the file.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show whether composer.json would change without writing it.');
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

        $projection = $this->configurator->project($configResult->configuration);

        if (!$projection->isSuccessful) {
            $this->renderDiagnostics($projection->diagnostics, $format, $input, $output);

            return ExitCode::InvalidProject->value;
        }

        $diagnostics = $projection->diagnostics;

        if ($input->getOption('dry-run') !== true) {
            $diagnostics->addAll($this->writer->write($projection, $configResult->configuration->projectRoot));
        }

        if ($diagnostics->hasErrors) {
            $this->renderDiagnostics($diagnostics, $format, $input, $output);

            return ExitCode::OutputValidationFailed->value;
        }

        if ($format === OutputFormat::Json) {
            $this->renderDiagnostics($diagnostics, $format, $input, $output);

            return ExitCode::Success->value;
        }

        if (!$projection->isChanged) {
            $output->writeln('Composer runtime autoload mappings already target the ++PHP build output.');
        } elseif ($input->getOption('dry-run') === true) {
            $output->writeln('Would update composer.json to target the ++PHP build output.');
        } else {
            $output->writeln('Updated composer.json to target the ++PHP build output.');
        }

        $output->writeln('Run these Composer commands next:');
        $output->writeln('  composer update --lock');
        $output->writeln('  composer dump-autoload');

        return ExitCode::Success->value;
    }
}
