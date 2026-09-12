<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Frontend\PpphpParser;
use Atatusoft\Ppphp\Project\ProjectParseResult;
use Atatusoft\Ppphp\Semantic\SemanticAnalyzer;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;

test('call boundary checks share lexical binding evidence and native expression eligibility', function (
    string $template, bool $blocked,
): void {
    $when = 'when ($take) { echo "branch|"; return 2; } else { return 3; }';
    $source = new SourceFile('/project/main.ppphp', 'main.ppphp', FileKind::Ppphp, '<?php ' . str_replace('WHEN', $when, $template));
    $parsed = (new PpphpParser())->parse($source);
    $analysis = (new SemanticAnalyzer())->analyze(new ProjectParseResult(
        [$source->path => $parsed->parsedFile], [$source->path => $source], $parsed->diagnostics,
    ));
    $errors = array_values(array_filter(iterator_to_array($analysis->diagnostics), static fn ($diagnostic): bool => $diagnostic->code->value === 'P5005'));
    expect($errors)->toHaveCount($blocked ? 1 : 0)
        ->and($analysis->isSuccessful)->toBe(!$blocked, implode("\n", array_map(static fn ($diagnostic): string => $diagnostic->message, iterator_to_array($analysis->diagnostics))));
    if ($blocked) {
        expect($errors[0]->primary->span->text)->toBe($when)
            ->and($errors[0]->related)->toHaveCount(1)
            ->and($errors[0]->related[0]->span->sourceFile)->toBe($source);
    }
})->with([
    'known call' => ['function value(int $n): int { return $n; } function run(bool $take): int { return value(WHEN); }', false],
    'array value call' => ['function value(int $n): int { return $n; } function run(bool $take): array<int> { return [value(WHEN)]; }', false],
    'array key call' => ['function value(int $n): int { return $n; } function run(bool $take): array<int, int> { return [value(WHEN) => 1]; }', false],
    'call inside an owning branch' => ['function value(int $n): int { return $n; } function run(bool $take): int { return when ($take) { return value(WHEN); } else { return 0; }; }', false],
    'function alias inside an owning branch' => ['namespace Contracts { function value(int $n): int { return $n; } } namespace App { use function Contracts\value as pick; function run(bool $take): int { return when ($take) { return pick(WHEN); } else { return 0; }; } }', false],
    'grouped function alias inside an owning branch' => ['namespace Contracts { function value(int $n): int { return $n; } } namespace App { use Contracts\{function value as pick}; function run(bool $take): int { return when ($take) { return pick(WHEN); } else { return 0; }; } }', false],
    'class import does not redirect a same-named function' => ['namespace Contracts { class pick {} function pick(int &$n): int { return $n; } } namespace App { use Contracts\pick; function pick(int $n): int { return $n; } function run(bool $take): int { return when ($take) { return pick(WHEN); } else { return 0; }; } }', false],
    'native global function fallback inside a namespace' => ['namespace { function value(int $n): int { return $n; } } namespace App { function run(bool $take): int { return when ($take) { return value(WHEN); } else { return 0; }; } }', false],
    'following unpack needs no early capture' => ['function collect(int $n, int ...$rest): int { return $n; } function run(bool $take, array<int> $rest): int { return collect(WHEN, ...$rest); }', false],
    'nested callable does not introduce an outer prelude' => ['function run(callable $callback): void { $callback(function (bool $take): int { return WHEN; }); }', false],
    'first-class callable is not an invocation' => ['function value(int $n): int { return $n; } function run(bool $take): int { value(...); return WHEN; }', false],
    'anonymous constructor signature' => ['function run(bool $take): object { return new class(WHEN) { public function __construct(public int $value) {} }; }', false],
    'unresolved call in an owning branch' => ['function run(callable $callback, bool $take): mixed { return when ($take) { return $callback(WHEN); } else { return 0; }; }', true],
    'otherwise bare outer expression contains statement work' => ['function run(callable $callback, bool $take): mixed { return $callback(when ($take) { return WHEN; } else { return 0; }); }', true],
]);
