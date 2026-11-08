<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Transpilation\Pass;

use Atatusoft\Ppphp\Interop\Composer\ComposerProject;
use Atatusoft\Ppphp\Semantic\NodeSpanResolver;
use Atatusoft\Ppphp\Support\Path;
use Atatusoft\Ppphp\Transpilation\Pass\Interfaces\TranspilationPass;
use Atatusoft\Ppphp\Transpilation\TranspilationContext;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;

/**
 * Keeps Composer bootstrap references valid when ++PHP source moves into its output tree.
 *
 * A ++PHP entry script may use the project-oriented Composer path familiar from source:
 *
 *     require_once __DIR__ . '/vendor/autoload.php';
 *
 * The emitted file can be nested beneath a configured output directory, so the production
 * expression is rebased against the actual Composer vendor directory. Other includes retain
 * ordinary PHP semantics and source-relative layout.
 */
final readonly class RelocateComposerAutoloadPass implements TranspilationPass
{
    private string $autoloadPath;

    public function __construct(
        private ComposerProject $composer,
        private string $outputPath,
        private NodeSpanResolver $spans = new NodeSpanResolver(),
    ) {
        $this->autoloadPath = Path::join($composer->vendorPath, 'autoload.php');

        if (!Path::isAbsolute($outputPath)) {
            throw new \InvalidArgumentException('A production output path must be absolute.');
        }
    }

    public function execute(TranspilationContext $context): void
    {
        foreach ($context->parsedFile->statements as $statement) {
            $this->relocateNode($statement, $context);
        }
    }

    private function relocateNode(Node $node, TranspilationContext $context): void
    {
        if (
            $node instanceof Expr\Include_
            && $this->referencesComposerAutoload($node->expr, $context->parsedFile->sourceFile->path)
        ) {
            $context->replace(
                $this->spans->resolve($context->parsedFile, $node->expr),
                $this->renderRuntimeExpression(),
            );

            return;
        }

        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->{$name};

            if ($value instanceof Node) {
                $this->relocateNode($value, $context);
            } elseif (is_array($value)) {
                foreach ($value as $child) {
                    if ($child instanceof Node) {
                        $this->relocateNode($child, $context);
                    }
                }
            }
        }
    }

    public function referencesComposerAutoload(Expr $expression, string $sourceFile): bool
    {
        $sourcePath = $this->resolveSourcePath($expression, $sourceFile);

        if (
            $sourcePath !== null
            && Path::buildComparisonKey($sourcePath) === Path::buildComparisonKey($this->autoloadPath)
        ) {
            return true;
        }

        $projectTail = $this->resolveProjectOrientedTail($expression);

        if ($projectTail === null || !Path::contains($this->composer->projectRoot, $this->autoloadPath)) {
            return false;
        }

        $autoloadRelative = Path::resolveRelativeTo(
            $this->autoloadPath,
            $this->composer->projectRoot,
        );

        return Path::buildComparisonKey($projectTail) === Path::buildComparisonKey($autoloadRelative);
    }

    private function resolveSourcePath(Expr $expression, string $sourceFile): ?string
    {
        $value = $this->evaluateStaticExpression(
            $expression,
            dirname($sourceFile),
            $sourceFile,
        );

        if ($value === null) {
            return null;
        }

        return Path::isAbsolute($value)
            ? Path::normalize($value)
            : Path::resolveAbsolute($value, $this->composer->projectRoot);
    }

    private function evaluateStaticExpression(
        Expr $expression,
        string $sourceDirectory,
        string $sourceFile,
    ): ?string {
        if ($expression instanceof Scalar\String_) {
            return $expression->value;
        }

        if ($expression instanceof Scalar\MagicConst\Dir) {
            return $sourceDirectory;
        }

        if ($expression instanceof Scalar\MagicConst\File) {
            return $sourceFile;
        }

        if (!$expression instanceof Expr\BinaryOp\Concat) {
            return null;
        }

        $left = $this->evaluateStaticExpression($expression->left, $sourceDirectory, $sourceFile);
        $right = $this->evaluateStaticExpression($expression->right, $sourceDirectory, $sourceFile);

        return $left === null || $right === null ? null : $left . $right;
    }

    private function resolveProjectOrientedTail(Expr $expression): ?string
    {
        $parts = $this->flattenConcatenation($expression);

        if ($parts === null || !$parts[0] instanceof Scalar\MagicConst\Dir) {
            return null;
        }

        $tail = '';

        foreach (array_slice($parts, 1) as $part) {
            if (!$part instanceof Scalar\String_) {
                return null;
            }

            $tail .= $part->value;
        }

        return Path::normalize(ltrim($tail, '/\\'));
    }

    /** @return list<Expr>|null */
    private function flattenConcatenation(Expr $expression): ?array
    {
        if ($expression instanceof Expr\BinaryOp\Concat) {
            $left = $this->flattenConcatenation($expression->left);
            $right = $this->flattenConcatenation($expression->right);

            return $left === null || $right === null ? null : [...$left, ...$right];
        }

        if (
            $expression instanceof Scalar\String_
            || $expression instanceof Scalar\MagicConst\Dir
            || $expression instanceof Scalar\MagicConst\File
        ) {
            return [$expression];
        }

        return null;
    }

    private function renderRuntimeExpression(): string
    {
        $relative = Path::makeRelative(
            $this->autoloadPath,
            dirname($this->outputPath),
        );

        if ($relative === null) {
            return var_export($this->autoloadPath, true);
        }

        $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $relative);

        return "__DIR__ . '/{$escaped}'";
    }
}
