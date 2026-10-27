<?php /** @var string $title @var string $body @var array $user @var array $prefs */ ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#0b1220">
<title><?= e($title ?? 'ShiftGuard') ?></title>
<link rel="manifest" href="/manifest.json">
<link rel="apple-touch-icon" href="/assets/icons/192.png">
<link rel="stylesheet" href="/assets/app.css">
</head>
<body data-theme="<?= e($prefs['theme'] ?? 'auto') ?>">
<header class="topbar">
  <a class="brand" href="/">ShiftGuard</a>
  <nav>
    <a href="/">Dashboard</a>
    <a href="/history">History</a>
    <a href="/calendar">Calendar</a>
    <a href="/analytics">Analytics</a>
    <a href="/clients">Clients</a>
    <a href="/settings">Settings</a>
    <form method="post" action="/logout" class="inline"><?= csrf_field() ?><button class="link">Sign out</button></form>
  </nav>
  <span id="net-status" class="net-status" hidden>offline</span>
</header>
<main class="container">
<?php foreach (flash_pull() as $f): ?>
  <div class="flash <?= e($f['kind']) ?>"><?= e($f['msg']) ?></div>
<?php endforeach; ?>
<?= $body ?>
</main>
<footer class="footer">
  <small>ShiftGuard records your hours. It does not guarantee payment. &copy; <?= date('Y') ?></small>
</footer>
<script src="/assets/app.js" defer></script>
</body>
</html>