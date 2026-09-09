import { readPhpStanDebugResult } from './phpstan-debug-output.mjs';

export const CONFIGURATION = Object.freeze({ source: ['src'], output: 'build', cache: '.cache', targetPhpVersion: '8.4', stubs: ['stubs'], exclude: ['build', '.cache'] });
export const LIMITS = Object.freeze({ cases: 128, files: 12, sourceBytes: 65536, corpusBytes: 2097152, outputBytes: 2097152 });
const bytes = (value) => new TextEncoder().encode(value).length;
export function sourcePath(path) {
  if (typeof path !== 'string' || path.length > 512 || !/^[\p{L}\p{N}_ -]+(?:\/[\p{L}\p{N}_ -]+)*\.(php|ppphp)$/u.test(path)) throw new Error('Invalid source path');
  return path;
}
export function validateCases(cases) {
  if (!Array.isArray(cases) || !cases.length || cases.length > LIMITS.cases) throw new Error('Invalid case count');
  const ids = new Set();
  for (const item of cases) {
    if (typeof item.id !== 'string' || !/^[a-z0-9/-]{1,128}$/.test(item.id) || ids.has(item.id)) throw new Error('Invalid or duplicate case ID');
    ids.add(item.id);
    if (!Array.isArray(item.files) || item.files.length > LIMITS.files) throw new Error('Invalid file count');
    const paths = new Set(); let total = 0;
    for (const file of item.files) {
      sourcePath(file.path);
      if (paths.has(file.path) || typeof file.source !== 'string' || !/^[a-f0-9]{64}$/.test(file.sha256 || '')) throw new Error('Invalid file identity');
      paths.add(file.path); total += bytes(file.source);
    }
    if (total > LIMITS.sourceBytes) throw new Error('Project source exceeds limit');
    if (item.configuration && JSON.stringify(item.configuration) !== JSON.stringify(CONFIGURATION)) throw new Error('Unsupported qualification configuration');
    if (item.fault !== undefined && item.fault !== 'missing-configuration') throw new Error('Unknown synthetic fault');
    if (item.directories !== undefined && (!Array.isArray(item.directories) || item.directories.length > 12
      || item.directories.some((p) => typeof p !== 'string' || !/^src(?:\/[A-Za-z0-9_-]+)+$/.test(p)))) throw new Error('Unsafe fixture directory');
    if (item.selection !== undefined && item.selection !== null && (typeof item.selection !== 'string'
      || !/^src(?:\/[\p{L}\p{N}_ .-]+)*$/u.test(item.selection) || item.selection.split('/').some((p) => p === '.' || p === '..'))) throw new Error('Invalid selection');
  }
  return cases;
}

export async function verifySourceHashes(cases, digest) {
  validateCases(cases);
  for (const item of cases) for (const file of item.files) {
    if (await digest(file.source) !== file.sha256) throw new Error(`Source hash mismatch: ${item.id}/${file.path}`);
  }
}

export function validateCorpus(value) {
  if (value?.format !== 'ppphp.browser-corpus' || value.version !== 1
    || value.provenance?.repository !== 'atatusoft-ltd/ppphp-website'
    || !/^[a-f0-9]{40}$/.test(value.provenance.contentRevision || '')
    || !Array.isArray(value.provenance.sourceFiles) || !value.provenance.sourceFiles.length || value.provenance.sourceFiles.length > 16) throw new Error('Invalid corpus provenance or version');
  const paths = value.provenance.sourceFiles.map((file) => {
    if (typeof file.path !== 'string' || !/^src\/[A-Za-z0-9_./-]+$/.test(file.path) || file.path.split('/').some((p) => !p || p === '.' || p === '..')
      || !/^[a-f0-9]{64}$/.test(file.sha256 || '')) throw new Error('Invalid provenance source');
    return file.path;
  });
  if (new Set(paths).size !== paths.length || JSON.stringify(paths) !== JSON.stringify([...paths].sort())) throw new Error('Unsorted or duplicate provenance');
  validateCases(value.cases);
  for (const item of value.cases) {
    if (!['playground', 'learn'].includes(item.page) || !['example', 'starter', 'solution'].includes(item.variant)
      || !/^[a-z0-9-]+$/.test(item.exampleId || '') || item.id !== `${item.page}/${item.exampleId}/${item.variant}`
      || (item.page === 'playground') !== (item.variant === 'example')
      || item.route !== (item.page === 'playground' ? '/playground' : `/learn/${item.exampleId}`)
      || typeof item.label !== 'string' || !item.label || !item.files.length
      || !['pass', 'diagnostics', 'unknown'].includes(item.intent?.check) || typeof item.intent.basis !== 'string' || !item.intent.basis
      || typeof item.entryBasis !== 'string' || !item.entryBasis
      || !Array.isArray(item.requiredActions) || !item.requiredActions.length || new Set(item.requiredActions).size !== item.requiredActions.length
      || item.requiredActions.some((action) => !['check', 'build', 'run'].includes(action))
      || !Object.hasOwn(item, 'expected') || (item.expected !== null && (typeof item.expected !== 'object' || Array.isArray(item.expected)))) throw new Error('Invalid teaching case contract');
    if (item.entry !== null && !item.files.some((file) => file.path.replace(/\.ppphp$/, '.php') === item.entry)) throw new Error('Entry is not a source-derived artifact');
  }
  return value;
}

export function frameProcess(observed, paths, command) {
  const process = { command, stdout: observed.stdout || '', stderr: observed.stderr || '', exitCode: observed.exitCode ?? -1,
    timedOut: observed.kind === 'timeout', outputLimitExceeded: observed.kind === 'overflow',
    executionFailure: ['completed', 'timeout', 'overflow'].includes(observed.kind) ? null : observed.error || observed.kind };
  if (observed.kind !== 'completed') return { process, framing: { status: 'NOT RUN', reason: observed.kind } };
  try {
    const framed = readPhpStanDebugResult(observed.stdout, paths);
    return { process: { ...process, stdout: framed.jsonText }, framing: { status: 'PASS', debugPaths: framed.debugPaths, result: JSON.parse(framed.jsonText) } };
  } catch (error) {
    // Preserve the original stream; existing PHP completion classifies statuses and malformed JSON.
    return { process, framing: { status: 'FAIL', reason: error.message } };
  }
}

export function compareDiagnostics(left, right) {
  // Public diagnostics use original relative paths; no message, range, identifier or order normalization.
  return JSON.stringify(left) === JSON.stringify(right);
}

export function terminateWorker(worker, observation) {
  try { worker.terminate(); return { ...observation, cleanup: 'PASS' }; }
  catch (error) { return { ...observation, cleanup: 'FAIL', cleanupError: String(error) }; }
}

export function runtimeConsoleFailures(events) {
  return events.filter((event) => {
    let message;
    try { message = JSON.parse(event); } catch { return true; }
    if (message.method === 'Runtime.exceptionThrown') return true;
    const entry = message.params?.entry;
    return entry?.source === 'worker' && entry.level === 'error'
      && /^(Assertion failed|Aborted\(|RuntimeError:)/.test(entry.text);
  });
}

export function normalizeAnalyzerFiles(observation) {
  const value = observation.framing?.result;
  if (!value) return null;
  const paths = new Map(observation.completion?.analysisPaths?.map((item) => [item.path, item.source]));
  if (!value.files || typeof value.files !== 'object') return value;
  return { ...value, files: Object.entries(value.files).map(([path, data]) => {
    if (!paths.has(path)) throw new Error('Analyzer reported an unselected file');
    return [paths.get(path), data];
  }) };
}

export function assessCase(fixture, native, browser) {
  if (!native || !browser) return { parity: 'NOT RUN', intent: 'NOT RUN' };
  const a = native.completion, b = browser.completion;
  const equivalent = native.kind === 'completed' && browser.kind === 'completed' && a && b
    && a.status === b.status && compareDiagnostics(a.diagnostics, b.diagnostics)
    && compareDiagnostics(a.identities, b.identities)
    && compareDiagnostics(native.prepared?.diagnostics, browser.prepared?.diagnostics)
    && compareDiagnostics(native.prepared?.continuation?.sources, browser.prepared?.continuation?.sources)
    && compareDiagnostics(a.analysisFiles, b.analysisFiles)
    && Boolean(native.analyzer) === Boolean(browser.analyzer)
    && (!native.analyzer || (native.analyzer.kind === browser.analyzer.kind && native.analyzer.exitCode === browser.analyzer.exitCode
      && native.framing.status === browser.framing.status && compareDiagnostics(a.mapped, b.mapped)
      && compareDiagnostics(normalizeAnalyzerFiles(native), normalizeAnalyzerFiles(browser))));
  const fullMatches = fixture.fault ? null : native.full?.kind === 'completed' && native.full.exitCode === a?.status && compareDiagnostics(native.full.diagnostics, a?.diagnostics);
  const codes = a?.diagnostics?.diagnostics.map((d) => d.code);
  const expected = fixture.expected;
  const intent = expected ? (a?.status === expected.status && compareDiagnostics(codes, expected.codes)
    && Boolean(native.analyzer) === expected.analyzer ? 'PASS' : 'FAIL')
    : fixture.intent?.check === 'unknown' ? 'NOT RUN'
      : (fixture.intent?.check === 'pass' ? a?.status === 0 : a?.status === 1 && a?.diagnostics?.summary.errors > 0) ? 'PASS' : 'FAIL';
  const backendError = b?.identities?.some((d) => d.origin === 'subprocess');
  const framingOk = fixture.fault || !browser.analyzer || browser.framing?.status === 'PASS';
  return { parity: equivalent && (fixture.fault || fullMatches) && framingOk && (fixture.fault || !backendError) ? 'PASS' : 'FAIL', intent,
    fullMatches, codes, analyzerRan: Boolean(browser.analyzer), backendError: Boolean(backendError) };
}
