<!-- 
============================================================
WIDGET NOTIFICATIONS - À intégrer dans vos pages admin
============================================================
Copiez ce code dans n'importe quelle page admin pour afficher
un bouton de notifications avec un compteur en temps réel
============================================================
-->

<?php
// Récupérer le nombre de notifications non lues
$stmt_notif = $pdo->query("SELECT COUNT(*) FROM admin_notifications WHERE is_read = 0");
$unread_count = $stmt_notif->fetchColumn();
?>

<!-- STYLE DU WIDGET -->
<style>
.notification-bell {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 9999;
}

.notification-btn {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
    border: none;
    padding: 15px 20px;
    border-radius: 50px;
    font-size: 18px;
    cursor: pointer;
    box-shadow: 0 4px 20px rgba(239, 68, 68, 0.4);
    transition: all 0.3s;
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 700;
    position: relative;
}

.notification-btn:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 30px rgba(239, 68, 68, 0.6);
}

.notification-btn i {
    animation: ring 2s ease-in-out infinite;
}

@keyframes ring {
    0%, 100% { transform: rotate(0deg); }
    10%, 30% { transform: rotate(-10deg); }
    20%, 40% { transform: rotate(10deg); }
}

.notification-count {
    background: white;
    color: #ef4444;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 14px;
    font-weight: 900;
    min-width: 30px;
    text-align: center;
    animation: pulse-badge 2s ease-in-out infinite;
}

@keyframes pulse-badge {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.1); }
}

.notification-popup {
    position: fixed;
    top: 80px;
    right: 20px;
    width: 400px;
    max-height: 600px;
    background: white;
    border-radius: 16px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.3);
    display: none;
    flex-direction: column;
    z-index: 9998;
    animation: slideIn 0.3s ease-out;
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.notification-popup.show {
    display: flex;
}

.notification-popup-header {
    padding: 20px;
    border-bottom: 2px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.notification-popup-header h3 {
    font-size: 18px;
    font-weight: 800;
    color: #0f172a;
}

.notification-popup-body {
    flex: 1;
    overflow-y: auto;
    padding: 15px;
}

.mini-notification {
    padding: 12px;
    border-radius: 10px;
    margin-bottom: 10px;
    background: #f8fafc;
    border-left: 4px solid #ef4444;
    transition: all 0.3s;
    cursor: pointer;
}

.mini-notification:hover {
    background: #f1f5f9;
    transform: translateX(5px);
}

.mini-notification.high { border-left-color: #ef4444; }
.mini-notification.normal { border-left-color: #3b82f6; }
.mini-notification.low { border-left-color: #10b981; }

.mini-notification-header {
    display: flex;
    justify-content: space-between;
    margin-bottom: 5px;
    font-size: 12px;
}

.mini-notification-name {
    font-weight: 700;
    color: #0f172a;
}

.mini-notification-time {
    color: #64748b;
    font-size: 11px;
}

.mini-notification-message {
    font-size: 13px;
    color: #475569;
    line-height: 1.4;
}

.notification-popup-footer {
    padding: 15px 20px;
    border-top: 2px solid #e2e8f0;
}

.btn-view-all {
    width: 100%;
    padding: 12px;
    background: linear-gradient(135deg, #32be8f, #38d39f);
    color: white;
    border: none;
    border-radius: 10px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s;
}

.btn-view-all:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(50, 190, 143, 0.4);
}

@media (max-width: 768px) {
    .notification-popup {
        width: calc(100vw - 40px);
        right: 20px;
    }
}
</style>

<!-- WIDGET HTML -->
<div class="notification-bell">
    <button class="notification-btn" onclick="toggleNotificationPopup()">
        <i class="fas fa-bell"></i>
        <?php if($unread_count > 0): ?>
        <span class="notification-count" id="notificationCount"><?= $unread_count ?></span>
        <?php endif; ?>
    </button>
</div>

<!-- POPUP NOTIFICATIONS -->
<div class="notification-popup" id="notificationPopup">
    <div class="notification-popup-header">
        <h3><i class="fas fa-bell"></i> Notifications</h3>
        <button onclick="toggleNotificationPopup()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #64748b;">
            &times;
        </button>
    </div>
    
    <div class="notification-popup-body" id="notificationList">
        <?php
        // Récupérer les 5 dernières notifications
        $stmt_recent = $pdo->query("
            SELECT n.*, e.full_name, e.employee_code
            FROM admin_notifications n
            JOIN employees e ON n.employee_id = e.id
            WHERE n.is_read = 0
            ORDER BY n.created_at DESC
            LIMIT 5
        ");
        $recent_notifs = $stmt_recent->fetchAll(PDO::FETCH_ASSOC);
        
        if(count($recent_notifs) > 0):
            foreach($recent_notifs as $notif):
                $time_ago = time() - strtotime($notif['created_at']);
                if($time_ago < 60) {
                    $time_text = "À l'instant";
                } elseif($time_ago < 3600) {
                    $time_text = floor($time_ago / 60) . " min";
                } else {
                    $time_text = floor($time_ago / 3600) . " h";
                }
        ?>
        <div class="mini-notification <?= htmlspecialchars($notif['priority']) ?>" 
             onclick="window.location.href='<?= project_url('admin/admin_notifications.php') ?>'">
            <div class="mini-notification-header">
                <span class="mini-notification-name"><?= htmlspecialchars($notif['full_name']) ?></span>
                <span class="mini-notification-time"><?= $time_text ?></span>
            </div>
            <div class="mini-notification-message">
                <?= htmlspecialchars(substr($notif['message'], 0, 60)) ?>...
            </div>
        </div>
        <?php 
            endforeach;
        else:
        ?>
        <div style="text-align: center; padding: 40px; color: #64748b;">
            <i class="fas fa-check-circle" style="font-size: 40px; margin-bottom: 10px; color: #10b981;"></i>
            <p style="font-weight: 600;">Aucune notification</p>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="notification-popup-footer">
        <button class="btn-view-all" onclick="window.location.href='<?= project_url('admin/admin_notifications.php') ?>'">
            <i class="fas fa-external-link-alt"></i>
            Voir toutes les notifications
        </button>
    </div>
</div>

<!-- SCRIPT -->
<script>
function toggleNotificationPopup() {
    const popup = document.getElementById('notificationPopup');
    popup.classList.toggle('show');
}

// Fermer le popup si on clique en dehors
document.addEventListener('click', function(event) {
    const popup = document.getElementById('notificationPopup');
    const btn = document.querySelector('.notification-btn');
    
    if (!popup.contains(event.target) && !btn.contains(event.target)) {
        popup.classList.remove('show');
    }
});

// Auto-refresh du compteur toutes les 30 secondes
setInterval(async function() {
    try {
        const response = await fetch('get_notification_count.php');
        const data = await response.json();
        
        const countElement = document.getElementById('notificationCount');
        if(data.count > 0) {
            if(countElement) {
                countElement.textContent = data.count;
            } else {
                // Créer le badge s'il n'existe pas
                const btn = document.querySelector('.notification-btn');
                const badge = document.createElement('span');
                badge.className = 'notification-count';
                badge.id = 'notificationCount';
                badge.textContent = data.count;
                btn.appendChild(badge);
            }
        } else {
            if(countElement) {
                countElement.remove();
            }
        }
    } catch(error) {
        console.error('Erreur refresh notifications:', error);
    }
}, 30000);
</script>

<!-- 
============================================================
FICHIER API REQUIS: get_notification_count.php
Créez ce fichier pour l'auto-refresh:
============================================================
-->
<?php
/*
// Contenu du fichier get_notification_count.php
header('Content-Type: application/json');
require_once APP_ROOT . '/app/core/DB.php';
use App\Core\DB;

$pdo = DB::getConnection();
$stmt = $pdo->query("SELECT COUNT(*) FROM admin_notifications WHERE is_read = 0");
$count = $stmt->fetchColumn();

echo json_encode(['count' => (int)$count]);
*/
?>
