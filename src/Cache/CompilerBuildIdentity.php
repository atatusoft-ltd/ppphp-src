<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Cache;

use Atatusoft\Ppphp\Compiler\Compiler;
use Atatusoft\Ppphp\Compiler\Manifest\BuildManifest;
use Atatusoft\Ppphp\Interop\Composer\Index\DependencyDeclarationIndexWriter;
use Atatusoft\Ppphp\Support\CanonicalJson;
use Atatusoft\Ppphp\Support\Path;
use Atatusoft\Ppphp\Transpilation\SourceMapWriter;

final class CompilerBuildIdentity
{
    private ?string $memoized = null;

    public function __construct(private readonly ?string $installationRoot = null) {}

    public function calculate(): string
    {
        if ($this->memoized !== null) {
            return $this->memoized;
        }

        $root = Path::normalize($this->installationRoot ?? dirname(__DIR__, 2));
        $paths = [
            'bin/ppphp',
            'composer.lock',
            'resources/php-signatures/8.4/manifest.json',
            'resources/php-signatures/8.4/overrides.json',
            'resources/phpstan/ppphp.neon',
            'resources/phpstan/extensions.php',
            'resources/release/manifest.json',
            'resources/schema/ppphp.schema.json',
        ];
        $sourceRoot = Path::join($root, 'src');

        if (!is_dir($sourceRoot) || is_link($sourceRoot)) {
            throw new \RuntimeException('The compiler source installation cannot be identified safely.');
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $sourceRoot,
            \FilesystemIterator::SKIP_DOTS,
        ));

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }

            if ($file->isFile() && !$file->isLink() && str_ends_with(strtolower($file->getFilename()), '.php')) {
                $paths[] = str_replace('\\', '/', Path::resolveRelativeTo($file->getPathname(), $root));
            }
        }

        sort($paths, SORT_STRING);
        $inputs = [];

        foreach (array_values(array_unique($paths)) as $relativePath) {
            $path = Path::join($root, $relativePath);

            if (!is_file($path) || is_link($path)) {
                throw new \RuntimeException(sprintf('Compiler identity input "%s" is unavailable.', $relativePath));
            }

            $contents = file_get_contents($path);

            if (!is_string($contents)) {
                throw new \RuntimeException(sprintf('Compiler identity input "%s" is unreadable.', $relativePath));
            }

            $inputs[] = [
                'path' => $relativePath,
                'sha256' => hash('sha256', $contents),
            ];
        }

        $payload = [
            'files' => $inputs,
            'formats' => [
                'artifact' => CacheFormat::ARTIFACT,
                'buildManifest' => BuildManifest::FORMAT_VERSION,
                'cache' => CacheFormat::COMPILER,
                'declaration' => DependencyDeclarationIndexWriter::DECLARATION_FORMAT_VERSION,
                'dependencyIndex' => DependencyDeclarationIndexWriter::FORMAT_VERSION,
                'diagnostic' => CacheFormat::DIAGNOSTIC,
                'lowering' => Compiler::LOWERING_FORMAT_VERSION,
                'processPolicy' => CacheFormat::PROCESS_POLICY,
                'sourceMap' => SourceMapWriter::FORMAT_VERSION,
            ],
        ];

        return $this->memoized = 'sha256:' . hash('sha256', CanonicalJson::encode($payload));
    }
}
