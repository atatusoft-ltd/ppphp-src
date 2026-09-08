// Trusted, finite runtime fixtures. Source is passed to PHP as data, not JS.
export const probes = [
  { id: 'plain-json', code: '<?php echo json_encode(["answer" => 42]);', stdout: '{"answer":42}', exitCode: 0 },
  { id: 'platform', code: '<?php echo json_encode(["version" => PHP_VERSION, "sapi" => PHP_SAPI, "intSize" => PHP_INT_SIZE, "binary" => PHP_BINARY, "fiber" => class_exists("Fiber"), "extensions" => get_loaded_extensions()]);', json: true, exitCode: 0 },
  { id: 'fiber-return', code: '<?php $f = new Fiber(static fn (): int => 42); $f->start(); echo $f->getReturn();', stdout: '42', exitCode: 0 },
  { id: 'fiber-resume', code: '<?php $f = new Fiber(static function (): int { $v = Fiber::suspend(10); return $v + 1; }); echo $f->start(), ":"; $f->resume(32); echo $f->getReturn();', stdout: '10:33', exitCode: 0 },
  { id: 'fiber-throw-finally', code: '<?php $f = new Fiber(static function (): void { try { Fiber::suspend(); } catch (RuntimeException $e) { echo "caught:"; } finally { echo "finally:"; } }); $f->start(); $f->throw(new RuntimeException("probe")); echo "done";', stdout: 'caught:finally:done', exitCode: 0 },
  { id: 'nested-fiber', code: '<?php $outer = new Fiber(static function (): int { $inner = new Fiber(static fn (): int => 21); $inner->start(); return $inner->getReturn() * 2; }); $outer->start(); echo $outer->getReturn();', stdout: '42', exitCode: 0 },
  { id: 'gc-destructor', code: '<?php $events = []; class Cycle { public mixed $self; public function __destruct() { $GLOBALS["events"][] = "destroyed"; } } $o = new Cycle(); $o->self = $o; unset($o); gc_collect_cycles(); echo json_encode($events);', stdout: '["destroyed"]', exitCode: 0 },
  { id: 'gc-inside-fiber', code: '<?php $events = []; class Cycle { public mixed $self; public function __destruct() { $GLOBALS["events"][] = "destroyed"; } } $f = new Fiber(static function (): void { $o = new Cycle(); $o->self = $o; unset($o); gc_collect_cycles(); }); $f->start(); echo json_encode($events);', stdout: '["destroyed"]', exitCode: 0 },
  { id: 'empty-output', code: '<?php $answer = 42;', stdout: '', exitCode: 0 },
  { id: 'explicit-exit', code: '<?php echo "before"; exit(7);', stdout: 'before', exitCode: 7 },
  { id: 'lint-valid', cliLint: true, code: '<?php file_put_contents(__DIR__ . "/side-effect", "executed");', exitCode: 0 },
  { id: 'lint-invalid', cliLint: true, code: '<?php function broken( {', exitCode: 255 },
  { id: 'runaway', code: '<?php while (true) {}', terminate: true },
  { id: 'post-runaway-control', code: '<?php echo "recovered";', stdout: 'recovered', exitCode: 0 },
];

export function assess(probe, result) {
  if (probe.terminate) return result.kind === 'terminated' && result.computeStarted ? 'PASS' : 'FAIL';
  if (result.kind !== 'completed') return result.kind === 'not-run' ? 'NOT RUN' : 'FAIL';
  if (result.exitCode !== probe.exitCode) return 'FAIL';
  if (probe.cliLint) {
    const message = `${result.stdout}\n${result.stderr}`;
    if (result.sideEffect !== false) return 'FAIL';
    return probe.id === 'lint-valid'
      ? (message.includes('No syntax errors detected') ? 'PASS' : 'FAIL')
      : (/parse error|Errors parsing/i.test(message) ? 'PASS' : 'FAIL');
  }
  if (result.stderr !== '') return 'FAIL';
  if (probe.json) {
    try {
      const value = JSON.parse(result.stdout);
      return typeof value.version === 'string' && typeof value.sapi === 'string'
        && [4, 8].includes(value.intSize) && typeof value.fiber === 'boolean'
        && Array.isArray(value.extensions) ? 'PASS' : 'FAIL';
    } catch { return 'FAIL'; }
  }
  return result.stdout === probe.stdout ? 'PASS' : 'FAIL';
}

// PHPStan uses either [] or {} for an empty per-file error map.
export function validCleanPhpStanResult(text) {
  try {
    const json = JSON.parse(text);
    return Boolean(json && typeof json === 'object' && !Array.isArray(json)
      && json.totals?.errors === 0 && json.totals?.file_errors === 0
      && Array.isArray(json.errors) && json.errors.length === 0
      && json.files && typeof json.files === 'object' && Object.keys(json.files).length === 0);
  } catch { return false; }
}
