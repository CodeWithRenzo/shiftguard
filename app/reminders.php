<?php
declare(strict_types=1);

function schedule_shift_reminders(int $shift_id, int $user_id, int $client_id, string $scheduled_end_utc, array $offsets, bool $email_on, bool $push_on): void {
    $end_ts = strtotime($scheduled_end_utc . ' UTC');
    $now = time();
    $c = q('SELECT * FROM clients WHERE id = ? AND user_id = ?', [$client_id, $user_id])->fetch();
    if (!$c) return;

    foreach ($offsets as $off) {
        $off = (int)$off;
        if ($off < 1) continue;
        $fire_ts = $end_ts - $off * 60;
        // Skip past reminders (documented late-reminder policy: never send a stale "10 min remaining" message)
        if ($fire_ts <= $now + 5) continue;

        if ($email_on && $c['active']) {
            $dedupe = "email:{$shift_id}:{$off}";
            q('INSERT IGNORE INTO notification_jobs (user_id, shift_id, channel, fire_at, status, dedupe_key, payload, created_at)
               VALUES (?, ?, "email", ?, "queued", ?, ?, ?)',
              [$user_id, $shift_id, gmdate('Y-m-d H:i:s', $fire_ts), $dedupe,
               json_encode(['client_id' => $client_id, 'offset_minutes' => $off]), now_utc()]);
        }
        if ($push_on) {
            $dedupe = "push:{$shift_id}:{$off}";
            q('INSERT IGNORE INTO notification_jobs (user_id, shift_id, channel, fire_at, status, dedupe_key, payload, created_at)
               VALUES (?, ?, "push", ?, "queued", ?, ?, ?)',
              [$user_id, $shift_id, gmdate('Y-m-d H:i:s', $fire_ts), $dedupe,
               json_encode(['offset_minutes' => $off]), now_utc()]);
        }
    }
    // Overtime-acknowledged push when scheduled end hit — schedule once at scheduled end
    if ($push_on) {
        $dedupe = "push:{$shift_id}:end";
        q('INSERT IGNORE INTO notification_jobs (user_id, shift_id, channel, fire_at, status, dedupe_key, payload, created_at)
           VALUES (?, ?, "push", ?, "queued", ?, ?, ?)',
          [$user_id, $shift_id, gmdate('Y-m-d H:i:s', max($now + 5, $end_ts)), $dedupe,
           json_encode(['kind' => 'shift_over']), now_utc()]);
    }
}

function render_email_body(array $client, array $shift, array $payload, string $user_tz): array {
    $end_dt = utc_to_user($shift['scheduled_end'], $user_tz);
    $end_local = $end_dt ? $end_dt->format('Y-m-d H:i T') : $shift['scheduled_end'] . ' UTC';
    $off = (int)($payload['offset_minutes'] ?? 0);
    $vars = [
        '{{client_name}}'  => $client['name'],
        '{{user_name}}'    => $GLOBALS['CURRENT_USER_NAME'] ?? '',
        '{{scheduled_end}}'=> $end_local,
        '{{minutes_left}}' => (string)$off,
    ];
    $subject = strtr($client['subject_tpl'] ?: 'Upcoming End of Shift Reminder', $vars);
    $body = strtr($client['body_tpl'] ?: "Hello,\n\nThis is a courtesy reminder that the scheduled shift is approaching its end time.\n\nScheduled end: {{scheduled_end}}\nTime remaining: Approximately {{minutes_left}} minutes.\n\nPlease coordinate any remaining tasks or handover requirements as needed.\n\nThank you.", $vars);
    return [$subject, $body];
}