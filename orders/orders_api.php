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
$user_id = $_SESSION['user_id'] ?? 0;

try {
    switch ($action) {
        
        /* ===========================
           SEARCH CLIENT BY PHONE
        =========================== */
        case 'search_client':
            $phone = trim($_POST['phone'] ?? '');
            $company_id = (int)$_POST['company_id'];
            
            if (empty($phone)) {
                echo json_encode(['success' => false, 'message' => 'Téléphone requis']);
                exit;
            }
            
            $stmt = $pdo->prepare("
                SELECT id, name, phone, city_id 
                FROM clients 
                WHERE phone = ? AND company_id = ?
                LIMIT 1
            ");
            $stmt->execute([$phone, $company_id]);
            $client = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($client) {
                echo json_encode([
                    'success' => true,
                    'client' => $client,
                    'exists' => true
                ]);
            } else {
                echo json_encode([
                    'success' => true,
                    'exists' => false
                ]);
            }
            break;
            
        /* ===========================
           CREATE NEW CLIENT
        =========================== */
        case 'create_client':
            $name = trim($_POST['name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $company_id = (int)$_POST['company_id'];
            $city_id = (int)$_POST['city_id'];
            
            if (empty($name) || empty($phone)) {
                echo json_encode(['success' => false, 'message' => 'Nom et téléphone requis']);
                exit;
            }
            
            // Vérifier si existe déjà
            $check = $pdo->prepare("SELECT id FROM clients WHERE phone = ? AND company_id = ?");
            $check->execute([$phone, $company_id]);
            if ($check->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Ce numéro existe déjà']);
                exit;
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO clients (company_id, city_id, name, phone, id_type)
                VALUES (?, ?, ?, ?, 'nouveau')
            ");
            $stmt->execute([$company_id, $city_id, $name, $phone]);
            $client_id = $pdo->lastInsertId();
            
            echo json_encode([
                'success' => true,
                'message' => 'Client créé avec succès',
                'client_id' => $client_id
            ]);
            break;
            
        /* ===========================
           CREATE ORDER
        =========================== */
        case 'create_order':
            $company_id = (int)$_POST['company_id'];
            $city_id = (int)$_POST['city_id'];
            $client_id = (int)$_POST['client_id'];
            $items = json_decode($_POST['items'] ?? '[]', true);
            $delivery_address = trim($_POST['delivery_address'] ?? '');
            $delivery_date = $_POST['delivery_date'] ?? null;
            $payment_method = $_POST['payment_method'] ?? 'cash';
            $notes = trim($_POST['notes'] ?? '');
            
            if (empty($items)) {
                echo json_encode(['success' => false, 'message' => 'Aucun article dans la commande']);
                exit;
            }
            
            $pdo->beginTransaction();
            
            try {
                // Générer numéro de commande
                $order_number = 'CMD-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                
                // Calculer total
                $total = 0;
                foreach ($items as $item) {
                    $total += $item['subtotal'];
                }
                
                // Créer commande
                $stmt = $pdo->prepare("
                    INSERT INTO orders (
                        order_number, company_id, city_id, client_id, 
                        total_amount, payment_method, payment_status,
                        delivery_address, delivery_date, delivery_status,
                        status, notes, created_by
                    ) VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, 'pending', 'confirmed', ?, ?)
                ");
                $stmt->execute([
                    $order_number, $company_id, $city_id, $client_id,
                    $total, $payment_method,
                    $delivery_address, $delivery_date, $notes, $user_id
                ]);
                $order_id = $pdo->lastInsertId();
                
                // Ajouter items
                $stmt = $pdo->prepare("
                    INSERT INTO order_items (order_id, product_id, product_name, quantity, unit_price, subtotal)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                
                foreach ($items as $item) {
                    $stmt->execute([
                        $order_id,
                        $item['product_id'],
                        $item['product_name'],
                        $item['quantity'],
                        $item['unit_price'],
                        $item['subtotal']
                    ]);
                }
                
                $pdo->commit();
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Commande créée avec succès',
                    'order_id' => $order_id,
                    'order_number' => $order_number
                ]);
                
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
            break;
            
        /* ===========================
           GET ORDERS
        =========================== */
        case 'get_orders':
            $company_id = (int)$_POST['company_id'];
            $city_id = (int)$_POST['city_id'];
            $status = $_POST['status'] ?? 'all';
            $search = trim($_POST['search'] ?? '');
            
            $sql = "
                SELECT 
                    o.*,
                    c.name as client_name,
                    c.phone as client_phone,
                    u.username as created_by_name,
                    ci.name as city_name,
                    (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as items_count
                FROM orders o
                LEFT JOIN clients c ON o.client_id = c.id
                LEFT JOIN users u ON o.created_by = u.id
                LEFT JOIN cities ci ON o.city_id = ci.id
                WHERE o.company_id = ?
            ";
            
            $params = [$company_id];
            
            // Filtrer par ville seulement si city_id > 0
            if ($city_id > 0) {
                $sql .= " AND o.city_id = ?";
                $params[] = $city_id;
            }
            
            if ($status != 'all') {
                $sql .= " AND o.status = ?";
                $params[] = $status;
            }
            
            if ($search) {
                $sql .= " AND (o.order_number LIKE ? OR c.name LIKE ? OR c.phone LIKE ?)";
                $search_param = "%$search%";
                $params[] = $search_param;
                $params[] = $search_param;
                $params[] = $search_param;
            }
            
            $sql .= " ORDER BY o.created_at DESC LIMIT 100";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'orders' => $orders]);
            break;
            
        /* ===========================
           GET ORDER DETAILS
        =========================== */
        case 'get_order_details':
            $order_id = (int)$_POST['order_id'];
            
            // Order info
            $stmt = $pdo->prepare("
                SELECT 
                    o.*,
                    c.name as client_name,
                    c.phone as client_phone,
                    u.username as created_by_name
                FROM orders o
                LEFT JOIN clients c ON o.client_id = c.id
                LEFT JOIN users u ON o.created_by = u.id
                WHERE o.id = ?
            ");
            $stmt->execute([$order_id]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Order items
            $stmt = $pdo->prepare("
                SELECT * FROM order_items WHERE order_id = ?
            ");
            $stmt->execute([$order_id]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'order' => $order,
                'items' => $items
            ]);
            break;
            
        /* ===========================
           UPDATE ORDER STATUS
        =========================== */
        case 'update_order_status':
            $order_id = (int)$_POST['order_id'];
            $status = $_POST['status'];
            $delivery_status = $_POST['delivery_status'] ?? null;
            $payment_status = $_POST['payment_status'] ?? null;
            
            $updates = ["status = ?"];
            $params = [$status];
            
            if ($delivery_status) {
                $updates[] = "delivery_status = ?";
                $params[] = $delivery_status;
            }
            
            if ($payment_status) {
                $updates[] = "payment_status = ?";
                $params[] = $payment_status;
            }
            
            $params[] = $order_id;
            
            $sql = "UPDATE orders SET " . implode(', ', $updates) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            echo json_encode(['success' => true, 'message' => 'Statut mis à jour']);
            break;
            
        /* ===========================
           DELETE ORDER
        =========================== */
        case 'delete_order':
            $order_id = (int)$_POST['order_id'];
            
            $stmt = $pdo->prepare("DELETE FROM orders WHERE id = ?");
            $stmt->execute([$order_id]);
            
            echo json_encode(['success' => true, 'message' => 'Commande supprimée']);
            break;
            
        /* ===========================
           GET PRODUCTS
        =========================== */
        case 'get_products':
            $company_id = (int)$_POST['company_id'];
            
            $stmt = $pdo->prepare("
                SELECT id, name, price, category 
                FROM products 
                WHERE company_id = ? 
                ORDER BY name
            ");
            $stmt->execute([$company_id]);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'products' => $products]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Action inconnue']);
    }
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()]);
}
