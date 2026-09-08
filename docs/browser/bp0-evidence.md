# BP-0 Runtime Evidence

Status: Runtime investigation recorded; combined BP-0 remains INCOMPLETE. This is not a repaired runtime or production release.

## Execution identity

The hosted run started at `2026-09-08T23:45:42.425Z` (September 9 in Africa/Lusaka), using commit `217d018e8f2d73d54858fe01e5ee57322d68c1c2`. No tracked inputs were changed by the run.

- Workflow: [Browser runtime baseline, run 34292039499](https://github.com/atatusoft-ltd/ppphp-src/actions/runs/34292039499), job `102280468843`.
- Evidence: [artifact 10081712781](https://github.com/atatusoft-ltd/ppphp-src/actions/runs/34292039499/artifacts/10081712781), named `bp0-runtime-217d018e8f2d73d54858fe01e5ee57322d68c1c2-1`. Original CI retention is seven days; this summary preserves findings, not the complete raw report.
- ZIP SHA-256: `2005b5bfe11aa298d7146653bc4a69b4d4d28b16a19642855b5789f03af9f6f1`.
- Contained `report.json` SHA-256: `4d5eb204bdafc7cc934ddebcaf3140937216897930791ae12a94a9ade4056069`.
- Browser: HeadlessChrome 152 on Linux x86_64. Browser PHP: `8.4.23`, SAPI `wasm`, `PHP_INT_SIZE` 8, empty `PHP_BINARY`.
- Installed `@php-wasm/universal`, `@php-wasm/util`, and `@php-wasm/web-8-4`: `3.1.52`. PHPStan: locked `2.2.9`. Node: `22.16.0`.
- Native CI reference: PHP `8.4.25`. The same 14 native probes also passed locally on PHP `8.4.23`; the full browser/compiler workload was not run locally.

The report records loaded browser resource URLs, lock/install identities and package asset hashes. The default loader was used unchanged. This does not separately qualify every loader mode or establish complete build-toolchain provenance.

## Observed results

All 24 harness tests passed, including 14 real native PHP probes and Chromium DevTools connectivity. All 15 independent browser cases were observed. Nine met their semantic assertions; six trapped.

| Browser cases | Result |
| --- | --- |
| Plain PHP/JSON and platform probe | PASS |
| Fiber return, suspend/resume, exception/finally, nested Fiber | FAIL: trap through `_getcontext` |
| Ordinary cyclic garbage collection with destructor | PASS |
| Garbage collection fixture inside an explicit Fiber | FAIL: trap through `_getcontext` |
| Empty output and explicit nonzero exit | PASS |
| Valid and invalid actual PHP CLI lint | PASS; valid lint did not execute the side-effect sentinel |
| External termination of runaway PHP and subsequent clean worker | PASS |
| Real compiler preparation followed by standalone pinned PHPStan | FAIL: preparation reached the analyzer invocation, which trapped through `_getcontext` |

The GC-inside-Fiber case may fail while starting its Fiber, before collection itself. It is not evidence of a separate GC defect. Ordinary collection passed. The PHPStan stack has unresolved WASM frames, so the exact analyzer PHP call site is not yet identified.

The smallest failing fixture needs neither ++PHP nor PHPStan:

```php
<?php
$f = new Fiber(static fn (): int => 42);
$f->start();
echo $f->getReturn();
```

Native output is `42`. Browser execution traps with `RuntimeError: unreachable` and `_getcontext` on the stack. This establishes a reproducible runtime context-creation failure independently of compiler syntax or nested subprocess transport.

## Earlier failures kept separate

The unchanged original spike fails before PHPStan because its worker expects capability catalog version 3. The actual compiler returned version 4, full required-capability parity, no required gaps, and the expected seven diagnostic codes. The original page's startup-failure wording is misleading here: PHP and compiler analysis had already run. The independent harness bypasses that stale assertion without changing its original source.

The first diagnostic attempt, run `34291551869` at `ad17419b5e2d6d169922147a9b68d4719e56bde2`, lost its browser observation when Chromium profile cleanup threw `ENOTEMPTY`. Commit `217d018` waits for process closure, bounds filesystem-cleanup retries, and preserves observations even when cleanup fails. Two regression tests cover that reporting failure. No PHP execution retry or runtime workaround was added.

The successful diagnostic workflow means evidence capture and its controls passed. It does not mean the six failing runtime behaviors passed. The ordinary aggregate compiler CI is a separate workflow and is not certified by this report.

## Next work and limits

Reproduce the minimal failure against an explicitly selected Asyncify artifact and a symbolized build, then implement the appropriate Zend/Emscripten context backend in the runtime-repair stages. Preserve these exact failures as regression targets. Do not downgrade the analyzer, disable analysis, stub context calls as successful, or substitute remote execution.

The full website corpus, native compiler/analyzer parity, complete protocol contracts and toolchain provenance are still outstanding BP-0 work. Full browser Build/Run, cross-browser qualification and production activation have not been completed. Compiler semantics, runtime dependency locks, website behavior and production safeguards remain unchanged by this investigation.

Reproduction commands and diagnostic-workflow semantics are documented in [the baseline harness guide](baseline.md).
