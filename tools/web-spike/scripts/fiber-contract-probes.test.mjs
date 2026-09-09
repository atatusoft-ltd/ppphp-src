import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fiberContractProbes } from '../src/fiber-contract-probes.mjs';

test('qualification identities are unique and fixtures are finite', () => {
  assert.equal(new Set(fiberContractProbes.map((p) => p.id)).size, fiberContractProbes.length);
  for (const probe of fiberContractProbes) {
    assert.match(probe.id, /^fiber-[a-z-]+$/);
    assert.ok(probe.code.startsWith('<?php\n'));
    assert.ok(Buffer.byteLength(probe.code) < 4096);
    assert.ok(Buffer.byteLength(probe.stdout) < 1024);
    assert.equal(probe.exitCode, 0);
  }
});
for (const probe of fiberContractProbes) test(`native Fiber contract: ${probe.id}`, () => {
  const directory = mkdtempSync(join(tmpdir(), 'ppphp-fiber-contract-'));
  try {
    const path = join(directory, 'probe.php');
    writeFileSync(path, probe.code);
    const result = spawnSync(process.env.PHP_BINARY || 'php', [
      '-d', 'memory_limit=128M', '-d', 'display_errors=stderr', '-d', 'log_errors=0', path,
    ], {
      cwd: directory, encoding: 'utf8', timeout: 10000,
      killSignal: 'SIGKILL', maxBuffer: 131072,
      env: { PATH: process.env.PATH || '', HOME: directory, TMPDIR: directory, LANG: 'C.UTF-8' },
    });
    assert.equal(result.error, undefined, result.error?.message);
    assert.equal(result.status, probe.exitCode, result.stderr);
    assert.equal(result.stderr, '');
    assert.equal(result.stdout, probe.stdout);
  } finally { rmSync(directory, { recursive: true, force: true }); }
});
