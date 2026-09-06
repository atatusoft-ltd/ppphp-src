# Command-Line Interface

The `ppphp` executable is available through `vendor/bin/ppphp` for a project installation and as `ppphp` for a Composer global installation.

`ppphp --version` reports the exact canonical compiler identity; the current
source reports `ppphp 2026.3.1-rc-2`. The ordinary compiler commands do not query
a release catalog, check for updates, or fetch schemas. There is no installer or
self-update command in the current CLI.

## Commands

| Command | Purpose |
| --- | --- |
| `ppphp init` | Create `ppphp.json` and the configured compiler-owned directories. A packaged release writes its immutable schema URL. Existing files are preserved unless `--force` is supplied. |
| `ppphp check [path]` | Check all project sources, one source subtree, or one project-owned `.php` or `.ppphp` file through the full supplemental path; exact successful evidence may be reused. |
| `ppphp build [path]` | Check the selected source set and durably commit its mixed PHP output; exact valid output may return up to date. |
| `ppphp clean [--dry-run]` | Recover any interrupted build, then remove only validated compiler-owned output and cache paths under the exclusive operation lock. |
| `ppphp composer:configure [--dry-run]` | Preview or write Composer runtime mappings that target generated output. |
| `ppphp dump:ast <path>` | Write one source file's syntax tree to standard output. |
| `ppphp editor:definition` | Serve the bounded editor definition protocol over standard input/output. |
| `ppphp editor:semantic-tokens` | Serve the bounded semantic-token protocol over standard input/output. |

Run `ppphp list`, `ppphp --help`, or `ppphp <command> --help` for the installed command surface.

Future release-aware acquisition defaults to Stable and must use the shared
release selector. Release Candidate and Development require an explicit channel
or exact version, and selection never falls back across channels. See
[Versioning](versioning.md).

The internal hidden `browser:analysis` command is a versioned transport used by the isolated web spike, not a public compiler-only mode. Protocol version 1 preserves Prepare Analysis and its supplemental continuation. Version 2 accepts only one-shot `analyze`/`check` requests for `analysis.engine: compiler`, reports `compilerCore` completeness plus required catalog gaps, and may name a project-contained format-2 portable dependency manifest plus SHA-256 in `dependencyContext`. The host must mount the index; the compiler never fetches it. Requests without the field are unchanged. Version 2 does not support Build, produce output, return a PHPStan command, or return a continuation. Human-facing `check` and `build` continue to use full native analysis; ADR 0004 retains the supplemental PHPStan path for the MVP.

## Project options

Human-facing project commands accept:

- `--working-directory=<path>` to select the project root;
- `--config=<path>` to select a configuration path relative to that root;
- `--format=console|json` to select diagnostic output;
- `--debug` to include normalized implementation details;
- `--ansi` or `--no-ansi` to control console decoration; and
- `--no-interaction` for automation and closed-standard-input environments.

Commands do not prompt. Omitting a source path selects the complete project for `check` and `build`. A directory selects its recursive project-owned sources. A file selects that source while valid unselected sources continue to provide semantic context.

`check` and `build` accept at most one path. To select several files, run the
command once per path or select their containing directory. An extra path is
reported as an invocation error before any project files are changed.

`init` works from a source checkout as well as an installed package. Without
release metadata it writes a valid configuration without the optional `$schema`
hint. Present but inconsistent release metadata is not silently ignored: the
diagnostic identifies the mismatched version, schema or missing release file.
Missing or invalid `ppphp.json.dist` templates are reported separately. Restore
the named files from the matching compiler version before retrying.

The cache is transparent: there is no public no-cache switch. Exact cache hits preserve normal output and exit status while avoiding parser, semantic, workspace, and PHPStan work when all evidence matches. Corruption is a safe miss. Checks share the stable project-root `.ppphp-operation.lock`; build and clean take it exclusively and report `P7009` rather than waiting. A build or clean first recovers a durable output journal; unrecoverable evidence reports `P7014` before mutation.

## Output contract

Console diagnostics use standard error when a separate error channel is available. Success messages, build summaries, AST data, and editor responses use standard output. JSON mode writes one document to standard output with no diagnostic text on standard error. See [diagnostics](diagnostics.md) for ordering, color precedence, source ranges, debug behavior, and the code catalog.
