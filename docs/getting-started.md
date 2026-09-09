# Getting Started

This guide takes a clean Composer project to executable generated PHP with ++PHP.

## Requirements

- PHP 8.4 or 8.5 within the compiler's `^8.4` host requirement.
- Composer 2.

Native compiler commands allow 512 MiB by default. This is a per-process ceiling,
not reserved memory or a change to your machine's `php.ini`. Small mixed projects
remain regression-tested at an explicitly selected 128 MiB ceiling.

### Compiler Memory

To choose an allowance once for subsequent terminal calls, export a whole number
of MiB in your shell configuration (or set the environment variable in your CI):

```bash
export PPPHP_COMPILER_MEMORY_LIMIT_MEGABYTES=768
```

The setting applies to `check`, `build`, and native editor commands. An explicit
value from 1 to 2,147,483,647 wins, even when lower than 512. Without it, the
compiler uses at least 512 MiB and preserves a larger or unlimited PHP allowance.
PHP's `-d memory_limit=...` alone does not distinguish an intentional low limit
from `php.ini`; use the compiler environment setting to select a lower ceiling.
Invalid values fail before project analysis rather than silently using a default.
The supplemental analyzer inherits the effective compiler limit.

Updated editor integrations send their configured memory allowance through the
same environment setting. Their settings control editor calls, not unrelated
terminal sessions. Embedded library use and the browser analysis protocol retain
their host's memory policy. Larger dependency graphs can still need more memory;
the compiler's separate dependency-index safety limits remain enforced.

This configuration is available in the development checkout, not the immutable
published `2026.3.1-rc-2` package.

## Create A Project

The workflow below requires a published Stable release of `atatusoft/ppphp`. Check [GitHub Releases](https://github.com/atatusoft-ltd/ppphp-src/releases) first. For a Release Candidate or Development release, substitute its exact installation command; unqualified resolution does not select prereleases or guarantee a Stable version is available. RC-1 retains the original package name in its published instructions.

```bash
mkdir hello-ppphp
cd hello-ppphp
composer init --name=example/hello-ppphp --no-interaction
composer require --dev atatusoft/ppphp
vendor/bin/ppphp init
```

`ppphp init` creates `ppphp.json`, `build/ppphp`, `.ppphp-cache`, and `stubs`. A packaged release writes the immutable `$schema` URL as the first configuration property.

The project's Composer `config.platform.php` selects the PHP target when set;
otherwise `ppphp.json` supplies it. Composer `require.php` constrains the selection.
Unsupported or conflicting settings are reported directly—changing the PHP
interpreter that runs the compiler does not silently change the project target.
See [diagnostics](diagnostics.md) for the target-selection rules.

Set the root project's Composer mapping to source, for example:

```json
{
    "autoload": {
        "psr-4": {
            "App\\": "src/"
        }
    }
}
```

Create `src/Greeting.ppphp`:

```php
<?php

namespace App;

function greeting(string $name): string
{
    readonly string $prefix = 'Hello';

    return $prefix . ', ' . $name;
}
```

Create `src/index.php` as an ordinary PHP entrypoint:

```php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

echo App\greeting('World'), "\n";
```

Project runtime mappings must load generated code. Preview and apply the maintained projection, then regenerate Composer metadata:

```bash
vendor/bin/ppphp composer:configure --dry-run
vendor/bin/ppphp composer:configure
composer update --lock --no-interaction --no-scripts
composer dump-autoload --optimize
vendor/bin/ppphp check
vendor/bin/ppphp build
php -l build/ppphp/Greeting.php
php build/ppphp/index.php
```

The final command prints `Hello, World`. `.ppphp` source is never executed directly. A pathless build owns and atomically replaces the complete configured output tree, compiles `.ppphp`, and copies project-owned `.php` byte-for-byte.

To remove compiler-owned generated state without touching source:

```bash
vendor/bin/ppphp clean --dry-run
vendor/bin/ppphp clean
```

For a maintained executable example, see the [mixed application](../examples/mixed-application/README.md).
