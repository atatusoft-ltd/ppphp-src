# Editor Protocol

> **Status:** Definition, semantic-token and unsaved-buffer diagnostic protocols are compiler-owned and independent of production builds. Each uses version 1. Saved-file checks retain supplemental analysis; live diagnostics explicitly report compiler-core coverage.

The compiler owns semantic editor queries so every editor observes the same ++PHP project model. Editor adapters must not infer symbol identity from text.

## Unsaved-Buffer Diagnostics

### Retained Worker Transport

`ppphp editor:diagnostics --server --working-directory <project> --format=json`
keeps a compiler-owned worker alive. The single-shot command without `--server`
is unchanged and remains the fallback. Worker transport version 1 wraps the
existing diagnostic request/response version 1; it does not add analysis coverage.
Use one worker per project/configuration-path/compiler installation. Restart it
when changing those launch settings or replacing the compiler installation.

The worker immediately writes one UTF-8 JSON line with `version: 1`, `type: "ready"`,
`compilerVersion`, `compilerBuildIdentity` (an opaque `sha256:` identity), and
`capabilities`. Capabilities advertise `diagnosticsVersion: 1`,
`maxInFlight: 1`, `cancellation: "client-discard"`, `maxFrameBytes: 16778240`,
`maxResponseBytes: 4195328`, `maxRequests: 1000`, and `singleShotFallback: true`.
Read and validate this handshake before sending requests. It means transport-ready,
not platform-prewarmed: the first successful analysis can still be cold.

Each request must be one JSON object followed by LF (CRLF is accepted). Embedded
newlines in source are JSON escapes, not frame delimiters. Example:

~~~json
{"version":1,"id":1,"method":"diagnostics","params":{"version":1,"document":{"path":"src/main.ppphp","contents":"<?php int $value = 1;","version":7}}}
~~~

IDs are strictly increasing positive integers, at most `9007199254740991`, scoped
to one worker. `params` is the complete single-shot request described below. The
response is `{"version":1,"id":1,"result":<single-shot response>,"recycle":false}`.
The nested result preserves document revision, diagnostic items, coverage, summary,
and structured errors exactly; a diagnostic error does not terminate the worker.
The frame limit includes its newline; the nested request retains all original limits.

Keep at most one request in flight. If it becomes obsolete, mark its ID/revision
superseded, retain only the latest pending editor snapshot, and discard that response
when received. Then send the newest pending request. There is no wire `cancel`
method and no promise of mid-pass CPU cancellation. Do not kill a healthy worker on
every edit: doing so discards reusable state and repeats cold startup. Enforce a
client-side startup/request timeout, reap a failed worker, and fall back or restart.
Never interpret EOF, a missing/mismatched response, or a protocol failure as an empty
successful diagnostic result. A superseded result must not clear newer errors.

`{"version":1,"id":2,"method":"shutdown"}` receives `result: null, recycle: true`
and exits successfully. Idle EOF exits successfully. Malformed/oversized/truncated
frames, unsupported versions/methods, missing params, and invalid/reused IDs produce
an `error` envelope with `code: "invalid-frame"`, `recycle: true`, and exit 2.
Unexpected transport failures may exit 70 without a response. No unbounded input
queue or additional files, sockets, project bootstrap, or background child process
is created by the compiler worker.

Before each analysis the worker rechecks its compiler source, lockfile, and
identity-bearing resources against `compilerBuildIdentity`. A same-version
replacement or unavailable identity input produces an `error` envelope with
`code: "installation-changed"`, `recycle: true`, and exit 0, without analysis.
Retire that worker and retry the latest non-superseded snapshot on a fresh one;
do not clear diagnostics. Identity failures before the handshake exit 70.
Clients need not reproduce the compiler's fingerprint algorithm. Installation
updates are not atomic analysis snapshots: clients must still restart on known
installation-change events and avoid editing compiler files during a request.

After 1,000 requests or when allocated memory reaches 256 MiB, the final normal
response has `recycle: true` and the worker exits; start another before sending more.
An internal diagnostic-handler failure also requests recycling. The memory threshold
is checked between requests, not a replacement for PHP's per-process memory limit.

Every request clears filesystem stat caches and reloads project configuration,
Composer/installed-package metadata, stubs, discovered sources and explicit overlays.
Deleted/renamed files and changed contracts are not served from old project results.
Only immutable derived metadata and platform modules are reused. Signature manifest
and output hashes are checked before module reuse; changes trigger verification and
reload, while corruption fails closed. No diagnostic result, project semantic model,
production output, lock, or disk analysis cache is replayed or written.

The worker provides reuse, not a universal latency guarantee. Editors should measure
actual valid → error → repaired transitions, including debounce, transport, cold
startup and worker recycling. The first request is not covered by warm measurements.

### Single-Shot Request And Response

`ppphp editor:diagnostics --working-directory <project> --format=json` reads one
bounded UTF-8 JSON request from stdin. It diagnoses one PHP or ++PHP document,
using optional other open documents as unsaved declaration context:

~~~json
{
  "version": 1,
  "document": {
    "path": "src/main.ppphp",
    "contents": "<?php\nint $value = 'wrong';\n",
    "version": 7
  },
  "overlays": [
    {
      "path": "src/Box.ppphp",
      "contents": "<?php\nclass Box<T> { public function __construct(public T $value) {} }\n"
    }
  ]
}
~~~

The top-level version is the protocol version. The optional integer
`document.version` is an opaque editor revision, echoed unchanged (or `null`
when omitted). `overlays` defaults to an empty list. Every document requires
string `path` and `contents`; an empty string is a valid buffer, not deletion.

Paths may be absolute or project-relative. The target must identify a `.php` or
`.ppphp` source beneath an existing configured source root. Excluded paths,
configured stubs, output/cache paths, directories and links within the project
cannot supply source buffers: they reject a target, but are ignored as context
overlays. Editors can send their open-buffer snapshot without reimplementing
compiler ownership rules; opening a vendor or output file does not disable
diagnostics in an owned target. Ignored overlays never replace disk declarations.
Traversal and malformed entries reject the entire request even in context.
Configured project-root aliases are normalized before ownership
checks. A new buffer may have missing intermediate directories; no directory or
file is created. An open file deleted on disk can still be checked using its
supplied contents. Without contents a missing document is an invalid request;
there is no implicit disk fallback for the target or an explicitly supplied
overlay. Duplicate owned normalized paths, including target/context duplication,
reject the entire request. This protocol does not accept untitled URI targets.

Each request is an independent snapshot: overlays replace the corresponding
disk source before parsing or declaration collection, and files not supplied
come from the loaded project. Only the target is selected for diagnostics;
other overlays supply focused declaration context, just like unselected saved
files. Malformed unrelated bodies do not become target diagnostics. Invalid
declaration headers are not fabricated. Related locations or project-global
diagnostics may refer to context files; clients must retain those locations.

The response reuses the normal `check --format=json` diagnostic items and
summary, adding the target revision, analysis coverage and protocol error:

~~~json
{
  "version": 1,
  "document": { "path": "src/main.ppphp", "version": 7 },
  "diagnostics": [],
  "summary": { "errors": 0, "warnings": 0, "notes": 0 },
  "analysis": {
    "completeness": "compilerCore",
    "catalogVersion": 4,
    "fullParity": true,
    "uncoveredRequiredCapabilities": [],
    "supplemental": false
  },
  "error": null
}
~~~

This empty-diagnostics example represents a successful buffer. Findings contain
the unchanged catalog-owned `code`, `severity`, `title`, `message`, `location`,
`related`, and `help` fields. Source ranges refer to supplied contents, never
saved text or generated PHP: zero-based, half-open UTF-8 byte offsets, one-based
lines and Unicode-code-point columns. A location may be `null` for a context or
project-level diagnostic. See [Diagnostics](diagnostics.md) for the item format.

`fullParity` describes coverage of **required catalog capabilities**, not a
successful source check or equality with every supplemental PHPStan finding.
Live results always identify `compilerCore` and `supplemental: false`. In
particular, deep ordinary-PHP body and generator-specific supplemental analysis
remain part of saved-file `check`, not this endpoint. Syntax failures return
normal diagnostics even if later semantic phases cannot run. No second parser,
editor-authored type rules, browser transport or semantic reconstruction is used.

Exit status is `0` without source errors, `1` with source errors, `2` for invalid
requests/projects/ownership or resource limits, and `70` for internal failures.
Errors return `document: null`, `diagnostics: []`, a zero summary, `analysis:
null`, and `error: {code, message}`. Error codes are `invalid-request`,
`request-read-failed`, `invalid-project`, `document-not-owned`, `response-limit`,
and `internal-error`. Never interpret an error envelope as a clean diagnostic
result. Unknown protocol versions fail closed. Stdout is always one JSON
envelope, even when `--format` is omitted; explicit non-JSON formats are rejected.

Limits (including ignored context): 32 documents including the target; 4096 path bytes; 2 MiB per document;
8 MiB total decoded contents; 16 MiB raw JSON; JSON nesting depth 32; 1000
diagnostics and 4 MiB encoded response. Oversized output returns an explicit
error, not a silently truncated or falsely successful result. Existing compiler
syntax and dependency limits still apply. Clients should additionally enforce a
process deadline, debounce changes, cancel obsolete requests, and only publish
results if **every** captured target/overlay revision and project identity still
matches. Closing a document drops its overlay on subsequent requests; deletion
and diagnostic clearing are client lifecycle operations, not server tombstones.

The endpoint performs no source writes, autosave, cache mutation, operation-lock
creation, build, lowering, PHPStan invocation, application bootstrap or Composer
script execution. It needs neither generated output nor a production manifest.
Normal `check` and `build` behavior is unchanged.

## Definition Request

`ppphp editor:definition --working-directory <project> --format=json` reads one bounded JSON request from standard input. Version 1 accepts the current unsaved contents of one project-owned `.ppphp` document and a UTF-8 byte offset:

~~~json
{
  "version": 1,
  "document": {
    "path": "/project/src/index.ppphp",
    "contents": "<?php\nPerson $person = new Person();\n"
  },
  "position": {
    "offset": 6
  }
}
~~~

Documents are limited to two mebibytes, paths to 4096 bytes, and offsets to the inclusive range from zero through the document byte length. The path must resolve to a source owned by the loaded project. The compiler substitutes the supplied contents for that document while it parses the project; it never writes the buffer to disk.

## Definition Response

The command returns a versioned envelope. A resolved symbol contains a stable project identity, semantic kind, declaration range, and precise name-selection range:

~~~json
{
  "version": 1,
  "definition": {
    "symbolId": "type:my\\app\\person",
    "kind": "class",
    "location": {
      "file": "src/Person.ppphp",
      "range": {
        "start": { "offset": 25, "line": 5, "column": 1 },
        "end": { "offset": 84, "line": 8, "column": 2 }
      },
      "selectionRange": {
        "start": { "offset": 31, "line": 5, "column": 7 },
        "end": { "offset": 37, "line": 5, "column": 13 }
      }
    }
  },
  "error": null
}
~~~

Offsets are zero-based UTF-8 bytes and ranges are half-open. Lines and columns follow the compiler source model: one-based lines and Unicode-code-point columns. Editor clients should use offsets to convert into their protocol's coordinate system.

No match is a successful response with `definition: null`. Invalid requests, projects, or document ownership return a non-zero status and a structured `error`. Unknown protocol versions fail closed.

## Resolution Semantics

Definition resolution uses the compiler's complete project symbol table, resolved namespace imports, declared types, local bindings, and inheritance graph. Version 1 resolves:

- classes, interfaces, traits, enums, and ++PHP source type references;
- namespaced, aliased, and imported functions;
- methods and properties through classes, parents, interfaces, and traits;
- `$this`, typed locals, parameters, and closure parameters;
- chained method returns and property types, including applied generic substitutions such as `Box<Person>::getValue(): T`; and
- nested generic base and argument references, plus declaration-owned type-parameter references in properties, methods, locals, loops, closures, and arrow functions; and
- local and parameter reads back to their declarations.

Symbol IDs are case-normalized and stable for project declarations: `type:<fqn>`, `function:<fqn>`, `method:<owner>::<name>`, and `property:<owner>::$<name>`. Type parameters use `type-parameter:<owner-qualified-identity>`, preventing two unrelated declarations named `T` from sharing an editor identity. Local and parameter identities additionally include their owning source or callable.

The query performs no lowering, PHPStan execution, cache mutation, or output writes. It does not require a production manifest or persisted source map. Recoverable syntax errors in unrelated files do not disable navigation. An incomplete target that cannot produce an AST returns no definition rather than guessing.

## Semantic Tokens

`ppphp editor:semantic-tokens --working-directory <project> --format=json` classifies the current unsaved contents of one project-owned document. The request uses the same bounded `version` and `document` object as definition requests, without a position:

~~~json
{
  "version": 1,
  "document": {
    "path": "/project/src/Box.ppphp",
    "contents": "<?php\nclass Box<T> { public function getValue(): T {} }\n"
  }
}
~~~

The compiler parses only that in-memory document for this query. It returns sorted, non-overlapping, half-open UTF-8 byte ranges:

~~~json
{
  "version": 1,
  "tokens": [
    {
      "type": "class",
      "modifiers": ["declaration"],
      "range": {
        "start": { "offset": 12 },
        "end": { "offset": 15 }
      }
    },
    {
      "type": "method",
      "modifiers": ["declaration"],
      "range": {
        "start": { "offset": 37 },
        "end": { "offset": 45 }
      }
    }
  ],
  "error": null
}
~~~

Token types follow the Language Server Protocol vocabulary: `namespace`, `class`, `enum`, `interface`, `typeParameter`, `parameter`, `variable`, `property`, `enumMember`, `function`, `method`, `keyword`, `type`, and `decorator`. Supported modifiers are `declaration`, `readonly`, `static`, `abstract`, and the standard `defaultLibrary` marker for PHP-owned symbols.

This stream augments editor lexical highlighting without maintaining an editor-specific keyword list. The compiler derives the complete reserved-word layer from PHP's tokenizer, classifies native types and predefined constants from syntax context, and supplies AST-backed roles for method declarations and calls, properties, parameters, generic parameter declarations and every visible reference, typed bindings, checked errors, and `when` keywords. Native types and predefined constants carry the standard `defaultLibrary` modifier so clients can distinguish them from project symbols while using their own PHP color scheme.

Production source maps are deployment metadata for emitted PHP and do not replace either editor protocol. Definition and semantic-token requests continue to operate directly from the project plus the current unsaved source buffer, even when no production build exists.

Browser analysis protocol version 2 is a separate transport contract. Its optional, project-contained portable dependency-index context changes how dependency declarations are supplied to browser checks, but does not change editor request version 1, editor response shapes, or editor symbol identities.

Mixed-project validation does not grant editor adapters a separate semantic model. PHP/++PHP declaration conflicts, checked-error boundaries, generic substitutions, and selected-source diagnostics remain compiler results. Adapters translate the versioned responses and the compiler's already-processed, original-source diagnostic spans into their editor protocol; they must not suppress, reorder, or reconstruct findings from generated PHP. Human console decoration and standard-error routing do not apply to these machine-owned standard-output protocols.
