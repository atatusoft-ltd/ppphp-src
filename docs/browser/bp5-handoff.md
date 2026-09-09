# BP-5 application client handoff

BP-5 implementation belongs to `atatusoft-ltd/ppphp-website`, in
`src/BrowserRuntime/` and `tools/browser-runtime/`. The compiler continues to own
protocol 3, production emission, source maps, actual PHP lint and atomic
publication. Protocols 1 and 2 and the independent BP-3/BP-4 suites are unchanged.
The [complete work order](../ppphp-browser-production-bp5-codex-prompt.md) is
retained in both repositories. No private website source is copied here.

The qualified compiler source baseline is
`d8f82c0d6daf581842df440d5ef2aad25957d917`, preserving the changes newer than the
approved BP-4 inspection at `5517fa4393ad355ae27bcb256904cc9bc9aaa739`.
Compiler version remains `2026.3.1-rc-2`, with build identity
`sha256:8e48520ad93938b2d31c3fc4836bc976f69546a0be3497f2105ceb08dc1e4bb6`.
This repository's BP-5 changes are maintainer documentation only.

## Runtime and regression ownership

The exact retained PHP 8.4.23 Asyncify binary was reused, without rebuilding PHP:

- WASM: `3198aa074ad42bd2fad37d9af80a4a382df743bd1df0651ded4d3d3fc1cb80f9`.
- Original loader: `7e6653fd2d6cb96cddf681bd7ce932b856635fd127f38f6626e407325136507c`.
- Frozen 18-case teaching corpus: `066124d08e112c8c8ac16bf795b7639bfaadd34052037b55a8779459bffce17f`.

Newly executed independent regressions passed against that source/runtime:
15 runtime controls, eight Fiber controls, all 48 BP-3 cases, and BP-4's 48 Check
comparisons, 33 eligible Builds and 33 deterministic repeats. The remaining 15
BP-4 cases are negative/fault cases. Sequential publication, stale owners,
12 publication guards, native-equivalent corrected output, actual lint without
top-level execution, warning-only lint, partial builds and abort/recovery passed.
The old baseline remained the expected negative control.

The compiler's applicable local gates passed, including `composer check`
(1,212 tests / 7,985 assertions), strict metadata validation, distribution,
locked dependency audit, 91 web-spike Node contracts, four runtime Node tests,
11 runtime Python tests and rebuild shell syntax.

## Application qualification and BP-6

Website implementation commit:
[`6abe33e036af9b2df87daf15f1f0e8ab1fa8fa1a`](https://github.com/atatusoft-ltd/ppphp-website/commit/6abe33e036af9b2df87daf15f1f0e8ab1fa8fa1a).
Authorized website readers can open its
[API and BP-6 instructions](https://github.com/atatusoft-ltd/ppphp-website/blob/6abe33e036af9b2df87daf15f1f0e8ab1fa8fa1a/docs/browser/bp5-client.md)
and [qualification record](https://github.com/atatusoft-ltd/ppphp-website/blob/6abe33e036af9b2df87daf15f1f0e8ab1fa8fa1a/docs/browser/bp5-qualification.md).

The complete application suite passed on Chrome 153.0.8010.36 / macOS
arm64: 75 top-level checks, including 10 additional boundary cases. All
18 frozen teaching cases passed: 14 programs executed and four invalid starters
started no program worker. A/B/C authority, artifact mutations, online containment,
offline use, enforced allocation limits, cancellation/disposal/recovery and 256
real compiler operations across workspace rotation passed. Firefox 155.0 passed
its bootstrap/one-execution probe; WebKit and wider device profiles are NOT RUN.

The website's `docs/browser/bp5-client.md`
documents its typed API, admission checks, actual opaque-frame/worker bootstrap,
CSP, denied capabilities, audited allocation boundaries and integration steps.
Its `docs/browser/bp5-qualification.md` records exact source/assets, observations,
PASS/FAIL/NOT RUN distinctions and evidence delivery.

BP-6 connects this one client to the existing playground and lesson lifecycle.
It must retain editor design/content, set the project on every relevant edit,
invoke Run only for an explicit consumer action, render output as text and
dispose on navigation/unmount. Check and previous output never grant execution
authority. Runtime text has no authenticated structured location channel; do
not turn guest stdout/stderr into trusted source-mapped diagnostics.

The evidence archive retains the exact tested runtime/compiler/client assets,
raw qualification and regression observations, member checksums, source records
and separate pushed-commit/CI observations. It is supplied outside user
workspaces with a SHA-256 sidecar. A green ordinary CI job does not establish
that the real browser suite ran.

Production components, routes, guards, public execution and deployment remain
unchanged. Wider browser/device certification, distribution and activation
remain later gates; BP-5 does not authorize them.
