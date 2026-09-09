<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Browser;

final readonly class WorkflowRequest
{
    public const int VERSION = 3;
    public const int MAXIMUM_BYTES = 2_162_688;

    /**
     * @param array<string, mixed>|null $runtime
     * @param list<WorkflowProcessResult> $results
     */
    public function __construct(
        public string $action,
        public string $operationId,
        public int $sequence,
        public ?string $operation = null,
        public ?string $path = null,
        public ?array $runtime = null,
        public ?string $continuation = null,
        public array $results = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = ['version' => self::VERSION, 'action' => $this->action, 'operationId' => $this->operationId, 'sequence' => $this->sequence];
        return $this->action === 'start'
            ? [...$data, 'operation' => $this->operation, 'selection' => ['path' => $this->path], 'runtime' => $this->runtime]
            : [...$data, 'continuation' => $this->continuation, 'results' => array_map(static fn (WorkflowProcessResult $result): array => $result->toArray(), $this->results)];
    }
}
