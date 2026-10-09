import test from 'node:test';
import assert from 'node:assert/strict';
import { gzipSync } from 'node:zlib';
import { createTar, createGzip } from './deterministic-archive.mjs';
import { inspectTar, inspectCompilerArchive, archiveLimits, compilerMember } from '../src/compiler-archive.mjs';
const file = (path, content = 'x', mode = 420) => ({ path, content, mode });
const tar = () => createTar([file('src/Example.php')]);
function headerChange(bytes, action) {
  const copy = Buffer.from(bytes); action(copy); copy.fill(32, 148, 156);
  const sum = copy.subarray(0, 512).reduce((a, b) => a + b, 0);
  copy.write(sum.toString(8).padStart(6, '0') + '\0 ', 148, 8); return copy;
}
test('canonical order, gzip metadata, modes and content are reproducible', () => {
  const a = [file('src/B.php'), file('bin/ppphp', '<?php', 493), file('src/A.php')];
  assert.deepEqual(createGzip(a), createGzip(a.toReversed()));
  assert.deepEqual(inspectTar(createTar(a)).members.map(m => [m.path, m.mode]), [['bin/ppphp', 493], ['src/A.php', 420], ['src/B.php', 420]]);
  assert.deepEqual([...createGzip(a).subarray(4, 8)], [0, 0, 0, 0]); assert.equal(createGzip(a)[9], 255);
});
test('affirmative compiler closure excludes development/private inputs', () => {
  for (const path of ['.env', 'tests/Foo.php', 'vendor/pestphp/pest/src/Test.php', 'vendor/bin/phpstan', 'vendor/composer/autoload_dev.php', 'src/.env', 'website/config/secure.php',
    'vendor/phpstan/phpstan/turbo-ext/macos/phpstan_turbo-8.4.so', 'vendor/symfony/service-contracts/Test/ServiceLocatorTest.php',
    'vendor/symfony/console/Resources/bin/hiddeninput.exe']) assert.equal(compilerMember(path), false, path);
  for (const path of ['vendor/composer/semver/src/Semver.php', 'vendor/phpstan/phpstan/phpstan.phar', 'resources/phpstan/ppphp.neon']) assert.equal(compilerMember(path), true, path);
});
for (const kind of ['1', '2', '3', '4', '5', '6', '7', 'x', 'g', 'L', 'K', 'S']) {
  test('rejects links, special files and extension kind ' + kind, () => assert.throws(() => inspectTar(headerChange(tar(), b => { b[156] = kind.charCodeAt(0); })), /kind/));
}
for (const name of ['/absolute', '../escape', 'src/../escape', 'src//duplicate', 'src/./duplicate', 'C:\\escape', 'src/./']) {
  test('rejects unsafe path ' + name, () => assert.throws(() => inspectTar(headerChange(tar(), b => { b.fill(0, 0, 100); b.write(name, 0); })), /path/));
}
test('rejects duplicate members and file-directory aliases before extraction', () => {
  assert.throws(() => createTar([file('src/A.php'), file('src/A.php')]), /duplicate/);
  assert.throws(() => createTar([file('src/a'), file('src/a/b')]), /collision/);
});
test('rejects forbidden injected members even in structurally valid tar', () => {
  for (const path of ['.env', 'vendor/pestphp/pest/Test.php', 'tests/Fixture.php']) assert.throws(() => inspectTar(createTar([file(path)])), /Forbidden/);
});
test('bounds individual files, count, expanded bytes and unsafe modes', () => {
  assert.throws(() => inspectTar(tar(), { limits: { ...archiveLimits, file: 0 } }), /file size/);
  assert.throws(() => inspectTar(tar(), { limits: { ...archiveLimits, files: 0 } }), /count/);
  assert.throws(() => inspectTar(tar(), { limits: { ...archiveLimits, expanded: 512 } }), /expanded/);
  assert.throws(() => inspectTar(headerChange(tar(), b => b.write('0004755\0', 100))), /mode/);
  assert.throws(() => inspectTar(headerChange(tar(), b => b[124] = 128)), /number/);
});
test('rejects checksum errors, truncated records and nonzero trailing data', () => {
  const checksum = tar(); checksum[0] ^= 1; assert.throws(() => inspectTar(checksum), /header/);
  assert.throws(() => inspectTar(tar().subarray(0, 1024)), /end/);
  const trailing = tar(); trailing[trailing.length - 1] = 1; assert.throws(() => inspectTar(trailing), /end/);
});
test('gzip expansion is bounded while streaming, before tar parsing or writes', async () => {
  await assert.rejects(inspectCompilerArchive(gzipSync(Buffer.alloc(archiveLimits.expanded + 512))), /expansion limit/);
  await assert.rejects(inspectCompilerArchive(createGzip([file('src/Example.php')])), /Incomplete compiler/);
  await assert.rejects(inspectCompilerArchive(new Uint8Array(archiveLimits.compressed + 1)), /compressed size/);
});
