<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\When;

use Atatusoft\Ppphp\Semantic\Type\AtomicType;
use Atatusoft\Ppphp\Semantic\Type\Interfaces\Type;
use Atatusoft\Ppphp\Semantic\Type\TypedArrayType;
use Atatusoft\Ppphp\Semantic\Type\UnionType;

/** Releasing objects, resources or containers holding them can execute code. */
final class WhenValueLifetime
{
    public function resolveReleaseSafety(Type $type): bool
    {
        if ($type instanceof UnionType) {
            return array_all($type->members, $this->resolveReleaseSafety(...));
        }
        if ($type instanceof TypedArrayType) {
            return $this->resolveReleaseSafety($type->valueType);
        }
        return $type instanceof AtomicType
            && in_array($type->canonical, ['int', 'float', 'bool', 'true', 'false', 'null', 'string', 'never'], true);
    }
}
