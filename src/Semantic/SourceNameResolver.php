<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic;

use Atatusoft\Ppphp\Frontend\ParsedFile;
use PhpParser\Node;
use PhpParser\Node\Stmt;

final class SourceNameResolver
{
    /**
     * @var \WeakMap<ParsedFile, array{
     *     globalImports: list<Stmt\Use_|Stmt\GroupUse>,
     *     namespaces: list<array{node: Stmt\Namespace_, imports: list<Stmt\Use_|Stmt\GroupUse>}>
     * }>|null
     */
    private static ?\WeakMap $importsByFile = null;

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

        [$namespace, $imports] = $this->resolveNamespace($file, $offset);

        if (str_starts_with(strtolower($name), 'namespace\\')) {
            return $this->qualify($namespace, substr($name, strlen('namespace\\')));
        }

        $parts = explode('\\', $name);
        $alias = strtolower($parts[0]);

        foreach ($imports as $statement) {
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

    /** @return array{string, list<Stmt\Use_|Stmt\GroupUse>} */
    private function resolveNamespace(ParsedFile $file, int $offset): array
    {
        self::$importsByFile ??= new \WeakMap();
        $scope = self::$importsByFile[$file] ??= $this->indexFile($file);

        foreach ($scope['namespaces'] as $namespace) {
            $node = $namespace['node'];
            if ($offset >= $node->getStartFilePos() && $offset <= $node->getEndFilePos() + 1) {
                return [$node->name?->toString() ?? '', $namespace['imports']];
            }
        }

        return ['', $scope['globalImports']];
    }

    /**
     * @return array{
     *     globalImports: list<Stmt\Use_|Stmt\GroupUse>,
     *     namespaces: list<array{node: Stmt\Namespace_, imports: list<Stmt\Use_|Stmt\GroupUse>}>
     * }
     */
    private function indexFile(ParsedFile $file): array
    {
        $namespaces = [];
        foreach ($file->statements as $statement) {
            if ($statement instanceof Stmt\Namespace_) {
                $namespaces[] = ['node' => $statement, 'imports' => $this->findImports($statement->stmts)];
            }
        }

        return ['globalImports' => $this->findImports($file->statements), 'namespaces' => $namespaces];
    }

    /**
     * @param array<Stmt> $statements
     * @return list<Stmt\Use_|Stmt\GroupUse>
     */
    private function findImports(array $statements): array
    {
        return array_values(array_filter($statements, static fn (Stmt $statement): bool =>
            $statement instanceof Stmt\Use_ || $statement instanceof Stmt\GroupUse));
    }

    private function qualify(string $namespace, string $name): string
    {
        return $namespace === '' ? $name : $namespace . '\\' . $name;
    }
}
