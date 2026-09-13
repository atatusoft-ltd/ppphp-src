<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Frontend\PpphpParser;
use Atatusoft\Ppphp\Project\ProjectParseResult;
use Atatusoft\Ppphp\Semantic\SemanticAnalysisResult;
use Atatusoft\Ppphp\Semantic\SemanticAnalyzer;
use Atatusoft\Ppphp\Semantic\Type\AtomicType;
use Atatusoft\Ppphp\Semantic\Type\GenericType;
use Atatusoft\Ppphp\Semantic\Type\MemberTypeResolver;
use Atatusoft\Ppphp\Semantic\Type\TypeParameter;
use Atatusoft\Ppphp\Semantic\Type\TypedArrayType;
use Atatusoft\Ppphp\Semantic\Type\UnionType;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;
use Atatusoft\Ppphp\Support\Path;

/** @return array{ProjectParseResult, SemanticAnalysisResult} */
function analyzePostStageTwelveSource(string $contents): array
{
    $path = '/project/src/SemanticClosure.ppphp';
    $source = new SourceFile($path, 'src/SemanticClosure.ppphp', FileKind::Ppphp, $contents);
    $parse = (new PpphpParser())->parse($source);
    $key = Path::buildComparisonKey($path);
    $projectParse = new ProjectParseResult(
        $parse->parsedFile === null ? [] : [$key => $parse->parsedFile],
        [$key => $source],
        $parse->diagnostics,
    );

    return [$projectParse, (new SemanticAnalyzer())->analyze($projectParse)];
}

/** @return list<string> */
function resolvePostStageTwelveCodes(SemanticAnalysisResult $result): array
{
    return array_map(
        static fn (Diagnostic $diagnostic): string => $diagnostic->code->value,
        iterator_to_array($result->diagnostics),
    );
}

test('absolute types retain their identity inside namespaces and generic scopes', function (): void {
    [, $analysis] = analyzePostStageTwelveSource(<<<'PPP'
<?php
namespace {
    class T {}
    class Box<A> { public function __construct(public A $value) {} }
}
namespace Sample {
    class Holder<T> {
        public function copy(\T $global, T $parameter, \Closure $callback): \T {
            \T $absolute = $global;
            T $local = $parameter;
            array<\T> $items = [$global];
            \Box<\T> $box = new \Box($global);
            \Closure $read = $callback;
            return $absolute;
        }
    }
}
PPP);
    expect(resolvePostStageTwelveCodes($analysis))->toBe([]);
    $bindings = [];
    foreach ($analysis->models as $model) {
        foreach ($model->bindings->bindings as $binding) {
            $bindings[$binding->name] = $binding->type->semanticType;
        }
    }
    expect($bindings['$absolute'])->toBeInstanceOf(AtomicType::class)
        ->and($bindings['$absolute']->renderPhpDoc())->toBe('\\T')
        ->and($bindings['$local'])->toBeInstanceOf(TypeParameter::class)
        ->and($bindings['$items']->renderPhpDoc())->toBe('list<\\T>')
        ->and($bindings['$box']->renderPhpDoc())->toBe('\\Box<\\T>')
        ->and($bindings['$read']->renderPhpDoc())->toBe('\\Closure');
    $method = $analysis->symbols->findClass('Sample\\Holder')->findMethod('copy');
    expect($method->parameters[0]->type->semanticType)->toBeInstanceOf(AtomicType::class)
        ->and($method->returnType->semanticType)->toBeInstanceOf(AtomicType::class);
});

test('owner-qualified type parameters survive symbols locals loops and callbacks', function (): void {
    [, $analysis] = analyzePostStageTwelveSource(<<<'PPP'
<?php
class Box<T>
{
    public function __construct(public T $value) {}

    public function select<U>(T $classValue, U $methodValue): U
    {
        T $local = $classValue;
        array<T> $items = [$local];
        foreach ($items as T $item) {}
        callable $callback = fn (U $value): U => $value;
        return $callback($methodValue);
    }
}
class Other<T> {}
PPP);

    $box = $analysis->symbols->findClass('Box');
    $other = $analysis->symbols->findClass('Other');
    $select = $box?->findMethod('select');
    $classParameter = $box?->genericDeclaration?->parameters[0] ?? null;
    $methodParameter = $select?->genericDeclaration?->parameters[0] ?? null;
    $propertyType = $box?->findProperty('value')?->type?->semanticType;
    $methodClassType = $select?->parameters[0]->type?->semanticType;
    $methodType = $select?->parameters[1]->type?->semanticType;
    $bindings = [];

    foreach ($analysis->models as $model) {
        foreach ($model->bindings->bindings as $binding) {
            $bindings[$binding->name] = $binding->type->semanticType;
        }
    }

    expect($classParameter)->toBeInstanceOf(TypeParameter::class)
        ->and($methodParameter)->toBeInstanceOf(TypeParameter::class)
        ->and($propertyType)->toBeInstanceOf(TypeParameter::class)
        ->and($propertyType?->canonical)->toBe($classParameter?->canonical)
        ->and($methodClassType?->canonical)->toBe($classParameter?->canonical)
        ->and($methodType?->canonical)->toBe($methodParameter?->canonical)
        ->and($bindings['$local']?->canonical)->toBe($classParameter?->canonical)
        ->and($bindings['$item']?->canonical)->toBe($classParameter?->canonical)
        ->and($classParameter?->canonical)->not->toBe($methodParameter?->canonical)
        ->and($classParameter?->canonical)->not->toBe($other?->genericDeclaration?->parameters[0]->canonical)
        ->and($propertyType?->renderPhpDoc())->toBe('T')
        ->and(resolvePostStageTwelveCodes($analysis))->not->toContain(
            DiagnosticCode::TypeDoesNotExist->value,
            DiagnosticCode::LoopBindingTypeDoesNotMatch->value,
        );
});

test('shared member resolution substitutes generic properties methods and inherited applications', function (): void {
    [, $analysis] = analyzePostStageTwelveSource(<<<'PPP'
<?php
final class Product {}
class Box<T>
{
    public function __construct(public T $value) {}
    public function get(): T { return $this->value; }
    public function values(): array<T> { return [$this->value]; }
}
final class ProductBox extends Box<Product> {}
function valid(): void
{
    Box<Product> $box = new Box(new Product());
    Product $property = $box->value;
    Product $method = $box->get();
    array<Product> $values = $box->values();
    ProductBox $inherited = new ProductBox(new Product());
    Product $fromParent = $inherited->get();
}
PPP);

    $resolver = new MemberTypeResolver($analysis->symbols);
    $receiver = new GenericType(new AtomicType('Box'), [new AtomicType('Product')]);
    $property = $resolver->resolvePropertyType($receiver, 'value');
    $method = $resolver->resolveMethodReturnType($receiver, 'get');
    $values = $resolver->resolveMethodReturnType($receiver, 'values');
    $inherited = $resolver->resolveMethodReturnType(new AtomicType('ProductBox'), 'get');

    expect($property->canonical)->toBe('product')
        ->and($method->canonical)->toBe('product')
        ->and($values)->toBeInstanceOf(TypedArrayType::class)
        ->and($values->renderPhpDoc())->toBe('list<Product>')
        ->and($inherited->canonical)->toBe('product')
        ->and(resolvePostStageTwelveCodes($analysis))->not->toContain(
            DiagnosticCode::InitializerNotAssignableToDeclaredType->value,
            DiagnosticCode::TypeDoesNotExist->value,
            DiagnosticCode::StaticAnalysisError->value,
        );
});

test('shared member resolution requires every reachable union arm to provide a member', function (): void {
    [, $analysis] = analyzePostStageTwelveSource(<<<'PPP'
<?php
final class Product {}
final class Service {}
class Box<T>
{
    public function __construct(public T $value) {}
    public function get(): T { return $this->value; }
}
final class Other {}
PPP);

    $resolver = new MemberTypeResolver($analysis->symbols);
    $productBox = new GenericType(new AtomicType('Box'), [new AtomicType('Product')]);
    $serviceBox = new GenericType(new AtomicType('Box'), [new AtomicType('Service')]);
    $supported = new UnionType([$productBox, $serviceBox]);
    $incomplete = new UnionType([$productBox, new AtomicType('Other')]);

    expect($resolver->resolveMethodReturnType($supported, 'get')->canonical)->toBe('product|service')
        ->and($resolver->resolvePropertyType($supported, 'value')->canonical)->toBe('product|service')
        ->and($resolver->resolveMethodReturnType($incomplete, 'get')->isUnknown)->toBeTrue()
        ->and($resolver->resolvePropertyType($incomplete, 'value')->isUnknown)->toBeTrue();
});

test('shared member resolution distinguishes declaring self from the original static receiver', function (): void {
    [, $analysis] = analyzePostStageTwelveSource(<<<'PPP'
<?php
final class Product {}
class Base<T>
{
    public function asSelf(): self { return $this; }
    public function with(): static { return $this; }
}
final class Child<T> extends Base<T> {}
function valid(Child<Product> $child): void
{
    Base<Product> $base = $child->asSelf();
    Child<Product> $copy = $child->with();
    Child<Product> $chain = $child->with()->with();
}
PPP);

    $resolver = new MemberTypeResolver($analysis->symbols);
    $receiver = new GenericType(
        new AtomicType('Child'),
        [new AtomicType('Product')],
    );

    expect($resolver->resolveMethodReturnType($receiver, 'asSelf')->canonical)
        ->toBe('base<product>')
        ->and($resolver->resolveMethodReturnType($receiver, 'with')->canonical)
        ->toBe('child<product>')
        ->and(resolvePostStageTwelveCodes($analysis))->not->toContain(
            DiagnosticCode::InitializerNotAssignableToDeclaredType->value,
            DiagnosticCode::TypeDoesNotExist->value,
        );
});

test('dependent applied bounds substitute earlier arguments and remain nominal', function (): void {
    [, $valid] = analyzePostStageTwelveSource(<<<'PPP'
<?php
interface CartItem { public int $id { get; } }
final class Product {}
final class Item<T> implements CartItem { public function __construct(public int $id, public T $value) {} }
class Cart<TProduct, TItem : Item<TProduct>> {}
class CapabilityCart<TItem : CartItem> {}
function valid(Cart<Product, Item<Product>> $cart, CapabilityCart<Item<Product>> $capability): void {}
PPP);
    [, $wrongApplication] = analyzePostStageTwelveSource(<<<'PPP'
<?php
interface CartItem { public int $id { get; } }
final class Product {}
final class Service {}
final class Item<T> implements CartItem { public function __construct(public int $id, public T $value) {} }
class Cart<TProduct, TItem : Item<TProduct>> {}
function invalid(Cart<Product, Item<Service>> $cart): void {}
PPP);
    [, $structuralOnly] = analyzePostStageTwelveSource(<<<'PPP'
<?php
interface CartItem { public int $id { get; } }
final class Structural { public int $id { get; } }
class Cart<TItem : CartItem> {}
function invalid(Cart<Structural> $cart): void {}
PPP);
    [, $rawBound] = analyzePostStageTwelveSource(<<<'PPP'
<?php
class Item<T> {}
class Cart<TItem : Item> {}
PPP);

    expect(resolvePostStageTwelveCodes($valid))->not->toContain(DiagnosticCode::TypeArgumentDoesNotSatisfyBound->value)
        ->and(resolvePostStageTwelveCodes($wrongApplication))->toContain(DiagnosticCode::TypeArgumentDoesNotSatisfyBound->value)
        ->and(resolvePostStageTwelveCodes($structuralOnly))->toContain(DiagnosticCode::TypeArgumentDoesNotSatisfyBound->value)
        ->and(resolvePostStageTwelveCodes($rawBound))->toContain(DiagnosticCode::GenericTypeArgumentsAreRequired->value);
});

test('collection builtins preserve generic values while accurately tracking list shape', function (): void {
    [, $valid] = analyzePostStageTwelveSource(<<<'PPP'
<?php
class Collection<T>
{
    public function normalize(array<T> $items): array<T>
    {
        array<int, T> $filtered = array_filter($items, fn (T $item): bool => true);
        array<T> $values = array_values($filtered);
        return $values;
    }
}
PPP);
    [, $invalid] = analyzePostStageTwelveSource(<<<'PPP'
<?php
function invalid(array<string> $items): void
{
    array<string> $filtered = array_filter($items, fn (string $item): bool => true);
}
PPP);

    expect(resolvePostStageTwelveCodes($valid))->not->toContain(
        DiagnosticCode::OperationWouldBreakListShape->value,
        DiagnosticCode::UncheckedCallBoundary->value,
    )
        ->and(resolvePostStageTwelveCodes($invalid))->toContain(DiagnosticCode::OperationWouldBreakListShape->value)
        ->not->toContain(DiagnosticCode::UncheckedCallBoundary->value);
});

test('this is an applied self type in instance scopes and is absent from static scopes', function (): void {
    [, $valid] = analyzePostStageTwelveSource(<<<'PPP'
<?php
class Box<T>
{
    public function __construct(public T $value) {}
    public function get(): T { return $this->value; }
    public function callback(): callable { return fn (): T => $this->get(); }
    public function closure(): callable { return function (): T { return $this->get(); }; }
}
PPP);
    [, $invalid] = analyzePostStageTwelveSource(<<<'PPP'
<?php
class Box<T>
{
    public static function invalid(): void { echo $this; }
    public function invalidClosure(): callable { return static fn (): T => $this->value; }
}
PPP);

    expect(resolvePostStageTwelveCodes($valid))->not->toContain(
        DiagnosticCode::LocalVariableNotDeclared->value,
        DiagnosticCode::PropertyDoesNotExist->value,
        DiagnosticCode::MethodDoesNotExist->value,
    )
        ->and(array_count_values(resolvePostStageTwelveCodes($invalid))[DiagnosticCode::LocalVariableNotDeclared->value] ?? 0)
        ->toBeGreaterThanOrEqual(2);
});
