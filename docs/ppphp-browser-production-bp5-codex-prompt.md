# BP-5: Shared Browser Client and Isolated Program Run

Date: 2026-09-09
Status: Implementation work order. BP-4 is owner-approved for its recorded profiles; BP-5 is not implemented or qualified by this document.
Primary application repository: `atatusoft-ltd/ppphp-website`
Compiler/runtime and independent regression repository: `atatusoft-ltd/ppphp-src`
Working branch in each repository: `develop`

## 1. Mission and stop boundary

Implement the reusable browser client that will serve both the playground and Learn editor. It must orchestrate the actual protocol 3 Check/Build implementation and execute a verified successful current build in a separate, disposable, restricted environment. Demonstrate the complete Check -> Build -> Run lifecycle through the production-intended client in an isolated development/test page.

Write and test the implementation. Do not return another design-only proposal. Use the accepted runtime repair and compiler transport; do not restart the alternatives survey or reproduce completed stages as substitutes for this assignment.

The eventual product is the functioning production playground and interactive Learn experience. For this self-managed route, compilation and execution stay on the visitor's device. Existing hosting serves static runtime/client assets and normal website pages. No remote execution endpoint, serverless runner, hosted fallback, or public native-PHP execution service may be introduced.

BP-5 owns the reusable client, safe Run, host capability restrictions, cancellation/disposal, bounded resources, and their real-browser qualification. BP-6 owns connecting that client to the actual playground/lesson components and routes. Do not rewire those components, change their design or content, remove production guards, deploy, publish packages, or enable public execution in BP-5. Test fixtures may mount the real new client without becoming public application routes.

## 2. Accepted baseline and coherent checkouts

The compiler head inspected for this handoff is `5517fa4393ad355ae27bcb256904cc9bc9aaa739`. Its aggregate CI run `34351477623` (CI #221) and diagnostic run `34351477611` completed successfully. These are that commit's results, not evidence for future changes or a new full browser run.

The website head inspected is `fefee1569f0caaf7e07a643677e136e28856b0f1`. The website has ongoing editor work. Preserve it. These identities locate the inspected inputs; they are not instructions to reset a newer checkout.

Read actual status, remotes, current agent guides and changes before editing. Freeze coherent source identities for the work and distinguish committed inputs from local changes. The qualified BP-4 implementation includes the separately integrated native memory/dependency work; do not undo that integration or apply an older compiler archive over it. If newer changes affect the compiler, client or teaching contract, reconcile and qualify the combined source rather than borrowing an older PASS.

The approved BP-4 evidence records, on native PHP 8.4.21 and browser PHP 8.4.23 in Chrome 153 on macOS arm64:

- The 15 repaired-runtime and eight additional Fiber controls passed.
- Independent BP-3 regression passed all 48 cases.
- Protocol 3 passed 48 Check comparisons and 33 eligible Builds with deterministic repeats. The other 15 cases were analysis-negative/fault cases, not successful Builds.
- Sequential A/B/C publication, stale completions from distinct A/B operations, 12 publication guards, actual PHP lint without top-level execution, and abort/recovery passed.
- The aggregate local gate passed 1,196 tests and 7,874 assertions.

These are the recorded approved results, not an assertion that this handoff author reran them. Other browsers, whole-browser memory, origin/CSP containment, production Run, page wiring and deployment are not certified by BP-4.

## 3. Read the existing inputs and respect ownership

In `ppphp-src`, read:

- `AGENTS.md` and `docs/ppphp-mvp-end-to-end-plan.md`, respecting subsequent accepted amendments and current code rather than historical attachments.
- `docs/ppphp-browser-production-plan.md`, particularly BP-5 and the BP-6 boundary.
- `docs/browser/bp4-workflow.md`, `docs/browser/bp4-evidence.md`, `docs/browser/bp3-analyzer-parity.md`, and `docs/browser/runtime-fiber-repair.md`.
- The current protocol 3 implementation and tests reached from those documents, including `BrowserWorkflowTest`. Discover actual owning classes from the checkout rather than inventing an API.
- `tools/web-spike/src/workflow.js`, `workflow-worker.js`, `workflow-contract.mjs`, `parity-streams.mjs`, and the independent BP-3 adapter/runner.
- `tools/web-spike/scripts/run-workflow.mjs`, `run-project-parity.mjs`, `run-fiber-contract.mjs`, and their applicable tests.
- `tools/web-spike/fixtures/projects.json` and `tools/web-spike/fixtures/website-corpus.json`.
- `tools/web-spike/scripts/prepare-compiler-bundle.mjs`, both dependency locks, `tools/php-wasm-runtime/`, `.github/workflows/browser-workflow.yml`, and the retained-artifact/rebuild workflows.

In `ppphp-website`, read:

- `AGENTS.md`, `README.md`, `composer.json`, `composer.lock`, `assegai.json`, and the actual frontend/test build configuration.
- `docs/ppphp-browser-production-handoff.md`.
- `src/WebComponents/PlaygroundEditor/PlaygroundEditorComponent.wc.ts` and its existing framework runtime, as integration consumers to preserve, not files to rewrite now.
- The existing Playground/Learn services, components and tests as needed to understand the public result types, entry selection and lesson lifecycle. Do not bootstrap production services to export source.

Application-specific client ownership remains in the website repository. A proposed location is `src/BrowserRuntime/`, with development/build/test support under `tools/browser-runtime/`. These are outputs to create if no appropriate implementation already exists. Follow the actual Assegai/TypeScript organization. No new repository, framework migration or React application is needed.

Reuse/refactor the proven compiler-host mechanics deliberately, separating reusable code from test fixtures. The current workflow worker contains test-only invalid-emitter and fault options: those must not be present in a production client or its distributed worker. Keep the independent BP-3/BP-4 tests and their intentional fault seams as tests. Do not duplicate PHP semantic analysis, lowering, diagnostic mapping or publication in JavaScript.

Use both authorized checkouts in the same Codex environment where available. Test/build tools may accept explicit compiler/client roots; never assume a sibling path or a private repository is available to public CI. Transfer only intended compiler distributions and reviewed fixture data. Do not copy private website implementation into the public compiler repository. Missing access must be reported with the affected task NOT RUN, not solved by silently relocating application ownership or building a second host.

## 4. Runtime and fixture inputs

Reuse the exact repaired Asyncify runtime already restored for BP-4. No PHP rebuild is needed for ordinary client or fixture changes. The supplied optional BP-5 bundle contains the original runtime archives and unchanged restoration helper, not an already-built BP-5 client or current compiler archive.

| Input | SHA-256 |
| --- | --- |
| Candidate WASM, 29,461,357 bytes | `3198aa074ad42bd2fad37d9af80a4a382df743bd1df0651ded4d3d3fc1cb80f9` |
| Candidate original Asyncify loader | `7e6653fd2d6cb96cddf681bd7ce932b856635fd127f38f6626e407325136507c` |
| Candidate artifact manifest | `408d2735395885c30df578204a5d029cb6f2f08705689017d5d685d8147e1263` |
| Baseline WASM | `ef597bdbddf37b8f648ffd7f7f95272b387f0255ee0b772040da4c220180a4c7` |
| Original binary ZIP, artifact `10084368486` | `00d50cf068ef5ec574fd33656cf2646da6c74a27a9c17be4ed0c9c6def70ff2a` |
| Original build evidence ZIP, artifact `10084367500` | `7aed9154a8bb51f776853d456d10ea89079e5b4240aef86fc66caf3924f49d34` |
| Original runtime verification ZIP | `d7b14c9afe39badc8037333c74a3e1821905ec8d0e54b1843d045d4d0dab377c` |
| Frozen 18-case teaching corpus, 23,688 bytes | `066124d08e112c8c8ac16bf795b7639bfaadd34052037b55a8779459bffce17f` |
| Compiler lock at BP-4 | `c8efe1b23e4ac3afc82c55f47c0654d2dcdc5f0d9f26fe5e489c29a0a330d3e1` |
| Browser lock at BP-4 | `709d3cbd573fef86493640a8970d7ca6edbfc05df4472c8913c0c51c0cbedbff` |
| Locked PHPStan 2.2.9 PHAR | `42af2954326f2f6f0c39e12eb0e1229b9e037297e2caec9a5db11d44e4f6cce1` |

The original build is run `34297911850` at `69dd18e7a884535031576d380e119183c1b979c3`. The runtime repair record and `.github/workflows/php-wasm-verify-artifact.yml` provide the exact retrieval and assembly procedure. Expired artifacts must not be replaced by an unrelated latest download. The optional bundle avoids that dependency. Set paths to actual accessible files; a chat URL or another machine's temporary path is not a mounted input.

The separately delivered raw BP-4 evidence ZIP is not in the optional handoff unless explicitly supplied by the owner. The uploaded qualification summary and committed documents are available. Do not claim to have inspected the raw report if you have only the summary, and do not relabel the original 23-case runtime report as BP-4 evidence.

Regenerate the compiler archive from the actual coherent current compiler checkout and locked dependencies using its existing packaging tools. Do not reuse a stale BP-3 archive because the runtime itself is unchanged. Record compiler build identity, dependency hashes and final browser bundle/worker hashes separately from the original loader hash. Never overwrite installed dependencies to substitute a candidate runtime.

The frozen corpus is now committed in the compiler repository. It contains six examples, eight starters and four solutions. Four starters are intentionally invalid; the other 14 cases are intended to execute. Preserve authored source, file order, exact bytes and provenance. Do not silently refresh the corpus from newer editor work. Run expectations are a BP-5 output to establish, not supplied goldens.

## 5. Consume the existing Check/Build contract

Protocol 3 remains the compiler transport. Do not introduce a compiler protocol 4 or a new public CLI Run command solely to build the browser client.

Preserve these implemented requirements:

- Fixed request file `.ppphp-browser/request.json` and actual PHP cwd `/workspace`. Call `chdir` explicitly; the previously ignored CLI option is not sufficient.
- `start`, `complete-analysis`, `complete-lint` and `abort`, with their exact versioned fields and ordered invocation/result identities. Only compiler-approved invocations run. No user-controlled shell command or generic filesystem API.
- Operation identity, increasing sequence, snapshot, compiler build, runtime/loader identity, selection, target, continuation, and verified file set remain bound throughout. Use the existing strict JSON and byte validation rather than weakening it to JavaScript shape checks alone.
- The currently qualified protocol supports the PHP 8.4 target and observed CLI runtime identity. Unsupported combinations fail explicitly. Keep this profile data-driven; do not claim new PHP targets or mistake the 8.4 qualification for an architectural ceiling.
- CLI exit 0 indicates a structured response, not a successful Build. Run eligibility requires a successful terminal Build response, `compilerStatus: 0`, and verified `currentOutput` for the current project. `previousOutput` never grants Run eligibility.
- Check never publishes. Warning-only success remains success. Analysis failures, output failures, rejected requests and infrastructure failures stay distinct.
- Each phase has fresh PHP ownership. Terminate its worker and verify job ownership before accepting any returned workspace bytes. Do not call runtime exit again after CLI shutdown or retain live interpreters across jobs without a separately proven reset contract.
- Respect protocol limits, including its 256-used-operation limit and maximum sequence. Rotate to a fresh workspace/epoch before exhaustion, preserving editor source safely but never restoring old continuation authority.

Source supplied by a caller cannot provide `.ppphp-browser` control files, pending results, caches, manifests, prior compiler output, the operation lock or journal. Separate project input from host-owned state. Cancellation or edit invalidates eligibility before asynchronous cleanup, hashing or response delivery can race with it.

## 6. Implement one reusable client

Provide a small typed API for initialization, Check, Build, Run, cancellation and disposal, with observable state and typed results/events. Exact class/module names are implementation choices. Avoid a framework-specific global singleton, generic event-bus dependency or application service scaffolding without an immediate consumer.

The client must:

1. Accept a validated project snapshot as data, copy/own its bytes, and identify the editor/session epoch. Async hashing must not race with caller mutation. Include relevant config, stubs and approved dependency identities, not only visible text.
2. Load and verify runtime/compiler assets lazily through an explicit trusted asset descriptor. Share verified immutable assets between clients, not mutable files, PHP instances or output authority. Bound cache retention and retries. A host-supplied asset URL is not a visitor-provided fetch request.
3. Drive real protocol 3 phases and retain exact byte snapshots as the serialized workspace. Treat the BP-4 qualification UI as a reference, not an unrestricted production API.
4. Expose truthful loading, ready, checking, building, running, cancelling, failed and disposed behavior. A source diagnostic is a usable terminal outcome, not an unusable client. A cancellation settles exactly once and leaves a recoverable client. Disposed instances cannot accept jobs.
5. Enforce one heavy phase at a time per page by default, including when two consumers exist. Keep pending work bounded, with a documented busy/supersession policy. Never silently execute an outdated queued Run. Cancellation is owner-scoped, not permission to kill another client's active job.
6. Retain at most the bounded current project, current operation and identified prior successful output needed by the contract. No unbounded transcript, snapshot, timer, listener, Promise or result history.
7. Dispose on consumer teardown/navigation, close ports, terminate workers, revoke object URLs and release references. Recreate on return from navigation where needed; no stale back/forward-cache job may regain authority.

Default `run(snapshot, entry)` should perform a complete pathless Build for that exact snapshot unless the client already holds an equally valid complete verified Build under identical identities. A successful Check is not enough. Keep focused Check/Build available without manufacturing full-project authority from a partial manifest.

Entry resolution must preserve the existing website contract in the corpus: prefer `main.php`, then the first applicable source-derived PHP file, then the existing verified artifact fallback where applicable. Document and test the exact ordering rather than auto-requiring all output files or assuming every example is `main.ppphp`. Only a safe regular PHP file in the eligible verified output may be an entry. Explicit caller selection must obey the same membership checks. No entries in compiler control or source-map metadata.

## 7. Establish the execution boundary before admitting user PHP

Implement and test the planned sandboxed opaque-origin frame, with `allow-scripts` but without `allow-same-origin`, containing the disposable program worker. The frame/bootstrap is a static application asset, not another server. Qualify the actual WASM loader, worker type, blob/module URLs, CSP inheritance and byte-transfer arrangement early; do not assume opaque-origin worker startup works just because a same-origin harness works.

Use a fixed trusted bootstrap. Source, filenames and stdout must never be interpolated into executable JavaScript, HTML, `srcdoc`, import expressions, CSS, URLs or CSP. The host supplies verified runtime bytes and the approved artifact snapshot over a dedicated channel. If blob scripts are needed, they contain only the hashed trusted worker bundle, not user code.

Authenticate initialization using the expected frame `WindowProxy`, the documented parent origin where observable, a fresh per-session random token and a dedicated MessageChannel. An incoming origin of `null` is not authentication. Document any unavoidable wildcard target used only to initialize the known opaque frame. Close/disable general window-message initialization after the authorized channel is established. Check current frame, worker, epoch, job and message type before processing payloads or adopting bytes. Do not leak tokens into logs or URLs.

Enforce CSP in the actual execution frame/worker, not only the parent page. Document the exact effective policies and test them. Default-deny network, objects, connections, forms, navigation capabilities and other unneeded channels. Add only the narrow script/worker/WASM permissions required by the verified bootstrap. Do not broadly add JavaScript `unsafe-eval`, same-origin privileges, permissive CORS or global website headers just to make the loader start.

Audit the retained runtime/glue and guest-callable extensions. Disable or remove guest PHP-to-JavaScript evaluation, arbitrary host callbacks, networking/proxy helpers, dynamic extension loading, process launching, host filesystem mounts and shared application storage before executing PHP. A replaced `fetch` function, `open_basedir`, disabled PHP function list or PHP memory limit is not the entire boundary. Explicitly check other reachable mechanisms, including stream/cURL/socket wrappers, JavaScript bridges, worker creation and storage APIs.

Distinguish loader internals that execute trusted initialization from a guest-callable capability. Audit upstream warnings rather than mechanically replacing direct eval with indirect eval. Required internal initialization is not permission to expose general JavaScript execution to a visitor. Small necessary adapter/build-profile corrections are allowed with focused tests and recorded identities; disabling assertions or widening privileges to hide a failure is not.

The compiler/analyzer also consumes untrusted input and must have no network/credential/host-filesystem bridge. Its private compiler files/control state are never made reachable from the program instance. Do not send the full compiler workspace into the execution frame. The program cannot return a replacement workspace, manifest, Build result or analyzer/lint result to the host.

A frame creation/handshake/CSP failure is a visible initialization failure. There is no same-origin privileged or remote fallback. Any different isolation mechanism requires explicit review with equivalent guarantees; do not silently abandon the approved boundary to claim completion.

## 8. Run only approved build bytes and return honest results

Before execution, verify the exact `currentOutput` file membership, per-file bytes, manifest, source maps, snapshot, compiler/runtime identities and complete-project status against host-owned state. Copy the admitted byte set into a fresh program filesystem. Do not transfer away or mutate the retained host copy. There is no general host-read RPC and no fallback to source files when an output is missing.

Only the verified program artifacts and explicitly approved runtime dependencies are mounted, with a bounded ephemeral writable area. Compiler dependencies such as PHPStan are not application dependencies. Keep relative include paths and file layout intact; do not synthesize automatic `require` calls that change execution semantics. Start the verified entry through fixed runtime APIs or a fixed CLI argument vector, not a shell or dynamically generated JavaScript/PHP launcher containing source text. Provide a minimal environment and noninteractive stdin/EOF unless a separately documented existing example requires otherwise.

Any guest writes remain disposable and cannot replace the host publication or leak into the next run. Bound file counts, bytes and growth as well as return/export size. Never export the guest filesystem back as compiler state. The program must not access the compiler archive, source-only files, pending artifacts, snapshots, other sessions, cookies, parent DOM or application storage.

Return a structured program result with job/snapshot/build identity, selected entry, actual exit status when observed, stdout/stderr, termination reason and bounded timings. Separate program nonzero exit/uncaught failure from runtime initialization failure, WASM trap, timeout, cancellation and output/memory exhaustion. Never infer success from nonempty output, parse stdout as a control message or invent exit 0 after termination. Empty successful output is a terminal success.

Capture stdout/stderr concurrently with bounded batches/backpressure and a shared byte budget. Count bytes before queuing/copying them, and keep the UI event queue bounded under floods. Handle UTF-8 split across chunks. Keep arbitrary program bytes distinct from protocol JSON: invalid program UTF-8 must not corrupt control parsing or make the client hang. Preserve bounded raw bytes; render a safe explicitly described textual representation where necessary. Never truncate analyzer JSON to meet a program-output limit.

All displayed output, filenames, exceptions and diagnostics remain text. Printed HTML, SVG, escape sequences, fake result JSON and links must not execute or become privileged UI. No live HTML preview is part of this task. Do not turn arbitrary stdout into source-mapped diagnostics. Map genuine available runtime locations through the exact verified source map using compiler-owned mapping facilities where possible; preserve raw output separately and leave unknown locations unknown. Do not invent diagnostic catalog codes in JavaScript.

Imported/shared source and lesson selection never auto-run. Only an explicit Run action from the intended consumer can authorize program execution.

## 9. Cancellation, resource limits and measurement

Cancellation must work during hydration, compiler preparation, PHPStan, lint, publication delivery and program execution. Watchdogs live outside the executing worker. On cancellation, revoke the job/epoch before accepting more messages, terminate all job-owned workers, then clean up. Protocol abort may be used only for the still-current compiler operation; cleanup must never submit an old abort against a newer operation. A wedged worker is killed rather than waiting for cooperative PHP code.

Do not depend solely on an acknowledgement from a potentially unresponsive execution frame. Provide and verify a bounded teardown path that stops its worker and prevents stale messages even when cooperation fails. If teardown cannot be established, fail the client closed rather than overlapping another heavy job. Browser suspension and OS process termination are not precise hard-real-time guarantees: describe and test observable recovery honestly.

Start from these distinct policies, then freeze tested BP-5 values in one owned configuration:

| Dimension | Starting contract |
| --- | --- |
| Editor source | 12 files / 64 KiB, separate from protocol 3's broader internal transport capacity |
| Protocol bounds | Retain current strict request/response, 64-artifact / 1 MiB, 1,024-record / 16 MiB workspace and diagnostic limits |
| Compiler timing | Existing 90-second phase / 360-second operation limits are qualification ceilings, not a promised user experience. Preserve until evidence supports a deliberate host-policy change. |
| Program time | Start at an 8-second externally enforced execution deadline, separate from bounded hydration/build time |
| Program output | Start at 128 KiB combined stdout/stderr, with finite chunks, event count and queued bytes |
| Concurrency | One heavy phase active per page by default; bounded admitted/pending work across consumers |
| PHP/WASM/JS memory | Explicit measured limits per role, plus aggregate active-instance, workspace, asset and message-copy budgets |

The retained binary has 64 MiB initial and 2 GiB maximum linear memory per instance, plus a 1 MiB stack; BP-4 used a filesystem host and CLI runtime together. Those are observed build parameters, not an approved aggregate production budget. PHP's 256 MiB analyzer limit is not a cap on guest native allocations, JavaScript memory or all active WASM instances. User code may try to raise PHP-level settings; an enforced runtime ceiling must remain.

Verify whether the pinned loader permits a genuinely smaller memory maximum or imported Memory configuration. A configuration property that is ignored does not satisfy this requirement. If a compile-time constraint or reachable host bridge makes a limited runtime build-profile change necessary, record the minimal cause and change through the existing pinned build tooling. Rebuild only for that runtime change, assign new hashes, and rerun the original runtime/Fiber and independent BP-3/BP-4 suites. Do not edit generated binary bytes, remove semantic checks or rewrite the working Fiber backend without evidence.

Measure cold hydration, build, execution, cancellation and repeated-job behavior on the actual test profile. Record active runtime counts, initial/peak linear-memory sizes and applicable JS/process measurements with their scope. Browser/process measurements are not necessarily per-client attribution; missing or unsupported APIs remain unknown. Do not enable broad deployment headers or experimental browser flags solely to manufacture a production memory claim.

Require finite allocation/file/message limits and actual stress evidence, not only metrics. Test growth beyond the chosen ceiling, output floods, deep recursion, infinite loops and repeated runs. Avoid dangerous deliberate whole-machine exhaustion. Explain which limit stops each fixture and how the next job recovers. Check growth trends over repeated batches; distinguish retained allocator capacity from demonstrated leaks. No unlimited retries, queue or asset cache. Wider device performance certification remains BP-8.

## 10. Executable acceptance

Create a test/development page importing the actual reusable client and production-intended workers/bootstrap. It may simulate two consumers for playground and lesson lifecycles, but must not substitute a second execution backend. It is not a new public website route. Retain a deterministic, checksummed suite manifest and distinguish native references, synthetic transport attacks, real compiler behavior and real browser containment.

Required groups:

1. **Independent regressions.** Rerun BP-3 and BP-4, including the complete 48-case corpus, eligible Builds, deterministic repeats, sequential A/B/C and all publication guards against the combined compiler/host inputs. Do not rewrite their oracles to fit the new client.
2. **Real teaching Run.** For all 18 frozen cases, exercise the actual client. The 14 intended valid examples/starters/solutions build and run with independently reviewed native expected stdout, stderr and status. The four invalid starters return their compiler diagnostics and launch no program worker. Cover mixed includes, non-main entry names, `when` branch processing and the solution with empty output. Establish these expected outputs using reviewed first-party source under bounded local native execution, not visitor code on a public service.
3. **Authority and edits.** Run A successfully, edit to invalid B and reject Run without executing A, then correct C and run only C. Deliver stale A/B analysis, lint and Run messages and stale workspace bytes after C starts. Include source/configuration/entry changes while awaiting hashes, Build completion and runtime startup. No old response or buffer can replace current state or grant eligibility.
4. **Artifact checks.** Reject a Check-only result, failed Build, previous output, partial/incompatible manifest, changed PHP/map/manifest bytes, additional/missing files, unsafe or nonmember entry and cross-session references. Synthetic objects labelled complete are not valid Build authority. Protect retained byte buffers against caller mutation/transfer races.
5. **Termination/recovery.** Test user cancel and deadlines in each meaningful phase; fatal PHP exit, explicit nonzero exit, WASM/runtime failure, output/FS/memory limits, malformed UTF-8 output, initialization failure and disposal. At each relevant fault boundary, the next valid Run must work and no previous program files/state may persist. Late output after cancel is ignored. A protocol abort failure is distinct from a successfully killed worker.
6. **Isolation.** With browser networking available and instrumented local test sinks, prove guest attempts cannot reach application-origin and external endpoints, parent credentials/storage/DOM, another editor, compiler files or general JS/host capabilities. Cover guest wrappers and any reachable bridge identified by the audit. Separately inject hostile channel messages and printed markup/control-looking output. Missing capabilities may be documented, but do not count a failed environment setup as an executed attack test.
7. **Offline/privacy.** Hydrate and verify all needed assets, then block application networking and complete Check/Build/Run and a repeated Run. Inspect traffic from the page, frame and workers. Source, encoded source, output, credentials and project snapshots must not enter URLs, requests or telemetry. The local browser automation channel is test control, not an execution service. Offline success alone does not prove CSP denial; keep the online denial test separate.
8. **Lifecycle/load.** Exercise two consumers, repeated edits/actions, bounded busy/queue policy, navigation/unmount/remount, workspace rotation at the operation-ID boundary, malformed channel messages, cache misses/corruption, retry caps, and cleanup. Record whole-operation responsiveness and memory behavior.

Run the full new suite on an actual available Chromium/Chrome profile. Prototype the isolation bootstrap on Firefox/WebKit where available and record exact results. No Safari/iOS/Edge/mobile claim is inferred from Chrome or automated WebKit. Broader supported-browser release gates remain BP-8. A required isolation, memory enforcement or current-snapshot Run test that is FAIL/NOT RUN prevents BP-5 qualification for that profile; do not declare a working unsecured demo complete.

## 11. Validation, evidence and delivery

Use existing locked dependencies. Explain and pin any genuinely necessary development/build dependency, reusing existing tools first. Do not install unconstrained test tools, update compiler PHPStan for convenience, change versions from the clock or introduce remote execution. Preserve `++PHP`, `ppphp`, `.ppphp`, `atatusoft/ppphp`, canonical namespaces and existing quarterly CalVer/channel semantics. Keep source transformations in the compiler.

Add this full work order to `ppphp-src/docs/ppphp-browser-production-bp5-codex-prompt.md` with the implementation unless a newer instruction already occupies that path. Include a repository-local website handoff containing its full requirements or a supplied verified work-order copy so the next website agent does not depend on chat attachments or inaccessible private paths.

Proposed outputs, not existing inputs:

- The reusable website client, fixed execution bootstrap/workers, typed contracts, asset integration and bounded policy in the owning feature area.
- Native/unit and real-browser client tests, plus an isolated fixture page and explicit local/CI invocation.
- `ppphp-website/docs/browser/bp5-client.md`: API, protocol 3 integration, effective CSP/host capability policy, limits, artifact admission, entry handling and concrete BP-6 integration instructions.
- A reviewed BP-5 qualification summary with a checksummed transferable evidence archive. Add a concise link/status in the compiler browser plan without relabelling earlier history or exposing private website source.
- A finite test workflow or documented invocation that consumes exact artifacts and compiler inputs. No required public CI job may depend on private website credentials or silently expired investigation artifacts. Do not rebuild PHP for every text/client change.

Run applicable current checks in both changed repositories. Known compiler checks are:

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

Use the exact serial browser commands in `docs/browser/bp4-workflow.md`, including controls, BP-3 and BP-4. They share a preview port/build directory; do not run them concurrently. Website checks include `composer validate`, `composer test`, and the existing `assegai wc:build` command after confirming the current environment. Add and actually execute the new client's type/build/unit/security/browser checks; do not cite invented commands as already existing.

Keep raw transcripts, profiles and captures under an OS temporary directory outside all user workspaces, with finite retention. Commit intentional source, fixtures and concise reviewed documentation only. Preserve source/asset/checksum identities and separate raw observations from reviewed PASS/FAIL/NOT RUN. Supply the archive and its digest via an actually accessible location; a temporary path on a different machine is not a handoff. Preserve notices and source/build provenance for reused runtime components. No public runtime release is authorized here.

Stage and commit validated implementation, consolidate local feature work into local `develop`, and push only `develop` in each affected repository. Do not push remote feature branches, force-push, discard concurrent changes, change `main`, cut release refs or deploy. Observe exact pushed CI results separately from local qualification. A pending CI run may be reported as pending; an earlier green commit is not the new result.

Finish with exact commits/push and CI status for each changed repository; API/ownership and source paths; compiler/runtime/corpus/worker identities; counts and real results for the independent regressions and all new groups; effective CSP/capability/memory enforcement evidence; sequential stale-run and recovery results; original-source diagnostic handling; evidence archive location/hash; remaining browser/resource limitations; and precise BP-6 instructions for connecting the existing editor and lessons without changing their content/design.

BP-5 completion means the actual reusable client performs verified Check/Build and isolated Run on the tested profile with correct boundaries and recovery. It does not mean production routes are enabled or the wider release matrix is approved.

## Technical references

Repository documents above define the implemented compiler behavior. The following primary browser specifications are guidance for implementing and testing the new isolation boundary, not evidence that this PHP-WASM artifact already meets it:

- HTML iframe sandbox: https://html.spec.whatwg.org/multipage/iframe-embed-object.html#attr-iframe-sandbox
- Workers and lifecycle: https://html.spec.whatwg.org/multipage/workers.html
- CSP worker restrictions: https://www.w3.org/TR/CSP3/#directive-worker-src

Verify the chosen mechanism in real browsers. Standards descriptions and a green build never replace the required runtime tests.
