import test from 'node:test';
import assert from 'node:assert/strict';
import { assessProfile } from './verify-built.mjs';
const ids = ['plain-json', 'fiber-return', 'phpstan-standalone'];
const baseline = [
  { id: 'plain-json', semantics: 'PASS', kind: 'completed' },
  { id: 'fiber-return', semantics: 'FAIL', kind: 'trap', error: 'Aborted in _getcontext' },
  { id: 'phpstan-standalone', semantics: 'FAIL', kind: 'trap', error: 'unreachable in _getcontext' },
];
test('baseline capture is not candidate success', () => {
  assert.equal(assessProfile(baseline, 'baseline', ids), true);
  assert.equal(assessProfile(baseline, 'candidate', ids), false);
});
test('candidate needs every semantic assertion to pass', () => {
  const success = ids.map((id) => ({ id, semantics: 'PASS', kind: 'completed' }));
  assert.equal(assessProfile(success, 'candidate', ids), true);
  assert.equal(assessProfile(success, 'baseline', ids), false);
  for (const semantics of ['FAIL', 'NOT RUN', undefined]) {
    assert.equal(assessProfile(success.map((p, i) => i ? p : { ...p, semantics }), 'candidate', ids), false);
  }
});
test('wrong trap, incomplete observations and duplicate cases cannot qualify', () => {
  for (const bad of [baseline.slice(0, 2), [...baseline, baseline[0]], [baseline[0], baseline[0], baseline[2]], baseline.map((p, i) => i ? { ...p, error: 'some other failure' } : p)]) {
    assert.equal(assessProfile(bad, 'baseline', ids), false);
  }
});
test('unknown profile and invalid reference case set are rejected', () => {
  assert.throws(() => assessProfile(baseline, 'production', ids));
  for (const invalid of [[], ['same', 'same'], null]) assert.throws(() => assessProfile(baseline, 'candidate', invalid));
});
