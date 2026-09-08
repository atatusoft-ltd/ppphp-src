<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Config;

use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Diagnostics\DiagnosticLabel;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;
use Atatusoft\Ppphp\Support\Path;

final class ProjectConfigLoader
{
    /** @var list<string> */
    private const ALLOWED_PROPERTIES = [
        '$schema',
        'source',
        'output',
        'cache',
        'targetPhpVersion',
        'stubs',
        'exclude',
    ];

    /** @var list<string> */
    private const REQUIRED_PROPERTIES = [
        'source',
        'output',
        'cache',
        'targetPhpVersion',
    ];

    public function load(
        string $projectRoot,
        ?string $configurationPath = null,
        bool $requireSourceDirectories = false,
    ): ProjectConfigLoadResult {
        $diagnostics = new DiagnosticBag();
        $projectRoot = $this->normalizeProjectRoot($projectRoot, $diagnostics);

        if ($projectRoot === null) {
            return ProjectConfigLoadResult::createFailure($diagnostics);
        }

        $configurationPath = $this->resolveConfigurationPath(
            $projectRoot,
            $configurationPath,
            $diagnostics,
        );

        if ($configurationPath === null) {
            return ProjectConfigLoadResult::createFailure($diagnostics);
        }

        if (!file_exists($configurationPath)) {
            $diagnostics->add($this->createDiagnostic(
                DiagnosticCode::ProjectConfigurationNotFound,
                sprintf('No project configuration exists at "%s".', Path::resolveRelativeTo($configurationPath, $projectRoot)),
                help: 'Run `ppphp init` to create the project configuration.',
            ));

            return ProjectConfigLoadResult::createFailure($diagnostics);
        }

        if (!is_file($configurationPath) || !is_readable($configurationPath)) {
            $diagnostics->add($this->createDiagnostic(
                DiagnosticCode::ProjectConfigurationNotReadable,
                sprintf('The project configuration at "%s" cannot be read.', Path::resolveRelativeTo($configurationPath, $projectRoot)),
            ));

            return ProjectConfigLoadResult::createFailure($diagnostics);
        }

        $realConfigurationPath = realpath($configurationPath);

        if ($realConfigurationPath === false || !Path::contains($projectRoot, Path::normalize($realConfigurationPath))) {
            $diagnostics->add($this->createDiagnostic(
                DiagnosticCode::UnsafeProjectPath,
                'The project configuration resolves outside the project root.',
            ));

            return ProjectConfigLoadResult::createFailure($diagnostics);
        }

        $configurationPath = Path::normalize($realConfigurationPath);
        $contents = file_get_contents($configurationPath);

        if ($contents === false) {
            $diagnostics->add($this->createDiagnostic(
                DiagnosticCode::ProjectConfigurationNotReadable,
                'The project configuration could not be read.',
            ));

            return ProjectConfigLoadResult::createFailure($diagnostics);
        }

        $source = new SourceFile(
            $configurationPath,
            Path::resolveRelativeTo($configurationPath, $projectRoot),
            FileKind::Configuration,
            $contents,
        );

        try {
            $decoded = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $diagnostics->add($this->createDiagnostic(
                DiagnosticCode::InvalidProjectConfigurationJson,
                'The project configuration does not contain valid JSON.',
                $source,
                help: 'Correct the JSON syntax in ppphp.json, then run the command again.',
                debug: ['message' => $exception->getMessage()],
            ));

            return ProjectConfigLoadResult::createFailure($diagnostics);
        }

        if (!is_object($decoded)) {
            $diagnostics->add($this->createDiagnostic(
                DiagnosticCode::InvalidConfigurationPropertyType,
                'The root JSON value must be an object.',
                $source,
            ));

            return ProjectConfigLoadResult::createFailure($diagnostics);
        }

        /** @var array<string, mixed> $values */
        $values = get_object_vars($decoded);
        $this->validateProperties($values, $source, $diagnostics);

        $sourceValues = $this->readStringArray($values, 'source', true, $source, $diagnostics);
        $outputValue = $this->readStringValue($values, 'output', $source, $diagnostics);
        $cacheValue = $this->readStringValue($values, 'cache', $source, $diagnostics);
        $targetPhpVersion = $this->readStringValue($values, 'targetPhpVersion', $source, $diagnostics);
        $stubValues = $this->readStringArray($values, 'stubs', false, $source, $diagnostics) ?? [];
        $excludedValues = $this->readStringArray($values, 'exclude', false, $source, $diagnostics) ?? [];

        if ($targetPhpVersion !== null) {
            $targetPhpVersion = (new ComposerPhpTargetResolver())->resolve($projectRoot, $targetPhpVersion, $diagnostics);
        }

        if ($targetPhpVersion !== null && !in_array($targetPhpVersion, PhpTarget::SUPPORTED, true)) {
            $diagnostics->add($this->createDiagnostic(
                DiagnosticCode::UnsupportedTargetPhpVersion,
                sprintf('The target PHP version "%s" is not supported.', $targetPhpVersion),
                $source,
                'targetPhpVersion',
                'Use a compiler release that supports your project target.',
            ));
        }

        if (
            $sourceValues === null
            || $outputValue === null
            || $cacheValue === null
            || $targetPhpVersion === null
            || $diagnostics->hasErrors
        ) {
            return ProjectConfigLoadResult::createFailure($diagnostics);
        }

        $sourceRoots = $this->resolvePaths($projectRoot, $sourceValues);
        $outputPath = Path::resolveAbsolute($outputValue, $projectRoot);
        $cachePath = Path::resolveAbsolute($cacheValue, $projectRoot);
        $stubPaths = $this->resolvePaths($projectRoot, $stubValues);
        $excludedPaths = $this->resolvePaths($projectRoot, $excludedValues);

        $this->validateResolvedDuplicates('source', $sourceRoots, $source, $diagnostics);
        $this->validateResolvedDuplicates('stubs', $stubPaths, $source, $diagnostics);
        $this->validateResolvedDuplicates('exclude', $excludedPaths, $source, $diagnostics);

        $this->validatePaths(
            $projectRoot,
            $configurationPath,
            $source,
            $sourceRoots,
            $outputPath,
            $cachePath,
            $stubPaths,
            $requireSourceDirectories,
            $diagnostics,
        );

        if ($diagnostics->hasErrors) {
            return ProjectConfigLoadResult::createFailure($diagnostics);
        }

        return ProjectConfigLoadResult::createSuccess(new ProjectConfig(
            $projectRoot,
            $configurationPath,
            $sourceRoots,
            $outputPath,
            $cachePath,
            $targetPhpVersion,
            $stubPaths,
            $excludedPaths,
        ), $diagnostics);
    }

    private function normalizeProjectRoot(string $projectRoot, DiagnosticBag $diagnostics): ?string
    {
        if (!Path::isAbsolute($projectRoot)) {
            $currentDirectory = getcwd();

            if ($currentDirectory === false) {
                throw new \RuntimeException('Unable to determine the current working directory.');
            }

            $projectRoot = Path::resolveAbsolute($projectRoot, Path::normalize($currentDirectory));
        } else {
            $projectRoot = Path::normalize($projectRoot);
        }

        if (!file_exists($projectRoot)) {
            $diagnostics->add($this->createDiagnostic(
                DiagnosticCode::ProjectPathDoesNotExist,
                sprintf('The project path "%s" does not exist.', basename($projectRoot)),
            ));

            return null;
        }

        if (!is_dir($projectRoot)) {
            $diagnostics->add($this->createDiagnostic(
                DiagnosticCode::ProjectPathNotDirectory,
                sprintf('The project path "%s" is not a directory.', basename($projectRoot)),
            ));

            return null;
        }

        $realProjectRoot = realpath($projectRoot);

        return $realProjectRoot === false ? $projectRoot : Path::normalize($realProjectRoot);
    }

    private function resolveConfigurationPath(
        string $projectRoot,
        ?string $configurationPath,
        DiagnosticBag $diagnostics,
    ): ?string {
        $configurationPath ??= 'ppphp.json';
        $resolved = Path::resolveAbsolute($configurationPath, $projectRoot);

        if (!Path::contains($projectRoot, $resolved)) {
            $diagnostics->add($this->createDiagnostic(
                DiagnosticCode::UnsafeProjectPath,
                'The project configuration path must remain inside the project root.',
            ));

            return null;
        }

        return $resolved;
    }

    /** @param array<string, mixed> $values */
    private function validateProperties(array $values, SourceFile $source, DiagnosticBag $diagnostics): void
    {
        foreach ($values as $property => $_value) {
            if (!in_array($property, self::ALLOWED_PROPERTIES, true)) {
                $diagnostics->add($this->createDiagnostic(
                    DiagnosticCode::UnknownConfigurationProperty,
                    sprintf('The property "%s" is not supported.', $property),
                    $source,
                    $property,
                    sprintf('Remove "%s" from the project configuration.', $property),
                ));
            }
        }

        foreach (self::REQUIRED_PROPERTIES as $property) {
            if (!array_key_exists($property, $values)) {
                $diagnostics->add($this->createDiagnostic(
                    DiagnosticCode::MissingConfigurationProperty,
                    sprintf('The required property "%s" is missing.', $property),
                    $source,
                    help: sprintf('Add "%s" to the project configuration.', $property),
                ));
            }
        }

        if (array_key_exists('$schema', $values) && (!is_string($values['$schema']) || $values['$schema'] === '')) {
            $diagnostics->add($this->createDiagnostic(
                DiagnosticCode::InvalidConfigurationPropertyType,
                'The property "$schema" must be a non-empty string.',
                $source,
                '$schema',
            ));
        }
    }

    /** @param array<string, mixed> $values */
    private function readStringValue(
        array $values,
        string $property,
        SourceFile $source,
        DiagnosticBag $diagnostics,
    ): ?string {
        if (!array_key_exists($property, $values)) {
            return null;
        }

        $value = $values[$property];

        if (!is_string($value) || $value === '') {
            $diagnostics->add($this->createDiagnostic(
                DiagnosticCode::InvalidConfigurationPropertyType,
                sprintf('The property "%s" must be a non-empty string.', $property),
                $source,
                $property,
            ));

            return null;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $values
     * @return list<string>|null
     */
    private function readStringArray(
        array $values,
        string $property,
        bool $nonEmpty,
        SourceFile $source,
        DiagnosticBag $diagnostics,
    ): ?array {
        if (!array_key_exists($property, $values)) {
            return $nonEmpty ? null : [];
        }

        $value = $values[$property];

        if (!is_array($value)) {
            $diagnostics->add($this->createDiagnostic(
                DiagnosticCode::InvalidConfigurationPropertyType,
                sprintf('The property "%s" must be an array of strings.', $property),
                $source,
                $property,
            ));

            return null;
        }

        if ($nonEmpty && $value === []) {
            $diagnostics->add($this->createDiagnostic(
                DiagnosticCode::InvalidConfigurationPropertyType,
                sprintf('The property "%s" must contain at least one path.', $property),
                $source,
                $property,
            ));

            return null;
        }

        $strings = [];

        foreach ($value as $entry) {
            if (!is_string($entry) || $entry === '') {
                $diagnostics->add($this->createDiagnostic(
                    DiagnosticCode::InvalidConfigurationPropertyType,
                    sprintf('Every entry in "%s" must be a non-empty string.', $property),
                    $source,
                    $property,
                ));

                return null;
            }

            $strings[] = $entry;
        }

        if (count(array_unique($strings)) !== count($strings)) {
            $diagnostics->add($this->createDiagnostic(
                DiagnosticCode::InvalidConfigurationPropertyType,
                sprintf('The property "%s" contains duplicate entries.', $property),
                $source,
                $property,
            ));

            return null;
        }

        return $strings;
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function resolvePaths(string $projectRoot, array $paths): array
    {
        return array_map(
            static fn (string $path): string => Path::resolveAbsolute($path, $projectRoot),
            $paths,
        );
    }

    /** @param list<string> $paths */
    private function validateResolvedDuplicates(
        string $property,
        array $paths,
        SourceFile $source,
        DiagnosticBag $diagnostics,
    ): void {
        $keys = array_map(Path::buildComparisonKey(...), $paths);

        if (count(array_unique($keys)) === count($keys)) {
            return;
        }

        $diagnostics->add($this->createDiagnostic(
            DiagnosticCode::InvalidConfigurationPropertyType,
            sprintf('The property "%s" contains paths that resolve to the same location.', $property),
            $source,
            $property,
        ));
    }

    /**
     * @param list<string> $sourceRoots
     * @param list<string> $stubPaths
     */
    private function validatePaths(
        string $projectRoot,
        string $configurationPath,
        SourceFile $source,
        array $sourceRoots,
        string $outputPath,
        string $cachePath,
        array $stubPaths,
        bool $requireSourceDirectories,
        DiagnosticBag $diagnostics,
    ): void {
        foreach ([...$sourceRoots, $outputPath, $cachePath, ...$stubPaths] as $path) {
            if (!Path::contains($projectRoot, $path)) {
                $diagnostics->add($this->createDiagnostic(
                    DiagnosticCode::UnsafeProjectPath,
                    sprintf('The configured path "%s" is outside the project root.', $path),
                    $source,
                    help: 'Use a path contained by the project root.',
                ));
            }
        }

        foreach (['output' => $outputPath, 'cache' => $cachePath] as $property => $ownedPath) {
            if (Path::buildComparisonKey($ownedPath) === Path::buildComparisonKey($projectRoot)) {
                $diagnostics->add($this->createDiagnostic(
                    DiagnosticCode::UnsafeProjectPath,
                    sprintf('The configured %s path cannot be the project root.', $property),
                    $source,
                    $property,
                ));
            }

            if (Path::hasSymlinkAncestor($ownedPath, $projectRoot)) {
                $diagnostics->add($this->createDiagnostic(
                    DiagnosticCode::UnsafeProjectPath,
                    sprintf('The configured %s path passes through a symbolic link.', $property),
                    $source,
                    $property,
                ));
            }

            if (Path::contains($ownedPath, $configurationPath)) {
                $diagnostics->add($this->createDiagnostic(
                    DiagnosticCode::ConfiguredPathsOverlap,
                    sprintf('The configured %s path contains the project configuration.', $property),
                    $source,
                    $property,
                ));
            }
        }

        foreach ($sourceRoots as $sourceRoot) {
            if (Path::overlaps($outputPath, $sourceRoot)) {
                $this->addOverlapDiagnostic('output', 'source', $source, $diagnostics);
            }

            if (Path::overlaps($cachePath, $sourceRoot)) {
                $this->addOverlapDiagnostic('cache', 'source', $source, $diagnostics);
            }

            if ($requireSourceDirectories) {
                if (!file_exists($sourceRoot)) {
                    $diagnostics->add($this->createDiagnostic(
                        DiagnosticCode::SourcePathDoesNotExist,
                        sprintf('The configured source path "%s" does not exist.', Path::resolveRelativeTo($sourceRoot, $projectRoot)),
                        $source,
                        'source',
                    ));
                } elseif (!is_dir($sourceRoot)) {
                    $diagnostics->add($this->createDiagnostic(
                        DiagnosticCode::SourcePathNotDirectory,
                        sprintf('The configured source path "%s" is not a directory.', Path::resolveRelativeTo($sourceRoot, $projectRoot)),
                        $source,
                        'source',
                    ));
                } else {
                    $realSourceRoot = realpath($sourceRoot);

                    if ($realSourceRoot === false || !Path::contains($projectRoot, Path::normalize($realSourceRoot))) {
                        $diagnostics->add($this->createDiagnostic(
                            DiagnosticCode::UnsafeProjectPath,
                            sprintf('The configured source path "%s" resolves outside the project root.', Path::resolveRelativeTo($sourceRoot, $projectRoot)),
                            $source,
                            'source',
                        ));
                    }
                }
            }
        }

        foreach ($stubPaths as $stubPath) {
            if (Path::overlaps($outputPath, $stubPath)) {
                $this->addOverlapDiagnostic('output', 'stubs', $source, $diagnostics);
            }

            if (Path::overlaps($cachePath, $stubPath)) {
                $this->addOverlapDiagnostic('cache', 'stubs', $source, $diagnostics);
            }
        }

        if (Path::overlaps($outputPath, $cachePath)) {
            $this->addOverlapDiagnostic('output', 'cache', $source, $diagnostics);
        }
    }

    private function addOverlapDiagnostic(
        string $first,
        string $second,
        SourceFile $source,
        DiagnosticBag $diagnostics,
    ): void {
        $diagnostics->add($this->createDiagnostic(
            DiagnosticCode::ConfiguredPathsOverlap,
            sprintf('The configured %s and %s paths overlap.', $first, $second),
            $source,
            $first,
            'Choose separate output and cache paths so generated files cannot affect protected project files.',
        ));
    }

    /** @param array<string, mixed> $debug */
    private function createDiagnostic(
        DiagnosticCode $code,
        string $message,
        ?SourceFile $source = null,
        ?string $property = null,
        ?string $help = null,
        array $debug = [],
    ): Diagnostic {
        $primary = null;

        if ($source !== null) {
            $start = 0;
            $end = min(1, $source->length);

            if ($property !== null) {
                $needle = '"' . $property . '"';
                $propertyOffset = strpos($source->contents, $needle);

                if ($propertyOffset !== false) {
                    $start = $propertyOffset;
                    $end = $propertyOffset + strlen($needle);
                }
            }

            $primary = new DiagnosticLabel(
                $source->createSpan($start, $end),
                $message,
            );
        }

        return new Diagnostic(
            $code,
            $message,
            $primary,
            help: $help,
            debug: $debug,
        );
    }
}
