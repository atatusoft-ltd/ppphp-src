import { createHash } from 'node:crypto';
import { mkdirSync, mkdtempSync, readFileSync, writeFileSync, copyFileSync, readdirSync, lstatSync, rmSync, realpathSync } from 'node:fs';
import { dirname, resolve, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import assert from 'node:assert/strict';
import { gunzipSync } from 'node:zlib';
import { createGzip } from './deterministic-archive.mjs';
import { archiveLimits, compilerMember, inspectCompilerArchive, productionPackages } from '../src/compiler-archive.mjs';

const compilerRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const hash = bytes => createHash('sha256').update(bytes).digest('hex');
const json = value => JSON.stringify(value, null, 2) + '\n';
const archiveName = 'compiler.tar.gz.bin';
const generatedAutoload = new Set(['vendor/autoload.php', ...[
  'autoload_classmap.php', 'autoload_files.php', 'autoload_namespaces.php', 'autoload_psr4.php',
  'autoload_real.php', 'autoload_static.php', 'installed.json', 'installed.php', 'platform_check.php',
].map(name => 'vendor/composer/' + name)]);

/** Qualification may reuse a sealed package, but never an unpinned cache. */
export function compilerPackageArguments(options) {
  const path = options['--compiler-package'], expected = options['--compiler-receipt-sha256'];
  if (path === undefined && expected === undefined) return [];
  if (!path || !/^[a-f0-9]{64}$/.test(expected || '')) throw new Error('Compiler package requires its independent receipt SHA-256');
  return ['--package', path, '--receipt-sha256', expected];
}

export async function reuseCompilerBundle({ root = compilerRoot, output, packageRoot, expected }) {
  assert.match(expected, /^[a-f0-9]{64}$/, 'Independent receipt hash required');
  packageRoot = realpathSync(packageRoot);
  const read = (path, maximum) => {
    const stat = lstatSync(path);
    assert.ok(stat.isFile() && !stat.isSymbolicLink() && stat.size <= maximum && realpathSync(path) === path, 'Unsafe or oversized bundle input');
    const bytes = readFileSync(path); assert.ok(bytes.length <= maximum); return bytes;
  };
  const receiptBytes = read(join(packageRoot, 'operator/release.json'), 1048576);
  assert.equal(hash(receiptBytes), expected, 'Receipt integrity');
  const receipt = JSON.parse(receiptBytes);
  assert.equal(receipt.format, 'ppphp.browser-release'); assert.equal(receipt.version, 1);
  assert.equal(receipt.productionReady, false); assert.match(receipt.assetSet, /^[a-f0-9]{64}$/);
  const manifest = receipt.identities.compiler;
  assert.equal(manifest.archive, archiveName);
  const bytes = read(join(packageRoot, 'upload/browser-runtime', receipt.assetSet, archiveName), archiveLimits.compressed);
  assert.equal(hash(bytes), manifest.sha256, 'Compiler archive integrity'); assert.equal(bytes.length, manifest.bytes);
  const inspection = await inspectCompilerArchive(bytes);
  assert.equal(inspection.members.length, manifest.members); assert.equal(inspection.expandedBytes, manifest.expandedBytes);
  assert.equal(inspection.dataBytes, manifest.dataBytes);
  const tar = gunzipSync(bytes, { maxOutputLength: archiveLimits.expanded });
  const members = inspection.members.map(member => {
    const content = tar.subarray(member.offset, member.offset + member.bytes);
    // Composer's generated autoload metadata differs in a development install.
    // All actual compiler, resource and production dependency bytes must match.
    if (!generatedAutoload.has(member.path)) {
      assert.equal(hash(read(join(realpathSync(root), member.path), archiveLimits.file)), hash(content), 'Native reference differs: ' + member.path);
    }
    return { path: member.path, bytes: member.bytes, sha256: hash(content), mode: member.mode };
  }).sort((a, b) => a.path < b.path ? -1 : 1);
  const owned = [];
  const visit = path => {
    const stat = lstatSync(join(root, path)); assert.equal(stat.isSymbolicLink(), false);
    if (stat.isDirectory()) { for (const name of readdirSync(join(root, path))) visit(path + '/' + name); }
    else if (compilerMember(path)) owned.push(path);
  };
  for (const path of ['bin/ppphp', 'composer.json', 'composer.lock', 'LICENSE.txt', 'resources', 'src']) visit(path);
  assert.deepEqual(members.filter(m => !m.path.startsWith('vendor/')).map(m => m.path), owned.sort(), 'Complete native compiler source membership');
  assert.equal(hash(json(members)), manifest.closureSha256, 'Compiler closure identity');
  assert.equal(members.find(m => m.path === 'composer.lock').sha256, manifest.compilerLockSha256);
  assert.equal(members.find(m => m.path === 'vendor/phpstan/phpstan/phpstan.phar').sha256, manifest.phpstanSha256);
  mkdirSync(output, { recursive: true });
  writeFileSync(join(output, archiveName), bytes);
  writeFileSync(join(output, 'compiler.json'), json(manifest));
  writeFileSync(join(output, 'compiler-members.json'), json(members));
  return manifest;
}

/** Isolated locked production install. The developer vendor tree is never
 * packaged or changed. No Composer plugins or scripts execute. */
export async function prepareCompilerBundle({ root = compilerRoot, output = join(compilerRoot, 'tools/web-spike/public/generated') } = {}) {
  // Regression subprocesses intentionally set TMPDIR to their project root.
  // Packaging caches/staging must nevertheless stay outside user checkouts.
  // This build profile is POSIX; the PHP-only installer is independent of it.
  const stage = mkdtempSync(join(realpathSync('/tmp'), 'ppphp-compiler-package-'));
  const lockBytes = readFileSync(join(root, 'composer.lock')), lock = JSON.parse(lockBytes);
  const names = lock.packages.map(p => p.name).sort();
  let version;
  const invoke = (command, args, cwd = stage) => {
    const result = spawnSync(command, args, { cwd, encoding: 'utf8', maxBuffer: 4 * 1048576, timeout: 180000,
      env: { ...process.env, TMPDIR: stage, COMPOSER_HOME: join(stage, '.composer'), COMPOSER_CACHE_DIR: join(stage, '.composer-cache'),
        ...(version ? { COMPOSER_ROOT_VERSION: version } : {}), COMPOSER_NO_INTERACTION: '1', COMPOSER_PROCESS_TIMEOUT: '120', COMPOSER_ALLOW_SUPERUSER: '1' } });
    if (result.status !== 0) throw new Error('Production packaging command failed: ' + (result.stderr || result.stdout));
    return result.stdout;
  };
  try {
    if (JSON.stringify(names) !== JSON.stringify([...productionPackages].sort())) throw new Error('Production closure requires review');
    version = invoke(process.env.PHP_BINARY || 'php', ['-r', 'require "src/Compiler/Compiler.php"; echo Atatusoft\\Ppphp\\Compiler\\Compiler::VERSION;'], root).trim();
    for (const path of ['composer.json', 'composer.lock']) copyFileSync(join(root, path), join(stage, path));
    invoke(process.env.COMPOSER_BINARY || 'composer', ['install', '--no-dev', '--no-scripts', '--no-plugins', '--no-interaction', '--no-progress', '--prefer-dist']);
    const installed = JSON.parse(readFileSync(join(stage, 'vendor/composer/installed.json')));
    if (installed.dev !== false || JSON.stringify(installed.packages.map(p => p.name).sort()) !== JSON.stringify(names)) throw new Error('Installed production closure mismatch');
    for (const p of installed.packages) {
      const expected = lock.packages.find(item => item.name === p.name);
      if (p.version !== expected.version || JSON.stringify(p.dist) !== JSON.stringify(expected.dist)) throw new Error('Installed dependency identity mismatch');
    }
    const files = [];
    const visit = (base, path) => {
      const full = join(base, path), stat = lstatSync(full);
      if (stat.isSymbolicLink()) throw new Error('Package input link: ' + path);
      if (stat.isDirectory()) { for (const name of readdirSync(full).sort()) visit(base, path + '/' + name); return; }
      if (!stat.isFile()) throw new Error('Package input kind: ' + path);
      if (!compilerMember(path)) return;
      files.push({ path, content: readFileSync(full), mode: path === 'bin/ppphp' ? 493 : 420 });
    };
    for (const path of ['bin/ppphp', 'composer.json', 'composer.lock', 'LICENSE.txt', 'resources', 'src']) visit(root, path);
    for (const name of productionPackages) visit(stage, 'vendor/' + name);
    visit(stage, 'vendor/autoload.php');
    for (const name of readdirSync(join(stage, 'vendor/composer')).sort()) {
      const path = 'vendor/composer/' + name;
      if (lstatSync(join(stage, path)).isFile() && compilerMember(path)) visit(stage, path);
    }
    // Probe the assembled installation, retaining all compiler identity inputs.
    for (const file of files.filter(f => !f.path.startsWith('vendor/'))) {
      const target = join(stage, file.path); mkdirSync(dirname(target), { recursive: true }); writeFileSync(target, file.content, { mode: file.mode });
    }
    const identity = JSON.parse(invoke(process.env.PHP_BINARY || 'php', ['-r', 'require "vendor/autoload.php"; echo json_encode(["version"=>Atatusoft\\Ppphp\\Compiler\\Compiler::VERSION,"buildIdentity"=>(new Atatusoft\\Ppphp\\Cache\\CompilerBuildIdentity())->calculate()]);']));
    const bytes = createGzip(files), inspection = await inspectCompilerArchive(bytes);
    const members = files.map(f => ({ path: f.path, bytes: f.content.length, sha256: hash(f.content), mode: f.mode })).sort((a, b) => a.path < b.path ? -1 : 1);
    const manifest = { archive: archiveName, bytes: bytes.length, compilerLockSha256: hash(lockBytes), sha256: hash(bytes), ...identity,
      format: 'ustar+gzip', expandedBytes: inspection.expandedBytes, dataBytes: inspection.dataBytes, members: files.length,
      phpstanSha256: hash(readFileSync(join(stage, 'vendor/phpstan/phpstan/phpstan.phar'))),
      productionPackages: installed.packages.map(p => ({ name: p.name, version: p.version, reference: p.dist.reference, license: p.license })),
      closureSha256: hash(json(members)) };
    mkdirSync(output, { recursive: true }); writeFileSync(join(output, archiveName), bytes);
    writeFileSync(join(output, 'compiler.json'), json(manifest)); writeFileSync(join(output, 'compiler-members.json'), json(members));
    return manifest;
  } finally { rmSync(stage, { recursive: true, force: true }); }
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  const args = process.argv.slice(2);
  const options = {};
  for (let i = 0; i < args.length; i += 2) {
    if (!['--output', '--package', '--receipt-sha256'].includes(args[i]) || !args[i + 1] || options[args[i]]) throw new Error('Invalid compiler bundle option');
    options[args[i]] = args[i + 1];
  }
  const output = options['--output'] ? resolve(options['--output']) : join(compilerRoot, 'tools/web-spike/public/generated');
  const reuse = compilerPackageArguments({ '--compiler-package': options['--package'], '--compiler-receipt-sha256': options['--receipt-sha256'] });
  const manifest = reuse.length ? await reuseCompilerBundle({ output, packageRoot: options['--package'], expected: options['--receipt-sha256'] })
    : await prepareCompilerBundle({ output });
  console.log('Prepared production compiler archive (' + manifest.bytes + ' bytes, ' + manifest.members + ' files).');
}
