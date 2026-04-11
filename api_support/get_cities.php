<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

session_start();
require_once APP_ROOT . '/app/core/DB.php';
use App\Core\DB;

header('Content-Type: application/json');

$company_id = (int)($_GET['company_id'] ?? 0);

if (!$company_id) {
    echo json_encode([]);
    exit;
}

try {
    $pdo = DB::getConnection();
    $stmt = $pdo->prepare("SELECT id, name FROM cities WHERE company_id = ? ORDER BY name");
    $stmt->execute([$company_id]);
    $cities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($cities);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
