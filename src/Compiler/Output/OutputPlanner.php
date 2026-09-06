<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Compiler\Output;

use Atatusoft\Ppphp\Compiler\Output\Enumerations\OutputOperation;
use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Project\Project;
use Atatusoft\Ppphp\Project\ProjectSource;
use Atatusoft\Ppphp\Project\SourceSet;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Support\Path;

final readonly class OutputPlanner
{
    public function __construct(private OutputPathResolver $resolver = new OutputPathResolver()) {}

    public function plan(Project $project, SourceSet $outputSources): OutputPlanResult
    {
        $diagnostics = new DiagnosticBag();
        /** @var array<string, array{path: string, sources: list<ProjectSource>}> $outputs */
        $outputs = [];

        foreach ($project->sources as $source) {
            $relative = $this->resolver->resolveRelative($source);
            $key = strtolower(Path::normalize($relative));
            $outputs[$key] ??= ['path' => $relative, 'sources' => []];
            $outputs[$key]['sources'][] = $source;
        }

        ksort($outputs, SORT_STRING);

        foreach ($outputs as $output) {
            if (count($output['sources']) < 2 || !$this->containsSelected($output['sources'], $outputSources)) {
                continue;
            }

            $paths = array_map(
                static fn (ProjectSource $source): string => $source->displayPath,
                $output['sources'],
            );
            sort($paths, SORT_STRING);
            $diagnostics->add(new Diagnostic(
                DiagnosticCode::OutputPathCollision,
                sprintf(
                    'The sources %s all map to "%s".',
                    implode(', ', array_map(static fn (string $path): string => '"' . $path . '"', $paths)),
                    Path::join(Path::resolveRelativeTo($project->configuration->outputPath, $project->configuration->projectRoot), $output['path']),
                ),
                help: 'Change the source-root layout so every project source has a unique build output path.',
            ));
        }

        foreach ($outputSources as $source) {
            $relative = $this->resolver->resolveRelative($source);
            $firstSegment = explode('/', Path::normalize($relative))[0];

            if (strcasecmp($firstSegment, '.ppphp') !== 0) {
                continue;
            }

            $diagnostics->add(new Diagnostic(
                DiagnosticCode::OutputPathIsReserved,
                sprintf('Source "%s" maps into the reserved .ppphp build metadata directory.', $source->displayPath),
                help: 'Rename or move the source so its output does not begin with .ppphp/.',
            ));
        }

        if ($diagnostics->hasErrors) {
            return new OutputPlanResult(null, $diagnostics);
        }

        $entries = [];

        foreach ($outputSources as $source) {
            $relative = $this->resolver->resolveRelative($source);
            $entries[] = new OutputPlanEntry(
                $source,
                $this->resolver->resolve($project->configuration, $source),
                $relative,
                $source->kind === FileKind::Ppphp ? OutputOperation::Compile : OutputOperation::Copy,
            );
        }

        usort($entries, static fn (OutputPlanEntry $left, OutputPlanEntry $right): int =>
            (strtolower(Path::normalize($left->relativeOutputPath)) <=> strtolower(Path::normalize($right->relativeOutputPath)))
            ?: ($left->source->displayPath <=> $right->source->displayPath));

        return new OutputPlanResult(new OutputPlan($entries), $diagnostics);
    }

    /** @param list<ProjectSource> $sources */
    private function containsSelected(array $sources, SourceSet $selected): bool
    {
        foreach ($sources as $source) {
            if ($selected->contains($source)) {
                return true;
            }
        }

        return false;
    }
}
