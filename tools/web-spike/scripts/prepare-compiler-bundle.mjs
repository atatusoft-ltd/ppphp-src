import { createHash } from 'node:crypto';
import { mkdirSync, mkdtempSync, readFileSync, writeFileSync, copyFileSync, readdirSync, lstatSync, rmSync, realpathSync } from 'node:fs';
import { dirname, resolve, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import { createGzip } from './deterministic-archive.mjs';
import { compilerMember, inspectCompilerArchive, productionPackages } from '../src/compiler-archive.mjs';

const compilerRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const hash = bytes => createHash('sha256').update(bytes).digest('hex');
const json = value => JSON.stringify(value, null, 2) + '\n';
const archiveName = 'compiler.tar.gz.bin';

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
  if (args.length && (args.length !== 2 || args[0] !== '--output')) throw new Error('Usage: prepare-compiler-bundle.mjs [--output directory]');
  const manifest = await prepareCompilerBundle(args.length ? { output: resolve(args[1]) } : {});
  console.log('Prepared production compiler archive (' + manifest.bytes + ' bytes, ' + manifest.members + ' files).');
}
