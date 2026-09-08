# Getting Started

This guide takes a clean Composer project to executable generated PHP with ++PHP.

## Requirements

- PHP 8.4 or 8.5 within the compiler's `^8.4` host requirement.
- Composer 2.
- A PHP `memory_limit` of at least `512M` for compiler commands.

Create a project and request the Stable package. For a Release Candidate or Development release, use the exact installation command from [GitHub Releases](https://github.com/atatusoft-ltd/ppphp-src/releases) instead; unqualified resolution does not select prereleases or guarantee a Stable version is available.

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
