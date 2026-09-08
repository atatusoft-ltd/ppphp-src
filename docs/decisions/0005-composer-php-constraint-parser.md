# ADR 0005: Reuse Composer's PHP Constraint Parser

- Status: Accepted
- Date: 2026-09-08
- Scope: Reading project PHP target requirements

## Decision And Correctness

Use the production `composer/semver` package through `ComposerPhpTargetResolver`
to interpret `require.php` using Composer's own constraint grammar. Exact
`config.platform.php` versions must satisfy that constraint; without an exact
override, the configured target's minor family must intersect it. Unsupported
targets and contradictory or malformed settings remain explicit errors.

The package handles conjunctions, alternatives, caret/tilde ranges, wildcards,
hyphen ranges, exclusions, and normalized version boundaries. A small-looking
subset would disagree with valid Composer projects or silently admit incompatible
targets. `tests/Unit/Config/ComposerPhpTargetResolverTest.php` exercises these
forms, exact-version conflicts, malformed input, and cache invalidation.

This dependency does not define ++PHP release versions. Quarterly CalVer and
Stable/Release Candidate/Development selection remain owned by `ReleaseVersion`.

## Alternatives

- PHP's `version_compare()` compares concrete versions; it is not a Composer
  constraint parser or interval-intersection implementation.
- A local parser would avoid a package but transfer grammar, normalization,
  compatibility, and security maintenance to this compiler. Supporting only a
  convenient subset would weaken the promise to respect the user's Composer file.
- The existing ++PHP release parser implements a different, deliberately strict
  grammar and cannot be reused for PHP requirements.
- PHPStan-bundled constraint utilities are not a compiler-owned dependency API.
  Depending on their internal packaging would couple project configuration and
  portable compiler analysis to the supplemental backend.
- Executing Composer would introduce process/runtime availability requirements
  and plugin or application-execution risks. Analysis reads configuration as data;
  it does not run Composer, its plugins, project scripts, or autoload entrypoints.

## Security, Packaging, And Maintenance Cost

Every compiler installation gains another third-party production package and its
autoloaded code. The currently locked package is MIT-licensed and has no runtime
package dependency beyond PHP; its development dependencies are not installed as
compiler runtime requirements. This keeps the dependency graph addition small,
but does not make it risk-free: parser defects and supply-chain changes remain
part of our maintenance responsibility.

Retain the compatible `^3.4` requirement and reviewed lockfile. CI installs locked
dependencies, audits them, and reviews dependency changes. Updates must retain
the constraint regression tests, installed-distribution checks, and supported-host
coverage. Configuration input remains bounded and is parsed without evaluating
project PHP or performing network activity. The package supplies parsing and
matching; the compiler still owns input limits, target policy, errors, and source
locations. Revisit this choice if its runtime graph, security posture, supported
hosts, or Composer grammar compatibility changes.
