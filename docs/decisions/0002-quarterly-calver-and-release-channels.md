# ADR 0002: Quarterly CalVer And Release Channels

- Status: Accepted
- Date: 2026-09-02
- Scope: Compiler identity, release selection, distribution metadata, and Stage 14 publication

## Context

++PHP needs one immutable identity across CLI output, compiler metadata,
manifests, cache fingerprints, browser analysis, release assets, and schemas.
The earlier `development` placeholder did not identify a release train, and a
single generic prerelease category would erase the product distinction between
active Development releases and Release Candidates.

## Decision

Use quarterly calendar versions with exactly three channels:

```text
Stable               YYYY.Q.R
Release Candidate    YYYY.Q.R-rc-N
Development          dev-YYYY.Q.R
```

The source version is defined by `Compiler::VERSION`. Development remains a separate
channel from Release Candidate. Release selection defaults to Stable only.
Release Candidate and Development acquisition requires an explicit channel or
an exact canonical version. Supplying both requires a channel match, and no
selection falls back across channels.

Canonical Git tags equal the exact version and have no prefix. GitHub Stable
releases are not prereleases; Release Candidate and Development releases are
prereleases while retaining their distinct channel identities. Every published
release carries an immutable schema artifact under the matching exact tag.

Ordinary `ppphp` commands perform no update checks or release-network activity.
The version model and selector are reused by Stage 14 release-aware metadata,
but this decision does not add an installer or self-update command.

## Alternatives Considered

- A conventional compatibility-oriented sequence would not communicate the
  settled quarterly release train and would encourage unsupported meaning for
  its numeric fields.
- A month-based calendar form was rejected because the product plans and ships
  by quarter.
- A suffix-form Development identity would conflict with the approved
  `dev-YYYY.Q.R` grammar.
- One prerelease channel would make Stable-default and explicit-channel
  selection ambiguous and would conflate Development with Release Candidate.
- A global cross-channel ordering was rejected because channel promotion and
  release history cannot be inferred from version syntax.

## Consequences

`Compiler::VERSION` is the one public compiler identity and all existing
consumers continue to derive from it. Existing development build manifests
using the retired placeholder require one complete pathless rebuild before
partial builds can resume.

Composer's rolling `dev-develop` branch identity remains distinct from an
immutable ++PHP Development release. Default Composer documentation covers
Stable acquisition. An exact Release Candidate command may be documented only
after it passes the installed-distribution verifier against supported Composer
and real package metadata.

Release publication must validate the exact compiler version, tag, channel,
schema filename, and immutable schema URL. Published identities and artifacts
are never moved or replaced.

## Revisit Conditions

Additional public channels, suffixes, fallback rules, update behavior, or tag
forms require a new decision. Compatibility and breaking-change policy remain
separate from this version syntax.
