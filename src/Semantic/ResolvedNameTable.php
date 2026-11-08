<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic;

use PhpParser\Node;

final class ResolvedNameTable
{
    /** @var array<int, string> */
    private array $names = [];

    public function record(Node $node, string $fullyQualifiedName): void
    {
        $this->names[spl_object_id($node)] = ltrim($fullyQualifiedName, '\\');
    }

    public function merge(self $table): void
    {
        $this->names = array_replace($this->names, $table->names);
    }

    public function resolve(Node $node): ?string
    {
        return $this->names[spl_object_id($node)] ?? null;
    }

    /** @var array<int, string> */
    public array $entries {
        get => $this->names;
    }
}
