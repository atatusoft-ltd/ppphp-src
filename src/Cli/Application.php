<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Cli;

use Atatusoft\Ppphp\Cache\CompilerCache;
use Atatusoft\Ppphp\Analysis\AnalysisWorkspacePreparer;
use Atatusoft\Ppphp\Analysis\Browser\BrowserAnalysisProtocol;
use Atatusoft\Ppphp\Analysis\Browser\BrowserDiagnosticRenderer;
use Atatusoft\Ppphp\Analysis\Browser\CompilerAnalysisProtocol;
use Atatusoft\Ppphp\Analysis\CompilerProjectAnalyzer;
use Atatusoft\Ppphp\Analysis\DeclarationContextCollector;
use Atatusoft\Ppphp\Cli\Command\BrowserAnalysisCommand;
use Atatusoft\Ppphp\Cli\Command\BuildCommand;
use Atatusoft\Ppphp\Cli\Command\CheckCommand;
use Atatusoft\Ppphp\Cli\Command\CleanCommand;
use Atatusoft\Ppphp\Cli\Command\ComposerConfigureCommand;
use Atatusoft\Ppphp\Cli\Command\DumpAstCommand;
use Atatusoft\Ppphp\Cli\Command\EditorDefinitionCommand;
use Atatusoft\Ppphp\Cli\Command\EditorDiagnosticsCommand;
use Atatusoft\Ppphp\Cli\Command\EditorSemanticTokensCommand;
use Atatusoft\Ppphp\Cli\Command\InitCommand;
use Atatusoft\Ppphp\Cli\Enumerations\ExitCode;
use Atatusoft\Ppphp\Cli\Enumerations\OutputFormat;
use Atatusoft\Ppphp\Compiler\Compiler;
use Atatusoft\Ppphp\Compiler\Output\AtomicBuildCommitter;
use Atatusoft\Ppphp\Compiler\Output\OutputPlanner;
use Atatusoft\Ppphp\Config\ProjectConfigLoader;
use Atatusoft\Ppphp\Diagnostics\ConsoleRenderer;
use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Diagnostics\JsonRenderer;
use Atatusoft\Ppphp\Frontend\AstDumper;
use Atatusoft\Ppphp\Frontend\PpphpParser;
use Atatusoft\Ppphp\Interop\Composer\ComposerConfigurationWriter;
use Atatusoft\Ppphp\Interop\Composer\ComposerRuntimeConfigurator;
use Atatusoft\Ppphp\Project\ProjectCleaner;
use Atatusoft\Ppphp\Project\ProjectChecker;
use Atatusoft\Ppphp\Project\ProjectLoader;
use Atatusoft\Ppphp\Project\ProjectSelector;
use Atatusoft\Ppphp\Project\ProjectSyntaxChecker;
use Atatusoft\Ppphp\Semantic\SemanticAnalyzer;
use Atatusoft\Ppphp\Transpilation\PhpLowerer;
use Atatusoft\Ppphp\Transpilation\Emission\ProductionPhpEmitter;
use Atatusoft\Ppphp\Versioning\ReleaseMetadataLoader;
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Exception\ExceptionInterface as ConsoleException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

final class Application extends SymfonyApplication
{
    public const NAME = Compiler::NAME;

    public const VERSION = Compiler::VERSION;

    public function __construct(private readonly bool $configureMemoryLimit = false)
    {
        parent::__construct(self::NAME, self::VERSION);

        $configLoader = new ProjectConfigLoader();
        $consoleRenderer = new ConsoleRenderer();
        $jsonRenderer = new JsonRenderer();
        $parser = new PpphpParser();
        $projectLoader = new ProjectLoader();
        $selector = new ProjectSelector();
        $syntaxChecker = new ProjectSyntaxChecker($parser);
        $semanticAnalyzer = new SemanticAnalyzer();
        $lowerer = new PhpLowerer();
        $composerRuntimeConfigurator = new ComposerRuntimeConfigurator();
        $cache = new CompilerCache();
        $compilerAnalyzer = new CompilerProjectAnalyzer(
            $syntaxChecker,
            $semanticAnalyzer,
            new DeclarationContextCollector($syntaxChecker, $semanticAnalyzer),
        );
        $checker = new ProjectChecker(
            $compilerAnalyzer,
            new AnalysisWorkspacePreparer($semanticAnalyzer, $lowerer),
            cache: $cache,
        );

        $this->addCommands([
            new BrowserAnalysisCommand(
                new BrowserAnalysisProtocol(
                    $configLoader,
                    $projectLoader,
                    $selector,
                    $checker,
                    diagnosticRenderer: new BrowserDiagnosticRenderer($jsonRenderer),
                ),
                compilerProtocol: new CompilerAnalysisProtocol(
                    $configLoader,
                    $projectLoader,
                    $selector,
                    $compilerAnalyzer,
                    new BrowserDiagnosticRenderer($jsonRenderer),
                ),
            ),
            new InitCommand(
                $configLoader,
                $consoleRenderer,
                $jsonRenderer,
                dirname(__DIR__, 2) . '/ppphp.json.dist',
                new ReleaseMetadataLoader(),
            ),
            new CheckCommand($configLoader, $consoleRenderer, $jsonRenderer, $projectLoader, $selector, $checker),
            new ComposerConfigureCommand(
                $configLoader,
                $consoleRenderer,
                $jsonRenderer,
                $composerRuntimeConfigurator,
                new ComposerConfigurationWriter(),
            ),
            new BuildCommand(
                $configLoader,
                $consoleRenderer,
                $jsonRenderer,
                $projectLoader,
                $selector,
                new Compiler(
                    $checker,
                    new OutputPlanner(),
                    new ProductionPhpEmitter($lowerer),
                    new AtomicBuildCommitter(),
                    $composerRuntimeConfigurator,
                    cache: $cache,
                ),
            ),
            new CleanCommand($configLoader, $consoleRenderer, $jsonRenderer, new ProjectCleaner()),
            new EditorDefinitionCommand(
                $configLoader,
                $consoleRenderer,
                $jsonRenderer,
                $projectLoader,
                $syntaxChecker,
                $semanticAnalyzer,
            ),
            new EditorSemanticTokensCommand(
                $configLoader,
                $consoleRenderer,
                $jsonRenderer,
                $projectLoader,
                $parser,
            ),
            new EditorDiagnosticsCommand($configLoader, $consoleRenderer, $jsonRenderer, $projectLoader),
            new DumpAstCommand(
                $configLoader,
                $consoleRenderer,
                $jsonRenderer,
                $projectLoader,
                $selector,
                $parser,
                new AstDumper(),
            ),
        ]);
    }

    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        try {
            if ($this->configureMemoryLimit && $this->getCommandName($input) !== 'browser:analysis') {
                (new CompilerMemoryLimit())->apply();
            }

            return parent::doRun($input, $output);
        } catch (ConsoleException $exception) {
            [$message, $help] = $this->describeInvalidInvocation($input, $exception);
            return $this->renderFailure(
                $input,
                $output,
                new Diagnostic(
                    DiagnosticCode::InvalidInvocation,
                    $message,
                    help: $help,
                ),
                ExitCode::InvalidProject,
            );
        } catch (\Throwable $exception) {
            return $this->renderFailure(
                $input,
                $output,
                new Diagnostic(
                    DiagnosticCode::InternalCompilerError,
                    'The compiler encountered an unexpected failure.',
                    help: 'Run the command again with --debug and include the resulting details when reporting the issue.',
                    debug: [
                        'exception' => $exception::class,
                        'message' => $exception->getMessage(),
                        'trace' => $exception->getTraceAsString(),
                    ],
                ),
                ExitCode::InternalCompilerFailure,
            );
        }
    }

    /** @return array{string, string} */
    private function describeInvalidInvocation(InputInterface $input, ConsoleException $exception): array
    {
        $fallback = [$exception->getMessage(), 'Review the command help and pass only supported arguments and options.'];
        $name = $this->getCommandName($input);
        if (!in_array($name, ['check', 'build'], true)) {
            return $fallback;
        }

        // Rebind a copy to distinguish excess paths from malformed options.
        // This is diagnostic-only; command execution still accepts one path.
        $definition = clone $this->find($name)->getDefinition();
        $definition->addArgument(new InputArgument('extraPaths', InputArgument::IS_ARRAY));
        $probe = clone $input;
        try {
            $probe->bind($definition);
            $probe->validate();
        } catch (ConsoleException) {
            return $fallback;
        }
        if ($probe->getArgument('extraPaths') === []) {
            return $fallback;
        }

        return [
            $name . ' accepts at most one path.',
            'Please run it once per path or ' . $name . ' the containing directory.',
        ];
    }

    private function renderFailure(
        InputInterface $input,
        OutputInterface $output,
        Diagnostic $diagnostic,
        ExitCode $exitCode,
    ): int {
        $diagnostics = new DiagnosticBag();
        $diagnostics->add($diagnostic);
        $formatValue = $input->getParameterOption('--format', OutputFormat::Console->value);
        $format = is_string($formatValue)
            ? OutputFormat::tryFrom($formatValue)
            : null;
        (new DiagnosticOutputWriter(new ConsoleRenderer(), new JsonRenderer()))
            ->write($diagnostics, $format ?? OutputFormat::Console, $input, $output);

        return $exitCode->value;
    }
}
