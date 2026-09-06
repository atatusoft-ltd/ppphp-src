<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Frontend\Enumerations\ParseMode;
use Atatusoft\Ppphp\Frontend\PpphpParser;
use Atatusoft\Ppphp\Project\ProjectParseResult;
use Atatusoft\Ppphp\Semantic\SemanticAnalysisResult;
use Atatusoft\Ppphp\Semantic\SemanticAnalyzer;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;
use Atatusoft\Ppphp\Support\Path;

function analyzeStageSixSource(string $contents, FileKind $kind = FileKind::Ppphp): SemanticAnalysisResult
{
    $relative = $kind === FileKind::Ppphp ? 'src/Feature.ppphp' : 'src/Feature.php';
    $source = new SourceFile('/project/' . $relative, $relative, $kind, $contents);
    $parse = (new PpphpParser())->parse(
        $source,
        $kind === FileKind::Ppphp ? ParseMode::PlusPlusPhp : ParseMode::Php,
    );
    $key = Path::buildComparisonKey($source->path);

    return (new SemanticAnalyzer())->analyze(new ProjectParseResult(
        $parse->parsedFile === null ? [] : [$key => $parse->parsedFile],
        [$key => $source],
        $parse->diagnostics,
    ));
}

/** @return list<string> */
function stageSixCodes(SemanticAnalysisResult $result): array
{
    return array_map(
        static fn (Diagnostic $diagnostic): string => $diagnostic->code->value,
        iterator_to_array($result->diagnostics),
    );
}

test('ppphp declarations require native parameter return and property types', function (): void {
    $analysis = analyzeStageSixSource(<<<'PPP'
<?php
final class Contract
{
    public $value;

    public function transform($input)
    {
        return $input;
    }
}
PPP);

    expect(stageSixCodes($analysis))
        ->toContain(DiagnosticCode::MissingPropertyType->value)
        ->toContain(DiagnosticCode::MissingParameterType->value)
        ->toContain(DiagnosticCode::MissingReturnType->value);
});

test('explicit broad types and lifecycle return exemptions remain valid', function (): void {
    $analysis = analyzeStageSixSource(<<<'PPP'
<?php
final class Contract
{
    public mixed $value;

    public function __construct(mixed $value)
    {
        $this->value = $value;
    }

    public function inspect(mixed $input): mixed
    {
        return $input;
    }

    public function stop(): never
    {
        throw new TypeError();
    }
}
PPP);

    expect($analysis->isSuccessful)->toBeTrue()
        ->and(stageSixCodes($analysis))->toBe([]);
});

test('closures arrows variadics and promoted parameters use the strict declaration contract', function (): void {
    $analysis = analyzeStageSixSource(<<<'PPP'
<?php
final class Invalid
{
    public function __construct(public $promoted) {}
}

function collect(...$items): void
{
    callable $closure = function ($value) { return $value; };
    callable $arrow = fn ($value) => $value;
}
PPP);
    $counts = array_count_values(stageSixCodes($analysis));

    expect($counts[DiagnosticCode::MissingParameterType->value] ?? 0)->toBe(4)
        ->and($counts[DiagnosticCode::MissingReturnType->value] ?? 0)->toBe(2);
});

test('ordinary php is exempt from ppphp declaration completeness', function (): void {
    $analysis = analyzeStageSixSource(<<<'PHP'
<?php
class Legacy
{
    public $value;
    public function transform($input) { return $input; }
}
PHP, FileKind::Php);

    expect($analysis->isSuccessful)->toBeTrue()
        ->and(stageSixCodes($analysis))->toBe([]);
});

test('unsafe dynamic constructs and dynamic property creation are rejected in ppphp', function (): void {
    $analysis = analyzeStageSixSource(<<<'PPP'
<?php
namespace App;
final class UnsafeExample
{
    public function execute(string $path): void
    {
        eval('echo 1;');
        include $path;
        $this->created = 1;
        ${$path} = 1;
    }
}
PPP);

    expect(stageSixCodes($analysis))
        ->toContain(DiagnosticCode::UnsafeDynamicConstruct->value)
        ->toContain(DiagnosticCode::DynamicPropertyNotAllowed->value);
});

test('property writes honor inherited and trait visibility without hiding dynamic creation', function (): void {
    $analysis = analyzeStageSixSource(<<<'PPP'
<?php
trait Named
{
    private string $traitName;
}
class ParentFeature
{
    protected string $inheritedName;
    private string $privateName;
}
final class Feature extends ParentFeature
{
    use Named;

    public function rename(): void
    {
        $this->inheritedName = 'inherited';
        $this->traitName = 'trait';
        $this->privateName = 'dynamic';
    }
}
PPP);
    $codes = stageSixCodes($analysis);

    expect(array_count_values($codes)[DiagnosticCode::DynamicPropertyNotAllowed->value] ?? 0)->toBe(1);
});

test('literal dir file and concatenated include paths remain statically analyzable', function (): void {
    $analysis = analyzeStageSixSource(<<<'PPP'
<?php
function loadFiles(): void
{
    include 'bootstrap.php';
    require_once __DIR__ . '/config.php';
    include __FILE__ . '.inc';
}
PPP);

    expect(stageSixCodes($analysis))->not->toContain(DiagnosticCode::UnsafeDynamicConstruct->value);
});

test('project declaration and resolved-name tables cover php namespaces imports and members', function (): void {
    $analysis = analyzeStageSixSource(<<<'PPP'
<?php
namespace App;
use DateTimeImmutable as Clock;
class BaseService {}
interface Clocked {}
trait Named {}
final class Service extends BaseService implements Clocked
{
    use Named;
    public string $name;
    public function now(Clock $clock): Clock { return $clock; }
}
function service(Service $service): Service { return $service; }
PPP);

    $class = $analysis->symbols->findClass('App\\Service');

    expect($class)->not->toBeNull()
        ->and($class?->findProperty('name'))->not->toBeNull()
        ->and($class?->findMethod('now'))->not->toBeNull()
        ->and($class?->parent)->toBe('App\\BaseService')
        ->and($class?->interfaces)->toBe(['App\\Clocked'])
        ->and($class?->traits)->toBe(['App\\Named'])
        ->and($class?->findMethod('now')?->parameters[0]->type?->text)->toBe('\\DateTimeImmutable')
        ->and($analysis->symbols->findFunction('App\\service'))->not->toBeNull()
        ->and($analysis->resolvedNames->entries)->not->toBeEmpty();
});
