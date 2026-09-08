<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Analysis\Declaration\DeclarationBodyPruner;
use Atatusoft\Ppphp\Frontend\Enumerations\ParseMode;
use Atatusoft\Ppphp\Frontend\PpphpParser;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;

test('dependency pruning covers nested if elseif and else branches without changing guards', function (): void {
    $source = new SourceFile('/project/guards.php', 'guards.php', FileKind::Php, <<<'PHP'
<?php
if (!function_exists('first')) {
    if (!function_exists('nested')) { function nested(): int { return 1; } }
} elseif (!function_exists('second')) {
    function second(): int { return 2; }
} else {
    function fallback(): int { return 3; }
}
PHP);
    $file = (new PpphpParser())->parse($source, ParseMode::Php)->parsedFile;
    $pruned = (new DeclarationBodyPruner())->prune($file);
    $finder = new \PhpParser\NodeFinder();
    $originals = $finder->findInstanceOf($file->statements, \PhpParser\Node\Stmt\Function_::class);
    $copies = $finder->findInstanceOf($pruned->statements, \PhpParser\Node\Stmt\Function_::class);
    expect($copies)->toHaveCount(3)
        ->and($pruned->statements[0]->cond)->toBe($file->statements[0]->cond);
    foreach ($copies as $index => $copy) {
        expect($copy->stmts)->toBe([])
            ->and($originals[$index]->stmts)->not->toBeEmpty()
            ->and($copy->getAttributes())->toBe($originals[$index]->getAttributes());
    }
});

test('dependency body pruning preserves declaration contracts and original syntax', function (): void {
    $source = new SourceFile('/project/library.php', 'library.php', FileKind::Php, <<<'PHP'
<?php
namespace Library;
use DateTimeImmutable as Date;
/** @template T */
abstract class Value {
    public string $backed { get => $this->backed; }
    public string $virtual { get => 'value'; }
    /** @param T $item @throws \RuntimeException */
    public function __construct(public mixed $item, public Date $date = new Date()) { echo 'never execute'; }
    abstract public function read(): string;
}
function format(Date $date): string { return $date->format('c'); }
PHP);
    $file = (new PpphpParser())->parse($source, ParseMode::Php)->parsedFile;
    expect($file)->not->toBeNull();
    $pruned = (new DeclarationBodyPruner())->prune($file);
    $original = $file->statements[0]->stmts[1];
    $copy = $pruned->statements[0]->stmts[1];

    expect($original->getMethod('__construct')->stmts)->not->toBeEmpty()
        ->and($copy->getMethod('__construct')->stmts)->toBe([])
        ->and($copy->getMethod('read')->stmts)->toBeNull()
        ->and($copy->getMethod('__construct')->params)->toBe($original->getMethod('__construct')->params)
        ->and($copy->getMethod('__construct')->getAttributes())->toBe($original->getMethod('__construct')->getAttributes())
        ->and($copy->getProperties())->toBe($original->getProperties())
        ->and($copy->getDocComment())->toBe($original->getDocComment())
        ->and($pruned->statements[0]->stmts[2]->stmts)->toBe([])
        ->and($pruned->sourceFile)->toBe($source)
        ->and($pruned->sourceMap)->toBe($file->sourceMap)
        ->and($pruned->tokens)->toBe($file->tokens);
});
