<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';
/****************************************************************
* PORTAIL EMPLOYÉ MOBILE SÉCURISÉ - VERSION CORRIGÉE
* Avec géolocalisation obligatoire à l'arrivée UNIQUEMENT
* Check-out intelligent avec calcul automatique heures sup
****************************************************************/

ini_set('display_errors',1);
error_reporting(E_ALL);

if(session_status() === PHP_SESSION_NONE) session_start();

require_once APP_ROOT . '/app/core/DB.php';
require_once PROJECT_ROOT . '/messaging/app_alerts.php';
use App\Core\DB;

$pdo = DB::getConnection();

if (!isset($_SESSION['employee_id']) || $_SESSION['account_type'] !== 'employee') {
    header('Location: /../auth/login_unified.php');
    exit;
}

$employee_id = $_SESSION['employee_id'];

if(empty($_SESSION['csrf_token'])){
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ================= CONFIGURATION ================= */
define('OVERTIME_RATE_PER_HOUR', 2000); // Taux horaire heures sup en FCFA

function ensureAttendanceSettingsStorage(PDO $pdo): void {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS attendance_settings (
                id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
                work_start_time TIME NOT NULL DEFAULT '07:30:00',
                work_end_time TIME NOT NULL DEFAULT '18:30:00',
                late_penalty_per_minute DECIMAL(10,2) NOT NULL DEFAULT 100.00,
                office_latitude DECIMAL(10,7) NOT NULL DEFAULT 5.3305820,
                office_longitude DECIMAL(10,7) NOT NULL DEFAULT -4.1973680,
                location_radius_meters INT NOT NULL DEFAULT 500,
                require_gps_check_in TINYINT(1) NOT NULL DEFAULT 1,
                require_gps_check_out TINYINT(1) NOT NULL DEFAULT 1,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $count = (int)$pdo->query("SELECT COUNT(*) FROM attendance_settings")->fetchColumn();
        if ($count === 0) {
            $pdo->exec("
                INSERT INTO attendance_settings
                (id, work_start_time, work_end_time, late_penalty_per_minute, office_latitude, office_longitude, location_radius_meters, require_gps_check_in, require_gps_check_out)
                VALUES
                (1, '07:30:00', '18:30:00', 100.00, 5.3305820, -4.1973680, 500, 1, 1)
            ");
        }

        $requiredColumns = [
            'checkout_latitude' => "ALTER TABLE attendance ADD COLUMN checkout_latitude DECIMAL(10,7) NULL AFTER longitude",
            'checkout_longitude' => "ALTER TABLE attendance ADD COLUMN checkout_longitude DECIMAL(10,7) NULL AFTER checkout_latitude",
            'checkout_distance_meters' => "ALTER TABLE attendance ADD COLUMN checkout_distance_meters DECIMAL(10,2) NULL AFTER checkout_longitude",
            'checkout_selfie_path' => "ALTER TABLE attendance ADD COLUMN checkout_selfie_path VARCHAR(255) NULL AFTER selfie_path",
        ];

        foreach ($requiredColumns as $column => $sql) {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM attendance LIKE ?");
            $stmt->execute([$column]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                $pdo->exec($sql);
            }
        }
    } catch (Throwable $e) {
        // Ne bloque pas le portail si le schéma existe déjà partiellement.
    }
}

function getAttendanceSettings(PDO $pdo): array {
    $defaults = [
        'work_start_time' => '07:30:00',
        'work_end_time' => '18:30:00',
        'late_penalty_per_minute' => 100,
        'office_latitude' => 5.330582,
        'office_longitude' => -4.197368,
        'location_radius_meters' => 500,
        'require_gps_check_in' => 1,
        'require_gps_check_out' => 1,
    ];

    try {
        $stmt = $pdo->query("SELECT * FROM attendance_settings WHERE id = 1 LIMIT 1");
        $settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return array_merge($defaults, $settings);
    } catch (Throwable $e) {
        return $defaults;
    }
}

function getCheckoutColumnNames(PDO $pdo): array {
    $map = [
        'latitude' => ['checkout_latitude', 'check_out_latitude'],
        'longitude' => ['checkout_longitude', 'check_out_longitude'],
        'distance' => ['checkout_distance_meters', 'check_out_distance_meters'],
    ];
    $resolved = [
        'latitude' => null,
        'longitude' => null,
        'distance' => null,
    ];

    try {
        foreach ($map as $key => $candidates) {
            foreach ($candidates as $candidate) {
                $stmt = $pdo->prepare("SHOW COLUMNS FROM attendance LIKE ?");
                $stmt->execute([$candidate]);
                if ($stmt->fetch(PDO::FETCH_ASSOC)) {
                    $resolved[$key] = $candidate;
                    break;
                }
            }
        }
    } catch (Throwable $e) {
    }

    return $resolved;
}

function ensureEmployeePortalProfileStorage(PDO $pdo): void {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS employee_portal_profiles (
                employee_id INT NOT NULL PRIMARY KEY,
                avatar_path VARCHAR(255) NULL,
                cover_path VARCHAR(255) NULL,
                bio TEXT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
    }
}

function getEmployeePortalProfile(PDO $pdo, int $employeeId): array {
    $defaults = [
        'employee_id' => $employeeId,
        'avatar_path' => null,
        'cover_path' => null,
        'bio' => null,
    ];

    try {
        $stmt = $pdo->prepare("SELECT * FROM employee_portal_profiles WHERE employee_id = ? LIMIT 1");
        $stmt->execute([$employeeId]);
        $profile = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return array_merge($defaults, $profile);
    } catch (Throwable $e) {
        return $defaults;
    }
}

function handleEmployeePortalImageUpload(array $file, string $targetDir, string $prefix, int $employeeId): string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new Exception("Upload image invalide.");
    }

    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new Exception("Image trop lourde. Maximum 5 MB.");
    }

    $tmpPath = $file['tmp_name'] ?? '';
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new Exception("Fichier temporaire introuvable.");
    }

    $mime = mime_content_type($tmpPath) ?: '';
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($allowed[$mime])) {
        throw new Exception("Format non supporté. Utilisez JPG, PNG ou WEBP.");
    }

    if (!is_dir($targetDir) && !mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
        throw new Exception("Impossible de créer le dossier d'upload.");
    }

    $fileName = $prefix . '_' . $employeeId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $destination = rtrim($targetDir, '/') . '/' . $fileName;

    if (!move_uploaded_file($tmpPath, $destination)) {
        throw new Exception("Échec de l'enregistrement de l'image.");
    }

    return $destination;
}

function ensureEmployeeSocialStorage(PDO $pdo): void {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS employee_notifications (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                employee_id INT NOT NULL,
                type VARCHAR(60) NOT NULL DEFAULT 'info',
                message TEXT NOT NULL,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_emp_notif (employee_id, is_read, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS employee_social_posts (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                author_type ENUM('employee','admin') NOT NULL DEFAULT 'employee',
                employee_id INT NULL,
                user_id INT NULL,
                category VARCHAR(50) NOT NULL DEFAULT 'general',
                title VARCHAR(160) NULL,
                body TEXT NOT NULL,
                media_path VARCHAR(255) NULL,
                media_type ENUM('image','video') NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_social_posts_created (created_at),
                INDEX idx_social_posts_author (author_type, employee_id, user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS employee_social_comments (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                post_id INT UNSIGNED NOT NULL,
                author_type ENUM('employee','admin') NOT NULL DEFAULT 'employee',
                employee_id INT NULL,
                user_id INT NULL,
                body TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_social_comments_post (post_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
    }
}

function handleEmployeeSocialMediaUpload(array $file, string $targetDir, int $employeeId): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new Exception("Fichier média invalide.");
    }

    if (($file['size'] ?? 0) > 30 * 1024 * 1024) {
        throw new Exception("Le média dépasse 30 MB.");
    }

    $tmpPath = $file['tmp_name'] ?? '';
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new Exception("Fichier média temporaire introuvable.");
    }

    $mime = mime_content_type($tmpPath) ?: '';
    $allowed = [
        'image/jpeg' => ['ext' => 'jpg', 'type' => 'image'],
        'image/png' => ['ext' => 'png', 'type' => 'image'],
        'image/webp' => ['ext' => 'webp', 'type' => 'image'],
        'video/mp4' => ['ext' => 'mp4', 'type' => 'video'],
        'video/webm' => ['ext' => 'webm', 'type' => 'video'],
        'video/quicktime' => ['ext' => 'mov', 'type' => 'video'],
    ];

    if (!isset($allowed[$mime])) {
        throw new Exception("Format refusé. Autorisés: JPG, PNG, WEBP, MP4, WEBM, MOV.");
    }

    if (!is_dir($targetDir) && !mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
        throw new Exception("Impossible de créer le dossier média.");
    }

    $meta = $allowed[$mime];
    $fileName = 'work_' . $employeeId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $meta['ext'];
    $destination = rtrim($targetDir, '/') . '/' . $fileName;

    if (!move_uploaded_file($tmpPath, $destination)) {
        throw new Exception("Impossible d'enregistrer le média.");
    }

    return [
        'path' => $destination,
        'type' => $meta['type'],
    ];
}

function tableExists(PDO $pdo, string $tableName): bool {
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$tableName]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/* ================= FONCTIONS ================= */
function calculateLatePenalty($check_in_time, array $settings) {
    $work_start = strtotime($settings['work_start_time']);
    $actual_arrival = strtotime($check_in_time);
    
    if($actual_arrival <= $work_start) {
        return ['minutes_late' => 0, 'penalty_amount' => 0, 'status' => 'present'];
    }
    
    $minutes_late = floor(($actual_arrival - $work_start) / 60);
    $penalty_amount = $minutes_late * (float)$settings['late_penalty_per_minute'];
    
    return [
        'minutes_late' => $minutes_late,
        'penalty_amount' => $penalty_amount,
        'status' => 'retard'
    ];
}

function calculateOvertime($check_out_time, array $settings) {
    $work_end = strtotime($settings['work_end_time']);
    $actual_departure = strtotime($check_out_time);
    
    if($actual_departure <= $work_end) {
        return ['hours' => 0, 'amount' => 0];
    }
    
    $overtime_seconds = $actual_departure - $work_end;
    $overtime_hours = round($overtime_seconds / 3600, 2);
    $overtime_amount = $overtime_hours * OVERTIME_RATE_PER_HOUR;
    
    return [
        'hours' => $overtime_hours,
        'amount' => $overtime_amount
    ];
}

function calculateDistanceMeters(float $latitude, float $longitude, float $officeLatitude, float $officeLongitude): float {
    $earth_radius = 6371000;
    $dLat = deg2rad($latitude - $officeLatitude);
    $dLon = deg2rad($longitude - $officeLongitude);
    $a = sin($dLat / 2) * sin($dLat / 2)
        + cos(deg2rad($officeLatitude)) * cos(deg2rad($latitude)) * sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earth_radius * $c;
}

function getAdviceForLateCount($late_count) {
    if($late_count >= 10) {
        return "🚨 ALERTE CRITIQUE: {$late_count} retards ce mois ! Votre emploi est en danger. Parlez à votre manager IMMÉDIATEMENT.";
    } elseif($late_count >= 5) {
        return "⚠️ ATTENTION: {$late_count} retards ce mois. Réglez votre réveil 30 min plus tôt. Préparez vos affaires la veille.";
    } elseif($late_count >= 3) {
        return "💡 CONSEIL: {$late_count} retards ce mois. Essayez de partir 15 min plus tôt de chez vous.";
    } elseif($late_count >= 1) {
        return "✅ Bon travail ! Seulement {$late_count} retard(s). Continuez vos efforts de ponctualité.";
    } else {
        return "🌟 EXCELLENT! Aucun retard ce mois. Vous êtes un exemple de ponctualité!";
    }
}

function sendAdminNotification($pdo, $employee_id, $employee_name, $type, $data) {
    try {
        $message = '';
        $priority = 'normal';
        
        if($type === 'check_in') {
            if($data['status'] === 'retard') {
                $message = "🔴 {$employee_name} EN RETARD à {$data['time']} ({$data['minutes_late']} min). Pénalité: {$data['penalty']} FCFA";
                $priority = 'high';
            } else {
                $message = "✅ {$employee_name} à l'heure à {$data['time']}";
                $priority = 'low';
            }
        } elseif($type === 'check_out') {
            $message = "🏁 {$employee_name} départ à {$data['time']}. Heures: {$data['hours_worked']}";
        } elseif($type === 'overtime_auto') {
            $message = "⏰ {$employee_name} HEURES SUP AUTOMATIQUES: {$data['hours']}h = {$data['amount']} FCFA";
            $priority = 'high';
        } elseif($type === 'overtime') {
            $message = "⏰ {$employee_name} déclare {$data['hours']}h sup ({$data['amount']} FCFA)";
        } elseif($type === 'permission') {
            $message = "📋 {$employee_name} demande permission {$data['start']} au {$data['end']}";
            $priority = 'high';
        } elseif($type === 'advance') {
            $message = "💰 {$employee_name} demande avance {$data['amount']} FCFA";
            $priority = 'high';
        } elseif($type === 'social_post') {
            $message = "📝 {$employee_name} a publié une mise à jour interne.";
            $priority = 'normal';
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO admin_notifications (type, message, priority, employee_id, created_at, is_read)
            VALUES (?, ?, ?, ?, NOW(), 0)
        ");
        $stmt->execute([$type, $message, $priority, $employee_id]);
        
        return true;
    } catch(Exception $e) {
        return false;
    }
}

function sendEmployeeNotification($pdo, $employee_id, $type, $message) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO employee_notifications (employee_id, type, message, created_at, is_read)
            VALUES (?, ?, ?, NOW(), 0)
        ");
        $stmt->execute([$employee_id, $type, $message]);
        return true;
    } catch(Exception $e) {
        return false;
    }
}

function notifyAttendanceAlert(PDO $pdo, array $employee, string $eventType, string $message, array $meta = []): void {
    try {
        appAlertNotifyRoles($pdo, appAlertHrRoles(), [
            'title' => $eventType === 'attendance_failed' ? 'Tentative de pointage échouée' : 'Pointage employé',
            'body' => mb_strimwidth($message, 0, 180, '…', 'UTF-8'),
            'url' => project_url('hr/employee_portal.php'),
            'tag' => 'attendance-' . ($eventType === 'attendance_failed' ? 'fail' : 'ok') . '-' . ((int)($employee['id'] ?? 0)),
            'unread' => 1,
        ], [
            'event_type' => $eventType,
            'employee_id' => (int)($employee['id'] ?? 0),
            'employee_name' => (string)($employee['full_name'] ?? ''),
            'meta' => $meta,
        ]);
    } catch (Throwable $e) {
        error_log('[ATTENDANCE ALERT] ' . $e->getMessage());
    }
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
    session_destroy();
    header('Location: login_unified.php');
    exit;
}

ensureAttendanceSettingsStorage($pdo);
$profileUploadDir = 'uploads/employee_profiles';
ensureEmployeePortalProfileStorage($pdo);
ensureEmployeeSocialStorage($pdo);
$attendanceSettings = getAttendanceSettings($pdo);
$checkoutColumns = getCheckoutColumnNames($pdo);
$workStartLabel = substr($attendanceSettings['work_start_time'], 0, 5);
$workEndLabel = substr($attendanceSettings['work_end_time'], 0, 5);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile_media'])) {
    try {
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            throw new Exception("Jeton de sécurité invalide");
        }

        $currentProfile = getEmployeePortalProfile($pdo, (int)$employee_id);
        $avatarPath = $currentProfile['avatar_path'];
        $coverPath = $currentProfile['cover_path'];
        $bio = trim((string)($_POST['profile_bio'] ?? ($currentProfile['bio'] ?? '')));

        if (!empty($_FILES['avatar']['name'] ?? '')) {
            $avatarPath = handleEmployeePortalImageUpload($_FILES['avatar'], $profileUploadDir . '/avatars', 'avatar', (int)$employee_id);
        }

        if (!empty($_FILES['cover_photo']['name'] ?? '')) {
            $coverPath = handleEmployeePortalImageUpload($_FILES['cover_photo'], $profileUploadDir . '/covers', 'cover', (int)$employee_id);
        }

        $stmt = $pdo->prepare("
            INSERT INTO employee_portal_profiles (employee_id, avatar_path, cover_path, bio, updated_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                avatar_path = VALUES(avatar_path),
                cover_path = VALUES(cover_path),
                bio = VALUES(bio),
                updated_at = NOW()
        ");
        $stmt->execute([(int)$employee_id, $avatarPath, $coverPath, $bio]);

        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?profile_updated=1');
        exit;
    } catch (Throwable $e) {
        $profileUploadError = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_social_post'])) {
    try {
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            throw new Exception("Jeton de sécurité invalide");
        }

        $allowedCategories = ['annonce', 'mission', 'rapport', 'securite', 'equipe'];
        $category = trim((string)($_POST['post_category'] ?? 'annonce'));
        $title = trim((string)($_POST['post_title'] ?? ''));
        $body = trim((string)($_POST['post_body'] ?? ''));

        if (!in_array($category, $allowedCategories, true)) {
            throw new Exception("Catégorie de publication invalide.");
        }
        if ($body === '' || mb_strlen($body) < 8) {
            throw new Exception("Écrivez un message professionnel plus complet.");
        }
        if (mb_strlen($body) > 3000) {
            throw new Exception("Publication trop longue. Maximum 3000 caractères.");
        }
        if (mb_strlen($title) > 160) {
            throw new Exception("Titre trop long. Maximum 160 caractères.");
        }

        $mediaPath = null;
        $mediaType = null;
        if (!empty($_FILES['post_media']['name'] ?? '')) {
            $uploadedMedia = handleEmployeeSocialMediaUpload($_FILES['post_media'], 'uploads/employee_social_media', (int)$employee_id);
            $mediaPath = $uploadedMedia['path'];
            $mediaType = $uploadedMedia['type'];
        }

        $stmt = $pdo->prepare("
            INSERT INTO employee_social_posts (author_type, employee_id, category, title, body, media_path, media_type, created_at)
            VALUES ('employee', ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([(int)$employee_id, $category, $title !== '' ? $title : null, $body, $mediaPath, $mediaType]);

        sendAdminNotification($pdo, $employee_id, $employee['full_name'], 'social_post', [
            'time' => date('H:i'),
            'status' => 'publication',
            'minutes_late' => 0,
            'penalty' => '0'
        ]);

        // Notifier tous les autres employés actifs
        try {
            $postTitle = $title !== '' ? $title : mb_substr($body, 0, 60) . (mb_strlen($body) > 60 ? '…' : '');
            $notifMsg = "📝 " . htmlspecialchars_decode($employee['full_name']) . " a publié : " . $postTitle;
            $stmtAll = $pdo->prepare("SELECT id FROM employees WHERE id != ? AND status = 'actif'");
            $stmtAll->execute([(int)$employee_id]);
            $allIds = $stmtAll->fetchAll(PDO::FETCH_COLUMN);
            foreach ($allIds as $otherId) {
                sendEmployeeNotification($pdo, (int)$otherId, 'social_post', $notifMsg);
            }
        } catch (Throwable $e) { /* silencieux */ }

        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?social_posted=1#publications');
        exit;
    } catch (Throwable $e) {
        $socialFeedError = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_social_comment'])) {
    try {
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            throw new Exception("Jeton de sécurité invalide");
        }

        $postId = (int)($_POST['post_id'] ?? 0);
        $commentBody = trim((string)($_POST['comment_body'] ?? ''));

        if ($postId <= 0) {
            throw new Exception("Publication introuvable.");
        }
        if ($commentBody === '' || mb_strlen($commentBody) < 2) {
            throw new Exception("Réponse trop courte.");
        }
        if (mb_strlen($commentBody) > 1200) {
            throw new Exception("Réponse trop longue.");
        }

        $stmt = $pdo->prepare("SELECT id FROM employee_social_posts WHERE id = ? LIMIT 1");
        $stmt->execute([$postId]);
        if (!$stmt->fetchColumn()) {
            throw new Exception("Publication inexistante.");
        }

        $stmt = $pdo->prepare("
            INSERT INTO employee_social_comments (post_id, author_type, employee_id, body, created_at)
            VALUES (?, 'employee', ?, ?, NOW())
        ");
        $stmt->execute([$postId, (int)$employee_id, $commentBody]);

        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?social_commented=1#post-' . $postId);
        exit;
    } catch (Throwable $e) {
        $socialFeedError = $e->getMessage();
    }
}

/* ================= AJAX HANDLERS ================= */
if(isset($_POST['action'])){
    header('Content-Type: application/json');
    $response = ['success'=>false,'msg'=>''];

    try{
        if(!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])){
            throw new Exception("Jeton de sécurité invalide");
        }

        // MARK NOTIFICATION AS READ
        if($_POST['action']=='mark_notification_read'){
            $notif_id = intval($_POST['notif_id'] ?? 0);
            $stmt = $pdo->prepare("UPDATE employee_notifications SET is_read=1 WHERE id=? AND employee_id=?");
            $stmt->execute([$notif_id, $employee_id]);
            $response['success'] = true;
            $response['msg'] = "Notification marquée comme lue";
        }

        // MARK ALL NOTIFICATIONS AS READ
        elseif($_POST['action']=='mark_all_notifications_read'){
            $stmt = $pdo->prepare("UPDATE employee_notifications SET is_read=1 WHERE employee_id=?");
            $stmt->execute([$employee_id]);
            $response['success'] = true;
            $response['msg'] = "Toutes les notifications marquées comme lues";
        }

        // DELETE SOCIAL POST (auteur uniquement)
        elseif($_POST['action']=='delete_post'){
            $post_id = intval($_POST['post_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT id FROM employee_social_posts WHERE id=? AND employee_id=? AND author_type='employee'");
            $stmt->execute([$post_id, $employee_id]);
            if(!$stmt->fetchColumn()){
                throw new Exception("Publication introuvable ou vous n'êtes pas l'auteur.");
            }
            $stmt = $pdo->prepare("DELETE FROM employee_social_comments WHERE post_id=?");
            $stmt->execute([$post_id]);
            $stmt = $pdo->prepare("DELETE FROM employee_social_posts WHERE id=? AND employee_id=?");
            $stmt->execute([$post_id, $employee_id]);
            $response['success'] = true;
            $response['msg'] = "Publication supprimée.";
        }

        // CHECK-IN avec sécurité STRICTE
        elseif($_POST['action']=='check_in'){
            $time = $_POST['time'] ?? date('H:i:s');
            $date = $_POST['date'] ?? date('Y-m-d');
            $latitude = floatval($_POST['latitude'] ?? 0);
            $longitude = floatval($_POST['longitude'] ?? 0);
            $selfie_data = $_POST['selfie'] ?? '';

            // ✅ Vérification géolocalisation OBLIGATOIRE pour CHECK-IN
            if((int)$attendanceSettings['require_gps_check_in'] === 1 && ($latitude == 0 || $longitude == 0)) {
                throw new Exception("⚠️ GÉOLOCALISATION REQUISE ! Activez votre GPS pour pointer.");
            }

            $distance = 0;
            if($latitude != 0 && $longitude != 0) {
                $distance = calculateDistanceMeters(
                    $latitude,
                    $longitude,
                    (float)$attendanceSettings['office_latitude'],
                    (float)$attendanceSettings['office_longitude']
                );
            }

            if((int)$attendanceSettings['require_gps_check_in'] === 1 && $distance > (float)$attendanceSettings['location_radius_meters']) {
                throw new Exception("❌ POSITION INVALIDE ! Vous êtes à " . round($distance) . "m du bureau. Vous devez être à moins de " . (int)$attendanceSettings['location_radius_meters'] . "m.");
            }

            // Vérification selfie
            if(empty($selfie_data)) {
                throw new Exception("📸 SELFIE REQUIS ! Prenez une photo pour valider votre pointage.");
            }

            $stmt = $pdo->prepare("SELECT * FROM attendance WHERE employee_id=? AND work_date=?");
            $stmt->execute([$employee_id, $date]);
            $attendance = $stmt->fetch(PDO::FETCH_ASSOC);

            if($attendance){
                throw new Exception("Vous avez déjà pointé aujourd'hui !");
            }
            
            // Sauvegarder selfie
            $selfie_path = null;
            if($selfie_data) {
                $img_data = str_replace('data:image/png;base64,', '', $selfie_data);
                $img_data = str_replace(' ', '+', $img_data);
                $img = base64_decode($img_data);
                
                $upload_dir = 'uploads/attendance_selfies/';
                if(!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                
                $selfie_path = $upload_dir . $employee_id . '_' . date('Y-m-d_H-i-s') . '.png';
                file_put_contents($selfie_path, $img);
            }
            
            $penalty_data = calculateLatePenalty($time, $attendanceSettings);
            
            $stmt = $pdo->prepare("
                INSERT INTO attendance 
                (employee_id, work_date, check_in, status, minutes_late, penalty_amount, 
                 latitude, longitude, selfie_path, created_at)
                VALUES (?,?,?,?,?,?,?,?,?,NOW())
            ");
            $stmt->execute([
                $employee_id, $date, $time, $penalty_data['status'],
                $penalty_data['minutes_late'], $penalty_data['penalty_amount'],
                $latitude, $longitude, $selfie_path
            ]);

            // Notification admin
            sendAdminNotification($pdo, $employee_id, $employee['full_name'], 'check_in', [
                'time' => substr($time, 0, 5),
                'status' => $penalty_data['status'],
                'minutes_late' => $penalty_data['minutes_late'],
                'penalty' => number_format($penalty_data['penalty_amount'], 0, ',', ' ')
            ]);
            notifyAttendanceAlert(
                $pdo,
                $employee,
                'attendance_success',
                sprintf(
                    '%s a pointé son arrivée à %s (%s)%s',
                    $employee['full_name'],
                    substr($time, 0, 5),
                    $penalty_data['status'],
                    $penalty_data['minutes_late'] > 0 ? ' · retard ' . $penalty_data['minutes_late'] . ' min' : ''
                ),
                [
                    'phase' => 'check_in',
                    'status' => $penalty_data['status'],
                    'minutes_late' => (int)$penalty_data['minutes_late'],
                    'distance_meters' => round((float)$distance, 2),
                ]
            );

            // Compter les retards du mois
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM attendance 
                WHERE employee_id=? AND work_date LIKE ? AND status='retard'
            ");
            $stmt->execute([$employee_id, date('Y-m')."%"]);
            $late_count = $stmt->fetchColumn();

            if($penalty_data['status'] === 'retard') {
                $advice = getAdviceForLateCount($late_count);
                $response['msg'] = "⚠️ ARRIVÉE EN RETARD à " . substr($time,0,5) . "<br>".
                                  "<span style='font-size:24px;color:#dc2626;font-weight:900'>🔴 RETARD: {$penalty_data['minutes_late']} MINUTES</span><br>".
                                  "<span style='font-size:20px;color:#ef4444;font-weight:900'>PÉNALITÉ: -".number_format($penalty_data['penalty_amount'], 0, ',', ' ')." FCFA</span><br><br>".
                                  "<div style='background:#fee2e2;padding:15px;border-radius:10px;margin-top:10px;'>{$advice}</div>";
                
                // Notification employé avec conseil
                sendEmployeeNotification($pdo, $employee_id, 'late_warning', $advice);
            } else {
                $response['msg'] = "✅ Arrivée enregistrée à " . substr($time,0,5) . " (À l'heure) 🎉";
            }

            $response['success'] = true;
        }

        // CHECK-OUT avec GPS + SELFIE + Calcul auto heures sup
        elseif($_POST['action']=='check_out'){
            $time = $_POST['time'] ?? date('H:i:s');
            $date = $_POST['date'] ?? date('Y-m-d');

            $latitude = floatval($_POST['latitude'] ?? 0);
            $longitude = floatval($_POST['longitude'] ?? 0);
            $selfie_data = $_POST['selfie'] ?? '';

            // ✅ VÉRIFICATION: Impossible de partir avant l'heure configurée
            $current_time = strtotime($time);
            $allowed_departure = strtotime($attendanceSettings['work_end_time']);
            
            if($current_time < $allowed_departure) {
                $time_remaining = $allowed_departure - $current_time;
                $minutes_remaining = ceil($time_remaining / 60);
                throw new Exception("⛔ DÉPART IMPOSSIBLE AVANT {$workEndLabel} !\n\nIl vous reste encore {$minutes_remaining} minutes de travail.\n\nHeure actuelle: " . substr($time, 0, 5) . "\nHeure de départ: {$workEndLabel}");
            }

            $stmt = $pdo->prepare("SELECT * FROM attendance WHERE employee_id=? AND work_date=?");
            $stmt->execute([$employee_id, $date]);
            $attendance = $stmt->fetch(PDO::FETCH_ASSOC);

            if(!$attendance){
                throw new Exception("Vous devez d'abord pointer votre arrivée !");
            }
            if($attendance['check_out']){
                throw new Exception("Vous avez déjà pointé votre départ !");
            }

            $check_in = strtotime($attendance['check_in']);
            $check_out = strtotime($time);
            $hours_worked = round(($check_out - $check_in) / 3600, 2);

            if((int)$attendanceSettings['require_gps_check_out'] === 1 && ($latitude == 0 || $longitude == 0)) {
                throw new Exception("⚠️ GÉOLOCALISATION REQUISE POUR LE DÉPART ! Activez votre GPS.");
            }

            $checkOutDistance = null;
            if($latitude != 0 && $longitude != 0) {
                $checkOutDistance = calculateDistanceMeters(
                    $latitude,
                    $longitude,
                    (float)$attendanceSettings['office_latitude'],
                    (float)$attendanceSettings['office_longitude']
                );
            }

            if((int)$attendanceSettings['require_gps_check_out'] === 1 && $checkOutDistance > (float)$attendanceSettings['location_radius_meters']) {
                throw new Exception("❌ POSITION DE DÉPART INVALIDE ! Vous êtes à " . round($checkOutDistance) . "m du bureau. Rayon autorisé: " . (int)$attendanceSettings['location_radius_meters'] . "m.");
            }

            if(empty($selfie_data)) {
                throw new Exception("📸 SELFIE DE DÉPART REQUIS ! Prenez une photo pour valider votre sortie.");
            }

            $checkout_selfie_path = null;
            $img_data = str_replace('data:image/png;base64,', '', $selfie_data);
            $img_data = str_replace(' ', '+', $img_data);
            $img = base64_decode($img_data);
            if($img !== false) {
                $upload_dir = 'uploads/attendance_selfies/';
                if(!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                $checkout_selfie_path = $upload_dir . $employee_id . '_checkout_' . date('Y-m-d_H-i-s') . '.png';
                file_put_contents($checkout_selfie_path, $img);
            }

            // ✅ VÉRIFICATION AUTOMATIQUE HEURES SUPPLÉMENTAIRES
            $overtime_data = calculateOvertime($time, $attendanceSettings);
            $overtime_message = '';
            
            if($overtime_data['hours'] > 0) {
                // Enregistrer automatiquement les heures sup
                $stmt = $pdo->prepare("
                    INSERT INTO overtime (employee_id, work_date, hours, rate_per_hour, total_amount, created_at)
                    VALUES (?,?,?,?,?,NOW())
                ");
                $stmt->execute([
                    $employee_id, 
                    $date, 
                    $overtime_data['hours'], 
                    OVERTIME_RATE_PER_HOUR, 
                    $overtime_data['amount']
                ]);

                // Notification admin
                sendAdminNotification($pdo, $employee_id, $employee['full_name'], 'overtime_auto', [
                    'hours' => $overtime_data['hours'],
                    'amount' => number_format($overtime_data['amount'], 0, ',', ' ')
                ]);

                // Notification employé
                $overtime_message = "<br><br>💰 <span style='color:#10b981;font-weight:900;font-size:18px;'>HEURES SUPPLÉMENTAIRES DÉTECTÉES !</span><br>".
                                   "<span style='font-size:16px;font-weight:700;'>⏰ {$overtime_data['hours']}h après {$workEndLabel}</span><br>".
                                   "<span style='font-size:20px;color:#059669;font-weight:900;'>+".number_format($overtime_data['amount'], 0, ',', ' ')." FCFA</span>";
                
                sendEmployeeNotification($pdo, $employee_id, 'overtime_bonus', 
                    "💰 Bravo ! Vous avez travaillé {$overtime_data['hours']}h supplémentaires le " . date('d/m/Y', strtotime($date)) . 
                    ". Montant: +".number_format($overtime_data['amount'], 0, ',', ' ')." FCFA"
                );
            }

            $updateFields = ['check_out=?', 'hours_worked=?'];
            $updateValues = [$time, $hours_worked];

            if (!empty($checkoutColumns['latitude'])) {
                $updateFields[] = "{$checkoutColumns['latitude']}=?";
                $updateValues[] = $latitude ?: null;
            }
            if (!empty($checkoutColumns['longitude'])) {
                $updateFields[] = "{$checkoutColumns['longitude']}=?";
                $updateValues[] = $longitude ?: null;
            }
            if (!empty($checkoutColumns['distance'])) {
                $updateFields[] = "{$checkoutColumns['distance']}=?";
                $updateValues[] = $checkOutDistance;
            }
            $updateFields[] = "checkout_selfie_path=?";
            $updateValues[] = $checkout_selfie_path;

            $updateValues[] = $attendance['id'];
            $stmt = $pdo->prepare("
                UPDATE attendance
                SET " . implode(', ', $updateFields) . "
                WHERE id=?
            ");
            $stmt->execute($updateValues);

            sendAdminNotification($pdo, $employee_id, $employee['full_name'], 'check_out', [
                'time' => substr($time, 0, 5),
                'hours_worked' => $hours_worked . 'h'
            ]);
            notifyAttendanceAlert(
                $pdo,
                $employee,
                'attendance_success',
                sprintf(
                    '%s a pointé son départ à %s · %.2fh travaillées',
                    $employee['full_name'],
                    substr($time, 0, 5),
                    $hours_worked
                ),
                [
                    'phase' => 'check_out',
                    'hours_worked' => (float)$hours_worked,
                    'distance_meters' => round((float)($checkOutDistance ?? 0), 2),
                    'overtime_hours' => (float)($overtime_data['hours'] ?? 0),
                    'overtime_amount' => (float)($overtime_data['amount'] ?? 0),
                ]
            );

            $response['msg'] = "✅ Départ enregistré à " . substr($time,0,5) . 
                              "<br>Heures travaillées: {$hours_worked}h" . 
                              $overtime_message;
            $response['success'] = true;
        }

        // REQUEST PERMISSION
        elseif($_POST['action']=='request_permission'){
            $start_date = $_POST['start_date'] ?? '';
            $end_date = $_POST['end_date'] ?? '';
            $reason = trim($_POST['reason'] ?? '');

            if(!$start_date || !$end_date || !$reason){
                throw new Exception("Tous les champs sont obligatoires");
            }

            $stmt = $pdo->prepare("
                INSERT INTO permissions (employee_id, start_date, end_date, reason, status, created_at)
                VALUES (?,?,?,?,?,NOW())
            ");
            $stmt->execute([$employee_id, $start_date, $end_date, $reason, 'en_attente']);

            sendAdminNotification($pdo, $employee_id, $employee['full_name'], 'permission', [
                'start' => date('d/m/Y', strtotime($start_date)),
                'end' => date('d/m/Y', strtotime($end_date))
            ]);

            $response['success'] = true;
            $response['msg'] = "✅ Demande envoyée ! Vous serez notifié de la réponse.";
        }

        // REQUEST ADVANCE
        elseif($_POST['action']=='request_advance'){
            $amount = trim($_POST['amount'] ?? '');
            $reason = trim($_POST['reason'] ?? '');
            $advance_date = $_POST['advance_date'] ?? date('Y-m-d');

            if(!$amount || !$reason){
                throw new Exception("Tous les champs sont obligatoires");
            }

            if(!is_numeric($amount) || $amount <= 0){
                throw new Exception("Montant invalide");
            }

            if($amount > ($employee['salary_amount'] * 0.5)){
                throw new Exception("Maximum: " . number_format($employee['salary_amount'] * 0.5, 0) . " FCFA");
            }

            $stmt = $pdo->prepare("
                INSERT INTO advances (employee_id, amount, reason, advance_date, status, created_at)
                VALUES (?,?,?,?,?,NOW())
            ");
            $stmt->execute([$employee_id, $amount, $reason, $advance_date, 'en_attente']);

            sendAdminNotification($pdo, $employee_id, $employee['full_name'], 'advance', [
                'amount' => number_format($amount, 0, ',', ' ') . ' FCFA'
            ]);

            $response['success'] = true;
            $response['msg'] = "✅ Demande envoyée ! Vous serez notifié de la réponse.";
        }

        // DECLARE OVERTIME (manuel si besoin)
        elseif($_POST['action']=='declare_overtime'){
            $work_date = $_POST['work_date'] ?? date('Y-m-d');
            $hours = trim($_POST['hours'] ?? '');
            $rate_per_hour = trim($_POST['rate_per_hour'] ?? '');

            if(!$hours || !$rate_per_hour){
                throw new Exception("Tous les champs sont obligatoires");
            }

            if(!is_numeric($hours) || $hours <= 0 || $hours > 12){
                throw new Exception("Heures invalides (max 12h)");
            }

            $total_amount = $hours * $rate_per_hour;

            $stmt = $pdo->prepare("
                INSERT INTO overtime (employee_id, work_date, hours, rate_per_hour, total_amount, created_at)
                VALUES (?,?,?,?,?,NOW())
            ");
            $stmt->execute([$employee_id, $work_date, $hours, $rate_per_hour, $total_amount]);

            sendAdminNotification($pdo, $employee_id, $employee['full_name'], 'overtime', [
                'hours' => $hours,
                'amount' => number_format($total_amount, 0, ',', ' ') . ' FCFA'
            ]);

            $response['success'] = true;
            $response['msg'] = "✅ Enregistré: " . number_format($total_amount, 0, ',', ' ') . " FCFA";
        }

    }catch(Throwable $e){
        if (in_array($_POST['action'] ?? '', ['check_in', 'check_out'], true) && !empty($employee)) {
            notifyAttendanceAlert(
                $pdo,
                $employee,
                'attendance_failed',
                sprintf(
                    '%s a échoué son %s · %s',
                    $employee['full_name'],
                    ($_POST['action'] ?? '') === 'check_out' ? 'pointage départ' : 'pointage arrivée',
                    preg_replace('/\s+/', ' ', trim($e->getMessage()))
                ),
                [
                    'phase' => (string)($_POST['action'] ?? ''),
                    'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
                ]
            );
        }
        $response['msg'] = $e->getMessage();
    }

    echo json_encode($response);
    exit;
}

/* ================= STATS ================= */
$stmt = $pdo->prepare("
    SELECT COUNT(*) as days_worked,
           SUM(CASE WHEN status='retard' THEN 1 ELSE 0 END) as late_count
    FROM attendance 
    WHERE employee_id=? AND work_date LIKE ?
");
$stmt->execute([$employee_id, date('Y-m')."%"]);
$month_stats = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT SUM(penalty_amount) as total_penalties
    FROM attendance 
    WHERE employee_id=? AND work_date LIKE ?
");
$stmt->execute([$employee_id, date('Y-m')."%"]);
$penalties = $stmt->fetch(PDO::FETCH_ASSOC);
$total_penalties = $penalties['total_penalties'] ?? 0;

// ✅ CALCUL TOTAL HEURES SUP DU MOIS
$stmt = $pdo->prepare("
    SELECT SUM(total_amount) as total_overtime
    FROM overtime 
    WHERE employee_id=? AND work_date LIKE ?
");
$stmt->execute([$employee_id, date('Y-m')."%"]);
$overtime_bonus = $stmt->fetch(PDO::FETCH_ASSOC);
$total_overtime = $overtime_bonus['total_overtime'] ?? 0;

$net_salary = $employee['salary_amount'] - $total_penalties + $total_overtime;

$stmt = $pdo->prepare("SELECT * FROM attendance WHERE employee_id=? AND work_date=?");
$stmt->execute([$employee_id, date('Y-m-d')]);
$today_attendance = $stmt->fetch(PDO::FETCH_ASSOC);
$employeeProfile = getEmployeePortalProfile($pdo, (int)$employee_id);

$profilesTableExists = tableExists($pdo, 'profiles');
$socialPosts = [];
try {
    $socialPostSql = "
        SELECT sp.*,
               CASE
                   WHEN sp.author_type = 'employee' THEN COALESCE(e.full_name, 'Employé')
                   ELSE COALESCE(u.username, 'Administration')
               END AS author_name,
               CASE
                   WHEN sp.author_type = 'employee' THEN epp.avatar_path
                   ELSE " . ($profilesTableExists ? "p.avatar" : "NULL") . "
               END AS author_avatar
        FROM employee_social_posts sp
        LEFT JOIN employees e ON sp.employee_id = e.id
        LEFT JOIN employee_portal_profiles epp ON epp.employee_id = e.id
        LEFT JOIN users u ON sp.user_id = u.id
        " . ($profilesTableExists ? "LEFT JOIN profiles p ON p.user_id = u.id" : "") . "
        ORDER BY sp.created_at DESC
        LIMIT 20
    ";
    $stmt = $pdo->query($socialPostSql);
    $socialPosts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $socialPosts = [];
}

$socialCommentsByPost = [];
if ($socialPosts) {
    $postIds = array_map(static fn(array $post): int => (int)$post['id'], $socialPosts);
    $placeholders = implode(',', array_fill(0, count($postIds), '?'));
    try {
        $socialCommentSql = "
            SELECT sc.*,
                   CASE
                       WHEN sc.author_type = 'employee' THEN COALESCE(e.full_name, 'Employé')
                       ELSE COALESCE(u.username, 'Administration')
                   END AS author_name,
                   CASE
                       WHEN sc.author_type = 'employee' THEN epp.avatar_path
                       ELSE " . ($profilesTableExists ? "p.avatar" : "NULL") . "
                   END AS author_avatar
            FROM employee_social_comments sc
            LEFT JOIN employees e ON sc.employee_id = e.id
            LEFT JOIN employee_portal_profiles epp ON epp.employee_id = e.id
            LEFT JOIN users u ON sc.user_id = u.id
            " . ($profilesTableExists ? "LEFT JOIN profiles p ON p.user_id = u.id" : "") . "
            WHERE sc.post_id IN ($placeholders)
            ORDER BY sc.created_at ASC
        ";
        $stmt = $pdo->prepare($socialCommentSql);
        $stmt->execute($postIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $commentItem) {
            $socialCommentsByPost[(int)$commentItem['post_id']][] = $commentItem;
        }
    } catch (Throwable $e) {
        $socialCommentsByPost = [];
    }
}

$socialMediaGallery = [];
foreach ($socialPosts as $socialPost) {
    if (!empty($socialPost['media_path']) && file_exists($socialPost['media_path'])) {
        $socialMediaGallery[] = [
            'path' => $socialPost['media_path'],
            'type' => $socialPost['media_type'] ?: 'image',
            'label' => $socialPost['title'] ?: ucfirst((string)$socialPost['category']),
        ];
    }
}

// Notifications non lues
$stmt = $pdo->prepare("SELECT COUNT(*) FROM employee_notifications WHERE employee_id=? AND is_read=0");
$stmt->execute([$employee_id]);
$unread_notifs = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT * FROM employee_notifications WHERE employee_id=? ORDER BY created_at DESC LIMIT 10");
$stmt->execute([$employee_id]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Retards du mois
$stmt = $pdo->prepare("
    SELECT * FROM attendance 
    WHERE employee_id=? AND work_date LIKE ? AND status='retard'
    ORDER BY work_date DESC
");
$stmt->execute([$employee_id, date('Y-m')."%"]);
$late_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

$late_count = $month_stats['late_count'] ?? 0;
$advice = getAdviceForLateCount($late_count);

// ✅ Données pour le graphique des retards par jour
$stmt = $pdo->prepare("
    SELECT work_date, 
           CASE WHEN status='retard' THEN 1 ELSE 0 END as is_late,
           minutes_late
    FROM attendance 
    WHERE employee_id=? AND work_date LIKE ?
    ORDER BY work_date ASC
");
$stmt->execute([$employee_id, date('Y-m')."%"]);
$attendance_days = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Compter les jours à l'heure et en retard
$ontime_count = 0;
$late_count_chart = 0;

foreach($attendance_days as $day) {
    if($day['is_late'] == 1) {
        $late_count_chart++;
    } else {
        $ontime_count++;
    }
}

$days_worked = (int)($month_stats['days_worked'] ?? 0);
$salary_amount = (float)($employee['salary_amount'] ?? 0);
$total_penalties = (float)$total_penalties;
$total_overtime = (float)$total_overtime;
$net_salary = (float)$net_salary;
$hours_today_decimal = 0.0;

if ($today_attendance) {
    $todayEndTime = $today_attendance['check_out'] ?: date('H:i:s');
    $hours_today_decimal = max(0, round((strtotime($todayEndTime) - strtotime($today_attendance['check_in'])) / 3600, 2));
}

$hoursTodayHours = floor($hours_today_decimal);
$hoursTodayMinutes = (int)round(($hours_today_decimal - $hoursTodayHours) * 60);
if ($hoursTodayMinutes === 60) {
    $hoursTodayHours++;
    $hoursTodayMinutes = 0;
}
$hoursTodayFormatted = sprintf('%02dh %02dmin', $hoursTodayHours, $hoursTodayMinutes);

$currentSeconds = time();
$workStartSeconds = strtotime(date('Y-m-d') . ' ' . $attendanceSettings['work_start_time']);
$workEndSeconds = strtotime(date('Y-m-d') . ' ' . $attendanceSettings['work_end_time']);

$todayStatusKey = 'not_checked';
$todayStatusLabel = 'Non pointé';
$todayStatusIcon = '🔴';
$todayStatusClass = 'status-danger';

if ($today_attendance) {
    if (!empty($today_attendance['check_out'])) {
        $todayStatusKey = 'finished';
        $todayStatusLabel = 'Terminé';
        $todayStatusIcon = '🔵';
        $todayStatusClass = 'status-info';
    } else {
        $todayStatusKey = 'present';
        $todayStatusLabel = 'Présent';
        $todayStatusIcon = '🟢';
        $todayStatusClass = $today_attendance['status'] === 'retard' ? 'status-warning' : 'status-success';
    }
} elseif ($currentSeconds > $workEndSeconds) {
    $todayStatusKey = 'absence';
    $todayStatusLabel = 'Absence';
    $todayStatusIcon = '❌';
    $todayStatusClass = 'status-danger';
}

$performanceScore = 100;
if ($days_worked > 0) {
    $performanceScore = max(40, min(100, 100 - ($late_count * 6)));
}

$presenceRate = $days_worked > 0 ? round((($days_worked - $late_count) / max($days_worked, 1)) * 100) : 100;
$presenceBadge = $presenceRate >= 95 ? 'Excellent' : ($presenceRate >= 80 ? 'Bon' : 'À améliorer');

$stmt = $pdo->prepare("
    SELECT work_date, check_in, check_out, status, minutes_late, penalty_amount, hours_worked
    FROM attendance
    WHERE employee_id=?
    ORDER BY work_date DESC
    LIMIT 6
");
$stmt->execute([$employee_id]);
$recent_attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);

$quick_notifications = [
    $late_count > 0 ? "Retard détecté: {$late_count} ce mois" : "Aucun retard détecté ce mois",
    "Nouveau salaire estimé: " . number_format($net_salary, 0, ',', ' ') . " FCFA",
    "Permission validée: consultez vos notifications",
    $todayStatusLabel === 'Absence' ? "Absence signalée aujourd'hui" : "Aucune absence signalée aujourd'hui",
    "Nouvelle annonce: vérifiez la cloche de notifications",
];

$aiInsights = [
    $late_count > 0 ? "Tu as {$late_count} retard(s) ce mois." : "Tu n'as aucun retard ce mois.",
    "Heures totales estimées aujourd'hui: {$hoursTodayFormatted}.",
    "Salaire estimé: " . number_format($net_salary, 0, ',', ' ') . " FCFA.",
    $salary_amount > 0 ? "Tu peux demander une avance jusqu'à " . number_format($salary_amount * 0.5, 0, ',', ' ') . " FCFA." : "Avance disponible selon le salaire de base.",
];

$employeeName = trim((string)($employee['full_name'] ?? 'Employé'));
$employeeInitials = strtoupper(substr($employeeName, 0, 2));
$employeePhone = trim((string)($employee['phone'] ?? ''));
$employeeEmail = trim((string)($employee['email'] ?? ''));
$employeeStatus = trim((string)($employee['status'] ?? 'actif'));
$employeeHireDate = !empty($employee['hire_date']) ? date('d/m/Y', strtotime($employee['hire_date'])) : 'Non renseignée';
$employeeSalaryType = trim((string)($employee['salary_type'] ?? 'mensuel'));
$employeeAvatarPath = (!empty($employeeProfile['avatar_path']) && file_exists($employeeProfile['avatar_path'])) ? $employeeProfile['avatar_path'] : null;
$employeeCoverPath = (!empty($employeeProfile['cover_path']) && file_exists($employeeProfile['cover_path'])) ? $employeeProfile['cover_path'] : null;
$employeeBio = trim((string)($employeeProfile['bio'] ?? '')) !== ''
    ? trim((string)$employeeProfile['bio'])
    : "Membre de l'équipe " . ($employee['category_name'] ?? 'interne') . ", " . strtolower((string)($employee['position_title'] ?? 'collaborateur')) . ".";
if ($employeeCoverPath) {
    array_unshift($socialMediaGallery, ['path' => $employeeCoverPath, 'type' => 'image', 'label' => 'Cover de profil']);
}
if ($employeeAvatarPath) {
    array_unshift($socialMediaGallery, ['path' => $employeeAvatarPath, 'type' => 'image', 'label' => 'Avatar de profil']);
}
$employeeProfileHighlights = [
    ['label' => 'Téléphone', 'value' => $employeePhone !== '' ? $employeePhone : 'Non renseigné', 'icon' => 'fa-phone'],
    ['label' => 'Email', 'value' => $employeeEmail !== '' ? $employeeEmail : 'Non renseigné', 'icon' => 'fa-envelope'],
    ['label' => 'Embauche', 'value' => $employeeHireDate, 'icon' => 'fa-calendar-days'],
    ['label' => 'Salaire ' . ucfirst($employeeSalaryType), 'value' => number_format($salary_amount, 0, ',', ' ') . ' FCFA', 'icon' => 'fa-wallet'],
];
$employeeIntroFacts = [
    'Travaille comme ' . ($employee['position_title'] ?? 'Collaborateur'),
    'Equipe ' . ($employee['category_name'] ?? 'Interne'),
    'A rejoint ESPERANCE H2O le ' . $employeeHireDate,
];
$profileTimeline = [
    [
        'title' => $today_attendance ? 'Pointage du jour enregistré' : 'Pointage du jour en attente',
        'meta' => $today_attendance
            ? 'Arrivée ' . substr($today_attendance['check_in'], 0, 5) . (!empty($today_attendance['check_out']) ? ' | Départ ' . substr($today_attendance['check_out'], 0, 5) : ' | Départ non enregistré')
            : 'Aucune présence enregistrée aujourd\'hui',
        'accent' => $today_attendance ? 'success' : 'danger',
    ],
    [
        'title' => 'Performance mensuelle',
        'meta' => $performanceScore . '% de score avec ' . $presenceRate . '% de présence utile',
        'accent' => $performanceScore >= 85 ? 'success' : 'warning',
    ],
    [
        'title' => 'Solde estimé du mois',
        'meta' => number_format($net_salary, 0, ',', ' ') . ' FCFA après retenues et bonus',
        'accent' => 'info',
    ],
];
$socialCategoryLabels = [
    'annonce' => 'Annonce',
    'mission' => 'Mission',
    'rapport' => 'Rapport',
    'securite' => 'Sécurité',
    'equipe' => 'Équipe',
];
$socialPostCount = count($socialPosts);
$socialCommentCount = 0;
foreach ($socialCommentsByPost as $commentList) {
    $socialCommentCount += count($commentList);
}
$socialMediaCount = count($socialMediaGallery);
$employeeProfileStats = [
    ['label' => 'Publications', 'value' => $socialPostCount, 'icon' => 'fa-newspaper'],
    ['label' => 'Commentaires', 'value' => $socialCommentCount, 'icon' => 'fa-comments'],
    ['label' => 'Photos', 'value' => $socialMediaCount, 'icon' => 'fa-images'],
    ['label' => 'Présences', 'value' => $days_worked, 'icon' => 'fa-calendar-check'],
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= htmlspecialchars($employee['full_name']) ?></title>
<meta name="theme-color" content="#10b981">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Employe">
<link rel="manifest" href="/hr/employee_manifest.json">
<link rel="icon" href="/hr/employee-app-icon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/hr/employee-app-icon.svg">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
:root {
    --bg: #f0f2f5;
    --surface: rgba(255,255,255,0.92);
    --surface-strong: #ffffff;
    --surface-muted: #e7f3ff;
    --text: #1c1e21;
    --text-soft: #65676b;
    --border: rgba(148,163,184,0.18);
    --accent: #1877f2;
    --accent-2: #42b3ff;
    --danger: #ef4444;
    --warning: #f59e0b;
    --info: #3b82f6;
    --shadow: 0 24px 50px rgba(15,23,42,0.12);
    --radius: 16px;
}
@media (prefers-color-scheme: dark) {
    :root {
        --bg: #0f172a;
        --surface: rgba(30,41,59,0.92);
        --surface-strong: #1e293b;
        --surface-muted: rgba(51,65,85,0.75);
        --text: #e2e8f0;
        --text-soft: #94a3b8;
        --border: rgba(148,163,184,0.15);
        --shadow: 0 24px 50px rgba(2,6,23,0.45);
    }
}
body {
    font-family: 'Inter','Poppins',sans-serif;
    background:
        radial-gradient(circle at top left, rgba(16,185,129,0.18), transparent 28%),
        radial-gradient(circle at bottom right, rgba(59,130,246,0.12), transparent 24%),
        var(--bg);
    color: var(--text);
    min-height: 100vh;
    padding: 14px;
    font-size: 14px;
    overflow-x: hidden;
}
.container { max-width: 1180px; margin: 0 auto; width: 100%; overflow-x: clip; }
.dashboard-grid { display: grid; gap: 18px; }
.card, .header-shell, .status-strip {
    background: var(--surface);
    backdrop-filter: blur(18px);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
}
.card, .header-shell, .status-strip, .stat-card, .action-card, .mini-card, .kv, .info-row, .history-row, .ai-row {
    min-width: 0;
}
.header-shell { padding: 22px; margin-bottom: 18px; }
.profile-hero {
    position: relative;
    overflow: hidden;
    padding: 0;
}
.profile-cover {
    position: relative;
    min-height: 360px;
    padding: 24px;
    background:
        linear-gradient(180deg, rgba(17,24,39,.18), rgba(17,24,39,.48)),
        radial-gradient(circle at top right, rgba(255,255,255,.22), transparent 32%),
        linear-gradient(120deg, #1b74e4 0%, #1d4ed8 45%, #0f172a 100%);
    color: #fff;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}
.profile-cover-media {
    position: absolute;
    inset: 0;
}
.profile-cover-media img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    filter: saturate(1.05) contrast(1.02);
}
.profile-cover-overlay {
    position: absolute;
    inset: 0;
    background: linear-gradient(180deg, rgba(15,23,42,.12) 0%, rgba(15,23,42,.58) 72%, rgba(15,23,42,.82) 100%);
}
.profile-cover::after {
    content: "";
    position: absolute;
    inset: auto -12% -52px auto;
    width: 240px;
    height: 240px;
    border-radius: 999px;
    background: rgba(255,255,255,.08);
}
.profile-cover-top {
    position: relative;
    z-index: 2;
    display: flex;
    justify-content: space-between;
    gap: 14px;
    align-items: flex-start;
    flex-wrap: wrap;
}
.profile-facebook-meta {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 16px;
}
.facebook-count {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 14px;
    border-radius: 999px;
    background: rgba(255,255,255,.15);
    border: 1px solid rgba(255,255,255,.18);
    font-size: 13px;
    font-weight: 700;
}
.cover-badge,
.cover-chip,
.profile-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 14px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 800;
}
.cover-badge {
    background: rgba(255,255,255,.14);
    border: 1px solid rgba(255,255,255,.18);
    letter-spacing: .04em;
    text-transform: uppercase;
}
.cover-chip-row {
    position: relative;
    z-index: 2;
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 18px;
}
.cover-chip {
    background: rgba(15,23,42,.22);
    border: 1px solid rgba(255,255,255,.18);
    color: #f8fafc;
    font-size: 13px;
    font-weight: 700;
}
.profile-hero-body {
    position: relative;
    margin-top: -72px;
    padding: 0 24px 24px;
}
.profile-identity {
    position: relative;
    z-index: 2;
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto;
    gap: 18px;
    align-items: end;
}
.profile-avatar-xl {
    position: relative;
    width: 112px;
    height: 112px;
    border-radius: 32px;
    background: linear-gradient(135deg, #10b981, #0ea5e9);
    border: 5px solid rgba(255,255,255,.92);
    box-shadow: 0 22px 50px rgba(15,23,42,.24);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 38px;
    font-weight: 900;
    overflow: hidden;
}
.profile-avatar-xl img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.media-edit-btn {
    border: 1px solid rgba(255,255,255,.22);
    background: rgba(15,23,42,.65);
    color: #fff;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    min-height: 40px;
    padding: 0 14px;
    border-radius: 999px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 800;
    backdrop-filter: blur(10px);
}
.media-edit-btn.light {
    background: var(--surface-strong);
    color: var(--text);
    border-color: var(--border);
}
.cover-actions {
    position: relative;
    z-index: 2;
    display: flex;
    justify-content: flex-end;
    margin-top: 16px;
}
.avatar-edit-wrap {
    position: absolute;
    right: 8px;
    bottom: 8px;
}
.avatar-edit-btn {
    width: 36px;
    height: 36px;
    border-radius: 12px;
    border: 1px solid rgba(255,255,255,.35);
    background: rgba(15,23,42,.82);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
}
.hidden-upload {
    display: none;
}
.profile-main-copy {
    min-width: 0;
    padding-top: 18px;
}
.profile-main-copy .welcome-line {
    font-size: 14px;
    font-weight: 700;
    color: var(--accent);
    margin-bottom: 8px;
    text-transform: uppercase;
    letter-spacing: .08em;
}
.profile-name {
    font-size: clamp(24px, 4vw, 34px);
    font-weight: 900;
    line-height: 1.05;
    margin-bottom: 8px;
    overflow-wrap: anywhere;
}
.profile-handle {
    color: var(--text-soft);
    font-size: 14px;
    font-weight: 700;
    margin-bottom: 12px;
}
.profile-badges,
.profile-side-actions,
.profile-highlight-grid,
.social-panel {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}
.profile-badge {
    border: 1px solid var(--border);
    background: var(--surface-strong);
    font-size: 13px;
    font-weight: 700;
}
.profile-side-actions {
    justify-content: flex-end;
}
.profile-summary-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.25fr) minmax(0, .95fr);
    gap: 18px;
    margin-top: 18px;
}
.profile-summary-card,
.profile-highlight-card {
    background: var(--surface-strong);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 18px;
}
.profile-summary-card.facebook-intro {
    display: grid;
    gap: 16px;
}
.facebook-intro-list {
    display: grid;
    gap: 12px;
}
.facebook-intro-item {
    display: grid;
    grid-template-columns: 36px minmax(0, 1fr);
    gap: 12px;
    align-items: center;
    font-size: 14px;
    font-weight: 700;
}
.facebook-intro-item i {
    width: 36px;
    height: 36px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #e7f3ff;
    color: #1877f2;
}
.facebook-profile-stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
}
.facebook-stat-card {
    padding: 16px;
    border-radius: 18px;
    background: linear-gradient(180deg, #ffffff 0%, #f7faff 100%);
    border: 1px solid rgba(24,119,242,.12);
}
.facebook-stat-card .label {
    font-size: 12px;
    font-weight: 700;
    color: var(--text-soft);
    margin-top: 8px;
}
.facebook-stat-card .value {
    font-size: 26px;
    font-weight: 900;
    color: #1b74e4;
}
.facebook-tabbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin: 18px 0;
    padding: 14px 18px;
    border-radius: 18px;
    border: 1px solid var(--border);
    background: var(--surface-strong);
    box-shadow: var(--shadow);
}
.live-progress-card {
    background: linear-gradient(180deg, #ffffff 0%, #f6f9ff 100%);
    border: 1px solid rgba(24,119,242,.14);
}
.live-progress-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
}
.live-progress-item {
    padding: 14px;
    border-radius: 16px;
    background: #f0f6ff;
    border: 1px solid rgba(24,119,242,.12);
}
.live-progress-item .k {
    font-size: 11px;
    color: var(--text-soft);
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    margin-bottom: 8px;
}
.live-progress-item .v {
    font-size: 22px;
    font-weight: 900;
    color: #1b74e4;
}
.facebook-tabbar-copy strong {
    display: block;
    font-size: 18px;
    font-weight: 900;
}
.facebook-tabbar-copy span {
    color: var(--text-soft);
    font-size: 13px;
    font-weight: 600;
}
.profile-summary-title {
    font-size: 13px;
    font-weight: 800;
    color: var(--text-soft);
    text-transform: uppercase;
    letter-spacing: .06em;
    margin-bottom: 10px;
}
.profile-summary-text {
    font-size: 15px;
    line-height: 1.7;
    color: var(--text);
}
.profile-highlight-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}
.profile-highlight {
    padding: 14px;
    border-radius: 16px;
    background: var(--surface);
    border: 1px solid var(--border);
}
.profile-highlight-label {
    font-size: 12px;
    font-weight: 700;
    color: var(--text-soft);
    margin-bottom: 8px;
}
.profile-highlight-value {
    font-size: 20px;
    font-weight: 900;
}
.facebook-tabs {
    display: flex;
    gap: 10px;
    flex-wrap: nowrap;
    overflow-x: auto;
    scrollbar-width: none;
    margin-bottom: 0;
}
.facebook-tabs::-webkit-scrollbar { display: none; }
.facebook-tab {
    border: none;
    background: transparent;
    color: var(--text-soft);
    border-radius: 0;
    min-height: 46px;
    padding: 0 18px;
    font-size: 14px;
    font-weight: 800;
    cursor: pointer;
    position: relative;
    white-space: nowrap;
}
.facebook-tab.active {
    color: #1b74e4;
}
.facebook-tab.active::after {
    content: "";
    position: absolute;
    left: 12px;
    right: 12px;
    bottom: 0;
    height: 3px;
    border-radius: 999px;
    background: #1b74e4;
}
.tab-panel { display: none; }
.tab-panel.active { display: block; }
.feed-layout {
    display: grid;
    grid-template-columns: minmax(0, 1.2fr) minmax(300px, .8fr);
    gap: 18px;
    align-items: start;
}
.composer-card, .feed-card {
    background: var(--surface-strong);
    border: 1px solid var(--border);
    border-radius: 20px;
    box-shadow: var(--shadow);
    padding: 18px;
    margin-bottom: 18px;
}
.composer-top {
    display: grid;
    grid-template-columns: 56px minmax(0, 1fr);
    gap: 14px;
    align-items: start;
    margin-bottom: 14px;
}
.mini-avatar {
    width: 56px;
    height: 56px;
    border-radius: 18px;
    background: linear-gradient(135deg, #10b981, #0ea5e9);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    font-weight: 900;
    overflow: hidden;
}
.mini-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.composer-grid {
    display: grid;
    gap: 12px;
}
.composer-grid textarea {
    min-height: 110px;
    resize: vertical;
}
.composer-inline {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 12px;
}
.media-note {
    font-size: 12px;
    color: var(--text-soft);
    line-height: 1.6;
}
.rule-card {
    background: linear-gradient(135deg, rgba(15,118,110,.12), rgba(14,165,233,.08));
    border: 1px solid rgba(14,165,233,.16);
}
.rule-list {
    display: grid;
    gap: 10px;
    margin-top: 12px;
}
.rule-item {
    padding: 12px 14px;
    border-radius: 14px;
    background: var(--surface-strong);
    border: 1px solid var(--border);
    font-size: 13px;
    font-weight: 700;
    line-height: 1.5;
}
.feed-post {
    background: var(--surface-strong);
    border: 1px solid var(--border);
    border-radius: 22px;
    padding: 18px;
    margin-bottom: 16px;
}
.feed-card.facebook-feed-shell {
    background: transparent;
    border: none;
    box-shadow: none;
    padding: 0;
}
.facebook-panel-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 14px;
}
.facebook-panel-title span {
    color: var(--text-soft);
    font-size: 13px;
    font-weight: 600;
}
.btn-delete-post {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 5px 11px; border-radius: 8px; border: none; cursor: pointer;
    font-size: 12px; font-weight: 700; font-family: inherit;
    background: rgba(239,68,68,.10); color: #ef4444;
    transition: background .15s;
}
.btn-delete-post:hover { background: rgba(239,68,68,.2); }
.feed-post-head {
    display: grid;
    grid-template-columns: 54px minmax(0, 1fr) auto;
    gap: 12px;
    align-items: center;
    margin-bottom: 14px;
}
.feed-author-line {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
}
.actor-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 10px;
    border-radius: 999px;
    background: rgba(14,165,233,.12);
    color: #0369a1;
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
}
.actor-badge.admin {
    background: rgba(245,158,11,.14);
    color: #b45309;
}
.category-badge {
    display: inline-flex;
    align-items: center;
    padding: 6px 10px;
    border-radius: 999px;
    background: rgba(16,185,129,.12);
    color: #047857;
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
}
.feed-post-title {
    font-size: 19px;
    font-weight: 900;
    margin-bottom: 10px;
}
.feed-post-body {
    font-size: 15px;
    line-height: 1.75;
    color: var(--text);
    white-space: pre-wrap;
}
.feed-media {
    margin-top: 14px;
    border-radius: 18px;
    overflow: hidden;
    border: 1px solid var(--border);
    background: #000;
    cursor: pointer;
}
.feed-media img,
.feed-media video {
    display: block;
    width: 100%;
    max-height: 420px;
    object-fit: cover;
}
.feed-meta {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 12px;
    color: var(--text-soft);
    font-size: 12px;
    font-weight: 700;
}
.comment-stack {
    display: grid;
    gap: 10px;
    margin-top: 16px;
}
.comment-item {
    display: grid;
    grid-template-columns: 40px minmax(0, 1fr);
    gap: 10px;
    padding: 12px;
    border-radius: 16px;
    background: var(--surface);
    border: 1px solid var(--border);
}
.comment-item .mini-avatar {
    width: 40px;
    height: 40px;
    border-radius: 14px;
    font-size: 16px;
}
.comment-author {
    font-size: 13px;
    font-weight: 900;
}
.comment-text {
    font-size: 14px;
    line-height: 1.6;
    margin-top: 4px;
}
.comment-form {
    display: grid;
    gap: 10px;
    margin-top: 14px;
}
.comment-form textarea {
    min-height: 78px;
    resize: vertical;
}
.gallery-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
}
.gallery-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 20px;
    overflow: hidden;
    box-shadow: var(--shadow);
}
.gallery-media {
    width: 100%;
    aspect-ratio: 1 / 1;
    background: #0f172a;
    cursor: pointer;
    position: relative;
}
.gallery-media img,
.gallery-media video {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.gallery-play {
    position: absolute;
    inset: 50% auto auto 50%;
    transform: translate(-50%, -50%);
    width: 58px;
    height: 58px;
    border-radius: 999px;
    background: rgba(15,23,42,.72);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    border: 1px solid rgba(255,255,255,.2);
    pointer-events: none;
}
.media-viewer {
    position: fixed;
    inset: 0;
    z-index: 32000;
    display: none;
    align-items: center;
    justify-content: center;
    background: rgba(0,0,0,.94);
    padding: 18px;
}
.media-viewer.show {
    display: flex;
}
.media-viewer-shell {
    width: min(100%, 1100px);
    max-height: 100%;
    position: relative;
}
.media-viewer-close {
    position: absolute;
    top: -6px;
    right: -6px;
    width: 44px;
    height: 44px;
    border-radius: 999px;
    border: none;
    background: rgba(255,255,255,.14);
    color: #fff;
    font-size: 20px;
    cursor: pointer;
}
.media-viewer-stage {
    width: 100%;
    max-height: calc(100vh - 110px);
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 22px;
    overflow: hidden;
    background: #000;
}
.media-viewer-stage img,
.media-viewer-stage video {
    width: 100%;
    max-height: calc(100vh - 110px);
    object-fit: contain;
    display: block;
}
.media-viewer-caption {
    color: #fff;
    text-align: center;
    margin-top: 14px;
    font-size: 14px;
    font-weight: 700;
}
.gallery-copy {
    padding: 12px 14px 14px;
}
.late-warning-big {
    background: linear-gradient(135deg, rgba(239,68,68,0.14), rgba(249,115,22,0.12));
    border: 1px solid rgba(239,68,68,0.28);
    border-radius: 16px;
    padding: 20px;
    margin-bottom: 18px;
    text-align: center;
    box-shadow: var(--shadow);
}
.late-warning-big .icon { font-size: 40px; margin-bottom: 10px; }
.late-warning-big .count { font-size: 30px; font-weight: 900; margin-bottom: 8px; color: var(--danger); }
.late-warning-big .message { font-size: 14px; line-height: 1.7; color: var(--text); }
.header-top { display: flex; justify-content: space-between; gap: 16px; align-items: center; flex-wrap: wrap; }
.header-user { display: flex; align-items: center; gap: 14px; min-width: 0; }
.user-avatar {
    width: 64px; height: 64px; border-radius: 18px;
    background: linear-gradient(135deg, var(--accent), var(--accent-2));
    color: #fff; display: flex; align-items: center; justify-content: center;
    font-size: 24px; font-weight: 900; box-shadow: 0 16px 36px rgba(16,185,129,0.26);
}
.header-copy { min-width: 0; flex: 1; }
.eyebrow { font-size: 12px; font-weight: 700; color: var(--accent); letter-spacing: .08em; text-transform: uppercase; margin-bottom: 6px; }
.welcome-line { font-size: clamp(22px, 4vw, 30px); font-weight: 800; line-height: 1.12; margin-bottom: 4px; overflow-wrap: anywhere; }
.subline { color: var(--text-soft); font-size: 14px; font-weight: 600; overflow-wrap: anywhere; }
.header-meta { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-top: 16px; }
.pill {
    display: inline-flex; align-items: center; gap: 8px; padding: 10px 14px;
    border-radius: 999px; background: var(--surface-muted); color: var(--text);
    font-size: 13px; font-weight: 700; border: 1px solid var(--border); min-width: 0;
}
.status-pill { padding: 11px 16px; }
.status-success { color: #047857; background: rgba(16,185,129,0.12); }
.status-danger { color: #b91c1c; background: rgba(239,68,68,0.12); }
.status-warning { color: #b45309; background: rgba(245,158,11,0.14); }
.status-info { color: #1d4ed8; background: rgba(59,130,246,0.14); }
.header-right { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.notif-bell, .icon-btn {
    position: relative; width: 48px; height: 48px; border-radius: 14px; border: 1px solid var(--border);
    background: var(--surface-strong); color: var(--text); display: flex; align-items: center; justify-content: center;
    cursor: pointer; transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease;
}
.notif-bell:hover, .icon-btn:hover, .card:hover, .action-card:hover, .mini-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 24px 40px rgba(15,23,42,0.12);
    border-color: rgba(16,185,129,0.28);
}
.notif-badge {
    position: absolute; top: -5px; right: -5px; min-width: 21px; height: 21px; padding: 0 6px;
    border-radius: 999px; background: var(--danger); color: #fff; font-size: 11px; font-weight: 900;
    display: flex; align-items: center; justify-content: center;
}
.btn-logout, .btn-secondary {
    text-decoration: none; border: none; cursor: pointer; font-family: inherit;
    display: inline-flex; align-items: center; justify-content: center; gap: 8px;
    min-height: 48px; padding: 0 18px; border-radius: 14px; font-size: 14px; font-weight: 700;
}
.btn-logout { background: linear-gradient(135deg, var(--danger), #dc2626); color: #fff; }
.btn-secondary { background: var(--surface-strong); color: var(--text); border: 1px solid var(--border); }
.install-btn {
    background: linear-gradient(135deg, var(--accent), var(--accent-2));
    color: #fff;
    display: none;
}
.network-badge{
    position:fixed;
    left:50%;
    bottom:18px;
    transform:translateX(-50%);
    z-index:25000;
    display:none;
    align-items:center;
    gap:8px;
    padding:11px 16px;
    border-radius:999px;
    background:rgba(239,68,68,.95);
    color:#fff;
    font-size:13px;
    font-weight:800;
    box-shadow:0 18px 40px rgba(0,0,0,.22);
}
.network-badge.show{display:inline-flex;}
.sync-badge{
    position:fixed;
    right:16px;
    bottom:18px;
    z-index:25000;
    display:none;
    align-items:center;
    gap:8px;
    padding:10px 14px;
    border-radius:999px;
    background:rgba(15,23,42,.92);
    color:#fff;
    font-size:12px;
    font-weight:800;
    box-shadow:0 18px 40px rgba(0,0,0,.22);
}
.sync-badge.show{display:inline-flex;}
.lock-overlay{
    position:fixed;
    inset:0;
    z-index:31000;
    display:none;
    align-items:center;
    justify-content:center;
    padding:20px;
    background:linear-gradient(180deg, rgba(15,23,42,.96) 0%, rgba(17,24,39,.98) 100%);
}
.lock-overlay.show{display:flex;}
.lock-box{
    width:min(100%, 360px);
    background:rgba(255,255,255,.96);
    color:#0f172a;
    border-radius:24px;
    padding:26px 22px;
    box-shadow:0 30px 70px rgba(0,0,0,.35);
    text-align:center;
}
.lock-title{font-size:22px;font-weight:800;margin-bottom:8px;}
.lock-text{font-size:13px;color:#475569;line-height:1.7;margin-bottom:18px;}
.lock-actions{display:grid;gap:10px;margin-top:14px;}
.lock-actions .btn{width:100%;}
.splash-screen{
    position:fixed;
    inset:0;
    z-index:30000;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:24px;
    background:
        radial-gradient(circle at top, rgba(16,185,129,.22), transparent 26%),
        linear-gradient(180deg, #0f172a 0%, #111827 100%);
    color:#e2e8f0;
    transition:opacity .35s ease, visibility .35s ease;
}
.splash-screen.hidden{
    opacity:0;
    visibility:hidden;
    pointer-events:none;
}
.splash-box{
    width:min(100%, 360px);
    text-align:center;
    padding:28px 24px;
    border-radius:28px;
    background:rgba(15,23,42,.58);
    border:1px solid rgba(255,255,255,.08);
    box-shadow:0 25px 60px rgba(0,0,0,.35);
}
.splash-logo{
    width:86px;
    height:86px;
    border-radius:24px;
    margin:0 auto 18px;
    background:linear-gradient(135deg, #10b981, #0ea5e9);
    display:flex;
    align-items:center;
    justify-content:center;
    box-shadow:0 18px 40px rgba(16,185,129,.25);
    overflow:hidden;
}
.splash-logo img{
    width:100%;
    height:100%;
    object-fit:cover;
}
.splash-title{
    font-size:22px;
    font-weight:800;
    margin-bottom:8px;
}
.splash-subtitle{
    font-size:13px;
    color:#94a3b8;
    line-height:1.6;
}
.splash-loader{
    width:100%;
    height:8px;
    margin-top:18px;
    border-radius:999px;
    background:rgba(148,163,184,.18);
    overflow:hidden;
}
.splash-loader::after{
    content:"";
    display:block;
    width:42%;
    height:100%;
    border-radius:inherit;
    background:linear-gradient(90deg, #10b981, #0ea5e9);
    animation:splash-slide 1.2s ease-in-out infinite;
}
@keyframes splash-slide{
    0%{transform:translateX(-110%)}
    100%{transform:translateX(260%)}
}
.status-strip { padding: 18px 20px; margin-bottom: 18px; display: grid; grid-template-columns: minmax(0,1.2fr) minmax(0,.8fr); gap: 16px; }
.status-main h3, .card-title { font-size: 18px; font-weight: 800; margin-bottom: 10px; display: flex; align-items: center; gap: 10px; }
.status-main p { color: var(--text-soft); line-height: 1.7; }
.status-quick { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.mini-card {
    padding: 16px; border-radius: 16px; background: var(--surface-strong); border: 1px solid var(--border);
    transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease;
}
.mini-card-label { font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color: var(--text-soft); font-weight: 700; margin-bottom: 8px; }
.mini-card-value { font-size: 22px; font-weight: 800; }
.stats-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; }
.stat-card, .action-card {
    background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
    padding: 20px; box-shadow: var(--shadow); transition: transform .22s ease, box-shadow .22s ease, border-color .22s ease;
}
.stat-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 14px; }
.stat-icon, .action-icon {
    width: 52px; height: 52px; border-radius: 16px; color: #fff; display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
}
.stat-primary { background: linear-gradient(135deg, #10b981, #34d399); }
.stat-success { background: linear-gradient(135deg, #0ea5e9, #3b82f6); }
.stat-danger { background: linear-gradient(135deg, #ef4444, #f97316); }
.stat-warning { background: linear-gradient(135deg, #8b5cf6, #6366f1); }
.stat-value { font-size: 30px; font-weight: 900; line-height: 1.05; margin-bottom: 8px; }
.stat-label { font-size: 13px; font-weight: 700; color: var(--text-soft); margin-bottom: 6px; }
.stat-subtext { font-size: 12px; color: var(--text-soft); line-height: 1.5; }
.section-grid { display: grid; grid-template-columns: minmax(0,1.25fr) minmax(0,.95fr); gap: 18px; align-items: start; }
.card { padding: 20px; margin-bottom: 18px; }
.clock-panel { text-align: center; padding: 10px 0 18px; }
.time-display { font-size: clamp(34px, 7vw, 54px); font-weight: 900; letter-spacing: -0.04em; color: var(--accent); margin: 10px 0 8px; font-variant-numeric: tabular-nums; }
.date-display { color: var(--text-soft); font-size: 15px; font-weight: 600; margin-bottom: 16px; }
.status-line { display: inline-flex; align-items: center; gap: 8px; padding: 12px 16px; border-radius: 999px; font-weight: 800; margin-bottom: 16px; }
.btn {
    width: 100%; border: none; cursor: pointer; min-height: 52px; border-radius: 14px;
    padding: 15px 18px; font-size: 14px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em;
    display: inline-flex; align-items: center; justify-content: center; gap: 10px;
}
.btn-primary { background: linear-gradient(135deg, var(--accent), #34d399); color: #fff; }
.btn-success { background: linear-gradient(135deg, #0ea5e9, #3b82f6); color: #fff; }
.btn-danger { background: linear-gradient(135deg, #ef4444, #dc2626); color: #fff; }
.btn-muted { background: var(--surface-muted); color: var(--text); border: 1px solid var(--border); }
.btn:disabled { opacity: .55; cursor: not-allowed; }
.today-breakdown { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin-top: 18px; }
.kv {
    background: var(--surface-strong); border: 1px solid var(--border); border-radius: 14px; padding: 14px;
}
.kv-label { font-size: 12px; color: var(--text-soft); text-transform: uppercase; letter-spacing: .06em; font-weight: 700; margin-bottom: 8px; }
.kv-value { font-size: 18px; font-weight: 800; }
.actions-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
.action-card { cursor: pointer; display: flex; flex-direction: column; gap: 14px; min-height: 164px; }
.action-title { font-size: 16px; font-weight: 800; overflow-wrap: anywhere; }
.action-desc { font-size: 13px; color: var(--text-soft); line-height: 1.6; overflow-wrap: anywhere; }
.section-title { font-size: 20px; font-weight: 800; margin-bottom: 14px; }
.list-stack { display: grid; gap: 12px; }
.info-row, .history-row, .ai-row {
    display: flex; justify-content: space-between; gap: 14px; align-items: center;
    background: var(--surface-strong); border: 1px solid var(--border); border-radius: 14px; padding: 14px 16px;
}
.contact-row,
.timeline-item {
    display: grid;
    gap: 12px;
    align-items: center;
    padding: 14px 16px;
    background: var(--surface-strong);
    border: 1px solid var(--border);
    border-radius: 16px;
}
.contact-row {
    grid-template-columns: 42px minmax(0, 1fr);
}
.contact-icon {
    width: 42px;
    height: 42px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, rgba(16,185,129,.14), rgba(14,165,233,.14));
    color: var(--accent);
}
.contact-label {
    font-size: 12px;
    color: var(--text-soft);
    font-weight: 700;
    margin-bottom: 4px;
}
.contact-value {
    font-size: 14px;
    font-weight: 800;
    overflow-wrap: anywhere;
}
.social-panel {
    display: grid;
    gap: 12px;
}
.timeline-item {
    grid-template-columns: auto minmax(0, 1fr);
    align-items: start;
}
.timeline-dot {
    width: 14px;
    height: 14px;
    margin-top: 4px;
    border-radius: 999px;
    background: var(--accent);
    box-shadow: 0 0 0 6px rgba(16,185,129,.12);
}
.timeline-dot.warning { background: var(--warning); box-shadow: 0 0 0 6px rgba(245,158,11,.14); }
.timeline-dot.danger { background: var(--danger); box-shadow: 0 0 0 6px rgba(239,68,68,.12); }
.timeline-dot.info { background: var(--info); box-shadow: 0 0 0 6px rgba(59,130,246,.12); }
.info-row strong, .history-row strong, .ai-row strong { font-size: 14px; }
.muted { color: var(--text-soft); }
.chart-wrap { position: relative; height: 250px; width: 100%; }
.chart-grid { display: grid; grid-template-columns: minmax(0,1fr) minmax(0,1fr); gap: 18px; }
.badge-score {
    padding: 8px 12px; border-radius: 999px; background: rgba(16,185,129,0.15); color: #047857;
    font-size: 12px; font-weight: 800;
}
.history-meta { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
.history-side { text-align: right; flex-shrink: 0; }
.empty-state { text-align: center; color: var(--text-soft); padding: 18px 12px; }
.data-block {
    background: linear-gradient(135deg, rgba(16,185,129,0.12), rgba(59,130,246,0.08));
    border: 1px solid rgba(16,185,129,0.2);
}
.mono { font-variant-numeric: tabular-nums; }
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.8);
    z-index: 9999;
    padding: 12px;
    overflow-y: auto;
}

.modal.show { display: block; }

.modal-content {
    background: white;
    border-radius: 16px;
    padding: 20px;
    max-width: 500px;
    margin: 20px auto;
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 2px solid #e5e7eb;
}

.modal-title {
    font-size: 18px;
    font-weight: 800;
    color: var(--dark);
}

.close-modal {
    font-size: 28px;
    cursor: pointer;
    color: var(--gray);
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
}

/* FORMS */
.form-group {
    margin-bottom: 16px;
}

label {
    display: block;
    font-size: 13px;
    font-weight: 700;
    color: var(--dark);
    margin-bottom: 8px;
    text-transform: uppercase;
}

input, textarea, select {
    width: 100%;
    padding: 14px;
    border: 2px solid #e5e7eb;
    border-radius: 10px;
    font-size: 16px;
    font-weight: 500;
    font-family: 'Poppins', sans-serif;
}

input:focus, textarea:focus, select:focus {
    outline: none;
    border-color: var(--primary);
}

/* CAMERA */
#video, #canvas {
    width: 100%;
    max-width: 400px;
    border-radius: 12px;
    margin: 10px auto;
    display: block;
}

/* STICKY TOPBAR */
.sticky-topbar {
    position: sticky;
    top: 0;
    z-index: 9000;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 10px 16px;
    margin-bottom: 14px;
    background: var(--surface-strong);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: 0 4px 20px rgba(15,23,42,.12);
    backdrop-filter: blur(18px);
}
.topbar-brand {
    display: flex; align-items: center; gap: 8px;
    font-size: 15px; font-weight: 900; color: var(--text); letter-spacing: -.3px;
    text-decoration: none;
}
.topbar-brand img { width: 28px; height: 28px; border-radius: 7px; }
.topbar-right { display: flex; align-items: center; gap: 8px; }
.topbar-clock { font-size: 14px; font-weight: 800; font-family: monospace; color: var(--text-soft); min-width: 42px; }

/* cloche propre dans le topbar */
.topbar-bell {
    position: relative; width: 42px; height: 42px; border-radius: 12px;
    background: var(--surface-muted); border: 1px solid var(--border);
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; font-size: 17px; color: var(--text);
    transition: background .15s;
}
.topbar-bell:hover { background: rgba(24,119,242,.12); color: var(--accent); }
.topbar-bell .notif-badge {
    position: absolute; top: -4px; right: -4px;
    min-width: 18px; height: 18px; padding: 0 4px;
    border-radius: 999px; background: var(--danger); color: #fff;
    font-size: 10px; font-weight: 900;
    display: flex; align-items: center; justify-content: center;
    border: 2px solid var(--surface-strong);
}
.topbar-logout {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 0 14px; height: 42px; border-radius: 12px;
    background: linear-gradient(135deg,var(--danger),#dc2626);
    color: #fff; font-size: 13px; font-weight: 700;
    text-decoration: none; border: none; cursor: pointer; font-family: inherit;
    transition: opacity .15s;
}
.topbar-logout:hover { opacity: .88; }
.topbar-install {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 0 12px; height: 42px; border-radius: 12px;
    background: var(--surface-muted); color: var(--text); border: 1px solid var(--border);
    font-size: 13px; font-weight: 700; cursor: pointer; font-family: inherit;
}

/* ATTENDANCE TOP BAR */
.attendance-top-bar {
    display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
    background: var(--surface-strong);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 14px 18px;
    margin-bottom: 16px;
    box-shadow: 0 4px 18px rgba(15,23,42,0.08);
}
.atb-status {
    display: flex; align-items: center; gap: 8px;
    flex: 1; min-width: 180px;
}
.atb-dot { width: 12px; height: 12px; border-radius: 50%; flex-shrink: 0; }
.atb-dot.green { background: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,.25); }
.atb-dot.red { background: #ef4444; box-shadow: 0 0 0 3px rgba(239,68,68,.25); }
.atb-dot.blue { background: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,.25); }
.atb-dot.orange { background: #f59e0b; box-shadow: 0 0 0 3px rgba(245,158,11,.25); }
.atb-info { display: flex; flex-direction: column; }
.atb-label { font-size: 11px; color: var(--text-soft); font-weight: 600; text-transform: uppercase; letter-spacing: .5px; }
.atb-value { font-size: 15px; font-weight: 800; color: var(--text); font-family: monospace; }
.atb-sep { width: 1px; height: 36px; background: var(--border); flex-shrink: 0; }
.atb-time { font-size: 22px; font-weight: 900; font-family: monospace; color: var(--text); min-width: 80px; }
.atb-actions { display: flex; gap: 8px; margin-left: auto; flex-wrap: wrap; }
.atb-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 16px; border-radius: 12px; border: none; cursor: pointer;
    font-size: 13px; font-weight: 700; font-family: inherit;
    transition: transform .1s, opacity .15s;
}
.atb-btn:active { transform: scale(.97); }
.atb-btn-in { background: linear-gradient(135deg,#10b981,#059669); color:#fff; }
.atb-btn-out { background: linear-gradient(135deg,#ef4444,#dc2626); color:#fff; }
.atb-btn-disabled { background: var(--surface-muted); color: var(--text-soft); cursor: not-allowed; opacity: .7; }

/* NOTIFICATIONS FULLSCREEN */
.notif-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.55);
    backdrop-filter: blur(4px);
    z-index: 10999;
    opacity: 0;
    pointer-events: none;
    transition: opacity .25s;
}
.notif-overlay.show { opacity: 1; pointer-events: auto; }

.notif-panel {
    position: fixed;
    inset: 0;
    z-index: 11000;
    display: flex;
    flex-direction: column;
    background: var(--bg);
    transform: translateY(100%);
    transition: transform .3s cubic-bezier(.4,0,.2,1);
    overflow: hidden;
}
.notif-panel.show { transform: translateY(0); }

.notif-header {
    flex-shrink: 0;
    padding: 18px max(20px, env(safe-area-inset-left)) 14px;
    background: linear-gradient(135deg,#1e293b,#0f172a);
    color: white;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
}
.notif-header-left { display: flex; flex-direction: column; gap: 3px; }
.notif-header-actions { display: flex; gap: 8px; align-items: center; }
.notif-mark-all {
    font-size: 12px; font-weight: 700; padding: 8px 14px;
    background: rgba(255,255,255,.12); color: #fff; border: 1px solid rgba(255,255,255,.2);
    border-radius: 10px; cursor: pointer; font-family: inherit; letter-spacing: .3px;
    transition: background .15s;
}
.notif-mark-all:hover { background: rgba(255,255,255,.22); }

.notif-close {
    font-size: 22px; cursor: pointer; line-height: 1;
    width: 38px; height: 38px; display: flex; align-items: center; justify-content: center;
    border-radius: 10px; background: rgba(255,255,255,.1); color: #fff;
    transition: background .15s;
}
.notif-close:hover { background: rgba(255,255,255,.22); }

/* liste scrollable occupe tout l'espace restant */
#notifList {
    flex: 1;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
    max-width: 780px;
    width: 100%;
    margin: 0 auto;
}

.notif-item {
    padding: 16px 20px;
    border-bottom: 1px solid var(--border);
    cursor: pointer;
    display: flex; gap: 14px; align-items: flex-start;
    transition: background .1s;
}
.notif-item:active { background: var(--surface-muted); }
@media (hover:hover) { .notif-item:hover { background: var(--surface-muted); } }

.notif-item.unread { background: rgba(24,119,242,.07); }
@media (prefers-color-scheme:dark) { .notif-item.unread { background: rgba(59,130,246,.10); } }

.notif-icon {
    width: 44px; height: 44px; border-radius: 13px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center; font-size: 18px;
}
.notif-icon.type-check_in      { background: rgba(16,185,129,.15); color: #10b981; }
.notif-icon.type-check_out     { background: rgba(59,130,246,.15);  color: #3b82f6; }
.notif-icon.type-overtime_bonus{ background: rgba(245,158,11,.15);  color: #f59e0b; }
.notif-icon.type-late_warning  { background: rgba(239,68,68,.15);   color: #ef4444; }
.notif-icon.type-social_post   { background: rgba(139,92,246,.15);  color: #8b5cf6; }
.notif-icon.type-permission    { background: rgba(14,165,233,.15);  color: #0ea5e9; }
.notif-icon.type-advance       { background: rgba(16,185,129,.15);  color: #10b981; }
.notif-icon.type-info          { background: rgba(148,163,184,.15); color: #94a3b8; }

.notif-body { flex: 1; min-width: 0; }
.notif-time {
    font-size: 11px; color: var(--text-soft); margin-bottom: 5px; font-weight: 600;
    text-transform: uppercase; letter-spacing: .4px;
}
.notif-message { font-size: 14px; color: var(--text); line-height: 1.55; font-weight: 500; }
.notif-unread-dot {
    width: 9px; height: 9px; border-radius: 50%; background: var(--accent);
    flex-shrink: 0; margin-top: 6px;
}
.notif-empty {
    padding: 80px 24px; text-align: center; color: var(--text-soft); font-size: 15px;
}
.notif-empty i { font-size: 52px; display: block; margin-bottom: 14px; opacity: .3; }

/* POPUP DÉTAIL NOTIFICATION */
.notif-popup-overlay {
    position: fixed; inset: 0; z-index: 12000;
    background: rgba(0,0,0,.6); backdrop-filter: blur(6px);
    display: flex; align-items: center; justify-content: center;
    padding: 20px;
    opacity: 0; pointer-events: none;
    transition: opacity .2s;
}
.notif-popup-overlay.show { opacity: 1; pointer-events: auto; }
.notif-popup {
    background: var(--surface-strong);
    border: 1px solid var(--border);
    border-radius: 22px;
    width: 100%; max-width: 480px;
    box-shadow: 0 32px 80px rgba(0,0,0,.35);
    overflow: hidden;
    transform: scale(.92) translateY(16px);
    transition: transform .25s cubic-bezier(.4,0,.2,1);
}
.notif-popup-overlay.show .notif-popup { transform: scale(1) translateY(0); }
.notif-popup-top {
    display: flex; align-items: center; gap: 14px;
    padding: 22px 22px 16px;
    border-bottom: 1px solid var(--border);
}
.notif-popup-icon {
    width: 52px; height: 52px; border-radius: 15px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center; font-size: 22px;
}
.notif-popup-meta { flex: 1; min-width: 0; }
.notif-popup-title { font-size: 13px; font-weight: 800; color: var(--text); margin-bottom: 4px; }
.notif-popup-date  { font-size: 11px; color: var(--text-soft); font-weight: 600; letter-spacing: .3px; }
.notif-popup-close {
    width: 36px; height: 36px; border-radius: 10px; border: none; cursor: pointer;
    background: var(--surface-muted); color: var(--text); font-size: 18px;
    display: flex; align-items: center; justify-content: center;
    transition: background .15s; flex-shrink: 0;
}
.notif-popup-close:hover { background: rgba(239,68,68,.15); color: #ef4444; }
.notif-popup-body {
    padding: 20px 22px 24px;
    font-size: 15px; line-height: 1.7; color: var(--text); font-weight: 400;
}
.notif-popup-footer {
    padding: 0 22px 20px;
    display: flex; justify-content: flex-end;
}
.notif-popup-btn {
    padding: 10px 22px; border-radius: 12px; border: none; cursor: pointer;
    font-size: 13px; font-weight: 700; font-family: inherit;
    background: var(--accent); color: #fff;
    transition: opacity .15s;
}
.notif-popup-btn:hover { opacity: .85; }

/* RESPONSIVE */
@media (min-width: 480px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (min-width: 768px) {
    body {
        padding: 20px;
    }
    
    .header {
        padding: 24px;
    }
    
    .stats-grid {
        grid-template-columns: repeat(4, 1fr);
    }
}

/* SWEETALERT2 CUSTOM */
.swal-mobile {
    border-radius: 20px !important;
    padding: 20px !important;
}

.swal-title {
    font-family: 'Poppins', sans-serif !important;
    font-size: 22px !important;
    font-weight: 900 !important;
}

.swal-text {
    font-family: 'Poppins', sans-serif !important;
    font-size: 15px !important;
    font-weight: 600 !important;
    line-height: 1.6 !important;
}

.swal-button {
    font-family: 'Poppins', sans-serif !important;
    font-size: 16px !important;
    font-weight: 700 !important;
    padding: 12px 30px !important;
    border-radius: 10px !important;
    text-transform: uppercase !important;
}
@media (max-width: 1024px) {
    .stats-grid, .actions-grid, .chart-grid, .status-strip { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .section-grid { grid-template-columns: 1fr; }
    .profile-summary-grid { grid-template-columns: 1fr; }
    .profile-identity { grid-template-columns: auto minmax(0, 1fr); }
    .profile-side-actions { grid-column: 1 / -1; justify-content: flex-start; }
    .feed-layout, .gallery-grid { grid-template-columns: 1fr 1fr; }
    .facebook-profile-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 768px) {
    body { padding: 12px; }
    .header-shell, .card, .status-strip, .stat-card, .action-card { padding: 18px; }
    .stats-grid, .actions-grid, .chart-grid, .status-strip, .status-quick, .today-breakdown { grid-template-columns: 1fr; }
    .header-top, .header-user { align-items: flex-start; }
    .header-user { flex-wrap: nowrap; }
    .header-right { width: 100%; justify-content: space-between; }
    .welcome-line { font-size: 24px; }
    .time-display { font-size: 38px; }
    .stat-value { font-size: 26px; }
    .action-card { min-height: auto; }
    .history-row, .info-row, .ai-row { flex-direction: column; align-items: flex-start; }
    .history-side { text-align: left; width: 100%; }
    .pill { width: 100%; justify-content: flex-start; }
    .container { max-width: 100%; }
    .profile-hero {
        border-radius: 0;
        margin-left: -12px;
        margin-right: -12px;
        border-left: 0;
        border-right: 0;
    }
    .profile-cover {
        min-height: 240px;
        padding: 0;
        border-radius: 0;
    }
    .profile-cover::after,
    .profile-facebook-meta,
    .cover-chip-row,
    .profile-cover-overlay,
    .profile-cover-top,
    .cover-actions {
        display: none;
    }
    .profile-hero-body {
        margin-top: -76px;
        padding: 0 16px 18px;
    }
    .profile-identity {
        grid-template-columns: auto minmax(0, 1fr);
        gap: 12px;
        align-items: start;
    }
    .profile-avatar-xl {
        width: 144px;
        height: 144px;
        border-radius: 999px;
        font-size: 42px;
        border-width: 5px;
        box-shadow: 0 12px 30px rgba(15,23,42,.18);
    }
    .profile-main-copy {
        padding-top: 30px;
    }
    .profile-main-copy .welcome-line {
        display: none;
    }
    .profile-name {
        font-size: 28px;
        line-height: 1.05;
    }
    .profile-handle {
        font-size: 13px;
        margin-bottom: 10px;
    }
    .profile-badges {
        gap: 8px;
    }
    .profile-badge {
        padding: 8px 12px;
        font-size: 11px;
    }
    .profile-side-actions {
        justify-content: stretch;
        gap: 10px;
        grid-column: 1 / -1;
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .profile-side-actions .btn-secondary { width: 100%; min-height: 44px; }
    .profile-side-actions .btn-secondary:last-child { grid-column: 1 / -1; }
    .profile-highlight-grid { grid-template-columns: 1fr 1fr; }
    .feed-layout, .gallery-grid, .composer-inline { grid-template-columns: 1fr; }
    .facebook-tabbar { display: grid; }
    .facebook-tabbar-copy { display: none; }
    .facebook-tabs {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 0;
        overflow: hidden;
    }
    .facebook-tab {
        min-width: 0;
        justify-content: center;
        padding: 0 6px;
        font-size: 13px;
        border-bottom: 1px solid var(--border);
    }
    .facebook-tab i { display: none; }
    .live-progress-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .media-viewer { padding: 0; }
    .media-viewer-shell { width: 100%; height: 100%; }
    .media-viewer-stage {
        height: 100%;
        max-height: 100vh;
        border-radius: 0;
    }
    .media-viewer-stage img,
    .media-viewer-stage video {
        max-height: 100vh;
    }
    .media-viewer-close {
        top: 14px;
        right: 14px;
        z-index: 2;
        background: rgba(15,23,42,.62);
    }
    .media-viewer-caption {
        position: absolute;
        left: 14px;
        right: 14px;
        bottom: 14px;
        margin-top: 0;
        padding: 10px 14px;
        border-radius: 14px;
        background: rgba(15,23,42,.62);
    }
}
@media (max-width: 480px) {
    body { padding: 10px; }
    .header-shell, .card, .status-strip, .stat-card, .action-card, .mini-card, .kv { padding: 16px; }
    .user-avatar { width: 56px; height: 56px; font-size: 20px; border-radius: 16px; }
    .welcome-line { font-size: 20px; }
    .subline, .date-display, .action-desc, .stat-subtext, .muted { font-size: 12px; }
    .time-display { font-size: 32px; }
    .btn, .btn-logout, .btn-secondary { min-height: 50px; font-size: 13px; padding-left: 14px; padding-right: 14px; }
    .notif-bell, .icon-btn { width: 44px; height: 44px; border-radius: 12px; }
    .modal { padding: 8px; }
    .modal-content { padding: 16px; margin: 10px auto 24px; }
    .chart-wrap { height: 220px; }
    .profile-hero {
        margin-left: -10px;
        margin-right: -10px;
    }
    .profile-cover {
        min-height: 210px;
    }
    .profile-hero-body {
        margin-top: -62px;
        padding: 0 14px 16px;
    }
    .profile-avatar-xl {
        width: 124px;
        height: 124px;
    }
    .avatar-edit-wrap {
        right: 4px;
        bottom: 4px;
    }
    .profile-name {
        font-size: 26px;
    }
    .profile-identity {
        grid-template-columns: auto minmax(0, 1fr);
        column-gap: 10px;
    }
    .profile-main-copy { padding-top: 22px; }
    .profile-side-actions { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .live-progress-grid { grid-template-columns: 1fr 1fr; }
    .profile-highlight-grid { grid-template-columns: 1fr; }
    .facebook-profile-stats { grid-template-columns: 1fr; }
}
</style>
</head>
<body>
<div class="splash-screen" id="appSplash">
    <div class="splash-box">
        <div class="splash-logo">
            <img src="/hr/logo.png" alt="ESPERANCE H2O">
        </div>
        <div class="splash-title">ESPERANCE H2O</div>
        <div class="splash-subtitle">Chargement sécurisé du portail employé. En mode application installée, la barre navigateur Chrome disparaît automatiquement.</div>
        <div class="splash-loader"></div>
    </div>
</div>
<div class="network-badge" id="networkBadge"><i class="fas fa-wifi"></i> Mode hors ligne</div>
<div class="sync-badge" id="syncBadge"><i class="fas fa-rotate"></i> 0 pointage en attente</div>
<div class="lock-overlay" id="pinLockOverlay">
    <div class="lock-box">
        <div class="splash-logo" style="width:72px;height:72px;margin:0 auto 14px;"><img src="/hr/employee-app-icon.svg" alt="ESPERANCEH²O"></div>
        <div class="lock-title">Verrouillage PIN</div>
        <div class="lock-text">Entrez votre code PIN local pour déverrouiller l'application installée.</div>
        <input type="password" id="pinUnlockInput" inputmode="numeric" maxlength="6" placeholder="Code PIN" autocomplete="off">
        <div class="lock-actions">
            <button type="button" class="btn btn-primary" onclick="unlockWithPin()"><i class="fas fa-lock-open"></i> Déverrouiller</button>
            <button type="button" class="btn btn-muted" onclick="logoutFromLock()"><i class="fas fa-sign-out-alt"></i> Se déconnecter</button>
        </div>
    </div>
</div>

<div class="container">

    <!-- ═══ TOPBAR STICKY ═══ -->
    <div class="sticky-topbar">
        <a class="topbar-brand" href="#">
            <img src="/hr/logo.png" alt="H2O">
            <span>ESPERANCE H2O</span>
        </a>
        <div class="topbar-right">
            <span class="topbar-clock" id="atbClock">--:--</span>
            <button type="button" class="topbar-install" id="installAppBtn" style="display:none;"><i class="fas fa-download"></i> Installer</button>
            <div class="topbar-bell" onclick="toggleNotifications()" title="Notifications">
                <i class="fas fa-bell"></i>
                <?php if($unread_notifs > 0): ?><span class="notif-badge"><?= $unread_notifs ?></span><?php endif; ?>
            </div>
            <a href="/../auth/logout.php" class="topbar-logout"><i class="fas fa-sign-out-alt"></i> Déc</a>
        </div>
    </div>
    <!-- ═══ FIN TOPBAR ═══ -->

    <?php if (!empty($profileUploadError)): ?>
        <div class="late-warning-big" style="background:linear-gradient(135deg, rgba(239,68,68,.16), rgba(127,29,29,.14));border-color:rgba(239,68,68,.32);text-align:left;">
            <div class="message"><strong>Upload profil impossible:</strong> <?= htmlspecialchars($profileUploadError) ?></div>
        </div>
    <?php elseif (isset($_GET['profile_updated'])): ?>
        <div class="late-warning-big" style="background:linear-gradient(135deg, rgba(16,185,129,.15), rgba(14,165,233,.12));border-color:rgba(16,185,129,.28);text-align:left;">
            <div class="message"><strong>Profil mis à jour.</strong> La photo, la cover ou la bio ont été enregistrées.</div>
        </div>
    <?php endif; ?>

    <div class="header-shell profile-hero">
        <div class="profile-cover">
            <?php if ($employeeCoverPath): ?>
                <div class="profile-cover-media">
                    <img src="<?= htmlspecialchars($employeeCoverPath) ?>" alt="Cover de profil">
                </div>
                <div class="profile-cover-overlay"></div>
            <?php endif; ?>
            <div class="profile-cover-top">
                <div>
                    <div class="cover-badge"><i class="fas fa-id-badge"></i> Profil Employé</div>
                    <div class="welcome-line" style="margin-top:14px; color:#fff; font-size:clamp(28px,4vw,42px);"><?= htmlspecialchars($employeeName) ?></div>
                    <div class="subline" style="color:rgba(255,255,255,.82);"><?= htmlspecialchars($employee['position_title']) ?> | <?= htmlspecialchars($employee['category_name']) ?> | Code <?= htmlspecialchars($employee['employee_code']) ?></div>
                    <div class="profile-facebook-meta">
                        <?php foreach ($employeeProfileStats as $profileStat): ?>
                            <span class="facebook-count"><i class="fas <?= htmlspecialchars($profileStat['icon']) ?>"></i> <strong><?= (int)$profileStat['value'] ?></strong> <?= htmlspecialchars($profileStat['label']) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="header-right">
                    <button type="button" class="icon-btn" onclick="toggleThemeHint()" title="Mode auto"><i class="fas fa-moon"></i></button>
                </div>
            </div>
            <div class="cover-actions">
                <form method="POST" enctype="multipart/form-data" id="coverUploadForm">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="update_profile_media" value="1">
                    <input type="hidden" name="profile_bio" value="<?= htmlspecialchars($employeeBio) ?>">
                    <input class="hidden-upload" type="file" name="cover_photo" id="coverInput" accept="image/jpeg,image/png,image/webp" onchange="submitProfileMediaForm('coverUploadForm')">
                    <button type="button" class="media-edit-btn" onclick="triggerProfileUpload('coverInput')"><i class="fas fa-camera"></i> <?= $employeeCoverPath ? 'Changer la cover' : 'Ajouter une cover' ?></button>
                </form>
            </div>
            <div class="cover-chip-row">
                <span class="cover-chip"><i class="fas fa-signal"></i> Statut: <?= htmlspecialchars($todayStatusLabel) ?> <?= $todayStatusIcon ?></span>
                <span class="cover-chip"><i class="fas fa-briefcase"></i> <?= htmlspecialchars($employee['category_name']) ?></span>
                <span class="cover-chip"><i class="fas fa-business-time"></i> <?= htmlspecialchars($workStartLabel) ?> - <?= htmlspecialchars($workEndLabel) ?></span>
                <span class="cover-chip"><i class="fas fa-user-check"></i> <?= htmlspecialchars(ucfirst($employeeStatus)) ?></span>
            </div>
        </div>
        <div class="profile-hero-body">
            <div class="profile-identity">
                <div class="profile-avatar-xl">
                    <?php if ($employeeAvatarPath): ?>
                        <img src="<?= htmlspecialchars($employeeAvatarPath) ?>" alt="Avatar de profil">
                    <?php else: ?>
                        <?= htmlspecialchars($employeeInitials) ?>
                    <?php endif; ?>
                    <div class="avatar-edit-wrap">
                        <form method="POST" enctype="multipart/form-data" id="avatarUploadForm">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="update_profile_media" value="1">
                            <input type="hidden" name="profile_bio" value="<?= htmlspecialchars($employeeBio) ?>">
                            <input class="hidden-upload" type="file" name="avatar" id="avatarInput" accept="image/jpeg,image/png,image/webp" onchange="submitProfileMediaForm('avatarUploadForm')">
                            <button type="button" class="avatar-edit-btn" onclick="triggerProfileUpload('avatarInput')" title="Changer l'avatar"><i class="fas fa-camera"></i></button>
                        </form>
                    </div>
                </div>
                <div class="profile-main-copy">
                    <div class="welcome-line">Profil</div>
                    <div class="profile-name"><?= htmlspecialchars($employeeName) ?></div>
                    <div class="profile-handle"><?= htmlspecialchars($employee['position_title']) ?> • <?= htmlspecialchars($employee['category_name']) ?> • <?= $socialPostCount ?> publication<?= $socialPostCount > 1 ? 's' : '' ?></div>
                    <div class="profile-badges">
                        <span class="profile-badge"><i class="fas fa-fingerprint"></i> <?= htmlspecialchars($employee['employee_code']) ?></span>
                        <span class="profile-badge"><i class="fas fa-briefcase"></i> <?= htmlspecialchars(ucfirst($employeeStatus)) ?></span>
                        <span class="profile-badge"><i class="fas fa-award"></i> Présence <?= $presenceBadge ?></span>
                    </div>
                </div>
                <div class="profile-side-actions">
                    <button type="button" class="btn-secondary" onclick="openModal('profileEditModal')"><i class="fas fa-pen"></i> Modifier le profil</button>
                    <button type="button" class="btn-secondary" onclick="openModal('permissionModal')"><i class="fas fa-paper-plane"></i> Action principale</button>
                    <button type="button" class="btn-secondary" onclick="openModal('advanceModal')"><i class="fas fa-hand-holding-dollar"></i> Demander une avance</button>
                </div>
            </div>

            <div class="profile-summary-grid">
                <div class="profile-summary-card facebook-intro">
                    <div>
                        <div class="profile-summary-title">Intro</div>
                        <div class="profile-summary-text"><?= nl2br(htmlspecialchars($employeeBio)) ?></div>
                    </div>
                    <div class="facebook-intro-list">
                        <?php foreach ($employeeIntroFacts as $introFact): ?>
                            <div class="facebook-intro-item">
                                <i class="fas fa-circle-info"></i>
                                <span><?= htmlspecialchars($introFact) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="header-meta" style="margin-top:0;">
                        <span class="pill status-pill <?= $todayStatusClass ?>">Aujourd'hui: <?= $todayStatusLabel ?> <?= $todayStatusIcon ?></span>
                        <span class="pill"><i class="fas fa-money-bill-wave"></i> Net estimé: <?= number_format($net_salary, 0, ',', ' ') ?> FCFA</span>
                    </div>
                </div>
                <div class="profile-highlight-card">
                    <div class="profile-summary-title">Vue d'ensemble</div>
                    <div class="facebook-profile-stats">
                        <?php foreach ($employeeProfileStats as $profileStat): ?>
                            <div class="facebook-stat-card">
                                <i class="fas <?= htmlspecialchars($profileStat['icon']) ?>" style="color:#1877f2;"></i>
                                <div class="value mono"><?= (int)$profileStat['value'] ?></div>
                                <div class="label"><?= htmlspecialchars($profileStat['label']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="profile-highlight-card">
                    <div class="profile-summary-title">Performance du mois</div>
                    <div class="profile-highlight-grid">
                        <div class="profile-highlight">
                            <div class="profile-highlight-label">Score</div>
                            <div class="profile-highlight-value mono"><?= $performanceScore ?>%</div>
                        </div>
                        <div class="profile-highlight">
                            <div class="profile-highlight-label">Retards</div>
                            <div class="profile-highlight-value mono"><?= $late_count ?></div>
                        </div>
                        <div class="profile-highlight">
                            <div class="profile-highlight-label">Heures du jour</div>
                            <div class="profile-highlight-value mono"><?= htmlspecialchars($hoursTodayFormatted) ?></div>
                        </div>
                        <div class="profile-highlight">
                            <div class="profile-highlight-label">Bonus sup</div>
                            <div class="profile-highlight-value mono"><?= number_format($total_overtime, 0, ',', ' ') ?></div>
                        </div>
                    </div>
                </div>
                <div class="profile-highlight-card live-progress-card">
                    <div class="profile-summary-title">Temps En Direct</div>
                    <div class="live-progress-grid">
                        <div class="live-progress-item">
                            <div class="k">Fin du mois</div>
                            <div class="v mono" id="monthRemainingLive">--</div>
                        </div>
                        <div class="live-progress-item">
                            <div class="k">% du mois</div>
                            <div class="v mono" id="monthPercentLive">--</div>
                        </div>
                        <div class="live-progress-item">
                            <div class="k">% de l'année</div>
                            <div class="v mono" id="yearPercentLive">--</div>
                        </div>
                        <div class="live-progress-item">
                            <div class="k">Jours restants</div>
                            <div class="v mono" id="daysRemainingMonth">--</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ POINTAGE RAPIDE SOUS LE PROFIL ═══ -->
    <div class="attendance-top-bar" style="margin-top:0;">
        <div class="atb-status">
            <div class="atb-dot <?php
                if ($todayStatusKey === 'present') echo 'green';
                elseif ($todayStatusKey === 'finished') echo 'blue';
                elseif ($todayStatusKey === 'absence') echo 'red';
                else echo 'orange';
            ?>"></div>
            <div class="atb-info">
                <span class="atb-label">Statut du jour</span>
                <span class="atb-value"><?= $todayStatusIcon ?> <?= htmlspecialchars($todayStatusLabel) ?></span>
            </div>
        </div>
        <div class="atb-sep"></div>
        <?php if ($today_attendance): ?>
        <div class="atb-info">
            <span class="atb-label">Arrivée</span>
            <span class="atb-value"><?= substr($today_attendance['check_in'], 0, 5) ?></span>
        </div>
        <div class="atb-sep"></div>
        <div class="atb-info">
            <span class="atb-label">Départ</span>
            <span class="atb-value"><?= $today_attendance['check_out'] ? substr($today_attendance['check_out'], 0, 5) : '--:--' ?></span>
        </div>
        <div class="atb-sep"></div>
        <?php endif; ?>
        <div class="atb-info">
            <span class="atb-label">Heure actuelle</span>
            <span class="atb-value atb-time" id="atbClockWidget">--:--</span>
        </div>
        <div class="atb-actions">
            <?php if (!$today_attendance): ?>
                <button class="atb-btn atb-btn-in" onclick="checkIn()">
                    <i class="fas fa-fingerprint"></i> Pointer Arrivée
                </button>
            <?php elseif (!$today_attendance['check_out']): ?>
                <button class="atb-btn atb-btn-out" onclick="checkOut()">
                    <i class="fas fa-sign-out-alt"></i> Pointer Départ
                </button>
            <?php else: ?>
                <span class="atb-btn atb-btn-disabled"><i class="fas fa-check-circle"></i> Journée terminée</span>
            <?php endif; ?>
        </div>
    </div>
    <!-- ═══ FIN POINTAGE ═══ -->

    <div class="status-strip">
        <div class="status-main">
            <h3><i class="fas fa-wave-square"></i> Statut Aujourd'hui</h3>
            <p>Vue production-ready de votre journée de travail, de vos notifications RH et de vos indicateurs AI locaux.</p>
        </div>
        <div class="status-quick">
            <div class="mini-card">
                <div class="mini-card-label">Performance</div>
                <div class="mini-card-value"><?= $performanceScore ?>%</div>
            </div>
            <div class="mini-card">
                <div class="mini-card-label">Présence</div>
                <div class="mini-card-value"><?= $presenceBadge ?></div>
            </div>
        </div>
    </div>

    <?php if($late_count >= 5): ?>
    <div class="late-warning-big">
        <div class="icon">🚨</div>
        <div class="count"><?= $late_count ?> RETARDS CE MOIS</div>
        <div class="message"><?= $advice ?></div>
    </div>
    <?php endif; ?>

    <div class="facebook-tabbar">
        <div class="facebook-tabbar-copy">
            <strong><?= htmlspecialchars($employeeName) ?></strong>
            <span>Fil interne, informations RH et galerie du profil</span>
        </div>
        <div class="facebook-tabs">
            <button type="button" class="facebook-tab active" data-tab-target="publications"><i class="fas fa-newspaper"></i> Publications</button>
            <button type="button" class="facebook-tab" data-tab-target="infos"><i class="fas fa-circle-info"></i> Infos</button>
            <button type="button" class="facebook-tab" data-tab-target="photos"><i class="fas fa-photo-film"></i> Photos</button>
        </div>
    </div>

    <section class="tab-panel active" data-tab-panel="publications" id="publications">
        <?php if (!empty($socialFeedError)): ?>
            <div class="late-warning-big" style="background:linear-gradient(135deg, rgba(239,68,68,.16), rgba(127,29,29,.14));border-color:rgba(239,68,68,.32);text-align:left;">
                <div class="message"><strong>Publication refusée:</strong> <?= htmlspecialchars($socialFeedError) ?></div>
            </div>
        <?php elseif (isset($_GET['social_posted'])): ?>
            <div class="late-warning-big" style="background:linear-gradient(135deg, rgba(16,185,129,.15), rgba(14,165,233,.12));border-color:rgba(16,185,129,.28);text-align:left;">
                <div class="message"><strong>Publication envoyée.</strong> Elle apparaît maintenant dans le fil interne.</div>
            </div>
        <?php elseif (isset($_GET['social_commented'])): ?>
            <div class="late-warning-big" style="background:linear-gradient(135deg, rgba(16,185,129,.15), rgba(14,165,233,.12));border-color:rgba(16,185,129,.28);text-align:left;">
                <div class="message"><strong>Réponse envoyée.</strong> Votre commentaire a été ajouté au fil.</div>
            </div>
        <?php endif; ?>

        <div class="feed-layout">
            <div>
                <div class="composer-card">
                    <div class="facebook-panel-title">
                        <div class="card-title" style="margin-bottom:0;"><i class="fas fa-pen-to-square"></i> Créer une publication</div>
                        <span>Comme sur un mur Facebook, mais réservé au travail</span>
                    </div>
                    <form method="POST" enctype="multipart/form-data" class="composer-grid">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="create_social_post" value="1">
                        <div class="composer-top">
                            <div class="mini-avatar">
                                <?php if ($employeeAvatarPath): ?>
                                    <img src="<?= htmlspecialchars($employeeAvatarPath) ?>" alt="Avatar">
                                <?php else: ?>
                                    <?= htmlspecialchars($employeeInitials) ?>
                                <?php endif; ?>
                            </div>
                            <div>
                                <strong><?= htmlspecialchars($employeeName) ?></strong>
                                <div class="muted" style="margin-top:4px;">Canal interne réservé aux sujets pro: missions, annonces, rapports, sécurité, coordination.</div>
                            </div>
                        </div>
                        <div class="composer-inline">
                            <div class="form-group">
                                <label>Catégorie</label>
                                <select name="post_category" required>
                                    <option value="annonce">Annonce</option>
                                    <option value="mission">Mission</option>
                                    <option value="rapport">Rapport</option>
                                    <option value="securite">Sécurité</option>
                                    <option value="equipe">Équipe</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Titre</label>
                                <input type="text" name="post_title" maxlength="160" placeholder="Ex: Point terrain du matin">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Message</label>
                            <textarea name="post_body" required placeholder="Écrivez uniquement une information utile au travail: consigne, état d'avancement, incident, rapport, coordination d'équipe..."></textarea>
                        </div>
                        <div class="form-group">
                            <label>Média joint</label>
                            <input type="file" name="post_media" accept="image/jpeg,image/png,image/webp,video/mp4,video/webm,video/quicktime">
                            <div class="media-note">Images et vidéos autorisées. Pas de contenu personnel. Maximum 30 MB.</div>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Publier dans l'espace interne</button>
                    </form>
                </div>

                <div class="feed-card facebook-feed-shell">
                    <div class="facebook-panel-title">
                        <div class="card-title" style="margin-bottom:0;"><i class="fas fa-rss"></i> Fil d'actualité</div>
                        <span><?= $socialPostCount ?> publication<?= $socialPostCount > 1 ? 's' : '' ?> dans votre espace</span>
                    </div>
                    <?php if ($socialPostCount > 0): ?>
                        <?php foreach ($socialPosts as $socialPost): ?>
                            <?php
                            $postAuthorAvatar = (!empty($socialPost['author_avatar']) && file_exists($socialPost['author_avatar'])) ? $socialPost['author_avatar'] : null;
                            $postComments = $socialCommentsByPost[(int)$socialPost['id']] ?? [];
                            ?>
                            <article class="feed-post" id="post-<?= (int)$socialPost['id'] ?>">
                                <div class="feed-post-head">
                                    <div class="mini-avatar">
                                        <?php if ($postAuthorAvatar): ?>
                                            <img src="<?= htmlspecialchars($postAuthorAvatar) ?>" alt="Avatar auteur">
                                        <?php else: ?>
                                            <?= htmlspecialchars(strtoupper(substr((string)$socialPost['author_name'], 0, 2))) ?>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <div class="feed-author-line">
                                            <strong><?= htmlspecialchars($socialPost['author_name']) ?></strong>
                                            <span class="actor-badge <?= $socialPost['author_type'] === 'admin' ? 'admin' : '' ?>"><?= $socialPost['author_type'] === 'admin' ? 'Administration' : 'Employé' ?></span>
                                            <span class="category-badge"><?= htmlspecialchars($socialCategoryLabels[$socialPost['category']] ?? ucfirst((string)$socialPost['category'])) ?></span>
                                        </div>
                                        <div class="muted" style="margin-top:6px;"><?= date('d/m/Y H:i', strtotime($socialPost['created_at'])) ?></div>
                                    </div>
                                    <?php if ($socialPost['author_type'] === 'employee' && (int)$socialPost['employee_id'] === (int)$employee_id): ?>
                                    <button class="btn-delete-post" onclick="deletePost(<?= (int)$socialPost['id'] ?>)" title="Supprimer cette publication">
                                        <i class="fas fa-trash-alt"></i> Supprimer
                                    </button>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($socialPost['title'])): ?>
                                    <div class="feed-post-title"><?= htmlspecialchars($socialPost['title']) ?></div>
                                <?php endif; ?>
                                <div class="feed-post-body"><?= nl2br(htmlspecialchars($socialPost['body'])) ?></div>
                                <?php if (!empty($socialPost['media_path']) && file_exists($socialPost['media_path'])): ?>
                                    <div class="feed-media" data-media-preview="<?= htmlspecialchars($socialPost['media_path']) ?>" data-media-type="<?= htmlspecialchars(($socialPost['media_type'] ?? '') === 'video' ? 'video' : 'image') ?>" data-media-caption="<?= htmlspecialchars($socialPost['title'] ?: $socialPost['author_name']) ?>">
                                        <?php if (($socialPost['media_type'] ?? '') === 'video'): ?>
                                            <video controls preload="metadata">
                                                <source src="<?= htmlspecialchars($socialPost['media_path']) ?>">
                                            </video>
                                        <?php else: ?>
                                            <img src="<?= htmlspecialchars($socialPost['media_path']) ?>" alt="Media publication">
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <div class="feed-meta">
                                    <span><?= count($postComments) ?> réponse(s)</span>
                                    <span>Diffusion interne travail uniquement</span>
                                </div>
                                <div class="comment-stack">
                                    <?php foreach ($postComments as $postComment): ?>
                                        <?php $commentAvatar = (!empty($postComment['author_avatar']) && file_exists($postComment['author_avatar'])) ? $postComment['author_avatar'] : null; ?>
                                        <div class="comment-item">
                                            <div class="mini-avatar">
                                                <?php if ($commentAvatar): ?>
                                                    <img src="<?= htmlspecialchars($commentAvatar) ?>" alt="Avatar commentaire">
                                                <?php else: ?>
                                                    <?= htmlspecialchars(strtoupper(substr((string)$postComment['author_name'], 0, 2))) ?>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <div class="comment-author"><?= htmlspecialchars($postComment['author_name']) ?> · <?= $postComment['author_type'] === 'admin' ? 'Admin' : 'Employé' ?></div>
                                                <div class="comment-text"><?= nl2br(htmlspecialchars($postComment['body'])) ?></div>
                                                <div class="muted" style="margin-top:6px;"><?= date('d/m/Y H:i', strtotime($postComment['created_at'])) ?></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <form method="POST" class="comment-form">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                    <input type="hidden" name="create_social_comment" value="1">
                                    <input type="hidden" name="post_id" value="<?= (int)$socialPost['id'] ?>">
                                    <textarea name="comment_body" placeholder="Répondez de façon professionnelle: précision, confirmation, suivi d'action..." required></textarea>
                                    <button type="submit" class="btn btn-muted"><i class="fas fa-reply"></i> Répondre</button>
                                </form>
                            </article>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">Aucune publication interne pour le moment. Lancez la première annonce de travail.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div>
                <div class="composer-card rule-card">
                    <div class="card-title"><i class="fas fa-shield"></i> Règles du mur interne</div>
                    <div class="rule-list">
                        <div class="rule-item">On publie uniquement ce qui concerne le travail: consignes, incidents, missions, rapports, planning, sécurité.</div>
                        <div class="rule-item">Photos et vidéos autorisées seulement si elles servent l'activité: terrain, stock, chantier, intervention, preuve, démonstration.</div>
                        <div class="rule-item">Les réponses servent à coordonner, confirmer, corriger ou faire remonter une information utile. Pas de contenu personnel.</div>
                    </div>
                </div>

                <div class="composer-card">
                    <div class="card-title"><i class="fas fa-chart-simple"></i> Activité du réseau</div>
                    <div class="today-breakdown">
                        <div class="kv"><div class="kv-label">Publications</div><div class="kv-value mono"><?= $socialPostCount ?></div></div>
                        <div class="kv"><div class="kv-label">Réponses</div><div class="kv-value mono"><?= $socialCommentCount ?></div></div>
                        <div class="kv"><div class="kv-label">Médias</div><div class="kv-value mono"><?= $socialMediaCount ?></div></div>
                        <div class="kv"><div class="kv-label">Canal</div><div class="kv-value">Interne</div></div>
                    </div>
                </div>

                <div class="composer-card">
                    <div class="card-title"><i class="fas fa-user-shield"></i> Infos strictes partagées</div>
                    <div class="list-stack">
                        <div class="info-row"><strong>Équipe</strong><span class="muted"><?= htmlspecialchars($employee['category_name']) ?></span></div>
                        <div class="info-row"><strong>Poste</strong><span class="muted"><?= htmlspecialchars($employee['position_title']) ?></span></div>
                        <div class="info-row"><strong>Horaires</strong><span class="muted mono"><?= htmlspecialchars($workStartLabel) ?> - <?= htmlspecialchars($workEndLabel) ?></span></div>
                        <div class="info-row"><strong>Rappel</strong><span class="muted">Aucune publication hors sujet</span></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="tab-panel" data-tab-panel="infos" id="infos">
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-head">
                <div>
                    <div class="stat-label">Présences ce mois</div>
                    <div class="stat-value mono"><?= $days_worked ?></div>
                    <div class="stat-subtext"><?= $days_worked ?> jours validés</div>
                </div>
                <div class="stat-icon stat-primary"><i class="fas fa-calendar-check"></i></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-head">
                <div>
                    <div class="stat-label">Salaire de base</div>
                    <div class="stat-value mono"><?= number_format($salary_amount, 0, ',', ' ') ?></div>
                    <div class="stat-subtext">FCFA</div>
                </div>
                <div class="stat-icon stat-success"><i class="fas fa-wallet"></i></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-head">
                <div>
                    <div class="stat-label">Retenues</div>
                    <div class="stat-value mono"><?= number_format($total_penalties, 0, ',', ' ') ?></div>
                    <div class="stat-subtext">FCFA</div>
                </div>
                <div class="stat-icon stat-danger"><i class="fas fa-minus-circle"></i></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-head">
                <div>
                    <div class="stat-label">Salaire net</div>
                    <div class="stat-value mono"><?= number_format($net_salary, 0, ',', ' ') ?></div>
                    <div class="stat-subtext">FCFA, bonus inclus</div>
                </div>
                <div class="stat-icon stat-warning"><i class="fas fa-money-bill-wave"></i></div>
            </div>
        </div>
    </div>

    <div class="section-grid">
        <div>
            <div class="card">
                <h3 class="card-title"><i class="fas fa-clock"></i> Pointage Aujourd'hui</h3>
                <div class="clock-panel">
                    <div class="time-display mono" id="currentTime"></div>
                    <div class="date-display" id="currentDate"></div>
                    <div class="status-line <?= $todayStatusClass ?>">Statut: <?= $todayStatusLabel ?> <?= $todayStatusIcon ?></div>
                </div>

                <?php if($today_attendance): ?>
                    <div class="today-breakdown">
                        <div class="kv"><div class="kv-label">Arrivée</div><div class="kv-value mono"><?= substr($today_attendance['check_in'], 0, 5) ?></div></div>
                        <div class="kv"><div class="kv-label">Départ</div><div class="kv-value mono"><?= $today_attendance['check_out'] ? substr($today_attendance['check_out'], 0, 5) : '--' ?></div></div>
                        <div class="kv"><div class="kv-label">Retard</div><div class="kv-value mono"><?= (int)$today_attendance['minutes_late'] ?> min</div></div>
                        <div class="kv"><div class="kv-label">Retenue</div><div class="kv-value mono"><?= number_format((float)$today_attendance['penalty_amount'], 0, ',', ' ') ?> FCFA</div></div>
                    </div>
                    <?php if(!$today_attendance['check_out']): ?>
                        <div style="margin-top:18px;">
                            <button class="btn btn-danger" onclick="checkOut()"><i class="fas fa-sign-out-alt"></i> Pointer Départ<?= (int)$attendanceSettings['require_gps_check_out'] === 1 ? ' (GPS requis)' : '' ?></button>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <button class="btn btn-primary" onclick="checkIn()"><i class="fas fa-fingerprint"></i> Pointer Arrivée (GPS + Selfie)</button>
                <?php endif; ?>
            </div>

            <div class="card">
                <h3 class="card-title"><i class="fas fa-stopwatch"></i> Heures Aujourd'hui</h3>
                <div class="stat-value mono"><?= $hoursTodayFormatted ?></div>
                <div class="today-breakdown">
                    <div class="kv"><div class="kv-label">Arrivée</div><div class="kv-value mono"><?= $today_attendance ? substr($today_attendance['check_in'], 0, 5) : '--' ?></div></div>
                    <div class="kv"><div class="kv-label">Pause</div><div class="kv-value mono">--</div></div>
                    <div class="kv"><div class="kv-label">Reprise</div><div class="kv-value mono">--</div></div>
                    <div class="kv"><div class="kv-label">Départ</div><div class="kv-value mono"><?= ($today_attendance && $today_attendance['check_out']) ? substr($today_attendance['check_out'], 0, 5) : '--' ?></div></div>
                </div>
            </div>

            <div class="card">
                <h3 class="card-title"><i class="fas fa-bolt"></i> Actions Rapides</h3>
                <div class="actions-grid">
                    <div class="action-card" onclick="openModal('permissionModal')">
                        <div class="action-icon stat-primary"><i class="fas fa-file-signature"></i></div>
                        <div class="action-title">Permission</div>
                        <div class="action-desc">Demander une permission RH rapidement.</div>
                    </div>
                    <div class="action-card" onclick="openEmployeeRequest('conge')">
                        <div class="action-icon stat-success"><i class="fas fa-umbrella-beach"></i></div>
                        <div class="action-title">Congé</div>
                        <div class="action-desc">Préparer une demande de congé avec motif.</div>
                    </div>
                    <div class="action-card" onclick="openEmployeeRequest('historique_pointage')">
                        <div class="action-icon stat-warning"><i class="fas fa-history"></i></div>
                        <div class="action-title">Mes pointages</div>
                        <div class="action-desc">Voir l'historique récent et les heures validées.</div>
                    </div>
                    <div class="action-card" onclick="openEmployeeRequest('historique_salaire')">
                        <div class="action-icon stat-danger"><i class="fas fa-wallet"></i></div>
                        <div class="action-title">Historique salaire</div>
                        <div class="action-desc">Consulter les montants estimés et retenues.</div>
                    </div>
                    <div class="action-card" onclick="openModal('advanceModal')">
                        <div class="action-icon stat-primary"><i class="fas fa-hand-holding-dollar"></i></div>
                        <div class="action-title">Avance</div>
                        <div class="action-desc">Demander une avance jusqu'à 50% du salaire.</div>
                    </div>
                    <div class="action-card" onclick="openEmployeeRequest('absence')">
                        <div class="action-icon stat-danger"><i class="fas fa-user-slash"></i></div>
                        <div class="action-title">Signaler absence</div>
                        <div class="action-desc">Créer un signalement d'absence et prévenir l'admin.</div>
                    </div>
                    <div class="action-card" onclick="window.location.href='/hr/install_app.php'">
                        <div class="action-icon stat-success"><i class="fas fa-mobile-screen-button"></i></div>
                        <div class="action-title">Installer l'app</div>
                        <div class="action-desc">Guide d'installation ESPERANCEH²O sur Android et iPhone.</div>
                    </div>
                    <div class="action-card" onclick="openModal('securityModal')">
                        <div class="action-icon stat-warning"><i class="fas fa-shield-halved"></i></div>
                        <div class="action-title">Sécurité PIN</div>
                        <div class="action-desc">Configurer un verrouillage local pour cette application.</div>
                    </div>
                </div>
            </div>

            <div class="card">
                <h3 class="card-title"><i class="fas fa-chart-pie"></i> Graphiques & Performance</h3>
                <div class="chart-grid">
                    <div>
                        <div class="chart-wrap"><canvas id="lateChart"></canvas></div>
                    </div>
                    <div>
                        <div class="chart-wrap"><canvas id="hoursChart"></canvas></div>
                    </div>
                </div>
                <div class="today-breakdown" style="margin-top:18px;">
                    <div class="kv"><div class="kv-label">Performance</div><div class="kv-value"><?= $performanceScore ?>%</div></div>
                    <div class="kv"><div class="kv-label">Présence</div><div class="kv-value"><?= $presenceBadge ?></div></div>
                    <div class="kv"><div class="kv-label">Retards</div><div class="kv-value"><?= $late_count ?></div></div>
                    <div class="kv"><div class="kv-label">Productivité</div><div class="kv-value"><?= $hours_today_decimal > 0 ? 'Active' : 'En attente' ?></div></div>
                </div>
            </div>
        </div>

        <div>
            <div class="card">
                <h3 class="card-title"><i class="fas fa-circle-info"></i> Informations Rapides</h3>
                <div class="list-stack">
                    <div class="info-row"><strong>Poste</strong><span class="muted"><?= htmlspecialchars($employee['position_title']) ?></span></div>
                    <div class="info-row"><strong>Département</strong><span class="muted"><?= htmlspecialchars($employee['employee_code']) ?></span></div>
                    <div class="info-row"><strong>Horaire</strong><span class="muted mono"><?= htmlspecialchars($workStartLabel) ?> - <?= htmlspecialchars($workEndLabel) ?></span></div>
                    <div class="info-row"><strong>Retard autorisé</strong><span class="muted">15 min</span></div>
                </div>
            </div>

            <div class="card">
                <h3 class="card-title"><i class="fas fa-address-card"></i> Coordonnées & Contrat</h3>
                <div class="social-panel">
                    <?php foreach ($employeeProfileHighlights as $profileItem): ?>
                        <div class="contact-row">
                            <div class="contact-icon"><i class="fas <?= htmlspecialchars($profileItem['icon']) ?>"></i></div>
                            <div>
                                <div class="contact-label"><?= htmlspecialchars($profileItem['label']) ?></div>
                                <div class="contact-value"><?= htmlspecialchars($profileItem['value']) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card">
                <h3 class="card-title"><i class="fas fa-stream"></i> Timeline Employé</h3>
                <div class="social-panel">
                    <?php foreach ($profileTimeline as $timelineItem): ?>
                        <div class="timeline-item">
                            <div class="timeline-dot <?= htmlspecialchars($timelineItem['accent']) ?>"></div>
                            <div>
                                <strong><?= htmlspecialchars($timelineItem['title']) ?></strong>
                                <div class="muted" style="margin-top:6px;"><?= htmlspecialchars($timelineItem['meta']) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card">
                <h3 class="card-title"><i class="fas fa-bell"></i> Notifications Pro</h3>
                <div class="list-stack">
                    <?php foreach ($quick_notifications as $quickNotification): ?>
                        <div class="info-row"><strong><?= htmlspecialchars($quickNotification) ?></strong><span class="muted">RH</span></div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card data-block" id="aiDataCard"
                data-ai='<?= htmlspecialchars(json_encode([
                    'heures_travaillees' => $hours_today_decimal,
                    'retards' => $late_count,
                    'absences' => $todayStatusLabel === 'Absence' ? 1 : 0,
                    'salaire' => $net_salary,
                    'performance' => $performanceScore,
                    'presence' => $presenceRate,
                    'productivite' => $hours_today_decimal > 0 ? 'active' : 'pending',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>'>
                <h3 class="card-title"><i class="fas fa-robot"></i> AI Local Ready</h3>
                <div class="list-stack">
                    <?php foreach ($aiInsights as $insight): ?>
                        <div class="ai-row"><strong><?= htmlspecialchars($insight) ?></strong></div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card">
                <h3 class="card-title"><i class="fas fa-user-check"></i> Historique Récent</h3>
                <div class="list-stack">
                    <?php if(count($recent_attendance) > 0): ?>
                        <?php foreach($recent_attendance as $attendanceItem): ?>
                            <div class="history-row">
                                <div class="history-meta">
                                    <strong><?= date('d/m/Y', strtotime($attendanceItem['work_date'])) ?></strong>
                                    <span class="muted">Arrivée: <?= $attendanceItem['check_in'] ? substr($attendanceItem['check_in'], 0, 5) : '--' ?> | Départ: <?= $attendanceItem['check_out'] ? substr($attendanceItem['check_out'], 0, 5) : '--' ?></span>
                                </div>
                                <div class="history-side">
                                    <div class="badge-score"><?= htmlspecialchars($attendanceItem['status'] ?: 'present') ?></div>
                                    <div class="muted mono" style="margin-top:6px;"><?= number_format((float)($attendanceItem['penalty_amount'] ?? 0), 0, ',', ' ') ?> FCFA</div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">Aucun pointage récent.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    </section>

    <section class="tab-panel" data-tab-panel="photos" id="photos">
        <div class="card">
            <h3 class="card-title"><i class="fas fa-photo-film"></i> Galerie média employé</h3>
            <?php if ($socialMediaGallery): ?>
                <div class="gallery-grid">
                    <?php foreach ($socialMediaGallery as $galleryItem): ?>
                        <div class="gallery-card">
                            <div class="gallery-media" data-media-preview="<?= htmlspecialchars($galleryItem['path']) ?>" data-media-type="<?= htmlspecialchars(($galleryItem['type'] ?? 'image') === 'video' ? 'video' : 'image') ?>" data-media-caption="<?= htmlspecialchars($galleryItem['label']) ?>">
                                <?php if (($galleryItem['type'] ?? 'image') === 'video'): ?>
                                    <video controls preload="metadata">
                                        <source src="<?= htmlspecialchars($galleryItem['path']) ?>">
                                    </video>
                                    <div class="gallery-play"><i class="fas fa-play"></i></div>
                                <?php else: ?>
                                    <img src="<?= htmlspecialchars($galleryItem['path']) ?>" alt="<?= htmlspecialchars($galleryItem['label']) ?>">
                                <?php endif; ?>
                            </div>
                            <div class="gallery-copy">
                                <strong><?= htmlspecialchars($galleryItem['label']) ?></strong>
                                <div class="muted" style="margin-top:6px;"><?= ($galleryItem['type'] ?? 'image') === 'video' ? 'Vidéo de travail' : 'Image de travail' ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">Aucun média de travail disponible pour le moment.</div>
            <?php endif; ?>
        </div>
    </section>
</div>

<div class="media-viewer" id="mediaViewer" onclick="closeMediaViewer(event)">
    <div class="media-viewer-shell">
        <button type="button" class="media-viewer-close" onclick="closeMediaViewer(event)"><i class="fas fa-times"></i></button>
        <div class="media-viewer-stage" id="mediaViewerStage"></div>
        <div class="media-viewer-caption" id="mediaViewerCaption"></div>
    </div>
</div>

<!-- Notification Overlay -->
<div class="notif-overlay" id="notifOverlay" onclick="toggleNotifications()"></div>

<!-- Popup détail notification -->
<div class="notif-popup-overlay" id="notifPopupOverlay" onclick="closeNotifPopup(event)">
    <div class="notif-popup" id="notifPopup">
        <div class="notif-popup-top">
            <div class="notif-popup-icon" id="npIcon"></div>
            <div class="notif-popup-meta">
                <div class="notif-popup-title" id="npTitle"></div>
                <div class="notif-popup-date" id="npDate"></div>
            </div>
            <button class="notif-popup-close" onclick="closeNotifPopup()"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="notif-popup-body" id="npBody"></div>
        <div class="notif-popup-footer">
            <button class="notif-popup-btn" onclick="closeNotifPopup()"><i class="fas fa-check"></i> Fermer</button>
        </div>
    </div>
</div>

<!-- Notification Panel -->
<div class="notif-panel" id="notifPanel">
    <div class="notif-header">
        <div class="notif-header-left">
            <h3 style="font-size:17px;font-weight:800;line-height:1.2;">Notifications</h3>
            <span style="font-size:11px;opacity:.7;"><?= $unread_notifs ?> non lue<?= $unread_notifs > 1 ? 's' : '' ?></span>
        </div>
        <div class="notif-header-actions">
            <?php if($unread_notifs > 0): ?>
            <button class="notif-mark-all" onclick="markAllNotificationsRead()"><i class="fas fa-check-double"></i> Tout lire</button>
            <?php endif; ?>
            <span class="notif-close" onclick="toggleNotifications()">&times;</span>
        </div>
    </div>
    <div id="notifList">
        <?php
        $notifTypeIcons = [
            'check_in'      => ['icon'=>'fa-fingerprint',   'cls'=>'type-check_in'],
            'check_out'     => ['icon'=>'fa-door-open',     'cls'=>'type-check_out'],
            'overtime_bonus'=> ['icon'=>'fa-coins',         'cls'=>'type-overtime_bonus'],
            'late_warning'  => ['icon'=>'fa-triangle-exclamation','cls'=>'type-late_warning'],
            'social_post'   => ['icon'=>'fa-newspaper',     'cls'=>'type-social_post'],
            'permission'    => ['icon'=>'fa-file-signature','cls'=>'type-permission'],
            'advance'       => ['icon'=>'fa-money-bill-wave','cls'=>'type-advance'],
        ];
        ?>
        <?php if(count($notifications) > 0): ?>
            <?php foreach($notifications as $notif):
                $nType = $notif['type'] ?? 'info';
                $iconInfo = $notifTypeIcons[$nType] ?? ['icon'=>'fa-bell','cls'=>'type-info'];
            ?>
            <div class="notif-item <?= $notif['is_read']==0?'unread':'' ?>"
                 id="notif-<?= $notif['id'] ?>"
                 onclick="openNotifPopup(<?= $notif['id'] ?>)"
                 data-notif-id="<?= $notif['id'] ?>"
                 data-notif-msg="<?= htmlspecialchars($notif['message'], ENT_QUOTES) ?>"
                 data-notif-date="<?= htmlspecialchars(date('d/m/Y à H:i', strtotime($notif['created_at'])), ENT_QUOTES) ?>"
                 data-notif-type="<?= htmlspecialchars($nType, ENT_QUOTES) ?>"
                 data-notif-icon="<?= htmlspecialchars($iconInfo['icon'], ENT_QUOTES) ?>"
                 data-notif-cls="<?= htmlspecialchars($iconInfo['cls'], ENT_QUOTES) ?>">
                <div class="notif-icon <?= $iconInfo['cls'] ?>">
                    <i class="fas <?= $iconInfo['icon'] ?>"></i>
                </div>
                <div class="notif-body">
                    <div class="notif-time"><i class="fas fa-clock"></i> <?= date('d/m/Y à H:i', strtotime($notif['created_at'])) ?></div>
                    <div class="notif-message"><?= nl2br(htmlspecialchars($notif['message'])) ?></div>
                </div>
                <?php if($notif['is_read']==0): ?><div class="notif-unread-dot"></div><?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="notif-empty"><i class="fas fa-bell-slash"></i>Aucune notification pour l'instant</div>
        <?php endif; ?>
    </div>
</div>

<!-- Modals-->
<!-- Permission Modal -->
<div class="modal" id="permissionModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Permission</h3>
            <span class="close-modal" onclick="closeModal('permissionModal')">&times;</span>
        </div>
        <form id="permissionForm">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <div class="form-group">
                <label>Date Début</label>
                <input type="date" name="start_date" required>
            </div>
            <div class="form-group">
                <label>Date Fin</label>
                <input type="date" name="end_date" required>
            </div>
            <div class="form-group">
                <label>Motif</label>
                <textarea name="reason" rows="3" required></textarea>
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-paper-plane"></i> Envoyer
            </button>
        </form>
    </div>
</div>

<!-- Advance Modal -->
<div class="modal" id="advanceModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Avance</h3>
            <span class="close-modal" onclick="closeModal('advanceModal')">&times;</span>
        </div>
        <form id="advanceForm">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <div class="form-group">
                <label>Montant (Max: <?= number_format($employee['salary_amount'] * 0.5, 0) ?> FCFA)</label>
                <input type="number" name="amount" step="1000" required>
            </div>
            <div class="form-group">
                <label>Date</label>
                <input type="date" name="advance_date" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="form-group">
                <label>Motif</label>
                <textarea name="reason" rows="3" required></textarea>
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-paper-plane"></i> Envoyer
            </button>
        </form>
    </div>
</div>

<!-- Camera Modal -->
<div class="modal" id="cameraModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">📸 Prenez un Selfie</h3>
            <span class="close-modal" onclick="closeCameraModal()">&times;</span>
        </div>
        <video id="video" autoplay></video>
        <canvas id="canvas" style="display:none;"></canvas>
        <button class="btn btn-primary" onclick="capturePhoto()">
            <i class="fas fa-camera"></i> Prendre Photo
        </button>
    </div>
</div>

<div class="modal" id="securityModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Sécurité PIN</h3>
            <span class="close-modal" onclick="closeModal('securityModal')">&times;</span>
        </div>
        <div class="form-group">
            <label>Nouveau code PIN</label>
            <input type="password" id="pinSetupInput" inputmode="numeric" maxlength="6" placeholder="4 à 6 chiffres">
        </div>
        <div class="form-group">
            <label>Confirmer le code PIN</label>
            <input type="password" id="pinConfirmInput" inputmode="numeric" maxlength="6" placeholder="Répétez le PIN">
        </div>
        <div class="form-group">
            <label>PIN actuel</label>
            <input type="password" id="pinCurrentInput" inputmode="numeric" maxlength="6" placeholder="Obligatoire pour modifier/supprimer">
        </div>
        <button type="button" class="btn btn-primary" onclick="savePinSettings()"><i class="fas fa-save"></i> Enregistrer le PIN</button>
        <button type="button" class="btn btn-danger" onclick="disablePinSettings()" style="margin-top:10px;"><i class="fas fa-trash"></i> Désactiver le PIN</button>
    </div>
</div>

<div class="modal" id="profileEditModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Modifier le profil</h3>
            <span class="close-modal" onclick="closeModal('profileEditModal')">&times;</span>
        </div>
        <form method="POST" enctype="multipart/form-data" id="profileEditForm">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="update_profile_media" value="1">
            <div class="form-group">
                <label>Bio</label>
                <textarea name="profile_bio" rows="4" placeholder="Présentez rapidement votre rôle, vos responsabilités ou votre humeur pro du moment."><?= htmlspecialchars($employeeBio) ?></textarea>
            </div>
            <div class="form-group">
                <label>Photo de profil</label>
                <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp">
            </div>
            <div class="form-group">
                <label>Photo de couverture</label>
                <input type="file" name="cover_photo" accept="image/jpeg,image/png,image/webp">
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Enregistrer le profil
            </button>
        </form>
    </div>
</div>

<script>
const csrf = "<?= $_SESSION['csrf_token'] ?>";
const employeeId = <?= (int)$employee_id ?>;
const attendanceConfig = <?= json_encode([
    'workStartTime' => $attendanceSettings['work_start_time'],
    'workEndTime' => $attendanceSettings['work_end_time'],
    'workStartLabel' => $workStartLabel,
    'workEndLabel' => $workEndLabel,
    'requireGpsCheckOut' => (int)$attendanceSettings['require_gps_check_out'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
let currentLocation = null;
let selfieData = null;
let cameraStream = null;
let deferredInstallPrompt = null;
let cameraAction = 'check_in';
const PIN_STORAGE_KEY = `employeePortalPin:${employeeId}`;
const PIN_UNLOCK_KEY = `employeePortalPinUnlocked:${employeeId}`;
const QUEUE_DB_NAME = 'employeePortalQueueDB';
const QUEUE_STORE = 'sync_queue';

// Son de notification
function playNotificationSound(type) {
    const audio = new Audio('data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVgodDbq2EcBj+a2/LDciUFLIHO8tiJNwgZaLvt559NEAxQp+PwtmMcBjiR1/LMeSwFJHfH8N2QQAoUXrTp66hVFApGn+DyvmwhBSuBzvLZiTYIHGS57OibUBELTKXh8bllHAdEmtny0H4qBSh+zPDajz4IHmS57OibTxELTKXh8bllHAdEmtny0H4qBSh+zPDajz4IHmS57OibTxELTKXh8bllHAdEmtny0H4qBSh+zPDajz4IHmS57OibTxELTKXh8bllHAdEmtny0H4qBSh+zPDajz4IHmS57OibTxELTKXh8bllHAdEmtny0H4qBSh+zPDajz4IHmS57OibTxELTKXh8bllHAdEmtny0H4qBSh+zPDajz4IHmS57OibTxELTKXh8bllHAdEmtny0H4qBSh+zPDajz4IHmS57OibTxELTKXh8bllHAdEmtny0H4qBSh+zPDajz4IHmS57OibTxELTKXh8bllHAdEmtny0H4qBSh+zPDajz4IHmS57OibTxELTKXh8bllHAdEmtny0H4qBSh+zPDajz4IHmS57OibTxELTKXh8bllHAdEmtny0H4qBSh+zPDajz4IHmS57OibTxELTKXh8bllHAdEmtny0H4qBSh+zPDajz4IHmS57OibTxELTKXh8bllHAdEmtny0H4qBSh+zPDajz4IHmS57OibTxELTKXh8bllHAdEmtny0H4qBSh+zPDajz4IHmS57OibT');
    if(type === 'success') {
        audio.playbackRate = 1.2;
    }
    audio.play().catch(() => {}); // Ignore si bloqué par navigateur
}

function showAlert(type, message) {
    playNotificationSound(type);
    
    if(type === 'error') {
        Swal.fire({
            icon: 'error',
            title: '❌ Erreur',
            html: message,
            confirmButtonText: 'OK',
            confirmButtonColor: '#ef4444',
            background: '#fff',
            backdrop: 'rgba(0,0,0,0.8)',
            customClass: {
                popup: 'swal-mobile',
                title: 'swal-title',
                htmlContainer: 'swal-text',
                confirmButton: 'swal-button'
            }
        });
    } else {
        Swal.fire({
            icon: 'success',
            title: '✅ Succès',
            html: message,
            confirmButtonText: 'OK',
            confirmButtonColor: '#10b981',
            background: '#fff',
            backdrop: 'rgba(0,0,0,0.8)',
            timer: 3000,
            timerProgressBar: true,
            customClass: {
                popup: 'swal-mobile',
                title: 'swal-title',
                htmlContainer: 'swal-text',
                confirmButton: 'swal-button'
            }
        });
    }
}
function updateClock() {
    const now = new Date();
    const timeEl = document.getElementById('currentTime');
    const dateEl = document.getElementById('currentDate');
    if(timeEl) timeEl.textContent = now.toLocaleTimeString('fr-FR', {hour: '2-digit', minute: '2-digit', second: '2-digit'});
    if(dateEl) dateEl.textContent = now.toLocaleDateString('fr-FR', {weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'});
}
function updateLiveProgress() {
    const now = new Date();
    const monthStart = new Date(now.getFullYear(), now.getMonth(), 1);
    const nextMonthStart = new Date(now.getFullYear(), now.getMonth() + 1, 1);
    const yearStart = new Date(now.getFullYear(), 0, 1);
    const nextYearStart = new Date(now.getFullYear() + 1, 0, 1);

    const monthPercent = ((now - monthStart) / (nextMonthStart - monthStart)) * 100;
    const yearPercent = ((now - yearStart) / (nextYearStart - yearStart)) * 100;
    const remainingMs = Math.max(0, nextMonthStart - now);
    const remainingDays = Math.ceil(remainingMs / 86400000);
    const remainingHours = Math.floor((remainingMs % 86400000) / 3600000);
    const remainingMinutes = Math.floor((remainingMs % 3600000) / 60000);

    const monthRemainingEl = document.getElementById('monthRemainingLive');
    const monthPercentEl = document.getElementById('monthPercentLive');
    const yearPercentEl = document.getElementById('yearPercentLive');
    const daysRemainingEl = document.getElementById('daysRemainingMonth');

    if (monthRemainingEl) monthRemainingEl.textContent = `${remainingDays}j ${remainingHours}h ${remainingMinutes}m`;
    if (monthPercentEl) monthPercentEl.textContent = `${Math.min(100, Math.max(0, monthPercent)).toFixed(1)}%`;
    if (yearPercentEl) yearPercentEl.textContent = `${Math.min(100, Math.max(0, yearPercent)).toFixed(1)}%`;
    if (daysRemainingEl) daysRemainingEl.textContent = `${remainingDays} jour${remainingDays > 1 ? 's' : ''}`;
}
updateClock();
updateLiveProgress();
setInterval(updateClock, 1000);
setInterval(updateLiveProgress, 1000);

function openModal(id) {
    document.getElementById(id).classList.add('show');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('show');
}

function triggerProfileUpload(inputId) {
    document.getElementById(inputId)?.click();
}

function submitProfileMediaForm(formId) {
    document.getElementById(formId)?.submit();
}

function activatePortalTab(tabName) {
    document.querySelectorAll('[data-tab-target]').forEach(btn => {
        btn.classList.toggle('active', btn.getAttribute('data-tab-target') === tabName);
    });
    document.querySelectorAll('[data-tab-panel]').forEach(panel => {
        panel.classList.toggle('active', panel.getAttribute('data-tab-panel') === tabName);
    });
}

function setupPortalTabs() {
    document.querySelectorAll('[data-tab-target]').forEach(btn => {
        btn.addEventListener('click', () => {
            const tabName = btn.getAttribute('data-tab-target');
            activatePortalTab(tabName);
            if (history.replaceState) {
                history.replaceState(null, '', `#${tabName}`);
            }
        });
    });

    const hash = window.location.hash.replace('#', '');
    if (hash && document.querySelector(`[data-tab-panel="${hash}"]`)) {
        activatePortalTab(hash);
    } else if (hash.startsWith('post-')) {
        activatePortalTab('publications');
    }
}

function openMediaViewer(source, type = 'image', caption = '') {
    const viewer = document.getElementById('mediaViewer');
    const stage = document.getElementById('mediaViewerStage');
    const captionEl = document.getElementById('mediaViewerCaption');
    if (!viewer || !stage || !source) return;

    stage.innerHTML = '';
    if (type === 'video') {
        const video = document.createElement('video');
        video.src = source;
        video.controls = true;
        video.autoplay = true;
        video.playsInline = true;
        stage.appendChild(video);
    } else {
        const img = document.createElement('img');
        img.src = source;
        img.alt = caption || 'Media';
        stage.appendChild(img);
    }
    if (captionEl) captionEl.textContent = caption || '';
    viewer.classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeMediaViewer(event) {
    if (event && event.target.closest('.media-viewer-shell') && !event.target.closest('.media-viewer-close') && event.target.id !== 'mediaViewer') {
        return;
    }
    const viewer = document.getElementById('mediaViewer');
    const stage = document.getElementById('mediaViewerStage');
    if (!viewer || !stage) return;
    const activeVideo = stage.querySelector('video');
    if (activeVideo) {
        activeVideo.pause();
        activeVideo.removeAttribute('src');
        activeVideo.load();
    }
    stage.innerHTML = '';
    viewer.classList.remove('show');
    document.body.style.overflow = '';
}

function setupMediaViewer() {
    document.querySelectorAll('[data-media-preview]').forEach(node => {
        node.addEventListener('click', event => {
            if (event.target.closest('video')) return;
            const source = node.getAttribute('data-media-preview') || '';
            const type = node.getAttribute('data-media-type') || 'image';
            const caption = node.getAttribute('data-media-caption') || '';
            openMediaViewer(source, type, caption);
        });
    });

    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            closeMediaViewer();
        }
    });
}

function toggleNotifications() {
    const panel   = document.getElementById('notifPanel');
    const overlay = document.getElementById('notifOverlay');
    const open    = panel.classList.toggle('show');
    overlay.classList.toggle('show', open);
    document.body.style.overflow = open ? 'hidden' : '';
}

function toggleThemeHint() {
    showAlert('success', 'Le dashboard suit automatiquement le mode clair/sombre de votre appareil.');
}

function setupPwaInstall() {
    const installBtn = document.getElementById('installAppBtn');
    if (!installBtn) return;

    window.addEventListener('beforeinstallprompt', event => {
        event.preventDefault();
        deferredInstallPrompt = event;
        installBtn.style.display = 'inline-flex';
    });

    installBtn.addEventListener('click', async () => {
        if (!deferredInstallPrompt) {
            showAlert('success', 'Si le bouton Installer n’apparait pas automatiquement, utilisez le menu du navigateur puis "Ajouter à l’écran d’accueil".');
            return;
        }

        deferredInstallPrompt.prompt();
        await deferredInstallPrompt.userChoice.catch(() => null);
        deferredInstallPrompt = null;
        installBtn.style.display = 'none';
    });

    window.addEventListener('appinstalled', () => {
        installBtn.style.display = 'none';
        showAlert('success', 'Application installée avec succès sur votre téléphone.');
    });

    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/hr/employee-sw.js').catch(() => {});
        });
    }
}

function saveOfflineSnapshot() {
    try {
        const payload = {
            name: <?= json_encode($employee['full_name']) ?>,
            status: <?= json_encode($todayStatusLabel . ' ' . $todayStatusIcon) ?>,
            hours: <?= json_encode($hoursTodayFormatted) ?>,
            salary: <?= json_encode(number_format($net_salary, 0, ',', ' ') . ' FCFA') ?>,
            updated_at: new Date().toLocaleString('fr-FR')
        };
        localStorage.setItem('employeePortalSnapshot', JSON.stringify(payload));
    } catch (e) {}
}

function hideSplashScreen() {
    const splash = document.getElementById('appSplash');
    if (!splash) return;
    splash.classList.add('hidden');
    setTimeout(() => splash.remove(), 450);
}

function updateNetworkBadge() {
    const badge = document.getElementById('networkBadge');
    if (!badge) return;
    badge.classList.toggle('show', !navigator.onLine);
}

async function hashPin(pin) {
    const data = new TextEncoder().encode(pin);
    const hash = await crypto.subtle.digest('SHA-256', data);
    return Array.from(new Uint8Array(hash)).map(b => b.toString(16).padStart(2, '0')).join('');
}

function getPinHash() {
    return localStorage.getItem(PIN_STORAGE_KEY);
}

function showPinLock() {
    document.getElementById('pinLockOverlay')?.classList.add('show');
    setTimeout(() => document.getElementById('pinUnlockInput')?.focus(), 80);
}

function hidePinLock() {
    document.getElementById('pinLockOverlay')?.classList.remove('show');
}

async function checkPinLock() {
    if (!getPinHash()) return;
    if (sessionStorage.getItem(PIN_UNLOCK_KEY) === '1') return;
    showPinLock();
}

async function unlockWithPin() {
    const input = document.getElementById('pinUnlockInput');
    const value = (input?.value || '').trim();
    if (!/^\d{4,6}$/.test(value)) {
        showAlert('error', 'Entrez un PIN entre 4 et 6 chiffres.');
        return;
    }
    const hash = await hashPin(value);
    if (hash !== getPinHash()) {
        showAlert('error', 'Code PIN incorrect.');
        if (input) input.value = '';
        return;
    }
    sessionStorage.setItem(PIN_UNLOCK_KEY, '1');
    hidePinLock();
}

function logoutFromLock() {
    window.location.href = '/../auth/logout.php';
}

async function savePinSettings() {
    const pin = (document.getElementById('pinSetupInput')?.value || '').trim();
    const confirmPin = (document.getElementById('pinConfirmInput')?.value || '').trim();
    const currentPin = (document.getElementById('pinCurrentInput')?.value || '').trim();
    const existing = getPinHash();

    if (!/^\d{4,6}$/.test(pin)) {
        showAlert('error', 'Le PIN doit contenir 4 à 6 chiffres.');
        return;
    }
    if (pin !== confirmPin) {
        showAlert('error', 'La confirmation du PIN ne correspond pas.');
        return;
    }
    if (existing) {
        if (!/^\d{4,6}$/.test(currentPin)) {
            showAlert('error', 'Entrez le PIN actuel pour le modifier.');
            return;
        }
        const currentHash = await hashPin(currentPin);
        if (currentHash !== existing) {
            showAlert('error', 'PIN actuel invalide.');
            return;
        }
    }

    localStorage.setItem(PIN_STORAGE_KEY, await hashPin(pin));
    sessionStorage.setItem(PIN_UNLOCK_KEY, '1');
    ['pinSetupInput','pinConfirmInput','pinCurrentInput'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = '';
    });
    closeModal('securityModal');
    showAlert('success', 'PIN local enregistré sur cet appareil.');
}

async function disablePinSettings() {
    const existing = getPinHash();
    if (!existing) {
        showAlert('success', 'Aucun PIN actif sur cet appareil.');
        return;
    }
    const currentPin = (document.getElementById('pinCurrentInput')?.value || '').trim();
    if (!/^\d{4,6}$/.test(currentPin)) {
        showAlert('error', 'Entrez le PIN actuel pour le désactiver.');
        return;
    }
    const currentHash = await hashPin(currentPin);
    if (currentHash !== existing) {
        showAlert('error', 'PIN actuel invalide.');
        return;
    }
    localStorage.removeItem(PIN_STORAGE_KEY);
    sessionStorage.removeItem(PIN_UNLOCK_KEY);
    ['pinSetupInput','pinConfirmInput','pinCurrentInput'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = '';
    });
    closeModal('securityModal');
    hidePinLock();
    showAlert('success', 'PIN désactivé sur cet appareil.');
}

function openQueueDb() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(QUEUE_DB_NAME, 1);
        request.onupgradeneeded = () => {
            const db = request.result;
            if (!db.objectStoreNames.contains(QUEUE_STORE)) {
                db.createObjectStore(QUEUE_STORE, { keyPath: 'id' });
            }
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

async function saveQueuedAttendance(item) {
    const db = await openQueueDb();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(QUEUE_STORE, 'readwrite');
        tx.objectStore(QUEUE_STORE).put(item);
        tx.oncomplete = () => resolve(true);
        tx.onerror = () => reject(tx.error);
    });
}

async function getQueuedAttendances() {
    const db = await openQueueDb();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(QUEUE_STORE, 'readonly');
        const req = tx.objectStore(QUEUE_STORE).getAll();
        req.onsuccess = () => resolve(req.result || []);
        req.onerror = () => reject(req.error);
    });
}

async function removeQueuedAttendance(id) {
    const db = await openQueueDb();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(QUEUE_STORE, 'readwrite');
        tx.objectStore(QUEUE_STORE).delete(id);
        tx.oncomplete = () => resolve(true);
        tx.onerror = () => reject(tx.error);
    });
}

async function updateSyncBadge() {
    const badge = document.getElementById('syncBadge');
    if (!badge) return;
    try {
        const items = await getQueuedAttendances();
        const count = items.length;
        badge.innerHTML = `<i class="fas fa-rotate"></i> ${count} pointage${count > 1 ? 's' : ''} en attente`;
        badge.classList.toggle('show', count > 0);
    } catch (e) {
        badge.classList.remove('show');
    }
}

async function queueAttendanceRequest(action, payload) {
    await saveQueuedAttendance({
        id: `${action}-${Date.now()}`,
        action,
        payload,
        createdAt: new Date().toISOString()
    });
    await updateSyncBadge();
    showAlert('success', 'Pointage enregistré hors ligne. Il sera synchronisé automatiquement dès le retour de la connexion.');
}

async function flushQueuedAttendances() {
    if (!navigator.onLine) return;
    try {
        const items = await getQueuedAttendances();
        for (const item of items) {
            const res = await fetch(window.location.href, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams(item.payload)
            });
            const data = await res.json();
            const msg = String(data.msg || '').toLowerCase();
            const duplicate = msg.includes('déjà pointé') || msg.includes('déjà pointé votre départ');
            if (data.success || duplicate) {
                await removeQueuedAttendance(item.id);
            }
        }
    } catch (e) {
    } finally {
        updateSyncBadge();
    }
}

function openEmployeeRequest(type) {
    const actions = {
        conge: () => {
            openModal('permissionModal');
            showAlert('success', 'Renseignez les dates et précisez "Demande de congé" dans le motif.');
        },
        historique_pointage: () => {
            activatePortalTab('infos');
            document.querySelector('#aiDataCard')?.scrollIntoView({behavior: 'smooth', block: 'center'});
            showAlert('success', 'Historique récent affiché plus bas dans le dashboard.');
        },
        historique_salaire: () => {
            activatePortalTab('infos');
            document.querySelector('#aiDataCard')?.scrollIntoView({behavior: 'smooth', block: 'center'});
            showAlert('success', 'Le salaire net estimé et les retenues sont affichés dans les cartes statistiques.');
        },
        absence: () => {
            openModal('permissionModal');
            showAlert('success', 'Pour signaler une absence, utilisez ce formulaire et indiquez "Absence" dans le motif.');
        }
    };
    if(actions[type]) actions[type]();
}

async function deletePost(postId) {
    const ok = await Swal.fire({
        title: 'Supprimer cette publication ?',
        text: 'Cette action est irréversible. Les commentaires seront aussi supprimés.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#64748b',
        confirmButtonText: '<i class="fas fa-trash-alt"></i> Supprimer',
        cancelButtonText: 'Annuler'
    });
    if (!ok.isConfirmed) return;
    try {
        const res = await fetch(window.location.href, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({action: 'delete_post', csrf_token: csrf, post_id: postId})
        });
        const data = await res.json();
        if (data.success) {
            const el = document.getElementById('post-' + postId);
            if (el) { el.style.transition = 'opacity .3s'; el.style.opacity = '0'; setTimeout(() => el.remove(), 320); }
            showAlert('success', data.msg);
        } else {
            showAlert('error', data.msg || 'Erreur lors de la suppression.');
        }
    } catch(e) {
        showAlert('error', 'Erreur: ' + e.message);
    }
}

async function markAllNotificationsRead() {
    try {
        await fetch(window.location.href, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({action: 'mark_all_notifications_read', csrf_token: csrf})
        });
        document.querySelectorAll('.notif-item.unread').forEach(el => {
            el.classList.remove('unread');
            const dot = el.querySelector('.notif-unread-dot');
            if (dot) dot.remove();
        });
        const badge = document.querySelector('.notif-badge');
        if (badge) badge.remove();
        const sub = document.querySelector('#notifPanel .notif-header-left span');
        if (sub) sub.textContent = '0 non lue';
        const btn = document.querySelector('.notif-mark-all');
        if (btn) btn.remove();
    } catch(e) {}
}

// Horloge topbar + widget pointage
(function atbTick() {
    const n = new Date();
    const t = String(n.getHours()).padStart(2,'0') + ':' + String(n.getMinutes()).padStart(2,'0');
    ['atbClock','atbClockWidget'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.textContent = t;
    });
    setTimeout(atbTick, 15000);
})();

const notifTypeLabels = {
    check_in:       'Pointage Arrivée',
    check_out:      'Pointage Départ',
    overtime_bonus: 'Heures Supplémentaires',
    late_warning:   'Avertissement Retard',
    social_post:    'Nouvelle Publication',
    permission:     'Demande de Permission',
    advance:        'Demande d\'Avance',
    info:           'Information',
};

function openNotifPopup(notifId) {
    const item = document.getElementById('notif-' + notifId);
    if (!item) return;

    const msg   = item.dataset.notifMsg;
    const date  = item.dataset.notifDate;
    const type  = item.dataset.notifType;
    const icon  = item.dataset.notifIcon;
    const cls   = item.dataset.notifCls;
    const label = notifTypeLabels[type] || 'Notification';

    document.getElementById('npIcon').className   = 'notif-popup-icon ' + cls;
    document.getElementById('npIcon').innerHTML   = `<i class="fas ${icon}"></i>`;
    document.getElementById('npTitle').textContent = label;
    document.getElementById('npDate').innerHTML   = `<i class="fas fa-clock"></i> ${date}`;
    document.getElementById('npBody').innerHTML   = msg.replace(/\n/g,'<br>');

    document.getElementById('notifPopupOverlay').classList.add('show');

    // Marquer comme lu silencieusement
    if (item.classList.contains('unread')) {
        item.classList.remove('unread');
        const dot = item.querySelector('.notif-unread-dot');
        if (dot) dot.remove();
        fetch(window.location.href, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({action:'mark_notification_read', csrf_token:csrf, notif_id:notifId})
        }).catch(() => {});
        // Mettre à jour le badge dans le topbar
        const badge = document.querySelector('.topbar-bell .notif-badge');
        if (badge) {
            const n = parseInt(badge.textContent, 10) - 1;
            if (n <= 0) badge.remove();
            else badge.textContent = n;
        }
    }
}

function closeNotifPopup(e) {
    if (e && e.target !== document.getElementById('notifPopupOverlay')) return;
    document.getElementById('notifPopupOverlay').classList.remove('show');
}

async function markAsRead(notifId) {
    openNotifPopup(notifId);
}

// GEOLOCATION
function getLocation() {
    return new Promise((resolve, reject) => {
        if(!navigator.geolocation) {
            reject(new Error("Géolocalisation non supportée"));
            return;
        }
        
        navigator.geolocation.getCurrentPosition(
            position => resolve({
                latitude: position.coords.latitude,
                longitude: position.coords.longitude
            }),
            error => reject(new Error("Impossible d'obtenir votre position. Activez le GPS.")),
            {enableHighAccuracy: true, timeout: 10000, maximumAge: 0}
        );
    });
}

// CAMERA
async function startCamera(action = 'check_in') {
    try {
        cameraAction = action;
        cameraStream = await navigator.mediaDevices.getUserMedia({video: {facingMode: 'user'}, audio: false});
        const video = document.getElementById('video');
        video.srcObject = cameraStream;
        const title = document.querySelector('#cameraModal .modal-title');
        if(title) title.textContent = action === 'check_out' ? '📸 Selfie de Départ' : '📸 Prenez un Selfie';
        openModal('cameraModal');
    } catch(e) {
        showAlert('error', '❌ Erreur caméra: ' + e.message);
    }
}

function capturePhoto() {
    const video = document.getElementById('video');
    const canvas = document.getElementById('canvas');
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0);
    selfieData = canvas.toDataURL('image/png');
    closeCameraModal();
    
    if (cameraAction === 'check_out') {
        submitCheckOut();
    } else {
        submitCheckIn();
    }
}

function closeCameraModal() {
    if(cameraStream) {
        cameraStream.getTracks().forEach(track => track.stop());
        cameraStream = null;
    }
    closeModal('cameraModal');
}

// ✅ CHECK-IN avec GPS + SELFIE
async function checkIn() {
    if(!confirm('📍 Pointer votre arrivée maintenant ?\n\nVotre position GPS et photo seront vérifiées.')) return;
    
    try {
        showAlert('success', '⏳ Vérification de votre position GPS...');
        currentLocation = await getLocation();
        
        showAlert('success', '📸 Position OK. Prenez maintenant un selfie...');
        await startCamera('check_in');
    } catch(e) {
        showAlert('error', e.message);
    }
}

async function submitCheckIn() {
    const payload = {
        action: "check_in",
        csrf_token: csrf,
        time: new Date().toTimeString().slice(0,8),
        date: new Date().toISOString().slice(0,10),
        latitude: currentLocation.latitude,
        longitude: currentLocation.longitude,
        selfie: selfieData
    };

    if (!navigator.onLine) {
        await queueAttendanceRequest('check_in', payload);
        setTimeout(() => location.reload(), 1200);
        return;
    }

    try {
        const res = await fetch(window.location.href, {
            method: "POST",
            headers: {"Content-Type": "application/x-www-form-urlencoded"},
            body: new URLSearchParams(payload)
        });
        
        const data = await res.json();
        
        if(data.success) {
            showAlert('success', data.msg);
            setTimeout(() => location.reload(), 3000);
        } else {
            showAlert('error', data.msg);
        }
    } catch(e) {
        await queueAttendanceRequest('check_in', payload);
        setTimeout(() => location.reload(), 1200);
    }
}

function timeToMinutes(timeString) {
    const [hours, minutes] = timeString.split(':').map(Number);
    return (hours * 60) + minutes;
}

// ✅ CHECK-OUT avec GPS + SELFIE
async function checkOut() {
    const now = new Date();
    const currentHour = now.getHours();
    const currentMinute = now.getMinutes();
    const currentTimeStr = now.toTimeString().slice(0,5);
    const currentMinutes = (currentHour * 60) + currentMinute;
    const allowedDepartureMinutes = timeToMinutes(attendanceConfig.workEndTime.slice(0, 5));
    
    // ✅ VÉRIFICATION: Bloquer si avant l'heure de départ configurée
    const isBeforeDeparture = currentMinutes < allowedDepartureMinutes;
    
    if(isBeforeDeparture) {
        const minutesRemaining = allowedDepartureMinutes - currentMinutes;
        alert(`⛔ DÉPART IMPOSSIBLE AVANT ${attendanceConfig.workEndLabel} !\n\n⏰ Il vous reste encore ${minutesRemaining} minutes de travail.\n\nHeure actuelle: ${currentTimeStr}\nHeure de départ autorisée: ${attendanceConfig.workEndLabel}`);
        return;
    }
    
    const isAfterDeparture = currentMinutes >= allowedDepartureMinutes;
    
    let confirmMsg = `🏁 Pointer votre départ à ${currentTimeStr} ?`;
    if(attendanceConfig.requireGpsCheckOut) {
        confirmMsg += `\n\n📍 Le GPS sera vérifié pour le départ.`;
    }
    confirmMsg += `\n\n📸 Un selfie de départ sera requis.`;
    if(isAfterDeparture && currentMinutes > allowedDepartureMinutes) {
        const overtimeMinutes = currentMinutes - allowedDepartureMinutes;
        const overtimeHours = (overtimeMinutes / 60).toFixed(2);
        confirmMsg += `\n\n💰 BONUS: ${overtimeHours}h supplémentaires détectées !\nSeront automatiquement enregistrées.`;
    }
    
    if(!confirm(confirmMsg)) return;
    
    try {
        if(attendanceConfig.requireGpsCheckOut) {
            showAlert('success', '⏳ Vérification de votre position GPS de départ...');
            currentLocation = await getLocation();
        }
        showAlert('success', '📸 Prenez maintenant un selfie de départ...');
        await startCamera('check_out');
    } catch(e) {
        showAlert('error', 'Erreur: ' + e.message);
    }
}

async function submitCheckOut() {
    const now = new Date();
    const payload = {
        action: "check_out",
        csrf_token: csrf,
        time: now.toTimeString().slice(0,8),
        date: now.toISOString().slice(0,10),
        latitude: currentLocation?.latitude || '',
        longitude: currentLocation?.longitude || '',
        selfie: selfieData
    };

    if (!navigator.onLine) {
        await queueAttendanceRequest('check_out', payload);
        setTimeout(() => location.reload(), 1200);
        return;
    }

    try {
        const res = await fetch(window.location.href, {
            method: "POST",
            headers: {"Content-Type": "application/x-www-form-urlencoded"},
            body: new URLSearchParams(payload)
        });
        
        const data = await res.json();
        
        if(data.success) {
            showAlert('success', data.msg);
            setTimeout(() => location.reload(), 4000);
        } else {
            showAlert('error', data.msg);
        }
    } catch(e) {
        await queueAttendanceRequest('check_out', payload);
        setTimeout(() => location.reload(), 1200);
    }
}

// FORMS
document.getElementById('permissionForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = this.querySelector('button');
    btn.disabled = true;
    
    try {
        const formData = new FormData(this);
        formData.append('action', 'request_permission');
        
        const res = await fetch(window.location.href, {
            method: "POST",
            body: new URLSearchParams(formData)
        });
        
        const data = await res.json();
        
        if(data.success) {
            showAlert('success', data.msg);
            closeModal('permissionModal');
            this.reset();
            setTimeout(() => location.reload(), 2000);
        } else {
            showAlert('error', data.msg);
        }
    } catch(e) {
        showAlert('error', 'Erreur: ' + e.message);
    } finally {
        btn.disabled = false;
    }
});

document.getElementById('advanceForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = this.querySelector('button');
    btn.disabled = true;
    
    try {
        const formData = new FormData(this);
        formData.append('action', 'request_advance');
        
        const res = await fetch(window.location.href, {
            method: "POST",
            body: new URLSearchParams(formData)
        });
        
        const data = await res.json();
        
        if(data.success) {
            showAlert('success', data.msg);
            closeModal('advanceModal');
            this.reset();
            setTimeout(() => location.reload(), 2000);
        } else {
            showAlert('error', data.msg);
        }
    } catch(e) {
        showAlert('error', 'Erreur: ' + e.message);
    } finally {
        btn.disabled = false;
    }
});

// ✅ GRAPHIQUE RETARDS - PIE CHART ÉCLATÉ
const lateChartCanvas = document.getElementById('lateChart');
const hoursChartCanvas = document.getElementById('hoursChart');
const cssTextColor = getComputedStyle(document.documentElement).getPropertyValue('--text').trim() || '#0f172a';
const cssMuted = getComputedStyle(document.documentElement).getPropertyValue('--text-soft').trim() || '#64748b';
const gridColor = 'rgba(148, 163, 184, 0.14)';

const ontimeCount = <?= $ontime_count ?>;
const lateCountChart = <?= $late_count_chart ?>;
const totalDays = ontimeCount + lateCountChart;

if (lateChartCanvas) {
    new Chart(lateChartCanvas.getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: ['À l\'heure', 'En retard'],
            datasets: [{
                data: [ontimeCount, lateCountChart],
                backgroundColor: ['rgba(16, 185, 129, 0.86)', 'rgba(239, 68, 68, 0.84)'],
                borderWidth: 0,
                hoverOffset: 12
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { color: cssTextColor, font: { family: 'Poppins', weight: '700' } }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const value = context.parsed || 0;
                            const percentage = totalDays > 0 ? ((value / totalDays) * 100).toFixed(1) : 0;
                            return `${context.label}: ${value} jour(s) (${percentage}%)`;
                        }
                    }
                }
            },
            cutout: '62%'
        }
    });
}

if (hoursChartCanvas) {
    new Chart(hoursChartCanvas.getContext('2d'), {
        type: 'bar',
        data: {
            labels: ['Heures du jour', 'Heures cible', 'Heures supp.'],
            datasets: [{
                data: [
                    <?= json_encode($hours_today_decimal) ?>,
                    <?= json_encode(round(max(0, ($workEndSeconds - $workStartSeconds) / 3600), 2)) ?>,
                    <?= json_encode(round(max(0, $hours_today_decimal - max(0, ($workEndSeconds - $workStartSeconds) / 3600)), 2)) ?>
                ],
                backgroundColor: ['rgba(59, 130, 246, 0.82)', 'rgba(16, 185, 129, 0.82)', 'rgba(245, 158, 11, 0.85)'],
                borderRadius: 12
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { ticks: { color: cssMuted, font: { family: 'Poppins', weight: '700' } }, grid: { display: false } },
                y: { ticks: { color: cssMuted }, grid: { color: gridColor }, beginAtZero: true }
            }
        }
    });
}

setupPwaInstall();
setupPortalTabs();
setupMediaViewer();
saveOfflineSnapshot();
window.addEventListener('load', () => setTimeout(hideSplashScreen, 350));
setTimeout(hideSplashScreen, 2200);
window.addEventListener('online', updateNetworkBadge);
window.addEventListener('offline', updateNetworkBadge);
updateNetworkBadge();
window.addEventListener('online', flushQueuedAttendances);
window.addEventListener('load', flushQueuedAttendances);
window.addEventListener('load', updateSyncBadge);
window.addEventListener('load', checkPinLock);

</script>

</body>
</html>
