<?php /* settings.php */ ?>
<h1>Settings</h1>
<form method="post" action="/settings" class="form card">
  <?= csrf_field() ?>
  <h2>Profile</h2>
  <label>Name <input type="text" name="display_name" value="<?= e($user['display_name']) ?>" required></label>
  <label>Timezone (IANA, e.g. Europe/Berlin)
    <input type="text" name="timezone" value="<?= e($user['timezone']) ?>" required></label>

  <h2>Schedule defaults</h2>
  <label>Shift length (minutes, elapsed)
    <input type="number" name="shift_minutes" min="60" max="1440" value="<?= (int)$prefs['shift_minutes'] ?>"></label>
  <label>Break minutes <input type="number" name="break_minutes" min="0" max="480" value="<?= (int)$prefs['break_minutes'] ?>"></label>
  <label><input type="checkbox" name="break_paid" value="1" <?= $prefs['break_paid'] ? 'checked' : '' ?>> Break is paid</label>

  <h2>Reminder offsets (minutes before shift end, comma separated)</h2>
  <input type="text" name="reminder_offsets" value="<?= e(implode(',', $prefs['reminder_offsets'])) ?>">

  <h2>Notification channels</h2>
  <label><input type="checkbox" name="email_enabled" value="1" <?= $prefs['email_enabled'] ? 'checked' : '' ?>> Email reminders</label>
  <label><input type="checkbox" name="push_enabled" value="1" <?= $prefs['push_enabled'] ? 'checked' : '' ?>> Push notifications</label>
  <label><input type="checkbox" name="sound_enabled" value="1" <?= $prefs['sound_enabled'] ? 'checked' : '' ?>> Sound (when app open)</label>
  <label>Theme
    <select name="theme">
      <?php foreach (['auto','light','dark'] as $t): ?>
        <option value="<?= $t ?>" <?= $prefs['theme'] === $t ? 'selected' : '' ?>><?= $t ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <button class="btn primary" type="submit">Save settings</button>
</form>

<section class="card">
  <h2>Push notifications on this device</h2>
  <p class="muted" id="push-state">Checking…</p>
  <p class="muted">iPhone: you must add ShiftGuard to your Home Screen and open it from there, then grant notification permission.</p>
  <button class="btn" id="push-enable" type="button">Enable push on this device</button>
  <button class="btn" id="push-test" type="button">Send test push</button>
  <button class="btn danger" id="push-disable" type="button">Remove this device</button>
</section>

<section class="card">
  <h2>Email</h2>
  <p class="muted">SMTP configured: <strong><?= smtp_configured() ? 'yes' : 'no (contact admin)' ?></strong></p>
  <p class="muted">VAPID configured: <strong><?= push_configured() ? 'yes' : 'no (contact admin)' ?></strong></p>
</section>

<section class="card">
  <h2>Danger zone</h2>
  <form method="post" action="/account/delete" onsubmit="return confirm('Delete your account and all records?');">
    <?= csrf_field() ?><button class="btn danger">Delete my account</button>
  </form>
</section>