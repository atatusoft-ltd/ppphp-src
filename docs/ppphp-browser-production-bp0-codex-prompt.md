# BP-0 Implementation Work Order — Compiler and Runtime Baseline

> Programme: Browser-Only Production Delivery
> Repository: `atatusoft-ltd/ppphp-src`
> Working branch: `develop`
> Status: Ready for an implementing agent; this prompt is not execution evidence.
> Scope: The compiler/runtime portion of BP-0, plus integration of the website acceptance corpus when available. These are work packages within BP-0, not new programme stages.

## Mission

Implement and run BP-0 of `docs/ppphp-browser-production-plan.md`. Produce a repeatable browser failure-reproduction harness, exact dependency/artifact identities, native reference results, executable acceptance-contract validation, and an evidence-based handoff to BP-1. Do not stop at another assessment or implementation proposal: write the scripts, fixtures and tests, run them where the environment supports them, and commit the work.

The final programme outcome is complete Check, Build PHP and Run on the production playground and Learn routes, executed entirely on the visitor's device. Existing hosting serves pages and static assets. There is no remote runner, serverless execution, execution API or automatic remote fallback. A reduced Check-only release is not the outcome. The owner intentionally blocked the production pages; those safeguards remain unchanged in this stage.

This work order contains all requirements needed to start in this repository. No chat, attachment, private website checkout or future runtime-fork repository is a prerequisite for the runtime investigation. Website corpus integration is a later input explicitly identified below.

## Read before changing code

Actual existing inputs inspected while preparing this work order:

- `AGENTS.md` and `docs/ppphp-mvp-end-to-end-plan.md`.
- `docs/ppphp-browser-production-plan.md`, especially BP-0 and the invariant product boundaries.
- `docs/decisions/0004-mvp-native-analysis-retains-phpstan.md`.
- `tools/web-spike/README.md`, `package.json`, `package-lock.json`, `vite.config.js`, `src/php-worker.js`, `src/timeout-worker.js`, `src/php-child-worker.js`, `src/drain-aware-spawn-handler.js`, and `scripts/prepare-compiler-bundle.mjs`.
- `src/Cli/Command/BrowserAnalysisCommand.php`, `src/Analysis/Browser/BrowserAnalysisProtocol.php`, the other existing browser request/response/continuation classes and their tests.
- `composer.json`, `composer.lock`, the current compiler identity, analysis capability catalog, PHPStan adapter and production lint/build implementations. Locate these implementations in the checkout rather than inventing class names.

At authoring, compiler `develop` was `0cc6ba1ee3893752821f24769cf585b94ecc6899`. The inspected package pins were `@php-wasm/universal`, `@php-wasm/util` and `@php-wasm/web-8-4` at `3.1.52`, and Vite at `8.2.2`. These are authoring observations, not instructions to reset the branch or override newer approved work. Freeze the actual coherent checkout at execution time and record any difference.

A concrete preflight discrepancy needs investigation: at the inspected commit, `tools/web-spike/src/php-worker.js` rejects `payload.catalogVersion !== 3`, whereas the README says the current gate requires version 4. The compiler-only gate runs before the standalone PHPStan probe. This is a source-level inconsistency, not evidence that a new browser run has failed. First preserve an unchanged run; then verify the authoritative catalog and actual response. Do not blindly change either number or weaken the assertion.

Use only the current repository specifications; historical attachments, retired product names and obsolete source extensions are not authoritative. Current repository contracts and owner-approved amendments govern. Preserve `++PHP`, `ppphp`, `.ppphp`, release-channel semantics and the separation of compiler host, source parsing, analysis platform, emitted target and execution runtime.

## Scope boundaries

This stage may add local test runners, ordinary-PHP probes, browser test automation, a website-corpus consumer, draft JSON schemas with tests, evidence documents and necessary harness-only repairs. Use the existing web-spike instead of creating a second compiler distribution pipeline.

Do not create the PHP-WASM fork yet, implement the Zend/Emscripten backend, alter PHPStan rules, change native Check/Build defaults, expose a public compiler-only mode, add the full production browser transport, or modify website runtime behavior. Those are later stages. Do not patch `vendor`, installed packages, generated WASM or dependency locks to manufacture a passing baseline.

A small pinned development-only browser test dependency is acceptable when existing automation cannot perform the task; explain its purpose and capture the unchanged runtime dependency identities before adding it. Do not replace installed compiler/runtime dependencies during this stage. Install from locks, not `composer update`, unconstrained `npm install` or an unpinned runner downloaded by `npx`.

## 1. Freeze the execution baseline

Inspect local work, branch, remotes and upstream state. Preserve unrelated or concurrent edits. Fetch and synchronize `develop` without destructive resets or force pushes. Use an isolated local checkout/worktree for unchanged execution where necessary; no remote feature branch.

Record a machine-readable baseline identity containing:

- Compiler input commit/tree, tracked lockfile hashes, dirty state and any harness patch hash; distinguish the input commit from the eventual evidence commit.
- Native PHP version, SAPI, integer size, extension list and explicitly selected relevant INI settings; Composer, Node, npm, OS and architecture. Never dump the complete environment or secrets.
- Exact resolved PHP-WASM package versions/integrity entries, loader/glue and WASM SHA-256 values, sizes, actual selected Asyncify/JSPI mode and runtime-reported PHP identity. A version string alone is not an artifact identity.
- PHPStan version and distribution hash from the installed locked package, its effective compiler-generated configuration and exact invocation.
- Compiler archive hash/size and embedded compiler identity; check installed dependencies agree with the lock before packaging.
- Browser engine/version, OS, headless versus interactive execution and actual browser availability. A Node-based WASM run is useful additional evidence, not a browser pass.
- PHP source ref and Emscripten/LLVM/Binaryen/build flags when recoverable from release/package provenance. Unknown provenance is recorded as unknown with the search/evidence used; do not infer that current upstream `trunk` built the pinned binary.

Record the date actually executed. Do not backdate evidence to this prompt's authoring date. Use stable relative paths in committed reports and sanitized summaries, retaining fixture hashes so sanitization cannot change the tested source.

## 2. Run the existing spike unchanged first

From a clean dependency installation, run the existing commands in their actual owning directories:

```sh
# Compiler root; respect the repository's dependency-install instructions.
composer install

# tools/web-spike
npm ci
npm run build
npm run preview -- --port 4173
```

Inspect the existing packaging script before invocation; it may create generated archives and perform dependency-install work. Record those operations and effective flags. Bind the preview to loopback and stop owned processes after the test. Local loopback tooling is a test fixture, not a remotely hosted execution service.

Open the built preview in a real available browser and capture console messages, worker errors, phase events, final evidence, requests for runtime assets and harness deadline behavior. Preserve exact stdout/stderr and browser exception stacks within bounded log files. The build exit code and the browser execution result are separate observations.

If the catalog assertion or another harness failure prevents PHPStan from running, record PHPStan as NOT RUN in that unchanged run. Do not attribute an unexecuted phase to `_getcontext`. Implement independently selectable probes next so unrelated assertions do not mask the runtime blocker. Harness corrections require before/after evidence, focused regression tests and proof from the authoritative compiler contract; do not treat them as runtime repairs.

The existing page can regard an expected PHPStan failure as a successful experiment. Never propagate that status as product readiness. Preserve the historical record and add a separate current BP-0 report rather than rewriting old runs as new evidence.

## 3. Implement independent minimal reproductions

Build a small data-driven runner around the exact baseline runtime. Each failure-prone case runs in a fresh disposable worker/runtime with its own filesystem. Capture pre-case and post-case markers outside PHP so a trap cannot erase the entire report. The parent enforces a finite deadline, terminates the owned worker on a trap/hang and advances to independent cases. Do not wait forever for stdout promises after WASM aborts. Keep queues, diagnostic output and case counts bounded.

Use ordinary `.php` for runtime probes: failure must not require ++PHP syntax or its compiler. At minimum include:

| Case | Purpose |
| --- | --- |
| Plain PHP and JSON | Establish basic execution, stdout/stderr and exit status without Fibers. |
| Platform/extension probe | Observe version, SAPI, integer width, extension inventory, `PHP_BINARY`, Fiber availability and selected runtime mode. |
| CLI lifecycle | Observe one top-level CLI invocation, normal/explicit exit, shutdown output and a fresh subsequent instance. |
| Fiber start/return | Separate context creation from suspension/resumption. |
| Fiber suspend/resume | Assert values and execution order across a switch. |
| Fiber exception transfer | Assert catch/finally behavior and thrown exceptions without altering PHP semantics. |
| Nested Fiber | Distinguish simple from nested context switching. |
| Ordinary cyclic GC/destructor | Observe collection without explicitly creating a user Fiber. |
| Cyclic GC/destructor inside a Fiber | Observe the engine-managed destructor/Fiber path independently. |
| PHAR and filesystem controls | Reproduce the archive/dependency loading conditions actually needed by the spike. |
| Real PHP lint | Run valid and invalid fixtures through actual CLI lint where available, with a top-level side-effect sentinel proving lint did not execute the program. |
| Runaway PHP | Demonstrate an external harness deadline/termination, then a successful clean control run. |

For the smallest Fiber case, a suitable starting fixture is:

```php
<?php
$fiber = new Fiber(static fn (): int => 42);
$fiber->start();
echo $fiber->getReturn(), "\n";
```

Use the same exact fixture bytes for native reference execution, with matching PHP version wherever available. Record a version mismatch rather than silently treating it as equivalent. Do not make a particular GC cycle count or unspecified destructor ordering the oracle. Validate semantic markers/counters appropriate to the fixture and PHP version.

Inspect the exact pinned PHPStan distribution and minimize its failing path. Do not assume a class mentioned in an earlier discussion exists in that distribution. Separate explicit analyzer Fiber usage, Zend GC-created contexts, initialization and process transport hypotheses. PHP documentation describes a distinct GC destructor Fiber path in PHP 8.4; that is a reason to probe it, not proof it causes this crash.

Run PHPStan independently of the compiler-core assertion: prepare a valid virtual project with the real compiler, record the returned command/configuration, and invoke the unchanged pinned analyzer as a fresh top-level command without a nested spawn handler. Also test a minimal ordinary-PHP project with a controlled configuration. Inspect complete output, exit status and any trap; do not accept JSON presence alone as success.

GC-disabled or altered-memory runs may be used only as explicitly labeled causal experiments after the unchanged run. They are not the baseline, a runtime fix, or the planned production configuration. Never mask source diagnostics, edit the PHAR, downgrade PHPStan or disable checks to make the path appear functional.

If symbols are absent, retain artifact identity, raw stack and available function offsets and record the exact debug-build requirement for BP-1. Do not turn BP-0 into a full toolchain rebuild or guess the missing frames. The baseline may locate an unresolved boundary without pretending to prove the complete C call chain.

## 4. Inventory native assumptions

Trace the current browser/compiler flow and record code locations, observed behavior and disposition for every external assumption: PHPStan, real PHP lint, CLI teardown/reset, PHP executable identity, process creation, exit-versus-stream completion, filesystem sharing, locks, rename/atomicity, PHAR, temporary directories, extension use, platform signatures, clocks, Composer metadata and autoload/bootstrap behavior.

Use `portable-observed`, `host-orchestration-required`, `runtime-defect-observed` or `unverified`, with evidence references. A function existing is not proof that its semantics work. Do not equate virtual-filesystem atomicity with durable native files after worker termination.

Check and Build must not execute project PHPStan configuration, project autoload entrypoints, Composer scripts or application bootstrap files. Keep the existing compiler-core and supplemental paths distinct. Record a compiler-core PASS only for that scope, not full Check/Build/Run.

## 5. Native references and website-corpus integration

The website agent independently implements its BP-0 work order in `ppphp-website/docs/ppphp-browser-production-bp0-codex-prompt.md`. Its output is a deterministic teaching-corpus JSON file with the format below. That file is an output to be supplied, not an input already present. Continue all independent runtime work while it is being produced.

Implement a local file-based corpus loader/validator. It must not require website credentials, a live website request or a sibling checkout. Use an explicit caller-supplied path. Reject malformed provenance, duplicate case IDs/paths, unsafe paths, bad source hashes, unknown operations and excessive bytes/counts. Source remains inert until a reviewed fixture is deliberately executed in the bounded native reference runner.

Interchange format `ppphp.browser-corpus`, version 1:

```text
format: "ppphp.browser-corpus"
version: 1
provenance:
  repository: "atatusoft-ltd/ppphp-website"
  contentRevision: full commit containing the exported lesson/example definitions
  sourceFiles: sorted [{ path, sha256 }] for those definitions
cases: ordered [{
  id: "playground/<id>/example" or "learn/<slug>/starter|solution",
  page: "playground" | "learn",
  route: site-relative route,
  exampleId: original example identifier or lesson slug,
  variant: "example" | "starter" | "solution",
  label: original visible title,
  files: ordered [{ path, source, sha256 }],
  entry: site-relative project entry or null,
  entryBasis: provenance/explanation of existing selection behavior,
  intent: { check: "pass" | "diagnostics" | "unknown", basis: textual source reference },
  requiredActions: subset of ["check", "build", "run"],
  expected: null or a separately evidenced expectation object
}]
```

Hash each source as SHA-256 of its exact UTF-8 bytes, preserving line endings. Hash the exported JSON file separately; do not put its own hash inside the payload. JSON formatting/order must be deterministic. `contentRevision` identifies committed teaching content, not the future commit containing the export itself. Do not introduce timestamps or a self-referential commit field that makes every regeneration differ.

The initial exporter may leave runtime/native expectations null. Null/unknown never satisfies a required golden assertion. Capture native Check and Build results with the current full compiler and actual lint. Execute only the reviewed teaching fixtures, locally, with time/output limits and no inherited secrets; do not expose a listening code-execution API or run arbitrary visitor content. Existing intentionally erroneous starters should fail appropriately, not be excluded.

For each fixture retain raw and normalized diagnostics, generated files/hashes, lint status and applicable stdout/stderr/exit status. Match the website's entry semantics rather than inventing a compiler entry setting. Separate authored lesson intent from actual compiler observation: a conflict is a content/compiler issue, not permission to rewrite either side or bless the observed result automatically. Normalize only documented workspace/timing differences, never missing diagnostics or semantic output differences.

Reviewed teaching snippets are the only candidate public corpus data. Do not copy the private website repository, service source, environment/configuration or internal logs into this public repository. Until publication suitability is checked, consume the supplied corpus as a local/private artifact and commit only safe metadata/tests. A private artifact URI is not access for a compiler-only agent; ensure the exact bounded artifact is actually supplied or retain integration as NOT RUN.

## 6. Define and test future contracts without implementing later stages

Create `docs/browser/contract.md` and testable draft schemas/fixtures for browser jobs/results and a static release descriptor. Choose a clearly draft schema identity; do not announce a shipped protocol version or immutable release URL. Preserve current version 1 and 2 transport behavior. A possible full-workflow version 3 remains a proposed BP-4 output, not implemented here.

Specify job/snapshot identity, operation, source/configuration/dependency hashes, compiler/runtime/target identity, diagnostics, generated artifacts, exit status, cancellation, structured resource limits, stale-response handling and unknown-version rejection. Specify that PHPStan results and complete Build validation are required for full Check/Build/Run. Separate runtime/host failures from catalog-owned source diagnostics; do not invent production diagnostic codes.

The release descriptor binds adapter/protocol, compiler/PHPStan, runtime build/loader mode, PHP/target/extensions, limits, artifact hashes/sizes and format versions. No execution endpoint is permitted. Tests reject incompatible identity mixtures, unexpected operations, duplicate paths, invalid hashes, stale revisions and malformed/oversized payloads. Browser draft validation is not claimed to implement compiler continuation completion.

Write the browser matrix with exact available browser observations and remaining required qualification rows. At least one actual browser run is necessary for the runtime baseline. Do not claim later multi-browser production support from this. Numeric production memory/performance ceilings remain BP-5/BP-8 qualification outputs; record measured baseline use and resource assumptions rather than accepting the spike's 1 GiB setting by default.

## Outputs to implement

Use existing project organization, with these canonical documentation outputs:

- `docs/browser/baseline.md`: exact identities, reproduction, raw-versus-harness-corrected distinction, findings and unresolved hypotheses.
- `docs/browser/contract.md`: tested draft job/result/descriptor and corpus contracts.
- `docs/browser/bp0-evidence.md`: case/status table, provenance and links to retained evidence, native references, website-corpus integration state and BP-1 handoff.
- Small versioned machine-readable baseline/report/fixture manifests and ordinary-PHP probes under the existing `tools/web-spike` test tooling or established test fixture area.
- A deterministic runner and documented commands to reproduce the baseline and native references. New command names are outputs to implement and verify, not commands presumed to exist today.

Keep dependency trees, WASM binaries, compiler archives, browser profiles and large traces out of Git. Commit small sanitized reports and scripts; place large evidence in finite-retention CI artifacts or a user-deliverable archive with a checksum. Do not cite only a temporary local path inaccessible to the next agent. Link the BP-0 work order/evidence from an appropriate existing agent/maintainer entry point without rewriting completed-stage history or unrelated plans.

## Evidence semantics and acceptance

Each case records two independent conclusions: (1) whether the reproduction/observation was completed correctly, and (2) whether the runtime/product behavior met its required semantics. Example: reproducibly trapping in `_getcontext` can be PASS for baseline reproduction while remaining FAIL for Fiber/runtime compatibility. Do not label the latter PASS or count expected crashes toward production readiness.

A launcher failure, skipped case, missing browser, missing corpus or unresolved native reference is NOT RUN for the affected behavior, with the concrete reason. An earlier stage failing must not fabricate later-stage results. Capture first-failure evidence; no infinite retries.

BP-0 runtime work is complete when exact artifacts and an actual unchanged browser run are recorded, independent minimal probes locate the observed failure boundary, native controls/reference results exist, and executable contract/harness tests pass. A null build-provenance field may remain only with an explicit BP-1 recovery task; it must not be called a verified build origin. If the known runtime failure no longer reproduces, investigate and document the version/environment cause and current blocker rather than altering the test to force the old answer.

Full BP-0 completion additionally requires the actual website corpus, native reference results for its required cases and its route/action acceptance inventory. Report the runtime work package separately when that integration is pending. Never claim the whole stage passed while required inputs/tests are absent.

Run the applicable existing repository checks, including:

```sh
composer validate --strict
composer verify:version
composer analyse
composer test
```

Also run the new harness/unit/schema/native-reference tests, the actual built-preview browser probes and relevant existing browser-analysis tests. Record new failures separately from unchanged baseline failures. Correct small in-scope harness defects with tests; leave runtime repairs and unrelated compiler changes for their owning stages with evidence.

## Commit, push and final handoff

Stage and commit validated stage work. Local feature branches/worktrees are permitted, but consolidate into local `develop` before pushing. Push only `develop`; never push remote feature branches, write to `main`, force-push or discard concurrent changes. Use the normal `develop`-to-`main` release PR later. A failed runtime reproduction target does not prohibit committing a valid diagnostic harness; preserve its FAIL status honestly.

Finish with exact commits/push result, files changed, reproducible commands, environment/artifact identities, minimal repro IDs, the catalog discrepancy resolution, source-trace conclusions with confidence, separate native/browser/website results, unresolved blockers and concrete BP-1 inputs. Do not create the fork or continue into BP-1 in this task. Do not report the runtime or production pages fixed.

## External technical references

These are investigation references, not substitutes for the pinned artifact's implementation or evidence:

- Emscripten Fiber API: https://emscripten.org/docs/api_reference/fiber.h.html
- PHP Fiber semantics: https://www.php.net/manual/en/language.fibers.php
- PHP 8.4 GC/destructor changes: https://www.php.net/manual/en/migration84.other-changes.php
- The actual PHP-WASM package repository/build is identified by the locked package provenance; inspect the exact corresponding source ref rather than assuming current upstream source.
