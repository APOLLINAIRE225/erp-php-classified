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
Middleware::role(['developer', 'admin', 'manager']);

$pdo = DB::getConnection();
$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        
        /* ===========================
           GET CITIES BY COMPANY
        =========================== */
        case 'get_cities':
            $company_id = (int)$_POST['company_id'];
            
            $stmt = $pdo->prepare("SELECT id, name FROM cities WHERE company_id = ? ORDER BY name");
            $stmt->execute([$company_id]);
            $cities = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            
            echo json_encode(['success' => true, 'data' => $cities]);
            break;
            
        /* ===========================
           GET STOCK DATA
        =========================== */
        case 'get_stock':
            $company_id = (int)$_POST['company_id'];
            $city_id = (int)$_POST['city_id'];
            
            if (!$company_id || !$city_id) {
                echo json_encode(['success' => false, 'message' => 'Société et ville requises']);
                exit;
            }
            
            $stmt = $pdo->prepare("
                SELECT 
                    p.id,
                    p.name,
                    p.category,
                    p.price,
                    p.alert_quantity,
                    COALESCE(SUM(CASE WHEN m.type='initial' THEN m.quantity END), 0) AS initial_stock,
                    COALESCE(SUM(CASE WHEN m.type='entry' THEN m.quantity END), 0) AS total_entries,
                    COALESCE(SUM(CASE WHEN m.type='exit' THEN m.quantity END), 0) AS total_exits,
                    COALESCE(SUM(CASE WHEN m.type='initial' THEN m.quantity END), 0)
                    +
                    COALESCE(SUM(CASE WHEN m.type='entry' THEN m.quantity END), 0)
                    -
                    COALESCE(SUM(CASE WHEN m.type='exit' THEN m.quantity END), 0)
                    AS stock,
                    CASE WHEN SUM(CASE WHEN m.type='initial' THEN 1 ELSE 0 END) > 0 THEN 1 ELSE 0 END AS has_initial_stock
                FROM products p
                LEFT JOIN stock_movements m
                    ON m.product_id = p.id AND m.company_id = ? AND m.city_id = ?
                WHERE p.company_id = ?
                GROUP BY p.id
                ORDER BY p.name
            ");
            $stmt->execute([$company_id, $city_id, $company_id]);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            
            // Calculate totals
            $total_initial = 0;
            $total_entries = 0;
            $total_exits = 0;
            $total_stock = 0;
            
            foreach ($products as $p) {
                $total_initial += $p['initial_stock'];
                $total_entries += $p['total_entries'];
                $total_exits += $p['total_exits'];
                $total_stock += $p['stock'];
            }
            
            echo json_encode([
                'success' => true,
                'products' => $products,
                'totals' => [
                    'initial' => $total_initial,
                    'entries' => $total_entries,
                    'exits' => $total_exits,
                    'stock' => $total_stock,
                    'products' => count($products)
                ]
            ]);
            break;
            
        /* ===========================
           GET INITIAL STOCK
        =========================== */
        case 'get_initial_stock':
            $company_id = (int)$_POST['company_id'];
            $city_id = (int)$_POST['city_id'];
            
            if (!$company_id || !$city_id) {
                echo json_encode(['success' => false, 'message' => 'Société et ville requises']);
                exit;
            }
            
            $stmt = $pdo->prepare("
                SELECT m.*, p.name AS product_name
                FROM stock_movements m
                JOIN products p ON p.id = m.product_id
                WHERE m.company_id = ? AND m.city_id = ? AND m.type = 'initial'
                ORDER BY m.movement_date DESC
            ");
            $stmt->execute([$company_id, $city_id]);
            $initial_stock = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            
            echo json_encode(['success' => true, 'data' => $initial_stock]);
            break;
            
        /* ===========================
           ADD INITIAL STOCK
        =========================== */
        case 'add_initial_stock':
            $company_id = (int)$_POST['company_id'];
            $city_id = (int)$_POST['city_id'];
            $product_id = (int)$_POST['product_id'];
            $quantity = (int)$_POST['quantity'];
            
            if (!$company_id || !$city_id) {
                echo json_encode(['success' => false, 'message' => 'Société et ville requises']);
                exit;
            }
            
            if ($quantity < 0) {
                echo json_encode(['success' => false, 'message' => 'Quantité invalide']);
                exit;
            }
            
            // Vérifier si ce produit a déjà un stock initial
            $check = $pdo->prepare("
                SELECT COUNT(*) FROM stock_movements 
                WHERE product_id = ? AND company_id = ? AND city_id = ? AND type = 'initial'
            ");
            $check->execute([$product_id, $company_id, $city_id]);
            $exists = $check->fetchColumn();
            $check->closeCursor();
            
            if ($exists > 0) {
                echo json_encode([
                    'success' => false, 
                    'message' => '❌ Ce produit a déjà un stock initial défini. Vous ne pouvez pas le modifier.'
                ]);
                exit;
            }
            
            $pdo->beginTransaction();
            
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO stock_movements
                    (product_id, reference, company_id, type, quantity, city_id, movement_date)
                    VALUES (?, 'STOCK_INITIAL', ?, 'initial', ?, ?, NOW())
                ");
                $stmt->execute([$product_id, $company_id, $quantity, $city_id]);
                $stmt->closeCursor();
                
                $pdo->commit();
                
                echo json_encode([
                    'success' => true,
                    'message' => '✅ Stock initial défini avec succès!'
                ]);
                
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
            break;
            
        /* ===========================
           GET MOVEMENTS
        =========================== */
        case 'get_movements':
            $company_id = (int)$_POST['company_id'];
            $city_id = (int)$_POST['city_id'];
            
            if (!$company_id || !$city_id) {
                echo json_encode(['success' => false, 'message' => 'Société et ville requises']);
                exit;
            }
            
            $stmt = $pdo->prepare("
                SELECT m.*, p.name AS product_name
                FROM stock_movements m
                JOIN products p ON p.id = m.product_id
                WHERE m.company_id = ? AND m.city_id = ?
                ORDER BY m.movement_date DESC, m.id DESC
                LIMIT 200
            ");
            $stmt->execute([$company_id, $city_id]);
            $movements = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            
            echo json_encode(['success' => true, 'data' => $movements]);
            break;
            
        /* ===========================
           ADD MOVEMENT
        =========================== */
        case 'add_movement':
            $company_id = (int)$_POST['company_id'];
            $city_id = (int)$_POST['city_id'];
            $product_id = (int)$_POST['product_id'];
            $type = $_POST['type'];
            $quantity = (int)$_POST['quantity'];
            $reference = trim($_POST['reference'] ?? '');
            
            if (!$company_id || !$city_id) {
                echo json_encode(['success' => false, 'message' => 'Société et ville requises']);
                exit;
            }
            
            if ($quantity <= 0) {
                echo json_encode(['success' => false, 'message' => 'Quantité invalide']);
                exit;
            }
            
            if (!in_array($type, ['entry', 'exit'])) {
                echo json_encode(['success' => false, 'message' => 'Type invalide']);
                exit;
            }
            
            // Vérifier le stock pour les sorties
            if ($type === 'exit') {
                $check = $pdo->prepare("
                    SELECT 
                        COALESCE(SUM(CASE WHEN type='initial' THEN quantity END), 0)
                        +
                        COALESCE(SUM(CASE WHEN type='entry' THEN quantity END), 0)
                        -
                        COALESCE(SUM(CASE WHEN type='exit' THEN quantity END), 0)
                        AS stock
                    FROM stock_movements
                    WHERE product_id = ? AND company_id = ? AND city_id = ?
                ");
                $check->execute([$product_id, $company_id, $city_id]);
                $current_stock = $check->fetchColumn();
                $check->closeCursor();
                
                if ($current_stock < $quantity) {
                    echo json_encode([
                        'success' => false, 
                        'message' => "❌ Stock insuffisant! Stock actuel: $current_stock"
                    ]);
                    exit;
                }
            }
            
            $pdo->beginTransaction();
            
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO stock_movements
                    (product_id, reference, company_id, type, quantity, city_id, movement_date)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$product_id, $reference, $company_id, $type, $quantity, $city_id]);
                $stmt->closeCursor();
                
                $pdo->commit();
                
                $message = $type === 'entry' 
                    ? '✅ Approvisionnement enregistré avec succès!' 
                    : '✅ Sortie enregistrée avec succès!';
                
                echo json_encode([
                    'success' => true,
                    'message' => $message
                ]);
                
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
            break;
            
        /* ===========================
           UPDATE MOVEMENT (ENTRY ONLY)
        =========================== */
        case 'update_movement':
            $movement_id = (int)$_POST['movement_id'];
            $company_id = (int)$_POST['company_id'];
            $city_id = (int)$_POST['city_id'];
            $quantity = (int)$_POST['quantity'];
            $reference = trim($_POST['reference'] ?? '');
            
            if ($quantity <= 0) {
                echo json_encode(['success' => false, 'message' => 'Quantité invalide']);
                exit;
            }
            
            // Verify movement type
            $check = $pdo->prepare("
                SELECT type FROM stock_movements 
                WHERE id = ? AND company_id = ? AND city_id = ?
            ");
            $check->execute([$movement_id, $company_id, $city_id]);
            $current = $check->fetch(PDO::FETCH_ASSOC);
            $check->closeCursor();
            
            if (!$current) {
                echo json_encode(['success' => false, 'message' => 'Mouvement introuvable']);
                exit;
            }
            
            if ($current['type'] === 'initial') {
                echo json_encode(['success' => false, 'message' => '❌ Le stock initial ne peut pas être modifié']);
                exit;
            }
            
            if ($current['type'] === 'exit') {
                echo json_encode(['success' => false, 'message' => '❌ Modification des sorties interdite']);
                exit;
            }
            
            $pdo->beginTransaction();
            
            try {
                $stmt = $pdo->prepare("
                    UPDATE stock_movements
                    SET quantity = ?, reference = ?, movement_date = NOW()
                    WHERE id = ? AND company_id = ? AND city_id = ?
                ");
                $stmt->execute([$quantity, $reference, $movement_id, $company_id, $city_id]);
                $stmt->closeCursor();
                
                $pdo->commit();
                
                echo json_encode([
                    'success' => true,
                    'message' => '✅ Approvisionnement modifié avec succès!'
                ]);
                
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
            break;
            
        /* ===========================
           DELETE MOVEMENT
        =========================== */
        case 'delete_movement':
            $movement_id = (int)$_POST['movement_id'];
            $company_id = (int)$_POST['company_id'];
            $city_id = (int)$_POST['city_id'];
            
            // Vérifier que ce n'est pas un stock initial
            $check = $pdo->prepare("
                SELECT type FROM stock_movements 
                WHERE id = ? AND company_id = ? AND city_id = ?
            ");
            $check->execute([$movement_id, $company_id, $city_id]);
            $current = $check->fetch(PDO::FETCH_ASSOC);
            $check->closeCursor();
            
            if (!$current) {
                echo json_encode(['success' => false, 'message' => 'Mouvement introuvable']);
                exit;
            }
            
            if ($current['type'] === 'initial') {
                echo json_encode([
                    'success' => false, 
                    'message' => '❌ Le stock initial ne peut pas être supprimé. Contactez l\'administrateur.'
                ]);
                exit;
            }
            
            $pdo->beginTransaction();
            
            try {
                $stmt = $pdo->prepare("
                    DELETE FROM stock_movements 
                    WHERE id = ? AND company_id = ? AND city_id = ?
                ");
                $stmt->execute([$movement_id, $company_id, $city_id]);
                $stmt->closeCursor();
                
                $pdo->commit();
                
                echo json_encode([
                    'success' => true,
                    'message' => '✅ Mouvement supprimé avec succès!'
                ]);
                
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Action inconnue: ' . $action]);
    }
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()]);
}
