import { readFileSync, writeFileSync, mkdirSync, mkdtempSync, rmSync, realpathSync, readdirSync, copyFileSync } from 'node:fs';
import { join, dirname, resolve, basename } from 'node:path';
import { pathToFileURL, fileURLToPath } from 'node:url';
import { tmpdir } from 'node:os';
import { setTimeout as delay } from 'node:timers/promises';
import { ROOT, SPIKE, nativeCase, runProcess, verifyRuntime, readBoundedJson } from './run-project-parity.mjs';
import { CONFIGURATION, validateCorpus, verifySourceHashes, runtimeConsoleFailures } from '../src/parity-contract.mjs';
import { createOutput, sha256, launchChrome, collectObservation } from './run-baseline.mjs';
import { forcedLoaderPlugin, findModeArtifact } from './inspect-runtime-modes.mjs';

export function nativeBuild(fixture) {
  const root = realpathSync(mkdtempSync(join(tmpdir(), 'ppphp-workflow-native-')));
  const write = (path, data) => { mkdirSync(dirname(join(root, path)), { recursive: true }); writeFileSync(join(root, path), data); };
  const collect = (directory, prefix = '') => readdirSync(directory, { withFileTypes: true }).flatMap((entry) => entry.isDirectory()
    ? collect(join(directory, entry.name), prefix + entry.name + '/') : [{ path: prefix + entry.name, base64: readFileSync(join(directory, entry.name)).toString('base64') }]).sort((a, b) => a.path.localeCompare(b.path, 'en'));
  try {
    mkdirSync(join(root, 'src')); mkdirSync(join(root, 'stubs'));
    for (const path of fixture.directories || []) mkdirSync(join(root, path), { recursive: true });
    for (const file of fixture.files) write('src/' + file.path, file.source);
    write('ppphp.json', JSON.stringify(fixture.configuration || CONFIGURATION)); write('composer.json', '{}');
    const command = [join(ROOT, 'bin/ppphp'), 'build', ...(fixture.selection ? [fixture.selection] : []), '--format=json', '--no-interaction', '--no-ansi'];
    const process = runProcess(globalThis.process.env.PHP_BINARY || 'php', command, root);
    return { process, outputs: process.exitCode === 0 ? collect(join(root, 'build')) : null };
  } finally { rmSync(root, { recursive: true, force: true }); }
}

export async function buildWorkflowPage(runtime) {
  const bundle = runProcess(process.execPath, [join(SPIKE, 'scripts/prepare-compiler-bundle.mjs')], ROOT, 120000);
  if (bundle.exitCode !== 0) throw new Error('Compiler bundle failed: ' + bundle.stderr);
  const descriptor = { phpVersion: '8.4.23', sapi: 'cli', intSize: 8, artifact: 'sha256:' + runtime.wasmSha256, loader: 'sha256:' + runtime.loaderSha256 };
  writeFileSync(join(SPIKE, 'public/generated/workflow-runtime.json'), JSON.stringify(descriptor));
  copyFileSync(findModeArtifact(runtime.root, 'asyncify'), join(SPIKE, 'public/generated/workflow.wasm.bin'));
  const vite = await import('vite');
  const configFile = join(SPIKE, 'vite.config.js');
  const config = (await import(pathToFileURL(configFile).href)).default;
  const assets = () => ({ name: 'ppphp-workflow-wasm', enforce: 'pre', load(id) {
    if (!id.startsWith(runtime.root + '/') || !id.endsWith('.wasm')) return null;
    // The inline worker receives verified wasmBinary. Avoid resolving an unused
    // asset URL against its blob: module URL during module initialization.
    return 'export default "/generated/workflow.wasm.bin";';
  } });
  await vite.build({ root: SPIKE, configFile, logLevel: 'error', plugins: [forcedLoaderPlugin('asyncify', runtime.root), assets()],
    worker: { plugins: () => [...config.worker.plugins(), forcedLoaderPlugin('asyncify', runtime.root), assets()], rolldownOptions: { output: { codeSplitting: false } } },
    build: { rolldownOptions: { input: { workflow: join(SPIKE, 'workflow.html') } } } });
  return vite.preview({ root: SPIKE, configFile, preview: { host: '127.0.0.1', port: 4173, strictPort: true } });
}

const ordered = (files) => files?.toSorted((a, b) => a.path < b.path ? -1 : a.path > b.path ? 1 : 0);
export function assessStaleCompletions(sequence) {
  const ids = ['a', 'b'].map((key) => sequence?.evidence?.[key]?.response?.operationId);
  const stale = sequence?.evidence?.stale;
  return ids.every((id) => typeof id === 'string') && new Set(ids).size === 2 && Array.isArray(stale)
    && stale.every((item) => ids.includes(item.response?.operationId) && item.response?.status === 'rejected' && item.response?.currentOutput === null)
    && ids.every((id) => stale.some((item) => item.response?.operationId === id));
}
export function assessWorkflow(native, build, browser) {
  return { check: browser.kind === 'completed' && native.completion?.status === browser.check?.response.compilerStatus
      && JSON.stringify(native.completion?.diagnostics) === JSON.stringify(browser.check?.response.diagnostics)
      && (!native.completion?.identities || JSON.stringify(native.completion.identities) === JSON.stringify(browser.check?.response.identities)) ? 'PASS' : 'FAIL',
    build: build === null ? 'NOT APPLICABLE' : build.process.exitCode === 0 && browser.build?.response.compilerStatus === 0
      && JSON.stringify(ordered(build.outputs)) === JSON.stringify(ordered(browser.build.outputs)) ? 'PASS' : 'FAIL',
    buildDiagnostics: build === null ? 'NOT APPLICABLE' : (() => {
      try { return JSON.stringify(JSON.parse(build.process.stdout)) === JSON.stringify(browser.build?.response.diagnostics) ? 'PASS' : 'FAIL'; }
      catch { return 'FAIL'; }
    })(),
    deterministic: build?.process.exitCode === 0 ? browser.build?.response.compilerStatus === 0 && browser.repeat?.response.compilerStatus === 0
      && Array.isArray(browser.build.outputs) && Array.isArray(browser.repeat.outputs)
      && JSON.stringify(ordered(browser.build.outputs)) === JSON.stringify(ordered(browser.repeat.outputs)) ? 'PASS' : 'FAIL' : 'NOT APPLICABLE',
    cleanup: browser.cleanup === 'PASS' ? 'PASS' : 'FAIL' };
}

export async function main(args = process.argv.slice(2)) {
  const options = {};
  for (let i = 0; i < args.length; i += 2) {
    if (args[i] === '--sequence-only') { options[args[i]] = true; i--; continue; }
    if (!['--runtime', '--wasm-sha256', '--corpus', '--output', '--case'].includes(args[i]) || !args[i + 1] || options[args[i]]) throw new Error('Invalid workflow option');
    options[args[i]] = args[i + 1];
  }
  const output = createOutput(options['--output']);
  const report = { format: 'ppphp.bp4-workflow', version: 1, observedAt: new Date().toISOString(), status: 'NOT RUN', productionReady: false, cases: [] };
  const save = () => writeFileSync(join(output, 'report.json'), JSON.stringify(report, null, 2) + '\n');
  let server;
  const suiteDeadline = Date.now() + 45 * 60 * 1000;
  const guardSuite = () => { if (Date.now() > suiteDeadline) throw new Error('45 minute workflow suite watchdog expired'); };
  try {
    let cases = readBoundedJson(join(SPIKE, 'fixtures/projects.json')).cases;
    report.corpus = { status: 'NOT RUN' };
    if (options['--corpus']) {
      const corpus = validateCorpus(readBoundedJson(options['--corpus']));
      report.corpus = { sha256: sha256(readFileSync(options['--corpus'])), count: corpus.cases.length, provenance: corpus.provenance };
      if (report.corpus.sha256 !== '066124d08e112c8c8ac16bf795b7639bfaadd34052037b55a8779459bffce17f') throw new Error('Frozen corpus hash mismatch');
      cases = [...cases, ...corpus.cases];
    }
    await verifySourceHashes(cases, sha256);
    if (options['--case']) cases = cases.filter((item) => item.id === options['--case']);
    if (!cases.length) throw new Error('No matching workflow cases');
    if (options['--sequence-only']) cases = [];
    report.runtime = verifyRuntime(options['--runtime'], options['--wasm-sha256']);
    report.sourceCommit = runProcess('git', ['rev-parse', 'HEAD'], ROOT).stdout.trim();
    report.identity = { node: process.version, platform: process.platform, architecture: process.arch,
      browserLockSha256: sha256(readFileSync(join(SPIKE, 'package-lock.json'))),
      harnessFiles: Object.fromEntries(['workflow.html', 'src/workflow.js', 'src/workflow-worker.js', 'src/workflow-contract.mjs',
        'src/workflow-fault-emitter.php', 'scripts/run-workflow.mjs', 'scripts/prepare-compiler-bundle.mjs'].map((path) => [path, sha256(readFileSync(join(SPIKE, path)))])),
      native: JSON.parse(runProcess(process.env.PHP_BINARY || 'php', ['-r',
        'require $argv[1]."/vendor/autoload.php"; echo json_encode(["php"=>PHP_VERSION,"sapi"=>PHP_SAPI,"intSize"=>PHP_INT_SIZE,"extensions"=>get_loaded_extensions(),"compiler"=>Atatusoft\\Ppphp\\Compiler\\Compiler::VERSION,"buildIdentity"=>(new Atatusoft\\Ppphp\\Cache\\CompilerBuildIdentity())->calculate(),"phpStan"=>Composer\\InstalledVersions::getPrettyVersion("phpstan/phpstan"),"phpStanSha256"=>hash_file("sha256",$argv[1]."/vendor/phpstan/phpstan/phpstan.phar")]);', ROOT], ROOT).stdout) };
    report.fixtures = cases;
    if (!options['--case']) report.sequenceReference = nativeBuild({ files: [
      { path: 'main.ppphp', source: '<?php\nint $value = 3;\necho $value;\n' },
      { path: 'new.php', source: "<?php\n// new copied bytes\necho 'new';\n" },
    ] });
    for (const fixture of cases) {
      guardSuite();
      const native = nativeCase(fixture);
      const build = native.completion?.status === 0 && !fixture.fault ? nativeBuild(fixture) : null;
      report.cases.push({ id: fixture.id, native, nativeBuild: build }); save();
      console.log(`Native workflow ${fixture.id}: check ${native.completion?.status}, build ${build?.process.exitCode ?? 'N/A'}`);
    }
    server = await buildWorkflowPage(report.runtime);
    report.compilerArchive = readBoundedJson(join(SPIKE, 'public/generated/compiler.json'));
    const identifyAssets = (directory, prefix = '') => readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
      const path = prefix + entry.name;
      if (entry.isDirectory()) return identifyAssets(join(directory, entry.name), path + '/');
      const bytes = readFileSync(join(directory, entry.name));
      return [{ path, bytes: bytes.length, sha256: sha256(bytes) }];
    });
    report.browserAssets = identifyAssets(join(SPIKE, 'dist'));
    const browser = await launchChrome();
    report.browser = await collectObservation(browser, async () => {
      const identity = await browser.send('Browser.getVersion');
      await browser.send('Page.navigate', { url: 'http://127.0.0.1:4173/workflow.html' });
      const readyDeadline = Date.now() + 60000;
      while (!(await browser.evaluate('window.__bp4?.ready || false'))) { if (Date.now() > readyDeadline) throw new Error('Workflow hydration failed: ' + browser.events.join('\n')); await delay(100); }
      report.hydrationResources = await browser.evaluate('performance.getEntriesByType("resource").map(({name,initiatorType,transferSize,encodedBodySize,decodedBodySize}) => ({name,initiatorType,transferSize,encodedBodySize,decodedBodySize}))');
      // No request is permitted after hydration, including loopback execution endpoints.
      await browser.send('Network.enable');
      await browser.send('Network.emulateNetworkConditions', { offline: true, latency: 0, downloadThroughput: 0, uploadThroughput: 0 });
      report.offline = true;
      for (let i = 0; i < cases.length; i++) {
        browser.events.length = 0;
        await browser.evaluate(`window.runWorkflowCase(${JSON.stringify(cases[i])})`);
        let result; const deadline = Date.now() + 720000;
        while (!(result = await browser.evaluate('window.__bp4.result'))) { guardSuite(); if (Date.now() > deadline) throw new Error('Workflow external watchdog expired'); await delay(250); }
        const entry = report.cases[i]; entry.browser = result; entry.consoleEvents = [...browser.events];
        entry.consoleFailures = runtimeConsoleFailures(entry.consoleEvents);
        entry.assessment = assessWorkflow(entry.native, entry.nativeBuild, result);
        if (entry.consoleFailures.length || entry.consoleEvents.length >= 200) entry.assessment.execution = 'FAIL';
        save(); console.log(`Browser workflow ${entry.id}: ${JSON.stringify(entry.assessment)} ${result.error || ''}`);
        if (result.cleanup !== 'PASS') throw new Error('Worker cleanup failed');
      }
      if (!options['--case']) {
        browser.events.length = 0;
        await browser.evaluate('window.runWorkflowSequence()');
        const deadline = Date.now() + 1200000; let result;
        while (!(result = await browser.evaluate('window.__bp4.result'))) { guardSuite(); if (Date.now() > deadline) throw new Error('Sequence watchdog expired'); await delay(250); }
        report.sequence = result;
        report.sequence.consoleEvents = [...browser.events];
        report.sequence.consoleFailures = runtimeConsoleFailures(browser.events);
        report.sequence.nativeEquivalentC = JSON.stringify(ordered(result.evidence?.c?.outputs)) === JSON.stringify(ordered(report.sequenceReference.outputs));
        report.sequence.staleOwnersRejected = assessStaleCompletions(result);
        if (report.sequence.consoleFailures.length || !report.sequence.nativeEquivalentC || !report.sequence.staleOwnersRejected) report.sequence.status = 'FAIL';
        save(); console.log('Sequential workflow: ' + report.sequence.status + ' ' + (result.error || ''));
      }
      await browser.send('Page.navigate', { url: 'about:blank' });
      return { kind: 'observed', identity };
    });
    const pass = report.cases.every((entry) => entry.assessment && !Object.values(entry.assessment).includes('FAIL'))
      && (!report.sequence || (report.sequence.status === 'PASS' && report.sequence.cleanup === 'PASS'))
      && report.browser.kind === 'observed' && !report.browser.cleanupError;
    report.status = pass ? 'PASS' : 'FAIL';
    report.completeCorpus = cases.length === 48 && !options['--case'];
    if (!pass) process.exitCode = 1;
  } catch (error) { report.error = String(error.stack || error); report.status = 'FAIL'; process.exitCode = 1; console.error(report.error); }
  finally {
    if (server) { server.httpServer.closeAllConnections(); await new Promise((accept) => server.httpServer.close(accept)); }
    save(); console.log('BP-4 evidence: ' + join(output, 'report.json'));
  }
  return report;
}
if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) await main();
