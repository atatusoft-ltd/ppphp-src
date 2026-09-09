import { writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { setTimeout as delay } from 'node:timers/promises';
import { createHash } from 'node:crypto';
import { fiberContractProbes } from '../src/fiber-contract-probes.mjs';

export function assessFiberContract(data) {
  if (data?.suite !== 'fiber-contract' || data.done !== true || !Array.isArray(data.cases)
      || data.cases.length !== fiberContractProbes.length
      || new Set(data.cases.map((p) => p.id)).size !== fiberContractProbes.length) return false;
  return fiberContractProbes.every((probe) => {
    const result = data.cases.find((p) => p.id === probe.id);
    return result?.kind === 'completed' && result.computeStarted === true
      && result.exitCode === probe.exitCode && result.stdout === probe.stdout
      && result.stderr === '' && result.semantics === 'PASS';
  });
}

export async function main(args = process.argv.slice(2)) {
  const options = {};
  for (let i = 0; i < args.length; i += 2) {
    if (!['--output', '--wasm-sha256'].includes(args[i]) || !args[i + 1] || options[args[i]]) throw new Error('Invalid Fiber contract arguments');
    options[args[i]] = args[i + 1];
  }
  if (!options['--output'] || !/^[a-f0-9]{64}$/.test(options['--wasm-sha256'] || '')) throw new Error('An output directory and pinned WASM hash are required');
  const { createOutput, launchChrome, collectObservation } = await import('./run-baseline.mjs');
  const { verifyLoadedArtifacts } = await import('./inspect-runtime-modes.mjs');
  const spike = resolve(dirname(fileURLToPath(import.meta.url)), '..');
  const output = createOutput(options['--output']);
  const report = { format: 'ppphp.fiber-contract', version: 1, observedAt: new Date().toISOString(),
    expectedWasmSha256: options['--wasm-sha256'], accepted: false, productionReady: false,
    fixtures: fiberContractProbes.map((p) => ({ id: p.id, sourceSha256: createHash('sha256').update(p.code).digest('hex') })) };
  let server;
  try {
    // Reuse the candidate's already-built diagnostic page. Do not rebuild PHP.
    const vite = await import('vite');
    server = await vite.preview({ root: spike, configFile: join(spike, 'vite.config.js'),
      preview: { host: '127.0.0.1', port: 4174, strictPort: true } });
    const browser = await launchChrome();
    report.browser = await collectObservation(browser, async () => {
      const navigation = await browser.send('Page.navigate', { url: 'http://127.0.0.1:4174/baseline.html?suite=fiber-contract' });
      if (navigation.errorText) throw new Error(navigation.errorText);
      const deadline = Date.now() + 180000;
      let data;
      while (Date.now() < deadline) {
        data = await browser.evaluate('window.__bp0 || null');
        if (data?.done) return { kind: 'observed', data, events: browser.events };
        await delay(250);
      }
      return { kind: 'timeout', data, events: browser.events };
    });
    if (report.browser.kind !== 'observed' || report.browser.cleanupError) throw new Error('Fiber contract observation or cleanup failed');
    report.loadedArtifacts = verifyLoadedArtifacts(report.browser.data.cases, join(spike, 'dist'), report.expectedWasmSha256);
    report.accepted = assessFiberContract(report.browser.data);
    for (const item of report.browser.data.cases) console.log(`Fiber contract ${item.id}: ${item.semantics}`);
    if (!report.accepted) throw new Error('The rebuilt candidate failed a Fiber lifecycle contract');
  } catch (error) {
    report.error = String(error.stack || error).slice(0, 16384); process.exitCode = 1; console.error(report.error);
  } finally {
    if (server) {
      server.httpServer.closeAllConnections();
      await new Promise((accept) => server.httpServer.close(accept));
    }
    writeFileSync(join(output, 'report.json'), JSON.stringify(report, null, 2) + '\n');
  }
  return report;
}
if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) await main();
