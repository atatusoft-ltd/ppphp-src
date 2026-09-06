# ++PHP Browser-Only Production Delivery Plan

> Revision: 1
> Date: 2026-09-06
> Status: Implementation plan; delivery gates are NOT RUN until backed by recorded evidence.
> Product outcome: Enable the production playground and interactive Learn routes with complete browser-side Check, Build PHP and Run.
> Canonical home: `atatusoft-ltd/ppphp-src`, `docs/ppphp-browser-production-plan.md`.

## 1. Objective and non-negotiable boundaries

Deliver the intended interactive experience at `/playground`, `/learn` and `/learn/:lesson` on the production ++PHP website. Compilation, supplemental analysis, generated-PHP validation and program execution must run on the visitor's device.

The existing production guards are deliberate release safeguards. They are not the defect to fix. Remove or replace them only as the final release step after the complete browser implementation passes this plan's gates.

The following boundaries govern every stage:

1. No remote execution server, serverless execution function, execution API, paid remote runner or automatic remote fallback. Existing website hosting serves pages and static assets; visitor actions must not invoke server-side compilation or execution.
2. Deliver full Check, Build PHP and Run through the playground and lesson editor. A Check-only release, prerecorded output or simulated compilation is not completion.
3. Preserve the accepted language and compiler contracts. Keep the pinned PHPStan supplemental analysis under ADR 0004; do not silently remove checks, downgrade the analyzer or redefine compiler-core catalog parity as full native workflow parity.
4. Preserve real PHP output validation, original-source diagnostics, deterministic emission, mixed-source behavior and atomic publication of successful builds. Reuse compiler-owned implementations instead of reproducing language semantics in JavaScript.
5. Repair the runtime portability defect through an upstream-tracking PHP-WASM fork or a verified upstream fix incorporated into that build. Further runtime defects discovered on the critical path remain work in this programme, not grounds for introducing remote execution.
6. Limit scope to launching and maintaining this experience. No new framework integrations, arbitrary online Composer installation, general POSIX emulator, public remote service, replacement PHP engine or PHPStan rewrite.
7. Use `++PHP`, `ppphp` and `.ppphp`. Existing native commands and release-channel rules remain unchanged. Compiler host, source parser, analysis platform, emission target and execution runtime are separate compatibility dimensions.

This is a browser-delivery supplement, not a replacement for the canonical compiler plan, accepted RFCs or framework programme. Historical planning attachments are not required by an implementing agent. Current accepted repository contracts govern wherever historical material differs.

## 2. Evidence baseline and what is not yet established

The source review supporting this plan is dated 2026-09-06. The compiler `develop` head observed during planning was `b9bd29cd3f9eae4ecca7717a41271d71e520c9a5`; other reads were of named `develop` paths. BP-0 must freeze a coherent execution snapshot rather than assume every planning read formed one atomic checkout.

| Evidence | Supported conclusion | Not established by it |
| --- | --- | --- |
| `tools/web-spike/README.md` | Recorded Chromium execution loaded PHP-WASM and performed compiler-owned checking. A separate PHPStan invocation aborted through `_getcontext`. | A repaired runtime, current full-suite browser parity, browser production Build or user-code Run. |
| `src/Cli/Command/BrowserAnalysisCommand.php` | The hidden command dispatches version 1 Prepare Analysis and version 2 compiler-owned analysis. | A complete public browser Check/Build/Run transport. |
| `src/Analysis/Browser/BrowserAnalysisProtocol.php` | Preparation creates analysis state and a content-addressed supplemental continuation, including source/configuration identities. | Successful continuation completion and production emission in the browser. |
| ADR 0004 | Native Check and Build retain supplemental PHPStan analysis. | Permission to remove that dependency for launch. |
| Website `PlaygroundService` and `LearnService` | The existing experience uses the playground API, and lessons include editable starters and optional solutions. | An already integrated browser-WASM execution backend. |

The leading runtime hypothesis is that Zend's selected Fiber backend reaches unsupported context operations. It must be confirmed against the exact shipped dependency set. Do not assert that a particular PHPStan class or call site is responsible without a trace. Test explicit Fibers and garbage-collection/destructor paths independently.

The initial candidate implementation is an Emscripten-specific Zend Fiber backend using Emscripten's Fiber primitives. The documented API requires Asyncify and is similar to, not identical to, POSIX `ucontext` [S7]. This is a proposed implementation route, not a verified patch.

## 3. Delivery architecture and ownership

The existing website serves its normal pages plus immutable, versioned runtime assets. A shared browser controller loads and verifies those assets, freezes a virtual project snapshot, drives compiler phases, and presents results. User PHP runs in a separate disposable execution context.

Logical flow:

```text
Existing website: HTML, editor bundle, immutable runtime/compiler assets
  -> shared browser controller and validated project snapshot
  -> compiler preparation and compiler-owned checks
  -> pinned PHPStan, as a top-level browser PHP invocation
  -> compiler-owned result validation and diagnostic mapping
  -> production lowering into a staging filesystem
  -> actual PHP lint/compile-only validation of emitted PHP
  -> atomic artifact publication
  -> separate, disposable browser PHP execution context
  -> bounded stdout, stderr, exit status and mapped runtime diagnostics
```

### Repository responsibilities

| Repository | Responsibility |
| --- | --- |
| `atatusoft-ltd/ppphp-src` | Compiler transport, compiler-owned completion/emission/validation integration, compiler archive generation, native-versus-browser fixtures and the canonical plan. |
| `atatusoft-ltd/ppphp-website` | Shared browser host, editor and lesson integration, origin/worker isolation, asset delivery, deployment configuration and real-page tests. |
| PHP-WASM fork, proposed name `atatusoft-ltd/php-wasm` | Upstream-derived build recipes, focused PHP/Emscripten patches, Fiber/runtime tests, reproducible runtime artifacts and upstream tracking. This repository is an output to create in BP-1, not an existing input. |

Do not create another repository just for the website adapter. Proposed new website source can live in `src/BrowserRuntime/`, with its build tooling under `tools/browser-runtime/`; finalize placement against the actual Assegai build rather than confusing the existing Web Components framework runtime with PHP-WASM.

### Runtime isolation

Use workers to keep PHP off the UI thread and to make cancellation enforceable by the host. Workers alone are not an origin-security boundary: they can have network and storage capabilities [S8].

The default design for user execution is a sandboxed, opaque-origin iframe with scripts enabled but without same-origin privileges, containing the disposable worker. The trusted parent supplies verified runtime bytes and permitted files through a tightly validated message channel. The iframe is a static asset on existing hosting, not another server. Validate this configuration early on all target browser engines [S9].

Do not give the user-program context the compiler installation, parent storage, application credentials, a filesystem proxy, network proxy, arbitrary JavaScript evaluation or unrestricted host callbacks. Compiler and analyzer contexts also process untrusted input and require strict host capabilities, even though they must not execute project code.

Heavy runtime instances should operate sequentially by default. Cache immutable assets, not uncontrolled live interpreter state. A request may use several short-lived phase instances when CLI shutdown/reset semantics require it; transfer only validated bounded filesystem state between them. Runtime reuse requires explicit reset and contamination tests.

## 4. Stage map and dependencies

Use BP identifiers so this programme does not renumber compiler or framework stages.

| Stage | Outcome | Dependencies |
| --- | --- | --- |
| BP-0 | Frozen baseline, reproducible failures, page/lesson inventory and executable acceptance contracts | None |
| BP-1 | Reproducible upstream-tracking runtime fork and unchanged baseline artifact | BP-0 |
| BP-2 | Correct browser Fiber/context implementation | BP-1 |
| BP-3 | Unchanged pinned PHPStan completes with parity evidence | BP-2 |
| BP-4 | Complete compiler-owned browser Check/Build contract, including real output validation | BP-0 contract work; acceptance requires BP-3 |
| BP-5 | Shared browser host, isolated Run, cancellation and resource containment | BP-0; complete acceptance requires BP-4 |
| BP-6 | Both website experiences use the same browser backend | BP-4 and BP-5 |
| BP-7 | Immutable, cache-efficient production assets and deployment integration | BP-1 packaging; acceptance requires BP-6 |
| BP-8 | Cross-browser, security, parity and production-like qualification | BP-2 through BP-7 |
| BP-9 | Production deployment and guarded activation of both routes | BP-8 |
| BP-10 | Repeatable runtime upgrades, upstream contribution and rollback maintenance | Initial runbook before BP-9; ongoing thereafter |

A solo developer may execute serially. Parallel agents may work on protocol tests, the website host contract and packaging while the runtime port proceeds, but mocks and unrelated passing tests never satisfy downstream real-runtime gates. The critical path is BP-0 -> BP-1 -> BP-2 -> BP-3 -> BP-4 -> BP-5/6 -> BP-7/8 -> BP-9.

## BP-0 — Freeze the baseline and define executable contracts

### Work

Read the current compiler and website agent guides, accepted analysis decision, browser spike, production services/controllers and editor. Record exact repository commits, PHP-WASM package lock, PHP build version, PHPStan version, PHP source ref, Emscripten toolchain identity when recoverable, loader mode, extension inventory and browser versions.

Run the existing spike without changing dependencies. Preserve complete logs and statuses. Minimize the crash using controls for ordinary PHP, `Fiber::start`, suspend/resume, exception transfer, garbage collection/destructors and the unchanged pinned PHPStan command. Capture a symbolized stack when the release artifact is insufficient. Separate runtime traps, malformed output, process transport and ordinary source diagnostics.

Inventory every playground example, every lesson starter and solution, the editor's file operations, Check/Build/Run, Reset/Solve, diagnostics, generated-file display and entry selection. Record expected passing and intentionally failing cases. Export bounded fixtures from the existing lesson/example definitions so tests do not maintain an unrelated second corpus.

Inventory all native subprocess and filesystem assumptions on the browser path: PHPStan, `php -l`, CLI exit behavior, `PHP_BINARY`, locks, atomic rename, temporary files, PHAR handling, extensions, clock/platform probes and autoloading. Classify each as already portable, explicitly host-orchestrated or needing a runtime fix.

Define the browser job/result schema, release-manifest schema and supported-browser matrix before implementation diverges. Inventory and test existing byte/count limits; do not silently inherit the spike's 1 GiB PHPStan setting as a production allocation policy.

### Outputs to create

In the compiler repository: `docs/browser/baseline.md`, `docs/browser/contract.md`, a machine-readable fixture inventory and baseline test reports. In the website repository: a route/lesson acceptance inventory. These are proposed outputs, not files an agent must already possess.

### Exit gate

The failure reproduces with exact identities and minimal tests; native reference results are captured; every intended page action is represented; no production safeguard is changed. Record uncertainty rather than promoting the Fiber hypothesis to fact prematurely.

## BP-1 — Establish the fork and reproducible builds

### Work

Fork `WordPress/wordpress-playground`, the source of the packages already used by the spike. Confirm the proposed fork name is available. Preserve upstream history, licenses and notices. Maintain a narrow downstream patch series: PHP portability, necessary build/loader changes and tests.

First reproduce an unmodified compatible runtime. Freeze PHP source, Emscripten/LLVM/Binaryen, dependency source checksums, package lock, build-container identity and all configure/link flags. Record any unavoidable nondeterministic build metadata; do not claim bit-for-bit reproducibility without comparison evidence.

Produce both a diagnostic build with useful symbols/assertions and a production-configured candidate. The initial candidate selects Asyncify explicitly. Do not let loader feature detection choose an untested JSPI artifact. Build definitions are parameterized by PHP version; the original PHP 8.4 reproducer is not an architectural ceiling.

Define required extensions from the actual compiler, PHPStan and lesson corpus. Disable unnecessary networking/JavaScript host integration in the execution profile. Do not remove an extension merely to obtain a passing build when a required workflow uses it. Keep profile differences explicit and tested.

Use distinct downstream package/artifact identity rather than impersonating `@php-wasm` upstream releases. Keep the compiler's established CalVer/channel semantics intact; a separate immutable runtime build identifier can record upstream commit plus patch/build identity.

### Exit gate

A clean build environment produces a traceable baseline runtime; the known failure still reproduces; ordinary PHP and compiler-core controls still pass. Every distributed artifact has checksums, source/build provenance and required notices. No unexplained edits to installed `node_modules` or vendor binaries are part of the recipe.

## BP-2 — Implement and qualify the runtime repair

### Work

Once the trace confirms the context-backend defect, add an Emscripten-specific backend at Zend's low-level Fiber boundary. Adapt context creation, root-context initialization and switching to the documented Fiber primitives; do not pretend POSIX context functions succeed.

Preserve Zend's existing VM-state capture/restore, active Fiber tracking, exception/bailout state, observer behavior and lifecycle semantics. Own both required stack allocations explicitly. Handle stack limits, initialization failure, completed-context cleanup and request teardown. The completion trampoline must transfer control correctly rather than return into invalid state. Never free the currently executing stack.

Audit Asyncify instrumentation, indirect calls, callback paths, PHP setjmp/longjmp behavior and interactions between Fiber switching and the runtime's asynchronous host operations. Establish correctness with conservative instrumentation before optimization. Do not use arbitrary sleeps or retries to conceal context or transport defects.

Port relevant upstream PHP Fiber tests into an automated browser-capable harness. Include start/suspend/resume/return, values and exceptions crossing switches, nested/symmetric use where supported, invalid lifecycle operations, suspended-Fiber destruction, finally/destructors, garbage-collection interactions, fatal exits, repeated creation and stack exhaustion. Compare with the same PHP release natively. Audit skipped tests: every skip needs a reason and must not hide a required semantic capability.

If the initial mechanism reveals another Emscripten defect, minimize it and carry a localized toolchain/runtime patch or verified compatible toolchain change. Preserve the browser-only architecture and the original failure evidence.

### Exit gate

Required Fiber tests pass in real Chromium, Firefox and WebKit-based test environments, with production optimization flags as well as the diagnostic build. Repeated stress tests reveal no unbounded leak or corruption within the defined workload. A passing trivial Fiber example alone is insufficient.

## BP-3 — Restore complete pinned PHPStan analysis

### Work

Run the unchanged pinned PHPStan package as a fresh top-level browser PHP invocation. Keep its effective rules, compiler-generated configuration, declarations and source context equivalent to the native reference. Serial execution is acceptable as scheduling, not as a reduction in analysis.

Resolve any subsequent PHAR, extension, stream, filesystem, memory, runtime-probe or output-flush defects. Do not fork analyzer rules or select an older analyzer just to bypass the failing path. Capture stdout/stderr separately with proper completion/EOF ordering and retain exit status. Valid JSON alone is not success; validate the complete schema and error state. Truncated output is a structured failure, never a partially consumed result.

Compare passing and failing projects, ordinary PHP, ++PHP boundaries, generics, checked errors, generator/body-analysis cases and the supplemental failure contract. Test analyzer initialization failures and resource limits as well as successful analysis.

Classify platform differences explicitly, including PHP version, extensions, filesystem case behavior, integer width and floating-point edges. Measure `PHP_INT_SIZE` and platform probes in the actual artifact. Do not advertise universal 64-bit-native behavioral equivalence from a WebAssembly smoke test. Required lesson semantics may not be waived away as platform differences.

### Exit gate

The current complete required corpus receives equivalent diagnostic meaning, source locations and pass/fail outcomes after only documented nonsemantic normalization. The old expected PHPStan crash becomes a positive regression test. No analyzer timeout, missing output or backend crash is reported as a clean check.

## BP-4 — Complete the compiler-owned browser workflow

### Work

Preserve existing version 1 preparation and version 2 compiler-core analysis behavior. Introduce a new versioned full-workflow contract where necessary; version 3 is the proposed next version, subject to checking the implementation at stage start. Do not mutate version 2 into a different promise.

The host orchestrates a fixed set of compiler-approved operations, not arbitrary shell commands:

```text
Check: snapshot -> prepare -> PHPStan -> validate/complete -> diagnostics
Build: full Check -> production lowering/staging -> real PHP validation -> publish
Run: successful Build for the same snapshot -> isolated program execution
```

Keep preparation, continuation validation, diagnostic mapping, lowering, source maps and build-manifest construction inside compiler-owned PHP code. Reuse native mechanisms through appropriate execution/filesystem abstractions. Do not introduce regex transpilation, duplicate semantic checks or a second browser-specific PHP emitter.

Bind every job to compiler identity, runtime identity, target profile, configuration, all relevant source/dependency hashes, operation, selection and snapshot revision. The host must reject changed inputs, stale continuations, mismatched result identities and replay from another job. A content hash provides consistency, not proof that an untrusted client is authoritative.

Implement actual PHP lint/compile-only validation of generated files in browser PHP, without executing their top-level statements. Prefer the runtime's real CLI lint path; another implementation must prove the equivalent Zend compilation/error behavior. A second pass through `nikic/php-parser` is not a substitute. If lint requires another top-level invocation, orchestrate it explicitly rather than enabling general subprocesses.

Publish output only after all selected files validate. Retain the previous complete artifact set if a new build fails, but mark it as belonging to its old snapshot and never run it as the current program. Preserve ordinary `.php` treatment and source-set selection exactly as the current compiler specifies. Browser in-memory atomicity must not be described as disk durability after worker termination.

Check and Build never execute project bootstrap, user PHPStan configuration, Composer scripts or project autoload entrypoints. Trusted compiler dependencies are distinct from project dependencies. The Run phase may execute permitted application initialization only within its isolated execution environment.

### Exit gate

Browser Check matches the full native contract. Valid builds are deterministic, contain correct manifests/maps and pass real PHP validation. A deliberately invalid emitted-PHP fixture fails validation without running its side effects. Invalid or edited projects never emit or execute stale/partial output. Native CLI behavior and existing transports remain green.

## BP-5 — Build the shared browser host and isolated Run

### Work

Implement one reusable client API consumed by both page types. Model initialization, readiness, checking, building, running, cancellation, failure and disposal explicitly. Separate asset loading from timed compute work. Every response carries job and snapshot identity; late messages cannot overwrite a newer edit or lesson.

Start with one foreground job per editor/session and a bounded queue. Run starts a fresh user-code worker containing only the successful build, its permitted runtime dependencies and a bounded scratch filesystem. Do not run user PHP in the compiler/analyzer instance. Cache trusted bytes or compiled modules where supported, not user state.

Put external watchdogs in the host, outside the PHP worker. Cancel or timeout terminates every worker belonging to the job and clears pending callbacks. Dispose on navigation/unmount. Reject late success after a deadline. Treat browser suspension and OS tab termination honestly: a browser cannot guarantee a precise hard-real-time wall-clock bound or always report an OS kill.

Use a fixed trusted bootstrap for the opaque-origin iframe and its worker. Pass source as data, never as JavaScript code. Validate the handshake using the expected window/source, an unguessable per-session token and a dedicated MessageChannel; an opaque frame's `null` origin is not sufficient authentication. Reject unknown message types, oversized payloads, arbitrary resource requests and unexpected artifacts.

No general PHP-to-JavaScript evaluator, filesystem proxy, outbound networking, shell/process launcher, external URL include or host filesystem mount is exposed to the program. Apply CSP to the actual iframe/worker contexts, not only the parent document. Hydrate execution resources from verified parent-provided bytes so the execution context does not need outbound connections. Test `connect-src 'none'`, script/worker restrictions and the narrowly required WASM compilation permission; do not solve loader warnings by broadly enabling JavaScript `unsafe-eval` [S8-S10].

Display program output and diagnostics as escaped text. Printed HTML is output, not application DOM. Do not add an HTML preview surface unless the existing accepted experience actually requires it and its isolation is separately tested. Imported/shared source never auto-executes without a user action.

### Initial containment policy

These are implementation starting points, not measured production-performance claims. Freeze final bounded values in one tested host policy before release.

| Resource | Initial policy |
| --- | --- |
| User project | Retain the current website's 12 files and 64 KiB aggregate source budget unless the inventory proves a required fixture needs a reviewed change. |
| Compiler/analyzer result | Preserve existing protocol bounds, including the 2 MiB result envelope where applicable; never truncate JSON. |
| Program output | 128 KiB combined stdout/stderr; cap queued messages and terminate the producer on overflow. |
| Compute deadlines | Begin with the website's 20-second compiler and 8-second program deadlines, excluding initial asset download; tune from browser evidence, not by removing checks. |
| Memory | Set explicit PHP memory limits, WASM maximum pages, stack sizes, artifact limits and aggregate active-instance limits after measuring the required corpus. A per-instance cap alone is not the whole-browser memory budget. |
| Concurrency | One heavy phase instance active at a time by default; bounded worker count, queues, file bytes and diagnostic count. |

No release with unbounded memory growth, runtime retries or download loops. A 1 GiB diagnostic-spike setting is not an approved production default. JS heaps and browser overhead must be measured separately from WASM linear memory.

### Exit gate

An infinite loop, output flood, deep recursion, memory exhaustion, fatal exit and malicious host-capability request cannot escape the permitted environment; cancellation recovers the UI; the next valid run succeeds without contamination. No source, output or derived workspace files are sent to an execution endpoint.

## BP-6 — Integrate playground and Learn without feature loss

### Work

Update the actual website integration points: `src/Playground/PlaygroundService.php`, its controller/component, `src/Learn/LearnService.php`, its controller/component and `src/WebComponents/PlaygroundEditor/PlaygroundEditorComponent.wc.ts`. Keep presentation in the existing Assegai/Twig/Web Components architecture.

Replace the editor's execution-API dependency with the shared browser client and verified release descriptor. Keep server-side page/lesson rendering, but remove production wiring to native execution. The old development API must remain unavailable in production and must never be selected automatically after a WASM failure.

Preserve multi-file editing, example selection, Check, Build PHP, Run, diagnostics, generated files, Reset/Solve and lesson navigation according to the BP-0 inventory. Entry selection is a website execution concern; do not change the compiler's project/selection semantics to manufacture an entry point.

Render the editor and lesson text before heavy runtime initialization. Show actual loading stages and actionable failure states. Keep edits when a runtime fails; provide a local retry with bounded attempts. Expose the actual compiler/runtime/target identity without implying an unavailable target is supported.

Use the existing lesson inventory as acceptance data. Intentionally broken starters must show the intended diagnostics; Solve must produce the expected valid project. Test actual `when` lessons for branch processing before the yielded result, not only trivial `match`-like examples. Do not rewrite failing examples merely to conceal a missing feature.

### Exit gate

Every inventoried action works through both real page types. Every lesson starter and solution behaves as intended. No test substitutes a mock compiler or a native backend for the browser implementation. The production guards remain in place while this is qualified in a staging configuration.

## BP-7 — Package and deploy immutable assets with bounded delivery costs

### Work

Create a release descriptor binding website adapter/protocol version, compiler release/commit, PHPStan lock identity, runtime build, PHP/target profiles, loader mode, extensions, artifact hashes, decoded sizes, limits and source-map/build format versions. Deploy one coherent descriptor and artifact set; reject incompatible mixtures.

Build compiler archives from explicit allowlists. Include required compiler PHP, resources and production dependencies, but no website source/configuration, credentials, `.env`, local paths, tests or unnecessary packages. Treat the intentionally published compiler archive as a distribution artifact: serve it as a static archive, not as a directory of host-executable `.php` files. Audit archive extraction for absolute/traversal paths, links, duplicate names, count limits and expanded-byte limits.

Produce browser/worker bundles without unresolved Node-only imports. Remove unnecessary Node paths rather than accepting production `worker_threads`/`events` externalization warnings. Audit upstream dynamic-evaluation warnings and required host bridges.

Build expensive WASM artifacts in bounded CI jobs or the developer's build environment, never on a visitor request or the production PHP host. Do not rebuild an unchanged runtime for every website text change. Pin external actions/toolchains, limit retries and retention, and scan dependencies/artifacts.

Serve assets from existing hosting using content-addressed URLs, correct WASM/JavaScript/archive MIME types, HTTPS, compression and tested headers. Immutable hashed assets can use long-lived immutable caching; the small active-release descriptor must revalidate rather than become a permanently stale mutable URL [S11]. Hash the documented canonical payload bytes consistently across transport compression.

Use HTTP cache first and bounded browser asset caching only where useful. Quota denial or private browsing must still allow an in-memory session. Evict corrupt assets, verify replacements, and bound retries. Cache eviction never removes the current job's required state. A runtime upgrade invalidates incompatible artifacts by identity, not filename coincidence.

Lazy-load the runtime only for interactive use. Loading the home/docs/blog routes must not fetch the compiler. Sharing one artifact identity between Learn and playground permits cache reuse. A warm, hydrated session must Check/Build/Run with network access blocked. Full cold-start offline/PWA support is not required for this launch.

Record actual compressed cold-download bytes and warm network behavior. Model delivery cost as cold asset bytes times cache-miss visitors plus normal page traffic; do not describe static hosting as cost-free. Use existing access/CDN logs where available; no source-bearing telemetry or new billable execution infrastructure.

### Exit gate

The deployed candidate loads with production headers and paths, including nested lesson routes. Warm actions do not refetch large assets or call any execution endpoint. Corrupt/partial assets, quota failures and mismatched manifests fail safely. Build and distribution notices/source obligations are reviewed for every included license; do not relabel upstream-derived bundles as Apache-2.0 merely because the compiler uses it.

## BP-8 — Qualify the actual production-shaped experience

### Test layers

1. Runtime: required PHP Fiber/lifecycle tests, repeated switches, exceptions, GC and production optimization settings.
2. Analyzer/compiler: complete supplemental analysis, source-mapped diagnostics, negative fixtures, deterministic production artifacts, real lint, native regression suites and platform compatibility boundaries.
3. Browser host: message schema, immutable snapshots, stale-response rejection, cancellation, output/memory/file bounds, corrupted caches and clean subsequent runs.
4. Website: real editor actions and every lesson starter/solution through actual routes, including Reset/Solve, navigation and nested asset resolution.
5. Security and privacy: denied network/host bridges, origin/storage isolation, malicious filenames, arbitrary printed HTML, no source transmission, no production execution API and no fallback URL.
6. Deployment/performance: production-served artifacts, compressed bytes, cold/warm timings, worker/JS/WASM memory, repeated sessions, low-memory devices and upgrade/rollback behavior.

The intended release matrix includes current and previous supported desktop Chrome/Edge/Firefox/Safari releases plus representative current Android Chrome and iOS Safari. Record exact browser/OS/device versions at test time. Playwright WebKit is valuable automation but is not evidence of real Safari/iOS qualification. Required rows marked NOT RUN or FAIL block the corresponding release claim; do not silently narrow the matrix to the one browser that happens to pass.

Use current real lesson/example behavior as the product oracle and native compiler results as the compiler oracle. Normalize only workspace paths, timing and other declared nondeterminism; never normalize away missing diagnostics, output changes or exit failures.

For the no-remote proof, record a cold page trace, hydrate assets, deny execution-API requests, disconnect the network for the hydrated session, and complete Check/Build/Run. Assert that raw source, encoded source, program output and project snapshots do not appear in requests, query strings, telemetry or crash reports.

Performance reports must separate network load, WASM compilation/instantiation, archive extraction, compiler preparation, PHPStan, emission, lint and execution. Compare repeated-run memory trends; do not label increasing allocator capacity a proven leak without ownership evidence. Freeze release budgets from the required corpus and actual supported devices before BP-9. Remaining budget TBDs are release blockers.

### Exit gate

Every required acceptance row has PASS evidence tied to exact artifacts and versions. No runtime or analyzer expected-failure test is being counted as product success. No mandatory row is waived through mocks, server execution or weaker semantics.

## BP-9 — Enable the production routes and verify delivery

### Work

Promote immutable runtime/compiler assets first. Verify availability, hashes, MIME types, compression and isolation headers from the actual hosting path. Deploy the compatible website build while the deliberate production safeguards remain active.

Run the BP-8 smoke corpus against a restricted production-shaped preview using those exact assets; it must exercise the browser backend rather than a development-only server path. Then switch one release configuration to enable the new browser-backed playground and Learn routes together. The default production editor must have no native execution backend configured.

Verify `/playground`, `/learn`, representative nested lessons and the full automated lesson set on the production host. Check one valid and one invalid project, generated PHP, Run output/status, cancellation, fresh-user asset loading and a warm no-network session. Confirm the historical `/playground/api` execution path still rejects production execution attempts.

Keep the previous compatible immutable descriptor/artifacts for rollback. If activation fails, revert the descriptor/website pairing or restore the existing guards. Never route traffic to a remote runner as recovery. Preserve user drafts where safe and keep ordinary website pages unaffected.

### Exit gate

Both production experiences are enabled and demonstrably work end to end, exclusively on the visitor's device. Deployment-specific behavior is included in the evidence. A merged PR or a local preview alone is not completion.

## BP-10 — Maintain the smallest sustainable fork

Keep a patch inventory with upstream origin, rationale, tests, applicable PHP versions and removal condition. Submit focused upstream reproductions and fixes; upstream acceptance is desirable but is not a launch dependency.

Qualify PHP security updates, PHP-WASM/Emscripten updates, analyzer updates and browser-engine changes against the same gates before moving the production descriptor. Add newer supported PHP profiles without hard-coding the initial baseline into the adapter. Maintain explicit compiler-host/target/execution compatibility rather than silently selecting a newer runtime.

Reuse immutable artifacts and build caches; run fast verification for ordinary website changes and the heavier runtime matrix for runtime/toolchain changes. Use finite CI timeouts, concurrency and artifact retention. Never publish a replacement under an existing immutable identity.

Retire downstream patches once a verified upstream release contains equivalent fixes. Keep the browser contract, lesson fixtures and no-remote tests so a dependency upgrade cannot reintroduce the original failure or an execution fallback.

## 5. Completion checklist

The programme is complete only when all of the following are true:

- Production playground, Learn root and lesson routes expose the intended interactive experience.
- Full Check includes the retained supplemental analysis; Build emits validated PHP; Run executes that successful current snapshot locally.
- All existing required examples, intentionally failing starters and solutions pass their intended assertions.
- Runtime context switching, analyzer execution and complete compiler behavior have real-browser evidence.
- Diagnostics map to original source; generated artifacts are deterministic and published atomically; failed builds never execute stale output.
- User execution is isolated from application state and host/network capabilities; output is not interpreted as website HTML.
- Runaway/erroring jobs terminate or fail within the tested containment policy, and the next normal job recovers cleanly.
- Exact release/runtime/target identities, resource ceilings and supported-browser rows are recorded; none remain implicit or NOT RUN.
- Warm hydrated Check/Build/Run works with the network blocked. No remote execution endpoint, serverless runner or automatic fallback exists.
- Static assets are reproducible/traceable, integrity-checked, cacheable, appropriately licensed and deployed through a tested rollback procedure.
- User source and output are not uploaded or recorded in telemetry. Any non-source operational metrics use the existing bounded infrastructure only.
- Production activation, not merely runtime patching, is recorded as PASS.

## 6. Execution and handoff rules

Every stage handoff must be repository-accessible and contain its scope, actual existing inputs, outputs to create, commands, acceptance tests and unresolved findings. Do not reference a chat attachment as though it exists in an agent checkout.

Before a stage, inspect current `develop`, confirm prerequisite evidence and reconcile concurrent work. Use coherent commits for each acceptance report. Report PASS, FAIL and NOT RUN separately; retain commands, versions, logs and fixture identities. Planning text is never evidence that a test ran.

Stage implementation should be staged, committed and pushed after validation. Local feature branches are allowed for isolation, but consolidate into local `develop` before pushing. Push only `develop`; never push remote feature branches, force-push over concurrent work, or bypass the normal `develop`-to-`main` release PR. Do not prohibit commits/pushes in future agent prompts. Production activation occurs only in BP-9 through the website's release workflow.

Keep changes proportionate for a solo maintainer: one canonical plan, one evidence index, focused patches and automated gates. Do not create ceremony-only services, empty future scaffolds or parallel implementations.

Existing commands verified from repository documentation include the browser spike's `npm ci`, `npm run build` and `npm run preview`; compiler checks include `composer validate --strict`, `composer verify:version`, `composer analyse` and `composer test`; website checks include `composer validate`, `composer test` and `assegai wc:build`. Run the full applicable repository suite as well as new browser tests. New build/test script names are outputs of the owning stage, not commands claimed to exist today.

## 7. References and input locations

The repository paths below are actual inspected inputs. Additional `docs/browser/*`, runtime-fork files and website adapter/tooling paths described above are outputs to create. Recheck source identities at BP-0.

- [S1] Compiler browser spike: `tools/web-spike/README.md`, `tools/web-spike/src/php-worker.js`, `tools/web-spike/package.json` and lockfile in `atatusoft-ltd/ppphp-src`.
- [S2] Compiler transport: `src/Cli/Command/BrowserAnalysisCommand.php`, `src/Analysis/Browser/BrowserAnalysisProtocol.php`, and existing browser-analysis tests in `atatusoft-ltd/ppphp-src`.
- [S3] Analysis policy: `docs/decisions/0004-mvp-native-analysis-retains-phpstan.md`, `AGENTS.md`, `docs/ppphp-mvp-end-to-end-plan.md` and accepted language documents in `atatusoft-ltd/ppphp-src`.
- [S4] Website: `src/Playground/PlaygroundController.php`, `src/Playground/PlaygroundService.php`, `src/Learn/LearnController.php`, `src/Learn/LearnService.php`, `src/WebComponents/PlaygroundEditor/PlaygroundEditorComponent.wc.ts`, `AGENTS.md` and `README.md` in `atatusoft-ltd/ppphp-website`. Website-only source is not required to begin the runtime port; BP-0 records the shared acceptance inventory for agents without website access.
- [S5] Upstream runtime build: https://github.com/WordPress/wordpress-playground/blob/trunk/packages/php-wasm/compile/php/Dockerfile and https://github.com/WordPress/wordpress-playground/blob/trunk/packages/php-wasm/universal/package.json . These moving references must be pinned for actual builds.
- [S6] Zend Fiber implementation: https://github.com/php/php-src/blob/PHP-8.4/Zend/zend_fibers.c . Inspect the exact PHP ref used in each build, not only this baseline branch.
- [S7] Emscripten Fiber API: https://emscripten.org/docs/api_reference/fiber.h.html and asynchronous build mechanisms: https://emscripten.org/docs/porting/asyncify.html . Current documentation is guidance; verify behavior against the pinned toolchain.
- [S8] Worker lifecycle, capabilities and CSP: https://developer.mozilla.org/en-US/docs/Web/API/Web_Workers_API/Using_web_workers .
- [S9] Iframe sandbox behavior: https://developer.mozilla.org/en-US/docs/Web/HTML/Reference/Elements/iframe .
- [S10] CSP script/WASM permissions: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/script-src .
- [S11] Immutable asset caching and revalidation: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Cache-Control .
