<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic;

use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Frontend\ParsedFile;
use Atatusoft\Ppphp\Semantic\Binding\BindingTable;
use Atatusoft\Ppphp\Semantic\Call\ArgumentPassingTable;
use Atatusoft\Ppphp\Semantic\Effect\CallableErrorIndex;
use Atatusoft\Ppphp\Semantic\Type\ExpressionTypeTable;
use Atatusoft\Ppphp\Semantic\When\WhenExpressionIndex;

final class SemanticModel
{
    public function __construct(
        public readonly ParsedFile $parsedFile,
        public readonly BindingTable $bindings,
        public readonly DiagnosticBag $diagnostics,
        public readonly CallableErrorIndex $errorContracts,
        public readonly WhenExpressionIndex $whenExpressions = new WhenExpressionIndex(),
        public readonly ExpressionTypeTable $expressionTypes = new ExpressionTypeTable(),
        public readonly ArgumentPassingTable $argumentPassing = new ArgumentPassingTable(),
    ) {}

    public bool $isSuccessful {
        get => !$this->diagnostics->hasErrors;
    }
}
