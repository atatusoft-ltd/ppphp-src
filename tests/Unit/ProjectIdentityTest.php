<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Cli\Application;
use Atatusoft\Ppphp\Compiler\Compiler;
use Atatusoft\Ppphp\Config\ProjectConfigLoader;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Frontend\PpphpParser;

test('the canonical project identity is complete', function (): void {
    $root = dirname(__DIR__, 2);
    $composer = json_decode(
        (string) file_get_contents($root . '/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $configuration = json_decode(
        (string) file_get_contents($root . '/ppphp.json.dist'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $application = new Application();
    $retiredNamespace = implode('', ['Ama', 'siye', '\\', 'Ppphp']);

    expect($composer['name'])->toBe('atatusoft-ltd/ppphp-src')
        ->and($composer['autoload']['psr-4'])->toBe(['Atatusoft\\Ppphp\\' => 'src/'])
        ->and($composer['bin'])->toBe(['bin/ppphp'])
        ->and(class_exists(PpphpParser::class))->toBeTrue()
        ->and(class_exists($retiredNamespace . '\\Frontend\\PpphpParser'))->toBeFalse()
        ->and($application->getVersion())->toBe(Compiler::VERSION)
        ->and(file_exists($root . '/resources/schema/ppphp.schema.json'))->toBeTrue()
        ->and(file_exists($root . '/resources/phpstan/ppphp.neon'))->toBeTrue()
        ->and(file_exists($root . '/docs/ppphp-mvp-end-to-end-plan.md'))->toBeTrue()
        ->and($configuration['cache'])->toBe('.ppphp-cache')
        ->and($configuration['output'])->toBe('build/ppphp');

    foreach ([
        'browser:analysis',
        'init',
        'check',
        'composer:configure',
        'build',
        'clean',
        'editor:definition',
        'editor:semantic-tokens',
        'dump:ast',
    ] as $command) {
        expect($application->has($command))->toBeTrue();
    }
});

test('the configuration loader does not fall back to the retired filename', function (): void {
    $root = $this->createTemporaryDirectory();
    $retiredFilename = 'ph' . 'plus.json';
    $this->writeFile($root . '/' . $retiredFilename, '{}');
    $result = (new ProjectConfigLoader())->load($root);

    expect($result->isSuccessful)->toBeFalse()
        ->and($result->diagnostics->errors[0]->code)->toBe(DiagnosticCode::ProjectConfigurationNotFound)
        ->and($result->diagnostics->errors[0]->message)->toContain('ppphp.json');
});

test('the post-MVP roadmap records the bounded Stage 15 contracts', function (): void {
    $plan = file_get_contents(dirname(__DIR__, 2) . '/docs/ppphp-mvp-end-to-end-plan.md');

    expect($plan)->not->toBeFalse();

    if (!is_string($plan)) {
        throw new RuntimeException('The end-to-end plan could not be read.');
    }

    $start = strpos($plan, '## Stage 15 — Immutable Records, Native Type Ergonomics, And Declarative Framework Metadata');
    $end = strpos($plan, '## 14. Dependency Policy', is_int($start) ? $start : 0);

    expect($start)->toBeInt()
        ->and($end)->toBeInt();

    if (!is_int($start) || !is_int($end)) {
        throw new RuntimeException('The bounded Stage 15 roadmap section is missing.');
    }

    $stage = substr($plan, $start, $end - $start);

    expect($stage)->toContain(
        'Stage 15A — Immutable Records',
        'Stage 15B — Postfix List Types',
        'Stage 15C — Scalar Objects',
        'Stage 15D — List And Map Objects',
        'Stage 15E — Attribute Factory Expressions',
        'length',
        'toLower',
        'count',
        'first',
        'firstKey',
        'find',
        'findKey',
        'filter',
        'map',
        'reduce',
        'CollectionQueryException',
        'remains a draft',
    );

    $index = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/rfcs/README.md');

    foreach ([
        ['0001-immutable-records', '15A', 'Accepted', 'Scheduled'],
        ['0002-list-and-map-path-access', '15D', 'Accepted', 'Scheduled'],
        ['0003-postfix-list-types', '15B', 'Accepted', 'Scheduled'],
        ['0004-scalar-objects', '15C', 'Accepted', 'Scheduled'],
        ['0005-list-and-map-objects', '15D', 'Accepted', 'Scheduled'],
        ['0006-attribute-factory-expressions', '15E', 'Draft', 'Proposed'],
    ] as [$name, $identifier, $status, $schedule]) {
        $rfc = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/rfcs/' . $name . '.md');

        expect($rfc)->toContain('Status: ' . $status, 'Implementation: ' . $schedule . ' For Stage ' . $identifier)
            ->and($index)->toContain('](' . $name . '.md) | ' . $status . ' | ' . $schedule . ' For Stage ' . $identifier . ' |');

        $section = explode('### Stage ' . $identifier . ' — ', $stage, 2)[1];
        expect(explode('### Stage ', $section, 2)[0])->toContain('(rfcs/' . $name . '.md)');
    }
});

test('the accepted Records RFC and roadmap preserve the complete Stage 15A contract', function (): void {
    $root = dirname(__DIR__, 2);
    $rfc = file_get_contents($root . '/docs/rfcs/0001-immutable-records.md');
    $plan = file_get_contents($root . '/docs/ppphp-mvp-end-to-end-plan.md');

    expect($rfc)->toBeString()
        ->and($plan)->toBeString();

    if (!is_string($rfc) || !is_string($plan)) {
        throw new RuntimeException('The Records documentation could not be read.');
    }

    foreach ([$rfc, $plan] as $document) {
        expect($document)->toContain(
            'contextual class-like declaration keyword',
            'implicitly final',
            'public readonly',
            'compiler-generated constructor',
            'generic',
            'implement interfaces',
            'virtual get-only computed properties',
            'set hooks',
            'additional backed instance state',
            'custom constructor',
            'extend a class',
            'shallow',
            'ordinary PHP object identity',
            'final class',
            'synthesized equality',
        );
    }
});

test('maintainer release status and the native analyzer contract agree', function (): void {
    $root = dirname(__DIR__, 2);
    $maintainerDocuments = [
        $root . '/AGENTS.md',
        $root . '/docs/ppphp-mvp-end-to-end-plan.md',
        $root . '/docs/releasing.md',
    ];

    foreach ($maintainerDocuments as $path) {
        $document = file_get_contents($path);
        expect($document)->toBeString()
            ->and($document)->toContain('Stage 14A', 'Stage 14B', 'release/');
    }

    $analysis = (string) file_get_contents($root . '/docs/analyzer-independence.md');
    $phpStan = (string) file_get_contents($root . '/docs/phpstan-integration.md');

    expect($analysis)->toContain('ADR 0004', 'retains the supplemental PHPStan native default')
        ->and($phpStan)->toContain('mandatory supplemental analysis for normal MVP check/build')
        ->and($phpStan)->toContain('ADR 0004');

    foreach ([...$maintainerDocuments, $root . '/docs/analyzer-independence.md'] as $path) {
        $document = (string) file_get_contents($path);
        expect($document)->not->toMatch('/Stage 13D (?:is next|work has not started|should implement)/i');
    }
});
