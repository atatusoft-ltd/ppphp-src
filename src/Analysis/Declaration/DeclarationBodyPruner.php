<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Declaration;

use Atatusoft\Ppphp\Frontend\ParsedFile;
use PhpParser\Node\Stmt;

/** Drops dependency implementation bodies only after dependency discovery has completed. */
final readonly class DeclarationBodyPruner
{
    public function prune(ParsedFile $file): ParsedFile
    {
        return $file->withDeclarations($file->sourceFile, $this->pruneStatements($file->statements));
    }

    /**
     * @param list<Stmt> $statements
     * @return list<Stmt>
     */
    private function pruneStatements(array $statements): array
    {
        return array_map(function (Stmt $statement): Stmt {
            if ($statement instanceof Stmt\Function_ || $statement instanceof Stmt\ClassMethod) {
                $copy = clone $statement;
                $copy->stmts = $statement->stmts === null ? null : [];

                return $copy;
            }

            // Retain property hooks, defaults, attributes and all original source positions.
            // They can describe declaration contracts, including backed versus virtual properties.
            if ($statement instanceof Stmt\Namespace_ || $statement instanceof Stmt\ClassLike) {
                $copy = clone $statement;
                $copy->stmts = $this->pruneStatements(array_values($statement->stmts));

                return $copy;
            }

            return $statement;
        }, $statements);
    }
}
