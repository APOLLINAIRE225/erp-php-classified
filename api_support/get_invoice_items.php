<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

session_start();
header('Content-Type: application/json');

require_once APP_ROOT . '/app/core/DB.php';
require_once APP_ROOT . '/app/core/Auth.php';

use App\Core\DB;
use App\Core\Auth;

Auth::check();

$pdo = DB::getConnection();
$invoice_id = (int)($_GET['invoice_id'] ?? 0);

if (!$invoice_id) {
    echo json_encode(['error' => 'Invalid invoice ID']);
    exit;
}

// Get invoice details
$stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
$stmt->execute([$invoice_id]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

// Get invoice items with product names
$stmt = $pdo->prepare("
    SELECT ii.*, p.name as product_name
    FROM invoice_items ii
    JOIN products p ON p.id = ii.product_id
    WHERE ii.invoice_id = ?
");
$stmt->execute([$invoice_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'invoice' => $invoice,
    'items' => $items
]);
