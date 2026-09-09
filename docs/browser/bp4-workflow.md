# BP-4 compiler-owned Check and Build

Protocol **3** adds full Check and Build to the hidden `browser:analysis` command.
Versions 1 (preparation) and 2 (compiler-core analysis) keep their existing
contracts. The governing [work order](../ppphp-browser-production-bp4-codex-prompt.md)
is committed verbatim. Qualification results and artifact identities are in
[bp4-evidence.md](bp4-evidence.md). This is an internal compiler transport;
production Run, website integration and route enablement remain outside BP-4.

## Ownership and invocation

The host owns one serialized logical workspace. Each PHP invocation acquires the
existing project operation lock, recovers any native build transaction, and
releases the lock before returning. No lock is claimed to survive between PHP
invocations. The browser qualification host retains bounded filesystem bytes
between fresh workers and terminates each worker before accepting another phase.
It verifies worker ownership before accepting any returned filesystem bytes;
a queued message from a terminated worker cannot overwrite a later workspace.
It mounts trusted compiler dependencies at `/opt/ppphp` and project data at
`/workspace`; it explicitly sets the real PHP cwd to `/workspace`.

Write the request to `.ppphp-browser/request.json`, then invoke:

```text
php /opt/ppphp/bin/ppphp browser:analysis .ppphp-browser/request.json --working-directory=/workspace --no-interaction --no-ansi
```

Protocol 3 requires the real cwd to equal the canonical project root, the fixed
request file and the project configuration. Configuration overrides are rejected.
Source, stub, vendor, output and cache roots must be contained in the owned
project and cannot overlap the reserved `.ppphp-browser` control directory.
Symlinks and special files are not supported by this transport.

`ProjectChecker` prepares and completes the full retained PHPStan analysis.
`Compiler::prepareProduction()` is shared by native Build and this transport;
it uses the existing output planner and production emitter. The compiler owns
source maps, ordinary PHP copy operations, diagnostics, configuration identity,
manifest construction, partial merge validation and atomic publication. JavaScript
neither lowers source nor maps diagnostics. The BP-3 adapter stays test-only and
is not called by the production worker path.

## Requests

Objects have exactly the documented fields. Unknown, missing and duplicate JSON
keys (including escaped spellings and keys after large strings), wrong primitive types, malformed
hashes and unsafe paths fail closed. Hashes are lowercase `sha256:` plus 64 hex
digits. Operation IDs contain 1 to 128 ASCII letters, digits, `_`, `.`, `/` or `-`
and begin with a letter or digit. Sequences are integers from 1 to 1,000,000.
Each new start requires an unused ID and a larger sequence. A workspace retains
up to 256 operation IDs; the host must create a fresh workspace after that limit.

Start has this shape; the hashes shown as placeholders must be replaced by the
verified runtime artifact and loader hashes:

```json
{
  "version": 3,
  "action": "start",
  "operationId": "editor/build/1",
  "sequence": 1,
  "operation": "build",
  "selection": { "path": null },
  "runtime": {
    "phpVersion": "8.4.23",
    "sapi": "cli",
    "intSize": 8,
    "artifact": "sha256:<verified-wasm-hash>",
    "loader": "sha256:<verified-loader-hash>"
  }
}
```

`operation` is `check` or `build`. A null selection means the complete project;
a project-relative source file or directory selects the existing native scope.
The runtime's observed PHP version, SAPI and integer size must match the start
record, and the configured emission target must be PHP 8.4. The qualified browser
CLI reports SAPI `cli`; its separate filesystem host reports `wasm`. Those are
separate observations and must not be substituted for one another.

Continuation requests contain exactly:

```json
{
  "version": 3,
  "action": "complete-analysis",
  "operationId": "editor/build/1",
  "sequence": 1,
  "continuation": "sha256:<returned-continuation-hash>",
  "results": [
    {
      "invocation": "sha256:<returned-invocation-identity>",
      "stdout": "<complete observed stdout>",
      "stderr": "<complete observed stderr>",
      "exitCode": 0,
      "complete": true,
      "timedOut": false,
      "outputLimitExceeded": false,
      "executionFailure": null
    }
  ]
}
```

Use `complete-lint` for pending validation. `abort` has the same envelope with an
empty results list; it revokes the current continuation and discards its pending
artifacts. A later start also supersedes an unfinished operation. Terminal state,
wrong phase, ID, sequence, continuation or ordered invocation set is rejected.
A rejected completion cannot publish. The owner can abort a still-current
operation or recover with a fresh start; old results cannot authorize that start.

Each result is the real process observation, not just a boolean success marker.
`exitCode` is null or an integer from -1 to 255. `executionFailure` is null or a
bounded UTF-8 string. The result set must be complete and in the compiler's order,
with no duplicate or unexpected invocation. Timeout, overflow, incomplete streams,
launch failure and malformed success output cannot count as validation.

## State and consistency

A canonical, hashed continuation stores typed operation data, source/output/compiler
identities, the current phase, ordered invocation identities and the bounded raw
analyzer observation. It does not deserialize PHP objects, ASTs or semantic models.
Every continuation reconstructs compiler preparation from the current project.

The project snapshot hashes the complete relevant directory/file set, paths,
bytes, modes and kinds, including unselected files, additions/removals, config,
stubs and dependency metadata. Only compiler-owned output, cache, control state,
operation lock and transaction journal are excluded. Prior output is separately
hashed in full. Compiler identity comes from `CompilerBuildIdentity`, which binds
compiler sources, resources and the dependency lock. The PHPStan PHAR hash is
included in every invocation binding. Start data binds operation, selection,
sequence, runtime and target configuration into those identities.

The analyzer invocation additionally binds generated analysis inputs and selected
progress paths. The lint phase binds the whole candidate artifact descriptor set,
including emitted/copied bytes, operation and source-map hashes. The host verifies
the compiler archive and actual WASM bytes before extraction/instantiation and
checks every fixed invocation identity. Content hashes establish consistency
inside this trusted ownership model; they do not authenticate an untrusted host.
Projects must never be allowed to supply or rewrite control state or observations.

PHPStan debug framing accepts each compiler-provided selected path exactly once,
then one complete JSON result. It rejects missing/duplicate/unknown progress,
unexpected files, duplicate JSON keys, inconsistent totals, trailing noise,
truncation and unexpected stderr. Existing PHPStan result parsing, diagnostic
mapping and full `ProjectChecker::complete()` remain authoritative. Real exit 1
with findings is distinct from a subprocess or transport failure.

## States and publication

| Response status | Meaning |
| --- | --- |
| `pending-analysis` | Execute the one compiler-approved retained PHPStan invocation. No production output exists for this operation. |
| `pending-validation` | Compiler production artifacts and maps are staged privately. Execute every approved `php -n -l` invocation. |
| `complete` | Check succeeded, or Build atomically committed its complete selected/merged output. |
| `diagnostics` | Source/project diagnostics prevented success. |
| `output-failure` | Production planning, manifest compatibility, lint or publication failed. |
| `infrastructure-failure` | Analysis/transport infrastructure failed; never treat this as a valid program. |
| `rejected` | Invalid request, stale state or mismatched evidence. |
| `aborted` | Current continuation revoked, pending artifacts discarded. |

The response includes operation/sequence, snapshot, compiler/runtime identity,
selection, status and `compilerStatus`. Pending status has a continuation and
approved invocations. Terminal status has no continuation. Check never publishes.
Diagnostic outcomes retain the existing compiler statuses 1/2/3; unexpected
infrastructure exceptions use 70. A protocol CLI exit of 0 means a structured
response was produced, not that Build succeeded. Inspect `status`, `compilerStatus`
and `currentOutput` together. Rejected requests use CLI exit 2; internal exceptions
use 70. Retained analyzer failures can report infrastructure status with compiler
status 1, matching the native compiler diagnostic contract.

Actual `php -n -l` invokes Zend compilation without executing candidate top-level
code. `WorkflowLintRunner` consumes the complete bound observations through the
existing `PhpLintValidator`; it checks the exact restaged bytes and complete final
lint success line again. Recognized nonfatal PHP warnings, notices and deprecations
for that exact candidate path retain native Build's successful behavior. Arbitrary
stream noise, missing success text or a warning naming another file is rejected.
Production is reconstructed before commit and compared with
the pending candidate, source maps and exact file membership. The shared
`AtomicBuildCommitter` validates the full candidate both before and after lint and
compares every candidate file hash, including otherwise unowned retained files.
Post-lint mutation or addition cannot publish.

The existing native committer performs manifest-aware partial merges, stale-file
removal and the final directory transaction. A terminal reservation is persisted
before publication, so an interrupted completion cannot replay publication. On
successful Build, `currentOutput` identifies the snapshot, operation, sequence,
output-tree and manifest hashes, root, artifact paths/hashes/operations, source
maps and complete manifest. These are verified retrievable artifact references,
not a general filesystem-read interface. The qualification page exports their
exact bytes as base64; ordinary PHP bytes are not decoded/re-encoded.

A failed B response has `currentOutput: null`; `previousOutput` identifies A only
when its saved publication identity still matches the actual tree. It cannot be
presented as B's successful output. If publication succeeds but response delivery
is interrupted, the host must not assume success. A fresh operation can recover;
there is no claim of browser disk durability after worker/tab termination.

## Bounds and qualification host

| Resource | Implemented bound |
| --- | --- |
| Project sources and stubs | 64 files, 1 MiB combined within the compiler transport |
| Teaching fixture host input | Existing 12 files and 64 KiB source budget |
| Serialized workspace tree | 1,024 records, 16 MiB combined |
| New production artifacts plus maps | 64 artifacts, 1 MiB combined |
| Request JSON | 2,162,688 bytes, bounded nesting |
| Response JSON / raw process streams | 2 MiB each envelope / combined stdout and stderr |
| Diagnostics | 1,000 |
| Fresh-worker phase / logical operation | 90 seconds / 360 seconds, external timers |
| Full browser qualification | 45 minutes, plus bounded cleanup |
| Active PHP ownership | One phase worker, containing one filesystem host and one CLI runtime |
| PHP analyzer memory | 256 MiB; this is not a whole-browser memory measurement |
| Retained WASM linear memory | 64 MiB initial, 2 GiB maximum per instance; 1 MiB stack |

The reusable production host policy and measured whole-browser memory budget are
BP-5 work. This qualification host deliberately discards both PHP instances after
each top-level CLI operation. It does not rely on `extra_exit()` or runtime reuse
across unrelated CLI executions. Hydration precedes timed work. The test runner
puts Chrome offline after hydration, and the worker rejects fetch during compute.
No remote compiler, execution endpoint or native subprocess stands in for browser
analysis or lint. The local DevTools automation channel carries test instructions
and observations; it is separate from application networking. The report retains
hydration resource URLs/sizes and hashes of every built browser asset.

Paths and protocol strings must be valid UTF-8. Source/output filesystem data is
transferred as byte arrays and downloaded as base64, without lossy replacement.
The teaching interface supplies UTF-8 source, including CRLF and Unicode cases.
Arbitrary non-UTF-8 source is not a qualified editor profile; invalid UTF-8 in a
JSON process result is rejected rather than decoded with replacement characters.

## Tests and reproducible commands

Use the verified retained runtime directory. Do not rebuild PHP for JavaScript,
fixtures or documentation changes. Set `PHP_BINARY` to a supported native PHP
binary and keep all raw outputs in an OS temporary directory outside workspaces.
The evidence directory must be beneath the runtime's `os.tmpdir()`; set `TMPDIR`
to the chosen OS temporary root when using a different system temporary location.
The original runtime verification archive is historical provenance, not the
separate accepted BP-3 raw evidence ZIP. The accepted BP-3 summary is already
committed in [bp3-analyzer-parity.md](bp3-analyzer-parity.md).

Run these browser suites serially because they share the local preview port and
built pages. The Fiber contract consumes the candidate page produced immediately
before it:

```sh
node tools/php-wasm-runtime/verify-built.mjs --runtime "$RUNTIME_ROOT/baseline" --expect baseline --output "$EVIDENCE_ROOT/baseline-control"
node tools/php-wasm-runtime/verify-built.mjs --runtime "$RUNTIME_ROOT/candidate" --expect candidate --output "$EVIDENCE_ROOT/candidate-control"
node tools/web-spike/scripts/run-fiber-contract.mjs --wasm-sha256 3198aa074ad42bd2fad37d9af80a4a382df743bd1df0651ded4d3d3fc1cb80f9 --output "$EVIDENCE_ROOT/fiber-control"
node tools/web-spike/scripts/run-project-parity.mjs --runtime "$RUNTIME_ROOT/candidate" --wasm-sha256 3198aa074ad42bd2fad37d9af80a4a382df743bd1df0651ded4d3d3fc1cb80f9 --corpus tools/web-spike/fixtures/website-corpus.json --controls "$EVIDENCE_ROOT" --output "$EVIDENCE_ROOT/bp3"
node tools/web-spike/scripts/run-workflow.mjs --runtime "$RUNTIME_ROOT/candidate" --wasm-sha256 3198aa074ad42bd2fad37d9af80a4a382df743bd1df0651ded4d3d3fc1cb80f9 --corpus tools/web-spike/fixtures/website-corpus.json --output "$EVIDENCE_ROOT/bp4"
```

The frozen corpus is committed byte-for-byte, so qualification does not depend
on a private website checkout. `--case <id>` and `--sequence-only` are focused
controls and do not establish a complete-corpus pass. The new manual
`browser-workflow.yml` workflow runs controls, independent BP-3, then BP-4 and
uploads checksummed evidence with seven-day retention. It uses the recorded build
and artifact names with pinned manifest and WASM hashes; expired artifacts fail
closed. Ordinary green CI does not imply
this manual browser suite ran.

`BrowserWorkflowTest` exercises real native PHPStan/lint through protocol 3 plus
labelled transport faults. `StageTenCommandsTest` covers the shared native
transaction/failure seams, including post-lint mutation followed by recovery.
Existing manifest, source-map, PHP lint and transaction recovery suites remain
applicable. The browser sequence uses the actual production command except for a
fixed, test-only invalid-emitter entrypoint under `tools/web-spike`. That seam
injects invalid compiler emission through `ProductionEmitter`, retaining the same
analysis, protocol and real PHP lint path. No public request flag enables it.
The sequential B fixture reaches retained PHPStan before returning a type error.
Acceptance requires replayed completions from both A and B after C, rejection
without current-output authority, and preservation of C against an independent
native Build reference. A fixture rejected before analysis cannot establish the
stale-B completion requirement.

## Concrete BP-5 handoff

Consume protocol 3 through a shared browser client with explicit readiness,
checking, building, cancellation, failure and disposal states. Preserve the exact
continuation/identity and byte verification contract and old-output labels.
Promote only a Build success for the current editor snapshot to Run eligibility.
Keep the BP-3 and BP-4 suites independent and rerun both on host changes.

Implement Run in a separate fresh user-code worker with only the verified current
build and approved runtime dependencies. Add the planned opaque-frame handshake,
dedicated channel, bounded queue, navigation disposal, CSP, denied host/network
capabilities and escaped output. Qualify CPU/output/memory exhaustion, fatal exit,
late results and recovery. Measure aggregate JS/WASM memory and choose production
deadlines from evidence. Archive allowlists, upstream loader warnings and broader
browser/device qualification remain later production gates. Do not wire website
routes or enable production execution as part of this handoff.
