import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, realpathSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir, platform, arch } from 'node:os';
import { dirname, join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawn, spawnSync } from 'node:child_process';
import { setTimeout as delay } from 'node:timers/promises';
import { probes, assess } from '../src/baseline-probes.js';

const spikeRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const repoRoot = resolve(spikeRoot, '../..');
export const sha256 = (bytes) => createHash('sha256').update(bytes).digest('hex');
const bounded = (value) => String(value ?? '').slice(0, 16384);

export function createOutput(directory) {
  if (!directory) return mkdtempSync(join(tmpdir(), 'ppphp-bp0-'));
  const path = resolve(directory);
  const parent = realpathSync(dirname(path));
  const temporaryRoot = realpathSync(tmpdir());
  if (parent !== temporaryRoot && !parent.startsWith(temporaryRoot + sep)) throw new Error('Evidence must be written under the OS temporary directory');
  if (path === repoRoot || path.startsWith(repoRoot + sep)) throw new Error('Evidence may not be written in the repository');
  mkdirSync(path); // Never overwrite an existing directory or follow an output symlink.
  return path;
}

export function nativeProbe(probe, phpBinary = process.env.PHP_BINARY || 'php') {
  const directory = mkdtempSync(join(tmpdir(), 'ppphp-native-probe-'));
  try {
    const script = join(directory, 'probe.php');
    writeFileSync(script, probe.code);
    const command = ['-d', 'memory_limit=256M', '-d', 'display_errors=stderr', '-d', 'log_errors=0', ...(probe.cliLint ? ['-l'] : []), script];
    const result = spawnSync(phpBinary, command, {
      cwd: directory, encoding: 'utf8', timeout: probe.terminate ? 250 : 10000,
      killSignal: 'SIGKILL', maxBuffer: 2097152,
      env: { PATH: process.env.PATH || '', HOME: directory, TMPDIR: directory, LANG: 'C.UTF-8' },
    });
    const observation = {
      id: probe.id, sourceSha256: sha256(probe.code),
      kind: result.error?.code === 'ETIMEDOUT' ? 'terminated' : result.error ? 'not-run' : 'completed',
      // This is a process timeout, not a claim about browser watchdog precision.
      computeStarted: result.error?.code === 'ETIMEDOUT',
      stdout: result.stdout || '', stderr: result.stderr || '', exitCode: result.status,
      error: result.error ? bounded(result.error.message) : undefined,
      sideEffect: existsSync(join(directory, 'side-effect')),
    };
    return { ...observation, semantics: assess(probe, observation) };
  } finally { rmSync(directory, { recursive: true, force: true }); }
}

export async function launchChrome(binary = process.env.CHROME_BIN) {
  binary ||= ['/usr/bin/chromium', '/usr/bin/google-chrome', '/usr/bin/google-chrome-stable', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'].find(existsSync);
  if (!binary) throw new Error('No Chrome/Chromium executable found; set CHROME_BIN');
  const profile = mkdtempSync(join(tmpdir(), 'ppphp-browser-probe-'));
  const processHandle = spawn(binary, ['--headless=new', '--no-sandbox', '--disable-dev-shm-usage', '--remote-debugging-port=0', `--user-data-dir=${profile}`, 'about:blank'], { stdio: ['ignore', 'ignore', 'pipe'] });
  let launchError;
  let stderr = '';
  processHandle.on('error', (error) => { launchError = error; });
  processHandle.stderr.on('data', (chunk) => { if (stderr.length < 8192) stderr += String(chunk).slice(0, 8192 - stderr.length); });
  let socket;
  const pending = new Map();
  const events = [];
  let sequence = 0;
  async function close() {
    for (const value of pending.values()) { clearTimeout(value.timer); value.reject(new Error('Browser closed')); }
    pending.clear();
    socket?.close();
    if (processHandle.exitCode === null) {
      processHandle.kill('SIGTERM');
      for (let i = 0; i < 40 && processHandle.exitCode === null; i++) await delay(25);
      if (processHandle.exitCode === null) processHandle.kill('SIGKILL');
    }
    rmSync(profile, { recursive: true, force: true });
  }
  try {
    const activePort = join(profile, 'DevToolsActivePort');
    const end = Date.now() + 15000;
    while (!existsSync(activePort)) {
      if (launchError || processHandle.exitCode !== null || Date.now() > end) throw new Error(`Browser startup failed: ${launchError?.message || stderr}`);
      await delay(50);
    }
    const port = Number(readFileSync(activePort, 'utf8').split('\n')[0]);
    const tabs = await (await fetch(`http://127.0.0.1:${port}/json/list`, { signal: AbortSignal.timeout(10000) })).json();
    const tab = tabs.find((item) => item.type === 'page');
    if (!tab) throw new Error('Browser has no page target');
    socket = new WebSocket(tab.webSocketDebuggerUrl);
    await new Promise((accept, reject) => {
      const timer = setTimeout(() => reject(new Error('Browser connection timed out')), 10000);
      socket.addEventListener('open', () => { clearTimeout(timer); accept(); }, { once: true });
      socket.addEventListener('error', () => { clearTimeout(timer); reject(new Error('Browser connection failed')); }, { once: true });
    });
    socket.addEventListener('message', ({ data }) => {
      const message = JSON.parse(String(data));
      if (message.id) {
        const item = pending.get(message.id);
        if (!item) return;
        clearTimeout(item.timer); pending.delete(message.id);
        if (message.error) item.reject(new Error(JSON.stringify(message.error)));
        else item.resolve(message.result);
      } else if (['Runtime.consoleAPICalled', 'Runtime.exceptionThrown', 'Log.entryAdded'].includes(message.method) && events.length < 200) {
        events.push(bounded(JSON.stringify(message)));
      }
    });
    socket.addEventListener('close', () => {
      for (const value of pending.values()) { clearTimeout(value.timer); value.reject(new Error('Browser disconnected')); }
      pending.clear();
    });
    function send(method, params = {}) {
      return new Promise((accept, reject) => {
        const id = ++sequence;
        const timer = setTimeout(() => { pending.delete(id); reject(new Error(`Browser command timed out: ${method}`)); }, 10000);
        pending.set(id, { resolve: accept, reject, timer });
        socket.send(JSON.stringify({ id, method, params }));
      });
    }
    await send('Runtime.enable'); await send('Page.enable'); await send('Log.enable');
    return {
      close, send, events,
      evaluate: async (expression) => {
        const value = await send('Runtime.evaluate', { expression, returnByValue: true });
        if (value.exceptionDetails) throw new Error(bounded(JSON.stringify(value.exceptionDetails)));
        return value.result.value;
      },
    };
  } catch (error) { await close(); throw error; }
}

async function observe(url, baseline = false) {
  const browser = await launchChrome();
  try {
    const navigation = await browser.send('Page.navigate', { url });
    if (navigation.errorText) throw new Error(`Browser navigation failed: ${navigation.errorText}`);
    const end = Date.now() + (baseline ? 300000 : 90000);
    let last;
    while (Date.now() < end) {
      last = await browser.evaluate(baseline ? 'window.__bp0 || null' : '({status: document.querySelector("#status")?.textContent || "", output: (document.querySelector("#output")?.textContent || "").slice(0, 2097152)})');
      if (baseline ? last?.done : /completed the|failed|crashed|unreadable/i.test(last?.status || '')) return { kind: 'observed', data: last, events: browser.events };
      await delay(250);
    }
    return { kind: 'timeout', data: last, events: browser.events };
  } finally { await browser.close(); }
}

function identity() {
  const git = (args) => spawnSync('git', args, { cwd: repoRoot, encoding: 'utf8', timeout: 10000 }).stdout?.trim() || null;
  const files = ['composer.lock', 'tools/web-spike/package-lock.json', 'tools/web-spike/src/php-worker.js', 'tools/web-spike/src/main.js', 'tools/web-spike/index.html', 'tools/web-spike/vite.config.js', 'tools/web-spike/scripts/prepare-compiler-bundle.mjs'];
  const fingerprints = {};
  for (const path of files) if (existsSync(join(repoRoot, path))) fingerprints[path] = sha256(readFileSync(join(repoRoot, path)));
  const runtimeAssets = [];
  function walk(path) {
    if (!existsSync(path)) return;
    for (const entry of readdirSync(path, { withFileTypes: true })) {
      const file = join(path, entry.name);
      if (entry.isDirectory()) walk(file);
      else if (/\.(wasm|m?js)$/.test(entry.name)) {
        const data = readFileSync(file);
        runtimeAssets.push({ path: relative(spikeRoot, file).split(sep).join('/'), bytes: data.length, sha256: sha256(data) });
      }
    }
  }
  walk(join(spikeRoot, 'node_modules/@php-wasm/web-8-4'));
  const packageLock = join(spikeRoot, 'package-lock.json');
  const locked = existsSync(packageLock) ? JSON.parse(readFileSync(packageLock, 'utf8')).packages : {};
  const packages = {};
  for (const name of ['universal', 'util', 'web-8-4']) {
    const key = `node_modules/@php-wasm/${name}`;
    const path = join(spikeRoot, key, 'package.json');
    packages[name] = { lock: locked?.[key] ?? null, installed: existsSync(path) ? JSON.parse(readFileSync(path, 'utf8')).version : null };
  }
  return { packages, commit: git(['rev-parse', 'HEAD']), dirty: git(['status', '--porcelain']), node: process.version, platform: platform(), architecture: arch(), fingerprints, runtimeAssets, toolchainProvenance: 'NOT VERIFIED; recover from the pinned artifact in BP-1' };
}

async function closePreview(server) {
  server.httpServer.closeAllConnections();
  await new Promise((resolve, reject) => server.httpServer.close((error) => error ? reject(error) : resolve()));
}

export async function main(args = process.argv.slice(2)) {
  const options = {};
  for (let i = 0; i < args.length; i++) {
    if (args[i] === '--native-only') options.nativeOnly = true;
    else if (args[i] === '--output' && args[i + 1]) options.output = args[++i];
    else throw new Error(`Unknown or incomplete argument: ${args[i]}`);
  }
  const output = createOutput(options.output);
  const report = { format: 'ppphp.runtime-baseline', version: 1, executedAt: new Date().toISOString(), identity: identity(), native: [], unchangedSpike: { kind: 'not-run' }, browser: { kind: 'not-run' }, bp0: 'INCOMPLETE', productionReady: false };
  let preview;
  function save() { writeFileSync(join(output, 'report.json'), JSON.stringify(report, null, 2) + '\n'); }
  try {
    report.native = probes.map((probe) => nativeProbe(probe));
    save();
    if (!options.nativeOnly) {
      // Keep the existing packaging and original entry point unchanged first.
      const bundle = spawnSync(process.execPath, [join(spikeRoot, 'scripts/prepare-compiler-bundle.mjs')], { cwd: spikeRoot, stdio: 'inherit', timeout: 120000 });
      if (bundle.status !== 0) throw new Error('Existing compiler packaging failed');
      const vite = await import('vite');
      const configFile = join(spikeRoot, 'vite.config.js');
      await vite.build({ root: spikeRoot, configFile });
      preview = await vite.preview({ root: spikeRoot, configFile, preview: { host: '127.0.0.1', port: 4173, strictPort: true } });
      report.unchangedSpike = await observe('http://127.0.0.1:4173/');
      save();
      await closePreview(preview); preview = undefined;
      await vite.build({ root: spikeRoot, configFile, build: { rolldownOptions: { input: { baseline: join(spikeRoot, 'baseline.html') } } } });
      preview = await vite.preview({ root: spikeRoot, configFile, preview: { host: '127.0.0.1', port: 4173, strictPort: true } });
      report.browser = await observe('http://127.0.0.1:4173/baseline.html', true);
      if (report.browser.kind !== 'observed' || report.browser.data?.cases?.length !== probes.length + 1) throw new Error('Browser observation did not finish all independent cases');
      const control = report.browser.data.cases.find((item) => item.id === 'plain-json');
      if (control?.semantics !== 'PASS') throw new Error('Basic browser PHP control failed; do not attribute this to Fibers');
    }
    if (report.native.some((item) => item.semantics !== 'PASS')) throw new Error('Native reference failed; inspect the report');
    report.observation = options.nativeOnly ? 'NATIVE ONLY; BROWSER NOT RUN' : 'RECORDED; inspect each runtime semantics result';
  } catch (error) {
    report.harnessError = bounded(error.stack || error);
    process.exitCode = 1;
  } finally {
    if (preview) await closePreview(preview);
    save();
    console.log(`BP-0 evidence: ${join(output, 'report.json')}`);
    for (const result of report.native) console.log(`Native ${result.id}: ${result.semantics}`);
    for (const result of report.browser.data?.cases || []) console.log(`Browser ${result.id}: ${result.semantics} (${result.kind}, ${result.phase})`);
    console.log('BP-0 remains INCOMPLETE: full website corpus, parity, provenance and protocol work are not certified by this diagnostic runner.');
  }
  return report;
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) await main();
