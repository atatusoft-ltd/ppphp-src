<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Frontend\Ast\NodeId;
use Atatusoft\Ppphp\Frontend\Normalization\NormalizationEdit;
use Atatusoft\Ppphp\Frontend\Normalization\NormalizationPlan;
use Atatusoft\Ppphp\Frontend\Normalization\SourceMask;
use Atatusoft\Ppphp\Frontend\PpphpParser;
use Atatusoft\Ppphp\Frontend\PhpParserDiagnosticMapper;
use Atatusoft\Ppphp\Frontend\Token\Enumerations\TokenKind;
use Atatusoft\Ppphp\Frontend\Token\Lexer;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;

function createStageFourSource(string $contents, string $name = 'Feature.ppphp'): SourceFile
{
    return new SourceFile('/project/src/' . $name, 'src/' . $name, FileKind::Ppphp, $contents);
}

/** @return list<string> */
function resolveStageFourCodes(Atatusoft\Ppphp\Frontend\ParseResult $result): array
{
    return array_map(
        static fn (Atatusoft\Ppphp\Diagnostics\Diagnostic $diagnostic): string => $diagnostic->code->value,
        iterator_to_array($result->diagnostics),
    );
}

test('the ++PHP lexer retains exact bytes spans trivia and unicode positions', function (): void {
    $source = createStageFourSource("<?php\r\n// 🙂\r\nstring \$name = 'Andrew';\r\n");
    $stream = (new Lexer())->tokenize($source);
    $tokens = $stream->tokens;
    $variable = array_values(array_filter($tokens, static fn ($token): bool => $token->text === '$name'))[0];
    $comment = array_values(array_filter($tokens, static fn ($token): bool => $token->kind === TokenKind::Comment))[0];

    expect(implode('', array_map(static fn ($token): string => $token->text, $tokens)))->toBe($source->contents)
        ->and($tokens[0]->start)->toBe(0)
        ->and($tokens[array_key_last($tokens)]->end)->toBe($source->length)
        ->and($comment->isTrivia)->toBeTrue()
        ->and($variable->span->text)->toBe('$name')
        ->and($variable->line)->toBe(3)
        ->and($variable->column)->toBe(8);
});

test('comments strings interpolation heredoc and nowdoc remain lexically opaque', function (): void {
    $contents = <<<'PPP'
<?php
// string $fake = when (true) { return 1; } else { return 2; };
$a = 'array<int> throws Failure';
$b = "when {$a}";
$c = <<<TEXT
class Box<T> {}
TEXT;
$d = <<<'TEXT'
readonly string $fake = 'x';
TEXT;
PPP;
    $result = (new PpphpParser())->parse(createStageFourSource($contents));

    expect($result->isSuccessful)->toBeTrue()
        ->and($result->parsedFile?->extensionSyntax->isEmpty)->toBeTrue()
        ->and($result->parsedFile?->normalizedSource->contents)->toBe($contents);
});

test('normalization preserves comments inside erased syntax at their original offsets', function (string $body): void {
    $source = createStageFourSource("<?php\r\n" . $body);
    $result = (new PpphpParser())->parse($source);
    $normalized = $result->parsedFile?->normalizedSource->contents;
    $comment = '/* Keep 🙂 this explanation. */';
    expect($result->isSuccessful)->toBeTrue()
        ->and($normalized)->not->toBeNull()
        ->and(strlen($normalized))->toBe($source->length)
        ->and(strpos($normalized, $comment))->toBe(strpos($source->contents, $comment));
})->with([
    'typed local' => 'int /* Keep 🙂 this explanation. */ $value = 7;',
    'readonly local' => 'readonly /* Keep 🙂 this explanation. */ int $value = 7;',
    'for binding' => 'for (int /* Keep 🙂 this explanation. */ $n = 0; $n < 1; ++$n) {}',
    'foreach binding' => 'foreach ([1] as int /* Keep 🙂 this explanation. */ $n) {}',
    'generic declaration' => 'function identity</* Keep 🙂 this explanation. */ T>(T $value): T { return $value; }',
    'generic application' => 'function show(array</* Keep 🙂 this explanation. */ int> $values): void {}',
    'throws clause' => 'function fail(): never throws /* Keep 🙂 this explanation. */ Error { throw new Error(); }',
]);

test('syntax masking distinguishes comments from quoted text and owned placeholders', function (): void {
    $text = 'array<"/* quoted text */"> /* retained */' . "\r\n" . '// retained line';
    $masked = SourceMask::erase($text);
    expect(strlen($masked))->toBe(strlen($text))
        ->and($masked)->toContain('/* retained */')->toContain('// retained line')->toContain("\r\n")
        ->not->toContain('quoted text')->not->toContain('array');
    $placeholder = SourceMask::erase($text, preserveComments: false);
    expect(strlen($placeholder))->toBe(strlen($text))
        ->and($placeholder)->not->toContain('retained')->not->toContain('quoted text')
        ->and(strpos($placeholder, "\r\n"))->toBe(strpos($text, "\r\n"));
});

test('ordinary PHP-only ppphp source has an identity plan and byte-identical normalization', function (): void {
    $contents = "<?php\n#[Attribute]\nfinal readonly class Example { public function value(): int { return 1; } }\n";
    $result = (new PpphpParser())->parse(createStageFourSource($contents));
    $parsed = $result->parsedFile;

    expect($result->isSuccessful)->toBeTrue()
        ->and($parsed?->extensionSyntax->isEmpty)->toBeTrue()
        ->and($parsed?->normalizationPlan->edits)->toBe([])
        ->and($parsed?->normalizedSource->contents)->toBe($contents)
        ->and($parsed?->sourceMap->resolveNormalizedOffset(12))->toBe(12)
        ->and($parsed?->sourceMap->resolveOriginalOffset(12))->toBe(12);
});

test('ordinary php files bypass extension recognition', function (): void {
    $contents = '<?php function f() { string $value = "x"; }';
    $source = new SourceFile('/project/src/Feature.php', 'src/Feature.php', FileKind::Php, $contents);
    $result = (new PpphpParser())->parse(
        $source,
        Atatusoft\Ppphp\Frontend\Enumerations\ParseMode::Php,
    );

    expect(resolveStageFourCodes($result))->toContain(DiagnosticCode::InvalidPhpSyntax->value)
        ->not->toContain(DiagnosticCode::TypedLocalSyntaxNotActive->value);
});

test('typed locals retain readonly type variable initializer and exact spans', function (): void {
    $contents = <<<'PPP'
<?php
function example(): void
{
    readonly array<string, int> $scores = ['Andrew' => 10];
    ?int $result = null;
    mixed $value = loadValue();
}
PPP;
    $result = (new PpphpParser())->parse(createStageFourSource($contents));
    $locals = $result->parsedFile?->extensionSyntax->typedLocals ?? [];

    expect($result->parsedFile)->not->toBeNull()
        ->and($locals)->toHaveCount(3)
        ->and($locals[0]->readonlySpan?->text)->toBe('readonly')
        ->and($locals[0]->type->text)->toBe('array<string, int>')
        ->and($locals[0]->variableSpan->text)->toBe('$scores')
        ->and($locals[0]->initializerSpan->text)->toBe("['Andrew' => 10]")
        ->and($locals[0]->span->text)->toContain('readonly array<string, int>')
        ->and(resolveStageFourCodes($result))->not->toContain(DiagnosticCode::GenericSyntaxNotActive->value)
        ->not->toContain(DiagnosticCode::TypedLocalSyntaxNotActive->value)
        ->not->toContain(DiagnosticCode::InvalidPhpSyntax->value);
});

test('typed loop declarations retain exact frontend structure and normalize only their prefixes', function (): void {
    $contents = <<<'PPP'
<?php
function iterate(array $items): void
{
    for (readonly int $index = 0; $index < 1; ) {
    }

    foreach ($items as string $key => mixed $value) {
    }
}
PPP;
    $result = (new PpphpParser())->parse(createStageFourSource($contents));
    $parsed = $result->parsedFile;
    $initializer = $parsed?->extensionSyntax->typedForInitializers[0] ?? null;
    $bindings = $parsed?->extensionSyntax->typedForeachBindings ?? [];

    expect($result->isSuccessful)->toBeTrue()
        ->and($initializer)->not->toBeNull()
        ->and($initializer?->readonlySpan?->text)->toBe('readonly')
        ->and($initializer?->type->text)->toBe('int')
        ->and($initializer?->variableSpan->text)->toBe('$index')
        ->and($initializer?->initializerSpan->text)->toBe('0')
        ->and($bindings)->toHaveCount(2)
        ->and(array_map(static fn ($binding): string => $binding->position->value, $bindings))->toBe(['key', 'value'])
        ->and(array_map(static fn ($binding): string => $binding->type->text, $bindings))->toBe(['string', 'mixed'])
        ->and($parsed?->normalizedSource->contents)->toContain('for (             $index = 0;')
        ->and($parsed?->normalizedSource->contents)->toContain('foreach ($items as        $key =>       $value)')
        ->and(resolveStageFourCodes($result))->toBe([]);
});

test('typed loop diagnostics are targeted and typed arrays remain recognized Stage 8 syntax', function (): void {
    $multiple = (new PpphpParser())->parse(createStageFourSource(<<<'PPP'
<?php
function invalid(): void
{
    for (int $first = 0, int $second = 0; false; ) {
    }
}
PPP));
    $readonly = (new PpphpParser())->parse(createStageFourSource(<<<'PPP'
<?php
function invalid(array $items): void
{
    foreach ($items as readonly mixed $item) {
    }
}
PPP));
    $typedArray = (new PpphpParser())->parse(createStageFourSource(<<<'PPP'
<?php
function inactive(): void
{
    array<string> $items = [];
    foreach ($items as string $item) {
    }
}
PPP));

    expect(resolveStageFourCodes($multiple))->toContain(DiagnosticCode::MultipleTypedForInitializersNotSupported->value)
        ->and(resolveStageFourCodes($multiple))->not->toContain(DiagnosticCode::InvalidPhpSyntax->value)
        ->and(resolveStageFourCodes($readonly))->toContain(DiagnosticCode::ReadonlyForeachBindingNotSupported->value)
        ->and(resolveStageFourCodes($readonly))->not->toContain(DiagnosticCode::InvalidPhpSyntax->value)
        ->and($typedArray->parsedFile?->extensionSyntax->typedForeachBindings)->toHaveCount(1)
        ->and(resolveStageFourCodes($typedArray))->not->toContain(DiagnosticCode::GenericSyntaxNotActive->value);
});

test('typed declarations are recognized in executable file and namespace scopes but not as properties', function (): void {
    $contents = <<<'PPP'
<?php
namespace {
    use Demo\Person;
    Person $first = new Person();
}
namespace One {
    readonly int $second = 2;
}
namespace Two {
    string $third = 'three';
    final class Example {
        public string $property;
    }
}
PPP;
    $result = (new PpphpParser())->parse(createStageFourSource($contents));
    $locals = $result->parsedFile?->extensionSyntax->typedLocals ?? [];

    expect($result->isSuccessful)->toBeTrue()
        ->and($locals)->toHaveCount(3)
        ->and(array_map(static fn ($local): string => $local->variableSpan->text, $locals))
        ->toBe(['$first', '$second', '$third'])
        ->and($result->parsedFile?->normalizedSource->contents)->toContain('       $first = new Person();')
        ->and($result->parsedFile?->normalizedSource->contents)->toContain('                 $second = 2;');
});

test('properties promoted parameters parameters return types and native readonly stay ordinary PHP', function (): void {
    $contents = <<<'PPP'
<?php
final readonly class Example
{
    public string $name = 'Andrew';
    public function __construct(public readonly string $id) {}
    public function map(string $value): string { return $value; }
}
PPP;
    $result = (new PpphpParser())->parse(createStageFourSource($contents));

    expect($result->isSuccessful)->toBeTrue()
        ->and($result->parsedFile?->extensionSyntax->typedLocals)->toBe([]);
});

test('native static globals catch foreach destructuring and closure capture are not typed locals', function (): void {
    $contents = <<<'PPP'
<?php
function example(array $items, $captured): void
{
    static $count = 0;
    global $service;
    Example::$count = 1;
    foreach ($items as $item) { [$first] = [$item]; }
    try {} catch (RuntimeException $error) {}
    $closure = function () use ($captured) { return $captured; };
}
PPP;
    $result = (new PpphpParser())->parse(createStageFourSource($contents));

    expect($result->isSuccessful)->toBeTrue()
        ->and($result->parsedFile?->extensionSyntax->typedLocals)->toBe([]);
});

test('generic declarations references bounds typed arrays and nested shift closers normalize safely', function (): void {
    $contents = <<<'PPP'
<?php
class Box<T : Entity> extends Base<T>
{
    public function map<U>(array<string, Box<U>> $values): Box<U>
    {
        return $values[0];
    }
}
PPP;
    $result = (new PpphpParser())->parse(createStageFourSource($contents));
    $parsed = $result->parsedFile;

    expect($parsed)->not->toBeNull()
        ->and($parsed?->extensionSyntax->genericDeclarations)->toHaveCount(2)
        ->and($parsed?->extensionSyntax->genericTypes)->not->toBeEmpty()
        ->and($parsed?->normalizedSource->contents)->toContain('class Box             extends Base')
        ->and($parsed?->normalizedSource->contents)->not->toContain('Box<U>')
        ->and(resolveStageFourCodes($result))->not->toContain(DiagnosticCode::GenericSyntaxNotActive->value)
        ->not->toContain(DiagnosticCode::InvalidPhpSyntax->value);
});

test('all approved generic declaration owners are indexed', function (): void {
    $contents = <<<'PPP'
<?php
interface Repository<T, U : Entity> {}
trait Stores<T> {}
function identity<T>(T $value): T { return $value; }
final class Service<T>
{
    public function map<U>(U $value): U { return $value; }
}
PPP;
    $result = (new PpphpParser())->parse(createStageFourSource($contents));
    $declarations = $result->parsedFile?->extensionSyntax->genericDeclarations ?? [];

    expect($declarations)->toHaveCount(5)
        ->and(array_map(static fn ($node): string => $node->declarationKind, $declarations))->toBe([
            'interface',
            'trait',
            'function',
            'class',
            'method',
        ])
        ->and($declarations[0]->parameters)->toHaveCount(2);
});

test('comparison and shift operators remain ordinary expressions', function (): void {
    $contents = '<?php function compare($a, $b, $mask) { $x = $a < $b; $y = $a >= $b; return $mask >> 2; }';
    $result = (new PpphpParser())->parse(createStageFourSource($contents));

    expect($result->isSuccessful)->toBeTrue()
        ->and($result->parsedFile?->extensionSyntax->genericTypes)->toBe([])
        ->and($result->parsedFile?->normalizedSource->contents)->toBe($contents);
});

test('comparison operators inside attributes remain ordinary expressions', function (): void {
    $contents = '<?php #[Example(limit: Foo < Bar)] function compare(): void {}';
    $result = (new PpphpParser())->parse(createStageFourSource($contents));

    expect($result->isSuccessful)->toBeTrue()
        ->and($result->parsedFile?->extensionSyntax->genericTypes)->toBe([]);
});

test('unsupported generic declaration and runtime positions are diagnosed precisely', function (string $contents): void {
    $result = (new PpphpParser())->parse(createStageFourSource($contents));

    expect(resolveStageFourCodes($result))->toContain(DiagnosticCode::UnsupportedExtensionSyntax->value)
        ->not->toContain(DiagnosticCode::InvalidPhpSyntax->value);
})->with([
    'anonymous class' => ['<?php $value = new class<T> {};'],
    'enum' => ['<?php enum Value<T> {}'],
    'call site' => ['<?php function f() { return collect<string>([]); }'],
]);

test('unsupported binding and throws positions are not silently accepted', function (string $contents): void {
    $result = (new PpphpParser())->parse(createStageFourSource($contents));

    expect(resolveStageFourCodes($result))->toContain(DiagnosticCode::UnsupportedExtensionSyntax->value)
        ->not->toContain(DiagnosticCode::InvalidPhpSyntax->value);
})->with([
    'val binding' => ['<?php function f() { val $value = 1; }'],
    'var binding' => ['<?php function f() { var $value = 1; }'],
    'static typed binding' => ['<?php function f() { static int $value = 1; }'],
    'global typed binding' => ['<?php function f() { global int $value = 1; }'],
    'anonymous throws' => ['<?php $f = function (): void throws Failure {};'],
]);

test('typed arrays validate arity and reject readonly inside arguments precisely', function (string $type, string $offending): void {
    $contents = '<?php function f() { ' . $type . ' $value = []; }';
    $result = (new PpphpParser())->parse(createStageFourSource($contents));
    $diagnostic = $result->diagnostics->errors[0];

    expect($result->parsedFile)->toBeNull()
        ->and($diagnostic->code)->toBe(DiagnosticCode::InvalidExtensionSyntax)
        ->and($diagnostic->primary?->span->text)->toContain($offending);
})->with([
    'empty' => ['array<>', '<'],
    'three arguments' => ['array<string, int, bool>', '<string, int, bool>'],
    'readonly key' => ['array<readonly string, int>', 'readonly'],
    'readonly value' => ['array<string, readonly int>', 'readonly'],
]);

test('throws clauses retain all declared error spans and normalize for PHP parsing', function (): void {
    $contents = <<<'PPP'
<?php
interface Repository
{
    public function load(string $id): User throws \App\UserNotFound, StorageFailure;
}
PPP;
    $result = (new PpphpParser())->parse(createStageFourSource($contents));
    $clause = $result->parsedFile?->extensionSyntax->throwsClauses[0] ?? null;

    expect($clause)->not->toBeNull()
        ->and($clause?->keywordSpan->text)->toBe('throws')
        ->and($clause?->ownerKind)->toBe('method')
        ->and($clause?->ownerNameSpan->text)->toBe('load')
        ->and($clause?->ownerDeclarationSpan->text)->toContain('function load')
        ->and($clause?->errorTypes)->toHaveCount(2)
        ->and($clause?->separatorSpans)->toHaveCount(1)
        ->and(resolveStageFourCodes($result))->not->toContain(DiagnosticCode::ThrowsSyntaxNotActive->value)
        ->not->toContain(DiagnosticCode::InvalidPhpSyntax->value);
});

test('when expressions retain conditional and final branches with nested bodies', function (): void {
    $contents = <<<'PPP'
<?php
function label(int $score): string
{
    return when ($score >= 80) {
        return nested([fn () => ['value' => 1]]);
    } else when ($score >= 50) {
        return 'Pass';
    } else {
        return 'Fail';
    };
}
PPP;
    $result = (new PpphpParser())->parse(createStageFourSource($contents));
    $when = $result->parsedFile?->extensionSyntax->whenExpressions[0] ?? null;

    expect($when)->not->toBeNull()
        ->and($when?->branches)->toHaveCount(2)
        ->and($when?->branches[0]->conditionSpan->text)->toBe('$score >= 80')
        ->and($when?->elseBranch->bodySpan->text)->toContain("return 'Fail';")
        ->and($result->parsedFile?->normalizedSource->contents)->toContain('return null')
        ->and(resolveStageFourCodes($result))->not->toContain(DiagnosticCode::WhenSyntaxNotActive->value)
        ->not->toContain(DiagnosticCode::InvalidPhpSyntax->value);
});

test('a callable named when remains ordinary PHP', function (): void {
    $contents = '<?php function when(int $value): int { return $value; } function f(): int { return when(1); }';
    $result = (new PpphpParser())->parse(createStageFourSource($contents));

    expect($result->isSuccessful)->toBeTrue()
        ->and($result->parsedFile?->extensionSyntax->whenExpressions)->toBe([]);
});

test('nested when expressions retain their hierarchy and one effective outer normalization edit', function (): void {
    $contents = <<<'PPP'
<?php
function f(): int
{
    return when (true) {
        return when (false) { return 1; } else { return 2; };
    } else {
        return 3;
    };
}
PPP;
    $result = (new PpphpParser())->parse(createStageFourSource($contents));

    $expressions = $result->parsedFile?->extensionSyntax->whenExpressions ?? [];

    expect($expressions)->toHaveCount(2)
        ->and($expressions[0]->parentId)->toBeNull()
        ->and($expressions[0]->depth)->toBe(0)
        ->and($expressions[1]->parentId)->toBe($expressions[0]->id)
        ->and($expressions[1]->depth)->toBe(1)
        ->and(resolveStageFourCodes($result))->not->toContain(DiagnosticCode::WhenSyntaxNotActive->value)
        ->and($result->parsedFile?->normalizationPlan->edits)->toHaveCount(1);
});

test('malformed extension syntax takes precedence over inactive diagnostics', function (string $contents): void {
    $result = (new PpphpParser())->parse(createStageFourSource($contents));

    expect($result->parsedFile)->toBeNull()
        ->and(resolveStageFourCodes($result))->toContain(DiagnosticCode::InvalidExtensionSyntax->value)
        ->not->toContain(DiagnosticCode::InvalidPhpSyntax->value);
})->with([
    'typed local initializer' => ['<?php function f() { string $value = ; }'],
    'readonly local explicit type' => ['<?php function f() { readonly $value = 1; }'],
    'typed local variable' => ['<?php function f() { string = "value"; }'],
    'typed local equals' => ['<?php function f() { string $value; }'],
    'typed local semicolon' => ['<?php function f() { string $value = "value" }'],
    'generic close' => ['<?php function f(array<string $values) {}'],
    'throws type' => ['<?php function f(): void throws {}'],
    'when final else' => ['<?php function f() { return when (true) { return 1; }; }'],
    'when parentheses' => ['<?php function f() { return when true { return 1; } else { return 2; }; }'],
]);

test('a syntactically valid when in an unsupported position reaches semantic validation', function (): void {
    $result = (new PpphpParser())->parse(createStageFourSource(
        '<?php function f() { echo when (true) { return 1; } else { return 2; }; }',
    ));

    expect($result->parsedFile)->not->toBeNull()
        ->and($result->parsedFile?->extensionSyntax->whenExpressions)->toHaveCount(1)
        ->and(resolveStageFourCodes($result))->not->toContain(DiagnosticCode::UnsupportedExtensionSyntax->value)
        ->not->toContain(DiagnosticCode::InvalidPhpSyntax->value);
});

test('normalization plans are deterministic preserve CRLF and reject overlap', function (): void {
    $source = createStageFourSource("<?php\r\nabcdef\r\n");
    $firstSpan = $source->createSpan(7, 10);
    $secondSpan = $source->createSpan(10, 13);
    $firstId = NodeId::create('first', $firstSpan);
    $secondId = NodeId::create('second', $secondSpan);
    $plan = new NormalizationPlan($source, [
        new NormalizationEdit($secondSpan, '   ', $secondId),
        new NormalizationEdit($firstSpan, '   ', $firstId),
    ]);
    $normalized = $plan->normalize();

    expect($plan->edits[0]->owner)->toBe($firstId)
        ->and(substr_count($normalized->contents, "\r\n"))->toBe(2)
        ->and($normalized->sourceMap->resolveOriginalOffset(9))->toBe(9)
        ->and($normalized->sourceMap->resolveNormalizedOffset(9))->toBe(9)
        ->and($plan->normalize()->contents)->toBe($normalized->contents);

    expect(fn () => new NormalizationPlan($source, [
        new NormalizationEdit($source->createSpan(7, 11), '    ', $firstId),
        new NormalizationEdit($source->createSpan(10, 13), '   ', $secondId),
    ]))->toThrow(DomainException::class, 'overlap');
});

test('parser positions inside a placeholder map to its owning original span', function (): void {
    $source = createStageFourSource('<?php whenxxxx;');
    $span = $source->createSpan(6, 14);
    $plan = new NormalizationPlan($source, [
        new NormalizationEdit($span, 'null    ', NodeId::create('when-expression', $span)),
    ]);
    $error = new PhpParser\Error('Synthetic placeholder error', [
        'startFilePos' => 9,
        'endFilePos' => 9,
        'startLine' => 1,
        'endLine' => 1,
    ]);
    $diagnostic = (new PhpParserDiagnosticMapper())->map($error, $source, $plan->normalize()->sourceMap);

    expect($diagnostic->primary?->span)->toBe($span);
});
