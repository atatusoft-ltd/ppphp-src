# Type-Flow Analysis

The ++PHP compiler is authoritative for supported expression flow and for calls, members, returns, and properties whose declarations are present in compiler-owned context. Portable installed-dependency and target-PHP declarations participate in that context. This analysis runs inside `SemanticAnalyzer`; it does not lower code, execute user code, prepare PHPStan, or create an analysis workspace.

## Expression types and resolution status

`ExpressionTypeResolver` resolves scalar and array literals, locals, assignments, operators, casts, conditionals, `match`, `new`, properties, class constants and enum cases, function/method/static/nullsafe calls, closures, arrows, `throw`, `exit`, and `when`. Structured arrays retain key, value, and list-shape information. `SemanticModel::expressionTypes` records each result against AST identity and source location so later checks reuse the same fact.

Every result has a type, status, and provenance. The statuses are:

- `known`: a compiler-owned contract produced a type;
- `dynamic`: the source deliberately crossed a dynamic invocation boundary;
- `deferred-external`: an unindexed dependency may own the declaration;
- `unknown-expression`: the expression family or fact cannot yet be resolved;
- `missing`: compiler-owned context proves that no declaration exists; and
- `invalid`: a known contract was violated.

Unknown and deferred results are not evidence of compatibility. They also do not justify false missing-symbol diagnostics.

## Compatibility

`TypeCompatibility` returns `Compatible`, `Incompatible`, or `Unknown`. It compares nullability, unions, intersections, typed arrays, generic applications and invariance, type-parameter bounds, and nominal project relationships. A diagnostic is emitted only for a proven incompatibility. Unknown hierarchy or expression information stays explicit for coverage and boundary decisions.

## Flow states and joins

`AnalyzeTypeFlowPass` performs one structured traversal per callable. `FlowState` carries narrowed local types and definitely initialized backed properties. A join unions local alternatives and intersects property-initialization facts, so a property is definite only when every normal predecessor initialized it.

`FlowOutcome` distinguishes normal completion from returns, throws, breaks, continues, and exits. Sequential blocks, conditional branches, loops, switches, `try`/`catch`/`finally`, short-circuit expressions, closures, arrows, hooks, and `when` feed the same outcome model. Loops remain conservative when they may run zero times. A terminating `finally` replaces an earlier pending outcome according to PHP control flow.

Generator-specific yield and return contracts remain the explicit optional `flow.generators` capability. General compiler-owned all-path analysis does not claim generator parity.

## Narrowing

The pass narrows supported locals through null comparisons, `is_null`, `isset`, `instanceof`, and the reviewed `is_*` intrinsics. `&&` analyzes its right operand with the true facts from the left; `||` uses the false facts. Branch joins restore the union of reachable alternatives. Assignments and by-reference calls update or conservatively invalidate affected local facts.

Negation reverses the branch polarity through the same narrowing operation,
including repeated negation and supported compound conditions. A terminating
`if (!is_string($value))` branch leaves a string on the surviving path. A branch
that continues, or an exception caught before continuing, does not establish that
guarantee. Catch inputs conservatively include reachable states observed in the
try body, so a caught exception cannot resurrect a narrowing invalidated by an
assignment or reference. Unreachable statements and unrelated callable bodies do
not contribute predecessor states. Joins and subsequent writes/references retain the declared storage
contract independently of narrowed reads.

Predicate semantics depend on resolved built-in callable identity. Global
fallback, fully qualified names and imported aliases work; same-named project
functions do not gain built-in guard semantics. Predicate intersections preserve
known class/generic identity and typed-array element contracts.

## Calls and generic inference

`CallableContractResolver` is the single resolution path for source functions, methods, constructors, ordinary-PHP declarations, configured stubs, Composer dependency declarations, target-PHP declarations, and reviewed intrinsics. A contract retains parameter order and names, effective native/PHPDoc types, defaults, variadics, reference requirements, return type, owner, visibility, static form, generic declaration, receiver substitutions, checked errors, origin, and source spans.

`CallArgumentBinder` binds positional, named, unpacked, defaulted, variadic, and by-reference arguments without executing defaults. It diagnoses count, placement, duplicate/unknown names, referenceability, and proven type mismatches. `GenericCallInference` infers callable and constructor type parameters from arguments and receiving context, then applies receiver and method substitutions to parameters and return types.

The same resolved callable contracts feed checked-error propagation, so type and error analysis cannot disagree because of duplicate signature indexes.

## Members and properties

`MemberTypeResolver` resolves properties and methods across inheritance, interfaces, traits, unions, intersections, nullable/nullsafe receivers, and applied generics. Its result distinguishes found, missing, deferred, and unknown receivers. Known members are checked for visibility and correct static or instance syntax.

Property writes use effective substituted property types and separate read from write visibility, including asymmetric setters, readonly storage, and property hooks. Definite initialization applies only to typed, instance-backed properties without a default or promoted initialization. Direct constructor writes and sound private/final helper summaries contribute facts; overrideable helper dispatch does not. Abstract and genuinely virtual properties are not treated as uninitialized backing storage.

## PHP, dependencies, platform signatures, and intrinsics

Ordinary `.php` declarations contribute native and compatible PHPDoc contracts to selected ++PHP without receiving ++PHP declaration-completeness rules. Deep analysis inside ordinary-PHP bodies remains supplemental. Configured stubs and installed Composer sources contribute the same contract shapes, do not execute or emit, and may enrich or supply runtime declarations. Contradictory runtime/stub metadata produces `P6012` rather than a false duplicate declaration.

The verified PHP 8.4 signature package supplies the broad platform contract. `IntrinsicFunctionRepository` is a reviewed, process-free refinement set for `strlen`, `count`, `is_null`, `is_int`, `is_string`, `is_bool`, `is_float`, `is_array`, `is_object`, `is_callable`, `array_filter`, and `array_values`. Its name list is shared with signature generation; intrinsics refine narrowing or structured collection flow without duplicating platform declarations. See [Portable Declaration Context](portable-declarations.md).

## Compiler core and full native analysis

Browser protocol version 2 and `CompilerProjectAnalyzer` expose this bounded compiler core without PHPStan or subprocess state. Catalog version 4 separately evidences installed Composer context and source-free portable dependency indexes; all MVP and Boundary capabilities are Complete, so `compilerCore` returns `fullParity: true` with no uncovered required capabilities.

Normal `ppphp check` and `ppphp build` continue through `ProjectChecker` and the pinned PHPStan backend. Parity schema version 2 compares required compiler and full diagnostics separately from supplemental deep-analysis findings and optional lint. The remaining Backend-only capabilities are Optional; PHPStan remains an oracle for those broader boundaries, not the ++PHP language specification.
