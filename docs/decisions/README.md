# Architectural and Language Decisions

This directory holds focused decision records for architectural and language choices that need durable context beyond the [++PHP MVP end-to-end plan](../ppphp-mvp-end-to-end-plan.md).

| Record | Status | Decision |
| --- | --- | --- |
| [0001](0001-compiler-owned-project-analysis.md) | Accepted | Separate compiler-owned project analysis from the supplemental PHPStan phase, preserve the native full default, and expose incomplete portable coverage honestly. |
| [0002](0002-quarterly-calver-and-release-channels.md) | Accepted | Use quarterly CalVer with distinct Stable, Release Candidate, and Development channels, Stable-default acquisition, and exact immutable release identities. |
| [0003](0003-content-addressed-compiler-cache.md) | Accepted | Use content-addressed compiler evidence, a separate internal build identity, stable operation locking, conservative invalidation, and durable output recovery. |
| [0004](0004-mvp-native-analysis-retains-phpstan.md) | Accepted | Retain compiler-owned analysis plus the PHPStan supplemental phase for the `2026.3.1` MVP release line. |
| [0005](0005-composer-php-constraint-parser.md) | Accepted | Reuse Composer's constraint grammar for project PHP requirements, with explicit correctness, packaging, security, and maintenance tradeoffs. |

Decision records state context, alternatives, consequences, and status without replacing the authoritative execution plan.
