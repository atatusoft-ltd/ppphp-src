import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, writeFileSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { waitForDevTools } from './browser-devtools.mjs';

const root = mkdtempSync(join(tmpdir(), 'ppphp-devtools-tests-'));
const file = join(root, 'DevToolsActivePort');
const tabs = [{ type: 'page', webSocketDebuggerUrl: 'ws://127.0.0.1:12345/devtools/page/test' }];
const response = (body) => ({ ok: true, json: async () => body });
test.after(() => rmSync(root, { recursive: true, force: true }));

test('retries an early refused connection before the debug listener is ready', async () => {
  writeFileSync(file, '12345\n/devtools/browser/test\n');
  let attempts = 0;
  const result = await waitForDevTools(file, { intervalMs: 1, request: async (url, options) => {
    assert.equal(url, 'http://127.0.0.1:12345/json/list');
    assert.ok(options.signal instanceof AbortSignal);
    if (++attempts < 3) throw new TypeError('fetch failed', { cause: { code: 'ECONNREFUSED' } });
    return response(tabs);
  } });
  assert.deepEqual(result, tabs);
  assert.equal(attempts, 3);
});
test('waits for a page target rather than treating an empty target list as ready', async () => {
  let attempts = 0;
  const result = await waitForDevTools(file, { intervalMs: 1, request: async () => response(++attempts === 1 ? [] : tabs) });
  assert.deepEqual(result, tabs);
  assert.equal(attempts, 2);
});
test('does not fetch from a partial port file and rereads it when completed', async () => {
  writeFileSync(file, '123');
  const timer = setTimeout(() => writeFileSync(file, '12345\n/devtools/browser/test\n'), 10);
  let attempts = 0;
  try {
    await waitForDevTools(file, { intervalMs: 1, request: async () => { attempts++; return response(tabs); } });
    assert.equal(attempts, 1);
  } finally { clearTimeout(timer); }
});
test('a permanently unavailable listener fails with its cause and a bounded deadline', async () => {
  const start = Date.now();
  await assert.rejects(waitForDevTools(file, { timeoutMs: 30, intervalMs: 1, request: async () => { throw new TypeError('fetch failed', { cause: { code: 'ECONNREFUSED' } }); } }), /ECONNREFUSED/);
  assert.ok(Date.now() - start < 1000);
});
test('rejects invalid ports without contacting another endpoint', async () => {
  writeFileSync(file, '0\n/devtools/browser/test\n');
  let requests = 0;
  await assert.rejects(waitForDevTools(file, { timeoutMs: 20, intervalMs: 1, request: async () => { requests++; return response(tabs); } }), /invalid DevTools port/);
  assert.equal(requests, 0);
});
test('rejects invalid timeout and polling options', async () => {
  await assert.rejects(waitForDevTools(file, { timeoutMs: Infinity }), /deadline/);
  await assert.rejects(waitForDevTools(file, { intervalMs: 0 }), /interval/);
});
