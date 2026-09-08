# Checked Errors

> **Status:** Available in the current compiler.

++PHP adds compile-time error contracts without changing PHP's runtime exception model. A named callable declares the checked exceptions it may let escape:

~~~php
function loadUser(string $id): User
    throws UserNotFound, StorageFailure
{
    if ($id === '') {
        throw new UserNotFound($id);
    }

    return readStoredUser($id);
}
~~~

The syntax is available on named functions, methods, constructors, interface methods, and abstract methods.

## Error Sets

A callable's escaping error set is computed as:

~~~text
directly thrown checked exceptions
+ checked contracts from resolved calls and constructors
- exceptions handled by matching catches
= checked exceptions that must be declared
~~~

A catch handles its declared type and all subtypes. Multi-catch is supported. Checked exceptions thrown by catch or finally bodies contribute normally; a finally block that cannot complete replaces pending control flow according to ordinary PHP behavior.

A callable may declare a broader checked supertype. Duplicate declarations are rejected. An overriding method may preserve or narrow every inherited contract but may not widen it. Constructors do not inherit a parent constructor's error contract.

## Checked And Unchecked Throwables

Exception descendants are checked. PHP Error descendants are unchecked and do not create catch-or-declare obligations. Throwable itself is a broad checked contract because it includes checked exceptions.

Every declared or documented error type must resolve to a Throwable implementation. Scalar, unknown non-throwable, nullable, generic, and intersection error declarations are rejected.

## Scope Boundaries

Executable file scope, closures, arrow functions, and destructors have no throws clause. A checked exception must be caught before it escapes one of those boundaries.

Dynamic calls, variable function or method names, and similar boundaries whose target cannot be resolved produce warning P4005. A statically named external callable without a declared checked-error contract is treated as having no known checked errors; it is not a dynamic boundary. Project-known generic receivers use their resolved base declaration and applied arguments, so a known method with an empty contract does not produce P4005 and a declared checked error still propagates through generic and chained calls. The warning is explicit about a genuinely missing guarantee and does not block checking or building by itself.

## PHP And Stub Interoperability

Ordinary .php functions and methods may contribute checked contracts through `@throws` tags. Configured `.stub.php` declarations use the same metadata and take precedence over matching project PHP declarations.

The compiler does not impose ++PHP declaration-completeness rules on ordinary PHP bodies. A configured stub may therefore supply an authoritative checked contract for an ordinary PHP boundary whose source omits `@throws`; ++PHP callers must still catch or declare that imported contract.

A .ppphp callable must use a native throws clause. PHPDoc alone is rejected, and PHPDoc that conflicts with the native clause is rejected. Matching descriptions and unrelated tags are preserved during lowering.

## Lowering

The throws clause is erased and its canonical fully qualified types are merged into the callable's PHPDoc:

~~~php
/** @throws \App\UserNotFound|\App\StorageFailure */
function loadUser(string $id): User
{
}
~~~

Existing descriptions, attributes, and unrelated PHPDoc tags remain intact. Matching `@throws` tags are not duplicated. Generated code contains ordinary PHP exceptions and requires no ++PHP runtime support.

## Diagnostics

~~~text
P4002  Error Type Is Not Throwable
P4003  Checked Error Is Not Handled
P4004  Exception Not Permitted By Inherited Contract
P4005  Unchecked Call Boundary
P4006  Native Throws Clause Is Required
P4007  Throws Documentation Conflicts With Native Clause
P4008  Checked Error Cannot Escape File Scope
P4009  Checked Error Cannot Escape Anonymous Callable
P4010  Checked Error Cannot Escape Destructor
P4011  Duplicate Error Declaration
P4012  Caught Error Is Never Thrown
P4013  Error Catch Is Unreachable
~~~

Diagnostics use original source paths and spans. Backend checked-exception findings are mapped to the same stable P4xxx family and deduplicated from compiler-owned findings.

## Errors In `when`

Calls and throws in every condition, branch statement, branch result, nested `when`, catch, and finally block contribute to the enclosing executable error flow. Catch clauses remove matching errors normally. A throwing branch has result type `never` and does not widen the value union. A `finally` result may supersede a pending branch result or exception, while a finally throw supersedes both. Lowering introduces no callable boundary and no exception solely for compiler control flow, so the native checked-error contract and emitted `@throws` metadata remain intact.

## Inherited Contract Guidance

`P4004` names both matching methods, the additional checked exception, and the
inherited method's permitted checked set (including an explicitly empty set).
Original-source frames show the implementation and each rejecting interface or
parent declaration. A different method's `throws` clause cannot authorize this
method. Allowed narrower contracts retain their existing behavior.

For project-owned declarations, help explains the API-changing option of adding
the exception to the matching public contract and the implementation-side option
of handling it before it escapes. Ordinary PHP contracts use `@throws` metadata.
All applicable inherited contracts must permit an escaping exception; changing
one of several rejecting interfaces is not necessarily enough. Dependency-owned
contracts receive implementation-side guidance, without a vendor-edit quick fix.
Deleting `throws` alone leaves an escaping-error diagnostic. The compiler never
automatically broadens an interface or changes which throwable hierarchy is checked.
