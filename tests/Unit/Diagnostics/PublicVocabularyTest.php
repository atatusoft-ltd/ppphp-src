<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Diagnostics\DiagnosticCatalog;
use Atatusoft\Ppphp\Diagnostics\DiagnosticHelpProvider;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use PhpParser\Node;
use PhpParser\ParserFactory;

test('authored public diagnostic and CLI templates use user vocabulary', function (): void {
    $pattern = '/\b(?:compiler-owned|declaration context|analysis workspace|supplemental|backend|project-owned|lowering|stage)\b/i';
    foreach (DiagnosticCode::cases() as $code) {
        expect(DiagnosticCatalog::definition($code)->title)->not->toMatch($pattern);
        expect(DiagnosticHelpProvider::resolve($code))->not->toMatch($pattern);
    }

    $parser = (new ParserFactory())->createForHostVersion();
    $violations = [];
    $scan = function (Node $node, string $path) use (&$scan, &$violations, $pattern): void {
        // These built-in exceptions are rendered as guarded failures; their
        // implementation detail is debug-only, not authored public copy.
        if ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name
            && in_array($node->class->getLast(), ['LogicException', 'InvalidArgumentException', 'RuntimeException', 'UnexpectedValueException', 'OutOfBoundsException'], true)) {
            return;
        }
        if ($node instanceof Node\Arg && $node->name?->toString() === 'debug') {
            return;
        }
        if (($node instanceof Node\Scalar\String_ || $node instanceof Node\InterpolatedStringPart)
            && str_contains($node->value, ' ') && preg_match($pattern, $node->value) === 1) {
            $violations[] = $path . ':' . $node->getStartLine() . ': ' . $node->value;
        }
        foreach ($node->getSubNodeNames() as $name) {
            foreach (is_array($node->$name) ? $node->$name : [$node->$name] as $child) {
                if ($child instanceof Node) {
                    $scan($child, $path);
                }
            }
        }
    };
    $root = dirname(__DIR__, 3) . '/src';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        $path = substr($file->getPathname(), strlen($root) + 1);
        // Capability/parity reports and documentation lint are maintainer tools.
        if ($file->getExtension() !== 'php' || str_starts_with($path, 'Analysis/Capability/') || str_starts_with($path, 'Analysis/Parity/') || $path === 'Versioning/DocumentationPolicy.php') {
            continue;
        }
        foreach ($parser->parse(file_get_contents($file->getPathname())) ?? [] as $node) {
            $scan($node, $path);
        }
    }
    expect($violations)->toBe([]);
});
