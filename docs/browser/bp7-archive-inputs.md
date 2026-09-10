# Browser distribution compiler input

The website owns the reusable client and release-set pipeline. This repository owns the compiler archive builder and browser archive admission rules. Compiler protocols 1, 2 and 3 are unchanged.

`tools/web-spike/scripts/prepare-compiler-bundle.mjs` creates a fresh locked production Composer installation under the operating-system temporary directory. Composer scripts/plugins and development dependencies are disabled. Per-build caches are removed with staging; neither the developer vendor tree nor a visitor request builds the archive. POSIX build machines need PHP, Composer and Node; the website's installer needs PHP only.

The reviewed closure consists of compiler source/resources/identity inputs, Composer autoload metadata and the 15 production dependencies explicitly listed by `productionPackages` in `tools/web-spike/src/compiler-archive.mjs`. Installed versions/dist references must match `composer.lock`. A lock dependency-set change requires closure review. PHPStan's PHAR and ordinary analysis are retained; optional native Turbo accelerators, Symfony's Windows prompt executable and contract test classes cannot run in this browser profile and are excluded. No development package tree is distributed.

The builder emits `compiler.tar.gz.bin`, `compiler.json` and `compiler-members.json`. Header order, permissions, ownership, timestamps, gzip metadata and closure checksums are deterministic. The executable's `Compiler::VERSION`, `CompilerBuildIdentity`, PHPStan PHAR hash and all compiler resources remain authoritative. A native probe runs from the isolated assembled production installation.

`compiler-archive.mjs` is browser-compatible and shared with the actual website compiler worker. It bounds streaming gzip expansion and validates regular-file USTAR membership before the worker creates a runtime or invokes `PharData`. Links, special files, GNU/PAX extensions, unsafe paths, duplicates/collisions, bad headers, unsupported modes/ownership, truncation, forbidden files and incomplete compiler membership are rejected. The limits are 20 MiB compressed, 96 MiB expanded, 8,000 files and 32 MiB per member.

Run `node --test tools/web-spike/scripts/compiler-archive.test.mjs` for the focused contracts. Independent runtime controls, BP-3 parity and BP-4 workflow qualification retain their own runners/oracles and must rerun against any new distribution archive. A green archive-contract CI job does not establish browser workflow qualification. Website packaging evidence records its exact executable-input hashes separately from source/license and hosting gates.

The BP-7 run passed the 26 archive contracts, all browser/runtime Node contracts,
the baseline/candidate/Fiber controls, independent BP-3 parity and independent
BP-4 workflows (48 cases each). The full compiler gates passed: strict Composer
validation, `composer check` including 1,212 tests / 7,985 assertions, distribution
verification and audit. These runs exercised canonical compiler archive
`c81f8c9dcec8b207a4e53341c19e17ec7c494c09cbc7aaa5dcb41605c5e24d7c`:
5,391,298 gzip bytes, 1,203 regular files and 38,977,024 expanded tar bytes.
The accepted BP-6 archive had 14,626,267 gzip bytes and 76,257,792 expanded tar
bytes. Compiler build identity, PHPStan PHAR and retained WASM/loader are unchanged;
the size reduction does not establish a different memory guarantee.

The website owns the distribution/receipt, PHP-only installer, source inventory,
actual Apache/browser delivery evidence and separate checksummed evidence archive.
Its BP-7 record keeps unresolved Oniguruma source provenance and the combined
frontend/wrapper licensing decision explicit. No runtime rebuild, publication,
production activation or wider BP-8 qualification is claimed here. Exact pushed CI
observations are recorded separately from these local runtime results.

## Source-distribution closure investigation (2026-09-10)

The owner has now selected the GPLv3 option for the applicable browser
combination, preserving the compiler's Apache-2.0 license. This does not clear
the separate legacy OpenSSL/native-runtime permission question. The website's
existing generated component inventory owns the detailed output/source map;
no second compiler-side license inventory is introduced.

The retained original build evidence and log for run `34297911850` show that
PHP linked prebuilt native libraries copied from the pinned WordPress Playground
checkout. The Oniguruma source tree that produced its tracked `libonig.a` has not
been recovered. The exact prebuilt Git blob is
`1e4a8d4f15b680d2f93433cb1adbb7dcd499b0a5`; the website source-input lock checks
its bytes and records the chain to the retained WASM. The supplied 6.9.10 tag
remains a version-matched candidate. DWARF filenames and line tables lack source
checksums and cannot establish an exact tree.

The effective OpenSSL input is **1.1.1t**, established by the copied header,
static libraries, final link command and retained WASM string. The original
`OPENSSL_VERSION=1.1.0h` build argument did not choose the copied library. The
closure source package corrects that snapshot while preserving the original
BP-7 package intact. No runtime, loader, compiler source, lock or protocol change
is required for this correction. See the website's closure evidence for actual
package equivalence, fresh installed-page tests and any remaining gates.
