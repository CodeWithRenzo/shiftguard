<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
security_headers();

$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

// Public routes
if ($path === '/login' && $method === 'GET') {
    page('Sign in', render('login'));
}
if ($path === '/login' && $method === 'POST') {
    csrf_check($_POST['_csrf'] ?? null);
    [$ok, $u] = attempt_login(post_str('email'), $_POST['password'] ?? '', $_SERVER['REMOTE_ADDR'] ?? '');
    if (!$ok) { flash_set('error', $u); redirect('/login'); }
    redirect('/');
}
if ($path === '/register' && $method === 'GET') page('Create account', render('register'));
if ($path === '/register' && $method === 'POST') {
    csrf_check($_POST['_csrf'] ?? null);
    $email = post_str('email'); $name = post_str('name'); $pw = $_POST['password'] ?? '';
    if (!valid_email($email) || strlen($pw) < 10 || $name === '') {
        flash_set('error', 'Invalid email, name, or password (min 10 chars).'); redirect('/register');
    }
    try { register_user($email, $pw, $name); }
    catch (Throwable $e) { flash_set('error', 'Email already registered.'); redirect('/register'); }
    flash_set('success', 'Account created. You can now sign in.');
    redirect('/login');
}
if ($path === '/forgot' && $method === 'GET') page('Reset password', render('forgot'));
if ($path === '/forgot' && $method === 'POST') {
    csrf_check($_POST['_csrf'] ?? null);
    request_password_reset(post_str('email'));
    flash_set('success', 'If that email is registered, a reset link has been sent.');
    redirect('/forgot');
}
if ($path === '/reset' && $method === 'GET') {
    page('Reset password', render('reset', ['token' => $_GET['token'] ?? '']));
}
if ($path === '/reset' && $method === 'POST') {
    csrf_check($_POST['_csrf'] ?? null);
    $ok = consume_reset($_POST['token'] ?? '', $_POST['password'] ?? '');
    flash_set($ok ? 'success' : 'error', $ok ? 'Password updated.' : 'Invalid or expired token.');
    redirect($ok ? '/login' : '/forgot');
}
if ($path === '/verify' && $method === 'GET') {
    $ok = verify_email($_GET['token'] ?? '');
    flash_set($ok ? 'success' : 'error', $ok ? 'Email verified.' : 'Invalid verification link.');
    redirect('/');
}
if ($path === '/manifest.json') { header('Content-Type: application/manifest+json'); readfile(SG_PUBLIC . '/manifest.json'); exit; }
if ($path === '/sw.js') { header('Content-Type: application/javascript'); header('Service-Worker-Allowed: /'); readfile(SG_PUBLIC . '/assets/sw.js'); exit; }

// Auth required below
$user = require_auth();
$prefs = user_prefs((int)$user['id']);
$GLOBALS['CURRENT_USER_NAME'] = $user['display_name'];

// Logout
if ($path === '/logout' && $method === 'POST') {
    csrf_check($_POST['_csrf'] ?? null);
    session_destroy();
    redirect('/login');
}

// ----- JSON API -----
if ($path === '/api/state' && $method === 'GET') {
    json_out([
        'ok' => true,
        'server_now' => now_utc(),
        'summary' => shift_summary((int)$user['id'], $user['timezone']),
        'smtp' => smtp_configured(),
        'push' => push_configured(),
        'vapid_public' => $GLOBALS['CONFIG']['vapid']['public'] ?? '',
    ]);
}
if ($path === '/api/push/subscribe' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $endpoint = $body['endpoint'] ?? ''; $keys = $body['keys'] ?? [];
    if (!is_string($endpoint) || !$endpoint || empty($keys['p256dh']) || empty($keys['auth'])) send_json_error('Bad subscription', 422);
    q('INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh), auth = VALUES(auth)',
      [$user['id'], $endpoint, $keys['p256dh'], $keys['auth'], $_SERVER['HTTP_USER_AGENT'] ?? '', now_utc()]);
    json_out(['ok' => true]);
}
if ($path === '/api/push/unsubscribe' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $endpoint = $body['endpoint'] ?? '';
    q('DELETE FROM push_subscriptions WHERE endpoint = ? AND user_id = ?', [$endpoint, $user['id']]);
    json_out(['ok' => true]);
}
if ($path === '/api/push/test' && $method === 'POST') {
    $r = push_send_to_user((int)$user['id'], ['title' => 'ShiftGuard test', 'body' => 'Push is working.', 'url' => '/']);
    json_out($r);
}
if ($path === '/api/shift/ack-overtime' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    shift_acknowledge_overtime((int)$user['id'], (int)($body['shift_id'] ?? 0));
    json_out(['ok' => true]);
}
if ($path === '/api/shift/break-toggle' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $sid = (int)($body['shift_id'] ?? 0);
    $open = shift_break_open($sid);
    if ($open) shift_break_end((int)$user['id'], $sid); else shift_break_start((int)$user['id'], $sid);
    json_out(['ok' => true, 'running' => !$open]);
}

// ----- Form POST actions -----
if ($path === '/shift/start' && $method === 'POST') {
    csrf_check($_POST['_csrf'] ?? null);
    $client_id = $_POST['client_id'] !== '' ? (int)$_POST['client_id'] : null;
    $override = $_POST['override_hours'] !== '' ? (int)round(((float)$_POST['override_hours']) * 60) : null;
    try {
        if ($client_id) {
            $c = q('SELECT id FROM clients WHERE id = ? AND user_id = ?', [$client_id, $user['id']])->fetch();
            if (!$c) throw new RuntimeException('Unknown client.');
        }
        shift_start((int)$user['id'], $client_id, $override, null);
        flash_set('success', 'Shift started.');
    } catch (Throwable $e) { flash_set('error', $e->getMessage()); }
    redirect('/');
}
if ($path === '/shift/end' && $method === 'POST') {
    csrf_check($_POST['_csrf'] ?? null);
    try {
        $r = shift_end((int)$user['id'], (int)($_POST['shift_id'] ?? 0));
        flash_set('success', 'Shift recorded. Duration ' . fmt_hm($r['elapsed_seconds']) . ($r['overtime_seconds'] > 0 ? '. Overtime: ' . fmt_hm($r['overtime_seconds']) : ''));
    } catch (Throwable $e) { flash_set('error', $e->getMessage()); }
    redirect('/');
}
if ($path === '/history/adjust' && $method === 'POST') {
    csrf_check($_POST['_csrf'] ?? null);
    $at = post_str('actual_end_utc'); // expect 'Y-m-d H:i'
    try {
        $at_utc = $at ? gmdate('Y-m-d H:i:s', strtotime($at . ' UTC')) : null;
        shift_end((int)$user['id'], (int)($_POST['shift_id'] ?? 0), $at_utc, post_str('reason'));
        flash_set('success', 'Shift end adjusted and audited.');
    } catch (Throwable $e) { flash_set('error', $e->getMessage()); }
    redirect('/history');
}
if ($path === '/clients' && $method === 'POST') {
    csrf_check($_POST['_csrf'] ?? null);
    $name = post_str('name'); $email = post_str('email');
    if ($name === '' || !valid_email($email)) { flash_set('error', 'Name and valid email required.'); redirect('/clients'); }
    q('INSERT INTO clients (user_id, name, email, alt_emails, subject_tpl, body_tpl, active, created_at) VALUES (?,?,?,?,?,?,1,?)',
      [$user['id'], $name, $email, post_str('alt_emails'), post_str('subject_tpl'), post_str('body_tpl'), now_utc()]);
    flash_set('success', 'Client saved.'); redirect('/clients');
}
if ($path === '/clients/toggle' && $method === 'POST') {
    csrf_check($_POST['_csrf'] ?? null);
    $id = (int)($_POST['id'] ?? 0);
    q('UPDATE clients SET active = 1 - active WHERE id = ? AND user_id = ?', [$id, $user['id']]);
    redirect('/clients');
}
if ($path === '/settings' && $method === 'POST') {
    csrf_check($_POST['_csrf'] ?? null);
    $offsets = array_values(array_filter(array_map('intval', explode(',', post_str('reminder_offsets'))), fn($n) => $n > 0 && $n <= 1440));
    if (!$offsets) $offsets = [30,10,5,3];
    q('UPDATE users SET display_name = ?, timezone = ?, updated_at = ? WHERE id = ?',
      [post_str('display_name'), post_str('timezone', 'UTC'), now_utc(), $user['id']]);
    q('UPDATE user_prefs SET shift_minutes = ?, break_minutes = ?, break_paid = ?, reminder_offsets = ?, email_enabled = ?, push_enabled = ?, sound_enabled = ?, theme = ? WHERE user_id = ?',
      [max(60, min(1440, (int)post_str('shift_minutes', '540'))),
       max(0, min(480, (int)post_str('break_minutes', '60'))),
       isset($_POST['break_paid']) ? 1 : 0,
       json_encode($offsets),
       isset($_POST['email_enabled']) ? 1 : 0,
       isset($_POST['push_enabled']) ? 1 : 0,
       isset($_POST['sound_enabled']) ? 1 : 0,
       in_array(post_str('theme'), ['auto','light','dark'], true) ? post_str('theme') : 'auto',
       $user['id']]);
    flash_set('success', 'Settings saved.'); redirect('/settings');
}
if ($path === '/account/delete' && $method === 'POST') {
    csrf_check($_POST['_csrf'] ?? null);
    q('UPDATE users SET deleted_at = ?, email = CONCAT("deleted+", id, "@example.invalid"), password_hash = "" WHERE id = ?', [now_utc(), $user['id']]);
    session_destroy();
    redirect('/login');
}

// ----- HTML GET routes -----
if ($path === '/' && $method === 'GET') {
    $summary = shift_summary((int)$user['id'], $user['timezone']);
    $clients = q('SELECT * FROM clients WHERE user_id = ? AND active = 1 ORDER BY name', [$user['id']])->fetchAll();
    $jobs = [];
    if (!empty($summary['active'])) {
        $jobs = q('SELECT * FROM notification_jobs WHERE shift_id = ? AND status IN ("queued","failed") ORDER BY fire_at', [$summary['id']])->fetchAll();
    }
    $notifs = q('SELECT * FROM in_app_notifications WHERE user_id = ? AND dismissed_at IS NULL ORDER BY created_at DESC LIMIT 20', [$user['id']])->fetchAll();
    page('Dashboard', render('dashboard', compact('user','prefs','summary','clients','jobs','notifs')), $user, $prefs);
}

if ($path === '/history' && $method === 'GET') {
    $from = $_GET['from'] ?? gmdate('Y-m-d', strtotime('-30 days'));
    $to   = $_GET['to']   ?? gmdate('Y-m-d');
    $rows = q('SELECT s.*, c.name AS client_name,
                 (SELECT COALESCE(SUM(TIMESTAMPDIFF(SECOND, b.started_at, COALESCE(b.ended_at, UTC_TIMESTAMP()))),0) FROM breaks b WHERE b.shift_id = s.id) AS break_seconds
               FROM shifts s LEFT JOIN clients c ON c.id = s.client_id
               WHERE s.user_id = ? AND s.started_at >= ? AND s.started_at < DATE_ADD(?, INTERVAL 1 DAY)
               ORDER BY s.started_at DESC',
              [$user['id'], $from . ' 00:00:00', $to])->fetchAll();
    $totals = ['elapsed' => 0, 'overtime' => 0];
    foreach ($rows as &$r) {
        if ($r['ended_at']) {
            $totals['elapsed'] += strtotime($r['ended_at'].' UTC') - strtotime($r['started_at'].' UTC');
            $totals['overtime'] += max(0, strtotime($r['ended_at'].' UTC') - strtotime($r['scheduled_end'].' UTC'));
        }
    }
    unset($r);
    page('History', render('history', compact('user','prefs','rows','from','to','totals') + ['shifts' => $rows]), $user, $prefs);
}

if ($path === '/history/export.csv' && $method === 'GET') {
    $from = $_GET['from'] ?? gmdate('Y-m-d', strtotime('-30 days'));
    $to   = $_GET['to']   ?? gmdate('Y-m-d');
    $rows = q('SELECT s.*, c.name AS client_name FROM shifts s LEFT JOIN clients c ON c.id = s.client_id
               WHERE s.user_id = ? AND s.started_at >= ? AND s.started_at < DATE_ADD(?, INTERVAL 1 DAY) ORDER BY s.started_at',
              [$user['id'], $from.' 00:00:00', $to])->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="shiftguard-' . $from . '-' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Shift ID','Client','Started (UTC)','Scheduled end (UTC)','Ended (UTC)','Elapsed (min)','Break (min)','Overtime (min)','Overtime acknowledged','Status']);
    foreach ($rows as $r) {
        $elapsed = $r['ended_at'] ? (strtotime($r['ended_at'].' UTC') - strtotime($r['started_at'].' UTC')) / 60 : 0;
        $ot = $r['ended_at'] ? max(0, (strtotime($r['ended_at'].' UTC') - strtotime($r['scheduled_end'].' UTC')) / 60) : 0;
        $brk = q('SELECT COALESCE(SUM(TIMESTAMPDIFF(SECOND, started_at, COALESCE(ended_at, UTC_TIMESTAMP()))),0) AS s FROM breaks WHERE shift_id = ?', [$r['id']])->fetch()['s'];
        fputcsv($out, [$r['id'], $r['client_name'] ?? '', $r['started_at'], $r['scheduled_end'], $r['ended_at'] ?? '', (int)round($elapsed), (int)round($brk/60), (int)round($ot), $r['overtime_ack_at'] ?? '', $r['status']]);
    }
    exit;
}

if ($path === '/report' && $method === 'GET') {
    $from = $_GET['from'] ?? gmdate('Y-m-d', strtotime('-30 days'));
    $to   = $_GET['to']   ?? gmdate('Y-m-d');
    $shifts = q('SELECT s.*, c.name AS client_name FROM shifts s LEFT JOIN clients c ON c.id = s.client_id
                 WHERE s.user_id = ? AND s.started_at >= ? AND s.started_at < DATE_ADD(?, INTERVAL 1 DAY) ORDER BY s.started_at',
                [$user['id'], $from.' 00:00:00', $to])->fetchAll();
    page('Report', render('report', compact('user','prefs','shifts','from','to')), $user, $prefs);
}

if ($path === '/clients' && $method === 'GET') {
    $clients = q('SELECT * FROM clients WHERE user_id = ? ORDER BY name', [$user['id']])->fetchAll();
    page('Clients', render('clients', compact('user','prefs','clients')), $user, $prefs);
}

if ($path === '/settings' && $method === 'GET') {
    page('Settings', render('settings', compact('user','prefs')), $user, $prefs);
}

if ($path === '/calendar' && $method === 'GET') {
    $tz = new DateTimeZone($user['timezone']);
    $now = new DateTimeImmutable('now', $tz);
    $y = (int)($now->format('Y')); $m = (int)($now->format('n'));
    $first = new DateTimeImmutable("$y-$m-01", $tz);
    $offset = (int)$first->format('N') - 1;
    $start = $first->modify("-{$offset} days");
    $weeks = [];
    $cursor = $start;
    for ($w = 0; $w < 6; $w++) {
        $week = [];
        for ($d = 0; $d < 7; $d++) {
            $day_start_utc = $cursor->setTime(0,0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $day_end_utc   = $cursor->modify('+1 day')->setTime(0,0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $shifts = q('SELECT s.*, c.name AS client_name FROM shifts s LEFT JOIN clients c ON c.id = s.client_id
                         WHERE s.user_id = ? AND s.started_at >= ? AND s.started_at < ? ORDER BY s.started_at',
                        [$user['id'], $day_start_utc, $day_end_utc])->fetchAll();
            $week[] = ['num' => (int)$cursor->format('j'), 'in_month' => $cursor->format('n') === (string)$m, 'today' => $cursor->format('Y-m-d') === $now->format('Y-m-d'), 'shifts' => $shifts];
            $cursor = $cursor->modify('+1 day');
        }
        $weeks[] = $week;
    }
    $month_label = $first->format('F Y');
    page('Calendar', render('calendar', compact('user','prefs','weeks','month_label')), $user, $prefs);
}

if ($path === '/analytics' && $method === 'GET') {
    $rows = q('SELECT s.*, c.name AS client_name FROM shifts s LEFT JOIN clients c ON c.id = s.client_id WHERE s.user_id = ? AND s.ended_at IS NOT NULL', [$user['id']])->fetchAll();
    $total = 0; $ot = 0; $late = 0; $by_client = []; $shifts_count = 0;
    foreach ($rows as $r) {
        $e = strtotime($r['ended_at'].' UTC') - strtotime($r['started_at'].' UTC');
        $o = max(0, strtotime($r['ended_at'].' UTC') - strtotime($r['scheduled_end'].' UTC'));
        $total += $e; $ot += $o; $shifts_count++;
        if ($o > 0) $late++;
        $k = $r['client_name'] ?? '—';
        if (!isset($by_client[$k])) $by_client[$k] = ['client_name' => $k, 'n' => 0, 'ot' => 0];
        $by_client[$k]['n']++; $by_client[$k]['ot'] += $o;
    }
    $stats = ['total_seconds' => $total, 'overtime_seconds' => $ot, 'late_count' => $late,
              'avg_overtime' => $shifts_count ? (int)($ot / $shifts_count) : 0,
              'by_client' => array_values($by_client)];
    page('Analytics', render('analytics', compact('user','prefs','stats')), $user, $prefs);
}

if ($path === '/history/adjust' && $method === 'GET') {
    $id = (int)($_GET['id'] ?? 0);
    $s = q('SELECT * FROM shifts WHERE id = ? AND user_id = ?', [$id, $user['id']])->fetch();
    if (!$s) { http_response_code(404); exit('Not found'); }
    $body = '<h1>Adjust shift end</h1><form method="post" action="/history/adjust" class="form card">'
          . csrf_field() . '<input type="hidden" name="shift_id" value="' . (int)$s['id'] . '">'
          . '<label>Actual end (UTC, YYYY-MM-DD HH:MM) <input type="text" name="actual_end_utc" value="' . e(gmdate('Y-m-d H:i')) . '" required></label>'
          . '<label>Reason <input type="text" name="reason" required maxlength="500"></label>'
          . '<button class="btn primary">Save adjustment</button></form>';
    page('Adjust shift', $body, $user, $prefs);
}

http_response_code(404);
exit('Not found');