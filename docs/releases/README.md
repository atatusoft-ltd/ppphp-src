# ++PHP Releases

These authored notes describe changes, compatibility, and limitations. The
[GitHub releases](https://github.com/atatusoft-ltd/ppphp-src/releases) list published
versions and available downloads. Historical notes and assets remain immutable.

- [`2026.3.1`](2026.3.1.md) — first Stable compiler release notes.
- [`2026.3.1-rc-2`](2026.3.1-rc-2.md) — diagnostic and flow-analysis improvements.
- [`2026.3.1-rc-1`](2026.3.1-rc-1.md) — first release-candidate notes retained as an immutable historical record.

RC-1 was published on 2026-09-03 and RC-2 on 2026-09-08. The retained
RC-1 notes were authored before publication; their preparation wording does
not describe current availability. Use the published release's installation
instructions. RC-1 retains its original Composer package identity; RC-2 uses
`atatusoft/ppphp`. See GitHub Releases for Stable availability.

RC-2 can exhaust PHP's default memory limit during `check` and `build`.
The memory improvements described in the [Stable release notes](2026.3.1.md)
are not included in RC-2.

The [RC-2 release](https://github.com/atatusoft-ltd/ppphp-src/releases/tag/2026.3.1-rc-2)
provides the versioned schema and checksums. To install RC-2 explicitly:

```bash
composer require --dev atatusoft/ppphp:2026.3.1-rc-2
```

Stable remains Composer's default acquisition channel. Release Candidates and Development releases require explicit selection. See [Versioning](../versioning.md). Maintainers follow the single [release lifecycle](../releasing.md); obsolete release branches are not required to use or verify historical artifacts.
