<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Type;

use Atatusoft\Ppphp\Semantic\SemanticContext;
use Atatusoft\Ppphp\Semantic\SourceNameResolver;
use Atatusoft\Ppphp\Semantic\Type\Interfaces\Type;
use PhpParser\Node\Stmt;

/** Renders evidence as a local source type; PHPDoc-only syntax is never inserted. */
final readonly class SourceTypeRenderer
{
    public function render(Type $type, SemanticContext $context, int $offset): ?string
    {
        if ($type->isUnknown) {
            return null;
        }
        if ($type instanceof TypeParameter) {
            return $context->genericDeclarations->findVisibleParameter($context->parsedFile->sourceFile, $offset, $type->name)?->canonical === $type->canonical
                ? $type->name : null;
        }
        if ($type instanceof AtomicType) {
            if ($type->isBuiltin) {
                return in_array($type->canonical, ['mixed', 'never', 'void', 'resource'], true) ? null : $type->canonical;
            }
            $names = new SourceNameResolver();
            $parts = explode('\\', $type->name);
            $candidates = [end($parts)];
            $statements = $context->parsedFile->statements;
            foreach ($statements as $statement) {
                if ($statement instanceof Stmt\Namespace_ && $offset >= $statement->getStartFilePos()
                    && $offset <= $statement->getEndFilePos() + 1) {
                    $statements = $statement->stmts;
                    break;
                }
            }
            foreach ($statements as $statement) {
                if (!$statement instanceof Stmt\Use_ && !$statement instanceof Stmt\GroupUse) {
                    continue;
                }
                foreach ($statement->uses as $use) {
                    $kind = $use->type === Stmt\Use_::TYPE_UNKNOWN ? $statement->type : $use->type;
                    if ($kind !== Stmt\Use_::TYPE_NORMAL) {
                        continue;
                    }
                    $import = ($statement instanceof Stmt\GroupUse ? $statement->prefix->toString() . '\\' : '') . $use->name->toString();
                    if (strcasecmp($type->name, $import) === 0) {
                        $candidates[] = $use->getAlias()->toString();
                    } elseif (str_starts_with(strtolower($type->name), strtolower($import) . '\\')) {
                        $candidates[] = $use->getAlias()->toString() . substr($type->name, strlen($import));
                    }
                }
            }
            foreach ($candidates as $candidate) {
                if (strcasecmp($names->resolve($context->parsedFile, $candidate, $offset), $type->name) === 0) {
                    return $candidate;
                }
            }
            return '\\' . $type->name;
        }
        if ($type instanceof TypedArrayType) {
            $key = $this->render($type->keyType, $context, $offset);
            $value = $this->render($type->valueType, $context, $offset);
            return $key === null || $value === null ? null
                : 'array<' . ($type->isList ? '' : $key . ', ') . $value . '>';
        }
        if ($type instanceof GenericType) {
            $base = $this->render($type->base, $context, $offset);
            $arguments = array_map(fn (Type $t): ?string => $this->render($t, $context, $offset), $type->arguments);
            return $base === null || in_array(null, $arguments, true) ? null : $base . '<' . implode(', ', $arguments) . '>';
        }
        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            $members = [];
            foreach ($type->members as $member) {
                $text = $this->render($member, $context, $offset);
                if ($text === null) {
                    return null;
                }
                $members[] = $type instanceof UnionType && $member instanceof IntersectionType ? '(' . $text . ')' : $text;
            }
            return implode($type instanceof UnionType ? '|' : '&', $members);
        }
        return null;
    }
}
