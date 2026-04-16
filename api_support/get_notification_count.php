<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

/****************************************************************
* API - Compteur de notifications
* Endpoint pour l'auto-refresh du widget notifications
****************************************************************/

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

require_once APP_ROOT . '/app/core/DB.php';
require_once APP_ROOT . '/app/core/Auth.php';
require_once APP_ROOT . '/app/core/Middleware.php';
use App\Core\DB;
use App\Core\Auth;
use App\Core\Middleware;

try {
    Auth::check();
    Middleware::role(['developer', 'admin', 'manager', 'informaticien', 'Superviseur', 'Directrice']);
} catch (Throwable $e) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Session requise',
        'count' => 0,
        'recent' => [],
    ]);
    exit;
}

try {
    $pdo = DB::getConnection();
    
    // Compter les notifications non lues
    $stmt = $pdo->query("SELECT COUNT(*) FROM admin_notifications WHERE is_read = 0");
    $count = $stmt->fetchColumn();
    
    // Récupérer aussi les 3 dernières notifications pour le popup
    $stmt = $pdo->query("
        SELECT n.id, n.type, n.message, n.priority, n.created_at,
               e.full_name, e.employee_code
        FROM admin_notifications n
        JOIN employees e ON n.employee_id = e.id
        WHERE n.is_read = 0
        ORDER BY n.priority DESC, n.created_at DESC
        LIMIT 3
    ");
    $recent = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formater les timestamps
    foreach($recent as &$notif) {
        $time_ago = time() - strtotime($notif['created_at']);
        if($time_ago < 60) {
            $notif['time_ago'] = "À l'instant";
        } elseif($time_ago < 3600) {
            $notif['time_ago'] = floor($time_ago / 60) . " min";
        } else {
            $notif['time_ago'] = floor($time_ago / 3600) . " h";
        }
    }
    
    echo json_encode([
        'success' => true,
        'count' => (int)$count,
        'recent' => $recent,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch(Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erreur serveur',
        'count' => 0,
        'recent' => []
    ]);
}
?>
