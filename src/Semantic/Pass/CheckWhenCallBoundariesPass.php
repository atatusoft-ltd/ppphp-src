<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Pass;

use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\DiagnosticLabel;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Semantic\Call\Enumerations\ArgumentPassingMode;
use Atatusoft\Ppphp\Semantic\NodeSpanResolver;
use Atatusoft\Ppphp\Semantic\Pass\Interfaces\SemanticPass;
use Atatusoft\Ppphp\Semantic\SemanticContext;
use Atatusoft\Ppphp\Semantic\When\WhenExpressionAnalysis;
use Atatusoft\Ppphp\Semantic\When\WhenExpressionIndex;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;

/** Checks only calls whose arguments cannot stay on PHP's expression stack. */
final readonly class CheckWhenCallBoundariesPass implements SemanticPass
{
    public function execute(SemanticContext $context): void
    {
        $expressions = $context->model->whenExpressions;
        if ($expressions->expressions === []) {
            return;
        }
        $roots = $context->parsedFile->statements;
        foreach ($expressions->expressions as $expression) {
            foreach ($expression->branches as $branch) {
                array_push($roots, ...$branch->statements);
                if ($branch->condition !== null) {
                    $roots[] = $branch->condition;
                }
            }
        }
        $reported = [];
        // Prefer the innermost blocked call when nested calls share a result.
        foreach (array_reverse((new NodeFinder())->findInstanceOf($roots, Expr\CallLike::class)) as $call) {
            $preceding = [];
            foreach ($call->getRawArgs() as $argument) {
                if (!$argument instanceof Arg) {
                    continue;
                }
                $when = $this->findStatementWhen($argument->value, $expressions);
                if ($when !== null && !isset($reported[$when->syntax->id->value])) {
                    foreach ([...$preceding, $argument] as $pending) {
                        $unpacked = $pending !== $argument && $pending->unpack;
                        $unknown = !$pending->unpack
                            && $context->model->argumentPassing->resolve($pending) === ArgumentPassingMode::Unknown;
                        if (!$unpacked && !$unknown) {
                            continue;
                        }
                        $message = $unpacked
                            ? 'This `when` needs statements, but an earlier unpacked argument must bind its elements before those statements run.'
                            : 'This `when` needs statements, but the compiler cannot determine whether this call passes an argument by value or by reference.';
                        $context->model->diagnostics->add(new Diagnostic(
                            DiagnosticCode::WhenPositionNotSupported,
                            $message,
                            new DiagnosticLabel($when->syntax->span, $message),
                            [new DiagnosticLabel((new NodeSpanResolver())->resolve($context->parsedFile, $pending),
                                $unpacked ? 'The earlier unpacked argument is here.' : 'The parameter binding for this argument is unresolved.')],
                            help: $unpacked
                                ? 'Use explicit arguments with a known parameter signature here, preserving their original values or references.'
                                : 'Call a function or method with an explicit parameter signature here, so its value and reference bindings are known.',
                        ));
                        $reported[$when->syntax->id->value] = true;
                        break;
                    }
                }
                $preceding[] = $argument;
            }
        }
    }

    private function findStatementWhen(Node $node, WhenExpressionIndex $expressions): ?WhenExpressionAnalysis
    {
        if ($node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike) {
            return null;
        }
        if ($node instanceof Expr && ($when = $expressions->findPlaceholder($node)) !== null) {
            $operands = $when->ternaryOperands;
            if ($operands === null) {
                return $when;
            }
            foreach ($operands as $operand) {
                if (($nested = $this->findStatementWhen($operand, $expressions)) !== null) {
                    return $nested;
                }
            }
            return null;
        }
        foreach ($node->getSubNodeNames() as $name) {
            foreach (is_array($node->$name) ? $node->$name : [$node->$name] as $child) {
                if ($child instanceof Node && ($when = $this->findStatementWhen($child, $expressions)) !== null) {
                    return $when;
                }
            }
        }
        return null;
    }
}
