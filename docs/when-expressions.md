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

Inside the lexical body of a branch, `return expression;` produces the value of the `when`; it does not return from the enclosing callable. Returns inside nested functions, methods, closures, and arrow functions keep their ordinary PHP meaning. `return;` is invalid. Every reachable branch path must produce a value, throw, exit, or end in a resolved `never` expression. A possibly empty loop does not establish a result. `break`, `continue`, `goto`, labels, `yield`, and `yield from` are rejected outside a nested callable boundary.

Conditions use ordinary PHP truthiness. They run from left to right, at most once, and only until a branch is selected. Only that branch body runs. `try`, `catch`, and `finally` retain PHP behavior; a result produced by `finally` supersedes an earlier pending result or exception, and an exception thrown by `finally` supersedes both.

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

## Types, Scopes, And Errors

The result type is the canonical union of reachable branch-result types. Equal types collapse and `never` branches do not widen the union. Unknown results remain conservative for backend refinement. Compatibility is checked against local, assignment, return, parameter, and typed-array contexts. Composite types, invariant generics, typed lists, and typed maps retain their existing contracts.

Each branch has a child binding scope. It sees outer bindings and may mutate mutable ones, but may not write readonly outer bindings. Branch locals do not escape, sibling branches may reuse a name, and a branch local may not shadow a visible outer local.

A branch may contain another `when`, including inside a loop or an ordinary
closure. Typed loop variables inside nested callables follow the same collection
type and declaration rules as other loop variables. Their bindings stay in that
callable; its returns and internal loop transfers keep their ordinary meaning.

Checked errors from conditions, statements, results, nested `when` expressions, and `finally` participate in the enclosing error flow. A caught error does not escape. A throwing branch has type `never`.

## Lowering And Diagnostics

The frontend keeps exact spans and hierarchical nested syntax, then parses conditions and branch bodies with the PHP 8.4 parser after applying descendant ++PHP normalization. Syntax, semantic, and backend diagnostics map to the original `.ppphp` file.

Two branches containing only a result expression each become a native PHP
ternary. For example, `when ($express) { return 1200; } else { return 500; }`
emits `$express ? 1200 : 500`. PHP retains the condition's truthiness, lazy
branch evaluation, argument binding and nullsafe short-circuiting without
compiler temporaries. Branch-local statement comments retain their statement
context instead of being discarded by this simplification. Nested ternaries
are explicitly parenthesized so their grouping remains clear.

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

When one partial guard is followed only by a fallback result that is safe to
evaluate early, the compiler assigns that fallback first and overwrites it on
an early result. This needs no completion test. Eligible fallbacks include
literals and unchanged local values; expressions with effects or values the
branch may change remain at their original evaluation point. The same
destination-safety rules apply, and authored result comments stay in place.

Embedded results and destinations that cannot be assigned directly use
collision-free temporaries. Tail-result forms release them at their consuming
expression, including when it throws. Nested calls finish their own argument
cleanup before a later operand is evaluated. Retained values use protected
cleanup, and a throwing destructor cannot postpone the remaining releases until
after an outer catch. Non-reference-counted scalar results need no exceptional
release wrapper.

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

Other control-transfer shapes currently retain compiler-owned `do` boundaries.
Lowering uses no synthetic closure, runtime helper or exception for compiler
control flow. Observable evaluation and binding of earlier call arguments and
array elements remain before the `when`; later siblings remain after it.

Nested consuming statements own their temporary cleanup. An enclosing `when`
does not release those temporaries a second time or reach into a nested callable.
Focused commands retain valid declarations from unselected files without
lowering invalid bodies; a complete check still reports those source errors.

`when` diagnostics are P5002–P5010 for missing results, valueless results, type mismatches, unsupported positions, prohibited transfers, by-reference use, and fragment parsing. P5001 remains permanently reserved and is not emitted for valid active syntax.
