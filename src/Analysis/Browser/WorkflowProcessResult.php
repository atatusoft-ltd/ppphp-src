<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Browser;

final readonly class WorkflowProcessResult
{
    public function __construct(
        public string $invocation,
        public string $stdout,
        public string $stderr,
        public ?int $exitCode,
        public bool $complete,
        public bool $timedOut,
        public bool $outputLimitExceeded,
        public ?string $executionFailure,
    ) {}

    public static function decode(mixed $value): self
    {
        $data = WorkflowValues::readObject($value, ['invocation', 'stdout', 'stderr', 'exitCode', 'complete', 'timedOut', 'outputLimitExceeded', 'executionFailure']);
        $stdout = WorkflowValues::readString($data['stdout'], 2_097_152);
        $stderr = WorkflowValues::readString($data['stderr'], 2_097_152);
        if (strlen($stdout) + strlen($stderr) > 2_097_152) {
            throw new \InvalidArgumentException('Workflow process output exceeds its combined limit.');
        }
        return new self(
            WorkflowValues::readHash($data['invocation']), $stdout, $stderr,
            $data['exitCode'] === null ? null : WorkflowValues::readInteger($data['exitCode'], -1, 255),
            WorkflowValues::readBoolean($data['complete']), WorkflowValues::readBoolean($data['timedOut']),
            WorkflowValues::readBoolean($data['outputLimitExceeded']),
            $data['executionFailure'] === null ? null : WorkflowValues::readString($data['executionFailure'], 8192),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['invocation' => $this->invocation, 'stdout' => $this->stdout, 'stderr' => $this->stderr,
            'exitCode' => $this->exitCode, 'complete' => $this->complete, 'timedOut' => $this->timedOut,
            'outputLimitExceeded' => $this->outputLimitExceeded, 'executionFailure' => $this->executionFailure];
    }
}
