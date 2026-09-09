# BP-4 qualification evidence

Observed on 2026-09-09. Qualification is in progress; unfinished rows below are
**NOT RUN** until their final observations are recorded. The implemented contract,
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
| Prior complete native gate identity, superseded by final hardening | `af36ab06b2b99ed69b2a32d37e066a7ca26baab5301a70224103fb60a88fc981` |
| Prior partial browser archive, 14,624,746 bytes | `b821ff42c00fb9784b89fb2e41908a90b6fd4923e4d5a6ba19d9fe91c7e1ee6d` |
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
| Browser and runtime Node contracts | PASS, 94 tests |
| Runtime helper and shell syntax | PASS, 11 Python tests and `bash -n` |
| Baseline negative runtime control | PASS: expected six Fiber-related failures and nine passing controls |
| Candidate runtime and additional Fiber controls | PASS: 15 and 8 cases |
| Independent BP-3 regression | Prior identity PASS, 48 cases; final combined identity NOT RUN |
| Protocol 3 full 48-case Check/Build qualification | NOT RUN to completion |
| Final sequential A/B/C and validation guards | NOT RUN to completion |
| Final aggregate repository gate | Prior identity PASS, 1,177 tests and 7,766 assertions; final combined identity NOT RUN |
| Exact pushed CI | NOT RUN |

An initial aggregate gate failed three release-documentation tests because the
workflow document was added before this evidence document existed. Its 1,166
other tests passed; the aggregate stopped before the later constituent gates.
That initial aggregate is FAIL and is not used as final acceptance evidence.
An earlier focused real PHP workflow suite passed 18 tests and 135 assertions, including
budget/mutation coverage. Earlier development runs exposed and fixed a JavaScript
syntax error, inline-worker asset URL resolution and CLI/host SAPI confusion.
One otherwise successful smoke run failed owned Chrome cleanup. The subsequent
sequential run passed cleanup. All these observations remain separate from the
final run and are not relabelled as full qualification success.

A final ownership review fixed a queued late worker message that could replace
the host filesystem before the existing completion guard ran. A regression test
now delivers an old completion after cancellation and a new worker start and
proves that the newer workspace remains unchanged. The interrupted browser run
had completed 18 cases successfully; it did not establish full qualification.
Its temporary captures and earlier gate captures disappeared with the execution
environment, so the final evidence is being recreated. The initial recreated
Node run failed to launch Chrome inside the sandbox; its permitted rerun passed
all 94 tests. An initial runtime command rejected a mismatched OS temporary root
before browser execution; explicitly setting `TMPDIR` resolved that setup error.

The final decoder review found that associative JSON decoding made object-shaped
results indistinguishable from arrays. The new regression failed before the fix
and passed nine assertions afterward. The decoder now verifies that `results`
is a JSON array. Both empty and numeric-keyed object shapes are also included in
the browser publication guards. The older aggregate and partial browser runs
were stopped before this compiler change; their captures remain under a separate
directory and do not count as final qualification. Runtime controls, independent
BP-3 and the aggregate gate passed on the corrected identity. The final aggregate
took 1,240.257 seconds, including 1,099.11 seconds for Pest. Complete BP-4 remains
in progress.

A subsequent large-string test reproduced a duplicate-key bypass when PCRE's JIT
stack was exhausted. JSON key validation now uses a linear scan independent of
regex backtracking. Large plain and escaped-string regressions fail before the
fix and pass afterward. A native compatibility check also showed that valid PHP
can emit nonfatal lint warnings and still build successfully. Protocol 3 now
requires a complete final lint success line and recognized warning lines bound
to the candidate path, preserving that native behavior while rejecting noise.
The focused final workflow suite passed 21 tests and 156 assertions; static
analysis and all 94 Node contracts passed. The preceding BP-4 run was stopped
after 31 passing cases and is recorded as incomplete, not full acceptance.

The separately implemented compiler memory/dependency work is being integrated
through its validated local feature commit before final qualification. The final
aggregate, independent BP-3 and complete BP-4 runs must use the combined compiler
identity. Its changes and validation remain attributable to that separate task.

## Evidence and remaining work

Raw reports are written only to OS temporary directories outside user workspaces.
Final reports, source/harness identities, gate observations and checksum manifests
will be assembled into a checksummed evidence archive when qualification ends.
No unrun browser, device or deployment row is inferred from ordinary CI.

The production Run interface, shared website client, website routes, opaque-frame
isolation/CSP, complete production memory measurements, Safari/Firefox/Edge/mobile
qualification, immutable public asset packaging and deployment are **NOT RUN**.
The current diagnostic runtime and upstream loader warnings remain qualification
limitations, not release certification. Consume the concrete BP-5 handoff in
[bp4-workflow.md](bp4-workflow.md).
