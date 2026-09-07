# Build Output

`ppphp build` emits deployable ordinary PHP through a compiler-owned transaction. The configured output root—`build/ppphp` by default—is generated state; do not edit it or place hand-maintained files beneath it.

## Scope And Up-To-Date Builds

A pathless build replaces the complete tree, including stale and unmanaged files, and records `completeProject: true`. Directory and focused builds clone the current tree, replace only manifest-owned entries in scope, and preserve unrelated entries. Partial merging requires a structurally valid current manifest with matching compiler name, public version, internal build identity, lowering version, target PHP, configuration fingerprint, output hashes, and source maps. Incompatible development output requires one pathless rebuild.

Before analysis, an exact cached bundle may validate the current manifest, output files, hashes, and maps. If all inputs and output evidence match, build returns up to date without parsing, semantics, workspace preparation, PHPStan, lowering, lint, candidate creation, or manifest rewriting. If output is absent, complete cached artifacts may reconstruct it, but reconstructed PHP is linted and committed through the normal transaction.

## Manifest Version 2

Every emitted or copied output has a source map and a sorted entry in `<output>/.ppphp/manifest.json`. Format version 2 records:

- compiler name and public release version;
- the exact path-independent compiler build identity;
- lowering format version and target PHP version;
- output-configuration fingerprint and complete-project state; and
- project-relative source/output/map paths, source kind, compile/copy operation, source/output SHA-256 hashes, and supported mode.

The manifest contains no timestamp, host path, temporary name, or transaction identity. `.ppphp` output receives `declare(strict_types=1)` and compile-time syntax is erased; project-owned `.php` is copied byte-for-byte. Each candidate PHP file is validated with bounded `PHP_BINARY -n -l` before commit.

## Stable Lock And Journal

Checks take a shared non-blocking `.ppphp-operation.lock`; build and clean take it exclusively. The lock is project-contained and outside removable output/cache roots. A conflict reports `P7009` without waiting.

After a complete candidate has passed manifest, hash, map, and lint validation, the compiler writes the canonical project-root `.ppphp-build-transaction.json`. The journal contains only format version, random transaction identity, project-relative output/stage/backup paths, candidate/prior manifest identities, and one state:

~~~text
Prepared
PreviousOutputBackedUp
CandidateCommitted
Completed
~~~

Journal updates use atomic file replacement. Candidate and previous-output roots carry a canonical marker tied to the transaction, role, output root, and manifest identity. The order is candidate validation, `Prepared`, prior-output backup, state update, candidate commit, state update, in-place validation, backup removal, then completed journal/marker removal.

## Recovery And Orphans

Every build and clean recovers a prior journal while holding the exclusive lock. Recovery validates canonical journal/marker data, path containment, manifest identity, every output hash, and every source map. A valid candidate already at output is retained and its valid marked backup is removed; an unmarked cleanup remnant is preserved without blocking the verified output. A missing or invalid output with a valid marked backup restores that backup. A prepared candidate that never displaced output is discarded only when its transaction marker matches.

When no authoritative output can be proven, corrupt or ambiguous evidence reports `P7014` and performs no guessed deletion. A directory is never removed merely because its name resembles `.ppphp-stage-*` or `.ppphp-backup-*`; unmarked or mismatched orphans are preserved for inspection. Recovery is idempotent, and cleanup failure either completes from durable evidence on the next operation or leaves the ambiguous remnant untouched.

`ppphp clean` validates output/cache ownership, performs recovery, removes output, detaches the cache while exclusively locked, and removes the detached tree after releasing the lock. It never removes the stable operation lock. `--dry-run` reports the validated generated roots without mutation.

## Determinism And Failure Semantics

Given identical sources, configuration, Composer/dependency inputs, signatures, compiler build, and target PHP, repeated pathless builds produce byte-identical PHP, copies, source maps, and manifest JSON. Filesystem timestamps are outside the content guarantee.

Handled analysis, lowering, staging, lint, commit, or recovery failures print no per-file success and do not silently lose a previous successful tree. Normal diagnostics hide transaction paths; `--debug` retains bounded normalized detail.
