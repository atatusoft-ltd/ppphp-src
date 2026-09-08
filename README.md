<p align="center">
    <a href="https://ppphplang.org"><img src="resources/images/ppphp-emblem.svg" alt="++PHP Logo" width="200" /></a>
</p>

<p align="center">
    <a href="https://github.com/atatusoft-ltd/ppphp-src/actions/workflows/php.yml?query=branch%3Adevelop"><img src="https://github.com/atatusoft-ltd/ppphp-src/actions/workflows/php.yml/badge.svg?branch=develop" alt="CI status" /></a>
    <a href="composer.json"><img src="https://img.shields.io/badge/PHP-%5E8.4-777BB4?logo=php&amp;logoColor=white" alt="PHP ^8.4" /></a>
    <a href="LICENSE.txt"><img src="https://img.shields.io/github/license/atatusoft-ltd/ppphp-src" alt="License" /></a>
</p>

# ++PHP

++PHP (pronounced “plus plus PHP”) is a source language and compiler for PHP projects. It adds compile-time type safety and expressive language features, then emits ordinary PHP for the official PHP runtime.

## Why ++PHP

++PHP is designed for gradual adoption. Existing `.php` files can stay in place while selected files move to `.ppphp`. The compiler checks both kinds of source together and builds one ordinary-PHP application for deployment.

Use ++PHP when you want stronger contracts without introducing a custom runtime:

- catch type, nullability, member, collection, and checked-error mistakes before deployment;
- express typed local variables, erased generics, and typed collections directly in source;
- keep Composer packages and existing PHP code in the same project; and
- deploy generated PHP using familiar PHP and Composer infrastructure.

## Releases

See [GitHub Releases](https://github.com/atatusoft-ltd/ppphp-src/releases) for available versions and exact installation instructions. Release Candidates are intended for evaluation; Development releases are a separate, explicitly selected channel.

## Language Highlights

- Explicitly typed mutable and readonly local bindings
- Strict project-wide typing
- Union, intersection, and DNF types
- Erased generics
- Typed list and map arrays
- Checked errors
- Value-producing `when` expressions
- Mixed `.php` and `.ppphp` projects
- Deterministic PHP output and source-mapped diagnostics
- Composer-aware builds
- Incremental, hash-verified compiler caching

## Example Source And Generated PHP

++PHP source can declare local types that PHP itself does not accept:

~~~php
<?php

function greeting(string $input): string
{
    string $name = trim($input);
    readonly string $prefix = 'Hello';

    return $prefix . ', ' . $name;
}
~~~

The compiler erases the extension syntax and preserves its type contract as PHPDoc:

~~~php
<?php

declare(strict_types=1);

function greeting(string $input): string
{
    /** @var string $name */ $name = trim($input);
    /** @var string $prefix */ $prefix = 'Hello';

    return $prefix . ', ' . $name;
}
~~~

Generics, typed arrays, checked `throws` clauses, and `when` expressions are also compile-time features. Generated code contains ordinary PHP syntax and requires no ++PHP runtime library.

## Requirements

- PHP `^8.4`
- Composer 2

Small mixed Composer projects are tested with a `128M` PHP memory limit. Memory
use grows with the source and dependency declarations being analyzed; the compiler
does not change your PHP memory setting.

Generated code targets PHP 8.4.

## Installation

The following command requires a published Stable release of `atatusoft/ppphp`. Check [available releases](https://github.com/atatusoft-ltd/ppphp-src/releases) first; for a prerelease, use its exact installation instructions instead. RC-1 uses the original package name recorded in its release notes.

~~~bash
composer require --dev atatusoft/ppphp
~~~

This selects Stable only and does not fall back to a prerelease if none is available. To evaluate a Release Candidate or Development release, choose an exact version from its release instructions. From a repository checkout, install locked dependencies and run the compiler directly:

~~~bash
composer install
php bin/ppphp --help
~~~

The Composer package is `atatusoft/ppphp`; its command is `ppphp`.

## Quick Start

From a project where ++PHP is installed:

~~~bash
vendor/bin/ppphp init
vendor/bin/ppphp composer:configure --dry-run
vendor/bin/ppphp composer:configure
composer update --lock --no-interaction --no-scripts
composer dump-autoload --optimize
vendor/bin/ppphp check
vendor/bin/ppphp build
~~~

`ppphp init` creates `ppphp.json` and the configured `build/ppphp`, `.ppphp-cache`, and stub directories. `composer:configure` projects root application autoload mappings to generated output while preserving their source forms for analysis.

Use `ppphp check [path]` to validate the complete project, a directory, or one project-owned source file. Use `ppphp build [path]` to compile selected `.ppphp` files and copy selected `.php` files into the configured output.

See [Getting Started](docs/getting-started.md) for a complete executable example.

## Mixed PHP/++PHP Projects

Ordinary `.php` source retains normal PHP behavior and participates in symbol and type analysis. It is copied byte-for-byte into selected build output. `.ppphp` source receives the ++PHP language checks and compiles to `.php` in the same output tree.

Focused commands use valid unselected declarations as context without reporting unrelated errors from unselected bodies. Complete builds are intended for deployment. See [Mixed Projects](docs/mixed-projects.md) and [PHP Interoperability](docs/interoperability.md).

## Building And Deployment

A complete `ppphp build` checks the project before replacing the compiler-owned output tree. New PHP files must pass isolated PHP linting, and a failed build preserves the previous successful output. Manifests and source maps record each generated artifact.

Deploy the configured output tree with the root Composer metadata and installed dependencies. Do not execute `.ppphp` files directly or place hand-maintained files inside `build/ppphp`. See [Build Output](docs/build-output.md) and [Composer Runtime Integration](docs/composer-runtime.md).

## Editor Support

The repository provides bounded definition and semantic-token protocols for editor integrations. They are internal integration surfaces rather than a standalone language server. See the [Editor Protocol](docs/editor-protocol.md).

## Current Limitations

- Generated source targets PHP 8.4.
- Native `ppphp check` and `ppphp build` include supplemental PHPStan analysis.
- Deep analysis of ordinary PHP implementation bodies is supplied by PHPStan.
- Generator-specific compiler-owned flow analysis remains limited.
- Browser compiler analysis is an internal integration protocol, not a supported browser build product.
- No formatter is included.
- No standalone language server is included in this repository.
- Immutable Records, postfix list syntax, Native Type Members, and attribute factory expressions are planned work and are not part of this release.

## Documentation

- [Language Overview](docs/language.md)
- [Command-Line Interface](docs/cli.md)
- [Migrating From PHP](docs/migrating-from-php.md)
- [Generics](docs/generics.md), [Typed Arrays](docs/typed-arrays.md), and [Checked Errors](docs/checked-errors.md)
- [`when` Expressions](docs/when-expressions.md)
- [Diagnostics](docs/diagnostics.md) and [Source Maps](docs/source-maps.md)
- [Versioning](docs/versioning.md) and [Release Notes](docs/releases/README.md)

Visit [ppphplang.org](https://ppphplang.org) for the public ++PHP website.

## Security

Report vulnerabilities privately using the process in [SECURITY.md](SECURITY.md).

## Contributing

Repository contributors should read [AGENTS.md](AGENTS.md) and the [maintainer release guide](docs/releasing.md) before changing compiler or release behavior.

## License

Licensed under the [Apache License 2.0](LICENSE.txt).
