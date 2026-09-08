<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Cache\CompilerCache;
use Atatusoft\Ppphp\Config\ProjectConfigLoader;
use Atatusoft\Ppphp\Diagnostics\JsonRenderer;
use Atatusoft\Ppphp\Project\Enumerations\SelectionMode;
use Atatusoft\Ppphp\Project\ProjectChecker;
use Atatusoft\Ppphp\Project\ProjectLoader;
use Atatusoft\Ppphp\Project\ProjectSelector;
use Symfony\Component\Process\Process;

function runUsabilityCommand(string $root, string $command, array $arguments = [], ?array $input = null): Process
{
    $process = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', $command,
        '--working-directory', $root, '--no-ansi', ...$arguments], timeout: 60);
    $process->setInput($input === null ? null : json_encode($input, JSON_THROW_ON_ERROR));
    $process->run();
    return $process;
}

function usabilityPayload(Process $process): array
{
    expect($process->getErrorOutput())->toBe('');
    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

function applyDisplayedUsabilityInsertion(string $source, array $diagnostic): string
{
    $sample = explode("\n\n    ", $diagnostic['help'], 2)[1] ?? '';
    $range = $diagnostic['location']['range'];
    $name = substr($source, $range['start']['offset'], $range['end']['offset'] - $range['start']['offset']);
    $offset = strpos($sample, $name);
    expect($offset)->not->toBeFalse();
    return substr_replace($source, substr($sample, 0, $offset), $range['start']['offset'], 0);
}

test('developer correction sequence checks builds lints and executes the original negated repository guard', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $fixture = dirname(__DIR__, 2) . '/Fixtures/DiagnosticUsability/';
    $interface = file_get_contents($fixture . 'Repository.ppphp');
    $implementation = file_get_contents($fixture . 'InMemoryRepository.ppphp');
    $this->writeFile($root . '/src/Repository.ppphp', $interface);
    $this->writeFile($root . '/src/InMemoryRepository.ppphp', $implementation);
    $before = runUsabilityCommand($root, 'check', ['--format=json']);
    $payload = usabilityPayload($before);
    expect($before->getExitCode())->toBe(1)
        ->and(array_column($payload['diagnostics'], 'code'))->toBe(['P4004', 'P2002']);
    $missing = array_values(array_filter($payload['diagnostics'], static fn (array $d): bool => $d['code'] === 'P2002'))[0];
    $implementation = applyDisplayedUsabilityInsertion($implementation, $missing);
    $this->writeFile($root . '/src/InMemoryRepository.ppphp', $implementation);
    $payload = usabilityPayload(runUsabilityCommand($root, 'check', ['--format=json']));
    expect(array_column($payload['diagnostics'], 'code'))->toBe(['P4004']);

    // Editing update() is not a remedy for create(); supply both methods so this
    // comparison has no independent missing-method error.
    $interface = str_replace("\n}", "\n    public function update(T \$item): void throws \\InvalidArgumentException;\n}", $interface);
    $implementation = substr_replace($implementation, "\n    public function update(T \$item): void throws \\InvalidArgumentException {}\n", strrpos($implementation, '}'), 0);
    $this->writeFile($root . '/src/Repository.ppphp', $interface);
    $this->writeFile($root . '/src/InMemoryRepository.ppphp', $implementation);
    $payload = usabilityPayload(runUsabilityCommand($root, 'check', ['--format=json']));
    expect(array_column($payload['diagnostics'], 'code'))->toBe(['P4004'])
        ->and($payload['diagnostics'][0]['help'])->toContain('Repository::create()')->not->toContain('update()');

    $interface = str_replace('public function create(T $item): void;', 'public function create(T $item): void throws \\InvalidArgumentException;', $interface);
    $this->writeFile($root . '/src/Repository.ppphp', $interface);
    $consumer = <<<'PPP'
<?php
use Atatusoft\Showcase\Infrastructure\InMemoryRepository;
require_once __DIR__ . '/Repository.php';
require_once __DIR__ . '/InMemoryRepository.php';
final class Item {
    public mixed $sku = 'sku';
    public mixed $name = 'name';
    public function __construct(public mixed $id) {}
}
Item $old = new Item('one');
InMemoryRepository<Item> $repository = new InMemoryRepository(['one' => $old]);
Item $replacement = new Item('one');
$repository->create($replacement);
if ($repository->findById('one') !== $replacement) { throw new \Error('Replacement failed'); }
try { $repository->create(new Item('absent')); } catch (\InvalidArgumentException $e) { throw new \Error('Unexpected rejection'); }
if ($repository->findById('absent') !== null) { throw new \Error('The original storage condition changed'); }
try { $repository->create(new Item(42)); throw new \Error('Invalid identifier accepted'); } catch (\InvalidArgumentException $e) {}
echo "repository-ok\n";
PPP;
    $this->writeFile($root . '/src/run.ppphp', $consumer);
    $payload = usabilityPayload(runUsabilityCommand($root, 'check', ['--format=json']));
    expect(array_column($payload['diagnostics'], 'code'))->toContain('P4008')->not->toContain('P4004', 'P2016');
    $consumer = str_replace('$repository->create($replacement);', 'try { $repository->create($replacement); } catch (\\InvalidArgumentException $e) { throw new \\Error("Unexpected rejection"); }', $consumer);
    $this->writeFile($root . '/src/run.ppphp', $consumer);
    $unconstrained = usabilityPayload(runUsabilityCommand($root, 'check', ['--format=json']));
    expect(array_column($unconstrained['diagnostics'], 'code'))->toBe(['P2019', 'P2019', 'P2019']);
    // The private structural validator accepts arbitrary values and checks them.
    // Expose that mixed input boundary in the runtime fixture. Unbounded T also
    // erases to mixed, so emitted behavior and both validation guards are identical.
    // Keep the original generic fixture's independent property findings above.
    $implementation = str_replace('private function getId(T $item)', 'private function getId(mixed $item)', $implementation);
    $this->writeFile($root . '/src/InMemoryRepository.ppphp', $implementation);
    $valid = runUsabilityCommand($root, 'check', ['--format=json']);
    expect($valid->getExitCode())->toBe(0, $valid->getOutput())->and(usabilityPayload($valid)['summary']['errors'])->toBe(0);
    $build = runUsabilityCommand($root, 'build', ['--format=json']);
    expect($build->getExitCode())->toBe(0, $build->getOutput());
    foreach (glob($root . '/build/ppphp/*.php') as $path) {
        $lint = new Process([PHP_BINARY, '-n', '-l', $path]);
        $lint->run();
        expect($lint->getExitCode())->toBe(0, $lint->getErrorOutput());
    }
    $runtime = new Process([PHP_BINARY, $root . '/build/ppphp/run.php']);
    $runtime->run();
    expect($runtime->getExitCode())->toBe(0, $runtime->getErrorOutput())
        ->and($runtime->getOutput())->toBe("repository-ok\n");

    $successfulOutput = file_get_contents($root . '/build/ppphp/InMemoryRepository.php');
    expect($successfulOutput)->toContain('if (!is_string($id))', 'if (!is_object($item))', 'if (isset($this->items[$id]))');
    $this->writeFile($root . '/src/InMemoryRepository.ppphp', str_replace('string $id = $this->getId($item);', '$id = $this->getId($item);', $implementation));
    $failedBuild = runUsabilityCommand($root, 'build', ['--format=json']);
    expect($failedBuild->getExitCode())->toBe(1)
        ->and(array_column(usabilityPayload($failedBuild)['diagnostics'], 'code'))->toContain('P2002')->not->toContain('P2003')
        ->and(file_get_contents($root . '/build/ppphp/InMemoryRepository.php'))->toBe($successfulOutput);
});

test('console JSON debug unsaved overlays and verified warm cache replay agree on both root causes', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $interface = "<?php\r\nnamespace App;\r\ninterface Repository { public function create(): void; }\r\n";
    $source = "<?php\r\nnamespace App;\r\n// café 😀\r\nfinal class ExtremelyLongButReadableInMemoryRepository implements Repository {\r\n    public function create(): void throws \\InvalidArgumentException {\r\n        \$id = \$this->getId();\r\n        echo \$id;\r\n        echo \$id;\r\n    }\r\n    private function getId(): string { return 'id'; }\r\n}\r\n";
    $this->writeFile($root . '/src/Repository.ppphp', $interface);
    $this->writeFile($root . '/src/Memory.ppphp', $source);
    $configuration = (new ProjectConfigLoader())->load($root, null, true)->configuration;
    $project = (new ProjectLoader())->load($configuration)->project;
    $selection = (new ProjectSelector())->select($project, null, SelectionMode::Check)->selection;
    $cache = new CompilerCache();
    $checker = new ProjectChecker(cache: $cache);
    $cold = $checker->check($project, $selection->analysisSources);
    $warm = $checker->check($project, $selection->analysisSources);
    expect($cold->semanticResult)->not->toBeNull()
        ->and($warm->semanticResult)->toBeNull()
        ->and($warm->compilerEvidence)->toBeTrue()
        ->and($warm->supplementalEvidence)->toBeFalse()
        ->and($cache->statistics->semanticWorkAvoided)->toBe(1)
        ->and((new JsonRenderer())->render($warm->diagnostics))->toBe((new JsonRenderer())->render($cold->diagnostics));
    $json = usabilityPayload(runUsabilityCommand($root, 'check', ['--format=json']));
    $debug = usabilityPayload(runUsabilityCommand($root, 'check', ['--format=json', '--debug']));
    $console = runUsabilityCommand($root, 'check');
    foreach ($debug['diagnostics'] as &$diagnostic) { unset($diagnostic['debug']); }
    unset($diagnostic);
    expect($debug)->toBe($json)
        ->and($console->getErrorOutput())->toContain('Missing Local Variable Type', 'Exception Not Permitted By Inherited Contract', 'Repository::create()', 'string $id = $this->getId();', 'src/Repository.ppphp');

    // The target is truly unsaved: disk is replaced with different, valid source.
    $saved = '<?php namespace App; final class Saved {}';
    $this->writeFile($root . '/src/Memory.ppphp', $saved);
    $editor = usabilityPayload(runUsabilityCommand($root, 'editor:diagnostics', ['--format=json'], [
        'version' => 1, 'document' => ['path' => 'src/Memory.ppphp', 'contents' => $source, 'version' => 7],
        'overlays' => [['path' => 'src/Repository.ppphp', 'contents' => $interface]],
    ]));
    expect($editor['diagnostics'])->toBe($json['diagnostics'])
        ->and($editor['analysis']['supplemental'])->toBeFalse()
        ->and(file_get_contents($root . '/src/Memory.ppphp'))->toBe($saved);
});

test('dependency-owned inherited contracts suggest handling at the implementation boundary', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/composer.json', json_encode(['require' => ['vendor/contracts' => '*']], JSON_THROW_ON_ERROR));
    $this->writeFile($root . '/vendor/composer/installed.json', json_encode(['packages' => [[
        'name' => 'vendor/contracts', 'version' => '1.0.0', 'install-path' => '../vendor/contracts',
        'autoload' => ['psr-4' => ['Vendor\\' => 'src/']],
    ]]], JSON_THROW_ON_ERROR));
    $this->writeFile($root . '/vendor/vendor/contracts/src/Repository.php', '<?php namespace Vendor; interface Repository { public function create(): void; }');
    $this->writeFile($root . '/src/Memory.ppphp', '<?php class Memory implements \\Vendor\\Repository { public function create(): void throws \\InvalidArgumentException {} }');
    $payload = usabilityPayload(runUsabilityCommand($root, 'check', ['--format=json']));
    $diagnostic = array_values(array_filter($payload['diagnostics'], static fn (array $d): bool => $d['code'] === 'P4004'))[0];
    expect($diagnostic['help'])->toContain('inside Memory::create()', 'dependency-owned')->not->toContain('add `throws')
        ->and($diagnostic['related'][0]['location']['file'])->toBe('<Composer vendor/contracts>/src/Repository.php');
});
