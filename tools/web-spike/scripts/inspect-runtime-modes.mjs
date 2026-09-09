import { createHash } from 'node:crypto';
import { existsSync, readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve, basename } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { spawnSync } from 'node:child_process';
import { setTimeout as delay } from 'node:timers/promises';

export const modes = Object.freeze(['asyncify', 'jspi']);
const digest = (bytes) => createHash('sha256').update(bytes).digest('hex');
const decoder = new TextDecoder('utf-8', { fatal: true });
const MAX_BYTES = 64 * 1024 * 1024;

function reader(bytes) {
  let position = 0;
  return {
    get remaining() { return bytes.length - position; },
    byte() { return this.take(1)[0]; },
    take(length) {
      if (!Number.isSafeInteger(length) || length < 0 || length > this.remaining) throw new Error('Truncated WASM section');
      const result = bytes.subarray(position, position + length);
      position += length;
      return result;
    },
    u32() {
      let result = 0;
      for (let i = 0; i < 5; i++) {
        const value = this.byte();
        if (i === 4 && value > 15) throw new Error('Invalid WASM u32');
        result += (value & 127) * (2 ** (i * 7));
        if (!(value & 128)) return result;
      }
      throw new Error('Invalid WASM u32');
    },
    text() {
      const length = this.u32();
      if (length > 16384) throw new Error('Oversized WASM name');
      return decoder.decode(this.take(length));
    },
  };
}

// Read metadata only. This never instantiates or executes the untrusted module.
export function inspectWasm(bytes) {
  if (!(bytes instanceof Uint8Array) || bytes.length > MAX_BYTES) throw new Error('Invalid WASM input size');
  const input = reader(bytes);
  if (Buffer.from(input.take(8)).toString('hex') !== '0061736d01000000') throw new Error('Invalid WASM header');
  const functions = new Map();
  const customSections = [];
  const producers = [];
  while (input.remaining) {
    const id = input.byte();
    const section = reader(input.take(input.u32()));
    if (id !== 0) continue;
    const name = section.text();
    if (customSections.length >= 64) throw new Error('Too many custom sections');
    customSections.push(name);
    if (name === 'name') {
      while (section.remaining) {
        const subsectionId = section.byte();
        const subsection = reader(section.take(section.u32()));
        if (subsectionId !== 1) continue;
        const count = subsection.u32();
        if (count > 250000 || functions.size + count > 250000) throw new Error('Oversized WASM function table');
        for (let i = 0; i < count; i++) {
          const index = subsection.u32();
          if (functions.has(index)) throw new Error('Duplicate WASM function name');
          functions.set(index, subsection.text());
        }
        if (subsection.remaining) throw new Error('Trailing WASM name data');
      }
    } else if (name === 'producers') {
      const fields = section.u32();
      if (fields > 64) throw new Error('Oversized producer table');
      for (let i = 0; i < fields; i++) {
        const field = section.text();
        const count = section.u32();
        if (count > 128 || producers.length + count > 128) throw new Error('Oversized producer table');
        for (let j = 0; j < count; j++) producers.push({ field, name: section.text(), version: section.text() });
      }
      if (section.remaining) throw new Error('Trailing WASM producer data');
    }
  }
  return { functions, customSections, producers };
}

export function symbolizeStack(text, names) {
  const frames = [];
  const seen = new Set();
  for (const match of String(text || '').matchAll(/wasm-function\[(\d+)\]:(0x[0-9a-f]+|\d+)/gi)) {
    const index = Number(match[1]);
    const key = `${index}:${match[2]}`;
    if (seen.has(key)) continue;
    seen.add(key);
    frames.push({ index, offset: match[2], name: names.get(index) || null });
    if (frames.length === 64) break;
  }
  return frames;
}

// Package subpaths are not exported. Resolve their installed files only for
// this diagnostic build, without editing packages or spoofing feature detection.
export function forcedLoaderPlugin(mode, packageRoot) {
  if (!modes.includes(mode)) throw new Error(`Unsupported runtime mode: ${mode}`);
  const loaderPath = join(packageRoot, mode, 'php_8_4.js');
  if (!existsSync(loaderPath)) throw new Error(`Pinned ${mode} loader is absent`);
  const virtualId = `\0ppphp-bp0-${mode}-loader`;
  return {
    name: `ppphp-bp0-force-${mode}`,
    enforce: 'pre',
    resolveId(id) { return id === '@php-wasm/web-8-4' ? virtualId : null; },
    load(id) {
      return id === virtualId
        ? `import * as loader from ${JSON.stringify(loaderPath.replaceAll('\\', '/'))};\nexport async function getPHPLoaderModule() { return loader; }\n`
        : null;
    },
  };
}

export function findModeArtifact(packageRoot, mode) {
  if (!modes.includes(mode)) throw new Error(`Unsupported runtime mode: ${mode}`);
  const files = [];
  function walk(directory, depth) {
    if (depth > 4) throw new Error('Unexpected runtime package nesting');
    for (const entry of readdirSync(directory, { withFileTypes: true })) {
      const path = join(directory, entry.name);
      if (entry.isDirectory()) walk(path, depth + 1);
      else if (entry.isFile() && entry.name === 'php_8_4.wasm') files.push(path);
    }
  }
  walk(join(packageRoot, mode), 0);
  if (files.length !== 1) throw new Error('Expected one unambiguous PHP WASM artifact per mode');
  return files[0];
}

export function verifyLoadedArtifacts(cases, outputRoot, expectedDigest) {
  const urls = [...new Set(cases.flatMap((item) => item.loadedResources || []))]
    .filter((url) => new URL(url).pathname.endsWith('.wasm'));
  if (urls.length === 0) throw new Error('No loaded WASM resource was recorded');
  return urls.map((url) => {
    const parsed = new URL(url);
    if (parsed.origin !== 'http://127.0.0.1:4173' || !/^\/assets\/[^/]+\.wasm$/.test(parsed.pathname)) throw new Error('Unexpected runtime resource URL');
    const file = join(outputRoot, 'assets', basename(parsed.pathname));
    const bytes = readFileSync(file);
    const sha256 = digest(bytes);
    if (sha256 !== expectedDigest) throw new Error('Loaded runtime does not match the explicitly selected artifact');
    return { path: parsed.pathname, bytes: bytes.length, sha256 };
  });
}

export async function main(args = process.argv.slice(2)) {
  const { createOutput, launchChrome, collectObservation } = await import('./run-baseline.mjs');
  const { probes } = await import('../src/baseline-probes.js');
  const options = { modes: [...modes] };
  for (let i = 0; i < args.length; i++) {
    if (args[i] === '--output' && args[i + 1]) options.output = args[++i];
    else if (args[i] === '--mode' && modes.includes(args[i + 1])) options.modes = [args[++i]];
    else throw new Error(`Unknown or incomplete argument: ${args[i]}`);
  }
  const spikeRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
  const repoRoot = resolve(spikeRoot, '../..');
  const packageRoot = join(spikeRoot, 'node_modules/@php-wasm/web-8-4');
  const output = createOutput(options.output);
  const report = { format: 'ppphp.runtime-mode-inspection', version: 1, executedAt: new Date().toISOString(), commit: null, modes: [], productionReady: false };
  const save = () => writeFileSync(join(output, 'modes-report.json'), JSON.stringify(report, null, 2) + '\n');
  try {
    report.commit = spawnSync('git', ['rev-parse', 'HEAD'], { cwd: repoRoot, encoding: 'utf8', timeout: 10000 }).stdout?.trim() || null;
    report.lockSha256 = digest(readFileSync(join(spikeRoot, 'package-lock.json')));
    const installed = JSON.parse(readFileSync(join(packageRoot, 'package.json'), 'utf8'));
    const locked = JSON.parse(readFileSync(join(spikeRoot, 'package-lock.json'), 'utf8')).packages['node_modules/@php-wasm/web-8-4'];
    if (installed.version !== locked.version) throw new Error('Installed runtime version differs from lock');
    report.package = { name: installed.name, version: installed.version, integrity: locked.integrity, gitHead: installed.gitHead || null };
    const scan = spawnSync(process.env.PHP_BINARY || 'php', [join(spikeRoot, 'scripts/inspect-phpstan-fibers.php'), join(repoRoot, 'vendor/phpstan/phpstan/phpstan.phar')], { encoding: 'utf8', timeout: 30000, maxBuffer: 1048576 });
    if (scan.status !== 0) throw new Error(`PHPStan source inspection failed: ${scan.stderr || scan.error}`);
    report.phpStanSource = JSON.parse(scan.stdout);
    // Reuse the existing packaging implementation; dependencies stay unchanged.
    const bundle = spawnSync(process.execPath, [join(spikeRoot, 'scripts/prepare-compiler-bundle.mjs')], { cwd: spikeRoot, stdio: 'inherit', timeout: 120000 });
    if (bundle.status !== 0) throw new Error('Compiler packaging failed');
    const vite = await import('vite');
    const configFile = join(spikeRoot, 'vite.config.js');
    const config = (await import(pathToFileURL(configFile).href)).default;
    for (const mode of options.modes) {
      const entry = { mode, observation: 'NOT RUN' };
      report.modes.push(entry);
      let server;
      try {
        const wasm = readFileSync(findModeArtifact(packageRoot, mode));
        const metadata = inspectWasm(wasm);
        entry.artifact = { wasmSha256: digest(wasm), wasmBytes: wasm.length, loaderSha256: digest(readFileSync(join(packageRoot, mode, 'php_8_4.js'))), functionNames: metadata.functions.size, customSections: metadata.customSections, producers: metadata.producers };
        await vite.build({ root: spikeRoot, configFile,
          plugins: [forcedLoaderPlugin(mode, packageRoot)],
          worker: { plugins: () => [...(config.worker?.plugins?.() || []), forcedLoaderPlugin(mode, packageRoot)] },
          build: { rolldownOptions: { input: { baseline: join(spikeRoot, 'baseline.html') } } },
        });
        server = await vite.preview({ root: spikeRoot, configFile, preview: { host: '127.0.0.1', port: 4173, strictPort: true } });
        const browser = await launchChrome();
        const observed = await collectObservation(browser, async () => {
          const navigation = await browser.send('Page.navigate', { url: 'http://127.0.0.1:4173/baseline.html' });
          if (navigation.errorText) throw new Error(navigation.errorText);
          const deadline = Date.now() + 300000;
          let data;
          while (Date.now() < deadline) {
            data = await browser.evaluate('window.__bp0 || null');
            if (data?.done) return { kind: 'observed', data, events: browser.events };
            await delay(250);
          }
          return { kind: 'timeout', data, events: browser.events };
        });
        entry.browser = observed;
        if (observed.cleanupError) throw new Error('Browser cleanup failed; evidence is retained');
        if (observed.kind !== 'observed' || observed.data?.cases?.length !== probes.length + 1) throw new Error('Not all independent probes were observed');
        entry.loadedArtifacts = verifyLoadedArtifacts(observed.data.cases, join(spikeRoot, 'dist'), entry.artifact.wasmSha256);
        for (const result of observed.data.cases) result.symbolizedFrames = symbolizeStack(result.error, metadata.functions);
        for (const control of ['plain-json', 'platform', 'empty-output', 'post-runaway-control']) {
          if (observed.data.cases.find((item) => item.id === control)?.semantics !== 'PASS') throw new Error(`Required browser control failed: ${control}`);
        }
        entry.observation = 'RECORDED';
        entry.runtimeSemantics = observed.data.runtimeSemantics;
      } catch (error) { entry.harnessError = String(error.stack || error).slice(0, 16384); process.exitCode = 1; }
      finally {
        if (server) {
          try {
            server.httpServer.closeAllConnections();
            await new Promise((accept, reject) => server.httpServer.close((error) => error ? reject(error) : accept()));
          } catch (error) { entry.cleanupError = String(error); process.exitCode = 1; }
        }
        save();
        console.log(`${mode}: ${entry.observation}; runtime semantics ${entry.runtimeSemantics || 'NOT RUN'}`);
        if (entry.harnessError) console.error(entry.harnessError);
        for (const result of entry.browser?.data?.cases || []) {
          console.log(`${mode} ${result.id}: ${result.semantics}`);
          for (const frame of result.symbolizedFrames || []) console.log(`  ${frame.index} ${frame.name || '(no retained symbol)'} @ ${frame.offset}`);
        }
      }
    }
  } catch (error) { report.harnessError = String(error.stack || error).slice(0, 16384); process.exitCode = 1; }
  finally {
    save();
    console.log(`Runtime-mode evidence: ${join(output, 'modes-report.json')}`);
    if (report.harnessError) console.error(report.harnessError);
  }
  return report;
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) await main();
