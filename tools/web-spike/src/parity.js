// Local test page. Each check owns one disposable worker; there is no queued execution API.
import { terminateWorker } from './parity-contract.mjs';
let active = false;
window.__bp3 = { ready: true, result: null, phase: 'idle' };
window.runParityCase = (fixture) => {
  if (active) throw new Error('A qualification case is already active');
  active = true; window.__bp3.result = null;
  const worker = new Worker(new URL('./parity-worker.js', import.meta.url), { type: 'module' });
  let finished = false; let timer;
  let partial = {};
  const started = performance.now();
  const finish = (result) => {
    if (finished) return;
    finished = true; clearTimeout(timer); clearTimeout(absoluteTimer);
    window.__bp3.result = terminateWorker(worker, { ...partial, ...result, durationMs: Math.round(performance.now() - started) });
    // A failed termination prevents further work in the same page.
    active = window.__bp3.result.cleanup !== 'PASS';
  };
  const deadline = () => { clearTimeout(timer); timer = setTimeout(() => finish({ id: fixture.id, kind: 'timeout', phase: window.__bp3.phase }), 90000); };
  const absoluteTimer = setTimeout(() => finish({ id: fixture.id, kind: 'timeout', phase: window.__bp3.phase }), 240000);
  deadline();
  worker.onmessage = ({ data }) => {
    if (data.id !== fixture.id || finished) return;
    if (data.type === 'observation') partial = data.result;
    if (data.type === 'phase') { window.__bp3.phase = data.name; document.querySelector('#status').textContent = `${fixture.id}: ${data.name}`; deadline(); }
    if (data.type === 'result') finish(data.result);
  };
  worker.onerror = (event) => finish({ id: fixture.id, kind: 'failure', error: String(event.message).slice(0, 8192) });
  worker.onmessageerror = () => finish({ id: fixture.id, kind: 'transport-error' });
  worker.postMessage(fixture);
};
