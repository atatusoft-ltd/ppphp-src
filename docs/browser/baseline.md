# BP-0 Runtime Baseline Harness

This is the first executable slice of the [browser production plan](../ppphp-browser-production-plan.md), not a runtime repair or a claim that BP-0 is complete.

## Run

Install the compiler dependencies from the root lockfile and run `npm ci` in `tools/web-spike`. Use Node 22.16 or a compatible newer Node release, PHP 8.4, and a locally installed Chrome/Chromium executable. Set `CHROME_BIN` when auto-detection does not find it.

From the repository root:

```sh
node --test tools/web-spike/scripts/run-baseline.test.mjs
node tools/web-spike/scripts/run-baseline.mjs
```

The runner creates a fresh OS-temporary evidence directory and prints its location. Optional `--output /tmp/a-new-directory` requires an absent directory beneath the OS temporary root. Existing destinations and repository paths are rejected. Raw output and reports do not belong in user workspaces.

`--native-only` runs native reference probes without loading Vite or downloading packages. It explicitly records browser execution as NOT RUN. The Chromium unit test verifies DevTools connectivity and JavaScript evaluation in a blank page, not PHP-WASM execution.

## What runs

The full runner uses the existing compiler packaging script, builds the unchanged spike entry point and observes its actual page result first. It preserves a failure in the existing catalog assertion instead of changing the assertion or claiming the PHPStan phase ran. It then builds a separate diagnostic entry point using the same Vite configuration and pinned runtime packages.

The independent cases exercise plain PHP, runtime identity, Fiber start/resume/throw/nesting, cyclic garbage collection outside and inside a Fiber, empty output, explicit exit, valid/invalid CLI lint, externally terminated runaway code and a subsequent clean control. Every browser case gets a fresh worker; failure does not suppress later cases. The standalone PHPStan case packages the real compiler, verifies the archive, prepares a valid virtual project through protocol version 1 and invokes its pinned analyzer independently of the compiler-core catalog gate.

Original source files and locks are fingerprinted. Installed runtime assets are hashed, and successful workers report loaded resource URLs so the selected loader artifact can be identified. This is not verified toolchain provenance: recovering the exact Emscripten/PHP build inputs remains work for BP-1.

## Interpret results

The report distinguishes observation from runtime semantics. Capturing a trap successfully is not a working runtime. Each case has its own PASS, FAIL or NOT RUN semantics result; exceptions, timeout and incomplete analyzer output are never clean analysis. Empty stdout is a valid completed result. Lint must produce appropriate diagnostics without executing its side-effect sentinel.

The diagnostic workflow succeeds when it has captured the independent cases and its native/basic-browser controls pass. It can therefore capture a known failing Fiber or analyzer case without pretending that the runtime is compatible. Read `runtimeSemantics` and each case, not only the workflow badge. Harness/startup failures fail the job. `productionReady` remains false and combined BP-0 remains INCOMPLETE.

The workflow has one 15-minute job, is limited to browser-tool changes on `develop` or explicit dispatch, cancels superseded runs, and retains its small report for seven days. It does not publish a website, create a fork, execute visitor code or change native compiler behavior.

## Remaining BP-0 work

This slice does not certify the complete website teaching corpus, native compiler/analyzer parity, all draft job/release contracts, cross-browser support, real-browser production limits or symbolized C traces. It does not correct the existing version-3/version-4 catalog discrepancy without authoritative execution evidence. The compiler and website work orders remain authoritative for those tasks.

The browser loader API is the one already used by `tools/web-spike/src/php-worker.js`. Runtime failures must be investigated against the hashed installed artifacts, not inferred from current upstream branches. No runtime package, analyzer rule, quality gate or production safeguard is weakened by these probes.
