<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;

function webpushRuntimeDir(): string
{
    return PROJECT_ROOT . '/messaging/runtime';
}

function webpushSubscriptionsPath(): string
{
    return webpushRuntimeDir() . '/webpush_subscriptions.json';
}

function webpushVapidPath(): string
{
    return webpushRuntimeDir() . '/vapid_keys.json';
}

function webpushEnsureRuntime(): void
{
    $dir = webpushRuntimeDir();
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    foreach ([webpushSubscriptionsPath() => '{}', webpushVapidPath() => ''] as $file => $initial) {
        if (!is_file($file)) {
            file_put_contents($file, $initial, LOCK_EX);
        }
    }
}

function webpushWithJsonStore(string $path, callable $cb)
{
    webpushEnsureRuntime();
    $fp = fopen($path, 'c+');
    if (!$fp) {
        throw new RuntimeException('Impossible d’ouvrir le store Web Push');
    }
    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException('Verrou Web Push indisponible');
        }
        rewind($fp);
        $raw = stream_get_contents($fp);
        $data = json_decode($raw ?: '{}', true);
        if (!is_array($data)) {
            $data = [];
        }
        $result = $cb($data);
        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return $result;
    } catch (Throwable $e) {
        flock($fp, LOCK_UN);
        fclose($fp);
        throw $e;
    }
}

function webpushGetVapidConfig(): array
{
    webpushEnsureRuntime();
    $path = webpushVapidPath();
    $raw = @file_get_contents($path);
    $data = json_decode($raw ?: '', true);
    if (is_array($data) && !empty($data['publicKey']) && !empty($data['privateKey']) && !empty($data['subject'])) {
        return $data;
    }

    $keys = VAPID::createVapidKeys();
    $data = [
        'subject' => 'mailto:no-reply@coredeskafrica.cloud',
        'publicKey' => $keys['publicKey'],
        'privateKey' => $keys['privateKey'],
        'created_at' => gmdate('c'),
    ];
    file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
    return $data;
}

function webpushNormalizeSubscription(array $subscription): array
{
    $endpoint = trim((string)($subscription['endpoint'] ?? ''));
    $keys = $subscription['keys'] ?? [];
    $p256dh = trim((string)($keys['p256dh'] ?? ''));
    $auth = trim((string)($keys['auth'] ?? ''));
    $contentEncoding = trim((string)($subscription['contentEncoding'] ?? 'aes128gcm'));
    if ($endpoint === '' || $p256dh === '' || $auth === '') {
        throw new InvalidArgumentException('Subscription push invalide');
    }
    if (!in_array($contentEncoding, ['aesgcm', 'aes128gcm'], true)) {
        $contentEncoding = 'aes128gcm';
    }
    return [
        'endpoint' => $endpoint,
        'keys' => [
            'p256dh' => $p256dh,
            'auth' => $auth,
        ],
        'contentEncoding' => $contentEncoding,
    ];
}

function webpushSaveSubscription(int $userId, string $username, array $subscription, string $userAgent = ''): void
{
    $normalized = webpushNormalizeSubscription($subscription);
    $now = gmdate('c');
    webpushWithJsonStore(webpushSubscriptionsPath(), function (&$data) use ($userId, $username, $normalized, $userAgent, $now) {
        $key = (string)$userId;
        $list = $data[$key]['subscriptions'] ?? [];
        $updated = false;
        foreach ($list as &$item) {
            if (($item['endpoint'] ?? '') === $normalized['endpoint']) {
                $item = array_merge($item, $normalized, [
                    'updated_at' => $now,
                    'username' => $username,
                    'user_agent' => $userAgent,
                ]);
                $updated = true;
                break;
            }
        }
        unset($item);
        if (!$updated) {
            $list[] = array_merge($normalized, [
                'created_at' => $now,
                'updated_at' => $now,
                'username' => $username,
                'user_agent' => $userAgent,
            ]);
        }
        $data[$key] = [
            'user_id' => $userId,
            'username' => $username,
            'subscriptions' => array_values($list),
            'updated_at' => $now,
        ];
    });
}

function webpushRemoveSubscription(int $userId, ?string $endpoint = null): void
{
    webpushWithJsonStore(webpushSubscriptionsPath(), function (&$data) use ($userId, $endpoint) {
        $key = (string)$userId;
        if (!isset($data[$key]['subscriptions'])) {
            return;
        }
        if ($endpoint === null || $endpoint === '') {
            unset($data[$key]);
            return;
        }
        $data[$key]['subscriptions'] = array_values(array_filter(
            $data[$key]['subscriptions'],
            static fn($sub) => ($sub['endpoint'] ?? '') !== $endpoint
        ));
        if (!$data[$key]['subscriptions']) {
            unset($data[$key]);
        }
    });
}

function webpushRemoveEndpointGlobally(string $endpoint): void
{
    webpushWithJsonStore(webpushSubscriptionsPath(), function (&$data) use ($endpoint) {
        foreach ($data as $uid => &$row) {
            $row['subscriptions'] = array_values(array_filter(
                $row['subscriptions'] ?? [],
                static fn($sub) => ($sub['endpoint'] ?? '') !== $endpoint
            ));
            if (!$row['subscriptions']) {
                unset($data[$uid]);
            }
        }
        unset($row);
    });
}

function webpushGetUserSubscriptions(array $userIds): array
{
    webpushEnsureRuntime();
    $raw = @file_get_contents(webpushSubscriptionsPath());
    $data = json_decode($raw ?: '{}', true);
    if (!is_array($data)) {
        return [];
    }
    $subs = [];
    foreach (array_unique(array_map('intval', $userIds)) as $userId) {
        $row = $data[(string)$userId]['subscriptions'] ?? [];
        foreach ($row as $sub) {
            $subs[] = $sub;
        }
    }
    return $subs;
}

function webpushSendToUsers(array $userIds, array $payload): array
{
    $subscriptions = webpushGetUserSubscriptions($userIds);
    if (!$subscriptions) {
        return ['queued' => 0, 'sent' => 0, 'failed' => 0];
    }

    $vapid = webpushGetVapidConfig();
    $webPush = new WebPush([
        'VAPID' => [
            'subject' => $vapid['subject'],
            'publicKey' => $vapid['publicKey'],
            'privateKey' => $vapid['privateKey'],
        ],
    ], [
        'TTL' => 300,
        'urgency' => 'high',
        'batchSize' => 100,
    ], 20);

    $queued = 0;
    foreach ($subscriptions as $sub) {
        try {
            $webPush->queueNotification(
                Subscription::create($sub),
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                [
                    'topic' => (string)($payload['tag'] ?? 'chat-message'),
                    'urgency' => 'high',
                    'TTL' => 300,
                ]
            );
            $queued++;
        } catch (Throwable $e) {
            error_log('[WEBPUSH QUEUE] ' . $e->getMessage());
        }
    }

    $sent = 0;
    $failed = 0;
    foreach ($webPush->flush() as $report) {
        if ($report->isSuccess()) {
            $sent++;
            continue;
        }
        $failed++;
        error_log('[WEBPUSH FAIL] ' . $report->getReason() . ' endpoint=' . $report->getEndpoint());
        if ($report->isSubscriptionExpired()) {
            webpushRemoveEndpointGlobally($report->getEndpoint());
        }
    }

    return ['queued' => $queued, 'sent' => $sent, 'failed' => $failed];
}
