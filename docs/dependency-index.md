# Portable Dependency Index

> **Format version:** 2
> **Declaration format version:** 2
> **Status:** Available with atomic validation, generation, and transaction recovery.

The portable dependency index carries compiler-owned Composer dependency declarations without carrying dependency implementation source. It is intended for explicit internal APIs, tests, the standalone development tool, and browser protocol version 2. Normal native `ppphp check` and `ppphp build` continue to read installed Composer source and run the supplemental PHPStan phase; there is no public provider-selection CLI or `ppphp.json` option.

## Package layout

```text
ppphp-dependencies/
├── manifest.json
└── packages/
    └── <stable-package-id>.json
```

`manifest.json` identifies the producer's public compiler version and exact path-independent build identity, a declaration-compatibility identity, target PHP version, Composer lock and installed-metadata hashes when available, ordered package identities, shard paths and SHA-256 hashes, aggregate declaration/alias/conditional/include counts, autoload forms, and one overall content identity. Producer identity records provenance; compatibility identity is derived from the index/declaration format contract and decides whether another compiler build may read the declarations. It contains no timestamp, hostname, process ID, temporary directory, or absolute path. Every path uses `/` and every file ends with one LF.

Each package shard records package identity and ordered production autoload metadata plus declaration-only PHP documents. These documents preserve native and PHPDoc contracts, defaults, references, variadics, visibility, static state, inheritance, traits, interfaces, generics, typed list/map contracts, checked errors, constants, enum cases, aliases, conditional fallbacks, package-relative locations, and autoload provenance. Literal aliases retain their declaring package-relative site and autoload order, so a cross-package alias chain does not borrow provenance from its resolved target. Functions, concrete methods, constructors, closures, arrows, and property hooks retain declarations but no executable implementation; stripped concrete hooks use valid empty hook syntax while originally abstract hooks remain bodyless. Dependency implementation statements and bodies are never serialized, loaded, or executed. Parsing the declaration syntax restores the existing compiler `Type` and symbol hierarchy rather than introducing an index-only type system.

## Generation and verification

The format's producer `identity` remains `atatusoft-ltd/ppphp-src`, identifying the unchanged compiler repository. It is not the Composer installation name (`atatusoft/ppphp` from RC-2 onward); the rename does not change the declaration-compatibility contract or require regenerating an otherwise compatible index.

Dependencies must already be installed. The builder does not invoke Composer, load target `vendor/autoload.php`, download packages, run plugins/scripts, or execute `autoload.files`:

```bash
php tools/build-dependency-index.php \
    --working-directory=/path/to/project \
    --output=/path/to/ppphp-dependencies
```

An external path-repository root is trusted only when the developer repeats `--allow-package-root=<path>` for the standalone builder. That trust does not become project configuration or a normal compiler option, and the resulting package remains path-independent. Identical installed declarations and metadata produce byte-identical output.

Verify the committed offline fixture with:

```bash
composer verify:dependency-index
```

The reader accepts the package atomically or rejects all of it with `P6019`. It validates the exact property schema, format and declaration versions, declaration-compatibility identity, producer shape, target PHP version, request manifest hash when supplied, all shard hashes, package/declaration uniqueness, count consistency, declaration syntax and absent executable bodies, path containment, final LFs, and resource limits. This validation is independent of the writer, so a malicious or defective producer cannot smuggle property-hook or other implementations into the compiler.

Generation uses a sibling candidate, previous-output backup, and canonical role markers bound to one transaction identity. The candidate is fully verified before the current index moves. If interruption occurs, the next generation rolls a valid committed candidate forward or restores the valid marked previous output. Only correlated marked directories are removed; ambiguous or unmarked orphans are preserved. Stale shards disappear on successful replacement because the whole index tree is committed atomically.

## Browser protocol version 2

The optional request field is:

```json
{
  "dependencyContext": {
    "kind": "portable-index",
    "manifestPath": "ppphp-dependencies/manifest.json",
    "sha256": "<manifest SHA-256>"
  }
}
```

The manifest must already be mounted beneath the project root. The compiler performs no network fetch. Requests without this field retain existing version 2 behavior, and protocol version 1 is unchanged. With a portable provider selected, installed dependency source is not scanned or merged a second time.

## Trust and missing context

Native discovery follows project references and dependency declaration contracts,
including native types, PHPDoc, defaults, inheritance and property hooks. After
static include, alias and guard inspection, named dependency callable bodies are
discarded; their implementation-only references do not recursively expand the
declaration index. Original files remain available to supplemental PHPStan
analysis. This avoids loading unrelated internals simply because a test tool or
other installed dependency mentions them inside an implementation.

Native dependency reading canonicalizes the project, vendor, package, and source paths and reads only the validated canonical file. Composer eager files follow dependency order, with providers before dependents and package-name ordering for equal weights; their static includes are traversed depth-first at the inclusion point. Only the exact `__DIR__ . '<literal>'` include form is followed; dynamic expressions are recorded as unavailable rather than guessed. A followed file must be a regular PHP file inside its owning package and an explicitly trusted root after symlink resolution. Static includes are cycle-safe, limited to depth 32, and share the global 2,048-file, 16 MiB, and 8,192-discovery-entry bounds. Symlink escapes and unsafe installed paths are rejected; no textual-prefix check grants trust. Existence guards and `class_alias()` are recognized only when namespace and function-import resolution proves the call targets the global intrinsic.

Missing, unavailable, and dynamic declaration context remain distinct. `P2020`/`P2021` mean no known declaration source owns a symbol. `P6018` means relevant Composer context exists but safe installed source or a valid index is unavailable. `P6021` identifies a relevant unsafe dependency path, and `P6020` identifies declarations for which Composer behavior does not establish one authority. An absent vendor tree or index does not fail unrelated selected source.

Cache snapshots key portable context by the manifest content identity, Composer lock identity, installed-metadata identity, declaration compatibility, compiler/catalog identity, and target. Index changes therefore invalidate relevant cached compiler and supplemental evidence without coupling the portable format to a host path.
