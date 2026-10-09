# `when` Expressions

> **Status:** Available in the current compiler and emitted through the normal atomic build pipeline.

`when` is a contextual, value-producing conditional expression. A final `else` is mandatory:

~~~php
string $label = when ($score >= 80) {
    return 'Excellent';
} else when ($score >= 50) {
    return 'Pass';
} else {
    return 'Fail';
};
~~~

Inside the lexical body of a branch, `return expression;` produces the value of the `when`; it does not return from the enclosing callable. Returns inside nested functions, methods, closures, and arrow functions keep their ordinary PHP meaning. `return;` is invalid. Every reachable branch path must produce a value, throw, exit, or end in a resolved `never` expression. A possibly empty loop does not establish a result. A guaranteed-entry loop needs no fallback if no reachable break or condition can let it finish without a result. Breaks and continues consumed by inner loops do not create an exit from an outer loop.

A nested `declare { … }` body is still part of the branch: its returns produce
the `when` result, and it does not add a level to `break` or `continue` targets.

`break` and `continue` may target loops and switches wholly inside the branch,
including numbered transfers; they cannot escape the `when` or leave a
`finally`. A `continue` targeting a switch is rejected with guidance to use
`break` or target a surrounding loop. Cleanup runs before a transfer takes
effect. If that cleanup produces a `when` result, the result takes priority;
otherwise the transfer resumes at its original target. A caught cleanup failure
cancels the interrupted transfer. `goto`, labels, `yield`, and `yield from` are rejected
outside a nested callable boundary.

A `return` operand that throws or calls a `never` function does not produce a
result. Its failure keeps normal exception handling; it does not complete the
`when` or replace another pending value.

Conditions use ordinary PHP truthiness. They run from left to right, at most once, and only until a branch is selected. Only that branch body runs. `try`, `catch`, and `finally` retain their native exception handling and cleanup. A result produced by `finally` replaces an earlier pending value, but never cancels a pending exception or error. An exception thrown by `finally` supersedes the pending value or exception. The destination is written only after the whole expression succeeds; a throw leaves its previous value unchanged.

## Positions

The compiler supports `when` as:

- an executable file-, function-, or method-scope typed-local initializer;
- the right-hand side of assignment to a mutable local, property, array offset, or other ordinary assignable target;
- a return operand;
- a direct function, method, nullsafe method, static method, or constructor argument, including named arguments; and
- a direct keyed or unkeyed array value.

It rejects standalone expressions, defaults, constants, attributes, match arms, arrow-function bodies, array keys or unpack operands, call unpack operands, known by-reference arguments, arbitrary unary or binary operands, ternary arms, coalesce operands, and use as another `when` condition. `when(...)` calls without the braced expression grammar, including methods and static methods named `when`, remain ordinary PHP.

The containing statement matters too. An assignment or call containing `when`
is not supported in a `for` initializer, condition or step, a `while` or
`do … while` condition, a `switch` condition or case, a `foreach` input, or an
`echo` or `unset` statement. These positions report P5005 before PHP is emitted.
An assignment containing `when` in an `if`/`elseif` condition is supported, as
are assignments and returns in loop bodies. Moving a loop-header expression
outside the loop changes how often it runs; put the computation in the loop
body when it must run on each iteration.

A call argument that needs statement-level lowering also needs known parameter
bindings for itself and all earlier arguments. An unresolved callable cannot
silently change a value into a reference, or a reference into a copy. Likewise,
an earlier unpacked argument must bind its individual elements before the block
runs. These unresolved cases report P5005, identifying the argument involved.
Use an explicit function or method signature and explicit arguments where this
binding is required. Bare two-branch results that remain native ternaries do
not have this restriction; they retain PHP's own argument evaluation and binding.

## Types, Scopes, And Errors

The result type is the canonical union of reachable branch-result types. Equal types collapse and `never` branches do not widen the union. Unknown results remain conservative for backend refinement. Compatibility is checked against local, assignment, return, parameter, and typed-array contexts. Composite types, invariant generics, typed lists, and typed maps retain their existing contracts.

A throwing path contributes no value or extra `null` alternative to the result
type. A value produced by `finally` does not turn that throwing path into success.

Each branch has a child binding scope. It sees outer bindings and may mutate mutable ones, but may not write readonly outer bindings. Branch locals do not escape, sibling branches may reuse a name, and a branch local may not shadow a visible outer local.

A branch may contain another `when`, including inside a loop or an ordinary
closure. Typed loop variables inside nested callables follow the same collection
type and declaration rules as other loop variables. Their bindings stay in that
callable; its returns and internal loop transfers keep their ordinary meaning.

Checked errors from conditions, statements, results, nested `when` expressions, and `finally` participate in the enclosing error flow. A caught error does not escape. A throwing branch has type `never`.

## Lowering And Diagnostics

The frontend keeps exact spans and hierarchical nested syntax, then parses conditions and branch bodies after applying descendant ++PHP normalization. Syntax, semantic, and backend diagnostics map to the original `.ppphp` file.

Two branches containing only a result expression each become a native PHP
ternary. For example, `when ($express) { return 1200; } else { return 500; }`
emits `$express ? 1200 : 500`. PHP retains the condition's truthiness, lazy
branch evaluation, argument binding and nullsafe short-circuiting without
compiler temporaries. Branch-local statement comments retain their statement
context instead of being discarded by this simplification. Nested ternaries
are explicitly parenthesized so their grouping remains clear.

Typed local declarations retain their comments and existing PHPDoc alongside
the generated type information. User-written assertions are still checked,
not replaced or treated as compiler-generated guarantees.

Other branch results in tail position lower to ordinary
`if`/`elseif`/`else` statements. A destination with stable components, such as a
plain local, `$this->property`, a named static property, or a property on a
provably unchanged local receiver, is assigned directly when the block does
not read it and no branch `try` encloses the result.
`return when …` keeps ordinary returns in those branches. Tail results inside
conditional arms and switch cases follow the same rule; switch cases use their
ordinary `break`, including a numbered exit when a result must leave multiple
real nested switches. User-written breaks retain their targets and fallthrough.
No synthetic loop, result annotation or scratch variable is
needed for a direct destination.

Early results in conditional guards skip the rest of their branch. A guard
with one continuing arm puts that remainder in the arm; successive guards
become an ordinary conditional chain. When several paths can continue, the
compiler keeps one copy of the remaining statements and checks whether a result
has already been produced. Non-null results can use `null` as the pending value;
nullable results need a separate completion bit so that `return null` still ends
the branch. A declared local used this way retains accurate nullable PHPDoc.
An existing destination is not used as pending storage: a failed result must
leave its old value intact. This also covers a declaration executed repeatedly
in the same scope. A fresh function-local initializer can hold the pending
value only when early assignment cannot be observed; protected regions,
file scope and local-symbol-table access retain a separate temporary.
A partial `switch` keeps its ordinary case exits and
checks completion before the following statements. `return when …` needs none
of this state for conditional guards: native returns already skip the remainder.

When one partial guard or loop is followed only by a fallback result that is safe to
evaluate early, the compiler assigns that fallback first and overwrites it on
an early result. This needs no completion test. Eligible fallbacks include
literals and unchanged local values; expressions with effects or values the
branch may change remain at their original evaluation point. The same
destination-safety rules apply, and authored result comments stay in place.

Loops containing early results keep their native form. An early result exits
the actual enclosing loops and switches with `break` at the required depth;
source-written transfers retain their own targets. A loop with no possible
fallthrough receives neither a fallback nor completion state. Otherwise loops
and partial guards share one completion state per branch, so the remaining
statements run once, only if no result has been produced. `return when …` uses
native returns without that state. Fresh typed loop bindings can be written
without changing a separate fallback local; possible aliases, calls and
executable iterator cleanup prevent that early-read optimization.
Dynamic local creation, including `extract` or included PHP in the same
callable, also prevents treating a typed loop binding as unaliased. File-scope
bindings may already be aliases supplied by an including PHP file.

Leaving a loop may release an iterator. If this can invoke a destructor or
generator cleanup, the result stays in a temporary until cleanup succeeds.
Thus a cleanup failure leaves an existing destination unchanged, even when
the result itself is a scalar. A property write or an old value's destructor
can also observe iterator storage, so those writes stay after iterator release.
Direct assignment remains available when both the local write and the
iterator release are proven unable to execute user code, including ordinary
fresh locals and arrays of recursively safe element types.

Embedded results and destinations that cannot be assigned directly use
collision-free temporaries. Tail-result forms release them at their consuming
expression, including when it throws. Nested calls finish their own argument
cleanup before a later operand is evaluated. Retained values use protected
cleanup, and a throwing destructor cannot postpone the remaining releases until
after an outer catch. Non-reference-counted scalar results need no exceptional
release wrapper. Cleanup accounts for intermediate pending values too: a scalar
result from `finally` does not remove the need to release an earlier object or
container if cleanup throws before replacing it.

Cleanup also preserves whether an exception is already active. For example,
PHP can skip a user stream wrapper's close callback during exception unwinding;
generated cleanup must not invoke that callback merely because it runs inside
a `finally`. Values passed by reference retain that reference through release,
including when an earlier destructor changes the referenced value.

A nested protected result stays separate from an earlier pending value until
its own cleanup succeeds. If a source `catch` handles a cleanup failure, the
failed inner value is released before the catch variable is replaced; the
earlier pending value remains available. This applies to iterator teardown as
well as explicit `finally` blocks. A successful handoff stays inside its source
handler, so that handler can also catch a failure from destroying the replaced
value.

Call arguments preserve their resolved parameter bindings: by-value
arguments keep their evaluated values, and known by-reference arguments retain
their original writable locations, including when a nested expression contains a
`when`. Explicit references in array elements also retain their bindings.
Captured call references are released at the call
boundary, including on exceptions; even a reference to an integer array element
must not leave later array copies aliased.

Earlier operands are left in place when the compiler can prove that doing so
preserves both their value and their parameter binding. Literals and `$this`
need no value capture. For other locals, the proof covers all intervening
operands and branches, not just direct writes to the same name: aliases,
callbacks, property hooks and cleanup can change a local indirectly. Where
that proof is unavailable, the compiler retains the capture.

Assignment receivers keep their native evaluation timing. A direct variable
receiver is read at the eventual property write, even if the right-hand side
replaces that variable. A receiver-producing call is evaluated before the
right-hand side and its result is retained until the write.

Other statement-bearing results use native branches and loops, with completion
state only where several paths can continue. Lowering uses no synthetic loop,
closure, runtime helper or exception for compiler control flow. In supported
call and array positions, observable evaluation and binding of earlier operands
remain before the `when`; later siblings remain after it.

Nested consuming statements own their temporary cleanup. An enclosing `when`
does not release those temporaries a second time or reach into a nested callable.
Focused commands retain valid declarations from unselected files without
lowering invalid bodies; a complete check still reports those source errors.

`when` diagnostics are P5002–P5010 for missing results, valueless results, type mismatches, unsupported positions, prohibited transfers, by-reference use, and fragment parsing. P5001 remains permanently reserved and is not emitted for valid active syntax.
