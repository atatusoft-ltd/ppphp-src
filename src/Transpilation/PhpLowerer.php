<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Transpilation;

use Atatusoft\Ppphp\Frontend\ParsedFile;
use Atatusoft\Ppphp\Semantic\SemanticModel;
use Atatusoft\Ppphp\Transpilation\Pass\EraseGenericTypesPass;
use Atatusoft\Ppphp\Transpilation\Pass\EnsureStrictTypesDeclarationPass;
use Atatusoft\Ppphp\Transpilation\Pass\Interfaces\TranspilationPass;
use Atatusoft\Ppphp\Transpilation\Pass\LowerLocalDeclarationsPass;
use Atatusoft\Ppphp\Transpilation\Pass\LowerLoopDeclarationsPass;
use Atatusoft\Ppphp\Transpilation\Pass\LowerWhenExpressionsPass;

final readonly class PhpLowerer
{
    /** @var list<TranspilationPass> */
    private array $passes;

    /** @param list<TranspilationPass>|null $passes */
    public function __construct(?array $passes = null)
    {
        $this->passes = $passes ?? [
            new EnsureStrictTypesDeclarationPass(),
            new LowerLocalDeclarationsPass(),
            new LowerLoopDeclarationsPass(),
            new EraseGenericTypesPass(),
            new LowerWhenExpressionsPass(),
        ];
    }

    /** @param list<TranspilationPass> $productionPasses
     * @param array<int, list<string>> $localAnnotationOmissions
     */
    public function lower(
        ParsedFile $parsedFile,
        SemanticModel $semanticModel,
        array $productionPasses = [],
        array $localAnnotationOmissions = [],
    ): GeneratedPhp
    {
        if ($semanticModel->parsedFile !== $parsedFile) {
            throw new \InvalidArgumentException('The semantic model must belong to the file being lowered.');
        }

        if (!$semanticModel->isSuccessful) {
            throw new \LogicException('A file with semantic errors cannot be lowered.');
        }

        $context = new TranspilationContext($parsedFile, $semanticModel, $localAnnotationOmissions);

        foreach ($this->passes as $pass) {
            $pass->execute($context);
        }

        foreach ($productionPasses as $pass) {
            $pass->execute($context);
        }

        return $context->generate();
    }
}
