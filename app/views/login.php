<?php /* login.php */ ?>
<h1>Sign in</h1>
<form method="post" action="/login" class="form card">
  <?= csrf_field() ?>
  <label>Email <input type="email" name="email" required autocomplete="email"></label>
  <label>Password <input type="password" name="password" required autocomplete="current-password"></label>
  <button class="btn primary" type="submit">Sign in</button>
</form>
<p><a href="/forgot">Forgot password?</a> · <a href="/register">Create account</a></p>