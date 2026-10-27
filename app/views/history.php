<?php /* history.php */ ?>
<h1>Shift history</h1>
<form method="get" action="/history" class="row">
  <label>From <input type="date" name="from" value="<?= e($from) ?>"></label>
  <label>To <input type="date" name="to" value="<?= e($to) ?>"></label>
  <button class="btn" type="submit">Filter</button>
  <a class="btn" href="/history/export.csv?from=<?= e($from) ?>&to=<?= e($to) ?>">Export CSV</a>
  <a class="btn" href="/report?from=<?= e($from) ?>&to=<?= e($to) ?>" target="_blank">Printable report</a>
</form>
<table class="data card">
  <thead><tr><th>Started</th><th>Ended</th><th>Scheduled</th><th>Elapsed</th><th>Break</th><th>Overtime</th><th>Client</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($shifts as $s):
    $tz = $user['timezone'];
    $start = utc_to_user($s['started_at'], $tz);
    $end = utc_to_user($s['ended_at'], $tz);
    $sched = utc_to_user($s['scheduled_end'], $tz);
    $elapsed = $s['ended_at'] ? strtotime($s['ended_at'].' UTC') - strtotime($s['started_at'].' UTC') : strtotime(now_utc().' UTC') - strtotime($s['started_at'].' UTC');
    $ot = $s['ended_at'] ? max(0, strtotime($s['ended_at'].' UTC') - strtotime($s['scheduled_end'].' UTC')) : 0;
    $brk = (int)($s['break_seconds'] ?? 0);
  ?>
    <tr>
      <td><?= e($start?->format('Y-m-d H:i') ?? '') ?></td>
      <td><?= e($end?->format('Y-m-d H:i') ?? '—') ?></td>
      <td><?= e($sched?->format('Y-m-d H:i') ?? '') ?></td>
      <td><?= e(fmt_hm($elapsed)) ?></td>
      <td><?= e(fmt_hm($brk)) ?></td>
      <td><?= $ot > 0 ? '<strong>' . e(fmt_hm($ot)) . '</strong>' : '—' ?></td>
      <td><?= e($s['client_name'] ?? '—') ?></td>
      <td><span class="badge <?= e($s['status']) ?>"><?= e($s['status']) ?></span></td>
      <td>
        <?php if (!$s['ended_at'] && $s['status'] === 'active'): ?>
          <a class="btn small" href="/history/adjust?id=<?= (int)$s['id'] ?>">Adjust end</a>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<p class="muted">Total elapsed: <?= e(fmt_hm($totals['elapsed'])) ?> · Total overtime: <?= e(fmt_hm($totals['overtime'])) ?></p>