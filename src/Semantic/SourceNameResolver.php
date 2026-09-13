<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic;

use Atatusoft\Ppphp\Frontend\ParsedFile;
use PhpParser\Node;
use PhpParser\Node\Stmt;

final readonly class SourceNameResolver
{
    public function resolve(ParsedFile $file, string $name, int $offset, ?int $useType = null): string
    {
        $name = trim($name);

        if (in_array(strtolower($name), [
            'array', 'bool', 'callable', 'false', 'float', 'int', 'iterable',
            'mixed', 'never', 'null', 'object', 'resource', 'string', 'true', 'void',
        ], true)) {
            return strtolower($name);
        }

        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        [$namespace, $statements] = $this->resolveNamespace($file, $offset);

        if (str_starts_with(strtolower($name), 'namespace\\')) {
            return $this->qualify($namespace, substr($name, strlen('namespace\\')));
        }

        $parts = explode('\\', $name);
        $alias = strtolower($parts[0]);

        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Use_) {
                foreach ($statement->uses as $use) {
                    if ($useType !== null && ($use->type ?: $statement->type) !== $useType) {
                        continue;
                    }
                    if (strtolower($use->getAlias()->toString()) !== $alias) {
                        continue;
                    }

                    array_shift($parts);

                    return implode('\\', [$use->name->toString(), ...$parts]);
                }
            } elseif ($statement instanceof Stmt\GroupUse) {
                foreach ($statement->uses as $use) {
                    if ($useType !== null && ($use->type ?: $statement->type) !== $useType) {
                        continue;
                    }
                    if (strtolower($use->getAlias()->toString()) !== $alias) {
                        continue;
                    }

                    array_shift($parts);

                    return implode('\\', [$statement->prefix->toString(), $use->name->toString(), ...$parts]);
                }
            }
        }

        return $this->qualify($namespace, $name);
    }

    public function resolveNamespaceAt(ParsedFile $file, int $offset): string
    {
        return $this->resolveNamespace($file, $offset)[0];
    }

    /** @return array{string, list<Stmt>} */
    private function resolveNamespace(ParsedFile $file, int $offset): array
    {
        foreach ($file->statements as $statement) {
            if (
                $statement instanceof Stmt\Namespace_
                && $offset >= $statement->getStartFilePos()
                && $offset <= $statement->getEndFilePos() + 1
            ) {
                return [$statement->name?->toString() ?? '', array_values($statement->stmts)];
            }
        }

        return ['', $file->statements];
    }

    private function qualify(string $namespace, string $name): string
    {
        return $namespace === '' ? $name : $namespace . '\\' . $name;
    }
}
