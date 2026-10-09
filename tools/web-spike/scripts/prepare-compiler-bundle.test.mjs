import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, readFileSync, rmSync, existsSync, symlinkSync, unlinkSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { tmpdir } from 'node:os';
import { createHash } from 'node:crypto';
import { gunzipSync } from 'node:zlib';
import { createGzip } from './deterministic-archive.mjs';
import { compilerPackageArguments, reuseCompilerBundle } from './prepare-compiler-bundle.mjs';

const hash = bytes => createHash('sha256').update(bytes).digest('hex');
const json = value => JSON.stringify(value, null, 2) + '\n';
function fixture(t) {
  const directory = mkdtempSync(join(tmpdir(), 'ppphp-reuse-bundle-test-'));
  t.after(() => rmSync(directory, { recursive: true, force: true }));
  const root = join(directory, 'native'), packageRoot = join(directory, 'package'), output = join(directory, 'output');
  const files = ['bin/ppphp', 'composer.json', 'composer.lock', 'LICENSE.txt', 'src/Compiler/Compiler.php',
    'resources/phpstan/ppphp.neon', 'vendor/autoload.php', 'vendor/composer/installed.json',
    'vendor/composer/ClassLoader.php', 'vendor/composer/InstalledVersions.php', 'vendor/composer/LICENSE',
    'vendor/composer/semver/src/Semver.php', 'vendor/phpstan/phpstan/phpstan.phar'].map(path => ({ path, content: Buffer.from('fixture: ' + path), mode: path === 'bin/ppphp' ? 493 : 420 }));
  for (const file of files) { const path = join(root, file.path); mkdirSync(dirname(path), { recursive: true }); writeFileSync(path, file.content); }
  const bytes = createGzip(files), assetSet = 'a'.repeat(64), archive = join(packageRoot, 'upload/browser-runtime', assetSet, 'compiler.tar.gz.bin');
  mkdirSync(dirname(archive), { recursive: true }); writeFileSync(archive, bytes);
  const members = files.map(f => ({ path: f.path, bytes: f.content.length, sha256: hash(f.content), mode: f.mode })).sort((a, b) => a.path < b.path ? -1 : 1);
  const manifest = { archive: 'compiler.tar.gz.bin', sha256: hash(bytes), bytes: bytes.length, members: files.length,
    expandedBytes: gunzipSync(bytes).length, dataBytes: files.reduce((n, f) => n + f.content.length, 0), closureSha256: hash(json(members)),
    compilerLockSha256: hash(files.find(f => f.path === 'composer.lock').content), phpstanSha256: hash(files.at(-1).content) };
  const receipt = { format: 'ppphp.browser-release', version: 1, productionReady: false, assetSet, identities: { compiler: manifest } };
  const receiptPath = join(packageRoot, 'operator/release.json'); mkdirSync(dirname(receiptPath), { recursive: true });
  writeFileSync(receiptPath, json(receipt));
  return { root, output, packageRoot, expected: hash(json(receipt)), bytes, archive, receipt, receiptPath };
}

test('sealed compiler reuse preserves exact bytes without running Composer or packaged PHP', async t => {
  const f = fixture(t);
  // Development autoload metadata is not used to reconstruct the production archive.
  writeFileSync(join(f.root, 'vendor/composer/installed.json'), 'different development metadata');
  const manifest = await reuseCompilerBundle(f);
  assert.deepEqual(manifest, f.receipt.identities.compiler);
  assert.deepEqual(readFileSync(join(f.output, manifest.archive)), f.bytes);
  assert.equal(hash(readFileSync(join(f.output, 'compiler-members.json'))), manifest.closureSha256);
});
test('reuse options require a package and independent pin together', () => {
  assert.deepEqual(compilerPackageArguments({}), []);
  const valid = { '--compiler-package': '/package', '--compiler-receipt-sha256': 'a'.repeat(64) };
  assert.deepEqual(compilerPackageArguments(valid), ['--package', '/package', '--receipt-sha256', 'a'.repeat(64)]);
  for (const invalid of [{ '--compiler-package': '/package' }, { '--compiler-receipt-sha256': 'a'.repeat(64) }, { ...valid, '--compiler-receipt-sha256': 'latest' }]) assert.throws(() => compilerPackageArguments(invalid));
});
for (const target of ['receipt', 'archive', 'closure', 'count', 'lock', 'phpstan']) {
  test('rejects changed ' + target + ' before publishing reusable output', async t => {
    const f = fixture(t);
    if (target === 'receipt') f.expected = '0'.repeat(64);
    else if (target === 'archive') writeFileSync(f.archive, 'corrupt');
    else {
      const field = { closure: 'closureSha256', count: 'members', lock: 'compilerLockSha256', phpstan: 'phpstanSha256' }[target];
      f.receipt.identities.compiler[field] = target === 'count' ? 0 : '0'.repeat(64);
      writeFileSync(f.receiptPath, json(f.receipt)); f.expected = hash(json(f.receipt));
    }
    await assert.rejects(reuseCompilerBundle(f)); assert.equal(existsSync(f.output), false);
  });
}
for (const target of ['src/Compiler/Compiler.php', 'vendor/composer/ClassLoader.php', 'vendor/composer/InstalledVersions.php',
  'vendor/composer/LICENSE', 'vendor/composer/semver/src/Semver.php', 'vendor/phpstan/phpstan/phpstan.phar']) {
  test('rejects native reference drift in ' + target, async t => {
    const f = fixture(t); writeFileSync(join(f.root, target), 'changed');
    await assert.rejects(reuseCompilerBundle(f), /Native reference differs/); assert.equal(existsSync(f.output), false);
  });
}
test('rejects native source absent from the sealed archive', async t => {
  const f = fixture(t); writeFileSync(join(f.root, 'src/Added.php'), '<?php');
  await assert.rejects(reuseCompilerBundle(f), /source membership/); assert.equal(existsSync(f.output), false);
});
test('rejects symlinked archive input', async t => {
  const f = fixture(t), other = join(dirname(f.archive), 'copy'); writeFileSync(other, f.bytes);
  unlinkSync(f.archive); symlinkSync(other, f.archive);
  await assert.rejects(reuseCompilerBundle(f), /Unsafe/); assert.equal(existsSync(f.output), false);
});
