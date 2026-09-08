<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;
use Atatusoft\Ppphp\Source\SourceManager;

test('node span reuse checks the source generation and current mapped offsets', function (): void {
    $parser = new \Atatusoft\Ppphp\Frontend\PpphpParser();
    $file = $parser->parse(new SourceFile('/project/main.ppphp', 'main.ppphp', FileKind::Ppphp, '<?php echo "é";'))->parsedFile;
    $node = $file->statements[0];
    $resolver = new \Atatusoft\Ppphp\Semantic\NodeSpanResolver();
    $first = $resolver->resolve($file, $node);
    expect((new \Atatusoft\Ppphp\Semantic\NodeSpanResolver())->resolve($file, $node))->toBe($first);
    $node->setAttribute('ppphpOriginalStart', 6);
    $node->setAttribute('ppphpOriginalEnd', 10);
    expect($resolver->resolve($file, $node)->text)->toBe('echo');
    $replacement = $parser->parse(new SourceFile('/project/main.ppphp', 'main.ppphp', FileKind::Ppphp, '<?php echo "🙂";'))->parsedFile;
    expect($resolver->resolve($replacement, $node)->sourceFile)->toBe($replacement->sourceFile)
        ->and($first->sourceFile)->toBe($file->sourceFile);
    $reference = WeakReference::create($node);
    unset($node, $file);
    gc_collect_cycles();
    expect($reference->get())->toBeNull();
});

test('empty and single-line source files expose one-based positions', function (): void {
    $empty = new SourceFile('/project/empty.php', 'empty.php', FileKind::Php, '');
    $single = new SourceFile('/project/main.php', 'main.php', FileKind::Php, 'abc');

    expect($empty->lineCount)->toBe(1)
        ->and($empty->resolvePositionAt(0)->line)->toBe(1)
        ->and($empty->resolvePositionAt(0)->column)->toBe(1)
        ->and($single->resolvePositionAt(3)->column)->toBe(4)
        ->and($single->readLineText(1))->toBe('abc');
});

test('LF CRLF and trailing newlines create logical lines', function (): void {
    $lf = new SourceFile('/project/lf.php', 'lf.php', FileKind::Php, "one\ntwo\n");
    $crlf = new SourceFile('/project/crlf.php', 'crlf.php', FileKind::Php, "one\r\ntwo");

    expect($lf->lineCount)->toBe(3)
        ->and($lf->resolvePositionAt(4)->line)->toBe(2)
        ->and($lf->resolvePositionAt(4)->column)->toBe(1)
        ->and($lf->readLineText(3))->toBe('')
        ->and($crlf->lineCount)->toBe(2)
        ->and($crlf->resolvePositionAt(5)->line)->toBe(2)
        ->and($crlf->resolvePositionAt(5)->column)->toBe(1)
        ->and($crlf->readLineText(1))->toBe('one');
});

test('columns count UTF-8 code points rather than bytes', function (): void {
    $source = new SourceFile('/project/utf8.ppphp', 'utf8.ppphp', FileKind::Ppphp, "aé🙂z");

    expect($source->resolvePositionAt(strlen('aé🙂'))->offset)->toBe(7)
        ->and($source->resolvePositionAt(strlen('aé🙂'))->column)->toBe(4);
});

test('spans are half-open and may be empty or end at EOF', function (): void {
    $source = new SourceFile('/project/main.php', 'main.php', FileKind::Php, 'value');

    expect($source->createSpan(1, 1)->isEmpty)->toBeTrue()
        ->and($source->createSpan(1, 5)->text)->toBe('alue')
        ->and(fn () => $source->createSpan(-1, 1))->toThrow(OutOfBoundsException::class)
        ->and(fn () => $source->createSpan(3, 2))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $source->createSpan(0, 6))->toThrow(OutOfBoundsException::class);
});

test('source managers reuse duplicate normalized paths', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeFile($root . '/src/main.php', '<?php');
    $manager = new SourceManager($root);
    $first = $manager->load('src/main.php');
    $second = $manager->load('src/./domain/../main.php');

    expect($second)->toBe($first)
        ->and($manager->get($root . '/src/main.php'))->toBe($first)
        ->and($first->displayPath)->toBe('src/main.php')
        ->and(FileKind::resolveFromPath('example.ppphp'))->toBe(FileKind::Ppphp)
        ->and(FileKind::resolveFromPath('example.stub.php'))->toBe(FileKind::Stub);
});
