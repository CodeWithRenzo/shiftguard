<?php
declare(strict_types=1);

function shift_active_for(int $user_id): ?array {
    $row = q('SELECT * FROM shifts WHERE user_id = ? AND status = "active" ORDER BY id DESC LIMIT 1', [$user_id])->fetch();
    return $row ?: null;
}

function shift_break_open(int $shift_id): ?array {
    $row = q('SELECT * FROM breaks WHERE shift_id = ? AND ended_at IS NULL ORDER BY id DESC LIMIT 1', [$shift_id])->fetch();
    return $row ?: null;
}

function shift_break_total(int $shift_id, ?string $cutoff_utc = null): int {
    $rows = q('SELECT started_at, ended_at FROM breaks WHERE shift_id = ?', [$shift_id])->fetchAll();
    $now = $cutoff_utc ?? now_utc();
    $total = 0;
    foreach ($rows as $r) {
        $s = strtotime($r['started_at'] . ' UTC');
        $e = $r['ended_at'] ? strtotime($r['ended_at'] . ' UTC') : strtotime($now . ' UTC');
        if ($e > $s) $total += $e - $s;
    }
    return $total;
}

function shift_start(int $user_id, ?int $client_id, ?int $override_minutes, ?array $override_offsets): array {
    $prefs = user_prefs($user_id);
    $minutes = $override_minutes ?? (int)$prefs['shift_minutes'];
    if ($minutes < 1 || $minutes > 24 * 60) $minutes = 540;

    return tx(function () use ($user_id, $client_id, $minutes, $prefs, $override_offsets): array {
        if (shift_active_for($user_id)) throw new RuntimeException('A shift is already active.');
        $now = now_utc();
        $end = gmdate('Y-m-d H:i:s', strtotime($now . ' UTC') + $minutes * 60);
        q('INSERT INTO shifts (user_id, client_id, started_at, scheduled_end, scheduled_minutes, break_minutes, break_paid, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, "active", ?, ?)',
          [$user_id, $client_id, $now, $end, $minutes, (int)$prefs['break_minutes'], (int)$prefs['break_paid'], $now, $now]);
        $shift_id = (int)db()->lastInsertId();

        // Schedule reminder jobs from user's default offsets (or overrides for this shift)
        $offsets = $override_offsets ?? $prefs['reminder_offsets'];
        if ($client_id) schedule_shift_reminders($shift_id, $user_id, $client_id, $end, $offsets, (bool)$prefs['email_enabled'], (bool)$prefs['push_enabled']);

        // In-app "shift started" notification
        add_inapp($user_id, 'shift_started', 'Shift started', 'Scheduled end: ' . $end . ' UTC', 'shift', $shift_id);

        return ['id' => $shift_id, 'started_at' => $now, 'scheduled_end' => $end, 'scheduled_minutes' => $minutes];
    });
}

function shift_end(int $user_id, int $shift_id, ?string $override_end_utc = null, string $reason = ''): array {
    return tx(function () use ($user_id, $shift_id, $override_end_utc, $reason): array {
        $s = q('SELECT * FROM shifts WHERE id = ? AND user_id = ? AND status = "active"', [$shift_id, $user_id])->fetch();
        if (!$s) throw new RuntimeException('Active shift not found.');
        $now = now_utc();
        $ended = $override_end_utc ?? $now;

        // Close any open break at the ending timestamp
        q('UPDATE breaks SET ended_at = ? WHERE shift_id = ? AND ended_at IS NULL', [$ended, $shift_id]);

        $started = strtotime($s['started_at'] . ' UTC');
        $sched = strtotime($s['scheduled_end'] . ' UTC');
        $end_ts = strtotime($ended . ' UTC');
        $elapsed = max(0, $end_ts - $started);
        $break_total = shift_break_total($shift_id, $ended);

        q('UPDATE shifts SET ended_at = ?, status = ?, updated_at = ?, notes = ? WHERE id = ?',
          [$ended, $override_end_utc ? 'adjusted' : 'completed', $now, $reason, $shift_id]);

        // Cancel remaining queued jobs
        q('UPDATE notification_jobs SET status = "cancelled" WHERE shift_id = ? AND status = "queued"', [$shift_id]);

        // Audit
        $before = json_encode(['ended_at' => $s['ended_at'], 'status' => $s['status']]);
        $after = json_encode(['ended_at' => $ended, 'status' => 'completed']);
        q('INSERT INTO audit_logs (user_id, entity, entity_id, action, before_json, after_json, reason, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
          [$user_id, 'shift', $shift_id, $override_end_utc ? 'adjusted_end' : 'clock_out', $before, $after, $reason, $now]);

        $overtime = max(0, $end_ts - $sched);
        add_inapp($user_id, 'shift_completed', 'Shift completed',
            'Duration ' . fmt_hm($elapsed) . ($overtime > 0 ? ' • Overtime ' . fmt_hm($overtime) : ''),
            'shift', $shift_id);

        return [
            'shift_id' => $shift_id, 'ended_at' => $ended,
            'elapsed_seconds' => $elapsed, 'break_seconds' => $break_total,
            'overtime_seconds' => $overtime,
            'net_seconds' => max(0, $elapsed - ($s['break_paid'] ? 0 : $break_total)),
        ];
    });
}

function shift_acknowledge_overtime(int $user_id, int $shift_id): void {
    $now = now_utc();
    q('UPDATE shifts SET overtime_ack_at = COALESCE(overtime_ack_at, ?), updated_at = ? WHERE id = ? AND user_id = ? AND status = "active"',
      [$now, $now, $shift_id, $user_id]);
}

function shift_break_start(int $user_id, int $shift_id): int {
    return tx(function () use ($user_id, $shift_id): int {
        $s = q('SELECT id FROM shifts WHERE id = ? AND user_id = ? AND status = "active"', [$shift_id, $user_id])->fetch();
        if (!$s) throw new RuntimeException('No active shift.');
        if (shift_break_open($shift_id)) throw new RuntimeException('A break is already running.');
        q('INSERT INTO breaks (shift_id, user_id, started_at) VALUES (?, ?, ?)', [$shift_id, $user_id, now_utc()]);
        return (int)db()->lastInsertId();
    });
}

function shift_break_end(int $user_id, int $shift_id): void {
    q('UPDATE breaks SET ended_at = ? WHERE shift_id = ? AND user_id = ? AND ended_at IS NULL', [now_utc(), $shift_id, $user_id]);
}

function shift_summary(int $user_id, string $tz): array {
    $row = shift_active_for($user_id);
    if (!$row) return ['active' => false];
    $started = strtotime($row['started_at'] . ' UTC');
    $sched = strtotime($row['scheduled_end'] . ' UTC');
    $now = time();
    $break_open = shift_break_open((int)$row['id']);
    $break_total = shift_break_total((int)$row['id']);
    return [
        'active'            => true,
        'id'                => (int)$row['id'],
        'started_at'        => $row['started_at'],
        'scheduled_end'     => $row['scheduled_end'],
        'scheduled_minutes' => (int)$row['scheduled_minutes'],
        'break_paid'        => (bool)$row['break_paid'],
        'break_minutes'     => (int)$row['break_minutes'],
        'overtime_ack_at'   => $row['overtime_ack_at'],
        'elapsed_seconds'   => max(0, $now - $started),
        'remaining_seconds' => $sched - $now,           // negative => overtime
        'overtime_seconds'  => max(0, $now - $sched),
        'break_running'     => (bool)$break_open,
        'break_total_seconds' => $break_total,
        'client_id'         => $row['client_id'] ? (int)$row['client_id'] : null,
        'state'             => $now >= $sched ? 'over' : ($sched - $now <= 1800 ? 'ending_soon' : 'on'),
    ];
}

function add_inapp(int $user_id, string $kind, string $title, string $body = '', ?string $ref_type = null, ?int $ref_id = null): void {
    q('INSERT INTO in_app_notifications (user_id, kind, title, body, ref_type, ref_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
      [$user_id, $kind, $title, $body, $ref_type, $ref_id, now_utc()]);
}