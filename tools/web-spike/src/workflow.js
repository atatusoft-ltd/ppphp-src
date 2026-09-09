import WorkflowWorker from './workflow-worker.js?worker&inline';
import { CONFIGURATION, verifySourceHashes } from './parity-contract.mjs';
import { hash, processRecord, relativePath, validateWorkspace, WORKFLOW_LIMITS } from './workflow-contract.mjs';

const encoder = new TextEncoder();
const decoder = new TextDecoder('utf-8', { fatal: true });
window.__bp4 = { ready: false, result: null, phase: 'hydrate' };
const assets = {};
assets.compiler = await (await fetch('/generated/compiler.json')).json();
assets.runtime = await (await fetch('/generated/workflow-runtime.json')).json();
assets.archive = await (await fetch('/generated/' + assets.compiler.archive)).arrayBuffer();
assets.wasm = await (await fetch('/generated/workflow.wasm.bin')).arrayBuffer();
if (assets.archive.byteLength !== assets.compiler.bytes || await hash(assets.archive) !== 'sha256:' + assets.compiler.sha256 || await hash(assets.wasm) !== assets.runtime.artifact) throw new Error('Hydration failed integrity validation');
window.__bp4.ready = true;
let active = null;
let files = [];
let sequence = 0;
const write = (path, source) => { files = files.filter((item) => item.path !== path); files.push({ path, kind: 'file', mode: 420, bytes: encoder.encode(source) }); };
const directory = (path) => { if (!files.some((item) => item.path === path)) files.push({ path, kind: 'directory', mode: 493, bytes: new Uint8Array() }); };
const phase = (input) => new Promise((resolve, reject) => {
  if (active) return reject(new Error('A workspace phase is already active'));
  const worker = new WorkflowWorker(); active = worker;
  const finish = (error, result) => {
    if (active !== worker) return;
    clearTimeout(timer); worker.terminate(); active = null;
    error ? reject(error) : resolve(result);
  };
  window.__bp4.phase = input.request?.action || input.invocation?.kind;
  document.querySelector('#status').textContent = window.__bp4.phase;
  const timer = setTimeout(() => finish(new Error('Phase watchdog expired')), WORKFLOW_LIMITS.phaseMs);
  window.abortWorkflow = (reason = 'Owned workflow worker aborted') => finish(new Error(reason));
  worker.onmessage = ({ data }) => {
    if (active !== worker) return;
    if (data.kind !== 'phase-complete') return finish(new Error(data.error || 'Worker failure'));
    try { validateWorkspace(data.files); files = data.files; finish(null, data); } catch (error) { finish(error); }
  };
  worker.onerror = (event) => finish(new Error(event.message));
  worker.onmessageerror = () => finish(new Error('Malformed worker message'));
  worker.postMessage({ assets, files, ...input });
});
const request = async (payload, testEmitter) => {
  const observation = await phase({ request: payload, testEmitter });
  if (observation.process.stderr || ![0, 2, 70].includes(observation.process.exitCode)) throw new Error('Compiler transport failure: ' + observation.process.stderr);
  const response = JSON.parse(observation.process.stdout);
  if (response.currentOutput) {
    if (response.status !== 'complete' || response.compilerStatus !== 0 || response.operation !== 'build') throw new Error('Invalid publication response');
    const published = response.currentOutput;
    const root = relativePath(published.root) + '/';
    const expected = [{ path: '.ppphp/manifest.json', hash: published.manifestHash },
      ...published.artifacts.flatMap((item) => [{ path: item.path, hash: item.hash }, { path: item.sourceMap, hash: item.sourceMapHash }])];
    if (expected.length > WORKFLOW_LIMITS.files || new Set(expected.map((item) => item.path)).size !== expected.length) throw new Error('Invalid publication file set');
    for (const item of expected) {
      const file = files.find((file) => file.path === root + relativePath(item.path) && file.kind === 'file');
      if (!file || await hash(file.bytes) !== item.hash) throw new Error('Published artifact failed retrieval verification');
    }
  }
  return { response, observation: { process: observation.process, platform: observation.platform } };
};
const outputs = (response) => {
  if (!response.currentOutput) return null;
  const root = response.currentOutput.root + '/';
  const names = ['.ppphp/manifest.json', ...response.currentOutput.artifacts.flatMap((item) => [item.path, item.sourceMap])];
  return names.sort().map((path) => {
    const found = files.find((file) => file.path === root + path && file.kind === 'file');
    if (!found) throw new Error('Published artifact is missing');
    let binary = ''; for (const byte of found.bytes) binary += String.fromCharCode(byte);
    return { path, base64: btoa(binary) };
  });
};
const operate = async (fixture, operation) => {
  const started = performance.now();
  const transcript = [];
  let next = { version: 3, action: 'start', operationId: fixture.id + '/' + operation + '/' + (++sequence), sequence,
    operation, selection: { path: fixture.selection || null }, runtime: assets.runtime };
  const watchdog = setTimeout(() => window.abortWorkflow('Operation watchdog expired'), WORKFLOW_LIMITS.operationMs);
  try {
  for (let i = 0; i < 4; i++) {
    if (performance.now() - started > WORKFLOW_LIMITS.operationMs) throw new Error('Operation watchdog expired');
    const observed = await request(next, fixture.testEmitter); transcript.push({ request: next, ...observed });
    const response = observed.response;
    if (!response.status.startsWith('pending-')) return { response, transcript, outputs: outputs(response), platform: observed.observation.platform };
    const results = [];
    for (const invocation of response.invocations) {
      const observation = await phase({ invocation, fault: fixture.fault });
      transcript.push({ invocation, process: observation.process, platform: observation.platform });
      results.push(processRecord(invocation, observation.process));
    }
    next = { version: 3, action: response.status === 'pending-analysis' ? 'complete-analysis' : 'complete-lint',
      operationId: response.operationId, sequence: response.sequence, continuation: response.continuation, results };
  }
  throw new Error('Excessive compiler phase count');
  } finally { clearTimeout(watchdog); }
};
window.runWorkflowCase = (fixture) => {
  if (window.__bp4.running) throw new Error('A workflow case is already active');
  window.__bp4.running = true; window.__bp4.result = null;
  (async () => {
    try {
      await verifySourceHashes([fixture], async (data) => (await hash(data)).slice(7));
      files = []; sequence = 0;
      directory('src'); directory('stubs');
      for (const path of fixture.directories || []) directory(path);
      for (const file of fixture.files) write('src/' + file.path, file.source);
      write('ppphp.json', JSON.stringify(fixture.configuration || CONFIGURATION)); write('composer.json', '{}');
      const check = await operate(fixture, 'check');
      const build = check.response.compilerStatus === 0 && !fixture.fault ? await operate(fixture, 'build') : null;
      const repeat = build?.response.compilerStatus === 0 ? await operate(fixture, 'build') : null;
      window.__bp4.result = { kind: 'completed', check, build, repeat, compiler: assets.compiler, runtime: assets.runtime, cleanup: active === null ? 'PASS' : 'FAIL' };
    } catch (error) { window.__bp4.result = { kind: 'failure', error: String(error.stack || error), cleanup: active === null ? 'PASS' : 'FAIL' }; }
    finally { window.__bp4.running = false; }
  })();
};
// Qualification controls use the same owned workspace and production transport.
window.workflowQualification = { request, operate, outputs, write, directory, readFiles: () => files, replaceFiles: (value) => { files = validateWorkspace(value); }, decoder };

window.runWorkflowSequence = () => {
  if (window.__bp4.running) throw new Error('A workflow case is already active');
  window.__bp4.running = true; window.__bp4.result = null;
  const evidence = {};
  (async () => {
    try {
      files = []; sequence = 0; directory('src'); directory('stubs');
      write('ppphp.json', JSON.stringify(CONFIGURATION)); write('composer.json', '{}');
      write('src/main.ppphp', '<?php\nint $value = 1;\necho $value;\n');
      write('src/old.php', "<?php\n// copied exactly\necho 'old';\n");
      const a = evidence.a = await operate({ id: 'A' }, 'build');
      if (a.response.compilerStatus !== 0) throw new Error('A did not publish');
      const outputA = JSON.stringify(a.outputs);
      // Ordinary-PHP body checking reaches retained PHPStan, providing a real
      // failed B continuation to replay after C (a core failure has none).
      write('src/old.php', "<?php\nfunction invalidBrowserBuild(): int { return 'wrong'; }\n");
      const b = evidence.b = await operate({ id: 'B' }, 'build');
      evidence.bPreservesA = b.response.compilerStatus === 1 && b.response.currentOutput === null
        && b.response.previousOutput?.snapshot === a.response.snapshot && JSON.stringify(outputs(a.response)) === outputA;
      files = files.filter((item) => item.path !== 'src/old.php');
      write('src/new.php', "<?php\n// new copied bytes\necho 'new';\n");
      write('src/main.ppphp', '<?php\nint $value = 3;\necho $value;\n');
      const c = evidence.c = await operate({ id: 'C' }, 'build');
      if (c.response.compilerStatus !== 0) throw new Error('C did not publish');
      evidence.cRemovedStale = !files.some((file) => file.path === 'build/old.php');
      const outputC = JSON.stringify(c.outputs);
      evidence.stale = [];
      for (const item of [...a.transcript, ...b.transcript].filter((item) => item.request?.action?.startsWith('complete-'))) {
        const stale = await request(item.request); evidence.stale.push(stale);
        if (stale.response.status !== 'rejected' || JSON.stringify(outputs(c.response)) !== outputC) throw new Error('Stale completion changed C');
      }
      evidence.staleOwnersRejected = [a, b].every((operation) => evidence.stale.some((item) => item.response.operationId === operation.response.operationId));
      write('src/Context.ppphp', "<?php\nfunction preservedWorkflowContext(): string { return 'context'; }\n");
      evidence.context = await operate({ id: 'partial-context', selection: 'src/Context.ppphp' }, 'build');
      if (evidence.context.response.compilerStatus !== 0) throw new Error('Partial context did not publish');
      // Actual compile-only lint must not execute this copied file's top level.
      // Its function call also requires valid unselected ++PHP context.
      write('src/sentinel.php', "<?php\nuse DateTime;\nfile_put_contents(__DIR__ . '/executed', 'BAD');\necho preservedWorkflowContext();\nfunction lintWarningContext(): DateTime { return new DateTime(); }\n");
      evidence.sentinel = await operate({ id: 'sentinel', selection: 'src/sentinel.php' }, 'build');
      evidence.noExecution = evidence.sentinel.response.compilerStatus === 0 && !files.some((file) => file.path.endsWith('/executed'));
      evidence.lintWarningSuccess = evidence.sentinel.response.compilerStatus === 0
        && evidence.sentinel.transcript.some((item) => item.invocation?.kind === 'php-lint' && item.process.exitCode === 0
          && (item.process.stdout.includes('Warning:') || item.process.stderr.includes('Warning:')));
      evidence.partialPreservesC = files.find((file) => file.path === 'build/main.php')?.bytes
        && outputs(evidence.sentinel.response).find((file) => file.path === 'main.php').base64 === c.outputs.find((file) => file.path === 'main.php').base64
        && outputs(evidence.sentinel.response).find((file) => file.path === 'Context.php').base64 === evidence.context.outputs.find((file) => file.path === 'Context.php').base64;
      // Labelled transport faults reuse real observations. They are never counted
      // as analyzer or lint success and cannot acquire publication authority.
      const guardedStart = await request({ version: 3, action: 'start', operationId: 'validation-guards', sequence: ++sequence,
        operation: 'build', selection: { path: null }, runtime: assets.runtime });
      const observedAnalysis = [];
      for (const invocation of guardedStart.response.invocations) observedAnalysis.push(processRecord(invocation, (await phase({ invocation })).process));
      const guardedPending = await request({ version: 3, action: 'complete-analysis', operationId: 'validation-guards', sequence,
        continuation: guardedStart.response.continuation, results: observedAnalysis });
      if (guardedPending.response.status !== 'pending-validation') throw new Error('Validation guard did not prepare production');
      const observedLint = [];
      for (const invocation of guardedPending.response.invocations) observedLint.push(processRecord(invocation, (await phase({ invocation })).process));
      const validation = { version: 3, action: 'complete-lint', operationId: 'validation-guards', sequence,
        continuation: guardedPending.response.continuation, results: observedLint };
      const priorGuard = JSON.stringify(outputs(evidence.sentinel.response));
      evidence.validationGuards = [];
      const reject = async (label, payload) => {
        const rejected = await request(payload);
        evidence.validationGuards.push({ label, ...rejected });
        if (rejected.response.status !== 'rejected' || JSON.stringify(outputs(evidence.sentinel.response)) !== priorGuard) throw new Error(label + ' acquired publication authority');
      };
      await reject('missing lint', { ...validation, results: [] });
      await reject('partial lint', { ...validation, results: observedLint.slice(0, -1) });
      await reject('duplicate lint', { ...validation, results: [...observedLint, observedLint[0]] });
      await reject('reordered lint', { ...validation, results: [...observedLint].reverse() });
      await reject('foreign lint identity', { ...validation, results: [{ ...observedLint[0], invocation: 'sha256:' + 'a'.repeat(64) }, ...observedLint.slice(1)] });
      await reject('out of order phase', { ...validation, action: 'complete-analysis' });
      await reject('malformed request', { ...validation, unexpected: true });
      await reject('object-shaped lint results', { ...validation, results: { ...observedLint } });
      await reject('empty object lint results', { ...validation, results: {} });
      const originalPending = files.map((file) => ({ ...file, bytes: file.bytes.slice() }));
      const candidatePath = '.ppphp-browser/pending/' + guardedPending.response.candidates[0].path;
      write(candidatePath, '<?php echo "changed after lint";');
      await reject('mutated candidate bytes', validation);
      files = originalPending.map((file) => ({ ...file, bytes: file.bytes.slice() }));
      const mapPath = files.find((file) => file.kind === 'file' && file.path.startsWith('.ppphp-browser/pending/.ppphp/source-maps/')).path;
      write(mapPath, '{}');
      await reject('mutated source map', validation);
      files = originalPending.map((file) => ({ ...file, bytes: file.bytes.slice() }));
      write('.ppphp-browser/pending/unvalidated.php', '<?php echo "unvalidated";');
      await reject('unvalidated additional artifact', validation);
      files = originalPending;
      evidence.validationGuardsPassed = evidence.validationGuards.length === 12;
      const priorFault = JSON.stringify(outputs(evidence.sentinel.response));
      write('src/main.ppphp', '<?php\nint $value = 1;\necho $value;\n');
      evidence.invalidEmission = await operate({ id: 'invalid-emission', selection: 'src/main.ppphp', testEmitter: 'invalid-php' }, 'build');
      evidence.realLintRejected = evidence.invalidEmission.response.compilerStatus === 3
        && evidence.invalidEmission.transcript.some((item) => item.invocation?.kind === 'php-lint' && item.process.exitCode !== 0)
        && JSON.stringify(outputs(evidence.sentinel.response)) === priorFault;
      evidence.recovery = await operate({ id: 'recovery', selection: 'src/main.ppphp' }, 'build');
      if (evidence.recovery.response.compilerStatus !== 0) throw new Error('Recovery did not publish');
      // Abort an actual owned worker after dispatch; no pending phase commits its filesystem.
      const operation = operate({ id: 'abort' }, 'build');
      await new Promise((accept) => setTimeout(accept, 100));
      window.abortWorkflow();
      try { await operation; throw new Error('Abort unexpectedly completed'); } catch (error) { evidence.abort = String(error).includes('aborted'); }
      evidence.afterAbort = await operate({ id: 'after-abort' }, 'build');
      evidence.recoveredAfterAbort = evidence.afterAbort.response.compilerStatus === 0;
      const gates = ['bPreservesA', 'cRemovedStale', 'staleOwnersRejected', 'noExecution', 'lintWarningSuccess', 'partialPreservesC', 'validationGuardsPassed', 'realLintRejected', 'abort', 'recoveredAfterAbort'];
      window.__bp4.result = { kind: 'completed', status: gates.every((key) => evidence[key] === true) ? 'PASS' : 'FAIL', evidence, cleanup: active === null ? 'PASS' : 'FAIL' };
    } catch (error) { window.__bp4.result = { kind: 'failure', error: String(error.stack || error), evidence, cleanup: active === null ? 'PASS' : 'FAIL' }; }
    finally { window.__bp4.running = false; }
  })();
};
