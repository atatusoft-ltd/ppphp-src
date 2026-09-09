import { readFileSync, writeFileSync, mkdirSync, lstatSync, realpathSync, mkdtempSync, rmSync } from 'node:fs';
import { join, dirname, resolve, basename, sep } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { spawnSync } from 'node:child_process';
import { tmpdir, platform, arch } from 'node:os';
import { setTimeout as delay } from 'node:timers/promises';
import { createOutput, sha256, launchChrome, collectObservation } from './run-baseline.mjs';
import { forcedLoaderPlugin, findModeArtifact, verifyLoadedArtifacts } from './inspect-runtime-modes.mjs';
import { CONFIGURATION, LIMITS, validateCorpus, verifySourceHashes, frameProcess, assessCase, runtimeConsoleFailures } from '../src/parity-contract.mjs';
import { resolvePreparedDebugPaths } from '../src/phpstan-debug-output.mjs';
import { probes } from '../src/baseline-probes.js';
import { assessProfile } from '../../php-wasm-runtime/verify-built.mjs';
import { assessFiberContract } from './run-fiber-contract.mjs';

export const SPIKE = resolve(dirname(fileURLToPath(import.meta.url)), '..');
export const ROOT = resolve(SPIKE, '../..');
export const ADAPTER = join(SPIKE, 'src/parity-adapter.php');

export function assessControls(baseline, candidate, fiber, wasmSha256, lockSha256) {
  const ids = [...probes.map((item) => item.id), 'phpstan-standalone'];
  return baseline?.format === 'ppphp.rebuilt-runtime' && candidate?.format === 'ppphp.rebuilt-runtime'
    && baseline.profile === 'baseline' && candidate.profile === 'candidate'
    && baseline.accepted === true && candidate.accepted === true && fiber?.accepted === true
    && baseline.browser?.kind === 'observed' && candidate.browser?.kind === 'observed' && fiber.browser?.kind === 'observed'
    && !baseline.browser.cleanupError && !candidate.browser.cleanupError && !fiber.browser.cleanupError
    && candidate.artifact?.sha256 === wasmSha256 && fiber.expectedWasmSha256 === wasmSha256
    && candidate.loadedArtifacts?.length > 0 && candidate.loadedArtifacts.every((item) => item.sha256 === wasmSha256)
    && fiber.loadedArtifacts?.length > 0 && fiber.loadedArtifacts.every((item) => item.sha256 === wasmSha256)
    && candidate.browser.data?.cases?.find((item) => item.id === 'phpstan-standalone')?.compilerArchive?.compilerLockSha256 === lockSha256
    && assessProfile(baseline.browser.data?.cases, 'baseline', ids)
    && assessProfile(candidate.browser.data?.cases, 'candidate', ids)
    && assessFiberContract(fiber.browser.data);
}

export function readBoundedJson(path) {
  if (!lstatSync(path).isFile() || lstatSync(path).size > LIMITS.corpusBytes) throw new Error('Input must be a bounded local JSON file');
  return JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(readFileSync(path)));
}

export function verifyRuntime(path, expectedSha256) {
  const root = realpathSync(path);
  if (!/^[a-f0-9]{64}$/.test(expectedSha256 || '')) throw new Error('Explicit WASM SHA-256 required');
  const manifest = readBoundedJson(join(root, 'artifacts.json'));
  if (manifest.profile !== 'candidate' || !Array.isArray(manifest.files) || manifest.files.length > 64) throw new Error('Invalid candidate manifest');
  const paths = new Set();
  for (const item of manifest.files) {
    if (typeof item.path !== 'string' || !/^[A-Za-z0-9_./-]+$/.test(item.path) || item.path.startsWith('/')
      || item.path.split('/').some((p) => !p || p === '.' || p === '..') || paths.has(item.path)) throw new Error('Unsafe or duplicate artifact path');
    paths.add(item.path);
    const file = join(root, item.path);
    if (!lstatSync(file).isFile() || realpathSync(file) !== file || !realpathSync(file).startsWith(root + sep)
      || lstatSync(file).size > 64 * 1024 * 1024) throw new Error('Invalid artifact file');
    const data = readFileSync(file);
    if (data.length !== item.bytes || sha256(data) !== item.sha256) throw new Error('Artifact integrity failure');
  }
  const wasm = findModeArtifact(root, 'asyncify');
  const relativeWasm = wasm.slice(root.length + 1);
  if (!paths.has('asyncify/php_8_4.js') || !paths.has(relativeWasm) || sha256(readFileSync(wasm)) !== expectedSha256) throw new Error('Candidate loader/WASM identity missing or mismatched');
  return { root, wasmSha256: expectedSha256, loaderSha256: sha256(readFileSync(join(root, 'asyncify/php_8_4.js'))), manifestSha256: sha256(readFileSync(join(root, 'artifacts.json'))) };
}

export function runProcess(binary, args, cwd, timeout = 90000) {
  const started = performance.now();
  const result = spawnSync(binary, args, { cwd, encoding: 'utf8', timeout, killSignal: 'SIGKILL', maxBuffer: LIMITS.outputBytes,
    env: { PATH: process.env.PATH || '/usr/bin:/bin', HOME: cwd, TMPDIR: cwd, LANG: 'C.UTF-8', NO_COLOR: '1', TERM: 'dumb' } });
  return { command: [binary, ...args], kind: result.error?.code === 'ETIMEDOUT' ? 'timeout' : result.error?.code === 'ENOBUFS' ? 'overflow' : result.error ? 'launch-failure' : 'completed',
    stdout: result.stdout || '', stderr: result.stderr || '', exitCode: result.status, error: result.error?.message,
    signal: result.signal, durationMs: Math.round(performance.now() - started) };
}

export function nativeCase(fixture, phpBinary = process.env.PHP_BINARY || 'php') {
  const directory = realpathSync(mkdtempSync(join(tmpdir(), 'ppphp-parity-native-')));
  const result = { id: fixture.id, kind: 'completed' };
  const php = (args) => runProcess(phpBinary, ['-d', 'memory_limit=256M', '-d', 'display_errors=stderr', '-d', 'log_errors=0', ...args], directory);
  const write = (path, content) => { mkdirSync(dirname(join(directory, path)), { recursive: true }); writeFileSync(join(directory, path), content); };
  try {
    mkdirSync(join(directory, 'src')); mkdirSync(join(directory, 'stubs'));
    for (const path of fixture.directories || []) mkdirSync(join(directory, path), { recursive: true });
    for (const file of fixture.files) write('src/' + file.path, file.source);
    write('ppphp.json', JSON.stringify(fixture.configuration || CONFIGURATION)); write('composer.json', '{}');
    result.full = php([join(ROOT, 'bin/ppphp'), 'check', ...(fixture.selection ? [fixture.selection] : []), '--format=json', '--no-interaction', '--no-ansi']);
    if (result.full.kind === 'completed' && [0, 1].includes(result.full.exitCode)) result.full.diagnostics = JSON.parse(result.full.stdout);
    write('request.json', JSON.stringify({ version: 1, requestId: fixture.id, action: 'prepare', operation: 'check', selection: { path: fixture.selection || null } }));
    result.preparation = php([join(ROOT, 'bin/ppphp'), 'browser:analysis', 'request.json', '--no-interaction', '--no-ansi']);
    if (result.preparation.kind !== 'completed' || result.preparation.exitCode !== 0 || result.preparation.stderr) throw new Error('Native preparation transport failed');
    result.prepared = JSON.parse(result.preparation.stdout);
    if (result.prepared.status === 'diagnostics') {
      result.completion = { status: result.prepared.diagnostics.summary.errors ? 1 : 0, diagnostics: result.prepared.diagnostics };
    } else if (result.prepared.status === 'prepared') {
      const plan = result.prepared.phpStan;
      if (!plan.resultPath.startsWith(directory + '/')) throw new Error('Analyzer workspace escaped the fixture');
      const projected = { ...result.prepared, phpStan: { ...plan, resultPath: '/workspace' + plan.resultPath.slice(directory.length) } };
      const paths = resolvePreparedDebugPaths(projected).map((path) => directory + path.slice('/workspace'.length));
      const command = [...plan.command];
      if (fixture.fault === 'missing-configuration') command[3] = '--configuration=' + directory + '/missing.neon';
      result.analyzer = php(command.slice(1));
      const framed = frameProcess(result.analyzer, paths, command);
      result.framing = framed.framing;
      write('completion-input.json', JSON.stringify({ prepared: result.prepared, selection: fixture.selection || null, process: framed.process }));
      result.mapping = php([ADAPTER, ROOT, join(directory, 'completion-input.json')]);
      if (result.mapping.kind !== 'completed' || result.mapping.exitCode !== 0 || result.mapping.stderr) throw new Error('Native compiler-owned completion failed: ' + result.mapping.stderr);
      result.completion = JSON.parse(result.mapping.stdout);
    } else throw new Error('Invalid native preparation response');
  } catch (error) { result.kind = 'failure'; result.error = String(error.stack || error).slice(0, 16384); }
  finally {
    try { rmSync(directory, { recursive: true, force: true }); }
    catch (error) { result.cleanupError = String(error); }
  }
  return result;
}

async function buildPage(runtime) {
  const bundle = runProcess(process.execPath, [join(SPIKE, 'scripts/prepare-compiler-bundle.mjs')], ROOT, 120000);
  if (bundle.exitCode !== 0) throw new Error('Compiler packaging failed: ' + bundle.stderr);
  const vite = await import('vite'); const configFile = join(SPIKE, 'vite.config.js');
  const config = (await import(pathToFileURL(configFile).href)).default;
  const assets = () => ({ name: 'ppphp-parity-wasm', enforce: 'pre', load(id) {
    if (!id.startsWith(runtime.root + '/') || !id.endsWith('.wasm')) return null;
    return `export default import.meta.ROLLUP_FILE_URL_${this.emitFile({ type: 'asset', name: basename(id), source: readFileSync(id) })};`;
  } });
  await vite.build({ root: SPIKE, configFile, logLevel: 'error', plugins: [forcedLoaderPlugin('asyncify', runtime.root), assets()],
    worker: { plugins: () => [...config.worker.plugins(), forcedLoaderPlugin('asyncify', runtime.root), assets()] },
    build: { rolldownOptions: { input: { parity: join(SPIKE, 'parity.html') } } } });
  return vite.preview({ root: SPIKE, configFile, preview: { host: '127.0.0.1', port: 4173, strictPort: true } });
}

export async function main(args = process.argv.slice(2)) {
  const options = {};
  for (let i = 0; i < args.length; i++) {
    const key = args[i];
    if (key === '--native-only') options[key] = true;
    else if (['--runtime', '--wasm-sha256', '--corpus', '--fixtures', '--output', '--case', '--controls'].includes(key) && args[i + 1] && !options[key]) options[key] = args[++i];
    else throw new Error('Unknown or duplicate parity option: ' + key);
  }
  const output = createOutput(options['--output']);
  const manifestPath = options['--fixtures'] || join(SPIKE, 'fixtures/projects.json');
  const manifest = readBoundedJson(manifestPath);
  if (manifest.format !== 'ppphp.browser-projects' || manifest.version !== 1) throw new Error('Invalid project manifest');
  let cases = manifest.cases;
  const report = { format: 'ppphp.bp3-parity', version: 1, observedAt: new Date().toISOString(), productionReady: false,
    bp3: 'INCOMPLETE', execution: 'NOT RUN', manifestSha256: sha256(readFileSync(manifestPath)), website: { status: 'NOT RUN', missingInput: 'Explicit --corpus path to a version 1 ppphp.browser-corpus export' }, cases: [] };
  const save = () => writeFileSync(join(output, 'report.json'), JSON.stringify(report, null, 2) + '\n');
  let server;
  try {
    if (options['--corpus']) {
      const corpus = validateCorpus(readBoundedJson(options['--corpus']));
      cases = [...cases, ...corpus.cases.map((item) => ({ ...item, configuration: CONFIGURATION, selection: null }))];
      report.website = { status: 'NOT RUN', sha256: sha256(readFileSync(options['--corpus'])), provenance: corpus.provenance, cases: corpus.cases.length };
    }
    await verifySourceHashes(cases, sha256);
    if (options['--case']) {
      cases = cases.filter((item) => item.id === options['--case']);
      if (!cases.length) throw new Error('Unknown case ID');
    }
    report.fixtures = cases;
    report.identity = { compilerCommit: runProcess('git', ['rev-parse', 'HEAD'], ROOT).stdout.trim(),
      compilerLockSha256: sha256(readFileSync(join(ROOT, 'composer.lock'))), browserLockSha256: sha256(readFileSync(join(SPIKE, 'package-lock.json'))),
      adapterSha256: sha256(readFileSync(ADAPTER)), node: process.version, platform: platform(), architecture: arch(),
      native: JSON.parse(runProcess(process.env.PHP_BINARY || 'php', ['-r', 'echo json_encode(["php"=>PHP_VERSION,"sapi"=>PHP_SAPI,"intSize"=>PHP_INT_SIZE,"extensions"=>get_loaded_extensions()]);'], output).stdout) };
    report.identity.dependencies = JSON.parse(runProcess(process.env.PHP_BINARY || 'php', ['-r', 'require $argv[1]."/vendor/autoload.php"; echo json_encode(["compiler"=>Atatusoft\\Ppphp\\Compiler\\Compiler::VERSION,"phpStan"=>Composer\\InstalledVersions::getPrettyVersion("phpstan/phpstan"),"phpStanSha256"=>hash_file("sha256",$argv[1]."/vendor/phpstan/phpstan/phpstan.phar"),"lockSha256"=>hash_file("sha256",$argv[1]."/composer.lock")]);', ROOT], output).stdout);
    const lockedAnalyzer = JSON.parse(readFileSync(join(ROOT, 'composer.lock'), 'utf8')).packages.find((item) => item.name === 'phpstan/phpstan');
    if (report.identity.dependencies.phpStan !== lockedAnalyzer.version) throw new Error('Installed analyzer differs from the lock');
    report.identity.harnessFiles = Object.fromEntries(['parity.html', 'src/parity-worker.js', 'src/parity.js', 'src/parity-adapter.php', 'src/parity-contract.mjs', 'src/parity-streams.mjs', 'src/phpstan-debug-output.mjs', 'scripts/run-project-parity.mjs', 'scripts/prepare-compiler-bundle.mjs'].map((path) => [path, sha256(readFileSync(join(SPIKE, path)))]));
    report.runtimeControls = { status: 'NOT RUN' };
    if (options['--controls']) {
      const controls = ['baseline-control', 'candidate-control', 'fiber-control'].map((name) => {
        const path = join(options['--controls'], name, 'report.json');
        return { report: readBoundedJson(path), sha256: sha256(readFileSync(path)) };
      });
      report.runtimeControls = { status: assessControls(...controls.map((item) => item.report), options['--wasm-sha256'], report.identity.compilerLockSha256) ? 'PASS' : 'FAIL', reportSha256: controls.map((item) => item.sha256) };
    }
    for (const fixture of cases) {
      const native = nativeCase(fixture);
      const nativeAssessment = assessCase(fixture, native, native);
      report.cases.push({ id: fixture.id, native, nativeAssessment, assessment: { parity: 'NOT RUN', intent: nativeAssessment.intent } }); save();
      console.log(`Native ${fixture.id}: ${native.kind}, status ${native.completion?.status}, codes ${native.completion?.diagnostics.diagnostics.map((d) => d.code).join(',')}`);
    }
    if (!options['--native-only']) {
      report.runtime = verifyRuntime(options['--runtime'], options['--wasm-sha256']);
      server = await buildPage(report.runtime);
      const browser = await launchChrome();
      report.browser = await collectObservation(browser, async () => {
        const identity = await browser.send('Browser.getVersion');
        await browser.send('Page.navigate', { url: 'http://127.0.0.1:4173/parity.html' });
        const readyDeadline = Date.now() + 15000;
        while (!(await browser.evaluate('window.__bp3?.ready || false'))) { if (Date.now() > readyDeadline) throw new Error('Parity page did not initialize'); await delay(100); }
        const suiteDeadline = Date.now() + 2700000;
        for (let i = 0; i < cases.length; i++) {
          if (Date.now() > suiteDeadline) throw new Error('Suite deadline exceeded');
          const fixture = cases[i];
          browser.events.length = 0;
          await browser.evaluate(`window.runParityCase(${JSON.stringify(fixture)})`);
          const deadline = Date.now() + 250000; let observed;
          while (!(observed = await browser.evaluate('window.__bp3.result'))) { if (Date.now() > deadline) throw new Error('External browser watchdog expired'); await delay(200); }
          const entry = report.cases[i]; entry.browser = observed;
          entry.consoleEvents = [...browser.events];
          entry.runtimeConsoleFailures = runtimeConsoleFailures(entry.consoleEvents);
          entry.consoleLimitReached = entry.consoleEvents.length >= 200;
          entry.execution = entry.native.kind === 'completed' && observed.kind === 'completed' && !entry.native.cleanupError && observed.cleanup === 'PASS' && !entry.runtimeConsoleFailures.length && !entry.consoleLimitReached ? 'PASS' : 'FAIL';
          try { entry.loadedArtifacts = verifyLoadedArtifacts([observed], join(SPIKE, 'dist'), report.runtime.wasmSha256); }
          catch (error) { entry.artifactError = error.message; }
          try { entry.assessment = assessCase(fixture, entry.native, observed); }
          catch (error) { entry.assessment = { parity: 'FAIL', intent: entry.nativeAssessment.intent, comparisonError: error.message }; }
          if (JSON.stringify(observed.dependencies) !== JSON.stringify(report.identity.dependencies)) entry.dependencyError = 'Browser compiler/analyzer bytes differ from the native reference';
          if (entry.dependencyError) entry.assessment.parity = 'FAIL';
          if (entry.artifactError) entry.assessment.parity = 'FAIL';
          save(); console.log(`Browser ${fixture.id}: parity ${entry.assessment.parity}, intent ${entry.assessment.intent}, execution ${entry.execution}${observed.error ? ', ' + observed.error : ''}`);
          if (observed.cleanupError) throw new Error('Worker termination failed; refusing to overlap cases');
        }
        return { kind: 'observed', identity, events: browser.events };
      });
      if (report.browser.kind !== 'observed' || report.browser.cleanupError) throw new Error('Browser observation or cleanup failed');
    }
    const compilerCases = report.cases.filter((entry) => !/^(learn|playground)\//.test(entry.id));
    const websiteCases = report.cases.filter((entry) => /^(learn|playground)\//.test(entry.id));
    const status = (items) => items.length && items.every((entry) => entry.assessment.parity === 'PASS' && entry.assessment.intent === 'PASS') ? 'PASS' : items.some((entry) => entry.assessment.parity === 'FAIL' || entry.assessment.intent === 'FAIL') ? 'FAIL' : 'NOT RUN';
    report.compilerFixtures = status(compilerCases);
    report.website.status = status(websiteCases);
    report.execution = report.cases.every((entry) => entry.execution === 'PASS') ? 'PASS' : report.cases.some((entry) => entry.execution === 'FAIL') ? 'FAIL' : 'NOT RUN';
    report.bp3 = !options['--case'] && report.manifestSha256 === sha256(readFileSync(join(SPIKE, 'fixtures/projects.json'))) && report.compilerFixtures === 'PASS' && report.website.status === 'PASS' && report.runtimeControls.status === 'PASS' && report.execution === 'PASS' ? 'PASS' : 'INCOMPLETE';
    if (report.compilerFixtures === 'FAIL' || report.website.status === 'FAIL' || report.runtimeControls.status === 'FAIL' || report.execution === 'FAIL' || report.cases.some((entry) => entry.native.kind !== 'completed')) process.exitCode = 1;
  } catch (error) { report.harnessError = String(error.stack || error).slice(0, 16384); process.exitCode = 1; console.error(report.harnessError); }
  finally {
    if (server) {
      try { server.httpServer.closeAllConnections(); await new Promise((accept, reject) => server.httpServer.close((error) => error ? reject(error) : accept())); }
      catch (error) { report.cleanupError = String(error); report.execution = 'FAIL'; report.bp3 = 'INCOMPLETE'; process.exitCode = 1; }
    }
    save(); console.log('BP-3 evidence: ' + join(output, 'report.json'));
  }
  return report;
}
if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) await main();
