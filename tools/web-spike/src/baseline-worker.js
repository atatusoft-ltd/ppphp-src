import { PHP, loadPHPRuntime, proxyFileSystem } from '@php-wasm/universal';
import { getPHPLoaderModule } from '@php-wasm/web-8-4';
import { validCleanPhpStanResult } from './baseline-probes.js';
import { readPhpStanDebugResult, resolvePreparedDebugPaths } from './phpstan-debug-output.mjs';

const MAX_OUTPUT = 2097152;
let caseId;
function phase(value) { self.postMessage({ id: caseId, type: 'phase', phase: value }); }
async function createPHP() { return new PHP(await loadPHPRuntime(await getPHPLoaderModule())); }
function dispose(php) { try { php?.[Symbol.dispose](); } catch { /* CLI may already have shut down. */ } }

// Drain both streams concurrently and reject oversized output before retaining it.
async function readLimited(stream) {
  const reader = stream.getReader();
  const chunks = [];
  let bytes = 0;
  try {
    for (;;) {
      const { value, done } = await reader.read();
      if (done) break;
      const chunk = typeof value === 'string' ? new TextEncoder().encode(value) : value;
      bytes += chunk.byteLength;
      if (bytes > MAX_OUTPUT) throw new Error('Probe output exceeded 2 MiB');
      chunks.push(chunk);
    }
  } finally { reader.releaseLock(); }
  const data = new Uint8Array(bytes);
  let offset = 0;
  for (const chunk of chunks) { data.set(chunk, offset); offset += chunk.byteLength; }
  return new TextDecoder().decode(data);
}
async function read(response) {
  const [stdout, stderr, exitCode] = await Promise.all([
    readLimited(response.stdout), readLimited(response.stderr), response.exitCode,
  ]);
  return { kind: 'completed', stdout, stderr, exitCode };
}
async function cli(host, args, paths = ['/workspace']) {
  const child = await createPHP();
  try {
    await proxyFileSystem(host, child, paths);
    return await read(await child.cli(args, {
      cwd: '/workspace', env: { HOME: '/tmp', NO_COLOR: '1', PATH: '/usr/bin:/bin', TERM: 'dumb' },
    }));
  } finally { dispose(child); }
}
async function mountCompiler(php) {
  phase('loading-compiler');
  const manifestResponse = await fetch('/generated/compiler.json');
  if (!manifestResponse.ok) throw new Error('Compiler manifest unavailable');
  const manifest = await manifestResponse.json();
  if (manifest.archive !== 'compiler.tar.gz.bin' || !/^[a-f0-9]{64}$/.test(manifest.sha256)) throw new Error('Invalid compiler manifest');
  const response = await fetch(`/generated/${manifest.archive}`);
  if (!response.ok) throw new Error('Compiler archive unavailable');
  const archive = new Uint8Array(await response.arrayBuffer());
  const digest = [...new Uint8Array(await crypto.subtle.digest('SHA-256', archive))].map((v) => v.toString(16).padStart(2, '0')).join('');
  if (digest !== manifest.sha256 || archive.byteLength !== manifest.bytes) throw new Error('Compiler archive integrity failure');
  php.mkdir('/opt/ppphp');
  php.writeFile('/tmp/compiler.tar.gz', archive);
  const extraction = await read(await php.runStream({ code: "<?php (new PharData('/tmp/compiler.tar.gz'))->extractTo('/opt/ppphp', null, true); echo 'ready';" }));
  if (extraction.exitCode !== 0 || extraction.stdout !== 'ready') throw new Error(`Compiler extraction failed: ${extraction.stderr}`);
  return manifest;
}
async function analyze(php) {
  const manifest = await mountCompiler(php);
  php.mkdir('/workspace/src');
  php.mkdir('/workspace/stubs');
  php.writeFile('/workspace/ppphp.json', JSON.stringify({ source: ['src'], output: 'build', cache: '.cache', targetPhpVersion: '8.4', stubs: ['stubs'], exclude: ['build', '.cache'] }));
  php.writeFile('/workspace/composer.json', '{}');
  php.writeFile('/workspace/src/main.ppphp', '<?php\nint $answer = 42;\necho $answer;\n');
  php.writeFile('/workspace/request.json', JSON.stringify({ version: 1, requestId: 'bp0-standalone', action: 'prepare', operation: 'check', selection: { path: null } }));
  phase('prepare-analysis');
  const prepared = await cli(php, ['php', '/opt/ppphp/bin/ppphp', 'browser:analysis', 'request.json', '--working-directory=/workspace', '--no-interaction', '--no-ansi'], ['/workspace', '/opt/ppphp']);
  if (prepared.exitCode !== 0) throw new Error(`Preparation failed: ${prepared.stdout}\n${prepared.stderr}`);
  const payload = JSON.parse(prepared.stdout);
  if (payload.status !== 'prepared' || !Array.isArray(payload.phpStan?.command)) throw new Error(`No analysis plan: ${prepared.stdout}`);
  // This command is produced by the trusted packaged compiler, not a visitor.
  phase('executing');
  const result = await cli(php, payload.phpStan.command, ['/workspace', '/opt/ppphp']);
  // Keep --debug and raw output. Separate only the manifest-owned progress lines.
  try {
    const expectedPaths = payload.phpStan.command.includes('--debug') ? resolvePreparedDebugPaths(payload) : [];
    const framed = readPhpStanDebugResult(result.stdout, expectedPaths);
    const validPhpStanJson = result.exitCode === 0 && result.stderr === '' && validCleanPhpStanResult(framed.jsonText);
    return { ...result, validPhpStanJson, phpStanJson: framed.jsonText, phpStanDebugPaths: framed.debugPaths,
      command: payload.phpStan.command, compilerArchive: manifest };
  } catch (error) {
    return { ...result, validPhpStanJson: false, outputFormatError: String(error.message).slice(0, 4096),
      command: payload.phpStan.command, compilerArchive: manifest };
  }
}

self.onmessage = async ({ data: probe }) => {
  caseId = probe.id;
  let php;
  let entropyReads = 0;
  try {
    php = await createPHP();
    php.mkdir('/workspace');
    let result;
    if (probe.compiler) result = await analyze(php);
    else if (probe.cliLint) {
      php.writeFile('/workspace/probe.php', probe.code);
      phase('executing');
      result = await cli(php, ['php', '-l', '/workspace/probe.php']);
      result.sideEffect = php.fileExists('/workspace/side-effect');
    } else {
      phase('executing');
      if (probe.entropyDenied) Object.defineProperty(globalThis.crypto, 'getRandomValues', { value() {
        entropyReads++;
        throw new Error('BP-7R entropy unavailable');
      } });
      result = await read(await php.runStream({ code: probe.code }));
    }
    result.loadedResources = performance.getEntriesByType('resource').map((entry) => entry.name).slice(0, 40);
    self.postMessage({ id: caseId, type: 'result', result });
  } catch (error) {
    self.postMessage({ id: caseId, type: 'result', result: { kind: 'trap', entropyReads, error: String(error?.stack ?? error).slice(0, 16384), loadedResources: performance.getEntriesByType('resource').map((entry) => entry.name).slice(0, 40) } });
  } finally { dispose(php); }
};
