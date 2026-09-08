# Mixed PHP And ++PHP Application

This maintained example demonstrates an application that adopts ++PHP incrementally while keeping ordinary PHP, one shared namespace, Composer autoloading, PHPDoc generics, checked-error stubs, and a source-free production runtime.

## Repository Development Workflow

Install the compiler dependencies from the repository root, then run:

```bash
cd examples/mixed-application
composer install --no-interaction --no-progress --no-scripts
php ../../bin/ppphp composer:configure
php ../../bin/ppphp check
php ../../bin/ppphp build
composer update --lock --no-interaction --no-progress --no-scripts
composer dump-autoload --optimize --no-interaction
php public/index.php
php build/ppphp/console.php
```

`composer:configure` preserves source mappings under `extra.ppphp` and changes runtime mappings to `build/ppphp/`. Run a complete pathless build before regenerating optimized Composer metadata.

## Package Workflow After A Release Exists

Once a Stable release of `atatusoft/ppphp` is publicly available, an application can use the following workflow. For a prerelease, substitute the exact installation command from its [published release instructions](https://github.com/atatusoft-ltd/ppphp-src/releases):

```bash
composer require --dev atatusoft/ppphp
vendor/bin/ppphp init
vendor/bin/ppphp composer:configure
vendor/bin/ppphp check
vendor/bin/ppphp build
composer dump-autoload --optimize
```

The compiler is a build-time dependency. Deploy `composer.json`, `composer.lock`, `vendor/`, `public/`, and `build/ppphp/`; source files, stubs, cache data, and compiler classes are not required at runtime.
