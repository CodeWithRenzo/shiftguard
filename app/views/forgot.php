<?php /* forgot.php */ ?>
<h1>Reset password</h1>
<form method="post" action="/forgot" class="form card">
  <?= csrf_field() ?>
  <label>Email <input type="email" name="email" required></label>
  <button class="btn primary" type="submit">Send reset link</button>
</form>