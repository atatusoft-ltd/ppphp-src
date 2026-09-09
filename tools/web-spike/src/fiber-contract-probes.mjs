// Trusted runtime regression fixtures, not user programs or compiler semantics.
// Every case runs in a fresh PHP context. Expected output is verified natively.
export const fiberContractProbes = [
  {
    id: 'fiber-lifecycle',
    code: `<?php
$f = new Fiber(static function (): int {
    $self = Fiber::getCurrent();
    echo (int) $self->isRunning(), ':';
    $value = Fiber::suspend(7);
    return $value * 2;
});
echo (int) $f->isStarted(), ':';
echo $f->start(), ':';
echo (int) $f->isStarted(), ':', (int) $f->isSuspended(), ':';
$f->resume(21);
echo (int) $f->isTerminated(), ':', $f->getReturn(), ':', (int) (Fiber::getCurrent() === null);
`,
    stdout: '0:1:7:1:1:1:42:1', exitCode: 0,
  },
  {
    id: 'fiber-unhandled-exception',
    code: `<?php
$f = new Fiber(static function (): void {
    try { Fiber::suspend('ready'); throw new RuntimeException('expected'); }
    finally { echo 'finally:'; }
});
echo $f->start(), ':';
try { $f->resume(); } catch (RuntimeException $e) { echo $e->getMessage(), ':'; }
echo (int) $f->isTerminated(), ':';
try { $f->getReturn(); } catch (FiberError $e) { echo 'no-return'; }
`,
    stdout: 'ready:finally:expected:1:no-return', exitCode: 0,
  },
  {
    id: 'fiber-invalid-transitions',
    code: `<?php
$errors = 0;
$f = new Fiber(static function (): int { Fiber::suspend(); return 42; });
try { $f->resume(); } catch (FiberError $e) { ++$errors; }
$f->start();
try { $f->start(); } catch (FiberError $e) { ++$errors; }
$f->resume();
try { $f->resume(); } catch (FiberError $e) { ++$errors; }
try { Fiber::suspend(); } catch (FiberError $e) { ++$errors; }
echo $errors, ':', $f->getReturn();
`,
    stdout: '4:42', exitCode: 0,
  },
  {
    id: 'fiber-suspended-destruction',
    code: `<?php
$events = [];
$f = new Fiber(static function () use (&$events): void {
    try { $events[] = 'start'; Fiber::suspend(); $events[] = 'unexpected-resume'; }
    finally { $events[] = 'finally'; }
});
$f->start();
$weak = WeakReference::create($f);
unset($f);
gc_collect_cycles();
echo json_encode($events), ':', (int) ($weak->get() === null);
`,
    stdout: '["start","finally"]:1', exitCode: 0,
  },
  {
    id: 'fiber-nested-callbacks',
    code: `<?php
function visit(int $depth): int {
    if ($depth === 0) return Fiber::suspend(10);
    $result = array_map(static fn (int $value): int => visit($depth - 1) + $value, [1]);
    return $result[0];
}
$f = new Fiber(static fn (): int => visit(8));
echo $f->start(), ':';
$f->resume(34);
echo $f->getReturn();
`,
    stdout: '10:42', exitCode: 0,
  },
  {
    id: 'fiber-cross-resume',
    code: `<?php
$b = new Fiber(static function (): int { echo 'b1:'; $v = Fiber::suspend(20); echo 'b2:'; return $v + 2; });
$a = new Fiber(static function () use ($b): int {
    echo 'a1:', $b->start(), ':';
    $value = Fiber::suspend(10);
    echo 'a2:';
    $b->resume($value);
    return $b->getReturn();
});
echo $a->start(), ':';
$a->resume(40);
echo $a->getReturn(), ':', (int) ($a->isTerminated() && $b->isTerminated());
`,
    stdout: 'a1:b1:20:10:a2:b2:42:1', exitCode: 0,
  },
  {
    id: 'fiber-error-state-isolation',
    code: `<?php
error_reporting(E_ALL);
$f = new Fiber(static function (): int {
    error_reporting(E_USER_WARNING);
    Fiber::suspend(error_reporting());
    return error_reporting();
});
$before = error_reporting();
$suspended = $f->start();
$middle = error_reporting();
$f->resume();
echo (int) ($before === E_ALL), ':', (int) ($middle === E_ALL), ':';
echo (int) ($suspended === E_USER_WARNING), ':', (int) ($f->getReturn() === E_USER_WARNING), ':';
echo (int) (error_reporting() === E_ALL);
`,
    stdout: '1:1:1:1:1', exitCode: 0,
  },
  {
    id: 'fiber-repeated-switch-and-collect',
    code: `<?php
$total = 0;
$destroyed = 0;
for ($i = 0; $i < 128; ++$i) {
    $f = new Fiber(static function (): int {
        $value = 0;
        for ($j = 0; $j < 8; ++$j) $value += Fiber::suspend($j);
        return $value;
    });
    $f->start();
    for ($j = 0; $j < 8; ++$j) $f->resume(1);
    $total += $f->getReturn();
    $weak = WeakReference::create($f);
    unset($f);
    gc_collect_cycles();
    if ($weak->get() === null) ++$destroyed;
}
echo $total, ':', $destroyed, ':', (int) (Fiber::getCurrent() === null);
`,
    stdout: '1024:128:1', exitCode: 0,
  },
];
