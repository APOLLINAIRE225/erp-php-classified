<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

session_start();

// En production, mettre à 0 !
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once APP_ROOT . '/app/core/DB.php';
require_once APP_ROOT . '/app/core/Auth.php';
require_once APP_ROOT . '/app/core/Middleware.php';
require APP_ROOT . '/vendor/autoload.php';
require APP_ROOT . '/fpdf186/fpdf.php';

use App\Core\DB;
use App\Core\Auth;
use App\Core\Middleware;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Fpdf186\Fpdf;

Auth::check();
Middleware::role(['developer', 'admin', 'manager']);

$pdo = DB::getConnection();

/* =========================
   FILTRES
========================= */
$company_id = (int)($_GET['company_id'] ?? 0);
$city_id    = (int)($_GET['city_id'] ?? 0);
$search     = trim($_GET['search'] ?? '');

/* =========================
   SOCIÉTÉS
========================= */
$companies = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   VILLES
========================= */
$cities = [];
if ($company_id) {
    $stmt = $pdo->prepare("SELECT id, name FROM cities WHERE company_id = ? ORDER BY name");
    $stmt->execute([$company_id]);
    $cities = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* =========================
   PRODUITS + STOCK CALCULÉ
========================= */
$products = [];
$stats = [
    'total_produits' => 0,
    'stock_total' => 0,
    'produits_alerte' => 0,
    'produits_rupture' => 0
];

if ($company_id && $city_id) {
    $params = [$company_id, $city_id, $company_id];
    
    $sql = "
        SELECT 
            p.id,
            p.name,
            p.alert_quantity,
            COALESCE(SUM(CASE WHEN sm.type='entry' THEN sm.quantity END), 0) AS total_entrees,
            COALESCE(SUM(CASE WHEN sm.type='exit' THEN sm.quantity END), 0) AS total_sorties,
            COALESCE(SUM(CASE WHEN sm.type='entry' THEN sm.quantity END), 0)
            -
            COALESCE(SUM(CASE WHEN sm.type='exit' THEN sm.quantity END), 0)
            AS stock_reel
        FROM products p
        LEFT JOIN stock_movements sm
            ON sm.product_id = p.id AND sm.company_id = ? AND sm.city_id = ?
        WHERE p.company_id = ?
    ";

    if ($search !== '') {
        $sql .= " AND p.name LIKE ? ";
        $params[] = "%$search%";
    }

    $sql .= " GROUP BY p.id ORDER BY p.name";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcul des statistiques
    $stats['total_produits'] = count($products);
    foreach ($products as $p) {
        $stock = (int)$p['stock_reel'];
        $stats['stock_total'] += $stock;
        
        if ($stock <= 0) {
            $stats['produits_rupture']++;
        } elseif ($stock <= $p['alert_quantity']) {
            $stats['produits_alerte']++;
        }
    }
}

/* =========================
   EXPORT
========================= */
if (isset($_GET['export']) && $company_id && $city_id) {
    /* ===== EXCEL ===== */
    if ($_GET['export'] === 'excel') {
        ob_clean();
        
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Stocks');
        
        // En-têtes stylisés
        $sheet->fromArray(['Produit', 'Entrées', 'Sorties', 'Stock réel', 'État'], null, 'A1');
        
        // Style des en-têtes
        $headerStyle = $sheet->getStyle('A1:E1');
        $headerStyle->getFont()->setBold(true)->setSize(12);
        $headerStyle->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                               ->getStartColor()->setARGB('FF6366F1');
        $headerStyle->getFont()->getColor()->setARGB('FFFFFFFF');
        
        $row = 2;
        foreach ($products as $p) {
            $stock = (int)$p['stock_reel'];
            
            if ($stock <= 0) {
                $etat = 'Rupture';
            } elseif ($stock <= $p['alert_quantity']) {
                $etat = 'Alerte';
            } else {
                $etat = 'OK';
            }
            
            $sheet->setCellValue("A$row", $p['name']);
            $sheet->setCellValue("B$row", $p['total_entrees']);
            $sheet->setCellValue("C$row", $p['total_sorties']);
            $sheet->setCellValue("D$row", $p['stock_reel']);
            $sheet->setCellValue("E$row", $etat);
            
            // Colorer selon l'état
            if ($stock <= 0) {
                $sheet->getStyle("A$row:E$row")->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFFEE2E2');
            } elseif ($stock <= $p['alert_quantity']) {
                $sheet->getStyle("A$row:E$row")->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFFEF3C7');
            }
            
            $row++;
        }
        
        // Auto-size colonnes
        foreach(range('A','E') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="stocks_' . date('Y-m-d_His') . '.xlsx"');
        header('Cache-Control: max-age=0');
        
        (new Xlsx($spreadsheet))->save('php://output');
        exit;
    }

    /* ===== PDF ===== */
    if ($_GET['export'] === 'pdf') {
        ob_clean();
        
        $pdf = new FPDF('P', 'mm', 'A4');
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, utf8_decode("Rapport de Stocks"), 0, 1, 'C');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(0, 6, utf8_decode("Généré le: " . date('d/m/Y à H:i')), 0, 1, 'C');
        $pdf->Ln(5);

        $pdf->SetFont('Arial', 'B', 11);
        $pdf->SetFillColor(99, 102, 241);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell(70, 8, 'Produit', 1, 0, 'C', true);
        $pdf->Cell(30, 8, utf8_decode('Entrées'), 1, 0, 'C', true);
        $pdf->Cell(30, 8, 'Sorties', 1, 0, 'C', true);
        $pdf->Cell(30, 8, utf8_decode('Stock réel'), 1, 0, 'C', true);
        $pdf->Cell(30, 8, utf8_decode('État'), 1, 0, 'C', true);
        $pdf->Ln();

        $pdf->SetFont('Arial', '', 10);
        $pdf->SetTextColor(0, 0, 0);
        
        foreach ($products as $p) {
            $stock = (int)$p['stock_reel'];
            
            if ($stock <= 0) {
                $etat = 'Rupture';
                $pdf->SetFillColor(254, 226, 226);
            } elseif ($stock <= $p['alert_quantity']) {
                $etat = 'Alerte';
                $pdf->SetFillColor(254, 243, 199);
            } else {
                $etat = 'OK';
                $pdf->SetFillColor(255, 255, 255);
            }
            
            $pdf->Cell(70, 7, utf8_decode($p['name']), 1, 0, 'L', true);
            $pdf->Cell(30, 7, $p['total_entrees'], 1, 0, 'C', true);
            $pdf->Cell(30, 7, $p['total_sorties'], 1, 0, 'C', true);
            $pdf->Cell(30, 7, $p['stock_reel'], 1, 0, 'C', true);
            $pdf->Cell(30, 7, utf8_decode($etat), 1, 0, 'C', true);
            $pdf->Ln();
        }
        
        $pdf->Output('D', 'stocks_' . date('Y-m-d_His') . '.pdf');
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Gestion des Stocks - ERP Pro</title>
<meta name="theme-color" content="#10b981">
<link rel="manifest" href="/stock/stock_manifest.json">
<link rel="icon" href="/stock/stock-app-icon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/stock/stock-app-icon-192.png">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

:root {
    --primary: #6366f1;
    --primary-dark: #4f46e5;
    --primary-light: #818cf8;
    --secondary: #10b981;
    --danger: #ef4444;
    --warning: #f59e0b;
    --dark: #1e293b;
    --light: #f8fafc;
    --gray: #64748b;
    --border: #e2e8f0;
    --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    --shadow-lg: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
}

body {
    font-family: 'Inter', sans-serif;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    min-height: 100vh;
    padding-top: 80px;
}

/* ===========================
   NAVIGATION BAR
=========================== */
.navbar {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    height: 70px;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    box-shadow: var(--shadow-lg);
    z-index: 1000;
    animation: slideDown 0.5s ease;
}

@keyframes slideDown {
    from {
        transform: translateY(-100%);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

.nav-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 0 30px;
    height: 100%;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.nav-logo {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 24px;
    font-weight: 800;
    background: linear-gradient(135deg, var(--primary), var(--primary-light));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.nav-logo i {
    font-size: 32px;
    background: linear-gradient(135deg, var(--primary), var(--primary-light));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

.nav-menu {
    display: flex;
    gap: 5px;
    list-style: none;
}

.nav-item {
    position: relative;
}

.nav-link {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    color: var(--dark);
    text-decoration: none;
    font-weight: 600;
    border-radius: 8px;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.nav-link::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(99, 102, 241, 0.1), transparent);
    transition: left 0.5s ease;
}

.nav-link:hover::before {
    left: 100%;
}

.nav-link:hover {
    background: linear-gradient(135deg, var(--primary), var(--primary-light));
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(99, 102, 241, 0.3);
}

.nav-link.active {
    background: var(--primary);
    color: white;
}

/* ===========================
   CONTAINER
=========================== */
.container {
    max-width: 1400px;
    margin: 20px auto;
    padding: 0 30px;
}

/* ===========================
   HEADER
=========================== */
.page-header {
    background: white;
    border-radius: 16px;
    padding: 30px;
    margin-bottom: 30px;
    box-shadow: var(--shadow-lg);
    animation: fadeInUp 0.6s ease;
}

@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.page-title {
    display: flex;
    align-items: center;
    gap: 15px;
    font-size: 32px;
    font-weight: 800;
    color: var(--dark);
    margin-bottom: 10px;
}

.page-title i {
    font-size: 36px;
    background: linear-gradient(135deg, var(--primary), var(--primary-light));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

.page-subtitle {
    color: var(--gray);
    font-size: 16px;
    margin-left: 51px;
}

/* ===========================
   CARDS
=========================== */
.card {
    background: white;
    border-radius: 16px;
    padding: 30px;
    margin-bottom: 30px;
    box-shadow: var(--shadow-lg);
    animation: fadeInUp 0.6s ease;
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.card:hover {
    transform: translateY(-5px);
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
}

.card-title {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 22px;
    font-weight: 700;
    color: var(--dark);
    margin-bottom: 25px;
    padding-bottom: 15px;
    border-bottom: 2px solid var(--border);
}

.card-title i {
    color: var(--primary);
    font-size: 24px;
}

/* ===========================
   STATS CARDS
=========================== */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    padding: 25px;
    border-radius: 16px;
    box-shadow: var(--shadow-lg);
    animation: fadeInUp 0.6s ease;
    transition: transform 0.3s ease;
    color: white;
    position: relative;
    overflow: hidden;
}

.stat-card::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 200%;
    height: 200%;
    background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
    animation: rotate 20s linear infinite;
}

@keyframes rotate {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

.stat-card:hover {
    transform: translateY(-8px) scale(1.02);
    box-shadow: 0 30px 60px -12px rgba(0, 0, 0, 0.3);
}

.stat-card-primary {
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
}

.stat-card-success {
    background: linear-gradient(135deg, #10b981, #059669);
}

.stat-card-warning {
    background: linear-gradient(135deg, #f59e0b, #d97706);
}

.stat-card-danger {
    background: linear-gradient(135deg, #ef4444, #dc2626);
}

.stat-icon {
    font-size: 40px;
    margin-bottom: 15px;
    opacity: 0.9;
    position: relative;
    z-index: 1;
}

.stat-value {
    font-size: 36px;
    font-weight: 800;
    margin-bottom: 8px;
    position: relative;
    z-index: 1;
}

.stat-label {
    font-size: 14px;
    opacity: 0.9;
    text-transform: uppercase;
    letter-spacing: 1px;
    position: relative;
    z-index: 1;
}

/* ===========================
   FORMS
=========================== */
.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.form-group {
    position: relative;
}

select, input[type="text"] {
    width: 100%;
    padding: 12px 16px;
    border: 2px solid var(--border);
    border-radius: 10px;
    font-size: 15px;
    font-weight: 500;
    color: var(--dark);
    transition: all 0.3s ease;
    background: white;
}

select:focus, input:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
    transform: translateY(-2px);
}

select:hover, input:hover {
    border-color: var(--primary-light);
}

/* ===========================
   BUTTONS
=========================== */
.btn {
    padding: 12px 28px;
    border: none;
    border-radius: 10px;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    position: relative;
    overflow: hidden;
}

.btn::before {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    width: 0;
    height: 0;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.3);
    transform: translate(-50%, -50%);
    transition: width 0.6s, height 0.6s;
}

.btn:hover::before {
    width: 300px;
    height: 300px;
}

.btn-primary {
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
    box-shadow: 0 4px 15px rgba(99, 102, 241, 0.4);
}

.btn-primary:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(99, 102, 241, 0.5);
}

.btn-success {
    background: linear-gradient(135deg, var(--secondary), #059669);
    color: white;
    box-shadow: 0 4px 15px rgba(16, 185, 129, 0.4);
}

.btn-success:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(16, 185, 129, 0.5);
}

.btn-danger {
    background: linear-gradient(135deg, var(--danger), #dc2626);
    color: white;
    box-shadow: 0 4px 15px rgba(239, 68, 68, 0.4);
}

.btn-danger:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(239, 68, 68, 0.5);
}

.btn-group {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

/* ===========================
   TABLE
=========================== */
.table-container {
    overflow-x: auto;
    border-radius: 12px;
    box-shadow: var(--shadow);
}

table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    background: white;
}

thead {
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
}

thead th {
    padding: 18px 16px;
    text-align: center;
    font-weight: 700;
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border: none;
}

thead th:first-child {
    border-radius: 12px 0 0 0;
}

thead th:last-child {
    border-radius: 0 12px 0 0;
}

tbody tr {
    border-bottom: 1px solid var(--border);
    transition: all 0.3s ease;
}

tbody tr:hover {
    background: linear-gradient(90deg, rgba(99, 102, 241, 0.05), rgba(99, 102, 241, 0.1));
    transform: scale(1.01);
}

tbody td {
    padding: 16px;
    color: var(--dark);
    font-size: 14px;
    text-align: center;
}

tbody td:nth-child(2) {
    text-align: left;
    font-weight: 600;
}

/* ===========================
   STATUS BADGES
=========================== */
.status-ok {
    background: linear-gradient(135deg, #d1fae5, #a7f3d0) !important;
    color: #065f46 !important;
}

.status-warning {
    background: linear-gradient(135deg, #fef3c7, #fde68a) !important;
    color: #92400e !important;
}

.status-danger {
    background: linear-gradient(135deg, #fee2e2, #fecaca) !important;
    color: #991b1b !important;
}

.badge {
    display: inline-block;
    padding: 8px 16px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.badge-ok {
    background: linear-gradient(135deg, #d1fae5, #a7f3d0);
    color: #065f46;
}

.badge-warning {
    background: linear-gradient(135deg, #fef3c7, #fde68a);
    color: #92400e;
}

.badge-danger {
    background: linear-gradient(135deg, #fee2e2, #fecaca);
    color: #991b1b;
}

/* ===========================
   EMPTY STATE
=========================== */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--gray);
}

.empty-state i {
    font-size: 64px;
    margin-bottom: 20px;
    display: block;
    opacity: 0.5;
}

.empty-state h3 {
    font-size: 24px;
    margin-bottom: 10px;
    color: var(--dark);
}

.empty-state p {
    font-size: 16px;
}

/* ===========================
   RESPONSIVE
=========================== */
@media (max-width: 768px) {
    .nav-menu {
        display: none;
    }
    
    .form-grid {
        grid-template-columns: 1fr;
    }
    
    .container {
        padding: 0 15px;
    }
    
    .card {
        padding: 20px;
    }
    
    .btn-group {
        flex-direction: column;
    }
    
    .btn {
        width: 100%;
        justify-content: center;
    }
    
    table {
        font-size: 12px;
    }
    
    thead th, tbody td {
        padding: 10px 8px;
    }
}

/* ===========================
   PRINT STYLES
=========================== */
@media print {
    .no-print {
        display: none !important;
    }
    
    body {
        background: white;
        padding: 0;
    }
    
    .navbar {
        display: none;
    }
    
    .card {
        box-shadow: none;
        page-break-inside: avoid;
    }
}

/* ===========================
   ANIMATIONS
=========================== */
@keyframes pulse {
    0%, 100% {
        opacity: 1;
    }
    50% {
        opacity: 0.5;
    }
}

.loading {
    animation: pulse 2s ease-in-out infinite;
}

html {
    scroll-behavior: smooth;
}
</style>
<link rel="stylesheet" href="/stock/stock_mobile_overrides.css">
</head>
<body>
<button type="button" id="stockInstallBtn" style="position:fixed;right:16px;bottom:18px;z-index:20000;border:none;border-radius:999px;padding:12px 16px;font-size:13px;font-weight:800;display:flex;align-items:center;gap:8px;box-shadow:0 18px 38px rgba(0,0,0,.24);background:linear-gradient(135deg,#10b981,#0ea5e9);color:#fff;cursor:pointer"><i class="fas fa-download"></i> Installer Stock</button>
<div id="stockNetworkBadge" style="position:fixed;left:50%;transform:translateX(-50%);bottom:18px;z-index:20000;border:none;border-radius:999px;padding:12px 16px;font-size:13px;font-weight:800;display:none;align-items:center;gap:8px;box-shadow:0 18px 38px rgba(0,0,0,.24);background:rgba(255,53,83,.96);color:#fff"><i class="fas fa-wifi"></i> Hors ligne</div>

<!-- ===========================
     NAVIGATION
=========================== -->
<nav class="navbar no-print">
    <div class="nav-container">
        <div class="nav-logo">
            <i class="fas fa-chart-line"></i>
            <span>ERP Pro</span>
        </div>
        <ul class="nav-menu">
            <li class="nav-item">
                <a href="<?= project_url('dashboard/index.php') ?>" class="nav-link">
                    <i class="fas fa-home"></i>
                    Accueil
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= project_url('clients/clients_erp_pro.php') ?>" class="nav-link">
                    <i class="fas fa-users"></i>
                    Clients
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= project_url('stock/stocks_erp_pro.php') ?>" class="nav-link active">
                    <i class="fas fa-boxes"></i>
                    Stocks
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= project_url('finance/caisse_complete_enhanced.php') ?>" class="nav-link">
                    <i class="fas fa-cash-register"></i>
                    Caisse
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= project_url('auth/profile.php') ?>" class="nav-link">
                    <i class="fas fa-user-circle"></i>
                    Profil
                </a>
            </li>
        </ul>
    </div>
</nav>

<!-- ===========================
     MAIN CONTAINER
=========================== -->
<div class="container">
    
    <!-- Page Header -->
    <div class="page-header">
        <h1 class="page-title">
            <i class="fas fa-boxes"></i>
            Gestion des Stocks
        </h1>
        <p class="page-subtitle">Visualisez et exportez l'état de vos stocks en temps réel</p>
    </div>

    <!-- Filters -->
    <div class="card no-print">
        <h3 class="card-title">
            <i class="fas fa-filter"></i>
            Filtres et Actions
        </h3>
        <form method="get">
            <div class="form-grid">
                <div class="form-group">
                    <select name="company_id" onchange="this.form.submit()">
                        <option value="">🏢 Sélectionner une société</option>
                        <?php foreach($companies as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $company_id==$c['id']?'selected':'' ?>>
                                <?= htmlspecialchars($c['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <select name="city_id" onchange="this.form.submit()">
                        <option value="">🏙️ Sélectionner une ville</option>
                        <?php foreach($cities as $v): ?>
                            <option value="<?= $v['id'] ?>" <?= $city_id==$v['id']?'selected':'' ?>>
                                <?= htmlspecialchars($v['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <input type="text" name="search" placeholder="🔍 Rechercher un produit" value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="form-group">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i>
                        Filtrer
                    </button>
                </div>
            </div>
            
            <?php if ($company_id && $city_id): ?>
            <div class="btn-group" style="margin-top: 15px;">
                <button type="submit" name="export" value="excel" class="btn btn-success">
                    <i class="fas fa-file-excel"></i>
                    Exporter Excel
                </button>
                <button type="submit" name="export" value="pdf" class="btn btn-danger">
                    <i class="fas fa-file-pdf"></i>
                    Exporter PDF
                </button>
                <button type="button" onclick="window.print()" class="btn btn-primary">
                    <i class="fas fa-print"></i>
                    Imprimer
                </button>
            </div>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($company_id && $city_id): ?>

    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card stat-card-primary">
            <div class="stat-icon">
                <i class="fas fa-cubes"></i>
            </div>
            <div class="stat-value"><?= $stats['total_produits'] ?></div>
            <div class="stat-label">Total Produits</div>
        </div>
        
        <div class="stat-card stat-card-success">
            <div class="stat-icon">
                <i class="fas fa-boxes"></i>
            </div>
            <div class="stat-value"><?= number_format($stats['stock_total'], 0, ',', ' ') ?></div>
            <div class="stat-label">Stock Total</div>
        </div>
        
        <div class="stat-card stat-card-warning">
            <div class="stat-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="stat-value"><?= $stats['produits_alerte'] ?></div>
            <div class="stat-label">Produits en Alerte</div>
        </div>
        
        <div class="stat-card stat-card-danger">
            <div class="stat-icon">
                <i class="fas fa-times-circle"></i>
            </div>
            <div class="stat-value"><?= $stats['produits_rupture'] ?></div>
            <div class="stat-label">Ruptures de Stock</div>
        </div>
    </div>

    <!-- Products Table -->
    <div class="card">
        <h3 class="card-title">
            <i class="fas fa-list"></i>
            État des Stocks
        </h3>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Produit</th>
                        <th>Entrées</th>
                        <th>Sorties</th>
                        <th>Stock Réel</th>
                        <th>État</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(count($products) > 0): ?>
                        <?php 
                        $i = 1; 
                        foreach ($products as $p):
                            $stock = (int)$p['stock_reel'];
                            
                            if ($stock <= 0) {
                                $etat = 'Rupture';
                                $badgeClass = 'badge-danger';
                                $rowClass = 'status-danger';
                            } elseif ($stock <= $p['alert_quantity']) {
                                $etat = 'Alerte';
                                $badgeClass = 'badge-warning';
                                $rowClass = 'status-warning';
                            } else {
                                $etat = 'OK';
                                $badgeClass = 'badge-ok';
                                $rowClass = 'status-ok';
                            }
                        ?>
                        <tr class="<?= $rowClass ?>">
                            <td><strong><?= $i++ ?></strong></td>
                            <td>
                                <i class="fas fa-box"></i>
                                <?= htmlspecialchars($p['name']) ?>
                            </td>
                            <td>
                                <i class="fas fa-arrow-down" style="color: var(--secondary);"></i>
                                <strong><?= (int)$p['total_entrees'] ?></strong>
                            </td>
                            <td>
                                <i class="fas fa-arrow-up" style="color: var(--danger);"></i>
                                <strong><?= (int)$p['total_sorties'] ?></strong>
                            </td>
                            <td>
                                <strong style="font-size: 18px;"><?= $stock ?></strong>
                            </td>
                            <td>
                                <span class="badge <?= $badgeClass ?>">
                                    <?= $etat ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="empty-state">
                                <i class="fas fa-inbox"></i>
                                <h3>Aucun produit trouvé</h3>
                                <p>Aucun produit ne correspond à vos critères de recherche</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php elseif ($company_id && !$city_id): ?>
    
    <div class="card">
        <div class="empty-state">
            <i class="fas fa-map-marker-alt"></i>
            <h3>Sélectionnez une ville</h3>
            <p>Veuillez sélectionner une ville pour visualiser les stocks</p>
        </div>
    </div>

    <?php else: ?>
    
    <div class="card">
        <div class="empty-state">
            <i class="fas fa-building"></i>
            <h3>Commencez par sélectionner une société</h3>
            <p>Sélectionnez une société et une ville pour afficher l'état des stocks</p>
        </div>
    </div>

    <?php endif; ?>

</div>

<script>
// Animation au scroll
const observerOptions = {
    threshold: 0.1,
    rootMargin: '0px 0px -50px 0px'
};

const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            entry.target.style.opacity = '1';
            entry.target.style.transform = 'translateY(0)';
        }
    });
}, observerOptions);

document.querySelectorAll('.card, .stat-card').forEach(el => {
    el.style.opacity = '0';
    el.style.transform = 'translateY(30px)';
    el.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
    observer.observe(el);
});

// Animation des valeurs de stats
document.querySelectorAll('.stat-value').forEach(el => {
    const target = parseInt(el.textContent.replace(/\s/g, ''));
    let current = 0;
    const increment = target / 50;
    const timer = setInterval(() => {
        current += increment;
        if (current >= target) {
            el.textContent = target.toLocaleString('fr-FR');
            clearInterval(timer);
        } else {
            el.textContent = Math.floor(current).toLocaleString('fr-FR');
        }
    }, 20);
});

// Confirmation pour les exports
document.querySelectorAll('[name="export"]').forEach(btn => {
    btn.addEventListener('click', function(e) {
        const type = this.value === 'excel' ? 'Excel' : 'PDF';
        if (!confirm(`Voulez-vous exporter les stocks en ${type} ?`)) {
            e.preventDefault();
        }
    });
});
let stockDeferredInstallPrompt=null;
window.addEventListener('beforeinstallprompt',e=>{e.preventDefault();stockDeferredInstallPrompt=e;});
document.getElementById('stockInstallBtn')?.addEventListener('click',async()=>{if(!stockDeferredInstallPrompt){window.location.href='/stock/install_stock_app.php';return;}stockDeferredInstallPrompt.prompt();await stockDeferredInstallPrompt.userChoice.catch(()=>null);stockDeferredInstallPrompt=null;});
function updateStockNetworkBadge(){document.getElementById('stockNetworkBadge').style.display=!navigator.onLine?'flex':'none';}
window.addEventListener('online',updateStockNetworkBadge);window.addEventListener('offline',updateStockNetworkBadge);updateStockNetworkBadge();
if('serviceWorker' in navigator){window.addEventListener('load',()=>navigator.serviceWorker.register('/stock/stock-sw.js').catch(()=>{}));}
</script>

</body>
</html>
