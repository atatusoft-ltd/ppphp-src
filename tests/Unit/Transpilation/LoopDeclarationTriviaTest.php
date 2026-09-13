<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Frontend\PpphpParser;
use Atatusoft\Ppphp\Project\ProjectParseResult;
use Atatusoft\Ppphp\Semantic\SemanticAnalyzer;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;
use Atatusoft\Ppphp\Transpilation\PhpLowerer;
use Symfony\Component\Process\Process;

test('loop type erasure removes obsolete separators but preserves comments and line breaks', function (
    string $header, string $expected,
): void {
    $source = new SourceFile('/project/main.ppphp', 'main.ppphp', FileKind::Ppphp,
        '<?php ' . $header . ' { echo $item, "|"; }');
    $parsed = (new PpphpParser())->parse($source);
    $analysis = (new SemanticAnalyzer())->analyze(new ProjectParseResult(
        [$source->path => $parsed->parsedFile], [$source->path => $source], $parsed->diagnostics,
    ));
    expect($analysis->isSuccessful)->toBeTrue();
    $generated = (new PhpLowerer())->lower($parsed->parsedFile, $analysis->findModel($source->path));
    expect($generated->contents)->toContain($expected)
        ->and($generated->contents)->toContain('@var int $item');
    foreach (['Keep this explanation.', 'Keep this key.'] as $comment) {
        expect(substr_count($generated->contents, $comment))->toBe(substr_count($source->contents, $comment));
    }
    $run = new Process([PHP_BINARY, '-r', substr($generated->contents, strlen('<?php'))], timeout: 5);
    $run->mustRun();
    expect($run->getOutput())->toBe('0|1|2|')->and($run->getErrorOutput())->toBe('');
})->with([
    'literal foreach input' => ['foreach ([0, 1, 2] as int $item)', 'foreach ([0, 1, 2] as $item)'],
    'typed key and value' => ['foreach ([0, 1, 2] as int $key => int $item)', 'foreach ([0, 1, 2] as $key => $item)'],
    'for initializer' => ['for (int $item = 0; $item < 3; ++$item)', 'for ($item = 0; $item < 3; ++$item)'],
    'foreach block comment' => [
        'foreach ([0, 1, 2] as int /* Keep this explanation. */ $item)',
        'foreach ([0, 1, 2] as /* Keep this explanation. */ $item)',
    ],
    'both binding comments' => [
        'foreach ([0, 1, 2] as int /* Keep this key. */ $key => int /* Keep this explanation. */ $item)',
        'foreach ([0, 1, 2] as /* Keep this key. */ $key => /* Keep this explanation. */ $item)',
    ],
    'for block comment' => [
        'for (int /* Keep this explanation. */ $item = 0; $item < 3; ++$item)',
        'for (/* Keep this explanation. */ $item = 0; $item < 3; ++$item)',
    ],
    'line comment' => [
        "foreach ([0, 1, 2] as int // Keep this explanation.\n    \$item)",
        "foreach ([0, 1, 2] as // Keep this explanation.\n    \$item)",
    ],
    'uncommented line break' => [
        "foreach ([0, 1, 2] as int\n    \$item)",
        "foreach ([0, 1, 2] as \n    \$item)",
    ],
    'CRLF line break' => [
        "for (int\r\n    \$item = 0; \$item < 3; ++\$item)",
        "for (\r\n    \$item = 0; \$item < 3; ++\$item)",
    ],
]);
