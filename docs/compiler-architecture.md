# Compiler Architecture

## Release Identity

`Compiler::VERSION` is the one public compiler identity. `ReleaseVersion`
centralizes quarterly CalVer parsing, canonical rendering, channel identity, and
explicit comparison; `ReleaseSelector` performs strict Stable-default selection
without cross-channel fallback. `ReleaseSchema` derives the exact immutable tag
and schema URL for published artifacts. Symfony Console, build manifests,
configuration fingerprints, browser responses, analyzer parity reports, and
portable dependency metadata all use the same compiler constant. The internal
`CompilerBuildIdentity` separately hashes executable compiler inputs so caches,
manifests, and indexes invalidate between code changes without changing public
CalVer. None of these paths performs release-network activity. See
[Versioning](versioning.md).

> **Status:** The compiler provides deterministic recoverable production builds, verified mixed PHP/++PHP interoperability, stable catalog-owned diagnostics, structured generic context, process-free compiler-owned type-flow analysis, portable PHP and dependency declarations, content-addressed incremental evidence, and deterministic release assets.

++PHP is a source compiler that emits ordinary PHP:

~~~text
configuration and discovery
    -> PHP and ++PHP parsing
    -> ++PHP semantic validation
    -> isolated analysis workspace
    -> pinned PHPStan analysis
    -> original-source diagnostic mapping
    -> in-memory production artifacts and output plan
    -> candidate-tree metadata and PHP lint validation
    -> atomic ordinary-PHP output commit
~~~

## Project Loading And Selection

Ordinary-PHP token streams are materialized only when an editor or output
consumer requests them; declaration analysis retains syntax trees without a
second set of tokens and per-token locations. Native parser tokens are likewise
reconstructed on demand using the original parser target. Composer discovery
still examines complete dependency sources for references, includes, guards and
aliases before releasing named function and method implementation bodies.
Declaration signatures, defaults, PHPDoc, property hooks and source coordinates
are preserved. Project source trees are not pruned, and PHPStan still reads the
original dependency sources. Temporary bootstrap symbols are released after final
declaration contracts have been constructed.

The supplemental analyzer receives the compiler process's configured PHP memory
limit rather than a hard-coded larger allowance. A cached successful check does
not stand in for the semantic model needed by a cold build; memory regressions
exercise both cold and warm commands.

ProjectConfigLoader reads ppphp.json from an explicit project root and validates normalized paths, source ownership, exclusions, and compiler-owned output and cache boundaries.

FileDiscovery recursively indexes case-insensitive .php and .ppphp files beneath configured source roots. It applies exclusions before selection, avoids directory-symlink traversal, rejects escaping file symlinks, deduplicates physical files, and assigns overlapping roots to the most-specific owner deterministically.

Project retains configuration, the deterministic source set, Composer metadata, configured stubs, a dependency graph, and a shared source manager. Composer source PSR-4, classmap, files, custom vendor paths, and installed-package metadata are analysis context rather than project-owned build inputs. When runtime mappings have been projected, ComposerResolver reads their original forms from extra.ppphp source metadata.

`ComposerResolver` models maintained `installed.json` forms and retains ordered PSR-4, PSR-0, classmap, files, exclusions, package identity, production requirements, and development metadata. `ComposerDependencyDeclarationLoader` resolves Composer precedence, wildcard classmaps, safe static includes, exact guarded fallbacks, and static aliases under canonical package/root trust. Dependency PHP is parsed as data and never loaded or executed. Resource limits, unavailable/unsafe context, invalid sources, and genuine ambiguity fail closed with P6013–P6015 and P6018–P6021.

`DependencyDeclarationProvider` is the single compiler-analysis seam. `InstalledComposerDeclarationProvider` is the native default; `PortableDependencyIndexProvider` atomically reads strict format-2 manifest/shards into the same `ProjectParseResult`, `SourceFile`, PHPDoc, type, and symbol pipeline. Selecting an index does not rescan installed source. See [Portable Dependency Index](dependency-index.md).

| Command | No path | Directory | File |
| --- | --- | --- | --- |
| check | Check all project sources | Check the recursive subtree | Check one .php or .ppphp file |
| build | Check all; compile .ppphp and copy .php | Check and build the subtree | Compile one .ppphp or copy one .php |
| dump:ast | Invalid | Invalid | Dump one source AST |

Configured .stub.php files remain global syntax and type context for focused commands and are never outputs. Project-owned ordinary .php files are copied byte-for-byte into corresponding build paths.

`editor:definition` and `editor:semantic-tokens` are separate bounded, versioned standard-input protocols. Definition queries overlay the current unsaved `.ppphp` document in memory, build project symbols without invoking the analysis backend or writing caches, and return declaration spans for a UTF-8 byte offset. Semantic-token queries parse only the current in-memory document, derive PHP reserved words from the tokenizer, and classify contextual PHP plus ++PHP syntax-tree nodes into standard editor roles. See [Editor Protocol](editor-protocol.md).

`editor:diagnostics` registers the target and other open buffers in a fresh
request-local `SourceManager`, resolves owned new or existing source paths
through `FileDiscovery`, and invokes `CompilerProjectAnalyzer` with only the
target selected. Both selected parsing and focused declaration context therefore
see the same unsaved snapshot. It uses the normal diagnostic processor/JSON
renderer, reports compiler-core coverage, and bypasses the persistent cache,
operation lock, supplemental workspace and production pipeline. It does not
change the native full-check default or either existing editor protocol.

## Frontend

PpphpParser implements the two-layer frontend. PhpToken::tokenize supplies exact source tokens. The extension parser records typed locals, typed loop declarations, throws clauses, generic declarations and references, typed arrays, and hierarchical `when` syntax as source-located nodes. A validated, length-preserving normalization plan masks extension-only syntax, then PhpParserAdapter parses the normalized source with the Composer-locked PHP-Parser API and PHP 8.4 grammar.

ParsedFile retains the original source, token stream, extension syntax index, normalization edits, normalized source, bidirectional source map, PHP AST, and parser tokens. Extension identities derive from node kind and original half-open byte span.

Normalization is parser-only. It preserves byte offsets and newline bytes so PHP parser diagnostics map back to the original source. Malformed extension syntax reports P1008 or P1009. The whole-file plan keeps one non-overlapping outer `when` placeholder while retaining descendant syntax in the extension index. `WhenFragmentParser` applies those descendant edits and parses each condition and branch body with the PHP 8.4 parser, preserving original positions and nested `when` identities.

## Semantic Analysis

SemanticAnalyzer first collects preliminary declarations, indexes their generic parameters, and then rebuilds final symbols with contextual structured types. This two-phase process lets declarations refer to owner-qualified type parameters without collapsing them into namespaced class names. It resolves names without mutating frontend nodes and creates a SemanticModel for each selected .ppphp file. Pass order covers declaration collection, name resolution, binding checks, and strict ++PHP checks. Typed declarations are associated with normalized PHP assignments by exact variable and initializer offsets.

Each source file owns one executable file scope shared across namespace statement lists. A function, method, closure, arrow function, or native PHP property hook owns a separate callable scope. Ordinary nested blocks share their enclosing scope. Parameters, catch variables, $this, property-hook $value, and PHP superglobals are existing bindings. Typed declarations create LocalBinding records containing the fixed type, mutability, source spans, resolved initializer type, reads, and writes.

The semantic type model represents atomic, union, intersection, DNF, generic, type-parameter, typed-array, and unknown types. Source types enter that model through one contextual resolver; semantic passes do not render and reparse types. Owner-qualified substitutions preserve identity across locals, loops, callbacks, members, inheritance, traits, and interfaces. The model separates normalized canonical identity from spelling-preserving diagnostic and PHPDoc rendering, and owns nullability, assignability, generic substitution, and native erasure. Composite forms are validated before assignment checks.

Generic declarations are indexed across classes, interfaces, traits, functions, and methods. The generic pass checks scope, duplicate parameters, arity, raw applications, dependent and applied bounds, inheritance, trait use, static restrictions, runtime-dependent uses, PHPDoc conflicts, and invariant applications. Bounds and inheritance are checked nominally with structured substitution. Ordinary PHP and stubs contribute generic templates through parsed PHPDoc.

The binding pass resolves local declarations and structured collection contracts. `AnalyzeTypeFlowPass` then performs one compiler-owned flow traversal for supported expressions, calls, members, returns, narrowing, and property initialization. `FlowState` joins local alternatives and intersects definite property facts; `FlowOutcome` distinguishes normal completion, returns, throws, breaks, continues, and exits. `SemanticModel` records expression types with known, dynamic, deferred-external, unknown, missing, or invalid status and provenance. `TypeCompatibility` reports compatible, incompatible, or unknown rather than accepting absent information as proof.

`CheckWhenExpressionsPass` resolves each placeholder by AST identity and original span, classifies its exact expression site, parses branch fragments, creates child scopes, validates control flow, infers the canonical result union, and checks its receiving context. The resulting typed `WhenExpressionAnalysis` models live in `SemanticModel`; other passes query them instead of interpreting the normalized `null` placeholder. Conditions and branches are also traversed by declaration, binding, type, generic, and checked-error infrastructure.

Project symbol tables record effective native/PHPDoc callable and property contracts in addition to classes, interfaces, traits, enums, constants, parameters, inheritance, namespaces, source files, and precise spans. Declaration provenance is explicit, with configured stubs taking precedence over project declarations, Composer dependencies, the target PHP platform package, and reviewed intrinsic refinements. Project declarations that collide with PHP platform symbols report `P6017`. `CallableContractResolver` is the single path for functions, methods, constructors, ordinary PHP, configured stubs, dependency declarations, PHP platform declarations, and reviewed intrinsics. Argument binding covers names, defaults, variadics, unpacking, and references; generic inference applies receiver and callable substitutions. One shared member resolver handles inheritance, interfaces, traits, unions, intersections, nullable/nullsafe receivers, visibility, static form, asymmetric property access, and effective PHPDoc member types. The editor continues to resolve definitions and semantic tokens against the same symbol table.

Project-owned declarations must be unambiguous. A duplicate class-like or function declaration is rejected with `P2034`, including both declaration locations, before the symbol table can silently select one definition. Configured stubs may intentionally describe a project declaration and retain their documented precedence rather than being treated as a duplicate source definition.

Callable error contracts are prepared project-wide before body analysis. Native throws clauses are authoritative for .ppphp declarations; ordinary PHP and configured stubs contribute @throws metadata, with compatible stubs enriching project declarations. Contradictory stub/runtime callable or property metadata reports `P6012`. The error-effect pass and type-flow pass consume the same resolved callable contracts, so call identity, parameters, substitutions, and checked effects do not diverge.

Typed for and foreach declarations enter the same enclosing PHP-compatible binding scope as ordinary typed locals. Foreach declarations extract exact list or map key/value contracts; broad arrays supply mixed. Collection relationships remain invariant.

Strict checking requires native parameter, property, and return types in .ppphp, with constructor and destructor return exemptions. An explicit `strict_types=0` is rejected because production `.ppphp` output always enables strict types. Strict checking also rejects eval, variable variables, dynamic include targets, return-by-reference declarations, and dynamic property creation. Ordinary PHP is exempt from these ++PHP-only rules.

## Analysis Backend

`CompilerCache` snapshots project-relative source, configuration, stub, Composer,
signature-package, dependency-index, operation, and compiler-build identities.
Canonical operation records reference committed content-addressed blobs; records
and blobs are independently bounded and hash-validated. Exact successful
compiler and supplemental results replay diagnostics without constructing a
semantic model. Build reuse instead consumes complete cached artifacts. A body
edit can retain unaffected artifacts while declaration changes conservatively
invalidate consumers. Corruption, incompatible formats, missing blobs, and
backend failures never become successful evidence. See [Compiler Cache](compiler-cache.md).

`CompilerProjectAnalyzer` is the in-process project-analysis boundary. It parses the selected sources once, collects safe declarations from unselected sources, loads dependency and PHP platform declarations, runs compiler semantics once, processes stable diagnostics, and returns `CompilerProjectAnalysis`. The result contains no `AnalysisProject` and reports `compilerCore`, catalog version 4, `fullParity: true`, and no uncovered required capabilities. It performs no lowering, workspace write, PHPStan preparation, or process launch.

`ProjectChecker` consumes that result for the normal full path. Only after compiler success does `AnalysisWorkspacePreparer` materialize `.ppphp-cache/analysis/`. `SupplementalAnalysisPreparation` binds the reusable compiler result to the optional backend project. Selected `.ppphp` files are lowered with complete generic, typed-array, checked-error, and `when` control-flow metadata; selected `.php` files are copied. Valid unselected sources become declaration-only scan context: their namespace, imports, declarations, member signatures, generic relationships, and error contracts remain available while bodies are replaced. Sources with invalid declaration headers are omitted, and unrelated body failures do not surface in a focused command. Configured stubs remain stub context, and Composer source paths are scanned as data. Deterministic source-root hashes isolate duplicate relative paths.

`PhpStanProjectAnalyzer` is constructed lazily on the supplemental path and invokes the compiler-installed backend through `PHP_BINARY` and bounded argument-array process execution. Standard output and error are capped, timeout termination reaps the child, and only a reviewed environment is forwarded. A generated configuration supplies selected paths, context, stubs, target PHP version, and a workspace-local cache. User PHPStan configuration, autoload entrypoints, Composer scripts, and application bootstrap files are not executed. PHPStan supplements Optional catalog capabilities such as generator-specific return flow, deep ordinary-PHP bodies, and backend failure handling, while normal `check` and `build` require this established full phase. Changing the native default or dependency placement requires explicit product approval.

Backend identifiers map to stable P2xxx diagnostics and original source spans. Internal and backend findings are deduplicated by category and source location. Infrastructure failures use P6005–P6007.

Every selected source is parsed and every selected .ppphp model is analyzed before a build writes output. The compiler-owned backend configuration enables checked-exception reporting and maps supported exception findings to P4xxx diagnostics.

## Lowering And Production Commit

PhpLowerer first ensures `declare(strict_types=1)`, then lowers typed local and loop declarations, erases generic syntax and throws clauses, and lowers `when` expressions against the original source. It returns GeneratedPhp containing the output, applied edits, and generated-to-original source map. Typed declaration passes replace only the declaration prefix:

~~~php
readonly string $name = 'Andrew';
~~~

becomes:

~~~php
/** @var string $name */ $name = 'Andrew';
~~~

The initializer, variable, comments, newline style, Unicode, and unaffected bytes remain intact. Edits use typed declaration spans, are validated for overlap, and are applied in reverse source order. Ordinary `.php` copies remain byte-identical; `.ppphp` output always gains strict types when it does not already declare them.

EraseGenericTypesPass removes declarations and applications from executable PHP and supplies canonical @template, @param, @return, @var, @extends, @implements, and @use metadata. EraseThrowsClausesPass removes native throws clauses. PhpDocEmitter coordinates one owning-docblock edit so existing descriptions, attributes, unrelated tags, newline style, and @throws metadata remain intact.

`LowerWhenExpressionsPass` consumes each outer containing statement and incorporates nested extension syntax without overlapping edits. It emits prerequisite evaluation statements, deterministic collision-free result variables, ordinary `if`/`elseif`/`else` inside compiler-owned `do` boundaries, literal break depths, and cleanup where control continues. Earlier call arguments and array members are hoisted only when required to preserve their textual evaluation point. No synthetic callable, runtime helper, or compiler-control exception is introduced. Source-edit submappings associate generated conditions, branch results, and temporary uses with their original `.ppphp` spans.

`Compiler` owns the complete production operation used by `BuildCommand`: project checking, global output planning, in-memory artifact emission, manifest/map construction, validation, and commit. `CompilationResult` distinguishes source failure, output failure, and committed success. Output plans label entries as ++PHP compilation or PHP copying and reject project-wide case-normalized collisions plus the reserved `.ppphp/` metadata path.

Each artifact carries source and output identity, operation, contents, generated-to-original map, SHA-256 hashes, relative output path, and source mode. Build manifest format 2 records the internal build and lowering-format identities. A pathless build starts with a role-marked sibling candidate. A directory or focused build safely clones the current output and merges selected entries with a compatible manifest. The candidate receives all new output, maps, and `.ppphp/manifest.json`; new or reconstructed PHP artifacts pass bounded `PHP_BINARY -n -l`; metadata and hashes are revalidated; then directory renames replace the live tree. A project-relative durable journal records Prepared, PreviousOutputBackedUp, CandidateCommitted, and Completed transitions. The next build or clean rolls forward a valid committed candidate or restores a valid prior output and reports `P7014` rather than guessing when evidence is invalid. The stable root `.ppphp-operation.lock` coordinates shared checks with exclusive build and clean operations and survives cache detachment. See [Build Output](build-output.md) and [Production Source Maps](source-maps.md).

Production ++PHP lowering also relocates statically analyzable Composer bootstrap expressions using the resolved Composer `vendor-dir` and the concrete output path. This keeps emitted entry scripts executable without source knowledge of the configured output directory. Other relative includes are preserved, and ordinary PHP copies remain byte-for-byte identical.

## Source Model And Diagnostics

SourceFile retains immutable contents and line starts. Positions use zero-based byte offsets with one-based lines and Unicode-code-point columns. Spans are half-open, may be empty, may end at EOF, and cannot cross files.

`DiagnosticCatalog` owns each code's family, active/reserved status, canonical severity, and Title Case summary. Producers supply only message, source labels, help, normalized debug context, explicit origin, and optional semantic identity. Reserved codes fail at runtime if a producer attempts to emit them.

`DiagnosticProcessor` validates catalog metadata and actionable help, sanitizes user-facing content, deduplicates exact findings, prefers a corresponding compiler-owned semantic result over a backend fallback, suppresses only bounded code/span/identity cascades, and applies one stable sort. Console and JSON renderers consume that same processed sequence and always report original source locations. See [Diagnostics](diagnostics.md).

~~~text
P0xxx  configuration and project errors
P1xxx  lexing and syntax
P2xxx  bindings and local types
P3xxx  generic types
P4xxx  checked errors
P5xxx  when expressions
P6xxx  PHP, Composer, and analysis-backend interoperability
P7xxx  emission and generated PHP
P9xxx  internal compiler errors
~~~

## Current Boundary

The language pipeline implements typed local and loop declarations, fixed and composite types, readonly enforcement, strict .ppphp declarations and output, unsafe-construct restrictions, project symbols, cross-file analysis, checked errors, Composer runtime projection, erased generics, typed arrays, expression-oriented `when`, source-mapped analysis diagnostics, deterministic atomic production builds, mixed-project interoperability validation, and catalog-owned deterministic diagnostics. Generic semantics retain owner-qualified context and dependent bounds through shared member typing, anonymous callbacks, collection flow, focused declaration context, and editor resolution.

The canonical `examples/mixed-application` project and `composer verify:mixed-application` exercise cross-language calls, generic and checked-error metadata, stub enrichment, autoload files, bootstrap relocation, manifest/source-map integrity, optimized Composer loading, and source-free execution. The maintained `tests/Fixtures/GenericContext/ShoppingCart` project exercises dependent generic bounds, generic property hooks and members, collection callbacks, foreach bindings, nested member access, lowering, linting, and execution. The repository also verifies its typed capability catalog, differential golden, process-free compiler analysis, portable Composer and PHP-platform declarations, conservative content-addressed reuse, recoverable transactions, bounded subprocesses, malformed-input hardening, benchmark evidence, immutable RC identity, deterministic assets, and installed-package behavior. There is no entry-point model, dependency-driven tree-shaking, watch mode, deployment bundling, compiler-only build, native compilation, analyzer-default switch, or public compiler plugin API.
