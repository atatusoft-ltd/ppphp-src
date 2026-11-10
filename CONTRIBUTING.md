# Contributing to ++PHP

Consult the [language RFCs](docs/rfcs/README.md) and [architecture decisions](docs/decisions/README.md) for the relevant contracts. Target the develop branch.

- Keep each change focused on the current development stage.
- Follow the repository's module layout, declaration-directory conventions, and <Verb><Object>Pass naming.
- Preserve original source spans through diagnostics and lowering.
- Add diagnostic codes to the central catalog, keep normal messages backend-neutral, and update the generated catalog with `php tools/update-diagnostic-catalog.php`.
- Review console and JSON golden changes deliberately; regenerate them only with `UPDATE_GOLDENS=1 composer test -- tests/Unit/Diagnostics/DiagnosticGoldenTest.php` and never in CI.
- Keep inactive syntax build-blocking until its assigned semantic stage.
- Update Pest tests and public documentation whenever behavior changes.
- Do not claim or scaffold behavior that has not been implemented.
- Run `composer validate --strict` and `composer check` before submitting changes.
- Use clear conventional-style commit messages such as feat(cli): ..., fix(config): ..., or docs: ....

Explain the need and tradeoff for any new dependency in the proposed change.
