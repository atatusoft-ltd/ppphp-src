import { PHP, loadPHPRuntime, proxyFileSystem } from '@php-wasm/universal';
import { getPHPLoaderModule } from '@php-wasm/web-8-4';
import { readProcessResult } from './parity-streams.mjs';
import { hash, validateWorkspace, validateInvocation, WORKFLOW_LIMITS } from './workflow-contract.mjs';
import faultEmitter from './workflow-fault-emitter.php?raw';

// Exactly one CLI phase per worker. Its filesystem host is discarded with it.
self.onmessage = async ({ data }) => {
  try {
    validateWorkspace(data.files);
    if (await hash(data.assets.wasm) !== data.assets.runtime.artifact || await hash(data.assets.archive) !== 'sha256:' + data.assets.compiler.sha256) throw new Error('Hydrated asset integrity mismatch');
    // All byte assets are supplied by the hydrated host. The PHP runtime cannot fetch.
    self.fetch = async () => { throw new Error('Networking is disabled during compiler execution'); };
    const createPHP = async () => new PHP(await loadPHPRuntime(await getPHPLoaderModule(), { wasmBinary: data.assets.wasm }));
    const host = await createPHP();
    host.mkdir('/opt/ppphp'); host.mkdir('/workspace');
    host.writeFile('/tmp/compiler.tar.gz', new Uint8Array(data.assets.archive));
    const mount = await readProcessResult(await host.runStream({ code: "<?php (new PharData('/tmp/compiler.tar.gz'))->extractTo('/opt/ppphp', null, true); echo json_encode(['phpVersion'=>PHP_VERSION,'sapi'=>PHP_SAPI,'intSize'=>PHP_INT_SIZE,'extensions'=>get_loaded_extensions(),'memoryLimit'=>ini_get('memory_limit')]);" }));
    if (mount.exitCode || mount.stderr) throw new Error('Compiler mount failed: ' + mount.stderr);
    const platform = JSON.parse(mount.stdout);
    for (const file of data.files) {
      const path = '/workspace/' + file.path;
      if (file.kind === 'directory') host.mkdir(path);
      else { host.mkdir(path.slice(0, path.lastIndexOf('/'))); host.writeFile(path, file.bytes); }
      host.chmod(path, file.mode);
    }
    const child = await createPHP();
    await proxyFileSystem(host, child, ['/workspace', '/opt/ppphp']);
    child.chdir('/workspace');
    let command;
    if (data.request) {
      host.mkdir('/workspace/.ppphp-browser');
      host.writeFile('/workspace/.ppphp-browser/request.json', JSON.stringify(data.request));
      command = ['php', '/opt/ppphp/bin/ppphp', 'browser:analysis', '.ppphp-browser/request.json', '--working-directory=/workspace', '--no-interaction', '--no-ansi'];
      if (data.testEmitter === 'invalid-php') {
        host.writeFile('/opt/ppphp/workflow-fault-emitter.php', faultEmitter);
        command = ['php', '/opt/ppphp/workflow-fault-emitter.php'];
      }
    } else {
      await validateInvocation(data.invocation);
      command = data.invocation.command;
      // Labelled qualification fault: remove the approved config, never accept another command.
      if (data.fault === 'missing-configuration') host.unlink('/workspace/.cache/analysis/phpstan.neon');
    }
    const process = await readProcessResult(await child.cli(command, { env: { HOME: '/tmp', PATH: '/usr/bin:/bin', NO_COLOR: '1', TERM: 'dumb' } }), WORKFLOW_LIMITS.processBytes);
    const metadata = await readProcessResult(await host.runStream({ code: "<?php $modes=[]; foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator('/workspace', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) { if($file->isLink()) throw new RuntimeException('Symlink in workspace'); $modes[$file->getPathname()]=$file->getPerms() & 0777; if(count($modes)>2048) throw new RuntimeException('Filesystem record limit'); } echo json_encode($modes);" }));
    if (metadata.exitCode || metadata.stderr) throw new Error('Filesystem metadata export failed');
    const modes = JSON.parse(metadata.stdout);
    const files = [];
    const collect = (directory) => {
      for (const name of host.listFiles(directory)) {
        const path = directory + '/' + name;
        if (path === '/workspace/.cache/analysis/tmp') continue;
        const relative = path.slice('/workspace/'.length);
        // Host-state bytes are bounded data. Analysis scratch caches are expendable.
        if (host.isDir(path)) {
          files.push({ path: relative, kind: 'directory', mode: modes[path], bytes: new Uint8Array() }); collect(path);
        } else files.push({ path: relative, kind: 'file', mode: modes[path], bytes: host.readFileAsBuffer(path) });
      }
    };
    collect('/workspace'); validateWorkspace(files);
    self.postMessage({ kind: 'phase-complete', process, files, platform });
  } catch (error) {
    self.postMessage({ kind: 'phase-failure', error: String(error.stack || error).slice(0, 16384), observation: error.observation });
  }
};
