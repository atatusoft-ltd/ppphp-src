<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Cli\Command;

use Atatusoft\Ppphp\Analysis\Capability\AnalysisCapabilityCatalog;
use Atatusoft\Ppphp\Cli\Command\AbstractClasses\ProjectCommand;
use Atatusoft\Ppphp\Cli\Enumerations\ExitCode;
use Atatusoft\Ppphp\Config\ProjectConfigLoader;
use Atatusoft\Ppphp\Diagnostics\ConsoleRenderer;
use Atatusoft\Ppphp\Diagnostics\JsonRenderer;
use Atatusoft\Ppphp\Editor\EditorDiagnosticsAnalyzer;
use Atatusoft\Ppphp\Editor\EditorDiagnosticsRequest;
use Atatusoft\Ppphp\Editor\EditorDiagnosticsRequestDecoder;
use Atatusoft\Ppphp\Editor\Exceptions\EditorDocumentNotOwned;
use Atatusoft\Ppphp\Project\ProjectLoader;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class EditorDiagnosticsCommand extends ProjectCommand
{
    public function __construct(
        ProjectConfigLoader $configLoader,
        ConsoleRenderer $consoleRenderer,
        private readonly JsonRenderer $renderer,
        private readonly ProjectLoader $projectLoader = new ProjectLoader(),
        private readonly EditorDiagnosticsAnalyzer $analyzer = new EditorDiagnosticsAnalyzer(),
        private readonly EditorDiagnosticsRequestDecoder $decoder = new EditorDiagnosticsRequestDecoder(),
    ) {
        parent::__construct('editor:diagnostics', $configLoader, $consoleRenderer, $renderer);
    }

    protected function configure(): void
    {
        $this->setDescription('Check an unsaved editor document for ++PHP errors.')
            ->setHelp('Reads one bounded JSON request from stdin and returns JSON. Does not save buffers or run the additional PHP analysis used by check and build.');
        $this->addProjectOptions();
        $this->getDefinition()->getOption('format')->setDefault('json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            return $this->handleRequest($input, $output);
        } catch (\Throwable) {
            return $this->renderError($output, 'internal-error', 'The compiler could not complete editor diagnostics.', ExitCode::InternalCompilerFailure->value);
        }
    }

    private function handleRequest(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('format') !== 'json') {
            return $this->renderError($output, 'invalid-request', 'The editor diagnostics output format must be json.');
        }

        $json = stream_get_contents(STDIN, EditorDiagnosticsRequest::MAXIMUM_REQUEST_BYTES + 1);

        if ($json === false) {
            return $this->renderError($output, 'request-read-failed', 'The editor diagnostics request could not be read.');
        }

        try {
            $request = $this->decoder->decode($json);
        } catch (\InvalidArgumentException $exception) {
            return $this->renderError($output, 'invalid-request', $exception->getMessage());
        }

        $configuration = $this->configLoader->load($this->resolveWorkingDirectory($input), $this->resolveConfigurationPath($input), true);

        if (!$configuration->isSuccessful || $configuration->configuration === null) {
            return $this->renderError($output, 'invalid-project', $this->describeProjectFailure($configuration->diagnostics));
        }

        $loaded = $this->projectLoader->load($configuration->configuration);

        if (!$loaded->isSuccessful || $loaded->project === null) {
            return $this->renderError($output, 'invalid-project', $this->describeProjectFailure($loaded->diagnostics));
        }

        try {
            $analysis = $this->analyzer->analyze($loaded->project, $request);
        } catch (EditorDocumentNotOwned $exception) {
            return $this->renderError($output, 'document-not-owned', $exception->getMessage());
        }

        if (count($analysis->diagnostics) > EditorDiagnosticsRequest::MAXIMUM_DIAGNOSTICS) {
            return $this->renderError($output, 'response-limit', 'The editor diagnostics result exceeds the diagnostic limit.');
        }

        $response = json_decode($this->renderer->render($analysis->diagnostics), true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($response)) {
            throw new \LogicException('The diagnostic renderer must return an object.');
        }

        $response['document'] = [
            'path' => $analysis->selectedSources->files[0]->displayPath,
            'version' => $request->documentVersion,
        ];
        $response['analysis'] = [
            'completeness' => $analysis->completeness->value,
            'catalogVersion' => AnalysisCapabilityCatalog::VERSION,
            'fullParity' => $analysis->uncoveredRequiredCapabilities === [],
            'uncoveredRequiredCapabilities' => $analysis->uncoveredRequiredCapabilities,
            'supplemental' => false,
        ];
        $response['error'] = null;
        $json = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);

        if (strlen($json) + 1 > EditorDiagnosticsRequest::MAXIMUM_RESPONSE_BYTES) {
            return $this->renderError($output, 'response-limit', 'The editor diagnostics result exceeds four mebibytes.');
        }

        $output->writeln($json, OutputInterface::OUTPUT_RAW);

        return $analysis->diagnostics->hasErrors ? ExitCode::DiagnosticsReported->value : ExitCode::Success->value;
    }

    private function renderError(OutputInterface $output, string $code, string $message, int $exit = 2): int
    {
        $output->writeln(json_encode([
            'version' => EditorDiagnosticsRequest::VERSION,
            'document' => null,
            'diagnostics' => [],
            'summary' => ['errors' => 0, 'warnings' => 0, 'notes' => 0],
            'analysis' => null,
            'error' => ['code' => $code, 'message' => $message],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

        return $exit;
    }
}
