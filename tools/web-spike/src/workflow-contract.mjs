// Fixed host capabilities for protocol 3. No visitor command or filesystem API.
export const WORKFLOW_LIMITS = Object.freeze({ files: 1024, bytes: 16777216, processBytes: 2097152, phaseMs: 90000, operationMs: 360000 });
export const hash = async (data) => 'sha256:' + [...new Uint8Array(await crypto.subtle.digest('SHA-256', typeof data === 'string' ? new TextEncoder().encode(data) : data))].map((v) => v.toString(16).padStart(2, '0')).join('');
export const canonical = (value) => JSON.stringify(sort(value));
function sort(value) {
  if (Array.isArray(value)) return value.map(sort);
  if (value && typeof value === 'object') return Object.fromEntries(Object.keys(value).sort().map((key) => [key, sort(value[key])]));
  return value;
}
export function relativePath(path) {
  if (typeof path !== 'string' || !path || path.length > 512 || /[\x00-\x1f\\:]/.test(path) || path.split('/').some((p) => ['', '.', '..'].includes(p))) throw new Error('Unsafe workflow path');
  return path;
}
export function validateWorkspace(files) {
  if (!Array.isArray(files) || files.length > WORKFLOW_LIMITS.files) throw new Error('Workspace file limit');
  let bytes = 0; const paths = new Set();
  for (const file of files) {
    relativePath(file.path);
    if (paths.has(file.path) || !Number.isInteger(file.mode) || file.mode < 0 || file.mode > 511 || !(file.bytes instanceof Uint8Array) || !['file', 'directory'].includes(file.kind)) throw new Error('Invalid workspace record');
    paths.add(file.path); bytes += file.bytes.byteLength;
  }
  if (bytes > WORKFLOW_LIMITS.bytes) throw new Error('Workspace byte limit');
  return files;
}
export async function validateInvocation(invocation) {
  const { identity, ...payload } = invocation;
  if (identity !== await hash(canonical(payload)) || invocation.workingDirectory !== '/workspace' || !/^sha256:[a-f0-9]{64}$/.test(invocation.binding)) throw new Error('Invalid compiler invocation identity');
  const command = invocation.command;
  if (!Array.isArray(command) || command.some((s) => typeof s !== 'string')) throw new Error('Malformed compiler invocation');
  if (invocation.kind === 'phpstan') {
    if (command.length !== 8 || command[0] !== 'php' || command[1] !== '/opt/ppphp/vendor/phpstan/phpstan/phpstan' || command[2] !== 'analyse'
      || command[3] !== '--configuration=/workspace/.cache/analysis/phpstan.neon' || command[4] !== '--error-format=json'
      || command[5] !== '--no-progress' || command[6] !== '--memory-limit=256M' || command[7] !== '--debug'
      || !Array.isArray(invocation.progressPaths) || !invocation.progressPaths.length || invocation.progressPaths.length > 64) throw new Error('Unapproved analyzer invocation');
    for (const path of invocation.progressPaths) {
      if (!path.startsWith('/workspace/.cache/analysis/selected/')) throw new Error('Unapproved analysis path');
      relativePath(path.slice('/workspace/'.length));
    }
  } else if (invocation.kind === 'php-lint') {
    relativePath(invocation.path);
    if (canonical(command) !== canonical(['php', '-n', '-l', '/workspace/.ppphp-browser/pending/' + invocation.path]) || !/^sha256:[a-f0-9]{64}$/.test(invocation.hash)) throw new Error('Unapproved lint invocation');
  } else throw new Error('Unknown compiler operation');
  return invocation;
}
export function processRecord(invocation, observed) {
  return { invocation: invocation.identity, stdout: observed.stdout, stderr: observed.stderr, exitCode: observed.exitCode,
    complete: observed.kind === 'completed', timedOut: observed.kind === 'timeout', outputLimitExceeded: observed.kind === 'overflow', executionFailure: observed.error || null };
}
