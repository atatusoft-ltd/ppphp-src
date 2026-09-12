<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\PhpStan;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Analyser\CollectedDataEmitter;
use PHPStan\Analyser\NodeCallbackInvoker;
use PHPStan\Collectors\Collector;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\Rules\Rule;

/** @implements Collector<Node\Stmt, list<array{offset: int, name: string}>> */
final readonly class GeneratedAnnotationCollector implements Collector
{
    /** @param Rule<Node\Stmt> $rule The pinned backend's native variable-tag rule.
     * @param array<string, array<int, array{owner: int, names: list<string>}>> $origins
     */
    public function __construct(
        private Rule $rule,
        private PhpDocParser $phpDocParser,
        private Lexer $lexer,
        private array $origins,
    ) {}

    public function getNodeType(): string
    {
        return Node\Stmt::class;
    }

    public function processNode(Node $node, Scope $scope): ?array
    {
        if (!$scope instanceof NodeCallbackInvoker || !$scope instanceof CollectedDataEmitter) {
            throw new \LogicException('Annotation decisions require the backend rule scope.');
        }
        $omissions = [];
        foreach ($node->getComments() as $comment) {
            if (!$comment instanceof Doc || !isset($this->origins[$scope->getFile()][$comment->getStartFilePos()])) {
                continue;
            }
            // Evaluate each generated tag through the pinned backend's actual
            // rule. A line or shared comment can also contain valid siblings.
            $phpDoc = $this->phpDocParser->parse(new TokenIterator($this->lexer->tokenize($comment->getText())));
            foreach ($phpDoc->getVarTagValues() as $tag) {
                if ($tag->variableName === '') {
                    continue;
                }
                $probe = clone $node;
                $probe->setAttribute('comments', [new Doc('/** @var ' . $tag . ' */')]);
                foreach ($this->rule->processNode($probe, $scope) as $error) {
                    if (in_array($error->getIdentifier(), ['varTag.nativeType', 'varTag.type', 'varTag.variableNotFound'], true)) {
                        $omissions[] = ['offset' => $comment->getStartFilePos(), 'name' => $tag->variableName];
                        break;
                    }
                }
            }
        }
        return $omissions === [] ? null : $omissions;
    }
}
