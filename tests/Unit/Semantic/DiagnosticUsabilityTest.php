<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Diagnostics\ConsoleRenderer;
use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Diagnostics\DiagnosticProcessor;
use Atatusoft\Ppphp\Frontend\PpphpParser;
use Atatusoft\Ppphp\Project\ProjectParseResult;
use Atatusoft\Ppphp\Semantic\SemanticAnalyzer;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;

/** @param array<string, string> $files */
function analyzeDiagnosticUsability(array $files): DiagnosticBag
{
    $parsed = [];
    $sources = [];
    $diagnostics = new DiagnosticBag();
    foreach ($files as $path => $contents) {
        $source = new SourceFile('/project/' . $path, $path, str_ends_with($path, '.php') ? FileKind::Php : FileKind::Ppphp, $contents);
        $result = (new PpphpParser())->parse($source);
        $sources[$source->path] = $source;
        $diagnostics->addAll($result->diagnostics);
        if ($result->parsedFile !== null) {
            $parsed[$source->path] = $result->parsedFile;
        }
    }
    return (new DiagnosticProcessor())->process((new SemanticAnalyzer())->analyze(new ProjectParseResult($parsed, $sources, $diagnostics))->diagnostics);
}

/** @return list<Diagnostic> */
function usabilityFind(DiagnosticBag $diagnostics, string $code): array
{
    return array_values(array_filter(iterator_to_array($diagnostics), static fn (Diagnostic $d): bool => $d->code->value === $code));
}

test('missing local type offers a declaration and recovers dependent reads only', function (): void {
    $source = <<<'PPP'
<?php
function getId(): string { return 'id'; }
function create(): void {
    $id = getId();
    echo $id;
    echo $id;
    echo $missing;
}
PPP;
    $diagnostics = analyzeDiagnosticUsability(['src/Local.ppphp' => $source]);
    $missing = usabilityFind($diagnostics, 'P2002');
    expect($missing)->toHaveCount(1)
        ->and($missing[0]->title)->toBe('Missing Local Variable Type')
        ->and($missing[0]->primary?->span->text)->toBe('$id')
        ->and($missing[0]->help)->toContain('getId()', 'declared to return string', 'string $id = getId();')
        ->and(usabilityFind($diagnostics, 'P2003'))->toHaveCount(1);
});

test('inherited exception explanation names both matching methods and the permitted set', function (): void {
    $diagnostics = analyzeDiagnosticUsability([
        'src/Repository.ppphp' => '<?php interface Repository { public function create(string $item): void; public function update(string $item): void throws \\InvalidArgumentException; }',
        'src/Memory.ppphp' => '<?php final class Memory implements Repository { public function create(string $item): void throws \\InvalidArgumentException {} public function update(string $item): void throws \\InvalidArgumentException {} }',
    ]);
    $mismatch = usabilityFind($diagnostics, 'P4004');
    expect($mismatch)->toHaveCount(1)
        ->and($mismatch[0]->message)->toContain('Memory::create()', 'Repository::create()', 'does not declare any checked exceptions')
        ->and($mismatch[0]->help)->toContain('throws \\InvalidArgumentException', 'Repository::create()', 'inside Memory::create()')
        ->not->toContain('update()')
        ->and($mismatch[0]->related[0]->span->sourceFile->displayPath)->toBe('src/Repository.ppphp');
});

test('negated string guard with a terminating branch narrows the surviving return', function (): void {
    $diagnostics = analyzeDiagnosticUsability(['src/Guard.ppphp' => <<<'PPP'
<?php
function requireString(mixed $value): string throws \InvalidArgumentException {
    if (!is_string($value)) { throw new \InvalidArgumentException('Expected a string.'); }
    return $value;
}
PPP]);
    expect(usabilityFind($diagnostics, 'P2016'))->toBe([]);
});

/** Apply the insertion actually shown by the compiler, without rewriting the initializer. */
function applyUsabilitySuggestion(string $source, Diagnostic $diagnostic): string
{
    $sample = explode("\n\n    ", $diagnostic->help ?? '', 2)[1] ?? '';
    $variable = $diagnostic->primary?->span->text ?? '';
    $position = strpos($sample, $variable);
    expect($position)->not->toBeFalse();
    $insertion = substr($sample, 0, $position);
    expect($insertion)->not->toBe('');
    return substr_replace($source, $insertion, $diagnostic->primary->span->start->offset, 0);
}

test('actual suggested types can be inserted and checked with independent errors intact', function (string $prefix, string $initializer, string $expected): void {
    $source = "<?php\n" . $prefix . "\nfunction exercise(): void {\n    // café 😀\n    \$value = " . $initializer . " /* keep */;\n    echo \$unknown;\n}\n";
    $diagnostics = analyzeDiagnosticUsability(['src/Types.ppphp' => $source]);
    $diagnostic = usabilityFind($diagnostics, 'P2002')[0];
    expect($diagnostic->help)->toContain($expected . ' $value = ', '/* keep */;');
    $fixed = applyUsabilitySuggestion($source, $diagnostic);
    $after = analyzeDiagnosticUsability(['src/Types.ppphp' => $fixed]);
    expect(usabilityFind($after, 'P2002'))->toBe([])
        ->and(usabilityFind($after, 'P2003'))->toHaveCount(1)
        ->and(usabilityFind($after, 'P2008'))->toBe([])
        ->and(usabilityFind($after, 'P2030'))->toBe([]);
})->with([
    'scalar' => ['function provide(): string { return "id"; }', 'provide()', 'string'],
    'nullable' => ['function provide(): ?string { return null; }', 'provide()', 'string|null'],
    'generic result' => ['class Box<T> { public function __construct(public T $value) {} } function provide<T>(T $item): T { return $item; }', 'provide(new Box("id"))', 'Box<string>'],
    'list syntax' => ['function provide(): array<string> { return ["id"]; }', 'provide()', 'array<string>'],
    'imported class' => ['namespace Domain; class Item {} namespace App; use Domain\Item as Product; function provide(): Product { return new Product(); }', 'provide()', 'Product'],
    'composite' => ['function provide(): string|int { return "id"; }', 'provide()', 'string|int'],
]);

test('recovery leaves earlier reads self-reference unrelated scopes and initializer errors visible', function (): void {
    $diagnostics = analyzeDiagnosticUsability(['src/Recovery.ppphp' => <<<'PPP'
<?php
function accept(string $value): string { return $value; }
function first(): void {
    echo $id;
    $id = $id . 'suffix';
    echo $id;
    $value = $missing;
    echo $value;
    $other = accept(42);
    readonly int $fixed = 1;
    $fixed = 2;
    string $wrong = 1;
    \Closure $closure = function (): void { echo $id; };
}
function second(): void { echo $id; }
PPP]);
    expect(usabilityFind($diagnostics, 'P2002'))->toHaveCount(3)
        ->and(usabilityFind($diagnostics, 'P2003'))->toHaveCount(5)
        ->and(usabilityFind($diagnostics, 'P2015'))->toHaveCount(1)
        ->and(usabilityFind($diagnostics, 'P2005'))->toHaveCount(1)
        ->and(usabilityFind($diagnostics, 'P2008'))->toHaveCount(1);
});

test('uncertain or conflicting initializers do not receive invented types or invalid insertion advice', function (string $body, string $explanation): void {
    $diagnostics = analyzeDiagnosticUsability(['src/Unknown.ppphp' => '<?php function example(mixed $input): void { ' . $body . ' }']);
    $diagnostic = usabilityFind($diagnostics, 'P2002')[0];
    expect($diagnostic->help)->toContain($explanation)->not->toContain('mixed $value', 'Write:');
})->with([
    'unknown' => ['$value = $missing;', 'could not determine a suitable type'],
    'explicit broad' => ['$value = $input;', 'could not determine a suitable type'],
    'later conflicting write' => ['$value = 1; $value = "text";', 'written again'],
    'expression' => ['echo ($value = 1);', 'separate statement'],
]);

test('recovery does not prevent a later real declaration or contaminate captured locals', function (): void {
    $diagnostics = analyzeDiagnosticUsability(['src/Capture.ppphp' => <<<'PPP'
<?php
function example(): void {
    $value = 'first';
    \Closure $read = function () use ($value): void { echo $value; };
    string $value = 'real';
    $value = 42;
}
PPP]);
    expect(usabilityFind($diagnostics, 'P2002'))->toHaveCount(1)
        ->and(usabilityFind($diagnostics, 'P2003'))->toBe([])
        ->and(usabilityFind($diagnostics, 'P2004'))->toBe([])
        ->and(usabilityFind($diagnostics, 'P2009'))->toHaveCount(1);
});

test('type parameters remain source parameters in declaration assistance', function (): void {
    $source = '<?php function identity<T>(T $input): T { $value = $input; return $value; }';
    $diagnostic = usabilityFind(analyzeDiagnosticUsability(['src/Generic.ppphp' => $source]), 'P2002')[0];
    expect($diagnostic->help)->toContain('T $value = $input;');
    $after = analyzeDiagnosticUsability(['src/Generic.ppphp' => applyUsabilitySuggestion($source, $diagnostic)]);
    expect($after->errors)->toBe([]);
});

test('declared return evidence survives an independently invalid helper body and CRLF', function (): void {
    $source = "<?php\r\n// café 😀\r\nfunction provide(): string { return 42; }\r\nfunction example(): void {\r\n\t\$value /* name */ = provide();\r\n\techo \$value;\r\n}\r\n";
    $diagnostics = analyzeDiagnosticUsability(['src/CRLF.ppphp' => $source]);
    $diagnostic = usabilityFind($diagnostics, 'P2002')[0];
    expect($diagnostic->help)->toContain('declared to return string', 'independently of checking its body', '$value /* name */')
        ->and($diagnostic->primary?->span->start->line)->toBe(5);
    $fixed = applyUsabilitySuggestion($source, $diagnostic);
    expect($fixed)->toBe(substr_replace($source, 'string ', strpos($source, '$value'), 0))
        ->and(usabilityFind(analyzeDiagnosticUsability(['src/CRLF.ppphp' => $fixed]), 'P2016'))->toHaveCount(1);
});

test('supported terminating guards establish facts with correct polarity', function (string $body): void {
    $diagnostics = analyzeDiagnosticUsability(['src/Guards.ppphp' => '<?php function stop(): never { exit; } function validate(mixed $value, bool $flag): string throws \\InvalidArgumentException { ' . $body . ' }']);
    expect(usabilityFind($diagnostics, 'P2016'))->toHaveCount(0);
})->with([
    'throw' => ['if (!is_string($value)) { throw new \\InvalidArgumentException(); } return $value;'],
    'early return' => ['if (!is_string($value)) { return "fallback"; } return $value;'],
    'never call' => ['if (!is_string($value)) { stop(); } return $value;'],
    'exit' => ['if (!is_string($value)) { exit; } return $value;'],
    'double' => ['if (!!is_string($value)) { return $value; } return "fallback";'],
    'triple' => ['if (!!!is_string($value)) { return "fallback"; } return $value;'],
    'compound disjunction' => ['if (!is_string($value) || !$flag) { return "fallback"; } return $value;'],
    'compound conjunction' => ['if (!(is_string($value) && $flag)) { return "fallback"; } return $value;'],
    'nested' => ['if ($flag) { if (!is_string($value)) { return "fallback"; } return $value; } return "other";'],
    'positive comparison' => ['if (is_string($value)) { return $value; } return "fallback";'],
]);

test('continuing caught invalidated and broad joined paths remain rejected', function (string $body): void {
    $diagnostics = analyzeDiagnosticUsability(['src/Unsafe.ppphp' => '<?php function replace(mixed &$input): void {} function validate(mixed $value, bool $flag): string { ' . $body . ' }']);
    expect(usabilityFind($diagnostics, 'P2016'))->toHaveCount(1);
})->with([
    'continuing' => ['if (!is_string($value)) { echo "not string"; } return $value;'],
    'caught' => ['try { if (!is_string($value)) { throw new \\InvalidArgumentException(); } } catch (\\InvalidArgumentException $e) {} return $value;'],
    'reassigned' => ['if (!is_string($value)) { return "fallback"; } $value = 42; return $value;'],
    'by reference' => ['if (!is_string($value)) { return "fallback"; } replace($value); return $value;'],
    'join' => ['if ($flag) { if (!is_string($value)) { return "fallback"; } } return $value;'],
    'conjunction' => ['if (!is_string($value) && $flag) { return "fallback"; } return $value;'],
]);

test('guards use resolved built-ins and never same-named project functions', function (string $prefix, string $call, bool $valid): void {
    $diagnostics = analyzeDiagnosticUsability(['src/Resolution.ppphp' => '<?php ' . $prefix . ' function validate(mixed $value): string { if (!' . $call . '($value)) { return "fallback"; } return $value; }']);
    expect(usabilityFind($diagnostics, 'P1001'))->toBe([])
        ->and(usabilityFind($diagnostics, 'P2016'))->toHaveCount($valid ? 0 : 1);
})->with([
    'fallback' => ['namespace App;', 'is_string', true],
    'qualified' => ['namespace App;', '\\is_string', true],
    'alias' => ['namespace App; use function is_string as stringValue;', 'stringValue', true],
    'shadow' => ['namespace App; function is_string(mixed $value): bool { return true; }', 'is_string', false],
    'imported shadow' => ['namespace Other; function is_string(mixed $value): bool { return true; } namespace App; use function Other\\is_string;', 'is_string', false],
]);

test('object generic and array predicates preserve more precise contracts', function (): void {
    $diagnostics = analyzeDiagnosticUsability(['src/Preserve.ppphp' => <<<'PPP'
<?php
class Box<T> { public function __construct(public T $value) {} }
function objectValue(Box<string> $box): string {
    if (!is_object($box)) { return ''; }
    return $box->value;
}
function arrayValue(array<string> $values): string {
    if (!is_array($values)) { return ''; }
    return $values[0];
}
function integerValue(mixed $value): int { if (!is_int($value)) { return 0; } return $value; }
function floatValue(mixed $value): float { if (!is_float($value)) { return 0.0; } return $value; }
function boolValue(mixed $value): bool { if (!is_bool($value)) { return false; } return $value; }
function nullableValue(?string $value): string { if (is_null($value)) { return ''; } return $value; }
PPP]);
    expect($diagnostics->errors)->toBe([]);
});

test('contract remedies distinguish permitted sets parents multiple interfaces and imported exceptions', function (string $declarations, string $inheritance, int $count, array $expected): void {
    $diagnostics = analyzeDiagnosticUsability(['src/Contracts.ppphp' => '<?php use InvalidArgumentException as InvalidItem; ' . $declarations . ' class Memory ' . $inheritance . ' { public function create(): void throws InvalidItem {} }']);
    $mismatches = usabilityFind($diagnostics, 'P4004');
    expect(usabilityFind($diagnostics, 'P1001'))->toBe([])->and($mismatches)->toHaveCount($count);
    foreach ($expected as $text) {
        expect((new ConsoleRenderer())->render($diagnostics))->toContain($text);
    }
})->with([
    'different set' => ['interface Repository { public function create(): void throws \\RuntimeException; }', 'implements Repository', 1, ['permits only \\RuntimeException and their subclasses', 'Repository::create()']],
    'parent' => ['class ParentRepository { public function create(): void {} }', 'extends ParentRepository', 1, ['ParentRepository::create()', 'Memory::create()']],
    'multiple' => ['interface Repository { public function create(): void; } interface Audit { public function create(): void; }', 'implements Repository, Audit', 2, ['Repository::create()', 'Audit::create()', 'Every other inherited contract']],
    'allowed narrower' => ['interface Repository { public function create(): void throws \\Exception; }', 'implements Repository', 0, []],
]);

test('changing the matching contract works but deleting throws leaves a checked escape', function (): void {
    $interface = '<?php interface Repository { public function create(): void; }';
    $implementation = '<?php class Memory implements Repository { public function create(): void throws \\InvalidArgumentException { throw new \\InvalidArgumentException(); } }';
    $files = ['src/Repository.ppphp' => $interface, 'src/Memory.ppphp' => $implementation];
    expect(usabilityFind(analyzeDiagnosticUsability($files), 'P4004'))->toHaveCount(1);
    $files['src/Repository.ppphp'] = str_replace(': void;', ': void throws \\InvalidArgumentException;', $interface);
    expect(analyzeDiagnosticUsability($files)->errors)->toBe([]);
    $files['src/Repository.ppphp'] = $interface;
    $files['src/Memory.ppphp'] = str_replace(' throws \\InvalidArgumentException', '', $implementation);
    $escapes = usabilityFind(analyzeDiagnosticUsability($files), 'P4003');
    expect($escapes)->toHaveCount(1)
        ->and($escapes[0]->help)->toContain('Catch \\InvalidArgumentException', 'throws \\InvalidArgumentException');
    $files['src/Memory.ppphp'] = '<?php class Memory implements Repository { public function create(): void { try { throw new \\InvalidArgumentException(); } catch (\\InvalidArgumentException $e) {} } }';
    expect(analyzeDiagnosticUsability($files)->errors)->toBe([]);
});

test('the original repository has the two root causes and preserves the original guard and storage condition', function (): void {
    $directory = dirname(__DIR__, 2) . '/Fixtures/DiagnosticUsability/';
    $files = [
        'src/Repository.ppphp' => file_get_contents($directory . 'Repository.ppphp'),
        'src/InMemoryRepository.ppphp' => file_get_contents($directory . 'InMemoryRepository.ppphp'),
    ];
    $diagnostics = analyzeDiagnosticUsability($files);
    expect(usabilityFind($diagnostics, 'P2002'))->toHaveCount(1)
        ->and(usabilityFind($diagnostics, 'P4004'))->toHaveCount(1)
        ->and(usabilityFind($diagnostics, 'P2003'))->toBe([])
        ->and(usabilityFind($diagnostics, 'P2016'))->toBe([]);
    $fixed = applyUsabilitySuggestion($files['src/InMemoryRepository.ppphp'], usabilityFind($diagnostics, 'P2002')[0]);
    expect($fixed)->toContain('string $id = $this->getId($item);', 'if (isset($this->items[$id]))', 'if (!is_string($id))');
    $files['src/InMemoryRepository.ppphp'] = $fixed;
    $after = analyzeDiagnosticUsability($files);
    expect(usabilityFind($after, 'P2002'))->toBe([])->and(usabilityFind($after, 'P4004'))->toHaveCount(1);
    $files['src/Repository.ppphp'] = str_replace('public function create(T $item): void;', 'public function create(T $item): void throws \\InvalidArgumentException;', $files['src/Repository.ppphp']);
    expect(analyzeDiagnosticUsability($files)->errors)->toBe([]);
});

test('real usability diagnostics retain complete console and JSON goldens', function (): void {
    $directory = dirname(__DIR__, 2) . '/Fixtures/DiagnosticUsability/';
    $diagnostics = analyzeDiagnosticUsability([
        'src/Repository.ppphp' => file_get_contents($directory . 'Repository.ppphp'),
        'src/InMemoryRepository.ppphp' => file_get_contents($directory . 'InMemoryRepository.ppphp'),
    ]);
    \Tests\Support\GoldenFile::assertMatches(dirname(__DIR__, 2) . '/Golden/Diagnostics/Console/usability.txt',
        (new ConsoleRenderer())->render($diagnostics, new \Atatusoft\Ppphp\Diagnostics\ConsoleRenderOptions(terminalWidth: 100)));
    \Tests\Support\GoldenFile::assertMatches(dirname(__DIR__, 2) . '/Golden/Diagnostics/Json/usability.json',
        (new \Atatusoft\Ppphp\Diagnostics\JsonRenderer())->render($diagnostics));
});

test('a rejected binding does not recover reads on an unrelated conditional path', function (): void {
    $diagnostics = analyzeDiagnosticUsability(['src/Paths.ppphp' => '<?php function example(bool $flag): void { if ($flag) { $id = "id"; echo $id; } else { echo $id; } echo $id; }']);
    expect(usabilityFind($diagnostics, 'P2002'))->toHaveCount(1)
        ->and(usabilityFind($diagnostics, 'P2003'))->toHaveCount(2);
});

test('parenthesized assignments require a separate declaration instead of invalid inserted syntax', function (): void {
    $diagnostic = usabilityFind(analyzeDiagnosticUsability(['src/Parentheses.ppphp' => '<?php function example(): void { ($value = 1); }']), 'P2002')[0];
    expect($diagnostic->help)->toContain('separate statement')->not->toContain('Write:');
});

test('catch inputs retain writes made after a guard without losing unaffected facts', function (string $body, bool $valid): void {
    $source = '<?php function replace(mixed &$value): void {} function example(mixed $value, bool $flag): string { if (!is_string($value)) { return ""; } ' . $body . ' return $value; }';
    $diagnostics = analyzeDiagnosticUsability(['src/Catch.ppphp' => $source]);
    expect(usabilityFind($diagnostics, 'P2016'))->toHaveCount($valid ? 0 : 1);
})->with([
    'assigned then caught' => ['try { $value = 42; throw new \\InvalidArgumentException(); } catch (\\InvalidArgumentException $e) {}', false],
    'conditional assignment' => ['try { if ($flag) { $value = 42; throw new \\InvalidArgumentException(); } } catch (\\InvalidArgumentException $e) {}', false],
    'reference then caught' => ['try { replace($value); throw new \\InvalidArgumentException(); } catch (\\InvalidArgumentException $e) {}', false],
    'unreachable write' => ['try { throw new \\InvalidArgumentException(); $value = 42; } catch (\\InvalidArgumentException $e) {}', true],
    'no write' => ['try { throw new \\InvalidArgumentException(); } catch (\\InvalidArgumentException $e) {}', true],
    'other callable scope' => ['try { \\Closure $callback = function (mixed $value): void { $value = 42; }; throw new \\InvalidArgumentException(); } catch (\\InvalidArgumentException $e) {}', true],
]);

test('optional control flow cannot leak rejected bindings into independent paths', function (string $body): void {
    $diagnostics = analyzeDiagnosticUsability(['src/Optional.ppphp' => '<?php function example(bool $flag, array<int> $values): void { ' . $body . ' echo $id; }']);
    expect(usabilityFind($diagnostics, 'P2002'))->toHaveCount(1)
        ->and(usabilityFind($diagnostics, 'P2003'))->toHaveCount(1);
})->with([
    'while' => ['while ($flag) { $id = 1; echo $id; }'],
    'for' => ['for (; $flag;) { $id = 1; echo $id; }'],
    'foreach' => ['foreach ($values as int $value) { $id = 1; echo $id; }'],
    'switch' => ['switch ($flag) { case true: { $id = 1; echo $id; break; } }'],
    'try' => ['try { if ($flag) { throw new \\RuntimeException(); } $id = 1; echo $id; } catch (\\RuntimeException $e) {}'],
    'short circuit' => ['$flag && ($id = 1);'],
    'ternary' => ['$flag ? ($id = 1) : 0;'],
    'match' => ['match ($flag) { true => ($id = 1), default => 0 };'],
    'do with break' => ['do { if ($flag) { break; } $id = 1; echo $id; } while ($flag);'],
]);
