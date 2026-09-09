import test from 'node:test';
import assert from 'node:assert/strict';
import { fiberContractProbes } from '../src/fiber-contract-probes.mjs';
import { assessFiberContract, FIBER_CONTRACT_ORIGIN } from './run-fiber-contract.mjs';
const complete = () => ({ suite: 'fiber-contract', done: true, cases: fiberContractProbes.map((p) => ({
  id: p.id, kind: 'completed', computeStarted: true, stdout: p.stdout, stderr: '', exitCode: p.exitCode, semantics: 'PASS',
})) });
test('accepts only the complete observed Fiber contract', () => {
  assert.equal(assessFiberContract(complete()), true);
});
test('rejects missing, duplicate, wrong-suite and incomplete results', () => {
  const missing = complete(); missing.cases.pop();
  const duplicate = complete(); duplicate.cases[1] = duplicate.cases[0];
  for (const data of [null, {}, { ...complete(), done: false }, { ...complete(), suite: 'baseline' }, missing, duplicate]) {
    assert.equal(assessFiberContract(data), false);
  }
});
test('PASS labels cannot conceal traps, wrong output, exit status or stderr', () => {
  for (const patch of [{ kind: 'trap' }, { kind: 'timeout' }, { computeStarted: false }, { stdout: '' }, { stderr: 'warning' }, { exitCode: 1 }, { semantics: 'FAIL' }]) {
    const data = complete(); Object.assign(data.cases[0], patch);
    assert.equal(assessFiberContract(data), false);
  }
});

test('preview uses the artifact verifier loopback origin', () => {
  const origin = new URL(FIBER_CONTRACT_ORIGIN);
  assert.equal(origin.origin, 'http://127.0.0.1:4173');
  assert.equal(origin.hostname, '127.0.0.1');
  assert.equal(Number(origin.port), 4173);
  assert.equal(new URL('/baseline.html?suite=fiber-contract', origin).href,
    'http://127.0.0.1:4173/baseline.html?suite=fiber-contract');
});
