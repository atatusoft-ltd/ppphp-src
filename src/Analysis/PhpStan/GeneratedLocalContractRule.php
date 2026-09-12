<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PHPStan\Analyser\Scope;
use PHPStan\Analyser\NameScope;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Rules\RuleLevelHelper;
use PHPStan\Type\Generic\TemplateTypeMap;
use PHPStan\Type\VerbosityLevel;

/** Checks erased storage contracts before PHPDoc can assert the assigned type.
 * @implements Rule<Expr\Assign>
 */
final readonly class GeneratedLocalContractRule implements Rule
{
    /** @param array<string, array<int, array{name: string, type: string, initializer: bool}>> $contracts */
    public function __construct(
        private array $contracts,
        private RuleLevelHelper $ruleLevelHelper,
        private TypeStringResolver $typeResolver,
    ) {}

    public function getNodeType(): string
    {
        return Expr\Assign::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->var instanceof Expr\Variable || !is_string($node->var->name)) {
            return [];
        }
        $contract = $this->contracts[$scope->getFile()][$node->var->getStartFilePos()] ?? null;
        if ($contract === null || $contract['name'] !== $node->var->name) {
            return [];
        }
        $class = $scope->isInClass() ? $scope->getClassReflection() : null;
        $function = $scope->getFunction();
        $templates = array_replace(
            $class?->getActiveTemplateTypeMap()->getTypes() ?? [],
            $function?->getVariants()[0]->getTemplateTypeMap()->getTypes() ?? [],
            $scope->getAnonymousFunctionReflection()?->getTemplateTypeMap()->getTypes() ?? [],
        );
        $names = new NameScope(null, [], $class?->getName(), $function?->getName(), new TemplateTypeMap($templates));
        $expected = $this->typeResolver->resolve($contract['type'], $names);
        $actual = $scope->getType($node->expr);
        if ($this->ruleLevelHelper->accepts($expected, $actual, true)->result) {
            return [];
        }
        $verbosity = VerbosityLevel::getRecommendedLevelByType($actual, $expected);
        return [RuleErrorBuilder::message(sprintf(
            'Value of type %s is not assignable to $%s of type %s.',
            $actual->describe($verbosity), $contract['name'], $expected->describe($verbosity),
        ))->identifier($contract['initializer'] ? 'ppphp.initializerType' : 'ppphp.assignmentType')
            ->line($node->expr->getStartLine())->build()];
    }
}
