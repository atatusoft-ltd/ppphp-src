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
legacy implementation in the proposed graph; that replacement is not established
until compilation, link provenance and runtime checks pass. Oniguruma 6.9.10 has
traceable source but an archived upstream. Version selection alone is not a
security or licensing qualification.

The dependency review replaces AOM 3.13.1 with 3.15.0 rather than carrying
forward known encoder memory-safety defects. The [upstream release](https://aomedia.googlesource.com/aom/+/refs/tags/v3.15.0)
records its ABI compatibility and security corrections. GD retains its release
API and image formats with the [PHP upstream GIF correction](https://github.com/php/php-src/commit/fcd691b377d02285740744bee17c0f298be227d5)
for CVE-2026-9672: initialize the decoder state/table and stop on the end code.
The recipe records before/after hashes; strict patch-context checks reject drift.
This is a specific source correction, not a claim that smoke tests exhaustively
prove memory safety. Fresh candidate execution remains required.

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
Ordinary CI runs fast recipe tests, not the expensive historical two-profile build.

The manual **PHP-WASM source rebuild** workflow selects `source-built` by default.
Use one clean build during integration; two independent clean builds are required
for reproducibility evidence. Only downloads and the pinned host-tool image may
be shared between those builds. The historical `rebuild.sh` remains available
through the workflow's `historical` profile.

## Current acceptance status

- Source acquisition and archive/tree verification: executed locally.
- Recipe, archive-safety, object-format and prefix-collision tests: executed locally.
- Source-built native compilation: in progress. The first run to reach target
  compilation built zlib (15 WASM objects, 5.395 seconds) and OpenSSL (1,082
  WASM objects, 116.363 seconds). Libiconv compiled but its header installation
  step failed; the recipe now uses the actual upstream installation source.
  These observations are from [run 34696125363](https://github.com/atatusoft-ltd/ppphp-src/actions/runs/34696125363),
  not a completed candidate or current-profile reproducibility result.
- PHP relink: not yet executed.
- New runtime compatibility, containment, full client/page/HTTPS qualification:
  pending the new executable pair; historical results are not substituted.
- Two-build byte comparison execution, matching distribution/source, durable owner retention
  and BP-8 handoff: pending.

The local machine has no running Docker daemon and insufficient spare disk for
this native build. The existing Linux workflow is the selected execution route.
No owner caches have been pruned. Build output remains in tool sessions or OS
temporary storage. `productionReady` stays `false` throughout this work.
