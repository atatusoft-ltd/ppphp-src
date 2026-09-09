import { readFileSync, existsSync, writeFileSync, realpathSync } from 'node:fs';
import { dirname, resolve, join, basename } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { createHash } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { setTimeout as delay } from 'node:timers/promises';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const spike = join(root, 'tools/web-spike');
const sha = (data) => createHash('sha256').update(data).digest('hex');
const knownFailures = new Set(['fiber-return', 'fiber-resume', 'fiber-throw-finally', 'nested-fiber', 'gc-inside-fiber', 'phpstan-standalone']);

export function assessProfile(cases, profile, ids) {
  if (!['baseline', 'candidate'].includes(profile)) throw new Error('Unknown diagnostic profile');
  if (!Array.isArray(ids) || !ids.length || new Set(ids).size !== ids.length) throw new Error('Invalid reference case identities');
  if (!Array.isArray(cases) || cases.length !== ids.length || new Set(cases.map((p) => p.id)).size !== ids.length) return false;
  return ids.every((id) => {
    const result = cases.find((p) => p.id === id);
    if (!result) return false;
    return profile === 'baseline' && knownFailures.has(id)
      ? result.semantics === 'FAIL' && result.kind === 'trap' && /getcontext/.test(result.error || '')
      : result.semantics === 'PASS';
  });
}

export async function main(args = process.argv.slice(2)) {
  const { createOutput, launchChrome, collectObservation } = await import('../web-spike/scripts/run-baseline.mjs');
  const { forcedLoaderPlugin, findModeArtifact, inspectWasm, symbolizeStack, verifyLoadedArtifacts } = await import('../web-spike/scripts/inspect-runtime-modes.mjs');
  const { probes } = await import('../web-spike/src/baseline-probes.js');
  const options = {};
  for (let i = 0; i < args.length; i += 2) {
    if (!['--runtime', '--expect', '--output'].includes(args[i]) || !args[i + 1]) throw new Error('Expected --runtime, --expect and --output');
    if (options[args[i]]) throw new Error('Duplicate option');
    options[args[i]] = args[i + 1];
  }
  if (!options['--runtime'] || !['baseline', 'candidate'].includes(options['--expect'])) throw new Error('Runtime directory and profile are required');
  const output = createOutput(options['--output']);
  const packageRoot = realpathSync(options['--runtime']);
  const report = { format: 'ppphp.rebuilt-runtime', version: 1, profile: options['--expect'], observedAt: new Date().toISOString(), productionReady: false, accepted: false };
  let server;
  try {
    const manifest = JSON.parse(readFileSync(join(packageRoot, 'artifacts.json'), 'utf8'));
    if (manifest.profile !== report.profile || !Array.isArray(manifest.files)) throw new Error('Invalid artifact profile manifest');
    for (const item of manifest.files) {
      if (typeof item.path !== 'string' || !/^[A-Za-z0-9_./-]+$/.test(item.path) || item.path.startsWith('/') || item.path.split('/').includes('..')) throw new Error('Unsafe artifact path');
      const bytes = readFileSync(join(packageRoot, item.path));
      if (bytes.length !== item.bytes || sha(bytes) !== item.sha256) throw new Error('Artifact integrity check failed');
    }
    const wasmPath = findModeArtifact(packageRoot, 'asyncify');
    const bytes = readFileSync(wasmPath);
    const metadata = inspectWasm(bytes);
    if (!metadata.functions.size) throw new Error('The rebuilt runtime did not preserve function names');
    report.artifact = { sha256: sha(bytes), bytes: bytes.length, functionNames: metadata.functions.size, customSections: metadata.customSections, producers: metadata.producers };
    const bundle = spawnSync(process.execPath, [join(spike, 'scripts/prepare-compiler-bundle.mjs')], { cwd: root, stdio: 'inherit', timeout: 120000 });
    if (bundle.status !== 0) throw new Error('Compiler archive preparation failed');
    const vite = await import(pathToFileURL(join(spike, 'node_modules/vite/dist/node/index.js')).href);
    const configFile = join(spike, 'vite.config.js');
    const config = (await import(pathToFileURL(configFile).href)).default;
    const assetPlugin = () => ({ name: 'ppphp-rebuilt-wasm', enforce: 'pre', load(id) {
      if (!id.startsWith(packageRoot + '/') || !id.endsWith('.wasm')) return null;
      const reference = this.emitFile({ type: 'asset', name: basename(id), source: readFileSync(id) });
      return `export default import.meta.ROLLUP_FILE_URL_${reference};`;
    } });
    await vite.build({ root: spike, configFile, plugins: [forcedLoaderPlugin('asyncify', packageRoot), assetPlugin()],
      worker: { plugins: () => [...(config.worker?.plugins?.() || []), forcedLoaderPlugin('asyncify', packageRoot), assetPlugin()] },
      build: { rolldownOptions: { input: { baseline: join(spike, 'baseline.html') } } },
    });
    server = await vite.preview({ root: spike, configFile, preview: { host: '127.0.0.1', port: 4173, strictPort: true } });
    const browser = await launchChrome();
    report.browser = await collectObservation(browser, async () => {
      const nav = await browser.send('Page.navigate', { url: 'http://127.0.0.1:4173/baseline.html' });
      if (nav.errorText) throw new Error(nav.errorText);
      const deadline = Date.now() + 300000;
      let data;
      while (Date.now() < deadline) {
        data = await browser.evaluate('window.__bp0 || null');
        if (data?.done) return { kind: 'observed', data, events: browser.events };
        await delay(250);
      }
      return { kind: 'timeout', data, events: browser.events };
    });
    if (report.browser.kind !== 'observed' || report.browser.cleanupError) throw new Error('Browser observation/cleanup failed');
    const cases = report.browser.data.cases;
    report.loadedArtifacts = verifyLoadedArtifacts(cases, join(spike, 'dist'), report.artifact.sha256);
    for (const result of cases) result.symbolizedFrames = symbolizeStack(result.error, metadata.functions);
    report.accepted = assessProfile(cases, report.profile, [...probes.map((p) => p.id), 'phpstan-standalone']);
    for (const result of cases) {
      console.log(`${report.profile} ${result.id}: ${result.semantics}`);
      if (result.error) console.log(result.error.slice(0, 16384));
      for (const frame of result.symbolizedFrames) console.log(`  ${frame.index}: ${frame.name || '(unknown)'} @ ${frame.offset}`);
    }
    if (!report.accepted) throw new Error('Rebuilt runtime did not satisfy the selected diagnostic profile');
  } catch (error) { report.error = String(error.stack || error).slice(0, 16384); process.exitCode = 1; console.error(report.error); }
  finally {
    if (server) {
      server.httpServer.closeAllConnections();
      await new Promise((accept) => server.httpServer.close(accept));
    }
    writeFileSync(join(output, 'report.json'), JSON.stringify(report, null, 2) + '\n');
  }
  return report;
}
if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) await main();
