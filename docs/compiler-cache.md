# Compiler Cache

The ++PHP compiler uses `.ppphp-cache` to reuse only evidence whose complete inputs still match. The cache is an internal performance layer: deleting it changes neither diagnostics nor generated PHP, and a cache hit is never treated as stronger evidence than the live operation it replaces.

## Layout And Formats

The current cache format is version 1:

~~~text
.ppphp-cache/
├── compiler/v1/operations/<kind>/<prefix>/<key>.json
├── compiler/v1/blobs/<prefix>/<sha256>.blob
└── supplemental/v1/results/<kind>/<prefix>/<key>.json
~~~

Compiler records, cached diagnostics, declaration-only source, and cached artifacts have independent format versions. Records are canonical JSON; declaration-only PHP, generated PHP, source maps, and manifests are immutable content-addressed blobs. Per-source declaration units are keyed by source, compiler, declaration-format, PHPDoc/importer, namespace/import, and provenance context. Persistent PHP serialization is not used. Compiler records commit only after every referenced blob has committed.

`CompilerBuildIdentity` is a path-independent SHA-256 identity over `src/**/*.php`, `bin/ppphp`, `composer.lock`, code-driving schema/release/PHPStan resources, authoritative PHP-signature metadata, and persisted-format constants. It excludes Git state, tests, documentation, timestamps, host paths, and process data. The public version remains a release-channel identity and is not a substitute for this build identity.

## Input Snapshots And Keys

Every operation key includes the exact compiler build, format identities, target PHP version, project-relative configuration, selection, source and stub hashes, source modes, PHP-signature identity, Composer lock and installed metadata, dependency declaration source identities, and relevant output/lowering context. Supplemental PHPStan evidence additionally includes the host PHP version, pinned PHPStan executable/package/configuration identities, and bounded-process policy version.

Source additions, removals, renames, public declarations, generic bounds, checked-error contracts, namespaces/imports, stubs, Composer metadata, dependencies, signatures, compiler code, and configuration therefore invalidate their affected evidence. A body-only source edit changes the exact project result but may reuse unaffected artifact units when the project-wide public declaration fingerprint is unchanged. Conservative project-wide invalidation is preferred whenever dependency impact is uncertain.

## Evidence Boundaries

Compiler-core records contain stable diagnostics, completion metadata, and an exact declaration-context identity, not a serialized or fabricated semantic model. Supplemental records contain only successfully decoded PHPStan results; timeouts, launch failures, invalid JSON, workspace failures, and other infrastructure failures are never cached. Diagnostic labels store project-relative paths, original byte ranges, and source hashes, then rebind against the current source before reuse.

Body-free per-source declaration representations reuse the same `DeclarationContextEmitter` model as portable dependency declarations. They preserve namespace/import and ++PHP extension declaration metadata while rejecting source-identity or format mismatches. On a localized edit, unchanged declarations are loaded from hash-validated blobs for the global declaration fingerprint. General PHP-Parser objects are never serialized. Exact result hits avoid all frontend work; localized edits still safely reparse normalized PHP and rerun semantics rather than fabricating a partial AST or model.

Production bundles store the complete validated manifest and every output/map blob. An exact warm build validates the live manifest, output hashes, and maps and can return up to date without parsing, semantics, PHPStan, lowering, lint, or output replacement. If the output tree is missing, the same complete bundle reconstructs a candidate, but every reconstructed PHP artifact is still linted before commit. Local artifact records require the source hash, operation, output path, compiler/lowering context, and global declaration identity.

Normal `check` and `build` still require both compiler and supplemental evidence. PHPStan remains installed, required, and the native supplemental default; there is no public compiler-only or no-cache mode.

## Atomicity, Coordination, And Corruption

Cache files are written to unique regular temporary files in the destination directory, flushed, synchronized where available, size/hash checked, and renamed into place. Existing valid immutable blobs are reused. Readers reject links, special files, oversized content, non-canonical JSON, unexpected fields, wrong keys/kinds/formats, unsafe paths, invalid ranges, missing blobs, and hash mismatches.

Recoverable corruption is a silent safe miss: the bad regular entry is removed, statistics record the invalidation, and live analysis recomputes the result. Corruption cannot produce successful compiler or supplemental evidence. Symlinks are never followed or deleted as if they were cache entries.

`.ppphp-operation.lock` is stable at the project root, outside both output and cache. Checks take a shared non-blocking lock; build and clean take it exclusively. The lock order is operation lock, cache work, then output transaction. `clean` detaches the cache while holding the exclusive lock and never removes or recreates the stable lock.

## Limits, Pruning, And Permissions

Internal defaults bound records to 2 MiB, blobs to 32 MiB, the cache to 256 MiB, records to 6,000, blobs to 4,096, and JSON depth to 64. These are implementation limits rather than `ppphp.json` settings. Pruning removes old records first, recomputes blob reachability across compiler and supplemental records, then removes only unreferenced blobs outside the active-write grace period. The record being committed and its reachable blobs remain protected even when a low test limit cannot immediately be satisfied. Stale transaction temporaries are removed without following links.

Directories and files use private permissions where the host supports them. Records reject the project’s absolute root, carry no arbitrary environment variables, and contain only project-relative semantic paths. Cache telemetry is exposed through explicit statistics used by tests and the benchmark harness; it does not alter normal console or JSON output.
