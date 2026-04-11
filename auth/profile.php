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

/* ========================= CRÉER PROFILE SI INEXISTANT ========================= */
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

/* ========================= INFOS UTILISATEUR ========================= */
$stmt = $pdo->prepare("
    SELECT u.*, c.name AS company_name
    FROM users u
    LEFT JOIN companies c ON c.id = u.company_id
    WHERE u.id = ?
");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

/* ========================= STATISTIQUES UTILISATEUR ========================= */
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM invoices 
    WHERE company_id = ? 
    AND DATE(created_at) >= DATE_SUB(NOW(), INTERVAL 30 DAY)
");
$stmt->execute([$user['company_id']]);
$sales_count = $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT SUM(total) FROM invoices 
    WHERE company_id = ? 
    AND status = 'Payée'
    AND DATE(created_at) >= DATE_SUB(NOW(), INTERVAL 30 DAY)
");
$stmt->execute([$user['company_id']]);
$revenue = $stmt->fetchColumn() ?? 0;

$stmt = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE company_id = ?");
$stmt->execute([$user['company_id']]);
$clients_count = $stmt->fetchColumn();

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

/* ========================= ÉQUIPE ========================= */
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

/* ========================= TRAITEMENT FORMULAIRES ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $avatarName = $profile['avatar'];
    if (!empty($_FILES['avatar']['name'])) {
        $uploadDir = 'uploads/avatars/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        $avatarName = $uploadDir . uniqid() . '_' . basename($_FILES['avatar']['name']);
        move_uploaded_file($_FILES['avatar']['tmp_name'], $avatarName);
    }

    $coverName = $profile['cover_photo'];
    if (!empty($_FILES['cover_photo']['name'])) {
        $uploadDir = 'uploads/covers/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        $coverName = $uploadDir . uniqid() . '_' . basename($_FILES['cover_photo']['name']);
        move_uploaded_file($_FILES['cover_photo']['tmp_name'], $coverName);
    }

    $bio = trim($_POST['bio'] ?? '');
    $stmt = $pdo->prepare("UPDATE profiles SET avatar = ?, cover_photo = ?, bio = ? WHERE user_id = ?");
    $stmt->execute([$avatarName, $coverName, $bio, $userId]);

    header("Location: profile.php");
    exit;
}

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

$active_tab = $_GET['tab'] ?? 'overview';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes, maximum-scale=5.0">
<title>Profil - <?= htmlspecialchars($user['username']) ?></title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }

:root {
    --primary-color: #1877f2;
    --hover-color: #166fe5;
    --bg-color: #f0f2f5;
    --card-bg: white;
    --text-primary: #050505;
    --text-secondary: #65676b;
    --border-color: #e4e6eb;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
    background: var(--bg-color);
    min-height: 100vh;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}

/* =============== MOBILE-FIRST NAVIGATION =============== */
.top-nav {
    background: var(--card-bg);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    padding: 12px 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: sticky;
    top: 0;
    z-index: 1000;
}

.logo {
    font-size: 20px;
    font-weight: bold;
    color: var(--primary-color);
    display: flex;
    align-items: center;
    gap: 8px;
}

.mobile-menu-toggle {
    display: none;
    background: none;
    border: none;
    font-size: 24px;
    color: var(--text-primary);
    cursor: pointer;
    padding: 8px;
}

.nav-links {
    display: flex;
    gap: 8px;
}

.nav-links a {
    color: var(--text-secondary);
    text-decoration: none;
    padding: 8px 12px;
    border-radius: 8px;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 14px;
}

.nav-links a:hover {
    background: var(--bg-color);
    color: var(--text-primary);
}

/* =============== PROFILE HEADER =============== */
.profile-header {
    background: var(--card-bg);
    border-radius: 0 0 12px 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    overflow: hidden;
    margin-bottom: 16px;
}

.cover-photo {
    width: 100%;
    height: 280px;
    object-fit: cover;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 48px;
    position: relative;
}

.cover-photo img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.profile-info-section {
    padding: 0 20px 16px;
    position: relative;
}

.avatar-section {
    display: flex;
    align-items: flex-start;
    gap: 16px;
    margin-top: -50px;
    padding-bottom: 16px;
    flex-wrap: wrap;
}

.avatar {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    border: 4px solid white;
    background: white;
    object-fit: cover;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    flex-shrink: 0;
}

.profile-details {
    flex: 1;
    padding-top: 40px;
    min-width: 200px;
}

.profile-details h1 {
    font-size: 24px;
    color: var(--text-primary);
    margin-bottom: 6px;
    word-break: break-word;
}

.profile-details .subtitle {
    color: var(--text-secondary);
    font-size: 14px;
    margin-bottom: 8px;
}

.role-badge {
    display: inline-block;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: bold;
    margin-top: 6px;
}

.profile-bio {
    color: var(--text-primary);
    margin-top: 12px;
    line-height: 1.5;
    font-size: 14px;
}

.profile-actions {
    display: flex;
    gap: 8px;
    margin-top: 12px;
    flex-wrap: wrap;
}

.btn {
    padding: 10px 18px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    text-decoration: none;
    font-size: 14px;
    white-space: nowrap;
}

.btn-primary {
    background: var(--primary-color);
    color: white;
}

.btn-primary:hover {
    background: var(--hover-color);
}

.btn-secondary {
    background: var(--border-color);
    color: var(--text-primary);
}

.btn-secondary:hover {
    background: #d8dadf;
}

/* =============== TABS MOBILE-FRIENDLY =============== */
.profile-tabs {
    border-top: 1px solid var(--border-color);
    display: flex;
    gap: 4px;
    padding: 0 16px;
    overflow-x: auto;
    overflow-y: hidden;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
}

.profile-tabs::-webkit-scrollbar {
    display: none;
}

.profile-tabs a {
    padding: 14px 16px;
    color: var(--text-secondary);
    text-decoration: none;
    border-bottom: 3px solid transparent;
    transition: all 0.2s;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 14px;
    white-space: nowrap;
    flex-shrink: 0;
}

.profile-tabs a:hover {
    background: var(--bg-color);
    border-radius: 8px 8px 0 0;
}

.profile-tabs a.active {
    color: var(--primary-color);
    border-bottom-color: var(--primary-color);
}

/* =============== CONTENT LAYOUT =============== */
.profile-content {
    max-width: 1200px;
    margin: 0 auto;
    padding: 16px;
    display: grid;
    grid-template-columns: 1fr;
    gap: 16px;
}

.sidebar, .main-content {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.card {
    background: var(--card-bg);
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.card h3 {
    font-size: 18px;
    color: var(--text-primary);
    margin-bottom: 16px;
}

/* =============== STATS GRID =============== */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
}

.stat-item {
    text-align: center;
    padding: 16px 12px;
    background: var(--bg-color);
    border-radius: 10px;
}

.stat-value {
    font-size: 24px;
    font-weight: bold;
    color: var(--primary-color);
    word-break: break-word;
}

.stat-label {
    font-size: 12px;
    color: var(--text-secondary);
    margin-top: 4px;
}

/* =============== INFO ITEMS =============== */
.info-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 0;
    border-bottom: 1px solid var(--border-color);
}

.info-item:last-child {
    border-bottom: none;
}

.info-item i {
    color: var(--text-secondary);
    width: 24px;
    text-align: center;
    font-size: 16px;
}

.info-item .label {
    color: var(--text-secondary);
    font-size: 13px;
}

.info-item .value {
    color: var(--text-primary);
    font-weight: 600;
    word-break: break-word;
}

/* =============== ACTIVITY FEED =============== */
.activity-item {
    display: flex;
    gap: 12px;
    padding: 16px 0;
    border-bottom: 1px solid var(--border-color);
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
    min-width: 0;
}

.activity-content .title {
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 4px;
}

.activity-content .details {
    color: var(--text-secondary);
    font-size: 13px;
    word-break: break-word;
}

.activity-content .time {
    color: var(--text-secondary);
    font-size: 12px;
    margin-top: 4px;
}

/* =============== TEAM GRID =============== */
.team-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 12px;
}

.team-member {
    background: var(--bg-color);
    border-radius: 12px;
    padding: 16px;
    text-align: center;
    transition: all 0.2s;
}

.team-member:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    transform: translateY(-2px);
}

.team-member img {
    width: 70px;
    height: 70px;
    border-radius: 50%;
    margin-bottom: 12px;
    object-fit: cover;
}

.team-member .name {
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 4px;
    font-size: 14px;
    word-break: break-word;
}

.team-member .role {
    color: var(--text-secondary);
    font-size: 12px;
}

/* =============== FORMS =============== */
.form-group {
    margin-bottom: 16px;
}

.form-group label {
    display: block;
    color: var(--text-primary);
    font-weight: 600;
    margin-bottom: 8px;
    font-size: 14px;
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
    font-family: inherit;
}

.form-group input:focus,
.form-group textarea:focus,
.form-group select:focus {
    outline: none;
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(24, 119, 242, 0.1);
}

.form-group textarea {
    resize: vertical;
    min-height: 100px;
}

.file-upload {
    position: relative;
    display: inline-block;
    width: 100%;
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
    justify-content: center;
    gap: 8px;
    padding: 12px 20px;
    background: var(--border-color);
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
    width: 100%;
    font-weight: 600;
}

.file-upload-label:hover {
    background: #d8dadf;
}

.success-message, .error-message {
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
}

.success-message {
    background: #d4edda;
    color: #155724;
}

.error-message {
    background: #f8d7da;
    color: #721c24;
}

/* =============== ADD USER FORM MOBILE =============== */
.add-user-form {
    background: var(--bg-color);
    padding: 16px;
    border-radius: 10px;
    margin-bottom: 20px;
}

.add-user-form h4 {
    margin-bottom: 12px;
    font-size: 16px;
}

.add-user-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 10px;
}

/* =============== OVERVIEW CARDS =============== */
.overview-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 12px;
    margin-top: 20px;
}

.overview-card {
    padding: 20px;
    border-radius: 10px;
}

.overview-card.blue { background: #e7f3ff; }
.overview-card.green { background: #e8f5e9; }
.overview-card.orange { background: #fff3e0; }

.overview-card .icon {
    font-size: 32px;
    margin-bottom: 8px;
}

.overview-card .title {
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 4px;
    font-size: 14px;
}

.overview-card .value {
    font-size: 22px;
    font-weight: bold;
    margin-top: 4px;
}

.overview-card.blue .value { color: #1877f2; }
.overview-card.green .value { color: #4caf50; }
.overview-card.orange .value { color: #ff9800; }

/* =============== RESPONSIVE BREAKPOINTS =============== */

/* Small phones: 320px - 479px */
@media (max-width: 479px) {
    .logo {
        font-size: 16px;
    }
    
    .nav-links a {
        padding: 6px 8px;
        font-size: 12px;
    }
    
    .nav-links a span {
        display: none;
    }
    
    .cover-photo {
        height: 180px;
        font-size: 36px;
    }
    
    .avatar {
        width: 90px;
        height: 90px;
        border: 3px solid white;
    }
    
    .avatar-section {
        margin-top: -45px;
        flex-direction: column;
        align-items: center;
        text-align: center;
    }
    
    .profile-details {
        padding-top: 12px;
        width: 100%;
    }
    
    .profile-details h1 {
        font-size: 20px;
    }
    
    .profile-actions {
        justify-content: center;
        width: 100%;
    }
    
    .btn {
        flex: 1;
        justify-content: center;
        min-width: 0;
    }
    
    .btn span {
        display: none;
    }
    
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .stat-value {
        font-size: 20px;
    }
    
    .team-grid {
        grid-template-columns: 1fr;
    }
}

/* Medium phones: 480px - 767px */
@media (min-width: 480px) and (max-width: 767px) {
    .cover-photo {
        height: 220px;
    }
    
    .avatar {
        width: 110px;
        height: 110px;
    }
    
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .team-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .overview-grid {
        grid-template-columns: 1fr;
    }
}

/* Tablets: 768px - 1023px */
@media (min-width: 768px) and (max-width: 1023px) {
    .profile-content {
        grid-template-columns: 300px 1fr;
        padding: 20px;
    }
    
    .cover-photo {
        height: 250px;
    }
    
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .team-grid {
        grid-template-columns: repeat(3, 1fr);
    }
    
    .overview-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .add-user-grid {
        grid-template-columns: 1fr 1fr;
    }
}

/* Desktop: 1024px+ */
@media (min-width: 1024px) {
    .profile-content {
        grid-template-columns: 360px 1fr;
    }
    
    .cover-photo {
        height: 350px;
    }
    
    .avatar {
        width: 168px;
        height: 168px;
        border: 6px solid white;
    }
    
    .avatar-section {
        margin-top: -80px;
    }
    
    .profile-details {
        padding-top: 60px;
    }
    
    .profile-details h1 {
        font-size: 32px;
    }
    
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .team-grid {
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    }
    
    .overview-grid {
        grid-template-columns: repeat(3, 1fr);
    }
    
    .add-user-grid {
        grid-template-columns: 1fr 1fr 1fr auto;
    }
}

/* Very large screens: 1440px+ */
@media (min-width: 1440px) {
    .profile-content {
        max-width: 1400px;
    }
    
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

/* Landscape mode on phones */
@media (max-height: 500px) and (orientation: landscape) {
    .cover-photo {
        height: 150px;
    }
    
    .avatar {
        width: 80px;
        height: 80px;
    }
    
    .avatar-section {
        margin-top: -40px;
    }
}

/* Touch-friendly tap areas */
@media (hover: none) and (pointer: coarse) {
    .btn, .nav-links a, .profile-tabs a {
        min-height: 44px;
        min-width: 44px;
    }
}
</style>
</head>
<body>

<!-- TOP NAVIGATION -->
<div class="top-nav">
    <div class="logo">
        <i class="fas fa-water"></i> 
        <span>ESPERANCE H2O</span>
    </div>
    <button class="mobile-menu-toggle" id="menuToggle">
        <i class="fas fa-bars"></i>
    </button>
    <div class="nav-links" id="navLinks">
        <a href="<?= project_url('dashboard/index.php') ?>"><i class="fas fa-home"></i> <span>Accueil</span></a>
        <a href="<?= project_url('finance/caisse_complete_enhanced.php') ?>"><i class="fas fa-cash-register"></i> <span>Caisse</span></a>
        <a href="<?= project_url('stock/stock_tracking.php') ?>"><i class="fas fa-boxes"></i> <span>Stock</span></a>
        <a href="<?= project_url('clients/clients_erp_pro.php') ?>"><i class="fas fa-users"></i> <span>Clients</span></a>
        <a href="<?= project_url('auth/logout.php') ?>"><i class="fas fa-sign-out-alt"></i> <span>Déconnexion</span></a>
    </div>
</div>

<!-- PROFILE HEADER -->
<div class="profile-header">
    <div class="cover-photo">
        <?php if (!empty($profile['cover_photo']) && file_exists($profile['cover_photo'])): ?>
            <img src="<?= htmlspecialchars($profile['cover_photo']) ?>" alt="Cover">
        <?php else: ?>
            <i class="fas fa-image"></i>
        <?php endif; ?>
    </div>
    
    <div class="profile-info-section">
        <div class="avatar-section">
            <?php if (!empty($profile['avatar']) && file_exists($profile['avatar'])): ?>
                <img src="<?= htmlspecialchars($profile['avatar']) ?>" alt="Avatar" class="avatar">
            <?php else: ?>
                <div class="avatar" style="display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; font-size: 48px; font-weight: bold;">
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
                        'admin' => '👑 Admin',
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
                        <i class="fas fa-edit"></i> <span>Modifier</span>
                    </a>
                    <a href="?tab=settings" class="btn btn-secondary">
                        <i class="fas fa-cog"></i> <span>Paramètres</span>
                    </a>
                </div>
            </div>
        </div>
        
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
        <div class="card">
            <h3>📊 Mes Stats (30j)</h3>
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
                        <?= (new DateTime())->diff(new DateTime($user['created_at']))->days ?>
                    </div>
                    <div class="stat-label">Jours actif</div>
                </div>
            </div>
        </div>
        
        <div class="card">
            <h3>ℹ️ Informations</h3>
            <div class="info-item">
                <i class="fas fa-building"></i>
                <div style="flex: 1; min-width: 0;">
                    <div class="label">Société</div>
                    <div class="value"><?= htmlspecialchars($user['company_name'] ?? 'Non défini') ?></div>
                </div>
            </div>
            <div class="info-item">
                <i class="fas fa-user-tag"></i>
                <div style="flex: 1; min-width: 0;">
                    <div class="label">Rôle</div>
                    <div class="value"><?= htmlspecialchars($user['role']) ?></div>
                </div>
            </div>
            <div class="info-item">
                <i class="fas fa-calendar"></i>
                <div style="flex: 1; min-width: 0;">
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
            <p style="color: var(--text-secondary); margin-top: 12px; font-size: 14px;">
                Bienvenue sur votre profil ! Consultez vos statistiques et gérez votre espace.
            </p>
            
            <div class="overview-grid">
                <div class="overview-card blue">
                    <div class="icon">💰</div>
                    <div class="title">Revenu total</div>
                    <div class="value"><?= number_format($revenue, 0) ?> CFA</div>
                </div>
                
                <div class="overview-card green">
                    <div class="icon">📦</div>
                    <div class="title">Ventes réalisées</div>
                    <div class="value"><?= number_format($sales_count) ?></div>
                </div>
                
                <div class="overview-card orange">
                    <div class="icon">👥</div>
                    <div class="title">Clients gérés</div>
                    <div class="value"><?= number_format($clients_count) ?></div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- TAB: ACTIVITY -->
        <?php if ($active_tab === 'activity'): ?>
        <div class="card">
            <h3>🕐 Activité récente</h3>
            
            <?php if (empty($recent_activities)): ?>
            <p style="color: var(--text-secondary); text-align: center; padding: 40px 20px; font-size: 14px;">
                Aucune activité récente
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
                            <strong><?= htmlspecialchars($activity['client_name']) ?></strong> • 
                            <strong><?= number_format($activity['total'], 0) ?> CFA</strong>
                        </div>
                        <div class="time">
                            <i class="far fa-clock"></i> 
                            <?php
                            $diff = (new DateTime())->diff(new DateTime($activity['created_at']));
                            if ($diff->d > 0) echo "Il y a " . $diff->d . " jour" . ($diff->d > 1 ? 's' : '');
                            elseif ($diff->h > 0) echo "Il y a " . $diff->h . " h";
                            else echo "Il y a " . $diff->i . " min";
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
            
            <form method="post" class="add-user-form">
                <h4>➕ Ajouter un membre</h4>
                <div class="add-user-grid">
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
                        <i class="fas fa-plus"></i> <span>Ajouter</span>
                    </button>
                </div>
            </form>
            
            <div class="team-grid">
                <?php foreach ($team_members as $member): ?>
                <div class="team-member">
                    <?php if (!empty($member['avatar']) && file_exists($member['avatar'])): ?>
                        <img src="<?= htmlspecialchars($member['avatar']) ?>" alt="Avatar">
                    <?php else: ?>
                        <div style="width: 70px; height: 70px; border-radius: 50%; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; display: flex; align-items: center; justify-content: center; font-size: 28px; font-weight: bold; margin: 0 auto 12px;">
                            <?= strtoupper(substr($member['username'], 0, 1)) ?>
                        </div>
                    <?php endif; ?>
                    <div class="name"><?= htmlspecialchars($member['username']) ?></div>
                    <div class="role"><?= htmlspecialchars($member['role']) ?></div>
                    <div style="color: var(--text-secondary); font-size: 11px; margin-top: 4px;">
                        <?= date('M Y', strtotime($member['created_at'])) ?>
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
                
                <button type="submit" name="update_profile" class="btn btn-primary" style="width: 100%;">
                    <i class="fas fa-save"></i> Enregistrer
                </button>
            </form>
        </div>
        <?php endif; ?>
        
        <!-- TAB: SETTINGS -->
        <?php if ($active_tab === 'settings'): ?>
        <div class="card">
            <h3>🔒 Sécurité</h3>
            
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
                    <label>Confirmer</label>
                    <input type="password" name="confirm_password" required>
                </div>
                
                <button type="submit" name="change_password" class="btn btn-primary" style="width: 100%;">
                    <i class="fas fa-key"></i> Changer le mot de passe
                </button>
            </form>
        </div>
        <?php endif; ?>
        
    </div>
</div>

<script>
// Mobile menu toggle (si besoin d'un menu burger plus tard)
const menuToggle = document.getElementById('menuToggle');
const navLinks = document.getElementById('navLinks');

if (menuToggle) {
    menuToggle.addEventListener('click', () => {
        navLinks.classList.toggle('active');
    });
}

// Smooth scroll for tabs on mobile
const tabsContainer = document.querySelector('.profile-tabs');
if (tabsContainer) {
    const activeTab = tabsContainer.querySelector('.active');
    if (activeTab) {
        activeTab.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
    }
}
</script>

</body>
</html>
