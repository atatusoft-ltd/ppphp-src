<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Analysis\CompilerProjectAnalysis;
use Atatusoft\Ppphp\Analysis\CompilerProjectAnalyzer;
use Atatusoft\Ppphp\Analysis\Enumerations\AnalysisCompleteness;
use Atatusoft\Ppphp\Config\ProjectConfigLoader;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Project\ProjectCheckResult;
use Atatusoft\Ppphp\Project\ProjectLoader;

test('the browser proof requires a checked error contract and validated paths through both call and catches', function (
    string $find, string $replace, bool $admitted, ?DiagnosticCode $diagnostic = null,
): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root, ['stubs' => []]);
    $source = <<<'SOURCE'
        <?php
        final class UserNotFound extends Exception {}
        final class StorageFailure extends Exception {}
        function lookupUser(string $id): string throws UserNotFound, StorageFailure {
            if ($id === '') { throw new UserNotFound(); }
            return $id;
        }
        try {
            string $user = lookupUser('Maya');
            echo $user;
        } catch (UserNotFound $error) {
            echo 'guest';
        } catch (StorageFailure $error) {
            echo 'unavailable';
        }
        SOURCE;
    $source = $find === '' ? $source . $replace : str_replace($find, $replace, $source);
    $this->writeFile($root . '/src/main.ppphp', $source);
    $configuration = (new ProjectConfigLoader())->load($root, requireSourceDirectories: true)->configuration;
    expect($configuration)->not->toBeNull();
    $project = (new ProjectLoader())->load($configuration)->project;
    expect($project)->not->toBeNull();

    $result = (new CompilerProjectAnalyzer())->analyzeWithLocalProof($project, $project->sources);
    if ($admitted) {
        expect($result)->toBeInstanceOf(ProjectCheckResult::class)
            ->and($result->isSuccessful)->toBeTrue()
            ->and($result->supplementalEvidence)->toBeFalse()
            ->and($result->completeness)->toBe(AnalysisCompleteness::ExperimentalScalarProof)
            ->and($result->declarationContext?->isSuccessful)->toBeTrue();
    } else {
        expect($result)->toBeInstanceOf(CompilerProjectAnalysis::class);
    }
    if ($diagnostic !== null) {
        expect(array_map(static fn ($item) => $item->code, $result->diagnostics->errors))->toContain($diagnostic);
    }
})->with([
    'normal return and all declared types caught' => ["'Maya'", "'Noor'", true],
    'direct throw remains covered' => ["'Maya'", "''", true],
    'optional catch variable' => ['StorageFailure $error)', 'StorageFailure)', true],
    'statically true guard' => ["\$id === ''", "'' === ''", false],
    'statically false guard' => ["\$id === ''", '$id === 1', false],
    'unchecked call in guard' => ["\$id === ''", 'lookupOther($id)', false],
    'unreachable throw' => ['throw new', 'return $id; throw new', false],
    'wrong return' => ['return $id;', 'return 1;', false, DiagnosticCode::ReturnTypeDoesNotMatch],
    'unknown top-level callable' => ["lookupUser('Maya')", "unknownUser('Maya')", false],
    'wrong class casing in new' => ['new UserNotFound()', 'new usernotfound()', false],
    'wrong class casing in catch' => ['catch (UserNotFound', 'catch (usernotfound', false],
    'conservative contract shortened makes catch dead' => ['throws UserNotFound, StorageFailure', 'throws UserNotFound', false],
    'unhandled declared error' => ['catch (StorageFailure $error)', 'catch (LogicException $error)', false, DiagnosticCode::CheckedErrorCannotEscapeFileScope],
    'compiler does not bypass native declaration collision' => ['lookupUser', 'strlen', false],
    'extra effects after try' => ['', "\necho \$user;", false],
]);
