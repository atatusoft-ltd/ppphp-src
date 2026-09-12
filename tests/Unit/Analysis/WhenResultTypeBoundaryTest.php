<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

test('the pinned analyser applies an exact completed-value fact without hiding subsequent writes', function (
    bool $bridge, string $consumer, bool $valid,
): void {
    $root = $this->createTemporaryDirectory();
    $source = <<<'PHP'
<?php
function choose(bool $fail, bool $replace, bool $reset, callable $factory): int|string {
    $pending = null;
    try {
        if ($fail) { throw new Error('pending'); }
        $pending = 1;
    } finally {
        if ($replace) { $pending = 'replacement'; }
    }
    $value = $pending;
PHP;
    $offset = strrpos($source, '$pending');
    $source .= "\n" . $consumer . "\n}\n";
    $path = $root . '/Probe.php';
    $this->writeFile($path, $source);
    $configuration = "parameters:\n    level: max\n    tmpDir: " . json_encode($root . '/cache') . "\n";
    if ($bridge) {
        $configuration .= "services:\n    -\n        class: Tests\\WhenDecisions\\CompletedValueExtension\n"
            . "        arguments:\n            path: " . json_encode($path) . "\n            offset: " . $offset
            . "\n        tags:\n            - phpstan.broker.expressionTypeResolverExtension\n";
    }
    $this->writeFile($root . '/phpstan.neon', $configuration);
    $compiler = dirname(__DIR__, 3);
    $run = new Process([
        PHP_BINARY, $compiler . '/vendor/bin/phpstan', 'analyse', '--debug', '--no-progress',
        '--error-format=json', '--configuration=' . $root . '/phpstan.neon',
        '--autoload-file=' . $compiler . '/tests/Fixtures/WhenDecisions/Analysis/CompletedValueExtension.php', $path,
    ], timeout: 30);
    $run->run();
    // Debug mode avoids worker sockets; its file progress precedes the JSON.
    $output = $run->getOutput();
    $result = json_decode(substr($output, strpos($output, '{')), true, flags: JSON_THROW_ON_ERROR);
    $identifiers = array_column($result['files'][$path]['messages'] ?? [], 'identifier');
    expect($result['errors'])->toBe([])
        ->and($run->getExitCode())->toBe($valid ? 0 : 1, $output . $run->getErrorOutput())
        ->and($identifiers)->toBe($valid ? [] : ['return.type']);
})->with([
    'native baseline retains the known false positive' => [false, 'return $value;', false],
    'completed handoff' => [true, 'return $value;', true],
    'unknown later write' => [true, '$value = $factory(); return $value;', false],
    'nullable later write' => [true, '$pending = $reset ? null : $value; return $pending;', false],
]);

test('the backend exposes an initializer before a generated declaration asserts its type', function (
    bool $contract, string $initializer, bool $valid,
): void {
    $root = $this->createTemporaryDirectory();
    $source = '<?php function choose(callable $factory): int {
        /** @var int $value */
        $value = ' . $initializer . ';
        return $value;
    }';
    $path = $root . '/Probe.php';
    $this->writeFile($path, $source);
    $configuration = "parameters:\n    level: max\n    tmpDir: " . json_encode($root . '/cache') . "\n";
    if ($contract) {
        $configuration .= "services:\n    -\n        class: Tests\\WhenDecisions\\InitializerContractRule\n"
            . "        arguments:\n            path: " . json_encode($path)
            . "\n            offset: " . strpos($source, '$value =')
            . "\n        tags:\n            - phpstan.rules.rule\n";
    }
    $this->writeFile($root . '/phpstan.neon', $configuration);
    $compiler = dirname(__DIR__, 3);
    $run = new Process([
        PHP_BINARY, $compiler . '/vendor/bin/phpstan', 'analyse', '--debug', '--no-progress',
        '--error-format=json', '--configuration=' . $root . '/phpstan.neon',
        '--autoload-file=' . $compiler . '/tests/Fixtures/WhenDecisions/Analysis/InitializerContractRule.php', $path,
    ], timeout: 30);
    $run->run();
    $output = $run->getOutput();
    $result = json_decode(substr($output, strpos($output, '{')), true, flags: JSON_THROW_ON_ERROR);
    expect($result['errors'])->toBe([])
        ->and($run->getExitCode())->toBe($valid ? 0 : 1, $output . $run->getErrorOutput())
        ->and(array_column($result['files'][$path]['messages'] ?? [], 'identifier'))
        ->toBe($valid ? [] : ['ppphp.initializerType']);
})->with([
    'ordinary assertion remains an assertion' => [false, '$factory()', true],
    'storage contract rejects an unverified initializer' => [true, '$factory()', false],
    'storage contract accepts its literal initializer' => [true, '1', true],
]);

test('a completed-value bridge retains actual backend contribution types', function (string $initializer, bool $valid, bool $complete = true): void {
    $root = $this->createTemporaryDirectory();
    $source = '<?php function choose(bool $fail, bool $replace, callable $factory): int|string {
        $pending = null;
        try {
            if ($fail) { throw new Error(); }
            $pending = ' . $initializer . ';
        } finally {
            if ($replace) { $pending = "replacement"; }
        }
        return $pending;
    }';
    $path = $root . '/Probe.php';
    $this->writeFile($path, $source);
    $definitions = [strpos($source, '$pending = ' . $initializer), strpos($source, '$pending = "replacement"')];
    if (!$complete) {
        $definitions[] = strlen($source);
    }
    $configuration = "parameters:\n    level: max\n    tmpDir: " . json_encode($root . '/cache') . "\n"
        . "services:\n    -\n        class: Atatusoft\\Ppphp\\Analysis\\PhpStan\\CompletedWhenResultExtension\n"
        . "        arguments:\n            results: " . json_encode([
            $path => [strrpos($source, '$pending') => ['name' => 'pending', 'definitions' => $definitions]],
        ])
        . "\n        tags: [phpstan.rules.rule, phpstan.broker.expressionTypeResolverExtension]\n";
    $this->writeFile($root . '/phpstan.neon', $configuration);
    $compiler = dirname(__DIR__, 3);
    $run = new Process([
        PHP_BINARY, $compiler . '/vendor/bin/phpstan', 'analyse', '--debug', '--no-progress',
        '--error-format=json', '--configuration=' . $root . '/phpstan.neon',
        '--autoload-file=' . $compiler . '/resources/phpstan/extensions.php', $path,
    ], timeout: 30);
    $run->run();
    $output = $run->getOutput();
    $result = json_decode(substr($output, strpos($output, '{')), true, flags: JSON_THROW_ON_ERROR);
    expect($result['errors'])->toBe([])
        ->and($run->getExitCode())->toBe($valid ? 0 : 1, $output . $run->getErrorOutput())
        ->and(array_column($result['files'][$path]['messages'] ?? [], 'identifier'))
        ->toBe($valid ? [] : ['return.type']);
})->with([
    'literal contribution' => ['1', true],
    'unknown contribution' => ['$factory()', false],
    'nullable contribution' => ['$replace ? 1 : null', false],
    'missing contribution evidence keeps normal checking' => ['1', false, false],
]);

test('collected result contributions account for subsequent loop iterations', function (string $next, bool $valid): void {
    $root = $this->createTemporaryDirectory();
    $source = '<?php
function consume(int|string $value): void {}
function choose(bool $fail, bool $replace, bool $repeat, callable $factory): void {
    $next = 1;
    while ($repeat) {
        $pending = null;
        try {
            if ($fail) { throw new Error(); }
            $pending = $next;
        } finally {
            if ($replace) { $pending = "replacement"; }
        }
        consume($pending);
        $next = ' . $next . ';
    }
}';
    $path = $root . '/Probe.php';
    $this->writeFile($path, $source);
    $definitions = [strpos($source, '$pending = $next'), strpos($source, '$pending = "replacement"')];
    $configuration = "parameters:\n    level: max\n    tmpDir: " . json_encode($root . '/cache') . "\n"
        . "services:\n    -\n        class: Atatusoft\\Ppphp\\Analysis\\PhpStan\\CompletedWhenResultExtension\n"
        . "        arguments:\n            results: " . json_encode([
            $path => [strrpos($source, '$pending') => ['name' => 'pending', 'definitions' => $definitions]],
        ])
        . "\n        tags: [phpstan.rules.rule, phpstan.broker.expressionTypeResolverExtension]\n";
    $this->writeFile($root . '/phpstan.neon', $configuration);
    $compiler = dirname(__DIR__, 3);
    $run = new Process([
        PHP_BINARY, $compiler . '/vendor/bin/phpstan', 'analyse', '--debug', '--no-progress',
        '--error-format=json', '--configuration=' . $root . '/phpstan.neon',
        '--autoload-file=' . $compiler . '/resources/phpstan/extensions.php', $path,
    ], timeout: 30);
    $run->run();
    $output = $run->getOutput();
    $result = json_decode(substr($output, strpos($output, '{')), true, flags: JSON_THROW_ON_ERROR);
    expect($result['errors'])->toBe([])
        ->and($run->getExitCode())->toBe($valid ? 0 : 1, $output . $run->getErrorOutput())
        ->and(array_column($result['files'][$path]['messages'] ?? [], 'identifier'))
        ->toBe($valid ? [] : ['argument.type']);
})->with(['stable contribution' => ['2', true], 'unknown next iteration' => ['$factory()', false], 'nullable next iteration' => ['$replace ? 1 : null', false]]);

test('completed-result correction preserves later container changes', function (bool $production): void {
    $root = $this->createTemporaryDirectory();
    $source = '<?php function choose(bool $fail, bool $replace): int {
        $pending = null;
        try {
            if ($fail) { throw new Error(); }
            $pending = [1];
        } finally {
            if ($replace) { $pending[0] = "changed"; }
        }
        return $pending[0];
    }';
    $path = $root . '/Probe.php';
    $this->writeFile($path, $source);
    $definition = strpos($source, '$pending = [1]');
    $read = strrpos($source, '$pending');
    $arguments = $production
        ? ['results' => [$path => [$read => ['name' => 'pending', 'definitions' => [$definition]]]]]
        : ['path' => $path, 'read' => $read, 'definitions' => [$definition]];
    $class = $production ? 'Atatusoft\\Ppphp\\Analysis\\PhpStan\\CompletedWhenResultExtension'
        : 'Tests\\WhenDecisions\\CollectedResultExtension';
    $configuration = "parameters:\n    level: max\n    tmpDir: " . json_encode($root . '/cache') . "\n"
        . "services:\n    -\n        class: " . $class . "\n        arguments: " . json_encode($arguments)
        . "\n        tags: [phpstan.rules.rule, phpstan.broker.expressionTypeResolverExtension]\n";
    $this->writeFile($root . '/phpstan.neon', $configuration);
    $compiler = dirname(__DIR__, 3);
    $extension = $production ? '/resources/phpstan/extensions.php' : '/tests/Fixtures/WhenDecisions/Analysis/CollectedResultExtension.php';
    $run = new Process([
        PHP_BINARY, $compiler . '/vendor/bin/phpstan', 'analyse', '--debug', '--no-progress',
        '--error-format=json', '--configuration=' . $root . '/phpstan.neon',
        '--autoload-file=' . $compiler . $extension, $path,
    ], timeout: 30);
    $run->run();
    $output = $run->getOutput();
    $result = json_decode(substr($output, strpos($output, '{')), true, flags: JSON_THROW_ON_ERROR);
    expect($result['errors'])->toBe([])
        ->and($run->getExitCode())->toBe($production ? 1 : 0, $output . $run->getErrorOutput())
        ->and(array_column($result['files'][$path]['messages'] ?? [], 'identifier'))
        ->toBe($production ? ['return.type'] : []);
})->with(['production retains the backend container state' => true, 'rejected whole-type override loses later changes' => false]);
