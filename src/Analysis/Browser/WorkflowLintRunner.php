<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Browser;

use Atatusoft\Ppphp\Compiler\Validation\Interfaces\PhpLintRunner;
use Atatusoft\Ppphp\Compiler\Validation\PhpLintResult;

/** Consumes a complete, already bound observation set, checking staged bytes again. */
final class WorkflowLintRunner implements PhpLintRunner
{
    /** @param array<string, array{hash: string, result: WorkflowProcessResult, original: string}> $records */
    public function __construct(private array $records, private readonly string $outputParent) {}

    public function run(string $path, float $timeoutSeconds): PhpLintResult
    {
        if (!str_starts_with($path, $this->outputParent . '/.ppphp-stage-')) {
            throw new \InvalidArgumentException('Lint candidate is outside the compiler stage.');
        }
        $relative = substr($path, strlen($this->outputParent) + 1);
        $separator = strpos($relative, '/');
        $record = $separator === false ? null : ($this->records[substr($relative, $separator + 1)] ?? null);
        if ($record === null || is_link($path) || !is_file($path)
            || ProtocolJson::hash((string) file_get_contents($path)) !== $record['hash']) {
            throw new \InvalidArgumentException('Lint evidence does not match the candidate bytes.');
        }
        $result = $record['result'];
        $failure = $result->executionFailure;
        if (!$result->complete || $result->outputLimitExceeded) {
            $failure = 'PHP lint did not produce a complete bounded result.';
        } elseif ($result->exitCode === 0 && !$this->validateSuccessOutput($result, $record['original'])) {
            $failure = 'PHP lint success output is missing or malformed.';
        }
        return new PhpLintResult($result->exitCode, $result->stdout, $result->stderr, $result->timedOut, $failure);
    }

    private function validateSuccessOutput(WorkflowProcessResult $result, string $path): bool
    {
        $lines = static fn (string $output): array => array_values(array_filter(
            explode("\n", str_replace("\r\n", "\n", $output)), static fn (string $line): bool => $line !== '',
        ));
        $stdout = $lines($result->stdout);
        if (array_pop($stdout) !== 'No syntax errors detected in ' . $path) {
            return false;
        }
        // Native Build accepts nonfatal lint notices. Preserve that behavior,
        // while requiring recognized notices for this exact candidate path.
        foreach ([...$stdout, ...$lines($result->stderr)] as $line) {
            $prefix = false;
            foreach (['Warning: ', 'Deprecated: ', 'Notice: ', 'PHP Warning: ', 'PHP Deprecated: ', 'PHP Notice: '] as $severity) {
                $prefix = $prefix || str_starts_with($line, $severity);
            }
            $suffix = ' in ' . $path . ' on line ';
            $position = strrpos($line, $suffix);
            $number = $position === false ? '' : substr($line, $position + strlen($suffix));
            if (!$prefix || $number === '' || !ctype_digit($number) || $number[0] === '0') {
                return false;
            }
        }
        return true;
    }
}
