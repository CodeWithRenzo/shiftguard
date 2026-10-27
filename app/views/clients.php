<?php /* clients.php */ ?>
<h1>Clients</h1>
<form method="post" action="/clients" class="form card">
  <?= csrf_field() ?>
  <h2>Add client</h2>
  <label>Name <input type="text" name="name" required maxlength="120"></label>
  <label>Email <input type="email" name="email" required></label>
  <label>Alternate emails (comma-separated) <input type="text" name="alt_emails" maxlength="500"></label>
  <label>Email subject <input type="text" name="subject_tpl" value="Upcoming End of Shift Reminder" maxlength="255"></label>
  <label>Email body <textarea name="body_tpl" rows="6">Hello {{client_name}},

This is a courtesy reminder that the scheduled shift is approaching its end time.

Scheduled end: {{scheduled_end}}
Time remaining: Approximately {{minutes_left}} minutes.

Thank you.</textarea></label>
  <button class="btn primary" type="submit">Save client</button>
</form>

<table class="data card">
  <thead><tr><th>Name</th><th>Email</th><th>Active</th><th></th></tr></thead>
  <tbody>
    <?php foreach ($clients as $c): ?>
      <tr>
        <td><?= e($c['name']) ?></td>
        <td><?= e($c['email']) ?></td>
        <td><?= $c['active'] ? 'Yes' : 'No' ?></td>
        <td>
          <form method="post" action="/clients/toggle" class="inline"><?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <button class="btn small"><?= $c['active'] ? 'Deactivate' : 'Activate' ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>