<?php
declare(strict_types=1);

function push_configured(): bool {
    global $CONFIG;
    return !empty($CONFIG['vapid']['public']) && !empty($CONFIG['vapid']['private']);
}

function push_send_to_user(int $user_id, array $payload): array {
    global $CONFIG;
    if (!push_configured()) return ['ok' => false, 'info' => 'VAPID keys missing'];
    if (!class_exists(\Minishlink\WebPush\WebPush::class)) return ['ok' => false, 'info' => 'web-push lib missing'];

    $subs = q('SELECT * FROM push_subscriptions WHERE user_id = ?', [$user_id])->fetchAll();
    if (!$subs) return ['ok' => false, 'info' => 'No push subscriptions'];

    $auth = [
        'VAPID' => [
            'subject'    => $CONFIG['vapid']['subject'] ?? 'mailto:admin@example.com',
            'publicKey'  => $CONFIG['vapid']['public'],
            'privateKey' => $CONFIG['vapid']['private'],
        ],
    ];
    $webPush = new \Minishlink\WebPush\WebPush($auth);
    foreach ($subs as $s) {
        $sub = \Minishlink\WebPush\Subscription::create([
            'endpoint' => $s['endpoint'],
            'publicKey' => $s['p256dh'],
            'authToken' => $s['auth'],
        ]);
        $webPush->queueNotification($sub, json_encode($payload));
    }
    $ok = 0; $errs = [];
    foreach ($webPush->flush() as $report) {
        if ($report->isSuccess()) $ok++;
        else {
            $errs[] = $report->getReason();
            // Clean up expired endpoints
            $ep = $report->getEndpoint();
            if ($report->isSubscriptionExpired()) {
                q('DELETE FROM push_subscriptions WHERE endpoint = ?', [$ep]);
            }
        }
    }
    return ['ok' => $ok > 0, 'info' => "sent {$ok}/{$webPush->count()}" . ($errs ? '; ' . implode(' | ', array_slice($errs, 0, 3)) : '')];
}