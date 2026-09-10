<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Type;

/** The list/map iteration contract shared by source and when-fragment bindings. */
final readonly class IterationTypeResolver
{
    /** @return array{LocalType, LocalType} */
    public function resolve(LocalType $collection): array
    {
        $type = $collection->semanticType;
        if ($type instanceof UnionType) {
            $contracts = array_values(array_filter(
                $type->members,
                static fn ($member): bool => $member instanceof TypedArrayType,
            ));
            $type = count($contracts) === 1 ? $contracts[0] : null;
        }

        return $type instanceof TypedArrayType
            ? [LocalType::createFromSemanticType($type->keyType), LocalType::createFromSemanticType($type->valueType)]
            : [LocalType::createAtomic('mixed'), LocalType::createAtomic('mixed')];
    }
}
