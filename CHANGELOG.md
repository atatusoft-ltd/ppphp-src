# Changelog

All notable changes to ++PHP are recorded here. Release dates are added only when a version is published.

## Unreleased

### Fixed

- Reduced memory use when checking and building Composer projects by deferring unused token streams and releasing dependency implementation bodies after discovery. Small mixed projects are regression-tested with a `128M` PHP memory limit, including uncached builds.

## 2026.3.1-rc-2

### Added

- Editor integrations can request diagnostics for unsaved PHP and ++PHP buffers, including changes in other open files, without saving source or building the project. Saved-file checks retain their additional analysis.

### Changed

- Composer installation uses `atatusoft/ppphp` from RC-2 onward. Existing users should follow the [package migration instructions](docs/releases/2026.3.1-rc-2.md#upgrade-notes); the CLI and PHP namespace are unchanged.
- A private property that is assigned but never read now reports non-blocking warning `P2046`; the signal remains visible without treating incomplete or obsolete stored state as a correctness failure.

### Fixed

- Valid string concatenations and broader local types no longer fail PHPDoc checks; closure and arrow-function locals retain callable signatures in generated PHP.
- `when` results no longer produce false PHPDoc errors for narrower branch values or acquire an artificial nullable result type.
- Fresh callback arrays retain their compatibility through `when` results, including nested expressions and `finally` overrides; existing arrays keep their type contracts.
- Erasing a standalone `throws` clause removes its indentation-only line without shifting diagnostic source locations.
- Type and project errors use clearer wording, initialization failures name their specific cause, and extra command paths receive one-path guidance.
- Missing PHP opening tags now point to the needed `<?php` insertion, instead of suggesting cache-permissions changes. Diagnostics preserve user identifiers, report the actual argument or return-type mismatch, and retain specific project and analysis-failure reasons in editor requests.

- Semantic type names in diagnostics retain their resolved spelling while normalized canonical identities remain internal to type comparison and lookup.
- Flow-sensitive local assignments honor earlier null guards instead of reporting false nullable-type errors.

## 2026.3.1-rc-1 — Prepared, Not Published

### Added

- Typed mutable and readonly locals, typed loop declarations, composite types, erased generics, typed lists and maps, checked errors, and expression-oriented `when`.
- Strict whole-project analysis across mixed PHP and ++PHP source, with compiler-owned type flow and portable dependency declarations.
- Composer runtime projection, atomic mixed builds, source maps, deterministic manifests, and source-free generated execution.
- A versioned incremental cache, deterministic diagnostic pipeline, portable PHP 8.4 signatures, and hardened compiler trust boundaries.

### Changed

- The prepared compiler identity is `2026.3.1-rc-1` and the canonical compiler namespace is `Atatusoft\Ppphp`.
- Native `check` and `build` retain the pinned PHPStan supplemental phase for the MVP release line.

### Fixed

- Fixed false generic diagnostics in inherited, nested callback, and focused-file contexts.
- Fixed incomplete dependency declarations for Composer autoload edge cases and source-free analysis.
- Fixed cache validation and interrupted-build recovery so invalid evidence cannot become successful output.

### Security

- Project, dependency, output, cache, process, and transaction boundaries fail closed and avoid following untrusted symlinks.
- Release assets are deterministic and covered by SHA-256 checksums.

### Known limitations

- Generated output targets PHP 8.4, and the compiler requires PHP 8.4 or newer within the declared `^8.4` range.
- Native checks and builds include supplemental PHPStan analysis, including deep ordinary-PHP bodies and generator-specific flow.
- Browser analysis is an internal integration protocol rather than a supported browser build product.
- No formatter or standalone language server is included in this repository.
- Immutable Records, postfix list syntax, Native Type Members, and attribute factory expressions are future work and are not part of this release.
- This is a prepared release candidate and may change before Stable.
