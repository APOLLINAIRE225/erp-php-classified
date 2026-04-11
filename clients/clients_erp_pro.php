<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once APP_ROOT . '/app/core/DB.php';
require_once APP_ROOT . '/app/core/Auth.php';
require_once APP_ROOT . '/app/core/Middleware.php';

use App\Core\DB;
use App\Core\Auth;
use App\Core\Middleware;

/*-------------------------------
| SÉCURITÉ
-------------------------------*/
Auth::check();
Middleware::role(['developer','admin','manager']);

$pdo = DB::getConnection();

try {
    $columnsStmt = $pdo->query("SHOW COLUMNS FROM clients");
    $clientColumns = $columnsStmt ? $columnsStmt->fetchAll(PDO::FETCH_COLUMN) : [];
    if (!in_array('id_type', $clientColumns, true)) {
        $pdo->exec("ALTER TABLE clients ADD COLUMN id_type VARCHAR(50) NOT NULL DEFAULT 'nouveau' AFTER phone");
    }
} catch (Throwable $e) {
}

/* =========================
   FILTRES
========================= */
$company_id = $_GET['company_id'] ?? '';
$city_id    = $_GET['city_id'] ?? '';
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = 10;

/* =========================
   TRAITEMENT FORMULAIRE
========================= */
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $company_id_post = $_POST['company_id'] ?? '';
    $city_id_post    = $_POST['city_id'] ?? '';
    $name            = trim($_POST['name'] ?? '');
    $phone           = trim($_POST['phone'] ?? '');
    $id_type         = $_POST['id_type'] ?? 'nouveau';

    if($company_id_post && $city_id_post && $name && $phone){
        $stmt = $pdo->prepare("SELECT id FROM clients WHERE phone=? AND city_id=? AND company_id=?");
        $stmt->execute([$phone,$city_id_post,$company_id_post]);
        if($stmt->rowCount()==0){
            $stmt = $pdo->prepare("INSERT INTO clients (company_id,city_id,name,phone,id_type) VALUES (?,?,?,?,?)");
            $stmt->execute([$company_id_post,$city_id_post,$name,$phone,$id_type]);
            $message = "Client ajouté avec succès";
            $messageType = 'success';
        } else {
            $message = "Ce téléphone existe déjà pour cette société/ville";
            $messageType = 'warning';
        }
    } else {
        $message = "Tous les champs sont obligatoires";
        $messageType = 'error';
    }
}

/* =========================
   SOCIÉTÉS ET VILLES
========================= */
$companies = $pdo->query("SELECT id,name FROM companies ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$cities = [];
if($company_id){
    $stmt = $pdo->prepare("SELECT id,name FROM cities WHERE company_id=? ORDER BY name");
    $stmt->execute([$company_id]);
    $cities = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* =========================
   HISTORIQUE CLIENTS
========================= */
$sqlWhere = "WHERE 1=1";
$params = [];
if($company_id){ $sqlWhere .= " AND cl.company_id=?"; $params[]=$company_id; }
if($city_id){ $sqlWhere .= " AND cl.city_id=?"; $params[]=$city_id; }

$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM clients cl $sqlWhere");
$totalStmt->execute($params);
$totalClients = $totalStmt->fetchColumn();
$offset = ($page-1)*$perPage;

$sqlHistory = "
SELECT cl.id,cl.name,cl.phone,cl.id_type,c.name AS city,co.name AS company,cl.created_at
FROM clients cl
JOIN cities c ON c.id=cl.city_id
JOIN companies co ON co.id=cl.company_id
$sqlWhere
ORDER BY cl.created_at DESC
LIMIT $offset,$perPage
";
$stmt = $pdo->prepare($sqlHistory);
$stmt->execute($params);
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Pagination */
$totalPages = ceil($totalClients/$perPage);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gestion Clients - ERP Pro</title>
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
   ALERTS
=========================== */
.alert {
    padding: 16px 24px;
    border-radius: 12px;
    margin-bottom: 25px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-weight: 600;
    animation: slideInRight 0.5s ease;
    box-shadow: var(--shadow);
}

@keyframes slideInRight {
    from {
        opacity: 0;
        transform: translateX(50px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

.alert-success {
    background: linear-gradient(135deg, #d1fae5, #a7f3d0);
    color: #065f46;
    border-left: 4px solid var(--secondary);
}

.alert-warning {
    background: linear-gradient(135deg, #fef3c7, #fde68a);
    color: #92400e;
    border-left: 4px solid var(--warning);
}

.alert-error {
    background: linear-gradient(135deg, #fee2e2, #fecaca);
    color: #991b1b;
    border-left: 4px solid var(--danger);
}

.alert i {
    font-size: 24px;
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

select, input[type="text"], input[type="tel"] {
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

.btn-primary:active {
    transform: translateY(-1px);
}

.btn-secondary {
    background: linear-gradient(135deg, var(--secondary), #059669);
    color: white;
    box-shadow: 0 4px 15px rgba(16, 185, 129, 0.4);
}

.btn-secondary:hover {
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
    text-align: left;
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
}

.badge {
    display: inline-block;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
}

.badge-nouveau {
    background: linear-gradient(135deg, #dbeafe, #bfdbfe);
    color: #1e40af;
}

.badge-ancien {
    background: linear-gradient(135deg, #fef3c7, #fde68a);
    color: #92400e;
}

.actions {
    display: flex;
    gap: 10px;
}

.action-link {
    padding: 8px 14px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 600;
    font-size: 13px;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.action-edit {
    background: linear-gradient(135deg, #dbeafe, #bfdbfe);
    color: #1e40af;
}

.action-edit:hover {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
}

.action-delete {
    background: linear-gradient(135deg, #fee2e2, #fecaca);
    color: #991b1b;
}

.action-delete:hover {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.4);
}

/* ===========================
   PAGINATION
=========================== */
.pagination {
    display: flex;
    justify-content: center;
    gap: 8px;
    margin-top: 30px;
    padding: 20px;
}

.page-link {
    padding: 10px 18px;
    border-radius: 10px;
    background: white;
    color: var(--dark);
    text-decoration: none;
    font-weight: 600;
    transition: all 0.3s ease;
    border: 2px solid var(--border);
}

.page-link:hover {
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
    transform: translateY(-3px);
    box-shadow: 0 5px 15px rgba(99, 102, 241, 0.3);
    border-color: var(--primary);
}

.page-link.active {
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
    border-color: var(--primary);
    box-shadow: 0 5px 15px rgba(99, 102, 241, 0.3);
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
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
    padding: 25px;
    border-radius: 16px;
    box-shadow: var(--shadow-lg);
    animation: fadeInUp 0.6s ease;
    transition: transform 0.3s ease;
}

.stat-card:hover {
    transform: translateY(-8px) scale(1.02);
    box-shadow: 0 30px 60px -12px rgba(0, 0, 0, 0.3);
}

.stat-icon {
    font-size: 40px;
    margin-bottom: 15px;
    opacity: 0.9;
}

.stat-value {
    font-size: 36px;
    font-weight: 800;
    margin-bottom: 8px;
}

.stat-label {
    font-size: 14px;
    opacity: 0.9;
    text-transform: uppercase;
    letter-spacing: 1px;
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

/* Smooth Scroll */
html {
    scroll-behavior: smooth;
}
</style>
</head>
<body>

<!-- ===========================
     NAVIGATION
=========================== -->
<nav class="navbar">
    <div class="nav-container">
        <div class="nav-logo">
            <i class="fas fa-chart-line"></i>
            <span>Gestion des clients  |  ESPERANCE H20</span>
        </div>
        <ul class="nav-menu">
            <li class="nav-item">
                <a href="<?= project_url('dashboard/index.php') ?>" class="nav-link">
                    <i class="fas fa-home"></i>
                    Accueil
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= project_url('clients/clients_erp_pro.php') ?>" class="nav-link active">
                    <i class="fas fa-users"></i>
                    Clients
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
            <i class="fas fa-users-cog"></i>
            Gestion des Clients
        </h1>
        <p class="page-subtitle">Ajoutez et gérez vos clients par société et magasin</p>
    </div>

    <!-- Alert Messages -->
    <?php if($message): ?>
    <div class="alert alert-<?= $messageType ?>">
        <i class="fas fa-<?= $messageType === 'success' ? 'check-circle' : ($messageType === 'warning' ? 'exclamation-triangle' : 'times-circle') ?>"></i>
        <?= htmlspecialchars($message) ?>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-users"></i>
            </div>
            <div class="stat-value"><?= $totalClients ?></div>
            <div class="stat-label">Total Clients</div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card">
        <h3 class="card-title">
            <i class="fas fa-filter"></i>
            Filtres
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
                        <option value="">🏙️ Sélectionner une magasin</option>
                        <?php foreach($cities as $v): ?>
                            <option value="<?= $v['id'] ?>" <?= $city_id==$v['id']?'selected':'' ?>>
                                <?= htmlspecialchars($v['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </form>
    </div>

    <!-- Add Client Form -->
    <div class="card">
        <h3 class="card-title">
            <i class="fas fa-user-plus"></i>
            Ajouter un nouveau client
        </h3>
        <form method="post">
            <div class="form-grid">
                <div class="form-group">
                    <select name="company_id" required>
                        <option value="">🏢 Société *</option>
                        <?php foreach($companies as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <select name="city_id" required>
                        <option value="">🏙️ magasin *</option>
                        <?php foreach($cities as $v): ?>
                            <option value="<?= $v['id'] ?>"><?= htmlspecialchars($v['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <input type="text" name="name" placeholder="👤 Nom du client *" required>
                </div>
                <div class="form-group">
                    <input type="tel" name="phone" placeholder="📱 Téléphone *" required>
                </div>
                <div class="form-group">
                    <select name="id_type">
                        <option value="nouveau">🆕 Nouveau</option>
                        <option value="ancien">⭐ Ancien</option>
                    </select>
                </div>
                <div class="form-group">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-plus-circle"></i>
                        Ajouter le client
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Clients Table -->
    <div class="card">
        <h3 class="card-title">
            <i class="fas fa-list"></i>
            Liste des clients
        </h3>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Nom</th>
                        <th>Téléphone</th>
                        <th>Type</th>
                        <th>Société</th>
                        <th>Ville</th>
                        <th>Créé le</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(count($clients) > 0): ?>
                        <?php $i=$offset+1; foreach($clients as $cl): ?>
                        <tr>
                            <td><strong><?= $i++ ?></strong></td>
                            <td><strong><?= htmlspecialchars($cl['name']) ?></strong></td>
                            <td><i class="fas fa-phone"></i> <?= htmlspecialchars($cl['phone']) ?></td>
                            <td><span class="badge badge-<?= $cl['id_type'] ?>"><?= htmlspecialchars($cl['id_type']) ?></span></td>
                            <td><?= htmlspecialchars($cl['company']) ?></td>
                            <td><?= htmlspecialchars($cl['city']) ?></td>
                            <td><i class="far fa-calendar"></i> <?= date('d/m/Y H:i', strtotime($cl['created_at'])) ?></td>
                            <td>
                                <div class="actions">
                                    <a href="client_edit.php?id=<?= $cl['id'] ?>" class="action-link action-edit">
                                        <i class="fas fa-edit"></i> Modifier
                                    </a>
                                    <a href="client_delete.php?id=<?= $cl['id'] ?>" 
                                       onclick="return confirm('Êtes-vous sûr de vouloir supprimer ce client ?')" 
                                       class="action-link action-delete">
                                        <i class="fas fa-trash"></i> Supprimer
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 40px; color: var(--gray);">
                                <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 15px; display: block;"></i>
                                <strong>Aucun client trouvé</strong><br>
                                Commencez par ajouter votre premier client
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if($totalPages > 1): ?>
        <div class="pagination">
            <?php for($p=1; $p<=$totalPages; $p++): ?>
                <a href="?company_id=<?= $company_id ?>&city_id=<?= $city_id ?>&page=<?= $p ?>" 
                   class="page-link <?= $p==$page?'active':'' ?>">
                    <?= $p ?>
                </a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>

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

// Auto-hide alerts après 5 secondes
setTimeout(() => {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        alert.style.transition = 'all 0.5s ease';
        alert.style.opacity = '0';
        alert.style.transform = 'translateX(100px)';
        setTimeout(() => alert.remove(), 500);
    });
}, 5000);
</script>

</body>
</html>
