# BP-4 qualification evidence

Observed on 2026-09-09. **BP-4 qualification PASS** for the recorded native and
Chrome profiles. This is not production release approval. The implemented contract,
limits, exact command sequence and BP-5 handoff are documented in
[bp4-workflow.md](bp4-workflow.md). The [full work order](../ppphp-browser-production-bp4-codex-prompt.md)
is included without alteration. Production Run, website wiring and production
routes are outside this assignment.

## Inputs and scope

Implementation began on compiler `develop` at
`82bf59727077769327bb23a5cbf99d8e6dff8a75`. Compiler version remains
`2026.3.1-rc-2`; PHPStan remains `2.2.9`. No dependency lock or runtime source was
changed. The retained BP-3 environment initially supplied verified runtime inputs.
After the execution environment lost its temporary files, the exact original
archives were restored from GitHub artifact IDs `10084368486` and `10084367500`,
and both archive hashes and every runtime file hash were verified again. PHP was
not rebuilt. The optional handoff README/helper was not used. The accepted BP-3 summary remains in
[bp3-analyzer-parity.md](bp3-analyzer-parity.md); the original runtime verification
archive is separate historical provenance, not a substitute for BP-3 evidence.

| Input | SHA-256 |
| --- | --- |
| Supplied and committed full BP-4 work order | `080705e8271794ca05a93164b992c1b0653eb56edd3829a5a7277a08cc580279` |
| Frozen teaching corpus, 18 cases, 23,688 bytes | `066124d08e112c8c8ac16bf795b7639bfaadd34052037b55a8779459bffce17f` |
| Compiler lock | `c8efe1b23e4ac3afc82c55f47c0654d2dcdc5f0d9f26fe5e489c29a0a330d3e1` |
| Qualified combined compiler build identity | `b80232fe37463b00418f91acffa307e401f1b18eaae4a47eb9761276d2bf299b` |
| Combined browser compiler archive, 14,625,495 bytes | `8ce6692fad582cc231024808b76545d7592fc4bcd9154ff54bbb9a6240ee6121` |
| Browser lock | `709d3cbd573fef86493640a8970d7ca6edbfc05df4472c8913c0c51c0cbedbff` |
| PHPStan PHAR | `42af2954326f2f6f0c39e12eb0e1229b9e037297e2caec9a5db11d44e4f6cce1` |
| Candidate WASM, 29,461,357 bytes | `3198aa074ad42bd2fad37d9af80a4a382df743bd1df0651ded4d3d3fc1cb80f9` |
| Candidate Asyncify loader | `7e6653fd2d6cb96cddf681bd7ce932b856635fd127f38f6626e407325136507c` |
| Candidate artifact manifest | `408d2735395885c30df578204a5d029cb6f2f08705689017d5d685d8147e1263` |
| Baseline WASM | `ef597bdbddf37b8f648ffd7f7f95272b387f0255ee0b772040da4c220180a4c7` |
| Original runtime binaries ZIP | `00d50cf068ef5ec574fd33656cf2646da6c74a27a9c17be4ed0c9c6def70ff2a` |
| Original build evidence ZIP | `7aed9154a8bb51f776853d456d10ea89079e5b4240aef86fc66caf3924f49d34` |
| Original runtime verification ZIP | `d7b14c9afe39badc8037333c74a3e1821905ec8d0e54b1843d045d4d0dab377c` |

The frozen public teaching corpus is now available at
[`tools/web-spike/fixtures/website-corpus.json`](../../tools/web-spike/fixtures/website-corpus.json),
with six examples, eight starters and four solutions. Its provenance is website
commit `c41628510113084a3534c478876f12a80e2120f6`, content revision
`3e97d5549460ae38bb89acce5d25f53192b329cc`. The private website implementation
was not copied or modified. Its Run action requirements remain NOT RUN in BP-4.

Build run `34297911850` at `69dd18e7a884535031576d380e119183c1b979c3`
produced the retained runtime. Original verification `34301044349` at
`153023e2a8e7b915cd7c0f0c7042ee3379ef5562` is historical input, not this task's
CI result. All nine manifest-listed files in each retained profile were verified.
The candidate uses PHP 8.4.23, Emscripten 4.0.19, explicit Asyncify, O3, assertions
and function names, without DWARF. Browser package versions remain 3.1.52 and
Vite 8.2.2. Native PHP is Homebrew 8.4.21 CLI, 64-bit; browser CLI is PHP 8.4.23
CLI, 64-bit, while its filesystem host is WASM SAPI. Extension sets are recorded
separately. This is not an identical-host claim. The observed local browser is
Chrome 153.0.8010.36 on macOS arm64, Node 22.16.0.

## Recorded checks

| Check | Current result |
| --- | --- |
| Composer validation, locked dependency audit and distribution | PASS |
| Browser and runtime Node contracts | PASS, 95 tests |
| Runtime helper and shell syntax | PASS, 11 Python tests and `bash -n` |
| Baseline negative runtime control | PASS: expected six Fiber-related failures and nine passing controls |
| Candidate runtime and additional Fiber controls | PASS: 15 and 8 cases |
| Independent BP-3 regression | PASS, 48 cases on the combined compiler identity |
| Protocol 3 full 48-case Check/Build qualification | PASS: 48 Check comparisons, 33 eligible Builds and deterministic repeats; 15 analysis-negative/fault cases have no applicable Build |
| Final sequential A/B/C and validation guards | PASS: native-equivalent C, three stale A/B completions rejected, all 12 publication guards, real lint and abort/recovery |
| Final aggregate repository gate | PASS, 1,196 tests and 7,874 assertions, all constituent gates completed |
| Exact pushed CI | NOT RUN at documentation capture; recorded after push in the delivery archive, independently of this local browser PASS |

The combined aggregate completed in 1,341.064 seconds, with 1,200.90 seconds
in Pest. Its version/documentation, static analysis, PHP signatures, dependency
index, cache, fuzz, benchmark-harness smoke, analyzer promotion, mixed application,
analyzer parity and offline release-readiness checks all completed. The benchmark
smoke is an existing repository gate, not a new native performance audit.

BP-4 implementation commit `c8d2dcaeb3bfb121be0659b94b107a19a37dc4ec` was
consolidated with the separately validated memory/dependency commit
`21adb0d555439b25205c8f8dfebbeb9c9bcc360f` in merge
`a6f497ee76ce174c301fa4ea5a8a524b01ba3814` before final qualification.
The combined compiler identity above binds those source changes. The separate
native CLI memory allowance and dependency traversal fix do not constitute a
whole-process performance result. Its task-reported VOD observations are explicitly
attributed in the evidence archive; that task supplied no raw capture files.
Diagnostic presentation changes remain isolated from this qualified compiler.

## Sequential publication and real lint

The full BP-4 command exited 0 after 1,781.353 seconds. It hydrated the pinned
assets, then ran with browser application networking disabled. All cases and the
sequential job released their owned workers; Chrome cleanup also completed.

1. `A/build/1` published `main.php`, copied `old.php`, both source maps and the
   complete manifest. All five file hashes were retained.
2. `B/build/2` reached real PHPStan and returned `P2016` for the ordinary-PHP
   return-type error in `src/old.php`. It returned no current output, labelled the
   retained generation as A, and preserved every A output byte.
3. `C/build/3` published `main.php`, copied `new.php`, both maps and the manifest,
   exactly matching all five independent native output files. `old.php` and its
   stale ownership were removed.
4. Replayed A analysis, A lint and B analysis completions all returned `rejected`
   with no current-output authority. C remained unchanged after each delivery.

| Snapshot | SHA-256 |
| --- | --- |
| A | `3c731b0695940b617495c031ddea74e02e1efe72c265140a2316d0d6b10fc31c` |
| B | `22d2f660eb0aa9270af6db27cf907da60c580fe18fd8f2afa398c09f3329f3b3` |
| C | `2029600faea6be33f0c60920c435843e196061195dd7a80a026e8b8bad076e79` |

The following partial builds preserved the valid unselected context and retained
C output. A copied sentinel contained top-level `file_put_contents` and `echo`,
plus a harmless lint warning. Actual browser `php -n -l` returned 0 with the
complete success line and warning; no sentinel file was created and top-level
code did not execute. Injected invalid emission went through the same production
transport and real lint, returned `P7003` mapped to `src/main.ppphp`, and preserved
the preceding output. A corrected build then succeeded.

All 12 labelled browser guards rejected missing, partial, duplicate, reordered,
foreign and object-shaped lint records, wrong phase, unknown request fields,
mutated PHP, mutated maps and an added unvalidated artifact. An actual owned-worker
abort was followed by a successful fresh Build. Native regression seams separately
cover source/configuration/stub/dependency/manifest mutations, budgets, transport
timeout/overflow/abnormal exit and staged/journal publication failures. The raw
report and `reviewed-acceptance.json` retain exact observations and artifact hashes.

## Development failures and superseded observations

The archive retains these observations separately from final acceptance:

- An initial aggregate failed three documentation-link tests while the evidence
  document was absent. Early worker tests exposed JavaScript syntax, asset URL,
  explicit cwd/SAPI and owned Chrome cleanup defects. These were corrected.
- A queued completion from a terminated worker could replace the host filesystem
  before the ownership guard. The guard now runs before accepting bytes; a Node
  regression delivers that old completion after a new worker starts.
- Earlier OS-temporary captures disappeared with the execution environment. Final
  runtime controls and gates were recreated. A recreated Node run could not launch
  Chrome in the sandbox; the permitted rerun passed. A runtime command rejected a
  mismatched temporary root before execution; setting `TMPDIR` fixed the setup.
- Associative JSON decoding confused object-shaped results with arrays. Red/green
  tests now cover empty and numeric-keyed objects. A later large-string test
  reproduced a duplicate-key bypass caused by PCRE JIT stack exhaustion; a linear
  scanner now handles large plain and escaped strings. Runs interrupted for these
  compiler fixes remain incomplete, despite their passing individual cases.
- Real native lint showed that valid PHP can emit a nonfatal warning and succeed.
  Protocol 3 now requires complete lint success framing and permits recognized
  warning lines only for the exact validated candidate. The final focused native
  workflow suite passed 21 tests and 156 assertions before combined qualification.
- The initial sequence used a B fixture rejected before supplemental analysis,
  so it supplied only stale A completions. Its raw harness PASS is explicitly
  overlaid as **INCOMPLETE** in `pre-integration-sequence/review.json`. The corrected
  B has an ordinary-PHP return-type error, reaches real PHPStan and supplies a
  failed B continuation. Both the browser and runner now require stale results
  from distinct A and B operations. The final 95-test Node run covers that rule.

No interrupted or older-identity browser run counts as complete BP-4 acceptance.
The previously complete 1,177-test native gate and prior BP-3 runs are historical
observations, not substitutes for the final combined-source results above.

## Evidence and remaining work

Raw reports are written only to OS temporary directories outside user workspaces.
The delivered `ppphp-bp4-evidence.zip` contains final reports, per-file source and
harness identities, raw gate observations, reviewed acceptance, historical failure
captures, `pushed-ci.json`, `delivery.json` and an internal `SHA256SUMS`. Its adjacent
`.sha256` file binds the whole ZIP; the delivery response supplies the exact OS-temp
path and digest. Archive assembly checks that the committed compiler identity and
every recorded harness hash still equal the bytes used in browser qualification.
No unrun browser, device or deployment row is inferred from ordinary CI.

The production Run interface, shared website client, website routes, opaque-frame
isolation/CSP, complete production memory measurements, Safari/Firefox/Edge/mobile
qualification, immutable public asset packaging and deployment are **NOT RUN**.
The current diagnostic runtime and upstream loader warnings remain qualification
limitations, not release certification. Consume the concrete BP-5 handoff in
[bp4-workflow.md](bp4-workflow.md).
