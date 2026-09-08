<?php

declare(strict_types=1);

namespace Tests\Support;

use Atatusoft\Ppphp\Compiler\Compiler;
use Atatusoft\Ppphp\Support\CanonicalJson;
use Atatusoft\Ppphp\Versioning\ReleaseSchema;
use Atatusoft\Ppphp\Versioning\ReleaseVersion;
use Symfony\Component\Process\Process;

final class ReleasePreparationFixture
{
    /** Snapshot the working source, without dependencies or real release refs. */
    public static function create(string $root, string $identity): void
    {
        $repository = dirname(__DIR__, 2);
        $files = new Process(['git', 'ls-files', '-z', '--cached', '--others', '--exclude-standard'], $repository);
        $files->mustRun();

        foreach (array_filter(explode("\0", $files->getOutput())) as $relative) {
            self::write($root . '/' . $relative, (string) file_get_contents($repository . '/' . $relative));
        }

        $version = ReleaseVersion::parse($identity);
        $schema = new ReleaseSchema($version);
        $manifest = json_decode((string) file_get_contents($root . '/resources/release/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $manifest['version'] = $identity;
        $manifest['tag'] = $identity;
        $manifest['channel'] = $version->channel->value;
        $manifest['prerelease'] = $version->isPrerelease;
        // Deliberately not a version-derived path: the loader owns this choice.
        $manifest['releaseNotes'] = 'docs/releases/selected-notes.md';
        self::write($root . '/' . $manifest['releaseNotes'], "# ++PHP $identity\n\nFixed nullable assignment diagnostics.\n");
        $schemaDocument = json_decode((string) file_get_contents($root . '/resources/schema/ppphp.schema.json'), true, flags: JSON_THROW_ON_ERROR);
        $schemaDocument['$id'] = $schema->url;
        $schemaBytes = CanonicalJson::encode($schemaDocument);
        self::write($root . '/resources/schema/ppphp.schema.json', $schemaBytes);
        $manifest['schema']['url'] = $schema->url;
        $manifest['schema']['sha256'] = 'sha256:' . hash('sha256', $schemaBytes);
        self::write($root . '/resources/release/manifest.json', CanonicalJson::encode($manifest));
        $compiler = (string) file_get_contents($root . '/src/Compiler/Compiler.php');
        self::write($root . '/src/Compiler/Compiler.php', str_replace("'" . Compiler::VERSION . "'", "'" . $identity . "'", $compiler));

        // Subprocesses select the fixture's compiler identity and reuse installed
        // classes; no dependency download, mutable symlink, or source edit.
        self::write($root . '/vendor/autoload.php', "<?php\nrequire dirname(__DIR__) . '/src/Compiler/Compiler.php';\nrequire "
            . var_export($repository . '/vendor/autoload.php', true) . ";\n");
    }

    private static function write(string $path, string $contents): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }

        file_put_contents($path, $contents);
    }
}
