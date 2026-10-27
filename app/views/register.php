<?php /* register.php */ ?>
<h1>Create your account</h1>
<form method="post" action="/register" class="form card">
  <?= csrf_field() ?>
  <label>Name <input type="text" name="name" required maxlength="120"></label>
  <label>Email <input type="email" name="email" required autocomplete="email"></label>
  <label>Password <input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
  <button class="btn primary" type="submit">Create account</button>
</form>
<p><a href="/login">Already have an account?</a></p>