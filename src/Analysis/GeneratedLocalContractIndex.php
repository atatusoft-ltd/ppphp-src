<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis;

use Atatusoft\Ppphp\Semantic\SemanticModel;
use Atatusoft\Ppphp\Transpilation\GeneratedPhp;

/** Maps declared storage contracts, independently of PHPDoc assertion text. */
final class GeneratedLocalContractIndex
{
    /** @return array<int, array{name: string, type: string, initializer: bool}> */
    public function collect(GeneratedPhp $generated, SemanticModel $model): array
    {
        $writes = [];
        foreach ($model->bindings->bindings as $binding) {
            foreach ([$binding->variableSpan, ...$binding->writes] as $span) {
                $writes[$span->start->offset] = [
                    'name' => ltrim($binding->name, '$'),
                    'type' => $binding->type->semanticType->renderPhpDoc(),
                    'initializer' => $span->start->offset === $binding->variableSpan->start->offset,
                ];
            }
        }
        foreach ($model->bindings->writeContracts as $write) {
            $writes[$write['span']->start->offset] = [
                'name' => ltrim($write['name'], '$'),
                'type' => $write['type']->semanticType->renderPhpDoc(),
                'initializer' => false,
            ];
        }
        $contracts = [];
        foreach ($generated->sourceMap->segments as $segment) {
            foreach ($writes as $offset => $contract) {
                if ($offset < $segment->originalStart || $offset >= $segment->originalEnd) {
                    continue;
                }
                $name = '$' . $contract['name'];
                if ($segment->owner !== null && ($segment->originalStart !== $offset
                    || $segment->originalEnd - $offset !== strlen($name)
                    || $segment->generatedEnd - $segment->generatedStart !== strlen($name))) {
                    continue;
                }
                $position = $segment->generatedStart + ($segment->owner === null ? $offset - $segment->originalStart : 0);
                if (substr($generated->contents, $position, strlen($name)) === $name) {
                    $contracts[$position] = $contract;
                }
            }
        }
        ksort($contracts);
        return $contracts;
    }
}
