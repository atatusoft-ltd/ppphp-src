# Pinned PHP-WASM Runtime Rebuild

This is experimental build tooling for the browser production programme. It does not enable production routes or replace installed compiler/runtime packages. Combined BP-0 and the production release remain incomplete.

The downstream changes are a small patch set in `tools/php-wasm-runtime`, applied to a disposable checkout of WordPress Playground at `a6ed3872674399baa47c2f55fe1e660633fc8051`. No separate hosted fork repository has been created. The connected repository tools do not expose repository creation; this patch set can be moved to a fork without copying the upstream monorepo into the compiler.

## Comparison

The build pins PHP 8.4.23 to source commit `52cee85adfeeb6f017f2ac796ab7973353702c20`, verifies the source/recipe Git blob identities, and uses upstream's Emscripten 4.0.19 recipe and committed libraries. It bypasses upstream's automatic PHP-version refresh by invoking the reviewed Docker build directly. It records the effective flags and image/toolchain identities. The Ubuntu base and downloaded SDK/dependency provenance still need release hardening; this is not a claim of bit-for-bit reproducibility or a security-current release profile.

The first profile keeps the original Fiber implementation, adds function names and assertions, and retains O3 optimization. These symbols are not DWARF source-line information. It must reproduce the six known context failures and passing controls in an actual browser before the candidate is built.

The candidate adds an Emscripten-specific Zend Fiber backend using `emscripten_fiber_init`, `emscripten_fiber_init_from_current_context`, and `emscripten_fiber_swap`. It keeps Zend's transfer, VM-state and lifecycle logic, owns continuation storage with the context allocation, and leaves native builds conditional. It uses conservative Asyncify instrumentation. It is not a fake-success implementation of POSIX context calls and does not alter PHPStan or compiler semantics.

Candidate acceptance requires all existing 15 browser cases, including the unchanged analyzer, to pass. The original baseline's expected failures must never count as candidate success. Passing this small corpus would still not qualify complete PHP Fiber semantics, ASan, stack exhaustion, cross-browser behavior, repeated-request leaks, production Build/Run, the teaching corpus, or the production website. The initial pinned PHP source is a baseline, not a permanent PHP-version ceiling.

## Execution

After installing the existing locked compiler and web-spike dependencies, run from the compiler root on a Docker-capable machine with Chrome/Chromium:

```sh
python3 -m unittest discover -s tools/php-wasm-runtime -p 'test_*.py' -v
node --test tools/php-wasm-runtime/verify-built.test.mjs
bash tools/php-wasm-runtime/rebuild.sh --output /tmp/ppphp-runtime-new-run
```

The output directory must not already exist. Downloads, upstream changes, reports and binaries stay beneath that new OS-temporary directory. The build uses local Docker image tags and should run in an isolated development/CI environment. It neither publishes packages nor changes production configuration. The workflow is limited to relevant changes on `develop` or explicit dispatch, cancels superseded runs, bounds build parallelism and timeouts, and retains artifacts for seven days.

The scripts' local unit tests validate transformation and result contracts, not C compilation or runtime correctness. The experimental workflow performs the actual builds and browser tests. Read its reports and status before claiming any runtime fix.

Upstream references: the pinned `packages/php-wasm/compile/php/Dockerfile`, its base-image Dockerfile and agent guide; PHP's pinned `Zend/zend_fibers.c`; Emscripten 4.0.19's `system/include/emscripten/fiber.h`. Existing reproduction evidence is in [bp0-evidence.md](bp0-evidence.md).

## First symbolized rebuild and prerequisite correction

Run `34296740743` at `d0f2af6e9a496c0124b2bb46329d351a7703fbe7` built the baseline WASM successfully. It retained 20,851 function names. The actual browser stack confirms `zim_Fiber_start` calls `zend_fiber_init_context`, then the missing `getcontext` import. Its WASM SHA-256 is `f88490ba014c3c672bb963ea1a3259247cc590e97d14d8b3c8ac6c0bcbcc0640`.

That run did not build the candidate. Compiler preparation timed out after an unhandled assertion from the upstream bridge: `__errno_location` was called with one argument although the native function takes none. This is a separate defect exposed by assertions, not a successful analyzer test. The failing baseline report is retained in evidence artifact `10083679198` (ZIP SHA-256 `991769b76c76866bf585473526015e8512163b6190f933c97a1324c1993307f2`).

Both diagnostic profiles now carry a common source-level correction in `phpwasm-emscripten-library.js`: obtain errno storage with the zero-argument call and write the numeric error into it. The ErrnoError branch uses `e.errno`, not its string code. All six affected calls are replaced with exact context and source-blob checks. Assertions remain enabled; no getcontext stub or compiler rule is bypassed. This bridge-corrected baseline must pass its required diagnostic assertions before the Fiber candidate is built. This section records the prerequisite correction, not a claim that the corrected build or candidate has passed.
