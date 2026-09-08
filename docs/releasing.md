# Releasing ++PHP

Every public version is immutable. Canonical tags have no `v` prefix and must never be moved or reused. This is the single maintainer release procedure for Stable, Release Candidate, and Development channels.

## Preparation (Stage 14A)

1. Prepare on the latest `develop`, preserve concurrent work, and inspect current CI.
2. Select the identity in `Compiler::VERSION`. Keep the necessary release manifest, schema identity/hash, and machine fixtures consistent. Historical entries remain historical; README and general guides do not need current-version edits.
3. Write user-facing changes, compatibility, migration, and limitations in the notes selected by `resources/release/manifest.json`. Keep temporary publication status, stages, prompts, and internal validation processes out of public notes.
4. Validate Composer metadata, install locked dependencies, audit them, run `composer check`, `composer verify:distribution`, CLI smoke checks, and `git diff --check`. The aggregate includes version, documentation, analysis, tests, and offline readiness; do not run it again under multiple names.
5. Commit the candidate. Build and verify assets twice in separate output directories with the same explicit source commit and compare every byte.
6. Push `develop` through the normal workflow and inspect CI. Integrate the intended changes into `main` through a pull request.

Preparation needs no release branch or tag, and never publishes. Ordinary feature work pushes only `develop`; owner-managed release branches are distinct from feature branches.

## Release And Public Verification (Stage 14B)

1. When ready, cut `release/<canonical-version>` from `main`. Do not cut it merely to satisfy preparation checks. Push the release branch to obtain the existing PHP host, installed-distribution, macOS, Windows, and lowest-dependency CI.
2. Complete release-branch verification and select its exact commit. Release-specific preparation commits need not already be merged back into `main`. Revalidate if the selected commit changes.
3. Derive the version from that checkout and record its commit locally:

   ```bash
   RELEASE_VERSION=$(php -r 'require "vendor/autoload.php"; echo Atatusoft\Ppphp\Compiler\Compiler::VERSION;')
   RELEASE_COMMIT=$(git rev-parse HEAD)
   php tools/release/build-assets.php --commit="$RELEASE_COMMIT" --output="$ASSET_DIRECTORY"
   php tools/release/verify-assets.php --input="$ASSET_DIRECTORY" --commit="$RELEASE_COMMIT"
   ```

   Set `ASSET_DIRECTORY` to a fresh output location. The builder reports it and refuses a dirty or mismatched checkout. The internal builder API also supports synthetic offline test fixtures; those are not publication evidence.
4. Create the exact canonical tag on the verified release-branch commit as the final step before submitting the release. Push that tag once. Annotated and lightweight tags both resolve to their underlying commit.
5. The tag-driven workflow checks matching metadata, release-branch tip, selected commit, and tag. It checks shared first-parent main-line history as Git graph evidence. Git does **not** record a branch-creation event: the operator is responsible for cutting from `main`. Neither the release commit being on `main` nor today's `main` tip being in the release history is required.
6. Verification captures the immutable commit once. Publication checks out that commit, rechecks the tag identity, rebuilds and verifies its deterministic assets, and uses `RELEASE_NOTES.md` as both the notes asset and identical GitHub release body. It does not select a moving branch again. Only this publication job has write permission; branch pushes do not publish.
7. Duplicate publication, malformed catalog responses, and API/authentication failures block publication. Existing releases, including drafts, must not be overwritten. If submission partially fails, inspect it rather than deleting or replacing a historical release.
8. Verify public assets and `SHA256SUMS`, package-index visibility and the GitHub-to-Packagist update path. Confirm the public website, issue links, and private vulnerability reporting work.
9. Install the exact version from the public package index into a clean consumer project. Run `init`, Composer configuration and autoload regeneration, `check`, `build`, PHP lint, and generated code. Confirm the installed configuration's schema URL and bytes match the published artifact. Local path-package tests do not establish public availability.
10. Delete the release branch when obsolete. Reconcile any release-only fixes through the normal development workflow; retaining the branch is not required for history.

## Composer Package Migration

RC-2 and later source uses `atatusoft/ppphp`, separately from the unchanged GitHub repository `atatusoft-ltd/ppphp-src`. `Compiler::COMPOSER_PACKAGE`, root Composer metadata, release assets, installation docs, and distribution verification must agree. The dependency index's producer identity remains stable repository provenance; a packaging rename must not invalidate its format.

Before public acquisition, the owner must register `atatusoft/ppphp` on Packagist using the existing repository, confirm access to the protected `atatusoft` vendor, and verify its update hook and exact tagged version. A local path installation is not proof of registration or public availability. Do not publish RC-2 merely to complete the rename.

Keep the published RC-1 tag, release assets, notes, and old package entry intact. Once the new package is publicly installable, mark the old Packagist entry abandoned with `atatusoft/ppphp` as its replacement; do not delete it or rewrite old tags. Existing root requirements and locks need an explicit migration, described in the RC-2 notes. The new package replaces only `self.version` of the old name, not every old or future version; this supports equivalent-version dependencies without claiming compatibility with arbitrary constraints.

## Historical Verification And Reconstruction

Use a clean checkout of the immutable tag/source commit, install its locked dependencies, and use the tooling from that commit. The asset verifier checks release metadata, source identity, and all hashes. Reconstruction with `build-assets.php --commit=<recorded-commit>` requires no surviving release branch.

For tags produced under this lifecycle, `verify-source.php --tag=<canonical-tag> --commit=<recorded-commit>` checks immutable identity without branch evidence. Add `--publication` only for initial publication, when the corresponding remote release branch must exist. Never recreate a deleted branch automatically or treat its deletion as artifact corruption.

## Stable Promotion (Stage 14C)

Select a Stable identity only after release blockers are resolved. Repeat this lifecycle with Stable metadata and freshly rebuilt version-bound artifacts. Stable publishes as a non-prerelease; RC and Development publish as prereleases without combining their channels. Confirm unqualified Composer resolution selects Stable. Never relabel a candidate tag or reuse its assets.

## Release Content And Assets

The bounded asset set is `ppphp.schema.json`, `ppphp-release.json`, `RELEASE_NOTES.md`, `THIRD_PARTY_NOTICES.md`, and `SHA256SUMS`. Composer plus immutable tagged source remains the distribution. There is no PHAR, installer, native launcher, standalone vendor archive, signing claim, or self-update client.

Authored notes carry historical change descriptions and their title. `ReleaseNotesRenderer` appends the exact installation command, channel, and tag-bound documentation/schema links from validated metadata. The builder hashes this rendering, the verifier compares it, and publication uses that verified asset—not a separate source document. Do not scatter version placeholders throughout Markdown or rewrite published notes when preparing a later release.

Channel prose is optional. If included, explicit current-release claims such as
“This is a Stable release” or “Channel: Stable” must agree with selected metadata;
the shared notes validator rejects contradictions before rendering and publication.
Historical statements about an earlier Stable release and future Stable plans are
allowed. This targeted prose check does not replace editorial review of arbitrary
wording or require workflow-status boilerplate in user-facing notes.
