<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

/****************************************************************
* GESTION PRODUITS ERP PRO - VERSION FINALE
* Société + Ville persistants
* Ajout rapide sans reload
* Édition inline via AJAX
****************************************************************/

ini_set('display_errors',1);
error_reporting(E_ALL);

/* ================= SESSION ================= */
if(session_status() === PHP_SESSION_NONE) session_start();

/* ================= REQUIRES ================= */
require_once APP_ROOT . '/app/core/DB.php';
require_once APP_ROOT . '/app/core/Auth.php';
require_once APP_ROOT . '/app/core/Middleware.php';

use App\Core\DB;
use App\Core\Auth;
use App\Core\Middleware;

/* ================= SÉCURITÉ ================= */
Auth::check();
Middleware::role(['developer','admin','manager','viewer']);

/* ================= CONNEXION PDO ================= */
$pdo = DB::getConnection();

/* ================= CSRF ================= */
if(empty($_SESSION['csrf_token'])){
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ================= AJAX HANDLER ================= */
if(isset($_POST['action']) || isset($_GET['action'])){
    header('Content-Type: application/json');
    $response = ['success'=>false,'msg'=>''];

    try{
        // GET CITIES FOR COMPANY
        if(isset($_GET['action']) && $_GET['action']=='get_cities'){
            $company_id = (int)($_GET['company_id'] ?? 0);
            if($company_id <= 0) throw new Exception("Company ID invalide");
            
            $stmt = $pdo->prepare("SELECT id, name FROM cities WHERE company_id=? ORDER BY name");
            $stmt->execute([$company_id]);
            $cities = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $response['success'] = true;
            $response['cities'] = $cities;
            echo json_encode($response);
            exit;
        }

        // Vérification CSRF pour POST
        if(!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])){
            throw new Exception("CSRF TOKEN INVALID");
        }

        if($_POST['action']=='add'){
            $company_id = (int)($_POST['company_id'] ?? 0);
            $city_id    = (int)($_POST['city_id'] ?? 0);
            $name       = trim($_POST['name'] ?? '');
            $price      = trim($_POST['price'] ?? '');

            if($company_id <= 0) throw new Exception("Société obligatoire");
            if($city_id <= 0) throw new Exception("Ville obligatoire");
            if($name=='' || $price=='') throw new Exception("Tous les champs sont obligatoires");
            if(!is_numeric($price)) throw new Exception("Le prix doit être numérique");

            // Vérifier que la ville appartient bien à la société
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM cities WHERE id=? AND company_id=?");
            $stmt->execute([$city_id, $company_id]);
            if($stmt->fetchColumn() == 0) throw new Exception("Ville invalide pour cette société");

            // Anti-doublon par société ET ville
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE name=? AND company_id=? AND city_id=?");
            $stmt->execute([$name, $company_id, $city_id]);
            if($stmt->fetchColumn()>0) throw new Exception("Produit déjà existant pour cette société et ville");

            $stmt = $pdo->prepare("INSERT INTO products (company_id, city_id, name, price, created_at) VALUES (?,?,?,?,NOW())");
            $stmt->execute([$company_id, $city_id, $name, $price]);

            // Récupérer le nom de la ville
            $stmt = $pdo->prepare("SELECT name FROM cities WHERE id=?");
            $stmt->execute([$city_id]);
            $city_name = $stmt->fetchColumn();

            $response['success']=true;
            $response['msg']="✓ Produit ajouté avec succès";
            $response['product'] = [
                'id'=>$pdo->lastInsertId(),
                'name'=>$name,
                'price'=>number_format((float)$price, 2, '.', ''),
                'city_id'=>$city_id,
                'city_name'=>$city_name,
                'created_at'=>date('Y-m-d H:i:s')
            ];

        } elseif($_POST['action']=='update'){
            $id    = (int)($_POST['id'] ?? 0);
            $field = $_POST['field'] ?? '';
            $value = trim($_POST['value'] ?? '');

            if($id<=0) throw new Exception("ID invalide");

            if($field=='name'){
                if($value=='') throw new Exception("Nom ne peut être vide");

                // Récupérer le company_id et city_id du produit
                $stmt = $pdo->prepare("SELECT company_id, city_id FROM products WHERE id=?");
                $stmt->execute([$id]);
                $prod = $stmt->fetch(PDO::FETCH_ASSOC);

                // Anti-doublon par société ET ville
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE name=? AND company_id=? AND city_id=? AND id<>?");
                $stmt->execute([$value, $prod['company_id'], $prod['city_id'], $id]);
                if($stmt->fetchColumn()>0) throw new Exception("Produit déjà existant");
            }

            if($field=='price'){
                if(!is_numeric($value)) throw new Exception("Prix invalide");
                $value = number_format((float)$value, 2, '.', '');
            }

            if($field=='city_id'){
                $city_id = (int)$value;
                if($city_id <= 0) throw new Exception("Ville invalide");
                
                // Vérifier que la ville existe et appartient à la même société
                $stmt = $pdo->prepare("SELECT c.company_id FROM cities c JOIN products p ON p.id=? WHERE c.id=? AND c.company_id=p.company_id");
                $stmt->execute([$id, $city_id]);
                if(!$stmt->fetchColumn()) throw new Exception("Ville invalide pour cette société");
                
                $field = 'city_id';
            }

            $stmt = $pdo->prepare("UPDATE products SET $field=?, updated_at=NOW() WHERE id=?");
            $stmt->execute([$value,$id]);

            $response['success']=true;
            $response['msg']="✓ Mis à jour";
            $response['value']=$value;

            // Si changement de ville, retourner le nom
            if($field=='city_id'){
                $stmt = $pdo->prepare("SELECT name FROM cities WHERE id=?");
                $stmt->execute([$value]);
                $response['city_name'] = $stmt->fetchColumn();
            }

        } elseif($_POST['action']=='delete'){
            $id = (int)($_POST['id'] ?? 0);
            if($id<=0) throw new Exception("ID invalide");

            $stmt = $pdo->prepare("DELETE FROM products WHERE id=?");
            $stmt->execute([$id]);

            $response['success']=true;
            $response['msg']="✓ Produit supprimé";
        }

    }catch(Throwable $e){
        $response['msg']=$e->getMessage();
    }

    echo json_encode($response);
    exit;
}

/* ================= FILTRES PERSISTANTS ================= */
if(isset($_POST['company_id']) && !isset($_POST['action'])){
    $_SESSION['product_company_id'] = (int)$_POST['company_id'];
    $_SESSION['product_city_id'] = 0; // Reset city
}

if(isset($_POST['city_id']) && !isset($_POST['action'])){
    $_SESSION['product_city_id'] = (int)$_POST['city_id'];
}

$company_id = $_SESSION['product_company_id'] ?? 0;
$city_id = $_SESSION['product_city_id'] ?? 0;

/* ================= SOCIÉTÉS ================= */
$companies = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

/* ================= VILLES ================= */
$cities = [];
if($company_id > 0){
    $stmt = $pdo->prepare("SELECT id, name FROM cities WHERE company_id=? ORDER BY name");
    $stmt->execute([$company_id]);
    $cities = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* ================= PRODUITS + STATS ================= */
$products = [];
$stats = [
    'total_products' => 0,
    'avg_price' => 0,
    'min_price' => 0,
    'max_price' => 0
];

if($company_id > 0 && $city_id > 0){
    $stmt = $pdo->prepare("
        SELECT p.id, p.name, p.price, p.city_id, c.name as city_name, p.created_at 
        FROM products p 
        JOIN cities c ON p.city_id=c.id 
        WHERE p.company_id=? AND p.city_id=? 
        ORDER BY p.id DESC
    ");
    $stmt->execute([$company_id, $city_id]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Stats
    $stats['total_products'] = count($products);
    if($stats['total_products'] > 0){
        $prices = array_column($products, 'price');
        $stats['avg_price'] = array_sum($prices) / $stats['total_products'];
        $stats['min_price'] = min($prices);
        $stats['max_price'] = max($prices);
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Gestion Produits - ERP Pro</title>
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
}

.nav-menu {
    display: flex;
    gap: 5px;
    list-style: none;
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
}

.nav-link:hover {
    background: linear-gradient(135deg, var(--primary), var(--primary-light));
    color: white;
    transform: translateY(-2px);
}

.nav-link.active {
    background: var(--primary);
    color: white;
}

.container {
    max-width: 1400px;
    margin: 20px auto;
    padding: 0 30px;
}

.page-header {
    background: white;
    border-radius: 16px;
    padding: 30px;
    margin-bottom: 30px;
    box-shadow: var(--shadow-lg);
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

.alert {
    padding: 16px 24px;
    border-radius: 12px;
    margin-bottom: 25px;
    display: none;
    align-items: center;
    gap: 12px;
    font-weight: 600;
    box-shadow: var(--shadow);
}

.alert.show {
    display: flex;
}

.alert-success {
    background: linear-gradient(135deg, #d1fae5, #a7f3d0);
    color: #065f46;
    border-left: 4px solid var(--secondary);
}

.alert-error {
    background: linear-gradient(135deg, #fee2e2, #fecaca);
    color: #991b1b;
    border-left: 4px solid var(--danger);
}

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
    transition: transform 0.3s ease;
    color: white;
    position: relative;
    overflow: hidden;
}

.stat-card:hover {
    transform: translateY(-8px) scale(1.02);
}

.stat-card-primary { background: linear-gradient(135deg, #6366f1, #4f46e5); }
.stat-card-success { background: linear-gradient(135deg, #10b981, #059669); }
.stat-card-warning { background: linear-gradient(135deg, #f59e0b, #d97706); }
.stat-card-danger { background: linear-gradient(135deg, #ef4444, #dc2626); }

.stat-icon {
    font-size: 40px;
    margin-bottom: 15px;
    opacity: 0.9;
}

.stat-value {
    font-size: 32px;
    font-weight: 800;
    margin-bottom: 8px;
}

.stat-label {
    font-size: 13px;
    opacity: 0.9;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.card {
    background: white;
    border-radius: 16px;
    padding: 30px;
    margin-bottom: 30px;
    box-shadow: var(--shadow-lg);
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

.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 15px;
    margin-bottom: 20px;
}

.form-grid-add {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr auto;
    gap: 15px;
}

.form-group {
    position: relative;
}

select, input[type="text"], input[type="number"] {
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
}

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

.btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none !important;
}

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
}

thead th:first-child { border-radius: 12px 0 0 0; }
thead th:last-child { border-radius: 0 12px 0 0; }

tbody tr {
    border-bottom: 1px solid var(--border);
    transition: all 0.3s ease;
}

tbody tr:hover {
    background: linear-gradient(90deg, rgba(99, 102, 241, 0.05), rgba(99, 102, 241, 0.1));
}

tbody td {
    padding: 16px;
    text-align: center;
    color: var(--dark);
    font-size: 14px;
}

.editable {
    width: 100%;
    border: 2px solid transparent;
    background: transparent;
    color: var(--dark);
    text-align: center;
    font-weight: 600;
    font-size: 14px;
    padding: 8px;
    border-radius: 6px;
    transition: all 0.3s ease;
}

.editable:focus {
    outline: none;
    border-color: var(--primary);
    background: linear-gradient(135deg, #eef2ff, #e0e7ff);
    box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
}

.action-btn {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: none;
    cursor: pointer;
    transition: all 0.3s ease;
}

.action-btn-delete {
    background: linear-gradient(135deg, #fee2e2, #fecaca);
    color: #991b1b;
}

.action-btn-delete:hover {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
    transform: translateY(-2px);
}

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

.filter-info {
    background: linear-gradient(135deg, #eef2ff, #e0e7ff);
    border: 2px solid var(--primary-light);
    border-radius: 12px;
    padding: 15px 20px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-weight: 600;
    color: var(--primary-dark);
}

@media (max-width: 768px) {
    .nav-menu { display: none; }
    .form-grid, .form-grid-add { grid-template-columns: 1fr; }
    .stats-grid { grid-template-columns: 1fr; }
}
</style>
<link rel="stylesheet" href="/stock/stock_mobile_overrides.css">
</head>
<body>
<button type="button" id="stockInstallBtn" style="position:fixed;right:16px;bottom:18px;z-index:20000;border:none;border-radius:999px;padding:12px 16px;font-size:13px;font-weight:800;display:flex;align-items:center;gap:8px;box-shadow:0 18px 38px rgba(0,0,0,.24);background:linear-gradient(135deg,#10b981,#0ea5e9);color:#fff;cursor:pointer"><i class="fas fa-download"></i> Installer Stock</button>
<div id="stockNetworkBadge" style="position:fixed;left:50%;transform:translateX(-50%);bottom:18px;z-index:20000;border:none;border-radius:999px;padding:12px 16px;font-size:13px;font-weight:800;display:none;align-items:center;gap:8px;box-shadow:0 18px 38px rgba(0,0,0,.24);background:rgba(255,53,83,.96);color:#fff"><i class="fas fa-wifi"></i> Hors ligne</div>

<nav class="navbar">
    <div class="nav-container">
        <div class="nav-logo">
            <i class="fas fa-chart-line"></i>
            <span>ERP Pro</span>
        </div>
        <ul class="nav-menu">
            <li><a href="<?= project_url('dashboard/index.php') ?>" class="nav-link"><i class="fas fa-home"></i> Accueil</a></li>
            <li><a href="<?= project_url('clients/clients_erp_pro.php') ?>" class="nav-link"><i class="fas fa-users"></i> Clients</a></li>
            <li><a href="<?= project_url('stock/products_erp_pro.php') ?>" class="nav-link active"><i class="fas fa-box"></i> Produits</a></li>
            <li><a href="<?= project_url('stock/stocks_erp_pro.php') ?>" class="nav-link"><i class="fas fa-boxes"></i> Stocks</a></li>
            <li><a href="<?= project_url('documents/documents_erp_pro.php') ?>" class="nav-link"><i class="fas fa-folder-open"></i> Documents</a></li>
            <li><a href="<?= project_url('finance/caisse_complete_enhanced.php') ?>" class="nav-link"><i class="fas fa-cash-register"></i> Caisse</a></li>
            <li><a href="<?= project_url('auth/profile.php') ?>" class="nav-link"><i class="fas fa-user-circle"></i> Profil</a></li>
        </ul>
    </div>
</nav>

<div class="container">
    
    <div class="page-header">
        <h1 class="page-title">
            <i class="fas fa-box"></i>
            Gestion des Produits | ESPERANCE H20
        </h1>
        <p class="page-subtitle">Société → magasin → Produits</p>
    </div>

    <div class="alert alert-success" id="successAlert">
        <i class="fas fa-check-circle"></i>
        <span id="successMsg"></span>
    </div>
    <div class="alert alert-error" id="errorAlert">
        <i class="fas fa-times-circle"></i>
        <span id="errorMsg"></span>
    </div>

    <!-- Filtres -->
    <div class="card">
        <h3 class="card-title">
            <i class="fas fa-filter"></i>
            Filtres
        </h3>
        <form method="post" id="filterForm">
            <div class="form-grid">
                <div class="form-group">
                    <select name="company_id" id="companySelect" required>
                        <option value="">🏢 Sélectionner une société</option>
                        <?php foreach($companies as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $company_id==$c['id']?'selected':'' ?>>
                                <?= htmlspecialchars($c['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <select name="city_id" id="citySelect" <?= $company_id<=0?'disabled':'' ?> required>
                        <option value="">🏙️ Sélectionner une magasin</option>
                        <?php foreach($cities as $ct): ?>
                            <option value="<?= $ct['id'] ?>" <?= $city_id==$ct['id']?'selected':'' ?>>
                                <?= htmlspecialchars($ct['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </form>
    </div>

    <?php if($company_id > 0 && $city_id > 0): ?>

    <div class="filter-info">
        <i class="fas fa-info-circle"></i>
        Société : <strong><?php
            $sc = array_filter($companies, fn($c) => $c['id'] == $company_id);
            echo htmlspecialchars(reset($sc)['name'] ?? '');
        ?></strong>
        &nbsp;→&nbsp;
        Ville : <strong><?php
            $sct = array_filter($cities, fn($c) => $c['id'] == $city_id);
            echo htmlspecialchars(reset($sct)['name'] ?? '');
        ?></strong>
    </div>

    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card stat-card-primary">
            <div class="stat-icon"><i class="fas fa-box"></i></div>
            <div class="stat-value" id="statTotal"><?= $stats['total_products'] ?></div>
            <div class="stat-label">Total Produits</div>
        </div>
        
        <div class="stat-card stat-card-success">
            <div class="stat-icon"><i class="fas fa-dollar-sign"></i></div>
            <div class="stat-value" id="statAvg"><?= number_format($stats['avg_price'], 2) ?> FCFA</div>
            <div class="stat-label">Prix Moyen</div>
        </div>
        
        <div class="stat-card stat-card-warning">
            <div class="stat-icon"><i class="fas fa-arrow-down"></i></div>
            <div class="stat-value" id="statMin"><?= number_format($stats['min_price'], 2) ?> FCFA</div>
            <div class="stat-label">Prix Min</div>
        </div>
        
        <div class="stat-card stat-card-danger">
            <div class="stat-icon"><i class="fas fa-arrow-up"></i></div>
            <div class="stat-value" id="statMax"><?= number_format($stats['max_price'], 2) ?> FCFA</div>
            <div class="stat-label">Prix Max</div>
        </div>
    </div>

    <!-- Add Product -->
    <div class="card">
        <h3 class="card-title">
            <i class="fas fa-plus-circle"></i>
            Ajouter un Produit
        </h3>
        <form id="addForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="company_id" value="<?= $company_id ?>">
            <input type="hidden" name="city_id" value="<?= $city_id ?>">
            <div class="form-grid-add">
                <div class="form-group">
                    <input type="text" name="name" id="productName" placeholder="📦 Nom du produit" required autofocus>
                </div>
                <div class="form-group">
                    <input type="number" step="0.01" name="price" id="productPrice" placeholder="💰 Prix" required>
                </div>
                <div class="form-group">
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-plus"></i>
                        Ajouter
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Products Table -->
    <div class="card">
        <h3 class="card-title">
            <i class="fas fa-list"></i>
            Liste des Produits (<span id="productCount"><?= count($products) ?></span>)
        </h3>
        
        <div id="tableWrapper">
        <?php if(count($products) > 0): ?>
        <div class="table-container">
            <table id="productTable">
                <thead>
                    <tr>
                        <th width="60">ID</th>
                        <th>Produit</th>
                        <th width="120">Prix (€)</th>
                        <th width="150">Ville</th>
                        <th width="180">Date</th>
                        <th width="80">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($products as $p): ?>
                    <tr data-id="<?= $p['id'] ?>">
                        <td><strong><?= $p['id'] ?></strong></td>
                        <td>
                            <input class="editable" 
                                   data-field="name" 
                                   type="text"
                                   value="<?= htmlspecialchars($p['name']) ?>">
                        </td>
                        <td>
                            <input class="editable" 
                                   data-field="price" 
                                   type="number" 
                                   step="0.01"
                                   value="<?= number_format((float)$p['price'], 2, '.', '') ?>">
                        </td>
                        <td>
                            <select class="editable" data-field="city_id">
                                <?php foreach($cities as $ct): ?>
                                <option value="<?= $ct['id'] ?>" <?= $p['city_id']==$ct['id']?'selected':'' ?>>
                                    <?= htmlspecialchars($ct['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <i class="far fa-calendar"></i>
                            <?= date('d/m/Y H:i', strtotime($p['created_at'])) ?>
                        </td>
                        <td>
                            <button class="action-btn action-btn-delete" 
                                    onclick="deleteProduct(<?= $p['id'] ?>)">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-state" id="emptyState">
            <i class="fas fa-inbox"></i>
            <h3>Aucun produit</h3>
            <p>Ajoutez votre premier produit ci-dessus</p>
        </div>
        <?php endif; ?>
        </div>
    </div>

    <?php else: ?>
    
    <div class="card">
        <div class="empty-state">
            <i class="fas fa-building"></i>
            <h3>Sélectionnez une société et une ville</h3>
            <p>Veuillez sélectionner les filtres pour commencer</p>
        </div>
    </div>

    <?php endif; ?>

</div>

<script>
const csrf = "<?= $_SESSION['csrf_token'] ?>";
const companyId = <?= $company_id ?>;
const cityId = <?= $city_id ?>;

// CHARGER LES VILLES
document.getElementById('companySelect')?.addEventListener('change', async function() {
    const company = this.value;
    const citySelect = document.getElementById('citySelect');
    
    citySelect.innerHTML = '<option value="">🏙️ Chargement...</option>';
    citySelect.disabled = true;
    
    if(!company) {
        citySelect.innerHTML = '<option value="">🏙️ Sélectionner une ville</option>';
        return;
    }
    
    try {
        const res = await fetch(`?action=get_cities&company_id=${company}`);
        const data = await res.json();
        
        if(data.success) {
            citySelect.innerHTML = '<option value="">🏙️ Sélectionner une ville</option>';
            data.cities.forEach(c => {
                citySelect.innerHTML += `<option value="${c.id}">${escapeHtml(c.name)}</option>`;
            });
            citySelect.disabled = false;
        }
    } catch(e) {
        alert('Erreur chargement villes');
    }
});

// SOUMETTRE FILTRE VILLE
document.getElementById('citySelect')?.addEventListener('change', function() {
    if(this.value) {
        document.getElementById('filterForm').submit();
    }
});

// ALERTS
function showAlert(type, message) {
    const successAlert = document.getElementById('successAlert');
    const errorAlert = document.getElementById('errorAlert');
    
    if(type === 'success') {
        document.getElementById('successMsg').textContent = message;
        successAlert.classList.add('show');
        errorAlert.classList.remove('show');
        setTimeout(() => successAlert.classList.remove('show'), 3000);
    } else {
        document.getElementById('errorMsg').textContent = message;
        errorAlert.classList.add('show');
        successAlert.classList.remove('show');
        setTimeout(() => errorAlert.classList.remove('show'), 5000);
    }
}

// ADD PRODUCT
document.getElementById('addForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const btn = this.querySelector('button[type="submit"]');
    btn.disabled = true;
    
    const formData = new FormData(this);
    formData.append('action', 'add');

    try {
        const res = await fetch(window.location.href, {
            method: "POST",
            body: new URLSearchParams(formData)
        });
        
        const data = await res.json();
        
        if(data.success) {
            showAlert('success', data.msg);
            
            // Remove empty state
            const emptyState = document.getElementById('emptyState');
            if(emptyState) {
                const wrapper = document.getElementById('tableWrapper');
                wrapper.innerHTML = `
                    <div class="table-container">
                        <table id="productTable">
                            <thead>
                                <tr>
                                    <th width="60">ID</th>
                                    <th>Produit</th>
                                    <th width="120">Prix (€)</th>
                                    <th width="150">Ville</th>
                                    <th width="180">Date</th>
                                    <th width="80">Actions</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                `;
            }
            
            // Add row
            const tbody = document.querySelector("#productTable tbody");
            const tr = document.createElement("tr");
            tr.setAttribute("data-id", data.product.id);
            
            // Get cities for dropdown
            const citySelect = document.getElementById('citySelect');
            let cityOptions = '';
            Array.from(citySelect.options).forEach(opt => {
                if(opt.value) {
                    const selected = opt.value == data.product.city_id ? 'selected' : '';
                    cityOptions += `<option value="${opt.value}" ${selected}>${escapeHtml(opt.text)}</option>`;
                }
            });
            
            tr.innerHTML = `
                <td><strong>${data.product.id}</strong></td>
                <td>
                    <input class="editable" data-field="name" type="text" value="${escapeHtml(data.product.name)}">
                </td>
                <td>
                    <input class="editable" data-field="price" type="number" step="0.01" value="${data.product.price}">
                </td>
                <td>
                    <select class="editable" data-field="city_id">${cityOptions}</select>
                </td>
                <td>
                    <i class="far fa-calendar"></i>
                    ${formatDate(data.product.created_at)}
                </td>
                <td>
                    <button class="action-btn action-btn-delete" onclick="deleteProduct(${data.product.id})">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            `;
            tbody.prepend(tr);
            
            updateStats();
            updateCount(1);
            
            this.reset();
            document.getElementById('productName').focus();
        } else {
            showAlert('error', data.msg);
        }
    } catch(error) {
        showAlert('error', 'Erreur: ' + error.message);
    } finally {
        btn.disabled = false;
    }
});

// INLINE EDIT
document.addEventListener('change', debounce(async function(e) {
    if(e.target.classList.contains('editable')) {
        const tr = e.target.closest('tr');
        const id = tr.dataset.id;
        const field = e.target.dataset.field;
        const value = e.target.value.trim();

        try {
            const res = await fetch(window.location.href, {
                method: "POST",
                headers: {"Content-Type": "application/x-www-form-urlencoded"},
                body: new URLSearchParams({
                    action: "update",
                    csrf_token: csrf,
                    id: id,
                    field: field,
                    value: value
                })
            });
            const data = await res.json();
            
            if(data.success) {
                showAlert('success', data.msg);
                if(data.value) e.target.value = data.value;
                updateStats();
            } else {
                showAlert('error', data.msg);
            }
        } catch(error) {
            showAlert('error', 'Erreur réseau');
        }
    }
}, 500));

// DELETE
async function deleteProduct(id) {
    if(!confirm('Supprimer ce produit ?')) return;
    
    try {
        const res = await fetch(window.location.href, {
            method: "POST",
            headers: {"Content-Type": "application/x-www-form-urlencoded"},
            body: new URLSearchParams({
                action: "delete",
                csrf_token: csrf,
                id: id
            })
        });
        const data = await res.json();
        
        if(data.success) {
            showAlert('success', data.msg);
            const tr = document.querySelector(`tr[data-id="${id}"]`);
            if(tr) {
                tr.style.opacity = '0';
                setTimeout(() => {
                    tr.remove();
                    updateStats();
                    updateCount(-1);
                    
                    // Check if empty
                    const tbody = document.querySelector('#productTable tbody');
                    if(tbody && tbody.children.length === 0) {
                        document.getElementById('tableWrapper').innerHTML = `
                            <div class="empty-state" id="emptyState">
                                <i class="fas fa-inbox"></i>
                                <h3>Aucun produit</h3>
                                <p>Ajoutez votre premier produit ci-dessus</p>
                            </div>
                        `;
                    }
                }, 300);
            }
        } else {
            showAlert('error', data.msg);
        }
    } catch(error) {
        showAlert('error', 'Erreur réseau');
    }
}

// UPDATE STATS
function updateStats() {
    const rows = document.querySelectorAll('#productTable tbody tr');
    const prices = Array.from(rows).map(row => {
        const priceInput = row.querySelector('input[data-field="price"]');
        return parseFloat(priceInput?.value) || 0;
    });
    
    const total = prices.length;
    const avg = total > 0 ? prices.reduce((a, b) => a + b, 0) / total : 0;
    const min = total > 0 ? Math.min(...prices) : 0;
    const max = total > 0 ? Math.max(...prices) : 0;
    
    document.getElementById('statTotal').textContent = total;
    document.getElementById('statAvg').textContent = avg.toFixed(2) + ' €';
    document.getElementById('statMin').textContent = min.toFixed(2) + ' €';
    document.getElementById('statMax').textContent = max.toFixed(2) + ' €';
}

function updateCount(delta) {
    const countElem = document.getElementById('productCount');
    if(countElem) {
        countElem.textContent = parseInt(countElem.textContent) + delta;
    }
}

function debounce(func, wait) {
    let timeout;
    return function(...args) {
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(this, args), wait);
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatDate(dateStr) {
    const date = new Date(dateStr);
    const day = String(date.getDate()).padStart(2, '0');
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const year = date.getFullYear();
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    return `${day}/${month}/${year} ${hours}:${minutes}`;
}
let stockDeferredInstallPrompt=null;
window.addEventListener('beforeinstallprompt',e=>{e.preventDefault();stockDeferredInstallPrompt=e;});
document.getElementById('stockInstallBtn')?.addEventListener('click',async()=>{if(!stockDeferredInstallPrompt){window.location.href='/stock/install_stock_app.php';return;}stockDeferredInstallPrompt.prompt();await stockDeferredInstallPrompt.userChoice.catch(()=>null);stockDeferredInstallPrompt=null;});
function updateStockNetworkBadge(){document.getElementById('stockNetworkBadge').style.display=!navigator.onLine?'flex':'none';}
window.addEventListener('online',updateStockNetworkBadge);window.addEventListener('offline',updateStockNetworkBadge);updateStockNetworkBadge();
if('serviceWorker' in navigator){window.addEventListener('load',()=>navigator.serviceWorker.register('/stock/stock-sw.js').catch(()=>{}));}
</script>

</body>
</html>
