<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Browser;

final readonly class WorkflowRequestDecoder
{
    public function decode(string $json): WorkflowRequest
    {
        if (strlen($json) > WorkflowRequest::MAXIMUM_BYTES) {
            throw new \InvalidArgumentException('The workflow request exceeds its byte limit.');
        }
        try {
            $data = WorkflowJson::decode($json);
        } catch (\JsonException $error) {
            throw new \InvalidArgumentException('The workflow request must be valid JSON.', previous: $error);
        }
        $action = is_array($data) ? ($data['action'] ?? null) : null;
        $data = WorkflowValues::readObject($data, $action === 'start'
            ? ['version', 'action', 'operationId', 'sequence', 'operation', 'selection', 'runtime']
            : ['version', 'action', 'operationId', 'sequence', 'continuation', 'results']);
        if ($data['version'] !== WorkflowRequest::VERSION || !in_array($action, ['start', 'complete-analysis', 'complete-lint', 'abort'], true)) {
            throw new \InvalidArgumentException('Unsupported workflow version or action.');
        }
        $id = WorkflowValues::readId($data['operationId']);
        $sequence = WorkflowValues::readInteger($data['sequence'], 1);
        if ($action === 'start') {
            if (!in_array($data['operation'], ['check', 'build'], true)) {
                throw new \InvalidArgumentException('Workflow operation must be check or build.');
            }
            $selection = WorkflowValues::readObject($data['selection'], ['path']);
            $runtime = WorkflowValues::readObject($data['runtime'], ['phpVersion', 'sapi', 'intSize', 'artifact', 'loader']);
            $runtime = [
                'phpVersion' => WorkflowValues::readString($runtime['phpVersion'], 32),
                'sapi' => WorkflowValues::readString($runtime['sapi'], 32),
                'intSize' => WorkflowValues::readInteger($runtime['intSize'], 4, 8),
                'artifact' => WorkflowValues::readHash($runtime['artifact']),
                'loader' => WorkflowValues::readHash($runtime['loader']),
            ];
            return new WorkflowRequest($action, $id, $sequence, $data['operation'],
                $selection['path'] === null ? null : WorkflowValues::readPath($selection['path']), $runtime);
        }
        // Associative decoding erases the distinction between [] and {}, and
        // between a list and an object whose keys happen to be numeric strings.
        $shape = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
        if (!$shape instanceof \stdClass || !is_array($shape->results ?? null)) {
            throw new \InvalidArgumentException('Workflow results must be a JSON array.');
        }
        $results = array_map(WorkflowProcessResult::decode(...), WorkflowValues::readList($data['results']));
        $ids = array_map(static fn (WorkflowProcessResult $result): string => $result->invocation, $results);
        if (count(array_unique($ids)) !== count($ids) || ($action === 'abort' && $results !== [])) {
            throw new \InvalidArgumentException('Duplicate or inappropriate workflow results.');
        }
        return new WorkflowRequest($action, $id, $sequence, continuation: WorkflowValues::readHash($data['continuation']), results: $results);
    }
}
