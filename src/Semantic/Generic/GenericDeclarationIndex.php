<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Generic;

use Atatusoft\Ppphp\Frontend\Ast\GenericDeclaration;
use Atatusoft\Ppphp\Semantic\Type\TypeParameter;
use Atatusoft\Ppphp\Semantic\Type\TypeName;
use Atatusoft\Ppphp\Source\SourceFile;
use Atatusoft\Ppphp\Support\Path;

final class GenericDeclarationIndex
{
    /** @var array<string, GenericDeclarationEntry> */
    private array $entriesByKey = [];

    /** @var array<string, list<GenericDeclarationEntry>> */
    private array $typeEntriesByName = [];

    public function record(GenericDeclarationEntry $entry): void
    {
        $this->entriesByKey[$entry->key] = $entry;

        if ($entry->isTypeDeclaration) {
            $nameKey = strtolower(ltrim($entry->name, '\\'));
            $shortKey = strtolower(TypeName::resolveShort($entry->name));
            $this->typeEntriesByName[$nameKey][] = $entry;
            $this->typeEntriesByName[$shortKey][] = $entry;
        }
    }

    public function findDeclaration(SourceFile $sourceFile, GenericDeclaration $declaration): ?GenericDeclarationEntry
    {
        return $this->entriesByKey[$this->buildKey($sourceFile, $declaration->ownerNameSpan->start->offset)] ?? null;
    }

    public function findType(string $name, string $namespace = ''): ?GenericDeclarationEntry
    {
        $qualified = $namespace === '' || str_contains(ltrim($name, '\\'), '\\')
            ? ltrim($name, '\\')
            : $namespace . '\\' . ltrim($name, '\\');
        $matches = $this->typeEntriesByName[strtolower($qualified)]
            ?? $this->typeEntriesByName[strtolower(ltrim($name, '\\'))]
            ?? [];
        $unique = [];

        foreach ($matches as $match) {
            $unique[$match->key] = $match;
        }

        return count($unique) === 1 ? array_values($unique)[0] : null;
    }

    /** @return list<GenericDeclarationEntry> */
    public function findVisibleDeclarations(SourceFile $sourceFile, int $offset): array
    {
        if ($this->entriesByKey === []) {
            return [];
        }

        $pathKey = Path::buildComparisonKey($sourceFile->path);
        $visible = array_values(array_filter(
            $this->entriesByKey,
            static fn (GenericDeclarationEntry $entry): bool => Path::buildComparisonKey($entry->sourceFile->path) === $pathKey
                && $offset >= $entry->ownerSpan->start->offset
                && $offset < $entry->ownerSpan->end->offset,
        ));
        usort($visible, static fn (GenericDeclarationEntry $left, GenericDeclarationEntry $right): int =>
            ($left->ownerSpan->start->offset <=> $right->ownerSpan->start->offset)
                ?: ($right->ownerSpan->end->offset <=> $left->ownerSpan->end->offset));

        return $visible;
    }

    public function findVisibleParameter(SourceFile $sourceFile, int $offset, string $name): ?TypeParameter
    {
        $declarations = array_reverse($this->findVisibleDeclarations($sourceFile, $offset));

        foreach ($declarations as $declaration) {
            $parameter = $declaration->findParameter($name);

            if ($parameter !== null) {
                return $parameter;
            }
        }

        return null;
    }

    public function containsParameterName(string $name): bool
    {
        foreach ($this->entriesByKey as $entry) {
            if ($entry->findParameter($name) !== null) {
                return true;
            }
        }

        return false;
    }

    /** @var list<GenericDeclarationEntry> */
    public array $entries {
        get => array_values($this->entriesByKey);
    }

    private function buildKey(SourceFile $sourceFile, int $ownerOffset): string
    {
        return Path::buildComparisonKey($sourceFile->path) . ':' . $ownerOffset;
    }
}
