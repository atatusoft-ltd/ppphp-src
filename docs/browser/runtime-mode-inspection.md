# Explicit Runtime-Mode Inspection

This diagnostic extends the [baseline harness](baseline.md). It does not repair PHP or declare BP-0 complete.

From the repository root, after the existing locked dependency installation:

```sh
node --test tools/web-spike/scripts/inspect-runtime-modes.test.mjs
node tools/web-spike/scripts/inspect-runtime-modes.mjs
```

Use `--mode asyncify` or `--mode jspi` for one mode. Otherwise both run serially. `--output` follows the baseline's absent OS-temporary-directory policy. The report is `modes-report.json`; raw evidence never belongs in a user workspace.

The original baseline runs unchanged before these checks in CI. Only the diagnostic build redirects the PHP loader import to an explicit installed Asyncify or JSPI loader. The original Vite configuration, source probes, compiler, analyzer, dependencies and their lockfiles remain unchanged. The emitted WASM fetched by the browser must hash to the selected installed binary. Selecting a mode without verifying the loaded bytes is not accepted as evidence.

Each mode repeats all independent browser probes, including compiler preparation and the pinned standalone analyzer. The report retains raw stacks and resolves function indices only where the exact WASM binary contains a function-name section. Missing symbols remain null. Producer metadata is recorded where present; it does not prove the complete upstream source or build-toolchain provenance.

A separate read-only PHP script tokenizes the installed PHPStan PHAR and records bounded Fiber-related references. It never evaluates its contents. Those references help locate investigation targets; they do not prove which call site executed.

Harness errors, failed basic controls, wrong artifacts and incomplete observations fail the workflow. A correctly recorded Fiber failure remains a runtime FAIL even if the diagnostic workflow succeeds. No production readiness claim follows from its badge.

The script has 14 focused local tests covering exact symbol indices, absent symbols, malformed metadata, explicit loader selection, versioned artifact paths, wrong artifact hashes and inert PHAR inspection. Full browser execution and installed-artifact evidence are supplied by the diagnostic workflow, not these unit tests.
