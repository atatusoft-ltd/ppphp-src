# Codex Prompt: Build and Qualify a Source-Pinned PHP-WASM Runtime

## 1. Objective and Authorization

Build a new PHP-WASM candidate whose PHP and linked native dependencies come from explicitly pinned, retained source. Preserve the existing repaired PHP integration, replace the uncertain prebuilt-library inputs, and qualify the resulting candidate for the BP-8 handoff.

**This is implementation authorization, not another feasibility study or historical-artifact recovery exercise.**

The owner has decided:

```text
No dependency on contacting WordPress PHP-WASM maintainers.
No waiting for upstream responses or historical build evidence.
No shortcuts that remove required functionality.
No replacement of the working browser architecture.

Build the native dependencies ourselves.
Preserve the existing Fiber repair and compiler integration.
Establish source provenance prospectively.
Qualify the new candidate through the existing test infrastructure.
```

This explicitly supersedes the previous closure task’s prohibition on rebuilding PHP **for the new candidate only**. Preserve the old artifacts and their evidence unchanged.

The required outcome is:

```text
Pinned source and reviewed patches
    → independently built native libraries
    → PHP linked against those libraries
    → verified runtime and matching loader
    → fresh compiler/browser qualification
    → reproducible distribution and corresponding source
    → BP-8 handoff
```

Do not stop after producing Dockerfiles or a plan. Execute the build and qualification wherever the available environment permits, recording specific environmental limitations rather than claiming unexecuted work passed.

---

## 2. Repositories and Scope

```text
Primary:
    atatusoft-ltd/ppphp-src

Website integration and packaging:
    atatusoft-ltd/ppphp-website

Working branch:
    develop in each repository
```

Use **BP-7R** as a maintainer label for this replacement-runtime work. It supplements the browser delivery programme without renumbering BP-0 through BP-10.

Commit this work order in the compiler repository as:

```text
docs/ppphp-browser-production-native-rebuild-codex-prompt.md
```

That is an output to create. Link the website handoff to the canonical work order rather than maintaining duplicate copies.

Read current `develop` in both repositories. Preserve concurrent work and identify the coherent compiler, website, runtime, and teaching-corpus inputs used for qualification.

### In scope

Build-system corrections, pinned native dependencies, necessary cross-compilation adaptations, PHP relinking, runtime compatibility and security verification, package/source updates, and the resulting BP-8 handoff.

### Out of scope

A new PHP engine, a replacement JavaScript wrapper implementation, PHPStan removal, an editor rewrite, new language features, remote execution, public package publication, production deployment, and full BP-8 device qualification.

Use existing infrastructure. Do not create another repository or paid service merely to house this work.

---

## 3. Read the Existing Inputs

### Compiler repository

```text
AGENTS.md
docs/ppphp-browser-production-plan.md
docs/browser/runtime-fiber-repair.md
docs/browser/bp3-analyzer-parity.md
docs/browser/bp4-workflow.md
docs/browser/bp4-evidence.md
docs/browser/bp7-archive-inputs.md

tools/php-wasm-runtime/rebuild.sh
tools/php-wasm-runtime/prepare.py
tools/php-wasm-runtime/verify-built.mjs
tools/php-wasm-runtime/verify-built.test.mjs

tools/web-spike/scripts/prepare-compiler-bundle.mjs
tools/web-spike/src/compiler-archive.mjs

.github/workflows/php-wasm-rebuild.yml
.github/workflows/php-wasm-verify-artifact.yml
```

Locate the actual baseline, candidate, Fiber, BP-3, and BP-4 runners and their options.

### Website repository

```text
AGENTS.md
docs/ppphp-browser-production-handoff.md
docs/ppphp-browser-production-bp7-closure-codex-prompt.md

docs/browser/bp7-packaging.md
docs/browser/bp7-evidence.md
docs/browser/browser-runtime-installation.md

tools/browser-runtime/README.md
tools/browser-runtime/source-inputs.lock.json
tools/browser-runtime/package-release.mjs
tools/browser-runtime/verify-release.mjs
tools/browser-runtime/audit-source-archives.py

src/BrowserRuntime/
```

Inspect current build graphs, source inventory generation, runtime admission, memory/import auditing, packaging, installation, and qualification tests.

### Starting facts

The retained runtime was built with PHP 8.4.23 and Emscripten 4.0.19, using the existing Asyncify Fiber repair. The current rebuild script rebuilds PHP but consumes prebuilt native libraries. Its `OPENSSL_VERSION=1.1.0h` argument did not select the effective library; the closure established that the retained binary uses OpenSSL 1.1.1t. **Rerunning that script unchanged does not resolve the problem.**

Treat those versions as the starting comparison profile, not a permanent architectural ceiling.

---

## 4. Execute in Four Checkpointed Slices

These are implementation checkpoints within one authorized task, not additional approval ceremonies.

| Slice                 | Deliverable                                                                                                          |
| --------------------- | -------------------------------------------------------------------------------------------------------------------- |
| **R1: Build inputs**  | Verified effective dependency graph, pinned sources, toolchain profile, and source-build orchestration.              |
| **R2: Runtime**       | Native libraries built from source, PHP linked against them, and a candidate passing low-level compatibility checks. |
| **R3: Qualification** | Fresh compiler, client, containment, and real-page results for the new executable inputs.                            |
| **R4: Distribution**  | Verified package, matching source/build materials, durable evidence, and updated BP-8 handoff.                       |

Commit useful validated checkpoints. Continue through the slices without stopping to request approval for work already authorized.

Record actual build duration, peak build resource observations where available, and the slowest or least predictable steps. Distinguish engineering effort from unattended compilation time. Use those measurements to improve the remaining execution estimate rather than presenting a speculative schedule as evidence.

---

## 5. Establish the Effective Native Dependency Graph

Inspect the retained final link command, copied headers, static archives, configure results, and upstream recipes.

Build **the complete effective third-party native dependency set** for the retained extension profile. Do not replace only Oniguruma while leaving other unexplained prebuilt libraries in the new artifact.

Start from the recorded library families, then verify actual usage:

```text
Oniguruma
OpenSSL and its dependents
zlib and libzip
libiconv
libxml2
curl
SQLite
GD and its linked image-codec libraries
Other libraries actually linked by the selected PHP profile
```

A library being present in an upstream directory does not prove it is linked. Conversely, a dependency hidden behind another static archive still belongs in the graph.

Separate:

```text
Host tools used to generate build inputs
Target libraries linked into WebAssembly
PHP-bundled source
Toolchain-provided runtime/system libraries
Unused upstream artifacts
```

**This does not require rebuilding LLVM, Docker, the host operating system, or every package in the upstream repository.** A pinned SDK installation is acceptable. Identify its distributed runtime components and their source/license provenance.

Emscripten supports building libraries into target object files or static archives and linking them into the final JavaScript/WebAssembly output. Its documentation also warns that host-native objects can accidentally enter a cross-build. Verify actual object formats and compiler invocations, not filename extensions alone. ([Emscripten][1])

### Eliminate ambiguous file selection

Replace wildcard merging of upstream `dist` directories with explicit dependency installation prefixes and an intentional final assembly.

Detect conflicting headers, archives, and package metadata. Do not permit “last copied file wins.”

The closure identified multiple prebuilt zlib archives carrying the same version. The new graph must establish which source-built zlib each dependent consumes and prevent an older archive from being reintroduced through a nested copy.

---

## 6. Pin Sources and Build Inputs Once

Introduce or extend one compiler-owned native-build input manifest. The website’s component/source inventory should consume the resulting runtime receipt rather than maintain another manually synchronized set of native pins.

For each native dependency, record:

```text
Source origin and exact immutable identity
Archive checksum or commit plus verified tree
Required submodules
Patch identities and application order
Build dependencies
Configure/build/install commands
Relevant target flags
License and notice locations
Produced libraries and headers
```

Pin the build-container image and SDK/toolchain identity, including the selected platform. Record installed build-tool versions and retain the effective recipes.

Use verified original release archives where practical. Resolve tags to immutable identities; do not clone an unqualified branch and call it pinned.

### Acquisition and compilation

Separate fetching from building:

```text
Acquire and verify permitted source/toolchain inputs
    → populate the build input store
    → compile without unexpected network acquisition
```

Audit build systems that download dependencies or use `FetchContent`-style mechanisms. Supply pinned inputs explicitly or fail with a specific missing-input message.

Use existing archive-safety tooling where applicable. Handle legitimate internal source-archive links safely; do not blindly extract untrusted archives or flatten source trees until they happen to build.

### Build caching

Cache completed library outputs only when the cache identity includes their source, patches, effective recipe, toolchain, flags, and dependency identities.

Verify cached output hashes and receipts. Unknown prebuilt binaries cannot become trusted merely by placing them in the cache.

For clean-build proof, do not share compiled-library caches between the independent builds.

---

## 7. Dependency Selection

### Oniguruma

Choose and pin an appropriate source release or revision, then build it ourselves with all required adaptations recorded.

There is no requirement to reproduce the old `libonig.a` byte-for-byte or identify its lost source. The new receipt must demonstrate:

```text
Selected source
    → recorded build
    → produced library
    → selected PHP link
```

Preserve `mbstring` and multibyte regular-expression functionality. Do not remove Oniguruma-dependent functions to avoid implementing the build.

### OpenSSL

Use a maintained Apache-2.0-licensed OpenSSL 3.x line compatible with the PHP baseline.

**Start with the OpenSSL 3.5 LTS line**, verify the appropriate current patch release at implementation time, and pin its exact source. Official support runs to April 2030; PHP 8.4’s documented OpenSSL range includes 3.x. Compatibility with our Emscripten build must still be demonstrated. ([OpenSSL Library][2])

Do not select OpenSSL 3.0 merely because it was formerly an LTS line, and do not let an unqualified “latest” lookup select a different major version during later builds.

OpenSSL 3.x uses Apache-2.0, which supports the already selected GPLv3 combination direction. This removes the specific legacy-OpenSSL obstacle when the old implementation is actually absent; it does not automatically verify every other component’s licensing. ([OpenSSL Library][3])

Rebuild native consumers of OpenSSL against the selected headers and libraries. Do not mix newly built OpenSSL with older prebuilt dependent archives.

Verify provider availability and any required runtime data under the actual static/virtual-filesystem profile. Do not assume a native desktop installation layout exists in the browser.

### Other libraries

Retain behavior while selecting traceable, compatible sources. Do not automatically upgrade every dependency, but do not blindly reproduce obsolete versions without checking applicable security issues.

Necessary compatibility or security updates within the native dependency graph are in scope. Record their rationale and test consequences. Major changes to PHP, the wrapper architecture, or the toolchain require a demonstrated need, not convenience.

Do not remove extensions or compiler checks to obtain a smaller or easier build.

---

## 8. Preserve the PHP Repair and Runtime Contract

Reuse the existing Fiber and bridge corrections from `prepare.py`, with verified patch context and focused tests. Do not rewrite the repair merely because the build inputs are changing.

Start with the qualified PHP and Emscripten profile to minimize unrelated variables. Preserve explicit Asyncify selection, the approved indirect-call instrumentation strategy, and the existing PHP/compiler protocols.

### Generated output may legitimately change

A new native build will produce new WASM and may produce a new loader. **Preserve the integration contract, not the old loader’s hash at all costs.**

Regenerate and verify the matching loader when necessary. Do not pair a new WASM with an old loader merely to avoid changing admission metadata.

Update runtime admission through explicit verified identities and compatibility checks. Never bypass a hash, import, or instruction audit to accommodate the new output.

### Platform correctness

Verify the actual target ABI, PHP integer width, alignment-sensitive behavior, exception/longjmp configuration, and library object compatibility.

Do not equate a 64-bit PHP integer with 64-bit WebAssembly pointers. Do not add native-platform defines indiscriminately to silence configure failures.

Host-native code generators may be built when needed, but they must remain separate from target libraries.

### Build failure handling

Every source patch should have a concrete reason and regression coverage where possible.

Do not use:

```text
Unconditional “|| true” around failed build/install steps
Ignored unresolved symbols
Fake successful platform probes
Unrecorded edits in installed dependency trees
Fallback to an old prebuilt library when a source build fails
```

A cross-compilation probe override is acceptable only when its asserted behavior is justified and checked in the target runtime.

---

## 9. Make the Build Sustainable for a Solo Maintainer

Extend the existing runtime tooling instead of creating an unrelated build pipeline.

Provide an explicit source-built-candidate mode or equivalent entry point. Keep historical reproduction available, but do not require rebuilding the original deliberately failing Fiber baseline before every new candidate.

Use the retained repaired runtime as the behavioral comparison. Its unavailable source provenance is no longer the new build’s prerequisite.

### Preflight

Before expensive work, check Docker or the selected build backend, available disk, memory, required tools, and verified inputs.

Use a consistent pinned build environment. A Linux container/runner is acceptable even when the owner operates from macOS.

Do not blindly reuse the old script’s 20-minute build timeout or the old workflow’s 45-minute total job limit for a larger source build. Set finite, justified per-step and overall budgets, with bounded parallelism and retained logs. The existing workflow currently performs the older two-profile build under those narrower assumptions.

Retain completed library checkpoints so an interrupted final PHP link does not discard all valid work.

Keep fast recipe/provenance tests in ordinary CI and the expensive candidate qualification behind an appropriate existing manual or scoped workflow. Avoid repeatedly triggering the complete native build for evidence-only Markdown changes.

Never run global Docker pruning or delete unrelated owner caches to recover disk space.

---

## 10. Record and Verify Source-to-Binary Provenance

Produce a build receipt containing the actual effective inputs and outputs:

```text
Source and patch hashes
Toolchain/container identities
Library dependency graph
Configure summaries
Commands and relevant flags
Generated configuration identities
Target archive/header hashes
Final link inputs
WASM and loader hashes
Extension inventory
Compatibility/security audit results
```

Do not capture secrets or dump arbitrary environment variables.

Verify that every third-party native link input comes from the new controlled build or its verified cache. Toolchain-provided libraries must be separately identified.

Keep old upstream `dist` archives outside the candidate link search path. Add a test that poisons or removes them and demonstrates that the source-build path does not consume them.

The final OpenSSL identity must be established through the selected headers, built libraries, link evidence, and runtime observations. A version string alone is not sufficient.

### Reproducibility

Perform two independent clean builds under the same pinned profile, sharing only verified source downloads and toolchain inputs.

Compare library outputs, WASM, loader, and canonical receipts. Normalize legitimate timestamps, archive ordering, and build paths at their source.

Investigate differences rather than patching binaries afterward or excluding inconvenient files from comparison. Distinguish:

```text
Source correspondence established
Clean rebuild executed
Same-profile byte reproducibility established
Cross-host reproducibility not tested
```

Do not claim cross-platform reproducibility from two builds in one environment. Unexplained output drift must be reported and investigated, not hidden.

---

## 11. Qualify the New Executable Inputs

Old qualification remains useful as a reference, but it is **not** fresh evidence for a changed runtime.

### A. Native-library and PHP smoke tests

Exercise at least:

| Area                      | Examples                                                                                                           |
| ------------------------- | ------------------------------------------------------------------------------------------------------------------ |
| Oniguruma/mbstring        | Multibyte matching, replacement, invalid patterns, encoding behavior, and bounded failure cases.                   |
| OpenSSL                   | Local key generation, sign/verify, encrypt/decrypt, certificate parsing, invalid input, and provider availability. |
| Entropy                   | The actual approved random-source path and its failure behavior. No deterministic production seed or fake success. |
| Compression/archives      | zlib operations, ZIP access, PHAR loading, and malformed-input handling used by the compiler.                      |
| Other retained extensions | Representative XML, SQLite, image-codec, and conversion operations for the retained profile.                       |
| PHP platform              | Integer width, required extensions, normal exit, fatal/error handling, and actual lint without source execution.   |

Do not enable guest networking to test local cryptographic operations. Preserving an OpenSSL extension is not permission to add network capabilities.

Do not silently enable legacy or insecure algorithms simply to make every old OpenSSL behavior identical. Identify material compatibility differences and distinguish intentional maintained-library behavior from integration defects.

### B. Fiber and compiler regressions

Run the retained candidate and additional Fiber lifecycle tests, including PHPStan’s original failing workload.

Run the independent BP-3 and BP-4 suites with the new runtime, preserving compiler source, analysis rules, checked errors, real lint, and output semantics.

Test malformed analyzer output, termination, repeated requests, and clean recovery. A successfully parsed JSON fragment is not sufficient when the process failed.

### C. Security and resource contracts

Repeat the actual WASM/import/instruction audits. Preserve the established containment policies, including the program and compiler memory ceilings, guest host/network denial, opaque-frame isolation, bounded output, cancellation, and recovery.

Do not assume the old zero-internal-`memory.grow` result applies to the new WASM. Verify it. Do not use a raw byte-pattern search in place of the existing structural audit.

Re-evaluate any generated dynamic-loading or evaluation sites. Do not broaden CSP to unrestricted evaluation or expose new host callbacks to make the runtime start.

### D. Client and real-page tests

Run the full independent BP-5 client suite, actual playground/Learn suite, and installed HTTPS delivery suite against the packaged candidate.

Cover valid and intentionally invalid teaching cases, warnings, source-mapped diagnostics, stale-output prevention, offline hydrated operation, cancellation, navigation, and subsequent recovery.

The earlier closure preserved executable bytes and could carry forward some evidence. This task changes the runtime, so that equivalence-based shortcut is no longer sufficient. 

### E. Performance observations

Measure build time, artifact sizes, cold initialization, Check, Build, Run, cancellation latency, and repeated-use memory observations on the comparison environment.

Separate download, WASM initialization, extraction, PHPStan, emission, lint, and execution where instrumentation permits.

Do not hide a significant regression by enlarging timeouts or memory limits. Investigate it and record the actual user-visible consequence. Wider device budgets remain BP-8 work.

---

## 12. Update Licensing and Corresponding Source for the New Candidate

Preserve the owner-selected GPLv3 direction for the applicable browser combination and the separate licenses of its components.

Generate the inventory from the **new actual build graph and receipt**. Do not ship the old unresolved native inputs as though they produced the new candidate.

Include matching source, patches, necessary generated-input recipes, build/install scripts, dependency identities, notices, and reproduction instructions.

Preserve the established PHP/Zend later-license selection only where applicable to covered files. PHP’s official licensing page permits the later terms for earlier covered versions; file-level third-party exceptions still require their own treatment. ([PHP][4])

Source-built does not mean automatically license-compatible. Check the complete effective combination, including LGPL or other obligations where relevant.

However, **do not retain the old “ask upstream for legacy OpenSSL permission” blocker automatically after that implementation has demonstrably been removed**. Explain which old findings apply only to the retained historical artifact and which, if any, remain relevant to the replacement.

No maintainer outreach, external consultation booking, or special-permission request is part of this task. If a genuinely new unresolved permission appears, identify the exact component and available compatible engineering alternatives rather than returning to the old recovery loop.

Keep source downloads direct and outside hydration. Do not publish private website implementation, credentials, qualification profiles, or arbitrary build workspaces.

---

## 13. Package, Preserve, and Hand Off

Use the existing website packager and installer. Generate a fresh immutable asset set, descriptor/receipt, matching editor configuration, source package, notices, checksums, and installation sidecar.

Do not change bytes under an old identity or manually copy trust pins between documents.

Verify two clean package constructions, safe archive handling, source reconstruction, installation, coherent selection, and rollback behavior.

The old unresolved runtime may be retained for **local comparison**, but do not present it as an approved public rollback target. Test public-candidate rollback using an otherwise eligible compatible set or restore the existing disabled-production state.

Preserve completed outputs in owner-controlled durable storage, with external sidecars and a bounded evidence archive. Temporary CI retention is not the sole handoff.

Update the existing compiler browser plan and website handoff:

```text
Old runtime:
    Historical comparison artifact; its unresolved provenance is unchanged.

New runtime:
    Independently identified source-built candidate with fresh results.

BP-8:
    Receives the selected candidate and its exact evidence/input set.

BP-9:
    Still owns public hosting, deployment, and activation.
```

Keep `productionReady: false`. Do not run the full BP-8 device campaign under this label or claim production-host verification from local Apache results.

---

## 14. Validation, Commits, and Completion

Run focused build/provenance tests and each affected repository’s existing quality gates. Inspect Composer/npm scripts first so aggregate suites are not repeatedly duplicated.

Commit cohesive changes, consolidate local feature work into local `develop`, and push `develop` in each affected repository. Inspect resulting CI and distinguish environment failures from implementation failures.

Do not force-push, merge into `main`, create release branches or tags, publish packages, deploy production, or change production guards.

### Completion criteria

```text
The candidate's effective native dependencies are built from pinned source.

No unexplained upstream prebuilt library enters the candidate link.

Oniguruma has a demonstrated source-to-library-to-runtime chain.

Legacy OpenSSL has been replaced by the selected compatible source-built line,
including affected consumers.

The existing PHP repair and browser/compiler contracts remain functional.

The matching WASM/loader pair passes fresh compatibility and security audits.

Independent compiler/client and actual-page suites have fresh results.

The package and corresponding source describe the actual candidate.

Rebuild and reproducibility claims are supported by executed evidence.

The handoff is preserved, traceable, and ready for BP-8 qualification.

No upstream response, public publication, or production activation was required.
```

### Final report

Return a maintainer summary containing:

* The build architecture changed and the dependencies actually rebuilt.
* Selected source/toolchain identities and any necessary compatibility patches.
* Measured build effort, elapsed compilation time, resource observations, and reproducibility results.
* Old-versus-new compatibility, security, performance, and package results.
* Which historical distribution blockers the replacement eliminates, with evidence.
* Commands and suites marked PASS, FAIL, or NOT RUN.
* Artifact locations, checksums, commits, current CI status, and concrete BP-8 inputs.

**The deliverable is a working, traceable replacement candidate. It is not another attempt to reconstruct missing history for the old binary.**

[1]: https://emscripten.org/docs/compiling/Building-Projects.html "Building Projects - Emscripten 6.0.7-git (dev) documentation"
[2]: https://www.openssl-library.org/policies/releasestrat/?utm_source=chatgpt.com "Release Strategy | OpenSSL Library"
[3]: https://openssl-library.org/source/license/ "License | OpenSSL Library"
[4]: https://www.php.net/license/index.php?utm_source=chatgpt.com "PHP: License Information"
