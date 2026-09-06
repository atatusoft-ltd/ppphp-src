<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Cli\Command\AbstractClasses;

use Atatusoft\Ppphp\Cli\Enumerations\OutputFormat;
use Atatusoft\Ppphp\Cli\DiagnosticOutputWriter;
use Atatusoft\Ppphp\Config\ProjectConfigLoader;
use Atatusoft\Ppphp\Diagnostics\ConsoleRenderer;
use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Diagnostics\DiagnosticProcessor;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Diagnostics\JsonRenderer;
use Atatusoft\Ppphp\Support\Path;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

abstract class ProjectCommand extends Command
{
    private readonly DiagnosticOutputWriter $diagnosticOutputWriter;

    public function __construct(
        string $name,
        protected readonly ProjectConfigLoader $configLoader,
        private readonly ConsoleRenderer $consoleRenderer,
        private readonly JsonRenderer $jsonRenderer,
    ) {
        parent::__construct($name);
        $this->diagnosticOutputWriter = new DiagnosticOutputWriter($consoleRenderer, $jsonRenderer);
    }

    protected function addProjectOptions(bool $withConfiguration = true): void
    {
        $this->addOption(
            'working-directory',
            null,
            InputOption::VALUE_REQUIRED,
            'Project root. Defaults to the current working directory.',
        );

        if ($withConfiguration) {
            $this->addOption(
                'config',
                null,
                InputOption::VALUE_REQUIRED,
                'Configuration path relative to the project root.',
            );
        }

        $this
            ->addOption(
                'format',
                null,
                InputOption::VALUE_REQUIRED,
                'Diagnostic output format: console or json.',
                OutputFormat::Console->value,
            )
            ->addOption(
                'debug',
                null,
                InputOption::VALUE_NONE,
                'Include internal failure details.',
            );
    }

    protected function resolveWorkingDirectory(InputInterface $input): string
    {
        $currentDirectory = getcwd();

        if ($currentDirectory === false) {
            throw new \RuntimeException('Unable to determine the current working directory.');
        }

        $value = $input->getOption('working-directory');

        if (!is_string($value) || $value === '') {
            return Path::normalize($currentDirectory);
        }

        return Path::resolveAbsolute($value, Path::normalize($currentDirectory));
    }

    protected function resolveConfigurationPath(InputInterface $input): ?string
    {
        $value = $input->getOption('config');

        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function resolveOutputFormat(InputInterface $input, OutputInterface $output): ?OutputFormat
    {
        $value = $input->getOption('format');
        $format = is_string($value) ? OutputFormat::tryFrom($value) : null;

        if ($format !== null) {
            return $format;
        }

        $diagnostics = new DiagnosticBag();
        $diagnostics->add(new Diagnostic(
            DiagnosticCode::InvalidOutputFormat,
            'The diagnostic output format must be "console" or "json".',
            help: 'Pass --format=console for human output or --format=json for one machine-readable document.',
        ));
        $this->diagnosticOutputWriter->write($diagnostics, OutputFormat::Console, $input, $output);

        return null;
    }

    protected function renderDiagnostics(
        DiagnosticBag $diagnostics,
        OutputFormat $format,
        InputInterface $input,
        OutputInterface $output,
    ): void {
        $this->diagnosticOutputWriter->write($diagnostics, $format, $input, $output);
    }

    /** Preserve the first actionable project failure in editor protocol error messages. */
    protected function describeProjectFailure(DiagnosticBag $diagnostics): string
    {
        $diagnostic = (new DiagnosticProcessor())->process($diagnostics)->errors[0] ?? null;
        if ($diagnostic === null) {
            throw new \LogicException('An unsuccessful project load must provide an error diagnostic.');
        }

        $location = $diagnostic->primary?->span;
        $where = $location === null ? '' : sprintf(
            ' (%s:%d:%d)',
            $location->start->sourceFile->displayPath,
            $location->start->line,
            $location->start->column,
        );

        return sprintf('%s: %s%s %s', $diagnostic->code->value, $diagnostic->message, $where, $diagnostic->help);
    }
}
