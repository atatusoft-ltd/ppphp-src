<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Browser;

/** Only identities and bounded process observations persist, never compiler models. */
final class WorkflowSession
{
    /**
     * @param list<string> $invocations
     * @param list<string> $usedIds
     * @param array<string, mixed>|null $previousPublication
     */
    public function __construct(
        public readonly WorkflowRequest $start,
        public readonly string $snapshot,
        public readonly string $previousOutput,
        public readonly string $compiler,
        public string $phase = 'analysis',
        public array $invocations = [],
        public ?WorkflowProcessResult $analysis = null,
        public ?string $candidate = null,
        public array $usedIds = [],
        public readonly ?array $previousPublication = null,
    ) {}

    public string $continuation {
        get => ProtocolJson::hash(ProtocolJson::encodeCanonical($this->toArray()));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['start' => $this->start->toArray(), 'snapshot' => $this->snapshot, 'previousOutput' => $this->previousOutput,
            'compiler' => $this->compiler, 'phase' => $this->phase, 'invocations' => $this->invocations,
            'analysis' => $this->analysis?->toArray(), 'candidate' => $this->candidate, 'usedIds' => $this->usedIds,
            'previousPublication' => $this->previousPublication];
    }

    public static function decode(mixed $value): self
    {
        $data = WorkflowValues::readObject($value, ['start', 'snapshot', 'previousOutput', 'compiler', 'phase', 'invocations', 'analysis', 'candidate', 'usedIds', 'previousPublication']);
        $start = (new WorkflowRequestDecoder())->decode(json_encode($data['start'], JSON_THROW_ON_ERROR));
        if ($start->action !== 'start' || !in_array($data['phase'], ['analysis', 'lint', 'terminal'], true)) {
            throw new \InvalidArgumentException('Invalid saved workflow phase.');
        }
        return new self($start, WorkflowValues::readHash($data['snapshot']), WorkflowValues::readHash($data['previousOutput']),
            WorkflowValues::readHash($data['compiler']), $data['phase'],
            array_map(WorkflowValues::readHash(...), WorkflowValues::readList($data['invocations'])),
            $data['analysis'] === null ? null : WorkflowProcessResult::decode($data['analysis']),
            $data['candidate'] === null ? null : WorkflowValues::readHash($data['candidate']),
            array_map(WorkflowValues::readId(...), WorkflowValues::readList($data['usedIds'], 256)),
            $data['previousPublication'] === null ? null : self::decodePublication($data['previousPublication']));
    }

    /** @return array{snapshot: string, operationId: string, sequence: int, tree: string, manifestHash: string} */
    public static function decodePublication(mixed $value): array
    {
        $data = WorkflowValues::readObject($value, ['snapshot', 'operationId', 'sequence', 'tree', 'manifestHash']);
        return ['snapshot' => WorkflowValues::readHash($data['snapshot']), 'operationId' => WorkflowValues::readId($data['operationId']),
            'sequence' => WorkflowValues::readInteger($data['sequence'], 1), 'tree' => WorkflowValues::readHash($data['tree']),
            'manifestHash' => WorkflowValues::readHash($data['manifestHash'])];
    }
}
