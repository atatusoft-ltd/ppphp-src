<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Diagnostics\ConsoleRenderer;
use Atatusoft\Ppphp\Diagnostics\ConsoleRenderOptions;
use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Diagnostics\DiagnosticCatalog;
use Atatusoft\Ppphp\Diagnostics\DiagnosticLabel;
use Atatusoft\Ppphp\Diagnostics\DiagnosticProcessor;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticFamily;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticOrigin;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticStatus;
use Atatusoft\Ppphp\Diagnostics\Enumerations\Severity;
use Atatusoft\Ppphp\Diagnostics\JsonRenderer;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;

test('the catalog defines every code once with canonical metadata', function (): void {
    $definitions = DiagnosticCatalog::definitions();
    $expectedReserved = [
        DiagnosticCode::CompilerFrontendNotAvailable,
        DiagnosticCode::DirectoryCompilationUnavailable,
        DiagnosticCode::PhpSourceIsNotBuildTarget,
        DiagnosticCode::TypedLocalSyntaxNotActive,
        DiagnosticCode::CompositeTypeIsNotAssignable,
        DiagnosticCode::GenericSyntaxNotActive,
        DiagnosticCode::ThrowsSyntaxNotActive,
        DiagnosticCode::WhenSyntaxNotActive,
        DiagnosticCode::GeneratedPhpCouldNotBeWritten,
    ];

    expect(array_keys($definitions))->toBe(array_map(
        static fn (DiagnosticCode $code): string => $code->value,
        DiagnosticCode::cases(),
    ))->and(array_values(array_filter(
        DiagnosticCode::cases(),
        static fn (DiagnosticCode $code): bool => $definitions[$code->value]->status === DiagnosticStatus::Reserved,
    )))->toBe($expectedReserved)
        ->and($definitions[DiagnosticCode::InvalidInvocation->value]->family)->toBe(DiagnosticFamily::Project)
        ->and($definitions[DiagnosticCode::UncheckedCallBoundary->value]->severity)->toBe(Severity::Warning)
        ->and($definitions[DiagnosticCode::PropertyIsNeverRead->value]->severity)->toBe(Severity::Warning)
        ->and($definitions[DiagnosticCode::InvalidPhpSyntax->value]->title)->toBe('Invalid PHP Syntax')
        ->and($definitions[DiagnosticCode::CompilerFrontendNotAvailable->value]->status)->toBe(DiagnosticStatus::Reserved);

    foreach ($definitions as $definition) {
        expect($definition->title)->toMatch('/^[A-Z0-9][A-Za-z0-9]*(?:-[A-Z0-9][A-Za-z0-9]*)?(?: [A-Z0-9][A-Za-z0-9]*(?:-[A-Z0-9][A-Za-z0-9]*)?)*$/')
            ->and($definition->family->value)->toBe(match ($definition->code->value[1]) {
                '0' => 'project',
                '1' => 'syntax',
                '2' => 'type',
                '3' => 'generic',
                '4' => 'checked-error',
                '5' => 'when',
                '6' => 'interop',
                '7' => 'emission',
                '9' => 'internal',
            });

        if ($definition->status === DiagnosticStatus::Active) {
            expect((new Diagnostic($definition->code, 'Representative diagnostic.'))->help)->not->toBeNull()->not->toBe('');
        }
    }
});

test('the documented catalog matches every code definition', function (): void {
    $documentation = file_get_contents(dirname(__DIR__, 3) . '/docs/diagnostics.md');

    expect($documentation)->not->toBeFalse();

    if (!is_string($documentation)) {
        return;
    }

    foreach (DiagnosticCatalog::definitions() as $definition) {
        expect($documentation)->toContain(sprintf(
            '| `%s` | `%s` | `%s` | `%s` | %s |',
            $definition->code->value,
            $definition->family->value,
            $definition->status->value,
            $definition->severity->value,
            $definition->title,
        ));
    }

    expect(substr_count($documentation, '| `P'))->toBe(count(DiagnosticCode::cases()));
});

test('production construction sites do not author severity or title metadata', function (): void {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/src'));

    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        expect($contents)->not->toBeFalse();

        if (!is_string($contents)) {
            continue;
        }

        expect($contents)->not->toMatch('/new Diagnostic\(\s*[^,]+,\s*Severity::/s');
    }
});

test('diagnostics derive catalog metadata and reject reserved codes', function (): void {
    $diagnostic = new Diagnostic(DiagnosticCode::InvalidInvocation, 'Bad input.');

    expect($diagnostic->severity)->toBe(Severity::Error)
        ->and($diagnostic->title)->toBe('Invalid Invocation')
        ->and($diagnostic->family)->toBe(DiagnosticFamily::Project)
        ->and($diagnostic->origin)->toBe(DiagnosticOrigin::Compiler);

    expect(fn (): Diagnostic => new Diagnostic(
        DiagnosticCode::CompilerFrontendNotAvailable,
        'Reserved.',
    ))->toThrow(LogicException::class, 'P0010');
});

test('diagnostic bags preserve collection order and expose canonical severity queries', function (): void {
    $error = new Diagnostic(DiagnosticCode::InvalidInvocation, 'Bad input.');
    $warning = new Diagnostic(DiagnosticCode::UncheckedCallBoundary, 'Careful.');
    $bag = new DiagnosticBag([$warning, $error]);

    expect($bag)->toHaveCount(2)
        ->and($bag->hasErrors)->toBeTrue()
        ->and($bag->errors)->toBe([$error])
        ->and($bag->warnings)->toBe([$warning])
        ->and($bag->notes)->toBe([])
        ->and($bag->toArray())->toBe([$warning, $error]);
});

test('the processor sorts deterministically without collapsing distinct same-line findings', function (): void {
    $source = new SourceFile('/project/main.ppphp', 'src/main.ppphp', FileKind::Ppphp, '<?php bad();');
    $first = new Diagnostic(
        DiagnosticCode::MethodDoesNotExist,
        'First issue.',
        new DiagnosticLabel($source->createSpan(6, 9), 'First'),
        identity: 'first',
    );
    $second = new Diagnostic(
        DiagnosticCode::PropertyDoesNotExist,
        'Second issue.',
        new DiagnosticLabel($source->createSpan(6, 9), 'Second'),
        identity: 'second',
    );
    $warning = new Diagnostic(DiagnosticCode::UncheckedCallBoundary, 'Warning.');
    $processed = (new DiagnosticProcessor())->process(new DiagnosticBag([$warning, $second, $first, $first]));

    expect(array_map(static fn (Diagnostic $item): DiagnosticCode => $item->code, $processed->toArray()))->toBe([
        DiagnosticCode::MethodDoesNotExist,
        DiagnosticCode::PropertyDoesNotExist,
        DiagnosticCode::UncheckedCallBoundary,
    ]);
});

test('the processor validates and sanitizes normal user-facing content', function (): void {
    $diagnostic = new Diagnostic(
        DiagnosticCode::StaticAnalysisError,
        "An error occurred in .ppphp-cache/analysis/selected/0123456789abcdef/Main.php\e",
        help: 'Run with --debug for details.',
        origin: DiagnosticOrigin::PhpStan,
    );
    $processed = (new DiagnosticProcessor())->process(new DiagnosticBag([$diagnostic]))->toArray();

    expect($processed)->toHaveCount(1)
        ->and($processed[0]->message)->not->toContain('.ppphp-cache/analysis', "\e")
        ->and($processed[0]->message)->toContain('compiler workspace', '\\x1B')
        ->and($processed[0]->help)->toBe('Run with --debug for details.');
});

test('sanitization preserves identifiers and literal words instead of rewriting diagnostic evidence', function (): void {
    $message = 'App\\PHPStanClient accepts App\\PhpParser, not the literal "normalized PHP".';
    $diagnostic = new Diagnostic(DiagnosticCode::ArgumentTypeDoesNotMatch, $message, help: 'Pass App\\PHPStanClient.');
    $processed = (new DiagnosticProcessor())->process(new DiagnosticBag([$diagnostic]))->errors[0];

    expect($processed->message)->toBe($message)
        ->and($processed->help)->toBe('Pass App\\PHPStanClient.');
});

test('compiler-owned findings suppress only the corresponding backend fallback', function (): void {
    $source = new SourceFile('/project/main.ppphp', 'src/main.ppphp', FileKind::Ppphp, '<?php $x; $y;');
    $compiler = new Diagnostic(
        DiagnosticCode::LocalVariableNotDeclared,
        'Variable $x is not declared.',
        new DiagnosticLabel($source->createSpan(6, 8), 'Compiler'),
        identity: 'variable:$x',
    );
    $backendDuplicate = new Diagnostic(
        DiagnosticCode::StaticAnalysisError,
        'Undefined variable: $x',
        new DiagnosticLabel($source->createSpan(6, 8), 'Backend'),
        origin: DiagnosticOrigin::PhpStan,
        identity: 'variable:$x',
    );
    $backendDistinct = new Diagnostic(
        DiagnosticCode::StaticAnalysisError,
        'Undefined variable: $y',
        new DiagnosticLabel($source->createSpan(10, 12), 'Backend'),
        origin: DiagnosticOrigin::PhpStan,
        identity: 'variable:$y',
    );
    $processed = (new DiagnosticProcessor())->process(new DiagnosticBag([$backendDistinct, $backendDuplicate, $compiler]));

    expect($processed->toArray())->toHaveCount(2)
        ->and($processed->toArray())->toContain($compiler, $backendDistinct);
});

test('console diagnostics render contextual source frames related labels help and multiline ranges', function (): void {
    $source = new SourceFile('/project/ppphp.json', 'ppphp.json', FileKind::Configuration, "{\n\t\"bad\": true,\n  \"next\": false\n}\n");
    $primary = new DiagnosticLabel($source->createSpan(3, 26), 'This property is not supported.');
    $related = new DiagnosticLabel($source->createSpan(0, 1), 'Configuration object.');
    $diagnostic = new Diagnostic(
        DiagnosticCode::UnknownConfigurationProperty,
        "The property \"bad\" is not supported.\nRemove it before continuing.",
        $primary,
        [$related],
        "Remove \"bad\".\nKeep supported properties only.",
    );
    $rendered = (new ConsoleRenderer())->render(new DiagnosticBag([$diagnostic]));

    expect($rendered)->toContain('Error[P0004]: Unknown Configuration Property')
        ->and($rendered)->toContain('--> ppphp.json:2:2')
        ->and($rendered)->toContain('2 |     "bad": true,')
        ->and($rendered)->toContain('This property is not supported.')
        ->and($rendered)->toContain('Related: Configuration object.')
        ->and($rendered)->toContain('Help: Remove "bad".')
        ->and($rendered)->not->toContain("\e[");
});

test('console decoration is explicit and control bytes are escaped', function (): void {
    $diagnostic = new Diagnostic(DiagnosticCode::InternalCompilerError, "Unsafe \e[31mtext");
    $plain = (new ConsoleRenderer())->render(new DiagnosticBag([$diagnostic]));
    $decorated = (new ConsoleRenderer())->render(
        new DiagnosticBag([$diagnostic]),
        new ConsoleRenderOptions(decorated: true, terminalWidth: 40),
    );

    expect($plain)->toContain('Unsafe \\x1B[31mtext')->not->toContain("\e[")
        ->and($decorated)->toContain("\e[31;1m")
        ->and($decorated)->toContain('Unsafe \\x1B[31mtext');
});

test('long source lines are clipped around the highlighted region', function (): void {
    $contents = '<?php ' . str_repeat('a', 70) . ' TARGET ' . str_repeat('z', 30);
    $source = new SourceFile('/project/long.ppphp', 'src/long.ppphp', FileKind::Ppphp, $contents);
    $start = strpos($contents, 'TARGET');

    expect($start)->not->toBeFalse();

    if (!is_int($start)) {
        return;
    }

    $diagnostic = new Diagnostic(
        DiagnosticCode::TypeDoesNotExist,
        'The highlighted type is unknown.',
        new DiagnosticLabel($source->createSpan($start, $start + 6), 'Unknown type.'),
    );
    $rendered = (new ConsoleRenderer())->render(
        new DiagnosticBag([$diagnostic]),
        new ConsoleRenderOptions(terminalWidth: 40),
    );

    expect($rendered)->toContain('…', 'TARGET', '^^^^^^ Unknown type.');
});

test('source frames align tabs Unicode CRLF controls and empty EOF spans', function (): void {
    $contents = "\tλ\evalue();\r\n";
    $source = new SourceFile('/project/alignment.ppphp', 'src/alignment.ppphp', FileKind::Ppphp, $contents);
    $start = strpos($contents, 'value');

    expect($start)->not->toBeFalse();

    if (!is_int($start)) {
        return;
    }

    $aligned = (new ConsoleRenderer())->render(new DiagnosticBag([
        new Diagnostic(
            DiagnosticCode::FunctionDoesNotExist,
            'Unknown function.',
            new DiagnosticLabel($source->createSpan($start, $start + 5), 'Unknown call.'),
        ),
    ]));
    $empty = new SourceFile('/project/empty.ppphp', 'src/empty.ppphp', FileKind::Ppphp, '');
    $atEof = (new ConsoleRenderer())->render(new DiagnosticBag([
        new Diagnostic(
            DiagnosticCode::InvalidPhpSyntax,
            'Expected source.',
            new DiagnosticLabel($empty->createSpan(0, 0), 'Insert source here.'),
        ),
    ]));

    expect($aligned)->toContain('    λ\\x1Bvalue();', '^^^^^ Unknown call.')
        ->not->toContain("\r", "\e")
        ->and($atEof)->toContain('--> src/empty.ppphp:1:1', '^ Insert source here.');
});

test('invalid UTF-8 and very long lines remain byte exact and render through bounded windows', function (): void {
    $contents = '<?php //' . "\xff" . str_repeat('a', 1_000_000) . ' TARGET';
    $source = new SourceFile('/project/bytes.ppphp', 'src/bytes.ppphp', FileKind::Ppphp, $contents);
    $start = strpos($contents, 'TARGET');

    expect($start)->not->toBeFalse();

    if (!is_int($start)) {
        return;
    }

    $diagnostics = new DiagnosticBag([new Diagnostic(
        DiagnosticCode::InvalidPhpSyntax,
        "Malformed \xff token.",
        new DiagnosticLabel($source->createSpan($start, $start + 6), 'Invalid token.'),
    )]);
    $console = (new ConsoleRenderer())->render(
        $diagnostics,
        new ConsoleRenderOptions(terminalWidth: 60),
    );
    $json = (new JsonRenderer())->render($diagnostics);
    $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

    expect(hash('sha256', $source->contents))->toBe(hash('sha256', $contents))
        ->and(strlen($console))->toBeLessThan(2_000)
        ->and($console)->toContain('TARGET', '^^^^^^ Invalid token.', 'Malformed � token.')
        ->and($decoded['diagnostics'][0]['location']['range']['start']['offset'])->toBe($start)
        ->and($decoded['diagnostics'][0]['message'])->toBe('Malformed � token.');
});

test('long multiline spans retain bounded context and an omission marker', function (): void {
    $contents = implode("\n", [
        '<?php',
        'line two',
        'line three',
        'line four',
        'line five',
        'line six',
        'line seven',
        'line eight',
    ]);
    $source = new SourceFile('/project/multiline.ppphp', 'src/multiline.ppphp', FileKind::Ppphp, $contents);
    $start = $source->resolveLineStartOffset(2);
    $end = $source->resolveLineStartOffset(8);
    $rendered = (new ConsoleRenderer())->render(new DiagnosticBag([
        new Diagnostic(
            DiagnosticCode::InvalidCompositeType,
            'The type spans an invalid region.',
            new DiagnosticLabel($source->createSpan($start, $end), 'Invalid region.'),
        ),
    ]));

    expect($rendered)->toContain('2 | line two', '3 | line three', '...', '6 | line six', '7 | line seven', '8 | line eight')
        ->not->toContain('4 | line four', '5 | line five');
});

test('JSON diagnostics use the stable envelope exact ranges and normalized debug data', function (): void {
    $source = new SourceFile('/project/main.php', 'src\\main.php', FileKind::Php, 'abc');
    $diagnostic = new Diagnostic(
        DiagnosticCode::InvalidInvocation,
        'Bad input.',
        new DiagnosticLabel($source->createSpan(1, 3), 'Invalid bytes'),
        debug: ['value' => DiagnosticCode::InvalidInvocation, 'object' => new stdClass()],
    );
    $json = (new JsonRenderer())->render(new DiagnosticBag([$diagnostic]), true);
    $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

    expect($decoded['version'])->toBe(1)
        ->and($decoded['diagnostics'][0]['location']['file'])->toBe('src/main.php')
        ->and($decoded['diagnostics'][0]['location']['range']['start'])->toBe([
            'offset' => 1,
            'line' => 1,
            'column' => 2,
        ])
        ->and($decoded['diagnostics'][0]['debug'])->toBe([
            'origin' => 'compiler',
            'value' => 'P0022',
            'object' => '[object stdClass]',
        ])
        ->and($decoded['summary'])->toBe(['errors' => 1, 'warnings' => 0, 'notes' => 0])
        ->and($json)->not->toContain("\e[")
        ->and($json)->toEndWith("\n");
});

test('portable dependency diagnostics render through both stable presentation contracts', function (): void {
    $source = new SourceFile('/project/src/main.ppphp', 'src/main.ppphp', FileKind::Ppphp, '<?php Acme\\Missing::run();');
    $diagnostics = new DiagnosticBag(array_map(
        static fn (DiagnosticCode $code): Diagnostic => new Diagnostic(
            $code,
            'Dependency context failure.',
            new DiagnosticLabel($source->createSpan(6, 18), 'The required dependency declaration is unavailable.'),
            help: 'Regenerate the portable dependency index.',
        ),
        [
            DiagnosticCode::DependencyDeclarationContextUnavailable,
            DiagnosticCode::PortableDependencyIndexInvalid,
            DiagnosticCode::DependencyDeclarationAmbiguous,
            DiagnosticCode::DependencySourcePathUnsafe,
        ],
    ));
    $console = (new ConsoleRenderer())->render($diagnostics);
    $json = json_decode((new JsonRenderer())->render($diagnostics), true, flags: JSON_THROW_ON_ERROR);

    expect($console)->toContain(
        'Error[P6018]: Dependency Source Unavailable',
        'Error[P6019]: Portable Dependency Index Invalid',
        'Error[P6020]: Dependency Declaration Ambiguous',
        'Error[P6021]: Dependency Source Path Unsafe',
    )->and(array_column($json['diagnostics'], 'code'))->toBe(['P6018', 'P6019', 'P6020', 'P6021']);
});

test('empty JSON diagnostics still produce a valid envelope', function (): void {
    $decoded = json_decode((new JsonRenderer())->render(new DiagnosticBag()), true, flags: JSON_THROW_ON_ERROR);

    expect($decoded)->toBe([
        'version' => 1,
        'diagnostics' => [],
        'summary' => ['errors' => 0, 'warnings' => 0, 'notes' => 0],
    ]);
});
