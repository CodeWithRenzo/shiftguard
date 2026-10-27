<?php
declare(strict_types=1);

/* ---------- Escaping / time ---------- */

function e(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now_utc(): string {
    return gmdate('Y-m-d H:i:s');
}

function utc_to_user(?string $utc, string $tz): ?DateTimeImmutable {
    if ($utc === null) return null;
    try {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone($tz));
    } catch (Throwable) {
        return null;
    }
}

function user_now(string $tz): DateTimeImmutable {
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone($tz));
}

/* ---------- CSRF ---------- */

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(?string $token): void {
    $session = $_SESSION['csrf'] ?? '';
    if ($session === '' || !is_string($token) || !hash_equals($session, $token)) {
        http_response_code(419);
        exit('CSRF token mismatch');
    }
}

/* ---------- Auth helper ---------- */

function require_auth(): array {
    $u = current_user();
    if (!$u) {
        header('Location: /login');
        exit;
    }
    return $u;
}

/* ---------- Navigation / flash ---------- */

function redirect(string $path): never {
    header('Location: ' . $path);
    exit;
}

function flash_set(string $kind, string $msg): void {
    $_SESSION['flash'][] = ['kind' => $kind, 'msg' => $msg];
}

function flash_pull(): array {
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------- Input / output ---------- */

function json_out(mixed $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function post_str(string $key, string $default = ''): string {
    return isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : $default;
}

function valid_email(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/* ---------- Formatting ---------- */

function fmt_hms(int $seconds): string {
    $sign = $seconds < 0 ? '-' : '';
    $seconds = abs($seconds);
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    if ($h > 0) {
        return sprintf('%s%d:%02d:%02d', $sign, $h, $m, $s);
    }
    return sprintf('%s%d:%02d', $sign, $m, $s);
}

function fmt_hm(int $seconds): string {
    $seconds = abs($seconds);
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    if ($h === 0) return "{$m} min";
    return "{$h} h {$m} min";
}

function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function security_headers(): void {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; manifest-src 'self'; worker-src 'self'");
}

function log_err(string $msg): void {
    error_log('[shiftguard] ' . $msg);
}

/* ---------- View rendering ---------- */

function render(string $view, array $vars = []): string {
    extract($vars, EXTR_SKIP);
    ob_start();
    require SG_APP . '/views/' . $view . '.php';
    return ob_get_clean();
}

function page(string $title, string $body, ?array $user = null, ?array $prefs = null): never {
    echo render('layout', [
        'title' => $title,
        'body'  => $body,
        'user'  => $user,
        'prefs' => $prefs ?? ['theme' => 'auto'],
    ]);
    exit;
}