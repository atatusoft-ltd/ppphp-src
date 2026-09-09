// PHPStan's --debug mode is retained to avoid nested analyzer processes.
// Its known filename progress records are framing, not part of the JSON result.
const MAX_BYTES = 2097152;

export function readPhpStanDebugResult(stdout, expectedPaths) {
  if (typeof stdout !== 'string' || new TextEncoder().encode(stdout).length > MAX_BYTES) {
    throw new Error('Analyzer output is missing or exceeds its byte limit');
  }
  if (!Array.isArray(expectedPaths) || expectedPaths.length > 64
      || new Set(expectedPaths).size !== expectedPaths.length
      || expectedPaths.some((p) => typeof p !== 'string' || !p.startsWith('/')
        || /[\r\n\0\\]/.test(p) || p.split('/').some((part) => part === '..' || part === '.'))) {
    throw new Error('Invalid expected analyzer progress paths');
  }
  const remaining = new Set(expectedPaths);
  const debugPaths = [];
  let offset = 0;
  // Consume exactly one known progress line for each selected analysis file.
  // Do not search forward for a convenient JSON object or ignore arbitrary text.
  while (remaining.size) {
    const end = stdout.indexOf('\n', offset);
    if (end < 0) throw new Error('Incomplete analyzer progress output');
    const line = stdout.slice(offset, end).replace(/\r$/, '');
    if (!remaining.delete(line)) throw new Error('Unexpected or repeated analyzer progress output');
    debugPaths.push(line);
    offset = end + 1;
  }
  const jsonText = stdout.slice(offset);
  if (!jsonText.trim()) throw new Error('Analyzer returned no JSON result');
  const value = JSON.parse(jsonText); // Reject truncation, trailing noise and multiple JSON results.
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    throw new Error('Analyzer returned a non-object result');
  }
  return { jsonText, debugPaths };
}

export function resolvePreparedDebugPaths(payload) {
  const resultPath = payload?.phpStan?.resultPath;
  const manifest = payload?.continuation?.workspaceManifest;
  if (typeof resultPath !== 'string' || !resultPath.startsWith('/workspace/')
      || !resultPath.endsWith('/result.json') || /[\r\n\0\\]/.test(resultPath)
      || resultPath.split('/').some((part) => part === '..' || part === '.')
      || !Array.isArray(manifest)) {
    throw new Error('Invalid compiler-owned analysis workspace');
  }
  const root = resultPath.slice(0, -'/result.json'.length);
  const paths = manifest.filter((file) => typeof file?.path === 'string'
    && file.path.startsWith('selected/') && file.path.endsWith('.php')).map((file) => {
      if (/[\r\n\0\\]/.test(file.path) || file.path.split('/').some((part) => !part || part === '..' || part === '.')
          || !/^sha256:[a-f0-9]{64}$/.test(file.hash || '')) {
        throw new Error('Invalid selected analysis-file identity');
      }
      return `${root}/${file.path}`;
    });
  if (!paths.length || paths.length > 64 || new Set(paths).size !== paths.length) {
    throw new Error('Missing or duplicate selected analysis files');
  }
  return paths;
}
