import { readFileSync } from 'node:fs';
import { setTimeout as delay } from 'node:timers/promises';

// Chrome can write DevToolsActivePort before its HTTP listener is accepting
// connections. Poll readiness, not PHP execution, under one finite deadline.
export async function waitForDevTools(activePort, { timeoutMs = 10000, request = fetch, intervalMs = 50 } = {}) {
  if (!Number.isInteger(timeoutMs) || timeoutMs < 1 || timeoutMs > 30000) throw new Error('Invalid DevTools readiness deadline');
  if (!Number.isInteger(intervalMs) || intervalMs < 1 || intervalMs > 1000) throw new Error('Invalid DevTools polling interval');
  const deadline = Date.now() + timeoutMs;
  let detail = 'Port file is not ready';
  while (Date.now() < deadline) {
    try {
      const lines = readFileSync(activePort, 'utf8').trim().split('\n');
      const port = Number(lines[0]);
      if (!/^\d+$/.test(lines[0]) || !Number.isInteger(port) || port < 1 || port > 65535 || !lines[1]?.startsWith('/devtools/browser/')) {
        throw new Error('Incomplete or invalid DevTools port file');
      }
      const remaining = deadline - Date.now();
      if (remaining <= 0) break;
      const response = await request(`http://127.0.0.1:${port}/json/list`, { signal: AbortSignal.timeout(Math.min(1000, remaining)) });
      if (!response.ok) throw new Error(`DevTools returned HTTP ${response.status}`);
      const tabs = await response.json();
      if (!Array.isArray(tabs) || !tabs.some((tab) => tab.type === 'page' && typeof tab.webSocketDebuggerUrl === 'string')) throw new Error('DevTools page target is not ready');
      return tabs;
    } catch (error) { detail = `${error.message}${error.cause?.code ? ` (${error.cause.code})` : ''}`; }
    await delay(Math.min(intervalMs, Math.max(1, deadline - Date.now())));
  }
  throw new Error(`DevTools readiness timed out: ${detail}`);
}
