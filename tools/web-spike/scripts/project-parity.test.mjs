import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, readFileSync, rmSync, symlinkSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { CONFIGURATION, validateCorpus, validateCases, verifySourceHashes, compareDiagnostics, frameProcess, assessCase, terminateWorker, runtimeConsoleFailures } from '../src/parity-contract.mjs';
import { readPhpStanDebugResult } from '../src/phpstan-debug-output.mjs';
import { ROOT, ADAPTER, SPIKE, runProcess, readBoundedJson, verifyRuntime, assessControls } from './run-project-parity.mjs';
import { sha256 } from './run-baseline.mjs';
import { readProcessResult } from '../src/parity-streams.mjs';
import { probes } from '../src/baseline-probes.js';
import { fiberContractProbes } from '../src/fiber-contract-probes.mjs';

const file = { path: 'main.php', source: '<?php\r\n// café 🧪\r\nfunction value(): int { return "wrong"; }\r\n' };
file.sha256 = sha256(file.source);
const corpus = () => ({ format: 'ppphp.browser-corpus', version: 1,
  provenance: { repository: 'atatusoft-ltd/ppphp-website', contentRevision: 'a'.repeat(40), sourceFiles: [{ path: 'src/Learn/LearnService.php', sha256: 'b'.repeat(64) }] },
  cases: [{ id: 'learn/example/starter', page: 'learn', route: '/learn/example', exampleId: 'example', variant: 'starter', label: 'Example',
    files: [file], entry: 'main.php', entryBasis: 'Existing resolver', intent: { check: 'diagnostics', basis: 'Authored error' }, requiredActions: ['check', 'build', 'run'], expected: null }] });

test('corpus import preserves exact CRLF and multibyte source and leaves expectations unknown', async () => {
  const data = validateCorpus(corpus());
  await verifySourceHashes(data.cases, sha256);
  assert.equal(data.cases[0].files[0].source, file.source);
  assert.equal(data.cases[0].expected, null);
});
test('corpus rejects invalid provenance identity paths operations and excessive size', async () => {
  const mutations = [
    (c) => c.version++, (c) => c.provenance.contentRevision = 'latest',
    (c) => c.provenance.sourceFiles.push(c.provenance.sourceFiles[0]),
    (c) => c.provenance.sourceFiles[0].path = 'src/../secret.php',
    (c) => c.cases.push(c.cases[0]), (c) => c.cases[0].files.push(c.cases[0].files[0]),
    (c) => c.cases[0].files[0].path = '../secret.php', (c) => c.cases[0].files[0].path = '/secret.php',
    (c) => c.cases[0].files[0].sha256 = 'sha256:' + 'a'.repeat(64),
    (c) => c.cases[0].requiredActions.push('shell'), (c) => c.cases[0].requiredActions.push('check'),
    (c) => c.cases[0].entry = 'missing.php', (c) => c.cases[0].intent.check = 'success',
    (c) => c.cases[0].route = '//remote.example/learn',
    (c) => c.cases[0].files[0].source = 'a'.repeat(65537),
    (c) => c.cases = Array.from({ length: 129 }, () => c.cases[0]),
  ];
  for (const mutate of mutations) { const data = structuredClone(corpus()); mutate(data); assert.throws(() => validateCorpus(data)); }
  const data = structuredClone(corpus()); data.cases[0].files[0].source += '\n';
  await assert.rejects(verifySourceHashes(data.cases, sha256), /hash mismatch/);
});
test('project fixtures bind exact hashes and reject unsafe configuration and directory writes', async () => {
  const manifest = readBoundedJson(join(SPIKE, 'fixtures/projects.json'));
  await verifySourceHashes(manifest.cases, sha256);
  for (const patch of [{ directories: ['../escape'] }, { configuration: { ...CONFIGURATION, cache: '/tmp' } }, { selection: 'src/../other' }, { fault: 'shell' }]) {
    assert.throws(() => validateCases([{ ...manifest.cases[0], ...patch }]));
  }
});
test('real-project framing accepts progress in either order and retains negative result identifiers', () => {
  const paths = ['/workspace/selected/a.php', '/workspace/selected/b.php'];
  const json = JSON.stringify({ totals: { errors: 0, file_errors: 1 }, files: { [paths[1]]: { errors: 1, messages: [{ message: "Expected int, 'quoted' given.", line: 3, ignorable: true, identifier: 'return.type' }] } }, errors: [] });
  for (const ordered of [paths, [...paths].reverse()]) {
    const text = ordered.join('\n') + '\n' + json;
    const result = frameProcess({ kind: 'completed', stdout: text, stderr: 'retained stderr', exitCode: 1 }, paths, ['php']);
    assert.equal(result.framing.status, 'PASS'); assert.equal(result.process.stdout, json);
    assert.equal(result.process.stderr, 'retained stderr'); assert.equal(result.process.exitCode, 1);
    assert.deepEqual(result.framing.debugPaths, ordered);
  }
  for (const invalid of [paths[0] + '\n' + json, paths.join('\n') + '\n' + json + '{}', paths.join('\n') + '\n' + json.slice(0, -1),
    paths[0] + '\n' + paths[0] + '\n' + json, '/unknown.php\n' + paths[1] + '\n' + json, 'noise\n' + paths.join('\n') + '\n' + json,
    paths.join('\n') + '\n' + json + 'trailing noise']) assert.throws(() => readPhpStanDebugResult(invalid, paths));
});
test('synthetic transport failures retain separate flags and raw observations', () => {
  for (const kind of ['timeout', 'overflow', 'launch-failure', 'trap']) {
    const result = frameProcess({ kind, stdout: 'partial', stderr: 'cause', error: 'failure' }, [], ['php']);
    assert.equal(result.framing.status, 'NOT RUN'); assert.equal(result.process.stdout, 'partial'); assert.equal(result.process.stderr, 'cause');
    assert.equal(result.process.timedOut, kind === 'timeout'); assert.equal(result.process.outputLimitExceeded, kind === 'overflow');
    assert.equal(result.process.executionFailure !== null, ['launch-failure', 'trap'].includes(kind));
  }
});
test('synthetic streams drain both channels and share the exact UTF-8 byte limit', async () => {
  const stream = (chunks) => new ReadableStream({ start(controller) { for (const chunk of chunks) controller.enqueue(chunk); controller.close(); } });
  const response = (stdout, stderr, exitCode = 1) => ({ stdout: stream(stdout), stderr: stream(stderr), exitCode: Promise.resolve(exitCode) });
  assert.deepEqual(await readProcessResult(response(['é'], ['warn']), 6), { kind: 'completed', stdout: 'é', stderr: 'warn', exitCode: 1 });
  await assert.rejects(readProcessResult(response(['é'], ['warn']), 5), /OVERFLOW/);
  await assert.rejects(readProcessResult(response(['partial'], ['over limit']), 7), (error) => {
    assert.equal(error.observation.kind, 'overflow'); assert.equal(error.observation.incomplete, true);
    assert.equal(error.observation.stdout, 'partial'); assert.equal(error.observation.stderr, ''); return true;
  });
  assert.deepEqual(await readProcessResult(response([], [], 0), 1), { kind: 'completed', stdout: '', stderr: '', exitCode: 0 });
  await assert.rejects(readProcessResult(response([Uint8Array.from([0xff])], [])), /encoded data/);
});
test('synthetic control reports cannot qualify missing cases wrong artifacts or cleanup failures', () => {
  const wasm = 'c'.repeat(64), lock = 'd'.repeat(64);
  const failures = ['fiber-return', 'fiber-resume', 'fiber-throw-finally', 'nested-fiber', 'gc-inside-fiber', 'phpstan-standalone'];
  const ids = [...probes.map((p) => p.id), 'phpstan-standalone'];
  const cases = ids.map((id) => ({ id, kind: 'completed', semantics: 'PASS', compilerArchive: { compilerLockSha256: lock } }));
  const baseline = { format: 'ppphp.rebuilt-runtime', profile: 'baseline', accepted: true, browser: { kind: 'observed', data: { cases: cases.map((c) => failures.includes(c.id) ? { ...c, kind: 'trap', semantics: 'FAIL', error: '_getcontext' } : c) } } };
  const candidate = { format: 'ppphp.rebuilt-runtime', profile: 'candidate', accepted: true, artifact: { sha256: wasm }, loadedArtifacts: [{ sha256: wasm }], browser: { kind: 'observed', data: { cases } } };
  const fiber = { accepted: true, expectedWasmSha256: wasm, loadedArtifacts: [{ sha256: wasm }], browser: { kind: 'observed', data: { suite: 'fiber-contract', done: true, cases: fiberContractProbes.map((p) => ({ id: p.id, kind: 'completed', computeStarted: true, exitCode: p.exitCode, stdout: p.stdout, stderr: '', semantics: 'PASS' })) } } };
  assert.equal(assessControls(baseline, candidate, fiber, wasm, lock), true);
  assert.equal(assessControls(baseline, candidate, fiber, '0'.repeat(64), lock), false);
  assert.equal(assessControls(baseline, candidate, fiber, wasm, '0'.repeat(64)), false);
  for (const mutate of [(c) => c.browser.data.cases.pop(), (c) => c.browser.cleanupError = 'failed', (c) => c.accepted = false]) {
    const changed = structuredClone(candidate); mutate(changed); assert.equal(assessControls(baseline, changed, fiber, wasm, lock), false);
  }
});
test('comparison does not erase identifiers quoted values locations order or missing findings', () => {
  const diagnostic = { code: 'P2016', severity: 'error', title: 'Return Type Does Not Match', message: "Expected int; got 'café'.", location: { file: 'main.php', range: { start: { offset: 12, line: 3, column: 1 } } }, related: [], help: 'Return an int.' };
  for (const patch of [{ code: 'P2099' }, { severity: 'warning' }, { message: "Expected int; got 'other'." }, { location: null }, { related: [{ message: 'changed' }] }, { help: 'changed' }]) {
    assert.equal(compareDiagnostics([diagnostic], [{ ...diagnostic, ...patch }]), false);
  }
  assert.equal(compareDiagnostics([diagnostic], []), false);
  assert.equal(compareDiagnostics([{ identity: 'return.type' }], [{ identity: 'argument.type' }]), false);
  assert.equal(compareDiagnostics([diagnostic, { ...diagnostic, code: 'P2015' }], [{ ...diagnostic, code: 'P2015' }, diagnostic]), false);
});
test('nonzero statuses alone never establish expected rejection or parity', () => {
  const fixture = { expected: { status: 1, codes: ['P2016'], analyzer: true } };
  assert.equal(assessCase(fixture, { kind: 'completed', full: { exitCode: 1 } }, { kind: 'completed', exitCode: 1 }).parity, 'FAIL');
  assert.equal(assessCase(fixture, null, null).parity, 'NOT RUN');
});

test('synthetic worker cleanup failures preserve completed observations separately', () => {
  const observed = { kind: 'completed', completion: { status: 1, diagnostics: ['source finding'] } };
  const result = terminateWorker({ terminate() { throw new Error('termination failed'); } }, observed);
  assert.deepEqual(result.completion, observed.completion);
  assert.equal(result.kind, 'completed'); assert.equal(result.cleanup, 'FAIL');
  assert.match(result.cleanupError, /termination failed/);
  assert.equal(terminateWorker({ terminate() {} }, observed).cleanup, 'PASS');
});

test('worker assertion observations cannot disappear behind a successful diagnostic result', () => {
  const event = (text) => JSON.stringify({ method: 'Log.entryAdded', params: { entry: { source: 'worker', level: 'error', text } } });
  const assertions = [event('Assertion failed'), event('Aborted(Assertion failed)'), event('RuntimeError: unreachable'), '{"truncated":', JSON.stringify({ method: 'Runtime.exceptionThrown' })];
  assert.deepEqual(runtimeConsoleFailures([...assertions, event('warning: unsupported syscall: __syscall_madvise')]), assertions);
});

test('runtime integrity requires manifest-owned loader and exact WASM; rejects changed bytes and links', () => {
  const directory = mkdtempSync(join(tmpdir(), 'ppphp-parity-artifact-'));
  try {
    mkdirSync(join(directory, 'asyncify'));
    const wasm = Buffer.from('synthetic artifact; never executed');
    writeFileSync(join(directory, 'asyncify/php_8_4.wasm'), wasm); writeFileSync(join(directory, 'asyncify/php_8_4.js'), '// synthetic');
    const files = ['asyncify/php_8_4.js', 'asyncify/php_8_4.wasm'].map((path) => ({ path, bytes: readFileSync(join(directory, path)).length, sha256: sha256(readFileSync(join(directory, path))) }));
    writeFileSync(join(directory, 'artifacts.json'), JSON.stringify({ profile: 'candidate', files }));
    assert.equal(verifyRuntime(directory, sha256(wasm)).wasmSha256, sha256(wasm));
    assert.throws(() => verifyRuntime(directory, '0'.repeat(64)), /identity/);
    writeFileSync(join(directory, 'asyncify/php_8_4.wasm'), 'changed');
    assert.throws(() => verifyRuntime(directory, sha256(wasm)), /integrity/);
    rmSync(join(directory, 'asyncify/php_8_4.wasm')); symlinkSync(join(directory, 'asyncify/php_8_4.js'), join(directory, 'asyncify/php_8_4.wasm'));
    assert.throws(() => verifyRuntime(directory, sha256(wasm)), /artifact file/);
    writeFileSync(join(directory, 'bad.json'), '{'); assert.throws(() => readBoundedJson(join(directory, 'bad.json')));
  } finally { rmSync(directory, { recursive: true, force: true }); }
});

test('test adapter uses real compiler mapping and rejects stale source/configuration; synthetic analyzer results', { timeout: 120000 }, () => {
  const directory = mkdtempSync(join(tmpdir(), 'ppphp-parity-adapter-'));
  const php = process.env.PHP_BINARY || 'php';
  const run = (args, cwd = directory) => runProcess(php, ['-d', 'memory_limit=256M', ...args], cwd);
  try {
    mkdirSync(join(directory, 'src')); mkdirSync(join(directory, 'stubs'));
    writeFileSync(join(directory, 'src/main.php'), file.source);
    writeFileSync(join(directory, 'ppphp.json'), JSON.stringify(CONFIGURATION)); writeFileSync(join(directory, 'composer.json'), '{}');
    writeFileSync(join(directory, 'request.json'), JSON.stringify({ version: 1, requestId: 'adapter', action: 'prepare', operation: 'check', selection: { path: null } }));
    const preparedProcess = run([join(ROOT, 'bin/ppphp'), 'browser:analysis', 'request.json']);
    assert.equal(preparedProcess.exitCode, 0, preparedProcess.stderr);
    const prepared = JSON.parse(preparedProcess.stdout); assert.equal(prepared.status, 'prepared');
    const path = prepared.phpStan.resultPath.replace('/result.json', '/') + prepared.continuation.workspaceManifest.find((item) => item.path.startsWith('selected/')).path;
    const json = JSON.stringify({ totals: { errors: 0, file_errors: 1 }, files: { [path]: { errors: 1, messages: [{ message: "Function value() should return int but returns string.", line: 3, ignorable: true, identifier: 'return.type' }] } }, errors: [] });
    const base = { command: prepared.phpStan.command, stdout: json, stderr: 'retained stderr', exitCode: 1, timedOut: false, outputLimitExceeded: false, executionFailure: null };
    const complete = (patch = {}) => {
      writeFileSync(join(directory, 'input.json'), JSON.stringify({ prepared, selection: null, process: { ...base, ...patch } }));
      // Intentionally run outside the project: the input file binds its frozen project root.
      return run([ADAPTER, ROOT, join(directory, 'input.json')], tmpdir());
    };
    const valid = complete(); assert.equal(valid.exitCode, 0, valid.stderr);
    const mapped = JSON.parse(valid.stdout); assert.equal(mapped.status, 1);
    assert.equal(mapped.diagnostics.diagnostics[0].code, 'P2016');
    assert.equal(mapped.diagnostics.diagnostics[0].location.file, 'src/main.php');
    assert.equal(mapped.diagnostics.diagnostics[0].location.range.start.line, 3);
    assert.equal(mapped.identities[0].identity, 'return.type'); assert.equal(mapped.backendMetadata.stderr, 'retained stderr');
    for (const [patch, code, cause] of [
      [{ timedOut: true }, 'P6005', 'time limit'], [{ outputLimitExceeded: true }, 'P6005', 'output limit'],
      [{ executionFailure: 'spawn failed' }, 'P6005', 'failed to complete'], [{ exitCode: 7 }, 'P6005', 'exit status 7'],
      [{ stdout: '{' }, 'P6006', 'malformed JSON'], [{ stdout: '{}' }, 'P6006', 'unexpected result format'],
      [{ stdout: '{"files":{"a":{"messages":[{}]}},"errors":[]}' }, 'P6006', 'invalid diagnostic'],
    ]) {
      const response = complete(patch); assert.equal(response.exitCode, 0, response.stderr);
      const error = JSON.parse(response.stdout).diagnostics.diagnostics[0];
      assert.equal(error.code, code); assert.ok(error.message.includes(cause)); assert.equal(error.location, null);
    }
    writeFileSync(join(directory, 'src/main.php'), file.source + '\n// changed');
    assert.equal(complete().exitCode, 70);
    writeFileSync(join(directory, 'src/main.php'), file.source);
    writeFileSync(join(directory, 'ppphp.json'), JSON.stringify({ ...CONFIGURATION, targetPhpVersion: '8.5' }));
    assert.equal(complete().exitCode, 70);
  } finally { rmSync(directory, { recursive: true, force: true }); }
});
