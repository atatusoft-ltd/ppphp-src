# BP-3 analyzer parity

Observed on 2026-09-09. This qualifies compiler-owned preparation, the locked
PHPStan invocation, and compiler-owned completion in a real Chromium worker.
It does not certify production Check/Build/Run, other browsers, or public runtime
distribution. The governing scope is [BP-3 and BP-4](../ppphp-browser-production-plan.md).

## Inputs

The native compiler and bundled browser compiler use source commit
`0aa99540667b71ca2e4c6cffaeb5606337c8bd10`, compiler `2026.3.1-rc-2`, and locked
PHPStan `2.2.9`. Production compiler source and dependencies are unchanged.
The evidence records hashes of the added harness independently of that input
commit; the commit containing this document supplies the reviewed implementation.

| Identity | SHA-256 |
| --- | --- |
| Compiler `composer.lock` | `c8efe1b23e4ac3afc82c55f47c0654d2dcdc5f0d9f26fe5e489c29a0a330d3e1` |
| PHPStan PHAR, verified on both hosts | `42af2954326f2f6f0c39e12eb0e1229b9e037297e2caec9a5db11d44e4f6cce1` |
| Browser package lock | `709d3cbd573fef86493640a8970d7ca6edbfc05df4472c8913c0c51c0cbedbff` |
| Candidate WASM, 29,461,357 bytes | `3198aa074ad42bd2fad37d9af80a4a382df743bd1df0651ded4d3d3fc1cb80f9` |
| Candidate Asyncify loader | `7e6653fd2d6cb96cddf681bd7ce932b856635fd127f38f6626e407325136507c` |
| Candidate artifact manifest | `408d2735395885c30df578204a5d029cb6f2f08705689017d5d685d8147e1263` |
| Comparison WASM | `ef597bdbddf37b8f648ffd7f7f95272b387f0255ee0b772040da4c220180a4c7` |
| Project fixture manifest | `ca336f936471a7bba29a72989c2ae3531628e639b9233bb2e6caf5f3275f7389` |
| Original binary ZIP, artifact `10084368486` | `00d50cf068ef5ec574fd33656cf2646da6c74a27a9c17be4ed0c9c6def70ff2a` |
| Original provenance ZIP, artifact `10084367500` | `7aed9154a8bb51f776853d456d10ea89079e5b4240aef86fc66caf3924f49d34` |
| Original verification ZIP, artifact `10084950378` | `d7b14c9afe39badc8037333c74a3e1821905ec8d0e54b1843d045d4d0dab377c` |

The original three archives were retrieved from the exact GitHub artifacts and
verified against the digests in [the runtime repair record](runtime-fiber-repair.md).
They were safely extracted and reassembled in an OS temporary directory; every
profile file was verified against its manifest. No runtime rebuild was needed.
The original verification run is `34301044349` at
`153023e2a8e7b915cd7c0f0c7042ee3379ef5562`; it is historical input evidence.
Build run `34297911850`, commit `69dd18e7a884535031576d380e119183c1b979c3`,
produced the candidate. Its upstream commit is
`a6ed3872674399baa47c2f55fe1e660633fc8051`, PHP source commit
`52cee85adfeeb6f017f2ac796ab7973353702c20`, PHP `8.4.23`, Emscripten `4.0.19`:
explicit Asyncify, O3, assertions and function names retained, no DWARF.

The actual host was macOS arm64, Node `22.16.0`, Headless Chrome
`153.0.8010.36`, revision `507c6ee3e2f3b2ca0e660547e5b9ea4820c67f4c`,
V8 `15.3.76.10`. Native PHP was Homebrew **8.4.21**, CLI, 64-bit;
8.4.23 was unavailable locally. Browser PHP was **8.4.23**, WASM SAPI,
64-bit. These are different profiles, with different extension sets recorded
in the raw report. Both checks used a 256 MiB PHP memory limit and analysis
target PHP 8.4. No claim of identical hosts or measured production memory use
is made. Browser packages remain `@php-wasm/*` 3.1.52 and Vite 8.2.2.

## Harness and comparison

[`run-project-parity.mjs`](../../tools/web-spike/scripts/run-project-parity.mjs)
consumes the exact, hashed bytes in
[`projects.json`](../../tools/web-spike/fixtures/projects.json) and an optional
local website corpus. The native reference runs the actual full `check`, then
prepares and invokes PHPStan separately using the compiler's serial/debug plan.
The browser prepares through the real compiler, invokes the unchanged analyzer
in a fresh top-level PHP runtime, and maps its observed result through the
existing PHP completion code.

[`parity-adapter.php`](../../tools/web-spike/src/parity-adapter.php) is test-only.
It reconstructs preparation from the same frozen project and compares the full
canonical envelope before calling `PhpStanProjectAnalyzer::complete()` and
`ProjectChecker::complete()`. Stale source/configuration fails. It adds no public
command, protocol version, trusted deserialization, language rule or mapper.

Comparison preserves diagnostic code, severity, title, message, original byte
range, labels, related locations, help, identifiers, missing findings and order.
Generated analysis bytes must match. Raw PHPStan result file keys are mapped
through compiler-provided selected-file paths to original source names; this is
the only raw-result path normalization. Root paths, timings and host metadata
are recorded separately rather than compared as semantic data. No message or
quoted value is scrubbed. Full native diagnostics must equal the standalone
completion, except for the explicitly injected analyzer failure.

Debug framing reuses the selected-file manifest and existing
`phpstan-debug-output.mjs`. Each expected progress path must occur exactly once;
traversal order is not assumed. Unknown, missing or duplicate progress, malformed
hashes, truncated JSON, concatenated JSON and trailing noise fail. The PHP
`sha256:` hash-contract regression remains active. Exit 1 with real findings is
valid; it is tested separately from malformed output, status 7, timeout, overflow
and launch failure. Synthetic streams/faults are labelled and do not substitute
for real analyzer findings.

Limits are 128 cases, 12 files and 64 KiB source per case, 2 MiB input JSON,
2 MiB combined browser process output, 90 seconds per phase, 240 seconds per
worker and a 250-second external observation watchdog. A suite has a 45-minute
browser deadline. Native subprocesses have 90-second deadlines and bounded
output; they inherit an explicit minimal environment. Project source is scanned
as data; no project bootstrap is executed. Infrastructure uses localhost only.
Each case owns a worker; worker termination releases all its PHP instances and
filesystem state. Observations survive cleanup failures, which prevent overall
acceptance. Console assertions are retained and fail execution qualification.

## Coverage and observations

The final corrected run passed all 48 cases: 30 compiler cases and all 18
exported teaching cases. PHPStan ran in 36 cases; preparation rejected or skipped
the other 12 according to the native contract. Fifteen cases intentionally
returned compiler status 1, including the controlled analyzer failure. There
were no unexpected findings, traps, timeouts, console assertions or cleanup
failures. Each case retained at most 20 console records, below the 200-record
per-case cap.

| Coverage | Representative observed behavior |
| --- | --- |
| Positive language features | Calls, generics, generators, typed locals/loops, readonly, lists/maps, checked errors, and `when` branch processing succeed. Website examples complement compiler parity scenarios. |
| Preparation rejection and correction | Binding `P2002`, type `P2008`, effect `P4003`, branch `P5002`; analyzer is skipped. Corrected cases succeed. Minimal binding pair adds only `int`. |
| Real supplemental-only findings | Ordinary PHP body fixture yields `P2016`, `P2015`, `P2099` in original location order. Corrected fixture is clean. |
| Generated source map and correction | CRLF/Unicode `.ppphp` boundary yields `P2020`, `src/main.ppphp`, line 5, byte offset 105; replacing the missing type with the declared type succeeds. |
| Multiple findings | Real PHPStan `return.type` findings map to `src/Alpha.php` and `src/Beta.php`, line 3, offsets 23 and 22. Neither finding is dropped. |
| Cross-file and mixed context | Imports, generic interfaces, implementations and trait members resolve. PHPDoc and ordinary untyped PHP retain native treatment. |
| Selection | File/directory checks use valid unselected context and ignore unrelated invalid syntax. Pathless check includes it (`P1001`). Empty project/directory succeeds without PHPStan. |
| Original locations | `src/métrique file.php`, CRLF and multibyte comment, maps `P2016` to line 3, byte offset 22. |
| Warning-only | PHPStan status 1 with `property.onlyWritten` becomes warning `P2046`; full compiler status remains 0 on both hosts. |
| Backend failure and recovery | Synthetic missing configuration invokes real PHPStan, retains its stderr and empty stdout, maps to `P6006`; the next clean project and an identical repeat succeed in fresh workers. |

Expectations came from accepted repository scenarios and reviewed native output.
An initial expected list was corrected to the compiler's actual location order;
the synthetic missing-configuration expectation was corrected to the existing
empty-result `P6006` contract. Sources were not rewritten to conceal findings.
Normal native full checks remain separate from the deliberately faulted debug
invocation. Website authored intent is retained separately from native outcomes.

## Website input

The deterministic exporter and fixture were committed separately to website
`develop` at `c41628510113084a3534c478876f12a80e2120f6`.
[Website CI run 34308639394](https://github.com/atatusoft-ltd/ppphp-website/actions/runs/34308639394)
passed on that exact commit. Other ongoing website/editor work was preserved.
During final compiler validation, that separate work changed the editor source;
the exporter's drift check correctly rejects the newer uncommitted editor.
This qualification binds the committed corpus and provenance below. It does not
certify the later editor implementation or refresh its expected behavior silently.

The version 1 `ppphp.browser-corpus` has SHA-256
`066124d08e112c8c8ac16bf795b7639bfaadd34052037b55a8779459bffce17f`.
Content revision `3e97d5549460ae38bb89acce5d25f53192b329cc` owns the frozen
definitions. The export binds all three source hashes: Learn service
`102246dec32c0ccd76a3baeb399bdcab5e5f0f1fc5cb4ca861435cbf20be3e38`,
Playground service `1ab40b1b9dd68252737d69ff82d357957a9777943722bfd3bd48ab420198ded2`,
editor `5e06c573b537accb1944f28378f8aa42e4f3b1e3138c512734f7b4be0604461c`.
It contains six Playground examples, eight Learn starters and four existing
solutions. The broken starters retain `P2009`, `P2005`, `P3013` and `P4008`.
All exported native expectations are initially null; authored intent is separate.
Build/Run actions are preserved as future requirements, not claimed executed.

The exporter reads trusted first-party definitions without application bootstrap,
uses the existing entry resolver, hashes exact UTF-8 bytes and detects drift.
The compiler consumes a supplied local file and needs no private repository at
runtime or in required CI. Service implementations, private configuration and
credentials are not copied into this repository.

## Reproduction

Use locked Composer dependencies and `npm ci --prefix tools/web-spike`.
Obtain and validate the retained runtime using the procedure in
[the artifact verification workflow](../../.github/workflows/php-wasm-verify-artifact.yml).
Set `BP3_INPUT` to the real OS-temporary directory containing the assembled
`runtime/baseline` and `runtime/candidate`, never an assumed attachment path.
Choose a new output root for each execution:

```sh
export TMPDIR=/private/tmp
export PHP_BINARY=/opt/homebrew/opt/php@8.4/bin/php
BP3_OUTPUT=$(mktemp -d "$TMPDIR/ppphp-bp3-evidence.XXXXXX")
node tools/php-wasm-runtime/verify-built.mjs --runtime "$BP3_INPUT/runtime/baseline" --expect baseline --output "$BP3_OUTPUT/baseline-control"
node tools/php-wasm-runtime/verify-built.mjs --runtime "$BP3_INPUT/runtime/candidate" --expect candidate --output "$BP3_OUTPUT/candidate-control"
node tools/web-spike/scripts/run-fiber-contract.mjs --wasm-sha256 3198aa074ad42bd2fad37d9af80a4a382df743bd1df0651ded4d3d3fc1cb80f9 --output "$BP3_OUTPUT/fiber-control"
node tools/web-spike/scripts/run-project-parity.mjs --runtime "$BP3_INPUT/runtime/candidate" --wasm-sha256 3198aa074ad42bd2fad37d9af80a4a382df743bd1df0651ded4d3d3fc1cb80f9 --corpus ../ppphp-website/tests/Fixtures/Browser/website-corpus.json --controls "$BP3_OUTPUT" --output "$BP3_OUTPUT/parity"
```

Use the host's actual OS temporary directory and PHP executable on other systems.
The Fiber command must immediately follow the candidate build, since it consumes
that page. Controls are rerun once for a new runtime/compiler bundle; JavaScript
harness changes reuse the same verified binary. `--case ID` minimizes a reproducer;
`--native-only` records reference results. Neither can mark BP-3 complete. An absent
`--corpus` explicitly leaves website parity NOT RUN; absent `--controls` leaves
runtime controls NOT RUN. Custom fixture manifests can be investigated with
`--fixtures`, but cannot certify the required manifest. No expired artifact ID is
introduced into an ordinary required CI gate.

## Validation and evidence

- Runtime controls: PASS, candidate 15/15; additional Fiber contracts 8/8.
  Comparison baseline reproduced the six original `_getcontext` failures and
  nine passes, as required by its unchanged expectation.
- Compiler `composer validate --strict` and `composer check`: PASS. The aggregate
  includes version/documentation, analysis, Pest, signatures, dependency indexes,
  cache, fuzz smoke, benchmark harness, analyzer promotion, mixed application,
  72-scenario analyzer parity and release-readiness checks.
- Node browser harness: 81/81 PASS. Runtime verifier Node tests: 4/4 PASS.
  Python runtime preparation tests: 11/11 PASS; rebuild shell syntax: PASS.
- Website: focused exporter/content checks and full 75-test suite PASS, 3,783
  assertions; component build PASS. Local Composer validation found the existing
  concurrent manifest/lock mismatch; it was preserved. The committed exporter
  itself passed the website CI above.
- Installed-package distribution and locked dependency audit: PASS. The first
  advisory request timed out; the retry completed without advisories.
- Final corrected browser execution: PASS, all 48/48 cases.

| Acceptance dimension | Observed result |
| --- | --- |
| Harness correctness | PASS, 81 browser-tool tests plus runtime tooling above |
| Runtime controls | PASS, expected baseline failures retained; candidate 15/15 and Fiber 8/8 |
| Compiler fixture mapped parity and intent | PASS, 30/30 |
| Website corpus mapped parity and intent | PASS, 18/18 |
| Execution, framing and cleanup | PASS, no unanticipated backend/resource failures |
| BP-3 scoped qualification | PASS on the recorded Chromium/host profiles |
| Production readiness and other browsers | NOT RUN; outside this qualification |

Commands used for the implementation checks (with PHP 8.4.21 on PATH):

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

Documentation and canonical-version checks were repeated after writing this
record. The exact compiler push's CI status is a separate handoff observation;
the historical input CI does not certify the added harness.

Raw results, runtime artifacts and browser profiles are excluded from source
control. The transferable `ppphp-bp3-evidence.zip` is 188,433 bytes, SHA-256
`7e1e481bee28602d5432e33f4268eb14fcb3afefc3ad93b7ec280ba6fc4b433d`.
It was written under `/private/tmp/ppphp-bp3.9hufcq`, outside every workspace.
It contains the full final report, controls, minimized cleanup regression,
explicitly labelled superseded assertion evidence, exact fixture/corpus and
harness bytes, and a verified member `SHA256SUMS`. It contains no runtime
binaries, dependencies, browser profiles or private website implementations.

| Raw report | SHA-256 |
| --- | --- |
| `parity-qualified/report.json`, 48 cases | `057de8c9346248e2da562f9ae2e9e03966a0741d662eb9c2394bcd0dc28a29ed` |
| `baseline-control/report.json` | `dd03ced2979a52f542ea922c7a2c8983ed50d67dd384ee6f9b4b708c51391c11` |
| `candidate-control/report.json` | `a3624bee15f442e3dba269ca25e5f12c12680978bda2975066d3eff1602b83d2` |
| `fiber-control/report.json` | `6fc233438a85aacecc98f647e64d848df3562129ea00c7e9185d45c9c280427d` |
| `cleanup-regression/report.json` | `d519f8ed9b032702cd15be6e62f3f8de1d8c624b7ed25d5280aea5792e52892e` |

## Corrections and remaining boundaries

The first adapter run exposed PHP-WASM CLI's ignored `cwd` option. The adapter
now binds its root to the fixed input file and the worker explicitly changes
directory. A regression invokes completion from outside the project and tests
source/configuration drift. An existing output-directory test assumed `/mnt`
existed; it now checks the actual workspace directory on macOS and Linux.

The initial diagnostic-equivalent browser run also emitted shutdown assertions.
The harness had called runtime exit after CLI shutdown and on its shared host.
It now follows the CLI discard contract and releases the whole dedicated worker,
retaining cleanup errors and rejecting console assertions separately from mapped
diagnostic observations. This changes only test orchestration, not PHP-WASM or
compiler semantics. The minimized real-browser regression and final 48-case rerun
both established the correction with no assertions or cleanup failures.

The original spike catalog mismatch remains historical diagnostic evidence.
Assertions-enabled runtime warnings about unsupported advisory memory syscalls
and asynchronous shutdown are retained; they are not treated as source findings.
They do not establish production resource behavior. Cross-browser/mobile,
production limits, stable licensed runtime distribution and earlier provenance
gates retain their independent acceptance requirements.

BP-4 can reuse the frozen project corpus, real analyzer invocation, strict framing
and compiler-owned mapped completion evidence. It must implement the actual
versioned full-workflow Check/Build transport, snapshot/continuation validation,
compiler-owned emission and source maps, atomic artifacts and real lint/output
validation. Shared website hosting, isolated program Run, cancellation, production
activation and broader certification remain BP-5 and later work.
