<?php /** @var array $user @var array $prefs @var array $summary @var array $clients @var array $jobs @var array $notifs */ ?>
<h1>Dashboard</h1>

<section id="timer-card" class="card timer"
  data-summary='<?= e(json_encode($summary)) ?>'
  data-server-now='<?= e(now_utc()) ?>'>
  <div class="status" id="status-label">
    <?php if (empty($summary['active'])): ?>Not Working<?php else: ?>
      <?= $summary['state'] === 'over' ? 'Shift Over' : ($summary['state'] === 'ending_soon' ? 'Ending Soon' : 'On Shift') ?>
    <?php endif; ?>
  </div>
  <?php if (empty($summary['active'])): ?>
    <form method="post" action="/shift/start" class="clock-form">
      <?= csrf_field() ?>
      <label>Client
        <select name="client_id">
          <option value="">— none —</option>
          <?php foreach ($clients as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?> (<?= e($c['email']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Shift length (hours, optional override)
        <input type="number" name="override_hours" min="1" max="24" step="0.25" placeholder="9">
      </label>
      <button class="btn primary big" type="submit">Time In</button>
    </form>
  <?php else: ?>
    <div class="timer-display" id="elapsed">--:--</div>
    <div class="timer-meta">
      <div>Scheduled end: <strong id="sched-end">
        <?php $e = utc_to_user($summary['scheduled_end'], $user['timezone']); echo e($e ? $e->format('Y-m-d H:i T') : $summary['scheduled_end']); ?>
      </strong></div>
      <div>Countdown: <strong id="countdown">--</strong></div>
      <div id="overtime-row" <?= $summary['overtime_seconds'] <= 0 ? 'hidden' : '' ?>>Overtime: <strong id="overtime">--</strong></div>
    </div>
    <div class="timer-actions">
      <button type="button" id="ack-ot" class="btn" data-shift="<?= (int)$summary['id'] ?>" <?= $summary['overtime_ack_at'] ? 'disabled' : '' ?>>
        <?= $summary['overtime_ack_at'] ? 'Overtime acknowledged' : 'Continue as Overtime' ?>
      </button>
      <button type="button" id="break-toggle" class="btn"><?= $summary['break_running'] ? 'End Break' : 'Start Break' ?></button>
      <form method="post" action="/shift/end" class="inline" onsubmit="return confirm('Record Time Out now?');">
        <?= csrf_field() ?>
        <input type="hidden" name="shift_id" value="<?= (int)$summary['id'] ?>">
        <button class="btn danger big" type="submit">Time Out</button>
      </form>
    </div>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Upcoming reminders</h2>
  <?php if (!$jobs): ?><p class="muted">No reminders scheduled.</p><?php else: ?>
    <table class="data">
      <thead><tr><th>When</th><th>Channel</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($jobs as $j): $t = utc_to_user($j['fire_at'], $user['timezone']); ?>
        <tr><td><?= e($t ? $t->format('Y-m-d H:i T') : $j['fire_at']) ?></td>
            <td><?= e($j['channel']) ?></td>
            <td><span class="badge <?= e($j['status']) ?>"><?= e($j['status']) ?></span></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Notifications</h2>
  <?php if (!$notifs): ?><p class="muted">All clear.</p><?php else: ?>
    <ul class="notif-list">
      <?php foreach ($notifs as $n): ?>
        <li><strong><?= e($n['title']) ?></strong> <small class="muted"><?= e($n['created_at']) ?> UTC</small>
          <?php if ($n['body']): ?><div><?= e($n['body']) ?></div><?php endif; ?></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>