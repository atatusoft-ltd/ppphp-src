<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Transpilation\Pass;

use Atatusoft\Ppphp\Transpilation\Pass\Interfaces\TranspilationPass;
use Atatusoft\Ppphp\Transpilation\PhpDocEmitter;
use Atatusoft\Ppphp\Transpilation\TranspilationContext;
use Atatusoft\Ppphp\Transpilation\ThrowsClauseEraser;

final readonly class EraseThrowsClausesPass implements TranspilationPass
{
    public function __construct(private PhpDocEmitter $phpDoc = new PhpDocEmitter()) {}

    public function execute(TranspilationContext $context): void
    {
        foreach ($context->parsedFile->extensionSyntax->throwsClauses as $clause) {
            $contract = $context->semanticModel->errorContracts->find(
                $context->parsedFile->sourceFile,
                $clause,
            );

            if ($contract === null) {
                throw new \LogicException(sprintf(
                    'The throws clause owned by %s has no validated semantic contract.',
                    $clause->ownerNameSpan->text,
                ));
            }

            $this->phpDoc->emit($context, $clause, $contract);
            (new ThrowsClauseEraser())->erase($clause, $context);
        }
    }
}
