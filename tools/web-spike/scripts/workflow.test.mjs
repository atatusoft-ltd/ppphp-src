import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { runInNewContext } from 'node:vm';
import { hash, canonical, validateWorkspace, validateInvocation, relativePath, processRecord } from '../src/workflow-contract.mjs';
import { ROOT, SPIKE, runProcess } from './run-project-parity.mjs';
import { assessWorkflow, assessStaleCompletions } from './run-workflow.mjs';

test('workflow canonical hashing agrees with the production PHP protocol', async () => {
  const value = { z: ['é', { c: 2, a: 1 }], a: '/workspace/example' };
  const result = runProcess(process.env.PHP_BINARY || 'php', ['-r',
    'require $argv[1]."/vendor/autoload.php"; echo Atatusoft\\Ppphp\\Analysis\\Browser\\ProtocolJson::hash(Atatusoft\\Ppphp\\Analysis\\Browser\\ProtocolJson::encodeCanonical(json_decode($argv[2],true)));',
    ROOT, JSON.stringify(value)], ROOT);
  assert.equal(result.exitCode, 0); assert.equal(result.stdout, await hash(canonical(value)));
});
test('workflow host rejects general commands and changed approved identities', async () => {
  const invocation = { kind: 'php-lint', command: ['php', '-n', '-l', '/workspace/.ppphp-browser/pending/main.php'],
    workingDirectory: '/workspace', path: 'main.php', hash: 'sha256:' + 'a'.repeat(64), binding: 'sha256:' + 'b'.repeat(64) };
  invocation.identity = await hash(canonical(invocation));
  await validateInvocation(invocation);
  await assert.rejects(validateInvocation({ ...invocation, path: 'other.php' }));
  for (const command of [['sh', '-c', 'php'], ['php', '-r', 'echo 1;'], ['php', '-l', '/workspace/main.php']]) {
    const changed = { ...invocation, command }; delete changed.identity;
    changed.identity = await hash(canonical(changed));
    await assert.rejects(validateInvocation(changed));
  }
});
test('workspace transfer rejects duplicate paths traversal invalid bytes and excess records', () => {
  const record = { path: 'src/main.php', mode: 420, kind: 'file', bytes: new Uint8Array([0, 255, 13, 10]) };
  assert.deepEqual(validateWorkspace([record]), [record]);
  for (const path of ['/root', '../root', 'a/../b', 'a\\b', 'http:remote', 'a\0b']) assert.throws(() => relativePath(path));
  for (const records of [[record, record], [{ ...record, bytes: 'truncated' }], [{ ...record, mode: -1 }],
    [{ ...record, bytes: new Uint8Array(16777217) }], Array(1025).fill(record)]) assert.throws(() => validateWorkspace(records));
});
test('process records retain incomplete timeout and overflow states', () => {
  for (const kind of ['timeout', 'overflow', 'failure']) {
    const value = processRecord({ identity: 'sha256:' + 'a'.repeat(64) }, { kind, stdout: 'partial', stderr: 'cause', exitCode: null, error: 'observed failure' });
    assert.equal(value.complete, false); assert.equal(value.stdout, 'partial'); assert.equal(value.executionFailure, 'observed failure');
  }
});
test('workflow comparison does not bless missing artifacts or changed diagnostics', () => {
  const diagnostics = { version: 1, diagnostics: [], summary: { errors: 0, warnings: 0, notes: 0 } };
  const native = { completion: { status: 0, diagnostics } };
  const build = { process: { exitCode: 0, stdout: JSON.stringify(diagnostics) }, outputs: [{ path: 'a.php', base64: 'AQ==' }] };
  const browser = { kind: 'completed', cleanup: 'PASS', check: { response: { compilerStatus: 0, diagnostics } },
    build: { response: { compilerStatus: 0, diagnostics }, outputs: build.outputs }, repeat: { response: { compilerStatus: 0 }, outputs: build.outputs } };
  assert.equal(assessWorkflow(native, build, browser).build, 'PASS');
  assert.equal(assessWorkflow(native, build, { ...browser, build: { ...browser.build, outputs: [] } }).build, 'FAIL');
  assert.equal(assessWorkflow(native, { process: { exitCode: 3 }, outputs: null }, { ...browser, build: { response: { compilerStatus: 3 }, outputs: null } }).build, 'FAIL');
  assert.equal(assessWorkflow(native, build, browser).deterministic, 'PASS');
  assert.equal(assessWorkflow(native, build, browser).buildDiagnostics, 'PASS');
  assert.equal(assessWorkflow(native, build, { ...browser, build: { ...browser.build, response: { compilerStatus: 0, diagnostics: {} } } }).buildDiagnostics, 'FAIL');
  assert.equal(assessWorkflow(native, build, { ...browser, build: undefined, repeat: undefined }).deterministic, 'FAIL');
});
test('workflow entry points parse and use the actual versioned compiler command', () => {
  for (const path of ['src/workflow.js', 'src/workflow-worker.js', 'src/workflow-contract.mjs', 'scripts/run-workflow.mjs']) {
    const result = runProcess(process.execPath, ['--check', join(SPIKE, path)], ROOT);
    assert.equal(result.exitCode, 0, result.stderr);
  }
  const worker = readFileSync(join(SPIKE, 'src/workflow-worker.js'), 'utf8');
  assert.match(worker, /'browser:analysis'/); assert.doesNotMatch(worker, /parity-adapter/);
  assert.match(worker, /child\.chdir\('\/workspace'\)/);
});

test('sequential acceptance requires rejected genuine completions from both A and B', () => {
  const response = (operationId) => ({ operationId, status: 'rejected', currentOutput: null });
  const evidence = { a: { response: { operationId: 'A/build/1' } }, b: { response: { operationId: 'B/build/2' } },
    stale: [{ response: response('A/build/1') }] };
  assert.equal(assessStaleCompletions({ evidence }), false);
  evidence.stale.push({ response: response('B/build/2') });
  assert.equal(assessStaleCompletions({ evidence }), true);
  evidence.stale[1].response.currentOutput = {};
  assert.equal(assessStaleCompletions({ evidence }), false);
});

test('a queued message from a terminated worker cannot overwrite the next workspace', async () => {
  const workers = [];
  class Worker {
    constructor() { workers.push(this); this.terminated = 0; }
    postMessage() {}
    terminate() { this.terminated++; }
  }
  const source = readFileSync(join(SPIKE, 'src/workflow.js'), 'utf8');
  const phase = source.slice(source.indexOf('const phase ='), source.indexOf('const request ='));
  const context = { WorkflowWorker: Worker, window: { __bp4: {} }, document: { querySelector: () => ({}) },
    WORKFLOW_LIMITS: { phaseMs: 1000 }, validateWorkspace, assets: {}, setTimeout, clearTimeout };
  runInNewContext('let active = null; let files = []; ' + phase + '; globalThis.startPhase = phase; globalThis.readFiles = () => files; globalThis.currentWorker = () => active;', context);
  const old = context.startPhase({ request: { action: 'start' } });
  context.window.abortWorkflow();
  await assert.rejects(old, /aborted/);
  const next = context.startPhase({ request: { action: 'start' } });
  workers[0].onmessage({ data: { kind: 'phase-complete', files: [{ path: 'build/stale.php', kind: 'file', mode: 420, bytes: new Uint8Array([1]) }] } });
  assert.equal(context.readFiles().length, 0);
  assert.equal(context.currentWorker(), workers[1]);
  workers[1].onmessage({ data: { kind: 'phase-complete', files: [] } });
  await next;
  assert.equal(workers[0].terminated, 1); assert.equal(workers[1].terminated, 1);
});
