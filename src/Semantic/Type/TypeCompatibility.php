<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Type;

use Atatusoft\Ppphp\Semantic\Symbol\ClassSymbol;
use Atatusoft\Ppphp\Semantic\Symbol\SymbolTable;
use Atatusoft\Ppphp\Semantic\Type\Interfaces\Type;

final class TypeCompatibility
{
    public function accepts(LocalType $declared, LocalType $actual, ?SymbolTable $symbols = null): bool
    {
        return $this->compare($declared->semanticType, $actual->semanticType, $symbols)->isAccepted();
    }

    public function compare(Type $declared, Type $actual, ?SymbolTable $symbols = null, bool $arrayLiteral = false): TypeCompatibilityResult
    {
        if ($declared->isUnknown || $actual->isUnknown) {
            return TypeCompatibilityResult::Unknown;
        }

        if (($declared instanceof AtomicType && $declared->canonical === 'mixed')
            || ($actual instanceof AtomicType && $actual->canonical === 'never')) {
            return TypeCompatibilityResult::Compatible;
        }

        if ($actual instanceof UnionType) {
            $result = TypeCompatibilityResult::Compatible;

            foreach ($actual->members as $member) {
                $memberResult = $this->compare($declared, $member, $symbols, $arrayLiteral);

                if ($memberResult === TypeCompatibilityResult::Incompatible) {
                    return $memberResult;
                }

                if ($memberResult === TypeCompatibilityResult::Unknown) {
                    $result = $memberResult;
                }
            }

            return $result;
        }

        if ($declared instanceof UnionType) {
            $unknown = false;

            foreach ($declared->members as $member) {
                $memberResult = $this->compare($member, $actual, $symbols, $arrayLiteral);

                if ($memberResult === TypeCompatibilityResult::Compatible) {
                    return $memberResult;
                }

                $unknown = $unknown || $memberResult === TypeCompatibilityResult::Unknown;
            }

            return $unknown ? TypeCompatibilityResult::Unknown : TypeCompatibilityResult::Incompatible;
        }

        if ($declared instanceof IntersectionType) {
            $result = TypeCompatibilityResult::Compatible;

            foreach ($declared->members as $member) {
                $memberResult = $this->compare($member, $actual, $symbols);

                if ($memberResult === TypeCompatibilityResult::Incompatible) {
                    return $memberResult;
                }

                if ($memberResult === TypeCompatibilityResult::Unknown) {
                    $result = $memberResult;
                }
            }

            return $result;
        }

        if ($actual instanceof IntersectionType) {
            $unknown = false;

            foreach ($actual->members as $member) {
                $memberResult = $this->compare($declared, $member, $symbols);

                if ($memberResult === TypeCompatibilityResult::Compatible) {
                    return $memberResult;
                }

                $unknown = $unknown || $memberResult === TypeCompatibilityResult::Unknown;
            }

            return $unknown ? TypeCompatibilityResult::Unknown : TypeCompatibilityResult::Incompatible;
        }

        if ($declared instanceof TypeParameter) {
            if ($actual instanceof TypeParameter && $declared->canonical === $actual->canonical) {
                return TypeCompatibilityResult::Compatible;
            }

            return $declared->bound === null
                ? TypeCompatibilityResult::Unknown
                : $this->compare($declared->bound, $actual, $symbols);
        }

        if ($actual instanceof TypeParameter) {
            return $actual->bound === null
                ? TypeCompatibilityResult::Unknown
                : $this->compare($declared, $actual->bound, $symbols);
        }

        if ($declared instanceof GenericType && ($actual instanceof GenericType || $actual instanceof AtomicType)) {
            if ($actual instanceof AtomicType && $declared->base->canonical === $actual->canonical) {
                return TypeCompatibilityResult::Compatible;
            }

            if ($symbols === null) {
                return $declared->canonical === $actual->canonical
                    ? TypeCompatibilityResult::Compatible
                    : TypeCompatibilityResult::Unknown;
            }

            return $this->fromNullableBoolean($this->satisfiesAppliedHierarchy($actual, $declared, $symbols));
        }

        if ($declared instanceof TypedArrayType && $actual instanceof TypedArrayType) {
            // A fresh literal can adopt the target element contract. Existing arrays
            // (including nested array values) retain their invariant contracts.
            if ($arrayLiteral) {
                if ($declared->isList && !$actual->isList) {
                    return TypeCompatibilityResult::Incompatible;
                }
                $results = [
                    $this->compare($declared->keyType, $actual->keyType, $symbols),
                    $this->compare($declared->valueType, $actual->valueType, $symbols),
                ];

                return in_array(TypeCompatibilityResult::Incompatible, $results, true)
                    ? TypeCompatibilityResult::Incompatible
                    : (in_array(TypeCompatibilityResult::Unknown, $results, true)
                        ? TypeCompatibilityResult::Unknown
                        : TypeCompatibilityResult::Compatible);
            }

            return $this->fromBoolean($declared->canonical === $actual->canonical);
        }

        if ($declared instanceof TypedArrayType && $actual instanceof AtomicType) {
            return $this->fromBoolean($actual->canonical === 'array');
        }

        if ($declared instanceof AtomicType && $actual instanceof TypedArrayType) {
            return $this->fromBoolean($declared->canonical === 'array');
        }

        if ($declared instanceof AtomicType && $actual instanceof GenericType) {
            if ($declared->canonical === $actual->base->canonical) {
                return TypeCompatibilityResult::Compatible;
            }

            return $this->fromNullableBoolean($this->satisfiesHierarchy($actual->base->name, $declared->name, $symbols));
        }

        if (!$declared instanceof AtomicType || !$actual instanceof AtomicType) {
            return $this->fromBoolean($declared->canonical === $actual->canonical);
        }

        if ($declared->canonical === $actual->canonical) {
            return TypeCompatibilityResult::Compatible;
        }

        if ($declared->canonical === 'bool' && in_array($actual->canonical, ['true', 'false'], true)) {
            return TypeCompatibilityResult::Compatible;
        }

        if ($declared->canonical === 'callable' && $actual->canonical === 'closure') {
            return TypeCompatibilityResult::Compatible;
        }

        if ($declared->canonical === 'iterable' && $actual->canonical === 'array') {
            return TypeCompatibilityResult::Compatible;
        }

        if ($declared->canonical === 'object' && !$actual->isBuiltin) {
            return TypeCompatibilityResult::Compatible;
        }

        if ($declared->isBuiltin || $actual->isBuiltin) {
            return TypeCompatibilityResult::Incompatible;
        }

        return $this->fromNullableBoolean($this->satisfiesHierarchy($actual->name, $declared->name, $symbols));
    }

    private function satisfiesHierarchy(string $actual, string $declared, ?SymbolTable $symbols): ?bool
    {
        if ($symbols === null) {
            return null;
        }

        $actualSymbol = $this->findClass($actual, $symbols);
        $declaredSymbol = $this->findClass($declared, $symbols);

        if ($actualSymbol === null || $declaredSymbol === null) {
            return null;
        }

        $visited = [];

        return $this->classSatisfies($actualSymbol, $declaredSymbol->fullyQualifiedName, $symbols, $visited);
    }

    /** @param array<string, true> $visited */
    private function satisfiesAppliedHierarchy(
        AtomicType|GenericType $actual,
        GenericType $declared,
        SymbolTable $symbols,
        array &$visited = [],
    ): ?bool {
        if ($actual instanceof GenericType && $actual->canonical === $declared->canonical) {
            return true;
        }

        $className = $actual instanceof GenericType ? $actual->base->name : $actual->name;
        $class = $this->findClass($className, $symbols);

        if ($class === null) {
            return null;
        }

        $visitKey = strtolower($class->fullyQualifiedName . '<' . $actual->canonical . '>');

        if (isset($visited[$visitKey])) {
            return false;
        }

        $visited[$visitKey] = true;
        $arguments = [];

        if ($actual instanceof GenericType && $class->genericDeclaration !== null) {
            foreach ($class->genericDeclaration->parameters as $index => $parameter) {
                if (isset($actual->arguments[$index])) {
                    $arguments[$parameter->canonical] = $actual->arguments[$index];
                }
            }
        }

        $substitution = new TypeSubstitution($arguments);
        $related = [
            ...$class->interfaceTypes,
            ...$class->traitTypes,
            ...($class->parentType === null ? [] : [$class->parentType]),
        ];
        $unknown = false;

        foreach ($related as $namedType) {
            $candidate = $substitution->substitute($namedType->semanticType);

            if ($candidate->canonical === $declared->canonical) {
                return true;
            }

            if ($candidate instanceof AtomicType || $candidate instanceof GenericType) {
                $result = $this->satisfiesAppliedHierarchy($candidate, $declared, $symbols, $visited);

                if ($result === true) {
                    return true;
                }

                $unknown = $unknown || $result === null;
            }
        }

        return $unknown ? null : false;
    }

    private function fromBoolean(bool $value): TypeCompatibilityResult
    {
        return $value ? TypeCompatibilityResult::Compatible : TypeCompatibilityResult::Incompatible;
    }

    private function fromNullableBoolean(?bool $value): TypeCompatibilityResult
    {
        return match ($value) {
            true => TypeCompatibilityResult::Compatible,
            false => TypeCompatibilityResult::Incompatible,
            null => TypeCompatibilityResult::Unknown,
        };
    }

    private function findClass(string $name, SymbolTable $symbols): ?ClassSymbol
    {
        $exact = $symbols->findClass($name);

        if ($exact !== null) {
            return $exact;
        }

        $matches = array_values(array_filter(
            $symbols->classes,
            static fn (ClassSymbol $symbol): bool => strcasecmp(
                TypeName::resolveShort($symbol->fullyQualifiedName),
                ltrim($name, '\\'),
            ) === 0,
        ));

        return count($matches) === 1 ? $matches[0] : null;
    }

    /** @param array<string, true> $visited */
    private function classSatisfies(
        ClassSymbol $actual,
        string $declared,
        SymbolTable $symbols,
        array &$visited,
    ): bool {
        $key = strtolower(ltrim($actual->fullyQualifiedName, '\\'));

        if (isset($visited[$key])) {
            return false;
        }

        $visited[$key] = true;

        if (strcasecmp($actual->fullyQualifiedName, $declared) === 0) {
            return true;
        }

        foreach ([...$actual->interfaces, ...$actual->traits, ...($actual->parent === null ? [] : [$actual->parent])] as $relatedName) {
            if (strcasecmp($relatedName, $declared) === 0) {
                return true;
            }

            $related = $symbols->findClass($relatedName);

            if ($related !== null && $this->classSatisfies($related, $declared, $symbols, $visited)) {
                return true;
            }
        }

        return false;
    }
}
