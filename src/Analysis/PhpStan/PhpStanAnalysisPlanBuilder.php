<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\PhpStan;

use Atatusoft\Ppphp\Analysis\AnalysisProject;
use Atatusoft\Ppphp\Analysis\PhpStan\Exceptions\PhpStanExecutionException;
use Atatusoft\Ppphp\Support\Path;
use Composer\InstalledVersions;

final readonly class PhpStanAnalysisPlanBuilder
{
    private string $compilerRoot;

    private bool $resolveComposerInstallation;

    public function __construct(?string $compilerRoot = null)
    {
        $this->compilerRoot = Path::normalize($compilerRoot ?? dirname(__DIR__, 3));
        $this->resolveComposerInstallation = $compilerRoot === null;
    }

    public function executablePath(): string
    {
        $bundled = Path::join($this->compilerRoot, 'vendor/phpstan/phpstan/phpstan');

        if (is_file($bundled) || !$this->resolveComposerInstallation) {
            return $bundled;
        }

        try {
            $installPath = InstalledVersions::getInstallPath('phpstan/phpstan');
        } catch (\OutOfBoundsException) {
            $installPath = null;
        }

        return is_string($installPath) && $installPath !== ''
            ? Path::join($installPath, 'phpstan')
            : $bundled;
    }

    public function build(
        AnalysisProject $project,
        bool $debug = false,
        ?string $phpExecutable = null,
    ): PhpStanAnalysisPlan
    {
        $executable = $this->executablePath();

        if (!is_file($executable)) {
            throw new PhpStanExecutionException('The static analyzer required by the compiler is not installed.', help: 'Reinstall the compiler and its locked dependencies.');
        }

        $configuration = (new PhpStanConfigBuilder($this->compilerRoot))->build($project);
        $command = [
            $phpExecutable ?? PHP_BINARY,
            $executable,
            'analyse',
            '--configuration=' . $configuration,
            '--error-format=json',
            '--no-progress',
            '--memory-limit=' . ini_get('memory_limit'),
        ];

        if ($debug) {
            $command[] = '--debug';
        }

        return new PhpStanAnalysisPlan(
            $command,
            $project->workspaceRoot,
            $configuration,
            Path::join($project->workspaceRoot, 'result.json'),
        );
    }
}
