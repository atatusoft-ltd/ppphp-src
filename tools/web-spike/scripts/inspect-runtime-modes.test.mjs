import test from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import { inspectWasm, symbolizeStack, forcedLoaderPlugin, findModeArtifact, verifyLoadedArtifacts } from './inspect-runtime-modes.mjs';

const root = mkdtempSync(join(tmpdir(), 'ppphp-mode-tests-'));
test.after(() => rmSync(root, { recursive: true, force: true }));
const u32 = (value) => {
  const bytes = [];
  do { const byte = value & 127; value >>>= 7; bytes.push(byte | (value ? 128 : 0)); } while (value);
  return bytes;
};
const text = (value) => [...u32(Buffer.byteLength(value)), ...Buffer.from(value)];
const section = (id, value) => [id, ...u32(value.length), ...value];
const custom = (name, value) => section(0, [...text(name), ...value]);
const header = [0, 97, 115, 109, 1, 0, 0, 0];
const named = Uint8Array.from([...header, ...custom('name', section(1, [2, ...u32(127), ...text('zend_fiber_init_context'), ...u32(128), ...text('zend_fiber_switch_context')])), ...custom('producers', [1, ...text('processed-by'), 1, ...text('clang'), ...text('test-only')])]);

test('reads exact function indices and producer metadata without execution', () => {
  const result = inspectWasm(named);
  assert.equal(result.functions.get(127), 'zend_fiber_init_context');
  assert.equal(result.functions.get(128), 'zend_fiber_switch_context');
  assert.deepEqual(result.producers, [{ field: 'processed-by', name: 'clang', version: 'test-only' }]);
  assert.deepEqual(result.customSections, ['name', 'producers']);
});
test('missing names are unknown, not invented symbols', () => {
  const result = inspectWasm(Uint8Array.from(header));
  assert.equal(result.functions.size, 0);
  assert.deepEqual(symbolizeStack('wasm-function[12]:0x123', result.functions), [{ index: 12, offset: '0x123', name: null }]);
});
test('unknown custom and name subsections are skipped', () => {
  assert.equal(inspectWasm(Uint8Array.from([...header, ...custom('other', [255, 0]), ...custom('name', section(2, [1, 2, 3]))])).functions.size, 0);
});
test('bad magic and truncated section or string lengths are rejected', () => {
  for (const data of [[], [1, 2, 3], [...header, 0, 10, 0], [...header, ...custom('name', section(1, [1, 2, 10, 65]))]]) {
    assert.throws(() => inspectWasm(Uint8Array.from(data)));
  }
});
test('overflowing u32 and oversized counts are rejected', () => {
  assert.throws(() => inspectWasm(Uint8Array.from([...header, 0, 255, 255, 255, 255, 16])), /u32/);
  assert.throws(() => inspectWasm(Uint8Array.from([...header, ...custom('name', section(1, u32(250001)))])), /table/);
});
test('duplicate names and trailing metadata are rejected', () => {
  assert.throws(() => inspectWasm(Uint8Array.from([...header, ...custom('name', section(1, [2, 0, ...text('a'), 0, ...text('b')]))])), /Duplicate/);
  assert.throws(() => inspectWasm(Uint8Array.from([...header, ...custom('name', section(1, [0, 1]))])), /Trailing/);
  assert.throws(() => inspectWasm(Uint8Array.from([...header, ...custom('producers', [0, 1])])), /Trailing/);
});
test('symbolization uses only retained names and bounds duplicate frames', () => {
  assert.deepEqual(symbolizeStack('wasm-function[127]:0xabc\nwasm-function[127]:0xabc\nwasm-function[99]:123', inspectWasm(named).functions), [
    { index: 127, offset: '0xabc', name: 'zend_fiber_init_context' }, { index: 99, offset: '123', name: null },
  ]);
  assert.equal(symbolizeStack(Array.from({ length: 100 }, (_, i) => `wasm-function[${i}]:0x1`).join('\n'), new Map()).length, 64);
});
for (const mode of ['asyncify', 'jspi']) {
  test(`forces ${mode} via installed bytes, without feature detection or package edits`, () => {
    mkdirSync(join(root, mode));
    const file = join(root, mode, 'php_8_4.js');
    writeFileSync(file, '// untouched');
    const plugin = forcedLoaderPlugin(mode, root);
    const id = plugin.resolveId('@php-wasm/web-8-4');
    assert.equal(plugin.enforce, 'pre');
    assert.ok(plugin.load(id).includes(`${mode}/php_8_4.js`));
    assert.ok(plugin.load(id).includes('export async function getPHPLoaderModule()'));
    assert.equal(plugin.resolveId('@php-wasm/universal'), null);
    assert.equal(plugin.load('anything-else'), null);
    assert.equal(readFileSync(file, 'utf8'), '// untouched');
  });
}
test('unknown modes and missing installed loaders fail before the build', () => {
  assert.throws(() => forcedLoaderPlugin('../../unsafe', root), /Unsupported/);
  assert.throws(() => forcedLoaderPlugin('asyncify', join(root, 'absent')), /absent/);
});
test('loaded WASM is verified by bytes, not by requested mode or hashed filename', () => {
  const output = join(root, 'dist');
  mkdirSync(join(output, 'assets'), { recursive: true });
  writeFileSync(join(output, 'assets', 'php-test.wasm'), named);
  const cases = [{ loadedResources: ['http://127.0.0.1:4173/assets/loader.js', 'http://127.0.0.1:4173/assets/php-test.wasm'] }];
  const sha = createHash('sha256').update(named).digest('hex');
  assert.equal(verifyLoadedArtifacts(cases, output, sha)[0].sha256, sha);
  assert.throws(() => verifyLoadedArtifacts(cases, output, '0'.repeat(64)), /does not match/);
  assert.throws(() => verifyLoadedArtifacts([], output, sha), /No loaded/);
  assert.throws(() => verifyLoadedArtifacts([{ loadedResources: ['https://example.org/assets/php-test.wasm'] }], output, sha), /Unexpected/);
});
test('PHPStan scanner parses PHAR contents as inert data', () => {
  const archive = join(root, 'sample.phar');
  const sideEffect = join(root, 'executed');
  const create = spawnSync('php', ['-d', 'phar.readonly=0', '-r', '$p = new Phar($argv[1]); $p["src/example.php"] = $argv[2]; $p["src/control.php"] = "<?php echo 42;"; $p->setStub("<?php __HALT_COMPILER();");', archive, `<?php file_put_contents(${JSON.stringify(sideEffect)}, 'bad'); $f = new \\Fiber(static fn() => 42);`], { encoding: 'utf8' });
  assert.equal(create.status, 0, create.stderr);
  const script = join(dirname(fileURLToPath(import.meta.url)), 'inspect-phpstan-fibers.php');
  const scan = spawnSync('php', [script, archive], { encoding: 'utf8' });
  assert.equal(scan.status, 0, scan.stderr);
  const result = JSON.parse(scan.stdout);
  assert.equal(result.filesInspected, 2);
  assert.equal(result.referenceCount, 1);
  assert.equal(result.references[0].file, 'src/example.php');
  assert.equal(result.references[0].text, '\\Fiber');
  assert.equal(result.references[0].line, 1);
  assert.equal(existsSync(sideEffect), false);
});
test('PHPStan scanner rejects an absent archive', () => {
  const script = join(dirname(fileURLToPath(import.meta.url)), 'inspect-phpstan-fibers.php');
  assert.equal(spawnSync('php', [script, join(root, 'missing.phar')]).status, 1);
});

test('locates the versioned binary and rejects ambiguous artifacts', () => {
  const packageRoot = join(root, 'nested-package');
  const directory = join(packageRoot, 'asyncify', '8_4_23');
  mkdirSync(directory, { recursive: true });
  writeFileSync(join(directory, 'php_8_4.wasm'), named);
  assert.equal(findModeArtifact(packageRoot, 'asyncify'), join(directory, 'php_8_4.wasm'));
  writeFileSync(join(packageRoot, 'asyncify', 'php_8_4.wasm'), named);
  assert.throws(() => findModeArtifact(packageRoot, 'asyncify'), /unambiguous/);
});
