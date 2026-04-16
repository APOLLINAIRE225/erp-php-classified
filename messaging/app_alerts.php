<?php

declare(strict_types=1);

require_once __DIR__ . '/webpush_lib.php';
require_once __DIR__ . '/fcm_lib.php';

function appAlertOpsRoles(): array
{
    return ['admin', 'developer', 'informaticien'];
}

function appAlertHrRoles(): array
{
    return ['admin', 'developer', 'informaticien', 'manager', 'Superviseur', 'Directrice'];
}

function appAlertOrderRoles(): array
{
    return ['admin', 'developer', 'manager', 'informaticien', 'Patron', 'PDG', 'Directrice', 'Superviseur'];
}

function appAlertEnsureTables(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS app_alert_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_type VARCHAR(80) NOT NULL DEFAULT 'generic',
            event_key VARCHAR(190) NULL,
            title VARCHAR(255) NOT NULL,
            body TEXT NOT NULL,
            target_roles VARCHAR(255) NOT NULL DEFAULT '',
            target_user_ids TEXT NULL,
            target_url VARCHAR(255) NULL,
            actor_user_id INT NULL,
            payload_json MEDIUMTEXT NULL,
            sent_webpush INT NOT NULL DEFAULT 0,
            sent_fcm INT NOT NULL DEFAULT 0,
            failed_webpush INT NOT NULL DEFAULT 0,
            failed_fcm INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_event_type_created (event_type, created_at),
            INDEX idx_event_key (event_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS app_alert_state (
            event_key VARCHAR(190) PRIMARY KEY,
            payload_hash CHAR(64) NOT NULL DEFAULT '',
            last_sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $done = true;
}

function appAlertClientIp(): string
{
    $raw = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $parts = array_map('trim', explode(',', $raw));
    return (string)($parts[0] ?? '0.0.0.0');
}

function appAlertNormalizeRoles(array $roles): array
{
    $clean = [];
    foreach ($roles as $role) {
        $role = trim((string)$role);
        if ($role !== '') {
            $clean[$role] = true;
        }
    }
    return array_keys($clean);
}

function appAlertFindUserIdsByRoles(PDO $pdo, array $roles): array
{
    $roles = appAlertNormalizeRoles($roles);
    if (!$roles) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($roles), '?'));
    $st = $pdo->prepare("SELECT id FROM users WHERE role IN ($placeholders)");
    $st->execute($roles);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

function appAlertShouldSend(PDO $pdo, string $eventKey, string $payloadHash, int $cooldownSeconds): bool
{
    if ($eventKey === '') {
        return true;
    }

    appAlertEnsureTables($pdo);
    $st = $pdo->prepare("SELECT payload_hash, last_sent_at FROM app_alert_state WHERE event_key=? LIMIT 1");
    $st->execute([$eventKey]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $pdo->prepare("INSERT INTO app_alert_state(event_key,payload_hash,last_sent_at) VALUES(?,?,NOW())")
            ->execute([$eventKey, $payloadHash]);
        return true;
    }

    $samePayload = hash_equals((string)($row['payload_hash'] ?? ''), $payloadHash);
    $lastTs = strtotime((string)($row['last_sent_at'] ?? '')) ?: 0;
    $age = time() - $lastTs;
    if ($samePayload && $cooldownSeconds > 0 && $age < $cooldownSeconds) {
        return false;
    }

    $pdo->prepare("UPDATE app_alert_state SET payload_hash=?, last_sent_at=NOW() WHERE event_key=?")
        ->execute([$payloadHash, $eventKey]);
    return true;
}

function appAlertDispatchToUsers(array $userIds, array $payload, string $context): array
{
    $stats = [
        'webpush' => ['queued' => 0, 'sent' => 0, 'failed' => 0],
        'fcm' => ['queued' => 0, 'sent' => 0, 'failed' => 0],
    ];

    if (!$userIds) {
        return $stats;
    }

    try {
        $stats['webpush'] = webpushSendToUsers($userIds, $payload);
    } catch (Throwable $e) {
        error_log('[APP ALERT WEBPUSH ' . $context . '] ' . $e->getMessage());
    }

    try {
        $stats['fcm'] = fcmSendToUsers($userIds, $payload);
    } catch (Throwable $e) {
        error_log('[APP ALERT FCM ' . $context . '] ' . $e->getMessage());
    }

    return $stats;
}

function appAlertNotifyRoles(PDO $pdo, array $roles, array $payload, array $options = []): array
{
    appAlertEnsureTables($pdo);

    $roles = appAlertNormalizeRoles($roles);
    $userIds = appAlertFindUserIdsByRoles($pdo, $roles);
    $exclude = array_map('intval', $options['exclude_user_ids'] ?? []);
    if ($exclude) {
        $userIds = array_values(array_diff($userIds, $exclude));
    }

    $normalizedPayload = [
        'title' => trim((string)($payload['title'] ?? 'Notification ERP')),
        'body' => trim((string)($payload['body'] ?? 'Nouvel événement métier')),
        'url' => trim((string)($payload['url'] ?? project_url('dashboard/index.php'))),
        'tag' => trim((string)($payload['tag'] ?? 'erp-alert')),
        'unread' => (int)($payload['unread'] ?? 1),
    ];

    if (!empty($payload['conversation']) && is_array($payload['conversation'])) {
        $normalizedPayload['conversation'] = $payload['conversation'];
    }

    $eventKey = trim((string)($options['event_key'] ?? ''));
    $metadata = $options;
    unset(
        $metadata['exclude_user_ids'],
        $metadata['event_key'],
        $metadata['cooldown_seconds'],
        $metadata['event_type'],
        $metadata['actor_user_id']
    );

    $payloadHash = hash('sha256', json_encode([$roles, $normalizedPayload, $metadata], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $cooldownSeconds = max(0, (int)($options['cooldown_seconds'] ?? 0));
    if (!appAlertShouldSend($pdo, $eventKey, $payloadHash, $cooldownSeconds)) {
        return ['ok' => true, 'skipped' => true, 'reason' => 'cooldown'];
    }

    $stats = appAlertDispatchToUsers($userIds, $normalizedPayload, (string)($options['event_type'] ?? 'generic'));

    $pdo->prepare("
        INSERT INTO app_alert_logs(
            event_type,event_key,title,body,target_roles,target_user_ids,target_url,
            actor_user_id,payload_json,sent_webpush,sent_fcm,failed_webpush,failed_fcm
        ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)
    ")->execute([
        (string)($options['event_type'] ?? 'generic'),
        $eventKey !== '' ? $eventKey : null,
        $normalizedPayload['title'],
        $normalizedPayload['body'],
        implode(',', $roles),
        implode(',', $userIds),
        $normalizedPayload['url'],
        isset($options['actor_user_id']) ? (int)$options['actor_user_id'] : null,
        json_encode([
            'payload' => $normalizedPayload,
            'meta' => $metadata,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        (int)($stats['webpush']['sent'] ?? 0),
        (int)($stats['fcm']['sent'] ?? 0),
        (int)($stats['webpush']['failed'] ?? 0),
        (int)($stats['fcm']['failed'] ?? 0),
    ]);

    return ['ok' => true, 'skipped' => false, 'user_ids' => $userIds, 'stats' => $stats];
}
