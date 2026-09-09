<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Interop\Composer;

use Atatusoft\Ppphp\Analysis\Declaration\DeclarationOrigin;
use Atatusoft\Ppphp\Analysis\Declaration\DeclarationBodyPruner;
use Atatusoft\Ppphp\Analysis\Declaration\DeclarationReferenceCollector;
use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Diagnostics\DiagnosticLabel;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Frontend\Enumerations\ParseMode;
use Atatusoft\Ppphp\Frontend\ParsedFile;
use Atatusoft\Ppphp\Frontend\PpphpParser;
use Atatusoft\Ppphp\Project\ProjectParseResult;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;
use Atatusoft\Ppphp\Support\Path;

/** Loads Composer declarations as data; it never includes or executes package code. */
final class ComposerDependencyDeclarationLoader
{
    public const int MAXIMUM_FILES = 2_048;
    public const int MAXIMUM_BYTES = 16_777_216;
    public const int MAXIMUM_DISCOVERY_ENTRIES = 8_192;
    public const int MAXIMUM_INCLUDE_DEPTH = 32;

    public function __construct(
        private readonly PpphpParser $parser = new PpphpParser(),
        private readonly DeclarationReferenceCollector $references = new DeclarationReferenceCollector(),
        private readonly ComposerDependencySourceInspector $inspector = new ComposerDependencySourceInspector(),
    ) {}

    /**
     * @param iterable<ParsedFile> $projectFiles
     * @param list<string> $allowedPackageRoots explicit roots for the standalone index builder only
     */
    public function load(
        ComposerProject $project,
        iterable $projectFiles,
        array $allowedPackageRoots = [],
        bool $completeIndex = false,
    ): ProjectParseResult {
        $diagnostics = new DiagnosticBag();
        $parsedFiles = [];
        $sourceFiles = [];
        /** @var list<DependencySourceCandidate> $pending */
        $pending = [];
        $queued = [];
        $loaded = [];
        $unavailable = [];
        $aliases = [];
        $aliasProvenance = [];
        $discoveryEntries = 0;
        $bytes = 0;
        $declarationOrder = 0;
        $bodyPruner = new DeclarationBodyPruner();
        $projectFiles = array_values(is_array($projectFiles) ? $projectFiles : iterator_to_array($projectFiles));
        $trustedRoots = $this->canonicalRoots([
            $project->projectRoot,
            $project->vendorPath,
            ...$allowedPackageRoots,
        ]);

        foreach ($this->sortPackagesForFiles($project->dependencies) as $package) {
            foreach ($package->autoload->files as $path) {
                $this->enqueue($pending, $queued, new DependencySourceCandidate($path, $package, 'files'));
            }
        }

        foreach ($project->dependencies as $package) {
            foreach ($package->autoload->classmap as $entry) {
                foreach ($this->expandClassmapEntry($entry, $package, $trustedRoots, $discoveryEntries, $unavailable) as $path) {
                    $this->enqueue($pending, $queued, new DependencySourceCandidate($path, $package, 'classmap'));
                }
            }

            if ($completeIndex) {
                foreach (['psr-4' => $package->autoload->psr4, 'psr-0' => $package->autoload->psr0] as $form => $mappings) {
                    foreach ($mappings as $directories) {
                        foreach ($directories as $directory) {
                            foreach ($this->expandClassmapEntry(
                                $directory,
                                $package,
                                $trustedRoots,
                                $discoveryEntries,
                                $unavailable,
                                false,
                            ) as $path) {
                                $this->enqueue($pending, $queued, new DependencySourceCandidate($path, $package, $form));
                            }
                        }
                    }
                }
            }
        }

        if (count($pending) > self::MAXIMUM_FILES) {
            $this->addLimitDiagnostic($diagnostics, 'files', count($pending), self::MAXIMUM_FILES);

            return new ProjectParseResult([], [], $diagnostics);
        }

        if ($discoveryEntries > self::MAXIMUM_DISCOVERY_ENTRIES) {
            $this->addLimitDiagnostic($diagnostics, 'discovery entries', $discoveryEntries, self::MAXIMUM_DISCOVERY_ENTRIES);

            return new ProjectParseResult([], [], $diagnostics);
        }

        while (true) {
            while ($pending !== []) {
                $candidate = array_shift($pending);
                $key = Path::buildComparisonKey($candidate->path);

                if (isset($loaded[$key])) {
                    continue;
                }

                if (count($loaded) >= self::MAXIMUM_FILES) {
                    $this->addLimitDiagnostic($diagnostics, 'files', count($loaded) + 1, self::MAXIMUM_FILES);

                    return new ProjectParseResult([], [], $diagnostics);
                }

                if ($candidate->includeDepth > self::MAXIMUM_INCLUDE_DEPTH) {
                    $this->addLimitDiagnostic($diagnostics, 'include depth', $candidate->includeDepth, self::MAXIMUM_INCLUDE_DEPTH);

                    return new ProjectParseResult([], [], $diagnostics);
                }

                $loaded[$key] = true;
                $safety = $this->safeRegularFile($candidate->path, $candidate->package, $trustedRoots);

                if ($safety !== true) {
                    $unavailable[] = [$candidate->package, $safety, $candidate->path];
                    continue;
                }

                $source = @file_get_contents($candidate->path);

                if (!is_string($source)) {
                    $unavailable[] = [$candidate->package, 'unreadable', $candidate->path];
                    continue;
                }

                $bytes += strlen($source);

                if ($bytes > self::MAXIMUM_BYTES) {
                    $this->addLimitDiagnostic($diagnostics, 'source bytes', $bytes, self::MAXIMUM_BYTES);

                    return new ProjectParseResult([], [], $diagnostics);
                }

                $provenance = new DependencyDeclarationProvenance(
                    $candidate->package->name,
                    $candidate->package->prettyVersion ?? $candidate->package->version,
                    $candidate->package->reference,
                    $this->relativePath($candidate->package, $candidate->path),
                    $candidate->autoloadForm,
                    $declarationOrder++,
                );
                $sourceFile = new SourceFile(
                    $candidate->path,
                    $this->displayPath($candidate->package, $candidate->path),
                    FileKind::Php,
                    $source,
                    DeclarationOrigin::ComposerDependency,
                    $provenance,
                );
                $result = $this->parser->parse($sourceFile, ParseMode::Php);

                if ($result->parsedFile === null || $result->diagnostics->hasErrors) {
                    $first = $result->diagnostics->errors[0] ?? null;
                    $diagnostics->add(new Diagnostic(
                        DiagnosticCode::ComposerDependencyDeclarationInvalid,
                        sprintf('Composer dependency source "%s" could not provide portable declarations.', $sourceFile->displayPath),
                        $first?->primary === null
                            ? null
                            : new DiagnosticLabel($first->primary->span, 'The dependency declaration is invalid here.'),
                        help: 'Install a dependency version compatible with the configured PHP target.',
                    ));
                    continue;
                }

                $sourceFiles[$key] = $sourceFile;
                $inspection = $this->inspector->inspect($result->parsedFile);
                // Inspect runtime includes, guards and aliases first. Only declaration
                // contracts belong in the dependency closure; PHPStan reads original bodies.
                $parsedFiles[$key] = $bodyPruner->prune($result->parsedFile);

                foreach (array_reverse($inspection->staticIncludes) as $include) {
                    $this->enqueueNext($pending, $queued, new DependencySourceCandidate(
                        $include,
                        $candidate->package,
                        'include',
                        $candidate->includeDepth + 1,
                    ));
                }

                if ($inspection->hasDynamicInclude || $inspection->hasDynamicAlias) {
                    $unavailable[] = [$candidate->package, 'dynamic', $candidate->path];
                }

                foreach ($inspection->aliases as $alias => $original) {
                    $existingAlias = $this->aliasTarget($aliases, $alias);

                    if ($existingAlias !== null && strcasecmp($existingAlias, $original) !== 0) {
                        $this->addAmbiguityDiagnostic($diagnostics, $alias, $sourceFile, null);
                    } elseif ($existingAlias === null) {
                        $aliases[$alias] = $original;
                        $aliasProvenance[$alias] = $provenance;
                    }
                }

                if ($inspection->conditionalDeclarations !== []) {
                    $conditionalKey = $key . '#conditional';
                    $conditionalSource = new SourceFile(
                        $candidate->path . '#conditional',
                        $sourceFile->displayPath,
                        FileKind::Php,
                        $source,
                        DeclarationOrigin::ConditionalComposerDependency,
                        new DependencyDeclarationProvenance(
                            $provenance->packageName,
                            $provenance->packageVersion,
                            $provenance->packageReference,
                            $provenance->packageRelativePath,
                            $provenance->autoloadForm,
                            $provenance->declarationOrder,
                            true,
                        ),
                    );
                    $parsedFiles[$conditionalKey] = $bodyPruner->prune($result->parsedFile->withDeclarations(
                        $conditionalSource,
                        $inspection->conditionalDeclarations,
                    ));
                    $sourceFiles[$conditionalKey] = $conditionalSource;
                }
            }

            if ($diagnostics->hasErrors) {
                return new ProjectParseResult([], [], $diagnostics);
            }

            $declarations = $this->references->collectDeclarations($parsedFiles);
            $referenced = $this->references->collect([
                ...$projectFiles,
                ...array_values($parsedFiles),
            ]);
            $added = false;

            foreach (array_values(array_unique([...$referenced['classes'], ...array_values($aliases)])) as $class) {
                if ($this->containsName($declarations['classes'], $class) || $this->containsAlias($aliases, $class)) {
                    continue;
                }

                $resolution = $this->resolveClass($project->dependencies, $class, $trustedRoots);

                if ($resolution instanceof DependencySourceCandidate
                    && !isset($loaded[Path::buildComparisonKey($resolution->path)])) {
                    $this->enqueue($pending, $queued, new DependencySourceCandidate(
                        $resolution->path,
                        $resolution->package,
                        $resolution->autoloadForm,
                    ));
                    $added = true;
                } elseif (is_array($resolution)) {
                    $unavailable[] = $resolution;
                }
            }

            if (!$added) {
                break;
            }
        }

        $parsedFiles = $this->resolveAmbiguousDeclarations($parsedFiles, $project, $trustedRoots, $diagnostics);
        $this->reportAliasConflicts($aliases, $parsedFiles, $diagnostics);

        if (!$diagnostics->hasErrors) {
            $this->reportRelevantUnavailable($projectFiles, $parsedFiles, $aliases, $unavailable, $diagnostics);
        }

        if ($diagnostics->hasErrors) {
            return new ProjectParseResult([], [], $diagnostics);
        }

        $prefixes = [];

        foreach ($project->dependencies as $package) {
            foreach ([...array_keys($package->autoload->psr4), ...array_keys($package->autoload->psr0)] as $prefix) {
                if ($prefix !== '') {
                    $prefixes[$prefix] = true;
                }
            }
        }

        return new ProjectParseResult(
            $parsedFiles,
            $sourceFiles,
            $diagnostics,
            array_keys($prefixes),
            $aliases,
            $aliasProvenance,
        );
    }

    /**
     * @param list<DependencySourceCandidate> $pending
     * @param array<string, true> $queued
     */
    private function enqueue(array &$pending, array &$queued, DependencySourceCandidate $candidate): void
    {
        $key = Path::buildComparisonKey($candidate->path);

        if (!isset($queued[$key])) {
            $queued[$key] = true;
            $pending[] = $candidate;
        }
    }

    /**
     * @param list<DependencySourceCandidate> $pending
     * @param array<string, true> $queued
     */
    private function enqueueNext(array &$pending, array &$queued, DependencySourceCandidate $candidate): void
    {
        $key = Path::buildComparisonKey($candidate->path);

        if (isset($queued[$key])) {
            foreach ($pending as $index => $queuedCandidate) {
                if (Path::buildComparisonKey($queuedCandidate->path) === $key) {
                    array_splice($pending, $index, 1);
                    array_unshift($pending, $candidate);

                    return;
                }
            }

            return;
        }

        $queued[$key] = true;
        array_unshift($pending, $candidate);
    }

    /**
     * Match Composer's eager-file ordering: providers precede packages which require them,
     * with natural package-name ordering for packages of equal dependency weight.
     *
     * @param list<ComposerPackage> $packages
     * @return list<ComposerPackage>
     */
    private function sortPackagesForFiles(array $packages): array
    {
        $available = [];
        $users = [];

        foreach ($packages as $package) {
            $available[$package->name] = true;
        }

        foreach ($packages as $package) {
            foreach (array_keys($package->requirements) as $requirement) {
                if (isset($available[$requirement])) {
                    $users[$requirement][] = $package->name;
                }
            }
        }

        $weights = [];
        $computing = [];
        $importance = function (string $name) use (&$importance, &$weights, &$computing, $users): int {
            if (isset($weights[$name])) {
                return $weights[$name];
            }

            if (isset($computing[$name])) {
                return 0;
            }

            $computing[$name] = true;
            $weight = 0;

            foreach ($users[$name] ?? [] as $user) {
                $weight -= 1 - $importance($user);
            }

            unset($computing[$name]);

            return $weights[$name] = $weight;
        };

        usort($packages, static fn (ComposerPackage $left, ComposerPackage $right): int =>
            $importance($left->name) <=> $importance($right->name)
                ?: strnatcasecmp($left->name, $right->name));

        return $packages;
    }

    /**
     * @param list<ComposerPackage> $packages
     * @param list<string> $trustedRoots
     * @return DependencySourceCandidate|array{ComposerPackage, string, string}|null
     */
    private function resolveClass(array $packages, string $class, array $trustedRoots): DependencySourceCandidate|array|null
    {
        foreach (['psr4', 'psr0'] as $form) {
            $candidates = [];
            $order = 0;

            foreach ($packages as $package) {
                $mapping = $form === 'psr4' ? $package->autoload->psr4 : $package->autoload->psr0;

                foreach ($mapping as $prefix => $directories) {
                    if ($prefix !== '' && !str_starts_with($class, $prefix)) {
                        continue;
                    }

                    $relative = $form === 'psr4' ? substr($class, strlen($prefix)) : $this->psr0Path($class);

                    foreach ($directories as $directory) {
                        $candidates[] = [
                            'path' => Path::join($directory, str_replace('\\', '/', $relative) . '.php'),
                            'package' => $package,
                            'prefixLength' => strlen($prefix),
                            'order' => $order++,
                        ];
                    }
                }
            }

            usort($candidates, static fn (array $left, array $right): int =>
                $right['prefixLength'] <=> $left['prefixLength'] ?: $left['order'] <=> $right['order']);

            foreach ($candidates as $candidate) {
                if ($this->safeRegularFile($candidate['path'], $candidate['package'], $trustedRoots) === true) {
                    return new DependencySourceCandidate(
                        $candidate['path'],
                        $candidate['package'],
                        $form === 'psr4' ? 'psr-4' : 'psr-0',
                    );
                }
            }

            if ($candidates !== []) {
                $first = $candidates[0];
                $safety = $this->safeRegularFile($first['path'], $first['package'], $trustedRoots);

                return [$first['package'], $safety === 'unsafe' ? 'unsafe' : 'unavailable', $first['path']];
            }
        }

        return null;
    }

    private function psr0Path(string $class): string
    {
        $separator = strrpos($class, '\\');

        if ($separator === false) {
            return str_replace('_', '/', $class);
        }

        return str_replace('\\', '/', substr($class, 0, $separator + 1))
            . str_replace('_', '/', substr($class, $separator + 1));
    }

    /**
     * @param list<string> $trustedRoots
     * @param list<array{ComposerPackage, string, string}> $unavailable
     * @return list<string>
     */
    private function expandClassmapEntry(
        string $entry,
        ComposerPackage $package,
        array $trustedRoots,
        int &$discoveryEntries,
        array &$unavailable,
        bool $applyExclusions = true,
    ): array {
        $wildcard = strpos($entry, '*');
        $base = $wildcard === false ? $entry : rtrim(substr($entry, 0, $wildcard), '/');

        while ($wildcard !== false && $base !== '' && !is_dir($base)) {
            $parent = dirname($base);

            if ($parent === $base) {
                break;
            }

            $base = $parent;
        }

        $safety = $this->safePackagePath($base, $package, $trustedRoots, false);

        if ($safety !== true) {
            $unavailable[] = [$package, $safety, $entry];

            return [];
        }

        if (is_file($base)) {
            return str_ends_with(strtolower($base), '.php')
                && (!$applyExclusions || !$this->excludedFromClassmap($base, $package))
                ? [$base]
                : [];
        }

        if (!is_dir($base) || is_link($base)) {
            $unavailable[] = [$package, 'unavailable', $entry];

            return [];
        }

        $files = [];
        $pending = [$base];
        $visited = [];

        while ($pending !== []) {
            $directory = array_shift($pending);
            $canonical = realpath($directory);

            if (!is_string($canonical) || isset($visited[$canonical])) {
                continue;
            }

            $visited[$canonical] = true;
            $entries = $this->directoryEntries($directory);

            if ($entries === null) {
                $unavailable[] = [$package, 'unreadable', $directory];
                continue;
            }

            foreach ($entries as $path) {
                if (++$discoveryEntries > self::MAXIMUM_DISCOVERY_ENTRIES) {
                    return [];
                }

                if ($this->safePackagePath($path, $package, $trustedRoots, false) !== true) {
                    $unavailable[] = [$package, 'unsafe', $path];
                    continue;
                }

                if (is_dir($path)) {
                    $pending[] = $path;
                } elseif (is_file($path)
                    && str_ends_with(strtolower($path), '.php')
                    && (!$applyExclusions || !$this->excludedFromClassmap($path, $package))
                    && ($wildcard === false || $this->matchesComposerPattern($path, $entry, true))) {
                    $files[] = $path;
                }
            }
        }

        return $files;
    }

    private function excludedFromClassmap(string $path, ComposerPackage $package): bool
    {
        foreach ($package->autoload->excludeFromClassmap as $pattern) {
            if ($this->matchesComposerPattern($path, $pattern, true)) {
                return true;
            }
        }

        return false;
    }

    private function matchesComposerPattern(string $path, string $pattern, bool $includeDescendants = false): bool
    {
        $quoted = preg_quote(Path::normalize($pattern), '~');
        $quoted = str_replace('\\*\\*', '.+', $quoted);
        $quoted = str_replace('\\*', '[^/]+', $quoted);

        return preg_match('~^' . $quoted . ($includeDescendants ? '(?:/.*)?' : '') . '$~D', Path::normalize($path)) === 1;
    }

    /** @param list<string> $trustedRoots */
    private function safeRegularFile(string $path, ComposerPackage $package, array $trustedRoots): true|string
    {
        $safety = $this->safePackagePath($path, $package, $trustedRoots, true);

        if ($safety !== true) {
            return $safety;
        }

        return is_file($path) && !is_dir($path) ? true : 'unavailable';
    }

    /** @param list<string> $trustedRoots */
    private function safePackagePath(
        string $path,
        ComposerPackage $package,
        array $trustedRoots,
        bool $mustExist,
    ): true|string {
        $packageRoot = realpath($package->installPath);
        $canonical = realpath($path);

        if (!is_string($packageRoot)) {
            return 'unavailable';
        }

        $rootTrusted = false;

        foreach ($trustedRoots as $trustedRoot) {
            if (Path::contains($trustedRoot, $packageRoot)) {
                $rootTrusted = true;
                break;
            }
        }

        if (!$rootTrusted || !Path::contains($package->installPath, $path)) {
            return 'unsafe';
        }

        if (!is_string($canonical)) {
            return $mustExist ? 'unavailable' : true;
        }

        return Path::contains($packageRoot, $canonical) ? true : 'unsafe';
    }

    /**
     * @param list<string> $roots
     * @return list<string>
     */
    private function canonicalRoots(array $roots): array
    {
        $canonical = [];

        foreach ($roots as $root) {
            $resolved = realpath($root);

            if (is_string($resolved)) {
                $canonical[Path::buildComparisonKey($resolved)] = Path::normalize($resolved);
            }
        }

        return array_values($canonical);
    }

    /**
     * @param array<string, ParsedFile> $parsedFiles
     * @param list<string> $trustedRoots
     * @return array<string, ParsedFile>
     */
    private function resolveAmbiguousDeclarations(
        array $parsedFiles,
        ComposerProject $project,
        array $trustedRoots,
        DiagnosticBag $diagnostics,
    ): array
    {
        /** @var array<string, array{kind: 'classes'|'functions'|'constants', name: string, candidates: list<array{key: string, file: ParsedFile, conditional: bool}>}> $groups */
        $groups = [];

        foreach ($parsedFiles as $fileKey => $file) {
            $declarations = $this->references->collectDeclarations([$file]);
            $conditional = $file->sourceFile->declarationOrigin === DeclarationOrigin::ConditionalComposerDependency;

            foreach ($declarations as $kind => $names) {
                foreach ($names as $name) {
                    $key = $kind . ':' . strtolower(ltrim($name, '\\'));
                    $groups[$key] ??= ['kind' => $kind, 'name' => $name, 'candidates' => []];
                    $groups[$key]['candidates'][] = [
                        'key' => $fileKey,
                        'file' => $file,
                        'conditional' => $conditional,
                    ];
                }
            }
        }

        /** @var array<string, array<string, true>> $edges */
        $edges = [];

        foreach ($groups as $group) {
            $unconditional = array_values(array_filter(
                $group['candidates'],
                static fn (array $candidate): bool => !$candidate['conditional'],
            ));
            $candidates = $unconditional !== [] ? $unconditional : $group['candidates'];

            if (count($candidates) < 2) {
                continue;
            }

            $winner = $this->authoritativeCandidate(
                $group['kind'],
                $group['name'],
                $candidates,
                $project,
                $trustedRoots,
                $unconditional === [],
            );

            if ($winner === null) {
                $this->addAmbiguityDiagnostic(
                    $diagnostics,
                    $group['name'],
                    $candidates[0]['file']->sourceFile,
                    $candidates[1]['file']->sourceFile,
                );
                continue;
            }

            foreach ($candidates as $candidate) {
                if ($candidate['key'] !== $winner['key']) {
                    $edges[$winner['key']][$candidate['key']] = true;
                }
            }
        }

        if ($diagnostics->hasErrors || $edges === []) {
            return $parsedFiles;
        }

        return $this->orderByAuthority($parsedFiles, $edges, $diagnostics);
    }

    /**
     * @param 'classes'|'functions'|'constants' $kind
     * @param list<array{key: string, file: ParsedFile, conditional: bool}> $candidates
     * @param list<string> $trustedRoots
     * @return array{key: string, file: ParsedFile, conditional: bool}|null
     */
    private function authoritativeCandidate(
        string $kind,
        string $name,
        array $candidates,
        ComposerProject $project,
        array $trustedRoots,
        bool $conditional,
    ): ?array {
        if ($conditional) {
            $eager = array_values(array_filter(
                $candidates,
                static fn (array $candidate): bool => in_array(
                    $candidate['file']->sourceFile->dependencyProvenance?->autoloadForm,
                    ['files', 'include'],
                    true,
                ),
            ));

            if ($eager !== []) {
                return $this->earliestCandidate($eager);
            }
        }

        if ($kind !== 'classes') {
            return null;
        }

        $eager = array_values(array_filter(
            $candidates,
            static fn (array $candidate): bool => in_array(
                $candidate['file']->sourceFile->dependencyProvenance?->autoloadForm,
                ['files', 'include'],
                true,
            ),
        ));

        if (count($eager) === 1) {
            return $eager[0];
        }

        if (count($eager) > 1) {
            return null;
        }

        $classmap = array_values(array_filter(
            $candidates,
            static fn (array $candidate): bool => $candidate['file']->sourceFile->dependencyProvenance?->autoloadForm === 'classmap',
        ));

        if ($classmap !== []) {
            return $this->earliestCandidate($classmap);
        }

        $resolution = $this->resolveClass($project->dependencies, $name, $trustedRoots);

        if (!$resolution instanceof DependencySourceCandidate) {
            return null;
        }

        $resolvedKey = Path::buildComparisonKey($resolution->path);
        $matching = array_values(array_filter(
            $candidates,
            static fn (array $candidate): bool => Path::buildComparisonKey($candidate['file']->sourceFile->path) === $resolvedKey,
        ));

        return count($matching) === 1 ? $matching[0] : null;
    }

    /**
     * @param non-empty-list<array{key: string, file: ParsedFile, conditional: bool}> $candidates
     * @return array{key: string, file: ParsedFile, conditional: bool}
     */
    private function earliestCandidate(array $candidates): array
    {
        usort($candidates, static function (array $left, array $right): int {
            $leftProvenance = $left['file']->sourceFile->dependencyProvenance;
            $rightProvenance = $right['file']->sourceFile->dependencyProvenance;

            if ($leftProvenance === null || $rightProvenance === null) {
                throw new \LogicException('A dependency authority candidate lacks provenance.');
            }

            return $leftProvenance->declarationOrder <=> $rightProvenance->declarationOrder;
        });

        return $candidates[0];
    }

    /**
     * @param array<string, ParsedFile> $parsedFiles
     * @param array<string, array<string, true>> $edges
     * @return array<string, ParsedFile>
     */
    private function orderByAuthority(array $parsedFiles, array $edges, DiagnosticBag $diagnostics): array
    {
        $remaining = array_fill_keys(array_keys($parsedFiles), true);
        $ordered = [];

        while ($remaining !== []) {
            $next = null;

            foreach (array_keys($remaining) as $candidate) {
                $hasIncoming = false;

                foreach ($edges as $owner => $dependents) {
                    if (isset($remaining[$owner], $dependents[$candidate])) {
                        $hasIncoming = true;
                        break;
                    }
                }

                if (!$hasIncoming) {
                    $next = $candidate;
                    break;
                }
            }

            if ($next === null) {
                $this->addAmbiguityDiagnostic($diagnostics, 'Composer dependency declarations', null, null);

                return $parsedFiles;
            }

            $ordered[$next] = $parsedFiles[$next];
            unset($remaining[$next]);
        }

        return $ordered;
    }

    /**
     * @param array<string, string> $aliases
     * @param array<string, ParsedFile> $parsedFiles
     */
    private function reportAliasConflicts(array $aliases, array $parsedFiles, DiagnosticBag $diagnostics): void
    {
        $declared = $this->references->collectDeclarations($parsedFiles)['classes'];
        $declaredKeys = array_fill_keys(array_map(static fn (string $name): string => strtolower(ltrim($name, '\\')), $declared), true);

        foreach ($aliases as $alias => $original) {
            if (isset($declaredKeys[strtolower(ltrim($alias, '\\'))]) || $this->aliasCycles($alias, $aliases)) {
                $this->addAmbiguityDiagnostic($diagnostics, $alias, null, null);
            }
        }
    }

    /** @param array<string, string> $aliases */
    private function aliasCycles(string $alias, array $aliases): bool
    {
        $normalized = [];

        foreach ($aliases as $name => $target) {
            $normalized[strtolower(ltrim($name, '\\'))] = strtolower(ltrim($target, '\\'));
        }

        $seen = [];
        $current = strtolower(ltrim($alias, '\\'));

        while (isset($normalized[$current])) {
            if (isset($seen[$current])) {
                return true;
            }

            $seen[$current] = true;
            $current = $normalized[$current];
        }

        return false;
    }

    /**
     * @param list<ParsedFile> $projectFiles
     * @param array<string, ParsedFile> $dependencyFiles
     * @param array<string, string> $aliases
     * @param list<array{ComposerPackage, string, string}> $unavailable
     */
    private function reportRelevantUnavailable(
        array $projectFiles,
        array $dependencyFiles,
        array $aliases,
        array $unavailable,
        DiagnosticBag $diagnostics,
    ): void {
        if ($unavailable === []) {
            return;
        }

        $references = $this->references->collect($projectFiles);
        $declarations = $this->references->collectDeclarations([...$projectFiles, ...array_values($dependencyFiles)]);
        $missing = [];

        foreach (array_keys($references) as $kind) {
            foreach ($references[$kind] as $name) {
                $resolvedName = $kind === 'classes' ? $this->resolveAliasTarget($aliases, $name) : $name;

                if (!$this->containsName($declarations[$kind], $resolvedName)) {
                    $missing[] = ['kind' => $kind, 'name' => $resolvedName];
                }
            }
        }

        if ($missing === []) {
            return;
        }

        $relevant = null;

        foreach ($unavailable as $candidate) {
            foreach ($missing as $missingReference) {
                if ($this->packageMayOwn($candidate[0], $missingReference['kind'], $missingReference['name'])) {
                    $relevant = [$candidate, $missingReference];
                    break 2;
                }
            }
        }

        if ($relevant === null) {
            return;
        }

        [[$package, $reason, $path], $missingReference] = $relevant;
        $unsafe = $reason === 'unsafe';
        $diagnostics->add(new Diagnostic(
            $unsafe ? DiagnosticCode::DependencySourcePathUnsafe : DiagnosticCode::DependencyDeclarationContextUnavailable,
            sprintf(
                'Package source for "%s" is %s while resolving %s.',
                $this->packageIdentity($package),
                $unsafe ? 'outside the trusted package roots' : 'unavailable',
                $missingReference['name'],
            ),
            help: $unsafe
                ? 'Keep installed package sources beneath the canonical project/vendor roots or build an index with an explicit trusted root.'
                : 'Restore the installed package source or supply a valid portable dependency index.',
            debug: ['source' => $this->displayPath($package, $path), 'reason' => $reason],
        ));
    }

    private function packageMayOwn(ComposerPackage $package, string $kind, string $name): bool
    {
        if ($kind === 'classes') {
            foreach ([...array_keys($package->autoload->psr4), ...array_keys($package->autoload->psr0)] as $prefix) {
                if ($prefix === '' || str_starts_with(strtolower($name), strtolower($prefix))) {
                    return true;
                }
            }

            [$vendor, $project] = array_pad(explode('/', strtolower($package->name), 2), 2, '');
            $name = strtolower(ltrim($name, '\\'));

            return ($vendor !== '' && str_starts_with($name, str_replace('-', '_', $vendor) . '\\'))
                || ($project !== '' && str_starts_with($name, str_replace('-', '_', $project) . '\\'));
        }

        [$vendor, $project] = array_pad(explode('/', strtolower($package->name), 2), 2, '');
        $name = strtolower(ltrim($name, '\\'));

        return ($vendor !== '' && str_starts_with($name, str_replace('-', '_', $vendor) . '_'))
            || ($project !== '' && str_starts_with($name, str_replace('-', '_', $project) . '_'));
    }

    private function addAmbiguityDiagnostic(
        DiagnosticBag $diagnostics,
        string $name,
        ?SourceFile $first,
        ?SourceFile $second,
    ): void {
        $providers = array_values(array_unique(array_filter([
            $this->sourcePackageIdentity($first),
            $this->sourcePackageIdentity($second),
        ])));
        $diagnostics->add(new Diagnostic(
            DiagnosticCode::DependencyDeclarationAmbiguous,
            sprintf(
                'Dependency declaration "%s" has no single runtime-authoritative source%s.',
                $name,
                $providers === [] ? '' : ' across ' . implode(' and ', $providers),
            ),
            $first === null ? null : new DiagnosticLabel($first->createSpan(0, 0), 'One dependency declaration is provided here.'),
            $second === null ? [] : [new DiagnosticLabel($second->createSpan(0, 0), 'A competing dependency declaration is provided here.')],
            help: 'Remove the conflicting declaration or make Composer runtime precedence explicit.',
        ));
    }

    private function packageIdentity(ComposerPackage $package): string
    {
        $version = $package->prettyVersion ?? $package->version;

        return $version === null ? $package->name : $package->name . '@' . $version;
    }

    private function sourcePackageIdentity(?SourceFile $sourceFile): ?string
    {
        $provenance = $sourceFile?->dependencyProvenance;

        if ($provenance === null) {
            return null;
        }

        return $provenance->packageVersion === null
            ? $provenance->packageName
            : $provenance->packageName . '@' . $provenance->packageVersion;
    }

    /** @param list<string> $names */
    private function containsName(array $names, string $candidate): bool
    {
        $candidate = strtolower(ltrim($candidate, '\\'));

        foreach ($names as $name) {
            if (strtolower(ltrim($name, '\\')) === $candidate) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, string> $aliases */
    private function containsAlias(array $aliases, string $candidate): bool
    {
        return $this->aliasTarget($aliases, $candidate) !== null;
    }

    /** @param array<string, string> $aliases */
    private function aliasTarget(array $aliases, string $candidate): ?string
    {
        $candidate = strtolower(ltrim($candidate, '\\'));

        foreach ($aliases as $alias => $target) {
            if (strtolower(ltrim($alias, '\\')) === $candidate) {
                return $target;
            }
        }

        return null;
    }

    /** @param array<string, string> $aliases */
    private function resolveAliasTarget(array $aliases, string $candidate): string
    {
        $current = ltrim($candidate, '\\');
        $seen = [];

        while (($target = $this->aliasTarget($aliases, $current)) !== null) {
            $key = strtolower($current);

            if (isset($seen[$key])) {
                break;
            }

            $seen[$key] = true;
            $current = ltrim($target, '\\');
        }

        return $current;
    }

    /** @return list<string>|null */
    private function directoryEntries(string $directory): ?array
    {
        $entries = [];

        try {
            foreach (new \DirectoryIterator($directory) as $entry) {
                if (!$entry->isDot()) {
                    $entries[] = Path::normalize($entry->getPathname());
                }
            }
        } catch (\UnexpectedValueException) {
            return null;
        }

        sort($entries, SORT_STRING);

        return $entries;
    }

    private function addLimitDiagnostic(DiagnosticBag $diagnostics, string $resource, int $observed, int $limit): void
    {
        $diagnostics->add(new Diagnostic(
            DiagnosticCode::ComposerDependencyIndexLimitExceeded,
            sprintf('The Composer dependency index requires %s %s; the limit is %s.', number_format($observed), $resource, number_format($limit)),
            help: $resource === 'include depth'
                ? 'Check the dependency static-include chain for unintended nesting. Report this limit if the chain is intentional.'
                : 'Check Composer autoload paths for unintended directories. If the dependency graph is intentional, report this limit with the package metadata.',
        ));
    }

    private function displayPath(ComposerPackage $package, string $path): string
    {
        return sprintf('<Composer %s>/%s', $package->name, $this->relativePath($package, $path));
    }

    private function relativePath(ComposerPackage $package, string $path): string
    {
        return str_replace('\\', '/', Path::makeRelative($path, $package->installPath) ?? basename($path));
    }
}
