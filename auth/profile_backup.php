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

Auth::check();
Middleware::role(['developer', 'admin', 'manager', 'staff', 'viewer']);

$pdo = DB::getConnection();
$userId = $_SESSION['user_id'];

/* =========================
   CRÉER PROFILE SI INEXISTANT
========================= */
$stmt = $pdo->prepare("SELECT * FROM profiles WHERE user_id = ?");
$stmt->execute([$userId]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$profile) {
    $stmt = $pdo->prepare("INSERT INTO profiles (user_id) VALUES (?)");
    $stmt->execute([$userId]);
    $stmt = $pdo->prepare("SELECT * FROM profiles WHERE user_id = ?");
    $stmt->execute([$userId]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
}

/* =========================
   INFOS UTILISATEUR
========================= */
$stmt = $pdo->prepare("
    SELECT u.*, c.name AS company_name
    FROM users u
    LEFT JOIN companies c ON c.id = u.company_id
    WHERE u.id = ?
");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

/* =========================
   STATISTIQUES UTILISATEUR
========================= */
// Nombre de ventes réalisées
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM invoices 
    WHERE company_id = ? 
    AND DATE(created_at) >= DATE_SUB(NOW(), INTERVAL 30 DAY)
");
$stmt->execute([$user['company_id']]);
$sales_count = $stmt->fetchColumn();

// Chiffre d'affaires généré
$stmt = $pdo->prepare("
    SELECT SUM(total) FROM invoices 
    WHERE company_id = ? 
    AND status = 'Payée'
    AND DATE(created_at) >= DATE_SUB(NOW(), INTERVAL 30 DAY)
");
$stmt->execute([$user['company_id']]);
$revenue = $stmt->fetchColumn() ?? 0;

// Nombre de clients gérés
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM clients 
    WHERE company_id = ?
");
$stmt->execute([$user['company_id']]);
$clients_count = $stmt->fetchColumn();

// Activité récente
$stmt = $pdo->prepare("
    SELECT 
        i.id,
        i.total,
        i.created_at,
        c.name AS client_name,
        'vente' AS type
    FROM invoices i
    JOIN clients c ON c.id = i.client_id
    WHERE i.company_id = ?
    ORDER BY i.created_at DESC
    LIMIT 10
");
$stmt->execute([$user['company_id']]);
$recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   ÉQUIPE (pour admin/manager)
========================= */
$team_members = [];
if (in_array($user['role'], ['admin', 'developer', 'manager'])) {
    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.role, u.created_at, p.avatar
        FROM users u
        LEFT JOIN profiles p ON p.user_id = u.id
        WHERE u.company_id = ?
        ORDER BY u.username
    ");
    $stmt->execute([$user['company_id']]);
    $team_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* =========================
   TRAITEMENT FORMULAIRE
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    
    // Upload Avatar
    $avatarName = $profile['avatar'];
    if (!empty($_FILES['avatar']['name'])) {
        $uploadDir = 'uploads/avatars/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        $avatarName = $uploadDir . uniqid() . '_' . basename($_FILES['avatar']['name']);
        move_uploaded_file($_FILES['avatar']['tmp_name'], $avatarName);
    }

    // Upload Cover
    $coverName = $profile['cover_photo'];
    if (!empty($_FILES['cover_photo']['name'])) {
        $uploadDir = 'uploads/covers/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        $coverName = $uploadDir . uniqid() . '_' . basename($_FILES['cover_photo']['name']);
        move_uploaded_file($_FILES['cover_photo']['tmp_name'], $coverName);
    }

    // Bio
    $bio = trim($_POST['bio'] ?? '');

    // Update Profile
    $stmt = $pdo->prepare("UPDATE profiles SET avatar = ?, cover_photo = ?, bio = ? WHERE user_id = ?");
    $stmt->execute([$avatarName, $coverName, $bio, $userId]);

    header("Location: profile.php");
    exit;
}

/* =========================
   CHANGER MOT DE PASSE
========================= */
$passwordMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $currentPass = $_POST['current_password'];
    $newPass = $_POST['new_password'];
    $confirmPass = $_POST['confirm_password'];
    
    if ($newPass !== $confirmPass) {
        $passwordMsg = "Les mots de passe ne correspondent pas !";
    } else {
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $userPass = $stmt->fetchColumn();
        
        if (password_verify($currentPass, $userPass)) {
            $newHash = password_hash($newPass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$newHash, $userId]);
            $passwordMsg = "Mot de passe mis à jour avec succès !";
        } else {
            $passwordMsg = "Mot de passe actuel incorrect !";
        }
    }
}

/* =========================
   AJOUTER UTILISATEUR (admin)
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $stmt = $pdo->prepare("
        INSERT INTO users (username, password, role, company_id)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([
        trim($_POST['new_username']),
        password_hash($_POST['new_password_user'], PASSWORD_DEFAULT),
        $_POST['new_role'],
        $user['company_id']
    ]);
    header("Location: profile.php?tab=team");
    exit;
}

/* =========================
   ONGLET ACTIF
========================= */
$active_tab = $_GET['tab'] ?? 'overview';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Profil - <?= htmlspecialchars($user['username']) ?></title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }

body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: #f0f2f5;
    min-height: 100vh;
}

/* HEADER */
.top-nav {
    background: white;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    padding: 12px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: sticky;
    top: 0;
    z-index: 1000;
}

.top-nav .logo {
    font-size: 24px;
    font-weight: bold;
    color: #1877f2;
}

.top-nav .nav-links {
    display: flex;
    gap: 15px;
}

.top-nav .nav-links a {
    color: #65676b;
    text-decoration: none;
    padding: 8px 16px;
    border-radius: 8px;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 8px;
}

.top-nav .nav-links a:hover {
    background: #f0f2f5;
}

/* PROFILE HEADER */
.profile-header {
    background: white;
    border-radius: 0 0 12px 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    overflow: hidden;
}

.cover-photo {
    width: 100%;
    height: 350px;
    object-fit: cover;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 48px;
}

.cover-photo img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.profile-info-section {
    padding: 0 40px;
    position: relative;
}

.avatar-section {
    display: flex;
    align-items: flex-end;
    gap: 20px;
    margin-top: -80px;
    padding-bottom: 20px;
}

.avatar {
    width: 168px;
    height: 168px;
    border-radius: 50%;
    border: 6px solid white;
    background: white;
    object-fit: cover;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.profile-details {
    flex: 1;
    padding-top: 60px;
}

.profile-details h1 {
    font-size: 32px;
    color: #050505;
    margin-bottom: 8px;
}

.profile-details .subtitle {
    color: #65676b;
    font-size: 15px;
    margin-bottom: 4px;
}

.profile-details .role-badge {
    display: inline-block;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 6px 16px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: bold;
    margin-top: 8px;
}

.profile-bio {
    color: #050505;
    margin-top: 12px;
    line-height: 1.6;
}

.profile-actions {
    display: flex;
    gap: 10px;
    margin-top: 16px;
}

.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
}

.btn-primary {
    background: #1877f2;
    color: white;
}

.btn-primary:hover {
    background: #166fe5;
}

.btn-secondary {
    background: #e4e6eb;
    color: #050505;
}

.btn-secondary:hover {
    background: #d8dadf;
}

/* TABS */
.profile-tabs {
    border-top: 1px solid #e4e6eb;
    display: flex;
    gap: 8px;
    padding: 0 40px;
}

.profile-tabs a {
    padding: 16px 16px;
    color: #65676b;
    text-decoration: none;
    border-bottom: 3px solid transparent;
    transition: all 0.2s;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

.profile-tabs a:hover {
    background: #f0f2f5;
    border-radius: 8px 8px 0 0;
}

.profile-tabs a.active {
    color: #1877f2;
    border-bottom-color: #1877f2;
}

/* CONTENT */
.profile-content {
    max-width: 1200px;
    margin: 20px auto;
    padding: 0 20px;
    display: grid;
    grid-template-columns: 360px 1fr;
    gap: 20px;
}

.sidebar {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.card h3 {
    font-size: 18px;
    color: #050505;
    margin-bottom: 16px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
}

.stat-item {
    text-align: center;
    padding: 16px;
    background: #f0f2f5;
    border-radius: 10px;
}

.stat-value {
    font-size: 28px;
    font-weight: bold;
    color: #1877f2;
}

.stat-label {
    font-size: 13px;
    color: #65676b;
    margin-top: 4px;
}

.info-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 0;
    border-bottom: 1px solid #e4e6eb;
}

.info-item:last-child {
    border-bottom: none;
}

.info-item i {
    color: #65676b;
    width: 24px;
    text-align: center;
}

.info-item .label {
    color: #65676b;
    font-size: 13px;
}

.info-item .value {
    color: #050505;
    font-weight: 600;
}

/* MAIN CONTENT */
.main-content {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

/* ACTIVITY FEED */
.activity-item {
    display: flex;
    gap: 12px;
    padding: 16px;
    border-bottom: 1px solid #e4e6eb;
}

.activity-item:last-child {
    border-bottom: none;
}

.activity-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    flex-shrink: 0;
}

.activity-content {
    flex: 1;
}

.activity-content .title {
    font-weight: 600;
    color: #050505;
    margin-bottom: 4px;
}

.activity-content .details {
    color: #65676b;
    font-size: 13px;
}

.activity-content .time {
    color: #65676b;
    font-size: 12px;
    margin-top: 4px;
}

/* TEAM GRID */
.team-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 16px;
}

.team-member {
    background: #f0f2f5;
    border-radius: 12px;
    padding: 20px;
    text-align: center;
    transition: all 0.2s;
}

.team-member:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    transform: translateY(-2px);
}

.team-member img {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    margin-bottom: 12px;
    object-fit: cover;
}

.team-member .name {
    font-weight: 600;
    color: #050505;
    margin-bottom: 4px;
}

.team-member .role {
    color: #65676b;
    font-size: 13px;
}

/* FORMS */
.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    color: #050505;
    font-weight: 600;
    margin-bottom: 8px;
}

.form-group input,
.form-group textarea,
.form-group select {
    width: 100%;
    padding: 12px;
    border: 1px solid #ccd0d5;
    border-radius: 8px;
    font-size: 15px;
    transition: all 0.2s;
}

.form-group input:focus,
.form-group textarea:focus,
.form-group select:focus {
    outline: none;
    border-color: #1877f2;
    box-shadow: 0 0 0 3px rgba(24, 119, 242, 0.1);
}

.form-group textarea {
    resize: vertical;
    min-height: 100px;
}

.file-upload {
    position: relative;
    display: inline-block;
}

.file-upload input[type="file"] {
    position: absolute;
    opacity: 0;
    width: 100%;
    height: 100%;
    cursor: pointer;
}

.file-upload-label {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    background: #e4e6eb;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
}

.file-upload-label:hover {
    background: #d8dadf;
}

.success-message {
    background: #d4edda;
    color: #155724;
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.error-message {
    background: #f8d7da;
    color: #721c24;
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}

/* RESPONSIVE */
@media (max-width: 1024px) {
    .profile-content {
        grid-template-columns: 1fr;
    }
    
    .profile-info-section {
        padding: 0 20px;
    }
    
    .profile-tabs {
        padding: 0 20px;
        overflow-x: auto;
    }
}

@media (max-width: 768px) {
    .cover-photo {
        height: 200px;
    }
    
    .avatar {
        width: 120px;
        height: 120px;
    }
    
    .avatar-section {
        margin-top: -60px;
    }
    
    .profile-details h1 {
        font-size: 24px;
    }
    
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .team-grid {
        grid-template-columns: 1fr;
    }
}
</style>
</head>
<body>

<!-- TOP NAVIGATION -->
<div class="top-nav">
    <div class="logo">
        <i class="fas fa-water"></i> ESPERANCE H2O
    </div>
    <div class="nav-links">
        <a href="<?= project_url('dashboard/index.php') ?>"><i class="fas fa-home"></i> Accueil</a>
        <a href="<?= project_url('finance/caisse_complete_enhanced.php') ?>"><i class="fas fa-cash-register"></i> Caisse</a>
        <a href="<?= project_url('stock/stock_tracking.php') ?>"><i class="fas fa-boxes"></i> Stock</a>
        <a href="<?= project_url('clients/clients_erp_pro.php') ?>"><i class="fas fa-users"></i> Clients</a>
        <a href="<?= project_url('auth/logout.php') ?>"><i class="fas fa-sign-out-alt"></i> Déconnexion</a>
    </div>
</div>

<!-- PROFILE HEADER -->
<div class="profile-header">
    <!-- Cover Photo -->
    <div class="cover-photo">
        <?php if (!empty($profile['cover_photo']) && file_exists($profile['cover_photo'])): ?>
            <img src="<?= htmlspecialchars($profile['cover_photo']) ?>" alt="Cover">
        <?php else: ?>
            <i class="fas fa-image"></i>
        <?php endif; ?>
    </div>
    
    <!-- Profile Info -->
    <div class="profile-info-section">
        <div class="avatar-section">
            <?php if (!empty($profile['avatar']) && file_exists($profile['avatar'])): ?>
                <img src="<?= htmlspecialchars($profile['avatar']) ?>" alt="Avatar" class="avatar">
            <?php else: ?>
                <div class="avatar" style="display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; font-size: 64px; font-weight: bold;">
                    <?= strtoupper(substr($user['username'], 0, 1)) ?>
                </div>
            <?php endif; ?>
            
            <div class="profile-details">
                <h1><?= htmlspecialchars($user['username']) ?></h1>
                <p class="subtitle"><?= htmlspecialchars($user['company_name'] ?? 'Aucune société') ?></p>
                <span class="role-badge">
                    <?php
                    $roleIcons = [
                        'developer' => '💻 Développeur',
                        'admin' => '👑 Administrateur',
                        'manager' => '📊 Manager',
                        'staff' => '👤 Employé',
                        'viewer' => '👁️ Observateur'
                    ];
                    echo $roleIcons[$user['role']] ?? $user['role'];
                    ?>
                </span>
                
                <?php if (!empty($profile['bio'])): ?>
                <p class="profile-bio"><?= nl2br(htmlspecialchars($profile['bio'])) ?></p>
                <?php endif; ?>
                
                <div class="profile-actions">
                    <a href="?tab=edit" class="btn btn-primary">
                        <i class="fas fa-edit"></i> Modifier le profil
                    </a>
                    <a href="?tab=settings" class="btn btn-secondary">
                        <i class="fas fa-cog"></i> Paramètres
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Tabs -->
        <div class="profile-tabs">
            <a href="?tab=overview" class="<?= $active_tab === 'overview' ? 'active' : '' ?>">
                <i class="fas fa-chart-line"></i> Vue d'ensemble
            </a>
            <a href="?tab=activity" class="<?= $active_tab === 'activity' ? 'active' : '' ?>">
                <i class="fas fa-clock"></i> Activité
            </a>
            <?php if (in_array($user['role'], ['admin', 'developer', 'manager'])): ?>
            <a href="?tab=team" class="<?= $active_tab === 'team' ? 'active' : '' ?>">
                <i class="fas fa-users"></i> Équipe
            </a>
            <?php endif; ?>
            <a href="?tab=edit" class="<?= $active_tab === 'edit' ? 'active' : '' ?>">
                <i class="fas fa-edit"></i> Modifier
            </a>
            <a href="?tab=settings" class="<?= $active_tab === 'settings' ? 'active' : '' ?>">
                <i class="fas fa-cog"></i> Paramètres
            </a>
        </div>
    </div>
</div>

<!-- PROFILE CONTENT -->
<div class="profile-content">
    <!-- SIDEBAR -->
    <div class="sidebar">
        <!-- Stats Card -->
        <div class="card">
            <h3>📊 Mes Statistiques (30 jours)</h3>
            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-value"><?= number_format($sales_count) ?></div>
                    <div class="stat-label">Ventes</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?= number_format($revenue, 0) ?></div>
                    <div class="stat-label">CA (CFA)</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?= number_format($clients_count) ?></div>
                    <div class="stat-label">Clients</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value">
                        <?php
                        $daysSinceJoin = (new DateTime())->diff(new DateTime($user['created_at']))->days;
                        echo $daysSinceJoin;
                        ?>
                    </div>
                    <div class="stat-label">Jours actif</div>
                </div>
            </div>
        </div>
        
        <!-- Info Card -->
        <div class="card">
            <h3>ℹ️ Informations</h3>
            <div class="info-item">
                <i class="fas fa-building"></i>
                <div>
                    <div class="label">Société</div>
                    <div class="value"><?= htmlspecialchars($user['company_name'] ?? 'Non défini') ?></div>
                </div>
            </div>
            <div class="info-item">
                <i class="fas fa-user-tag"></i>
                <div>
                    <div class="label">Rôle</div>
                    <div class="value"><?= htmlspecialchars($user['role']) ?></div>
                </div>
            </div>
            <div class="info-item">
                <i class="fas fa-calendar"></i>
                <div>
                    <div class="label">Membre depuis</div>
                    <div class="value"><?= date('d/m/Y', strtotime($user['created_at'])) ?></div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- MAIN CONTENT -->
    <div class="main-content">
        
        <!-- TAB: OVERVIEW -->
        <?php if ($active_tab === 'overview'): ?>
        <div class="card">
            <h3>🎯 Tableau de bord</h3>
            <p style="color: #65676b; margin-top: 12px;">
                Bienvenue sur votre profil professionnel ! Ici vous pouvez consulter vos statistiques, 
                gérer votre équipe et personnaliser votre espace.
            </p>
            
            <div style="margin-top: 24px; display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 16px;">
                <div style="background: #e7f3ff; padding: 20px; border-radius: 10px;">
                    <div style="font-size: 32px; margin-bottom: 8px;">💰</div>
                    <div style="font-weight: 600; color: #050505;">Revenu total</div>
                    <div style="font-size: 24px; font-weight: bold; color: #1877f2; margin-top: 4px;">
                        <?= number_format($revenue, 0) ?> CFA
                    </div>
                </div>
                
                <div style="background: #e8f5e9; padding: 20px; border-radius: 10px;">
                    <div style="font-size: 32px; margin-bottom: 8px;">📦</div>
                    <div style="font-weight: 600; color: #050505;">Ventes réalisées</div>
                    <div style="font-size: 24px; font-weight: bold; color: #4caf50; margin-top: 4px;">
                        <?= number_format($sales_count) ?>
                    </div>
                </div>
                
                <div style="background: #fff3e0; padding: 20px; border-radius: 10px;">
                    <div style="font-size: 32px; margin-bottom: 8px;">👥</div>
                    <div style="font-weight: 600; color: #050505;">Clients gérés</div>
                    <div style="font-size: 24px; font-weight: bold; color: #ff9800; margin-top: 4px;">
                        <?= number_format($clients_count) ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- TAB: ACTIVITY -->
        <?php if ($active_tab === 'activity'): ?>
        <div class="card">
            <h3>🕐 Activité récente</h3>
            
            <?php if (empty($recent_activities)): ?>
            <p style="color: #65676b; text-align: center; padding: 40px 0;">
                Aucune activité récente à afficher
            </p>
            <?php else: ?>
                <?php foreach ($recent_activities as $activity): ?>
                <div class="activity-item">
                    <div class="activity-icon">
                        <i class="fas fa-shopping-cart"></i>
                    </div>
                    <div class="activity-content">
                        <div class="title">Vente réalisée</div>
                        <div class="details">
                            Client: <strong><?= htmlspecialchars($activity['client_name']) ?></strong> • 
                            Montant: <strong><?= number_format($activity['total'], 0) ?> CFA</strong>
                        </div>
                        <div class="time">
                            <i class="far fa-clock"></i> 
                            <?php
                            $date = new DateTime($activity['created_at']);
                            $now = new DateTime();
                            $diff = $now->diff($date);
                            
                            if ($diff->d > 0) {
                                echo "Il y a " . $diff->d . " jour" . ($diff->d > 1 ? 's' : '');
                            } elseif ($diff->h > 0) {
                                echo "Il y a " . $diff->h . " heure" . ($diff->h > 1 ? 's' : '');
                            } else {
                                echo "Il y a " . $diff->i . " minute" . ($diff->i > 1 ? 's' : '');
                            }
                            ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- TAB: TEAM -->
        <?php if ($active_tab === 'team' && in_array($user['role'], ['admin', 'developer'])): ?>
        <div class="card">
            <h3>👥 Gestion de l'équipe</h3>
            
            <!-- Add User Form -->
            <form method="post" style="background: #f0f2f5; padding: 20px; border-radius: 10px; margin-bottom: 24px;">
                <h4 style="margin-bottom: 16px;">➕ Ajouter un membre</h4>
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: 12px;">
                    <input type="text" name="new_username" placeholder="Nom d'utilisateur" required 
                           style="padding: 10px; border: 1px solid #ccd0d5; border-radius: 8px;">
                    <input type="password" name="new_password_user" placeholder="Mot de passe" required 
                           style="padding: 10px; border: 1px solid #ccd0d5; border-radius: 8px;">
                    <select name="new_role" required style="padding: 10px; border: 1px solid #ccd0d5; border-radius: 8px;">
                        <option value="staff">Employé</option>
                        <option value="manager">Manager</option>
                        <option value="admin">Admin</option>
                        <option value="developer">Développeur</option>
                        <option value="viewer">Observateur</option>
                    </select>
                    <button type="submit" name="add_user" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Ajouter
                    </button>
                </div>
            </form>
            
            <!-- Team Grid -->
            <div class="team-grid">
                <?php foreach ($team_members as $member): ?>
                <div class="team-member">
                    <?php if (!empty($member['avatar']) && file_exists($member['avatar'])): ?>
                        <img src="<?= htmlspecialchars($member['avatar']) ?>" alt="Avatar">
                    <?php else: ?>
                        <div style="width: 80px; height: 80px; border-radius: 50%; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; display: flex; align-items: center; justify-content: center; font-size: 32px; font-weight: bold; margin: 0 auto 12px;">
                            <?= strtoupper(substr($member['username'], 0, 1)) ?>
                        </div>
                    <?php endif; ?>
                    <div class="name"><?= htmlspecialchars($member['username']) ?></div>
                    <div class="role"><?= htmlspecialchars($member['role']) ?></div>
                    <div style="color: #65676b; font-size: 12px; margin-top: 4px;">
                        Depuis <?= date('M Y', strtotime($member['created_at'])) ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- TAB: EDIT -->
        <?php if ($active_tab === 'edit'): ?>
        <div class="card">
            <h3>✏️ Modifier le profil</h3>
            
            <form method="post" enctype="multipart/form-data">
                <div class="form-group">
                    <label>Photo de profil</label>
                    <div class="file-upload">
                        <input type="file" name="avatar" accept="image/*" id="avatar-input">
                        <label for="avatar-input" class="file-upload-label">
                            <i class="fas fa-camera"></i> Choisir une photo
                        </label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Photo de couverture</label>
                    <div class="file-upload">
                        <input type="file" name="cover_photo" accept="image/*" id="cover-input">
                        <label for="cover-input" class="file-upload-label">
                            <i class="fas fa-image"></i> Choisir une image
                        </label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Biographie</label>
                    <textarea name="bio" placeholder="Parlez de vous..."><?= htmlspecialchars($profile['bio'] ?? '') ?></textarea>
                </div>
                
                <button type="submit" name="update_profile" class="btn btn-primary">
                    <i class="fas fa-save"></i> Enregistrer les modifications
                </button>
            </form>
        </div>
        <?php endif; ?>
        
        <!-- TAB: SETTINGS -->
        <?php if ($active_tab === 'settings'): ?>
        <div class="card">
            <h3>🔒 Sécurité et paramètres</h3>
            
            <?php if (!empty($passwordMsg)): ?>
            <div class="<?= strpos($passwordMsg, 'succès') !== false ? 'success-message' : 'error-message' ?>">
                <i class="fas fa-<?= strpos($passwordMsg, 'succès') !== false ? 'check-circle' : 'exclamation-circle' ?>"></i>
                <?= htmlspecialchars($passwordMsg) ?>
            </div>
            <?php endif; ?>
            
            <form method="post">
                <div class="form-group">
                    <label>Mot de passe actuel</label>
                    <input type="password" name="current_password" required>
                </div>
                
                <div class="form-group">
                    <label>Nouveau mot de passe</label>
                    <input type="password" name="new_password" required>
                </div>
                
                <div class="form-group">
                    <label>Confirmer le nouveau mot de passe</label>
                    <input type="password" name="confirm_password" required>
                </div>
                
                <button type="submit" name="change_password" class="btn btn-primary">
                    <i class="fas fa-key"></i> Changer le mot de passe
                </button>
            </form>
        </div>
        <?php endif; ?>
        
    </div>
</div>

</body>
</html>
