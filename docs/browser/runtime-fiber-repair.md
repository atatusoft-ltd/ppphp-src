# PHP-WASM Fiber Repair: Verified Experimental Runtime

Date: 2026-09-09. Status: The original context-creation blocker is resolved for the tested Asyncify workload. This is not a production website release or completion of the entire browser programme.

## Verified outcome

The rebuilt candidate passes all 15 core browser probes, including the unchanged pinned PHPStan invocation, and all eight additional Fiber lifecycle contracts. The comparison baseline still reproduces the original six context-related failures. Both profiles run in Headless Chrome 152 on Linux, using PHP 8.4.23. Loaded WASM asset hashes are checked against the retained build rather than inferred from a version label.

The successful verification is [run 34301044349](https://github.com/atatusoft-ltd/ppphp-src/actions/runs/34301044349), job `102307809115`, using verification commit `153023e2a8e7b915cd7c0f0c7042ee3379ef5562`. All job steps passed, including tracked-input immutability. The runtime binaries were built earlier at `69dd18e7a884535031576d380e119183c1b979c3`, not rebuilt or modified during this verification.

| Test group | Symbol baseline | Patched candidate |
| --- | --- | --- |
| Plain PHP, identity, empty output and explicit exit | PASS | PASS |
| Fiber return, suspend/resume, exception/finally and nesting | Four context traps | PASS |
| Ordinary cyclic GC with destructor | PASS | PASS |
| GC fixture inside an explicitly started Fiber | Context trap | PASS |
| Actual PHP lint for valid/invalid files; valid lint must not execute side effects | PASS | PASS |
| External termination of runaway PHP and subsequent clean worker | PASS | PASS |
| Real compiler preparation followed by pinned PHPStan 2.2.9 | Context trap in analyzer | PASS |
| Eight additional Fiber lifecycle contracts | Not part of this comparison baseline | Eight PASS |

The additional contracts cover lifecycle introspection, uncaught exceptions and finally, invalid transitions, suspended-Fiber destruction, nested callbacks across suspension, cross-Fiber resumption, error-reporting state restoration, and repeated switching/collection. The final stress fixture creates 128 Fibers with eight suspensions each, checks the accumulated result, verifies their weak references are cleared, and checks that no current Fiber remains. This bounded workload is not a comprehensive memory-leak or performance certification.

The successful analyzer exits `0`, has empty stderr and returns this complete JSON result after its known debug progress line:

```json
{"totals":{"errors":0,"file_errors":0},"files":{},"errors":[]}
```

Its command retains `--debug`, `--error-format=json`, `--no-progress`, the compiler-generated configuration and the pinned analyzer dependency. Analysis rules are not removed or downgraded. This is one positive integration fixture, not the complete native-versus-browser analyzer corpus.

## Runtime repair and build identity

The downstream build recipe and focused source transformations live in [the runtime tooling](../../tools/php-wasm-runtime). No separately published runtime package or standalone fork is claimed by this record.

| Input | Recorded identity |
| --- | --- |
| Upstream PHP-WASM source | `WordPress/wordpress-playground` at `a6ed3872674399baa47c2f55fe1e660633fc8051` |
| Existing package baseline | `@php-wasm` packages `3.1.52` |
| PHP | `8.4.23`, source commit `52cee85adfeeb6f017f2ac796ab7973353702c20` |
| Emscripten | `4.0.19`; reported compiler commit `08e2de1031913e4ba7963b1c56f35f036a7d4d56` |
| Recorded emsdk checkout | `5eb0bde7585670252e8ba05e9d361627bffd08b5` |
| Build mode | Explicit Asyncify, O3, assertions enabled, retained function names; no DWARF |
| Analyzer | Locked PHPStan `2.2.9` |

The baseline's named stack identifies `zend_fiber_init_context` and `zim_Fiber_start` immediately above the missing `getcontext` operation, including in the analyzer failure. The candidate replaces that low-level backend on Emscripten with `emscripten_fiber_init`, `emscripten_fiber_init_from_current_context` and `emscripten_fiber_swap`. It allocates the required continuation stacks and retains Zend's surrounding VM-state, transfer and lifecycle machinery. It does not fake success from POSIX context functions.

The candidate instruments indirect Asyncify paths conservatively and removes the narrow instrumentation allowlist. Optimization of this broad instrumentation is later work. Native paths remain conditional, and the experimental backend explicitly rejects unqualified ASan builds.

Both baseline and candidate also carry a necessary JavaScript bridge correction exposed by assertions: `errno_location` returns a storage pointer, so errors must be written through that pointer instead of passed as arguments. The shared correction uses numeric errno values and is recorded independently of the Fiber change.

| WASM profile | Uncompressed bytes | SHA-256 |
| --- | ---: | --- |
| Symbol baseline | 23,269,621 | `ef597bdbddf37b8f648ffd7f7f95272b387f0255ee0b772040da4c220180a4c7` |
| Experimental Fiber candidate | 29,461,357 | `3198aa074ad42bd2fad37d9af80a4a382df743bd1df0651ded4d3d3fc1cb80f9` |

These are diagnostic artifact sizes, not production transfer budgets. The recipes verify upstream/PHP source identities and preserve library Git-object provenance, but this does not certify bit-identical rebuilding or independently rebuilt provenance for every linked library. PHP 8.4.23 is the tested reproduction baseline, not a permanent version ceiling.

## Output framing and harness corrections

The original rebuilt candidate already completed PHPStan successfully, but its first verifier expected pure JSON. PHPStan's retained debug mode prints analyzed filenames before the JSON result. [The framing adapter](../../tools/web-spike/src/phpstan-debug-output.mjs) now consumes exactly the selected-file progress records supplied by the compiler-owned manifest and parses the complete remaining JSON. It preserves raw stdout and separately records the parsed JSON and debug paths.

Unknown text, duplicate or missing progress records, truncated JSON, trailing noise, multiple JSON objects, malformed identities, nonzero status for a clean check and nonempty stderr remain failures. It never scans forward for a convenient JSON object or declares a failing analyzer result clean. A native cross-language test calls the actual PHP `ProtocolJson::hash()` helper to enforce its `sha256:`-prefixed contract.

Earlier failures are preserved rather than relabeled:

- Build run `34297911850` produced both binaries and passing Fiber probes, but its candidate assessment failed because the debug prefix preceded JSON.
- Verification run `34300346190` exposed the framing adapter's incorrect assumption that manifest hashes were unprefixed. The compiler actually emits `sha256:`-prefixed hashes.
- Verification run `34300811221` passed all 15 core cases and executed all eight extra contracts successfully, but artifact verification rejected the extra suite's port 4174. The artifact checker correctly accepts only its fixed loopback origin. The suite now derives preview and navigation settings from the matching port 4173; the origin check was not weakened.
- Run `34301044349` passes the complete comparison, both candidate suites and their artifact checks.

These are harness corrections, not additional changes to the retained runtime binary. Native compiler protocols and the native JSON parser remain unchanged.

## Evidence and reproduction

Final [verification artifact 10084950378](https://github.com/atatusoft-ltd/ppphp-src/actions/runs/34301044349/artifacts/10084950378):

```text
ZIP SHA-256:
d7b14c9afe39badc8037333c74a3e1821905ec8d0e54b1843d045d4d0dab377c
baseline-verification/report.json:
9a95762b512f62a8971ac1100e725c408fe65c27d6418a8a24a123cfc0107e5d
candidate-verification/report.json:
cdc0b7a1887666f2cee4f72d9d6796088fd8efb51413722345acd024089b90c3
fiber-verification/report.json:
915138c9a7666c0e8147f0e1ae1df1f4b11c9edec76e33ecca46916b06dbe6fa
```

Original build [run 34297911850](https://github.com/atatusoft-ltd/ppphp-src/actions/runs/34297911850) retains source/flags/provenance as artifact `10084367500` and diagnostic binaries as artifact `10084368486`. Their ZIP digests are respectively `7aed9154a8bb51f776853d456d10ea89079e5b4240aef86fc66caf3924f49d34` and `00d50cf068ef5ec574fd33656cf2646da6c74a27a9c17be4ed0c9c6def70ff2a`.

CI artifacts have seven-day retention. [The retained-artifact workflow](../../.github/workflows/php-wasm-verify-artifact.yml) is now manual-only: it is a recorded experiment, not an ongoing gate dependent on expiring files. It pins the exact build and hashes and fails closed if those artifacts are unavailable; it never substitutes a latest build. For new builds, use [the rebuild workflow](../../.github/workflows/php-wasm-rebuild.yml) or its local `rebuild.sh` entry point and retain the resulting new identities.

The tested source entry points are:

```sh
node --test tools/web-spike/scripts/*.test.mjs
node --test tools/php-wasm-runtime/verify-built.test.mjs
python3 -m unittest discover -s tools/php-wasm-runtime -p 'test_*.py' -v
bash -n tools/php-wasm-runtime/rebuild.sh
```

The actual build is invoked with `bash tools/php-wasm-runtime/rebuild.sh --output /tmp/new-unique-directory`. Install the locked compiler/harness dependencies and provide Docker, PHP, Node and Chromium as required by the repository workflows. Captured evidence stays in an OS-temporary location outside user workspaces. Do not overwrite an existing destination or replace installed package files to test a candidate.

The rebuild verifies the core comparison. The separately executable `run-fiber-contract.mjs` verifies the extra suite against an already-built candidate page and an explicitly supplied WASM SHA-256. The successful retained-artifact workflow shows the exact invocation and ordering.

## Delivery status and next integration work

This establishes a working repair for the original context/Fiber/PHPStan blocker in the tested Asyncify configuration. The result remains experimental, with `productionReady: false` in its machine-readable reports.

Still required are negative and representative multi-file analyzer parity, the complete compiler-owned browser Check/Build/Run workflow, lesson/example acceptance, packaging and stable artifact distribution, supported-browser testing, resource/security qualification and production activation. The existing original spike's stale capability-catalog assertion is a separate known harness issue. The original [BP-0 evidence](bp0-evidence.md) remains a historical failing baseline, not a claim that those old binaries are repaired.

The compiler's full CI passed on runtime-build commit `69dd18e7a884535031576d380e119183c1b979c3`. The focused retained-runtime workflow passed on `153023e2a8e7b915cd7c0f0c7042ee3379ef5562`; this document does not assert a full aggregate CI result for later commits.

No compiler language rules, dependency locks, website code or production safeguards were changed by the verification/framing work. The product outcome remains the working production playground and Learn experience described by the [browser delivery plan](../ppphp-browser-production-plan.md).
