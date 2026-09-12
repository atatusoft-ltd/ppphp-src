# Source-built browser runtime candidate

This is the implementation record for the [BP-7R work order](../ppphp-browser-production-native-rebuild-codex-prompt.md).
It does not approve a runtime for distribution or production. The retained runtime
remains an unchanged historical comparison, not an approved public rollback target.

## Inputs and build boundaries

The compiler owns `tools/php-wasm-runtime/native-inputs.json`. It pins the
source archives, dependency graph, initial PHP/SDK profile, target flags and
container platform. `native-recipes.py` contains the effective library commands;
receipts include its hash, executed commands, patches, dependency receipts,
configuration hashes and installed-file hashes. Website integration must consume
the resulting receipt, not create a second set of native pins.

The retained PHP recipe links zlib, ZIP, XML, SQLite, GD, JPEG, PNG, WebP,
SharpYUV, AVIF, OpenSSL, iconv, curl and Oniguruma. AOM is also required behind
AVIF. The old recipe copied several complete prebuilt prefixes into one directory,
then copied ZIP and OpenSSL prefixes over that directory. The new path never
copies those inputs. Each library has its own prefix and a collision-checked
view of its declared dependencies, including transitive dependencies. The final
link lists every selected archive explicitly; archive members must be WASM
objects, not host-native objects or unidentified bitcode.

PHP-bundled libraries remain part of the pinned PHP source. Emscripten's system
libraries are SDK inputs, separate from third-party native library receipts.
Autoconf, Automake, Bison, Flex, libtool, pkgconf and re2c are host tools acquired
from a dated, signed Ubuntu snapshot. The SDK image is platform/digest pinned.
Host package versions are recorded; the task does not rebuild LLVM or Ubuntu.

The initial comparison keeps PHP 8.4.23 and Emscripten 4.0.19. These are explicit
candidate inputs, not a permanent platform ceiling. OpenSSL 3.5.8 replaces the
legacy implementation in the executed source-built graph; compilation, link
provenance and runtime checks establish that replacement. Oniguruma 6.9.10 has
traceable source but an archived upstream. Version selection alone is not a
security or licensing qualification.

The dependency review replaces AOM 3.13.1 with 3.15.0 rather than carrying
forward known encoder memory-safety defects. The [upstream release](https://aomedia.googlesource.com/aom/+/refs/tags/v3.15.0)
records its ABI compatibility and security corrections. GD retains its release
API and image formats with the [PHP upstream GIF correction](https://github.com/php/php-src/commit/fcd691b377d02285740744bee17c0f298be227d5)
for CVE-2026-9672: initialize the decoder state/table and stop on the end code.
The recipe records before/after hashes; strict patch-context checks reject drift.
This is a specific source correction, not a claim that smoke tests exhaustively
prove memory safety. The candidate's separately recorded execution tests below
verify its retained functionality and bounded failure contracts.

GD's AVIF discovery consumes libavif's installed pkg-config metadata and imported
target. This static libavif profile does not install a CMake config package;
pretending to be a VCPKG/shared build would misdescribe the producer. GD's private
overflow helper is renamed without removing its checks to avoid a final-link
symbol collision. Its font-cache cleanup is empty only in the retained no-FreeType
profile, where no font cache exists. Source patch receipts retain each change.

The same review checks PHP-bundled libraries rather than trusting the version
label alone. The pinned PHP source already bounds PHAR link traversal by the
manifest size; the native suite tests short, long and tail-into-cycle archives.
BCMath lacks the [upstream fractional-endpoint correction](https://github.com/php/php-src/commit/4f83876af75d43b24cf3ed567394451f42e6bca1)
for CVE-2026-17544. The source-built path applies that one-line correction with
strict context and before/after hashes. The historical rebuild and Fiber repair
are unchanged. The new regression covers small and larger allocations and both
signs; source correspondence and runtime execution remain separate evidence.

AOM's Gitiles archive contains request-time entry timestamps. Its immutable
commit and reconstructed Git tree (file content, executable modes and symlinks)
are verified instead of trusting a changing transport checksum. The acquired
archive's actual checksum is retained. Extraction sets deterministic source
timestamps before compilation; no resulting binary is rewritten to make it match.

WordPress Playground's complete upstream archive contains historical prebuilt
libraries and runtime payloads. Acquisition verifies that original archive, then
produces a deterministic source-only archive from the manifest's explicit path
selection. The selected tree is separately pinned and includes the PHP integration,
wrapper source, supporting build tools and notices; it excludes the old binaries.
The selection preserves source bytes and executable modes. Its actual archive hash
is recorded with every other acquisition and consumed by Website packaging.
The public corresponding-source package carries this verified selection, not the
complete historical archive. Original and selected PHP integration inventories
were compared byte-for-byte and match.

## Commands

Use new OS-temporary output directories, outside all user workspaces:

```bash
python3 tools/php-wasm-runtime/native.py acquire --store /tmp/ppphp-native-inputs
python3 tools/php-wasm-runtime/source-build.py build --clean \
  --store /tmp/ppphp-native-inputs --output /tmp/ppphp-native-build-1
node tools/php-wasm-runtime/verify-built.mjs \
  --runtime /tmp/ppphp-native-build-1/candidate --expect candidate \
  --output /tmp/ppphp-native-build-1/compatibility
python3 tools/php-wasm-runtime/source-build.py compare \
  --first /tmp/ppphp-native-build-1 --second /tmp/ppphp-native-build-2 \
  --output /tmp/ppphp-native-reproducibility.json
```

Source acquisition and toolchain installation precede network-disabled target
compilation. Library build steps have finite 30-minute command budgets and use
two jobs. The native phase has a 90-minute budget; PHP's phase has three hours.
The manual workflow has a four-hour total limit, so its total is also bounded.
Completed native prefixes and receipts are copied out before PHP compilation.
CI retains that checkpoint inside a tar archive, because ZIP artifact transport
dereferences installed symlinks. Validate archive paths and internal links before
extracting `native-checkpoint.tar` into its build directory for comparison.
Comparison checks every installed inventory against its producing receipt before
comparing the two builds; identical transport damage cannot count as reproducibility.
The final link alone uses Emscripten's canonical temporary-directory mode
(`EMCC_DEBUG=1` with a fresh `EMCC_TEMP_DIR`). The pinned SDK otherwise generates
random temporary object paths that leak into its link map. This supported setting
retains linker intermediates and enables build logging; the explicit optimization,
PHP debug and Asyncify settings remain unchanged. Its two environment values are
recorded with the final command. The map is compared unchanged, not rewritten
after linking or omitted from reproducibility evidence.
Ordinary CI runs fast recipe tests, not the expensive historical two-profile build.
The workflow caches only fully verified source downloads, immediately after
acquisition so a later compile failure does not discard them. Cache identity
includes the manifest and acquisition implementation; every restored file is
checked again before use. Compiled libraries are excluded from this cache.

The manual **PHP-WASM source rebuild** workflow selects `source-built` by default.
Use one clean build during integration; two independent clean builds are required
for reproducibility evidence. Only downloads and the pinned host-tool image may
be shared between those builds. The historical `rebuild.sh` remains available
through the workflow's `historical` profile.

For BP-3, run the retained repaired runtime with `verify-built.mjs --expect
candidate` into `comparison-control/`, and the new runtime into
`candidate-control/`, followed by its `fiber-control/`. Pass the parent's
`--controls` directory and the retained runtime's explicit
`--comparison-wasm-sha256` to `run-project-parity.mjs`. This requires every
comparison case to pass on the observed comparison bytes and current compiler
lock; it does not accept the deliberately broken historical Fiber baseline as
a repaired-runtime comparison. Without this option the earlier negative-control
contract remains unchanged. BP-4 and Website qualification use the new pair.

The fresh BP-4 run exposed two stale harness assumptions: its analyzer allowlist
omitted the compiler-owned extension autoloader, and its loop permitted only four
protocol turns. The harness now checks the exact current command and allows fresh
analysis rounds under the unchanged operation deadline, rejecting replay and
analysis after lint. Tests exercise the actual compiler-generated command and
multi-round progression. No production compiler or Website capability was relaxed.
The fault-injection sequence uses the same progression code to reach pending lint,
rather than assuming one analyzer round; its complete sequential regression passes.

Ordinary CI exhausted its existing 30-minute job budget while the expanded Pest
suite was still passing. CI now runs four isolated shards per PHP host, retaining
that deadline. All 2,169 listed tests occur exactly once across the four shards.
The existing required PHP checks depend on every shard succeeding and then run
`composer check:contracts`; `composer check` still includes those gates and the
entire test suite. No test or aggregate check was removed.

## Current acceptance status

- Source acquisition and archive/tree verification: executed locally.
- Recipe, archive-safety, object-format and prefix-collision tests: executed locally.
- Source-built native compilation and PHP relink: PASS in
  [run 34702000916](https://github.com/atatusoft-ltd/ppphp-src/actions/runs/34702000916).
  All 14 producers compiled and their archives contain WASM objects. The complete
  build took about 25.3 minutes; OpenSSL took 119.437 seconds and curl 187.257 seconds,
  mostly configuration. The native prefixes survived PHP compilation, but GitHub's
  ZIP transport flattened three libpng symlinks. That downloaded checkpoint is not
  accepted as intact reproducibility evidence; subsequent runs retain it in tar.
- Initial candidate controls: 15 runtime/PHPStan, eight Fiber lifecycle and
  16 native-library/security regression cases passed in that run. Local runtime
  controls also passed. This is not the full independent qualification.
- The linker command and map were inspected against the native receipts: every
  supplied third-party archive is source-built; 16 contribute object code and the
  auxiliary libcharset archive is unused. The other contributing archives are
  from the pinned Emscripten sysroot. The actual installed OpenSSL headers identify
  3.5.8, its produced archives match the link receipt, and the runtime version and
  local cryptographic operations pass.
- Initial WASM audit: no internal `memory.grow` instructions. Compared with the
  repaired runtime, imports add two indirect-call signatures and WASI `random_get`,
  and remove two unused indirect-call signatures. The generated `random_get`
  implementation uses `crypto.getRandomValues`; it adds no networking capability.
  A seventeenth local native regression exercises OpenSSL with that browser entropy
  source unavailable. It observes the propagated failure, while the separate PHP
  random-bytes regression observes `RandomException`. Neither may report success
  without an actual entropy request. Both pass; production entropy is unchanged.
- Initial independent compiler parity: all 48 cases PASS, including 18 Website
  teaching cases, malformed analyzer output and recovery. The broader workflow,
  client, real-page and installed-delivery suites remain separate gates.
- The first two-clean-build comparison in
  [run 34704147604](https://github.com/atatusoft-ltd/ppphp-src/actions/runs/34704147604)
  failed on receipt identity, not installed libraries, WASM, loader or link maps.
  Both candidates passed their runtime, Fiber and native controls. The sole root
  difference is OpenSSL's generated `Makefile`; curl and ZIP inherit its receipt
  identity. The pinned template emits `DEPS` from unsorted Perl hash keys. A
  recorded one-line template patch sorts that complete set before generation.
  Two local configuration runs reproduce the difference before the patch and
  identical configuration bytes afterward. The failed receipts remain unchanged.
- The corrected two-clean-build run
  [34707400759](https://github.com/atatusoft-ltd/ppphp-src/actions/runs/34707400759)
  passes at compiler commit `8cb510f1e8697d53039e700070248d3ae0706b96`.
  Both builds used `--no-cache` for native libraries and PHP, sharing only verified
  downloads and the pinned host-tool image. Build times were 1,481.433 and 1,452.364
  seconds. Every installed file, canonical producer/runtime receipt, WASM, loader
  and final-link record matches. Safe checkpoint extraction and local comparison
  reproduce the same PASS, including the installed symlinks. Cross-host
  reproducibility is NOT RUN; peak build RSS and active engineering time were not
  metered. Each candidate passes 15 runtime/PHPStan, eight Fiber and 17 native
  compatibility/security checks.
- The retained execution records cover five completed builds: the initial
  candidate, the first two-build comparison, and the corrected pair. Their
  compilation totals approximately 126.1 minutes, excluding earlier interrupted
  attempts, acquisition, browser qualification and active engineering work.
  The first comparison's two builds took 1,644.447 and 1,470.346 seconds.
  This is measured completed-build effort, not the total task duration.
- The final runtime receipt is
  `5c5720b30972072723b3a23d663eb18dc246cf308a9ec5daa352bdb4e972d8bc`;
  WASM is `ec3b5374a6315cd07a0f76ff1ce4051ba3de7f8068f00dd02df8d31f4a1059db`
  (32,919,008 bytes), and its matching loader is
  `8b4674837fccc8ba115282413da317a13e306420681579b8f333520045a53320`
  (381,974 bytes). Both full comparison inventories hash to
  `87581087b60d8157037bbb729931b8ef4b2a0250d63cd40f59eb418f9f3b3312`.
- Fresh BP-4: all 48 compiler/teaching cases and the sequential fault/recovery
  checks PASS, with complete-corpus, native-output and stale-owner assertions.
  This run used the new source-built executable pair above, unchanged by the
  later receipt-ordering correction, not the historical comparison runtime.
- Two clean Website packages using the separate native-build outputs match all
  86 members, sidecars and the compressed archive. Safe outer/source extraction,
  eight byte-identical frontend outputs reconstructed from supplied source, and
  exact reconstruction of the Linux build context from its 18 packaged source
  inputs and five recipes all PASS. This does not claim another native build on
  macOS. See the Website `docs/browser/bp7r-evidence.md` for package identities.
- The current-input BP-3 refresh passes all 48 cases against compiler `8cb510f`,
  its current dependency lock and the final runtime receipt. Fresh local controls
  pass 15 runtime/PHPStan, eight Fiber and 17 native/security cases.
- Full client qualification found historical-only admission pins in the Website
  client and program worker. Website `cc8fb1a` binds builder, client and workers
  to one reviewed WASM/loader/receipt/size profile. Wrong pairs and corrupt bytes
  are rejected before interpreter allocation; actual Build/Run smoke, type checks,
  contract tests, editor regressions and Website CI pass. The final package pair
  was rebuilt after this correction; its source contains 144 safely checked
  members and reproduces the frontend and native context exactly.
- Full client qualification now passes 75 checks and all ten additional real-client
  boundary checks, including actual 256-operation rotation and recovery. Chrome
  completes the full suite; Firefox completes a limited real-execution smoke.
  WebKit is NOT RUN because its automation binary is not installed.
- Installed HTTPS delivery passes all 14 checks, including complete source
  download, cache/fault recovery, and coherent rollback between two replacement
  packages. It is local Apache evidence, not public-host qualification.
- All 49 actual-page checks pass against the installed HTTPS package, including
  all 18 teaching inputs, native diagnostic/artifact comparison, multi-round
  annotations, lifecycle/security recovery, and the limited Firefox lesson
  check. The final source-identity and no-execution-traffic assertions pass.
- The selected package, compatible rollback-test package, original native
  checkpoints, external sidecars and bounded evidence are retained in the
  owner's Downloads folder `ppphp-bp7r-source-built-2026-09-12`, with its README
  and manifest as the entry point. The 80-member evidence archive is 2,723,314
  bytes, SHA-256 `0c2effcaa2e81b21bbfc222e928d606f15015a17af9c55508b35b1e91e1a01b4`.
  It includes failures and intermediate results as such, excludes private
  application/profile/key/log material, and passes safe member/hash verification.
  BP-7R is complete and hands these exact inputs to BP-8. Wider device acceptance
  and BP-9 hosting/publication/activation remain NOT RUN; historical results
  have not been substituted for these new executable bytes.

The local machine has no running Docker daemon and insufficient spare disk for
this native build. The existing Linux workflow is the selected execution route.
No owner caches have been pruned. Build output remains in tool sessions or OS
temporary storage. `productionReady` stays `false` throughout this work.
