# Internal `when` control-transfer experiment

This local decision-B experiment is not an accepted language change. The
published contract in `docs/when-expressions.md` remains the baseline. The
experiment does not change lowering, release identity, or the PHPStan gate.

## Candidate

Allow `break N` and `continue N` only when their actual target belongs to the
same `when` branch. Each loop and switch contributes a level; omitted levels
mean one. Nested callable bodies keep ordinary PHP semantics, while a `when`
inside a callable still establishes its own boundary. Invalid transfers are
checked even in unreachable code.

The experiment rejects a `continue` targeting a switch with guidance to use
`break` or an appropriate enclosing-loop level. It never silently changes the
meaning of that spelling. Goto, label, and yield restrictions are unchanged.

Flow analysis carries transfer targets separately from yielded values and
fallthrough. A switch break can reach the branch's tail; it is not itself a
result. Case fallthrough reaches the following case. Transfers across multiple
internal constructs remain pending until their actual target consumes them.

## Deliberate limits

- A transfer out of a `finally` block is rejected, matching PHP's restriction.
- A transfer from a protected try/catch across its finally is temporarily
  rejected. The current lowerer inserts loop wrappers along that path and
  would intercept an unadjusted user transfer. No renumbering is needed for
  accepted paths. This is an implementation limit, not the recommended final
  language contract.
- Loops wholly inside try or finally have valid internal targets. Two build
  probes nevertheless reproduce an existing P2099 failure: generated cleanup
  unsets a synthetic catch variable that might not exist. They deliberately
  assert this known failure instead of claiming finally lowering is repaired.
- Bare assignment immediately after a switch case label independently trips
  the extension parser. The transfer test uses braced case bodies to isolate
  control flow. That parser defect is not fixed here.

## Reproduction

Run `vendor/bin/pest tests/Feature/Cli/WhenControlTransferExperimentTest.php`.
The suite builds temporary projects through the normal supplemental analysis,
lint, and atomic-output path, then executes successful output with a timeout.
Negative cases must fail before output exists.

The paired search sample is `tests/Fixtures/WhenDecisions/Search.ppphp`; its
verbatim emitted golden is `Search.php` in the same directory. It builds one
source and prints `3`. Other cases cover continue, all loop forms, internal
multi-level targets, switch fallthrough, empty/no-match inputs, missing tail
values, independent nested boundaries, and finally-crossing rejection.

The recommendation is to allow internal transfers while preserving `when` as
a value-producing boundary. The owner must approve the semantic change before
this candidate is integrated or documented as supported behavior.
