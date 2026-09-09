<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Cli;

use Symfony\Component\Console\Exception\InvalidArgumentException;

/** Native command policy; embedded and browser runtimes own their memory limits. */
final readonly class CompilerMemoryLimit
{
    public const string ENVIRONMENT_VARIABLE = 'PPPHP_COMPILER_MEMORY_LIMIT_MEGABYTES';
    public const int DEFAULT_MEGABYTES = 512;
    public const int MAXIMUM_MEGABYTES = 2_147_483_647;

    public function apply(): void
    {
        $configured = getenv(self::ENVIRONMENT_VARIABLE);
        $current = ini_get('memory_limit');
        $limit = $this->resolve($configured, $current);

        if (@ini_set('memory_limit', $limit) === false) {
            throw new InvalidArgumentException(sprintf(
                'The compiler could not apply memory limit %s. Set %s to a limit large enough for the running process and permitted by your PHP configuration.',
                $limit,
                self::ENVIRONMENT_VARIABLE,
            ));
        }
    }

    public function resolve(string|false $configured, string $current): string
    {
        if ($configured !== false) {
            if (preg_match('/^[1-9][0-9]*$/D', $configured) !== 1
                || strlen($configured) > 10
                || (int) $configured > self::MAXIMUM_MEGABYTES) {
                throw new InvalidArgumentException(sprintf(
                    '%s must be a whole number of MiB between 1 and %d.',
                    self::ENVIRONMENT_VARIABLE,
                    self::MAXIMUM_MEGABYTES,
                ));
            }

            return $configured . 'M';
        }

        // Keep a more generous host allowance; only an explicit compiler setting lowers it.
        $bytes = ini_parse_quantity($current);

        return $bytes < 0 || $bytes >= self::DEFAULT_MEGABYTES * 1_048_576
            ? $current
            : self::DEFAULT_MEGABYTES . 'M';
    }
}
