<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

session_start();
header('Content-Type: application/json');

require_once APP_ROOT . '/app/core/DB.php';
require_once APP_ROOT . '/app/core/Auth.php';
require_once APP_ROOT . '/app/core/Middleware.php';

use App\Core\DB;
use App\Core\Auth;
use App\Core\Middleware;

Auth::check();
Middleware::role(['developer', 'admin', 'manager', 'staff']);

$pdo = DB::getConnection();
$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        
        /* ===========================
           GET LAST ORDER ID
        =========================== */
        case 'get_last_order_id':
            $company_id = (int)$_POST['company_id'];
            $city_id = (int)$_POST['city_id'];
            
            $sql = "SELECT MAX(id) as last_id FROM orders WHERE company_id = ?";
            $params = [$company_id];
            
            if ($city_id > 0) {
                $sql .= " AND city_id = ?";
                $params[] = $city_id;
            }
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'last_id' => (int)($result['last_id'] ?? 0)
            ]);
            break;
            
        /* ===========================
           CHECK NEW ORDERS
        =========================== */
        case 'check_new_orders':
            $company_id = (int)$_POST['company_id'];
            $city_id = (int)$_POST['city_id'];
            $last_order_id = (int)$_POST['last_order_id'];
            
            $sql = "
                SELECT 
                    o.id,
                    o.order_number,
                    o.total_amount,
                    o.created_at,
                    c.name as client_name,
                    c.phone as client_phone,
                    ci.name as city_name,
                    (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as items_count
                FROM orders o
                LEFT JOIN clients c ON o.client_id = c.id
                LEFT JOIN cities ci ON o.city_id = ci.id
                WHERE o.company_id = ? AND o.id > ?
            ";
            
            $params = [$company_id, $last_order_id];
            
            if ($city_id > 0) {
                $sql .= " AND o.city_id = ?";
                $params[] = $city_id;
            }
            
            $sql .= " ORDER BY o.id ASC LIMIT 10";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $new_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'new_orders' => $new_orders,
                'count' => count($new_orders)
            ]);
            break;
            
        /* ===========================
           MARK AS READ (optionnel)
        =========================== */
        case 'mark_notification_read':
            $order_id = (int)$_POST['order_id'];
            $user_id = $_SESSION['user_id'] ?? 0;
            
            // Tu peux créer une table notifications_read si besoin
            // Pour l'instant, juste un succès
            
            echo json_encode(['success' => true]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Action inconnue']);
    }
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()]);
}
