<?php /* reset.php */ ?>
<h1>Choose a new password</h1>
<form method="post" action="/reset" class="form card">
  <?= csrf_field() ?>
  <input type="hidden" name="token" value="<?= e($token) ?>">
  <label>New password <input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
  <button class="btn primary" type="submit">Save password</button>
</form>