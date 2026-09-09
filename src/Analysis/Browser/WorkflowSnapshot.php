<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Browser;

use Atatusoft\Ppphp\Compiler\Output\NativeBuildFilesystem;
use Atatusoft\Ppphp\Project\Project;
use Atatusoft\Ppphp\Support\Path;

final readonly class WorkflowSnapshot
{
    public const int MAXIMUM_FILES = 1024;
    public const int MAXIMUM_BYTES = 16_777_216;

    public function __construct(private NativeBuildFilesystem $filesystem = new NativeBuildFilesystem()) {}

    public function identifyProject(Project $project): string
    {
        $configuration = $project->configuration;
        $sourceBytes = 0;
        $sources = [...$project->sources->files, ...$project->stubs->files];
        if (count($sources) > 64) {
            throw new \InvalidArgumentException('The workflow source count exceeds 64 files.');
        }
        foreach ($sources as $source) {
            $sourceBytes += strlen($this->filesystem->readFileBounded($source->path, 1_048_576));
        }
        if ($sourceBytes > 1_048_576) {
            throw new \InvalidArgumentException('The workflow source payload exceeds 1 MiB.');
        }
        return $this->identifyTree($configuration->projectRoot, [
            $configuration->outputPath, $configuration->cachePath,
            Path::join($configuration->projectRoot, '.ppphp-browser'),
            Path::join($configuration->projectRoot, '.ppphp-operation.lock'),
            Path::join($configuration->projectRoot, '.ppphp-build-transaction.json'),
        ]);
    }

    /** @param list<string> $exclude */
    public function identifyTree(string $root, array $exclude = []): string
    {
        return ProtocolJson::hash(ProtocolJson::encodeCanonical(['files' => $this->collectTree($root, $exclude)]));
    }

    /**
     * @param list<string> $exclude
     * @return list<array{path: string, bytes: int, hash: string, mode: int, kind: string}>
     */
    public function collectTree(string $root, array $exclude = []): array
    {
        $records = [];
        $bytes = 0;
        if (is_link($root) || (file_exists($root) && !is_dir($root))) {
            throw new \InvalidArgumentException('A workflow tree must be a regular directory.');
        }
        if (is_dir($root)) {
            $this->collect($root, $root, $exclude, $records, $bytes);
        }
        usort($records, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));
        return $records;
    }

    /**
     * @param list<string> $exclude
     * @param list<array{path: string, bytes: int, hash: string, mode: int, kind: string}> $records
     */
    private function collect(string $root, string $directory, array $exclude, array &$records, int &$bytes): void
    {
        foreach (new \DirectoryIterator($directory) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            $path = Path::normalize($entry->getPathname());
            if (in_array($path, $exclude, true)) {
                continue;
            }
            if ($entry->isLink() || (!$entry->isFile() && !$entry->isDir())) {
                throw new \InvalidArgumentException('Workflow inputs cannot contain symbolic links or special files.');
            }
            $relative = WorkflowValues::readPath(Path::resolveRelativeTo($path, $root));
            $contents = $entry->isDir() ? '' : $this->filesystem->readFileBounded($path, self::MAXIMUM_BYTES - $bytes);
            $bytes += strlen($contents);
            $records[] = ['path' => $relative, 'bytes' => strlen($contents), 'hash' => ProtocolJson::hash($contents),
                'mode' => $entry->getPerms() & 0777, 'kind' => $entry->isDir() ? 'directory' : 'file'];
            if (count($records) > self::MAXIMUM_FILES || $bytes > self::MAXIMUM_BYTES) {
                throw new \InvalidArgumentException('The workflow tree exceeds its file or byte limit.');
            }
            if ($entry->isDir()) {
                $this->collect($root, $path, $exclude, $records, $bytes);
            }
        }
    }
}
