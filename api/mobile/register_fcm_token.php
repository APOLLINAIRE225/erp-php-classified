<?php
require_once dirname(__DIR__, 3) . '/_php_classified/bootstrap_paths.php';

session_start();

require_once APP_ROOT . '/app/core/Auth.php';
require_once APP_ROOT . '/app/core/Middleware.php';
require_once PROJECT_ROOT . '/messaging/fcm_lib.php';

use App\Core\Auth;
use App\Core\Middleware;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function out(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    out(['ok' => false, 'err' => 'Méthode non autorisée'], 405);
}

try {
    Auth::check();
    Middleware::role(['developer','admin','manager','staff','employee','Patron','PDG','Directrice','Secretaire','Superviseur','informaticien']);
} catch (Throwable $e) {
    out(['ok' => false, 'err' => 'Session requise'], 401);
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$username = (string)($_SESSION['username'] ?? 'User');
$token = trim((string)($_POST['token'] ?? ''));
$platform = trim((string)($_POST['platform'] ?? 'android_native'));

if ($userId <= 0 || $token === '') {
    out(['ok' => false, 'err' => 'Token ou session invalide'], 400);
}

try {
    fcmSaveToken($userId, $username, $token, $platform, (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    out(['ok' => true]);
} catch (Throwable $e) {
    error_log('[FCM REGISTER] ' . $e->getMessage());
    out(['ok' => false, 'err' => 'Enregistrement FCM échoué'], 500);
}
