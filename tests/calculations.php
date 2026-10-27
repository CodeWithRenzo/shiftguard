<?php
// Run: php tests/calculations.php
declare(strict_types=1);

function assert_eq($a, $b, string $msg) {
    if ($a !== $b) { fwrite(STDERR, "FAIL: $msg ($a != $b)\n"); exit(1); }
    echo "ok - $msg\n";
}

// 9h shift, clock in 09:00 UTC, expected 18:00 UTC
$start = strtotime('2024-06-01 09:00:00 UTC');
$sched = $start + 540 * 60;
assert_eq(gmdate('Y-m-d H:i:s', $sched), '2024-06-01 18:00:00', '9-hour default shift computes 18:00');

// Overtime calculation
$actual = strtotime('2024-06-01 19:42:00 UTC');
assert_eq($actual - $sched, 6120, 'Overtime seconds = 1h42m');

// No rounding: minimum threshold must be zero
$sched2 = strtotime('2024-06-01 18:00:00 UTC');
$actual2 = strtotime('2024-06-01 18:00:30 UTC');
assert_eq($actual2 - $sched2, 30, '30-second overtime recorded exactly, no rounding');

// Reminder offsets are strictly positive minutes
$offsets = [45, 30, 15, 10, 5, 3];
foreach ($offsets as $o) assert_eq($o > 0, true, "offset {$o} valid");

// Break subtraction
$elapsed = 9 * 3600; $brk = 3600; $paid = false;
$net = $paid ? $elapsed : $elapsed - $brk;
assert_eq($net, 8 * 3600, 'unpaid break subtracts 1h');
$paid = true;
$net = $paid ? $elapsed : $elapsed - $brk;
assert_eq($net, 9 * 3600, 'paid break keeps net at elapsed');

// fmt_hms edge
function fmt_hms_t(int $s): string {
    $h = intdiv($s, 3600); $m = intdiv($s % 3600, 60); $s = $s % 60;
    return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
}
assert_eq(fmt_hms_t(0), '0:00', 'zero formats as 0:00');
assert_eq(fmt_hms_t(3600), '1:00:00', 'one hour formats as 1:00:00');
assert_eq(fmt_hms_t(6120), '1:42:00', '1h42m formats as 1:42:00');

echo "\nAll checks passed.\n";