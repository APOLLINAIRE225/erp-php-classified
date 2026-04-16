<?php
require_once dirname(__DIR__, 3) . '/_php_classified/bootstrap_paths.php';

session_start();

require_once APP_ROOT . '/app/core/DB.php';
require_once PROJECT_ROOT . '/messaging/app_alerts.php';

use App\Core\DB;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function app_event_out(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_event_out(['ok' => false, 'err' => 'Méthode non autorisée'], 405);
}

$message = trim((string)($_POST['message'] ?? ''));
if ($message === '') {
    app_event_out(['ok' => false, 'err' => 'Message requis'], 400);
}

$type = trim((string)($_POST['type'] ?? 'app_error'));
$source = trim((string)($_POST['source'] ?? 'android_wrapper'));
$url = trim((string)($_POST['url'] ?? project_url('messaging/messagerie.php')));
$details = trim((string)($_POST['details'] ?? ''));
$actorUserId = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$actorName = (string)($_SESSION['username'] ?? $_SESSION['employee_name'] ?? $_SESSION['client_name'] ?? 'Session inconnue');
$ip = appAlertClientIp();
$ua = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));

try {
    $pdo = DB::getConnection();
    $title = 'Erreur app Android';
    $body = mb_strimwidth(
        $message . ' · ' . $actorName . ' · ' . $ip,
        0,
        180,
        '…',
        'UTF-8'
    );

    $meta = [
        'source' => $source,
        'details' => $details,
        'ip' => $ip,
        'user_agent' => $ua,
        'actor_name' => $actorName,
        'url' => $url,
    ];

    appAlertNotifyRoles($pdo, appAlertOpsRoles(), [
        'title' => $title,
        'body' => $body,
        'url' => $url !== '' ? $url : project_url('dashboard/index.php'),
        'tag' => 'app-error-' . substr(sha1($message . $source), 0, 12),
        'unread' => 1,
    ], [
        'event_type' => $type !== '' ? $type : 'app_error',
        'event_key' => 'app-error:' . substr(sha1($message . '|' . $source . '|' . $url), 0, 40),
        'cooldown_seconds' => 300,
        'actor_user_id' => $actorUserId,
        'metadata' => $meta,
    ]);

    app_event_out(['ok' => true]);
} catch (Throwable $e) {
    error_log('[APP EVENT REPORT] ' . $e->getMessage());
    app_event_out(['ok' => false, 'err' => 'Report échoué'], 500);
}
