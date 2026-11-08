# Analyzer Independence

> **Status:** Compiler-owned project analysis is independent of PHPStan; native full analysis remains the default and any change requires explicit product approval.
> **Evidence:** capability catalog version 4, 72 differential scenarios, 34 Complete, 0 Partial, 3 Backend-only, and no required compiler-only gaps.

## 1. Executive Summary

`CompilerProjectAnalyzer` separates compiler-owned project analysis from PHPStan preparation and execution. It parses the selected source set, collects safe declarations from unselected project sources, runs semantic analysis once, processes stable diagnostics, and returns `CompilerProjectAnalysis`. The result is usable without an `AnalysisProject`, generated PHP, a PHPStan configuration, an executable, a result handoff, or a child process.

This separation is not a PHPStan removal. Normal native `ppphp check` and `ppphp build` still add the supplemental PHPStan phase. Browser protocol version 2 exposes the bounded compiler core and returns `completeness: compilerCore`, `fullParity: true`, and no uncovered required capabilities. ADR 0004 retains the supplemental PHPStan native default for the MVP. No public compiler-only command or configuration mode exists.

The internal [editor diagnostic protocol](editor-protocol.md#unsaved-buffer-diagnostics)
also consumes this compiler-owned result for unsaved buffers without writes or
subprocesses. It explicitly reports `supplemental: false`; required-capability
parity does not imply coverage of optional PHPStan findings. This is an editor
transport, not a compiler-only replacement for human-facing `check` or `build`.

## 2. Current Architecture

The implemented flow is:

```text
ProjectConfigLoader -> ProjectLoader -> ProjectSelector
                                      |
                                      v
                            CompilerProjectAnalyzer
                            - selected syntax
                            - declaration context
                            - semantic analysis
                            - stable diagnostics
                                      |
                   +------------------+------------------+
                   |                                     |
          Browser protocol v2                   native ProjectChecker
          compilerCore response                 supplemental preparation
                                                        |
                                                        v
                                              PhpStanProjectAnalyzer
                                                        |
                                                        v
                                              full ProjectCheckResult
```

`DeclarationContextCollector` owns focused declaration safety. `AnalysisWorkspacePreparer` now consumes a successful compiler result; it no longer discovers the selected syntax or causes selected semantic analysis to run again. `SupplementalAnalysisPreparation` explicitly binds the compiler result to an optional prepared backend project. PHPStan construction is lazy and occurs only on the supplemental path.

## 3. Current PHPStan Responsibilities

PHPStan has two independent roles:

1. Compiler development analysis: the Composer `analyse` script uses `phpstan.neon.dist` to check the compiler implementation. This role is development tooling.
2. User-project supplemental analysis: the normal native check/build path lowers selected ++PHP, supplies declaration-only context, configured stubs, Composer/vendor scan context, and a generated compiler-owned PHPStan configuration. This role contributes user diagnostics.

For user projects, PHPStan supplies optional deep ordinary-PHP body and generator analysis, optional lint, and full-path backend failure handling. Broad target-version PHP core or extension signatures and installed Composer dependency declarations are compiler owned. Backend failure parsing maps to `P6005` and `P6006`.

PHPStan does not define ++PHP syntax, generic identity, type-parameter substitution, checked-error policy, `when` behavior, diagnostic codes, source mapping, lowering, or build output.

## 4. Compiler-Owned Responsibilities

The compiler owns PHP and extension parsing; project discovery and selection; strict ++PHP declaration rules; typed local, loop, property, and collection rules; supported expression types and narrowing; known function, method, constructor, member, and return validation; definite backed-property initialization; ordinary-PHP and stub boundary contracts; reviewed intrinsics; symbol and resolved-name tables; composite types; generic declaration and call inference; checked errors and dynamic boundaries; `when` flow; diagnostics; source maps; lowering; and atomic builds.

Compiler-owned responsibilities include the project-analysis result, completeness metadata, capability catalog, differential corpus, browser protocol, structured type-flow facts, authoritative call and member contracts, verified target PHP signatures, bounded installed-package declarations, Composer edge semantics, and a source-free index through one declaration-provider boundary.

## 5. Browser/PHP-WASM Evidence

The browser spike packages the real repository compiler and Composer dependencies, verifies the archive hash, extracts it into the browser filesystem, and runs it with PHP 8.4.23 WASM in real Chromium. The packaging check also includes the verified PHP 8.4 signature resources and a virtual installed Composer package whose top-level throw is never executed; compiler-owned diagnostics cover both dependency and platform contracts.

The current packaging check requires catalog version 4, `compilerCore`, `fullParity: true`, and no required gaps. Version 2 may consume an explicitly mounted, project-contained portable dependency index after hash and compatibility validation. No spawn handler is installed, no PHPStan plan or continuation is returned, no analysis workspace is created, and `_getcontext` is not entered. The separate version 1/top-level PHPStan experiment reproduces the expected `_getcontext` abort. These observations prove portable compiler-core checking, not browser full analysis, building, preview compilation, or user-code execution.

## 6. Ordinary PHP Policy Alternatives

Model A, full mixed-body parity, would require the compiler to reproduce all release-required deep flow analysis for ordinary PHP immediately. It offers one simple guarantee but expands the analyzer substantially and risks rebuilding optional PHP lint.

Model B, strict ++PHP with contract-oriented PHP, makes the compiler authoritative for all ++PHP bodies and for ordinary-PHP declarations, PHPDoc contracts, stubs, and calls that cross the boundary. Deep ordinary-PHP body analysis can remain supplemental where it does not affect ++PHP correctness.

Model C, native full plus portable core, exposes the same distinction operationally: native full analysis remains mandatory while the portable compiler core grows under differential testing. It is a migration model rather than the final language policy.

## 7. Recommended Target Contract

Adopt Model B as the target contract and Model C as the rollout mechanism. Compiler-owned analysis must become Complete for strict ++PHP and for every ordinary-PHP declaration or effect visible across a ++PHP boundary. Deep ordinary-PHP bodies may remain supplemental until their absence can cause a required false negative; those cases then become compiler requirements in the catalog.

The native full path remains authoritative during migration. `compilerCore` is explicitly incomplete while any Mvp or Boundary capability is Partial or Backend-only. Optional lint differences do not block eventual promotion unless separately approved as product requirements.

## 8. Capability Matrix

The typed catalog and its generated table live in [Analyzer Capabilities](analyzer-capabilities.md). Version 4 contains 37 capabilities: 34 Complete, 0 Partial, and 3 Backend-only. Installed Composer, source-free dependency-index, and built-in-signature boundaries are independently Complete. All remaining Backend-only capabilities are Optional, so there are no required compiler-core gaps.

Every Complete or Partial entry cites an executable fixture. Catalog ordering, unique IDs, diagnostic-code validity, evidence existence, documentation parity, and catalog-version stability are tested.

## 9. Analyzer Architecture

The compiler core now combines:

- Binding: scopes, declarations, captures, definite initialization, and readonly writes.
- Control flow: structured outcomes for branches, loops, return, throw, try/catch/finally, break, continue, and exit.
- Flow facts: null checks, `isset`, `instanceof`, deliberately supported truthiness, union narrowing, and assignment updates.
- Expression typing: literals, operators, calls, members, arrays, closures, `when`, `match`, `new`, and nullsafe access.
- Call resolution: functions, methods, constructors, named arguments, defaults, variadics, references, and generic inference.
- Object model: inheritance, interfaces, traits, visibility, overrides, property hooks, and asymmetric visibility.
- Type system: native types, unions, intersections, DNF, generics, typed arrays, type parameters, substitution, `never`, `mixed`, and `null`.
- Interop: PHPDoc, stubs, Composer metadata, ordinary-PHP declarations, and built-ins.
- Effects: checked errors and dynamic boundaries.
- Diagnostics: stable compiler codes and original source spans.
- Incrementality: exact compiler and supplemental result reuse plus conservative production-artifact reuse through versioned content-addressed evidence.

The detailed implemented semantics are documented in [Type-Flow Analysis](type-flow-analysis.md), [Portable Declaration Context](portable-declarations.md), and [Compiler Cache](compiler-cache.md).

## 10. Structured Control Flow

`AnalyzeTypeFlowPass` uses the smallest structured model required by the measured gaps rather than a speculative SSA graph. `FlowOutcome` records normal completion, returns, throws, breaks, continues, and exits. It covers sequential blocks, conditional and short-circuit branches, loops, switches, `try`/`catch`/`finally`, closures, arrows, property hooks, and `when` results while retaining original AST spans.

Flow joins are deterministic. A terminating `finally` supersedes pending outcomes, possibly empty loops do not prove a return, and generator-specific return behavior remains the explicit optional `flow.generators` gap.

## 11. Data Flow And Narrowing

`FlowState` carries assigned/narrowed local types and definitely initialized properties. Joins union reachable local alternatives and intersect property facts. Supported narrowing includes null comparisons, `is_null`, `isset`, `instanceof`, reviewed `is_*` predicates, and short-circuit operands. Assignment updates facts and by-reference calls restore the parameter contract rather than retaining an unsound narrower fact.

The analyzer must prefer an explicit unknown result to unsound inference. It must not use PHPStan output as internal state. Diagnostic decisions must remain reproducible from compiler-owned facts.

## 12. Call And Member Resolution

`CallableContractResolver` and `MemberTypeResolver` cover project functions, methods, constructors, inherited/interface/trait members, visibility, property hooks, static versus instance access, named arguments, defaults, variadics, by-reference parameters, nullsafe receivers, and generic inference/substitution. The old duplicate `CallableSignatureIndex` was removed. Checked errors and type flow now consume the same contracts.

External unresolved symbols cross an explicit boundary: configured stubs, installed Composer declarations, or the target PHP platform package. A missing name beneath a known installed PSR-4 prefix is diagnosed rather than deferred. Dynamic calls remain `P4005` boundaries rather than being fabricated as statically known.

## 13. PHPDoc And Stub Contracts

The existing PHPDoc parser normalizes `@template`, `@param`, `@return`, `@var`, inheritance, trait-use, and `@throws` contracts. Native ++PHP syntax remains authoritative. Ordinary PHP and configured stubs contribute declarations as data and are never executed.

Compatible stubs enrich matching runtime declarations without a false duplicate. Contradictory native or documented parameters, references, variadics, returns, methods, or properties report `P6012` with both locations. Deep ordinary-PHP bodies remain supplemental, and no large third-party stub corpus is copied into the repository.

## 14. Built-In Signature Strategy

Five approaches were evaluated:

- Hand-maintained compiler stubs are precise and reviewable but costly and easy to leave incomplete.
- PHP reflection generation tracks the current runtime but is nondeterministic across hosts and cannot describe unavailable browser extensions.
- Official PHP metadata generation is deterministic after pinning but needs a maintained importer and semantic normalization.
- Reusing PHPStan stubs is broad but ties the portable core to backend packaging and upstream representation choices.
- A versioned hybrid uses generated official metadata for the configured PHP target plus small reviewed compiler overrides for ++PHP-relevant behavior.

The compiler uses the versioned hybrid. The checked-in PHP 8.4 package is deterministically generated from official `php/php-src` tag `php-8.4.23`, keyed to the configured target, normalized into compiler-owned declarations, verified by manifest and shard hashes, and refined by a small reviewed intrinsic layer. Runtime reflection is not used as a compiler or browser source of truth. See [Portable Declaration Context](portable-declarations.md).

## 15. Dependency/Package Strategy

`phpstan/phpstan` remains a runtime dependency because normal check/build use it. `phpstan/phpdoc-parser` is directly used by compiler-owned PHPDoc parsing and remains core even after possible backend optionalization. `symfony/process` remains required both for the native PHPStan adapter and for bounded production `php -n -l`; process-free analysis does not imply process-free production linting. `nikic/php-parser` and `symfony/console` remain core compiler dependencies.

Architecture tests enforce that compiler-core files do not import the PHPStan adapter, `AnalysisProject`, or Symfony Process, while packaging tests record which dependencies remain runtime and development requirements. A future optional package or installation profile must preserve the native default, provide structured missing-backend behavior, and define upgrade/distribution contracts before `phpstan/phpstan` moves. Do not add another analyzer dependency.

## 16. Differential-Testing Strategy

`tests/Fixtures/AnalyzerParity/scenarios.php` declares stable scenario and capability IDs, virtual project sources, selection, required compiler/full expectations, supplemental full expectations, optional findings, release-blocking status, and expected disagreement class. `AnalyzerParityRunner` runs compiler-owned and full analysis independently. Required parity compares stable code multisets; exact original paths, half-open ranges, and identities remain recorded for review without assuming backend and compiler fingerprints are interchangeable.

Disagreements are classified as `CompilerGap`, `BackendGap`, `LanguagePolicyDifference`, `Supplemental`, `OptionalLint`, or `FixtureError`. The deterministic report contains no timestamp, PID, absolute path, or duration. `composer verify:analyzer-parity` compares it with the reviewed golden; `UPDATE_ANALYZER_PARITY=1` is the only update path. PHPStan is an oracle that can itself be wrong or optional, not the specification.

## 17. Browser Protocol Strategy

Version 1 Prepare Analysis remains compatible and process-oriented. It can prepare Check or Build analysis, materialize the PHPStan workspace, and return a content-addressed continuation and top-level PHPStan command.

Version 2 is a one-shot `analyze` action with `operation: check` and `analysis.engine: compiler`. It invokes the compiler core once and returns normal diagnostics plus compiler identity, catalog version, `compilerCore` completeness, `fullParity`, and uncovered required capabilities. An optional `dependencyContext` names a project-contained portable manifest and SHA-256; omission retains prior behavior. It cannot build, fetch an index, return a continuation or command, or invoke a supplemental analyzer. Protocol version 1 is unchanged, and no public human-facing compiler-only mode is exposed.

The isolated version 3 browser experiment can use a smaller verified declaration context for closed checked-error scripts. The compiler resolves the native `Exception` document through the reviewed PHP signature package, checks project declarations against the complete class and function index, and admits output only after the closed-script proof succeeds. If the reduced context cannot establish the proof, analysis retries with the complete declarations and follows the existing supplemental policy. An idle browser compiler may prepare the verified declaration document and its symbols before project input arrives. The prepared declarations stay in that worker under the existing immutable installation identity. The experiment still uses the version 3 production lint and publication checks.

## 18. Security And Resource Limits

Version 2 accepts at most 16 KiB per request, 256 project source/stub files, 4 MiB total source bytes, 1,000 diagnostics, and a 2 MiB response. Limit failures return a complete structured `resource-limit-exceeded` error; JSON is never truncated. Request files must be regular files contained by the project root.

Compiler-only analysis does not execute user source, project autoload entrypoints, Composer scripts/plugins, application bootstraps, PHPStan configuration, or arbitrary bootstrap files. It performs no lowering or output writes and creates no backend workspace. Browser cancellation terminates the disposable worker; no timing delay is used as a synchronization workaround.

## 19. Incremental-Analysis Strategy

The compiler uses a versioned content-addressed cache under `.ppphp-cache/compiler/`. Its path-independent internal build identity covers executable source/resources and dependency lock state separately from public CalVer. Project snapshots combine normalized configuration, target, selected mode, source/stub content, Composer metadata and lock state, signature-package identity, dependency-index identity, and relevant supplemental identity.

Compiler-core, supplemental, and artifact records are distinct canonical JSON documents that reference hash-validated committed blobs. Exact successful results can replay stable diagnostics, but no semantic model is fabricated. A normal build either retains live semantics or uses complete cached artifacts. Body-only changes reuse unaffected artifacts; public declarations conservatively invalidate consumers. Backend failures are not cached, and compiler-core evidence does not depend on PHPStan representation.

Records contain project-relative identities, have bounded sizes and counts, and are written atomically only after referenced blobs commit. Corruption, truncation, incompatible formats, unknown properties, symlinks, and missing blobs are safe misses. Reachability-aware pruning preserves current evidence. The stable root operation lock prevents build/clean detachment races. Browser protocol version 2 remains process-free and does not expose the persistent cache as a public mode.

## 20. Analyzer Default Requirements

Compiler-owned analysis may replace PHPStan as the native default only when:

- every Mvp capability is Complete;
- every Boundary capability is Complete or has an approved conservative boundary;
- no backend-only blocking finding or known required false negative remains;
- canonical examples have no known compiler false positive;
- mixed-application and shopping-cart verification pass without supplemental analysis;
- generated output still passes `php -l`;
- native full and compiler-only diagnostics agree on every required parity fixture;
- one-process browser analysis, resource limits, and cancellation remain verified; and
- a separate decision explicitly approves the switch.

Optional lint parity is not a default-switch requirement unless product policy changes.

## 21. Rollback Strategy

The default remains the established full path, so there is no user-facing analyzer rollback switch. If compiler-only protocol behavior regresses, disable or remove version 2 while retaining version 1 and native checks; do not weaken normal diagnostics. If a catalog claim is wrong, downgrade it, add a reproducing fixture, update the golden explicitly, and select the gap for migration.

Each future promotion must be reversible at the orchestration boundary: keep compiler and supplemental results distinct, retain the full parity corpus, and avoid storing cache data that only one analyzer can interpret without versioning.

## 22. Known Risks

The compiler still lacks compiler-owned generator-specific yield/return contracts and deep ordinary-PHP body analysis, both Optional capabilities. Portable declarations deliberately cover declared Composer autoload surfaces rather than every possible generated or dynamic loader. PHPStan and compiler results can disagree because of backend defects, supplemental behavior, optional lint, source lowering, or language policy. A catalog can become stale unless fixtures and docs remain mechanically verified.

PHP-WASM payload size and Content Security Policy remain production concerns. The browser gate proves checking only; PHPStan still aborts at `_getcontext`, browser production build is unsupported, and no user code may run in the analyzer worker.

## 23. Explicit Non-Goals

This architecture does not replace or remove PHPStan; move it to `require-dev`; add another analyzer; provide full ordinary-PHP body, generator, or optional-lint parity; expose public compiler-only check/build modes; implement compiler-only build; couple in process to undocumented PHPStan APIs; copy a large third-party stub corpus; execute user code; implement browser production builds; add language features, generic variance, native scalar members, formatter support, a standalone LSP, release automation, or a timing/partial-JSON workaround.
