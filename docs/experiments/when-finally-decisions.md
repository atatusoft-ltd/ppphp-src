# `when` finally decision evidence

These are handwritten candidate outputs, not a new compiler lowerer. They
support the paired review against the owner's requirements: greedy value
returns, readable PHP, native temporary lifetime, exception/error precedence,
and no destination assignment when evaluation throws. Published semantics are
unchanged by this experiment.

## Outcomes

Sources are under `tests/Fixtures/WhenDecisions/Finally/`. Counts include the
complete standalone script, helpers and observation code; they are not claims
about an eventual compiler's output size.

| Source | Selected-path observation | Lines | Pinned maximum-level gate |
| --- | --- | ---: | --- |
| `S1-cleanup.php` | `cleanup\|5` | 17 | Pass |
| `S2-propagate.php` (A2) | `exception:pending` | 19 | Pass |
| `S2-supersede.php` (A1 comparison) | `recovered` | 17 | Pass |
| `S3-replace-result.php` | `finally` | 15 | Pass |
| `S4-nested-cleanup.php` | `inner\|outer\|value` | 19 | Pass |
| `S5-catch-cleanup.php` | `cleanup\|5` or `cleanup\|-1` | 26 | Pass |
| `S6-delayed-assignment.php` | `compute\|caught fail\|10` | 21 | Pass |
| `S6-unsafe-early-assignment.php` (counterexample) | `compute\|caught fail\|5` | 19 | Pass |
| `native-baseline.php` | `recovered\|finally` | 22 | Rejects overridden exit points and checked-exception declaration |

All candidate scripts avoid synthetic loops and completion flags. A1's S2
requires a catch-all to discard the pending exception. A2 does not. Native
PHP's baseline executes successfully despite the analyzer rejecting its exit
points: runtime behavior and static acceptance are distinct observations.
No suppression or baseline was added. The unsafe early-assignment script
passing static analysis does **not** make it semantically correct.

S1, S3, S4 and S5 can use the same readable shapes under A1 or A2. S6 must
preserve the old destination under either option; the difference between its
two scripts demonstrates an unsafe optimization, not a choice of semantics.
S2 is the actual semantic difference: A1 cancels the pending exception; A2
lets it propagate after required cleanup. A3 would reject the finally returns
in S2 and S3. Under A3, recovery belongs in a catch; successful replacement
can be expressed as ordinary value-producing branches outside finally.

## Correctness boundaries

- Replacing each return with an assignment is insufficient. Greedy completion
  must still skip subsequent source statements in that branch.
- A destination write must occur only after successful expression completion.
  Moving a property write inside the source try can make its catches intercept
  a setter failure they would not otherwise catch. Plain local assignment is
  not automatically safe either: overwriting its previous object can invoke
  a throwing destructor. Destination syntax alone cannot establish that
  moving the write across a catch boundary preserves behavior.
- `CheckWhenExpressionsPass::canComplete` means fallthrough **without yielding**.
  It cannot alone decide whether to emit the destination copy. A successful
  yield and a throw both set it false today; the lowerer needs those outcomes
  distinguished. The S2/S6 scripts share a post-if copy reachable from the else
  path, avoiding an unreachable copy after the always-throwing selected path.
- These scalar-only probes exercise normal-path temporary cleanup. They do
  not prove object lifetimes on exceptional exits. A general lowerer must
  release temporary references on those paths as well; an unset placed only
  after a possibly throwing call is insufficient.

## Reproduction and recommendation

Run `vendor/bin/pest tests/Feature/Cli/WhenFinallyDecisionExperimentTest.php`.
The test includes `resources/phpstan/ppphp.neon`, sets the target to PHP 8.4,
and runs selected, unselected, and throwing helper paths. The native baseline's
gate rejection is an explicit expected observation, not an ignored finding.
The same probe suite was executed with the native PHP 8.4.21 interpreter.

The owner confirmed A2 on 2026-09-10: greedy completion, exception/error
precedence, and delayed destination assignment. A finally result can replace
a pending value, but cannot cancel a pending exception. Keep native cleanup
and catch behavior; preserve temporary lifetime on successful and exceptional
exits. These are the agreed requirements, not a claim that this experiment
implements them. Independent review reproduced the candidate outputs and
maximum-level gate results. Do not infer authorization for a broad lowerer
rewrite from these narrow examples or from their static-analysis success.
