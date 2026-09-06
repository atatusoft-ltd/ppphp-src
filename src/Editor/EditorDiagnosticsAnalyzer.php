<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Editor;

use Atatusoft\Ppphp\Analysis\CompilerProjectAnalysis;
use Atatusoft\Ppphp\Analysis\CompilerProjectAnalyzer;
use Atatusoft\Ppphp\Editor\Exceptions\EditorDocumentNotOwned;
use Atatusoft\Ppphp\Project\FileDiscovery;
use Atatusoft\Ppphp\Project\Project;
use Atatusoft\Ppphp\Project\SourceSet;
use Atatusoft\Ppphp\Source\SourceFile;
use Atatusoft\Ppphp\Source\SourceManager;
use Atatusoft\Ppphp\Support\Path;

final readonly class EditorDiagnosticsAnalyzer
{
    public function __construct(
        private CompilerProjectAnalyzer $analyzer = new CompilerProjectAnalyzer(),
        private FileDiscovery $discovery = new FileDiscovery(),
    ) {}

    public function analyze(Project $project, EditorDiagnosticsRequest $request): CompilerProjectAnalysis
    {
        $manager = new SourceManager($project->configuration->projectRoot);
        $sources = $project->sources->files;
        $overlays = [];

        foreach ($request->documents as $index => $document) {
            $source = $this->discovery->resolveBufferSource($project->configuration, $document['path']);

            if ($source === null || (is_file($source->path) && !$project->sources->owns($source->path))) {
                if ($index === 0) {
                    throw new EditorDocumentNotOwned('The target is not a safe PHP or ++PHP source file within the configured source roots.');
                }

                // Editors may have vendor/output/stub documents open. They cannot supply source overlays.
                continue;
            }

            $key = Path::buildComparisonKey($source->path);

            if (isset($overlays[$key])) {
                throw new EditorDocumentNotOwned('Each editor document path must appear only once, including the target.');
            }

            $overlays[$key] = $source;
            $sources[] = $source;
            $manager->register(new SourceFile($source->path, $source->displayPath, $source->kind, $document['contents']));
        }

        $overlayProject = new Project(
            $project->configuration,
            new SourceSet($sources),
            $project->composer,
            $project->stubs,
            clone $project->dependencyGraph,
            $manager,
        );

        return $this->analyzer->analyze($overlayProject, new SourceSet([array_values($overlays)[0]]));
    }
}
