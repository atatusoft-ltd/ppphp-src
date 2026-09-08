<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Binding;

use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\DiagnosticLabel;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Source\Span;

/** Error recovery only: never recorded in BindingTable or used as a declared type. */
final class RejectedLocalBinding
{
    public bool $hasSubsequentWrite = false;

    public function __construct(
        public readonly string $name,
        public readonly Span $span,
        private readonly string $help,
        private readonly ?DiagnosticLabel $evidence = null,
        private readonly bool $standalone = true,
    ) {}

    public function createDiagnostic(): Diagnostic
    {
        return new Diagnostic(
            DiagnosticCode::AssignmentCannotDeclareVariable,
            sprintf('The local variable %s is missing its explicit type.', $this->name),
            new DiagnosticLabel($this->span, $this->standalone
                ? sprintf('Add a type before %s.', $this->name)
                : sprintf('Declare %s before this expression.', $this->name)),
            $this->evidence === null ? [] : [$this->evidence],
            $this->hasSubsequentWrite && $this->standalone
                ? sprintf('Add an explicit type before %s. This local is written again; choose a type that accepts its initializer and subsequent values.', $this->name)
                : $this->help,
            identity: 'missing-local-type:' . $this->span->start->offset,
        );
    }
}
