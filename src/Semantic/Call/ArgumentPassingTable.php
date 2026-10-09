<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Call;

use Atatusoft\Ppphp\Semantic\Call\Enumerations\ArgumentPassingMode;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Param;
use PhpParser\Node\VariadicPlaceholder;

final class ArgumentPassingTable
{
    /** @var \WeakMap<Arg, ArgumentPassingMode> */
    private \WeakMap $entries;

    public function __construct()
    {
        $this->entries = new \WeakMap();
    }

    public function record(Arg $argument, ArgumentPassingMode $mode): void
    {
        // A site visited with different flow states must not acquire the
        // calling convention of whichever state happened to be checked last.
        $previous = $this->entries[$argument] ?? $mode;
        $this->entries[$argument] = $previous === $mode ? $mode : ArgumentPassingMode::Unknown;
    }

    public function resolve(Arg $argument): ArgumentPassingMode
    {
        return $this->entries[$argument] ?? ArgumentPassingMode::Unknown;
    }

    /**
     * Records binding facts from an anonymous callable's actual source signature.
     * No nominal callable contract or parameter type is invented.
     * @param array<Param> $parameters
     * @param array<Arg|VariadicPlaceholder> $arguments
     */
    public function recordParameters(array $parameters, array $arguments): void
    {
        $parameters = array_values($parameters);
        $named = [];
        $variadic = null;
        foreach ($parameters as $parameter) {
            if ($parameter->var instanceof Variable && is_string($parameter->var->name)) {
                $named[$parameter->var->name] = $parameter;
            }
            if ($parameter->variadic) {
                $variadic = $parameter;
            }
        }
        $position = 0;
        foreach ($arguments as $argument) {
            if (!$argument instanceof Arg || $argument->unpack) {
                $position = null;
                continue;
            }
            $parameter = $argument->name !== null
                ? ($named[$argument->name->toString()] ?? $variadic)
                : ($position === null ? null : ($parameters[$position++] ?? $variadic));
            $this->record($argument, $parameter === null ? ArgumentPassingMode::Unknown
                : ($parameter->byRef ? ArgumentPassingMode::Reference : ArgumentPassingMode::Value));
        }
    }
}
