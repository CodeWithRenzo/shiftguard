<?php /* analytics.php */ ?>
<h1>Overtime analytics</h1>
<div class="grid">
  <div class="card stat"><div class="stat-label">Total recorded</div><div class="stat-value"><?= e(fmt_hm($stats['total_seconds'])) ?></div></div>
  <div class="card stat"><div class="stat-label">Overtime</div><div class="stat-value"><?= e(fmt_hm($stats['overtime_seconds'])) ?></div></div>
  <div class="card stat"><div class="stat-label">Shifts ending late</div><div class="stat-value"><?= (int)$stats['late_count'] ?></div></div>
  <div class="card stat"><div class="stat-label">Average overtime / shift</div><div class="stat-value"><?= e(fmt_hm((int)$stats['avg_overtime'])) ?></div></div>
</div>

<div class="card">
  <h2>By client</h2>
  <?php if (!$stats['by_client']): ?><p class="muted">No data.</p><?php else: ?>
    <table class="data">
      <thead><tr><th>Client</th><th>Shifts</th><th>Overtime</th></tr></thead>
      <tbody>
      <?php foreach ($stats['by_client'] as $r): ?>
        <tr><td><?= e($r['client_name'] ?? '—') ?></td><td><?= (int)$r['n'] ?></td><td><?= e(fmt_hm((int)$r['ot'])) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>