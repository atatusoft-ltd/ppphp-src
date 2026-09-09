# ++PHP MVP End-to-End Development Plan

> **Repository:** `atatusoft-ltd/ppphp-src`
> **Branch:** `develop`
> **Status:** Stages 0–12, post-Stage-12 semantic closure, Stages 13A–13D, the post-Stage-13C completion gate, and Stage 14A are complete; Stage 14B publication is next
> **Last updated:** 2026-09-02

## 1. Purpose

++PHP is a PHP source compiler and language superset that adds a deliberately small set of compile-time features while continuing to target the official PHP runtime.

The MVP should prove the following product proposition:

> Write stronger, more expressive PHP-shaped source, validate it before runtime, and emit clean ordinary PHP that can be deployed like any other PHP application.

++PHP is not intended to compete directly with projects that compile PHP to C++ and native machine code. Its initial value is incremental adoption:

```text
Existing PHP project
    ↓
Add ++PHP as a development dependency
    ↓
Keep ordinary .php files unchanged
    ↓
Introduce selected .ppphp files
    ↓
Compile and statically validate the project
    ↓
Deploy generated ordinary PHP
```

The MVP must be useful on its own. Future features may make the language more ambitious, but they must not delay delivery of the initial focused compiler.

---

## 2. Repository Conventions And Stage Status

The repository is organized around these compiler modules:

```text
src/
├── Analysis/
├── Cli/
├── Compiler/
├── Config/
├── Diagnostics/
├── Frontend/
├── Interop/
├── Project/
├── Semantic/
├── Source/
├── Support/
├── Transpilation/
└── Versioning/
```

Implementation status is determined from the latest `develop` branch, not from this architectural summary. Before each stage, inspect the current repository, verify the preceding stage's acceptance criteria, and close any remaining gaps before moving forward.

The accepted organization and naming conventions are:

```text
- Concrete classes live directly in their owning module or subdomain.
- Interfaces live under Interfaces/.
- Enumerations live under Enumerations/.
- Traits live under Traits/.
- Attributes live under Attributes/.
- Exceptions live under Exceptions/.
- Abstract classes live under AbstractClasses/.
- Do not create Classes/ directories.
```

Pipeline pass classes use:

```text
<Verb><Object>Pass
```

Examples:

```text
DeclareSymbolsPass
ResolveNamesPass
CheckTypesPass
CheckBindingsPass
CheckGenericTypesPass
CheckErrorEffectsPass
EraseGenericTypesPass
LowerLocalDeclarationsPass
LowerWhenExpressionsPass
EraseThrowsClausesPass
```

Passes expose a common `execute()` operation. Orchestrators use role names such as `SemanticAnalyzer`, `PhpLowerer`, and `PhpStanProjectAnalyzer`.

---

## 3. Product Contract

### 3.1 Core Promise

A developer working from the repository can run:

```bash
composer install
php bin/ppphp init
php bin/ppphp check
php bin/ppphp build
```

The equivalent `composer require --dev atatusoft/ppphp` installation
workflow begins with the public package release in Stage 14.

The build output must:

```text
- Be valid PHP.
- Run on the official PHP runtime.
- Require no ++PHP runtime library unless the source explicitly imports a normal PHP package.
- Contain no ++PHP-only syntax.
- Preserve useful generic and checked-error metadata as PHPDoc.
- Be deterministic.
- Pass php -l.
- Preserve executable Composer bootstraps when source files move into configured output.
```

### 3.2 Normative Semantic Rule

PHP runtime behavior remains authoritative wherever ++PHP has not explicitly added a compile-time rule or source transformation.

++PHP may:

```text
- Reject unsafe or insufficiently typed code.
- Add compile-time-only generic types.
- Enforce checked-error propagation.
- Enforce explicitly typed local declarations and readonly local bindings.
- Lower when expressions into ordinary PHP control flow.
```

++PHP must not silently redefine:

```text
- PHP object identity.
- PHP array behavior.
- PHP property mutation.
- PHP exception propagation.
- PHP truthiness or comparison semantics.
- PHP reference counting or garbage collection.
- PHP extension behavior.
```

### 3.3 Quarterly Release Identity

++PHP uses quarterly CalVer with exactly three canonical forms:

```text
Stable               YYYY.Q.R
Release Candidate    YYYY.Q.R-rc-N
Development          dev-YYYY.Q.R
```

`YYYY` is the four-digit year, `Q` is 1–4, `R` is the positive release
increment within that quarter, and `N` is the positive candidate increment for
one exact release core. `Compiler::VERSION` owns the selected source identity.
Development is a separate channel from Release Candidate.

Stable is the default acquisition channel. Release Candidate and Development
require an explicit channel or exact version, and selection never falls back
across channels. Canonical tags equal the exact version without a `v` prefix.
Ordinary compiler commands perform no automatic update checks or release-catalog
network activity. See [Quarterly CalVer And Release Channels](versioning.md) and
[ADR 0002](decisions/0002-quarterly-calver-and-release-channels.md).

## 4. MVP Scope

The MVP includes the following product capabilities.

### 4.1 Erased Generics

First-class generic syntax for:

```text
- Classes
- Interfaces
- Traits
- Functions
- Methods
- Generic type references
```

Generic information is checked at compile time and erased from PHP syntax. Compatible PHPDoc is emitted for PHPStan, IDEs, and ordinary PHP consumers.

There is no runtime reification, specialization, or monomorphization in the MVP.

### 4.2 Natively Typed Arrays

++PHP adds first-class generic array types:

```php
array<string> $names = [];
array<string, int> $scores = [];
readonly array<Person> $people = [];
array $phpArray = [];
```

The forms mean:

```text
array<T>       Generic list of T
array<K, V>    Generic map / associative array from K to V
array           Broad PHP-style array
```

The generic arguments are checked by ++PHP and erased from emitted PHP syntax. Compatible `list<T>` and `array<K, V>` PHPDoc is generated.

### 4.3 Strict Project-Wide Types

For `.ppphp` files, the compiler enforces:

```text
- Explicit parameter, property, return, and ordinary local-variable types.
- No implicit local-variable declarations.
- No accidental implicit mixed.
- Explicit nullability.
- Compile-time argument and return checking.
- Initialized-before-use checking.
- All-path return validation.
- Unknown member diagnostics.
- Exact scalar typing beyond PHP's caller-controlled strict_types behavior.
```

Explicit broad types such as `mixed` and bare `array` remain available when the developer deliberately chooses them.

### 4.4 Explicitly Typed Local Declarations And Readonly Local Bindings

```php
string $name = "Andrew";
readonly string $creator = "Andrew";
int $attempts = 0;
?int $result = null;
```

Every ordinary local variable declaration requires an explicit type and an initializer. A local declaration is mutable by default. Prefixing it with `readonly` prevents reassignment and mutation through that local storage location.

Bare assignment never declares a variable:

```php
$attempts = 0; // Error: Missing Local Variable Type
```

There is no inferred local declaration form in the MVP.

### 4.5 `when` / `else when` / `else`

```php
string $label = when ($score >= 80) {
    return "Excellent";
} else when ($score >= 50) {
    return "Pass";
} else {
    return "Fail";
};
```

This is a value-producing ++PHP expression lowered into ordinary PHP control flow.

The MVP does not include setup clauses or control-flow `finally`.

### 4.6 Checked Errors

```php
function loadUser(string $id): User
    throws UserNotFound, StorageFailure
{
}
```

A checked error must be caught or declared in the enclosing callable's `throws` clause.

At runtime, checked errors remain ordinary PHP exceptions.

### 4.7 Mixed-Project Support

A project may contain both:

```text
.php
.ppphp
```

Ordinary PHP remains unchanged. ++PHP files receive the full ++PHP language contract and are emitted as `.php` files into the configured output directory.

---

## 5. Explicit MVP Non-Goals

```text
- PHP-to-C++ or PHP-to-native compilation
- A custom runtime or virtual machine
- Reified generics
- Generic specialization or monomorphization
- Runtime generic reflection
- Explicit generic call-site arguments
- Variance annotations
- Generic defaults
- Higher-kinded types
- Macros
- Async/await
- Pattern matching
- Setup clauses or control-flow `finally`
- A new object or memory model
- Ownership or borrow checking
- Deep immutability
- Local type inference
- Per-element or per-type-argument `readonly` modifiers
- Automatic runtime boundary guards
- Framework-specific compiler plugins
- Laravel, Symfony, or Doctrine magic emulation
- An LSP
- A formatter
- A Composer plugin
- PHAR packaging
- Native launcher packaging
- Native-performance claims
- Support for every historical PHP version
- Automatic conversion of an entire PHP project to ++PHP
```

Features may be discussed or reserved syntactically without being added to the MVP release gate.

---

## 6. Source and Output Contract

### 6.1 Source Files

++PHP source files use:

```text
.ppphp
```

A ++PHP file remains PHP-shaped and includes the normal PHP opening tag:

```php
<?php
```

An ordinary typed PHP file should be capable of being renamed from `.php` to `.ppphp`, after which stricter ++PHP semantic rules may require changes.

### 6.2 Output Files

```text
src/Domain/UserService.ppphp
    ↓
build/ppphp/Domain/UserService.php
```

The output must:

```text
- Preserve the namespace and relative source path.
- Contain no ++PHP-only syntax.
- Contain declare(strict_types=1).
- Preserve useful source comments and descriptive PHPDoc.
- Add deterministic generated PHPDoc where required.
- Rebase the project-oriented Composer bootstrap against each emitted file.
- Pass php -l.
- Require no compiler runtime.
```

### 6.3 Plain PHP Files

Plain `.php` files:

```text
- Are never rewritten in the source tree.
- Are copied byte-for-byte to corresponding selected build paths.
- Are available for symbol and type analysis.
- May contribute existing PHPDoc metadata.
- May be enriched by ++PHP stub files.
- Remain directly executable by PHP.
```

### 6.4 Atomic Builds

`ppphp build` must be atomic:

```text
1. Validate the project.
2. Compile into a temporary output tree.
3. Validate every generated PHP file.
4. Replace the previous successful output only after all files succeed.
5. Keep the previous successful output when compilation fails.
```

A failed build must never leave a partially updated application.

---

## 7. CLI Surface

The human-facing MVP command surface is:

```text
ppphp init
ppphp composer:configure [--dry-run]
ppphp check [path]
ppphp build [path]
ppphp clean [--dry-run]
ppphp dump:ast <file>
ppphp list
ppphp --version
```

Compiler-backed editor integrations use these internal, versioned standard-input
protocol commands:

```text
ppphp editor:definition
ppphp editor:semantic-tokens
ppphp editor:diagnostics
```

They are not substitutes for the human-facing `check` or `build` workflows and
do not constitute a standalone language server.

### 7.1 `init`

```text
- Creates ppphp.json when missing.
- Creates configured output, cache, and stub directories where appropriate.
- Prints required Composer autoload guidance.
- Does not silently rewrite composer.json in the MVP.
- Supports --no-interaction.
```

### 7.2 `check`

```text
- Parses selected .ppphp files and relevant project-owned .php files.
- Runs ++PHP semantic checks where applicable.
- Runs the pinned PHPStan analysis backend.
- Emits no production PHP.
- Exits non-zero when errors are present.
```

### 7.3 `build`

```text
- Performs the complete check pipeline for the selected files.
- Compiles selected .ppphp files and copies selected .php files only after checks succeed.
- Never rewrites project source files.
- Validates generated PHP.
- Writes source maps and a build manifest.
```

### 7.4 Command Selection Semantics

++PHP does not use an entry-point configuration in the MVP. PHP applications commonly have multiple executable scripts and autoloaded declarations, so configured source roots define project ownership and the optional command path acts as a selection filter.

The final command behavior is:

```text
ppphp check
    Check the complete project under all configured source roots.

ppphp check <directory>
    Recursively check project-owned .ppphp and .php files in that subtree.

ppphp check <file>
    Perform a focused check of that project-owned .ppphp or .php file,
    loading required project context for resolution.

ppphp build
    Compile every project-owned .ppphp file and copy every project-owned .php
    file under all configured source roots.

ppphp build <directory>
    Recursively compile .ppphp and copy .php files in that subtree.

ppphp build <file.ppphp>
    Build that one focused ++PHP source file.

ppphp build <file.php>
    Copy that one focused ordinary PHP source file byte-for-byte.
```

Selection rules:

```text
- Relative paths resolve from the project root.
- A selected path must remain within a configured source root.
- Directory selection respects configured exclusions.
- Plain .php files are analysis context and copied build outputs when selected.
- A focused build outputs exactly the selected .ppphp or .php files; it does not
  implicitly output unselected or transitive dependencies.
- Use pathless build for complete deployable project output.
- Project discovery may index unselected files and load dependencies needed
  to resolve selected targets.
- Unrelated unselected files should not block a focused command, except for
  project-global conflicts that make the selected target ambiguous or unsafe.
```

The dependency graph supports declaration resolution, analysis ordering, invalidation, and diagnostics. It is not a tree-shaking mechanism and must never reduce a pathless build below all project-owned `.ppphp` files.

### 7.5 `clean`

```text
- Removes the complete configured output and cache roots after validating that
  both are safe compiler-owned project paths.
- Refuses to remove a path outside the project root.
- Never deletes a source directory.
```

### 7.6 `dump:ast`

A developer-oriented command that requires one explicit file and displays:

```text
- ++PHP extension nodes.
- Normalized PHP AST information.
- Source spans.
- Generated mappings.
```

### 7.7 `composer:configure`

```text
- Reads root Composer metadata as data and never runs Composer or project PHP.
- Preserves source PSR-4, classmap, and files mappings under extra.ppphp.
- Projects application runtime mappings to the configured generated output.
- Supports multiple source roots, custom output and vendor paths, --dry-run,
  deterministic repeated application, and atomic conflict-safe writes.
```

### 7.8 Editor Protocol Commands

`editor:definition` resolves project symbols from one bounded unsaved `.ppphp`
document and UTF-8 byte offset. `editor:semantic-tokens` classifies one bounded
unsaved PHP or ++PHP document. Both use versioned JSON envelopes, execute no
project code or PHPStan backend, and write no cache or production output.

`editor:diagnostics` checks one unsaved PHP or ++PHP target with optional open
document overlays using the existing compiler-owned analyzer and normal JSON
diagnostic items. Its bounded version 1 protocol reports compiler-core coverage
explicitly, preserves original buffer coordinates, and performs no source,
cache, lock or output writes. Saved-file `check` and `build` retain supplemental
PHPStan analysis. See [Editor Protocol](editor-protocol.md) for ownership,
new/deleted-buffer, limits and stale-response rules.

The optional `--server` transport retains a bounded worker with ordered request
IDs, client-discard cancellation, explicit recycling, and single-shot fallback.
Each request reloads project inputs; only verified platform modules and immutable
derived metadata are reused. Warm measurements do not imply cold-start or
end-to-end editor latency guarantees.

---

## 8. Configuration

The initial configuration should remain small. A released configuration uses an immutable, versioned remote schema URL:

```json
{
    "$schema": "https://github.com/atatusoft-ltd/ppphp-src/releases/download/<release-tag>/ppphp.schema.json",
    "source": [
        "src"
    ],
    "output": "build/ppphp",
    "cache": ".ppphp-cache",
    "targetPhpVersion": "8.4",
    "stubs": [
        "stubs"
    ],
    "exclude": [
        "vendor",
        "build",
        ".ppphp-cache"
    ]
}
```

Configuration principles:

```text
- ++PHP strictness is not a PHPStan rule level.
- .ppphp has one defined language contract.
- PHPStan remains an implementation detail.
- Source, output, cache, and stub paths are relative to the project root.
- Output and cache paths may not overlap source paths.
- Unknown configuration properties produce diagnostics.
- The target PHP version is distinct from the host PHP version running the compiler.
- Root Composer config.platform.php takes precedence over targetPhpVersion; require.php constrains the selection. Conflicting or unsupported targets fail before analysis, without silently selecting the compiler host. This does not qualify additional platform capabilities or complete the FI-1 runtime/dependency gates.
```

### 8.1 Schema Distribution Policy

The configuration's `$schema` property is an editor and tooling hint. The compiler validates configuration through its bundled schema and implementation rules; it must not fetch the remote schema during normal `init`, `check`, `build`, or `clean` operations.

Schema references must follow these rules:

```text
- Do not reference a path under vendor/ from generated project configuration.
- Do not reference mutable develop, main, latest, or unversioned release URLs.
- Every published Stable, Release Candidate, and Development artifact owns the
  schema URL under its exact canonical version and matching exact Git tag.
- A packaged ppphp release writes the immutable schema URL corresponding to its
  exact compiler release.
- Before a public website exists, publish ppphp.schema.json as an asset of
  the exact immutable GitHub release and reference that versioned asset.
- Once a public website exists, prefer a canonical versioned URL such as
  https://<public-site>/schemas/<schema-version>/ppphp.schema.json.
- The website path must remain immutable for that schema version; a separate
  convenience latest URL may exist for browsing but must not be written into
  committed project configuration.
- An untagged development checkout omits the instance-level $schema hint until
  an immutable artifact for its exact Development release exists. It must not
  point at a mutable branch or a nonexistent release.
```

The schema document itself should declare:

```text
- Its JSON Schema dialect through its own root $schema keyword.
- A canonical, versioned $id matching the published schema identity.
```

Release automation must publish the schema artifact together with the compiler release and verify that the generated `ppphp.json` points to the matching schema version.

The former development template's local `vendor/` schema reference was removed
in Stage 3. Until the first immutable schema artifact exists,
development-generated configuration omits the instance-level `$schema`
property rather than pointing to a mutable or nonexistent URL.

PHP 8.4 is the initial compiler host and output target baseline.

---

## 9. Compiler Architecture

The compiler should use `nikic/php-parser` for ordinary PHP parsing and printing. ++PHP should own only the syntax and semantics it adds to PHP.

### 9.1 Pipeline

```text
Project configuration
        ↓
File discovery
        ↓
Source manager
        ↓
++PHP-aware tokenization
        ↓
++PHP extension syntax parsing
        ↓
Extension syntax index
        ↓
Normalization plan
        ↓
Valid analysis PHP
        ↓
PHP AST
        ↓
++PHP semantic passes
        ↓
Analysis-PHP emission
        ↓
Pinned PHPStan analysis
        ↓
Diagnostic mapping
        ↓
Production lowering passes
        ↓
Production PHP emission
        ↓
php -l validation
        ↓
Build manifest and source maps
```

### 9.2 Two-Layer Frontend

A standard PHP parser cannot directly parse:

```php
readonly string $name = "Andrew";
string $city = "Lusaka";
array<string> $names = [];
array<string, int> $scores = [];
class Box<T> {}
function load(): User throws Failure {}
when (...) { ... }
```

Writing a complete PHP parser solely to add these syntax families would make the MVP unnecessarily large. The frontend should therefore have two layers.

#### ++PHP Extension Layer

Responsible for:

```text
- Explicitly typed ordinary local declarations
- readonly local declaration modifiers
- Generic declarations and references
- Natively typed array forms array<T> and array<K, V>
- throws clauses
- when expressions
- Exact original source spans
- Source-to-normalized mappings
```

#### PHP Parser Layer

Responsible for:

```text
- Ordinary declarations
- Expressions and statements
- Namespaces and imports
- Classes, interfaces, traits, and enums
- Native PHP property and class readonly syntax
- Attributes
- Closures
- PHP control flow
- Comments and ordinary PHP parse errors
```

### 9.3 No Regex Architecture

Regular expressions must not drive source transformation.

The extension frontend must be:

```text
- Token-aware
- Nesting-aware
- Comment-aware
- String-aware
- Interpolation-aware
- Heredoc/nowdoc-aware
- Context-aware
```

### 9.4 Semantic Model Boundary

The ++PHP semantic model owns:

```text
- Local binding mutability and declared type
- ++PHP generic parameter declarations
- Generic source types
- Checked-error declarations and effects
- when branch structure
- Original source spans
- Lowering metadata
- Imported PHP boundary metadata
```

PHPStan supplements the compiler-owned model with:

```text
- Flow-sensitive PHP type checking
- PHP argument and return checking
- PHPDoc generic substitution
- PHP inheritance rules
- Additional PHP call-site and try/catch flow analysis
```

The compiler owns project selection and symbols, native ++PHP semantics,
checked-error effects, generic structure, typed-array rules, diagnostic identity,
source mapping, and all output. PHPStan remains a replaceable backend and never
defines the language contract.

---

## 10. PHPStan Integration Contract

PHPStan is used in two independent roles:

```text
1. Check the ++PHP compiler implementation.
2. Analyse normalized PHP generated from ++PHP user projects.
```

The governing rule is:

> PHPStan is a pinned and replaceable analysis backend, not the ++PHP language specification.

Use:

```text
Analysis/Interfaces/ProjectAnalyzer
Analysis/PhpStan/PhpStanProjectAnalyzer
Analysis/PhpStan/PhpStanProcessRunner
Analysis/PhpStan/PhpStanResultParser
Analysis/PhpStan/PhpStanDiagnosticMapper
```

Maintain separate configurations:

```text
phpstan.neon.dist
    Checks the ++PHP compiler source.

resources/phpstan/ppphp.neon
    Checks generated analysis PHP.
```

Users must receive ++PHP diagnostics against original `.ppphp` source, never generated paths or raw PHPStan implementation terminology in normal mode.

---

## 11. Feature Contracts

### 11.1 Explicitly Typed Local Declarations And Readonly Local Bindings

Supported ordinary local declaration forms:

```php
string $name = "Andrew";
readonly string $creator = "Andrew";
int $attempts = 0;
?int $result = null;
mixed $value = loadValue();
```

The grammar is conceptually:

```text
local-variable-declaration
    ::= readonly? type variable = expression ;
```

Normative rules:

```text
- Every ordinary local declaration has an explicit type.
- Every ordinary local declaration has an initializer in the MVP.
- There is no inferred mutable or inferred readonly declaration form.
- A declaration without readonly creates a mutable local binding.
- A declaration with readonly creates a readonly local binding.
- Bare assignment never declares a variable.
- Later assignment must be assignable to the fixed declared type.
- The declared type never widens because of a later assignment.
- Null is assignable only when the written type admits null.
- Explicit broad types such as mixed remain valid.
```

Examples:

```php
int $attempts = 0;
$attempts = 4;      // Valid
$attempts = null;   // Error
$attempts = "N/A";  // Error

?int $result = 0;
$result = null;     // Valid

readonly string $name = "Andrew";
$name = "Lucy";    // Error
```

A readonly local rejects every operation that can replace or mutate its stored value, including simple and compound assignment, increment/decrement, `unset`, assignment by reference, and mutation through a by-reference parameter.

For objects, readonly applies to the local binding rather than recursively freezing the referenced object:

```php
readonly User $user = new User("Andrew");

$user = new User("Lucy"); // Error
$user->name = "Lucy";      // Governed by the property's PHP/++PHP rules
```

Properties remain PHP property declarations. ++PHP does not add a second member-variable declaration model; native PHP property types, visibility, and `readonly` remain authoritative for properties.

#### Typed Loop Bindings

++PHP extends PHP loop headers with explicit local declarations:

```php
for (int $i = 0; $i < 10; ++$i) {
}

array<string> $names = [];

foreach ($names as string $name) {
}

array<string, int> $scores = [
    'peter' => 90,
    'john' => 100,
];

foreach ($scores as string $key => int $value) {
}

array $varietyOfThings = [];

foreach ($varietyOfThings as mixed $value) {
}

foreach ($varietyOfThings as mixed $key => mixed $value) {
}
```

The MVP contract is:

```text
- A newly declared foreach binding uses exact canonical type matching.
- array<T> provides int keys and T values.
- array<K, V> provides K keys and V values.
- broad array provides mixed keys and mixed values.
- Existing bare foreach targets use ordinary assignment compatibility.
- Loop bindings use PHP-compatible enclosing variable scope.
- A foreach binding may be uninitialized after a zero-iteration loop.
- Typed-array verification is active and uses the structured semantic type
  model introduced in Stage 8.
- Hierarchy-aware collection assignment and loop-binding widening are
  post-MVP enhancements.
```

Stage 5 resolves the remaining binding positions as follows:

```text
- Parameters, catch variables, $this, native property-hook bindings, and superglobals are existing bindings.
- Closure captures must resolve an outer binding and retain its type and mutability.
- A typed foreach target declares a new binding; a bare foreach target and
  destructuring target must already be mutable local bindings.
- foreach by reference is rejected.
- Global declarations and static local declarations are unsupported in .ppphp files.
- Top-level bare assignment cannot introduce a local.
```

These rules prevent PHP-style implicit binding from being accepted accidentally.

Lowering removes the local type and local `readonly` modifier while preserving the declared type as generated PHPDoc where required:

```php
readonly Animal $animal = new Dog();
```

becomes conceptually:

```php
/** @var Animal $animal */
$animal = new Dog();
```

### 11.2 Natively Typed Arrays

++PHP supports these array types:

```text
array<T>       Generic list of T
array<K, V>    Generic map / associative array from K to V
array           Broad PHP-style array
```

Examples:

```php
array<string> $names = ["matthew", "mark", "luke", "john"];
array<string, int> $scores = ["john" => 92, "mary" => 96];
readonly array<Person> $people = [new Person("Lucas", 34)];
array $phpArray = [];
readonly array $readonlyPhpArray = [];
```

Rules:

```text
- array<T> has list semantics and every value must be assignable to T.
- array<K, V> has map/associative semantics; keys must be compatible with
  PHP's array-key domain and values must be assignable to V.
- bare array retains PHP's broad mixed list/map capability.
- Nullable forms remain explicit, for example ?array<string, int>.
- Array types may appear in local, parameter, return, property, and nested
  generic type positions.
- Typed arrays are invariant in the MVP.
- An empty array literal is assignable when it introduces no conflicting
  key or value type.
```

PHP key coercion, including numeric-string keys, follows observable PHP runtime behavior and is covered by Stage 8 semantic and runtime tests.

`readonly` is a declaration modifier, not a type constructor. It may apply to the array variable or property as a whole, but not to its key type, value type, or atomic elements:

```php
readonly array<string, int> $scores = []; // Valid
array<readonly string, int> $scores = []; // Invalid
array<string, readonly int> $scores = []; // Invalid
```

A readonly array cannot be reassigned, appended to, written through an offset, unset through an offset, or passed to a mutating by-reference operation. Readonly array storage is not deep object immutability: an object contained in the array remains governed by that object's own rules.

Erasure preserves PHP's native `array` type and emits PHPDoc:

```php
array<string>             -> array with @var/@param/@return list<string>
array<string, int>        -> array with @var/@param/@return array<string, int>
array                     -> native broad array without invented element types
```

### 11.3 Strict Types

A `.ppphp` file initially requires:

```text
- Typed function and method parameters
- Explicit return types
- Typed properties
- Explicitly typed ordinary local declarations
- Explicit nullable types
- Initializers for ordinary local bindings
- No undefined local variables
- No implicit mixed in ++PHP-authored declarations
- No unknown properties or methods
- No dynamic properties
- No missing returns
- No incompatible scalar coercions
- No assignment incompatible with a local binding's declared type
```

Reject in `.ppphp` for the MVP:

```text
- eval
- Variable variables
- Dynamic include or require paths
- Assignment by reference
- foreach by reference
- Return by reference
- Dynamic property creation
```

Generated files still contain `declare(strict_types=1);`, but project-wide analysis is the primary guarantee. Explicit `mixed` and bare `array` remain deliberate escape hatches rather than accidental inference results.

### 11.4 Composite Types

The MVP supports union types, intersection types, and valid PHP 8.4 disjunctive
normal form through one structured semantic model. Composite types are valid in
explicit local and loop declarations, parameters, returns, properties, generic
arguments, and nested typed arrays. Nullable shorthand is canonicalized as a
union with `null`; union and intersection member order does not change semantic
identity.

Validate duplicate or redundant members, illegal builtin intersections,
`mixed` combinations, non-return `void`/`never`, nullable shorthand mixed with
unions, and DNF parentheses before assignability checks. PHP-native composites
remain native in generated signatures and properties. Erased local, loop,
generic, and typed-array forms retain the complete composite in PHPDoc.

### 11.5 Checked Errors

Recommended grammar:

```php
function loadUser(string $id): User
    throws UserNotFound, StorageFailure
{
}
```

Core rules:

```text
1. A directly thrown exception contributes its static type to the callable's error set.
2. Calling a ++PHP callable contributes its declared error set.
3. A matching catch removes the handled type and its subtypes.
4. Remaining checked errors must be declared.
5. An override may narrow but may not widen the parent's error set.
6. Interface and abstract declarations may include throws.
7. PHP's Error hierarchy remains unchecked.
8. Runtime PHP errors and fatal conditions are outside the checked-error guarantee.
9. throws is erased into generated @throws metadata.
10. A callable without throws promises that no known checked error escapes; it does not promise PHP can never produce a Throwable.
```

Ordinary PHP signatures, PHPDoc, and ++PHP stubs enrich boundary metadata. A genuinely dynamic or unresolved callable produces an `Unchecked Call Boundary` warning rather than a false guarantee.

### 11.6 Erased Generics

Support generic classes, interfaces, traits, functions, and methods:

```php
class Box<T> {}
interface Repository<T> {}
trait Stores<T> {}

function identity<T>(T $value): T
{
    return $value;
}
```

Support a single upper bound:

```php
class Repository<T : Entity> {}
```

Support type uses such as:

```php
Repository<User>
Box<string>
iterable<int, User>
array<User>
array<string, User>
```

Emit compatible `@template`, `@extends`, `@implements`, `@use`, `@param`, `@return`, and `@var` metadata.

Reject:

```text
- new T()
- T::class
- instanceof T
- Static storage using class-level type parameters
- Explicit call-site type arguments
- Generic anonymous classes
- Runtime reification
- Specialization
- Variance
- Generic defaults
```

Type parameters are invariant. Native ++PHP generic syntax is authoritative over conflicting PHPDoc in `.ppphp` source.

### 11.7 `when` Expressions

```php
string $label = when ($condition) {
    return $value;
} else when ($otherCondition) {
    return $otherValue;
} else {
    return $fallback;
};
```

Rules:

```text
- when is value-producing.
- A final else is mandatory.
- Every reachable branch yields or terminates.
- return yields from when, not the enclosing function.
- A throw branch has type never.
- Branch bindings stay branch-local.
- Conditions evaluate left-to-right and at most once.
- break and continue are rejected inside value-producing branches.
```

Required MVP positions are local initializers, assignment right-hand sides, return operands, direct call arguments, and array elements.

Do not lower through closures. Use deterministic, collision-free temporary variables and ordinary PHP control flow.

---

## 12. Development Stages

| Stage | Outcome |
|---:|---|
| 0 | Repository and language contract normalized |
| 1 | Working CLI, configuration, source model, and diagnostics |
| 2 | Ordinary PHP frontend and no-op PHP build |
| 3 | Project discovery, Composer awareness, and mixed source sets |
| 4 | ++PHP extension lexer/parser and source mappings |
| 5 | Typed local declarations and readonly local bindings |
| 6 | Strict typing and PHPStan analysis backend |
| 7 | Typed loop bindings and checked errors |
| 8 | Build-aware Composer integration, composite types, erased generics, and natively typed arrays |
| 9 | `when` expressions |
| 10 | Production emission, manifests, and atomic builds |
| 11 | Full mixed-project and interoperability validation |
| 12 | Diagnostic and developer-experience polish |
| 13 | Incremental performance, security, and hardening |
| 14 | Public MVP release |

Stages are completed in order. Stages 0–12, the post-Stage-12 semantic closure, Stages 13A–13D, and Stage 14A are complete; Stage 14B publication is next. A later stage must not excuse an incomplete earlier acceptance criterion.

---

## Stage 0 — Normalize the Foundation

### Goal

Turn the current scaffold into a reliable, documented PHP compiler project without implementing language features.

### Work

Update Composer metadata:

```text
- Add an explicit host PHP requirement.
- Change package type from project to library.
- Register bin/ppphp.
- Add nikic/php-parser.
- Add symfony/process.
- Add test autoloading and scripts.
- Keep Laravel Prompts only if init uses it.
```

Repository cleanup:

```text
- Complete AGENTS.md and README.md.
- Complete ppphp.json.dist and phpstan.neon.dist.
- Correct phpunit.xml.
- Ignore build/ and .ppphp-cache/.
- Remove placeholder tests.
- Add resources/phpstan/ppphp.neon.
- Add resources/schema/ppphp.schema.json.
- Add core design documentation.
- Add CI.
```

Naming alignment:

```text
ThrowClause.php → ThrowsClause.php
CheckErrorsPass.php → CheckErrorEffectsPass.php
CheckGenericsPass.php → CheckGenericTypesPass.php
Analyzer.php → SemanticAnalyzer.php
```

Do not add empty future-oriented classes merely to match an architectural sketch.

### Acceptance Criteria

```bash
composer validate --strict
composer install
composer analyse
composer test
php bin/ppphp --version
php bin/ppphp --help
```

All succeed. No language feature is implemented.

---

## Stage 1 — CLI, Configuration, Source Model, and Diagnostics

### Goal

Create the operational shell every later feature uses.

### Work

Implement the application and commands, project config loading, source files and spans, structured diagnostics, console and JSON renderers, stable diagnostic ranges, stable exit codes, and guarded internal-error handling.

Diagnostic families:

```text
P0xxx   Configuration and project errors
P1xxx   Lexing and syntax
P2xxx   Bindings and strict types
P3xxx   Generic types
P4xxx   Checked errors
P5xxx   when expressions
P6xxx   PHP and Composer interoperability
P7xxx   Emission and generated PHP
P9xxx   Internal compiler errors
```

Exit codes:

```text
0   Success
1   Source diagnostics
2   Invalid project or configuration
3   Output validation failure
70  Internal compiler failure
```

### Acceptance Criteria

`init` creates valid config; invalid JSON and unknown properties produce source-located diagnostics; unsafe paths are rejected; console and JSON represent the same diagnostic; CRLF and multibyte spans work; and `clean` refuses unsafe paths.

---

## Stage 2 — Ordinary PHP Frontend

### Goal

Parse valid PHP and build a no-op `.ppphp`-to-`.php` pipeline before adding new syntax.

### Work

Implement the PHP parser adapter, ++PHP parser facade, parsed-file model, parse result, and parser interface. Retain comments and precise locations. Build a corpus covering modern PHP declarations, expressions, control flow, attributes, enums, closures, heredoc/nowdoc, interpolation, promoted properties, readonly, and nullsafe access.

### Acceptance Criteria

A `.ppphp` file containing only valid PHP passes `check`, builds to valid PHP, preserves behavior and source text where required, and reports syntax errors against the original file.

---

## Stage 3 — Project Discovery and Mixed Source Sets

### Goal

Understand real Composer projects and implement the final optional-path selection model before adding ++PHP semantics.

### Work

Implement project loading, discovery, source sets, dependency graph, Composer PSR-4/classmap/file resolution, configured stubs, exclusions, output-collision detection, and deterministic command selections.

Stage 3 must distinguish:

```text
Project index
    Every project-owned .ppphp and .php file under configured source roots.

Command selection
    The files targeted by the optional path supplied to check or build.

Analysis context
    Indexed declarations, stubs, vendor metadata, and dependencies needed to
    resolve the selected files.

Emission set
    Selected .ppphp files compile and selected project-owned .php files copy.
```

Implement these selection rules:

```text
No path:
    Select the complete project. build compiles all .ppphp and copies all .php.

Directory path:
    Recursively select project-owned files in that subtree, respecting excludes.

File path:
    Select one project-owned file. build compiles .ppphp or copies .php.
```

A focused build does not automatically emit transitive or unselected ++PHP dependencies. The dependency graph supports analysis and invalidation rather than tree-shaking. Do not add an entry-point configuration to the MVP.

Apply the schema transition in Section 8.1 during this stage: remove the generated local `vendor/` schema reference. Until an immutable release schema exists, omit the instance-level `$schema` property from development-generated configuration.

Do not treat all of `vendor/` as project-owned source.

### Acceptance Criteria

Pathless, directory, and file selections behave exactly as documented; multiple source roots are handled deterministically; ++PHP and PHP files may share namespaces and call each other; ordinary PHP source is never rewritten and selected PHP is copied byte-for-byte; focused builds output only selected files; pathless builds include every project-owned ++PHP and PHP file; output collisions span compiled and copied sources; exclusions work; symlink loops cannot hang discovery; and development-generated configuration no longer contains a local `vendor/` schema reference.

---

## Stage 4 — ++PHP Extension Frontend

> **Implementation status:** Completed on `develop`. Syntax recognition, exact extension spans, parser-only normalization, source mapping, and inactive-stage diagnostics are implemented. Typed locals activate in Stage 5; other feature semantics remain in their assigned stages.

### Goal

Parse every MVP extension syntax before implementing all semantics.

### Work

Implement tokenization, explicit typed-local parsing, local `readonly` parsing, generic declarations and references, `array<T>`, `array<K, V>`, `throws`, `when`, extension nodes, and exact original-to-normalized source mappings.

`readonly`, `when`, and `throws` are contextual where required. Ordinary PHP property/class `readonly` syntax must continue to parse through the PHP layer. Do not introduce `val` or `var` local-declaration keywords; neither is ++PHP local syntax.

### Acceptance Criteria

Strings/comments are untouched; typed locals are distinguished from properties, parameters, and expressions; local `readonly` is distinguished from native PHP readonly declarations; generic brackets and comparisons are disambiguated; `array<T>` and `array<K, V>` parse in all approved type positions; `readonly` inside a type argument is rejected precisely; every extension node has exact spans; normalization edits cannot overlap silently; and not-yet-active features produce explicit diagnostics.

---

## Stage 5 — Typed Local Declarations And Readonly Local Bindings

> **Implementation status:** Completed on `develop`. Explicit declarations at executable file and callable scope, fixed local types, readonly enforcement, stable P2xxx diagnostics, semantic-first project builds, source-preserving local lowering, and complete mixed compiled/copied output trees are implemented.

### Goal

Ship explicit local declarations and readonly local enforcement as the first user-visible ++PHP feature.

### Work

Implement symbol declaration, local binding checks, and local-declaration lowering. Track scopes, written types, nullability, mutable versus readonly storage, writes, compound writes, offset mutation, by-reference uses, `unset`, and closure capture.

Implement these rules:

```text
- Type Local = Initializer declares a mutable local.
- readonly Type Local = Initializer declares a readonly local.
- Every ordinary local declaration requires an explicit type and initializer.
- Bare assignment cannot declare.
- Later values must be assignable to the fixed declared type.
- Readonly local storage cannot be reassigned or mutated through that storage.
- Native PHP property declarations and property readonly behavior remain separate.
- Executable file scope is one variable scope per source file, including across namespace blocks.
```

Before finalizing this stage, explicitly decide and document the binding syntax and mutability rules for `foreach`, destructuring, catch variables, closure captures, globals, and static locals. Do not silently inherit implicit PHP local creation.

Required diagnostics include assignment-cannot-declare, explicit-type-required, missing initializer, initializer type mismatch, later assignment type mismatch, readonly reassignment/mutation, invalid by-reference use, duplicate declaration, and use before declaration.

### Acceptance Criteria

Mutable and readonly typed declarations work; inferred declarations are rejected; bare assignment to an undeclared local fails; nullable and explicit broad types behave as written; compatible mutable assignment succeeds; incompatible assignment fails without widening; every write form to readonly storage fails; array structural mutation through a readonly local fails; object property access continues to follow property rules; shadowing is documented; captures obey the decided binding policy; generated PHP contains no local type or local readonly syntax and preserves type metadata; ordinary `.php` behavior remains unchanged.

---

## Stage 6 — Strict Types and PHPStan Adapter

> **Implementation status:** Completed on `develop`. Strict .ppphp declarations, unsafe-construct checks, project symbols, non-mutating name resolution, isolated analysis workspaces, compiler-pinned PHPStan execution, source-mapped stable diagnostics, focused-context isolation, PHP/PHPDoc/stub interoperability, and backend security boundaries are implemented.

### Goal

Deliver the strict whole-project type checker.

### Work

Implement the replaceable analyzer contract, PHPStan process adapter, result parsing, diagnostic mapping, analysis-PHP emitter, name resolution, and ++PHP-specific strict-type checks.

Analysis artifacts live under `.ppphp-cache/analysis/` and never appear in normal diagnostics.

### Acceptance Criteria

Argument, return, missing return, member, nullability, and implicit-`mixed` failures are detected; ordinary PHP contributes native/PHPDoc types; PHPStan crashes and timeouts become structured diagnostics; and the compiler's own source passes its separate PHPStan configuration.

---

## Stage 7 — Checked Errors

> **Implementation status:** Completed. Typed loop declarations, checked-error contracts, cross-call effect resolution, catch handling, PHP/PHPDoc/stub interoperability, override compatibility, source-mapped diagnostics, throws erasure, and deterministic PHPDoc emission are implemented.

### Goal

Add first-class checked error declarations without changing runtime propagation.

### Work

Implement error sets, effect resolution, effect compatibility, the checked-error pass, throws-clause erasure, and PHPDoc throws import.

```text
Direct throws
+ Called error sets
- Caught errors
= Escaping checked errors
```

### Acceptance Criteria

Direct, nested, caught, partially caught, constructor, interface, abstract, and override cases work; PHP and stub metadata are imported; generated PHP contains correct `@throws`; and runtime behavior remains ordinary PHP.

---

## Stage 8 — Build-Aware Composer Integration, Composite Types, Erased Generics, And Natively Typed Arrays

> **Implementation status:** Complete.

### Goal

Make generated PHP the Composer runtime surface, then implement a useful,
constrained generic type system together with ++PHP's native list and map array
forms.

### Work

Add `ppphp composer:configure` with the standard project, configuration, output,
and debug options plus `--dry-run`. The command reads the root `composer.json` as
data, preserves the source mappings it projects under
`extra.ppphp.source-autoload` and `extra.ppphp.source-autoload-dev`, and rewrites
only project-owned PSR-4, classmap, and files entries beneath configured source
roots so they point at the configured output tree. It is deterministic,
idempotent, symlink-safe, conflict-aware, and never runs Composer commands or
loads project PHP. Console output names the follow-up `composer update --lock`
and `composer dump-autoload` commands; JSON output uses the normal diagnostic
envelope. `ppphp build` warns when Composer runtime mappings still target ++PHP
source while projects without Composer metadata remain warning-free. Compiler
analysis continues to use the preserved source mappings rather than the runtime
projection.

Production lowering resolves the project-oriented
`__DIR__ . '/vendor/autoload.php'` bootstrap through Composer metadata and
rebases it from the concrete generated path. This preserves executable entry
scripts without making source aware of `build/`; ordinary static includes and
byte-for-byte PHP copies are unchanged.

Implement one structured semantic type model for native, composite, generic,
and typed-array syntax. It owns canonical rendering and equality, nullability,
assignability, generic substitution, runtime erasure, and PHPDoc rendering.
Validate PHP 8.4 unions, intersections, and DNF forms in every supported type
position before applying ++PHP's generic rules.

Implement generic types, parameters, substitutions, generic checks, erasure,
PHPDoc emission/import, arity, bounds, inheritance, shadowing, invalid runtime
operations, and invariance. Native ++PHP syntax is authoritative over
conflicting PHPDoc. Existing PHPDoc descriptions, attributes, unrelated tags,
line endings, and checked-error `@throws` metadata remain intact through one
coordinated declaration-level emission pass.

Implement typed arrays as part of the same erased type system:

```text
array<T>       list<T>
array<K, V>    array<K, V>
array           broad native PHP array
```

Check list shape, map key/value assignments, nested typed arrays, nullable typed arrays, readonly array structural mutation, PHP array-key behavior, signature/property/local usage, and PHPDoc interoperability. Reject `readonly` inside generic arguments.

### Acceptance Criteria

Composer runtime configuration handles PSR-4 string and list mappings,
classmaps, files, multiple source roots, custom outputs, and repeated runs
without accumulating changes; Composer can autoload generated classes after the
documented follow-up commands; generated entry scripts can load the configured
default or custom vendor directory from nested output paths; and builds provide
actionable warnings until the runtime mapping is projected. Generic classes,
interfaces, traits, functions,
methods, nesting, arrays, and iterables work; composite types are validated and
preserved natively where PHP supports them; `array<T>` behaves as a generic
list; `array<K, V>` behaves as a generic map/associative array; bare `array`
remains available; wrong arity and bounds fail; invalid list/map keys or values
fail; runtime-dependent generic uses fail; readonly typed arrays cannot be
structurally mutated; generated PHP passes PHPStan with `list<T>` or
`array<K, V>` metadata; and generic metadata crosses the PHP/++PHP boundary both
ways.

---

## Stage 9 — `when` Expressions

> **Implementation status:** Complete.

### Goal

Deliver expression-oriented conditional flow with predictable lowering.

### Work

Activate the contextual `when` syntax already recognized by the extension
frontend. A `when` expression contains one or more ordered conditional branches
and one mandatory final `else`. Each reachable branch must either yield a value
with branch-level `return`, terminate, or transfer through `finally` to a value
or termination. Branch-local `return` never returns from the enclosing callable;
returns inside nested callables retain ordinary PHP meaning.

The MVP supports `when` as a complete typed-local initializer, assignment
right-hand side, callable return operand, direct ordinary/named call argument,
or direct array value. It does not support statement-form `when`, expression
composition, defaults, constants, attributes, match arms, arrow bodies, array
keys or unpacking, call unpacking, by-reference arguments, or another `when`
condition. Unsupported sites receive a dedicated `P5005` diagnostic.

Parse each condition and branch body as source-mapped PHP fragments after
recursively normalizing nested ++PHP syntax. Store semantic branch structure,
result expressions, result types, termination, and nesting in a dedicated
semantic index; do not derive semantics from the normalized outer `null`
placeholder or mutate frontend syntax nodes. Reuse the binding, type,
checked-error, name-resolution, and project-symbol models. Branches are child
scopes: outer bindings are visible, branch locals do not escape, sibling names
may be reused, outer bindings cannot be shadowed, and writes to outer readonly
bindings remain invalid.

Diagnose missing values (`P5002`), valueless branch returns (`P5003`), result
type mismatches (`P5004`), unsupported sites (`P5005`), branch
`break`/`continue` (`P5006`), `yield` (`P5007`), `goto`/labels (`P5008`), known
by-reference arguments (`P5009`), and malformed branch fragments (`P5010`).
Keep `P5001` reserved for pre-Stage-9 compatibility only.

Lower after local, loop, and generic erasure. Hoist prerequisite evaluation in
source order, assign a deterministic collision-free temporary in each yielding
path, and use ordinary PHP control flow (`do { ... } while (false)`) without
synthetic closures. Preserve lazy condition evaluation, single evaluation,
argument and array-element order, nested expressions, `try`/`catch`/`finally`
control flow, checked effects, original-source diagnostics, CRLF, and source-map
ownership. Both production PHP and the isolated PHPStan workspace consume fully
lowered PHP.

### Acceptance Criteria

All supported source positions work, including multiple and nested expressions;
nested and throwing branches work; invalid positions and control flow receive
their dedicated diagnostics; missing `else`, missing values, and incompatible
result or contextual types fail; branch scopes and checked effects remain sound;
conditions and surrounding operands evaluate once in source order; generated
PHP is deterministic, closure-free, lint-clean, PHPStan-clean, and runtime
equivalent; focused commands stay isolated; and all earlier-stage tests remain
green.

---

## Stage 10 — Production Emission and Atomic Builds

> **Implementation status:** Complete.

### Goal

Turn successful analyses into clean deployment output.

### Work

Centralize build orchestration behind a typed `Compiler` facade. The CLI loads
the project and selection, delegates compilation, renders diagnostics, and only
reports file successes after the complete candidate tree has committed. The
compiler distinguishes source diagnostics, output validation failures, committed
success, and internal failures without interpreting diagnostic-code strings.

Move output planning from the frontend into the compiler output module and retain
project, directory, or focused-file selection metadata. Plan every selected
output before emission, reject collisions and the reserved `.ppphp/` metadata
path, then materialize compiled and copied sources as in-memory production
artifacts. Compiled `.ppphp` output uses the established source-edit lowerer,
production Composer relocation, and `declare(strict_types=1)` enforcement;
ordinary `.php` output remains byte-for-byte identical. Explicit
`strict_types=0` in `.ppphp` is a source-located semantic error.

Every committed output owns a deterministic source map under
`.ppphp/source-maps/` and an entry in `.ppphp/manifest.json`. Version 1 metadata
uses only project/output-relative forward-slash paths, stable key and entry
ordering, SHA-256 source and output hashes, normalized file modes, the target PHP
version, compiler identity, a canonical output-affecting configuration
fingerprint, and a complete-project flag. It contains no timestamp, host path,
transaction identifier, or other machine-specific state.

A pathless build begins from an empty candidate and replaces the complete
compiler-owned output tree, removing every stale or unmanaged output. Directory
and focused builds safely clone the existing tree, merge only with a compatible
validated manifest, replace the selected scope, remove stale manifest-owned
entries in that scope, and preserve unrelated output. Partial builds without a
previous manifest create an incomplete manifest; incompatible or manually
modified manifest-owned output requires a pathless rebuild.

Stage the candidate beside the output root without following symlinks. Hold one
project build lock under the configured cache for build or clean coordination,
write artifacts and metadata only beneath the candidate, validate candidate
metadata, and run bounded `PHP_BINARY -n -l` through Symfony Process for every new PHP
artifact. Commit by renaming the prior output to a sibling backup and the
candidate into place, restoring the backup on a failed commit. Handled failures
before commit leave the previous output byte-for-byte unchanged. A successful
commit with failed backup cleanup keeps the new output and reports a warning.

Document the output-root ownership, full and partial build behavior, manifest and
source-map formats, strict-types insertion, lint validation, locking, staging,
stale cleanup, determinism, and the precise directory-replacement atomicity
contract. Preserve editor definition and semantic-token protocols independently
of production builds, and remove duplicate empty compiler/emission scaffolds
rather than retaining competing abstractions.

### Acceptance Criteria

Every output file parses; every committed artifact has a valid deterministic map
and manifest entry; failed builds preserve or restore prior output; repeated
builds are byte-identical; pathless and partial stale-output rules are enforced;
output, staging, backup, manifest, and map paths cannot escape their owned roots;
concurrent build and clean operations cannot race; no mixed old/new tree is
exposed through a handled transaction; editor protocols remain independent; and
execution tests match expected output, error stream, and status.

---

## Stage 11 — Full Mixed-Project Validation

> **Implementation status:** Complete. The canonical mixed application,
> compiler-owned conflict diagnostics, multi-root Composer projection, focused
> and complete checks, atomic failure behavior, and source-free deployment are
> covered by repository tests and `composer verify:mixed-application`.

### Goal

Prove realistic adoption workflows.

### Fixtures

```text
- ++PHP calling PHP
- PHP calling generated ++PHP
- PHPDoc generic PHP consumed by ++PHP
- Generated generic ++PHP consumed by PHP
- Stub-declared checked PHP boundary
- Unchecked dynamic boundary
- Multiple source roots under one PSR-4 prefix projected to generated output
```

### Work

Maintain `examples/mixed-application` as an executable, multi-root application
rather than a synthetic single-file fixture. Its source-oriented Composer
metadata covers PHP and ++PHP PSR-4 classes plus autoload files. The application
exercises ordinary-PHP generic and checked-error metadata consumed by ++PHP,
generated generic ++PHP consumed by PHP, union and intersection types, typed
lists and maps, `when`, a web entrypoint, and an executable generated console
entrypoint.

Reject duplicate project-owned class-like and function declarations with
compiler-owned `P2034` diagnostics that identify both declarations. Configured
stubs may enrich project declarations without becoming build output. Preserve
specific PHPDoc generic and checked-error conflict diagnostics, Composer
projection conflicts, compiled/copied output collision diagnostics, and
`P4005` for genuinely unresolved dynamic invocation targets.

When multiple Composer source roots beneath one mapping project to the same
runtime output root, deduplicate the runtime paths while retaining every source
path under `extra.ppphp`. Composer configuration remains deterministic,
idempotent, and atomic.

Add `composer verify:mixed-application` and run it in CI. The verifier must copy
the example to a clean temporary project, install without scripts, prove
`composer:configure --dry-run` is non-mutating, apply the projection twice,
check and build the complete project, validate the manifest, hashes, source maps,
permissions, copy identity, strict generated output, and relocated bootstrap,
lint every output, regenerate normal and optimized/authoritative Composer
metadata, execute both entrypoints, and repeat execution from a source-free
deployment containing only runtime files.

Keep focused and pathless behavior consistent. Focused checks use valid
unselected cross-language declarations as context without surfacing unrelated
invalid files. Failed selected PHP or ++PHP builds preserve the previous
complete output. Complete pathless builds compile every project-owned `.ppphp`
file, copy every project-owned `.php` file, and remove stale or unmanaged output.

### Acceptance Criteria

Cross-direction calls execute from normal, optimized, authoritative, and
source-free Composer runtimes; Composer loads generated classes and copied PHP
from the configured output; the complete and partial compiled/copied output
contract is validated in realistic applications; stubs enrich analysis;
duplicate declarations and cross-boundary conflicts are diagnosed without
source-order ambiguity; unresolved dynamic boundaries still warn; focused and
complete checks agree on valid source; atomic failures preserve prior output;
and the complete example runs from a clean checkout using documented commands
only.

---

## Stage 12 — Diagnostic and Developer-Experience Polish

**Status:** Complete

### Goal

Make ++PHP feel like a compiler, not a PHPStan wrapper.

Every diagnostic contains a stable code, Title Case summary, original path, primary span, related span where useful, explanation, and concrete help.

### Acceptance Criteria

Golden tests cover every diagnostic family; generated paths and analyzer/parser implementation terminology never appear in normal output; cascades are suppressed; JSON output is stable; `--debug` reveals internals; color honors `NO_COLOR`; and non-interactive environments never prompt.

---

## Post-Stage-12 Semantic Closure — Generic Context, Member Types, and Focused Analysis

**Status:** Complete

### Goal

Complete the compiler-owned structured semantics required by realistic generic projects without changing the Stage 12 diagnostic architecture or starting Stage 13.

### Outcome

Type parameters retain owner-qualified identity across declarations, locals, loops, anonymous callables, inheritance, and lowering. Applied receivers use one shared member resolver for properties, methods, bounds, nullability, chains, error contracts, and `when` contexts. Generic `$this`, applied and dependent bounds, nominal capability constraints, anonymous-callable erasure, and `array_filter()`/`array_values()` flow are compiler-owned.

Focused checks retain safe declaration-only context from unselected sources with unrelated body failures and omit declarations whose headers are invalid. Nested applied types and type parameters resolve through the editor protocols. The maintained shopping-cart fixture checks, builds, lints, and runs while covering both explicit `foreach` accumulation and `array_values(array_filter(...))` removal.

### Acceptance Criteria

The Stage 12 catalog and presentation remain intact; valid generic property iteration and callbacks produce no false `P2020`, `P2026`, `P2099`, `P4005`, or `P3015`; true dynamic invocations and list-shape errors remain diagnosed; focused and complete checks expose the appropriate source failures; and the closure itself imports no later analyzer work.

---

## Diagnostic Usability And Negated-Guard Regression Maintenance

The owner-approved console presentation now uses a location-first, cause-led
layout across diagnostic families, without arrow/pipe framing, repeated catalog
titles or duplicate primary explanations. Specific remedies and related locations
remain visible; generic family advice is omitted from console output. Syntax
titles are language-neutral, while stable codes, structured protocol fields and
original source ranges remain unchanged. This supersedes earlier presentation
preservation requirements without changing completed semantic contracts.

This compiler-correctness work preserves completed stage numbering, the current
compiler identity, explicit declarations, checked-error contracts and release
preparation/publication separation. Repository-local regression fixtures exercise
the reported generic repository through suggested type insertion, wrong-method
contract edits, the correct inherited contract, checked consumer calls and atomic
builds. The original `isset` storage behavior and negated validation guard remain.

Acceptance covers specific `P2002`/`P4004` evidence and remedies, scoped rejected
binding recovery, resolved built-in predicate polarity, unsafe continuation/join
counterexamples, suggested corrections applied to real source, source locations,
console/JSON/editor/cache parity and build/lint/runtime behavior. Catalog tables
and reviewed diagnostic goldens remain maintained outputs. This is release
quality maintenance, not a new language stage or a publication claim.

The unconstrained generic repository also exposes three independent supplemental
property-access findings at `id`, `sku` and `name`; these are explicitly retained
by the end-to-end test. Its executable variant makes the private structural
validator's input `mixed` (the same native type as erased unbounded `T`) and uses
concrete objects with declared public initialized properties. Neither negated
guard nor the `isset` storage condition is rewritten. That separate generic
property-analysis boundary is not claimed fixed by this work.

## Stage 13 — Analyzer Independence, Incrementality, Security, And Hardening

Stage 13 is split by measured evidence. The split does not change the Stage 14 release number or weaken any completed stage.

### Stage 13A — Analyzer Independence And Portable Analysis Foundation

> **Implementation status:** Complete on `develop` after merge. Native full analysis remains the default.

Separate compiler-owned project analysis from supplemental PHPStan workspace preparation and execution. Represent compiler parses, safe declaration context, semantic models, stable diagnostics, analysis completeness, and uncovered required capabilities without `AnalysisProject` or process state. Avoid repeated selected parsing and semantic analysis.

Add a typed, versioned capability catalog and one executable differential scenario per capability. Compare compiler-owned and normal full results by stable diagnostic code, original source path/range, and identity. Commit a deterministic golden with explicit update workflow and classify disagreements rather than treating PHPStan as the specification.

Preserve browser protocol version 1. Add internal protocol version 2 for one-shot compiler-owned Check only. Enforce request, source-count, source-byte, diagnostic-count, and response-byte limits. Return `compilerCore`, catalog version, full-parity state, and required gaps; return no PHPStan command or continuation. Do not expose a public compiler-only mode.

The real PHP 8.4 WASM spike must run the packaged compiler once at the top level against valid and invalid virtual sources without a spawn handler or `_getcontext`. Preserve the separate PHPStan `_getcontext` failure gate and disposable-worker cancellation evidence. Record the policy and target in the analyzer-independence plan and ADR 0001. Do not move dependencies.

Acceptance: catalog version 1 contains 33 evidenced capabilities; the differential golden has no unexpected diagnostics; normal `check`/`build` still use PHPStan; browser version 2 is process-free and honest about required gaps; version 1 remains compatible; Stages 0–12 remain green.

### Stage 13B — Compiler-Owned Type-Flow Parity

> **Implementation status:** Complete.

The compiler now records structured expression facts with explicit known, dynamic, deferred, unknown, missing, and invalid states; compatibility is tri-state. One authoritative callable-contract resolver covers source, ordinary PHP, configured stubs, constructors, methods, and reviewed intrinsics. It binds positional/named/defaulted/variadic/reference arguments, performs generic call and constructor inference, substitutes generic receivers, and shares call identity with checked-error analysis.

Structured flow outcomes drive supported expression assignability, narrowing, return compatibility and normal-completion checks, member existence and access form, property read/write contracts, and definite backed-property initialization. Ordinary-PHP declarations contribute boundary contracts without ++PHP declaration rules or compiler-owned deep-body parity. Compatible stubs enrich contracts; contradictions report `P6012`. The reviewed process-free intrinsic repository covers language-critical predicates, `strlen`, `count`, `array_filter`, and `array_values` without pretending to cover the full PHP library.

Catalog version 2 contains 36 capabilities and 51 executable parity scenarios: 31 compiler-complete, 0 partial, and 5 backend-only. The nine scheduled Stage 13B capabilities are Complete with positive and negative evidence. Required compiler/full diagnostics, supplemental findings, and optional lint use separate parity expectations. The only remaining required gaps are `interop.composer-vendor` and `interop.builtin-signatures`, both retained for Stage 13C. Native `check` and `build` remain on the pinned PHPStan supplemental path; browser protocol version 2 remains process-free and no public compiler-only mode exists.

Acceptance: the measured Stage 13B gaps are closed without suppressions or Stage 12 golden churn; mixed-application, shopping-cart, source mapping, atomic build, editor, browser protocol, diagnostic, and parity regressions remain green; generator-specific return flow stays an explicit optional capability rather than an overclaim.

### Stage 13C — Portable Dependency And Signature Context

> **Implementation status:** Complete, including the post-Stage-13C completion gate.

Build a deterministic, versioned built-in signature package tied to the configured PHP target and a portable Composer/vendor declaration index. Decide every remaining Boundary capability as Complete or as an explicitly approved conservative boundary. Do not use runtime reflection for browser correctness or copy an unreviewed third-party stub corpus.

The checked-in PHP 8.4 package is generated deterministically from official `php/php-src` tag `php-8.4.23` at commit `52cee85adfeeb6f017f2ac796ab7973353702c20`. Its manifest and module hashes are verified before use; modules load lazily and immutable parsed results are reused in process. It covers normalized core and extension functions, constants, class-likes, methods, properties, aliases, conditions, and a small reviewed override layer. Runtime reflection and backend stub data are not compiler sources of truth. Corrupt or incompatible package data fails closed with `P6016`, while project/platform collisions report `P6017`.

Mutually exclusive conditional function variants are reduced during generation to a conservative contract accepted by every exhaustive branch. Alternatives without a provably common contract fail generation rather than exposing a build-specific signature as portable.

Installed Composer production packages are modeled from `vendor/composer/installed.json` with stable package and autoload order. The compiler parses `autoload.files` and classmap declarations, lazily resolves referenced PSR-4 classes by longest prefix and declared order, and follows supported native/PHPDoc declaration references. It never includes or executes dependency PHP. The index is bounded to 2,048 files, 16 MiB, and 8,192 classmap-discovery entries; limit, unreadable-source, and invalid-declaration failures use P6013–P6015. Declaration provenance and precedence are explicit: configured stub, project, dependency, platform, then reviewed intrinsic refinement.

Catalog version 3 contains 36 capabilities and 51 executable parity scenarios: 33 compiler-complete, 0 partial, and 3 backend-only. Both Stage 13C Boundary capabilities are Complete, all remaining Backend-only capabilities are Optional, and `compilerCore` reports `fullParity: true` with no required gaps. The reviewed parity report has zero unexpected diagnostics and zero expectation failures. Browser packaging includes the signature resources and a non-executed Composer dependency fixture.

The dependency-optionalization design is tested without changing distribution behavior. Compiler-core code cannot import the PHPStan adapter, `AnalysisProject`, or Symfony Process. `phpstan/phpstan` remains required while native `check` and `build` use the supplemental phase; `phpstan/phpdoc-parser` remains a direct compiler dependency and Symfony Process also serves production lint. Any optional backend package or default change requires a separate decision and packaging contract.

Acceptance: met. `interop.composer-vendor` and `interop.builtin-signatures` no longer depend exclusively on PHPStan; required portable fixtures resolve without executing autoload code; corruption and resource limits fail closed; generation and verification are deterministic; and dependency optionalization has a tested packaging design.

### Post-Stage-13C Completion Gate — Portable Dependency Index And Composer Edge Semantics

> **Implementation status:** Complete.

Complete the portable dependency boundary before incremental hardening begins. The compiler must model maintained Composer installed metadata and production autoload behavior, including PSR-4, PSR-0, ordered files and classmap entries, `exclude-from-classmap`, supported classmap wildcards, safe static include traversal, common negative existence-guard polyfills, and static class aliases. All dependency declarations retain package and source provenance, conditional availability, and deterministic Composer precedence; unresolved ambiguity is diagnosed instead of allowing insertion order to select a declaration.

Normal native analysis continues to read installed dependency source without executing it. A shared dependency-declaration provider also accepts a deterministic, versioned, source-free `ppphp-dependencies/` manifest and package shards. The portable package contains declaration contracts, relationships, aliases, conditions, locations, hashes, counts, and autoload provenance, but never implementation bodies, absolute source-machine paths, or executable serialization. Its reader validates the package atomically and its writer produces byte-identical output for identical input.

Dependency paths are canonicalized and confined to trusted project and vendor roots. Symlinks, includes, file counts, byte counts, discovery counts, and include depth are bounded; the standalone index builder alone may receive explicit external trusted roots. Missing, unavailable, and dynamic declaration context remain distinct, and unavailable context is diagnosed only when selected source actually needs it. Browser protocol version 2 may consume an explicitly mounted portable index after containment, identity, compatibility, hash, and resource validation; version 1 and version 2 requests without dependency context remain unchanged and process-free.

Acceptance: met. The Composer edge model and portable dependency index are covered by focused unit, feature, browser, source-free, path-safety, determinism, corruption, ambiguity, and differential-parity tests. Catalog version 4 records 37 capabilities and 72 scenarios: 34 Complete, 0 Partial, and 3 Backend-only, with zero required gaps, zero unexpected compiler or full diagnostics, and zero expectation failures. Native `check` and `build` retain their supplemental PHPStan phase; Stage 13D subsequently completed without implementing Stage 15 syntax.

### Stage 13D — Incremental Performance, Security, And Hardening

> **Implementation status:** Complete. Stage 14A release-candidate preparation follows and is now complete.

Make repeated use practical and eliminate obvious hazards. Cache keys include source, configuration, compiler/catalog, target, stub, Composer-lock, and relevant supplemental hashes. Reuse normalized source, token streams, safe parsed artifacts, semantic facts, source maps, and supplemental results without coupling compiler-core caches to PHPStan.

Record cold/warm check and build time, peak memory, and output size against small, medium, and large fixture projects. Measurements inform development but must not become fragile platform-specific blockers.

Security rules:

```text
- Never evaluate user source.
- Never use eval.
- Do not execute arbitrary analyzer bootstrap files automatically.
- Validate subprocess arguments and apply timeouts.
- Prevent path traversal and unsafe symlink traversal.
- Restrict output/cache paths to the project root by default.
- clean removes the complete validated compiler-owned output and cache roots.
- Do not expose environment secrets.
- Validate output before treating it as successful.
```

Add malformed-source, fuzz-smoke, interrupted-build, read-only-filesystem, invalid-UTF-8, very-long-line, deep-nesting, Windows-path, and CRLF tests.

Acceptance: warm builds reuse work; cache corruption rebuilds safely; interrupted builds preserve prior output; `clean` is path-safe; deterministic builds remain deterministic; dependency scanning is enabled; malformed input does not crash the compiler; and the analyzer promotion gates in `docs/analyzer-independence.md` are evaluated explicitly before any default switch.

Outcome: a versioned content-addressed cache stores canonical records and hash-verified blobs beneath `.ppphp-cache/compiler/`, keyed by a path-independent internal build identity plus project inputs. Exact successful compiler and supplemental results are reusable, complete cached artifacts can reconstruct missing output, localized body edits reuse unaffected artifacts, and declaration changes conservatively invalidate consumers. Corrupt, incompatible, oversized, or incomplete evidence is a safe miss; bounded pruning preserves reachable active evidence.

Build and dependency-index commits use durable role-bound journals and deterministic recovery. The stable root `.ppphp-operation.lock` coordinates shared checks with exclusive build and clean operations even while the cache directory is detached. PHPStan and lint execute without a shell through bounded, timed, reaped processes with a reviewed environment; lint uses `PHP_BINARY -n -l`. Malformed input, invalid UTF-8, long lines, deep nesting, fuzz smoke, cache corruption, interrupted operations, and portable-body rejection are covered by executable tests.

The repeatable benchmark records cold, exact-warm, localized-edit, focused, output, cache, work-avoidance, and peak-memory evidence without imposing timing thresholds. Analyzer promotion technical gates pass, and ADR 0004 records the MVP product decision to retain the full supplemental PHPStan path. PHPStan dependency placement is unchanged, and no public compiler-only mode was added.

---

## Stage 14 — MVP Release

### Goal

Publish a credible first release usable in a real PHP project.

### Stage 14A — Prepare `2026.3.1-rc-1`

**Status:** Complete

Prepare, but do not publish, the first MVP Release Candidate. The compiler,
Composer package, immutable schema, release manifest, release notes, changelog,
security and migration guidance, installed-package verifier, deterministic
asset builder, and tag-driven least-privilege release workflow all identify
`2026.3.1-rc-1`. `ppphp init` writes the immutable schema URL only when valid
release metadata is present. Offline release-readiness and documentation gates
prove that generated assets are byte-reproducible and do not modify tracked
state. PHPStan remains the pinned runtime supplemental backend under ADR 0004.
No tag, GitHub Release, package publication, or Stable claim is made in this
stage. Subsequent user-facing correctness fixes advance the prepared candidate
identity to `2026.3.1-rc-2` while retaining the first candidate's notes as
history.

### Stage 14B — Publish And Validate The Release Candidate

Prepare on `develop` and integrate intended changes into `main`. Only when ready,
cut `release/<canonical-version>` from `main`, complete verification of the exact
release-branch commit, then create the matching tag as the final step before
submission. Release-only commits need not already be on `main`, and an advanced
`main` does not invalidate the branch. Tag-driven publication validates the
selected branch/tag/commit identity, reruns the aggregate and distribution gates,
and publishes verified deterministic assets with the rendered notes asset as
its body. Branch pushes receive CI but do not publish. Delete obsolete release
branches without affecting historical tag/commit/hash verification. See the
single [release runbook](releasing.md) for graph-evidence limits and public
installation checks; preparation requires no release refs.

### Stage 14C — Promote A Validated Stable Release

Promote only after field validation of the RC and resolution of release-blocking
defects. Stable uses the exact `2026.3.1` identity, is not a GitHub prerelease,
and becomes the default unqualified Composer acquisition channel. Rebuild every
version-bound artifact; never relabel or mutate the RC tag or assets.

### Maintained Release Documentation

Required documentation:

```text
- Complete README
- Language guide
- Compiler architecture guide
- Mixed-project guide
- Erased-generics guide
- Checked-errors guide
- Typed local declarations and readonly bindings guide
- Natively typed arrays guide
- when guide
- Migration-from-PHP guide
- Example mixed application
- Changelog
- Security policy
- Versioned `ppphp.schema.json` release artifact
```

The canonical product identity is ++PHP, with the `ppphp` compiler, `.ppphp` source extension, `Atatusoft\Ppphp` namespace, and `atatusoft/ppphp` Composer package.

### Release And Acquisition Contract

Stage 14 publishes with the settled quarterly CalVer model. The release process
must parse the compiler version through `ReleaseVersion`, require an exact
matching Git tag, classify Stable GitHub releases as non-prereleases, classify
Release Candidate and Development GitHub releases as prereleases without
merging their channels, and publish `ppphp.schema.json` under that same exact
immutable identity.

Default acquisition considers Stable releases only. Release Candidate and
Development acquisition requires an explicit `rc` or `dev` channel or an exact
canonical version. A supplied channel and exact version must match, an empty or
unavailable channel fails, and there is no cross-channel fallback. Any installer
or release resolver introduced in this stage must use `ReleaseSelector`, remain
separate from ordinary compiler commands, and never turn `check`, `build`,
`init`, or editor/browser protocols into update clients.

Default Composer documentation uses ordinary Stable package resolution. Exact
Release Candidate and Development Composer commands may be published only after
validation against the supported Composer version and real package metadata.
Composer's rolling `dev-develop` branch identity remains distinct from an
immutable `dev-YYYY.Q.R` Development release.

### Final MVP Release Criteria

```text
1. Explicitly typed mutable and readonly local declarations are fully checked and erased.
2. Natively typed arrays are checked and erased with correct PHPDoc metadata.
3. Strict typing works across project files.
4. Checked errors propagate, catch, and override correctly.
5. Erased generics preserve relationships through generated PHPDoc.
6. when expressions lower predictably.
7. Mixed PHP and ++PHP calls work in both directions.
8. Generated output has no compiler runtime dependency.
9. Every generated file passes php -l.
10. All diagnostics point to original source.
11. No raw PHPStan diagnostic is exposed in normal mode.
12. Builds are atomic and deterministic.
13. Cold and warm compiler performance are recorded.
14. Cache and output operations are path-safe.
15. CI is green.
16. Documentation describes only implemented behavior.
17. A complete mixed-project example runs from a clean checkout.
18. `ppphp init` writes the matching immutable schema URL for a release, while runtime configuration validation remains network-independent.
19. Stable is the default acquisition channel, and prerelease acquisition is explicit with no cross-channel fallback.
20. Compiler version, Git tag, GitHub classification, and schema artifact identity agree exactly.
21. Release verification is network-independent and ordinary compiler commands perform no update checks.
```

---

## 13. Testing Strategy

Every feature stage adds relevant unit, fixture, golden, integration, and execution tests.

```text
tests/Fixtures/
├── Parsing/
├── Bindings/
├── StrictTypes/
├── CheckedErrors/
├── Generics/
├── TypedArrays/
├── When/
├── MixedProjects/
└── InvalidProjects/

tests/Golden/
├── Diagnostics/
├── AnalysisPhp/
├── ProductionPhp/
└── SourceMaps/
```

For successful generated programs:

```text
1. Build.
2. Run php -l.
3. Execute with PHP.
4. Compare stdout.
5. Compare stderr.
6. Compare exit status.
```

Every successful build proves that output contains no ++PHP tokens, parses as the configured PHP target, derives from the same semantics as analysis output, is deterministic, and did not modify source files.

---

## Stage 15 — Immutable Records, Native Type Ergonomics, And Declarative Framework Metadata

Stage 15 is unimplemented post-MVP work. This section records the owner-approved schedule, not feature availability. Accepted RFCs define the language contracts; draft RFCs retain their open decisions. The project owner may revise the grouping, order and identifiers of unimplemented work. Such changes must be reconciled across this plan, the [RFC index](rfcs/README.md), affected references and planning tests, without rewriting completed-stage history or silently weakening accepted semantics.

The current sequence separates Scalar Objects (15C) from List And Map Objects (15D), with Attribute Factory Expressions proposed for 15E. This supersedes the earlier combined Native Type Members grouping in 15C and attribute-factory reservation in 15D. The accepted [Immutable Records RFC](rfcs/0001-immutable-records.md) remains authoritative for Stage 15A.

### Stage 15A — Immutable Records

Immutable Records are the first approved Stage 15 work item. `record` is a
contextual class-like declaration keyword and records are implicitly final.
Explicitly typed signature components become public readonly promoted
properties, and the compiler generates the canonical constructor. Records may
be generic, implement interfaces, and contain instance methods, static methods,
class constants, and virtual get-only computed properties.

Records cannot extend a class, declare a custom constructor, set hooks,
additional backed instance state, backed computed-property hooks, or initial
static properties. Components cannot be variadic, passed by reference, or
repeat `readonly`. State evolution returns a new instance, while returning
`$this` for an unchanged value remains valid.

Lowering produces an ordinary final class with public readonly promoted
components and a compiler-generated constructor, not a PHP `readonly class`.
The model is shallowly immutable and retains ordinary PHP object identity; it
does not provide synthesized equality, hashing, copying, serialization, destructuring,
pattern matching, or array/JSON conversion. Stage 15A implements the complete
contract recorded by the accepted [Immutable Records RFC](rfcs/0001-immutable-records.md); earlier stages only preserve it.

### Stage 15B — Postfix List Types

The accepted [Postfix List Types RFC](rfcs/0003-postfix-list-types.md) defines the complete syntax and acceptance contract.

`T[]` is an exact syntax alias for `array<T>`. Both spellings use the same `TypedArrayType`; postfix syntax does not introduce a second collection type, and `array<K, V>` remains the associative/map form. Postfix list types apply in local, parameter, return, property, generic-argument, nullable, union, intersection-compatible, and nested type positions, including `int[][]`.

Postfix binding follows TypeScript-like precedence:

```text
int|string[]    means int|array<string>
(int|string)[]  means array<int|string>
```

Examples include `int[] $scores`, `ShoppingCartItem<Product>[] $items`, `readonly User[] $users`, and nested matrices.

### Stage 15C — Scalar Objects

The accepted [Scalar Objects RFC](rfcs/0004-scalar-objects.md) defines compiler-owned synthetic properties and methods for `int`, `float`, `bool` and `string`. Values remain native, unboxed PHP scalars; member lookup, type checking and lowering are compiler-owned, with no runtime registry or separately installed runtime package. Examples include `length`, `toLower()` and `abs()`. The RFC owns the full member, evaluation, target-platform and acceptance contracts rather than a duplicated subset here.

### Stage 15D — List And Map Objects

The accepted [List And Map Objects RFC](rfcs/0005-list-and-map-objects.md) defines the member surface for `array<T>`, its `T[]` alias, `array<K, V>` and broad `array`, sharing the synthetic-member architecture with Scalar Objects. It incorporates the accepted [List And Map Path Access RFC](rfcs/0002-list-and-map-path-access.md), which remains authoritative for `get()` and `hasPath()`.

The surface includes observational properties such as `count`, `first` and `firstKey`, queries such as `find()` and `findKey()`, and transformations such as `filter()`, `map()` and `reduce()`. Receivers remain native arrays. The accepted RFC supersedes the earlier nullable `first`/`last` summary: these value properties throw `CollectionQueryException` on an empty collection; key properties remain nullable. Checked query behavior and `FindOptions` follow the RFC's exact contracts.

The narrow generated-support exception is approved: shared ordinary PHP support artifacts must deploy without the compiler or a separately installed runtime package. Cross-package identity, checked effects, source-free deployment and build ownership are acceptance requirements, not deferred integration details. The RFCs own the complete contracts; this schedule introduces no alternative semantics.

### Stage 15E — Attribute Factory Expressions

The [Attribute Factory Expressions RFC](rfcs/0006-attribute-factory-expressions.md) remains a draft. It proposes a constrained, statically named factory call inside an eligible attribute argument, such as `DatabaseModule::forRoot([UserEntity::class])` in a `Module` attribute. The compiler would recognize and type-check the call without executing it, then lower it to valid ordinary PHP metadata. Runtime resolution belongs to the consuming framework or library; native PHP retains an explicit descriptor or configuration form.

The direction is framework-neutral. AssegaiPHP is the motivating acceptance case, not a prerequisite for language implementation. The exact lowering protocol remains an open design decision in the draft; this scheduling change does not settle it.

---

## 14. Dependency Policy

Recommended runtime dependencies:

```text
symfony/console
symfony/process
nikic/php-parser
phpstan/phpstan
phpstan/phpdoc-parser
```

Remove the unused prompt dependency. All commands, including `ppphp init`, remain deterministic with closed standard input and `--no-interaction`.

Development dependency:

```text
pestphp/pest
```

Avoid a DI container, full framework, database, general event bus, second AST library, parser generator for the MVP, or snapshot package before custom golden helpers prove insufficient.

---

## 15. Post-MVP Roadmap

Possible future features after real-world use:

```text
- Generic variance and defaults
- Explicit generic call arguments
- Opt-in reified generics
- More when expression positions
- Statement-form when
- given and ++PHP control-flow finally
- Typed array shapes
- Type aliases
- Sealed classes
- Exhaustive enum handling
- Result-style error APIs
- Checked/unchecked boundary attributes
- Runtime boundary guards
- Formatter
- Standalone LSP servers and additional IDE integrations
- Watch mode
- Composer plugin
- PHAR distribution
- PHP-to-++PHP migration assistance
- Hierarchy-aware typed collection assignment
- Hierarchy-aware foreach widening
```

Native compilation remains a separate strategic discussion. ++PHP's first advantage is incremental adoption on the official PHP runtime.

---

## 2026.4 Framework Integration Programme

The approved [Framework Integration Programme Amendment](ppphp-framework-integration-plan-amendment.md) supplements this plan. The [repository-local FI-0 work order](ppphp-framework-integration-fi0-codex-prompt.md), [feasibility report](spikes/framework-integration-2026.4.md), [candidate matrix](spikes/framework-integration-matrix.md), [evidence and open gates](spikes/framework-integration-evidence.md), and [FI-1 implementation handoff](spikes/framework-integration-fi1-codex-prompt.md) keep the programme independent of private attachments.

**First-class AssegaiPHP and Laravel integration must ship during the `2026.4.x` release line.** Creation/adoption, valid generators, mixed source, development/watch, tests, HTTP/console, migrations/queues, production/source-free deployment, source diagnostics, recovery, supported versions and removal are gates, not optional polish. Planning and test-only experiments do not establish shipped support.

| Item | Outcome |
| --- | --- |
| FI-0 — Framework Integration Evidence Spike | Production characterization, internal platform/layout experiments, live-framework probes and bounded decisions; GO WITH LIMITS, with negative/unexecuted application gates recorded. |
| FI-1 — Multi-Version Platform, Runtime Layout And Resource Pipeline | Shared PHP 8.4/8.5 capability selection and real analysis/emission/runtime evidence, mounted file/directory roots, resources, persistent-state ownership and lifecycle. |
| FI-2 — AssegaiPHP Native Integration | Native creation/schematics, commands, watching and source-free fixture. |
| FI-3 — Laravel Official Integration | Reversible adoption, generator/command/recovery coverage, analysis and source-free fixture. |
| FI-4 — Framework Queue Validation | Symfony, CakePHP, conditional Tempest, separate Yii 2/Yii 3; CMS track Drupal, WordPress and Joomla. |

Compiler host, project/dependency syntax, built-in signatures/extensions, emission target and complete-application runtime are independent dimensions. The initial baseline is not a ceiling. PHP 8.5 belongs to shared FI-1, not optional Tempest-only work; older-host/newer-platform combinations need explicit interpreter evidence. PV-1 version-sensitive analysis, PV-2 host separation, PV-3 actual lint/runtime selection, PV-4 dependency/native/extension rejection, PV-5 evidence isolation and PV-6 reviewed extensibility follow the amendment's exact contract. The evidence report distinguishes specimen PASS from full production FAIL/NOT RUN; no support claim follows from widening configuration.

Framework-specific compiler plugins and framework-magic emulation remain outside the language core and the `2026.3` MVP. Official post-MVP adapters may integrate project creation, generation, build/watch/test/deployment, declarative metadata, safe stubs and framework-native commands without redefining ++PHP semantics. Portable analysis never bootstraps framework applications.

Provisional allocation: `2026.4.1` shared foundation and AssegaiPHP; `2026.4.2` Laravel preview; `2026.4.3` Laravel stable qualification. Early Tempest platform and WordPress packaging research informs the foundation without adding a third launch gate. FI work preserves accepted language contracts and follows the current owner-approved Stage 15 schedule above; FI labels do not independently renumber language work. FI-0 does not change `2026.3.1-rc-2`, publish a release or mark Stage 14B complete.

---

## 16. Stage Execution Rule

The current implementation stage must be determined from the latest `develop` branch rather than hard-coded in this plan.

Before authoring or executing each stage prompt:

```text
1. Read the current repository and this plan in full.
2. Verify the preceding stage's acceptance criteria against actual code and tests.
3. Close any remaining gap before moving to the next stage.
4. Preserve later user-approved amendments recorded in this plan.
5. Do not infer missing language or CLI semantics when the plan identifies an
   open decision; obtain a decision and record it before implementation.
```

In particular, Stage 3 must implement the command-selection rules in Section 7.4, and the release/configuration work must implement the schema-distribution policy in Section 8.1.
