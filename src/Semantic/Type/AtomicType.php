<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Type;

use Atatusoft\Ppphp\Semantic\Type\Interfaces\Type;

final class AtomicType implements Type
{
    private const array BUILTINS = [
        'array' => true, 'bool' => true, 'callable' => true, 'false' => true,
        'float' => true, 'int' => true, 'iterable' => true, 'mixed' => true,
        'never' => true, 'null' => true, 'object' => true, 'resource' => true,
        'string' => true, 'true' => true, 'void' => true,
    ];

    public readonly string $name;

    public readonly bool $isFullyQualified;

    public readonly string $canonical;

    public function __construct(string $name, ?bool $isFullyQualified = null)
    {
        $name = trim($name);
        $this->name = ltrim($name, '\\');
        $this->canonical = strtolower($this->name);
        $this->isFullyQualified = $isFullyQualified ?? str_starts_with($name, '\\');
    }

    public bool $isNullable {
        get => in_array($this->canonical, ['mixed', 'null'], true);
    }

    public bool $isUnknown {
        get => false;
    }

    public bool $isBuiltin {
        get => isset(self::BUILTINS[$this->canonical]);
    }

    public function renderPhpDoc(): string
    {
        if ($this->isBuiltin) {
            return $this->canonical;
        }

        return $this->isFullyQualified ? '\\' . $this->name : $this->name;
    }

    public function eraseToNative(): string
    {
        return $this->renderPhpDoc();
    }
}
