# Typed Local Bindings

Typed local declarations are the first active ++PHP language extension. They are available at executable file scope, within namespace statement lists, and inside functions, methods, closures, arrow-function bodies, and property-hook bodies.

## Declaration Form

Every ordinary local declaration writes an explicit type and initializer:

~~~php
string $name = 'Andrew';
int $attempts = 0;
?int $result = null;
mixed $value = loadValue();
array $items = [];
readonly User $user = new User('Andrew');
~~~

The grammar is:

~~~text
readonly? type variable = expression ;
~~~

A declaration is mutable unless it starts with readonly. There is no inferred val or var form, and a missing initializer is invalid.

Bare assignment does not declare:

~~~php
$attempts = 0; // P2002
~~~

Declare first, then assign without repeating the type:

~~~php
int $attempts = 0;
$attempts = 4;
~~~

## Fixed Types

A local keeps its declared type. Later assignments do not widen it:

~~~php
int $attempts = 0;
$attempts = 4;       // valid
$attempts = 'four';  // P2009
~~~

?int accepts int or null. mixed deliberately accepts any value. Bare array is the broad PHP array type. Composite, generic, and typed-array forms such as int|string, Box<Item>, array<Item>, and array<string, Item> use the same structured type system.

The binding pass checks types it can resolve definitively: literals, broad arrays, closures, casts, exact new expressions, known local reads, and simple unary and arithmetic expressions. Project analysis checks resolved cross-file calls, hierarchy relationships, PHPDoc, members, returns, and nullability while retaining unknown results conservatively.

A declaration may be broader than its initial value: `string $summary = $name . ': done';`
and `Animal $animal = new Dog();` are valid when `Dog` extends `Animal`.
Generated PHPDoc does not turn these declarations into narrower-type assertions.

Closure literals and arrow functions are `Closure` values and can be stored in
either `Closure` or `callable` locals:

~~~php
readonly string $prefix = 'Order';
Closure $label = function (int $number) use ($prefix): string {
    return $prefix . ' #' . $number;
};
callable $shortLabel = fn (int $number): string => $prefix . ' #' . $number;
~~~

Their generated PHPDoc preserves parameter names, types, reference/variadic and
optional markers, and return types so PHP tools can check subsequent calls.
This also applies to typed `for` initializers and declarations inside `when` branches.
Fresh array literals containing closures also satisfy `array<callable>` contracts.
This also applies to a `when` result when every value-producing path returns a
fresh array, including nested `when` expressions and results overridden by `finally`.
Existing typed arrays remain invariant: an `array<Closure>` variable does not
become `array<callable>` merely because each current element is callable.

## Scope And Existing Bindings

Each source file has one executable variable scope shared by global and namespace statement lists. Functions and methods each have one local scope. Closures and arrow functions have separate scopes. An if, loop, try, namespace, or other ordinary nested block does not create a shadowing scope, so a second declaration with the same name is P2004.

Parameters, catch variables, $this, native property-hook bindings, and PHP superglobals already exist and may be read without a typed-local declaration.

A closure capture must resolve to a visible binding. The captured binding retains its type and readonly state. A readonly local cannot be captured by reference.

Bare foreach and destructuring targets must already be mutable bindings. A for or foreach header may instead declare a typed binding; see [typed loop bindings](typed-loop-bindings.md). Foreach by reference, global declarations, static local declarations, and explicit reference creation are unsupported in .ppphp files.

Bare assignment cannot introduce a ++PHP variable at file scope or callable scope. Entry scripts may use typed file-scope declarations, including declarations after imports and static include expressions.

## Readonly Storage

A readonly binding cannot be reassigned, incremented, decremented, unset, referenced, or structurally mutated:

~~~php
readonly int $count = 0;
$count++; // P2005

readonly array $items = [];
$items[] = 1; // P2006
sort($items); // P2006
~~~

Readonly applies to the local storage location, not recursively to an object:

~~~php
readonly User $user = new User('Andrew');

$user->rename('Lucy'); // allowed
$user->name = 'Lucy';  // governed by property rules
$user = new User('Lucy'); // P2005
~~~

A readonly local is rejected when passed to a by-reference parameter whose function or method declaration is unambiguously available in parsed project source. Broader argument and member relationships are checked by project analysis.

## Lowering

A successful build changes only the typed declaration prefix:

~~~php
readonly ?int $result = null;
~~~

becomes:

~~~php
/** @var int|null $result */ $result = null;
~~~

Lowering preserves the variable, initializer bytes, surrounding comments, newline style, Unicode, and every unaffected source byte. It removes the local type and local readonly syntax and records the applied edits in a generated-to-original source map. Generated output is ordinary PHP and must pass php -l.

Files without activated syntax are emitted byte-identically. Typed loop declarations use the same source-edit model and add PHPDoc immediately before the loop.

## Diagnostics

Typed-local diagnostics use:

~~~text
P2002  Assignment Cannot Declare Variable
P2003  Local Variable Is Not Declared
P2004  Duplicate Local Declaration
P2005  Readonly Local Cannot Be Reassigned
P2006  Readonly Local Cannot Be Mutated
P2007  Readonly Local Cannot Be Referenced
P2008  Initializer Is Not Assignable To Declared Type
P2009  Assignment Is Not Assignable To Declared Type
P2010  Unsupported Local Binding Position
~~~

Diagnostics point to original .ppphp spans and include related declaration labels when applicable.

Composite local diagnostics use P2030–P2032. Generic and typed-array diagnostics use P3002–P3016; P3001 remains reserved.

## Bindings Inside `when`

A `when` may initialize a typed local or supply an assignment value. Each branch creates a child analysis scope that imports visible outer bindings. Its declarations are available later in that branch and nested scopes, but not in siblings or after the expression. Sibling branches may reuse a local name; a branch may not shadow a visible outer local. Assignments to mutable outer bindings retain their fixed-type checks, while writes, references, unsets, or structural mutations through readonly outer storage remain invalid. Generated result temporaries are compiler-owned and do not alter the user binding model.
