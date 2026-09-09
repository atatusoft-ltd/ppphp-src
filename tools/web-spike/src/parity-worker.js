import { PHP, loadPHPRuntime, proxyFileSystem } from '@php-wasm/universal';
import { getPHPLoaderModule } from '@php-wasm/web-8-4';
import adapter from './parity-adapter.php?raw';
import { CONFIGURATION, verifySourceHashes, frameProcess } from './parity-contract.mjs';
import { resolvePreparedDebugPaths } from './phpstan-debug-output.mjs';
import { readProcessResult as read } from './parity-streams.mjs';

const digest = async (data) => [...new Uint8Array(await crypto.subtle.digest('SHA-256', typeof data === 'string' ? new TextEncoder().encode(data) : data))].map((v) => v.toString(16).padStart(2, '0')).join('');
const createPHP = async () => new PHP(await loadPHPRuntime(await getPHPLoaderModule()));

self.onmessage = async ({ data: fixture }) => {
  let php; const result = { id: fixture.id, kind: 'completed', durations: {} };
  const phase = (name) => self.postMessage({ type: 'phase', id: fixture.id, name });
  const runPhase = async (name, run) => {
    phase(name); const started = performance.now();
    self.postMessage({ type: 'observation', id: fixture.id, result });
    try { return await run(); } finally { result.durations[name] = Math.round(performance.now() - started); }
  };
  const cli = async (command) => {
    const child = await createPHP();
    await proxyFileSystem(php, child, ['/workspace', '/opt/ppphp']);
    child.chdir('/workspace');
    // cli() shuts PHP down; its documented contract requires discarding the instance.
    // The owning worker releases every runtime together, including trapped invocations.
    return await read(await child.cli(command, { env: { HOME: '/tmp', PATH: '/usr/bin:/bin', NO_COLOR: '1', TERM: 'dumb' } }));
  };
  try {
    await verifySourceHashes([fixture], digest);
    php = await createPHP();
    await runPhase('mount', async () => {
      const manifest = await (await fetch('/generated/compiler.json')).json();
      if (manifest.archive !== 'compiler.tar.gz.bin' || !/^[a-f0-9]{64}$/.test(manifest.sha256) || manifest.bytes > 32 * 1024 * 1024) throw new Error('Invalid compiler manifest');
      const response = await fetch('/generated/' + manifest.archive);
      if (!response.ok) throw new Error('Compiler archive unavailable');
      const archive = new Uint8Array(await response.arrayBuffer());
      if (archive.byteLength !== manifest.bytes || await digest(archive) !== manifest.sha256) throw new Error('Compiler archive integrity failure');
      php.mkdir('/opt/ppphp'); php.mkdir('/workspace/src'); php.mkdir('/workspace/stubs');
      php.writeFile('/tmp/compiler.tar.gz', archive);
      const mounted = await read(await php.runStream({ code: "<?php (new PharData('/tmp/compiler.tar.gz'))->extractTo('/opt/ppphp', null, true); echo json_encode(['php'=>PHP_VERSION,'sapi'=>PHP_SAPI,'intSize'=>PHP_INT_SIZE,'extensions'=>get_loaded_extensions(),'memoryLimit'=>ini_get('memory_limit')]);" }));
      if (mounted.exitCode !== 0 || mounted.stderr) throw new Error('Compiler mount failed: ' + mounted.stderr);
      result.platform = JSON.parse(mounted.stdout); result.compilerArchive = manifest;
      const identity = await read(await php.runStream({ code: "<?php require '/opt/ppphp/vendor/autoload.php'; echo json_encode(['compiler'=>Atatusoft\\Ppphp\\Compiler\\Compiler::VERSION,'phpStan'=>Composer\\InstalledVersions::getPrettyVersion('phpstan/phpstan'),'phpStanSha256'=>hash_file('sha256','/opt/ppphp/vendor/phpstan/phpstan/phpstan.phar'),'lockSha256'=>hash_file('sha256','/opt/ppphp/composer.lock')]);" }));
      if (identity.exitCode !== 0 || identity.stderr) throw new Error('Compiler dependency identity probe failed');
      result.dependencies = JSON.parse(identity.stdout);
    });
    for (const file of fixture.files) {
      const path = '/workspace/src/' + file.path;
      php.mkdir(path.slice(0, path.lastIndexOf('/')));
      php.writeFile(path, new TextEncoder().encode(file.source));
    }
    for (const path of fixture.directories || []) php.mkdir('/workspace/' + path);
    php.writeFile('/workspace/ppphp.json', JSON.stringify(fixture.configuration || CONFIGURATION));
    php.writeFile('/workspace/composer.json', '{}');
    php.writeFile('/workspace/request.json', JSON.stringify({ version: 1, requestId: fixture.id, action: 'prepare', operation: 'check', selection: { path: fixture.selection || null } }));
    result.preparation = await runPhase('prepare', () => cli(['php', '/opt/ppphp/bin/ppphp', 'browser:analysis', 'request.json', '--working-directory=/workspace', '--no-interaction', '--no-ansi']));
    if (![0, 1].includes(result.preparation.exitCode) || result.preparation.stderr) throw new Error('Preparation transport failed');
    result.prepared = JSON.parse(result.preparation.stdout);
    if (result.prepared.status === 'diagnostics') {
      result.completion = { status: result.prepared.diagnostics.summary.errors ? 1 : 0, diagnostics: result.prepared.diagnostics };
    } else if (result.prepared.status === 'prepared') {
      const command = [...result.prepared.phpStan.command];
      if (fixture.fault === 'missing-configuration') command[3] = '--configuration=/workspace/missing.neon';
      result.analyzer = await runPhase('analyzer', () => cli(command));
      const framed = frameProcess(result.analyzer, resolvePreparedDebugPaths(result.prepared), command);
      result.framing = framed.framing;
      php.writeFile('/workspace/parity-adapter.php', adapter);
      php.writeFile('/workspace/completion-input.json', JSON.stringify({ prepared: result.prepared, selection: fixture.selection || null, process: framed.process }));
      result.mapping = await runPhase('complete', () => cli(['php', '/workspace/parity-adapter.php', '/opt/ppphp', '/workspace/completion-input.json']));
      if (result.mapping.exitCode !== 0 || result.mapping.stderr) throw new Error('Compiler-owned completion failed: ' + result.mapping.stderr);
      result.completion = JSON.parse(result.mapping.stdout);
    } else throw new Error('Invalid preparation envelope');
  } catch (error) {
    result.kind = String(error).includes('BP3_OUTPUT_OVERFLOW') ? 'overflow' : 'failure';
    result.error = String(error.stack || error).slice(0, 16384);
    if (error.observation) result.failedProcess = error.observation;
  } finally {
    result.loadedResources = performance.getEntriesByType('resource').map((entry) => entry.name).slice(0, 64);
    // Do not call _exit again on CLI instances or the shared filesystem host.
    // The parent terminates this entire worker immediately after receiving the observation.
    self.postMessage({ type: 'result', id: fixture.id, result });
  }
};
