<?php

declare(strict_types=1);

function fcmRuntimeDir(): string
{
    return PROJECT_ROOT . '/messaging/runtime';
}

function fcmTokensPath(): string
{
    return fcmRuntimeDir() . '/fcm_tokens.json';
}

function fcmServiceAccountPath(): string
{
    return fcmRuntimeDir() . '/firebase_service_account.json';
}

function fcmEnsureRuntime(): void
{
    $dir = fcmRuntimeDir();
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    if (!is_file(fcmTokensPath())) {
        file_put_contents(fcmTokensPath(), '{}', LOCK_EX);
    }
}

function fcmWithJsonStore(callable $cb)
{
    fcmEnsureRuntime();
    $path = fcmTokensPath();
    $fp = fopen($path, 'c+');
    if (!$fp) {
        throw new RuntimeException('Impossible d’ouvrir le store FCM');
    }
    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException('Verrou FCM indisponible');
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

function fcmGetServiceAccount(): array
{
    $path = fcmServiceAccountPath();
    if (!is_file($path)) {
        throw new RuntimeException('Service account Firebase manquant: messaging/runtime/firebase_service_account.json');
    }
    $data = json_decode((string)file_get_contents($path), true);
    if (!is_array($data) || empty($data['client_email']) || empty($data['private_key']) || empty($data['project_id'])) {
        throw new RuntimeException('Service account Firebase invalide');
    }
    $data['token_uri'] = $data['token_uri'] ?? 'https://oauth2.googleapis.com/token';
    return $data;
}

function fcmBase64UrlEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function fcmCreateJwt(array $sa): string
{
    $now = time();
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $payload = [
        'iss' => $sa['client_email'],
        'sub' => $sa['client_email'],
        'aud' => $sa['token_uri'],
        'iat' => $now,
        'exp' => $now + 3600,
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
    ];
    $segments = [
        fcmBase64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES)),
        fcmBase64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES)),
    ];
    $signingInput = implode('.', $segments);
    $signature = '';
    $ok = openssl_sign($signingInput, $signature, $sa['private_key'], OPENSSL_ALGO_SHA256);
    if (!$ok) {
        throw new RuntimeException('Signature JWT Firebase impossible');
    }
    $segments[] = fcmBase64UrlEncode($signature);
    return implode('.', $segments);
}

function fcmGetAccessToken(): array
{
    $sa = fcmGetServiceAccount();
    $jwt = fcmCreateJwt($sa);
    $ch = curl_init($sa['token_uri']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]),
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $code >= 400) {
        throw new RuntimeException('OAuth Firebase échoué: ' . ($err ?: $raw ?: ('HTTP ' . $code)));
    }
    $json = json_decode($raw, true);
    if (!is_array($json) || empty($json['access_token'])) {
        throw new RuntimeException('Réponse OAuth Firebase invalide');
    }
    return [
        'access_token' => $json['access_token'],
        'project_id' => $sa['project_id'],
    ];
}

function fcmSaveToken(int $userId, string $username, string $token, string $platform, string $userAgent = ''): void
{
    $token = trim($token);
    if ($token === '') {
        throw new InvalidArgumentException('Token FCM vide');
    }
    $now = gmdate('c');
    fcmWithJsonStore(function (&$data) use ($userId, $username, $token, $platform, $userAgent, $now) {
        $key = (string)$userId;
        $list = $data[$key]['tokens'] ?? [];
        $updated = false;
        foreach ($list as &$item) {
            if (($item['token'] ?? '') === $token) {
                $item['updated_at'] = $now;
                $item['platform'] = $platform;
                $item['user_agent'] = $userAgent;
                $updated = true;
                break;
            }
        }
        unset($item);
        if (!$updated) {
            $list[] = [
                'token' => $token,
                'platform' => $platform,
                'user_agent' => $userAgent,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $data[$key] = [
            'user_id' => $userId,
            'username' => $username,
            'tokens' => array_values($list),
            'updated_at' => $now,
        ];
    });
}

function fcmRemoveToken(int $userId, ?string $token = null): void
{
    fcmWithJsonStore(function (&$data) use ($userId, $token) {
        $key = (string)$userId;
        if (!isset($data[$key]['tokens'])) {
            return;
        }
        if ($token === null || $token === '') {
            unset($data[$key]);
            return;
        }
        $data[$key]['tokens'] = array_values(array_filter(
            $data[$key]['tokens'],
            static fn($row) => ($row['token'] ?? '') !== $token
        ));
        if (!$data[$key]['tokens']) {
            unset($data[$key]);
        }
    });
}

function fcmRemoveTokenGlobally(string $token): void
{
    fcmWithJsonStore(function (&$data) use ($token) {
        foreach ($data as $uid => &$row) {
            $row['tokens'] = array_values(array_filter(
                $row['tokens'] ?? [],
                static fn($item) => ($item['token'] ?? '') !== $token
            ));
            if (!$row['tokens']) {
                unset($data[$uid]);
            }
        }
        unset($row);
    });
}

function fcmGetUserTokens(array $userIds): array
{
    fcmEnsureRuntime();
    $data = json_decode((string)file_get_contents(fcmTokensPath()), true);
    if (!is_array($data)) {
        return [];
    }
    $tokens = [];
    foreach (array_unique(array_map('intval', $userIds)) as $userId) {
        foreach (($data[(string)$userId]['tokens'] ?? []) as $row) {
            if (!empty($row['token'])) {
                $tokens[] = $row['token'];
            }
        }
    }
    return array_values(array_unique($tokens));
}

function fcmSendToUsers(array $userIds, array $payload): array
{
    $tokens = fcmGetUserTokens($userIds);
    if (!$tokens) {
        return ['queued' => 0, 'sent' => 0, 'failed' => 0];
    }

    $auth = fcmGetAccessToken();
    $url = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode($auth['project_id']) . '/messages:send';
    $sent = 0;
    $failed = 0;

    foreach ($tokens as $token) {
        $body = [
            'message' => [
                'token' => $token,
                'notification' => [
                    'title' => (string)($payload['title'] ?? 'Nouveau message'),
                    'body' => (string)($payload['body'] ?? 'Ouvrir la messagerie'),
                ],
                'data' => array_map('strval', array_filter([
                    'title' => $payload['title'] ?? 'Nouveau message',
                    'body' => $payload['body'] ?? 'Ouvrir la messagerie',
                    'url' => $payload['url'] ?? '',
                    'tag' => $payload['tag'] ?? 'chat-message',
                    'unread' => (string)($payload['unread'] ?? 1),
                    'conversation_type' => $payload['conversation']['type'] ?? '',
                    'conversation_id' => isset($payload['conversation']['id']) ? (string)$payload['conversation']['id'] : '',
                ], static fn($v) => $v !== '')),
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
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_TIMEOUT => 20,
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw !== false && $code >= 200 && $code < 300) {
            $sent++;
            continue;
        }

        $failed++;
        $reason = $err ?: ($raw ?: ('HTTP ' . $code));
        error_log('[FCM FAIL] token=' . substr($token, 0, 20) . '... ' . $reason);
        if (str_contains($reason, 'UNREGISTERED') || str_contains($reason, 'registration-token-not-registered')) {
            fcmRemoveTokenGlobally($token);
        }
    }

    return ['queued' => count($tokens), 'sent' => $sent, 'failed' => $failed];
}
