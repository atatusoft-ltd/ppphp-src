<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Transpilation\PrintedNodeMapper;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

test('printed node provenance requires the whole executable structure to match', function (string $replacement): void {
    $statements = (new ParserFactory())->createForNewestSupportedVersion()
        ->parse('<?php try { throw new Error(); } catch (Throwable $error) { throw $error; }');
    $catch = (new NodeFinder())->findFirstInstanceOf($statements, Stmt\Catch_::class);
    $catch->setAttribute('ppphpUnwindCleanup', true);
    expect((new PrintedNodeMapper())->map($statements, $replacement))->toBe([]);
})->with([
    'different caught variable' => 'try { throw new Error(); } catch (Throwable $other) { throw $other; }',
    'additional authored action' => 'try { throw new Error(); } catch (Throwable $error) { echo "observed"; throw $error; }',
    'different throw type' => 'try { throw new Exception(); } catch (Throwable $error) { throw $error; }',
    'invalid output' => 'try {',
]);

test('printed node provenance distinguishes identical authored and generated catch bodies', function (): void {
    $php = 'try { throw new Error(); } catch (Throwable $same) { throw $same; }';
    $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse('<?php ' . $php . $php);
    $catches = (new NodeFinder())->findInstanceOf($statements, Stmt\Catch_::class);
    $catches[1]->setAttribute('ppphpUnwindCleanup', true);
    $pairs = (new PrintedNodeMapper())->map($statements, $php . "\n/* preserve this comment */\n" . $php);
    $marked = [];
    foreach ($pairs as [$original, $printed]) {
        if ($original->getAttribute('ppphpUnwindCleanup') === true) {
            $marked[] = $printed;
        }
    }
    expect($marked)->toHaveCount(1)
        ->and($marked[0]->getStartFilePos())->toBeGreaterThan(strlen($php));
});
