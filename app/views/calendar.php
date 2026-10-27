<?php /* calendar.php */ ?>
<h1>Calendar</h1>
<p class="muted"><?= e($month_label) ?></p>
<div class="calendar">
<?php foreach ($weeks as $week): ?>
  <div class="week">
  <?php foreach ($week as $day): ?>
    <div class="day <?= $day['in_month'] ? '' : 'dim' ?> <?= $day['today'] ? 'today' : '' ?>">
      <div class="daynum"><?= $day['num'] ?></div>
      <?php foreach ($day['shifts'] as $s): ?>
        <div class="cal-shift <?= e($s['status']) ?>" title="<?= e($s['started_at'].' → '.($s['ended_at'] ?? 'active')) ?>">
          <?= e($s['client_name'] ?? 'Shift') ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
  </div>
<?php endforeach; ?>
</div>