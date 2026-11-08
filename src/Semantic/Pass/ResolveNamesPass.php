<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Pass;

use Atatusoft\Ppphp\Analysis\Declaration\DeclarationOrigin;
use Atatusoft\Ppphp\Frontend\ParsedFile;
use Atatusoft\Ppphp\Project\ProjectParseResult;
use Atatusoft\Ppphp\Semantic\ProjectSemanticContext;
use Atatusoft\Ppphp\Semantic\ResolvedNameTable;
use PhpParser\ErrorHandler\Collecting;
use PhpParser\NameContext;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;

final class ResolveNamesPass
{
    /** @var \WeakMap<ParsedFile, ResolvedNameTable> */
    private \WeakMap $platformNames;

    public function __construct(private readonly bool $reusePlatformNames = false)
    {
        $this->platformNames = new \WeakMap();
    }

    public function preparePlatformNames(ProjectParseResult $platform): void
    {
        if (!$this->reusePlatformNames) {
            throw new \LogicException('Platform name reuse is unavailable.');
        }
        foreach ($platform->parsedFiles as $file) {
            if ($file->sourceFile->declarationOrigin !== DeclarationOrigin::PhpPlatform) {
                throw new \LogicException('Only verified PHP platform names may be prepared.');
            }
            $this->platformNames[$file] ??= $this->resolveFile($file, new ResolvedNameTable());
        }
    }

    public function execute(ProjectSemanticContext $context): void
    {
        foreach ($context->parseResult->parsedFiles as $file) {
            if (isset($this->platformNames[$file])) {
                $context->resolvedNames->merge($this->platformNames[$file]);
                continue;
            }
            $this->resolveFile($file, $context->resolvedNames);
        }
    }

    private function resolveFile(ParsedFile $file, ResolvedNameTable $resolved): ResolvedNameTable
    {
        $names = new NameContext(new Collecting());
        $names->startNamespace();
        $this->resolveStatements($file->statements, $names, $resolved);

        return $resolved;
    }

    /** @param list<Stmt> $statements */
    private function resolveStatements(array $statements, NameContext $names, ResolvedNameTable $table): void
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Namespace_) {
                $nested = new NameContext(new Collecting());
                $nested->startNamespace($statement->name);
                $this->resolveStatements(array_values($statement->stmts), $nested, $table);
                continue;
            }

            if ($statement instanceof Stmt\Use_) {
                foreach ($statement->uses as $use) {
                    $type = $use->type === Stmt\Use_::TYPE_UNKNOWN ? $statement->type : $use->type;
                    $names->addAlias($use->name, $use->getAlias()->toString(), $type, $use->getAttributes());
                }
                continue;
            }

            if ($statement instanceof Stmt\GroupUse) {
                foreach ($statement->uses as $use) {
                    $type = $use->type === Stmt\Use_::TYPE_UNKNOWN ? $statement->type : $use->type;
                    $name = Name::concat($statement->prefix, $use->name);

                    if ($name !== null) {
                        $names->addAlias($name, $use->getAlias()->toString(), $type, $use->getAttributes());
                    }
                }
                continue;
            }

            $this->resolveNode($statement, $names, $table, Stmt\Use_::TYPE_NORMAL);
        }
    }

    /** @param Stmt\Use_::TYPE_* $type */
    private function resolveNode(Node $node, NameContext $names, ResolvedNameTable $table, int $type): void
    {
        if ($node instanceof Name) {
            $resolved = $names->getResolvedName($node, $type);
            $table->record($node, ($resolved ?? $node)->toString());

            return;
        }

        if ($node instanceof Expr\FuncCall && $node->name instanceof Name) {
            $this->resolveNode($node->name, $names, $table, Stmt\Use_::TYPE_FUNCTION);
        } elseif ($node instanceof Expr\ConstFetch) {
            $this->resolveNode($node->name, $names, $table, Stmt\Use_::TYPE_CONSTANT);
        }

        foreach ($node->getSubNodeNames() as $subNodeName) {
            $value = $node->{$subNodeName};

            if ($value instanceof Node) {
                if (($node instanceof Expr\FuncCall && $subNodeName === 'name') || ($node instanceof Expr\ConstFetch && $subNodeName === 'name')) {
                    continue;
                }

                $this->resolveNode($value, $names, $table, Stmt\Use_::TYPE_NORMAL);
            } elseif (is_array($value)) {
                foreach ($value as $child) {
                    if ($child instanceof Node) {
                        $this->resolveNode($child, $names, $table, Stmt\Use_::TYPE_NORMAL);
                    }
                }
            }
        }
    }
}
