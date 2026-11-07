<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Frontend\PpphpParser;
use Atatusoft\Ppphp\Interop\Php\Signature\PhpSignatureModuleReferences;
use Atatusoft\Ppphp\Interop\Php\Signature\PhpSignaturePackageLoader;
use Atatusoft\Ppphp\Interop\Php\Signature\PhpSignaturePackageVerifier;
use Atatusoft\Ppphp\Interop\Php\Signature\PhpSignaturePhpDocIndex;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;
use Atatusoft\Ppphp\Support\CanonicalJson;
use Tests\Support\StageElevenProject;

test('indexed module selection agrees with the legacy path across project edits', function (): void {
    $root = $this->createTemporaryDirectory();
    $package = $root . '/8.4';
    StageElevenProject::copyTree(dirname(__DIR__, 3) . '/resources/php-signatures/8.4', $package);
    $manifestPath = $package . '/manifest.json';
    $manifest = CanonicalJson::decode(file_get_contents($manifestPath));
    $manifest['outputs'] = array_values(array_filter(
        $manifest['outputs'],
        static fn (array $output): bool => !in_array($output['path'], [PhpSignatureModuleReferences::PATH, PhpSignaturePhpDocIndex::PATH], true),
    ));
    file_put_contents($manifestPath, CanonicalJson::encode($manifest));
    expect(hash_file('sha256', $manifestPath))->toBe('fc8acea749a472694be41c0d2a61a1778f6f127ecbfcabc0c00efa90a01acec2');
    file_put_contents($package . '/' . PhpSignatureModuleReferences::PATH, 'ignored without a manifest record');

    $indexed = new PhpSignaturePackageLoader();
    $legacy = new PhpSignaturePackageLoader($root);
    $parser = new PpphpParser();
    $cases = [
        '<?php json_encode([new DateTimeImmutable()]);' => '/ext/json/',
        '<?php curl_init(); gzencode("hello");' => '/ext/curl/',
        '<?php function project(PDOStatement $statement): void {}' => '/ext/pdo/',
    ];
    foreach ($cases as $source => $module) {
        $project = $parser->parse(new SourceFile('/project/index.ppphp', 'index.ppphp', FileKind::Ppphp, $source));
        expect($project->parsedFile)->not->toBeNull();
        $displayPaths = static fn (array $files): array => array_values(array_map(
            static fn ($file): string => $file->sourceFile->displayPath,
            $files,
        ));
        $actual = $indexed->load('8.4', [$project->parsedFile]);
        $expected = $legacy->load('8.4', [$project->parsedFile]);
        expect($actual->isSuccessful)->toBeTrue()
            ->and($expected->isSuccessful)->toBeTrue()
            ->and($displayPaths($actual->parsedFiles))->toBe($displayPaths($expected->parsedFiles))
            ->and(implode("\n", $displayPaths($actual->parsedFiles)))->toContain($module);
    }
});

test('a changed graph fails the reviewed hash and a rehashed false graph fails the structural audit', function (): void {
    $root = $this->createTemporaryDirectory();
    $package = $root . '/8.4';
    StageElevenProject::copyTree(dirname(__DIR__, 3) . '/resources/php-signatures/8.4', $package);
    $loader = new PhpSignaturePackageLoader($root);
    expect($loader->load('8.4', [])->isSuccessful)->toBeTrue();

    $path = $package . '/' . PhpSignatureModuleReferences::PATH;
    $graph = CanonicalJson::decode(file_get_contents($path));
    expect($graph['modules']['core'])->not->toBe([]);
    $graph['modules']['core'] = [];
    $bytes = CanonicalJson::encode($graph);
    file_put_contents($path, $bytes);
    $rejected = $loader->load('8.4', []);
    expect($rejected->parsedFiles)->toBe([])
        ->and($rejected->diagnostics->errors[0]->code)->toBe(DiagnosticCode::PhpSignaturePackageInvalid);

    $manifestPath = $package . '/manifest.json';
    $manifest = CanonicalJson::decode(file_get_contents($manifestPath));
    foreach ($manifest['outputs'] as $index => $output) {
        if ($output['path'] === PhpSignatureModuleReferences::PATH) {
            $manifest['outputs'][$index]['sha256'] = hash('sha256', $bytes);
        }
    }
    file_put_contents($manifestPath, CanonicalJson::encode($manifest));
    expect(fn () => (new PhpSignaturePackageVerifier())->verifyInstalled($package, '8.4'))
        ->toThrow(RuntimeException::class, 'module reference index does not match its shards');
});
