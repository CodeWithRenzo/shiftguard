<?php
declare(strict_types=1);

function current_user(): ?array {
    if (empty($_SESSION['uid'])) return null;
    static $u = null;
    if ($u !== null) return $u;
    $row = q('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL', [$_SESSION['uid']])->fetch();
    return $u = $row ?: null;
}

function user_prefs(int $user_id): array {
    $p = q('SELECT * FROM user_prefs WHERE user_id = ?', [$user_id])->fetch();
    if (!$p) {
        q('INSERT INTO user_prefs (user_id, reminder_offsets) VALUES (?, ?)', [$user_id, json_encode([30,10,5,3])]);
        $p = q('SELECT * FROM user_prefs WHERE user_id = ?', [$user_id])->fetch();
    }
    $p['reminder_offsets'] = json_decode($p['reminder_offsets'] ?? '[]', true) ?: [30,10,5,3];
    return $p;
}

function login_rate_ok(string $email, string $ip): bool {
    $cutoff = gmdate('Y-m-d H:i:s', time() - 900);
    $st = q('SELECT COUNT(*) AS n FROM login_attempts WHERE (email = ? OR ip = ?) AND success = 0 AND attempted_at > ?', [$email, $ip, $cutoff]);
    return (int)$st->fetch()['n'] < 10;
}

function record_login(string $email, string $ip, bool $ok): void {
    q('INSERT INTO login_attempts (email, ip, success, attempted_at) VALUES (?, ?, ?, ?)', [$email, $ip, $ok ? 1 : 0, now_utc()]);
}

function register_user(string $email, string $password, string $name): int {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $now = now_utc();
    return tx(function () use ($email, $hash, $name, $now): int {
        q('INSERT INTO users (email, password_hash, display_name, timezone, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
          [$email, $hash, $name, 'UTC', $now, $now]);
        $id = (int)db()->lastInsertId();
        q('INSERT INTO user_prefs (user_id, reminder_offsets) VALUES (?, ?)', [$id, json_encode([30,10,5,3])]);
        // Verification token
        $token = base64url_encode(random_bytes(32));
        $pepper = $GLOBALS['CONFIG']['token_pepper'] ?? '';
        q('INSERT INTO email_verifications (user_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?)',
          [$id, hash('sha256', $token . $pepper), gmdate('Y-m-d H:i:s', time() + 86400), $now]);
        return $id;
    });
}

function attempt_login(string $email, string $password, string $ip): array {
    if (!login_rate_ok($email, $ip)) return [false, 'Too many attempts. Try again in 15 minutes.'];
    $u = q('SELECT * FROM users WHERE email = ? AND deleted_at IS NULL', [$email])->fetch();
    $ok = $u && password_verify($password, $u['password_hash']);
    record_login($email, $ip, (bool)$ok);
    if (!$ok) return [false, 'Invalid email or password.'];
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    return [true, $u];
}

function request_password_reset(string $email): void {
    $u = q('SELECT id FROM users WHERE email = ? AND deleted_at IS NULL', [$email])->fetch();
    if (!$u) return; // silent to avoid account enumeration
    $token = base64url_encode(random_bytes(32));
    $pepper = $GLOBALS['CONFIG']['token_pepper'] ?? '';
    q('INSERT INTO password_resets (user_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?)',
      [$u['id'], hash('sha256', $token . $pepper), gmdate('Y-m-d H:i:s', time() + 3600), now_utc()]);
    $link = rtrim($GLOBALS['CONFIG']['url'], '/') . '/reset?token=' . urlencode($token);
    send_mail($email, 'Reset your ShiftGuard password', "Click this link to reset your password (valid for 1 hour):\n\n{$link}\n\nIf you did not request this, ignore this email.");
}

function consume_reset(string $token, string $new_password): bool {
    $pepper = $GLOBALS['CONFIG']['token_pepper'] ?? '';
    $h = hash('sha256', $token . $pepper);
    return tx(function () use ($h, $new_password): bool {
        $row = q('SELECT id, user_id FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?', [$h, now_utc()])->fetch();
        if (!$row) return false;
        q('UPDATE password_resets SET used_at = ? WHERE id = ?', [now_utc(), $row['id']]);
        q('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?',
          [password_hash($new_password, PASSWORD_DEFAULT), now_utc(), $row['user_id']]);
        return true;
    });
}

function verify_email(string $token): bool {
    $pepper = $GLOBALS['CONFIG']['token_pepper'] ?? '';
    $h = hash('sha256', $token . $pepper);
    return tx(function () use ($h): bool {
        $row = q('SELECT id, user_id FROM email_verifications WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?', [$h, now_utc()])->fetch();
        if (!$row) return false;
        q('UPDATE email_verifications SET used_at = ? WHERE id = ?', [now_utc(), $row['id']]);
        q('UPDATE users SET email_verified_at = ?, updated_at = ? WHERE id = ?', [now_utc(), now_utc(), $row['user_id']]);
        return true;
    });
}