# ++PHP Browser Runtime Spike

This isolated spike tests the production ++PHP compiler with PHP 8.4 WebAssembly in a real browser. Stage 13A added a compiler-owned gate before the earlier PHPStan experiment; Stages 13B–13C and the completion gate extend it to compiler-owned type flow, portable PHP-platform declarations, complete Composer semantics, and optional source-free dependency indexes. Stage 13D preserves the process-free version 2 contract and validates current packaging while native check/build remain on the full supplemental path. It does not modify a website or claim browser production builds.

## Outcome

The recorded Stage 13A browser run proved compiler-owned project checking in process. The packaged compiler analyzed one real virtual project containing valid and invalid `.ppphp` sources through one top-level CLI invocation. It returned normal diagnostics, `compilerCore` completeness, catalog version 1, `fullParity: false`, and the 10 then-uncovered required capability IDs.

The gate installed no spawn handler, created no PHPStan workspace, returned no command or continuation, started no child process, and did not enter `_getcontext`. The valid source remained clean and the invalid typed initializer produced `P2008` at `src/invalid.ppphp`.

The current gate requires catalog version 4, `fullParity: true`, no uncovered required capabilities, and compiler-owned platform/dependency `P2015` findings alongside `P2016`, `P2018`, `P2024`, and `P2044`. Its virtual Composer dependency contains a top-level throw, proving that declaration loading parses rather than executes package code. Protocol version 2 may instead consume a mounted body-free format-2 index after containment, compatibility, and hash validation. Stage 13D does not add persistent browser caching; the disposable virtual filesystem may reuse in-memory state only where the host retains it. Building the Vite bundle validates current packaging only; do not treat it as a new Chromium result until the preview gate is actually run in a real browser.

The old PHPStan gate remains separate. After the compiler-only gate, the spike corrects the invalid fixture, runs version 1 Prepare Analysis, and invokes the pinned PHPStan CLI as a fresh top-level PHP-WASM command with no spawn handler. PHPStan still aborts in `_getcontext` before complete JSON is available. The drain-aware nested-process experiment remains in `drain-aware-spawn-handler.js` and `php-child-worker.js`; it is not used to make the compiler-only gate pass.

| Capability | Result |
| --- | --- |
| Start PHP-WASM | Pass: PHP 8.4.23, `wasm` SAPI |
| Load and hash-check the production compiler archive | Pass |
| Parse real extension syntax | Pass |
| Compiler-only Check, valid and invalid sources | Pass in one top-level compiler invocation |
| Compiler-only subprocess count | Zero; no spawn handler installed |
| Compiler-only completeness | Recorded Stage 13A run: `compilerCore`, full parity false, 10 required gaps; current packaging gate: full parity true, zero required gaps |
| Compiler-only PHPStan state | None: no workspace, command, result handoff, or continuation |
| Run PHPStan as a separate top-level command | Expected failure at `_getcontext` |
| Stop runaway user code | Pass by terminating the disposable worker |
| Browser Build PHP | Not supported |
| Browser user-code execution | Not performed |

## Version 2 Compiler Analysis

The virtual project stores this request and passes it to the hidden compiler command:

```json
{
  "version": 2,
  "requestId": "a stable caller identifier",
  "action": "analyze",
  "operation": "check",
  "analysis": {
    "engine": "compiler"
  },
  "selection": {
    "path": null
  }
}
```

```text
php /opt/ppphp/bin/ppphp browser:analysis \
  /workspace/compiler-analysis-request.json \
  --working-directory=/workspace \
  --no-interaction \
  --no-ansi
```

A complete response contains compiler identity, `engine: compiler`, `completeness: compilerCore`, catalog version, `fullParity`, uncovered required capabilities, and the unchanged version 1 diagnostic envelope. It never contains `phpStan`, `command`, or `continuation` fields. Version 2 supports Check only and optionally accepts a project-contained `dependencyContext`; compiler-only Build is deliberately unsupported.

Limits are 16 KiB request bytes, 256 source/stub files, 4 MiB total source bytes, 1,000 diagnostics, and 2 MiB response bytes. A limit failure returns a small structured `resource-limit-exceeded` response. JSON is never truncated.

## Version 1 And PHPStan Failure Evidence

Version 1 remains compatible. Prepare Analysis performs the native compiler phase, lowers selected sources, creates `.cache/analysis`, writes the compiler-owned PHPStan configuration, and returns a content-addressed continuation with the exact top-level command.

PHP-WASM reports an empty `PHP_BINARY`, so the browser plan uses the public `php` token. The separate command remains:

```text
php /opt/ppphp/vendor/phpstan/phpstan/phpstan analyse \
  --configuration=/workspace/.cache/analysis/phpstan.neon \
  --error-format=json \
  --no-progress \
  --memory-limit=1G \
  --debug
```

The response rejects with `RuntimeError: unreachable` through `_getcontext`. No complete PHPStan JSON is fabricated, inferred, or partially consumed. This failure does not affect version 2 because compiler-owned analysis neither constructs nor invokes PHPStan.

The earlier drain-aware adapter proved that nested process streams must be drained before exit is exposed to `proc_open()`. That implementation and child worker remain available as historical transport evidence. Stage 13A does not use a nested adapter, add a delay, retry partial JSON, or weaken diagnostics.

## Real Chromium Evidence

The recorded Stage 13A run used Chromium on macOS on 2026-09-01:

```text
Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)
AppleWebKit/537.36 (KHTML, like Gecko)
Chrome/151.0.0.0 Safari/537.36
```

Observed compiler gate data:

| Measurement | Observed value |
| --- | ---: |
| Compiler archive | 14,148,522 bytes |
| Primary PHP-WASM binary | 19,310,275 bytes |
| Compiler-only analysis | 118 ms |
| Top-level compiler invocations | 1 |
| Compiler diagnostics | `P2008` only |
| Catalog gaps | 10 |
| Total path including PHPStan failure gate | 2,236 ms |
| Runaway-worker cancellation | 311 ms observed; 250 ms requested boundary |

These are local feasibility observations, not production benchmarks. Precise process memory is not exposed by this surface. The Vite build still reports upstream direct-`eval` warnings and browser externalization of Node `worker_threads`/`events`; installed dependencies were not edited.

## Reproduction

From `tools/web-spike`:

```shell
npm ci
npm run build
npm run preview -- --port 4173
```

Open `http://127.0.0.1:4173/`. The page runs the compiler gate, the separate PHPStan failure gate, and disposable-worker cancellation automatically. A successful experiment reports “The browser completed the ++PHP compiler spike” and includes both `compilerAnalysis` and `topLevelPHPStan` evidence.

The compiler archive is generated from the current checkout and includes `bin`, Composer metadata, resources, source, and installed vendor packages. Its SHA-256 is verified before extraction. Generated `dist`, archive, runtime, `vendor`, and `node_modules` artifacts are not committed.

## Security And Scope

The compiler gate parses and analyzes source as data. It does not execute project source, project autoload entrypoints, Composer scripts, application bootstrap files, or user PHPStan configuration. Worker termination is the only wall-clock containment mechanism; there are no arbitrary synchronization delays.

This is an isolated compiler feasibility spike. It proves portable compiler-core Check only. Native `ppphp check` and `ppphp build` retain PHPStan for the MVP under ADR 0004; browser full analysis, Build PHP, preview compilation, website integration, and user-code Run remain unsupported.
