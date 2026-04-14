<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';
require_once __DIR__ . '/fcm_lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$token = trim((string)($argv[1] ?? ''));
$title = trim((string)($argv[2] ?? 'Test FCM'));
$body = trim((string)($argv[3] ?? 'Notification FCM de test'));

if ($token === '') {
    fwrite(STDERR, "Usage: php messaging/test_fcm_send.php <fcm_token> [title] [body]\n");
    exit(1);
}

try {
    $auth = fcmGetAccessToken();
    $url = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode($auth['project_id']) . '/messages:send';
    $payload = [
        'message' => [
            'token' => $token,
            'notification' => [
                'title' => $title,
                'body' => $body,
            ],
            'data' => [
                'title' => $title,
                'body' => $body,
                'url' => project_url('messaging/messagerie.php'),
                'tag' => 'manual-fcm-test',
                'unread' => '1',
            ],
            'android' => [
                'priority' => 'high',
                'notification' => [
                    'channel_id' => 'messages_high_priority',
                    'sound' => 'default',
                    'default_sound' => true,
                    'default_vibrate_timings' => true,
                    'notification_priority' => 'PRIORITY_MAX',
                    'visibility' => 'PUBLIC',
                ],
            ],
        ],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $auth['access_token'],
            'Content-Type: application/json; charset=utf-8',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $code >= 400) {
        fwrite(STDERR, "FCM failed: " . ($err ?: $raw ?: ('HTTP ' . $code)) . "\n");
        exit(2);
    }

    echo $raw . "\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(3);
}
