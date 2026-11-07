# PHP Signature Package

> **Target:** PHP 8.4
> **Package version:** 8.4.23.2
> **Status:** Available for the PHP 8.4 target.

The checked-in PHP signature package supplies compiler-owned platform declarations independently of the host runtime and PHPStan. It is generated from the official `php/php-src` tag `php-8.4.23` at commit `52cee85adfeeb6f017f2ac796ab7973353702c20`; the tag, peeled commit, clean checkout, tracked stub inputs, and hashes are verified before generation.

`resources/php-signatures/8.4/manifest.json` identifies upstream provenance, package/generator format, target, tracked input hashes, directive audit, output hashes, counts, and licensing. Extension shards hold normalized declaration syntax, `symbols.json` provides the deterministic lazy-load index, `module-references.json` records direct module dependencies, `overrides.json` lists the small reviewed intrinsic refinement set, and `NOTICE.md` carries attribution under the PHP License 3.01. Ordinary CI verifies committed output and never regenerates it or requires network access:

```bash
composer verify:php-signatures
```

The verifier rebuilds the dependency graph from audited declarations. Older manifests without the graph collect platform references during analysis. Project reference collection and symbol resolution use the current project in both cases. The optional `phpdoc-metadata.json` stores parsed, scope-independent metadata for platform comments keyed by their exact text digest. Full verification rebuilds it from the audited sources. Missing entries and older packages use ordinary parsing. Project comments and project-dependent type resolution continue to use the current project.

Generation is an explicit maintainer workflow through `tools/generate-php-signatures.php`. Normalization preserves functions, constants, class-like declarations, methods, properties, defaults, parameter names, references, variadics, aliases, tentative returns, and relevant availability conditions. Mutually exclusive conditional signatures are reduced only when every exhaustive branch supports one conservative contract; otherwise generation fails rather than selecting a build-specific signature.

Aliases resolve to the same compiler symbol contracts. Reviewed intrinsics refine language-critical narrowing and structured collection flow but do not replace broad platform declarations. Host reflection and PHPStan stubs are not sources of truth. An extension declaration describes the configured PHP target; deployment must still install extensions required by the application. Corrupt, incompatible, or wrong-target package data fails closed with `P6016`, and project collisions with platform declarations report `P6017`.

See [Portable Declaration Context](portable-declarations.md) for precedence and [Portable Dependency Index](dependency-index.md) for the separate Composer package format.
