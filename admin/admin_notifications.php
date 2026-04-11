<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

/****************************************************************
* ADMIN NOTIFICATIONS WIDGET
* Version corrigée avec Auth standard
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

// Authentification standard
Auth::check();
Middleware::role(['developer','admin','manager']);

$pdo = DB::getConnection();

if(empty($_SESSION['csrf_token'])){
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// AJAX - Marquer comme lu
if(isset($_POST['mark_as_read']) && isset($_POST['notification_id'])){
    header('Content-Type: application/json');
    
    if(!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])){
        echo json_encode(['success' => false, 'error' => 'CSRF invalid']);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("UPDATE admin_notifications SET is_read = 1 WHERE id = ?");
        $stmt->execute([$_POST['notification_id']]);
        echo json_encode(['success' => true]);
    } catch(Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// AJAX - Marquer tout comme lu
if(isset($_POST['mark_all_read'])){
    header('Content-Type: application/json');
    
    if(!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])){
        echo json_encode(['success' => false, 'error' => 'CSRF invalid']);
        exit;
    }
    
    try {
        $pdo->query("UPDATE admin_notifications SET is_read = 1 WHERE is_read = 0");
        echo json_encode(['success' => true]);
    } catch(Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Récupérer les notifications non lues
$stmt = $pdo->prepare("
    SELECT n.*, e.full_name, e.employee_code
    FROM admin_notifications n
    JOIN employees e ON n.employee_id = e.id
    WHERE n.is_read = 0
    ORDER BY 
        CASE n.priority 
            WHEN 'high' THEN 1
            WHEN 'normal' THEN 2
            WHEN 'low' THEN 3
        END,
        n.created_at DESC
    LIMIT 50
");
$stmt->execute();
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Compter les notifications par priorité
$stmt = $pdo->query("
    SELECT 
        priority,
        COUNT(*) as count
    FROM admin_notifications
    WHERE is_read = 0
    GROUP BY priority
");
$priority_counts = [];
foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $priority_counts[$row['priority']] = $row['count'];
}

$total_unread = array_sum($priority_counts);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Notifications Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

:root {
    --primary: #32be8f;
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
    padding: 20px;
}

.notifications-container {
    max-width: 1200px;
    margin: 0 auto;
}

.notifications-header {
    background: white;
    padding: 30px;
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    margin-bottom: 30px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.header-title {
    display: flex;
    align-items: center;
    gap: 15px;
}

.header-title h1 {
    font-size: 32px;
    font-weight: 800;
    color: var(--dark);
}

.notification-badge {
    background: var(--danger);
    color: white;
    padding: 6px 16px;
    border-radius: 20px;
    font-size: 16px;
    font-weight: 700;
}

.priority-stats {
    display: flex;
    gap: 20px;
}

.priority-stat {
    text-align: center;
    padding: 12px 20px;
    border-radius: 12px;
}

.priority-stat.high {
    background: linear-gradient(135deg, #fee2e2, #fecaca);
    color: #991b1b;
}

.priority-stat.normal {
    background: linear-gradient(135deg, #dbeafe, #bfdbfe);
    color: #1e40af;
}

.priority-stat.low {
    background: linear-gradient(135deg, #d1fae5, #a7f3d0);
    color: #065f46;
}

.priority-stat .count {
    font-size: 24px;
    font-weight: 900;
}

.priority-stat .label {
    font-size: 12px;
    text-transform: uppercase;
    font-weight: 600;
}

.actions-bar {
    background: white;
    padding: 20px 30px;
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    margin-bottom: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.btn {
    padding: 12px 24px;
    border: none;
    border-radius: 10px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
}

.btn-primary {
    background: linear-gradient(135deg, var(--primary), #38d39f);
    color: white;
}

.btn-danger {
    background: linear-gradient(135deg, var(--danger), #dc2626);
    color: white;
}

.btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.2);
}

.notifications-list {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.notification-item {
    background: white;
    padding: 25px;
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    display: flex;
    align-items: flex-start;
    gap: 20px;
    transition: all 0.3s;
    position: relative;
    overflow: hidden;
}

.notification-item::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 5px;
}

.notification-item.high::before {
    background: #ef4444;
}

.notification-item.normal::before {
    background: #3b82f6;
}

.notification-item.low::before {
    background: #10b981;
}

.notification-item:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 30px rgba(0,0,0,0.12);
}

.notification-icon {
    width: 60px;
    height: 60px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    color: white;
    flex-shrink: 0;
}

.icon-check-in { background: linear-gradient(135deg, #10b981, #059669); }
.icon-check-out { background: linear-gradient(135deg, #3b82f6, #2563eb); }
.icon-overtime { background: linear-gradient(135deg, #f59e0b, #d97706); }
.icon-permission { background: linear-gradient(135deg, #8b5cf6, #7c3aed); }
.icon-advance { background: linear-gradient(135deg, #ec4899, #db2777); }

.notification-content {
    flex: 1;
}

.notification-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.employee-info {
    display: flex;
    align-items: center;
    gap: 12px;
}

.employee-name {
    font-size: 18px;
    font-weight: 700;
    color: var(--dark);
}

.employee-code {
    background: rgba(50, 190, 143, 0.1);
    color: var(--primary);
    padding: 4px 12px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 700;
}

.notification-time {
    font-size: 13px;
    color: var(--gray);
    font-weight: 600;
}

.notification-message {
    font-size: 15px;
    color: var(--dark);
    line-height: 1.6;
    margin-bottom: 12px;
}

.notification-actions {
    display: flex;
    gap: 10px;
}

.btn-sm {
    padding: 8px 16px;
    font-size: 12px;
}

.empty-state {
    background: white;
    padding: 80px 40px;
    border-radius: 16px;
    text-align: center;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
}

.empty-state i {
    font-size: 80px;
    color: #cbd5e1;
    margin-bottom: 20px;
}

.empty-state h2 {
    font-size: 24px;
    font-weight: 700;
    color: var(--dark);
    margin-bottom: 10px;
}

.empty-state p {
    font-size: 16px;
    color: var(--gray);
}

@media (max-width: 768px) {
    .notifications-header {
        flex-direction: column;
        gap: 20px;
    }
    
    .priority-stats {
        width: 100%;
        justify-content: space-around;
    }
    
    .notification-item {
        flex-direction: column;
    }
}
</style>
</head>
<body>

<div class="notifications-container">
    
    <!-- Header -->
    <div class="notifications-header">
        <div class="header-title">
            <h1><i class="fas fa-bell"></i> Notifications</h1>
            <?php if($total_unread > 0): ?>
            <span class="notification-badge"><?= $total_unread ?></span>
            <?php endif; ?>
        </div>
        
        <div class="priority-stats">
            <div class="priority-stat high">
                <div class="count"><?= $priority_counts['high'] ?? 0 ?></div>
                <div class="label">Urgent</div>
            </div>
            <div class="priority-stat normal">
                <div class="count"><?= $priority_counts['normal'] ?? 0 ?></div>
                <div class="label">Normal</div>
            </div>
            <div class="priority-stat low">
                <div class="count"><?= $priority_counts['low'] ?? 0 ?></div>
                <div class="label">Info</div>
            </div>
        </div>
    </div>

    <!-- Actions Bar -->
    <?php if($total_unread > 0): ?>
    <div class="actions-bar">
        <p style="color: var(--gray); font-weight: 600;">
            <i class="fas fa-info-circle"></i>
            <?= $total_unread ?> notification<?= $total_unread > 1 ? 's' : '' ?> non lue<?= $total_unread > 1 ? 's' : '' ?>
        </p>
        <button class="btn btn-primary" onclick="markAllAsRead()">
            <i class="fas fa-check-double"></i>
            Tout marquer comme lu
        </button>
    </div>
    <?php endif; ?>

    <!-- Notifications List -->
    <div class="notifications-list">
        <?php if(count($notifications) > 0): ?>
            <?php foreach($notifications as $notif): ?>
            <div class="notification-item <?= htmlspecialchars($notif['priority']) ?>" id="notif-<?= $notif['id'] ?>">
                <div class="notification-icon icon-<?= htmlspecialchars($notif['type']) ?>">
                    <?php
                    $icons = [
                        'check_in' => 'fa-sign-in-alt',
                        'check_out' => 'fa-sign-out-alt',
                        'overtime' => 'fa-clock',
                        'permission' => 'fa-file-alt',
                        'advance' => 'fa-hand-holding-usd'
                    ];
                    ?>
                    <i class="fas <?= $icons[$notif['type']] ?? 'fa-bell' ?>"></i>
                </div>
                
                <div class="notification-content">
                    <div class="notification-header">
                        <div class="employee-info">
                            <span class="employee-name"><?= htmlspecialchars($notif['full_name']) ?></span>
                            <span class="employee-code"><?= htmlspecialchars($notif['employee_code']) ?></span>
                        </div>
                        <span class="notification-time">
                            <i class="far fa-clock"></i>
                            <?php
                            $time_ago = time() - strtotime($notif['created_at']);
                            if($time_ago < 60) {
                                echo "À l'instant";
                            } elseif($time_ago < 3600) {
                                echo floor($time_ago / 60) . " min";
                            } elseif($time_ago < 86400) {
                                echo floor($time_ago / 3600) . " h";
                            } else {
                                echo date('d/m/Y H:i', strtotime($notif['created_at']));
                            }
                            ?>
                        </span>
                    </div>
                    
                    <div class="notification-message">
                        <?= nl2br(htmlspecialchars($notif['message'])) ?>
                    </div>
                    
                    <div class="notification-actions">
                        <button class="btn btn-sm btn-primary" onclick="markAsRead(<?= $notif['id'] ?>)">
                            <i class="fas fa-check"></i>
                            Marquer comme lu
                        </button>
                        <button class="btn btn-sm btn-danger" onclick="viewEmployee(<?= $notif['employee_id'] ?>)">
                            <i class="fas fa-user"></i>
                            Voir l'employé
                        </button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-check-circle"></i>
                <h2>Aucune notification</h2>
                <p>Vous avez tout traité ! 🎉</p>
            </div>
        <?php endif; ?>
    </div>

</div>

<script>
const csrf = "<?= $_SESSION['csrf_token'] ?>";

async function markAsRead(notificationId) {
    try {
        const res = await fetch(window.location.href, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                mark_as_read: 1,
                notification_id: notificationId,
                csrf_token: csrf
            })
        });
        
        const data = await res.json();
        
        if(data.success) {
            const notif = document.getElementById('notif-' + notificationId);
            notif.style.opacity = '0.5';
            notif.style.transform = 'scale(0.95)';
            setTimeout(() => {
                notif.remove();
                location.reload();
            }, 500);
        } else {
            alert('Erreur: ' + (data.error || 'Erreur inconnue'));
        }
    } catch(error) {
        console.error('Erreur:', error);
        alert('Erreur lors du marquage');
    }
}

async function markAllAsRead() {
    if(!confirm('Marquer toutes les notifications comme lues ?')) return;
    
    try {
        const res = await fetch(window.location.href, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                mark_all_read: 1,
                csrf_token: csrf
            })
        });
        
        const data = await res.json();
        
        if(data.success) {
            location.reload();
        } else {
            alert('Erreur: ' + (data.error || 'Erreur inconnue'));
        }
    } catch(error) {
        console.error('Erreur:', error);
        alert('Erreur lors du marquage');
    }
}

function viewEmployee(employeeId) {
    // Rediriger vers la page de détails de l'employé
    window.location.href = '/../hr/employee_details.php?id=' + employeeId;
}

// Auto-refresh toutes les 30 secondes
setInterval(() => {
    location.reload();
}, 30000);
</script>

</body>
</html>
