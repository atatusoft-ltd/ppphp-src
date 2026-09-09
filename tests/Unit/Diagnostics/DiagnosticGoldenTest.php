<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Diagnostics\ConsoleRenderer;
use Atatusoft\Ppphp\Diagnostics\ConsoleRenderOptions;
use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Diagnostics\DiagnosticLabel;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticOrigin;
use Atatusoft\Ppphp\Diagnostics\JsonRenderer;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;
use Tests\Support\GoldenFile;

function createFamilyGoldenBag(): DiagnosticBag
{
    $contents = file_get_contents(dirname(__DIR__, 2) . '/Fixtures/Diagnostics/Families.ppphp');

    if ($contents === false) {
        throw new RuntimeException('Could not read the diagnostic family fixture.');
    }

    $source = new SourceFile(
        '/project/src/Families.ppphp',
        'src/Families.ppphp',
        FileKind::Ppphp,
        $contents,
    );
    $codes = [
        DiagnosticCode::UnknownConfigurationProperty,
        DiagnosticCode::InvalidPhpSyntax,
        DiagnosticCode::LocalVariableNotDeclared,
        DiagnosticCode::GenericTypeArgumentCountDoesNotMatch,
        DiagnosticCode::CheckedErrorNotHandled,
        DiagnosticCode::WhenBranchDoesNotProduceValue,
        DiagnosticCode::InvalidComposerConfiguration,
        DiagnosticCode::OutputPathCollision,
        DiagnosticCode::InternalCompilerError,
    ];
    $diagnostics = [];

    foreach ($codes as $index => $code) {
        $offset = 6 + ($index % 3) * 9;
        $diagnostics[] = new Diagnostic(
            $code,
            sprintf('Representative %s diagnostic.', $code->value),
            new DiagnosticLabel($source->createSpan($offset, $offset + 5), 'Primary label.'),
            identity: 'golden:' . $code->value,
        );
    }

    return new DiagnosticBag($diagnostics);
}

function createComplexGoldenBag(): DiagnosticBag
{
    $contents = file_get_contents(dirname(__DIR__, 2) . '/Fixtures/Diagnostics/Complex.ppphp');

    if ($contents === false) {
        throw new RuntimeException('Could not read the complex diagnostic fixture.');
    }

    $source = new SourceFile(
        '/project/src/Complex.ppphp',
        'src/Complex.ppphp',
        FileKind::Ppphp,
        $contents,
    );

    return new DiagnosticBag([
        new Diagnostic(
            DiagnosticCode::ReturnTypeDoesNotMatch,
            "The returned expression does not match the declared type.\nThis second line remains aligned and undecorated.",
            new DiagnosticLabel($source->createSpan(8, 119), 'Return expression is incompatible.'),
            [new DiagnosticLabel($source->createSpan(8, 33), 'The return type is declared here.')],
            "Return a compatible value.\nOr update the declared return type.",
            identity: 'complex:return',
        ),
        new Diagnostic(
            DiagnosticCode::UncheckedCallBoundary,
            'A zero-width warning at EOF.',
            new DiagnosticLabel($source->createSpan($source->length, $source->length), 'End of file.'),
            identity: 'complex:eof',
        ),
    ]);
}

function createDebugGoldenBag(): DiagnosticBag
{
    $source = new SourceFile(
        '/project/src/Debug.ppphp',
        'src/Debug.ppphp',
        FileKind::Ppphp,
        "<?php\nunknown();\n",
    );

    return new DiagnosticBag([
        new Diagnostic(
            DiagnosticCode::StaticAnalysisError,
            'The analyzed call could not be resolved.',
            new DiagnosticLabel($source->createSpan(6, 13), 'Unknown call.'),
            help: 'Declare or import the called function.',
            debug: [
                'backendIdentifier' => 'function.notFound',
                'analysisPath' => '/tmp/project/.ppphp-cache/analysis/selected/0123456789abcdef/Debug.php',
            ],
            origin: DiagnosticOrigin::PhpStan,
            identity: 'function:unknown',
        ),
        new Diagnostic(
            DiagnosticCode::InternalCompilerError,
            'The compiler encountered an unexpected failure.',
            help: 'Run the command again with --debug and include the resulting details when reporting the issue.',
            debug: [
                'exception' => LogicException::class,
                'message' => 'Controlled failure.',
                'resource' => fopen('php://memory', 'rb'),
            ],
        ),
    ]);
}

test('diagnostic families retain stable console and JSON output', function (): void {
    $bag = createFamilyGoldenBag();

    GoldenFile::assertMatches(
        dirname(__DIR__, 2) . '/Golden/Diagnostics/Console/families.txt',
        (new ConsoleRenderer())->render($bag, new ConsoleRenderOptions(terminalWidth: 80)),
    );
    GoldenFile::assertMatches(
        dirname(__DIR__, 2) . '/Golden/Diagnostics/Json/families.json',
        (new JsonRenderer())->render($bag),
    );
});

test('complex diagnostic layout retains stable plain and decorated output', function (): void {
    $bag = createComplexGoldenBag();
    $plain = (new ConsoleRenderer())->render($bag, new ConsoleRenderOptions(terminalWidth: 64));
    $decorated = (new ConsoleRenderer())->render(
        $bag,
        new ConsoleRenderOptions(decorated: true, terminalWidth: 64),
    );

    GoldenFile::assertMatches(
        dirname(__DIR__, 2) . '/Golden/Diagnostics/Console/complex.txt',
        $plain,
    );
    GoldenFile::assertMatches(
        dirname(__DIR__, 2) . '/Golden/Diagnostics/Json/complex.json',
        (new JsonRenderer())->render($bag),
    );

    expect($decorated)->toContain("\e[31;1m", "\e[33;1m", "\e[32mReturn a compatible value.")
        ->and(preg_replace('/\e\[[0-9;]*m/', '', $decorated))->toBe($plain);
});

test('empty and debug diagnostic contracts retain exact golden output', function (): void {
    GoldenFile::assertMatches(
        dirname(__DIR__, 2) . '/Golden/Diagnostics/Json/empty.json',
        (new JsonRenderer())->render(new DiagnosticBag()),
    );

    $bag = createDebugGoldenBag();
    GoldenFile::assertMatches(
        dirname(__DIR__, 2) . '/Golden/Diagnostics/Console/debug.txt',
        (new ConsoleRenderer())->render($bag, new ConsoleRenderOptions(includeDebug: true, terminalWidth: 80)),
    );
    GoldenFile::assertMatches(
        dirname(__DIR__, 2) . '/Golden/Diagnostics/Json/debug.json',
        (new JsonRenderer())->render($bag, true),
    );
});
