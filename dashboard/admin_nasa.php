<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

/**
 * ═══════════════════════════════════════════════════════════════
 * ADMIN NASA — ESPERANCE H2O  [Enhanced v2.0]
 * Style: Dark Neon · C059 Bold · Tabs Navigation · AJAX Inline
 * ✅ Dropdown nav (plus de sidebar)
 * ✅ Tabs modules fluides
 * ✅ Produits : société + ville obligatoires (chargement AJAX)
 * ✅ AJAX 100% inline (pas de fichier externe)
 * ✅ Stock rapide · Activité live · Export CSV logs
 * ✅ Raccourcis clavier · Toasts · Dark Neon
 * ═══════════════════════════════════════════════════════════════
 */
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
Middleware::role(['developer', 'admin']);

$pdo    = DB::getConnection();
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'admin';
$csrfToken = app_ensure_csrf_token();

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
        ];

        foreach ($requiredColumns as $column => $sql) {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM attendance LIKE ?");
            $stmt->execute([$column]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                $pdo->exec($sql);
            }
        }
    } catch (Throwable $e) {
    }
}

function ensureProductImageStorage(PDO $pdo): void {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM products LIKE 'image_path'");
        $stmt->execute();
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("ALTER TABLE products ADD COLUMN image_path VARCHAR(255) NULL AFTER alert_quantity");
        }

        $uploadRoot = project_path('uploads');
        $productDir = project_path('uploads/products');
        if (!is_dir($uploadRoot)) {
            @mkdir($uploadRoot, 0777, true);
        }
        if (!is_dir($productDir)) {
            @mkdir($productDir, 0777, true);
        }
    } catch (Throwable $e) {
    }
}

function ensurePromotionStorage(PDO $pdo): void {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS promotion_campaigns (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                subtitle VARCHAR(255) NULL,
                promo_type ENUM('simple','flash','pack','quantity') NOT NULL DEFAULT 'simple',
                filter_tag VARCHAR(50) NOT NULL DEFAULT 'reduction',
                badge_label VARCHAR(80) NULL,
                product_id INT NULL,
                discount_percent DECIMAL(8,2) NULL,
                old_price DECIMAL(12,2) NULL,
                promo_price DECIMAL(12,2) NULL,
                quantity_buy INT NULL,
                quantity_pay INT NULL,
                tiers_json TEXT NULL,
                starts_at DATETIME NULL,
                ends_at DATETIME NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                company_id INT NOT NULL DEFAULT 0,
                city_id INT NOT NULL DEFAULT 0,
                notify_clients TINYINT(1) NOT NULL DEFAULT 0,
                email_sent_at DATETIME NULL,
                created_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_context(company_id, city_id, is_active),
                INDEX idx_product(product_id),
                INDEX idx_period(starts_at, ends_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS promotion_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                promotion_id INT NOT NULL,
                product_id INT NOT NULL,
                quantity INT NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_promotion(promotion_id),
                INDEX idx_product(product_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
    }
}

function ensureLoyaltyStorage(PDO $pdo): void {
    try {
        $clientColumns = [];
        try {
            $st = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clients'");
            $clientColumns = $st ? $st->fetchAll(PDO::FETCH_COLUMN) : [];
        } catch (Throwable $e) {
            $clientColumns = [];
        }
        if (!in_array('loyalty_points', $clientColumns, true)) {
            $pdo->exec("ALTER TABLE clients ADD COLUMN loyalty_points INT NOT NULL DEFAULT 0 AFTER city_id");
            $clientColumns[] = 'loyalty_points';
        }
        if (!in_array('vip_status', $clientColumns, true)) {
            $pdo->exec("ALTER TABLE clients ADD COLUMN vip_status VARCHAR(30) NOT NULL DEFAULT 'standard' AFTER loyalty_points");
            $clientColumns[] = 'vip_status';
        }
        if (!in_array('last_order_at', $clientColumns, true)) {
            $pdo->exec("ALTER TABLE clients ADD COLUMN last_order_at DATETIME NULL AFTER vip_status");
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS client_loyalty_transactions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                client_id INT NOT NULL,
                points_delta INT NOT NULL,
                reason VARCHAR(120) NOT NULL,
                reference_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_client (client_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS abandoned_carts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                client_id INT NOT NULL,
                company_id INT NOT NULL DEFAULT 0,
                city_id INT NOT NULL DEFAULT 0,
                cart_payload LONGTEXT NOT NULL,
                item_count INT NOT NULL DEFAULT 0,
                cart_total DECIMAL(12,2) NOT NULL DEFAULT 0,
                status ENUM('active','recovered','expired') NOT NULL DEFAULT 'active',
                last_activity_at DATETIME NOT NULL,
                reminder_sent_at DATETIME NULL,
                whatsapp_link TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_client (client_id),
                INDEX idx_activity (last_activity_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS personalized_coupons (
                id INT AUTO_INCREMENT PRIMARY KEY,
                client_id INT NOT NULL,
                abandoned_cart_id INT NULL,
                code VARCHAR(40) NOT NULL,
                title VARCHAR(160) NOT NULL DEFAULT 'Coupon personnalisé',
                discount_percent DECIMAL(5,2) NULL,
                amount_off DECIMAL(12,2) NULL,
                min_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                status ENUM('active','used','expired') NOT NULL DEFAULT 'active',
                expires_at DATETIME NULL,
                sent_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_code (code),
                INDEX idx_client (client_id),
                INDEX idx_cart (abandoned_cart_id),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
    }
}

function loyaltyTierMetaAdmin(string $status): array {
    $map = [
        'standard' => ['label' => 'Standard', 'color' => 'var(--muted)'],
        'silver' => ['label' => 'Silver', 'color' => '#c0d6df'],
        'gold' => ['label' => 'Gold', 'color' => 'var(--gold)'],
        'platinum' => ['label' => 'Platinum', 'color' => '#7adfff'],
    ];
    return $map[$status] ?? $map['standard'];
}

function generateRecoveryCouponCode(PDO $pdo): string {
    do {
        try {
            $random = strtoupper(bin2hex(random_bytes(3)));
        } catch (Throwable $e) {
            $random = strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 6));
        }
        $code = 'RECOV-' . $random;
        $check = $pdo->prepare("SELECT id FROM personalized_coupons WHERE code=? LIMIT 1");
        $check->execute([$code]);
    } while ($check->fetchColumn());
    return $code;
}

function parsePromotionTiersInput(?string $raw): ?string {
    $raw = trim((string)$raw);
    if ($raw === '') {
        return null;
    }
    $tiers = [];
    foreach (preg_split('/\s*,\s*/', $raw) as $chunk) {
        if ($chunk === '') {
            continue;
        }
        [$qty, $discount] = array_pad(explode(':', $chunk, 2), 2, null);
        $qty = (int)$qty;
        $discount = (float)str_replace('%', '', (string)$discount);
        if ($qty > 0 && $discount > 0) {
            $tiers[] = ['qty' => $qty, 'discount_percent' => $discount];
        }
    }
    return $tiers ? json_encode($tiers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
}

function parsePromotionItemsInput(?string $raw): array {
    $raw = trim((string)$raw);
    if ($raw === '') {
        return [];
    }
    $items = [];
    foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        [$productId, $qty] = array_pad(explode(':', $line, 2), 2, null);
        $productId = (int)$productId;
        $qty = max(1, (int)$qty);
        if ($productId > 0) {
            $items[] = ['product_id' => $productId, 'quantity' => $qty];
        }
    }
    return $items;
}

function normalizePromotionDateTime(?string $raw): ?string {
    $raw = trim((string)$raw);
    if ($raw === '') {
        return null;
    }
    $raw = str_replace('T', ' ', $raw);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $raw)) {
        $raw .= ':00';
    }
    return $raw;
}

function sendPromotionNotifications(PDO $pdo, array $promotion): array {
    $title = trim((string)($promotion['title'] ?? 'Promotion'));
    $subtitle = trim((string)($promotion['subtitle'] ?? ''));
    $promoType = trim((string)($promotion['promo_type'] ?? 'simple'));
    $promoPrice = (float)($promotion['promo_price'] ?? 0);
    $discount = (float)($promotion['discount_percent'] ?? 0);
    $endsAt = trim((string)($promotion['ends_at'] ?? ''));
    $subject = 'Nouvelle promotion ESPERANCE H2O';
    $messageText = $title;
    if ($subtitle !== '') {
        $messageText .= ' - ' . $subtitle;
    }
    if ($discount > 0) {
        $messageText .= ' | Remise: -' . rtrim(rtrim(number_format($discount, 2, '.', ''), '0'), '.') . '%';
    }
    if ($promoPrice > 0) {
        $messageText .= ' | Prix promo: ' . number_format($promoPrice, 0, '', '.') . ' CFA';
    }
    if ($promoType === 'flash' && $endsAt !== '') {
        $messageText .= ' | Fin: ' . $endsAt;
    }

    $clients = $pdo->query("SELECT id,name,email FROM clients WHERE email IS NOT NULL AND TRIM(email) <> '' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    $notified = 0;
    $emailsSent = 0;
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "From: ESPERANCE H2O <no-reply@" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ">\r\n";

    foreach ($clients as $client) {
        try {
            $pdo->prepare("INSERT INTO notifications(client_id,title,message,type) VALUES(?,?,?,'promo')")
                ->execute([(int)$client['id'], $subject, $messageText]);
            $notified++;
        } catch (Throwable $e) {
        }
        if (!empty($client['email'])) {
            $body = "Bonjour " . trim((string)($client['name'] ?? 'client')) . ",\n\n" . $messageText . "\n\nMerci,\nESPERANCE H2O";
            if (@mail((string)$client['email'], $subject, $body, $headers)) {
                $emailsSent++;
            }
        }
    }

    return ['notifications' => $notified, 'emails' => $emailsSent];
}

function productImageUrl(?string $path): ?string {
    if (!$path) {
        return null;
    }
    return project_url(ltrim($path, '/'));
}

function deleteProductImageFile(?string $path): void {
    if (!$path) {
        return;
    }
    $full = project_path($path);
    if (is_file($full)) {
        @unlink($full);
    }
}

function storeUploadedProductImage(array $file, int $productId): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload image invalide');
    }

    $tmp = $file['tmp_name'] ?? '';
    if (!$tmp || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Fichier image non reçu');
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    $mime = function_exists('mime_content_type') ? @mime_content_type($tmp) : '';
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Format image non supporté. Utilisez JPG, PNG, WEBP ou GIF');
    }

    $dir = project_path('uploads/products');
    if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
        throw new RuntimeException('Impossible de créer le dossier images produits');
    }

    $filename = sprintf('product_%d_%s.%s', $productId, bin2hex(random_bytes(6)), $allowed[$mime]);
    $relative = 'uploads/products/' . $filename;
    $dest = project_path($relative);

    if (!@move_uploaded_file($tmp, $dest)) {
        throw new RuntimeException("Impossible d'enregistrer l'image produit");
    }

    return ['path' => $relative, 'url' => productImageUrl($relative)];
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

ensureAttendanceSettingsStorage($pdo);
ensureProductImageStorage($pdo);
ensurePromotionStorage($pdo);
ensureLoyaltyStorage($pdo);

/* ─── Logger ─── */
function logAction($pdo, $userId, $action, $details = '') {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS action_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL, action VARCHAR(255) NOT NULL,
            details TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX(user_id), INDEX(created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $s = $pdo->prepare("INSERT INTO action_logs (user_id,action,details,created_at) VALUES(?,?,?,NOW())");
        $s->execute([$userId, $action, $details]);
    } catch(Exception $e) {}
}

/* ═══════════════════════════════════════════════════
   ██  AJAX HANDLER INLINE  ██
═══════════════════════════════════════════════════ */
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=UTF-8');
    $act = $_GET['ajax'];
    $readOnlyActs = [
        'get_users','get_companies','get_cities','get_cities_by_company','get_products','get_products_by_context',
        'get_promotions','get_loyalty_clients','get_abandoned_carts','get_loyalty_transactions','get_coupon_stats',
        'get_personalized_coupons','get_logs','logs_chart','db_stats','quick_stock','dashboard_stats',
        'get_notif_count','get_attendance_settings','export_logs_csv','export_abandoned_carts_csv','export_coupons_csv',
    ];
    $requiresCsrf = !in_array($act, $readOnlyActs, true);
    if ($requiresCsrf) {
        $csrfInput = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_GET['csrf_token'] ?? $_POST['csrf_token'] ?? '');
        if (!app_csrf_validate($csrfInput)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'msg' => 'CSRF invalide']); exit;
        }
    }

    /* ── Users ── */
    if ($act === 'get_users') {
        $search = '%' . ($_GET['q'] ?? '') . '%';
        $st = $pdo->prepare("SELECT u.id,u.username,u.role,COALESCE(c.name,'—') company_name
            FROM users u LEFT JOIN companies c ON c.id=u.company_id
            WHERE u.username LIKE ? OR u.role LIKE ? ORDER BY u.id DESC LIMIT 100");
        $st->execute([$search,$search]);
        echo json_encode($st->fetchAll(PDO::FETCH_ASSOC)); exit;
    }
    if ($act === 'add_user') {
        $d = json_decode(file_get_contents('php://input'), true);
        $pw = password_hash($d['password'], PASSWORD_DEFAULT);
        $s = $pdo->prepare("INSERT INTO users (username,password,role,company_id) VALUES(?,?,?,?)");
        $s->execute([$d['username'],$pw,$d['role'],$d['company_id']]);
        $id = $pdo->lastInsertId();
        logAction($pdo,$userId,'add_user',"User: {$d['username']} Role: {$d['role']}");
        echo json_encode(['ok'=>true,'id'=>$id]); exit;
    }
    if ($act === 'update_user') {
        $d = json_decode(file_get_contents('php://input'), true);
        if (!empty($d['password'])) {
            $pw = password_hash($d['password'], PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET username=?,password=?,role=?,company_id=? WHERE id=?")
                ->execute([$d['username'],$pw,$d['role'],$d['company_id'],$d['id']]);
        } else {
            $pdo->prepare("UPDATE users SET username=?,role=?,company_id=? WHERE id=?")
                ->execute([$d['username'],$d['role'],$d['company_id'],$d['id']]);
        }
        logAction($pdo,$userId,'update_user',"ID: {$d['id']} User: {$d['username']}");
        echo json_encode(['ok'=>true]); exit;
    }
    if ($act === 'delete_user') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id == $userId) { echo json_encode(['ok'=>false,'msg'=>'Vous ne pouvez pas vous supprimer']); exit; }
        $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
        logAction($pdo,$userId,'delete_user',"ID: $id");
        echo json_encode(['ok'=>true]); exit;
    }

    /* ── Companies ── */
    if ($act === 'get_companies') {
        $q = '%' . ($_GET['q'] ?? '') . '%';
        $st = $pdo->prepare("SELECT id,name,created_at FROM companies WHERE name LIKE ? ORDER BY name LIMIT 100");
        $st->execute([$q]);
        echo json_encode($st->fetchAll(PDO::FETCH_ASSOC)); exit;
    }
    if ($act === 'add_company') {
        $d = json_decode(file_get_contents('php://input'), true);
        $s = $pdo->prepare("INSERT INTO companies (name) VALUES(?)");
        $s->execute([trim($d['name'])]);
        $id = $pdo->lastInsertId();
        logAction($pdo,$userId,'add_company',"Name: {$d['name']}");
        echo json_encode(['ok'=>true,'id'=>$id]); exit;
    }
    if ($act === 'update_company') {
        $d = json_decode(file_get_contents('php://input'), true);
        $pdo->prepare("UPDATE companies SET name=? WHERE id=?")->execute([trim($d['name']),$d['id']]);
        logAction($pdo,$userId,'update_company',"ID: {$d['id']} Name: {$d['name']}");
        echo json_encode(['ok'=>true]); exit;
    }
    if ($act === 'delete_company') {
        $id = (int)($_GET['id'] ?? 0);
        $pdo->prepare("DELETE FROM companies WHERE id=?")->execute([$id]);
        logAction($pdo,$userId,'delete_company',"ID: $id");
        echo json_encode(['ok'=>true]); exit;
    }

    /* ── Cities ── */
    if ($act === 'get_cities') {
        $q = '%' . ($_GET['q'] ?? '') . '%';
        $cid = (int)($_GET['company_id'] ?? 0);
        $extra = $cid ? " AND ci.company_id=$cid" : '';
        $st = $pdo->prepare("SELECT ci.id,ci.name,c.name company_name FROM cities ci
            LEFT JOIN companies c ON c.id=ci.company_id WHERE ci.name LIKE ? $extra ORDER BY ci.name LIMIT 100");
        $st->execute([$q]);
        echo json_encode($st->fetchAll(PDO::FETCH_ASSOC)); exit;
    }
    if ($act === 'get_cities_by_company') {
        $cid = (int)($_GET['company_id'] ?? 0);
        $st = $pdo->prepare("SELECT id,name FROM cities WHERE company_id=? ORDER BY name");
        $st->execute([$cid]);
        echo json_encode($st->fetchAll(PDO::FETCH_ASSOC)); exit;
    }
    if ($act === 'add_city') {
        $d = json_decode(file_get_contents('php://input'), true);
        $s = $pdo->prepare("INSERT INTO cities (name,company_id) VALUES(?,?)");
        $s->execute([trim($d['name']),$d['company_id']]);
        $id = $pdo->lastInsertId();
        logAction($pdo,$userId,'add_city',"Name: {$d['name']} Company: {$d['company_id']}");
        echo json_encode(['ok'=>true,'id'=>$id]); exit;
    }
    if ($act === 'update_city') {
        $d = json_decode(file_get_contents('php://input'), true);
        $pdo->prepare("UPDATE cities SET name=?,company_id=? WHERE id=?")->execute([trim($d['name']),$d['company_id'],$d['id']]);
        logAction($pdo,$userId,'update_city',"ID: {$d['id']}");
        echo json_encode(['ok'=>true]); exit;
    }
    if ($act === 'delete_city') {
        $id = (int)($_GET['id'] ?? 0);
        $pdo->prepare("DELETE FROM cities WHERE id=?")->execute([$id]);
        logAction($pdo,$userId,'delete_city',"ID: $id");
        echo json_encode(['ok'=>true]); exit;
    }

    /* ── Products ── */
    if ($act === 'get_products') {
        $q = '%' . ($_GET['q'] ?? '') . '%';
        $cid = (int)($_GET['company_id'] ?? 0);
        $cityFilter = (int)($_GET['city_id'] ?? 0);
        $extra = $cid ? " AND p.company_id=$cid" : '';
        if ($cityFilter) $extra .= " AND p.city_id=$cityFilter";
        $st = $pdo->prepare("SELECT p.id,p.name,p.category,p.price,p.alert_quantity,
            p.image_path, COALESCE(c.name,'—') company_name, COALESCE(ci.name,'—') city_name
            FROM products p LEFT JOIN companies c ON c.id=p.company_id
            LEFT JOIN cities ci ON ci.id=p.city_id
            WHERE (p.name LIKE ? OR p.category LIKE ?) $extra ORDER BY p.id DESC LIMIT 200");
        $st->execute([$q,$q]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['image_url'] = productImageUrl($row['image_path'] ?? null);
        }
        echo json_encode($rows); exit;
    }
    if ($act === 'add_product') {
        $d = json_decode(file_get_contents('php://input'), true);
        if (empty($d['company_id']) || empty($d['city_id'])) {
            echo json_encode(['ok'=>false,'msg'=>'Société et Ville obligatoires']); exit;
        }
        /* ── Anti-doublon : même nom (insensible casse) dans la même ville ── */
        if (empty($d['force'])) {
            $chk = $pdo->prepare("SELECT id,name FROM products WHERE LOWER(name)=LOWER(?) AND company_id=? AND city_id=? LIMIT 1");
            $chk->execute([trim($d['name']), (int)$d['company_id'], (int)$d['city_id']]);
            $existing = $chk->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                echo json_encode(['ok'=>false,'doublon'=>true,'msg'=>"Un produit « {$existing['name']} » existe déjà dans ce magasin (ID #{$existing['id']})."]); exit;
            }
        }
        $s = $pdo->prepare("INSERT INTO products (name,category,price,alert_quantity,company_id,city_id) VALUES(?,?,?,?,?,?)");
        $s->execute([
            trim($d['name']),
            trim($d['category']),
            (float)$d['price'],
            (int)$d['alert_quantity'],
            (int)$d['company_id'],
            (int)$d['city_id']
        ]);
        $prod_id = $pdo->lastInsertId();
        /* Stock initial = 0 pour ce magasin */
        try {
            $pdo->prepare("INSERT INTO stock_movements (product_id,company_id,city_id,type,quantity,reference,movement_date)
                VALUES(?,?,?,'initial',0,'INIT-PRODUIT',NOW())")
                ->execute([$prod_id, $d['company_id'], $d['city_id']]);
        } catch(Exception $e) {}
        logAction($pdo,$userId,'add_product',"Name: {$d['name']} Company: {$d['company_id']} City: {$d['city_id']}");
        echo json_encode(['ok'=>true,'id'=>$prod_id]); exit;
    }
    if ($act === 'update_product') {
        $d = json_decode(file_get_contents('php://input'), true);
        $pdo->prepare("UPDATE products SET name=?,category=?,price=?,alert_quantity=? WHERE id=?")
            ->execute([trim($d['name']),trim($d['category']),(float)$d['price'],(int)$d['alert_quantity'],$d['id']]);
        logAction($pdo,$userId,'update_product',"ID: {$d['id']}");
        echo json_encode(['ok'=>true]); exit;
    }
    if ($act === 'upload_product_image') {
        try {
            $id = (int)($_POST['product_id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Produit invalide');
            }
            if (empty($_FILES['image'])) {
                throw new RuntimeException('Image requise');
            }

            $st = $pdo->prepare("SELECT image_path,name FROM products WHERE id=? LIMIT 1");
            $st->execute([$id]);
            $product = $st->fetch(PDO::FETCH_ASSOC);
            if (!$product) {
                throw new RuntimeException('Produit introuvable');
            }

            $uploaded = storeUploadedProductImage($_FILES['image'], $id);
            $pdo->prepare("UPDATE products SET image_path=? WHERE id=?")->execute([$uploaded['path'], $id]);
            deleteProductImageFile($product['image_path'] ?? null);
            logAction($pdo,$userId,'upload_product_image',"ID: {$id} Product: {$product['name']}");
            echo json_encode(['ok'=>true,'image_path'=>$uploaded['path'],'image_url'=>$uploaded['url']]); exit;
        } catch (Throwable $e) {
            echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); exit;
        }
    }
    if ($act === 'delete_product_image') {
        try {
            $id = (int)($_POST['product_id'] ?? $_GET['product_id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Produit invalide');
            }
            $st = $pdo->prepare("SELECT image_path,name FROM products WHERE id=? LIMIT 1");
            $st->execute([$id]);
            $product = $st->fetch(PDO::FETCH_ASSOC);
            if (!$product) {
                throw new RuntimeException('Produit introuvable');
            }
            deleteProductImageFile($product['image_path'] ?? null);
            $pdo->prepare("UPDATE products SET image_path=NULL WHERE id=?")->execute([$id]);
            logAction($pdo,$userId,'delete_product_image',"ID: {$id} Product: {$product['name']}");
            echo json_encode(['ok'=>true]); exit;
        } catch (Throwable $e) {
            echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); exit;
        }
    }
    if ($act === 'delete_product') {
        $id = (int)($_GET['id'] ?? 0);
        try {
            $st = $pdo->prepare("SELECT image_path, name FROM products WHERE id=? LIMIT 1");
            $st->execute([$id]);
            $product = $st->fetch(PDO::FETCH_ASSOC);
            $imagePath = $product['image_path'] ?? null;
            $productName = $product['name'] ?? '';
        } catch (Throwable $e) {
            $imagePath = null; $productName = '';
        }
        /* Supprime d'abord les enregistrements dépendants avant le produit */
        $pdo->prepare("DELETE FROM stock_movements WHERE product_id=?")->execute([$id]);
        try { $pdo->prepare("DELETE FROM order_items WHERE product_id=?")->execute([$id]); } catch(Exception $e) {}
        try { $pdo->prepare("DELETE FROM promotion_campaigns WHERE product_id=?")->execute([$id]); } catch(Exception $e) {}
        $pdo->prepare("DELETE FROM products WHERE id=?")->execute([$id]);
        deleteProductImageFile($imagePath);
        logAction($pdo,$userId,'delete_product',"ID: $id Name: $productName");
        echo json_encode(['ok'=>true,'name'=>$productName]); exit;
    }

    /* ── Promotions ── */
    if ($act === 'get_products_by_context') {
        $companyId = (int)($_GET['company_id'] ?? 0);
        $cityId = (int)($_GET['city_id'] ?? 0);
        $params = [];
        $sql = "SELECT id,name,price FROM products WHERE 1=1";
        if ($companyId > 0) {
            $sql .= " AND company_id = ?";
            $params[] = $companyId;
        }
        if ($cityId > 0) {
            $sql .= " AND city_id = ?";
            $params[] = $cityId;
        }
        $sql .= " ORDER BY name LIMIT 300";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC)); exit;
    }
    if ($act === 'get_promotions') {
        $q = '%' . ($_GET['q'] ?? '') . '%';
        $companyId = (int)($_GET['company_id'] ?? 0);
        $extra = $companyId ? " AND pc.company_id = $companyId" : '';
        $stmt = $pdo->prepare("
            SELECT pc.*, c.name AS company_name, ci.name AS city_name, p.name AS product_name
            FROM promotion_campaigns pc
            LEFT JOIN companies c ON c.id = pc.company_id
            LEFT JOIN cities ci ON ci.id = pc.city_id
            LEFT JOIN products p ON p.id = pc.product_id
            WHERE (pc.title LIKE ? OR COALESCE(pc.subtitle,'') LIKE ?) $extra
            ORDER BY pc.updated_at DESC, pc.id DESC
            LIMIT 200
        ");
        $stmt->execute([$q, $q]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $ids = array_map('intval', array_column($rows, 'id'));
        $itemsMap = [];
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $itStmt = $pdo->prepare("
                SELECT pi.promotion_id, pi.product_id, pi.quantity, p.name AS product_name
                FROM promotion_items pi
                INNER JOIN products p ON p.id = pi.product_id
                WHERE pi.promotion_id IN ($in)
                ORDER BY pi.id ASC
            ");
            $itStmt->execute($ids);
            foreach ($itStmt->fetchAll(PDO::FETCH_ASSOC) as $itemRow) {
                $itemsMap[(int)$itemRow['promotion_id']][] = $itemRow;
            }
        }
        foreach ($rows as &$row) {
            $row['items'] = $itemsMap[(int)$row['id']] ?? [];
            $row['tiers_text'] = '';
            $tiers = json_decode((string)($row['tiers_json'] ?? ''), true);
            if (is_array($tiers) && $tiers) {
                $row['tiers_text'] = implode(', ', array_map(fn($tier) => ((int)($tier['qty'] ?? 0)) . ':-' . rtrim(rtrim(number_format((float)($tier['discount_percent'] ?? 0), 2, '.', ''), '0'), '.'), $tiers));
            }
        }
        unset($row);
        echo json_encode($rows); exit;
    }
    if ($act === 'save_promotion') {
        $d = json_decode(file_get_contents('php://input'), true);
        $id = (int)($d['id'] ?? 0);
        $title = trim((string)($d['title'] ?? ''));
        $promoType = trim((string)($d['promo_type'] ?? 'simple'));
        $filterTag = trim((string)($d['filter_tag'] ?? 'reduction'));
        $productId = (int)($d['product_id'] ?? 0);
        $companyId = (int)($d['company_id'] ?? 0);
        $cityId = (int)($d['city_id'] ?? 0);
        $notifyClients = !empty($d['notify_clients']) ? 1 : 0;
        if ($title === '' || $companyId <= 0 || $cityId <= 0) {
            echo json_encode(['ok' => false, 'msg' => 'Titre, société et magasin obligatoires']); exit;
        }

        $items = parsePromotionItemsInput($d['items_text'] ?? '');
        if (in_array($promoType, ['simple', 'flash', 'quantity'], true)) {
            if ($productId <= 0) {
                echo json_encode(['ok' => false, 'msg' => 'Produit obligatoire pour cette promotion']); exit;
            }
            if (!$items) {
                $items = [['product_id' => $productId, 'quantity' => 1]];
            }
        }
        if ($promoType === 'pack' && !$items) {
            echo json_encode(['ok' => false, 'msg' => 'Ajoutez au moins un produit au pack']); exit;
        }

        $tiersJson = parsePromotionTiersInput($d['tiers_text'] ?? '');
        $startsAt = normalizePromotionDateTime($d['starts_at'] ?? '');
        $endsAt = normalizePromotionDateTime($d['ends_at'] ?? '');
        $payload = [
            $title,
            trim((string)($d['subtitle'] ?? '')),
            $promoType,
            $filterTag,
            trim((string)($d['badge_label'] ?? 'PROMO')),
            $productId > 0 ? $productId : null,
            (float)($d['discount_percent'] ?? 0),
            (float)($d['old_price'] ?? 0),
            (float)($d['promo_price'] ?? 0),
            (int)($d['quantity_buy'] ?? 0),
            (int)($d['quantity_pay'] ?? 0),
            $tiersJson,
            $startsAt,
            $endsAt,
            !empty($d['is_active']) ? 1 : 0,
            (int)($d['sort_order'] ?? 0),
            $companyId,
            $cityId,
            $notifyClients,
            $userId,
        ];

        if ($id > 0) {
            $stmt = $pdo->prepare("
                UPDATE promotion_campaigns
                SET title=?, subtitle=?, promo_type=?, filter_tag=?, badge_label=?, product_id=?, discount_percent=?, old_price=?, promo_price=?,
                    quantity_buy=?, quantity_pay=?, tiers_json=?, starts_at=?, ends_at=?, is_active=?, sort_order=?, company_id=?, city_id=?,
                    notify_clients=?, created_by=?
                WHERE id=?
            ");
            $stmt->execute([...$payload, $id]);
            $promotionId = $id;
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO promotion_campaigns
                (title, subtitle, promo_type, filter_tag, badge_label, product_id, discount_percent, old_price, promo_price, quantity_buy, quantity_pay, tiers_json, starts_at, ends_at, is_active, sort_order, company_id, city_id, notify_clients, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->execute($payload);
            $promotionId = (int)$pdo->lastInsertId();
        }

        $pdo->prepare("DELETE FROM promotion_items WHERE promotion_id=?")->execute([$promotionId]);
        if ($items) {
            $itemStmt = $pdo->prepare("INSERT INTO promotion_items (promotion_id, product_id, quantity) VALUES (?,?,?)");
            foreach ($items as $item) {
                $itemStmt->execute([$promotionId, (int)$item['product_id'], max(1, (int)$item['quantity'])]);
            }
        }

        $emailSummary = ['notifications' => 0, 'emails' => 0];
        if ($notifyClients && !empty($d['is_active'])) {
            $promoStmt = $pdo->prepare("SELECT * FROM promotion_campaigns WHERE id=? LIMIT 1");
            $promoStmt->execute([$promotionId]);
            $promotion = $promoStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $emailSummary = sendPromotionNotifications($pdo, $promotion);
            $pdo->prepare("UPDATE promotion_campaigns SET email_sent_at=NOW() WHERE id=?")->execute([$promotionId]);
        }

        logAction($pdo, $userId, $id > 0 ? 'update_promotion' : 'add_promotion', "ID: {$promotionId} Title: {$title}");
        echo json_encode(['ok' => true, 'id' => $promotionId, 'summary' => $emailSummary]); exit;
    }
    if ($act === 'toggle_promotion') {
        $id = (int)($_GET['id'] ?? 0);
        $enabled = (int)($_GET['enabled'] ?? 0);
        $pdo->prepare("UPDATE promotion_campaigns SET is_active=? WHERE id=?")->execute([$enabled ? 1 : 0, $id]);
        logAction($pdo, $userId, 'toggle_promotion', "ID: {$id} Enabled: {$enabled}");
        echo json_encode(['ok' => true]); exit;
    }
    if ($act === 'delete_promotion') {
        $id = (int)($_GET['id'] ?? 0);
        $pdo->prepare("DELETE FROM promotion_items WHERE promotion_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM promotion_campaigns WHERE id=?")->execute([$id]);
        logAction($pdo, $userId, 'delete_promotion', "ID: {$id}");
        echo json_encode(['ok' => true]); exit;
    }

    /* ── Loyalty / Abandoned carts ── */
    if ($act === 'get_loyalty_clients') {
        $q = '%' . ($_GET['q'] ?? '') . '%';
        $stmt = $pdo->prepare("
            SELECT c.id, c.name, c.phone, c.email, c.loyalty_points, c.vip_status, c.last_order_at,
                   COALESCE(co.name,'—') AS company_name,
                   COALESCE(ci.name,'—') AS city_name,
                   COALESCE(o.orders_count,0) AS orders_count,
                   COALESCE(o.total_spent,0) AS total_spent
            FROM clients c
            LEFT JOIN companies co ON co.id = c.company_id
            LEFT JOIN cities ci ON ci.id = c.city_id
            LEFT JOIN (
                SELECT client_id, COUNT(*) AS orders_count, SUM(total_amount) AS total_spent
                FROM orders
                WHERE status IN('pending','confirmed','delivering','done')
                GROUP BY client_id
            ) o ON o.client_id = c.id
            WHERE c.name LIKE ? OR c.phone LIKE ? OR COALESCE(c.email,'') LIKE ?
            ORDER BY c.loyalty_points DESC, o.total_spent DESC, c.id DESC
            LIMIT 250
        ");
        $stmt->execute([$q, $q, $q]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC)); exit;
    }
    if ($act === 'get_abandoned_carts') {
        $status = trim((string)($_GET['status'] ?? 'all'));
        $extra = $status !== 'all' ? " AND ac.status = " . $pdo->quote($status) : '';
        $stmt = $pdo->prepare("
            SELECT ac.*, c.name AS client_name, c.phone AS client_phone, c.email AS client_email,
                   COALESCE(co.name,'—') AS company_name, COALESCE(ci.name,'—') AS city_name
            FROM abandoned_carts ac
            INNER JOIN clients c ON c.id = ac.client_id
            LEFT JOIN companies co ON co.id = ac.company_id
            LEFT JOIN cities ci ON ci.id = ac.city_id
            WHERE 1=1 {$extra}
            ORDER BY ac.updated_at DESC
            LIMIT 250
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $payload = json_decode((string)$row['cart_payload'], true);
            $row['items_preview'] = is_array($payload) ? implode(', ', array_map(fn($item) => ((int)($item['qty'] ?? 0)) . 'x ' . trim((string)($item['product_name'] ?? 'Produit')), array_slice($payload, 0, 4))) : '';
        }
        unset($row);
        echo json_encode($rows); exit;
    }
    if ($act === 'resend_abandoned_cart') {
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT ac.*, c.name AS client_name, c.phone AS client_phone, c.email AS client_email
            FROM abandoned_carts ac
            INNER JOIN clients c ON c.id = ac.client_id
            WHERE ac.id=? LIMIT 1
        ");
        $stmt->execute([$id]);
        $cart = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$cart) {
            echo json_encode(['ok' => false, 'msg' => 'Panier introuvable']); exit;
        }
        $payload = json_decode((string)$cart['cart_payload'], true);
        $itemsText = is_array($payload) ? implode(', ', array_map(fn($item) => ((int)($item['qty'] ?? 0)) . 'x ' . trim((string)($item['product_name'] ?? 'Produit')), $payload)) : 'vos articles';
        $message = "Bonjour {$cart['client_name']}, votre panier ESPERANCE H2O vous attend encore : {$itemsText}.";
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= "From: ESPERANCE H2O <no-reply@" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ">\r\n";
        $emailSent = false;
        if (!empty($cart['client_email'])) {
            $emailSent = @mail((string)$cart['client_email'], 'Relance panier ESPERANCE H2O', $message, $headers);
        }
        $digits = preg_replace('/\D+/', '', (string)($cart['client_phone'] ?? ''));
        $waLink = $digits ? 'https://wa.me/' . $digits . '?text=' . rawurlencode($message) : '';
        $pdo->prepare("UPDATE abandoned_carts SET reminder_sent_at=NOW(), whatsapp_link=? WHERE id=?")->execute([$waLink ?: null, $id]);
        try {
            $pdo->prepare("INSERT INTO notifications(client_id,title,message,type) VALUES(?,?,?,'promo')")
                ->execute([(int)$cart['client_id'], 'Relance panier', $message . ($waLink ? " {$waLink}" : '')]);
        } catch (Throwable $e) {
        }
        logAction($pdo, $userId, 'resend_abandoned_cart', "Cart: {$id}");
        echo json_encode(['ok' => true, 'email_sent' => $emailSent, 'whatsapp_link' => $waLink]); exit;
    }
    if ($act === 'set_client_vip') {
        $id = (int)($_GET['id'] ?? 0);
        $vip = trim((string)($_GET['vip'] ?? 'standard'));
        $allowed = ['standard','silver','gold','platinum'];
        if (!in_array($vip, $allowed, true)) {
            echo json_encode(['ok' => false, 'msg' => 'Statut invalide']); exit;
        }
        $pdo->prepare("UPDATE clients SET vip_status=? WHERE id=?")->execute([$vip, $id]);
        logAction($pdo, $userId, 'set_client_vip', "Client: {$id} VIP: {$vip}");
        echo json_encode(['ok' => true]); exit;
    }
    if ($act === 'get_loyalty_transactions') {
        $clientId = (int)($_GET['client_id'] ?? 0);
        if ($clientId <= 0) {
            echo json_encode([]); exit;
        }
        $stmt = $pdo->prepare("
            SELECT lt.id, lt.points_delta, lt.reason, lt.reference_id, lt.created_at,
                   o.order_number
            FROM client_loyalty_transactions lt
            LEFT JOIN orders o ON o.id = lt.reference_id
            WHERE lt.client_id = ?
            ORDER BY lt.created_at DESC, lt.id DESC
            LIMIT 200
        ");
        $stmt->execute([$clientId]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC)); exit;
    }
    if ($act === 'export_abandoned_carts_csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="paniers_abandonnes_' . date('Y-m-d') . '.csv"');
        echo "\xEF\xBB\xBF";
        echo "ID,Client,Téléphone,Email,Société,Magasin,Articles,Total,Statut,Dernière activité,Relance envoyée,WhatsApp,Coupon actif,Expiration coupon,Créé le\n";
        $stmt = $pdo->query("
            SELECT ac.id,
                   c.name AS client_name,
                   c.phone AS client_phone,
                   c.email AS client_email,
                   COALESCE(co.name,'') AS company_name,
                   COALESCE(ci.name,'') AS city_name,
                   ac.cart_payload,
                   ac.cart_total,
                   ac.status,
                   ac.last_activity_at,
                   ac.reminder_sent_at,
                   ac.whatsapp_link,
                   pc.code AS coupon_code,
                   pc.expires_at AS coupon_expires_at,
                   ac.created_at
            FROM abandoned_carts ac
            INNER JOIN clients c ON c.id = ac.client_id
            LEFT JOIN companies co ON co.id = ac.company_id
            LEFT JOIN cities ci ON ci.id = ac.city_id
            LEFT JOIN personalized_coupons pc
                ON pc.abandoned_cart_id = ac.id
               AND pc.status = 'active'
               AND (pc.expires_at IS NULL OR pc.expires_at >= NOW())
            ORDER BY ac.updated_at DESC, pc.id DESC
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $payload = json_decode((string)$row['cart_payload'], true);
            $items = is_array($payload)
                ? implode(' | ', array_map(fn($item) => ((int)($item['qty'] ?? 0)) . 'x ' . trim((string)($item['product_name'] ?? 'Produit')), $payload))
                : '';
            $csvRow = [
                $row['id'],
                $row['client_name'],
                $row['client_phone'],
                $row['client_email'],
                $row['company_name'],
                $row['city_name'],
                $items,
                $row['cart_total'],
                $row['status'],
                $row['last_activity_at'],
                $row['reminder_sent_at'],
                $row['whatsapp_link'],
                $row['coupon_code'],
                $row['coupon_expires_at'],
                $row['created_at'],
            ];
            echo implode(',', array_map(static fn($v) => '"' . str_replace('"', '""', (string)($v ?? '')) . '"', $csvRow)) . "\n";
        }
        exit;
    }
    if ($act === 'create_abandoned_cart_coupon') {
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT ac.*, c.name AS client_name, c.phone AS client_phone, c.email AS client_email
            FROM abandoned_carts ac
            INNER JOIN clients c ON c.id = ac.client_id
            WHERE ac.id=? LIMIT 1
        ");
        $stmt->execute([$id]);
        $cart = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$cart) {
            echo json_encode(['ok' => false, 'msg' => 'Panier introuvable']); exit;
        }
        $existing = $pdo->prepare("
            SELECT code, discount_percent, amount_off, min_amount, expires_at
            FROM personalized_coupons
            WHERE abandoned_cart_id=? AND status='active' AND (expires_at IS NULL OR expires_at >= NOW())
            ORDER BY id DESC LIMIT 1
        ");
        $existing->execute([$id]);
        $coupon = $existing->fetch(PDO::FETCH_ASSOC);
        if (!$coupon) {
            $cartTotal = (float)($cart['cart_total'] ?? 0);
            $discountPercent = $cartTotal >= 25000 ? 15 : ($cartTotal >= 10000 ? 12 : 10);
            $minAmount = $cartTotal > 0 ? round($cartTotal * 0.6, 2) : 0;
            $expiresAt = date('Y-m-d H:i:s', strtotime('+72 hours'));
            $code = generateRecoveryCouponCode($pdo);
            $pdo->prepare("
                INSERT INTO personalized_coupons(client_id, abandoned_cart_id, code, title, discount_percent, amount_off, min_amount, status, expires_at, sent_at)
                VALUES(?,?,?,?,?,?,?,?,?,NOW())
            ")->execute([
                (int)$cart['client_id'],
                $id,
                $code,
                'Coupon récupération panier',
                $discountPercent,
                null,
                $minAmount,
                'active',
                $expiresAt,
            ]);
            $coupon = [
                'code' => $code,
                'discount_percent' => $discountPercent,
                'amount_off' => null,
                'min_amount' => $minAmount,
                'expires_at' => $expiresAt,
            ];
        }
        $message = "Bonjour {$cart['client_name']}, voici votre coupon personnalisé {$coupon['code']} : -" . rtrim(rtrim(number_format((float)($coupon['discount_percent'] ?? 0), 2, '.', ''), '0'), '.') . "% sur votre prochaine commande";
        if ((float)($coupon['min_amount'] ?? 0) > 0) {
            $message .= " dès " . number_format((float)$coupon['min_amount'], 0, ',', ' ') . " CFA";
        }
        if (!empty($coupon['expires_at'])) {
            $message .= ", valable jusqu'au {$coupon['expires_at']}";
        }
        $message .= '.';
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= "From: ESPERANCE H2O <no-reply@" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ">\r\n";
        $emailSent = false;
        if (!empty($cart['client_email'])) {
            $emailSent = @mail((string)$cart['client_email'], 'Votre coupon personnalisé ESPERANCE H2O', $message, $headers);
        }
        try {
            $pdo->prepare("INSERT INTO notifications(client_id,title,message,type) VALUES(?,?,?,'promo')")
                ->execute([(int)$cart['client_id'], 'Coupon personnalisé', $message]);
        } catch (Throwable $e) {
        }
        logAction($pdo, $userId, 'create_abandoned_cart_coupon', "Cart: {$id} Code: {$coupon['code']}");
        echo json_encode([
            'ok' => true,
            'code' => $coupon['code'],
            'discount_percent' => $coupon['discount_percent'],
            'amount_off' => $coupon['amount_off'],
            'min_amount' => $coupon['min_amount'],
            'expires_at' => $coupon['expires_at'],
            'email_sent' => $emailSent,
        ]); exit;
    }
    if ($act === 'get_coupon_stats') {
        $stats = [
            'total_coupons' => 0,
            'active_coupons' => 0,
            'recovered_orders' => 0,
            'recovery_rate' => 0,
        ];
        $row = $pdo->query("
            SELECT COUNT(*) AS total_coupons,
                   SUM(CASE WHEN pc.status='active' AND (pc.expires_at IS NULL OR pc.expires_at >= NOW()) THEN 1 ELSE 0 END) AS active_coupons,
                   SUM(CASE WHEN ac.status='recovered' THEN 1 ELSE 0 END) AS recovered_orders
            FROM personalized_coupons pc
            LEFT JOIN abandoned_carts ac ON ac.id = pc.abandoned_cart_id
        ")->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $stats['total_coupons'] = (int)($row['total_coupons'] ?? 0);
            $stats['active_coupons'] = (int)($row['active_coupons'] ?? 0);
            $stats['recovered_orders'] = (int)($row['recovered_orders'] ?? 0);
            $stats['recovery_rate'] = $stats['total_coupons'] > 0
                ? round(($stats['recovered_orders'] / $stats['total_coupons']) * 100, 1)
                : 0;
        }
        echo json_encode($stats); exit;
    }
    if ($act === 'get_personalized_coupons') {
        $q = '%' . trim((string)($_GET['q'] ?? '')) . '%';
        $status = trim((string)($_GET['status'] ?? 'all'));
        $statusSql = $status !== 'all' ? " AND pc.status = " . $pdo->quote($status) : '';
        $stmt = $pdo->prepare("
            SELECT pc.*,
                   c.name AS client_name,
                   c.phone AS client_phone,
                   c.email AS client_email,
                   ac.status AS cart_status,
                   ac.cart_total,
                   ac.last_activity_at
            FROM personalized_coupons pc
            INNER JOIN clients c ON c.id = pc.client_id
            LEFT JOIN abandoned_carts ac ON ac.id = pc.abandoned_cart_id
            WHERE (
                pc.code LIKE ?
                OR COALESCE(pc.title,'') LIKE ?
                OR c.name LIKE ?
                OR COALESCE(c.phone,'') LIKE ?
                OR COALESCE(c.email,'') LIKE ?
            ) {$statusSql}
            ORDER BY pc.created_at DESC, pc.id DESC
            LIMIT 250
        ");
        $stmt->execute([$q,$q,$q,$q,$q]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['is_recovered'] = ((string)($row['cart_status'] ?? '') === 'recovered');
            $row['is_currently_active'] = ((string)($row['status'] ?? '') === 'active')
                && (empty($row['expires_at']) || strtotime((string)$row['expires_at']) >= time());
        }
        unset($row);
        echo json_encode($rows); exit;
    }
    if ($act === 'export_coupons_csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="coupons_personnalises_' . date('Y-m-d') . '.csv"');
        echo "\xEF\xBB\xBF";
        echo "ID,Code,Titre,Client,Téléphone,Email,Remise %,Montant remise,Minimum achat,Statut,Panier lié,Panier récupéré,Expiration,Créé le,Utilisé le\n";
        $stmt = $pdo->query("
            SELECT pc.id, pc.code, pc.title, c.name AS client_name, c.phone AS client_phone, c.email AS client_email,
                   pc.discount_percent, pc.amount_off, pc.min_amount, pc.status, pc.abandoned_cart_id,
                   ac.status AS cart_status, pc.expires_at, pc.created_at, pc.used_at
            FROM personalized_coupons pc
            INNER JOIN clients c ON c.id = pc.client_id
            LEFT JOIN abandoned_carts ac ON ac.id = pc.abandoned_cart_id
            ORDER BY pc.created_at DESC, pc.id DESC
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $csvRow = [
                $row['id'],
                $row['code'],
                $row['title'],
                $row['client_name'],
                $row['client_phone'],
                $row['client_email'],
                $row['discount_percent'],
                $row['amount_off'],
                $row['min_amount'],
                $row['status'],
                $row['abandoned_cart_id'],
                ((string)($row['cart_status'] ?? '') === 'recovered') ? 'Oui' : 'Non',
                $row['expires_at'],
                $row['created_at'],
                $row['used_at'],
            ];
            echo implode(',', array_map(static fn($v) => '"' . str_replace('"', '""', (string)($v ?? '')) . '"', $csvRow)) . "\n";
        }
        exit;
    }
    if ($act === 'toggle_personalized_coupon') {
        $id = (int)($_GET['id'] ?? 0);
        $status = trim((string)($_GET['status'] ?? 'active'));
        $allowed = ['active', 'expired'];
        if ($id <= 0 || !in_array($status, $allowed, true)) {
            echo json_encode(['ok' => false, 'msg' => 'Paramètres invalides']); exit;
        }
        $expiresAt = $status === 'expired' ? date('Y-m-d H:i:s') : date('Y-m-d H:i:s', strtotime('+72 hours'));
        $pdo->prepare("UPDATE personalized_coupons SET status=?, expires_at=? WHERE id=?")->execute([$status, $expiresAt, $id]);
        logAction($pdo, $userId, 'toggle_personalized_coupon', "Coupon: {$id} Status: {$status}");
        echo json_encode(['ok' => true]); exit;
    }

    /* ── Logs ── */
    if ($act === 'get_logs') {
        $st = $pdo->prepare("SELECT al.id,al.action,al.details,al.created_at,
            COALESCE(u.username,'?') username
            FROM action_logs al LEFT JOIN users u ON u.id=al.user_id
            ORDER BY al.created_at DESC LIMIT 200");
        $st->execute();
        echo json_encode($st->fetchAll(PDO::FETCH_ASSOC)); exit;
    }
    if ($act === 'clear_logs') {
        $pdo->exec("TRUNCATE TABLE action_logs");
        logAction($pdo,$userId,'clear_logs','Logs effacés');
        echo json_encode(['ok'=>true]); exit;
    }
    if ($act === 'export_logs_csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="logs_esperance_' . date('Y-m-d') . '.csv"');
        echo "\xEF\xBB\xBF"; // BOM UTF-8 pour Excel Windows
        echo "ID,Utilisateur,Action,Détails,Date\n";
        $st = $pdo->prepare("SELECT al.id,COALESCE(u.username,'?'),al.action,al.details,al.created_at
            FROM action_logs al LEFT JOIN users u ON u.id=al.user_id ORDER BY al.created_at DESC");
        $st->execute();
        while($r = $st->fetch(PDO::FETCH_NUM)) {
            echo implode(',', array_map(fn($v) => '"'.str_replace('"','""',$v ?? '').'"', $r)) . "\n";
        }
        exit;
    }
    if ($act === 'logs_chart') {
        /* Top users */
        $st = $pdo->prepare("SELECT COALESCE(u.username,'?') AS name, COUNT(*) AS cnt
            FROM action_logs al LEFT JOIN users u ON u.id=al.user_id
            GROUP BY al.user_id ORDER BY cnt DESC LIMIT 8");
        $st->execute();
        $top_users = $st->fetchAll(PDO::FETCH_ASSOC);
        /* Top actions */
        $st2 = $pdo->prepare("SELECT action,COUNT(*) cnt FROM action_logs GROUP BY action ORDER BY cnt DESC LIMIT 8");
        $st2->execute();
        $top_actions = $st2->fetchAll(PDO::FETCH_ASSOC);
        /* Timeline 7j */
        $st3 = $pdo->prepare("SELECT DATE(created_at) d, COUNT(*) cnt FROM action_logs
            WHERE created_at >= DATE_SUB(NOW(),INTERVAL 7 DAY) GROUP BY d ORDER BY d ASC");
        $st3->execute();
        $timeline = $st3->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['top_users'=>$top_users,'top_actions'=>$top_actions,'timeline'=>$timeline]); exit;
    }

    /* ── Database ── */
    if ($act === 'db_stats') {
        $st = $pdo->prepare("SELECT TABLE_NAME,TABLE_ROWS,
            ROUND((DATA_LENGTH+INDEX_LENGTH)/1024/1024,3) AS size_mb
            FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY size_mb DESC");
        $st->execute();
        $tables = $st->fetchAll(PDO::FETCH_ASSOC);
        $total  = array_sum(array_column($tables,'size_mb'));
        echo json_encode(['tables'=>$tables,'total_mb'=>round($total,2),'count'=>count($tables)]); exit;
    }
    if ($act === 'optimize_db') {
        $r = $pdo->query("SHOW TABLES");
        while($row=$r->fetch(PDO::FETCH_NUM)) $pdo->exec("OPTIMIZE TABLE `{$row[0]}`");
        logAction($pdo,$userId,'optimize_db','All tables optimized');
        echo json_encode(['ok'=>true]); exit;
    }

    /* ── Stock rapide (mon ajout) ── */
    if ($act === 'quick_stock') {
        $cid = (int)($_GET['company_id'] ?? 0);
        $vid = (int)($_GET['city_id'] ?? 0);
        if (!$cid || !$vid) { echo json_encode([]); exit; }
        $st = $pdo->prepare("SELECT p.id,p.name,p.category,p.alert_quantity,
            COALESCE(SUM(CASE WHEN sm.type='initial' THEN sm.quantity END),0)+
            COALESCE(SUM(CASE WHEN sm.type='entry'   THEN sm.quantity END),0)-
            COALESCE(SUM(CASE WHEN sm.type='exit'    THEN sm.quantity END),0)+
            COALESCE(SUM(CASE WHEN sm.type='adjustment' THEN sm.quantity END),0) AS stock
            FROM products p LEFT JOIN stock_movements sm ON sm.product_id=p.id
                AND sm.company_id=? AND sm.city_id=?
            WHERE p.company_id=? GROUP BY p.id ORDER BY p.category,p.name");
        $st->execute([$cid,$vid,$cid]);
        echo json_encode($st->fetchAll(PDO::FETCH_ASSOC)); exit;
    }

    /* ── Dashboard stats ── */
    if ($act === 'dashboard_stats') {
        $tables = ['users','companies','cities','clients','products','invoices','expenses','stock_movements'];
        $res = [];
        foreach($tables as $t) {
            try { $res[$t] = (int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn(); }
            catch(Exception $e) { $res[$t] = 0; }
        }
        try { $res['db_size'] = round($pdo->query("SELECT SUM(data_length+index_length)/1024/1024 FROM information_schema.TABLES WHERE table_schema=DATABASE()")->fetchColumn(),2); }
        catch(Exception $e){ $res['db_size']=0; }
        /* Revenus recents */
        try {
            $res['revenue_month'] = (float)$pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM invoices WHERE MONTH(created_at)=MONTH(NOW())")->fetchColumn();
        } catch(Exception $e){ $res['revenue_month']=0; }
        /* Appros en attente */
        try {
            $res['appro_pending'] = (int)$pdo->query("SELECT COUNT(*) FROM appro_requests WHERE status='en_attente'")->fetchColumn();
        } catch(Exception $e){ $res['appro_pending']=0; }
        echo json_encode($res); exit;
    }

    /* ── Notifications ── */
    if ($act === 'get_notif_count') {
        try {
            $cnt = (int)$pdo->query("SELECT COUNT(*) FROM admin_notifications WHERE is_read=0")->fetchColumn();
        } catch(Exception $e){ $cnt=0; }
        echo json_encode(['count'=>$cnt]); exit;
    }
    if ($act === 'get_attendance_settings') {
        echo json_encode(getAttendanceSettings($pdo)); exit;
    }
    if ($act === 'update_attendance_settings') {
        $d = json_decode(file_get_contents('php://input'), true);
        $workStart = preg_match('/^\d{2}:\d{2}$/', $d['work_start_time'] ?? '') ? $d['work_start_time'] . ':00' : '07:30:00';
        $workEnd = preg_match('/^\d{2}:\d{2}$/', $d['work_end_time'] ?? '') ? $d['work_end_time'] . ':00' : '18:30:00';
        $penalty = max(0, (float)($d['late_penalty_per_minute'] ?? 0));
        $latitude = (float)($d['office_latitude'] ?? 0);
        $longitude = (float)($d['office_longitude'] ?? 0);
        $radius = max(1, (int)($d['location_radius_meters'] ?? 1));
        $gpsIn = !empty($d['require_gps_check_in']) ? 1 : 0;
        $gpsOut = !empty($d['require_gps_check_out']) ? 1 : 0;

        $st = $pdo->prepare("
            UPDATE attendance_settings
            SET work_start_time=?, work_end_time=?, late_penalty_per_minute=?, office_latitude=?, office_longitude=?, location_radius_meters=?, require_gps_check_in=?, require_gps_check_out=?
            WHERE id=1
        ");
        $st->execute([$workStart, $workEnd, $penalty, $latitude, $longitude, $radius, $gpsIn, $gpsOut]);
        logAction($pdo,$userId,'update_attendance_settings',"Start: {$workStart} End: {$workEnd} GPS-IN: {$gpsIn} GPS-OUT: {$gpsOut}");
        echo json_encode(['ok'=>true]); exit;
    }

    echo json_encode(['error'=>'unknown action']); exit;
}

/* ═════════════════════════════════════
   BACKUP HANDLER (POST)
═════════════════════════════════════ */
if (isset($_POST['backup_db'])) {
    if (!is_dir(APP_ROOT . '/backups')) mkdir(APP_ROOT . '/backups',0777,true);
    $fn = 'backup_'.date('Y-m-d_H-i-s').'.sql';
    $out = "-- ESPERANCE H2O BACKUP ".date('Y-m-d H:i:s')."\n\n";
    $tables_r = $pdo->query("SHOW TABLES");
    while($row=$tables_r->fetch(PDO::FETCH_NUM)){
        $t=$row[0];
        $out.="DROP TABLE IF EXISTS `$t`;\n";
        $out.=$pdo->query("SHOW CREATE TABLE $t")->fetch(PDO::FETCH_NUM)[1].";\n\n";
        $rows=$pdo->query("SELECT * FROM $t");
        $nc=$rows->columnCount();
        while($r=$rows->fetch(PDO::FETCH_NUM)){
            $out.="INSERT INTO `$t` VALUES(".implode(',',array_map(fn($v)=>$pdo->quote($v??''),$r)).");\n";
        }
        $out.="\n";
    }
    logAction($pdo,$userId,'database_backup',"File: $fn");
    header('Content-Type: application/octet-stream');
    header("Content-Disposition: attachment; filename=\"$fn\"");
    echo $out; exit;
}

/* ─── Stats globales (chargement initial) ─── */
$stats = [];
foreach (['users','companies','cities','products','invoices','clients','expenses','stock_movements'] as $t) {
    try { $stats[$t] = (int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn(); }
    catch(Exception $e){ $stats[$t]=0; }
}
try { $stats['db_size']=round($pdo->query("SELECT SUM(data_length+index_length)/1024/1024 FROM information_schema.TABLES WHERE table_schema=DATABASE()")->fetchColumn(),2); }
catch(Exception $e){ $stats['db_size']=0; }
try { $stats['appro_pending']=(int)$pdo->query("SELECT COUNT(*) FROM appro_requests WHERE status='en_attente'")->fetchColumn(); }
catch(Exception $e){ $stats['appro_pending']=0; }

$notif_count=0;
try { $notif_count=(int)$pdo->query("SELECT COUNT(*) FROM admin_notifications WHERE is_read=0")->fetchColumn(); }
catch(Exception $e){}

$companies_list=$pdo->query("SELECT id,name FROM companies ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$attendance_settings = getAttendanceSettings($pdo);
$google_maps_embed_key = 'AIzaSyBFw0Qbyq9zTFTd-tUY6dZWTgaQzuU17R8';
$module = $_GET['module'] ?? 'dashboard';
$user_name = $_SESSION['username'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>Administration — ESPERANCE H2O</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=Source+Serif+4:wght@700;900&family=DM+Serif+Display&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
@font-face{font-family:'C059';src:local('C059-Bold'),local('C059 Bold'),local('Century Schoolbook');font-weight:700 900;}
:root{
    --bg:#0f1726;--surf:#162033;--card:#1b263b;--card2:#22324a;--card3:#162638;
    --bord:rgba(148,163,184,0.18);--bord2:rgba(50,190,143,0.28);
    --neon:#00a86b;--neon2:#00c87a;
    --red:#e53935;--orange:#f57c00;--blue:#1976d2;--gold:#f9a825;
    --purple:#a855f7;--cyan:#06b6d4;--pink:#ec4899;
    --text:#e8eef8;--text2:#bfd0e4;--muted:#8ea3bd;
    --glow:0 8px 24px rgba(0,168,107,0.18);
    --glow-r:0 8px 24px rgba(229,57,53,0.18);
    --glow-c:0 0 26px rgba(6,182,212,0.45);
    --glow-gold:0 8px 24px rgba(249,168,37,0.18);
    --fh:'C059','Source Serif 4','DM Serif Display','Playfair Display',Georgia,serif;
    --fb:'Inter','Segoe UI',system-ui,sans-serif;
}
.map-picker{width:100%;height:360px;border-radius:16px;overflow:hidden;border:1.5px solid var(--bord);background:rgba(0,0,0,0.25);}
.map-picker-help{margin-top:10px;font-size:12px;color:var(--muted);line-height:1.7;}
.map-picker-preview{width:100%;height:220px;border:0;border-radius:14px;margin-top:14px;background:rgba(0,0,0,0.2);}
.product-image-preview{
    width:100%;height:170px;border-radius:14px;border:1.5px dashed var(--bord);
    background:linear-gradient(135deg,rgba(50,190,143,0.06),rgba(61,140,255,0.05));
    display:flex;align-items:center;justify-content:center;overflow:hidden;color:var(--muted);
}
.product-image-preview img{width:100%;height:100%;object-fit:cover;display:block;}
.product-image-preview.empty span{font-family:var(--fh);font-size:12px;font-weight:900;letter-spacing:.4px;text-align:center;padding:16px;}
.product-thumb{
    width:48px;height:48px;border-radius:12px;overflow:hidden;flex-shrink:0;
    background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);
    display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:18px;
}
.product-thumb img{width:100%;height:100%;object-fit:cover;display:block;}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
html{scroll-behavior:smooth;}
body{font-family:var(--fb);font-size:15px;line-height:1.7;background:var(--bg);color:var(--text);min-height:100vh;overflow-x:hidden;}

/* GRID BG */
body::before{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;
    background:radial-gradient(ellipse 65% 42% at 4% 8%,rgba(50,190,143,0.07) 0%,transparent 62%),
    radial-gradient(ellipse 52% 36% at 96% 88%,rgba(61,140,255,0.06) 0%,transparent 62%),
    radial-gradient(ellipse 40% 30% at 50% 50%,rgba(168,85,247,0.03) 0%,transparent 70%);}
body::after{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;
    background-image:linear-gradient(rgba(50,190,143,0.018) 1px,transparent 1px),linear-gradient(90deg,rgba(50,190,143,0.018) 1px,transparent 1px);
    background-size:48px 48px;}
.wrap{position:relative;z-index:1;max-width:1600px;margin:0 auto;padding:14px 16px 56px;}

/* ══════ TOPBAR ══════ */
.topbar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;
    background:rgba(22,32,51,0.96);border:1px solid var(--bord);border-radius:18px;
    padding:16px 26px;margin-bottom:14px;backdrop-filter:blur(28px);
    box-shadow:0 4px 32px rgba(0,0,0,0.4);}
.brand{display:flex;align-items:center;gap:14px;flex-shrink:0;}
.brand-ico{width:48px;height:48px;background:linear-gradient(135deg,var(--purple),var(--blue));
    border-radius:14px;display:flex;align-items:center;justify-content:center;
    font-size:22px;color:#fff;box-shadow:0 0 28px rgba(168,85,247,0.55);animation:breathe 3.5s ease-in-out infinite;flex-shrink:0;}
@keyframes breathe{0%,100%{box-shadow:0 0 16px rgba(168,85,247,0.4);}50%{box-shadow:0 0 42px rgba(168,85,247,0.9);}}
.brand-txt h1{font-family:var(--fh);font-size:21px;font-weight:900;color:var(--text);letter-spacing:0.4px;line-height:1.2;}
.brand-txt p{font-size:11px;font-weight:700;color:var(--purple);letter-spacing:2.8px;text-transform:uppercase;margin-top:2px;}
.clock-d{font-family:var(--fh);font-size:28px;font-weight:900;color:var(--gold);letter-spacing:5px;text-shadow:0 0 24px rgba(255,208,96,0.6);line-height:1;}
.clock-sub{font-size:10px;color:var(--muted);letter-spacing:1.5px;text-transform:uppercase;margin-top:4px;}
.user-badge{display:flex;align-items:center;gap:10px;background:linear-gradient(135deg,var(--purple),var(--blue));
    color:#fff;padding:10px 20px;border-radius:32px;font-family:var(--fh);font-size:13px;font-weight:900;
    box-shadow:0 0 22px rgba(168,85,247,0.4);flex-shrink:0;cursor:pointer;text-decoration:none;}

/* ══════ CONTEXT BAR (Société / Ville) ══════ */
.ctx-bar{display:flex;align-items:center;flex-wrap:wrap;gap:10px;
    background:rgba(27,38,59,0.88);border:1px solid var(--bord);border-radius:14px;
    padding:12px 20px;margin-bottom:12px;backdrop-filter:blur(20px);}
.ctx-label{font-family:var(--fh);font-size:11px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:1.2px;flex-shrink:0;}
.ctx-select{padding:9px 14px;background:rgba(15,23,38,0.76);border:1.5px solid var(--bord);border-radius:10px;
    color:var(--text);font-family:var(--fb);font-size:13px;font-weight:600;min-width:180px;
    appearance:none;-webkit-appearance:none;cursor:pointer;transition:all 0.28s;}
.ctx-select:focus{outline:none;border-color:var(--purple);box-shadow:0 0 16px rgba(168,85,247,0.3);}
.ctx-select option{background:#1b263b;color:var(--text);}
.ctx-info{font-family:var(--fb);font-size:12px;color:var(--muted);margin-left:auto;display:flex;align-items:center;gap:8px;}
.ctx-dot{width:7px;height:7px;border-radius:50%;background:var(--neon);box-shadow:0 0 8px var(--neon);animation:pdot 2s infinite;}
@keyframes pdot{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.4;transform:scale(.7)}}
@keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.35;transform:scale(.7)}}

/* ══════ TAB NAV ══════ */
.tab-nav{display:flex;align-items:center;flex-wrap:wrap;gap:6px;
    background:rgba(27,38,59,0.88);border:1px solid var(--bord);border-radius:16px;
    padding:12px 18px;margin-bottom:18px;backdrop-filter:blur(20px);}
.tab-nav,.tab-nav *{position:relative;z-index:5;}
.tab-nav-title{font-family:var(--fh);font-size:11px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:1.2px;margin-right:6px;flex-shrink:0;}
.tn{display:flex;align-items:center;gap:7px;padding:9px 16px;border-radius:11px;
    border:1.5px solid var(--bord);background:rgba(168,85,247,0.05);color:var(--text2);
    font-family:var(--fh);font-size:12px;font-weight:900;text-decoration:none;white-space:nowrap;
    letter-spacing:0.3px;transition:all 0.28s cubic-bezier(0.23,1,0.32,1);cursor:pointer;}
.tn:hover{background:rgba(168,85,247,0.15);color:var(--text);border-color:rgba(168,85,247,0.35);transform:translateY(-2px);}
.tn.active{background:linear-gradient(135deg,rgba(168,85,247,0.25),rgba(61,140,255,0.2));
    color:#fff;border-color:rgba(168,85,247,0.5);box-shadow:0 0 18px rgba(168,85,247,0.25);}
.tn .tb{display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;
    border-radius:50%;font-size:10px;font-weight:900;background:var(--red);color:#fff;margin-left:2px;
    animation:pulse-r 1.5s infinite;}
@keyframes pulse-r{0%,100%{box-shadow:0 0 0 0 rgba(255,53,83,0.4);}50%{box-shadow:0 0 0 5px transparent;}}

/* ══════ PANEL ══════ */
.panel{background:var(--card);border:1px solid var(--bord);border-radius:18px;overflow:hidden;margin-bottom:18px;
    animation:fadeUp .4s ease backwards;transition:border-color 0.3s;}
.panel:hover{border-color:var(--bord2);}
@keyframes fadeUp{from{opacity:0;transform:translateY(22px)}to{opacity:1;transform:translateY(0)}}
.ph{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;
    padding:16px 22px;border-bottom:1px solid rgba(255,255,255,0.05);background:rgba(0,0,0,0.2);}
.ph-title{font-family:var(--fh);font-size:15px;font-weight:900;color:var(--text);display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;animation:pdot 2.2s infinite;}
.dot.p{background:var(--purple);box-shadow:0 0 9px var(--purple);}
.dot.c{background:var(--cyan);box-shadow:0 0 9px var(--cyan);}
.dot.n{background:var(--neon);box-shadow:0 0 9px var(--neon);}
.dot.r{background:var(--red);box-shadow:0 0 9px var(--red);}
.dot.g{background:var(--gold);box-shadow:0 0 9px var(--gold);}
.dot.b{background:var(--blue);box-shadow:0 0 9px var(--blue);}
.dot.o{background:var(--orange);box-shadow:0 0 9px var(--orange);}
.pbadge{font-family:var(--fb);font-size:11px;font-weight:800;padding:4px 12px;border-radius:18px;white-space:nowrap;}
.pbadge.p{background:rgba(168,85,247,0.12);color:var(--purple);}
.pbadge.c{background:rgba(6,182,212,0.12);color:var(--cyan);}
.pbadge.n{background:rgba(50,190,143,0.12);color:var(--neon);}
.pbadge.r{background:rgba(255,53,83,0.12);color:var(--red);}
.pbadge.g{background:rgba(255,208,96,0.12);color:var(--gold);}
.pbadge.o{background:rgba(255,145,64,0.12);color:var(--orange);}
.pb{padding:18px 22px;}

/* ══════ KPI STRIP ══════ */
.kpi-strip{display:grid;grid-template-columns:repeat(auto-fill,minmax(165px,1fr));gap:13px;margin-bottom:18px;}
.ks{background:var(--card);border:1px solid var(--bord);border-radius:16px;padding:18px 16px;
    display:flex;align-items:center;gap:13px;transition:all 0.3s;cursor:default;}
.ks:hover{transform:translateY(-4px);box-shadow:0 14px 34px rgba(0,0,0,0.42);border-color:var(--bord2);}
.ks-ico{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;}
.ks-val{font-family:var(--fh);font-size:25px;font-weight:900;line-height:1;}
.ks-lbl{font-family:var(--fb);font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-top:4px;}

/* ══════ TABLE ══════ */
.tbl-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;}
.tbl{width:100%;border-collapse:collapse;min-width:560px;}
.tbl th{font-family:var(--fh);font-size:11px;font-weight:900;color:var(--muted);text-transform:uppercase;
    letter-spacing:1.2px;padding:12px 14px;border-bottom:1px solid rgba(255,255,255,0.06);text-align:left;
    background:rgba(0,0,0,0.16);white-space:nowrap;}
.tbl td{font-family:var(--fb);font-size:13px;font-weight:500;color:var(--text2);padding:12px 14px;
    border-bottom:1px solid rgba(255,255,255,0.04);line-height:1.5;vertical-align:middle;}
.tbl tr:last-child td{border-bottom:none;}
.tbl tbody tr{transition:all 0.22s;}
.tbl tbody tr:hover{background:rgba(168,85,247,0.04);}
.tbl td strong{font-family:var(--fh);font-weight:900;color:var(--text);}

/* ══════ FORM ══════ */
.fg{margin-bottom:16px;}
.fg label{font-family:var(--fh);font-size:11px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:1.2px;display:block;margin-bottom:6px;}
.fg input,.fg select,.fg textarea{width:100%;padding:11px 15px;background:rgba(0,0,0,0.35);border:1.5px solid var(--bord);
    border-radius:11px;color:var(--text);font-family:var(--fb);font-size:14px;font-weight:600;
    transition:all 0.28s;appearance:none;-webkit-appearance:none;}
.fg input:focus,.fg select:focus,.fg textarea:focus{outline:none;border-color:var(--purple);box-shadow:0 0 18px rgba(168,85,247,0.22);background:rgba(168,85,247,0.04);}
.fg select option{background:#1b263b;color:var(--text);}
.fg textarea{resize:vertical;min-height:80px;}
.required-star{color:var(--red);margin-left:3px;}
.fg-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.fg-row3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;}
.forced-indicator{background:rgba(255,208,96,0.1);border:1px solid rgba(255,208,96,0.25);border-radius:10px;
    padding:10px 14px;font-family:var(--fb);font-size:12px;color:var(--gold);margin-bottom:14px;display:flex;align-items:center;gap:10px;}

/* ══════ BTN ══════ */
.btn{display:inline-flex;align-items:center;gap:7px;padding:10px 20px;border-radius:11px;
    border:none;cursor:pointer;font-family:var(--fh);font-size:12px;font-weight:900;
    letter-spacing:0.3px;transition:all 0.26s;text-decoration:none;white-space:nowrap;}
.btn:active{transform:scale(0.97);}
.btn-p{background:rgba(168,85,247,0.14);border:1.5px solid rgba(168,85,247,0.32);color:var(--purple);}
.btn-p:hover{background:var(--purple);color:#fff;box-shadow:0 0 22px rgba(168,85,247,0.45);}
.btn-n{background:rgba(50,190,143,0.12);border:1.5px solid rgba(50,190,143,0.3);color:var(--neon);}
.btn-n:hover{background:var(--neon);color:var(--bg);box-shadow:var(--glow);}
.btn-c{background:rgba(6,182,212,0.12);border:1.5px solid rgba(6,182,212,0.3);color:var(--cyan);}
.btn-c:hover{background:var(--cyan);color:var(--bg);box-shadow:var(--glow-c);}
.btn-g{background:rgba(255,208,96,0.12);border:1.5px solid rgba(255,208,96,0.3);color:var(--gold);}
.btn-g:hover{background:var(--gold);color:var(--bg);box-shadow:var(--glow-gold);}
.btn-r{background:rgba(255,53,83,0.12);border:1.5px solid rgba(255,53,83,0.3);color:var(--red);}
.btn-r:hover{background:var(--red);color:#fff;box-shadow:var(--glow-r);}
.btn-b{background:rgba(61,140,255,0.12);border:1.5px solid rgba(61,140,255,0.3);color:var(--blue);}
.btn-b:hover{background:var(--blue);color:#fff;}
.btn-o{background:rgba(255,145,64,0.12);border:1.5px solid rgba(255,145,64,0.3);color:var(--orange);}
.btn-o:hover{background:var(--orange);color:#fff;}
.btn-sm{padding:6px 13px;font-size:11px;border-radius:8px;}
.btn-xs{padding:4px 9px;font-size:10px;border-radius:7px;}
.btn-full{width:100%;justify-content:center;padding:13px;font-size:14px;border-radius:12px;}

/* ══════ SEARCH ══════ */
.search-row{display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap;}
.s-input{flex:1;min-width:200px;padding:10px 16px;background:rgba(15,23,38,0.72);border:1.5px solid var(--bord);
    border-radius:11px;color:var(--text);font-family:var(--fb);font-size:13px;font-weight:600;transition:all 0.28s;}
.s-input:focus{outline:none;border-color:var(--purple);box-shadow:0 0 16px rgba(168,85,247,0.22);}
.s-input::placeholder{color:var(--muted);}

/* ══════ MODAL ══════ */
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.8);z-index:2000;
    align-items:center;justify-content:center;backdrop-filter:blur(6px);padding:16px;}
.modal.show{display:flex;}
.modal-box{background:var(--card);border:1px solid var(--bord2);border-radius:20px;
    padding:28px;max-width:560px;width:100%;max-height:90vh;overflow-y:auto;
    animation:mzoom .28s cubic-bezier(0.23,1,0.32,1);}
@keyframes mzoom{from{opacity:0;transform:scale(0.88)}to{opacity:1;transform:scale(1)}}
.modal-box h2{font-family:var(--fh);font-size:19px;font-weight:900;color:var(--text);margin-bottom:22px;line-height:1.3;display:flex;align-items:center;gap:10px;}
.modal-btns{display:flex;gap:10px;margin-top:18px;flex-wrap:wrap;}
.modal-btns>*{flex:1;justify-content:center;}

/* ══════ TOAST ══════ */
.toast-stack{position:fixed;bottom:22px;right:22px;z-index:9999;display:flex;flex-direction:column;gap:8px;align-items:flex-end;}
.toast{background:var(--card2);border:1px solid rgba(168,85,247,0.28);border-radius:13px;
    padding:13px 18px;min-width:260px;max-width:360px;display:flex;align-items:center;gap:12px;
    box-shadow:0 8px 30px rgba(0,0,0,0.55);animation:toast-in .4s cubic-bezier(0.23,1,0.32,1) forwards;}
.toast.out{animation:toast-out .3s ease forwards;}
@keyframes toast-in{from{opacity:0;transform:translateX(50px)}to{opacity:1;transform:translateX(0)}}
@keyframes toast-out{from{opacity:1;transform:translateX(0)}to{opacity:0;transform:translateX(50px)}}
.toast-ico{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;}
.toast-txt strong{font-family:var(--fh);font-size:12px;font-weight:900;display:block;}
.toast-txt span{font-family:var(--fb);font-size:11px;color:var(--muted);}

/* ══════ CHART ══════ */
.chart-box{position:relative;width:100%;height:220px;}

/* ══════ BADGE ROLE ══════ */
.role-badge{display:inline-flex;align-items:center;gap:4px;font-family:var(--fb);font-size:10px;font-weight:800;padding:3px 10px;border-radius:16px;}
.rb-dev{background:rgba(168,85,247,0.15);color:var(--purple);border:1px solid rgba(168,85,247,0.3);}
.rb-admin{background:rgba(50,190,143,0.15);color:var(--neon);border:1px solid rgba(50,190,143,0.3);}
.rb-user{background:rgba(6,182,212,0.12);color:var(--cyan);border:1px solid rgba(6,182,212,0.25);}

/* ══════ STOCK INDICATOR ══════ */
.stock-ok {color:var(--neon);font-weight:900;font-family:var(--fh);}
.stock-low{color:var(--orange);font-weight:900;font-family:var(--fh);animation:blink-o 1.5s ease infinite;}
.stock-out{color:var(--red);font-weight:900;font-family:var(--fh);animation:blink-r 1s ease infinite;}
@keyframes blink-o{0%,100%{opacity:1}50%{opacity:.4}}
@keyframes blink-r{0%,100%{opacity:1}50%{opacity:.3}}
.stock-bar{width:70px;height:6px;background:rgba(255,255,255,0.06);border-radius:3px;overflow:hidden;display:inline-block;vertical-align:middle;margin-left:6px;}
.stock-bar-fill{height:100%;border-radius:3px;transition:width 0.6s ease;}

/* ══════ CONFIRM OVERLAY ══════ */
.confirm-overlay{display:none;position:fixed;inset:0;z-index:3000;align-items:center;justify-content:center;background:rgba(0,0,0,0.85);backdrop-filter:blur(8px);}
.confirm-overlay.show{display:flex;}
.confirm-box{background:var(--card);border:1px solid rgba(255,53,83,0.3);border-radius:18px;padding:28px;max-width:380px;width:92%;text-align:center;animation:mzoom .25s ease;}
.confirm-box h3{font-family:var(--fh);font-size:18px;font-weight:900;color:var(--red);margin-bottom:10px;}
.confirm-box p{font-family:var(--fb);font-size:13px;color:var(--text2);margin-bottom:22px;line-height:1.6;}

/* DB SIZE BAR */
.db-bar{width:100%;height:8px;background:rgba(255,255,255,0.05);border-radius:4px;overflow:hidden;margin-top:6px;}
.db-bar-fill{height:100%;border-radius:4px;background:linear-gradient(90deg,var(--purple),var(--blue));}

/* QUICK ACTION CARDS */
.qa-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:18px;}
.qa-card{background:var(--card2);border:1px solid var(--bord);border-radius:14px;padding:16px 18px;
    display:flex;align-items:center;gap:13px;text-decoration:none;transition:all 0.28s;cursor:pointer;}
.qa-card:hover{transform:translateY(-3px);box-shadow:0 12px 28px rgba(0,0,0,0.4);border-color:var(--bord2);}
.qa-ico{width:40px;height:40px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;}
.qa-txt strong{font-family:var(--fh);font-size:13px;font-weight:900;color:var(--text);display:block;}
.qa-txt span{font-family:var(--fb);font-size:11px;color:var(--muted);}
.split-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:18px;}
.split-grid-2-tight{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;}
.split-grid-main-side{display:grid;grid-template-columns:1.2fr .8fr;gap:16px;margin-bottom:16px;}
.topbar-clock-wrap{text-align:center;flex-shrink:0;}
.topbar-actions{display:flex;align-items:center;gap:10px;flex-shrink:0;flex-wrap:wrap;justify-content:flex-end;}
.btn-row{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;}

/* EMPTY STATE */
.empty-state{text-align:center;padding:44px 24px;color:var(--muted);}
.empty-state i{font-size:48px;display:block;margin-bottom:14px;opacity:.12;}
.empty-state h3{font-family:var(--fh);font-size:17px;font-weight:900;margin-bottom:6px;color:var(--text2);}

/* RESPONSIVE */
@media(max-width:1100px){.kpi-strip{grid-template-columns:repeat(3,1fr);}.fg-row3{grid-template-columns:1fr 1fr;}}
@media(max-width:768px){
    .wrap{padding:10px;}
    .topbar,.ctx-bar,.ph,.pb,.modal-box{padding-left:14px;padding-right:14px;}
    .topbar,.ctx-bar{border-radius:16px;}
    .topbar,.topbar-actions{justify-content:center;}
    .topbar-clock-wrap,.topbar-actions,.brand,.user-badge,.ctx-select,.ctx-info{width:100%;}
    .ctx-select{min-width:0;}
    .ctx-info{margin-left:0;justify-content:center;text-align:center;}
    .kpi-strip{grid-template-columns:repeat(2,1fr);gap:10px;}
    .tab-nav{gap:8px;flex-wrap:nowrap;overflow-x:auto;padding:10px 12px;-webkit-overflow-scrolling:touch;scrollbar-width:none;}
    .tab-nav::-webkit-scrollbar{display:none;}
    .tab-nav-title{display:none;}
    .tn{padding:8px 12px;font-size:11px;flex:0 0 auto;}
    .fg-row,.fg-row3,.split-grid-2,.split-grid-2-tight,.split-grid-main-side{grid-template-columns:1fr;}
    .modal-box{padding-top:22px;padding-bottom:22px;max-height:88vh;}
    .modal-btns>*{flex:1 1 100%;}
    .search-row{flex-direction:column;}
    .s-input{min-width:0;width:100%;}
    .clock-d{font-size:22px;letter-spacing:3px;}
}
@media(max-width:480px){
    .wrap{padding:8px;}
    .kpi-strip{grid-template-columns:1fr;}
    .topbar{padding:12px 14px;}
    .brand-txt h1{font-size:17px;}
    .brand-txt p{letter-spacing:1.8px;}
    .brand-ico{width:42px;height:42px;font-size:18px;}
    .ks{padding:15px 14px;}
    .tbl{min-width:520px;}
    .map-picker{height:260px;}
    .map-picker-preview{height:180px;}
    .btn,.user-badge{width:100%;justify-content:center;}
    .btn-row{flex-direction:column;}
    .toast-stack{left:10px;right:10px;bottom:12px;align-items:stretch;}
    .toast{min-width:0;max-width:none;width:100%;}
}
</style>
</head>
<body>
<div class="wrap">

<!-- ══════ TOPBAR ══════ -->
<div class="topbar">
    <div class="brand">
        <div class="brand-ico"><i class="fas fa-rocket"></i></div>
        <div class="brand-txt">
            <h1>Administration</h1>
            <p>ESPERANCE H2O &nbsp;·&nbsp; Centre de contrôle</p>
        </div>
    </div>
    <div class="topbar-clock-wrap">
        <div class="clock-d" id="clk">--:--:--</div>
        <div class="clock-sub" id="clkd">Chargement…</div>
    </div>
    <div class="topbar-actions">
        <?php if($stats['appro_pending']>0): ?>
        <a href="<?= project_url('stock/appro_requests.php') ?>" style="display:inline-flex;align-items:center;gap:8px;background:rgba(255,53,83,0.14);border:1.5px solid rgba(255,53,83,0.3);
            color:var(--red);padding:8px 16px;border-radius:24px;font-family:var(--fh);font-size:12px;font-weight:900;text-decoration:none;
            animation:pulse-r 1.5s infinite;">
            <i class="fas fa-bell"></i> <?= $stats['appro_pending'] ?> appro en attente
        </a>
        <?php endif; ?>
        <a href="<?= project_url('admin/admin_notifications.php') ?>" class="user-badge" style="padding:8px 16px;font-size:12px">
            <i class="fas fa-bell"></i>
            Notifications
            <?php if($notif_count>0): ?>
            <span style="background:var(--red);color:#fff;border-radius:50%;width:18px;height:18px;display:inline-flex;align-items:center;justify-content:center;font-size:10px"><?= min($notif_count,99) ?></span>
            <?php endif; ?>
        </a>
        <a href="<?= project_url('admin/app_alert_history.php') ?>" class="user-badge" style="padding:8px 16px;font-size:12px">
            <i class="fas fa-timeline"></i>
            Historique Notifs
        </a>
        <div class="user-badge" onclick="showUserMenu()">
            <i class="fas fa-user-shield"></i>
            <?= htmlspecialchars($user_name) ?>
            <span style="font-size:10px;background:rgba(255,255,255,0.14);padding:2px 7px;border-radius:9px"><?= strtoupper($userRole) ?></span>
        </div>
    </div>
</div>

<!-- ══════ CONTEXT BAR — Société + Ville ══════ -->
<div class="ctx-bar">
    <span class="ctx-label"><i class="fas fa-building"></i> Contexte :</span>
    <select class="ctx-select" id="global-company" onchange="onGlobalCompanyChange()">
        <option value="">— Toutes les sociétés —</option>
        <?php foreach($companies_list as $c): ?>
        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <select class="ctx-select" id="global-city" onchange="onGlobalCityChange()" disabled>
        <option value="">— Toutes les magasins —</option>
    </select>
    <div class="ctx-info">
        <div class="ctx-dot"></div>
        <span id="ctx-status-txt" style="font-family:var(--fh);font-size:11px;font-weight:900;color:var(--neon)">Contexte global</span>
    </div>
    <div style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap">
        <a href="<?= project_url('dashboard/index.php') ?>" class="btn btn-b btn-sm"><i class="fas fa-home"></i> Site</a>
        <a href="<?= project_url('auth/logout.php') ?>" class="btn btn-r btn-sm"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
</div>

<!-- ══════ TAB NAV ══════ -->
<div class="tab-nav">
    <span class="tab-nav-title"><i class="fas fa-th"></i></span>
    <a href="?module=dashboard" class="tn <?= $module==='dashboard'?'active':'' ?>"><i class="fas fa-chart-line"></i> Dashboard</a>
    <a href="?module=users"     class="tn <?= $module==='users'?'active':'' ?>"><i class="fas fa-users"></i> Utilisateurs</a>
    <a href="?module=companies" class="tn <?= $module==='companies'?'active':'' ?>"><i class="fas fa-building"></i> Sociétés</a>
    <a href="?module=cities"    class="tn <?= $module==='cities'?'active':'' ?>"><i class="fas fa-city"></i> Magasins</a>
    <a href="?module=products"  class="tn <?= $module==='products'?'active':'' ?>"><i class="fas fa-box"></i> Produits</a>
    <a href="?module=promotions" class="tn <?= $module==='promotions'?'active':'' ?>"><i class="fas fa-tags"></i> Promotions</a>
    <a href="?module=loyalty" class="tn <?= $module==='loyalty'?'active':'' ?>"><i class="fas fa-gem"></i> Fidélité CRM</a>
    <a href="?module=stock"     class="tn <?= $module==='stock'?'active':'' ?>"><i class="fas fa-warehouse"></i> Stock rapide</a>
    <a href="?module=logs"      class="tn <?= $module==='logs'?'active':'' ?>"><i class="fas fa-history"></i> Logs</a>
    <a href="?module=hr_settings" class="tn <?= $module==='hr_settings'?'active':'' ?>"><i class="fas fa-user-clock"></i> RH</a>
    <a href="?module=database"  class="tn <?= $module==='database'?'active':'' ?>"><i class="fas fa-database"></i> Database</a>
    <!-- Liens rapides -->
    <div style="margin-left:auto;display:flex;gap:6px;flex-wrap:wrap">
        <a href="<?= project_url('stock/appro_requests.php') ?>"         class="tn" style="border-color:rgba(255,145,64,0.3);color:var(--orange)"><i class="fas fa-truck-loading"></i> Appro</a>
        <a href="<?= project_url('admin/app_alert_history.php') ?>"      class="tn" style="border-color:rgba(124,58,237,0.3);color:var(--purple)"><i class="fas fa-timeline"></i> Histo Notifs</a>
        <a href="<?= project_url('hr/employees_manager.php') ?>"      class="tn" style="border-color:rgba(6,182,212,0.3);color:var(--cyan)"><i class="fas fa-user-tie"></i> Employés</a>
        <a href="<?= project_url('documents/documents_erp_pro.php') ?>"      class="tn" style="border-color:rgba(50,190,143,0.3);color:var(--neon)"><i class="fas fa-archive"></i> Archives</a>
        <a href="<?= project_url('clients/clients_erp_pro.php') ?>"        class="tn" style="border-color:rgba(61,140,255,0.3);color:var(--blue)"><i class="fas fa-users-cog"></i> Clients</a>

    </div>
</div>

<!-- ═══════════════════════════════════════════
     MODULE: DASHBOARD
═══════════════════════════════════════════ -->
<?php if($module==='dashboard'): ?>

<!-- KPI -->
<div class="kpi-strip">
    <div class="ks"><div class="ks-ico" style="background:rgba(168,85,247,0.16);color:var(--purple)"><i class="fas fa-users"></i></div>
        <div><div class="ks-val" style="color:var(--purple)" id="kpi-users"><?= $stats['users'] ?></div><div class="ks-lbl">Utilisateurs</div></div></div>
    <div class="ks"><div class="ks-ico" style="background:rgba(6,182,212,0.16);color:var(--cyan)"><i class="fas fa-building"></i></div>
        <div><div class="ks-val" style="color:var(--cyan)"><?= $stats['companies'] ?></div><div class="ks-lbl">Sociétés</div></div></div>
    <div class="ks"><div class="ks-ico" style="background:rgba(50,190,143,0.16);color:var(--neon)"><i class="fas fa-box"></i></div>
        <div><div class="ks-val" style="color:var(--neon)"><?= $stats['products'] ?></div><div class="ks-lbl">Produits</div></div></div>
    <div class="ks"><div class="ks-ico" style="background:rgba(255,208,96,0.16);color:var(--gold)"><i class="fas fa-file-invoice"></i></div>
        <div><div class="ks-val" style="color:var(--gold)"><?= $stats['invoices'] ?></div><div class="ks-lbl">Factures</div></div></div>
    <div class="ks"><div class="ks-ico" style="background:rgba(61,140,255,0.16);color:var(--blue)"><i class="fas fa-users-cog"></i></div>
        <div><div class="ks-val" style="color:var(--blue)"><?= $stats['clients'] ?></div><div class="ks-lbl">Clients</div></div></div>
    <div class="ks"><div class="ks-ico" style="background:rgba(255,53,83,0.16);color:var(--red)"><i class="fas fa-truck-loading"></i></div>
        <div><div class="ks-val" style="color:var(--red)"><?= $stats['appro_pending'] ?></div><div class="ks-lbl">Appro attente</div></div></div>
    <div class="ks"><div class="ks-ico" style="background:rgba(255,145,64,0.16);color:var(--orange)"><i class="fas fa-exchange-alt"></i></div>
        <div><div class="ks-val" style="color:var(--orange)"><?= $stats['stock_movements'] ?></div><div class="ks-lbl">Mouvements</div></div></div>
    <div class="ks"><div class="ks-ico" style="background:rgba(236,72,153,0.16);color:var(--pink)"><i class="fas fa-database"></i></div>
        <div><div class="ks-val" style="color:var(--pink)"><?= $stats['db_size'] ?> MB</div><div class="ks-lbl">Base données</div></div></div>
</div>

<!-- Accès rapides -->
<div class="qa-grid">
    <a href="?module=users" class="qa-card">
        <div class="qa-ico" style="background:rgba(168,85,247,0.14);color:var(--purple)"><i class="fas fa-user-plus"></i></div>
        <div class="qa-txt"><strong>Nouvel utilisateur</strong><span>Créer un compte</span></div>
    </a>
    <a href="?module=products" class="qa-card">
        <div class="qa-ico" style="background:rgba(50,190,143,0.14);color:var(--neon)"><i class="fas fa-box-open"></i></div>
        <div class="qa-txt"><strong>Ajouter produit</strong><span>Avec société + ville</span></div>
    </a>
    <a href="?module=stock" class="qa-card">
        <div class="qa-ico" style="background:rgba(6,182,212,0.14);color:var(--cyan)"><i class="fas fa-warehouse"></i></div>
        <div class="qa-txt"><strong>Stock rapide</strong><span>Vue inventaire</span></div>
    </a>
    <a href="<?= project_url('stock/appro_requests.php') ?>" class="qa-card">
        <div class="qa-ico" style="background:rgba(255,53,83,0.14);color:var(--red)"><i class="fas fa-truck-loading"></i></div>
        <div class="qa-txt"><strong>Appros en attente</strong><span><?= $stats['appro_pending'] ?> demande(s)</span></div>
    </a>
     <a href="<?= project_url('system/reinitialisation_saison.php') ?>" class="qa-card">
        <div class="qa-ico" style="background:rgba(255,53,83,0.14);color:var(--red)"><i class="fas fa-truck-loading"></i></div>
        <div class="qa-txt"><strong>reinitialisation</strong><span><?= $stats['appro_pending'] ?> Denger(s)</span></div>
    </a>
    <a href="?module=logs" class="qa-card">
        <div class="qa-ico" style="background:rgba(255,208,96,0.14);color:var(--gold)"><i class="fas fa-history"></i></div>
        <div class="qa-txt"><strong>Activité récente</strong><span>Logs système</span></div>
    </a>
    <a href="?module=database" onclick="handleBackup(event)" class="qa-card">
        <div class="qa-ico" style="background:rgba(236,72,153,0.14);color:var(--pink)"><i class="fas fa-download"></i></div>
        <div class="qa-txt"><strong>Backup DB</strong><span>Export SQL complet</span></div>
    </a>
</div>

<!-- Graphiques -->
<div class="split-grid-2">
    <div class="panel">
        <div class="ph"><div class="ph-title"><div class="dot p"></div> Statistiques modules</div></div>
        <div class="pb"><div class="chart-box"><canvas id="chart-stats"></canvas></div></div>
    </div>
    <div class="panel">
        <div class="ph"><div class="ph-title"><div class="dot c"></div> Répartition données</div></div>
        <div class="pb"><div class="chart-box"><canvas id="chart-pie"></canvas></div></div>
    </div>
</div>

<!-- Tableau récap -->
<div class="panel">
    <div class="ph">
        <div class="ph-title"><div class="dot n"></div> Vue système complète</div>
        <span class="pbadge n"><?= count($stats) ?> modules</span>
    </div>
    <div class="pb">
        <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr><th>Module</th><th>Enregistrements</th><th>Statut</th><th>Action</th></tr></thead>
            <tbody>
            <?php
            $module_links = ['users'=>'users','companies'=>'companies','cities'=>'cities','products'=>'products','promotions'=>'promotions','loyalty'=>'loyalty',
                'clients'=>'clients','invoices'=>'#','expenses'=>'#','stock_movements'=>'stock'];
            foreach($stats as $k=>$v):
                if($k==='db_size') continue;
                $link = $module_links[$k] ?? '#';
                $cl = $link!='#'?'?module='.$link:'#';
            ?>
            <tr>
                <td><strong><?= ucfirst(str_replace('_',' ',$k)) ?></strong></td>
                <td><span style="font-family:var(--fh);font-size:16px;font-weight:900;color:var(--text)"><?= number_format($v) ?></span></td>
                <td><span style="display:inline-flex;align-items:center;gap:5px;font-family:var(--fb);font-size:11px;font-weight:800;
                    padding:3px 10px;border-radius:15px;background:rgba(50,190,143,0.1);color:var(--neon)">
                    <i class="fas fa-circle" style="font-size:7px"></i> Actif</span></td>
                <td><?php if($link!='#'): ?><a href="<?= $cl ?>" class="btn btn-p btn-xs"><i class="fas fa-arrow-right"></i> Voir</a><?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<script>
// Graphique barres
new Chart(document.getElementById('chart-stats').getContext('2d'), {
    type:'bar',
    data:{
        labels:['Users','Sociétés','Produits','Factures','Clients','Mouvements'],
        datasets:[{
            label:'Enregistrements',
            data:[<?= $stats['users'] ?>,<?= $stats['companies'] ?>,<?= $stats['products'] ?>,<?= $stats['invoices'] ?>,<?= $stats['clients'] ?>,<?= $stats['stock_movements'] ?>],
            backgroundColor:['rgba(168,85,247,0.75)','rgba(6,182,212,0.75)','rgba(50,190,143,0.75)','rgba(255,208,96,0.75)','rgba(61,140,255,0.75)','rgba(255,145,64,0.75)'],
            borderRadius:8,borderSkipped:false
        }]
    },
    options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},
        scales:{x:{grid:{color:'rgba(148,163,184,0.12)'},ticks:{color:'#8a9fad',font:{size:11}}},
            y:{grid:{color:'rgba(148,163,184,0.12)'},ticks:{color:'#8a9fad'},beginAtZero:true}}}
});
// Graphique donut
new Chart(document.getElementById('chart-pie').getContext('2d'), {
    type:'doughnut',
    data:{
        labels:['Users','Sociétés','Produits','Clients'],
        datasets:[{data:[<?= $stats['users'] ?>,<?= $stats['companies'] ?>,<?= $stats['products'] ?>,<?= $stats['clients'] ?>],
            backgroundColor:['rgba(168,85,247,0.8)','rgba(6,182,212,0.8)','rgba(50,190,143,0.8)','rgba(61,140,255,0.8)'],
            borderColor:'#22324a',borderWidth:3}]
    },
    options:{responsive:true,maintainAspectRatio:false,
        plugins:{legend:{position:'bottom',labels:{color:'#b8d8cc',font:{size:11},padding:12}}},
        cutout:'65%'}
});
</script>

<?php endif; ?>

<!-- ═══════════════════════════════════════════
     MODULE: USERS
═══════════════════════════════════════════ -->
<?php if($module==='users'): ?>
<div class="panel">
    <div class="ph">
        <div class="ph-title"><div class="dot p"></div> Utilisateurs du système</div>
        <button onclick="openModal('modal-add-user')" class="btn btn-p btn-sm"><i class="fas fa-plus"></i> Ajouter</button>
    </div>
    <div class="pb">
        <div class="search-row">
            <input type="text" class="s-input" id="user-search" placeholder="🔍 Rechercher par nom ou rôle…" oninput="debounce(loadUsers,400)()">
        </div>
        <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr><th>ID</th><th>Utilisateur</th><th>Rôle</th><th>Société</th><th>Actions</th></tr></thead>
            <tbody id="users-tbody"><tr><td colspan="5" style="text-align:center;padding:30px;color:var(--muted)">Chargement…</td></tr></tbody>
        </table>
        </div>
    </div>
</div>

<!-- Modal Ajouter User -->
<div id="modal-add-user" class="modal">
<div class="modal-box">
    <h2 style="color:var(--purple)"><i class="fas fa-user-plus"></i> Nouvel utilisateur</h2>
    <div class="fg"><label>Nom d'utilisateur <span class="required-star">*</span></label><input type="text" id="nu-username" placeholder="Ex: jean.dupont"></div>
    <div class="fg"><label>Mot de passe <span class="required-star">*</span></label><input type="password" id="nu-password" placeholder="Min. 4 caractères"></div>
    <div class="fg-row">
        <div class="fg"><label>Rôle <span class="required-star">*</span></label>
            <select id="nu-role">
                <option value="employee">Employé</option>
                <option value="admin">Administrateur</option>
                <option value="developer">Developeur</option>
                <option value="caissiere">Caissière</option>
                <option value="PDG">La PDG</option>
                <option value="Patron">Le patron</option>
                <option value="staff">Le magasinier</option>
                <option value="Directrice">La directrice</option>
                <option value="Superviseur">Le superviseur</option>
                <option value="informaticien">L'informaticien</option>
            </select>
        </div>
        <div class="fg"><label>Société <span class="required-star">*</span></label>
            <select id="nu-company">
                <option value="">— Sélectionner —</option>
                <?php foreach($companies_list as $c): ?>
                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="modal-btns">
        <button onclick="addUser()" class="btn btn-p"><i class="fas fa-save"></i> Créer</button>
        <button onclick="closeModal('modal-add-user')" class="btn btn-r"><i class="fas fa-times"></i> Annuler</button>
    </div>
</div>
</div>

<!-- Modal Modifier User -->
<div id="modal-edit-user" class="modal">
<div class="modal-box">
    <h2 style="color:var(--gold)"><i class="fas fa-edit"></i> Modifier utilisateur</h2>
    <input type="hidden" id="eu-id">
    <div class="fg"><label>Nom d'utilisateur</label><input type="text" id="eu-username"></div>
    <div class="fg"><label>Nouveau mot de passe <span style="color:var(--muted);font-size:10px">(laisser vide = inchangé)</span></label><input type="password" id="eu-password" placeholder="Laisser vide pour ne pas changer"></div>
    <div class="fg-row">
        <div class="fg"><label>Rôle</label>
            <select id="eu-role">
                <option value="user">User</option>
                <option value="admin">Admin</option>
                <option value="developer">Developer</option>
                <option value="caissiere">Caissière</option>
            </select>
        </div>
        <div class="fg"><label>Société</label>
            <select id="eu-company">
                <?php foreach($companies_list as $c): ?>
                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="modal-btns">
        <button onclick="updateUser()" class="btn btn-g"><i class="fas fa-save"></i> Enregistrer</button>
        <button onclick="closeModal('modal-edit-user')" class="btn btn-r"><i class="fas fa-times"></i> Annuler</button>
    </div>
</div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════
     MODULE: COMPANIES
═══════════════════════════════════════════ -->
<?php if($module==='companies'): ?>
<div class="panel">
    <div class="ph">
        <div class="ph-title"><div class="dot c"></div> Gestion des sociétés</div>
        <button onclick="openModal('modal-add-co')" class="btn btn-c btn-sm"><i class="fas fa-plus"></i> Ajouter</button>
    </div>
    <div class="pb">
        <div class="search-row">
            <input type="text" class="s-input" id="co-search" placeholder="🔍 Rechercher une société…" oninput="debounce(loadCompanies,400)()">
        </div>
        <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr><th>ID</th><th>Nom</th><th>Date création</th><th>Actions</th></tr></thead>
            <tbody id="companies-tbody"><tr><td colspan="4" style="text-align:center;padding:30px;color:var(--muted)">Chargement…</td></tr></tbody>
        </table>
        </div>
    </div>
</div>
<div id="modal-add-co" class="modal"><div class="modal-box">
    <h2 style="color:var(--cyan)"><i class="fas fa-building"></i> Nouvelle société</h2>
    <div class="fg"><label>Nom de la société <span class="required-star">*</span></label><input type="text" id="nco-name" placeholder="Ex: ESPERANCE Abidjan"></div>
    <div class="modal-btns">
        <button onclick="addCompany()" class="btn btn-c"><i class="fas fa-save"></i> Créer</button>
        <button onclick="closeModal('modal-add-co')" class="btn btn-r"><i class="fas fa-times"></i> Annuler</button>
    </div>
</div></div>
<div id="modal-edit-co" class="modal"><div class="modal-box">
    <h2 style="color:var(--gold)"><i class="fas fa-edit"></i> Modifier société</h2>
    <input type="hidden" id="eco-id">
    <div class="fg"><label>Nom</label><input type="text" id="eco-name"></div>
    <div class="modal-btns">
        <button onclick="updateCompany()" class="btn btn-g"><i class="fas fa-save"></i> Enregistrer</button>
        <button onclick="closeModal('modal-edit-co')" class="btn btn-r"><i class="fas fa-times"></i> Annuler</button>
    </div>
</div></div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════
     MODULE: CITIES
═══════════════════════════════════════════ -->
<?php if($module==='cities'): ?>
<div class="panel">
    <div class="ph">
        <div class="ph-title"><div class="dot b"></div> Gestion des magasins</div>
        <button onclick="openModal('modal-add-city')" class="btn btn-b btn-sm"><i class="fas fa-plus"></i> Ajouter</button>
    </div>
    <div class="pb">
        <div class="search-row">
            <input type="text" class="s-input" id="city-search" placeholder="🔍 Rechercher un magasin…" oninput="debounce(loadCities,400)()">
            <select class="s-input" id="city-co-filter" onchange="loadCities()" style="max-width:220px">
                <option value="0">Toutes sociétés</option>
                <?php foreach($companies_list as $c): ?>
                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr><th>ID</th><th>Magasin</th><th>Société</th><th>Actions</th></tr></thead>
            <tbody id="cities-tbody"><tr><td colspan="4" style="text-align:center;padding:30px;color:var(--muted)">Chargement…</td></tr></tbody>
        </table>
        </div>
    </div>
</div>
<div id="modal-add-city" class="modal"><div class="modal-box">
    <h2 style="color:var(--blue)"><i class="fas fa-city"></i> Nouveau magasin</h2>
    <div class="fg"><label>Nom du magasin <span class="required-star">*</span></label><input type="text" id="ncity-name" placeholder="Ex: Magasin Plateau"></div>
    <div class="fg"><label>Société <span class="required-star">*</span></label>
        <select id="ncity-company">
            <option value="">— Sélectionner —</option>
            <?php foreach($companies_list as $c): ?>
            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="modal-btns">
        <button onclick="addCity()" class="btn btn-b"><i class="fas fa-save"></i> Créer</button>
        <button onclick="closeModal('modal-add-city')" class="btn btn-r"><i class="fas fa-times"></i> Annuler</button>
    </div>
</div></div>
<div id="modal-edit-city" class="modal"><div class="modal-box">
    <h2 style="color:var(--gold)"><i class="fas fa-edit"></i> Modifier magasin</h2>
    <input type="hidden" id="ecity-id">
    <div class="fg"><label>Nom</label><input type="text" id="ecity-name"></div>
    <div class="fg"><label>Société</label>
        <select id="ecity-company">
            <?php foreach($companies_list as $c): ?>
            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="modal-btns">
        <button onclick="updateCity()" class="btn btn-g"><i class="fas fa-save"></i> Enregistrer</button>
        <button onclick="closeModal('modal-edit-city')" class="btn btn-r"><i class="fas fa-times"></i> Annuler</button>
    </div>
</div></div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════
     MODULE: PRODUCTS — Société + Ville obligatoires
═══════════════════════════════════════════ -->
<?php if($module==='products'): ?>

<!-- Compteur live total produits -->
<div style="display:flex;align-items:center;gap:20px;background:linear-gradient(135deg,rgba(50,190,143,0.1),rgba(6,182,212,0.07));border:1px solid rgba(50,190,143,0.25);border-radius:16px;padding:18px 28px;margin-bottom:18px">
    <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;background:rgba(50,190,143,0.18);border-radius:12px;padding:12px 24px;min-width:100px">
        <span id="global-prod-count" style="font-size:56px;font-weight:900;color:var(--neon);line-height:1;font-family:var(--fh,monospace);letter-spacing:-2px;transition:transform .15s ease">0</span>
        <span style="font-size:10px;font-weight:700;color:rgba(50,190,143,0.7);text-transform:uppercase;letter-spacing:2px;margin-top:4px">produit(s)</span>
    </div>
    <div>
        <div style="font-size:16px;font-weight:800;color:var(--neon);margin-bottom:4px"><i class="fas fa-boxes"></i> Catalogue total</div>
        <div style="font-size:12px;color:var(--muted);line-height:1.6">Nombre de produits enregistrés<br>dans toutes les sociétés et magasins.<br><span style="color:var(--cyan)"><i class="fas fa-circle" style="font-size:7px;animation:pulse 1.5s infinite"></i> Mis à jour en temps réel</span></div>
    </div>
</div>

<div class="panel">
    <div class="ph">
        <div class="ph-title"><div class="dot n"></div> Gestion des produits</div>
        <button onclick="openModal('modal-add-prod')" class="btn btn-n btn-sm"><i class="fas fa-plus"></i> Ajouter</button>
    </div>
    <div class="pb">
        <div class="search-row">
            <input type="text" class="s-input" id="prod-search" placeholder="🔍 Nom ou catégorie…" oninput="debounce(loadProducts,400)()">
            <select class="s-input" id="prod-co-filter" onchange="loadProducts()" style="max-width:220px">
                <option value="0">Toutes sociétés</option>
                <?php foreach($companies_list as $c): ?>
                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr><th>ID</th><th>Image</th><th>Produit</th><th>Catégorie</th><th>Prix</th><th>Alerte</th><th>Société</th><th>Actions</th></tr></thead>
            <tbody id="products-tbody"><tr><td colspan="8" style="text-align:center;padding:30px;color:var(--muted)">Chargement…</td></tr></tbody>
        </table>
        </div>
    </div>
</div>

<!-- Modal Ajouter Produit — SOCIÉTÉ + VILLE FORCÉES -->
<div id="modal-add-prod" class="modal"><div class="modal-box">
    <h2 style="color:var(--neon)"><i class="fas fa-box-open"></i> Nouveau produit</h2>

    <!-- Compteur live — en haut du modal -->
    <div id="np-city-count-wrap" style="display:none;align-items:center;gap:18px;background:linear-gradient(135deg,rgba(50,190,143,0.12),rgba(6,182,212,0.07));border:1px solid rgba(50,190,143,0.28);border-radius:14px;padding:14px 22px;margin-bottom:16px">
        <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;background:rgba(50,190,143,0.2);border-radius:10px;padding:10px 20px;min-width:90px">
            <span id="np-city-count" style="font-size:52px;font-weight:900;color:var(--neon);line-height:1;font-family:var(--fh,monospace);letter-spacing:-2px;transition:transform .15s ease">0</span>
            <span style="font-size:10px;font-weight:700;color:rgba(50,190,143,0.7);text-transform:uppercase;letter-spacing:1.5px;margin-top:3px">produit(s)</span>
        </div>
        <div style="flex:1">
            <div style="font-size:14px;font-weight:800;color:var(--neon);margin-bottom:4px"><i class="fas fa-store"></i> Produits dans ce magasin</div>
            <div style="font-size:11px;color:var(--muted);line-height:1.6"><i class="fas fa-circle" style="font-size:7px;color:var(--cyan);animation:pulse 1.5s infinite"></i> Mis à jour en temps réel à chaque ajout</div>
        </div>
    </div>

    <div class="forced-indicator">
        <i class="fas fa-exclamation-triangle"></i>
        <span><strong>Société et Ville obligatoires</strong> — Le produit sera rattaché à ce contexte et son stock initialisé à zéro.</span>
    </div>
    <div class="fg-row">
        <div class="fg"><label>Société <span class="required-star">*</span></label>
            <select id="np-company" onchange="loadCitiesForProduct()">
                <option value="">— Sélectionner la société —</option>
                <?php foreach($companies_list as $c): ?>
                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fg"><label>Ville / Magasin <span class="required-star">*</span></label>
            <select id="np-city" disabled onchange="onCitySelectForProduct()">
                <option value="">— Choisir société d'abord —</option>
            </select>
        </div>
    </div>
    <div class="fg-row">
        <div class="fg"><label>Nom du produit <span class="required-star">*</span></label><input type="text" id="np-name" placeholder="Ex: Eau minérale 1.5L"></div>
        <div class="fg"><label>Catégorie</label><input type="text" id="np-category" placeholder="Ex: Eau, Jus, Boisson…"></div>
    </div>
    <div class="fg-row">
        <div class="fg"><label>Prix (FCFA) <span class="required-star">*</span></label><input type="number" id="np-price" step="1" min="0" placeholder="0"></div>
        <div class="fg"><label>Alerte stock (qté min)</label><input type="number" id="np-alert" value="5" min="0"></div>
    </div>
    <div class="fg">
        <label>Photo du produit</label>
        <input type="file" id="np-image" accept="image/jpeg,image/png,image/webp,image/gif">
        <div class="product-image-preview empty" id="np-image-preview"><span><i class="fas fa-image"></i><br>Aucune image sélectionnée</span></div>
    </div>
    <div class="modal-btns">
        <button onclick="addProduct()" id="btn-add-prod" class="btn btn-n"><i class="fas fa-save"></i> Créer le produit</button>
        <button onclick="closeModal('modal-add-prod')" class="btn btn-r"><i class="fas fa-times"></i> Fermer</button>
    </div>
    <!-- Historique produits de la ville sélectionnée -->
    <div id="np-city-history" style="display:none;margin-top:20px;border-top:1px solid rgba(255,255,255,0.08);padding-top:14px">
        <div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:8px"><i class="fas fa-history"></i> Historique du magasin</div>
        <div id="np-city-history-list" style="max-height:220px;overflow-y:auto;display:flex;flex-direction:column;gap:5px"></div>
    </div>
</div></div>

<!-- Modal Suppression Produit -->
<div id="modal-delete-prod" class="modal"><div class="modal-box" style="max-width:420px">
    <div style="text-align:center;margin-bottom:18px">
        <div style="width:64px;height:64px;border-radius:50%;background:rgba(255,53,83,0.15);border:2px solid rgba(255,53,83,0.4);display:flex;align-items:center;justify-content:center;margin:0 auto 14px">
            <i class="fas fa-trash-alt" style="font-size:26px;color:var(--red)"></i>
        </div>
        <h2 style="color:var(--red);margin:0 0 6px">Supprimer le produit</h2>
        <div id="del-prod-name" style="font-size:15px;font-weight:700;color:var(--light);background:rgba(255,53,83,0.08);border:1px solid rgba(255,53,83,0.2);border-radius:8px;padding:8px 16px;margin:10px 0"></div>
        <div style="font-size:12px;color:var(--muted);line-height:1.6">Cette action est <strong style="color:var(--red)">irréversible</strong>.<br>Le produit, son image et ses données de stock seront supprimés.</div>
    </div>
    <div class="fg" style="margin-bottom:16px">
        <label style="font-size:12px;color:var(--muted)">Tapez <strong id="del-prod-confirm-hint" style="color:var(--red)"></strong> pour confirmer</label>
        <input type="text" id="del-prod-input" placeholder="Nom du produit…" oninput="checkDeleteConfirm()" autocomplete="off"
            style="border:1.5px solid rgba(255,53,83,0.3);background:rgba(255,53,83,0.05)">
    </div>
    <div class="modal-btns">
        <button id="btn-confirm-delete-prod" onclick="confirmDeleteProduct()" class="btn btn-r" disabled
            style="opacity:.4;cursor:not-allowed;flex:1"><i class="fas fa-trash-alt"></i> Supprimer définitivement</button>
        <button onclick="closeModal('modal-delete-prod')" class="btn btn-g"><i class="fas fa-times"></i> Annuler</button>
    </div>
</div></div>

<!-- Modal Modifier Produit -->
<div id="modal-edit-prod" class="modal"><div class="modal-box">
    <h2 style="color:var(--gold)"><i class="fas fa-edit"></i> Modifier produit</h2>
    <input type="hidden" id="ep-id">
    <div class="fg-row">
        <div class="fg"><label>Nom</label><input type="text" id="ep-name"></div>
        <div class="fg"><label>Catégorie</label><input type="text" id="ep-category"></div>
    </div>
    <div class="fg-row">
        <div class="fg"><label>Prix (FCFA)</label><input type="number" id="ep-price" step="1"></div>
        <div class="fg"><label>Alerte stock</label><input type="number" id="ep-alert"></div>
    </div>
    <div class="fg">
        <label>Photo du produit</label>
        <input type="file" id="ep-image" accept="image/jpeg,image/png,image/webp,image/gif">
        <div class="product-image-preview empty" id="ep-image-preview"><span><i class="fas fa-image"></i><br>Aucune image</span></div>
    </div>
    <div class="modal-btns">
        <button onclick="removeProductImage()" class="btn btn-r" id="ep-remove-image" style="display:none"><i class="fas fa-trash"></i> Supprimer l'image</button>
        <button onclick="updateProduct()" class="btn btn-g"><i class="fas fa-save"></i> Enregistrer</button>
        <button onclick="closeModal('modal-edit-prod')" class="btn btn-r"><i class="fas fa-times"></i> Annuler</button>
    </div>
</div></div>
<?php endif; ?>

<?php if($module==='promotions'): ?>
<div class="panel">
    <div class="ph">
        <div class="ph-title"><div class="dot r"></div> Gestion des promotions</div>
        <button onclick="openPromotionModal()" class="btn btn-r btn-sm"><i class="fas fa-plus"></i> Nouvelle promo</button>
    </div>
    <div class="pb">
        <div class="search-row">
            <input type="text" class="s-input" id="promo-search" placeholder="🔍 Titre ou sous-titre…" oninput="debounce(loadPromotions,400)()">
            <select class="s-input" id="promo-co-filter" onchange="loadPromotions()" style="max-width:220px">
                <option value="0">Toutes sociétés</option>
                <?php foreach($companies_list as $c): ?>
                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr><th>ID</th><th>Offre</th><th>Type</th><th>Prix promo</th><th>Contexte</th><th>Période</th><th>État</th><th>Actions</th></tr></thead>
            <tbody id="promotions-tbody"><tr><td colspan="8" style="text-align:center;padding:30px;color:var(--muted)">Chargement…</td></tr></tbody>
        </table>
        </div>
    </div>
</div>

<div id="modal-promo" class="modal"><div class="modal-box" style="max-width:760px">
    <h2 style="color:var(--red)"><i class="fas fa-tags"></i> Promotion</h2>
    <input type="hidden" id="pm-id">
    <div class="fg-row">
        <div class="fg">
            <label>Société <span class="required-star">*</span></label>
            <select id="pm-company" onchange="loadPromotionCities();loadPromotionProducts();">
                <option value="">— Sélectionner —</option>
                <?php foreach($companies_list as $c): ?>
                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fg">
            <label>Magasin / Ville <span class="required-star">*</span></label>
            <select id="pm-city" onchange="loadPromotionProducts()" disabled>
                <option value="">— Choisir société d'abord —</option>
            </select>
        </div>
    </div>
    <div class="fg-row">
        <div class="fg"><label>Titre <span class="required-star">*</span></label><input type="text" id="pm-title" placeholder="Ex: PACK SOIRÉE"></div>
        <div class="fg"><label>Sous-titre</label><input type="text" id="pm-subtitle" placeholder="Offres spéciales disponibles aujourd’hui"></div>
    </div>
    <div class="fg-row3">
        <div class="fg">
            <label>Type promo</label>
            <select id="pm-type" onchange="togglePromotionFields()">
                <option value="simple">Réduction simple</option>
                <option value="flash">Promo Flash</option>
                <option value="pack">Pack promotionnel</option>
                <option value="quantity">Offre quantité</option>
            </select>
        </div>
        <div class="fg">
            <label>Filtre mobile</label>
            <select id="pm-filter">
                <option value="reduction">Réduction</option>
                <option value="flash">Flash</option>
                <option value="pack">Pack</option>
                <option value="nouveau">Nouveau</option>
            </select>
        </div>
        <div class="fg"><label>Badge</label><input type="text" id="pm-badge" value="PROMO" placeholder="PROMO"></div>
    </div>
    <div class="fg-row">
        <div class="fg"><label>Produit principal</label><select id="pm-product"><option value="">— Sélectionner —</option></select></div>
        <div class="fg"><label>Ordre affichage</label><input type="number" id="pm-sort" value="0" min="0"></div>
    </div>
    <div class="fg-row3">
        <div class="fg"><label>Ancien prix</label><input type="number" id="pm-old-price" min="0" step="1" placeholder="1500"></div>
        <div class="fg"><label>Prix promo</label><input type="number" id="pm-price" min="0" step="1" placeholder="1111"></div>
        <div class="fg"><label>Réduction %</label><input type="number" id="pm-discount" min="0" step="0.01" placeholder="20"></div>
    </div>
    <div class="fg-row3" id="pm-quantity-row" style="display:none">
        <div class="fg"><label>Achetez</label><input type="number" id="pm-buy" min="0" step="1" placeholder="3"></div>
        <div class="fg"><label>Payez</label><input type="number" id="pm-pay" min="0" step="1" placeholder="2"></div>
        <div class="fg"><label>Paliers</label><input type="text" id="pm-tiers" placeholder="2:10,5:20"></div>
    </div>
    <div class="fg" id="pm-items-wrap" style="display:none">
        <label>Produits du pack</label>
        <textarea id="pm-items" rows="5" placeholder="Format: product_id:quantité&#10;12:1&#10;18:2&#10;21:1"></textarea>
        <div class="map-picker-help">Utilisez le format <strong>product_id:quantité</strong>, une ligne par produit. Les IDs sont visibles dans le module Produits.</div>
    </div>
    <div class="fg-row">
        <div class="fg"><label>Début</label><input type="datetime-local" id="pm-start"></div>
        <div class="fg"><label>Fin</label><input type="datetime-local" id="pm-end"></div>
    </div>
    <div class="fg-row3">
        <div class="fg">
            <label>Active</label>
            <select id="pm-active"><option value="1">Oui</option><option value="0">Non</option></select>
        </div>
        <div class="fg">
            <label>Notifier les clients</label>
            <select id="pm-notify"><option value="1">Oui</option><option value="0">Non</option></select>
        </div>
        <div class="fg">
            <label>Rappel</label>
            <input type="text" value="Email + notification in-app" disabled>
        </div>
    </div>
    <div class="modal-btns">
        <button onclick="savePromotion()" class="btn btn-r"><i class="fas fa-save"></i> Enregistrer</button>
        <button onclick="closeModal('modal-promo')" class="btn btn-b"><i class="fas fa-times"></i> Annuler</button>
    </div>
</div></div>
<?php endif; ?>

<?php if($module==='loyalty'): ?>
<div class="kpi-strip">
    <div class="ks"><div class="ks-ico" style="background:rgba(255,208,96,0.16);color:var(--gold)"><i class="fas fa-star"></i></div><div><div class="ks-val" style="color:var(--gold)" id="loyalty-total-points">0</div><div class="ks-lbl">Points cumulés</div></div></div>
    <div class="ks"><div class="ks-ico" style="background:rgba(6,182,212,0.16);color:var(--cyan)"><i class="fas fa-gem"></i></div><div><div class="ks-val" style="color:var(--cyan)" id="loyalty-vip-count">0</div><div class="ks-lbl">Clients VIP</div></div></div>
    <div class="ks"><div class="ks-ico" style="background:rgba(255,145,64,0.16);color:var(--orange)"><i class="fas fa-cart-shopping"></i></div><div><div class="ks-val" style="color:var(--orange)" id="loyalty-abandoned-count">0</div><div class="ks-lbl">Paniers abandonnés</div></div></div>
    <div class="ks"><div class="ks-ico" style="background:rgba(168,85,247,0.16);color:var(--purple)"><i class="fab fa-whatsapp"></i></div><div><div class="ks-val" style="color:var(--purple)" id="loyalty-reminders-count">0</div><div class="ks-lbl">Relances envoyées</div></div></div>
</div>

<div class="panel">
    <div class="ph">
        <div class="ph-title"><div class="dot g"></div> Points fidélité et statut VIP</div>
    </div>
    <div class="pb">
        <div class="search-row">
            <input type="text" class="s-input" id="loyalty-search" placeholder="🔍 Client, téléphone, email…" oninput="debounce(loadLoyaltyClients,400)()">
        </div>
        <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr><th>ID</th><th>Client</th><th>Points</th><th>VIP</th><th>Commandes</th><th>Dépense</th><th>Dernière commande</th><th>Actions</th></tr></thead>
            <tbody id="loyalty-tbody"><tr><td colspan="8" style="text-align:center;padding:30px;color:var(--muted)">Chargement…</td></tr></tbody>
        </table>
        </div>
    </div>
</div>

<div class="panel">
    <div class="ph">
        <div class="ph-title"><div class="dot o"></div> Paniers abandonnés et relances</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <a href="?ajax=export_abandoned_carts_csv" class="btn btn-c btn-sm"><i class="fas fa-file-csv"></i> Export CSV</a>
            <button onclick="loadAbandonedCarts('all', this)" class="btn btn-b btn-sm" id="ab-filter-all"><i class="fas fa-layer-group"></i> Tous</button>
            <button onclick="loadAbandonedCarts('active', this)" class="btn btn-n btn-sm" id="ab-filter-active"><i class="fas fa-clock"></i> Actifs</button>
            <button onclick="loadAbandonedCarts('recovered', this)" class="btn btn-g btn-sm" id="ab-filter-recovered"><i class="fas fa-check"></i> Récupérés</button>
            <button onclick="loadAbandonedCarts('expired', this)" class="btn btn-r btn-sm" id="ab-filter-expired"><i class="fas fa-times"></i> Expirés</button>
        </div>
    </div>
    <div class="pb">
        <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr><th>ID</th><th>Client</th><th>Contexte</th><th>Articles</th><th>Total</th><th>Activité</th><th>Relance</th><th>Actions</th></tr></thead>
            <tbody id="abandoned-tbody"><tr><td colspan="8" style="text-align:center;padding:30px;color:var(--muted)">Chargement…</td></tr></tbody>
        </table>
        </div>
    </div>
</div>

<div class="split-grid-2">
    <div class="panel">
        <div class="ph">
            <div class="ph-title"><div class="dot p"></div> Statistiques coupons récupération</div>
        </div>
        <div class="pb">
            <div class="kpi-strip" style="grid-template-columns:repeat(2,minmax(0,1fr));margin-bottom:0">
                <div class="ks"><div class="ks-ico" style="background:rgba(168,85,247,0.16);color:var(--purple)"><i class="fas fa-ticket-alt"></i></div><div><div class="ks-val" style="color:var(--purple)" id="coupon-total-count">0</div><div class="ks-lbl">Coupons créés</div></div></div>
                <div class="ks"><div class="ks-ico" style="background:rgba(34,197,94,0.16);color:var(--neon)"><i class="fas fa-bolt"></i></div><div><div class="ks-val" style="color:var(--neon)" id="coupon-active-count">0</div><div class="ks-lbl">Coupons actifs</div></div></div>
                <div class="ks"><div class="ks-ico" style="background:rgba(6,182,212,0.16);color:var(--cyan)"><i class="fas fa-cart-arrow-down"></i></div><div><div class="ks-val" style="color:var(--cyan)" id="coupon-recovered-count">0</div><div class="ks-lbl">Paniers récupérés</div></div></div>
                <div class="ks"><div class="ks-ico" style="background:rgba(255,208,96,0.16);color:var(--gold)"><i class="fas fa-chart-line"></i></div><div><div class="ks-val" style="color:var(--gold)" id="coupon-recovery-rate">0%</div><div class="ks-lbl">Taux récupération</div></div></div>
            </div>
        </div>
    </div>
    <div class="panel">
        <div class="ph">
            <div class="ph-title"><div class="dot c"></div> Pilotage coupons</div>
        </div>
        <div class="pb" style="font-size:13px;color:var(--muted);line-height:1.7">
            Les coupons liés aux paniers abandonnés peuvent être désactivés manuellement ou réactivés pour une nouvelle fenêtre de récupération.
            Le taux de récupération est calculé sur les coupons liés à un panier passé au statut <strong>recovered</strong>.
        </div>
    </div>
</div>

<div class="panel">
    <div class="ph">
        <div class="ph-title"><div class="dot c"></div> Coupons personnalisés créés</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <a href="?ajax=export_coupons_csv" class="btn btn-c btn-sm"><i class="fas fa-file-csv"></i> Export CSV</a>
        </div>
    </div>
    <div class="pb">
        <div class="search-row">
            <input type="text" class="s-input" id="coupon-search" placeholder="🔍 Code, client, téléphone, email…" oninput="debounce(loadPersonalizedCoupons,400)()">
            <select class="s-input" id="coupon-status-filter" onchange="loadPersonalizedCoupons()" style="max-width:220px">
                <option value="all">Tous les statuts</option>
                <option value="active">Actifs</option>
                <option value="used">Utilisés</option>
                <option value="expired">Expirés</option>
            </select>
        </div>
        <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr><th>Code</th><th>Client</th><th>Remise</th><th>Panier lié</th><th>Statut</th><th>Expiration</th><th>Performance</th><th>Actions</th></tr></thead>
            <tbody id="coupon-tbody"><tr><td colspan="8" style="text-align:center;padding:30px;color:var(--muted)">Chargement…</td></tr></tbody>
        </table>
        </div>
    </div>
</div>

<div class="modal" id="modal-loyalty-history"><div class="mc" style="max-width:900px">
    <div class="mh">
        <div>
            <strong id="loyalty-history-title">Historique fidélité</strong>
            <div style="font-size:12px;color:var(--muted);margin-top:4px">Transactions détaillées des points client</div>
        </div>
        <button onclick="closeModal('modal-loyalty-history')" class="x">&times;</button>
    </div>
    <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr><th>ID</th><th>Variation</th><th>Motif</th><th>Référence</th><th>Date</th></tr></thead>
            <tbody id="loyalty-history-body"><tr><td colspan="5" style="text-align:center;padding:30px;color:var(--muted)">Chargement…</td></tr></tbody>
        </table>
    </div>
</div></div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════
     MODULE: STOCK RAPIDE (MON AJOUT)
═══════════════════════════════════════════ -->
<?php if($module==='stock'): ?>
<div class="panel">
    <div class="ph">
        <div class="ph-title"><div class="dot c"></div> Stock rapide — Vue inventaire</div>
        <span class="pbadge c">Sélectionnez société + ville</span>
    </div>
    <div class="pb">
        <div style="background:rgba(6,182,212,0.06);border:1px solid rgba(6,182,212,0.2);border-radius:12px;padding:12px 16px;margin-bottom:16px;font-family:var(--fb);font-size:13px;color:var(--cyan)">
            <i class="fas fa-info-circle"></i> Utilisez les sélecteurs <strong>Société</strong> et <strong>Ville</strong> dans la barre du haut pour voir le stock d'un magasin.
        </div>
        <div id="stock-content">
            <div class="empty-state"><i class="fas fa-warehouse"></i><h3>Aucun contexte sélectionné</h3><p>Choisissez une société et une ville ci-dessus</p></div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════
     MODULE: LOGS
═══════════════════════════════════════════ -->
<?php if($module==='logs'): ?>
<div class="panel">
    <div class="ph">
        <div class="ph-title"><div class="dot g"></div> Journal d'activité système</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button onclick="loadLogs()" class="btn btn-c btn-sm"><i class="fas fa-sync"></i> Rafraîchir</button>
            <a href="?ajax=export_logs_csv" class="btn btn-n btn-sm"><i class="fas fa-file-csv"></i> Export CSV</a>
            <button onclick="confirmClearLogs()" class="btn btn-r btn-sm"><i class="fas fa-broom"></i> Effacer</button>
        </div>
    </div>
    <div class="pb">
        <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr><th>ID</th><th>Utilisateur</th><th>Action</th><th>Détails</th><th>Date</th></tr></thead>
            <tbody id="logs-tbody"><tr><td colspan="5" style="text-align:center;padding:30px;color:var(--muted)">Chargement…</td></tr></tbody>
        </table>
        </div>
    </div>
</div>

<!-- Graphiques logs -->
<div class="split-grid-2">
    <div class="panel">
        <div class="ph"><div class="ph-title"><div class="dot p"></div> Top utilisateurs</div></div>
        <div class="pb"><div class="chart-box"><canvas id="chart-log-users"></canvas></div></div>
    </div>
    <div class="panel">
        <div class="ph"><div class="ph-title"><div class="dot o"></div> Répartition actions</div></div>
        <div class="pb"><div class="chart-box"><canvas id="chart-log-actions"></canvas></div></div>
    </div>
</div>
<div class="panel">
    <div class="ph"><div class="ph-title"><div class="dot n"></div> Activité 7 derniers jours</div></div>
    <div class="pb"><div class="chart-box" style="height:180px"><canvas id="chart-log-timeline"></canvas></div></div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════
     MODULE: RH SETTINGS
═══════════════════════════════════════════ -->
<?php if($module==='hr_settings'): ?>
<div class="split-grid-main-side">
    <div class="panel">
        <div class="ph"><div class="ph-title"><div class="dot n"></div> Paramètres de pointage</div></div>
        <div class="pb">
            <div class="fg-row">
                <div class="fg">
                    <label for="att-start-time">Heure de début</label>
                    <input type="time" id="att-start-time" value="<?= htmlspecialchars(substr($attendance_settings['work_start_time'], 0, 5)) ?>">
                </div>
                <div class="fg">
                    <label for="att-end-time">Heure de départ</label>
                    <input type="time" id="att-end-time" value="<?= htmlspecialchars(substr($attendance_settings['work_end_time'], 0, 5)) ?>">
                </div>
            </div>
            <div class="fg-row3">
                <div class="fg">
                    <label for="att-latitude">Latitude bureau</label>
                    <input type="number" step="0.0000001" id="att-latitude" value="<?= htmlspecialchars((string)$attendance_settings['office_latitude']) ?>">
                </div>
                <div class="fg">
                    <label for="att-longitude">Longitude bureau</label>
                    <input type="number" step="0.0000001" id="att-longitude" value="<?= htmlspecialchars((string)$attendance_settings['office_longitude']) ?>">
                </div>
                <div class="fg">
                    <label for="att-radius">Rayon GPS (mètres)</label>
                    <input type="number" min="1" id="att-radius" value="<?= htmlspecialchars((string)$attendance_settings['location_radius_meters']) ?>">
                </div>
            </div>
            <div class="fg">
                <label>Position de l'entreprise sur Google Maps</label>
                <div class="fg-row">
                    <button type="button" onclick="useCurrentPositionForAttendance()" class="btn btn-c"><i class="fas fa-location-crosshairs"></i> Utiliser ma position</button>
                    <a id="attendance-map-open-link" href="https://www.google.com/maps?q=<?= urlencode((string)$attendance_settings['office_latitude']) ?>,<?= urlencode((string)$attendance_settings['office_longitude']) ?>" target="_blank" rel="noopener" class="btn btn-b"><i class="fas fa-map-marked-alt"></i> Ouvrir Google Maps</a>
                </div>
                <div class="map-picker-help">Ouvrez Google Maps, cherchez l'entreprise, puis collez le lien Google Maps ci-dessous. Les coordonnées seront extraites automatiquement.</div>
                <input type="text" id="att-google-maps-url" placeholder="Collez ici un lien Google Maps ou des coordonnées: 5.330582,-4.197368">
                <div class="fg-row" style="margin-top:10px">
                    <button type="button" onclick="applyAttendanceGoogleMapsUrl()" class="btn btn-g"><i class="fas fa-link"></i> Extraire la position</button>
                    <button type="button" onclick="refreshAttendanceMapPreview()" class="btn btn-p"><i class="fas fa-sync"></i> Actualiser l'aperçu</button>
                </div>
                <iframe id="attendance-map-preview" class="map-picker-preview" loading="lazy" referrerpolicy="no-referrer-when-downgrade"
                    src="https://www.google.com/maps/embed/v1/view?key=<?= urlencode($google_maps_embed_key) ?>&center=<?= urlencode((string)$attendance_settings['office_latitude']) ?>,<?= urlencode((string)$attendance_settings['office_longitude']) ?>&zoom=18&maptype=roadmap"></iframe>
            </div>
            <div class="fg-row">
                <div class="fg">
                    <label for="att-penalty">Pénalité par minute</label>
                    <input type="number" min="0" step="0.01" id="att-penalty" value="<?= htmlspecialchars((string)$attendance_settings['late_penalty_per_minute']) ?>">
                </div>
                <div class="fg">
                    <label for="att-gps-in">GPS obligatoire à l'arrivée</label>
                    <select id="att-gps-in">
                        <option value="1" <?= !empty($attendance_settings['require_gps_check_in']) ? 'selected' : '' ?>>Oui</option>
                        <option value="0" <?= empty($attendance_settings['require_gps_check_in']) ? 'selected' : '' ?>>Non</option>
                    </select>
                </div>
            </div>
            <div class="fg">
                <label for="att-gps-out">GPS obligatoire au départ</label>
                <select id="att-gps-out">
                    <option value="1" <?= !empty($attendance_settings['require_gps_check_out']) ? 'selected' : '' ?>>Oui</option>
                    <option value="0" <?= empty($attendance_settings['require_gps_check_out']) ? 'selected' : '' ?>>Non</option>
                </select>
            </div>
            <button onclick="saveAttendanceSettings()" class="btn btn-n btn-full"><i class="fas fa-save"></i> Enregistrer les paramètres RH</button>
        </div>
    </div>
    <div class="panel">
        <div class="ph"><div class="ph-title"><div class="dot g"></div> Résumé actif</div></div>
        <div class="pb" id="attendance-settings-summary" style="font-family:var(--fb);font-size:13px;line-height:2;color:var(--text2)">
            Chargement…
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════
     MODULE: DATABASE
═══════════════════════════════════════════ -->
<?php if($module==='database'): ?>
<div class="split-grid-2-tight">
    <div class="panel">
        <div class="ph"><div class="ph-title"><div class="dot p"></div> Outils base de données</div></div>
        <div class="pb" style="display:flex;flex-direction:column;gap:10px">
            <form method="post">
                <button type="submit" name="backup_db" class="btn btn-p btn-full"><i class="fas fa-download"></i> Backup SQL complet</button>
            </form>
            <button onclick="optimizeDB()" class="btn btn-n btn-full"><i class="fas fa-wrench"></i> Optimiser toutes les tables</button>
            <button onclick="loadDbStats()" class="btn btn-c btn-full"><i class="fas fa-sync"></i> Actualiser les stats</button>
        </div>
    </div>
    <div class="panel">
        <div class="ph"><div class="ph-title"><div class="dot c"></div> Informations serveur</div></div>
        <div class="pb">
            <div id="db-info" style="font-family:var(--fb);font-size:13px;line-height:2.2;color:var(--text2)">
                <div><span style="color:var(--muted);width:120px;display:inline-block">Taille totale:</span> <strong id="dbi-size" style="color:var(--neon)">—</strong></div>
                <div><span style="color:var(--muted);width:120px;display:inline-block">Nb tables:</span> <strong id="dbi-tables" style="color:var(--cyan)">—</strong></div>
                <div><span style="color:var(--muted);width:120px;display:inline-block">Moteur:</span> <strong style="color:var(--gold)">InnoDB / MySQL</strong></div>
                <div><span style="color:var(--muted);width:120px;display:inline-block">Charset:</span> <strong style="color:var(--purple)">utf8mb4</strong></div>
            </div>
        </div>
    </div>
</div>
<div class="panel">
    <div class="ph"><div class="ph-title"><div class="dot n"></div> Statistiques par table</div></div>
    <div class="pb">
        <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr><th>Table</th><th>Enregistrements</th><th>Taille (MB)</th><th>Utilisation</th></tr></thead>
            <tbody id="db-tbody"><tr><td colspan="4" style="text-align:center;padding:30px;color:var(--muted)">Chargement…</td></tr></tbody>
        </table>
        </div>
    </div>
</div>
<div class="panel">
    <div class="ph"><div class="ph-title"><div class="dot b"></div> Répartition espace disque</div></div>
    <div class="pb"><div class="chart-box"><canvas id="chart-db"></canvas></div></div>
</div>
<?php endif; ?>

</div><!-- /wrap -->

<!-- TOAST STACK -->
<div class="toast-stack" id="toast-stack"></div>

<!-- CONFIRM OVERLAY -->
<div id="confirm-overlay" class="confirm-overlay">
    <div class="confirm-box">
        <h3><i class="fas fa-exclamation-triangle"></i> Confirmation</h3>
        <p id="confirm-msg">Êtes-vous sûr de vouloir effectuer cette action ?</p>
        <div class="btn-row">
            <button id="confirm-ok" class="btn btn-r"><i class="fas fa-check"></i> Confirmer</button>
            <button onclick="closeConfirm()" class="btn btn-b"><i class="fas fa-times"></i> Annuler</button>
        </div>
    </div>
</div>

<script>
const GOOGLE_MAPS_EMBED_KEY = <?= json_encode($google_maps_embed_key) ?>;
/* ═══════════════════════════════════════════════
   🕐 HORLOGE
═══════════════════════════════════════════════ */
function tick(){
    const n=new Date();
    document.getElementById('clk').textContent=n.toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
    document.getElementById('clkd').textContent=n.toLocaleDateString('fr-FR',{weekday:'long',day:'numeric',month:'long',year:'numeric'});
}
tick(); setInterval(tick,1000);

/* ═══════════════════════════════════════════════
   🔔 TOAST
═══════════════════════════════════════════════ */
function toast(msg, type='info', sub=''){
    const colors={'success':'var(--neon)','error':'var(--red)','info':'var(--cyan)','warn':'var(--gold)'};
    const icons={'success':'fa-check-circle','error':'fa-times-circle','info':'fa-info-circle','warn':'fa-exclamation-triangle'};
    const stack=document.getElementById('toast-stack');
    const t=document.createElement('div'); t.className='toast';
    t.innerHTML=`<div class="toast-ico" style="background:${colors[type]}22;color:${colors[type]}"><i class="fas ${icons[type]}"></i></div>
        <div class="toast-txt"><strong style="color:${colors[type]}">${msg}</strong>${sub?`<span>${sub}</span>`:''}</div>`;
    stack.appendChild(t);
    setTimeout(()=>{ t.classList.add('out'); setTimeout(()=>t.remove(),350); },4000);
}

/* ═══════════════════════════════════════════════
   🪟 MODALS
═══════════════════════════════════════════════ */
function openModal(id){ document.getElementById(id).classList.add('show'); }
function closeModal(id){ document.getElementById(id).classList.remove('show'); }
document.querySelectorAll('.modal').forEach(m=>m.addEventListener('click',e=>{if(e.target===m) m.classList.remove('show');}));

/* ESC fermature */
document.addEventListener('keydown',e=>{
    if(e.key==='Escape'){
        document.querySelectorAll('.modal.show').forEach(m=>m.classList.remove('show'));
        closeConfirm();
    }
});

/* ═══════════════════════════════════════════════
   ✅ CONFIRM OVERLAY
═══════════════════════════════════════════════ */
let confirmCallback=null;
function confirm2(msg, cb){
    document.getElementById('confirm-msg').textContent=msg;
    confirmCallback=cb;
    document.getElementById('confirm-overlay').classList.add('show');
    document.getElementById('confirm-ok').onclick=()=>{ closeConfirm(); cb(); };
}
function closeConfirm(){ document.getElementById('confirm-overlay').classList.remove('show'); }

/* ═══════════════════════════════════════════════
   ⏱ DEBOUNCE
═══════════════════════════════════════════════ */
function debounce(fn,delay){
    let t; return ()=>{ clearTimeout(t); t=setTimeout(fn,delay); };
}

/* ═══════════════════════════════════════════════
   🌍 AJAX helper
═══════════════════════════════════════════════ */
async function api(action, params={}, body=null){
    const csrf = <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const mergedParams = {...params, csrf_token: csrf};
    const qs=Object.entries(mergedParams).map(([k,v])=>`${k}=${encodeURIComponent(v)}`).join('&');
    const url=`?ajax=${action}${qs?'&'+qs:''}`;
    const opts={method: body ? 'POST' : 'GET', headers:{'X-Requested-With':'XMLHttpRequest','X-CSRF-Token':csrf}};
    if(body){
        opts.headers['Content-Type']='application/json';
        opts.body=JSON.stringify({...body, csrf_token: csrf});
    }
    const r=await fetch(url,opts);
    return r.json();
}

/* ═══════════════════════════════════════════════
   🌐 CONTEXTE GLOBAL (Société / Ville)
═══════════════════════════════════════════════ */
async function onGlobalCompanyChange(){
    const cid=document.getElementById('global-company').value;
    const citySelect=document.getElementById('global-city');
    citySelect.innerHTML='<option value="">— Toutes les villes —</option>';
    citySelect.disabled=!cid;
    if(cid){
        const cities=await api('get_cities_by_company',{company_id:cid});
        cities.forEach(c=>{ const o=document.createElement('option'); o.value=c.id; o.textContent=c.name; citySelect.appendChild(o); });
        citySelect.disabled=false;
    }
    onGlobalCityChange();
}
function onGlobalCityChange(){
    const co=document.getElementById('global-company');
    const ci=document.getElementById('global-city');
    const coName=co.options[co.selectedIndex]?.text||'';
    const ciName=ci.options[ci.selectedIndex]?.text||'';
    const txt=document.getElementById('ctx-status-txt');
    if(co.value && ci.value) txt.textContent=`${coName} — ${ciName}`;
    else if(co.value) txt.textContent=coName;
    else txt.textContent='Contexte global';
    /* Rafraîchir stock si module actif */
    if(<?= json_encode($module) ?>==='stock') loadQuickStock();
}

/* ═══════════════════════════════════════════════
   👥 USERS
═══════════════════════════════════════════════ */
async function loadUsers(){
    const q=document.getElementById('user-search')?.value||'';
    const users=await api('get_users',{q});
    const tbody=document.getElementById('users-tbody');
    if(!tbody) return;
    if(!users.length){ tbody.innerHTML='<tr><td colspan="5"><div class="empty-state"><i class="fas fa-users"></i><h3>Aucun utilisateur trouvé</h3></div></td></tr>'; return; }
    tbody.innerHTML=users.map(u=>`
    <tr>
        <td><strong style="color:var(--cyan)">#${u.id}</strong></td>
        <td><strong>${esc(u.username)}</strong></td>
        <td><span class="role-badge ${u.role==='developer'?'rb-dev':u.role==='admin'?'rb-admin':'rb-user'}"><i class="fas ${u.role==='developer'?'fa-code':u.role==='admin'?'fa-shield-alt':'fa-user'}"></i>${u.role}</span></td>
        <td>${esc(u.company_name)}</td>
        <td><div style="display:flex;gap:5px">
            <button onclick='editUser(${u.id}, ${JSON.stringify(String(u.username||""))}, ${JSON.stringify(String(u.role||""))}, ${JSON.stringify(String(u.company_id||""))})' class="btn btn-g btn-xs"><i class="fas fa-edit"></i></button>
            <button onclick='deleteUser(${u.id}, ${JSON.stringify(String(u.username||""))})' class="btn btn-r btn-xs"><i class="fas fa-trash"></i></button>
        </div></td>
    </tr>`).join('');
}
async function addUser(){
    const username=document.getElementById('nu-username').value.trim();
    const password=document.getElementById('nu-password').value;
    const role=document.getElementById('nu-role').value;
    const company_id=document.getElementById('nu-company').value;
    if(!username||!password||!company_id){ toast('Tous les champs requis','error'); return; }
    const r=await api('add_user',{},{username,password,role,company_id});
    if(r.ok){ toast('Utilisateur créé !','success',username); closeModal('modal-add-user'); loadUsers(); document.getElementById('nu-username').value=''; document.getElementById('nu-password').value=''; }
    else toast(r.msg||'Erreur','error');
}
function editUser(id,username,role,company_id){
    document.getElementById('eu-id').value=id;
    document.getElementById('eu-username').value=username;
    document.getElementById('eu-role').value=role;
    document.getElementById('eu-company').value=company_id||'';
    document.getElementById('eu-password').value='';
    openModal('modal-edit-user');
}
async function updateUser(){
    const id=document.getElementById('eu-id').value;
    const username=document.getElementById('eu-username').value.trim();
    const password=document.getElementById('eu-password').value;
    const role=document.getElementById('eu-role').value;
    const company_id=document.getElementById('eu-company').value;
    const r=await api('update_user',{},{id,username,password,role,company_id});
    if(r.ok){ toast('Utilisateur modifié','success'); closeModal('modal-edit-user'); loadUsers(); }
    else toast('Erreur','error');
}
function deleteUser(id,name){
    confirm2(`Supprimer l'utilisateur "${name}" ? Cette action est irréversible.`,async()=>{
        const r=await api('delete_user',{id});
        if(r.ok){ toast('Utilisateur supprimé','warn'); loadUsers(); }
        else toast(r.msg||'Erreur','error');
    });
}

/* ═══════════════════════════════════════════════
   🏢 COMPANIES
═══════════════════════════════════════════════ */
async function loadCompanies(){
    const q=document.getElementById('co-search')?.value||'';
    const data=await api('get_companies',{q});
    const tbody=document.getElementById('companies-tbody');
    if(!tbody) return;
    if(!data.length){ tbody.innerHTML='<tr><td colspan="4"><div class="empty-state"><i class="fas fa-building"></i><h3>Aucune société</h3></div></td></tr>'; return; }
    tbody.innerHTML=data.map(c=>`
    <tr>
        <td><strong style="color:var(--cyan)">#${c.id}</strong></td>
        <td><strong>${esc(c.name)}</strong></td>
        <td style="color:var(--muted);font-size:12px">${c.created_at||'—'}</td>
        <td><div style="display:flex;gap:5px">
            <button onclick='editCompany(${c.id}, ${JSON.stringify(String(c.name||""))})' class="btn btn-g btn-xs"><i class="fas fa-edit"></i></button>
            <button onclick='deleteCompany(${c.id}, ${JSON.stringify(String(c.name||""))})' class="btn btn-r btn-xs"><i class="fas fa-trash"></i></button>
        </div></td>
    </tr>`).join('');
}
async function addCompany(){
    const name=document.getElementById('nco-name').value.trim();
    if(!name){ toast('Nom requis','error'); return; }
    const r=await api('add_company',{},{name});
    if(r.ok){ toast('Société créée !','success',name); closeModal('modal-add-co'); loadCompanies(); document.getElementById('nco-name').value=''; }
    else toast('Erreur','error');
}
function editCompany(id,name){ document.getElementById('eco-id').value=id; document.getElementById('eco-name').value=name; openModal('modal-edit-co'); }
async function updateCompany(){
    const id=document.getElementById('eco-id').value;
    const name=document.getElementById('eco-name').value.trim();
    const r=await api('update_company',{},{id,name});
    if(r.ok){ toast('Société modifiée','success'); closeModal('modal-edit-co'); loadCompanies(); }
    else toast('Erreur','error');
}
function deleteCompany(id,name){
    confirm2(`Supprimer la société "${name}" ? Toutes les villes associées seront également supprimées.`,async()=>{
        const r=await api('delete_company',{id});
        if(r.ok){ toast('Société supprimée','warn'); loadCompanies(); }
    });
}

/* ═══════════════════════════════════════════════
   🏙️ CITIES
═══════════════════════════════════════════════ */
async function loadCities(){
    const q=document.getElementById('city-search')?.value||'';
    const company_id=document.getElementById('city-co-filter')?.value||0;
    const data=await api('get_cities',{q,company_id});
    const tbody=document.getElementById('cities-tbody');
    if(!tbody) return;
    if(!data.length){ tbody.innerHTML='<tr><td colspan="4"><div class="empty-state"><i class="fas fa-city"></i><h3>Aucun magasin</h3></div></td></tr>'; return; }
    tbody.innerHTML=data.map(c=>`
    <tr>
        <td><strong style="color:var(--cyan)">#${c.id}</strong></td>
        <td><strong>${esc(c.name)}</strong></td>
        <td><span style="font-size:11px;padding:3px 10px;border-radius:14px;background:rgba(6,182,212,0.1);color:var(--cyan);font-weight:700">${esc(c.company_name)}</span></td>
        <td><div style="display:flex;gap:5px">
            <button onclick='editCity(${c.id}, ${JSON.stringify(String(c.name||""))}, ${JSON.stringify(String(c.company_id||""))})' class="btn btn-g btn-xs"><i class="fas fa-edit"></i></button>
            <button onclick='deleteCity(${c.id}, ${JSON.stringify(String(c.name||""))})' class="btn btn-r btn-xs"><i class="fas fa-trash"></i></button>
        </div></td>
    </tr>`).join('');
}
async function addCity(){
    const name=document.getElementById('ncity-name').value.trim();
    const company_id=document.getElementById('ncity-company').value;
    if(!name||!company_id){ toast('Nom et société requis','error'); return; }
    const r=await api('add_city',{},{name,company_id});
    if(r.ok){ toast('Magasin créé !','success',name); closeModal('modal-add-city'); loadCities(); document.getElementById('ncity-name').value=''; }
    else toast('Erreur','error');
}
function editCity(id,name,company_id){ document.getElementById('ecity-id').value=id; document.getElementById('ecity-name').value=name; document.getElementById('ecity-company').value=company_id||''; openModal('modal-edit-city'); }
async function updateCity(){
    const id=document.getElementById('ecity-id').value;
    const name=document.getElementById('ecity-name').value.trim();
    const company_id=document.getElementById('ecity-company').value;
    const r=await api('update_city',{},{id,name,company_id});
    if(r.ok){ toast('Magasin modifié','success'); closeModal('modal-edit-city'); loadCities(); }
}
function deleteCity(id,name){
    confirm2(`Supprimer le magasin "${name}" ?`,async()=>{
        const r=await api('delete_city',{id});
        if(r.ok){ toast('Magasin supprimé','warn'); loadCities(); }
    });
}

/* ═══════════════════════════════════════════════
   📦 PRODUCTS — Société + Ville obligatoires
═══════════════════════════════════════════════ */
async function loadProducts(){
    const q=document.getElementById('prod-search')?.value||'';
    const company_id=document.getElementById('prod-co-filter')?.value||0;
    const data=await api('get_products',{q,company_id});
    window.__adminProducts = Array.isArray(data) ? data : [];
    /* Met à jour le compteur live en haut de page */
    animateCount(document.getElementById('global-prod-count'), Array.isArray(data)?data.length:0);
    const tbody=document.getElementById('products-tbody');
    if(!tbody) return;
    if(!data.length){ tbody.innerHTML='<tr><td colspan="8"><div class="empty-state"><i class="fas fa-box"></i><h3>Aucun produit</h3></div></td></tr>'; return; }
    tbody.innerHTML=data.map(p=>`
    <tr>
        <td><strong style="color:var(--cyan)">#${p.id}</strong></td>
        <td><div class="product-thumb">${p.image_url ? `<img src="${esc(p.image_url)}" alt="${esc(p.name)}">` : '<i class="fas fa-image"></i>'}</div></td>
        <td><strong>${esc(p.name)}</strong></td>
        <td><span style="font-size:11px;padding:3px 9px;border-radius:13px;background:rgba(50,190,143,0.1);color:var(--neon);font-weight:700">${esc(p.category||'—')}</span></td>
        <td><span style="font-family:var(--fh);font-size:14px;font-weight:900;color:var(--gold)">${formatPrice(p.price)}</span></td>
        <td><span style="color:${p.alert_quantity>10?'var(--orange)':'var(--muted)'};font-size:12px">${p.alert_quantity}</span></td>
        <td><span style="font-size:11px;padding:3px 9px;border-radius:13px;background:rgba(6,182,212,0.1);color:var(--cyan);font-weight:700">${esc(p.company_name)}</span></td>
        <td><div style="display:flex;gap:6px;align-items:center">
            <button onclick="editProductById(${p.id})" class="btn btn-g btn-xs"><i class="fas fa-edit"></i> Modifier</button>
            <button onclick='openDeleteProduct(${p.id}, ${JSON.stringify(String(p.name||""))})' class="btn btn-r" style="font-size:12px;padding:5px 12px;font-weight:700;letter-spacing:.3px"><i class="fas fa-trash-alt"></i> Supprimer</button>
        </div></td>
    </tr>`).join('');
}
function editProductById(id){
    const products = window.__adminProducts || [];
    const product = products.find(p => Number(p.id) === Number(id));
    if(!product){
        toast('Produit introuvable','error');
        return;
    }
    editProduct(product);
}
/* Charger les villes selon société choisie dans modal produit */
async function loadCitiesForProduct(){
    const cid=document.getElementById('np-company').value;
    const cs=document.getElementById('np-city');
    cs.innerHTML='<option value="">Chargement…</option>';
    cs.disabled=true;
    /* Masque l'historique et le compteur quand on change de société */
    const wrap=document.getElementById('np-city-history');
    if(wrap) wrap.style.display='none';
    const cw=document.getElementById('np-city-count-wrap');
    if(cw) cw.style.display='none';
    if(!cid){ cs.innerHTML='<option value="">— Choisir société d\'abord —</option>'; return; }
    const cities=await api('get_cities_by_company',{company_id:cid});
    if(!cities.length){ cs.innerHTML='<option value="">Aucun magasin pour cette société</option>'; return; }
    cs.innerHTML='<option value="">— Sélectionner le magasin —</option>' + cities.map(c=>`<option value="${c.id}">${esc(c.name)}</option>`).join('');
    cs.disabled=false;
}
/* Déclenché quand l'utilisateur choisit une ville dans le modal */
function onCitySelectForProduct(){
    const city_id=document.getElementById('np-city').value;
    if(city_id) loadCityProductHistory(city_id);
    else {
        const wrap=document.getElementById('np-city-history');
        if(wrap) wrap.style.display='none';
        const cw=document.getElementById('np-city-count-wrap');
        if(cw) cw.style.display='none';
    }
}
function bindProductImagePreview(inputId, previewId){
    const input=document.getElementById(inputId);
    const preview=document.getElementById(previewId);
    if(!input || !preview || input.dataset.bound==='1') return;
    input.dataset.bound='1';
    input.addEventListener('change',()=>{
        const file=input.files?.[0];
        if(!file){
            setProductImagePreview(previewId, null);
            return;
        }
        const url=URL.createObjectURL(file);
        preview.classList.remove('empty');
        preview.innerHTML=`<img src="${url}" alt="Aperçu produit">`;
    });
}
function setProductImagePreview(previewId, imageUrl=null){
    const preview=document.getElementById(previewId);
    if(!preview) return;
    if(imageUrl){
        preview.classList.remove('empty');
        preview.innerHTML=`<img src="${esc(imageUrl)}" alt="Image produit">`;
    }else{
        preview.classList.add('empty');
        preview.innerHTML='<span><i class="fas fa-image"></i><br>Aucune image</span>';
    }
}
async function uploadProductImage(productId, file){
    const fd=new FormData();
    fd.append('product_id', productId);
    fd.append('image', file);
    const r=await fetch('?ajax=upload_product_image', {
        method:'POST',
        headers:{'X-Requested-With':'XMLHttpRequest'},
        body:fd
    });
    return r.json();
}
async function addProduct(force=false){
    const company_id=document.getElementById('np-company').value;
    const city_id=document.getElementById('np-city').value;
    const name=document.getElementById('np-name').value.trim();
    const category=document.getElementById('np-category').value.trim();
    const price=document.getElementById('np-price').value;
    const alert_quantity=document.getElementById('np-alert').value||5;
    const imageFile=document.getElementById('np-image').files?.[0]||null;
    if(!company_id){ toast('Sélectionnez une société !','error'); document.getElementById('np-company').focus(); return; }
    if(!city_id){ toast('Sélectionnez un magasin / ville !','error'); document.getElementById('np-city').focus(); return; }
    if(!name){ toast('Nom du produit requis','error'); return; }
    if(!price||parseFloat(price)<0){ toast('Prix requis','error'); return; }
    const btn=document.getElementById('btn-add-prod');
    btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Création…';
    const r=await api('add_product',{},{company_id,city_id,name,category,price,alert_quantity,force:force?1:0});
    btn.disabled=false; btn.innerHTML='<i class="fas fa-save"></i> Créer le produit';
    if(r.doublon){
        /* Doublon détecté — demande confirmation avant de forcer */
        confirm2(`⚠️ Doublon détecté !\n\n${r.msg}\n\nVoulez-vous quand même créer ce produit ?`, ()=>addProduct(true));
        return;
    }
    if(r.ok){
        if(imageFile){
            const up=await uploadProductImage(r.id, imageFile);
            if(!up.ok) toast(up.msg||'Image non enregistrée','warn');
        }
        toast('Produit créé !','success',`${name} — Stock initialisé à 0`);
        document.getElementById('np-name').value='';
        document.getElementById('np-price').value='';
        document.getElementById('np-category').value='';
        document.getElementById('np-alert').value='5';
        document.getElementById('np-image').value='';
        setProductImagePreview('np-image-preview', null);
        loadProducts();
        loadCityProductHistory(city_id);
    }
    else toast(r.msg||'Erreur création produit','error');
}
function animateCount(el, target){
    if(!el) return;
    const start=parseInt(el.textContent)||0;
    if(start===target){ el.textContent=target; return; }
    const step=Math.ceil(Math.abs(target-start)/12);
    let cur=start;
    const tick=()=>{
        cur+=(target>cur?1:-1)*step;
        if((target>start&&cur>=target)||(target<start&&cur<=target)) cur=target;
        el.textContent=cur;
        el.style.transform='scale(1.12)';
        setTimeout(()=>{ el.style.transform='scale(1)'; },120);
        if(cur!==target) requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
}
async function loadCityProductHistory(city_id){
    if(!city_id) return;
    const wrap=document.getElementById('np-city-history');
    const list=document.getElementById('np-city-history-list');
    const counter=document.getElementById('np-city-count');
    const countWrap=document.getElementById('np-city-count-wrap');
    if(!wrap||!list) return;
    list.innerHTML='<div style="color:var(--muted);font-size:12px;padding:6px 0"><i class="fas fa-spinner fa-spin"></i> Chargement…</div>';
    wrap.style.display='block';
    if(countWrap) countWrap.style.display='flex';
    const data=await api('get_products',{city_id,q:'',company_id:0});
    const n=Array.isArray(data)?data.length:0;
    animateCount(counter, n);
    if(!n){
        list.innerHTML='<div style="color:var(--muted);font-size:12px;padding:6px 0"><i class="fas fa-inbox"></i> Aucun produit pour ce magasin.</div>';
        return;
    }
    list.innerHTML=data.map(p=>`
        <div style="display:flex;align-items:center;gap:10px;background:rgba(255,255,255,0.04);border-radius:8px;padding:7px 10px">
            <div style="width:32px;height:32px;border-radius:6px;overflow:hidden;flex-shrink:0;background:rgba(255,255,255,0.07);display:flex;align-items:center;justify-content:center">
                ${p.image_url?`<img src="${esc(p.image_url)}" style="width:100%;height:100%;object-fit:cover" alt="">`:'<i class="fas fa-box" style="color:var(--muted);font-size:13px"></i>'}
            </div>
            <div style="flex:1;min-width:0">
                <div style="font-weight:700;font-size:13px;color:var(--light);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(p.name)}</div>
                <div style="font-size:11px;color:var(--muted)">${esc(p.category||'—')} · <span style="color:var(--gold);font-weight:700">${formatPrice(p.price)}</span></div>
            </div>
            <span style="font-size:11px;color:var(--cyan);background:rgba(6,182,212,0.1);padding:2px 8px;border-radius:10px;flex-shrink:0">#${p.id}</span>
        </div>`).join('');
}
function editProduct(p){
    document.getElementById('ep-id').value=p.id;
    document.getElementById('ep-name').value=p.name;
    document.getElementById('ep-category').value=p.category||'';
    document.getElementById('ep-price').value=p.price;
    document.getElementById('ep-alert').value=p.alert_quantity||5;
    document.getElementById('ep-image').value='';
    setProductImagePreview('ep-image-preview', p.image_url || null);
    document.getElementById('ep-remove-image').style.display = p.image_url ? 'inline-flex' : 'none';
    openModal('modal-edit-prod');
}
async function updateProduct(){
    const id=document.getElementById('ep-id').value;
    const name=document.getElementById('ep-name').value.trim();
    const category=document.getElementById('ep-category').value.trim();
    const price=document.getElementById('ep-price').value;
    const alert_quantity=document.getElementById('ep-alert').value;
    const imageFile=document.getElementById('ep-image').files?.[0]||null;
    const r=await api('update_product',{},{id,name,category,price,alert_quantity});
    if(r.ok){
        if(imageFile){
            const up=await uploadProductImage(id, imageFile);
            if(!up.ok) toast(up.msg||'Image non enregistrée','warn');
        }
        toast('Produit modifié','success'); closeModal('modal-edit-prod'); loadProducts();
    }
    else toast('Erreur','error');
}
async function removeProductImage(){
    const id=document.getElementById('ep-id').value;
    if(!id) return;
    const r=await fetch(`?ajax=delete_product_image&product_id=${encodeURIComponent(id)}`,{
        method:'POST',
        headers:{'X-Requested-With':'XMLHttpRequest'}
    });
    const data=await r.json();
    if(data.ok){
        document.getElementById('ep-image').value='';
        document.getElementById('ep-remove-image').style.display='none';
        setProductImagePreview('ep-image-preview', null);
        toast('Image supprimée','warn');
        loadProducts();
    } else {
        toast(data.msg||'Suppression impossible','error');
    }
}
let __delProdId=null, __delProdName='';
function openDeleteProduct(id, name){
    __delProdId=id; __delProdName=name;
    document.getElementById('del-prod-name').textContent=name;
    document.getElementById('del-prod-confirm-hint').textContent=name;
    document.getElementById('del-prod-input').value='';
    const btn=document.getElementById('btn-confirm-delete-prod');
    btn.disabled=true; btn.style.opacity='.4'; btn.style.cursor='not-allowed';
    openModal('modal-delete-prod');
    setTimeout(()=>document.getElementById('del-prod-input').focus(),200);
}
function checkDeleteConfirm(){
    const val=document.getElementById('del-prod-input').value;
    const btn=document.getElementById('btn-confirm-delete-prod');
    const ok=val.trim().toLowerCase()===__delProdName.trim().toLowerCase();
    btn.disabled=!ok;
    btn.style.opacity=ok?'1':'.4';
    btn.style.cursor=ok?'pointer':'not-allowed';
}
async function confirmDeleteProduct(){
    if(!__delProdId) return;
    const btn=document.getElementById('btn-confirm-delete-prod');
    btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Suppression…';
    const r=await api('delete_product',{id:__delProdId});
    if(r.ok){
        toast(`"${__delProdName}" supprimé`,'warn');
        closeModal('modal-delete-prod');
        loadProducts();
    } else {
        toast(r.msg||'Erreur lors de la suppression','error');
        btn.disabled=false; btn.innerHTML='<i class="fas fa-trash-alt"></i> Supprimer définitivement';
    }
}
/* Kept for backward compat (used elsewhere e.g. history cards) */
function deleteProduct(id,name){ openDeleteProduct(id,name); }

/* ═══════════════════════════════════════════════
   🏷️ PROMOTIONS
═══════════════════════════════════════════════ */
window.__adminPromotions = [];
window.__promotionProducts = [];
async function loadPromotions(){
    const q=document.getElementById('promo-search')?.value||'';
    const company_id=document.getElementById('promo-co-filter')?.value||0;
    const rows=await api('get_promotions',{q,company_id});
    window.__adminPromotions = Array.isArray(rows) ? rows : [];
    const tbody=document.getElementById('promotions-tbody');
    if(!tbody) return;
    if(!rows.length){
        tbody.innerHTML='<tr><td colspan="8"><div class="empty-state"><i class="fas fa-tags"></i><h3>Aucune promotion</h3></div></td></tr>';
        return;
    }
    tbody.innerHTML=rows.map(p=>`
        <tr>
            <td><strong style="color:var(--cyan)">#${p.id}</strong></td>
            <td>
                <strong>${esc(p.title)}</strong>
                <div style="font-size:11px;color:var(--muted);margin-top:4px">${esc(p.subtitle||p.product_name||'—')}</div>
            </td>
            <td><span class="pbadge ${p.promo_type==='flash'?'o':p.promo_type==='pack'?'b':p.promo_type==='quantity'?'c':'r'}">${esc(p.promo_type)}</span></td>
            <td><strong style="color:var(--neon)">${formatPrice(p.promo_price||0)}</strong></td>
            <td><div style="font-size:11px;line-height:1.6"><strong>${esc(p.company_name||'—')}</strong><br><span style="color:var(--muted)">${esc(p.city_name||'—')}</span></div></td>
            <td><div style="font-size:11px;line-height:1.6">${p.starts_at||'—'}<br><span style="color:var(--muted)">${p.ends_at||'Sans fin'}</span></div></td>
            <td><button onclick="togglePromotion(${p.id},${p.is_active?0:1})" class="btn ${p.is_active?'btn-g':'btn-b'} btn-xs">${p.is_active?'Active':'Inactive'}</button></td>
            <td><div style="display:flex;gap:5px">
                <button onclick="editPromotionById(${p.id})" class="btn btn-g btn-xs"><i class="fas fa-edit"></i></button>
                <button onclick='deletePromotion(${p.id}, ${JSON.stringify(String(p.title||""))})' class="btn btn-r btn-xs"><i class="fas fa-trash"></i></button>
            </div></td>
        </tr>
    `).join('');
}
async function loadPromotionCities(selected=''){
    const cid=document.getElementById('pm-company').value;
    const citySelect=document.getElementById('pm-city');
    citySelect.innerHTML='<option value="">Chargement…</option>';
    citySelect.disabled=true;
    if(!cid){
        citySelect.innerHTML="<option value=\"\">— Choisir société d'abord —</option>";
        return;
    }
    const cities=await api('get_cities_by_company',{company_id:cid});
    citySelect.innerHTML='<option value="">— Sélectionner —</option>'+cities.map(c=>`<option value="${c.id}">${esc(c.name)}</option>`).join('');
    citySelect.disabled=false;
    if(selected) citySelect.value=String(selected);
}
async function loadPromotionProducts(selected=''){
    const companyId=document.getElementById('pm-company').value||0;
    const cityId=document.getElementById('pm-city').value||0;
    const products=await api('get_products_by_context',{company_id:companyId,city_id:cityId});
    window.__promotionProducts = Array.isArray(products) ? products : [];
    const select=document.getElementById('pm-product');
    if(!select) return;
    select.innerHTML='<option value="">— Sélectionner —</option>'+window.__promotionProducts.map(p=>`<option value="${p.id}">#${p.id} · ${esc(p.name)} · ${formatPrice(p.price)}</option>`).join('');
    if(selected) select.value=String(selected);
}
function togglePromotionFields(){
    const type=document.getElementById('pm-type').value;
    document.getElementById('pm-items-wrap').style.display=type==='pack'?'block':'none';
    document.getElementById('pm-quantity-row').style.display=type==='quantity'?'grid':'none';
    if(type==='pack') document.getElementById('pm-filter').value='pack';
    if(type==='flash') document.getElementById('pm-filter').value='flash';
}
function resetPromotionForm(){
    document.getElementById('pm-id').value='';
    document.getElementById('pm-title').value='';
    document.getElementById('pm-subtitle').value='';
    document.getElementById('pm-type').value='simple';
    document.getElementById('pm-filter').value='reduction';
    document.getElementById('pm-badge').value='PROMO';
    document.getElementById('pm-company').value='';
    document.getElementById('pm-city').innerHTML='<option value="">— Choisir société d\'abord —</option>';
    document.getElementById('pm-city').disabled=true;
    document.getElementById('pm-product').innerHTML='<option value="">— Sélectionner —</option>';
    document.getElementById('pm-sort').value='0';
    document.getElementById('pm-old-price').value='';
    document.getElementById('pm-price').value='';
    document.getElementById('pm-discount').value='';
    document.getElementById('pm-buy').value='';
    document.getElementById('pm-pay').value='';
    document.getElementById('pm-tiers').value='';
    document.getElementById('pm-items').value='';
    document.getElementById('pm-start').value='';
    document.getElementById('pm-end').value='';
    document.getElementById('pm-active').value='1';
    document.getElementById('pm-notify').value='1';
    togglePromotionFields();
}
function toDatetimeLocalValue(v){
    if(!v) return '';
    return String(v).replace(' ','T').slice(0,16);
}
function openPromotionModal(){
    resetPromotionForm();
    openModal('modal-promo');
}
async function editPromotionById(id){
    const promo=(window.__adminPromotions||[]).find(p=>Number(p.id)===Number(id));
    if(!promo){toast('Promotion introuvable','error');return;}
    resetPromotionForm();
    document.getElementById('pm-id').value=promo.id;
    document.getElementById('pm-title').value=promo.title||'';
    document.getElementById('pm-subtitle').value=promo.subtitle||'';
    document.getElementById('pm-type').value=promo.promo_type||'simple';
    document.getElementById('pm-filter').value=promo.filter_tag||'reduction';
    document.getElementById('pm-badge').value=promo.badge_label||'PROMO';
    document.getElementById('pm-company').value=promo.company_id||'';
    await loadPromotionCities(promo.city_id||'');
    await loadPromotionProducts(promo.product_id||'');
    document.getElementById('pm-sort').value=promo.sort_order||0;
    document.getElementById('pm-old-price').value=promo.old_price||'';
    document.getElementById('pm-price').value=promo.promo_price||'';
    document.getElementById('pm-discount').value=promo.discount_percent||'';
    document.getElementById('pm-buy').value=promo.quantity_buy||'';
    document.getElementById('pm-pay').value=promo.quantity_pay||'';
    document.getElementById('pm-tiers').value=promo.tiers_text||'';
    document.getElementById('pm-items').value=(promo.items||[]).map(it=>`${it.product_id}:${it.quantity}`).join('\n');
    document.getElementById('pm-start').value=toDatetimeLocalValue(promo.starts_at);
    document.getElementById('pm-end').value=toDatetimeLocalValue(promo.ends_at);
    document.getElementById('pm-active').value=promo.is_active?'1':'0';
    document.getElementById('pm-notify').value=promo.notify_clients?'1':'0';
    togglePromotionFields();
    openModal('modal-promo');
}
async function savePromotion(){
    const body={
        id:document.getElementById('pm-id').value||0,
        title:document.getElementById('pm-title').value.trim(),
        subtitle:document.getElementById('pm-subtitle').value.trim(),
        promo_type:document.getElementById('pm-type').value,
        filter_tag:document.getElementById('pm-filter').value,
        badge_label:document.getElementById('pm-badge').value.trim(),
        company_id:document.getElementById('pm-company').value,
        city_id:document.getElementById('pm-city').value,
        product_id:document.getElementById('pm-product').value,
        sort_order:document.getElementById('pm-sort').value||0,
        old_price:document.getElementById('pm-old-price').value||0,
        promo_price:document.getElementById('pm-price').value||0,
        discount_percent:document.getElementById('pm-discount').value||0,
        quantity_buy:document.getElementById('pm-buy').value||0,
        quantity_pay:document.getElementById('pm-pay').value||0,
        tiers_text:document.getElementById('pm-tiers').value.trim(),
        items_text:document.getElementById('pm-items').value.trim(),
        starts_at:document.getElementById('pm-start').value,
        ends_at:document.getElementById('pm-end').value,
        is_active:document.getElementById('pm-active').value==='1',
        notify_clients:document.getElementById('pm-notify').value==='1'
    };
    if(!body.title){ toast('Titre obligatoire','error'); return; }
    if(!body.company_id){ toast('Société obligatoire','error'); return; }
    if(!body.city_id){ toast('Magasin / ville obligatoire','error'); return; }
    if(['simple','flash','quantity'].includes(body.promo_type) && !body.product_id){
        toast('Produit principal obligatoire','error');
        return;
    }
    if(body.promo_type==='pack' && !body.items_text){
        toast('Ajoutez les produits du pack','error');
        return;
    }
    const r=await api('save_promotion',{},body);
    if(r.ok){
        const note=(r.summary&&r.summary.emails)?`${r.summary.emails} emails envoyés`:'Promotion enregistrée';
        toast('Promotion enregistrée','success',note);
        closeModal('modal-promo');
        loadPromotions();
    }else{
        toast(r.msg||'Erreur promotion','error');
    }
}
async function togglePromotion(id,enabled){
    const r=await api('toggle_promotion',{id,enabled});
    if(r.ok){ toast(enabled?'Promotion activée':'Promotion désactivée','info'); loadPromotions(); }
}
function deletePromotion(id,title){
    confirm2(`Supprimer la promotion "${title}" ?`,async()=>{
        const r=await api('delete_promotion',{id});
        if(r.ok){ toast('Promotion supprimée','warn'); loadPromotions(); }
    });
}

/* ═══════════════════════════════════════════════
   💎 LOYALTY CRM
═══════════════════════════════════════════════ */
let currentAbandonedStatus='all';
async function loadLoyaltyClients(){
    const q=document.getElementById('loyalty-search')?.value||'';
    const rows=await api('get_loyalty_clients',{q});
    const tbody=document.getElementById('loyalty-tbody');
    if(!tbody) return;
    const clients=Array.isArray(rows)?rows:[];
    const vipCount=clients.filter(c=>String(c.vip_status||'standard')!=='standard').length;
    const totalPoints=clients.reduce((s,c)=>s+Number(c.loyalty_points||0),0);
    document.getElementById('loyalty-total-points').textContent=new Intl.NumberFormat('fr-FR').format(totalPoints);
    document.getElementById('loyalty-vip-count').textContent=vipCount;
    if(!clients.length){
        tbody.innerHTML='<tr><td colspan="8"><div class="empty-state"><i class="fas fa-gem"></i><h3>Aucun client fidélité</h3></div></td></tr>';
        return;
    }
    const badgeClass={standard:'pbadge c',silver:'pbadge b',gold:'pbadge g',platinum:'pbadge p'};
    tbody.innerHTML=clients.map(c=>`
        <tr>
            <td><strong style="color:var(--cyan)">#${c.id}</strong></td>
            <td>
                <strong>${esc(c.name)}</strong>
                <div style="font-size:11px;color:var(--muted);margin-top:3px">${esc(c.phone||'—')} · ${esc(c.email||'—')}</div>
            </td>
            <td><strong style="color:var(--gold)">${new Intl.NumberFormat('fr-FR').format(Number(c.loyalty_points||0))}</strong></td>
            <td><span class="${badgeClass[c.vip_status]||'pbadge c'}">${esc(c.vip_status||'standard')}</span></td>
            <td>${new Intl.NumberFormat('fr-FR').format(Number(c.orders_count||0))}</td>
            <td style="color:var(--neon)">${formatPrice(c.total_spent||0)}</td>
            <td style="font-size:12px;color:var(--muted)">${c.last_order_at||'—'}</td>
            <td><div style="display:flex;gap:5px;flex-wrap:wrap">
                <button onclick='openLoyaltyHistory(${c.id}, ${JSON.stringify(String(c.name||""))})' class="btn btn-c btn-xs"><i class="fas fa-clock-rotate-left"></i> Historique</button>
                <button onclick='setClientVip(${c.id}, "silver")' class="btn btn-b btn-xs">Silver</button>
                <button onclick='setClientVip(${c.id}, "gold")' class="btn btn-g btn-xs">Gold</button>
                <button onclick='setClientVip(${c.id}, "platinum")' class="btn btn-p btn-xs">Platinum</button>
            </div></td>
        </tr>
    `).join('');
}
async function openLoyaltyHistory(clientId, clientName){
    const tbody=document.getElementById('loyalty-history-body');
    const title=document.getElementById('loyalty-history-title');
    if(!tbody || !title) return;
    title.textContent=`Historique fidélité · ${clientName||('Client #'+clientId)}`;
    tbody.innerHTML='<tr><td colspan="5" style="text-align:center;padding:30px;color:var(--muted)">Chargement…</td></tr>';
    openModal('modal-loyalty-history');
    const rows=await api('get_loyalty_transactions',{client_id:clientId});
    const tx=Array.isArray(rows)?rows:[];
    if(!tx.length){
        tbody.innerHTML='<tr><td colspan="5"><div class="empty-state"><i class="fas fa-receipt"></i><h3>Aucune transaction de points</h3></div></td></tr>';
        return;
    }
    tbody.innerHTML=tx.map(t=>`
        <tr>
            <td><strong style="color:var(--cyan)">#${t.id}</strong></td>
            <td style="color:${Number(t.points_delta||0) >= 0 ? 'var(--neon)' : 'var(--danger)'};font-weight:800">${Number(t.points_delta||0) > 0 ? '+' : ''}${new Intl.NumberFormat('fr-FR').format(Number(t.points_delta||0))}</td>
            <td>${esc(t.reason||'—')}</td>
            <td style="font-size:12px;color:var(--muted)">${t.order_number?`Commande ${esc(t.order_number)}`:(t.reference_id?`#${t.reference_id}`:'—')}</td>
            <td style="font-size:12px;color:var(--muted)">${t.created_at||'—'}</td>
        </tr>
    `).join('');
}
async function setClientVip(id,vip){
    const r=await api('set_client_vip',{id,vip});
    if(r.ok){ toast('Statut VIP mis à jour','success',vip); loadLoyaltyClients(); }
    else toast(r.msg||'Erreur','error');
}
async function loadAbandonedCarts(status='all',btn=null){
    currentAbandonedStatus=status;
    const rows=await api('get_abandoned_carts',{status});
    const tbody=document.getElementById('abandoned-tbody');
    if(!tbody) return;
    document.querySelectorAll('[id^="ab-filter-"]').forEach(el=>el.style.boxShadow='none');
    if(btn) btn.style.boxShadow='0 0 18px rgba(255,255,255,0.12)';
    const carts=Array.isArray(rows)?rows:[];
    document.getElementById('loyalty-abandoned-count').textContent=carts.filter(c=>String(c.status)==='active').length;
    document.getElementById('loyalty-reminders-count').textContent=carts.filter(c=>c.reminder_sent_at).length;
    if(!carts.length){
        tbody.innerHTML='<tr><td colspan="8"><div class="empty-state"><i class="fas fa-cart-shopping"></i><h3>Aucun panier abandonné</h3></div></td></tr>';
        return;
    }
    tbody.innerHTML=carts.map(c=>`
        <tr>
            <td><strong style="color:var(--cyan)">#${c.id}</strong></td>
            <td>
                <strong>${esc(c.client_name)}</strong>
                <div style="font-size:11px;color:var(--muted);margin-top:3px">${esc(c.client_phone||'—')} · ${esc(c.client_email||'—')}</div>
            </td>
            <td><strong>${esc(c.company_name)}</strong><div style="font-size:11px;color:var(--muted)">${esc(c.city_name)}</div></td>
            <td style="max-width:260px;font-size:12px;color:var(--text2)">${esc(c.items_preview||'—')}</td>
            <td style="color:var(--gold)">${formatPrice(c.cart_total||0)}</td>
            <td style="font-size:12px;color:var(--muted)">${c.last_activity_at}<br><span class="${String(c.status)==='recovered'?'pbadge g':String(c.status)==='expired'?'pbadge r':'pbadge n'}">${esc(c.status)}</span></td>
            <td style="font-size:12px;color:var(--muted)">${c.reminder_sent_at||'Jamais'}${c.whatsapp_link?`<br><a href="${esc(c.whatsapp_link)}" target="_blank" rel="noopener" style="color:var(--cyan);text-decoration:none;font-weight:700">WhatsApp</a>`:''}</td>
            <td><div style="display:flex;gap:5px;flex-wrap:wrap">
                <button onclick="resendAbandonedCart(${c.id})" class="btn btn-o btn-xs"><i class="fas fa-paper-plane"></i> Relancer</button>
                <button onclick="createAbandonedCartCoupon(${c.id})" class="btn btn-g btn-xs"><i class="fas fa-ticket-alt"></i> Coupon</button>
            </div></td>
        </tr>
    `).join('');
}
async function loadCouponStats(){
    const stats=await api('get_coupon_stats');
    const totalEl=document.getElementById('coupon-total-count');
    const activeEl=document.getElementById('coupon-active-count');
    const recoveredEl=document.getElementById('coupon-recovered-count');
    const rateEl=document.getElementById('coupon-recovery-rate');
    if(totalEl) totalEl.textContent=new Intl.NumberFormat('fr-FR').format(Number(stats.total_coupons||0));
    if(activeEl) activeEl.textContent=new Intl.NumberFormat('fr-FR').format(Number(stats.active_coupons||0));
    if(recoveredEl) recoveredEl.textContent=new Intl.NumberFormat('fr-FR').format(Number(stats.recovered_orders||0));
    if(rateEl) rateEl.textContent=`${Number(stats.recovery_rate||0).toLocaleString('fr-FR')}%`;
}
async function loadPersonalizedCoupons(){
    const q=document.getElementById('coupon-search')?.value||'';
    const status=document.getElementById('coupon-status-filter')?.value||'all';
    const rows=await api('get_personalized_coupons',{q,status});
    const tbody=document.getElementById('coupon-tbody');
    if(!tbody) return;
    const coupons=Array.isArray(rows)?rows:[];
    if(!coupons.length){
        tbody.innerHTML='<tr><td colspan="8"><div class="empty-state"><i class="fas fa-ticket-alt"></i><h3>Aucun coupon personnalisé</h3></div></td></tr>';
        return;
    }
    tbody.innerHTML=coupons.map(c=>`
        <tr>
            <td><strong style="color:var(--purple)">${esc(c.code)}</strong><div style="font-size:11px;color:var(--muted);margin-top:3px">${esc(c.title||'Coupon personnalisé')}</div></td>
            <td><strong>${esc(c.client_name)}</strong><div style="font-size:11px;color:var(--muted);margin-top:3px">${esc(c.client_phone||'—')} · ${esc(c.client_email||'—')}</div></td>
            <td><strong style="color:var(--neon)">${c.discount_percent?('-'+Number(c.discount_percent).toLocaleString('fr-FR')+'%'):formatPrice(c.amount_off||0)}</strong><div style="font-size:11px;color:var(--muted);margin-top:3px">Min: ${formatPrice(c.min_amount||0)}</div></td>
            <td><strong>#${c.abandoned_cart_id||'—'}</strong><div style="font-size:11px;color:var(--muted);margin-top:3px">${c.cart_total?formatPrice(c.cart_total):'—'}</div></td>
            <td><span class="${c.is_currently_active?'pbadge g':'pbadge r'}">${esc(c.status||'active')}</span></td>
            <td style="font-size:12px;color:var(--muted)">${c.expires_at||'Sans limite'}</td>
            <td><span class="${c.is_recovered?'pbadge g':'pbadge c'}">${c.is_recovered?'Récupéré':'En attente'}</span></td>
            <td><button onclick="togglePersonalizedCoupon(${c.id}, '${c.is_currently_active?'expired':'active'}')" class="btn ${c.is_currently_active?'btn-r':'btn-g'} btn-xs">${c.is_currently_active?'Désactiver':'Activer'}</button></td>
        </tr>
    `).join('');
}
async function resendAbandonedCart(id){
    const r=await api('resend_abandoned_cart',{id});
    if(r.ok){
        toast('Relance envoyée','success',r.email_sent?'Email envoyé':'Notification/WhatsApp prêts');
        loadAbandonedCarts(currentAbandonedStatus, document.getElementById(`ab-filter-${currentAbandonedStatus}`));
    } else {
        toast(r.msg||'Erreur de relance','error');
    }
}
async function createAbandonedCartCoupon(id){
    const r=await api('create_abandoned_cart_coupon',{id});
    if(r.ok){
        toast(`Coupon ${r.code} créé`,'success',r.email_sent?'Email envoyé':'Notification créée');
        loadAbandonedCarts(currentAbandonedStatus, document.getElementById(`ab-filter-${currentAbandonedStatus}`));
        loadCouponStats();
        loadPersonalizedCoupons();
    } else {
        toast(r.msg||'Erreur coupon','error');
    }
}
async function togglePersonalizedCoupon(id,status){
    const r=await api('toggle_personalized_coupon',{id,status});
    if(r.ok){
        toast(status==='active'?'Coupon activé':'Coupon désactivé','success');
        loadCouponStats();
        loadPersonalizedCoupons();
    } else {
        toast(r.msg||'Erreur coupon','error');
    }
}

/* ═══════════════════════════════════════════════
   📦 STOCK RAPIDE
═══════════════════════════════════════════════ */
async function loadQuickStock(){
    const cid=document.getElementById('global-company').value;
    const vid=document.getElementById('global-city').value;
    const container=document.getElementById('stock-content');
    if(!container) return;
    if(!cid||!vid){ container.innerHTML='<div class="empty-state"><i class="fas fa-warehouse"></i><h3>Sélectionnez société + ville</h3><p>Utilisez les sélecteurs dans la barre du haut</p></div>'; return; }
    container.innerHTML='<div style="text-align:center;padding:24px;color:var(--muted)"><i class="fas fa-spinner fa-spin"></i> Chargement…</div>';
    const data=await api('quick_stock',{company_id:cid,city_id:vid});
    if(!data.length){ container.innerHTML='<div class="empty-state"><i class="fas fa-box-open"></i><h3>Aucun produit</h3><p>Aucun mouvement de stock pour ce magasin</p></div>'; return; }
    /* Grouper par catégorie */
    const cats={};
    data.forEach(p=>{ const c=p.category||'Non catégorisé'; if(!cats[c]) cats[c]=[]; cats[c].push(p); });
    let html='';
    for(const [cat,prods] of Object.entries(cats)){
        const low=prods.filter(p=>p.stock<=p.alert_quantity&&p.stock>0).length;
        const out=prods.filter(p=>p.stock<=0).length;
        html+=`<div style="margin-bottom:20px">
        <div style="font-family:var(--fh);font-size:13px;font-weight:900;color:var(--text);margin-bottom:10px;
            display:flex;align-items:center;gap:10px;padding:8px 14px;background:rgba(0,0,0,0.2);border-radius:10px">
            <i class="fas fa-tag" style="color:var(--cyan)"></i> ${esc(cat)}
            <span style="margin-left:auto;font-size:11px;color:var(--muted)">${prods.length} produit(s)</span>
            ${out?`<span style="font-size:11px;padding:2px 9px;border-radius:12px;background:rgba(255,53,83,0.12);color:var(--red);font-weight:800">${out} rupture</span>`:''}
            ${low?`<span style="font-size:11px;padding:2px 9px;border-radius:12px;background:rgba(255,145,64,0.12);color:var(--orange);font-weight:800">${low} alerte</span>`:''}
        </div>
        <div class="tbl-wrap"><table class="tbl">
        <thead><tr><th>#</th><th>Produit</th><th>Stock actuel</th><th>Alerte</th><th>Statut</th></tr></thead>
        <tbody>`;
        prods.forEach(p=>{
            const s=parseFloat(p.stock)||0;
            const a=parseFloat(p.alert_quantity)||5;
            const maxS=Math.max(s,a*3);
            const pct=Math.min(100,maxS>0?(s/maxS)*100:0);
            const sc=s<=0?'stock-out':(s<=a?'stock-low':'stock-ok');
            const sl=s<=0?'Rupture':(s<=a?'Alerte':'OK');
            const bc=s<=0?'var(--red)':(s<=a?'var(--orange)':'var(--neon)');
            html+=`<tr>
                <td style="color:var(--muted)">#${p.id}</td>
                <td><strong>${esc(p.name)}</strong></td>
                <td><span class="${sc}">${s}</span>
                    <span class="stock-bar"><span class="stock-bar-fill" style="width:${pct}%;background:${bc}"></span></span>
                </td>
                <td style="color:var(--muted);font-size:12px">${a}</td>
                <td><span style="font-size:11px;padding:2px 9px;border-radius:12px;font-weight:800;
                    background:${s<=0?'rgba(255,53,83,0.12)':(s<=a?'rgba(255,145,64,0.12)':'rgba(50,190,143,0.12)')};
                    color:${s<=0?'var(--red)':(s<=a?'var(--orange)':'var(--neon)')}">${sl}</span></td>
            </tr>`;
        });
        html+='</tbody></table></div></div>';
    }
    container.innerHTML=html;
}

/* ═══════════════════════════════════════════════
   📜 LOGS
═══════════════════════════════════════════════ */
let logCharts={};
async function loadLogs(){
    const data=await api('get_logs');
    const tbody=document.getElementById('logs-tbody');
    if(!tbody) return;
    if(!data.length){ tbody.innerHTML='<tr><td colspan="5"><div class="empty-state"><i class="fas fa-history"></i><h3>Aucun log</h3></div></td></tr>'; return; }
    const actionColors={'add_user':'var(--neon)','update_user':'var(--gold)','delete_user':'var(--red)',
        'add_product':'var(--cyan)','update_product':'var(--gold)','delete_product':'var(--red)',
        'add_company':'var(--blue)','database_backup':'var(--purple)','optimize_db':'var(--neon)',
        'clear_logs':'var(--red)','AUTO_CONFIRMATION':'var(--purple)'};
    tbody.innerHTML=data.map(l=>{
        const c=actionColors[l.action]||'var(--muted)';
        return `<tr>
            <td><strong style="color:var(--muted)">#${l.id}</strong></td>
            <td><strong>${esc(l.username)}</strong></td>
            <td><span style="font-size:11px;padding:3px 10px;border-radius:14px;border:1px solid ${c}33;color:${c};background:${c}15;font-weight:800">${esc(l.action)}</span></td>
            <td style="max-width:260px;font-size:12px;color:var(--muted)">${esc(l.details||'—').substring(0,80)}</td>
            <td style="font-size:12px;color:var(--muted);white-space:nowrap">${l.created_at}</td>
        </tr>`;
    }).join('');

    /* Charts */
    const charts=await api('logs_chart');
    renderLogCharts(charts);
}
function renderLogCharts(d){
    /* Destroy previous */
    ['chart-log-users','chart-log-actions','chart-log-timeline'].forEach(id=>{
        if(logCharts[id]){ logCharts[id].destroy(); delete logCharts[id]; }
    });
    if(document.getElementById('chart-log-users')&&d.top_users?.length){
        logCharts['chart-log-users']=new Chart(document.getElementById('chart-log-users').getContext('2d'),{
            type:'bar',
            data:{labels:d.top_users.map(u=>u.name),datasets:[{label:'Actions',
                data:d.top_users.map(u=>u.cnt),backgroundColor:'rgba(168,85,247,0.75)',borderRadius:6}]},
            options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},
                scales:{x:{ticks:{color:'#8a9fad',font:{size:10}},grid:{color:'rgba(255,255,255,0.03)'}},
                    y:{ticks:{color:'#8a9fad'},grid:{color:'rgba(255,255,255,0.03)'},beginAtZero:true}}}
        });
    }
    if(document.getElementById('chart-log-actions')&&d.top_actions?.length){
        logCharts['chart-log-actions']=new Chart(document.getElementById('chart-log-actions').getContext('2d'),{
            type:'doughnut',
            data:{labels:d.top_actions.map(a=>a.action),datasets:[{data:d.top_actions.map(a=>a.cnt),
                backgroundColor:['rgba(168,85,247,0.8)','rgba(6,182,212,0.8)','rgba(50,190,143,0.8)','rgba(255,208,96,0.8)','rgba(255,53,83,0.8)','rgba(61,140,255,0.8)','rgba(255,145,64,0.8)','rgba(236,72,153,0.8)'],
                borderColor:'#22324a',borderWidth:2}]},
            options:{responsive:true,maintainAspectRatio:false,cutout:'60%',
                plugins:{legend:{position:'bottom',labels:{color:'#b8d8cc',font:{size:10},padding:8}}}}
        });
    }
    if(document.getElementById('chart-log-timeline')&&d.timeline?.length){
        logCharts['chart-log-timeline']=new Chart(document.getElementById('chart-log-timeline').getContext('2d'),{
            type:'line',
            data:{labels:d.timeline.map(t=>t.d),datasets:[{label:'Actions/jour',data:d.timeline.map(t=>t.cnt),
                borderColor:'var(--neon)',backgroundColor:'rgba(50,190,143,0.1)',tension:0.4,fill:true,pointRadius:4,
                pointBackgroundColor:'var(--neon)'}]},
            options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},
                scales:{x:{ticks:{color:'#8a9fad',font:{size:10}},grid:{color:'rgba(255,255,255,0.03)'}},
                    y:{ticks:{color:'#8a9fad'},grid:{color:'rgba(255,255,255,0.03)'},beginAtZero:true}}}
        });
    }
}
function confirmClearLogs(){
    confirm2('Effacer tous les logs ? Cette action est irréversible.',async()=>{
        const r=await api('clear_logs');
        if(r.ok){ toast('Logs effacés','warn'); loadLogs(); }
    });
}

/* ═══════════════════════════════════════════════
   💾 DATABASE
═══════════════════════════════════════════════ */
let dbChart=null;
async function loadDbStats(){
    const d=await api('db_stats');
    document.getElementById('dbi-size').textContent=d.total_mb+' MB';
    document.getElementById('dbi-tables').textContent=d.count;
    const tbody=document.getElementById('db-tbody');
    if(!tbody) return;
    const maxMb=Math.max(...d.tables.map(t=>parseFloat(t.size_mb)||0),0.01);
    tbody.innerHTML=d.tables.map(t=>{
        const pct=Math.min(100,((parseFloat(t.size_mb)||0)/maxMb)*100);
        return `<tr>
            <td><strong>${esc(t.TABLE_NAME)}</strong></td>
            <td>${Number(t.TABLE_ROWS||0).toLocaleString()}</td>
            <td style="font-family:var(--fh);font-weight:900;color:var(--cyan)">${t.size_mb||0}</td>
            <td><div class="db-bar"><div class="db-bar-fill" style="width:${pct}%"></div></div></td>
        </tr>`;
    }).join('');
    /* Chart */
    const top=d.tables.slice(0,8);
    if(document.getElementById('chart-db')){
        if(dbChart) dbChart.destroy();
        dbChart=new Chart(document.getElementById('chart-db').getContext('2d'),{
            type:'doughnut',
            data:{labels:top.map(t=>t.TABLE_NAME),datasets:[{data:top.map(t=>parseFloat(t.size_mb)||0),
                backgroundColor:['rgba(168,85,247,0.8)','rgba(6,182,212,0.8)','rgba(50,190,143,0.8)','rgba(255,208,96,0.8)','rgba(255,53,83,0.8)','rgba(61,140,255,0.8)','rgba(255,145,64,0.8)','rgba(236,72,153,0.8)'],
                borderColor:'#22324a',borderWidth:3}]},
            options:{responsive:true,maintainAspectRatio:false,cutout:'55%',
                plugins:{legend:{position:'bottom',labels:{color:'#b8d8cc',font:{size:11},padding:10}}}}
        });
    }
}

/* ═══════════════════════════════════════════════
   🧑‍💼 RH SETTINGS
═══════════════════════════════════════════════ */
function getAttendanceLatLngFromInputs(){
    const lat=parseFloat(document.getElementById('att-latitude')?.value || '0');
    const lng=parseFloat(document.getElementById('att-longitude')?.value || '0');
    if(Number.isFinite(lat) && Number.isFinite(lng) && lat !== 0 && lng !== 0) return {lat,lng};
    return {lat:5.330582,lng:-4.197368};
}
function updateAttendanceMapPreview(lat,lng){
    const frame=document.getElementById('attendance-map-preview');
    const link=document.getElementById('attendance-map-open-link');
    if(!frame) return;
    frame.src=`https://www.google.com/maps/embed/v1/view?key=${encodeURIComponent(GOOGLE_MAPS_EMBED_KEY)}&center=${encodeURIComponent(`${lat},${lng}`)}&zoom=18&maptype=roadmap`;
    if(link) link.href=`https://www.google.com/maps?q=${encodeURIComponent(`${lat},${lng}`)}`;
}
function syncAttendancePosition(lat,lng){
    const fixedLat=Number(lat).toFixed(7);
    const fixedLng=Number(lng).toFixed(7);
    if(document.getElementById('att-latitude')) document.getElementById('att-latitude').value=fixedLat;
    if(document.getElementById('att-longitude')) document.getElementById('att-longitude').value=fixedLng;
    updateAttendanceMapPreview(fixedLat,fixedLng);
}
function extractLatLngFromGoogleMapsInput(value){
    const input=String(value || '').trim();
    if(!input) return null;

    const patterns=[
        /@(-?\d+(?:\.\d+)?),\s*(-?\d+(?:\.\d+)?)/,
        /[?&]q=(-?\d+(?:\.\d+)?),\s*(-?\d+(?:\.\d+)?)/,
        /[?&]center=(-?\d+(?:\.\d+)?),\s*(-?\d+(?:\.\d+)?)/,
        /\b(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\b/
    ];

    for(const pattern of patterns){
        const match=input.match(pattern);
        if(match){
            const lat=parseFloat(match[1]);
            const lng=parseFloat(match[2]);
            if(Number.isFinite(lat) && Number.isFinite(lng)) return {lat,lng};
        }
    }

    return null;
}
function applyAttendanceGoogleMapsUrl(){
    const raw=document.getElementById('att-google-maps-url')?.value || '';
    const point=extractLatLngFromGoogleMapsInput(raw);
    if(!point){
        toast('Lien Google Maps invalide','error','Collez une URL Google Maps ou des coordonnées valides');
        return;
    }
    syncAttendancePosition(point.lat, point.lng);
    toast('Position extraite','success',`${point.lat.toFixed(6)}, ${point.lng.toFixed(6)}`);
}
function refreshAttendanceMapPreview(){
    const point=getAttendanceLatLngFromInputs();
    syncAttendancePosition(point.lat, point.lng);
}
function useCurrentPositionForAttendance(){
    if(!navigator.geolocation){
        toast('Géolocalisation non supportée','error');
        return;
    }
    navigator.geolocation.getCurrentPosition(
        pos=>{
            syncAttendancePosition(pos.coords.latitude, pos.coords.longitude);
            toast('Position actuelle chargée','success');
        },
        ()=>toast('Impossible d’obtenir la position','error','Activez le GPS du navigateur'),
        {enableHighAccuracy:true, timeout:10000, maximumAge:0}
    );
}
function renderAttendanceSettingsSummary(settings){
    const box=document.getElementById('attendance-settings-summary');
    if(!box) return;
    box.innerHTML=`
        <div><span style="color:var(--muted);width:160px;display:inline-block">Début:</span> <strong style="color:var(--neon)">${esc(String(settings.work_start_time || '').slice(0,5))}</strong></div>
        <div><span style="color:var(--muted);width:160px;display:inline-block">Départ:</span> <strong style="color:var(--gold)">${esc(String(settings.work_end_time || '').slice(0,5))}</strong></div>
        <div><span style="color:var(--muted);width:160px;display:inline-block">Pénalité/min:</span> <strong style="color:var(--red)">${Number(settings.late_penalty_per_minute || 0).toLocaleString('fr-FR')} FCFA</strong></div>
        <div><span style="color:var(--muted);width:160px;display:inline-block">Latitude:</span> <strong>${esc(settings.office_latitude)}</strong></div>
        <div><span style="color:var(--muted);width:160px;display:inline-block">Longitude:</span> <strong>${esc(settings.office_longitude)}</strong></div>
        <div><span style="color:var(--muted);width:160px;display:inline-block">Rayon GPS:</span> <strong>${esc(settings.location_radius_meters)} m</strong></div>
        <div><span style="color:var(--muted);width:160px;display:inline-block">GPS arrivée:</span> <strong>${Number(settings.require_gps_check_in) ? 'Oui' : 'Non'}</strong></div>
        <div><span style="color:var(--muted);width:160px;display:inline-block">GPS départ:</span> <strong>${Number(settings.require_gps_check_out) ? 'Oui' : 'Non'}</strong></div>
    `;
}
async function loadAttendanceSettings(){
    const data=await api('get_attendance_settings');
    if(document.getElementById('att-start-time')) document.getElementById('att-start-time').value=String(data.work_start_time || '').slice(0,5);
    if(document.getElementById('att-end-time')) document.getElementById('att-end-time').value=String(data.work_end_time || '').slice(0,5);
    if(document.getElementById('att-latitude')) document.getElementById('att-latitude').value=data.office_latitude ?? '';
    if(document.getElementById('att-longitude')) document.getElementById('att-longitude').value=data.office_longitude ?? '';
    if(document.getElementById('att-radius')) document.getElementById('att-radius').value=data.location_radius_meters ?? '';
    if(document.getElementById('att-penalty')) document.getElementById('att-penalty').value=data.late_penalty_per_minute ?? '';
    if(document.getElementById('att-gps-in')) document.getElementById('att-gps-in').value=Number(data.require_gps_check_in) ? '1' : '0';
    if(document.getElementById('att-gps-out')) document.getElementById('att-gps-out').value=Number(data.require_gps_check_out) ? '1' : '0';
    const point=getAttendanceLatLngFromInputs();
    updateAttendanceMapPreview(point.lat, point.lng);
    renderAttendanceSettingsSummary(data);
}
async function saveAttendanceSettings(){
    const payload={
        work_start_time: document.getElementById('att-start-time')?.value || '07:30',
        work_end_time: document.getElementById('att-end-time')?.value || '18:30',
        office_latitude: document.getElementById('att-latitude')?.value || 0,
        office_longitude: document.getElementById('att-longitude')?.value || 0,
        location_radius_meters: document.getElementById('att-radius')?.value || 500,
        late_penalty_per_minute: document.getElementById('att-penalty')?.value || 0,
        require_gps_check_in: document.getElementById('att-gps-in')?.value === '1' ? 1 : 0,
        require_gps_check_out: document.getElementById('att-gps-out')?.value === '1' ? 1 : 0,
    };
    const r=await api('update_attendance_settings',{},payload);
    if(r.ok){
        toast('Paramètres RH enregistrés','success');
        loadAttendanceSettings();
    } else {
        toast(r.msg || 'Erreur de sauvegarde','error');
    }
}
async function optimizeDB(){
    confirm2('Optimiser toutes les tables de la base de données ?',async()=>{
        toast('Optimisation en cours…','info');
        const r=await api('optimize_db');
        if(r.ok) toast('Base optimisée !','success'); else toast('Erreur','error');
    });
}
function handleBackup(e){ e.preventDefault(); document.querySelector('form[method=post]')?.submit(); }

/* ═══════════════════════════════════════════════
   🛠️ UTILS
═══════════════════════════════════════════════ */
function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function formatPrice(v){ return new Intl.NumberFormat('fr-FR',{minimumFractionDigits:0,maximumFractionDigits:0}).format(v||0)+' F'; }
function showUserMenu(){ /* placeholder pour menu déroulant futur */ toast('Profil admin','info','Session active'); }

/* ═══════════════════════════════════════════════
   ⌨️ RACCOURCIS CLAVIER
═══════════════════════════════════════════════ */
document.addEventListener('keydown',e=>{
    if(e.ctrlKey||e.metaKey){
        switch(e.key){
            case 'k': e.preventDefault();
                const m='<?= $module ?>';
                if(m==='users') { document.getElementById('user-search')?.focus(); }
                else if(m==='products') { document.getElementById('prod-search')?.focus(); }
                else if(m==='promotions') { document.getElementById('promo-search')?.focus(); }
                else if(m==='loyalty') { document.getElementById('loyalty-search')?.focus(); }
                else if(m==='companies') { document.getElementById('co-search')?.focus(); }
                break;
            case 'n': e.preventDefault();
                if('<?= $module ?>'==='users') openModal('modal-add-user');
                else if('<?= $module ?>'==='products') openModal('modal-add-prod');
                else if('<?= $module ?>'==='promotions') openPromotionModal();
                else if('<?= $module ?>'==='companies') openModal('modal-add-co');
                else if('<?= $module ?>'==='cities') openModal('modal-add-city');
                break;
        }
    }
});
toast('⌨️ Ctrl+K = Recherche · Ctrl+N = Nouveau','info','Raccourcis disponibles');

/* ═══════════════════════════════════════════════
   🚀 INIT AU CHARGEMENT
═══════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', ()=>{
    bindProductImagePreview('np-image','np-image-preview');
    bindProductImagePreview('ep-image','ep-image-preview');
    const mod='<?= $module ?>';
    if(mod==='users')     loadUsers();
    if(mod==='companies') loadCompanies();
    if(mod==='cities')    loadCities();
    if(mod==='products')  loadProducts();
    if(mod==='promotions') loadPromotions();
    if(mod==='loyalty')  { loadLoyaltyClients(); loadAbandonedCarts('all', document.getElementById('ab-filter-all')); loadCouponStats(); loadPersonalizedCoupons(); }
    if(mod==='logs')      loadLogs();
    if(mod==='hr_settings') loadAttendanceSettings();
    if(mod==='database')  loadDbStats();
    if(mod==='stock')     loadQuickStock();
});
</script>
</body>
</html>
