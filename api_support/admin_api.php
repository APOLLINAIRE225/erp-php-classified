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
Middleware::role(['developer', 'admin']);

$pdo = DB::getConnection();
$userId = $_SESSION['user_id'];

function logAction($pdo, $userId, $action, $details = '') {
    try {
        $stmt = $pdo->prepare("INSERT INTO action_logs (user_id, action, details, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$userId, $action, $details]);
        $stmt->closeCursor();
    } catch (PDOException $e) {
        // Table doesn't exist, will be created elsewhere
    }
}

$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        
        /* ===========================
           PRODUCTS
        =========================== */
        case 'get_products':
            $search = '%' . ($_POST['search'] ?? '') . '%';
            $company_id = (int)($_POST['company_id'] ?? 0);
            
            $sql = "SELECT p.*, c.name AS company_name 
                    FROM products p 
                    LEFT JOIN companies c ON c.id = p.company_id 
                    WHERE p.name LIKE ?";
            
            $params = [$search];
            
            if ($company_id > 0) {
                $sql .= " AND p.company_id = ?";
                $params[] = $company_id;
            }
            
            $sql .= " ORDER BY p.id DESC LIMIT 100";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            
            echo json_encode(['success' => true, 'data' => $products]);
            break;
            
        case 'add_product':
            $name = trim($_POST['name']);
            $category = trim($_POST['category'] ?? '');
            $price = (float)$_POST['price'];
            $alert_quantity = (int)$_POST['alert_quantity'];
            $company_id = (int)$_POST['company_id'];
            
            $stmt = $pdo->prepare("INSERT INTO products (name, category, price, alert_quantity, company_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$name, $category, $price, $alert_quantity, $company_id]);
            $stmt->closeCursor();
            
            logAction($pdo, $userId, 'product_created', "Product: $name");
            
            echo json_encode(['success' => true, 'message' => 'Produit créé avec succès!']);
            break;
            
        case 'update_product':
            $id = (int)$_POST['id'];
            $name = trim($_POST['name']);
            $category = trim($_POST['category'] ?? '');
            $price = (float)$_POST['price'];
            $alert_quantity = (int)$_POST['alert_quantity'];
            
            $stmt = $pdo->prepare("UPDATE products SET name = ?, category = ?, price = ?, alert_quantity = ? WHERE id = ?");
            $stmt->execute([$name, $category, $price, $alert_quantity, $id]);
            $stmt->closeCursor();
            
            logAction($pdo, $userId, 'product_updated', "Product ID: $id");
            
            echo json_encode(['success' => true, 'message' => 'Produit modifié avec succès!']);
            break;
            
        case 'delete_product':
            $id = (int)$_POST['id'];
            
            $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
            $stmt->execute([$id]);
            $stmt->closeCursor();
            
            logAction($pdo, $userId, 'product_deleted', "Product ID: $id");
            
            echo json_encode(['success' => true, 'message' => 'Produit supprimé!']);
            break;
            
        /* ===========================
           CITIES
        =========================== */
        case 'get_cities':
            $search = '%' . ($_POST['search'] ?? '') . '%';
            $company_id = (int)($_POST['company_id'] ?? 0);
            
            $sql = "SELECT ci.*, c.name AS company_name 
                    FROM cities ci 
                    LEFT JOIN companies c ON c.id = ci.company_id 
                    WHERE ci.name LIKE ?";
            
            $params = [$search];
            
            if ($company_id > 0) {
                $sql .= " AND ci.company_id = ?";
                $params[] = $company_id;
            }
            
            $sql .= " ORDER BY ci.id DESC LIMIT 100";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $cities = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            
            echo json_encode(['success' => true, 'data' => $cities]);
            break;
            
        case 'add_city':
            $name = trim($_POST['name']);
            $company_id = (int)$_POST['company_id'];
            
            $stmt = $pdo->prepare("INSERT INTO cities (name, company_id, created_at) VALUES (?, ?, NOW())");
            $stmt->execute([$name, $company_id]);
            $stmt->closeCursor();
            
            logAction($pdo, $userId, 'city_created', "City: $name");
            
            echo json_encode(['success' => true, 'message' => 'Ville créée avec succès!']);
            break;
            
        case 'update_city':
            $id = (int)$_POST['id'];
            $name = trim($_POST['name']);
            $company_id = (int)$_POST['company_id'];
            
            $stmt = $pdo->prepare("UPDATE cities SET name = ?, company_id = ? WHERE id = ?");
            $stmt->execute([$name, $company_id, $id]);
            $stmt->closeCursor();
            
            logAction($pdo, $userId, 'city_updated', "City ID: $id");
            
            echo json_encode(['success' => true, 'message' => 'Ville modifiée avec succès!']);
            break;
            
        case 'delete_city':
            $id = (int)$_POST['id'];
            
            $stmt = $pdo->prepare("DELETE FROM cities WHERE id = ?");
            $stmt->execute([$id]);
            $stmt->closeCursor();
            
            logAction($pdo, $userId, 'city_deleted', "City ID: $id");
            
            echo json_encode(['success' => true, 'message' => 'Ville supprimée!']);
            break;
            
        /* ===========================
           USERS
        =========================== */
        case 'get_users':
            $search = '%' . ($_POST['search'] ?? '') . '%';
            
            $stmt = $pdo->prepare("
                SELECT u.*, c.name AS company_name, p.avatar
                FROM users u
                LEFT JOIN companies c ON c.id = u.company_id
                LEFT JOIN profiles p ON p.user_id = u.id
                WHERE u.username LIKE ?
                ORDER BY u.id DESC
                LIMIT 100
            ");
            $stmt->execute([$search]);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            
            echo json_encode(['success' => true, 'data' => $users]);
            break;
            
        case 'add_user':
            $username = trim($_POST['username']);
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $role = $_POST['role'];
            $company_id = (int)$_POST['company_id'];
            
            $stmt = $pdo->prepare("INSERT INTO users (username, password, role, company_id, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->execute([$username, $password, $role, $company_id]);
            $stmt->closeCursor();
            
            logAction($pdo, $userId, 'user_created', "User: $username");
            
            echo json_encode(['success' => true, 'message' => 'Utilisateur créé avec succès!']);
            break;
            
        case 'update_user':
            $id = (int)$_POST['id'];
            $username = trim($_POST['username']);
            $role = $_POST['role'];
            $company_id = (int)$_POST['company_id'];
            
            if (!empty($_POST['password'])) {
                $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET username = ?, password = ?, role = ?, company_id = ? WHERE id = ?");
                $stmt->execute([$username, $password, $role, $company_id, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET username = ?, role = ?, company_id = ? WHERE id = ?");
                $stmt->execute([$username, $role, $company_id, $id]);
            }
            $stmt->closeCursor();
            
            logAction($pdo, $userId, 'user_updated', "User ID: $id");
            
            echo json_encode(['success' => true, 'message' => 'Utilisateur modifié avec succès!']);
            break;
            
        case 'delete_user':
            $id = (int)$_POST['id'];
            
            if ($id == $userId) {
                echo json_encode(['success' => false, 'message' => 'Vous ne pouvez pas vous supprimer!']);
                exit;
            }
            
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $stmt->closeCursor();
            
            logAction($pdo, $userId, 'user_deleted', "User ID: $id");
            
            echo json_encode(['success' => true, 'message' => 'Utilisateur supprimé!']);
            break;
            
        /* ===========================
           COMPANIES
        =========================== */
        case 'get_companies':
            $search = '%' . ($_POST['search'] ?? '') . '%';
            
            $stmt = $pdo->prepare("SELECT * FROM companies WHERE name LIKE ? ORDER BY id DESC LIMIT 100");
            $stmt->execute([$search]);
            $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            
            echo json_encode(['success' => true, 'data' => $companies]);
            break;
            
        case 'add_company':
            $name = trim($_POST['name']);
            
            $stmt = $pdo->prepare("INSERT INTO companies (name) VALUES (?)");
            $stmt->execute([$name]);
            $stmt->closeCursor();
            
            logAction($pdo, $userId, 'company_created', "Company: $name");
            
            echo json_encode(['success' => true, 'message' => 'Société créée avec succès!']);
            break;
            
        case 'update_company':
            $id = (int)$_POST['id'];
            $name = trim($_POST['name']);
            
            $stmt = $pdo->prepare("UPDATE companies SET name = ? WHERE id = ?");
            $stmt->execute([$name, $id]);
            $stmt->closeCursor();
            
            logAction($pdo, $userId, 'company_updated', "Company ID: $id");
            
            echo json_encode(['success' => true, 'message' => 'Société modifiée avec succès!']);
            break;
            
        case 'delete_company':
            $id = (int)$_POST['id'];
            
            // Check if company has users
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE company_id = ?");
            $stmt->execute([$id]);
            $count = $stmt->fetchColumn();
            $stmt->closeCursor();
            
            if ($count > 0) {
                echo json_encode(['success' => false, 'message' => 'Cette société a des utilisateurs associés!']);
                exit;
            }
            
            $stmt = $pdo->prepare("DELETE FROM companies WHERE id = ?");
            $stmt->execute([$id]);
            $stmt->closeCursor();
            
            logAction($pdo, $userId, 'company_deleted', "Company ID: $id");
            
            echo json_encode(['success' => true, 'message' => 'Société supprimée!']);
            break;
            
        /* ===========================
           DATABASE STATS
        =========================== */
        case 'get_db_stats':
            $stats = [];
            
            // Get all tables
            $result = $pdo->query("SHOW TABLES");
            $tables = [];
            while ($row = $result->fetch(PDO::FETCH_NUM)) {
                $tables[] = $row[0];
            }
            $result->closeCursor();
            
            // Get size and row count for each table
            foreach ($tables as $table) {
                $stmt = $pdo->query("
                    SELECT 
                        '$table' as table_name,
                        COUNT(*) as row_count,
                        ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
                    FROM $table, information_schema.TABLES 
                    WHERE table_schema = DATABASE() 
                    AND table_name = '$table'
                ");
                $info = $stmt->fetch(PDO::FETCH_ASSOC);
                $stmt->closeCursor();
                
                $stats[] = $info;
            }
            
            // Get total DB size
            $stmt = $pdo->query("
                SELECT 
                    ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS total_size_mb,
                    COUNT(*) as total_tables
                FROM information_schema.TABLES 
                WHERE table_schema = DATABASE()
            ");
            $totals = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            
            echo json_encode([
                'success' => true, 
                'tables' => $stats,
                'totals' => $totals
            ]);
            break;
            
        /* ===========================
           LOGS
        =========================== */
        case 'get_logs':
            $limit = (int)($_POST['limit'] ?? 100);
            
            $stmt = $pdo->prepare("
                SELECT al.*, u.username
                FROM action_logs al
                LEFT JOIN users u ON u.id = al.user_id
                ORDER BY al.created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            
            echo json_encode(['success' => true, 'data' => $logs]);
            break;
            
        case 'clear_logs':
            $pdo->exec("TRUNCATE TABLE action_logs");
            logAction($pdo, $userId, 'logs_cleared', 'All logs cleared');
            
            echo json_encode(['success' => true, 'message' => 'Logs effacés avec succès!']);
            break;
            
        /* ===========================
           STATISTICS LINGUISTIQUES
        =========================== */
        case 'get_linguistic_stats':
            $stats = [];
            
            // Actions par utilisateur
            $stmt = $pdo->query("
                SELECT u.username, COUNT(*) as action_count
                FROM action_logs al
                LEFT JOIN users u ON u.id = al.user_id
                GROUP BY u.username
                ORDER BY action_count DESC
                LIMIT 10
            ");
            $stats['top_users'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            
            // Actions par type
            $stmt = $pdo->query("
                SELECT action, COUNT(*) as count
                FROM action_logs
                GROUP BY action
                ORDER BY count DESC
                LIMIT 10
            ");
            $stats['top_actions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            
            // Actions par jour (7 derniers jours)
            $stmt = $pdo->query("
                SELECT DATE(created_at) as date, COUNT(*) as count
                FROM action_logs
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                GROUP BY DATE(created_at)
                ORDER BY date DESC
            ");
            $stats['daily_actions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            
            // Produits les plus modifiés
            $stmt = $pdo->query("
                SELECT details, COUNT(*) as count
                FROM action_logs
                WHERE action LIKE '%product%'
                GROUP BY details
                ORDER BY count DESC
                LIMIT 5
            ");
            $stats['top_products'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            
            echo json_encode(['success' => true, 'data' => $stats]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Action inconnue']);
    }
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()]);
}
