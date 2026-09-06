# ADR 0001: Compiler-Owned Project Analysis

- Status: Accepted
- Date: 2026-09-01
- Scope: Stage 13A, with Stage 13B–13C consequence updates

## Context

The native `ppphp check` and `ppphp build` pipeline combines compiler-owned syntax and semantics with a pinned PHPStan backend. PHPStan also checks the compiler implementation through `phpstan.neon.dist`; that development role is unrelated to user-project analysis. The first browser spike proved that the production PHPStan CLI aborts in PHP 8.4 WASM at `_getcontext`, even when invoked as a top-level command without a spawn adapter. Compiler-owned parsing and semantics have no such process requirement.

Before Stage 13A, `ProjectChecker::prepare()` mixed selected-source parsing, declaration-context collection, semantic analysis, lowering, analysis-workspace creation, and PHPStan planning. A successful compiler semantic result therefore could not be represented without an `AnalysisProject`, and the old flow analyzed the selected sources again after workspace preparation.

## Decision

Introduce `CompilerProjectAnalyzer` and `CompilerProjectAnalysis` as the authoritative in-process compiler-core boundary. That result owns the project selection, selected parse result, safe unselected declaration context, semantic models, processed diagnostics, `compilerCore` completeness, and the catalog-derived list of uncovered required capabilities. It has no dependency on `AnalysisProject`, the PHPStan adapter, Symfony Process, subprocess state, or analysis-workspace files.

Treat analysis workspace materialization and PHPStan invocation as a separate supplemental phase. `ProjectChecker` still runs that phase for normal native `check` and `build`; its guarantees and public behavior do not change. PHPStan is constructed lazily only when the supplemental path is actually requested. Browser protocol version 2 calls the compiler-owned analyzer directly and supports Check only. It returns neither a command nor a continuation. Browser protocol version 1 remains compatible for the existing process-oriented experiment.

Treat the typed capability catalog and differential fixtures as evidence. PHPStan is an oracle for mature PHP behavior, not the ++PHP specification. Disagreements are classified as compiler gaps, backend gaps, language-policy differences, optional lint, or fixture errors and are reviewed rather than blindly copied.

Generated local `@var` tags express ++PHP storage declarations, not assertions
that narrow PHPStan's inferred initializer type. The adapter therefore discards
only `varTag.nativeType`, `varTag.type`, and `varTag.variableNotFound` findings on unambiguous generated
declaration lines whose initializer compatibility the compiler has established.
Source-map provenance distinguishes these tags from authored PHPDoc. Unknown
compatibility, authored assertions (including `@phpstan-var`/`@psalm-var`, adjacent comment blocks, and ambiguous same-line findings),
other findings, and ordinary PHP retain their checks. This is a language-policy
difference, not a rule-level change or a user-configurable ignore. Closure
literals retain their nominal `Closure` identity and parameter/return metadata.
The variable-existence distinction covers typed `for` initializers: their tags
declare bindings introduced by the loop, not pre-existing PHP variables.
Regenerated `when` code carries annotation ownership from its generated comment
objects through the verified emitted comment sequence into source maps; authored
comments are never identified as declarations merely because their text matches.
The same policy covers inferred `when` result temporaries, including combined
consumer annotations, only when the result type is known. Unknown or authored
assertions keep the combined comment ineligible. Result temporaries have no null
seed: every valid completing branch assigns a result, and terminating branches
do not reach the consumer.
Fresh-array compatibility follows the effective result paths through `when`,
including nested results and `finally` overrides; any existing-array result
retains invariance. The shared freshness query is used by context, call, return,
property-write, and generated-declaration checks. Finally handling records a
protected result before dispatching completion, with fixed `bool` and
`Throwable|null` control declarations using the same provenance policy.

For ordinary PHP, adopt Model B as the target contract and Model C as the migration vehicle: compiler-owned analysis must be complete for strict ++PHP and for ordinary-PHP declarations/contracts crossing the language boundary; deep ordinary-PHP body analysis remains supplemental until its required subset is deliberately promoted. A `compilerCore` result is never presented as full while required catalog gaps remain.

## Alternatives Considered

- Full mixed-body parity immediately would offer the simplest user promise, but it would require building a broad PHP analyzer before the measured release-critical gaps are isolated.
- Keeping compiler and backend orchestration inseparable would preserve the old implementation shape but would make portable in-process checking impossible and repeat selected parsing/semantic work.
- Replacing PHPStan or calling undocumented PHPStan APIs in process would increase compatibility and maintenance risk while weakening the existing full native path.
- A no-op backend would fabricate completeness and was rejected.
- Exposing a public compiler-only CLI/configuration mode in Stage 13A would let users mistake measured partial coverage for the full guarantee and was rejected.

## Consequences

The browser can perform bounded one-shot compiler checking in a single PHP-WASM process. Native checks and builds still use PHPStan. The codebase has two explicit success dimensions: whether the requested analysis produced errors and whether its coverage is `compilerCore` or `full`.

Stage 13B preserves that decision while making known calls, members, returns, properties, ordinary-PHP/stub contracts, name resolution, and reviewed intrinsics compiler owned. Stage 13C adds the verified target-PHP signature package and bounded installed-Composer declaration context. The post-Stage-13C completion gate adds Composer edge semantics and a source-free dependency index behind the same provider boundary. Catalog version 4 records 37 capabilities and 72 scenarios: 34 Complete, 0 Partial, and 3 Backend-only. Every MVP and Boundary capability is Complete, so `compilerCore` reports `fullParity: true` with no required gaps; the remaining Backend-only capabilities are Optional.

The current runtime dependency placement does not change. `phpstan/phpstan` remains required while the full native path depends on it; `phpstan/phpdoc-parser` remains a direct compiler parsing dependency; `symfony/process` remains required for PHPStan and production `php -l`. Architecture tests keep compiler-core dependencies pointing away from the backend. Any optional package or installation profile requires a separate product decision and explicit native-default, failure, distribution, and upgrade tests.

## Revisit Conditions

The required-capability and parity prerequisites are satisfied by Stage 13C and its completion gate, but that does not itself switch the product default. Revisit the native default after canonical projects are explicitly certified on the compiler-only path, packaging/optionalization behavior is designed, incremental and security hardening is complete, and supported consumers no longer require protocol version 1. Any switch requires a separate decision and may not be inferred from this ADR's completeness metadata.
