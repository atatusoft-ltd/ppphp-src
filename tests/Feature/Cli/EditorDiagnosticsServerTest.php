<?php

declare(strict_types=1);

use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

test('retained diagnostics match fresh single-shot results across source and configuration changes', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.ppphp', '<?php int $value = supplied();');
    $native = '<?php /** @return int */ function supplied() {}';
    $this->writeFile($root . '/src/native.php', $native);
    $input = new InputStream();
    $command = [PHP_BINARY, dirname(__DIR__, 3) . '/bin/ppphp', 'editor:diagnostics', '--working-directory', $root];
    $worker = new Process([...$command, '--server']);
    $worker->setInput($input)->setTimeout(90)->start();
    $frames = [];
    $buffer = '';
    $read = static function (int $count) use ($worker, &$frames, &$buffer): void {
        $ready = $worker->waitUntil(static function (string $type, string $output) use (&$frames, &$buffer, $count): bool {
            if ($type === Process::ERR) {
                throw new RuntimeException($output);
            }
            $buffer .= $output;
            while (($end = strpos($buffer, "\n")) !== false) {
                $frames[] = json_decode(substr($buffer, 0, $end), true, flags: JSON_THROW_ON_ERROR);
                $buffer = substr($buffer, $end + 1);
            }
            return count($frames) >= $count;
        });
        expect($ready)->toBeTrue($worker->getErrorOutput());
    };
    try {
        $read(1);
        expect($frames[0]['type'])->toBe('ready');
        $cases = ['valid', 'native-edit', 'overlay-repair', 'missing-tag', 'config-error', 'restore',
            'delete-native', 'restore-native', 'rename-native', 'stub-add', 'stub-edit', 'stub-delete',
            'exclude-target', 'restore-config', 'vendor-add', 'vendor-edit', 'vendor-metadata-error', 'vendor-repair'];
        $installed = json_encode(['packages' => [[
            'name' => 'example/library', 'install_path' => '../example/library',
            'autoload' => ['files' => ['functions.php']],
        ]]], JSON_THROW_ON_ERROR);
        foreach ($cases as $index => $case) {
            $request = ['version' => 1, 'document' => ['path' => 'src/main.ppphp', 'contents' => '<?php int $value = supplied();', 'version' => $index]];
            if ($case === 'native-edit') {
                $this->writeFile($root . '/src/native.php', '<?php /** @return string */ function supplied() {}');
            } elseif ($case === 'overlay-repair') {
                $request['overlays'] = [['path' => 'src/native.php', 'contents' => $native]];
            } elseif ($case === 'missing-tag') {
                $request['document']['contents'] = 'int $value = 1;';
            } elseif ($case === 'config-error') {
                $this->writeFile($root . '/composer.json', '{');
            } elseif ($case === 'restore') {
                $this->writeFile($root . '/composer.json', '{}');
                $this->writeFile($root . '/src/native.php', $native);
            } elseif ($case === 'delete-native') {
                unlink($root . '/src/native.php');
            } elseif ($case === 'restore-native') {
                $this->writeFile($root . '/src/native.php', $native);
            } elseif ($case === 'rename-native') {
                rename($root . '/src/native.php', $root . '/src/renamed.php');
            } elseif ($case === 'stub-add') {
                $this->writeFile($root . '/stubs/external.stub.php', '<?php function externalValue(): int {}');
            } elseif ($case === 'stub-edit') {
                $this->writeFile($root . '/stubs/external.stub.php', '<?php function externalValue(): string {}');
            } elseif ($case === 'stub-delete') {
                unlink($root . '/stubs/external.stub.php');
            } elseif ($case === 'exclude-target') {
                $this->writeConfiguration($root, ['exclude' => ['src/main.ppphp']]);
            } elseif ($case === 'restore-config') {
                $this->writeConfiguration($root);
            } elseif ($case === 'vendor-add' || $case === 'vendor-repair') {
                $this->writeFile($root . '/vendor/composer/installed.json', $installed);
                $this->writeFile($root . '/vendor/example/library/functions.php', '<?php namespace Vendor; function provided(): int {}');
            } elseif ($case === 'vendor-edit') {
                $this->writeFile($root . '/vendor/example/library/functions.php', '<?php namespace Vendor; function provided(): string {}');
            } elseif ($case === 'vendor-metadata-error') {
                $this->writeFile($root . '/vendor/composer/installed.json', '{');
            }
            if (str_starts_with($case, 'stub-')) {
                $request['document']['contents'] = '<?php int $value = externalValue();';
            } elseif (str_starts_with($case, 'vendor-')) {
                $request['document']['contents'] = '<?php int $value = Vendor\\provided();';
            }
            $input->write(json_encode(['version' => 1, 'id' => $index + 1, 'method' => 'diagnostics', 'params' => $request]) . "\n");
            $read($index + 2);
            $fresh = new Process($command);
            $fresh->setInput(json_encode($request))->run();
            expect($fresh->getExitCode())->toBeIn([0, 1, 2]);
            expect($frames[$index + 1]['result'])->toBe(json_decode($fresh->getOutput(), true, flags: JSON_THROW_ON_ERROR))
                ->and($frames[$index + 1]['id'])->toBe($index + 1)
                ->and($frames[$index + 1]['recycle'])->toBeFalse();
            if (in_array($case, ['valid', 'overlay-repair', 'stub-add', 'vendor-add', 'vendor-repair'], true)) {
                expect($frames[$index + 1]['result']['error'])->toBeNull()
                    ->and($frames[$index + 1]['result']['summary']['errors'])->toBe(0);
            } elseif (in_array($case, ['native-edit', 'stub-edit', 'vendor-edit'], true)) {
                expect(in_array('P2008', array_column($frames[$index + 1]['result']['diagnostics'], 'code'), true))
                    ->toBeTrue($case . ': ' . json_encode($frames[$index + 1]['result']));
            }
        }
        $input->write(json_encode(['version' => 1, 'id' => count($cases) + 1, 'method' => 'shutdown']) . "\n");
        $input->close();
        $worker->wait();
        expect($worker->getExitCode())->toBe(0)
            ->and(file_get_contents($root . '/src/renamed.php'))->toBe($native)
            ->and(file_exists($root . '/.ppphp-cache'))->toBeFalse()
            ->and(file_exists($root . '/.ppphp-operation.lock'))->toBeFalse()
            ->and(file_exists($root . '/build'))->toBeFalse();
    } finally {
        $worker->stop();
    }
});
