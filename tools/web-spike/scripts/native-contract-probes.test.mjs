import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { nativeContractProbes, assessNativeContract } from '../src/native-contract-probes.mjs';

test('native-library fixtures have unique identities and valid PHP except the explicit lint control', () => {
  assert.equal(new Set(nativeContractProbes.map(probe => probe.id)).size, nativeContractProbes.length);
  for (const probe of nativeContractProbes) {
    const result = spawnSync(process.env.PHP_BINARY || 'php', ['-l'], { input: probe.code, encoding: 'utf8', timeout: 10000 });
    assert.equal(result.status, probe.id === 'native-lint-invalid' ? 255 : 0, probe.id + ': ' + result.stderr);
  }
});

const accepted = () => ({ suite: 'native-contract', done: true, cases: nativeContractProbes.map(probe => ({
  id: probe.id, computeStarted: true, semantics: 'PASS', kind: 'completed',
  stdout: 'ok', stderr: '', exitCode: probe.exitCode, sideEffect: false,
  ...(probe.entropyDenied ? { entropyReads: 1, exitCode: 0, stdout: 'entropy-unavailable' } : {}),
})) });

test('native qualification rejects missing execution, failed processes and fabricated entropy evidence', () => {
  assert.equal(assessNativeContract(accepted()), true);
  for (const change of [
    data => { data.cases.pop(); },
    data => { data.cases[0].computeStarted = false; },
    data => { data.cases[1].exitCode = 1; },
    data => { data.cases[2].stderr = 'warning'; },
    data => { data.cases.find(item => item.id === 'native-entropy-failure').entropyReads = 0; },
    data => { data.cases.find(item => item.id === 'native-entropy-failure').stdout = 'random data'; },
    data => { data.cases.find(item => item.id === 'native-entropy-failure').kind = 'trap'; },
    data => { data.cases.find(item => item.id === 'native-lint-valid').sideEffect = true; },
  ]) {
    const data = accepted(); change(data); assert.equal(assessNativeContract(data), false);
  }
});
