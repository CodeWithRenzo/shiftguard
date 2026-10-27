<?php
declare(strict_types=1);

function smtp_configured(): bool {
    global $CONFIG;
    return !empty($CONFIG['smtp']['host']) && !empty($CONFIG['smtp']['from']);
}

function send_mail(string $to, string $subject, string $body, array $extra_to = []): array {
    global $CONFIG;
    if (!smtp_configured()) return ['ok' => false, 'info' => 'SMTP not configured'];
    if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) return ['ok' => false, 'info' => 'PHPMailer missing'];
    $m = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $m->isSMTP();
        $m->Host = $CONFIG['smtp']['host'];
        $m->Port = (int)($CONFIG['smtp']['port'] ?? 587);
        $m->SMTPAuth = !empty($CONFIG['smtp']['user']);
        $m->Username = $CONFIG['smtp']['user'] ?? '';
        $m->Password = $CONFIG['smtp']['pass'] ?? '';
        $m->SMTPSecure = $CONFIG['smtp']['secure'] ?: false;
        $m->CharSet = 'UTF-8';
        $m->setFrom($CONFIG['smtp']['from'], $CONFIG['smtp']['from_name'] ?? 'ShiftGuard');
        $m->addAddress($to);
        foreach ($extra_to as $e) if (valid_email($e)) $m->addAddress($e);
        $m->Subject = $subject;
        $m->Body = $body;
        $m->send();
        return ['ok' => true, 'info' => 'accepted by SMTP'];
    } catch (\Throwable $e) {
        log_err('SMTP: ' . $e->getMessage());
        return ['ok' => false, 'info' => 'SMTP error: ' . substr($e->getMessage(), 0, 200)];
    }
}