<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Analysis\Declaration\DeclarationBodyPruner;
use Atatusoft\Ppphp\Frontend\Enumerations\ParseMode;
use Atatusoft\Ppphp\Frontend\PpphpParser;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;

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
