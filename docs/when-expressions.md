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

Lowering emits prerequisite statements, a collision-free deterministic temporary, and ordinary `if`/`elseif`/`else` control flow inside compiler-owned `do` boundaries. It uses no synthetic closure, runtime helper, or exception for compiler control flow. Earlier call arguments and array elements are evaluated before the `when`; later siblings remain after it. Temporaries are cleaned up when control continues.

Nested consuming statements own their temporary cleanup. An enclosing `when`
does not release those temporaries a second time or reach into a nested callable.
Focused commands retain valid declarations from unselected files without
lowering invalid bodies; a complete check still reports those source errors.

`when` diagnostics are P5002–P5010 for missing results, valueless results, type mismatches, unsupported positions, prohibited transfers, by-reference use, and fragment parsing. P5001 remains permanently reserved and is not emitted for valid active syntax.
