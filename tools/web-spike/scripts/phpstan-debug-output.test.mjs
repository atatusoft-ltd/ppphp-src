import test from 'node:test';
import assert from 'node:assert/strict';
import { readPhpStanDebugResult, resolvePreparedDebugPaths } from '../src/phpstan-debug-output.mjs';

const path = '/workspace/.cache/analysis/selected/fixture/main.php';
const clean = '{"totals":{"errors":0,"file_errors":0},"files":{},"errors":[]}';

test('separates exactly known debug progress from complete JSON', () => {
  for (const separator of ['\n', '\r\n']) {
    assert.deepEqual(readPhpStanDebugResult(path + separator + clean, [path]), { jsonText: clean, debugPaths: [path] });
  }
});
test('accepts pure JSON only when no progress records are expected', () => {
  assert.equal(readPhpStanDebugResult(clean, []).jsonText, clean);
  assert.throws(() => readPhpStanDebugResult(clean, [path]));
});
test('accepts each expected path once without depending on traversal order', () => {
  const other = '/workspace/.cache/analysis/selected/fixture/other.php';
  assert.deepEqual(readPhpStanDebugResult(other + '\n' + path + '\n' + clean, [path, other]).debugPaths, [other, path]);
  assert.throws(() => readPhpStanDebugResult(path + '\n' + path + '\n' + clean, [path, other]));
});
test('never recovers truncated or contaminated JSON', () => {
  for (const body of ['', '{', 'null', '[]', 'false', 'warning\n' + clean, clean + '\nwarning', clean + clean]) {
    assert.throws(() => readPhpStanDebugResult(path + '\n' + body, [path]));
  }
});
test('rejects unknown progress paths and hidden backend warnings', () => {
  for (const prefix of ['Warning: analyzer failed', '/workspace/other.php', '', path + '\nwarning']) {
    assert.throws(() => readPhpStanDebugResult(prefix + '\n' + clean, [path]));
  }
});
test('framing preserves diagnostic failures rather than declaring them clean', () => {
  const failure = '{"totals":{"errors":1,"file_errors":0},"files":{},"errors":["failed"]}';
  assert.equal(readPhpStanDebugResult(path + '\n' + failure, [path]).jsonText, failure);
});
test('rejects oversized output and invalid expected progress identities', () => {
  assert.throws(() => readPhpStanDebugResult('x'.repeat(2097153), []));
  assert.throws(() => readPhpStanDebugResult('é'.repeat(1048577), []));
  for (const paths of [null, [path, path], ['../bad.php'], ['/workspace/../bad.php'], ['/workspace/./bad.php'], ['/workspace/a\n.php'], [42]]) {
    assert.throws(() => readPhpStanDebugResult(clean, paths));
  }
});
function prepared() {
  return {
    phpStan: { resultPath: '/workspace/.cache/analysis/result.json' },
    continuation: { workspaceManifest: [
      { path: 'phpstan.neon', hash: 'a'.repeat(64) },
      { path: 'context/library.php', hash: 'b'.repeat(64) },
      { path: 'selected/fixture/main.php', hash: 'c'.repeat(64) },
    ] },
  };
}
test('progress identities come only from selected compiler-manifest files', () => {
  assert.deepEqual(resolvePreparedDebugPaths(prepared()), [path]);
});
test('rejects missing, traversing, duplicate and unhashed selected files', () => {
  for (const change of [
    (p) => { p.phpStan.resultPath = '/outside/result.json'; },
    (p) => { p.phpStan.resultPath = '/workspace/../outside/result.json'; },
    (p) => { p.continuation.workspaceManifest = []; },
    (p) => { p.continuation.workspaceManifest[2].path = 'selected/../bad.php'; },
    (p) => { p.continuation.workspaceManifest[2].hash = 'bad'; },
    (p) => { p.continuation.workspaceManifest.push(p.continuation.workspaceManifest[2]); },
  ]) {
    const payload = prepared(); change(payload);
    assert.throws(() => resolvePreparedDebugPaths(payload));
  }
});
