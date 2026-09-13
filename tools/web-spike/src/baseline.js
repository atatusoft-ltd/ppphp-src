import { probes, assess } from './baseline-probes.js';
import { fiberContractProbes } from './fiber-contract-probes.mjs';
import { nativeContractProbes, assessEntropyFailure } from './native-contract-probes.mjs';

const suite = new URL(location.href).searchParams.get('suite') || 'baseline';
if (!['baseline', 'fiber-contract', 'native-contract'].includes(suite)) throw new Error('Unknown diagnostic suite');
const selectedProbes = suite === 'fiber-contract' ? fiberContractProbes : suite === 'native-contract' ? nativeContractProbes : [...probes, { id: 'phpstan-standalone', compiler: true }];
const report = { userAgent: navigator.userAgent, suite, cases: [], done: false };
window.__bp0 = report;
const output = document.querySelector('#output');
const status = document.querySelector('#status');

function runCase(probe) {
  return new Promise((resolve) => {
    const worker = new Worker(new URL('./baseline-worker.js', import.meta.url), { type: 'module' });
    let finished = false;
    let computeStarted = false;
    let phase = 'initializing';
    let timer;
    const started = performance.now();
    function finish(result) {
      if (finished) return;
      finished = true;
      clearTimeout(timer);
      worker.terminate();
      resolve({ ...result, id: probe.id, phase, computeStarted, durationMs: Math.round(performance.now() - started) });
    }
    function deadline(ms) {
      clearTimeout(timer);
      timer = setTimeout(() => finish({ kind: probe.terminate && computeStarted ? 'terminated' : 'timeout' }), ms);
    }
    deadline(60000);
    worker.onmessage = ({ data }) => {
      if (data.id !== probe.id || finished) return;
      if (data.type === 'phase') {
        phase = data.phase;
        status.textContent = `${probe.id}: ${phase}`;
        if (phase === 'executing') {
          computeStarted = true;
          deadline(probe.terminate ? 250 : probe.id === 'phpstan-standalone' ? 90000 : 15000);
        }
      } else if (data.type === 'result') finish(data.result);
    };
    worker.onerror = (event) => finish({ kind: 'trap', error: String(event.message).slice(0, 8192) });
    worker.onmessageerror = () => finish({ kind: 'transport-error', error: 'Unreadable worker message' });
    worker.postMessage(probe);
  });
}

// Serial, fresh workers: a crashing Fiber probe cannot suppress later controls.
for (const probe of selectedProbes) {
  const result = await runCase(probe);
  result.semantics = probe.entropyDenied
    ? assessEntropyFailure(result, probe.entropyTrap) ? 'PASS' : 'FAIL'
    : probe.compiler
    ? result.kind === 'completed' && result.validPhpStanJson === true && result.exitCode === 0 ? 'PASS' : 'FAIL'
    : assess(probe, result);
  report.cases.push(result);
  output.textContent = JSON.stringify(report, null, 2);
}
report.done = true;
report.runtimeSemantics = report.cases.every((item) => item.semantics === 'PASS') ? 'PASS' : 'FAIL';
status.textContent = `Observation complete; runtime semantics ${report.runtimeSemantics}`;
output.textContent = JSON.stringify(report, null, 2);
