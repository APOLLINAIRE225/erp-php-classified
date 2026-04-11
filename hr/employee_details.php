<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

/****************************************************************
* EMPLOYEE DETAILS - Détails complets d'un employé
* Version mobile-responsive avec graphiques
****************************************************************/

ini_set('display_errors',1);
error_reporting(E_ALL);

if(session_status() === PHP_SESSION_NONE) session_start();

require_once APP_ROOT . '/app/core/DB.php';
require_once APP_ROOT . '/app/core/Auth.php';
require_once APP_ROOT . '/app/core/Middleware.php';

use App\Core\DB;
use App\Core\Auth;
use App\Core\Middleware;

Auth::check();
Middleware::role(['developer','admin','manager']);

$pdo = DB::getConnection();

$employee_id = (int)($_GET['id'] ?? 0);

if(!$employee_id) {
    header('Location: ' . project_url('admin/admin_notifications.php'));
    exit;
}

/* ================= GET EMPLOYEE INFO ================= */
$stmt = $pdo->prepare("
    SELECT e.*, c.name as category_name, p.title as position_title
    FROM employees e
    JOIN categories c ON e.category_id=c.id
    JOIN positions p ON e.position_id=p.id
    WHERE e.id=?
");
$stmt->execute([$employee_id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$employee){
    die("❌ Employé introuvable (ID: $employee_id)");
}

/* ================= STATISTICS THIS MONTH ================= */
$current_month = date('Y-m');

// Jours travaillés
$stmt = $pdo->prepare("
    SELECT COUNT(*) as days_worked,
           SUM(CASE WHEN status='present' THEN 1 ELSE 0 END) as on_time,
           SUM(CASE WHEN status='retard' THEN 1 ELSE 0 END) as late_count
    FROM attendance 
    WHERE employee_id=? AND work_date LIKE ?
");
$stmt->execute([$employee_id, $current_month.'%']);
$month_stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Heures supplémentaires
$stmt = $pdo->prepare("
    SELECT SUM(hours) as total_overtime_hours,
           SUM(hours * rate_per_hour) as total_overtime_amount
    FROM overtime 
    WHERE employee_id=? AND work_date LIKE ?
");
$stmt->execute([$employee_id, $current_month.'%']);
$overtime_stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Permissions
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_permissions,
        SUM(CASE WHEN status='en_attente' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status='accepte' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status='rejete' THEN 1 ELSE 0 END) as rejected
    FROM permissions 
    WHERE employee_id=?
");
$stmt->execute([$employee_id]);
$permission_stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Avances
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_advances,
        SUM(CASE WHEN status='en_attente' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status='approuve' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status='rejete' THEN 1 ELSE 0 END) as rejected,
        SUM(CASE WHEN status='approuve' THEN amount ELSE 0 END) as total_approved_amount
    FROM advances 
    WHERE employee_id=?
");
$stmt->execute([$employee_id]);
$advance_stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Salaire net estimé
$net_salary = $employee['salary_amount'];

/* ================= RECENT HISTORY ================= */
$stmt = $pdo->prepare("
    SELECT * FROM attendance 
    WHERE employee_id=? 
    ORDER BY work_date DESC, created_at DESC 
    LIMIT 10
");
$stmt->execute([$employee_id]);
$recent_attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT * FROM permissions 
    WHERE employee_id=? 
    ORDER BY created_at DESC 
    LIMIT 5
");
$stmt->execute([$employee_id]);
$recent_permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT * FROM advances 
    WHERE employee_id=? 
    ORDER BY created_at DESC 
    LIMIT 5
");
$stmt->execute([$employee_id]);
$recent_advances = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT *, (hours * rate_per_hour) as total_amount FROM overtime 
    WHERE employee_id=? 
    ORDER BY work_date DESC 
    LIMIT 5
");
$stmt->execute([$employee_id]);
$recent_overtime = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT * FROM attendance WHERE employee_id=? AND work_date=?");
$stmt->execute([$employee_id, date('Y-m-d')]);
$today_attendance = $stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Détails - <?= htmlspecialchars($employee['full_name']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

:root {
    --primary: #32be8f;
    --primary-dark: #2a9d75;
    --success: #10b981;
    --danger: #ef4444;
    --warning: #f59e0b;
    --info: #3b82f6;
    --dark: #0f172a;
    --gray: #64748b;
    --border: #e2e8f0;
}

body {
    font-family: 'Poppins', sans-serif;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    min-height: 100vh;
    padding: 15px;
}

.container {
    max-width: 1400px;
    margin: 0 auto;
}

.page-header {
    background: white;
    border-radius: 16px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
    animation: slideDown 0.6s ease-out;
}

@keyframes slideDown {
    from { transform: translateY(-50px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

.header-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.page-title {
    font-size: 24px;
    font-weight: 900;
    color: var(--dark);
    display: flex;
    align-items: center;
    gap: 10px;
}

.header-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.back-btn, .dashboard-btn {
    background: linear-gradient(135deg, var(--gray), #475569);
    color: white;
    padding: 10px 18px;
    border-radius: 10px;
    text-decoration: none;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s;
    font-size: 14px;
}

.dashboard-btn {
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
}

.back-btn:hover, .dashboard-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0,0,0,0.3);
}

.profile-card {
    background: white;
    border-radius: 16px;
    padding: 25px;
    margin-bottom: 20px;
    box-shadow: 0 8px 25px rgba(0,0,0,0.15);
    display: flex;
    gap: 25px;
    align-items: center;
    animation: fadeIn 0.6s ease-out;
}

@keyframes fadeIn {
    from { opacity: 0; transform: scale(0.95); }
    to { opacity: 1; transform: scale(1); }
}

.profile-avatar {
    width: 100px;
    height: 100px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 40px;
    font-weight: 900;
    box-shadow: 0 10px 30px rgba(50, 190, 143, 0.4);
    flex-shrink: 0;
}

.profile-info {
    flex: 1;
}

.profile-name {
    font-size: 26px;
    font-weight: 900;
    color: var(--dark);
    margin-bottom: 8px;
}

.profile-details {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 15px;
    margin-top: 15px;
}

.profile-detail {
    display: flex;
    flex-direction: column;
}

.profile-detail-label {
    font-size: 11px;
    color: var(--gray);
    text-transform: uppercase;
    font-weight: 700;
    letter-spacing: 0.5px;
    margin-bottom: 4px;
}

.profile-detail-value {
    font-size: 15px;
    font-weight: 700;
    color: var(--dark);
}

.badge {
    display: inline-block;
    padding: 6px 12px;
    border-radius: 16px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
}

.badge-success { background: #d1fae5; color: #065f46; }
.badge-warning { background: #fef3c7; color: #92400e; }
.badge-danger { background: #fee2e2; color: #991b1b; }
.badge-info { background: #dbeafe; color: #1e40af; }
.badge-primary { background: rgba(50, 190, 143, 0.2); color: var(--primary-dark); }

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.stat-card {
    background: white;
    padding: 20px;
    border-radius: 14px;
    box-shadow: 0 6px 20px rgba(0,0,0,0.12);
    transition: all 0.3s;
    position: relative;
    overflow: hidden;
}

.stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 100%;
}

.stat-card.success::before { background: var(--success); }
.stat-card.danger::before { background: var(--danger); }
.stat-card.warning::before { background: var(--warning); }
.stat-card.info::before { background: var(--info); }
.stat-card.primary::before { background: var(--primary); }

.stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
}

.stat-icon {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: white;
    margin-bottom: 12px;
}

.icon-success { background: linear-gradient(135deg, var(--success), #059669); }
.icon-danger { background: linear-gradient(135deg, var(--danger), #dc2626); }
.icon-warning { background: linear-gradient(135deg, var(--warning), #d97706); }
.icon-info { background: linear-gradient(135deg, var(--info), #2563eb); }
.icon-primary { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); }

.stat-value {
    font-size: 26px;
    font-weight: 900;
    color: var(--dark);
    margin-bottom: 4px;
}

.stat-label {
    font-size: 11px;
    color: var(--gray);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.stat-sublabel {
    font-size: 10px;
    color: var(--gray);
    margin-top: 4px;
}

.section {
    background: white;
    border-radius: 16px;
    padding: 25px;
    margin-bottom: 20px;
    box-shadow: 0 6px 20px rgba(0,0,0,0.12);
}

.section-title {
    font-size: 20px;
    font-weight: 800;
    color: var(--dark);
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
    padding-bottom: 15px;
    border-bottom: 2px solid var(--border);
}

.section-title i {
    color: var(--primary);
}

.today-status {
    padding: 20px;
    border-radius: 12px;
    margin-bottom: 20px;
    text-align: center;
}

.today-status.present {
    background: linear-gradient(135deg, #d1fae5, #a7f3d0);
}

.today-status.late {
    background: linear-gradient(135deg, #fee2e2, #fecaca);
}

.today-status-title {
    font-size: 18px;
    font-weight: 800;
    margin-bottom: 12px;
}

.today-status-time {
    font-size: 32px;
    font-weight: 900;
    margin: 12px 0;
}

.today-status-details {
    display: flex;
    gap: 25px;
    justify-content: center;
    flex-wrap: wrap;
}

.table-container {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.table {
    width: 100%;
    border-collapse: collapse;
    min-width: 500px;
}

.table thead {
    background: var(--primary);
    color: white;
}

.table th {
    padding: 12px;
    text-align: left;
    font-weight: 700;
    text-transform: uppercase;
    font-size: 11px;
    letter-spacing: 0.5px;
}

.table td {
    padding: 12px;
    border-bottom: 1px solid var(--border);
    font-weight: 500;
    font-size: 13px;
}

.table tbody tr:hover {
    background: #f8fafc;
}

.history-item {
    padding: 16px;
    border: 2px solid var(--border);
    border-radius: 10px;
    margin-bottom: 12px;
    transition: all 0.3s;
}

.history-item:hover {
    border-color: var(--primary);
    box-shadow: 0 3px 8px rgba(50,190,143,0.2);
}

.history-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}

.history-date {
    font-size: 12px;
    color: var(--gray);
    font-weight: 600;
}

.history-content {
    color: var(--dark);
    font-size: 14px;
    line-height: 1.5;
}

.grid-2 {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
}

/* CHARTS - PETITS */
.chart-wrapper {
    position: relative;
    width: 100%;
    height: 200px;
}

canvas {
    max-width: 100% !important;
    height: auto !important;
}

/* RESPONSIVE MOBILE */
@media (max-width: 768px) {
    body {
        padding: 10px;
    }
    
    .page-header {
        padding: 15px;
    }
    
    .header-top {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
    }
    
    .page-title {
        font-size: 20px;
    }
    
    .header-actions {
        width: 100%;
    }
    
    .back-btn, .dashboard-btn {
        flex: 1;
        justify-content: center;
        padding: 10px;
        font-size: 12px;
    }
    
    .profile-card {
        flex-direction: column;
        text-align: center;
        padding: 20px;
    }
    
    .profile-avatar {
        width: 80px;
        height: 80px;
        font-size: 32px;
    }
    
    .profile-name {
        font-size: 22px;
    }
    
    .profile-details {
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
    }
    
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
    }
    
    .stat-card {
        padding: 15px;
    }
    
    .stat-icon {
        width: 36px;
        height: 36px;
        font-size: 18px;
    }
    
    .stat-value {
        font-size: 20px;
    }
    
    .stat-label {
        font-size: 10px;
    }
    
    .section {
        padding: 20px;
    }
    
    .section-title {
        font-size: 18px;
    }
    
    .grid-2 {
        grid-template-columns: 1fr;
    }
    
    .table th,
    .table td {
        padding: 10px 8px;
        font-size: 12px;
    }
    
    .chart-wrapper {
        height: 180px;
    }
    
    .today-status-time {
        font-size: 28px;
    }
}

@media (max-width: 480px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .profile-details {
        grid-template-columns: 1fr;
    }
}
</style>
</head>
<body>

<div class="container">
    
    <!-- Header -->
    <div class="page-header">
        <div class="header-top">
            <h1 class="page-title">
                <i class="fas fa-user-circle"></i>
                Détails Employé
            </h1>
        </div>
        <div class="header-actions">
            <a href="<?= project_url('admin/admin_notifications.php') ?>" class="back-btn">
                <i class="fas fa-arrow-left"></i>
                Retour
            </a>
            <a href="<?= project_url('hr/employees_manager.php') ?>" class="dashboard-btn">
                <i class="fas fa-users"></i>
                Employés
            </a>
            <a href="<?= project_url('dashboard/index.php') ?>" class="dashboard-btn">
                <i class="fas fa-home"></i>
                Dashboard
            </a>
            <a href="<?= project_url('dashboard/admin_nasa.php') ?>" class="dashboard-btn">
                <i class="fas fa-cog"></i>
                Admin
            </a>
        </div>
    </div>

    <!-- Profile Card -->
    <div class="profile-card">
        <div class="profile-avatar">
            <?= strtoupper(substr($employee['full_name'], 0, 2)) ?>
        </div>
        
        <div class="profile-info">
            <h2 class="profile-name"><?= htmlspecialchars($employee['full_name']) ?></h2>
            
            <div style="display: flex; gap: 10px; margin-bottom: 15px; flex-wrap: wrap;">
                <span class="badge badge-<?= $employee['status']=='actif'?'success':'danger' ?>">
                    <?= ucfirst($employee['status']) ?>
                </span>
                <span class="badge badge-primary">
                    <?= htmlspecialchars($employee['employee_code']) ?>
                </span>
            </div>
            
            <div class="profile-details">
                <div class="profile-detail">
                    <span class="profile-detail-label">Position</span>
                    <span class="profile-detail-value"><?= htmlspecialchars($employee['position_title']) ?></span>
                </div>
                
                <div class="profile-detail">
                    <span class="profile-detail-label">Catégorie</span>
                    <span class="profile-detail-value"><?= htmlspecialchars($employee['category_name']) ?></span>
                </div>
                
                <div class="profile-detail">
                    <span class="profile-detail-label">Téléphone</span>
                    <span class="profile-detail-value"><?= htmlspecialchars($employee['phone'] ?? '-') ?></span>
                </div>
                
                <div class="profile-detail">
                    <span class="profile-detail-label">Embauche</span>
                    <span class="profile-detail-value"><?= $employee['hire_date'] ? date('d/m/Y', strtotime($employee['hire_date'])) : '-' ?></span>
                </div>
                
                <div class="profile-detail">
                    <span class="profile-detail-label">Salaire <?= ucfirst($employee['salary_type']) ?></span>
                    <span class="profile-detail-value"><?= number_format($employee['salary_amount'], 0, ',', ' ') ?> F</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Today's Status -->
    <?php if($today_attendance): ?>
    <div class="section">
        <h3 class="section-title">
            <i class="fas fa-calendar-day"></i>
            Pointage Aujourd'hui
        </h3>
        
        <div class="today-status <?= $today_attendance['status'] == 'retard' ? 'late' : 'present' ?>">
            <div class="today-status-title" style="color: <?= $today_attendance['status']=='retard'?'#991b1b':'#065f46' ?>;">
                <?= $today_attendance['status']=='retard' ? '⚠️ Arrivé en RETARD' : '✅ Arrivé à l\'heure' ?>
            </div>
            
            <div class="today-status-details">
                <div>
                    <div class="profile-detail-label">Arrivée</div>
                    <div class="today-status-time" style="color: <?= $today_attendance['status']=='retard'?'#991b1b':'#065f46' ?>;">
                        <?= substr($today_attendance['check_in'], 0, 5) ?>
                    </div>
                </div>
                
                <?php if($today_attendance['check_out']): ?>
                <div>
                    <div class="profile-detail-label">Départ</div>
                    <div class="today-status-time" style="color: #065f46;">
                        <?= substr($today_attendance['check_out'], 0, 5) ?>
                    </div>
                </div>
                <?php else: ?>
                <div style="color: var(--gray); font-size: 14px; font-weight: 600;">
                    En attente du départ...
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Statistics This Month -->
    <div class="section">
        <h3 class="section-title">
            <i class="fas fa-chart-line"></i>
            Statistiques <?= date('m/Y') ?>
        </h3>
        
        <div class="stats-grid">
            <div class="stat-card success">
                <div class="stat-icon icon-success">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <div class="stat-value"><?= $month_stats['days_worked'] ?? 0 ?></div>
                <div class="stat-label">Jours Travaillés</div>
                <div class="stat-sublabel">
                    <?= $month_stats['on_time'] ?? 0 ?> à l'heure | <?= $month_stats['late_count'] ?? 0 ?> retard
                </div>
            </div>
            
            <div class="stat-card primary">
                <div class="stat-icon icon-primary">
                    <i class="fas fa-wallet"></i>
                </div>
                <div class="stat-value"><?= number_format($net_salary, 0, ',', ' ') ?></div>
                <div class="stat-label">Salaire Net (F)</div>
            </div>
            
            <div class="stat-card warning">
                <div class="stat-icon icon-warning">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-value"><?= number_format($overtime_stats['total_overtime_hours'] ?? 0, 1) ?>h</div>
                <div class="stat-label">Heures Sup</div>
                <div class="stat-sublabel">
                    <?= number_format($overtime_stats['total_overtime_amount'] ?? 0, 0, ',', ' ') ?> F
                </div>
            </div>
            
            <div class="stat-card info">
                <div class="stat-icon icon-info">
                    <i class="fas fa-file-alt"></i>
                </div>
                <div class="stat-value"><?= $permission_stats['total_permissions'] ?></div>
                <div class="stat-label">Permissions</div>
                <div class="stat-sublabel">
                    <?= $permission_stats['pending'] ?> attente | <?= $permission_stats['approved'] ?> ok
                </div>
            </div>
            
            <div class="stat-card info">
                <div class="stat-icon icon-info">
                    <i class="fas fa-hand-holding-usd"></i>
                </div>
                <div class="stat-value"><?= $advance_stats['total_advances'] ?></div>
                <div class="stat-label">Avances</div>
                <div class="stat-sublabel">
                    <?= number_format($advance_stats['total_approved_amount'] ?? 0, 0, ',', ' ') ?> F
                </div>
            </div>
        </div>
    </div>

    <!-- GRAPHIQUES STATISTIQUES -->
    <div class="grid-2" style="margin-bottom: 20px;">
        <!-- Graphique Présences -->
        <div class="section">
            <h3 class="section-title">
                <i class="fas fa-chart-pie"></i>
                Pointages Ce Mois
            </h3>
            <div class="chart-wrapper">
                <canvas id="chart-attendance"></canvas>
            </div>
        </div>

        <!-- Graphique Permissions -->
        <div class="section">
            <h3 class="section-title">
                <i class="fas fa-chart-bar"></i>
                Permissions
            </h3>
            <div class="chart-wrapper">
                <canvas id="chart-permissions"></canvas>
            </div>
        </div>
    </div>

    <div class="grid-2" style="margin-bottom: 20px;">
        <!-- Graphique Avances -->
        <div class="section">
            <h3 class="section-title">
                <i class="fas fa-chart-doughnut"></i>
                Avances
            </h3>
            <div class="chart-wrapper">
                <canvas id="chart-advances"></canvas>
            </div>
        </div>

        <!-- Graphique Heures Sup -->
        <div class="section">
            <h3 class="section-title">
                <i class="fas fa-chart-line"></i>
                Heures Sup (7j)
            </h3>
            <div class="chart-wrapper">
                <canvas id="chart-overtime"></canvas>
            </div>
        </div>
    </div>

    <!-- Recent History Grid -->
    <div class="grid-2">
        
        <!-- Recent Attendance -->
        <div class="section">
            <h3 class="section-title">
                <i class="fas fa-history"></i>
                Historique Pointages
            </h3>
            
            <?php if(count($recent_attendance) > 0): ?>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Arrivée</th>
                            <th>Départ</th>
                            <th>Statut</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($recent_attendance as $att): ?>
                        <tr>
                            <td><?= date('d/m', strtotime($att['work_date'])) ?></td>
                            <td><strong><?= substr($att['check_in'], 0, 5) ?></strong></td>
                            <td><?= $att['check_out'] ? substr($att['check_out'], 0, 5) : '-' ?></td>
                            <td>
                                <span class="badge badge-<?= $att['status']=='present'?'success':($att['status']=='retard'?'warning':'danger') ?>">
                                    <?= ucfirst($att['status']) ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <p style="text-align: center; color: var(--gray); padding: 25px;">Aucun pointage</p>
            <?php endif; ?>
        </div>

        <!-- Recent Overtime -->
        <div class="section">
            <h3 class="section-title">
                <i class="fas fa-clock"></i>
                Heures Supplémentaires
            </h3>
            
            <?php if(count($recent_overtime) > 0): ?>
                <?php foreach($recent_overtime as $ot): ?>
                <div class="history-item">
                    <div class="history-header">
                        <span class="history-date"><?= date('d/m/Y', strtotime($ot['work_date'])) ?></span>
                        <span class="badge badge-success">Validé</span>
                    </div>
                    <div class="history-content">
                        <strong style="font-size: 16px; color: var(--success);">
                            <?= $ot['hours'] ?>h × <?= number_format($ot['rate_per_hour'], 0) ?> F = 
                            <?= number_format($ot['total_amount'], 0, ',', ' ') ?> F
                        </strong>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p style="text-align: center; color: var(--gray); padding: 25px;">Aucune heure sup</p>
            <?php endif; ?>
        </div>

    </div>

    <!-- Permissions & Advances Grid -->
    <div class="grid-2">
        
        <!-- Recent Permissions -->
        <div class="section">
            <h3 class="section-title">
                <i class="fas fa-file-alt"></i>
                Permissions
            </h3>
            
            <?php if(count($recent_permissions) > 0): ?>
                <?php foreach($recent_permissions as $perm): ?>
                <div class="history-item">
                    <div class="history-header">
                        <span class="history-date">
                            <?= date('d/m', strtotime($perm['start_date'])) ?> - 
                            <?= date('d/m', strtotime($perm['end_date'])) ?>
                        </span>
                        <span class="badge badge-<?= $perm['status']=='accepte'?'success':($perm['status']=='rejete'?'danger':'warning') ?>">
                            <?= ucfirst(str_replace('_', ' ', $perm['status'])) ?>
                        </span>
                    </div>
                    <div class="history-content">
                        <?= htmlspecialchars($perm['reason']) ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p style="text-align: center; color: var(--gray); padding: 25px;">Aucune permission</p>
            <?php endif; ?>
        </div>

        <!-- Recent Advances -->
        <div class="section">
            <h3 class="section-title">
                <i class="fas fa-hand-holding-usd"></i>
                Avances
            </h3>
            
            <?php if(count($recent_advances) > 0): ?>
                <?php foreach($recent_advances as $adv): ?>
                <div class="history-item">
                    <div class="history-header">
                        <span class="history-date"><?= date('d/m/Y', strtotime($adv['advance_date'])) ?></span>
                        <span class="badge badge-<?= $adv['status']=='approuve'?'success':($adv['status']=='rejete'?'danger':'warning') ?>">
                            <?= ucfirst($adv['status']) ?>
                        </span>
                    </div>
                    <div class="history-content">
                        <strong style="font-size: 18px; color: var(--primary);">
                            <?= number_format($adv['amount'], 0, ',', ' ') ?> F
                        </strong>
                        <p style="margin-top: 6px; font-size: 13px;"><?= htmlspecialchars($adv['reason']) ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p style="text-align: center; color: var(--gray); padding: 25px;">Aucune avance</p>
            <?php endif; ?>
        </div>

    </div>

</div>

<script>
// GRAPHIQUE 1: POINTAGES (Pie Chart)
const ctxAttendance = document.getElementById('chart-attendance').getContext('2d');
new Chart(ctxAttendance, {
    type: 'doughnut',
    data: {
        labels: ['À l\'heure', 'Retard'],
        datasets: [{
            data: [<?= $month_stats['on_time'] ?? 0 ?>, <?= $month_stats['late_count'] ?? 0 ?>],
            backgroundColor: [
                'rgba(16, 185, 129, 0.8)',
                'rgba(245, 158, 11, 0.8)'
            ],
            borderWidth: 2,
            borderColor: '#fff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom',
                labels: { 
                    font: { size: 11 },
                    padding: 10
                }
            }
        }
    }
});

// GRAPHIQUE 2: PERMISSIONS (Bar Chart)
const ctxPermissions = document.getElementById('chart-permissions').getContext('2d');
new Chart(ctxPermissions, {
    type: 'bar',
    data: {
        labels: ['En attente', 'Acceptées', 'Rejetées'],
        datasets: [{
            label: 'Permissions',
            data: [
                <?= $permission_stats['pending'] ?>, 
                <?= $permission_stats['approved'] ?>, 
                <?= $permission_stats['rejected'] ?>
            ],
            backgroundColor: [
                'rgba(245, 158, 11, 0.8)',
                'rgba(16, 185, 129, 0.8)',
                'rgba(239, 68, 68, 0.8)'
            ],
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false }
        },
        scales: {
            y: { 
                beginAtZero: true,
                ticks: { stepSize: 1 }
            }
        }
    }
});

// GRAPHIQUE 3: AVANCES (Doughnut Chart)
const ctxAdvances = document.getElementById('chart-advances').getContext('2d');
new Chart(ctxAdvances, {
    type: 'doughnut',
    data: {
        labels: ['En attente', 'Approuvées', 'Rejetées'],
        datasets: [{
            data: [
                <?= $advance_stats['pending'] ?>, 
                <?= $advance_stats['approved'] ?>, 
                <?= $advance_stats['rejected'] ?>
            ],
            backgroundColor: [
                'rgba(245, 158, 11, 0.8)',
                'rgba(16, 185, 129, 0.8)',
                'rgba(239, 68, 68, 0.8)'
            ],
            borderWidth: 2,
            borderColor: '#fff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom',
                labels: { 
                    font: { size: 11 },
                    padding: 10
                }
            }
        }
    }
});

// GRAPHIQUE 4: HEURES SUP (Line Chart) - 7 derniers jours
const ctxOvertime = document.getElementById('chart-overtime').getContext('2d');
new Chart(ctxOvertime, {
    type: 'line',
    data: {
        labels: <?= json_encode(array_map(function($ot) {
            return date('d/m', strtotime($ot['work_date']));
        }, array_reverse(array_slice($recent_overtime, 0, 7)))) ?>,
        datasets: [{
            label: 'Heures',
            data: <?= json_encode(array_map(function($ot) {
                return $ot['hours'];
            }, array_reverse(array_slice($recent_overtime, 0, 7)))) ?>,
            borderColor: 'rgba(245, 158, 11, 1)',
            backgroundColor: 'rgba(245, 158, 11, 0.2)',
            tension: 0.4,
            fill: true,
            borderWidth: 2
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false }
        },
        scales: {
            y: { 
                beginAtZero: true,
                ticks: { stepSize: 1 }
            }
        }
    }
});
</script>

</body>
</html>
