# BP-4: Implement the Browser Check/Build Workflow

Repository: `atatusoft-ltd/ppphp-src`  
Working branch: `develop`  
Prepared: 2026-09-09  
Status: Owner-approved next implementation assignment. BP-4 has not been implemented or verified by this prompt.

## Mission and stop boundary

Implement BP-4 of `docs/ppphp-browser-production-plan.md`: the actual versioned compiler-owned browser Check/Build transport, including full supplemental analysis, snapshot/continuation validation, production emission, source maps, real PHP lint and atomic successful output publication.

BP-3 has been accepted for its recorded Chromium/native profiles. Continue from that implementation and the repaired Asyncify runtime. Do not restart the runtime investigation, survey alternative services or produce another plan instead of code.

The deliverable is a working compiler transport exercised end to end in an actual browser worker, ready for the shared website client to consume. A native-only implementation, copied test adapter, mock analyzer, parser-only lint substitute or collection of draft schemas is not completion.

This task does not implement the production user-program Run interface, website editor integration, deployment or production activation. Those remain BP-5 and later. A minimal browser host and internal disposal/abort path necessary to exercise Check/Build are in scope. Executing reviewed, trusted generated regression fixtures after a successful build is permissible as a test; it is not a public Run service or a claim that BP-5 is finished.

The overall product remains the production playground and Learn experience. For this selected self-managed architecture, visitors' browsers compile and execute; existing hosting serves pages and assets. No remote compiler/runner, serverless execution, automatic remote fallback or general subprocess emulator.

## 1. Starting point and governing inputs

Compiler `develop` was `82bf59727077769327bb23a5cbf99d8e6dff8a75` when this prompt was prepared. It contains BP-3 plus the browser-startup correction. This is an observed input identity, not permission to reset newer work. Fetch and reconcile the actual current branch non-destructively; preserve concurrent changes and record the execution baseline.

Read these existing repository inputs before implementation:

- `AGENTS.md`, `docs/ppphp-mvp-end-to-end-plan.md`, `docs/ppphp-browser-production-plan.md`, and `docs/decisions/0004-mvp-native-analysis-retains-phpstan.md`.
- `docs/browser/bp3-analyzer-parity.md` and `docs/browser/runtime-fiber-repair.md`.
- `src/Cli/Command/BrowserAnalysisCommand.php`, `BuildCommand.php`, and the existing Check command and exit-code enumeration in that module.
- `src/Analysis/Browser/`, particularly `BrowserAnalysisProtocol.php`, `AnalysisContinuation.php`, `ProtocolJson.php`, and the current request decoders.
- `src/Analysis/PhpStan/PhpStanProjectAnalyzer.php`, its parser/mapper/process-result collaborators, and `src/Project/ProjectChecker.php`.
- `src/Compiler/Compiler.php`, `src/Compiler/Output/AtomicBuildCommitter.php`, `ProjectBuildLock.php`, `BuildTransactionRecovery.php`, `OutputPlanner.php`, and their filesystem/journal interfaces and tests.
- `src/Compiler/Validation/PhpLintValidator.php` and its existing runner interface; `src/Transpilation/Emission/ProductionPhpEmitter.php`; the existing manifest, source-map and cache-identity implementations used by these classes.
- `tools/web-spike/fixtures/projects.json`, `scripts/run-project-parity.mjs`, `src/parity-adapter.php`, `src/parity-worker.js`, `src/parity-contract.mjs`, `src/parity-streams.mjs`, and `src/phpstan-debug-output.mjs`.
- `tools/php-wasm-runtime/`, `.github/workflows/php-wasm-verify-artifact.yml`, `.github/workflows/php-wasm-rebuild.yml`, and the applicable existing protocol/build/transaction tests.

Use the current accepted repository contracts, not old planning attachments. Use `++PHP`, `ppphp`, `.ppphp` and the current compiler namespace. Preserve quarterly CalVer and release-channel policy; do not change the release version from the wall clock. Avoid em dashes in authored documentation. Run the identity/documentation checks on the work order too; explaining that obsolete identifiers are forbidden does not exempt their literal appearance from those checks.

### Accepted evidence and its limits

BP-3 records 48 passing cases: 30 compiler fixtures and 18 teaching cases. Real PHPStan runs in 36 cases; preparation rejects or skips the other 12 according to native behavior. Fifteen cases intentionally return compiler status 1. All original runtime controls and eight additional Fiber lifecycle contracts passed for the candidate. Full production Check/Build/Run and other-browser certification were not claimed.

The BP-3 execution used native PHP 8.4.21 on macOS arm64 and browser PHP 8.4.23 in Headless Chrome 153.0.8010.36, with different recorded extension sets. Do not rewrite this as an identical-host comparison. For new runs, record actual host/runtime profiles and use compatible native PHP, preferring the same PHP patch release when available. The initial target remains 8.4 for this verified artifact; it is not an architectural ceiling and must not silently override a requested unsupported target.

### Current build behavior that must survive

At the inspected commit, native Build is an atomic mixed-output operation. `BuildCommand` accepts ordinary `.php` and `.ppphp` selections, and reports compiled and copied outputs. `Compiler::compile()` owns the lock/recovery/check/planning/emission/commit/cache flow. `AtomicBuildCommitter` validates a staged candidate before journaled publication, and `PhpLintValidator` maps actual PHP-lint failures to original source.

Preserve those behaviors. In particular, ordinary PHP participates in mixed output through the current copy rules; do not restore an older rule that excludes it from builds. File and directory builds remain manifest-aware partial builds, not a newly invented whole-project rebuild. Existing collisions, exclusions, unselected context, stale removal, manifest compatibility and retained-output behavior are authoritative.

## 2. Actual runtime and corpus inputs

The optional accompanying `ppphp-bp4-codex-handoff.zip` supplies the work order, the original runtime archives, the original runtime-verification archive, a restoration helper and the exact frozen website corpus. It does not contain the separate raw `ppphp-bp3-evidence.zip`; the accepted BP-3 summary is committed in the compiler repository. Do not claim to have inspected that separate archive unless it is actually supplied.

If continuing in the BP-3 environment, reuse its verified runtime paths. Otherwise extract the handoff to an actual accessible location, read `HANDOFF-README.md`, and run its helper:

```sh
python3 "$BP4_HANDOFF/restore-runtime.py" --bundle "$BP4_HANDOFF"
```

Set `BP4_HANDOFF` yourself to the real extracted directory. The helper returns actual `baseline`, `candidate` and `restoredRoot` paths. It validates original ZIP hashes, reconstructs profiles in a fresh OS-temporary directory, and verifies every manifest-listed file. Restoration is not a new runtime execution result.

| Input | Required recorded identity |
| --- | --- |
| Candidate WASM | SHA-256 `3198aa074ad42bd2fad37d9af80a4a382df743bd1df0651ded4d3d3fc1cb80f9`, 29,461,357 bytes |
| Candidate loader | SHA-256 `7e6653fd2d6cb96cddf681bd7ce932b856635fd127f38f6626e407325136507c` |
| Candidate artifact manifest | SHA-256 `408d2735395885c30df578204a5d029cb6f2f08705689017d5d685d8147e1263` |
| Comparison WASM | SHA-256 `ef597bdbddf37b8f648ffd7f7f95272b387f0255ee0b772040da4c220180a4c7` |
| Runtime build | Run `34297911850`, commit `69dd18e7a884535031576d380e119183c1b979c3` |
| Runtime profile | PHP 8.4.23, Emscripten 4.0.19, explicit Asyncify, O3, assertions and function names retained |
| Locked analyzer in BP-3 | PHPStan 2.2.9; PHAR SHA-256 `42af2954326f2f6f0c39e12eb0e1229b9e037297e2caec9a5db11d44e4f6cce1` |
| Frozen teaching corpus | SHA-256 `066124d08e112c8c8ac16bf795b7639bfaadd34052037b55a8779459bffce17f` |

Do not change dependency locks, edit installed packages or rebuild PHP just to run new fixtures. Build the browser compiler archive from the same modified compiler source and locked dependencies used for the new native references. Compiler version text alone is not a sufficient identity; source/build hashes must distinguish this implementation from the historical input.

If the supplied runtime is unavailable, retrieve build-run artifacts `10084368486` (binaries) and `10084367500` (provenance) using the exact names and assembly procedure in `.github/workflows/php-wasm-verify-artifact.yml`. Compare the ZIP hashes in `docs/browser/runtime-fiber-repair.md`. Those historical artifacts expire. A missing/expired artifact requires the bounded committed rebuild procedure and new recorded identities, never an arbitrary latest runtime. Do not make ordinary required CI depend on expiring historical artifacts or silently install a stock binary containing the old defect.

The supplied `website-corpus.json` is byte-identical to:

```text
Repository: atatusoft-ltd/ppphp-website
Commit: c41628510113084a3534c478876f12a80e2120f6
Path: tests/Fixtures/Browser/website-corpus.json
Content revision: 3e97d5549460ae38bb89acce5d25f53192b329cc
```

It contains six playground examples, eight Learn starters and four existing solutions. Check its full-file hash, format/provenance, case/file identities and each source hash. Mount its project-relative files using the established fixture convention, without adding duplicate `src/` prefixes. Keep authored Check intent separate from new Build observations. Its `requiredActions` includes Run, but that does not make Run completed in BP-4.

The private website application is not required for this compiler task. The frozen corpus is sufficient. Later editor/source changes require a deliberate refresh and rerun, not automatic baseline replacement. Do not modify the website, copy private implementations into the compiler repository, or silently omit the 18 cases. Missing inputs allow independent implementation but leave the affected acceptance rows NOT RUN.

## 3. Implement a versioned full-workflow transport

Preserve existing version 1 Prepare Analysis and version 2 compiler-core analysis, including their response and failure contracts. The proposed new full-workflow version is 3; confirm no concurrent implementation has already allocated it. Implement one coherent internal transport, preferably extending the existing hidden command where appropriate, and document its actual action names and schemas. Do not expose a new public compiler-only command or reinterpret version 2 as full Check.

Conceptually, the new workflow is:

```text
Check:
  freeze/validate project -> compiler preparation -> top-level PHPStan
  -> validated compiler-owned completion -> final diagnostics

Build:
  freeze/validate project -> full analysis for the build selection
  -> compiler-owned production staging -> actual PHP lint
  -> validate results and candidate identities -> atomic publication
```

Model terminal rejection, source diagnostics, infrastructure failures, pending analysis, pending validation and successful completion explicitly. Intermediate success is not final Check/Build success. Include a stable operation ID, snapshot identity, operation, selection, compiler/build identity, runtime/validator profile and protocol version in the appropriate envelopes. Keep terminal diagnostic outcomes distinct from transport-process exit codes using the existing CLI conventions; do not collapse invalid requests, source failures, output failures and internal exceptions into one generic success/failure flag.

Strict decoders must reject malformed types, unknown versions/actions, inappropriate fields, duplicate logical records, invalid phase order and oversized data. Use existing canonical hashing rules, including the `sha256:` prefix where `ProtocolJson` owns the field. Do not confuse that contract with bare digest strings in the external runtime/corpus manifests. Add cross-language contract tests instead of guessing formats.

The browser host executes only compiler-approved top-level operations. It does not accept arbitrary shell strings, paths to executables, PHP bootstrap code or general commands from visitor requests. Workspaces and tool installations remain separate. No arbitrary networking, user PHPStan configuration, Composer scripts or project bootstrap is executed during Check/Build.

## 4. Preserve ownership and freshness across phases

The BP-3 adapter is evidence that existing compiler completion works, not production transport code ready to copy unchanged. Factor the smallest necessary shared orchestration from existing compiler services. Keep parsing, semantic checks, lowering, diagnostic mapping, source maps, artifact planning and manifests in compiler-owned PHP. Do not duplicate `Compiler::compile()` into a browser-only emitter or rewrite diagnostic conversion in JavaScript.

Validate fresh source/configuration/selection identities against each continuation before consuming external results, staging output or publishing a build. Include relevant stubs, Composer/dependency metadata, compiler source/build identity, analyzer configuration/identity and the actual emitted candidate bytes. Cover additions, removals and renames, not only modifications to previously listed files. Include the previous output generation/manifest identity when resuming a partial build.

Do not deserialize persisted ASTs, semantic models or source-map objects as authoritative state. Reconstruct from the frozen inputs through existing services, or retain trusted live state inside one owned session. Hashes establish consistency, not cryptographic proof that a user-controlled browser honestly executed analysis. Do not invent remote attestation or an authentication service for this local tool.

Bind every analyzer/lint result to its operation, phase, snapshot, approved invocation and relevant byte identities. Reject missing, duplicate, stale, unexpected and cross-job results. Replaying a completed phase must either be explicitly idempotent with the exact same immutable result or fail closed; it must never trigger another publication or overwrite a newer generation.

A native file lock cannot be assumed to remain owned after a top-level PHP CLI invocation exits. Make the asynchronous ownership model explicit: serialize conflicting work through a trusted host/session, or use validated resumable transaction ownership with generation checks. Preserve native lock/journal guarantees. Do not hold an unavailable PHP process lock across unrelated instances, deadlock completion, or use an unconditional lock-success stub. Test concurrent/interleaved requests for the same project and stale completion after a newer operation.

The host must deliberately set the PHP working directory. BP-3 found that the CLI `cwd` option did not provide the required behavior; use the proven explicit `chdir` pattern and independently verify the root inside compiler processing. Do not allow caller-supplied roots to escape the fixed session workspace.

## 5. Deliver complete Check with the retained analyzer

Use compiler-owned preparation, the unchanged locked PHPStan and existing mapped completion. The new transport must handle empty projects, early preparation diagnostics, selection-specific context, real supplemental errors and warning-only results.

Retain the strict debug-output framing proved in BP-3. Preserve bounded raw stdout/stderr for debug evidence. Strip only the exact allowed progress paths obtained from compiler-owned selected-file metadata, allowing actual traversal order. Never search forward for convenient JSON or discard arbitrary warnings/noise. The complete framed result still passes the existing PHP parser/mapper and compiler completion logic.

Do not equate PHPStan exit 1 with compiler failure: the warning-only BP-3 case succeeds at compiler level. Conversely, a crash, incomplete JSON, timeout, overflow, missing configuration or failed invocation must not become a clean Check. Keep backend details behind debug output while normal diagnostics retain specific actionable causes and original-source ranges.

Check emits no production PHP and does not publish output. Any internal analysis files remain bounded compiler-owned state. A new Check result must not accidentally authorize a Build for different source, selection, target or compiler bytes.

## 6. Deliver production Build with real output validation

Refactor the smallest necessary boundaries so the browser can complete full analysis and then invoke the existing production planner/emitter/committer without trying to spawn an unsupported native process again. Native defaults must remain the full existing check/build path. Do not add public bypass flags, no-op validators, or a prechecked boolean supplied by a visitor.

Preserve ordinary PHP copying, namespaces, comments/PHPDoc, original mappings, deterministic temporary naming, output collisions, partial-build merging and manifest ownership. Analysis PHP is not production PHP. Do not use the normalized analysis files as final artifacts.

The committed `PhpLintValidator` and runner interface are the starting seam for asynchronous lint integration. Lint each newly emitted/copied candidate according to the current native output contract using actual browser PHP CLI lint or another proven equivalent Zend compile-only path. A second `nikic/php-parser` pass is not sufficient. Compilation/lint must not execute the file's top-level statements.

If the host runs lint between PHP invocations, record the complete invocation result and bind it to candidate relative path, exact content hash, validator identity, target/profile, operation and candidate generation. The compiler must validate the complete expected result set and use its existing lint diagnostic/source-map logic. Reject missing, duplicate or unknown files, truncated streams, failures, incompatible profiles and content changed after validation. Reused retained output needs the existing manifest/hash compatibility checks; it is not authorized merely because a previous request once passed.

Do not publish a candidate to the live output root and lint it afterward. Stage privately, validate, then commit. Retain the previous successful artifact set on every rejected build, with its old snapshot identity. Only the current successful publication may be reported as the output for the current source. Earlier output may remain available as explicitly previous output, never masquerading as current.

Preserve the existing stable lock, journal/recovery, manifest and filesystem mechanisms. Where browser memory-filesystem capabilities differ, implement the narrow adaptation and document its limits. Atomic publication within a live virtual workspace does not promise durable persistence after the whole worker/tab dies. A cache is optional optimization and must never authorize stale output; do not weaken native caching/recovery to simplify browser code.

Expose a bounded artifact descriptor/result containing committed relative paths, bytes or verified retrievable file references, hashes, compile/copy operation, source maps, manifest and snapshot identity. Enumerate only compiler-owned published files; do not expose an arbitrary filesystem-read API or present staging paths as final artifacts. Retained data, invalid UTF-8 handling and total output budgets must be explicit and lossless for supported file bytes.

## 7. Real browser host for qualification, not the website backend

Extend the existing web-spike tooling with a minimal host that drives the production transport. BP-4 acceptance must call the actual new request decoder/command/services, not `parity-adapter.php` or a second test-only implementation that sidesteps them. Retain the BP-3 harness as an independent reference.

Use verified candidate assets and matching compiler bytes. Record loaded WASM/loader identity, compiler archive/build identity and dependency hashes. Use finite per-phase and overall watchdogs, bounded message/output/file sizes, explicit working directories and one owned session per project. Do not treat the generous diagnostic timeouts or 256 MiB PHP allowance as measured production budgets.

Respect CLI lifecycle: a completed CLI invocation is discarded. Do not call runtime exit a second time, including on a shared filesystem host. Retain errors/assertions independently of semantic results. Release each owned worker/session on success, error or abort; verify a fresh operation succeeds after a failure. Do not retain an unbounded number of live runtime instances merely because eventual worker termination clears them.

For sequential Build tests, deliberately retain or restore the previous output generation through the session contract. Fresh unrelated workers with empty filesystems cannot prove that a failed second build preserves the first output.

The test infrastructure serves static assets on loopback only. After hydration, the new Check/Build sequence must work with external networking and execution endpoints blocked. Keep project source and results out of network requests/telemetry. This proves the BP-4 path has no remote compute dependency; it does not certify the eventual website isolation model or mobile browsers.

## 8. Required acceptance tests

Use the same exact fixture bytes and modified compiler source for native and browser references. Keep expected outcomes based on accepted semantics and independent native behavior; do not bless changes automatically. Report any native/runtime profile mismatch explicitly.

### Analysis and compatibility

Run the 30 compiler fixtures and 18 frozen teaching cases through the new full Check transport. Compare the same diagnostic dimensions as BP-3, including complete messages, original byte ranges, labels, help, severity, identity and ordering. Preserve supplemental-only negative findings, multi-file findings, selected context, early rejection, empty-project behavior and warning-only success. Keep version 1 and 2 regression suites green.

### Build equivalence

Build all applicable passing teaching examples/solutions and representative compiler cases natively and in browser PHP. Compare production PHP/copied bytes, artifact membership, maps and manifests under their declared identity rules. Separately identify genuine platform-specific metadata; never scrub semantic output or silently remove fields to obtain parity. Repeat builds of the same snapshot to prove determinism. A null teaching `expected` field is not a golden result: create and review independent native Build expectations.

Cover pathless/file/directory selections, focused `.php` copy builds, mixed projects, untouched manifest entries, invalid previous manifests, stale files, source deletion/rename, output collisions and empty/no-output selections. Ordinary copied PHP must retain native byte treatment. Do not force the analysis-only synthetic fault fixture through a fictitious successful Build.

### The decisive sequential scenario

In one logical workspace:

1. Build valid snapshot A, publish generation A, and retain hashes of all output and metadata.
2. Edit it into invalid snapshot B and Build. Return B's diagnostics, publish nothing, and verify every A output byte remains unchanged and still labelled A.
3. Correct it to C and Build. Publish exactly C's expected mixed artifacts/maps/manifest, including correct stale removal.
4. Deliver a delayed A/B continuation or validation result. It must not change C or be reported as current success.

Add a partial-build sequence with valid unselected context and retained outputs. Treat stale user edits and two competing builds as explicit cases rather than relying on timing luck.

### Lint and publication failures

Inject invalid candidate PHP through a test-only emitter/fixture seam, not a public arbitrary-emission API. Prove actual browser PHP lint rejects it, maps the failure and leaves the previous output untouched. Include a top-level side-effect sentinel demonstrating that successful lint also does not execute program code.

Test real lint success/failure plus labelled synthetic timeout, overflow, abnormal exit, missing/truncated response, duplicate/missing lint records and mismatched validator/file identity. Change a staged file after lint and before commit; publication must fail or require real revalidation. A matching filename alone is insufficient.

### Transport, lifecycle and mutation

Test invalid JSON/types/versions/actions; invalid IDs/phase order; unsafe paths/symlinks where supported; malformed hashes; excessive source/result/artifact budgets; unknown files; and replay. Mutate source additions/deletions, unselected context, configuration, stubs, dependency metadata, selected path, candidate content and previous manifest between phases. Require rejection appropriate to that state.

Inject interruption/failure at staging, awaiting analysis, awaiting lint, and publication boundaries. Use the existing native transaction fault seams for filesystem failures and at least one real browser abort of an owned job. No partial candidate becomes a successful artifact set; the next valid operation recovers. Do not claim crash-durable browser persistence unless implemented and tested.

Use synthetic fault injection where necessary to reach error branches, but keep it labelled. It supplements real analyzer/lint/browser evidence and cannot replace it. No skipped required row counts as PASS.

## 9. Deliverables, checks and handoff

Implement the typed production transport, shared compiler orchestration changes, narrow validation/filesystem/session adapters actually needed, and tests. Keep concrete classes in their owning modules and follow the existing naming/property conventions. Add no empty future framework or unrelated dependencies. Explain a dependency's tradeoff before adding it.

Create or update these proposed documentation outputs:

- `docs/browser/bp4-workflow.md`: actual protocol/actions, ownership/state model, limits, compatibility, tested commands and consumption instructions for BP-5.
- `docs/browser/bp4-evidence.md`: exact commits/hashes, native/browser profiles, separate Check/Build/atomicity/lint results, supported scope and remaining work.
- A repository-local copy of this work order at `docs/ppphp-browser-production-bp4-codex-prompt.md`, so a subsequent agent does not need this chat. Do not overwrite a newer work order.

Update the existing browser plan's status and cross-references accurately. Preserve the historical BP-3 record. Put new schemas, fixtures and scripts in the existing owning modules/test areas and document their real paths. Script/action names not already present are implementation outputs, not commands presumed to exist.

Run the applicable repository gates, including:

```sh
composer validate --strict
composer check
composer verify:distribution
composer audit --locked --abandoned=fail
node --test tools/web-spike/scripts/*.test.mjs
node --test tools/php-wasm-runtime/verify-built.test.mjs
python3 -m unittest discover -s tools/php-wasm-runtime -p 'test_*.py' -v
bash -n tools/php-wasm-runtime/rebuild.sh
```

`composer check` includes version/documentation validation, static analysis and Pest; report any constituent that did not run. Run the existing repaired-runtime controls once for the changed compiler bundle, then BP-3 regression and the new real browser Check/Build/transaction tests. Use the committed workflow ordering for the extra Fiber suite: it consumes the candidate page built by the preceding verification. Avoid redundant PHP rebuilds.

Connect new tests to appropriate bounded CI. Do not modify checks to accept retired names, remove validation, or relabel failure as expected success. Green ordinary CI is not proof that the actual browser workflow suite ran. Provide finite retention and an accessible checksummed evidence archive for expensive/browser runs; no raw logs, profiles or captures inside any user workspace, including sibling repositories. Keep small reviewed fixtures/docs in Git, not runtime binaries, dependency trees or raw execution logs.

BP-4 passes only when the real versioned browser transport completes Check and Build, preserves the accepted analysis corpus, produces native-equivalent validated production artifacts, rejects stale/malformed results, proves sequential failure preservation and passes applicable native regressions. Website Run, UI integration and broader browser/production qualification remain explicitly outside that PASS.

Commit validated work and push it. Local feature branches/worktrees may isolate work, but consolidate into local `develop` before pushing. Push only `develop`; do not push remote feature branches, force-push, discard concurrent work, change `main`, create release refs or publish runtime/compiler packages. Use the normal release workflow later. Do not stop with uncommitted changes merely because subsequent product stages remain incomplete.

Finish with exact commits/push result, files and implemented request lifecycle, tested runtime/compiler/fixture identities, commands/results, new diagnostics and native/browser Build comparisons, the sequential A/B/C proof, lint no-execution evidence, raw artifact location/checksum, CI status actually verified and any FAIL/NOT RUN rows. Supply BP-5 with the actual client-facing contract and limitations, not a proposed substitute. Stop at BP-4; leave production safeguards unchanged.
