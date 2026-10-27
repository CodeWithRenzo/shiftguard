<?php
declare(strict_types=1);
/**
 * ShiftGuard notification worker.
 *
 * Schedule via cron (recommended):
 *   * * * * * /usr/bin/php /var/www/shiftguard/bin/worker.php >> /var/log/shiftguard-worker.log 2>&1
 *
 * Or via HTTP (only if PHP CLI is unavailable):
 *   * * * * * curl -fsS "https://shift.example.com/bin/worker.php?token=YOUR_CRON_TOKEN" >/dev/null
 *
 * If invoked over HTTP, the ?token= must match config['cron_token'].
 */

require dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    $expected = $GLOBALS['CONFIG']['cron_token'] ?? '';
    if (!$expected || ($_GET['token'] ?? '') !== $expected) {
        http_response_code(403);
        exit('forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$now    = now_utc();
$due    = q("SELECT * FROM notification_jobs
             WHERE status = 'queued' AND fire_at <= ?
             ORDER BY fire_at LIMIT 25", [$now])->fetchAll();

$processed = 0;

foreach ($due as $job) {
    // Atomic claim — prevents double-send from concurrent cron invocations
    $claimed = q("UPDATE notification_jobs
                  SET status = 'sending'
                  WHERE id = ? AND status = 'queued'", [$job['id']])->rowCount();
    if (!$claimed) continue;

    // If the shift already ended, cancel — never send a stale reminder
    $shift = q('SELECT * FROM shifts WHERE id = ?', [$job['shift_id']])->fetch();
    if (!$shift || $shift['ended_at']) {
        q("UPDATE notification_jobs SET status = 'cancelled', last_error = 'shift ended' WHERE id = ?",
          [$job['id']]);
        continue;
    }

    $payload = json_decode($job['payload'], true) ?: [];
    $result  = ['ok' => false, 'info' => 'unknown'];

    if ($job['channel'] === 'email') {
        $client = q('SELECT * FROM clients WHERE id = ? AND user_id = ?',
                    [$payload['client_id'] ?? 0, $job['user_id']])->fetch();
        $user = q('SELECT * FROM users WHERE id = ?', [$job['user_id']])->fetch();

        if (!$client || !$user) {
            q("UPDATE notification_jobs SET status = 'cancelled', last_error = 'client/user missing' WHERE id = ?",
              [$job['id']]);
            continue;
        }

        $GLOBALS['CURRENT_USER_NAME'] = $user['display_name'];
        [$subject, $body] = render_email_body($client, $shift, $payload, $user['timezone'] ?: 'UTC');
        $alts = array_filter(array_map('trim', explode(',', $client['alt_emails'] ?? '')));

        $result = send_mail($client['email'], $subject, $body, $alts);
        q('INSERT INTO email_attempts (job_id, attempted_at, ok, info) VALUES (?, ?, ?, ?)',
          [$job['id'], now_utc(), $result['ok'] ? 1 : 0, substr($result['info'], 0, 500)]);

    } elseif ($job['channel'] === 'push') {
        $offset = (int)($payload['offset_minutes'] ?? 0);
        $isEnd  = ($payload['kind'] ?? '') === 'shift_over';
        $title  = $isEnd ? 'Shift over' : "Shift ends in {$offset} minutes";
        $body   = $isEnd
            ? 'Scheduled end reached. Overtime is being recorded.'
            : 'Courtesy reminder for your shift.';

        $result = push_send_to_user((int)$job['user_id'], [
            'title' => $title, 'body' => $body, 'url' => '/',
        ]);
    }

    $attempts = (int)$job['attempts'] + 1;

    if ($result['ok']) {
        q("UPDATE notification_jobs
           SET status = 'sent', sent_at = ?, attempts = ?, last_error = NULL
           WHERE id = ?",
          [now_utc(), $attempts, $job['id']]);
    } else {
        $newStatus = $attempts >= 5 ? 'failed' : 'queued';
        $nextFire  = gmdate('Y-m-d H:i:s', time() + min(900, 60 * (2 ** $attempts)));

        q("UPDATE notification_jobs
           SET status = ?, attempts = ?, last_error = ?,
               fire_at = IF(? = 'queued', ?, fire_at)
           WHERE id = ?",
          [$newStatus, $attempts, substr($result['info'], 0, 500), $newStatus, $nextFire, $job['id']]);

        if ($newStatus === 'failed') {
            add_inapp(
                (int)$job['user_id'],
                'delivery_failed',
                'Delivery failed',
                substr($result['info'], 0, 400),
                'shift',
                (int)$job['shift_id']
            );
        }
    }

    $processed++;
}

// Cancel queued jobs belonging to shifts that have ended
q("UPDATE notification_jobs j
   SET j.status = 'cancelled', j.last_error = 'shift ended'
   WHERE j.status = 'queued'
     AND EXISTS (SELECT 1 FROM shifts s WHERE s.id = j.shift_id AND s.ended_at IS NOT NULL)");

// Emit the shift-over in-app notification once per active shift
$overShifts = q("SELECT s.* FROM shifts s
                 WHERE s.status = 'active' AND s.scheduled_end <= ?
                   AND NOT EXISTS (
                       SELECT 1 FROM in_app_notifications n
                       WHERE n.ref_type = 'shift' AND n.ref_id = s.id AND n.kind = 'shift_over'
                   )", [$now])->fetchAll();

foreach ($overShifts as $s) {
    add_inapp(
        (int)$s['user_id'],
        'shift_over',
        'Shift Over',
        'Scheduled end was reached. Overtime is being recorded.',
        'shift',
        (int)$s['id']
    );
}

// Recover stale "sending" rows from a crashed worker
q("UPDATE notification_jobs
   SET status = 'queued', last_error = 'recovered from stale sending'
   WHERE status = 'sending'
     AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE)");

echo '[' . now_utc() . '] worker complete. due=' . count($due) . ' processed=' . $processed . "\n";