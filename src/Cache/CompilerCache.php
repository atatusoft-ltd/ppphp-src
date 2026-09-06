<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Cache;

use Atatusoft\Ppphp\Analysis\CompilerProjectAnalysis;
use Atatusoft\Ppphp\Analysis\Enumerations\AnalysisCompleteness;
use Atatusoft\Ppphp\Compiler\CompilationArtifact;
use Atatusoft\Ppphp\Compiler\Compiler;
use Atatusoft\Ppphp\Compiler\Manifest\BuildManifest;
use Atatusoft\Ppphp\Compiler\Manifest\BuildManifestCodec;
use Atatusoft\Ppphp\Compiler\Manifest\ConfigurationFingerprint;
use Atatusoft\Ppphp\Compiler\Output\Enumerations\OutputOperation;
use Atatusoft\Ppphp\Compiler\Output\NativeBuildFilesystem;
use Atatusoft\Ppphp\Compiler\Output\OutputPlan;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Project\Enumerations\SelectionKind;
use Atatusoft\Ppphp\Project\Project;
use Atatusoft\Ppphp\Project\ProjectCheckResult;
use Atatusoft\Ppphp\Project\ProjectSelection;
use Atatusoft\Ppphp\Project\SourceSet;
use Atatusoft\Ppphp\Support\Path;
use Atatusoft\Ppphp\Transpilation\GeneratedSourceMap;
use Atatusoft\Ppphp\Transpilation\SourceMapWriter;

final readonly class CompilerCache
{
    public CacheStatistics $statistics;

    private ProjectInputSnapshotBuilder $snapshots;

    private CompilerBuildIdentity $buildIdentity;

    private ConfigurationFingerprint $fingerprints;

    public function __construct(
        ?ProjectInputSnapshotBuilder $snapshots = null,
        private CachedDiagnosticCodec $diagnostics = new CachedDiagnosticCodec(),
        private BuildManifestCodec $manifests = new BuildManifestCodec(),
        private SourceMapWriter $sourceMaps = new SourceMapWriter(),
        ?CompilerBuildIdentity $buildIdentity = null,
        ?ConfigurationFingerprint $fingerprints = null,
        ?CacheStatistics $statistics = null,
    ) {
        $this->buildIdentity = $buildIdentity ?? new CompilerBuildIdentity();
        $this->snapshots = $snapshots ?? new ProjectInputSnapshotBuilder($this->buildIdentity);
        $this->fingerprints = $fingerprints ?? new ConfigurationFingerprint(buildIdentity: $this->buildIdentity);
        $this->statistics = $statistics ?? new CacheStatistics();
    }

    public function snapshot(Project $project, SourceSet $selectedSources): ProjectInputSnapshot
    {
        return $this->snapshots->build($project, $selectedSources);
    }

    public function loadCheck(
        Project $project,
        SourceSet $selectedSources,
        ProjectInputSnapshot $snapshot,
    ): ?ProjectCheckResult {
        $store = $this->store($project);
        $coreKey = $snapshot->key('compiler-core-check');
        $core = $this->loadCompilerEvidence($store, $coreKey, $project, $snapshot);

        if ($core === null) {
            return null;
        }

        if (!$core['successful']) {
            $this->recordCompilerReuse($selectedSources, $core['semanticCompleted']);

            return $this->compilerEvidenceResult($core);
        }

        $supplementalKey = $snapshot->key('supplemental-check', $this->supplementalIdentity());

        try {
            $supplemental = $store->readRecord('supplemental', 'phpstan-check', $supplementalKey);

            if ($supplemental === null) {
                return null;
            }

            if (array_keys($supplemental) !== [
                'diagnosticFormatVersion',
                'diagnostics',
                'snapshotIdentity',
            ]
                || ($supplemental['diagnosticFormatVersion'] ?? null) !== CacheFormat::DIAGNOSTIC
                || ($supplemental['snapshotIdentity'] ?? null) !== $snapshot->identity) {
                throw new \RuntimeException('Cached supplemental evidence is malformed.');
            }

            $finalDiagnostics = $this->decodeDiagnostics($supplemental['diagnostics'] ?? null, $project);
            $this->recordCompilerReuse($selectedSources, true);
            $this->statistics->supplementalProcessesAvoided++;

            return new ProjectCheckResult(
                null,
                null,
                null,
                $finalDiagnostics,
                AnalysisCompleteness::Full,
                $core['uncovered'],
                true,
                true,
                $this->statistics,
            );
        } catch (\Throwable) {
            $this->statistics->corruptEntries++;
            $store->invalidateRecord('supplemental', 'phpstan-check', $supplementalKey);

            return null;
        }
    }

    public function loadCompilerCheck(
        Project $project,
        SourceSet $selectedSources,
        ProjectInputSnapshot $snapshot,
    ): ?ProjectCheckResult {
        $core = $this->loadCompilerEvidence(
            $this->store($project),
            $snapshot->key('compiler-core-check'),
            $project,
            $snapshot,
        );

        if ($core === null) {
            return null;
        }

        $this->recordCompilerReuse($selectedSources, $core['semanticCompleted']);

        return $this->compilerEvidenceResult($core);
    }

    public function storeCompilerAnalysis(
        Project $project,
        ProjectInputSnapshot $snapshot,
        CompilerProjectAnalysis $analysis,
    ): void {
        $encoded = $this->diagnostics->encode($analysis->diagnostics, $project);

        if ($encoded === null) {
            return;
        }

        $this->store($project)->writeRecord(
            'compiler',
            'compiler-check',
            $snapshot->key('compiler-core-check'),
            [
                'completeness' => $analysis->completeness->value,
                'contextIdentity' => $snapshot->key('declaration-context')->value,
                'diagnosticFormatVersion' => CacheFormat::DIAGNOSTIC,
                'diagnostics' => $encoded,
                'semanticCompleted' => $analysis->semanticResult !== null,
                'snapshotIdentity' => $snapshot->identity,
                'successful' => $analysis->isSuccessful,
                'uncoveredRequiredCapabilities' => $analysis->uncoveredRequiredCapabilities,
            ],
        );
    }

    public function storeSupplementalResult(
        Project $project,
        ProjectInputSnapshot $snapshot,
        ProjectCheckResult $result,
    ): void {
        if ($result->backendResult === null || !$this->isCacheableSupplementalResult($result->diagnostics)) {
            return;
        }

        $encoded = $this->diagnostics->encode($result->diagnostics, $project);

        if ($encoded === null) {
            return;
        }

        $this->store($project)->writeRecord(
            'supplemental',
            'phpstan-check',
            $snapshot->key('supplemental-check', $this->supplementalIdentity()),
            [
                'diagnosticFormatVersion' => CacheFormat::DIAGNOSTIC,
                'diagnostics' => $encoded,
                'snapshotIdentity' => $snapshot->identity,
            ],
        );
    }

    public function buildKey(
        ProjectInputSnapshot $snapshot,
        Project $project,
        ProjectSelection $selection,
    ): CacheKey {
        $selectedPath = $selection->selectedPath === null
            ? null
            : str_replace('\\', '/', Path::resolveRelativeTo(
                $selection->selectedPath,
                $project->configuration->projectRoot,
            ));

        return $snapshot->key('production-artifacts', [
            'outputSources' => array_map(
                static fn ($source): string => str_replace('\\', '/', $source->displayPath),
                $selection->outputSources->files,
            ),
            'selectedPath' => $selectedPath,
            'selectionKind' => $selection->kind->value,
        ]);
    }

    /** @param list<CompilationArtifact> $artifacts */
    public function storeArtifactBundle(
        Project $project,
        ProjectSelection $selection,
        ProjectInputSnapshot $snapshot,
        array $artifacts,
        BuildManifest $manifest,
        DiagnosticBag $diagnostics,
        ProjectCheckResult $check,
    ): void {
        $store = $this->store($project);
        $manifestContents = $this->manifests->serialize($manifest);
        $manifestBlob = $store->writeBlob($manifestContents);
        $encodedDiagnostics = $this->diagnostics->encode($diagnostics, $project);

        if ($manifestBlob === null || $encodedDiagnostics === null) {
            return;
        }

        $records = [];

        foreach ($artifacts as $artifact) {
            $contentBlob = $store->writeBlob($artifact->contents);
            $map = $this->sourceMaps->serialize($artifact);
            $mapBlob = $store->writeBlob($map);

            if ($contentBlob === null || $mapBlob === null) {
                return;
            }

            $records[] = [
                'compilerBuildIdentity' => $manifest->compilerBuildIdentity,
                'contentBlob' => $contentBlob,
                'fileMode' => $artifact->mode,
                'loweringFormatVersion' => Compiler::LOWERING_FORMAT_VERSION,
                'operation' => $artifact->operation->value,
                'outputHash' => $artifact->outputHash,
                'outputPath' => str_replace('\\', '/', $artifact->relativeOutputPath),
                'sourceHash' => $artifact->sourceHash,
                'sourceMapBlob' => $mapBlob,
                'sourceMapFormatVersion' => SourceMapWriter::FORMAT_VERSION,
                'sourcePath' => str_replace('\\', '/', $artifact->sourceFile->displayPath),
                'targetPhpVersion' => $project->configuration->targetPhpVersion,
            ];
        }

        $store->writeRecord(
            'compiler',
            'artifact-bundle',
            $this->buildKey($snapshot, $project, $selection),
            [
                'artifactFormatVersion' => CacheFormat::ARTIFACT,
                'artifacts' => $records,
                'compilerBuildIdentity' => $manifest->compilerBuildIdentity,
                'diagnostics' => $encodedDiagnostics,
                'loweringFormatVersion' => Compiler::LOWERING_FORMAT_VERSION,
                'manifestBlob' => $manifestBlob,
                'snapshotIdentity' => $snapshot->identity,
                'targetPhpVersion' => $project->configuration->targetPhpVersion,
            ],
        );

        $declarationIdentity = (new DeclarationFingerprint())->calculate(
            $project,
            $check,
            $store,
            $snapshot,
        );

        if ($declarationIdentity === null) {
            return;
        }

        foreach ($artifacts as $artifact) {
            $this->storeArtifactUnit($store, $snapshot, $project, $artifact, $declarationIdentity);
        }
    }

    public function loadArtifactBundle(
        Project $project,
        ProjectSelection $selection,
        ProjectInputSnapshot $snapshot,
    ): ?CachedArtifactBundle {
        $store = $this->store($project);
        $key = $this->buildKey($snapshot, $project, $selection);
        $record = $store->readRecord(
            'compiler',
            'artifact-bundle',
            $key,
        );

        if ($record === null) {
            return null;
        }

        try {
            if (array_keys($record) !== [
                'artifactFormatVersion',
                'artifacts',
                'compilerBuildIdentity',
                'diagnostics',
                'loweringFormatVersion',
                'manifestBlob',
                'snapshotIdentity',
                'targetPhpVersion',
            ]
                || ($record['artifactFormatVersion'] ?? null) !== CacheFormat::ARTIFACT
                || ($record['loweringFormatVersion'] ?? null) !== Compiler::LOWERING_FORMAT_VERSION
                || ($record['targetPhpVersion'] ?? null) !== $project->configuration->targetPhpVersion
                || ($record['snapshotIdentity'] ?? null) !== $snapshot->identity
                || ($record['compilerBuildIdentity'] ?? null) !== $this->buildIdentity->calculate()
                || !is_string($record['manifestBlob'] ?? null)
                || !is_array($record['artifacts'] ?? null)
                || !array_is_list($record['artifacts'])) {
                throw new \RuntimeException('The cached artifact bundle identity is invalid.');
            }

            $manifestContents = $store->readBlob($record['manifestBlob']);

            if ($manifestContents === null) {
                throw new \RuntimeException('The cached build manifest blob is unavailable.');
            }

            $manifest = $this->manifests->parse($manifestContents);

            if ($manifest->compilerName !== Compiler::NAME
                || $manifest->compilerVersion !== Compiler::VERSION
                || $manifest->compilerBuildIdentity !== $this->buildIdentity->calculate()
                || $manifest->loweringFormatVersion !== Compiler::LOWERING_FORMAT_VERSION
                || $manifest->targetPhpVersion !== $project->configuration->targetPhpVersion
                || $manifest->configurationFingerprint !== $this->fingerprints->calculate($project)
                || $record['compilerBuildIdentity'] !== $manifest->compilerBuildIdentity) {
                throw new \RuntimeException('The cached artifact producer identity is invalid.');
            }

            $sources = [];

            foreach ($project->sources as $source) {
                $sources[strtolower(str_replace('\\', '/', $source->displayPath))] = $source;
            }

            $artifacts = [];
            $expectedSources = [];
            $artifactSources = [];

            foreach ($selection->outputSources as $source) {
                $expectedSources[strtolower(str_replace('\\', '/', $source->displayPath))] = true;
            }

            foreach ($record['artifacts'] as $artifactRecord) {
                if (!is_array($artifactRecord)
                    || array_is_list($artifactRecord)
                    || array_keys($artifactRecord) !== [
                        'compilerBuildIdentity',
                        'contentBlob',
                        'fileMode',
                        'loweringFormatVersion',
                        'operation',
                        'outputHash',
                        'outputPath',
                        'sourceHash',
                        'sourceMapBlob',
                        'sourceMapFormatVersion',
                        'sourcePath',
                        'targetPhpVersion',
                    ]) {
                    throw new \RuntimeException('A cached artifact is malformed.');
                }

                foreach (['contentBlob', 'operation', 'outputHash', 'outputPath', 'sourceHash', 'sourceMapBlob', 'sourcePath'] as $field) {
                    if (!is_string($artifactRecord[$field] ?? null) || $artifactRecord[$field] === '') {
                        throw new \RuntimeException('A cached artifact field is invalid.');
                    }
                }

                $relativeOutput = Path::normalize($artifactRecord['outputPath']);

                if (Path::isAbsolute($relativeOutput)
                    || $relativeOutput === '..'
                    || str_starts_with($relativeOutput, '../')
                    || str_starts_with(strtolower($relativeOutput), '.ppphp/')) {
                    throw new \RuntimeException('A cached artifact output path is unsafe.');
                }

                $sourceKey = strtolower(str_replace('\\', '/', $artifactRecord['sourcePath']));
                $projectSource = $sources[$sourceKey] ?? null;
                $operation = OutputOperation::tryFrom($artifactRecord['operation']);
                $contents = $store->readBlob($artifactRecord['contentBlob']);
                $map = $store->readBlob($artifactRecord['sourceMapBlob']);
                $mode = $artifactRecord['fileMode'] ?? null;

                if ($projectSource === null
                    || !isset($expectedSources[$sourceKey])
                    || isset($artifactSources[$sourceKey])
                    || $operation === null
                    || $contents === null
                    || $map === null
                    || ($artifactRecord['compilerBuildIdentity'] ?? null) !== $this->buildIdentity->calculate()
                    || ($artifactRecord['loweringFormatVersion'] ?? null) !== Compiler::LOWERING_FORMAT_VERSION
                    || ($artifactRecord['sourceMapFormatVersion'] ?? null) !== SourceMapWriter::FORMAT_VERSION
                    || ($artifactRecord['targetPhpVersion'] ?? null) !== $project->configuration->targetPhpVersion
                    || ($mode !== null && (!is_int($mode) || $mode < 0 || $mode > 0777))) {
                    throw new \RuntimeException('A cached artifact cannot be reconstructed.');
                }

                $sourceFile = $project->sourceManager->load($projectSource->path, $projectSource->kind);
                $sourceHash = 'sha256:' . hash('sha256', $sourceFile->contents);
                $outputHash = 'sha256:' . hash('sha256', $contents);

                if (!hash_equals($artifactRecord['sourceHash'], $sourceHash)
                    || !hash_equals($artifactRecord['outputHash'], $outputHash)) {
                    throw new \RuntimeException('A cached artifact content hash is invalid.');
                }

                $this->sourceMaps->parseAndValidate(
                    $map,
                    $sourceFile->displayPath,
                    $relativeOutput,
                    $sourceHash,
                    $outputHash,
                    strlen($contents),
                );
                $artifact = new CompilationArtifact(
                    $projectSource,
                    $sourceFile,
                    $operation,
                    Path::join($project->configuration->outputPath, $relativeOutput),
                    $relativeOutput,
                    $contents,
                    new GeneratedSourceMap($sourceFile, strlen($contents), []),
                    $sourceHash,
                    $outputHash,
                    $mode,
                    $map,
                );
                $manifestEntry = $manifest->findBySource($sourceFile->displayPath);

                if ($manifestEntry === null
                    || $manifestEntry->output !== $artifact->relativeOutputPath
                    || $manifestEntry->sourceKind !== $artifact->projectSource->kind
                    || $manifestEntry->operation !== $artifact->operation
                    || $manifestEntry->sourceHash !== $artifact->sourceHash
                    || $manifestEntry->outputHash !== $artifact->outputHash
                    || $manifestEntry->sourceMap !== $artifact->sourceMapPath
                    || $manifestEntry->mode !== ($artifact->mode === null
                        ? null
                        : sprintf('%04o', $artifact->mode & 0777))) {
                    throw new \RuntimeException('A cached artifact does not match its build manifest.');
                }

                $artifactSources[$sourceKey] = true;
                $artifacts[] = $artifact;
            }

            if (count($artifactSources) !== count($expectedSources)
                || ($selection->kind === SelectionKind::Project
                    && (!$manifest->completeProject || count($artifacts) !== count($manifest->files)))) {
                throw new \RuntimeException('The cached artifact bundle is incomplete.');
            }

            $diagnostics = $this->decodeDiagnostics($record['diagnostics'] ?? null, $project);

            return new CachedArtifactBundle($artifacts, $manifest, $manifestContents, $diagnostics);
        } catch (\Throwable) {
            $this->statistics->corruptEntries++;
            $store->invalidateRecord('compiler', 'artifact-bundle', $key);

            return null;
        }
    }

    public function currentOutputIsValid(
        Project $project,
        ProjectSelection $selection,
        CachedArtifactBundle $bundle,
    ): bool
    {
        $output = $project->configuration->outputPath;

        if (!is_dir($output) || is_link($output)) {
            return false;
        }

        $manifestPath = Path::join($output, '.ppphp/manifest.json');

        if (!is_file($manifestPath) || is_link($manifestPath)) {
            return false;
        }

        $contents = file_get_contents($manifestPath);

        if (!is_string($contents) || !hash_equals($bundle->serializedManifest, $contents)) {
            return false;
        }

        try {
            if ($selection->kind === SelectionKind::Project) {
                $expectedFiles = ['.ppphp/manifest.json'];

                foreach ($bundle->manifest->files as $entry) {
                    $expectedFiles[] = Path::normalize($entry->output);
                    $expectedFiles[] = Path::normalize($entry->sourceMap);
                }

                $actualFiles = array_map(
                    static fn (string $path): string => Path::normalize($path),
                    (new NativeBuildFilesystem())->listFiles($output),
                );
                sort($expectedFiles, SORT_STRING);
                sort($actualFiles, SORT_STRING);

                if ($actualFiles !== $expectedFiles) {
                    return false;
                }
            }

            foreach ($bundle->manifest->files as $entry) {
                $artifactPath = Path::join($output, $entry->output);
                $mapPath = Path::join($output, $entry->sourceMap);

                if (!Path::contains($output, $artifactPath)
                    || !is_file($artifactPath)
                    || is_link($artifactPath)
                    || !is_file($mapPath)
                    || is_link($mapPath)) {
                    return false;
                }

                $artifact = file_get_contents($artifactPath);
                $map = file_get_contents($mapPath);

                if (!is_string($artifact)
                    || !is_string($map)
                    || !hash_equals($entry->outputHash, 'sha256:' . hash('sha256', $artifact))) {
                    return false;
                }

                $this->sourceMaps->parseAndValidate(
                    $map,
                    $entry->source,
                    $entry->output,
                    $entry->sourceHash,
                    $entry->outputHash,
                    strlen($artifact),
                );
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string, CompilationArtifact> */
    public function loadReusableArtifacts(
        Project $project,
        ProjectInputSnapshot $snapshot,
        ProjectCheckResult $check,
        OutputPlan $plan,
    ): array {
        if ($check->parseResult === null) {
            return [];
        }

        $declarationIdentity = (new DeclarationFingerprint())->calculate(
            $project,
            $check,
            $this->store($project),
            $snapshot,
        );

        if ($declarationIdentity === null) {
            return [];
        }

        $store = $this->store($project);
        $artifacts = [];

        foreach ($plan as $entry) {
            $sourceFile = $check->parseResult->findSourceFile($entry->source->path);

            if ($sourceFile === null) {
                continue;
            }

            $sourceHash = 'sha256:' . hash('sha256', $sourceFile->contents);
            $key = $this->artifactUnitKey(
                $snapshot,
                $entry->source->displayPath,
                $sourceHash,
                $entry->relativeOutputPath,
                $entry->operation,
                $declarationIdentity,
            );
            $record = $store->readRecord('compiler', 'artifact-unit', $key);

            if ($record === null) {
                continue;
            }

            try {
                if (array_keys($record) !== [
                    'artifactFormatVersion',
                    'compilerBuildIdentity',
                    'contentBlob',
                    'declarationIdentity',
                    'fileMode',
                    'loweringFormatVersion',
                    'operation',
                    'outputHash',
                    'outputPath',
                    'sourceHash',
                    'sourceMapBlob',
                    'sourceMapFormatVersion',
                    'sourcePath',
                    'targetPhpVersion',
                ]) {
                    throw new \RuntimeException('A cached artifact unit is malformed.');
                }

                foreach (['contentBlob', 'outputHash', 'sourceMapBlob'] as $field) {
                    if (!is_string($record[$field] ?? null)) {
                        throw new \RuntimeException('A cached artifact unit field is invalid.');
                    }
                }

                if (($record['artifactFormatVersion'] ?? null) !== CacheFormat::ARTIFACT
                    || ($record['compilerBuildIdentity'] ?? null) !== $this->buildIdentity->calculate()
                    || ($record['declarationIdentity'] ?? null) !== $declarationIdentity
                    || ($record['loweringFormatVersion'] ?? null) !== Compiler::LOWERING_FORMAT_VERSION
                    || ($record['operation'] ?? null) !== $entry->operation->value
                    || ($record['outputPath'] ?? null) !== str_replace('\\', '/', $entry->relativeOutputPath)
                    || ($record['sourceHash'] ?? null) !== $sourceHash
                    || ($record['sourceMapFormatVersion'] ?? null) !== SourceMapWriter::FORMAT_VERSION
                    || ($record['sourcePath'] ?? null) !== str_replace('\\', '/', $entry->source->displayPath)
                    || ($record['targetPhpVersion'] ?? null) !== $project->configuration->targetPhpVersion) {
                    throw new \RuntimeException('A cached artifact unit identity is invalid.');
                }

                $contents = $store->readBlob($record['contentBlob']);
                $map = $store->readBlob($record['sourceMapBlob']);
                $mode = $record['fileMode'] ?? null;

                if ($contents === null
                    || $map === null
                    || ($mode !== null && (!is_int($mode) || $mode < 0 || $mode > 0777))
                    || !hash_equals($record['outputHash'], 'sha256:' . hash('sha256', $contents))) {
                    throw new \RuntimeException('A cached artifact unit cannot be reconstructed.');
                }

                $this->sourceMaps->parseAndValidate(
                    $map,
                    $sourceFile->displayPath,
                    $entry->relativeOutputPath,
                    $sourceHash,
                    $record['outputHash'],
                    strlen($contents),
                );
                $artifacts[Path::buildComparisonKey($entry->source->path)] = new CompilationArtifact(
                    $entry->source,
                    $sourceFile,
                    $entry->operation,
                    $entry->outputPath,
                    $entry->relativeOutputPath,
                    $contents,
                    new GeneratedSourceMap($sourceFile, strlen($contents), []),
                    $sourceHash,
                    $record['outputHash'],
                    $mode,
                    $map,
                );
                $this->statistics->loweringWorkAvoided++;
            } catch (\Throwable) {
                $this->statistics->corruptEntries++;
                $store->invalidateRecord('compiler', 'artifact-unit', $key);
            }
        }

        return $artifacts;
    }

    private function storeArtifactUnit(
        CacheStore $store,
        ProjectInputSnapshot $snapshot,
        Project $project,
        CompilationArtifact $artifact,
        string $declarationIdentity,
    ): void {
        $contentBlob = $store->writeBlob($artifact->contents);
        $mapBlob = $store->writeBlob($this->sourceMaps->serialize($artifact));

        if ($contentBlob === null || $mapBlob === null) {
            return;
        }

        $store->writeRecord(
            'compiler',
            'artifact-unit',
            $this->artifactUnitKey(
                $snapshot,
                $artifact->sourceFile->displayPath,
                $artifact->sourceHash,
                $artifact->relativeOutputPath,
                $artifact->operation,
                $declarationIdentity,
            ),
            [
                'artifactFormatVersion' => CacheFormat::ARTIFACT,
                'compilerBuildIdentity' => $this->buildIdentity->calculate(),
                'contentBlob' => $contentBlob,
                'declarationIdentity' => $declarationIdentity,
                'fileMode' => $artifact->mode,
                'loweringFormatVersion' => Compiler::LOWERING_FORMAT_VERSION,
                'operation' => $artifact->operation->value,
                'outputHash' => $artifact->outputHash,
                'outputPath' => str_replace('\\', '/', $artifact->relativeOutputPath),
                'sourceHash' => $artifact->sourceHash,
                'sourceMapBlob' => $mapBlob,
                'sourceMapFormatVersion' => SourceMapWriter::FORMAT_VERSION,
                'sourcePath' => str_replace('\\', '/', $artifact->sourceFile->displayPath),
                'targetPhpVersion' => $project->configuration->targetPhpVersion,
            ],
        );
    }

    private function artifactUnitKey(
        ProjectInputSnapshot $snapshot,
        string $sourcePath,
        string $sourceHash,
        string $outputPath,
        OutputOperation $operation,
        string $declarationIdentity,
    ): CacheKey {
        $context = $snapshot->inputs;
        $context['sources'] = array_map(
            static function (mixed $source): mixed {
                if (!is_array($source)) {
                    return $source;
                }

                unset($source['sha256']);

                return $source;
            },
            is_array($context['sources'] ?? null) ? $context['sources'] : [],
        );
        unset($context['selectedSources']);

        return CacheKey::create('artifact-unit', [
            'context' => $context,
            'declarationIdentity' => $declarationIdentity,
            'operation' => $operation->value,
            'outputPath' => str_replace('\\', '/', $outputPath),
            'sourceHash' => $sourceHash,
            'sourcePath' => str_replace('\\', '/', $sourcePath),
        ]);
    }

    /** @return array<string, mixed> */
    private function supplementalIdentity(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];

        foreach ([
            'configuration' => $root . '/resources/phpstan/ppphp.neon',
            'executable' => $root . '/vendor/phpstan/phpstan/phpstan',
            'package' => $root . '/vendor/phpstan/phpstan/composer.json',
        ] as $name => $path) {
            $hash = is_file($path) && !is_link($path) ? hash_file('sha256', $path) : false;
            $files[$name] = is_string($hash) ? 'sha256:' . $hash : null;
        }

        return [
            'files' => $files,
            'hostPhpVersion' => PHP_VERSION,
            'processPolicyVersion' => CacheFormat::PROCESS_POLICY,
        ];
    }

    private function store(Project $project): CacheStore
    {
        return new CacheStore(
            $project->configuration->projectRoot,
            $project->configuration->cachePath,
            $this->statistics,
        );
    }

    private function decodeDiagnostics(mixed $records, Project $project): DiagnosticBag
    {
        if (!is_array($records) || !array_is_list($records)) {
            throw new \RuntimeException('Cached diagnostics are malformed.');
        }

        return $this->diagnostics->decode($records, $project);
    }

    /**
     * @return array{
     *     completeness: AnalysisCompleteness,
     *     diagnostics: DiagnosticBag,
     *     semanticCompleted: bool,
     *     successful: bool,
     *     uncovered: list<string>
     * }|null
     */
    private function loadCompilerEvidence(
        CacheStore $store,
        CacheKey $key,
        Project $project,
        ProjectInputSnapshot $snapshot,
    ): ?array {
        $record = $store->readRecord('compiler', 'compiler-check', $key);

        if ($record === null) {
            return null;
        }

        try {
            if (array_keys($record) !== [
                'completeness',
                'contextIdentity',
                'diagnosticFormatVersion',
                'diagnostics',
                'semanticCompleted',
                'snapshotIdentity',
                'successful',
                'uncoveredRequiredCapabilities',
            ]
                || ($record['diagnosticFormatVersion'] ?? null) !== CacheFormat::DIAGNOSTIC
                || ($record['snapshotIdentity'] ?? null) !== $snapshot->identity) {
                throw new \RuntimeException('Cached compiler evidence has an invalid schema.');
            }

            $diagnostics = $this->decodeDiagnostics($record['diagnostics'] ?? null, $project);
            $successful = $record['successful'] ?? null;
            $semanticCompleted = $record['semanticCompleted'] ?? null;
            $contextIdentity = $record['contextIdentity'] ?? null;
            $completeness = is_string($record['completeness'] ?? null)
                ? AnalysisCompleteness::tryFrom($record['completeness'])
                : null;
            $uncovered = $record['uncoveredRequiredCapabilities'] ?? null;

            if (!is_bool($successful)
                || !is_bool($semanticCompleted)
                || ($successful && !$semanticCompleted)
                || !is_string($contextIdentity)
                || !hash_equals($snapshot->key('declaration-context')->value, $contextIdentity)
                || $completeness === null
                || !is_array($uncovered)
                || !array_is_list($uncovered)
                || array_filter($uncovered, 'is_string') !== $uncovered) {
                throw new \RuntimeException('Cached compiler evidence is malformed.');
            }

            return [
                'completeness' => $completeness,
                'diagnostics' => $diagnostics,
                'semanticCompleted' => $semanticCompleted,
                'successful' => $successful,
                'uncovered' => $uncovered,
            ];
        } catch (\Throwable) {
            $this->statistics->corruptEntries++;
            $store->invalidateRecord('compiler', 'compiler-check', $key);

            return null;
        }
    }

    /**
     * @param array{
     *     completeness: AnalysisCompleteness,
     *     diagnostics: DiagnosticBag,
     *     semanticCompleted: bool,
     *     successful: bool,
     *     uncovered: list<string>
     * } $evidence
     */
    private function compilerEvidenceResult(array $evidence): ProjectCheckResult
    {
        return new ProjectCheckResult(
            null,
            null,
            null,
            $evidence['diagnostics'],
            $evidence['completeness'],
            $evidence['uncovered'],
            true,
            false,
            $this->statistics,
        );
    }

    private function recordCompilerReuse(SourceSet $selectedSources, bool $semanticCompleted): void
    {
        $this->statistics->parserWorkAvoided += count($selectedSources);
        $this->statistics->semanticWorkAvoided += $semanticCompleted ? 1 : 0;
    }

    private function isCacheableSupplementalResult(DiagnosticBag $diagnostics): bool
    {
        foreach ($diagnostics as $diagnostic) {
            if (in_array($diagnostic->code, [
                DiagnosticCode::StaticAnalysisBackendFailed,
                DiagnosticCode::StaticAnalysisResultInvalid,
                DiagnosticCode::AnalysisWorkspacePreparationFailed,
                DiagnosticCode::InternalCompilerError,
            ], true)) {
                return false;
            }
        }

        return true;
    }
}
