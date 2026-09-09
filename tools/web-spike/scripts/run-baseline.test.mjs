import test from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, mkdtempSync, rmSync, symlinkSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { probes, assess, validCleanPhpStanResult } from '../src/baseline-probes.js';
import { nativeProbe, launchChrome, selectChromeBinary, closeFailedLaunch, createOutput, sha256, collectObservation } from './run-baseline.mjs';

for (const probe of probes) test(`native reference: ${probe.id}`, () => {
  const actual = nativeProbe(probe);
  assert.equal(actual.semantics, 'PASS', JSON.stringify(actual));
  assert.equal(actual.sourceSha256, sha256(probe.code));
});

test('empty output is a completed success, not pending work', () => {
  const probe = probes.find((item) => item.id === 'empty-output');
  assert.equal(assess(probe, { kind: 'completed', stdout: '', stderr: '', exitCode: 0 }), 'PASS');
  assert.equal(assess(probe, { kind: 'trap', stdout: '' }), 'FAIL');
});
test('wrong output, stderr, exit code and missing execution cannot pass', () => {
  const probe = probes[0];
  const result = { kind: 'completed', stdout: probe.stdout, stderr: '', exitCode: 0 };
  for (const patch of [{ stdout: '{}' }, { stderr: 'error' }, { exitCode: 1 }, { kind: 'timeout' }]) {
    assert.equal(assess(probe, { ...result, ...patch }), 'FAIL');
  }
  assert.equal(assess(probe, { kind: 'not-run' }), 'NOT RUN');
});
test('timeout before PHP execution is not runaway containment evidence', () => {
  const probe = probes.find((item) => item.id === 'runaway');
  assert.equal(assess(probe, { kind: 'terminated', computeStarted: false }), 'FAIL');
});
test('lint needs compiler diagnostics and no executed side effects', () => {
  const probe = probes.find((item) => item.id === 'lint-valid');
  assert.equal(assess(probe, { kind: 'completed', exitCode: 0, stdout: 'No syntax errors detected', stderr: '', sideEffect: true }), 'FAIL');
  assert.equal(assess(probe, { kind: 'completed', exitCode: 0, stdout: '', stderr: '', sideEffect: false }), 'FAIL');
});
test('report creation rejects workspaces, existing files and symlinks', () => {
  assert.throws(() => createOutput(fileURLToPath(new URL('./bp0-report', import.meta.url))), /temporary/);
  const path = mkdtempSync(join(tmpdir(), 'ppphp-output-test-'));
  try {
    assert.throws(() => createOutput(path));
    symlinkSync(path, join(path, 'link'));
    assert.throws(() => createOutput(join(path, 'link')));
    const output = createOutput(join(path, 'new'));
    assert.ok(existsSync(output));
  } finally { rmSync(path, { recursive: true, force: true }); }
});
test('real Chromium control: DevTools connection and JavaScript evaluation', { timeout: 25000 }, async () => {
  const browser = await launchChrome();
  try {
    const version = await browser.send('Browser.getVersion');
    assert.match(version.product, /Chrome/);
    assert.equal(await browser.evaluate('6 * 7'), 42);
  } finally { await browser.close(); }
});

test('browser selection prefers direct Chrome, retains Chromium fallback and honors an explicit binary', () => {
  const both = (path) => ['/usr/bin/google-chrome', '/usr/bin/chromium'].includes(path);
  assert.equal(selectChromeBinary('', both), '/usr/bin/google-chrome');
  assert.equal(selectChromeBinary('', (path) => path === '/usr/bin/chromium'), '/usr/bin/chromium');
  assert.equal(selectChromeBinary('/explicit/browser', both), '/explicit/browser');
  assert.throws(() => selectChromeBinary('', () => false), /No Chrome/);
});

test('failed browser startup retains its cause when process cleanup also fails', async () => {
  const startup = new Error('Selected browser did not publish its DevTools port');
  const cleanup = new Error('Browser did not close');
  await assert.rejects(closeFailedLaunch(startup, async () => { throw cleanup; }), (error) => {
    assert.deepEqual(error.errors, [startup, cleanup]);
    assert.match(error.message, /DevTools port/); assert.match(error.message, /cleanup failed/); return true;
  });
  await assert.rejects(closeFailedLaunch(startup, async () => {}), (error) => error === startup);
});

test('clean PHPStan JSON accepts empty object and empty array file maps', () => {
  for (const files of [{}, []]) assert.equal(validCleanPhpStanResult(JSON.stringify({ totals: { errors: 0, file_errors: 0 }, errors: [], files })), true);
});
test('missing, partial and failing analyzer results are not clean', () => {
  for (const text of ['', '{', '{}', 'null', '[]', JSON.stringify({ totals: { errors: 1, file_errors: 0 }, errors: ['failed'], files: [] }), JSON.stringify({ totals: { errors: 0, file_errors: 0 }, errors: [], files: { bad: {} } })]) {
    assert.equal(validCleanPhpStanResult(text), false);
  }
});


test('profile-cleanup failure preserves the completed browser observation', async () => {
  const observation = { kind: 'observed', data: { cases: [{ id: 'fiber-return', semantics: 'FAIL' }], done: true } };
  const browser = { events: [], close: async () => { throw new Error('ENOTEMPTY'); } };
  const result = await collectObservation(browser, async () => observation);
  assert.equal(result.kind, 'observed');
  assert.deepEqual(result.data, observation.data);
  assert.match(result.cleanupError, /ENOTEMPTY/);
});
test('observation and cleanup failures remain independently visible', async () => {
  const browser = { events: ['partial evidence'], close: async () => { throw new Error('cleanup failed'); } };
  const result = await collectObservation(browser, async () => { throw new Error('navigation failed'); });
  assert.equal(result.kind, 'observation-error');
  assert.match(result.error, /navigation failed/);
  assert.match(result.cleanupError, /cleanup failed/);
  assert.deepEqual(result.events, ['partial evidence']);
});
