<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';
require_once dirname(__DIR__) . '/legal/legal_bootstrap.php';

/**
 * ════════════════════════════════════════════════════════════════
 * COMMANDE EN LIGNE MOBILE — ESPERANCE H2O
 * Style: Light Clean Pro · C059 Bold · Mobile-First
 * v2 — Suivi commandes · Annulation · Historique · Notifs · Logout
 * ════════════════════════════════════════════════════════════════
 */

session_start();
ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

require_once APP_ROOT . '/app/core/DB.php';
require_once PROJECT_ROOT . '/messaging/app_alerts.php';
use App\Core\DB;

$pdo = DB::getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function ensureClientAuthSchema(PDO $pdo): void {
    $existing = [];
    try {
        $st = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clients'");
        $existing = $st ? $st->fetchAll(PDO::FETCH_COLUMN) : [];
    } catch (Throwable $e) {
        $existing = [];
    }

    if (!in_array('email', $existing, true)) {
        $pdo->exec("ALTER TABLE clients ADD COLUMN email VARCHAR(190) NULL AFTER name");
        $existing[] = 'email';
    }
    if (!in_array('password_hash', $existing, true)) {
        $after = in_array('email', $existing, true) ? 'email' : 'phone';
        $pdo->exec("ALTER TABLE clients ADD COLUMN password_hash VARCHAR(255) NULL AFTER {$after}");
    }
}
ensureClientAuthSchema($pdo);

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

function parsePromotionTiers(?string $tiersJson): array {
    if (!$tiersJson) {
        return [];
    }
    $decoded = json_decode($tiersJson, true);
    if (!is_array($decoded)) {
        return [];
    }
    $tiers = [];
    foreach ($decoded as $tier) {
        $qty = (int)($tier['qty'] ?? 0);
        $discount = (float)($tier['discount_percent'] ?? 0);
        if ($qty > 0 && $discount > 0) {
            $tiers[] = ['qty' => $qty, 'discount_percent' => $discount];
        }
    }
    usort($tiers, fn(array $a, array $b) => $a['qty'] <=> $b['qty']);
    return $tiers;
}

function calculatePromotionPricing(array $promotion, float $basePrice, int $qty = 1): array {
    $qty = max(1, $qty);
    $promoType = (string)($promotion['promo_type'] ?? 'simple');
    $promoPrice = isset($promotion['promo_price']) ? (float)$promotion['promo_price'] : 0.0;
    $discountPercent = isset($promotion['discount_percent']) ? (float)$promotion['discount_percent'] : 0.0;
    $oldPrice = isset($promotion['old_price']) && (float)$promotion['old_price'] > 0 ? (float)$promotion['old_price'] : $basePrice;
    $unitPrice = $basePrice;
    $total = $basePrice * $qty;
    $summary = '';

    if (in_array($promoType, ['simple', 'flash'], true)) {
        if ($promoPrice > 0) {
            $unitPrice = $promoPrice;
        } elseif ($discountPercent > 0) {
            $unitPrice = $basePrice * (1 - ($discountPercent / 100));
        }
        $unitPrice = max(0, round($unitPrice, 2));
        $total = round($unitPrice * $qty, 2);
        $summary = $discountPercent > 0 ? '-' . rtrim(rtrim(number_format($discountPercent, 2, '.', ''), '0'), '.') . '%' : 'Prix promo';
    } elseif ($promoType === 'quantity') {
        $buy = (int)($promotion['quantity_buy'] ?? 0);
        $pay = (int)($promotion['quantity_pay'] ?? 0);
        if ($buy > 0 && $pay > 0 && $pay < $buy && $qty >= $buy) {
            $groups = intdiv($qty, $buy);
            $remaining = $qty % $buy;
            $total = (($groups * $pay) + $remaining) * $basePrice;
            $summary = "Achetez {$buy}, payez {$pay}";
        } else {
            $tiers = parsePromotionTiers($promotion['tiers_json'] ?? null);
            $appliedDiscount = 0.0;
            foreach ($tiers as $tier) {
                if ($qty >= (int)$tier['qty']) {
                    $appliedDiscount = (float)$tier['discount_percent'];
                }
            }
            if ($appliedDiscount > 0) {
                $total = $qty * $basePrice * (1 - ($appliedDiscount / 100));
                $summary = $qty . '+ = -' . rtrim(rtrim(number_format($appliedDiscount, 2, '.', ''), '0'), '.') . '%';
            }
        }
        $total = round(max(0, $total), 2);
        $unitPrice = round($total / $qty, 2);
    }

    return [
        'old_price' => round($oldPrice, 2),
        'unit_price' => round($unitPrice, 2),
        'total' => round($total, 2),
        'summary' => $summary,
    ];
}

function loadActivePromotions(PDO $pdo, int $companyId, int $cityId): array {
    if ($companyId <= 0 || $cityId <= 0) {
        return ['campaigns' => [], 'by_product' => []];
    }

    $sql = "
        SELECT *
        FROM promotion_campaigns
        WHERE company_id = ?
          AND city_id = ?
          AND is_active = 1
          AND (starts_at IS NULL OR starts_at <= NOW())
          AND (ends_at IS NULL OR ends_at >= NOW())
        ORDER BY sort_order DESC, discount_percent DESC, id DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$companyId, $cityId]);
    $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$campaigns) {
        return ['campaigns' => [], 'by_product' => []];
    }

    $ids = array_map('intval', array_column($campaigns, 'id'));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $itemsStmt = $pdo->prepare("
        SELECT pi.promotion_id, pi.product_id, pi.quantity, p.name AS product_name, p.price AS product_price, p.image_path
        FROM promotion_items pi
        INNER JOIN products p ON p.id = pi.product_id
        WHERE pi.promotion_id IN ($placeholders)
        ORDER BY pi.id ASC
    ");
    $itemsStmt->execute($ids);
    $itemRows = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    $itemsMap = [];
    foreach ($itemRows as $row) {
        $row['quantity'] = (int)$row['quantity'];
        $row['product_id'] = (int)$row['product_id'];
        $row['product_price'] = (float)$row['product_price'];
        $row['image_url'] = !empty($row['image_path']) ? project_url($row['image_path']) : '';
        $itemsMap[(int)$row['promotion_id']][] = $row;
    }

    $byProduct = [];
    foreach ($campaigns as &$campaign) {
        $campaign['id'] = (int)$campaign['id'];
        $campaign['product_id'] = (int)($campaign['product_id'] ?? 0);
        $campaign['discount_percent'] = (float)($campaign['discount_percent'] ?? 0);
        $campaign['promo_price'] = (float)($campaign['promo_price'] ?? 0);
        $campaign['old_price'] = (float)($campaign['old_price'] ?? 0);
        $campaign['quantity_buy'] = (int)($campaign['quantity_buy'] ?? 0);
        $campaign['quantity_pay'] = (int)($campaign['quantity_pay'] ?? 0);
        $campaign['items'] = $itemsMap[$campaign['id']] ?? [];
        if ($campaign['product_id'] <= 0 && !empty($campaign['items'])) {
            $campaign['product_id'] = (int)$campaign['items'][0]['product_id'];
        }
        if (empty($campaign['items']) && $campaign['product_id'] > 0) {
            $fallbackStmt = $pdo->prepare("SELECT id AS product_id, name AS product_name, price AS product_price, image_path FROM products WHERE id=? LIMIT 1");
            $fallbackStmt->execute([$campaign['product_id']]);
            $fallbackProduct = $fallbackStmt->fetch(PDO::FETCH_ASSOC);
            if ($fallbackProduct) {
                $campaign['items'] = [[
                    'promotion_id' => $campaign['id'],
                    'product_id' => (int)$fallbackProduct['product_id'],
                    'quantity' => 1,
                    'product_name' => (string)$fallbackProduct['product_name'],
                    'product_price' => (float)$fallbackProduct['product_price'],
                    'image_path' => $fallbackProduct['image_path'] ?? null,
                    'image_url' => !empty($fallbackProduct['image_path']) ? project_url($fallbackProduct['image_path']) : '',
                ]];
            }
        }
        if ($campaign['product_id'] > 0) {
            $byProduct[$campaign['product_id']][] = $campaign;
        }
    }
    unset($campaign);

    return ['campaigns' => $campaigns, 'by_product' => $byProduct];
}

function chooseBestProductPromotion(array $promotions, float $basePrice): ?array {
    $best = null;
    $bestUnitPrice = $basePrice;
    $fallbackQuantity = null;
    foreach ($promotions as $promotion) {
        if (($promotion['promo_type'] ?? '') === 'pack') {
            continue;
        }
        if (($promotion['promo_type'] ?? '') === 'quantity' && $fallbackQuantity === null) {
            $fallbackQuantity = $promotion;
        }
        $pricing = calculatePromotionPricing($promotion, $basePrice, 1);
        if ($pricing['unit_price'] < $bestUnitPrice) {
            $bestUnitPrice = $pricing['unit_price'];
            $promotion['pricing'] = $pricing;
            $best = $promotion;
        }
    }
    return $best ?: $fallbackQuantity;
}

ensurePromotionStorage($pdo);

function ensureCommerceEnhancementsStorage(PDO $pdo): void {
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
            CREATE TABLE IF NOT EXISTS client_favorites (
                id INT AUTO_INCREMENT PRIMARY KEY,
                client_id INT NOT NULL,
                product_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_client_product (client_id, product_id),
                INDEX idx_client (client_id),
                INDEX idx_product (product_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS product_reviews (
                id INT AUTO_INCREMENT PRIMARY KEY,
                product_id INT NOT NULL,
                client_id INT NOT NULL,
                rating TINYINT NOT NULL DEFAULT 5,
                review_text TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_product (product_id),
                INDEX idx_client (client_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
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
                UNIQUE KEY uniq_client_context_active (client_id, company_id, city_id, status),
                INDEX idx_client (client_id),
                INDEX idx_activity (last_activity_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        try {
            $idxStmt = $pdo->query("SHOW INDEX FROM abandoned_carts WHERE Key_name = 'uniq_client_context_active'");
            $hasLegacyUnique = $idxStmt && $idxStmt->fetch(PDO::FETCH_ASSOC);
            if ($hasLegacyUnique) {
                $pdo->exec("ALTER TABLE abandoned_carts DROP INDEX uniq_client_context_active");
                $pdo->exec("ALTER TABLE abandoned_carts ADD INDEX idx_client_context_status (client_id, company_id, city_id, status)");
            }
        } catch (Throwable $e) {
        }
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
                used_at DATETIME NULL,
                used_order_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_code (code),
                INDEX idx_client (client_id),
                INDEX idx_cart (abandoned_cart_id),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $couponColumns = [];
        try {
            $st = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'personalized_coupons'");
            $couponColumns = $st ? $st->fetchAll(PDO::FETCH_COLUMN) : [];
        } catch (Throwable $e) {
            $couponColumns = [];
        }
        if (!in_array('sent_at', $couponColumns, true)) {
            $pdo->exec("ALTER TABLE personalized_coupons ADD COLUMN sent_at DATETIME NULL AFTER expires_at");
            $couponColumns[] = 'sent_at';
        }
        if (!in_array('used_at', $couponColumns, true)) {
            $after = in_array('sent_at', $couponColumns, true) ? 'sent_at' : 'expires_at';
            $pdo->exec("ALTER TABLE personalized_coupons ADD COLUMN used_at DATETIME NULL AFTER {$after}");
            $couponColumns[] = 'used_at';
        }
        if (!in_array('used_order_id', $couponColumns, true)) {
            $after = in_array('used_at', $couponColumns, true) ? 'used_at' : 'sent_at';
            $pdo->exec("ALTER TABLE personalized_coupons ADD COLUMN used_order_id INT NULL AFTER {$after}");
        }
    } catch (Throwable $e) {
    }
}

function ensureDeliveryZoneStorage(PDO $pdo): void {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS delivery_zones (
                id INT AUTO_INCREMENT PRIMARY KEY,
                company_id INT NOT NULL DEFAULT 0,
                city_id INT NOT NULL DEFAULT 0,
                zone_name VARCHAR(160) NOT NULL,
                delivery_delay_label VARCHAR(80) NOT NULL DEFAULT '',
                delivery_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                notes VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_context(company_id, city_id, is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
    }
}

function seedDeliveryZonesForContext(PDO $pdo, int $companyId, int $cityId): void {
    if ($companyId <= 0 || $cityId <= 0) {
        return;
    }
    try {
        $check = $pdo->prepare("SELECT COUNT(*) FROM delivery_zones WHERE company_id=? AND city_id=?");
        $check->execute([$companyId, $cityId]);
        if ((int)$check->fetchColumn() > 0) {
            return;
        }
        $rows = [
            ['Centre-ville', '30-45 min', 0, 10, 'Livraison prioritaire'],
            ['Quartier residentiel', '45-60 min', 0, 20, 'Zone standard'],
            ['Banlieue proche', '1h-1h30', 500, 30, 'Frais legers'],
            ['Zone periurbaine', '1h30-2h', 1000, 40, 'Selon circulation'],
            ['Zone eloignee', '2h-3h', 1500, 50, 'Confirmation WhatsApp recommandee'],
        ];
        $stmt = $pdo->prepare("
            INSERT INTO delivery_zones(company_id,city_id,zone_name,delivery_delay_label,delivery_fee,sort_order,notes)
            VALUES(?,?,?,?,?,?,?)
        ");
        foreach ($rows as $row) {
            $stmt->execute([$companyId, $cityId, $row[0], $row[1], $row[2], $row[3], $row[4]]);
        }
    } catch (Throwable $e) {
    }
}

function loadDeliveryZones(PDO $pdo, int $companyId, int $cityId): array {
    if ($companyId <= 0 || $cityId <= 0) {
        return [];
    }
    ensureDeliveryZoneStorage($pdo);
    seedDeliveryZonesForContext($pdo, $companyId, $cityId);
    try {
        $stmt = $pdo->prepare("
            SELECT id, zone_name, delivery_delay_label, delivery_fee, sort_order, notes
            FROM delivery_zones
            WHERE company_id=? AND city_id=? AND is_active=1
            ORDER BY sort_order ASC, id ASC
        ");
        $stmt->execute([$companyId, $cityId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function getDeliveryZoneById(PDO $pdo, int $companyId, int $cityId, int $zoneId): ?array {
    if ($companyId <= 0 || $cityId <= 0 || $zoneId <= 0) {
        return null;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT id, zone_name, delivery_delay_label, delivery_fee, sort_order, notes
            FROM delivery_zones
            WHERE id=? AND company_id=? AND city_id=? AND is_active=1
            LIMIT 1
        ");
        $stmt->execute([$zoneId, $companyId, $cityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function deliveryZoneTone(float $fee): string {
    if ($fee <= 0) {
        return 'ok';
    }
    if ($fee <= 1000) {
        return 'mid';
    }
    return 'far';
}

function fetchPendingDeletionRequest(PDO $pdo, int $clientId): ?array {
    if ($clientId <= 0) {
        return null;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT id, status, requested_at, processed_at, admin_note
            FROM account_deletion_requests
            WHERE client_id=? AND status='pending'
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$clientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function fetchReviewStats(PDO $pdo, array $productIds): array {
    if (!$productIds) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $stmt = $pdo->prepare("
        SELECT product_id, ROUND(AVG(rating),1) AS avg_rating, COUNT(*) AS review_count
        FROM product_reviews
        WHERE product_id IN ($placeholders)
        GROUP BY product_id
    ");
    $stmt->execute(array_values($productIds));
    $stats = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $stats[(int)$row['product_id']] = [
            'avg_rating' => (float)$row['avg_rating'],
            'review_count' => (int)$row['review_count'],
        ];
    }
    return $stats;
}

function fetchFavoriteProductIds(PDO $pdo, int $clientId): array {
    if ($clientId <= 0) {
        return [];
    }
    $stmt = $pdo->prepare("SELECT product_id FROM client_favorites WHERE client_id=?");
    $stmt->execute([$clientId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function fetchProductReviews(PDO $pdo, int $productId): array {
    $stmt = $pdo->prepare("
        SELECT pr.id, pr.rating, pr.review_text, pr.created_at, c.name AS client_name
        FROM product_reviews pr
        LEFT JOIN clients c ON c.id = pr.client_id
        WHERE pr.product_id=?
        ORDER BY pr.created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$productId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function loyaltyTierMeta(string $status): array {
    $map = [
        'standard' => ['label' => 'Standard', 'color' => '#9ca8a3', 'threshold' => 0],
        'silver' => ['label' => 'Silver', 'color' => '#c0d6df', 'threshold' => 300],
        'gold' => ['label' => 'Gold', 'color' => '#ffd060', 'threshold' => 900],
        'platinum' => ['label' => 'Platinum', 'color' => '#7adfff', 'threshold' => 1800],
    ];
    return $map[$status] ?? $map['standard'];
}

function determineVipStatus(int $points, float $totalSpent, int $ordersCount): string {
    if ($points >= 1800 || $totalSpent >= 600000 || $ordersCount >= 30) {
        return 'platinum';
    }
    if ($points >= 900 || $totalSpent >= 250000 || $ordersCount >= 15) {
        return 'gold';
    }
    if ($points >= 300 || $totalSpent >= 100000 || $ordersCount >= 6) {
        return 'silver';
    }
    return 'standard';
}

function updateClientLoyaltyProfile(PDO $pdo, int $clientId): array {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) AS total_spent, COUNT(*) AS orders_count FROM orders WHERE client_id=? AND status IN('pending','confirmed','delivering','done')");
    $stmt->execute([$clientId]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total_spent' => 0, 'orders_count' => 0];
    $profileStmt = $pdo->prepare("SELECT loyalty_points FROM clients WHERE id=? LIMIT 1");
    $profileStmt->execute([$clientId]);
    $points = (int)$profileStmt->fetchColumn();
    $vip = determineVipStatus($points, (float)$stats['total_spent'], (int)$stats['orders_count']);
    $pdo->prepare("UPDATE clients SET vip_status=? WHERE id=?")->execute([$vip, $clientId]);
    return [
        'points' => $points,
        'vip_status' => $vip,
        'total_spent' => (float)$stats['total_spent'],
        'orders_count' => (int)$stats['orders_count'],
    ];
}

function buildWhatsAppRecoveryLink(string $phone, string $message): string {
    $digits = preg_replace('/\D+/', '', $phone);
    if ($digits === '') {
        return '';
    }
    return 'https://wa.me/' . $digits . '?text=' . rawurlencode($message);
}

function formatCartRecoveryItems(array $items): string {
    $parts = [];
    foreach ($items as $item) {
        $parts[] = ((int)($item['qty'] ?? 0)) . 'x ' . trim((string)($item['product_name'] ?? 'Produit'));
    }
    return implode(', ', array_filter($parts));
}

function saveAbandonedCartSnapshot(PDO $pdo, int $clientId, int $companyId, int $cityId, array $cartItems): void {
    if ($clientId <= 0 || $companyId <= 0 || $cityId <= 0) {
        return;
    }
    $total = 0.0;
    foreach ($cartItems as $item) {
        $total += (float)($item['sub'] ?? 0);
    }
    $payload = json_encode(array_values($cartItems), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $count = count($cartItems);
    if ($count === 0) {
        $pdo->prepare("UPDATE abandoned_carts SET status='expired', updated_at=NOW() WHERE client_id=? AND company_id=? AND city_id=? AND status='active'")
            ->execute([$clientId, $companyId, $cityId]);
        return;
    }
    $stmt = $pdo->prepare("SELECT id FROM abandoned_carts WHERE client_id=? AND company_id=? AND city_id=? AND status='active' LIMIT 1");
    $stmt->execute([$clientId, $companyId, $cityId]);
    $existingId = (int)$stmt->fetchColumn();
    if ($existingId > 0) {
        $pdo->prepare("UPDATE abandoned_carts SET cart_payload=?, item_count=?, cart_total=?, last_activity_at=NOW() WHERE id=?")
            ->execute([$payload, $count, $total, $existingId]);
    } else {
        $pdo->prepare("INSERT INTO abandoned_carts(client_id,company_id,city_id,cart_payload,item_count,cart_total,last_activity_at,status) VALUES(?,?,?,?,?,?,NOW(),'active')")
            ->execute([$clientId, $companyId, $cityId, $payload, $count, $total]);
    }
}

function processAbandonedCartRecovery(PDO $pdo, int $clientId): array {
    if ($clientId <= 0) {
        return ['reminded' => false];
    }
    $stmt = $pdo->prepare("
        SELECT ac.*, c.email, c.phone, c.name
        FROM abandoned_carts ac
        INNER JOIN clients c ON c.id = ac.client_id
        WHERE ac.client_id=?
          AND ac.status='active'
          AND ac.item_count > 0
          AND ac.last_activity_at <= DATE_SUB(NOW(), INTERVAL 1 HOUR)
          AND (ac.reminder_sent_at IS NULL OR ac.reminder_sent_at <= DATE_SUB(NOW(), INTERVAL 12 HOUR))
        ORDER BY ac.updated_at DESC
        LIMIT 1
    ");
    $stmt->execute([$clientId]);
    $cart = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cart) {
        return ['reminded' => false];
    }
    $items = json_decode((string)$cart['cart_payload'], true);
    if (!is_array($items) || !$items) {
        return ['reminded' => false];
    }
    $itemsText = formatCartRecoveryItems($items);
    $message = "Bonjour {$cart['name']}, votre panier ESPERANCE H2O vous attend encore : {$itemsText}. Revenez finaliser votre commande.";
    $subject = 'Votre panier ESPERANCE H2O vous attend';
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "From: ESPERANCE H2O <no-reply@" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ">\r\n";
    $emailSent = false;
    if (!empty($cart['email'])) {
        $emailSent = @mail((string)$cart['email'], $subject, $message, $headers);
    }
    $waLink = buildWhatsAppRecoveryLink((string)($cart['phone'] ?? ''), $message);
    $notifMessage = $message . ($waLink ? " Ouvrir WhatsApp : {$waLink}" : '');
    $pdo->prepare("INSERT INTO notifications(client_id,title,message,type) VALUES(?,?,?,'promo')")
        ->execute([$clientId, 'Panier abandonné détecté', $notifMessage]);
    $pdo->prepare("UPDATE abandoned_carts SET reminder_sent_at=NOW(), whatsapp_link=? WHERE id=?")
        ->execute([$waLink ?: null, (int)$cart['id']]);
    return ['reminded' => true, 'email_sent' => $emailSent, 'whatsapp_link' => $waLink];
}

function buildPersonalizedOffers(PDO $pdo, int $clientId, array $products): array {
    if ($clientId <= 0 || !$products) {
        return [];
    }
    $catStmt = $pdo->prepare("
        SELECT p.category, COUNT(*) AS cnt
        FROM order_items oi
        INNER JOIN orders o ON o.id = oi.order_id
        INNER JOIN products p ON p.id = oi.product_id
        WHERE o.client_id=? AND p.category IS NOT NULL AND TRIM(p.category) <> ''
        GROUP BY p.category
        ORDER BY cnt DESC
        LIMIT 1
    ");
    $catStmt->execute([$clientId]);
    $favoriteCategory = (string)($catStmt->fetchColumn() ?: '');
    $offers = [];
    foreach ($products as $product) {
        if ($favoriteCategory !== '' && strcasecmp((string)($product['category'] ?? ''), $favoriteCategory) === 0) {
            $offers[] = [
                'type' => 'favorite_category',
                'title' => 'Votre catégorie préférée',
                'subtitle' => $favoriteCategory . ' revient souvent dans vos commandes',
                'product_id' => (int)$product['id'],
            ];
        } elseif (!empty($product['promo'])) {
            $offers[] = [
                'type' => 'promo_match',
                'title' => 'Offre personnalisée',
                'subtitle' => 'Promo recommandée selon vos achats',
                'product_id' => (int)$product['id'],
            ];
        }
        if (count($offers) >= 4) {
            break;
        }
    }
    return $offers;
}

function findApplicableCoupon(PDO $pdo, int $clientId, string $code, float $subtotal): ?array {
    $code = strtoupper(trim($code));
    if ($clientId <= 0 || $code === '') {
        return null;
    }
    $stmt = $pdo->prepare("
        SELECT *
        FROM personalized_coupons
        WHERE client_id = ?
          AND UPPER(code) = UPPER(?)
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$clientId, $code]);
    $coupon = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$coupon) {
        return null;
    }
    if (($coupon['status'] ?? '') !== 'active') {
        throw new Exception('Coupon inactif ou déjà utilisé');
    }
    if (!empty($coupon['expires_at']) && strtotime((string)$coupon['expires_at']) < time()) {
        throw new Exception('Coupon expiré');
    }
    $minAmount = (float)($coupon['min_amount'] ?? 0);
    if ($minAmount > 0 && $subtotal < $minAmount) {
        throw new Exception('Montant minimum requis : ' . number_format($minAmount, 0, ',', ' ') . ' CFA');
    }
    $discount = 0.0;
    if ((float)($coupon['discount_percent'] ?? 0) > 0) {
        $discount = round($subtotal * ((float)$coupon['discount_percent'] / 100), 2);
    } elseif ((float)($coupon['amount_off'] ?? 0) > 0) {
        $discount = min($subtotal, (float)$coupon['amount_off']);
    }
    if ($discount <= 0) {
        throw new Exception('Coupon invalide');
    }
    $coupon['discount_amount'] = min($subtotal, $discount);
    return $coupon;
}

ensureCommerceEnhancementsStorage($pdo);
ensureDeliveryZoneStorage($pdo);
try {
    $orderColumns = [];
    $st = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'");
    $orderColumns = $st ? $st->fetchAll(PDO::FETCH_COLUMN) : [];
    if (!in_array('delivery_zone_id', $orderColumns, true)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN delivery_zone_id INT NULL AFTER city_id");
        $orderColumns[] = 'delivery_zone_id';
    }
    if (!in_array('delivery_zone_name', $orderColumns, true)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN delivery_zone_name VARCHAR(160) NULL AFTER delivery_zone_id");
        $orderColumns[] = 'delivery_zone_name';
    }
    if (!in_array('delivery_fee', $orderColumns, true)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN delivery_fee DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER coupon_discount");
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_click_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL DEFAULT 0,
            order_id INT NULL,
            action_type VARCHAR(30) NOT NULL DEFAULT 'contact',
            target_phone VARCHAR(30) NOT NULL DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_client (client_id),
            INDEX idx_order (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Throwable $e) {}

/* ── Tables ── */
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS clients (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL, phone VARCHAR(50) NOT NULL,
        company_id INT NOT NULL DEFAULT 0, city_id INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_phone(phone), INDEX idx_company(company_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_number VARCHAR(50) NOT NULL UNIQUE,
        client_id INT NOT NULL, company_id INT NOT NULL, city_id INT NOT NULL,
        delivery_address TEXT NOT NULL, payment_method VARCHAR(50) DEFAULT 'cash',
        notes TEXT, total_amount DECIMAL(12,2) DEFAULT 0,
        coupon_code VARCHAR(40) NULL,
        coupon_discount DECIMAL(12,2) NOT NULL DEFAULT 0,
        status ENUM('pending','confirmed','delivering','done','cancelled') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_client(client_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS order_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL, product_id INT NOT NULL,
        product_name VARCHAR(255) NOT NULL, quantity INT NOT NULL DEFAULT 1,
        unit_price DECIMAL(12,2) NOT NULL, subtotal DECIMAL(12,2) NOT NULL,
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        type ENUM('order','status','info','promo') DEFAULT 'info',
        is_read TINYINT(1) DEFAULT 0,
        order_id INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_client(client_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch(Exception $e){}

try {
    $orderColumns = [];
    $st = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'");
    $orderColumns = $st ? $st->fetchAll(PDO::FETCH_COLUMN) : [];
    if (!in_array('coupon_code', $orderColumns, true)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN coupon_code VARCHAR(40) NULL AFTER total_amount");
        $orderColumns[] = 'coupon_code';
    }
    if (!in_array('coupon_discount', $orderColumns, true)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN coupon_discount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER coupon_code");
    }
} catch (Throwable $e) {}

/* ── Logout ── */
if(isset($_GET['logout'])){
    session_unset(); session_destroy();
    header('Location: /../auth/login_unified.php'); exit;
}

/* ══════════════════════════════════════════════════════
   AJAX HANDLERS
══════════════════════════════════════════════════════ */
if($_SERVER['REQUEST_METHOD']==='POST' && !empty($_POST['action'])){
    header('Content-Type: application/json; charset=utf-8');
    $action = trim($_POST['action']);

    if($action === 'search_client'){
        try{
            $phone = trim($_POST['phone']??'');
            $cid   = (int)($_POST['company_id']??0);
            if(!$phone){echo json_encode(['success'=>false,'message'=>'Numéro requis']);exit;}
            $st = $pdo->prepare("SELECT id,name,phone FROM clients WHERE phone=? AND company_id=? LIMIT 1");
            $st->execute([$phone,$cid]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['success'=>true,'exists'=>(bool)$row,'client'=>$row?:null]);
        }catch(Exception $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }

    if($action === 'login_client'){
        try{
            $email = trim($_POST['email'] ?? '');
            $password = (string)($_POST['password'] ?? '');
            if(!$email || !$password){echo json_encode(['success'=>false,'message'=>'Email et mot de passe requis']);exit;}
            $st = $pdo->prepare("SELECT id,name,phone,email,password_hash,company_id,city_id FROM clients WHERE LOWER(TRIM(email))=LOWER(TRIM(?)) LIMIT 1");
            $st->execute([$email]);
            $client = $st->fetch(PDO::FETCH_ASSOC);
            if(!$client || empty($client['password_hash']) || !password_verify($password, $client['password_hash'])){
                echo json_encode(['success'=>false,'message'=>'Identifiants incorrects']);exit;
            }
            $_SESSION['client_id']    = (int)$client['id'];
            $_SESSION['client_name']  = $client['name'];
            $_SESSION['client_phone'] = $client['phone'];
            $_SESSION['order_company_id'] = (int)($client['company_id'] ?? 0);
            $_SESSION['order_city_id'] = (int)($client['city_id'] ?? 0);
            echo json_encode(['success'=>true,'client_id'=>$client['id'],'name'=>$client['name'],'phone'=>$client['phone']]);
        }catch(Exception $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }

    if($action === 'create_client'){
        try{
            $name = trim($_POST['name']??'');
            $email = trim($_POST['email']??'');
            $phone = trim($_POST['phone']??'');
            $password = (string)($_POST['password'] ?? '');
            $coid = (int)($_POST['company_id']??0);
            $ciid = (int)($_POST['city_id']??0);
            if(strlen($name)<2){echo json_encode(['success'=>false,'message'=>'Nom trop court']);exit;}
            if(!filter_var($email, FILTER_VALIDATE_EMAIL)){echo json_encode(['success'=>false,'message'=>'Email invalide']);exit;}
            if(strlen($phone)<8){echo json_encode(['success'=>false,'message'=>'Numéro invalide']);exit;}
            if(strlen($password)<6){echo json_encode(['success'=>false,'message'=>'Mot de passe trop court']);exit;}
            $st=$pdo->prepare("SELECT id FROM clients WHERE LOWER(TRIM(email))=LOWER(TRIM(?)) LIMIT 1");
            $st->execute([$email]);
            if($st->fetch()){echo json_encode(['success'=>false,'message'=>'Email déjà enregistré']);exit;}
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO clients(name,email,password_hash,phone,company_id,city_id)VALUES(?,?,?,?,?,?)")
                ->execute([$name,$email,$passwordHash,$phone,$coid,$ciid]);
            $newId = (int)$pdo->lastInsertId();
            // Notif bienvenue
            $pdo->prepare("INSERT INTO notifications(client_id,title,message,type)VALUES(?,?,?,?)")
                ->execute([$newId,'Bienvenue sur ESPERANCE H2O !','Votre compte a été créé avec succès. Commandez vos boissons en quelques clics.','info']);
            echo json_encode(['success'=>true,'client_id'=>$newId,'name'=>$name,'phone'=>$phone]);
        }catch(Exception $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }

    if($action === 'set_client_session'){
        $_SESSION['client_id']    = (int)($_POST['client_id']??0);
        $_SESSION['client_name']  = trim($_POST['client_name']??'');
        $_SESSION['client_phone'] = trim($_POST['client_phone']??'');
        echo json_encode(['success'=>true]);
        exit;
    }

    if($action === 'get_orders'){
        try{
            $cid = (int)($_SESSION['client_id']??0);
            if(!$cid){echo json_encode(['success'=>false,'message'=>'Non connecté']);exit;}
            $st = $pdo->prepare("
                SELECT o.*,
                    ci.name AS city_name,
                    co.name AS company_name
                FROM orders o
                LEFT JOIN cities ci ON o.city_id=ci.id
                LEFT JOIN companies co ON o.company_id=co.id
                WHERE o.client_id=? ORDER BY o.created_at DESC LIMIT 100");
            $st->execute([$cid]);
            $orders = $st->fetchAll(PDO::FETCH_ASSOC);
            if($orders){
                $ids = implode(',',array_map('intval',array_column($orders,'id')));
                $items = $pdo->query("
                    SELECT oi.*, p.image_path
                    FROM order_items oi
                    LEFT JOIN products p ON p.id = oi.product_id
                    WHERE oi.order_id IN($ids)
                ")->fetchAll(PDO::FETCH_ASSOC);
                $itemMap = [];
                foreach($items as &$it){
                    $it['image_url'] = !empty($it['image_path']) ? project_url($it['image_path']) : '';
                    $itemMap[$it['order_id']][] = $it;
                }
                unset($it);
                foreach($orders as &$o) $o['items'] = $itemMap[$o['id']]??[];
            }
            echo json_encode(['success'=>true,'orders'=>$orders]);
        }catch(Exception $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }

    if($action === 'cancel_order'){
        try{
            $oid = (int)($_POST['order_id']??0);
            $cid = (int)($_SESSION['client_id']??0);
            if(!$oid||!$cid){echo json_encode(['success'=>false,'message'=>'Données manquantes']);exit;}
            $st=$pdo->prepare("SELECT id,status,order_number FROM orders WHERE id=? AND client_id=? LIMIT 1");
            $st->execute([$oid,$cid]);
            $order=$st->fetch(PDO::FETCH_ASSOC);
            if(!$order){echo json_encode(['success'=>false,'message'=>'Commande introuvable']);exit;}
            if(!in_array($order['status'],['pending','confirmed'])){
                echo json_encode(['success'=>false,'message'=>'Impossible d\'annuler — commande en cours ou terminée']);exit;
            }
            $pdo->prepare("UPDATE orders SET status='cancelled' WHERE id=? AND client_id=?")->execute([$oid,$cid]);
            // Notif annulation
            $pdo->prepare("INSERT INTO notifications(client_id,title,message,type,order_id)VALUES(?,?,?,?,?)")
                ->execute([$cid,'Commande annulée','La commande '.$order['order_number'].' a été annulée avec succès.','order',$oid]);
            echo json_encode(['success'=>true]);
        }catch(Exception $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }

    /* ── Export données client ── */
    if($action === 'export_data'){
        $cid = (int)($_SESSION['client_id']??0);
        if(!$cid){echo json_encode(['success'=>false,'message'=>'Non connecté']);exit;}
        try{
            $client = $pdo->prepare("SELECT id,name,phone,email,created_at FROM clients WHERE id=? LIMIT 1");
            $client->execute([$cid]);
            $clientRow = $client->fetch(PDO::FETCH_ASSOC) ?: [];

            $orders = $pdo->prepare("
                SELECT o.id, o.order_number, o.status, o.total_amount, o.delivery_address, o.created_at,
                       GROUP_CONCAT(CONCAT(p.name,' x',oi.quantity,' (',oi.unit_price,' CFA)') SEPARATOR ' | ') as items
                FROM orders o
                LEFT JOIN order_items oi ON oi.order_id = o.id
                LEFT JOIN products p ON p.id = oi.product_id
                WHERE o.client_id=?
                GROUP BY o.id ORDER BY o.created_at DESC
            ");
            $orders->execute([$cid]);
            $orderRows = $orders->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $notificationsStmt = $pdo->prepare("SELECT title,message,type,is_read,order_id,created_at FROM notifications WHERE client_id=? ORDER BY created_at DESC");
            $notificationsStmt->execute([$cid]);
            $notificationRows = $notificationsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $favoritesStmt = $pdo->prepare("
                SELECT p.id, p.name, p.category, p.price
                FROM client_favorites cf
                INNER JOIN products p ON p.id = cf.product_id
                WHERE cf.client_id=?
                ORDER BY cf.created_at DESC
            ");
            $favoritesStmt->execute([$cid]);
            $favoriteRows = $favoritesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $reviewsStmt = $pdo->prepare("
                SELECT pr.product_id, p.name AS product_name, pr.rating, pr.review_text, pr.created_at
                FROM product_reviews pr
                LEFT JOIN products p ON p.id = pr.product_id
                WHERE pr.client_id=?
                ORDER BY pr.created_at DESC
            ");
            $reviewsStmt->execute([$cid]);
            $reviewRows = $reviewsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $export = [
                'export_date' => date('Y-m-d H:i:s'),
                'application' => 'Espérance H2O',
                'client' => $clientRow,
                'orders' => $orderRows,
                'notifications' => $notificationRows,
                'favorites' => $favoriteRows,
                'reviews' => $reviewRows,
                'total_orders' => count($orderRows),
                'total_spent' => array_sum(array_column(
                    array_filter($orderRows, fn($o)=>$o['status']==='done'),
                    'total_amount'
                )),
            ];
            echo json_encode([
                'success'=>true,
                'filename'=>'client-export-' . $cid . '-' . date('Ymd-His') . '.json',
                'data'=>$export,
            ]);
        }catch(Throwable $e){
            echo json_encode(['success'=>false,'message'=>'Erreur export']);
        }
        exit;
    }

    /* ── Demande suppression de compte ── */
    if($action === 'request_delete'){
        $cid = (int)($_SESSION['client_id']??0);
        if(!$cid){echo json_encode(['success'=>false,'message'=>'Non connecté']);exit;}
        try{
            $client = $pdo->prepare("SELECT name,phone,email FROM clients WHERE id=? LIMIT 1");
            $client->execute([$cid]);
            $cl = $client->fetch(PDO::FETCH_ASSOC) ?: [];
            $pdo->exec("CREATE TABLE IF NOT EXISTS account_deletion_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                client_id INT NOT NULL,
                client_name VARCHAR(255) NOT NULL DEFAULT '',
                client_phone VARCHAR(50) NOT NULL DEFAULT '',
                client_email VARCHAR(190) NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'pending',
                requested_at DATETIME NOT NULL,
                processed_at DATETIME NULL,
                admin_note TEXT NULL,
                INDEX idx_client_status (client_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $existing = $pdo->prepare("SELECT id FROM account_deletion_requests WHERE client_id=? AND status='pending' ORDER BY id DESC LIMIT 1");
            $existing->execute([$cid]);
            if ($existing->fetchColumn()) {
                echo json_encode(['success'=>false,'message'=>'Une demande est déjà en attente de traitement']);
                exit;
            }
            $req = $pdo->prepare("
                INSERT INTO account_deletion_requests(client_id,client_name,client_phone,client_email,status,requested_at)
                VALUES(?,?,?,?, 'pending', NOW())
            ");
            $req->execute([
                $cid,
                (string)($cl['name'] ?? ''),
                (string)($cl['phone'] ?? ''),
                (string)($cl['email'] ?? ''),
            ]);
            $msg = "[SUPPRESSION COMPTE] Client #{$cid} — ".($cl['name']??'')." / ".($cl['phone']??'')." a demandé la suppression de son compte le ".date('d/m/Y à H:i');
            $pdo->exec("CREATE TABLE IF NOT EXISTS admin_notes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                type VARCHAR(50) NOT NULL DEFAULT 'info',
                message TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $ins = $pdo->prepare("INSERT INTO admin_notes(type,message) VALUES('delete_request',?)");
            $ins->execute([$msg]);
            $_SESSION['delete_requested'] = true;
            echo json_encode(['success'=>true,'message'=>'Demande envoyée à l\'administrateur']);
        }catch(Throwable $e){
            echo json_encode(['success'=>false,'message'=>'Erreur envoi demande']);
        }
        exit;
    }

    if($action === 'get_notifications'){
        try{
            $cid = (int)($_SESSION['client_id']??0);
            if(!$cid){echo json_encode(['success'=>false,'message'=>'Non connecté']);exit;}
            $st = $pdo->prepare("SELECT * FROM notifications WHERE client_id=? ORDER BY created_at DESC LIMIT 50");
            $st->execute([$cid]);
            $notifs = $st->fetchAll(PDO::FETCH_ASSOC);
            $unread = (int)$pdo->prepare("SELECT COUNT(*) FROM notifications WHERE client_id=? AND is_read=0")->execute([$cid]);
            $stU = $pdo->prepare("SELECT COUNT(*) as cnt FROM notifications WHERE client_id=? AND is_read=0");
            $stU->execute([$cid]);
            $unread = (int)$stU->fetchColumn();
            echo json_encode(['success'=>true,'notifications'=>$notifs,'unread'=>$unread]);
        }catch(Exception $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }

    if($action === 'mark_notifs_read'){
        try{
            $cid = (int)($_SESSION['client_id']??0);
            if(!$cid){echo json_encode(['success'=>false]);exit;}
            $pdo->prepare("UPDATE notifications SET is_read=1 WHERE client_id=?")->execute([$cid]);
            echo json_encode(['success'=>true]);
        }catch(Exception $e){echo json_encode(['success'=>false]);}
        exit;
    }

    if($action === 'toggle_favorite'){
        try{
            $cid = (int)($_SESSION['client_id']??0);
            $pid = (int)($_POST['product_id']??0);
            if(!$cid || !$pid){echo json_encode(['success'=>false,'message'=>'Données manquantes']);exit;}
            $st = $pdo->prepare("SELECT id FROM client_favorites WHERE client_id=? AND product_id=? LIMIT 1");
            $st->execute([$cid,$pid]);
            $exists = (bool)$st->fetchColumn();
            if($exists){
                $pdo->prepare("DELETE FROM client_favorites WHERE client_id=? AND product_id=?")->execute([$cid,$pid]);
            } else {
                $pdo->prepare("INSERT INTO client_favorites(client_id,product_id) VALUES(?,?)")->execute([$cid,$pid]);
            }
            echo json_encode(['success'=>true,'favorite'=>!$exists]);
        }catch(Exception $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }

    if($action === 'get_product_reviews'){
        try{
            $pid = (int)($_POST['product_id']??0);
            if($pid<=0){echo json_encode(['success'=>false,'message'=>'Produit invalide']);exit;}
            $reviews = fetchProductReviews($pdo, $pid);
            $stats = fetchReviewStats($pdo, [$pid]);
            echo json_encode(['success'=>true,'reviews'=>$reviews,'stats'=>$stats[$pid] ?? ['avg_rating'=>0,'review_count'=>0]]);
        }catch(Exception $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }

    if($action === 'save_product_review'){
        try{
            $cid = (int)($_SESSION['client_id']??0);
            $pid = (int)($_POST['product_id']??0);
            $rating = max(1,min(5,(int)($_POST['rating']??5)));
            $review = trim((string)($_POST['review_text']??''));
            if(!$cid || !$pid){echo json_encode(['success'=>false,'message'=>'Données manquantes']);exit;}
            $pdo->prepare("INSERT INTO product_reviews(product_id,client_id,rating,review_text) VALUES(?,?,?,?)")
                ->execute([$pid,$cid,$rating,$review]);
            $reviews = fetchProductReviews($pdo, $pid);
            $stats = fetchReviewStats($pdo, [$pid]);
            echo json_encode(['success'=>true,'reviews'=>$reviews,'stats'=>$stats[$pid] ?? ['avg_rating'=>0,'review_count'=>0]]);
        }catch(Exception $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }

    if($action === 'sync_cart'){
        try{
            $cid = (int)($_SESSION['client_id']??0);
            $coid = (int)($_POST['company_id']??0);
            $ciid = (int)($_POST['city_id']??0);
            $cartItems = json_decode($_POST['cart']??'[]', true);
            if($cid<=0){echo json_encode(['success'=>false,'message'=>'Non connecté']);exit;}
            if(!is_array($cartItems)){$cartItems=[];}
            saveAbandonedCartSnapshot($pdo, $cid, $coid, $ciid, $cartItems);
            echo json_encode(['success'=>true]);
        }catch(Exception $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }

    if($action === 'log_whatsapp_click'){
        try{
            $cid = (int)($_SESSION['client_id'] ?? 0);
            $orderId = (int)($_POST['order_id'] ?? 0);
            $actionType = trim((string)($_POST['click_type'] ?? 'contact'));
            $phone = trim((string)($_POST['target_phone'] ?? ''));
            if($cid > 0){
                $pdo->prepare("INSERT INTO whatsapp_click_logs(client_id,order_id,action_type,target_phone) VALUES(?,?,?,?)")
                    ->execute([$cid, $orderId ?: null, $actionType, $phone]);
            }
            echo json_encode(['success'=>true]);
        }catch(Throwable $e){
            echo json_encode(['success'=>false]);
        }
        exit;
    }

    if($action === 'validate_coupon'){
        try{
            $cid = (int)($_SESSION['client_id']??0);
            $code = trim((string)($_POST['coupon_code'] ?? ''));
            $items_raw = json_decode($_POST['items']??'[]', true);
            $coid = (int)($_POST['company_id']??0);
            $ciid = (int)($_POST['city_id']??0);
            if($cid<=0){throw new Exception('Non connecté');}
            if($code===''){throw new Exception('Code coupon requis');}
            if(!is_array($items_raw) || empty($items_raw)){throw new Exception('Panier vide');}
            $promotionState = loadActivePromotions($pdo, $coid, $ciid);
            $promotionsByProduct = $promotionState['by_product'];
            $campaignsById = [];
            foreach ($promotionState['campaigns'] as $campaign) {
                $campaignsById[(int)$campaign['id']] = $campaign;
            }
            $subtotal = 0.0;
            foreach($items_raw as $item){
                $type = trim((string)($item['item_type'] ?? 'product'));
                $qty  = max(1, (int)($item['quantity']??0));
                if($type === 'pack'){
                    $promotionId = (int)($item['promotion_id'] ?? 0);
                    $campaign = $campaignsById[$promotionId] ?? null;
                    if ($campaign && ($campaign['promo_type'] ?? '') === 'pack') {
                        $subtotal += round((float)($campaign['promo_price'] ?? 0) * $qty, 2);
                    }
                    continue;
                }
                $pid = (int)($item['product_id']??0);
                if($pid<=0){continue;}
                $ps=$pdo->prepare("SELECT price FROM products WHERE id=? LIMIT 1");
                $ps->execute([$pid]);
                $basePrice = (float)($ps->fetchColumn() ?: 0);
                if($basePrice<=0){continue;}
                $bestPromo = chooseBestProductPromotion($promotionsByProduct[$pid] ?? [], $basePrice);
                $pricing = $bestPromo ? calculatePromotionPricing($bestPromo, $basePrice, $qty) : ['total' => round($qty * $basePrice, 2)];
                $subtotal += (float)$pricing['total'];
            }
            if($subtotal<=0){throw new Exception('Panier invalide');}
            $coupon = findApplicableCoupon($pdo, $cid, $code, $subtotal);
            if(!$coupon){throw new Exception('Coupon introuvable');}
            echo json_encode([
                'success'=>true,
                'coupon'=>[
                    'code'=>(string)$coupon['code'],
                    'title'=>(string)($coupon['title'] ?? 'Coupon personnalisé'),
                    'discount_amount'=>(float)$coupon['discount_amount'],
                    'discount_percent'=>(float)($coupon['discount_percent'] ?? 0),
                    'amount_off'=>(float)($coupon['amount_off'] ?? 0),
                    'min_amount'=>(float)($coupon['min_amount'] ?? 0),
                    'expires_at'=>(string)($coupon['expires_at'] ?? ''),
                ],
                'subtotal'=>$subtotal,
                'total'=>max(0, $subtotal - (float)$coupon['discount_amount']),
            ]);
        }catch(Exception $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }

    if($action === 'get_order_detail'){
        try{
            $oid = (int)($_POST['order_id']??0);
            $cid = (int)($_SESSION['client_id']??0);
            if(!$oid||!$cid){echo json_encode(['success'=>false,'message'=>'Données manquantes']);exit;}
            $st = $pdo->prepare("
                SELECT o.*,ci.name AS city_name,co.name AS company_name
                FROM orders o
                LEFT JOIN cities ci ON o.city_id=ci.id
                LEFT JOIN companies co ON o.company_id=co.id
                WHERE o.id=? AND o.client_id=? LIMIT 1");
            $st->execute([$oid,$cid]);
            $order = $st->fetch(PDO::FETCH_ASSOC);
            if(!$order){echo json_encode(['success'=>false,'message'=>'Introuvable']);exit;}
            $st2 = $pdo->prepare("
                SELECT oi.*, p.image_path
                FROM order_items oi
                LEFT JOIN products p ON p.id = oi.product_id
                WHERE oi.order_id=?
            ");
            $st2->execute([$oid]);
            $order['items'] = $st2->fetchAll(PDO::FETCH_ASSOC);
            foreach($order['items'] as &$item){
                $item['image_url'] = !empty($item['image_path']) ? project_url($item['image_path']) : '';
            }
            unset($item);
            echo json_encode(['success'=>true,'order'=>$order]);
        }catch(Exception $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }

    if($action === 'create_order'){
        try{
            $pdo->beginTransaction();
            $coid      = (int)($_POST['company_id']??0);
            $ciid      = (int)($_POST['city_id']??0);
            $client_id = (int)($_POST['client_id']??0);
            $addr      = trim($_POST['delivery_address']??'');
            $pay       = trim($_POST['payment_method']??'cash');
            $notes     = trim($_POST['notes']??'');
            $couponCode = strtoupper(trim((string)($_POST['coupon_code'] ?? '')));
            $deliveryZoneId = (int)($_POST['delivery_zone_id'] ?? 0);
            $items_raw = json_decode($_POST['items']??'[]',true);

            if($client_id<=0) throw new Exception('Client non identifié');
            if(empty($addr))  throw new Exception('Adresse obligatoire');
            if(!is_array($items_raw)||empty($items_raw)) throw new Exception('Panier vide');

            $promotionState = loadActivePromotions($pdo, $coid, $ciid);
            $promotionsByProduct = $promotionState['by_product'];
            $campaignsById = [];
            foreach ($promotionState['campaigns'] as $campaign) {
                $campaignsById[(int)$campaign['id']] = $campaign;
            }

            $selectedDeliveryZone = getDeliveryZoneById($pdo, $coid, $ciid, $deliveryZoneId);
            $deliveryFee = $selectedDeliveryZone ? (float)($selectedDeliveryZone['delivery_fee'] ?? 0) : 0.0;
            $clean=[]; $total=0.0;
            foreach($items_raw as $item){
                $type = trim((string)($item['item_type'] ?? 'product'));
                $qty  = max(1, (int)($item['quantity']??0));

                if($type === 'pack'){
                    $promotionId = (int)($item['promotion_id'] ?? 0);
                    $campaign = $campaignsById[$promotionId] ?? null;
                    if (!$campaign || ($campaign['promo_type'] ?? '') !== 'pack' || empty($campaign['items'])) {
                        continue;
                    }
                    $packPrice = (float)($campaign['promo_price'] ?? 0);
                    if ($packPrice <= 0) {
                        continue;
                    }
                    $normalTotal = 0.0;
                    foreach ($campaign['items'] as $packItem) {
                        $normalTotal += ((float)$packItem['product_price']) * ((int)$packItem['quantity']);
                    }
                    $normalTotal = $normalTotal > 0 ? $normalTotal : $packPrice;
                    $promoTotal = round($packPrice * $qty, 2);
                    $total += $promoTotal;
                    $components = [];
                    foreach ($campaign['items'] as $packItem) {
                        $componentQty = (int)$packItem['quantity'] * $qty;
                        $shareRatio = (((float)$packItem['product_price']) * ((int)$packItem['quantity'])) / $normalTotal;
                        $componentSubtotal = round($promoTotal * $shareRatio, 2);
                        $components[] = [
                            'product_id' => (int)$packItem['product_id'],
                            'product_name' => $campaign['title'] . ' • ' . $packItem['product_name'],
                            'quantity' => $componentQty,
                            'unit_price' => round($componentQty > 0 ? $componentSubtotal / $componentQty : 0, 2),
                            'subtotal' => $componentSubtotal,
                        ];
                    }
                    if (!empty($components)) {
                        $delta = round($promoTotal - array_sum(array_column($components, 'subtotal')), 2);
                        $components[count($components) - 1]['subtotal'] = round($components[count($components) - 1]['subtotal'] + $delta, 2);
                        $lastQty = max(1, (int)$components[count($components) - 1]['quantity']);
                        $components[count($components) - 1]['unit_price'] = round($components[count($components) - 1]['subtotal'] / $lastQty, 2);
                        foreach ($components as $component) {
                            $clean[] = $component;
                        }
                    }
                    continue;
                }

                $pid   = (int)($item['product_id']??0);
                if($pid<=0||$qty<=0) continue;
                $ps=$pdo->prepare("SELECT name,price FROM products WHERE id=? LIMIT 1");
                $ps->execute([$pid]);
                $pr=$ps->fetch(PDO::FETCH_ASSOC);
                if(!$pr){ continue; }

                $basePrice = (float)($pr['price'] ?? 0);
                $pname = trim($item['product_name']??'') ?: (string)$pr['name'];
                $price = $basePrice;

                $bestPromo = chooseBestProductPromotion($promotionsByProduct[$pid] ?? [], $basePrice);
                if ($bestPromo) {
                    $pricing = calculatePromotionPricing($bestPromo, $basePrice, $qty);
                    $price = (float)$pricing['unit_price'];
                    $sub = (float)$pricing['total'];
                } else {
                    $sub = round($qty * $price, 2);
                }

                if($price<=0 || $sub<=0){ continue; }
                $total += $sub;
                $clean[]=['product_id'=>$pid,'product_name'=>$pname,'quantity'=>$qty,'unit_price'=>$price,'subtotal'=>$sub];
            }
            if(empty($clean)) throw new Exception('Aucun article valide');

            $couponDiscount = 0.0;
            $coupon = null;
            if ($couponCode !== '') {
                $coupon = findApplicableCoupon($pdo, $client_id, $couponCode, $total);
                if (!$coupon) {
                    throw new Exception('Coupon introuvable');
                }
                $couponDiscount = min($total, (float)($coupon['discount_amount'] ?? 0));
                $total = max(0, round($total - $couponDiscount, 2));
            }

            $total = round($total + $deliveryFee, 2);

            $onum='CMD-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(3)));
            $pdo->prepare("INSERT INTO orders(order_number,client_id,company_id,city_id,delivery_zone_id,delivery_zone_name,delivery_address,payment_method,notes,total_amount,coupon_code,coupon_discount,delivery_fee)VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $onum,$client_id,$coid,$ciid,
                    $selectedDeliveryZone['id'] ?? null,
                    $selectedDeliveryZone['zone_name'] ?? null,
                    $addr,$pay,$notes,$total,
                    $coupon ? $coupon['code'] : null,$couponDiscount,$deliveryFee
                ]);
            $oid=(int)$pdo->lastInsertId();

            $st=$pdo->prepare("INSERT INTO order_items(order_id,product_id,product_name,quantity,unit_price,subtotal)VALUES(?,?,?,?,?,?)");
            foreach($clean as $ci){
                $st->execute([$oid,$ci['product_id'],$ci['product_name'],$ci['quantity'],$ci['unit_price'],$ci['subtotal']]);
                try{
                    $pdo->prepare("INSERT INTO stock_movements(product_id,company_id,city_id,type,quantity,notes,created_at)VALUES(?,?,?,'exit',?,?,NOW())")
                        ->execute([$ci['product_id'],$coid,$ciid,$ci['quantity'],'Commande '.$onum]);
                }catch(Exception $se){}
            }
            // Notif nouvelle commande
            $pdo->prepare("INSERT INTO notifications(client_id,title,message,type,order_id)VALUES(?,?,?,?,?)")
                ->execute([$client_id,'Commande reçue ! 🎉','Votre commande '.$onum.' a bien été enregistrée. Montant : '.number_format($total,0,'','.').' CFA.','order',$oid]);
            if ($selectedDeliveryZone) {
                $pdo->prepare("INSERT INTO notifications(client_id,title,message,type,order_id)VALUES(?,?,?,?,?)")
                    ->execute([$client_id,'Zone de livraison confirmée','Zone : '.$selectedDeliveryZone['zone_name'].' · Frais : '.number_format($deliveryFee,0,'','.').' CFA.','info',$oid]);
            }
            if ($coupon && $couponDiscount > 0) {
                $pdo->prepare("UPDATE personalized_coupons SET status='used', used_at=NOW(), used_order_id=? WHERE id=?")
                    ->execute([$oid, (int)$coupon['id']]);
                $pdo->prepare("INSERT INTO notifications(client_id,title,message,type,order_id)VALUES(?,?,?,?,?)")
                    ->execute([$client_id,'Coupon appliqué','Le coupon '.$coupon['code'].' a été appliqué. Réduction : '.number_format($couponDiscount,0,'','.').' CFA.','promo',$oid]);
            }
            $earnedPoints = (int)floor($total / 1000);
            if ($earnedPoints > 0) {
                $pdo->prepare("UPDATE clients SET loyalty_points = loyalty_points + ?, last_order_at=NOW() WHERE id=?")
                    ->execute([$earnedPoints, $client_id]);
                $pdo->prepare("INSERT INTO client_loyalty_transactions(client_id,points_delta,reason,reference_id) VALUES(?,?,?,?)")
                    ->execute([$client_id, $earnedPoints, 'Commande validée', $oid]);
            } else {
                $pdo->prepare("UPDATE clients SET last_order_at=NOW() WHERE id=?")->execute([$client_id]);
            }
            $profile = updateClientLoyaltyProfile($pdo, $client_id);
            $tierMeta = loyaltyTierMeta((string)$profile['vip_status']);
            $pdo->prepare("INSERT INTO notifications(client_id,title,message,type,order_id)VALUES(?,?,?,?,?)")
                ->execute([$client_id,'Points fidélité ajoutés','Vous avez gagné '.$earnedPoints.' points. Statut actuel : '.$tierMeta['label'].'.','promo',$oid]);
            $pdo->prepare("UPDATE abandoned_carts SET status='recovered', updated_at=NOW() WHERE client_id=? AND company_id=? AND city_id=? AND status='active'")
                ->execute([$client_id, $coid, $ciid]);
            $pdo->commit();
            try {
                $clientLabel = trim((string)($_SESSION['client_name'] ?? 'Client #' . $client_id));
                $body = sprintf(
                    'Commande %s · %s · %s CFA',
                    $onum,
                    $clientLabel,
                    number_format((float)$total, 0, '', '.')
                );
                appAlertNotifyRoles($pdo, appAlertOrderRoles(), [
                    'title' => 'Nouvelle commande client',
                    'body' => $body,
                    'url' => project_url('orders/admin_orders.php?highlight_order=' . $oid),
                    'tag' => 'order-' . $oid,
                    'unread' => 1,
                ], [
                    'event_type' => 'new_order',
                    'event_key' => 'order-created-' . $oid,
                ]);
            } catch (Throwable $e) {
                error_log('[ORDER ALERT] ' . $e->getMessage());
            }
            echo json_encode(['success'=>true,'order_number'=>$onum,'total'=>$total,'order_id'=>$oid,'earned_points'=>$earnedPoints,'vip_status'=>$profile['vip_status']]);
        }catch(Exception $e){
            $pdo->rollBack();
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Action inconnue']);
    exit;
}

/* ══════════════════════════════════════════════════════
   DONNÉES
══════════════════════════════════════════════════════ */
if(!isset($_SESSION['client_id']))    $_SESSION['client_id']    = 0;
if(!isset($_SESSION['client_name']))  $_SESSION['client_name']  = '';
if(!isset($_SESSION['client_phone'])) $_SESSION['client_phone'] = '';

$companies  = $pdo->query("SELECT id,name FROM companies ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$company_id = (int)($_GET['company_id']??$_SESSION['order_company_id']??0);
$city_id    = (int)($_GET['city_id']??$_SESSION['order_city_id']??0);
$_SESSION['order_company_id'] = $company_id;
$_SESSION['order_city_id']    = $city_id;

$cities=[];
$company_name='';
$city_name='';
if($company_id){
    $st=$pdo->prepare("SELECT id,name FROM cities WHERE company_id=? ORDER BY name");
    $st->execute([$company_id]);
    $cities=$st->fetchAll(PDO::FETCH_ASSOC);
    $stC=$pdo->prepare("SELECT name FROM companies WHERE id=? LIMIT 1");
    $stC->execute([$company_id]);
    $company_name=$stC->fetchColumn()?:'';
}
if($city_id){
    $stCi=$pdo->prepare("SELECT name FROM cities WHERE id=? LIMIT 1");
    $stCi->execute([$city_id]);
    $city_name=$stCi->fetchColumn()?:'';
}

$products=[];
$promotionCampaigns = [];
$productPromoMap = [];
if($company_id&&$city_id){
    $st=$pdo->prepare("
        SELECT p.*,
            (COALESCE((SELECT SUM(quantity) FROM stock_movements WHERE product_id=p.id AND company_id=? AND city_id=? AND type IN('initial','entry')),0)
            -COALESCE((SELECT SUM(quantity) FROM stock_movements WHERE product_id=p.id AND company_id=? AND city_id=? AND type='exit'),0)) AS stock
        FROM products p
        WHERE p.company_id=? AND p.city_id=?
        HAVING stock>0 ORDER BY p.name
    ");
    $st->execute([$company_id,$city_id,$company_id,$city_id,$company_id,$city_id]);
    $products=$st->fetchAll(PDO::FETCH_ASSOC);
    foreach($products as &$prod){
        if(!empty($prod['image_path'])){
            $prod['image_url']=project_url($prod['image_path']) . '?v=' . urlencode((string)@filemtime(project_path($prod['image_path'])));
        } else {
            $prod['image_url']='';
        }
    }
    unset($prod);

    $promotionState = loadActivePromotions($pdo, $company_id, $city_id);
    $productStocks = [];
    foreach ($products as $prod) {
        $productStocks[(int)$prod['id']] = [
            'stock' => (int)$prod['stock'],
            'name' => $prod['name'],
            'price' => (float)$prod['price'],
            'image_url' => $prod['image_url'] ?? '',
            'icon' => drinkIcon((string)$prod['name']),
        ];
    }
    foreach ($promotionState['campaigns'] as $campaign) {
        $items = $campaign['items'] ?? [];
        if (empty($items)) {
            continue;
        }
        $promoType = (string)($campaign['promo_type'] ?? 'simple');
        $firstItem = $items[0];
        $stock = 0;
        if ($promoType === 'pack') {
            $stock = PHP_INT_MAX;
            foreach ($items as $packItem) {
                $itemStock = (int)($productStocks[(int)$packItem['product_id']]['stock'] ?? 0);
                $neededQty = max(1, (int)$packItem['quantity']);
                $stock = min($stock, intdiv($itemStock, $neededQty));
            }
            if ($stock === PHP_INT_MAX) {
                $stock = 0;
            }
        } else {
            $stock = (int)($productStocks[(int)$campaign['product_id']]['stock'] ?? 0);
        }
        if ($stock <= 0) {
            continue;
        }

        $basePrice = (float)($productStocks[(int)$campaign['product_id']]['price'] ?? (float)($firstItem['product_price'] ?? 0));
        $pricing = $promoType === 'pack'
            ? ['old_price' => (float)($campaign['old_price'] ?: array_sum(array_map(fn($it) => ((float)$it['product_price']) * ((int)$it['quantity']), $items))), 'unit_price' => (float)$campaign['promo_price'], 'total' => (float)$campaign['promo_price'], 'summary' => 'Pack promotionnel']
            : calculatePromotionPricing($campaign, $basePrice, 1);

        $endsAt = trim((string)($campaign['ends_at'] ?? ''));
        $timeRemaining = '';
        if ($endsAt !== '') {
            $remaining = strtotime($endsAt) - time();
            if ($remaining > 0) {
                $hours = floor($remaining / 3600);
                $minutes = floor(($remaining % 3600) / 60);
                $seconds = $remaining % 60;
                $timeRemaining = sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
            }
        }

        $tiers = parsePromotionTiers($campaign['tiers_json'] ?? null);
        $promotionCampaigns[] = [
            'id' => (int)$campaign['id'],
            'title' => (string)$campaign['title'],
            'subtitle' => (string)($campaign['subtitle'] ?? ''),
            'promo_type' => $promoType,
            'filter_tag' => (string)($campaign['filter_tag'] ?? 'reduction'),
            'badge_label' => (string)($campaign['badge_label'] ?? 'PROMO'),
            'discount_percent' => (float)($campaign['discount_percent'] ?? 0),
            'old_price' => (float)$pricing['old_price'],
            'promo_price' => (float)$pricing['unit_price'],
            'stock' => $stock,
            'starts_at' => (string)($campaign['starts_at'] ?? ''),
            'ends_at' => $endsAt,
            'time_remaining' => $timeRemaining,
            'quantity_buy' => (int)($campaign['quantity_buy'] ?? 0),
            'quantity_pay' => (int)($campaign['quantity_pay'] ?? 0),
            'tiers' => $tiers,
            'summary' => (string)$pricing['summary'],
            'items' => array_map(function (array $promotionItem) use ($productStocks): array {
                $productId = (int)$promotionItem['product_id'];
                return [
                    'product_id' => $productId,
                    'product_name' => (string)$promotionItem['product_name'],
                    'quantity' => (int)$promotionItem['quantity'],
                    'product_price' => (float)$promotionItem['product_price'],
                    'image_url' => (string)($productStocks[$productId]['image_url'] ?? ($promotionItem['image_url'] ?? '')),
                    'icon' => (string)($productStocks[$productId]['icon'] ?? drinkIcon((string)$promotionItem['product_name'])),
                ];
            }, $items),
        ];
    }

    foreach ($products as &$prod) {
        $bestPromo = chooseBestProductPromotion($promotionState['by_product'][(int)$prod['id']] ?? [], (float)$prod['price']);
        if ($bestPromo) {
            $pricing = calculatePromotionPricing($bestPromo, (float)$prod['price'], 1);
            $prod['promo'] = [
                'promotion_id' => (int)$bestPromo['id'],
                'promo_type' => (string)$bestPromo['promo_type'],
                'discount_percent' => (float)($bestPromo['discount_percent'] ?? 0),
                'old_price' => (float)$pricing['old_price'],
                'promo_price' => (float)$pricing['unit_price'],
                'summary' => (string)$pricing['summary'],
                'badge_label' => (string)($bestPromo['badge_label'] ?? 'PROMO'),
                'quantity_buy' => (int)($bestPromo['quantity_buy'] ?? 0),
                'quantity_pay' => (int)($bestPromo['quantity_pay'] ?? 0),
                'tiers' => parsePromotionTiers($bestPromo['tiers_json'] ?? null),
                'ends_at' => (string)($bestPromo['ends_at'] ?? ''),
            ];
            $productPromoMap[(int)$prod['id']] = $prod['promo'];
        } else {
            $prod['promo'] = null;
        }
    }
    unset($prod);
}

$location_set = ($company_id>0&&$city_id>0);
$client_id    = (int)$_SESSION['client_id'];
$client_name  = $_SESSION['client_name'];
$client_phone = $_SESSION['client_phone'];
$favoriteProductIds = fetchFavoriteProductIds($pdo, $client_id);
$loyaltyProfile = ['points' => 0, 'vip_status' => 'standard', 'total_spent' => 0, 'orders_count' => 0];
if ($client_id > 0) {
    $loyaltyProfile = updateClientLoyaltyProfile($pdo, $client_id);
}

if (!empty($products)) {
    $productIds = array_map(fn(array $p): int => (int)$p['id'], $products);
    $reviewStats = fetchReviewStats($pdo, $productIds);
    $popStmt = $pdo->prepare("
        SELECT oi.product_id, COUNT(*) AS sold_count
        FROM order_items oi
        INNER JOIN orders o ON o.id = oi.order_id
        WHERE o.company_id=? AND o.city_id=?
        GROUP BY oi.product_id
    ");
    $popStmt->execute([$company_id, $city_id]);
    $popularity = [];
    foreach ($popStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $popularity[(int)$row['product_id']] = (int)$row['sold_count'];
    }
    foreach ($products as &$prod) {
        $stats = $reviewStats[(int)$prod['id']] ?? ['avg_rating' => 0, 'review_count' => 0];
        $prod['avg_rating'] = (float)$stats['avg_rating'];
        $prod['review_count'] = (int)$stats['review_count'];
        $prod['is_favorite'] = in_array((int)$prod['id'], $favoriteProductIds, true);
        $prod['sold_count'] = (int)($popularity[(int)$prod['id']] ?? 0);
    }
    unset($prod);
}

$recommendedProducts = $products;
usort($recommendedProducts, function(array $a, array $b): int {
    $scoreA = ((int)($a['sold_count'] ?? 0) * 3) + ((float)($a['avg_rating'] ?? 0) * 10) + ((int)($a['review_count'] ?? 0));
    $scoreB = ((int)($b['sold_count'] ?? 0) * 3) + ((float)($b['avg_rating'] ?? 0) * 10) + ((int)($b['review_count'] ?? 0));
    return $scoreB <=> $scoreA;
});
$recommendedProducts = array_slice($recommendedProducts, 0, 6);
$shopCategories = array_values(array_unique(array_filter(array_map(fn(array $p): string => trim((string)($p['category'] ?? '')), $products))));
sort($shopCategories);
$heroProducts = array_values(array_filter($recommendedProducts, static function (array $product): bool {
    return !empty($product['image_url']);
}));
if (count($heroProducts) < 3) {
    $fallbackHeroProducts = array_values(array_filter($products, static function (array $product): bool {
        return !empty($product['image_url']);
    }));
    foreach ($fallbackHeroProducts as $fallbackHeroProduct) {
        $alreadyIncluded = false;
        foreach ($heroProducts as $heroProduct) {
            if ((int)$heroProduct['id'] === (int)$fallbackHeroProduct['id']) {
                $alreadyIncluded = true;
                break;
            }
        }
        if (!$alreadyIncluded) {
            $heroProducts[] = $fallbackHeroProduct;
        }
        if (count($heroProducts) >= 3) {
            break;
        }
    }
}
$heroProducts = array_slice($heroProducts, 0, 3);
$abandonedCartRecovery = processAbandonedCartRecovery($pdo, $client_id);
$loyaltyTier = loyaltyTierMeta((string)$loyaltyProfile['vip_status']);
$deliveryZones = loadDeliveryZones($pdo, $company_id, $city_id);
$pendingDeletionRequest = fetchPendingDeletionRequest($pdo, $client_id);

// Unread notif count
$unread_notifs = 0;
if($client_id){
    try{
        $stN=$pdo->prepare("SELECT COUNT(*) FROM notifications WHERE client_id=? AND is_read=0");
        $stN->execute([$client_id]);
        $unread_notifs=(int)$stN->fetchColumn();
    }catch(Exception $e){}
}

function drinkIcon(string $n): string {
    $s=strtolower($n);
    if(str_contains($s,'eau')||str_contains($s,'water'))  return '💧';
    if(str_contains($s,'jus')||str_contains($s,'juice'))  return '🍹';
    if(str_contains($s,'lait'))  return '🥛';
    if(str_contains($s,'bière')) return '🍺';
    if(str_contains($s,'soda'))  return '🥤';
    if(str_contains($s,'energ')) return '⚡';
    return '🫙';
}

if (($_GET['download_export'] ?? '') === 'pdf') {
    $cid = (int)($_SESSION['client_id'] ?? 0);
    if ($cid <= 0) {
        http_response_code(403);
        exit('Non connecté');
    }

    require_once APP_ROOT . '/fpdf186/fpdf.php';

    $client = $pdo->prepare("SELECT id,name,phone,email,created_at FROM clients WHERE id=? LIMIT 1");
    $client->execute([$cid]);
    $clientRow = $client->fetch(PDO::FETCH_ASSOC) ?: [];

    $ordersStmt = $pdo->prepare("
        SELECT o.order_number, o.status, o.total_amount, o.delivery_address, o.created_at
        FROM orders o
        WHERE o.client_id=?
        ORDER BY o.created_at DESC
        LIMIT 100
    ");
    $ordersStmt->execute([$cid]);
    $orderRows = $ordersStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->AddPage();
    $pdf->SetMargins(12, 12, 12);
    $pdf->SetAutoPageBreak(true, 12);
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(0, 10, utf8_decode('Export client - Espérance H2O'), 0, 1, 'C');
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 6, utf8_decode('Généré le ' . date('d/m/Y H:i')), 0, 1, 'C');
    $pdf->Ln(4);

    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 8, 'Profil client', 0, 1);
    $pdf->SetFont('Arial', '', 10);
    foreach ([
        'Nom' => (string)($clientRow['name'] ?? ''),
        'Telephone' => (string)($clientRow['phone'] ?? ''),
        'Email' => (string)($clientRow['email'] ?? ''),
        'Inscription' => (string)($clientRow['created_at'] ?? ''),
    ] as $label => $value) {
        $pdf->Cell(42, 7, $label . ' :', 0, 0);
        $pdf->Cell(0, 7, utf8_decode($value), 0, 1);
    }

    $pdf->Ln(4);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 8, 'Commandes recentes', 0, 1);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(36, 7, 'Numero', 1);
    $pdf->Cell(25, 7, 'Statut', 1);
    $pdf->Cell(30, 7, 'Montant', 1);
    $pdf->Cell(0, 7, 'Date', 1, 1);
    $pdf->SetFont('Arial', '', 9);
    foreach ($orderRows as $row) {
        $pdf->Cell(36, 7, utf8_decode((string)$row['order_number']), 1);
        $pdf->Cell(25, 7, utf8_decode((string)$row['status']), 1);
        $pdf->Cell(30, 7, number_format((float)$row['total_amount'], 0, '', '.') . ' CFA', 1);
        $pdf->Cell(0, 7, utf8_decode((string)$row['created_at']), 1, 1);
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="client-export-' . $cid . '.pdf"');
    $pdf->Output('I', 'client-export-' . $cid . '.pdf');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=5,user-scalable=yes">
<title>Commander — ESPERANCE H2O</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:wght@700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
@font-face{
    font-family:'C059';
    src:local('C059-Bold'),local('C059 Bold'),local('Century Schoolbook');
    font-weight:700 900;
}
:root{
    --bg:#f4f7fb;--surf:#ffffff;--card:#ffffff;--card2:#eef2f7;
    --bord:rgba(0,0,0,.09);
    --neon:#00a86b;--neon2:#00c87a;--red:#e53935;--orange:#f57c00;
    --blue:#1976d2;--gold:#f9a825;--cyan:#0097a7;--purple:#7e57c2;
    --text:#1a2e3a;--text2:#4a6375;--muted:#8a9fad;
    --glow:0 2px 12px rgba(0,168,107,.22);
    --glow-r:0 2px 12px rgba(229,57,53,.22);
    --fh:'C059','Source Serif 4','Book Antiqua',Georgia,serif;
}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
html{scroll-behavior:smooth;-webkit-tap-highlight-color:transparent;}
body{
    font-family:var(--fh);font-weight:900;font-size:15px;
    background:var(--bg);color:var(--text);min-height:100vh;
    overflow-x:hidden;line-height:1.6;
}
body::before{
    content:'';position:fixed;inset:0;z-index:0;pointer-events:none;
    background:radial-gradient(ellipse 65% 42% at 4% 8%,rgba(0,168,107,.05) 0%,transparent 62%),
               radial-gradient(ellipse 52% 36% at 96% 88%,rgba(25,118,210,.04) 0%,transparent 62%);
}
body::after{
    content:'';position:fixed;inset:0;z-index:0;pointer-events:none;
    background-image:linear-gradient(rgba(0,0,0,.014) 1px,transparent 1px),
                     linear-gradient(90deg,rgba(0,0,0,.014) 1px,transparent 1px);
    background-size:46px 46px;
}
.wrap{position:relative;z-index:1;max-width:100%;padding:0 12px 110px;margin:0 auto;}

@keyframes fadeUp{from{opacity:0;transform:translateY(18px)}to{opacity:1;transform:translateY(0)}}
@keyframes breathe{0%,100%{box-shadow:0 0 8px rgba(0,168,107,.2)}50%{box-shadow:0 0 20px rgba(0,168,107,.42)}}
@keyframes spin{to{transform:rotate(360deg)}}
@keyframes pulse{0%,100%{transform:scale(1)}50%{transform:scale(1.05)}}
@keyframes slideIn{from{opacity:0;transform:translateX(-15px)}to{opacity:1;transform:translateX(0)}}
@keyframes slideDown{from{opacity:0;transform:translateY(-10px)}to{opacity:1;transform:translateY(0)}}
@keyframes notifPop{0%{transform:scale(.7);opacity:0}60%{transform:scale(1.15)}100%{transform:scale(1);opacity:1}}
@keyframes progress{from{width:0}to{width:100%}}
@keyframes shimmer{0%{background-position:-200% 0}100%{background-position:200% 0}}

/* ─── TOPBAR ─── */
.topbar{
    position:sticky;top:0;z-index:800;
    background:rgba(255,255,255,.97);border-bottom:1px solid var(--bord);
    backdrop-filter:blur(20px);padding:10px 12px;margin-bottom:0;
}
.topbar-row1{
    display:flex;align-items:center;justify-content:space-between;gap:8px;
    margin-bottom:0;
}
.brand{display:flex;align-items:center;gap:8px;flex-shrink:0;}
.brand-ico{
    width:34px;height:34px;border-radius:10px;
    background:linear-gradient(135deg,var(--neon),var(--cyan));
    display:flex;align-items:center;justify-content:center;
    font-size:17px;color:#fff;box-shadow:var(--glow);
    animation:breathe 3.5s ease-in-out infinite;
}
.brand-txt{font-family:var(--fh);font-size:13px;font-weight:900;color:var(--text);}
.brand-txt span{color:var(--neon);}

/* Location pill */
.loc-pill{
    display:flex;align-items:center;gap:5px;
    padding:5px 10px;border-radius:20px;margin-top:7px;
    background:rgba(0,168,107,.06);border:1px solid rgba(0,168,107,.18);
}
.loc-pill i{font-size:9px;color:var(--neon);}
.loc-pill span{font-family:var(--fh);font-size:10px;font-weight:900;color:var(--text2);}
.loc-sep{color:var(--muted);font-size:10px;}

.topbar-actions{display:flex;align-items:center;gap:6px;flex-shrink:0;}
.user-mini{
    display:flex;align-items:center;gap:6px;
    padding:5px 10px;border-radius:20px;
    background:rgba(0,168,107,.06);border:1px solid rgba(0,168,107,.2);
}
.user-av{
    width:26px;height:26px;border-radius:50%;
    background:linear-gradient(135deg,var(--neon),var(--cyan));
    display:flex;align-items:center;justify-content:center;
    font-size:11px;font-weight:900;color:#fff;
}
.user-name{font-family:var(--fh);font-size:10px;font-weight:900;color:var(--text);}

/* Notif button */
.notif-btn{
    position:relative;width:34px;height:34px;border-radius:50%;
    background:rgba(249,168,37,.07);border:1.5px solid rgba(249,168,37,.22);
    display:flex;align-items:center;justify-content:center;
    cursor:pointer;font-size:14px;color:var(--gold);
    transition:all .25s;-webkit-tap-highlight-color:transparent;
}
.notif-btn:active{transform:scale(.9);}
.notif-badge{
    position:absolute;top:-3px;right:-3px;
    min-width:16px;height:16px;border-radius:8px;padding:0 4px;
    background:var(--red);color:#fff;font-family:var(--fh);
    font-size:9px;font-weight:900;display:flex;
    align-items:center;justify-content:center;
    animation:notifPop .4s ease;border:1.5px solid #fff;
}
.notif-badge.hidden{display:none;}

/* Logout button */
.logout-btn{
    width:34px;height:34px;border-radius:50%;
    background:rgba(229,57,53,.07);border:1.5px solid rgba(229,57,53,.22);
    display:flex;align-items:center;justify-content:center;
    cursor:pointer;font-size:12px;color:var(--red);
    transition:all .25s;-webkit-tap-highlight-color:transparent;
    text-decoration:none;
}
.logout-btn:active{background:var(--red);color:#fff;}

/* ─── TABS ─── */
.tabs{
    display:flex;gap:5px;background:rgba(0,0,0,.05);
    border-radius:12px;padding:4px;margin:10px 12px 0;
    overflow-x:auto;-webkit-overflow-scrolling:touch;position:relative;z-index:1;
}
.tabs::-webkit-scrollbar{display:none;}
.tab{
    padding:9px 14px;border-radius:9px;border:1.5px solid transparent;
    background:none;cursor:pointer;font-family:var(--fh);font-size:12px;
    font-weight:900;color:var(--muted);letter-spacing:.5px;
    transition:all .25s;white-space:nowrap;flex-shrink:0;
    -webkit-tap-highlight-color:transparent;display:flex;align-items:center;gap:5px;
}
.tab.on{
    background:rgba(0,168,107,.1);color:var(--neon);
    border-color:rgba(0,168,107,.28);
}
.tab-count{
    background:rgba(0,168,107,.15);color:var(--neon);
    width:18px;height:18px;border-radius:9px;
    font-size:9px;display:flex;align-items:center;justify-content:center;
}

/* ─── PANEL ─── */
.panel{display:none;animation:fadeUp .35s ease;padding-top:10px;}
.panel.show{display:block;}

/* ─── CARD ─── */
.card{
    background:var(--card);border:1px solid var(--bord);
    border-radius:16px;overflow:hidden;margin-bottom:12px;
}
.ch{
    padding:13px 15px;border-bottom:1px solid rgba(0,0,0,.06);
    background:rgba(0,0,0,.02);display:flex;align-items:center;
    justify-content:space-between;gap:10px;
}
.chtitle{font-family:var(--fh);font-size:14px;font-weight:900;color:var(--text);letter-spacing:.4px;}
.cbody{padding:15px;}

/* ─── FORMS ─── */
.fg{margin-bottom:12px;}
.fg label{
    font-family:var(--fh);font-size:11px;font-weight:900;
    color:var(--muted);text-transform:uppercase;letter-spacing:1px;
    display:block;margin-bottom:5px;
}
.fg input,.fg select,.fg textarea{
    width:100%;padding:11px 13px;background:rgba(0,0,0,.03);
    border:1.5px solid var(--bord);border-radius:10px;color:var(--text);
    font-family:var(--fh);font-size:14px;font-weight:700;
    transition:all .25s;appearance:none;
}
.fg textarea{resize:vertical;min-height:80px;}
.fg input:focus,.fg select:focus,.fg textarea:focus{
    outline:none;border-color:var(--neon);box-shadow:var(--glow);
}
.fg input::placeholder,.fg textarea::placeholder{color:var(--muted);}
.fg select option{background:#fff;color:var(--text);}

/* ─── BUTTONS ─── */
.btn{
    display:inline-flex;align-items:center;justify-content:center;gap:7px;
    padding:11px 17px;border-radius:10px;border:1.5px solid transparent;
    cursor:pointer;font-family:var(--fh);font-size:13px;font-weight:900;
    letter-spacing:.5px;transition:all .25s;text-decoration:none;
    white-space:nowrap;-webkit-tap-highlight-color:transparent;
}
.btn:active{transform:scale(.96);}
.btn-n{background:rgba(0,168,107,.08);border-color:rgba(0,168,107,.25);color:var(--neon);}
.btn-n:hover{background:var(--neon);color:#fff;box-shadow:var(--glow);}
.btn-r{background:rgba(229,57,53,.08);border-color:rgba(229,57,53,.25);color:var(--red);}
.btn-r:hover{background:var(--red);color:#fff;box-shadow:var(--glow-r);}
.btn-g{background:rgba(249,168,37,.08);border-color:rgba(249,168,37,.25);color:var(--gold);}
.btn-g:hover{background:var(--gold);color:var(--text);}
.btn-solid{
    background:linear-gradient(135deg,var(--neon),var(--cyan));
    color:#fff;border:none;box-shadow:var(--glow);
}
.btn-solid:hover{box-shadow:0 6px 20px rgba(0,168,107,.4);}
.btn-full{width:100%;}

/* ─── PRODUCTS GRID ─── */
.pgrid{
    display:grid;grid-template-columns:repeat(2,1fr);gap:10px;
    margin-bottom:14px;
}
.pcard{
    border-radius:14px;overflow:hidden;border:1px solid var(--bord);
    background:var(--card);transition:all .3s;
}
.pcard:active{transform:scale(.97);}
.pimg{
    height:110px;display:flex;align-items:center;justify-content:center;
    position:relative;overflow:hidden;
    background:linear-gradient(135deg,rgba(0,168,107,.06),rgba(0,151,167,.04));
}
.pimg img{width:100%;height:100%;object-fit:cover;display:block;}
.pemoji{font-size:46px;opacity:.9;}
.sbadge{
    position:absolute;top:6px;right:6px;
    font-family:var(--fh);font-size:9px;font-weight:900;
    padding:3px 7px;border-radius:8px;backdrop-filter:blur(8px);
}
.sok{background:rgba(0,168,107,.12);color:#006b44;border:1px solid rgba(0,168,107,.22);}
.slow{background:rgba(245,124,0,.12);color:#9a4a00;border:1px solid rgba(245,124,0,.22);}
.pbody{padding:9px;}
.pname{font-family:var(--fh);font-size:12px;font-weight:900;color:var(--text);margin-bottom:4px;line-height:1.3;}
.pprice{font-family:var(--fh);font-size:16px;font-weight:900;color:var(--neon);margin-bottom:7px;}
.pprice small{font-size:10px;color:var(--muted);}
.qrow{display:flex;align-items:center;gap:5px;margin-bottom:7px;}
.qbtn{
    width:30px;height:30px;border-radius:8px;
    border:1.5px solid var(--bord);background:rgba(0,168,107,.04);
    color:var(--text2);cursor:pointer;transition:all .22s;
    display:flex;align-items:center;justify-content:center;
    font-size:12px;-webkit-tap-highlight-color:transparent;
}
.qbtn:active{background:var(--neon);color:var(--bg);}
.qin{
    width:40px;text-align:center;padding:5px 3px;
    background:rgba(0,0,0,.03);border:1.5px solid var(--bord);
    border-radius:8px;color:var(--text);font-family:var(--fh);
    font-size:12px;font-weight:900;
}
.qin:focus{outline:none;border-color:var(--neon);}
.qmax{font-family:var(--fh);font-size:9px;font-weight:700;color:var(--muted);}
.badd{
    width:100%;padding:9px;border-radius:9px;border:none;
    cursor:pointer;font-family:var(--fh);font-size:11px;font-weight:900;
    letter-spacing:.5px;background:linear-gradient(135deg,var(--neon),var(--cyan));
    color:#fff;box-shadow:0 3px 10px rgba(0,168,107,.22);
    transition:all .25s;-webkit-tap-highlight-color:transparent;
}
.badd:active{transform:scale(.96);}

/* ─── FLOATING CART ─── */
.cfloat{
    position:fixed;bottom:16px;left:12px;right:12px;z-index:700;
    background:var(--card);border:1px solid var(--bord);border-radius:16px;
    box-shadow:0 12px 44px rgba(0,0,0,.12);max-height:70vh;
    transition:all .3s cubic-bezier(.23,1,.32,1);overflow:hidden;
}
.cfloat.mini{
    left:auto;right:16px;width:56px;height:56px;border-radius:50%;
}
.cfloat.mini .cbdy,.cfloat.mini .cfot{display:none!important;}
.cfloat.mini .chd{
    border-radius:50%;padding:0;height:56px;
    justify-content:center;border-bottom:none;
}
.cfloat.mini .chtxt,.cfloat.mini .chtog{display:none;}
.chd{
    display:flex;align-items:center;justify-content:space-between;gap:8px;
    padding:11px 13px;cursor:pointer;
    background:linear-gradient(135deg,rgba(0,168,107,.1),rgba(0,151,167,.07));
    border-bottom:1px solid var(--bord);-webkit-tap-highlight-color:transparent;
}
.chico{
    width:28px;height:28px;border-radius:8px;
    background:linear-gradient(135deg,var(--neon),var(--cyan));
    display:flex;align-items:center;justify-content:center;
    font-size:13px;color:#fff;
}
.chtxt{font-family:var(--fh);font-size:12px;font-weight:900;color:var(--text);letter-spacing:.5px;}
.ccnt{
    background:var(--red);color:#fff;width:20px;height:20px;
    border-radius:50%;display:flex;align-items:center;justify-content:center;
    font-family:var(--fh);font-size:9px;font-weight:900;
}
.cbdy{padding:9px;max-height:230px;overflow-y:auto;background:rgba(0,0,0,.02);}
.cempty{text-align:center;padding:18px;color:var(--muted);}
.cempty i{font-size:28px;display:block;margin-bottom:7px;opacity:.1;}
.ci{
    display:flex;align-items:center;gap:7px;padding:7px;
    border-radius:9px;border:1px solid var(--bord);
    background:rgba(0,168,107,.02);margin-bottom:5px;
}
.ciico{font-size:17px;flex-shrink:0;}
.ciinf{flex:1;min-width:0;}
.ciname{font-family:var(--fh);font-size:11px;font-weight:900;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.cisub{font-family:var(--fh);font-size:9px;font-weight:700;color:var(--muted);}
.ciprice{font-family:var(--fh);font-size:11px;font-weight:900;color:var(--neon);flex-shrink:0;}
.cid{
    width:20px;height:20px;border-radius:50%;
    border:1px solid rgba(229,57,53,.2);background:rgba(229,57,53,.06);
    color:var(--red);cursor:pointer;display:flex;align-items:center;
    justify-content:center;font-size:11px;transition:all .22s;flex-shrink:0;
}
.cid:active{background:var(--red);color:#fff;}
.cfot{padding:11px;border-top:1px solid rgba(0,0,0,.06);background:rgba(0,0,0,.02);}
.ctrow{
    display:flex;align-items:center;justify-content:space-between;
    padding:7px 11px;border-radius:9px;
    background:rgba(0,168,107,.06);border:1px solid rgba(0,168,107,.15);
    margin-bottom:7px;
}
.ctlbl{font-family:var(--fh);font-size:10px;font-weight:900;color:var(--muted);letter-spacing:1px;text-transform:uppercase;}
.ctamt{font-family:var(--fh);font-size:16px;font-weight:900;color:var(--neon);}

/* ─── MODAL ─── */
.modal{
    display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);
    z-index:1000;align-items:flex-start;justify-content:center;
    padding:14px;backdrop-filter:blur(10px);overflow-y:auto;
}
.modal.show{display:flex;}
.mbox{
    background:var(--card);border:1px solid var(--bord);
    border-radius:18px;width:100%;max-width:480px;margin:auto;
    box-shadow:0 24px 60px rgba(0,0,0,.15);overflow:hidden;
}
.mhead{
    display:flex;align-items:center;justify-content:space-between;
    padding:13px 15px;border-bottom:1px solid rgba(0,0,0,.06);
    background:rgba(0,0,0,.02);
}
.mtitle{font-family:var(--fh);font-size:14px;font-weight:900;color:var(--text);letter-spacing:.5px;}
.mtitle i{color:var(--neon);}
.mclose{
    width:30px;height:30px;border-radius:50%;
    background:rgba(229,57,53,.08);border:1.5px solid rgba(229,57,53,.2);
    color:var(--red);display:flex;align-items:center;justify-content:center;
    cursor:pointer;font-size:17px;transition:all .25s;
    -webkit-tap-highlight-color:transparent;
}
.mclose:active{background:var(--red);color:#fff;}
.mbody{padding:15px;}

/* ─── ORDER TRACKING TIMELINE ─── */
.timeline{
    display:flex;align-items:center;justify-content:space-between;
    padding:14px 10px;position:relative;margin-bottom:10px;
}
.timeline::before{
    content:'';position:absolute;top:50%;left:0;right:0;height:2px;
    background:rgba(0,0,0,.07);transform:translateY(-50%);z-index:0;
}
.tl-step{
    display:flex;flex-direction:column;align-items:center;gap:4px;
    position:relative;z-index:1;flex:1;
}
.tl-dot{
    width:28px;height:28px;border-radius:50%;
    display:flex;align-items:center;justify-content:center;
    font-size:11px;border:2px solid rgba(0,0,0,.08);
    background:var(--card2);transition:all .4s;
}
.tl-dot.done{background:var(--neon);border-color:var(--neon);color:#fff;}
.tl-dot.current{
    background:linear-gradient(135deg,var(--neon),var(--cyan));
    border-color:var(--neon);color:#fff;
    box-shadow:0 0 14px rgba(0,168,107,.4);
    animation:breathe 2s infinite;
}
.tl-dot.cancelled{background:var(--red);border-color:var(--red);color:#fff;}
.tl-lbl{font-family:var(--fh);font-size:8px;font-weight:900;color:var(--muted);text-align:center;max-width:52px;}
.tl-lbl.done,.tl-lbl.current{color:var(--neon);}
.tl-lbl.cancelled{color:var(--red);}
.tl-line{
    position:absolute;top:14px;left:calc(50% + 14px);right:auto;
    height:2px;background:var(--bord);z-index:0;
    transition:background .4s;
}
.tl-line.done{background:var(--neon);}

/* ─── ORDER CARDS ─── */
.order-card{
    background:var(--card);border:1px solid var(--bord);
    border-radius:14px;overflow:hidden;margin-bottom:10px;
    animation:slideIn .3s ease backwards;transition:border-color .3s;
}
.order-card:hover{border-color:rgba(50,190,143,.3);}
.oc-header{
    padding:12px 14px;border-bottom:1px solid rgba(0,0,0,.05);
    background:rgba(0,0,0,.02);cursor:pointer;
    -webkit-tap-highlight-color:transparent;
}
.oc-top{display:flex;align-items:flex-start;justify-content:space-between;gap:8px;}
.oc-num{font-family:var(--fh);font-size:12px;font-weight:900;color:var(--gold);letter-spacing:1px;}
.oc-date{font-family:var(--fh);font-size:9px;font-weight:700;color:var(--muted);margin-top:2px;}
.oc-right{text-align:right;}
.oc-total{font-family:var(--fh);font-size:15px;font-weight:900;color:var(--neon);}
.oc-total small{font-size:9px;color:var(--muted);}
.oc-status{margin-top:4px;display:flex;align-items:center;justify-content:space-between;}
.oc-body{padding:13px;border-top:1px solid rgba(0,0,0,.05);}
.oc-section-title{
    font-family:var(--fh);font-size:9px;font-weight:900;
    color:var(--muted);text-transform:uppercase;letter-spacing:1.5px;
    margin-bottom:8px;
}
.item-row{
    display:flex;align-items:center;gap:7px;padding:7px 8px;
    border-radius:8px;border:1px solid rgba(0,0,0,.05);
    background:rgba(0,0,0,.02);margin-bottom:5px;
}
.ir-ico{font-size:16px;flex-shrink:0;}
.ir-name{font-family:var(--fh);font-size:11px;font-weight:900;color:var(--text);flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.ir-qty{font-family:var(--fh);font-size:9px;font-weight:700;color:var(--muted);flex-shrink:0;}
.ir-price{font-family:var(--fh);font-size:11px;font-weight:900;color:var(--neon);flex-shrink:0;}
.order-meta{
    display:flex;flex-wrap:wrap;gap:6px;margin-top:10px;
}
.meta-pill{
    display:flex;align-items:center;gap:4px;
    padding:4px 8px;border-radius:8px;
    background:rgba(0,0,0,.04);border:1px solid rgba(0,0,0,.07);
    font-family:var(--fh);font-size:9px;font-weight:900;color:var(--muted);
}
.meta-pill i{font-size:8px;}
.meta-pill.addr{flex:1 1 100%;}

/* ─── BADGES ─── */
.bdg{
    font-family:var(--fh);font-size:10px;font-weight:900;
    padding:3px 8px;border-radius:10px;display:inline-flex;
    align-items:center;gap:4px;
}
.bdg-n{background:rgba(0,168,107,.1);color:#006b44;}
.bdg-r{background:rgba(229,57,53,.1);color:#b71c1c;}
.bdg-g{background:rgba(249,168,37,.1);color:#e65100;}
.bdg-c{background:rgba(0,151,167,.1);color:#006064;}
.bdg-p{background:rgba(126,87,194,.1);color:#4527a0;}

/* ─── ORDER SUMMARY ─── */
.order-summary-row{
    display:flex;align-items:center;justify-content:space-between;
    padding:8px 0;border-bottom:1px solid rgba(0,0,0,.06);
}
.order-summary-row:last-child{border:none;}

/* ─── FILTER TABS ─── */
.filter-tabs{
    display:flex;gap:5px;overflow-x:auto;padding-bottom:8px;
    -webkit-overflow-scrolling:touch;margin-bottom:10px;
}
.filter-tabs::-webkit-scrollbar{display:none;}
.ftab{
    padding:5px 12px;border-radius:20px;border:1.5px solid var(--bord);
    background:none;cursor:pointer;font-family:var(--fh);font-size:10px;
    font-weight:900;color:var(--muted);letter-spacing:.5px;
    white-space:nowrap;flex-shrink:0;transition:all .25s;
    -webkit-tap-highlight-color:transparent;
}
.ftab.on{background:rgba(0,168,107,.08);border-color:rgba(0,168,107,.28);color:var(--neon);}

/* ─── STATS BAR ─── */
.stats-bar{
    display:grid;grid-template-columns:repeat(3,1fr);gap:8px;
    margin-bottom:12px;
}
.stat-card{
    background:var(--card);border:1px solid var(--bord);border-radius:12px;
    padding:10px 8px;text-align:center;
}
.stat-val{font-family:var(--fh);font-size:18px;font-weight:900;color:var(--neon);}
.stat-lbl{font-family:var(--fh);font-size:8px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-top:2px;}

/* ─── NOTIFICATIONS PANEL ─── */
.notif-panel{
    position:fixed;top:0;right:0;bottom:0;
    width:min(340px,100vw);background:var(--surf);
    border-left:1px solid var(--bord);z-index:900;
    transform:translateX(100%);transition:transform .35s cubic-bezier(.23,1,.32,1);
    display:flex;flex-direction:column;overflow:hidden;
}
.notif-panel.open{transform:translateX(0);}
.notif-overlay{
    position:fixed;inset:0;background:rgba(0,0,0,.35);z-index:899;
    opacity:0;pointer-events:none;transition:opacity .35s;backdrop-filter:blur(4px);
}
.notif-overlay.show{opacity:1;pointer-events:all;}
.notif-header{
    padding:14px;border-bottom:1px solid var(--bord);
    background:rgba(0,0,0,.02);flex-shrink:0;
    display:flex;align-items:center;justify-content:space-between;
}
.notif-title{font-family:var(--fh);font-size:15px;font-weight:900;color:var(--text);}
.notif-title i{color:var(--gold);margin-right:6px;}
.notif-actions{display:flex;align-items:center;gap:8px;}
.mark-read-btn{
    font-family:var(--fh);font-size:9px;font-weight:900;
    color:var(--muted);background:none;border:1px solid var(--bord);
    padding:4px 10px;border-radius:8px;cursor:pointer;
    -webkit-tap-highlight-color:transparent;
}
.mark-read-btn:active{color:var(--neon);border-color:var(--neon);}
.notif-close{
    width:30px;height:30px;border-radius:50%;
    background:rgba(229,57,53,.08);border:1.5px solid rgba(229,57,53,.2);
    color:var(--red);display:flex;align-items:center;justify-content:center;
    cursor:pointer;font-size:16px;
    -webkit-tap-highlight-color:transparent;
}
.notif-close:active{background:var(--red);color:#fff;}
.notif-list{flex:1;overflow-y:auto;padding:10px;}
.notif-item{
    display:flex;align-items:flex-start;gap:10px;
    padding:10px;border-radius:12px;
    background:rgba(0,0,0,.02);border:1px solid var(--bord);
    margin-bottom:8px;cursor:pointer;transition:all .25s;
    animation:slideDown .3s ease backwards;
    -webkit-tap-highlight-color:transparent;
}
.notif-item.unread{
    background:rgba(0,168,107,.04);border-color:rgba(0,168,107,.18);
}
.notif-item:active{transform:scale(.98);}
.notif-ico{
    width:34px;height:34px;border-radius:10px;flex-shrink:0;
    display:flex;align-items:center;justify-content:center;font-size:16px;
}
.notif-ico.order{background:rgba(0,168,107,.12);}
.notif-ico.status{background:rgba(25,118,210,.12);}
.notif-ico.info{background:rgba(126,87,194,.12);}
.notif-ico.promo{background:rgba(249,168,37,.12);}
.notif-content{flex:1;min-width:0;}
.notif-item-title{
    font-family:var(--fh);font-size:12px;font-weight:900;color:var(--text);
    margin-bottom:3px;
}
.notif-item.unread .notif-item-title{color:var(--neon);}
.notif-item-msg{
    font-family:var(--fh);font-size:10px;font-weight:700;color:var(--muted);
    line-height:1.5;
}
.notif-item-time{
    font-family:var(--fh);font-size:8px;font-weight:700;color:var(--muted);
    margin-top:4px;
}
.notif-dot{
    width:7px;height:7px;border-radius:50%;background:var(--neon);
    flex-shrink:0;margin-top:4px;
}
.notif-dot.hidden{visibility:hidden;}
.notif-empty{
    text-align:center;padding:40px 20px;
}
.notif-empty i{font-size:42px;display:block;margin-bottom:12px;opacity:.07;}
.notif-empty p{font-family:var(--fh);font-size:12px;font-weight:900;color:var(--muted);}

/* ─── SPINNER ─── */
.sp{
    width:14px;height:14px;border:2px solid rgba(0,0,0,.1);
    border-top-color:currentColor;border-radius:50%;
    animation:spin .7s linear infinite;display:inline-block;
}

/* ─── TOAST ─── */
.tstack{position:fixed;bottom:18px;left:12px;right:12px;z-index:9999;display:flex;flex-direction:column;gap:6px;pointer-events:none;}
.toast{
    background:var(--card2);border:1px solid rgba(0,168,107,.18);
    border-radius:12px;padding:9px 13px;display:flex;
    align-items:center;gap:9px;box-shadow:0 8px 28px rgba(0,0,0,.1);
    animation:fadeUp .4s ease;pointer-events:none;position:relative;overflow:hidden;
}
.toast::after{
    content:'';position:absolute;bottom:0;left:0;height:2px;
    background:var(--neon);animation:progress 3.5s linear forwards;
}
.toast.err{border-color:rgba(229,57,53,.25);}
.toast.err::after{background:var(--red);}
.toast.warn{border-color:rgba(249,168,37,.25);}
.toast.warn::after{background:var(--gold);}
.tico{
    width:26px;height:26px;border-radius:7px;
    display:flex;align-items:center;justify-content:center;
    font-size:12px;flex-shrink:0;
}
.ttxt strong{font-family:var(--fh);font-size:11px;font-weight:900;display:block;}
.ttxt span{font-family:var(--fh);font-size:9px;font-weight:700;color:var(--muted);}

/* ─── EMPTY ─── */
.empty{text-align:center;padding:40px 20px;color:var(--muted);}
.empty i{font-size:48px;display:block;margin-bottom:12px;opacity:.07;}
.empty h3{font-family:var(--fh);font-size:16px;font-weight:900;color:var(--text2);margin-bottom:6px;}
.empty p{font-family:var(--fh);font-size:12px;font-weight:700;}

/* ─── SUCCESS OVERLAY ─── */
.success-overlay{
    display:none;position:fixed;inset:0;z-index:9000;
    background:rgba(244,247,251,.88);flex-direction:column;
    align-items:center;justify-content:center;padding:0;
    backdrop-filter:blur(26px);
}
.success-overlay.show{display:flex;}
.success-box{
    position:relative;width:100%;height:100%;overflow:hidden;
    background:
        radial-gradient(circle at top left, rgba(25,118,210,.1), transparent 30%),
        radial-gradient(circle at top right, rgba(0,168,107,.08), transparent 28%),
        linear-gradient(180deg, rgba(248,251,255,.99), rgba(244,247,251,.99));
    animation:fadeUp .45s ease;
}
.success-shell{
    position:relative;display:flex;flex-direction:column;gap:14px;
    width:min(100%,760px);height:100%;
    margin:0 auto;padding:20px 16px 24px;
}
.success-head{
    display:flex;align-items:flex-start;justify-content:space-between;gap:12px;
}
.success-status{
    display:inline-flex;align-items:center;gap:8px;padding:9px 14px;border-radius:999px;
    background:rgba(0,168,107,.08);border:1px solid rgba(0,168,107,.22);
    color:var(--neon);font-size:12px;font-weight:900;box-shadow:var(--glow);
}
.success-order-meta{
    display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end;
}
.smeta-pill{
    display:inline-flex;align-items:center;gap:6px;padding:8px 11px;border-radius:999px;
    background:rgba(0,0,0,.04);border:1px solid rgba(0,0,0,.07);
    font-size:10px;color:var(--text2);font-weight:900;
}
.snum{
    display:inline-flex;align-items:center;gap:8px;padding:9px 14px;border-radius:12px;
    background:rgba(0,168,107,.08);border:1.5px solid rgba(0,168,107,.22);
    font-family:var(--fh);font-size:14px;font-weight:900;color:var(--neon);
    letter-spacing:1px;
}
.success-stage{
    position:relative;background:rgba(255,255,255,.85);
    border:1px solid rgba(0,0,0,.08);border-radius:28px;
    padding:22px 16px 18px;box-shadow:0 8px 30px rgba(0,0,0,.09);
    overflow:hidden;
}
.success-stage::before{
    content:'';position:absolute;inset:auto -10% -60% -10%;height:220px;
    background:radial-gradient(circle, rgba(25,118,210,.08), transparent 60%);
    pointer-events:none;
}
.loader-block{
    display:flex;align-items:center;justify-content:center;flex-direction:column;
    gap:12px;min-height:120px;transition:opacity .35s ease,transform .35s ease;
}
.loader-block.hidden{
    opacity:0;transform:translateY(-10px) scale(.98);pointer-events:none;height:0;min-height:0;overflow:hidden;
}
.loader-ring{
    width:70px;height:70px;border-radius:50%;
    border:4px solid rgba(25,118,210,.1);border-top-color:var(--cyan);border-right-color:var(--neon);
    animation:spin 1s linear infinite;box-shadow:0 0 20px rgba(25,118,210,.1);
}
.loader-text{font-size:14px;color:var(--text);font-weight:900;text-align:center;}
.loader-sub{font-size:11px;color:var(--muted);text-align:center;}
.success-main{
    display:none;flex-direction:column;gap:14px;animation:fadeUp .45s ease;
}
.success-main.show{display:flex;}
.check-hero{
    display:flex;align-items:center;gap:12px;flex-wrap:wrap;justify-content:center;text-align:center;
}
.check-circle{
    position:relative;width:88px;height:88px;border-radius:50%;
    background:radial-gradient(circle at 30% 30%, rgba(255,255,255,.6), rgba(0,168,107,.12));
    border:2px solid rgba(0,168,107,.28);display:flex;align-items:center;justify-content:center;
    box-shadow:0 0 28px rgba(0,168,107,.2);animation:checkPop .75s cubic-bezier(.2,.9,.25,1.1) forwards;
}
.check-circle::after{
    content:'';position:absolute;inset:-8px;border-radius:50%;border:1px solid rgba(50,190,143,.16);
    animation:pulseHalo 1.8s ease infinite;border:1px solid rgba(0,168,107,.14);
}
.check-mark{
    font-size:42px;color:var(--neon);transform:scale(.2);opacity:0;animation:tickIn .45s ease .28s forwards;
}
.success-copy h2{
    font-family:var(--fh);font-size:24px;line-height:1.15;color:var(--text);margin-bottom:6px;
}
.success-copy p{
    font-size:13px;font-weight:800;color:var(--text2);line-height:1.75;max-width:540px;
}
.vehicle-zone{
    position:relative;height:140px;border-radius:24px;overflow:hidden;
    background:
        linear-gradient(180deg, rgba(25,118,210,.05), rgba(244,247,251,.08)),
        linear-gradient(0deg, rgba(0,0,0,.02), rgba(0,0,0,.02));
    border:1px solid rgba(0,0,0,.08);
}
.vehicle-road{
    position:absolute;left:8%;right:8%;bottom:32px;height:10px;border-radius:999px;
    background:linear-gradient(90deg, rgba(0,0,0,.05), rgba(0,0,0,.12), rgba(0,0,0,.05));
}
.vehicle-road::after{
    content:'';position:absolute;left:0;right:0;top:50%;height:2px;transform:translateY(-50%);
    background:repeating-linear-gradient(90deg, rgba(0,0,0,.3) 0 24px, transparent 24px 44px);
    opacity:.25;
}
.truck-wrap{
    position:absolute;left:-34%;bottom:38px;width:190px;
    animation:truckDrive 5.4s cubic-bezier(.2,.8,.15,1) forwards;
}
.truck{
    position:relative;display:flex;align-items:flex-end;gap:0;
    filter:drop-shadow(0 10px 10px rgba(0,0,0,.15)) drop-shadow(0 0 10px rgba(0,168,107,.15));
}
.truck-body{
    width:118px;height:44px;border-radius:16px 10px 12px 12px;
    background:linear-gradient(135deg, #00a86b, #1976d2);position:relative;
    box-shadow:0 0 14px rgba(25,118,210,.15);
}
.truck-body::before{
    content:'';position:absolute;left:12px;right:18px;top:10px;height:10px;border-radius:10px;
    background:rgba(255,255,255,.3);
}
.truck-cabin{
    width:48px;height:34px;border-radius:12px 12px 10px 4px;
    background:linear-gradient(135deg, #64d8f0, #4da1ff);margin-left:-6px;position:relative;
}
.truck-cabin::before{
    content:'';position:absolute;left:10px;right:8px;top:6px;height:12px;border-radius:7px;
    background:rgba(244,247,251,.55);
}
.truck-wheel,.bike-wheel{
    position:absolute;bottom:-10px;width:22px;height:22px;border-radius:50%;
    background:#dde8f0;border:4px solid #0097a7;animation:spin .55s linear infinite;
}
.truck-wheel::after,.bike-wheel::after{
    content:'';position:absolute;inset:4px;border-radius:50%;background:#64d8f0;
}
.truck-wheel.w1{left:24px;}
.truck-wheel.w2{left:126px;}
.vehicle-shadow{
    position:absolute;left:20px;right:14px;bottom:-18px;height:18px;border-radius:50%;
    background:radial-gradient(circle, rgba(0,0,0,.18), transparent 70%);
    filter:blur(4px);
}
.prep-line{
    display:flex;align-items:center;justify-content:center;gap:10px;font-size:14px;color:var(--text);font-weight:900;
}
.prep-line i,.eta-badge,.notify-bubble i,.thanks-pill i,.map-pin i,.success-status i{animation:softBounce 2s ease infinite;}
.bike{
    position:absolute;left:-16%;bottom:76px;font-size:32px;filter:drop-shadow(0 0 8px rgba(25,118,210,.2));
    animation:bikeRush 2.9s ease-in-out .6s 1 forwards;
}
.timeline-pro{
    display:grid;grid-template-columns:repeat(4,1fr);gap:8px;
}
.tl-pro-step{
    position:relative;padding:12px 8px 10px;border-radius:18px;
    background:rgba(0,0,0,.02);border:1px solid rgba(0,0,0,.07);
    text-align:center;overflow:hidden;
}
.tl-pro-step::after{
    content:'';position:absolute;left:0;bottom:0;height:3px;width:0;
    background:linear-gradient(90deg, var(--cyan), var(--neon));transition:width .65s ease;
}
.tl-pro-step.done::after,.tl-pro-step.current::after{width:100%;}
.tl-pro-step.done{border-color:rgba(0,168,107,.2);background:rgba(0,168,107,.04);}
.tl-pro-step.current{border-color:rgba(25,118,210,.2);background:rgba(25,118,210,.05);box-shadow:0 0 14px rgba(25,118,210,.08);}
.tl-pro-dot{
    width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;
    margin:0 auto 8px;background:rgba(0,0,0,.05);color:var(--muted);font-size:15px;
}
.tl-pro-step.done .tl-pro-dot{background:rgba(0,168,107,.12);color:var(--neon);}
.tl-pro-step.current .tl-pro-dot{background:rgba(25,118,210,.12);color:var(--cyan);animation:pulse 1.5s ease infinite;}
.tl-pro-title{font-size:10px;color:var(--text);font-weight:900;}
.tl-pro-sub{font-size:9px;color:var(--muted);margin-top:4px;font-weight:800;}
.grid-premium{
    display:grid;grid-template-columns:1.18fr .82fr;gap:12px;
}
.map-card,.eta-card,.notify-card,.thanks-card{
    background:rgba(0,0,0,.02);border:1px solid rgba(0,0,0,.07);border-radius:22px;padding:14px;
}
.map-card h3,.eta-card h3,.notify-card h3,.thanks-card h3{
    font-size:12px;color:var(--text);margin-bottom:10px;
}
.fake-map{
    position:relative;height:190px;border-radius:18px;overflow:hidden;
    background:
        radial-gradient(circle at 18% 22%, rgba(25,118,210,.1), transparent 22%),
        radial-gradient(circle at 78% 68%, rgba(0,168,107,.08), transparent 24%),
        linear-gradient(135deg, rgba(235,244,255,.95), rgba(240,252,246,.95));
    border:1px solid rgba(0,0,0,.08);
}
.fake-map::before,.fake-map::after{
    content:'';position:absolute;inset:0;pointer-events:none;
}
.fake-map::before{
    background:
        linear-gradient(90deg, transparent 8%, rgba(0,0,0,.04) 8% 10%, transparent 10% 18%, rgba(0,0,0,.03) 18% 20%, transparent 20% 100%),
        linear-gradient(transparent 18%, rgba(0,0,0,.03) 18% 20%, transparent 20% 48%, rgba(0,0,0,.03) 48% 50%, transparent 50% 100%);
    opacity:.9;
}
.fake-map::after{
    background:
        radial-gradient(circle at 24% 28%, rgba(0,168,107,.12), transparent 10%),
        radial-gradient(circle at 76% 72%, rgba(25,118,210,.12), transparent 12%);
}
.map-pin{
    position:absolute;display:flex;align-items:center;gap:6px;padding:7px 9px;border-radius:999px;
    font-size:10px;font-weight:900;border:1px solid rgba(0,0,0,.1);background:rgba(255,255,255,.85);backdrop-filter:blur(8px);
}
.map-pin.store{left:18%;top:22%;color:var(--neon);}
.map-pin.city{left:58%;top:62%;color:var(--cyan);}
.map-pin.home{right:12%;bottom:18%;color:var(--gold);}
.route-dot{
    position:absolute;width:16px;height:16px;border-radius:50%;
    background:radial-gradient(circle, #fff 0 25%, #00a86b 28% 100%);
    box-shadow:0 0 0 6px rgba(0,168,107,.1),0 0 16px rgba(0,168,107,.35);
    animation:routeMove 5.8s ease-in-out infinite;
}
.eta-live{
    display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;
}
.eta-time{
    font-size:28px;color:var(--text);line-height:1.05;
}
.eta-time small{display:block;font-size:10px;letter-spacing:1.3px;color:var(--muted);text-transform:uppercase;}
.eta-range{color:var(--gold);}
.eta-badge{
    display:inline-flex;align-items:center;gap:8px;padding:9px 12px;border-radius:999px;
    background:rgba(25,118,210,.08);border:1px solid rgba(25,118,210,.2);color:#1565c0;
    font-size:11px;font-weight:900;animation:pulse 1.8s ease infinite;
}
.eta-progress{
    position:relative;height:10px;border-radius:999px;background:rgba(0,0,0,.07);overflow:hidden;margin-top:12px;
}
.eta-progress-bar{
    position:absolute;inset:0 auto 0 0;width:42%;
    background:linear-gradient(90deg, var(--cyan), var(--neon), #80ffe8);
    border-radius:999px;animation:etaBar 8s ease-in-out infinite;
}
.notify-list{display:flex;flex-direction:column;gap:9px;}
.notify-bubble{
    display:flex;align-items:center;gap:10px;padding:11px 12px;border-radius:18px;
    background:rgba(0,0,0,.03);border:1px solid rgba(0,0,0,.07);font-size:11px;color:var(--text2);font-weight:800;
}
.notify-bubble strong{display:block;color:var(--text);font-size:11px;}
.notify-bubble small{display:block;color:var(--muted);font-size:9px;margin-top:2px;}
.thanks-pill{
    display:flex;align-items:flex-start;gap:12px;padding:14px;border-radius:20px;
    background:linear-gradient(135deg, rgba(0,168,107,.05), rgba(25,118,210,.05));
    border:1px solid rgba(0,168,107,.15);
}
.thanks-pill h3{margin:0 0 5px 0;}
.thanks-pill p{font-size:11px;color:var(--text2);line-height:1.65;font-weight:800;}
.success-actions{
    display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:auto;
}
.success-btn{
    min-height:54px;border-radius:18px;padding:12px 14px;
}
.success-btn.ok{
    background:linear-gradient(135deg, var(--neon), var(--cyan));color:#fff;box-shadow:var(--glow);
}
.success-close-hint{
    text-align:center;font-size:10px;color:var(--muted);font-weight:900;
}
.confetti-layer{
    position:absolute;inset:0;overflow:hidden;pointer-events:none;
}
.confetti-piece{
    position:absolute;top:-18px;width:10px;height:18px;border-radius:4px;opacity:0;
    animation:confettiFall 3s ease-out forwards;
}
.audio-dot{
    width:9px;height:9px;border-radius:50%;background:var(--neon);box-shadow:0 0 12px var(--neon);
    animation:pulse 1.4s ease infinite;
}
@keyframes checkPop{
    0%{transform:scale(.55);opacity:0}
    60%{transform:scale(1.06);opacity:1}
    100%{transform:scale(1)}
}
@keyframes tickIn{
    0%{opacity:0;transform:scale(.2) rotate(-20deg)}
    100%{opacity:1;transform:scale(1) rotate(0)}
}
@keyframes pulseHalo{
    0%{transform:scale(.94);opacity:.4}
    70%{transform:scale(1.14);opacity:0}
    100%{transform:scale(1.18);opacity:0}
}
@keyframes truckDrive{
    0%{transform:translateX(0) translateY(0)}
    58%{transform:translateX(104vw) translateY(0)}
    70%{transform:translateX(112vw) translateY(0)}
    82%{transform:translateX(108vw) translateY(-3px)}
    90%{transform:translateX(108vw) translateY(0)}
    100%{transform:translateX(108vw) translateY(0)}
}
@keyframes bikeRush{
    0%{transform:translateX(0) translateY(0) scale(.8);opacity:0}
    10%{opacity:1}
    90%{opacity:1}
    100%{transform:translateX(128vw) translateY(-6px) scale(1);opacity:0}
}
@keyframes softBounce{
    0%,100%{transform:translateY(0)}
    50%{transform:translateY(-4px)}
}
@keyframes routeMove{
    0%{left:24%;top:28%}
    30%{left:40%;top:42%}
    58%{left:57%;top:50%}
    100%{left:78%;top:72%}
}
@keyframes etaBar{
    0%,100%{width:28%}
    50%{width:84%}
}
@keyframes confettiFall{
    0%{transform:translate3d(0,0,0) rotate(0deg);opacity:1}
    100%{transform:translate3d(var(--tx),110vh,0) rotate(760deg);opacity:0}
}

/* ─── CONFIRM DIALOG ─── */
.confirm-modal{
    display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);
    z-index:2000;align-items:center;justify-content:center;
    padding:20px;backdrop-filter:blur(12px);
}
.confirm-modal.show{display:flex;}
.confirm-box{
    background:var(--card);border:1px solid rgba(229,57,53,.2);
    border-radius:18px;width:100%;max-width:320px;
    padding:24px;text-align:center;
    box-shadow:0 24px 60px rgba(0,0,0,.15);
}
.confirm-ico{font-size:46px;display:block;margin-bottom:12px;}
.confirm-title{font-family:var(--fh);font-size:16px;font-weight:900;color:var(--text);margin-bottom:8px;}
.confirm-sub{font-family:var(--fh);font-size:11px;font-weight:700;color:var(--muted);margin-bottom:18px;line-height:1.6;}
.confirm-btns{display:flex;gap:10px;}

/* ─── SKELETON ─── */
.skel{
    background:linear-gradient(90deg,rgba(0,0,0,.05) 25%,rgba(0,0,0,.09) 50%,rgba(0,0,0,.05) 75%);
    background-size:200% 100%;
    animation:shimmer 1.5s infinite;
    border-radius:8px;height:14px;
}

/* ─── PROMOTIONS ─── */
.promo-hero{margin:2px 0 14px;padding:16px;border-radius:18px;border:1px solid rgba(245,124,0,.22);background:linear-gradient(135deg,rgba(229,57,53,.08),rgba(245,124,0,.06));box-shadow:0 4px 12px rgba(0,0,0,.07);}
.promo-hero-top{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;}
.promo-hero-kicker{font-size:10px;letter-spacing:1.8px;text-transform:uppercase;color:#7a3500;font-family:var(--fh);}
.promo-hero h3{font-size:19px;line-height:1.15;color:#3a1a00;margin:2px 0 4px}
.promo-hero p{font-size:11px;color:#7a3500}
.promo-hero-stat{padding:10px 12px;border-radius:14px;border:1px solid rgba(0,0,0,.1);background:rgba(255,255,255,.55);text-align:right;min-width:112px}
.promo-hero-stat strong{display:block;font-size:22px;color:var(--text)}
.promo-hero-stat span{font-size:10px;color:#7a3500}
.promo-toolbar{display:flex;flex-direction:column;gap:10px;margin-bottom:14px}
.promo-filters,.promo-sorts{display:flex;gap:8px;overflow:auto;padding-bottom:2px;scrollbar-width:none}
.promo-filters::-webkit-scrollbar,.promo-sorts::-webkit-scrollbar{display:none}
.promo-chip{white-space:nowrap;border-radius:999px;padding:9px 13px;border:1px solid rgba(0,0,0,.09);background:rgba(0,0,0,.03);color:var(--text2);font-family:var(--fh);font-size:10px;font-weight:900;letter-spacing:.5px}
.promo-chip.on{background:rgba(245,124,0,.1);border-color:rgba(245,124,0,.3);color:#9a4000}
.promo-grid{display:grid;gap:12px}
.promo-card{border-radius:18px;border:1px solid rgba(245,124,0,.15);background:linear-gradient(180deg,rgba(255,255,255,.98),rgba(250,252,255,.98));box-shadow:0 4px 14px rgba(0,0,0,.07);overflow:hidden}
.promo-card-head{display:flex;gap:12px;padding:14px 14px 0}
.promo-card-media{width:78px;height:78px;border-radius:16px;overflow:hidden;background:linear-gradient(135deg,rgba(245,124,0,.1),rgba(249,168,37,.07));display:flex;align-items:center;justify-content:center;font-size:30px;flex-shrink:0}
.promo-card-media img{width:100%;height:100%;object-fit:cover}
.promo-badges{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px}
.promo-badge{border-radius:999px;padding:4px 9px;font-size:9px;font-weight:900;letter-spacing:.5px;text-transform:uppercase}
.promo-badge.main{background:rgba(229,57,53,.1);color:#c62828;border:1px solid rgba(229,57,53,.25)}
.promo-badge.discount{background:rgba(245,124,0,.1);color:#e65100;border:1px solid rgba(245,124,0,.25)}
.promo-badge.flash{background:rgba(249,168,37,.1);color:#f57f17;border:1px solid rgba(249,168,37,.25)}
.promo-badge.pack{background:rgba(25,118,210,.1);color:#1565c0;border:1px solid rgba(25,118,210,.25)}
.promo-card-title{font-size:15px;line-height:1.25}
.promo-card-sub{font-size:11px;color:var(--muted);margin-top:4px}
.promo-card-body{padding:12px 14px 14px}
.promo-price-old{text-decoration:line-through;color:#8a9fad;font-size:12px}
.promo-price-new{color:var(--neon);font-size:22px;line-height:1.1}
.promo-meta-line{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:10px;font-size:11px}
.promo-stock{color:#a34500}
.promo-timer{color:var(--gold);font-weight:900}
.promo-pack-list{margin:10px 0 0;padding:0;list-style:none;display:flex;flex-direction:column;gap:6px}
.promo-pack-list li{display:flex;justify-content:space-between;gap:10px;font-size:11px;color:var(--text2);padding:7px 9px;border-radius:10px;background:rgba(0,0,0,.03)}
.promo-actions{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:14px}
.promo-qty{display:flex;align-items:center;gap:8px}
.promo-disabled{opacity:.55;filter:grayscale(.08)}
.promo-empty{padding:28px 14px;text-align:center;border:1px dashed rgba(245,124,0,.18);border-radius:18px;color:var(--muted)}
.shop-tools{display:grid;gap:10px;margin:10px 0 14px}
.shop-search{width:100%;padding:13px 15px;border-radius:14px;border:1px solid rgba(0,0,0,.09);background:rgba(0,0,0,.03);color:var(--text);font-family:var(--fh);font-size:12px;font-weight:900}
.shop-row{display:flex;gap:8px;overflow:auto;scrollbar-width:none}
.shop-row::-webkit-scrollbar{display:none}
.shop-pill{white-space:nowrap;border-radius:999px;padding:9px 13px;border:1px solid rgba(0,0,0,.09);background:rgba(0,0,0,.03);color:var(--text2);font-family:var(--fh);font-size:10px;font-weight:900}
.shop-pill.on{background:rgba(0,168,107,.08);border-color:rgba(0,168,107,.28);color:var(--neon)}
.recommend-strip{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin:0 0 14px}
.recommend-card{border-radius:16px;border:1px solid rgba(0,168,107,.1);background:linear-gradient(180deg,rgba(255,255,255,.97),rgba(248,252,250,.97));padding:12px}
.recommend-top{display:flex;gap:10px;align-items:center}
.recommend-media{width:54px;height:54px;border-radius:12px;overflow:hidden;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.05);font-size:24px;flex-shrink:0}
.recommend-media img{width:100%;height:100%;object-fit:cover}
.recommend-name{font-size:11px;line-height:1.35}
.recommend-meta{font-size:9px;color:var(--muted);margin-top:2px}
.rating-row{display:flex;align-items:center;gap:6px;margin:6px 0 8px}
.stars{letter-spacing:1px;color:var(--gold);font-size:10px}
.rating-txt{font-size:9px;color:var(--muted)}
.fav-btn{position:absolute;top:8px;right:8px;width:34px;height:34px;border-radius:50%;border:1px solid rgba(0,0,0,.1);background:rgba(255,255,255,.85);color:var(--text);display:flex;align-items:center;justify-content:center;z-index:2}
.fav-btn.on{color:#e53935;border-color:rgba(229,57,53,.25);background:rgba(229,57,53,.08)}
.pimg{position:relative}
.pcard.hidden-by-filter{display:none}
.product-actions{display:flex;gap:7px;margin-top:10px}
.ghost-btn{flex:1;border-radius:12px;border:1px solid rgba(0,0,0,.09);background:rgba(0,0,0,.03);color:var(--text2);font-family:var(--fh);font-size:10px;font-weight:900;padding:10px 8px}
.ghost-btn:hover{border-color:rgba(0,168,107,.22);color:var(--neon)}
.review-card{padding:10px;border-radius:12px;background:rgba(0,0,0,.02);border:1px solid rgba(0,0,0,.06);margin-bottom:8px}
.review-head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:4px}
.review-name{font-size:11px;color:var(--text);font-weight:900}
.review-date{font-size:9px;color:var(--muted)}
.review-text{font-size:11px;color:var(--text2);line-height:1.6}
.review-form{display:grid;gap:10px;margin-top:12px}
.review-textarea{width:100%;min-height:88px;padding:12px;border-radius:12px;border:1px solid rgba(0,0,0,.1);background:rgba(0,0,0,.02);color:var(--text);font-family:var(--fh);font-size:11px}
.review-summary{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 12px;border-radius:12px;background:rgba(249,168,37,.07);border:1px solid rgba(249,168,37,.15);margin-bottom:12px}
.review-empty{padding:14px;text-align:center;color:var(--muted);font-size:11px}
.loyalty-card{margin:0 0 14px;padding:15px 16px;border-radius:18px;border:1px solid rgba(249,168,37,.18);background:linear-gradient(135deg,rgba(249,168,37,.08),rgba(25,118,210,.05))}
.loyalty-top{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
.loyalty-kicker{font-size:10px;letter-spacing:1.6px;text-transform:uppercase;color:#8a6200}
.loyalty-title{font-size:18px;color:var(--text);line-height:1.1}
.loyalty-meta{font-size:11px;color:var(--text2);margin-top:4px}
.loyalty-badge{padding:8px 12px;border-radius:999px;border:1px solid rgba(0,0,0,.1);background:rgba(255,255,255,.6);font-size:10px;font-weight:900}
.loyalty-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:12px}
.loyalty-stat{padding:10px;border-radius:14px;background:rgba(0,0,0,.03);border:1px solid rgba(0,0,0,.07)}
.loyalty-stat strong{display:block;font-size:18px;color:var(--text)}
.loyalty-stat span{font-size:10px;color:var(--muted)}
.offer-stack{display:grid;gap:8px;margin:0 0 14px}
.offer-card{padding:12px 14px;border-radius:16px;background:rgba(0,0,0,.02);border:1px solid rgba(25,118,210,.1)}
.offer-title{font-size:12px;color:var(--text)}
.offer-sub{font-size:10px;color:var(--text2);margin-top:3px}
.offer-alert{margin:0 0 14px;padding:12px 14px;border-radius:16px;background:rgba(245,124,0,.06);border:1px solid rgba(245,124,0,.18);font-size:11px;color:#a34500}

/* ─── RESPONSIVE ─── */
@media(min-width:480px){.pgrid{grid-template-columns:repeat(3,1fr);}}
@media(min-width:768px){
    .wrap{max-width:720px;}
    .pgrid{grid-template-columns:repeat(4,1fr);}
    .cfloat{left:auto;right:20px;width:360px;bottom:20px;}
    .cfloat.mini{width:60px;height:60px;}
}
@media(max-width:767px){
    .success-shell{padding:16px 12px 20px;}
    .success-head,.eta-live{flex-direction:column;align-items:stretch;}
    .success-order-meta{justify-content:flex-start;}
    .grid-premium,.success-actions{grid-template-columns:1fr;}
    .timeline-pro{grid-template-columns:repeat(2,1fr);}
    .vehicle-zone{height:128px;}
    .truck-wrap{transform:scale(.84);transform-origin:left bottom;}
    .bike{bottom:78px;font-size:28px;}
    .success-copy h2{font-size:21px;}
    .success-btn{width:100%;}
}
@media(max-width:420px){
    .timeline-pro{grid-template-columns:1fr 1fr;}
    .success-status,.smeta-pill,.snum{font-size:10px;}
    .success-copy p,.thanks-pill p,.notify-bubble{font-size:10px;}
    .eta-time{font-size:24px;}
}

/* ══════════════════════════════════════════════════════
   FIGMA-STYLE MICRO-INTERACTIONS — ESPERANCE H2O 💧
══════════════════════════════════════════════════════ */

/* ─── Product card — lift 3D + shadow bloom ─── */
.pcard{
    transition:transform .32s cubic-bezier(.34,1.56,.64,1),
               box-shadow .32s ease;
    will-change:transform;
}
.pcard:hover{
    transform:translateY(-7px) scale(1.02);
    box-shadow:0 18px 38px rgba(0,168,107,.14), 0 4px 14px rgba(0,0,0,.07);
    z-index:2;
}
.pcard:active{transform:scale(.97);transition:transform .08s ease;}

/* ─── Liquid wave sur l'image au hover ─── */
.pimg{overflow:hidden;}
.pimg::after{
    content:'';position:absolute;bottom:-10px;left:-8%;width:116%;height:28px;
    background:linear-gradient(90deg,rgba(0,168,107,.16),rgba(0,151,167,.12),rgba(0,168,107,.16));
    border-radius:50%;transform:scaleX(0);
    transition:transform .42s cubic-bezier(.34,1.56,.64,1);
    pointer-events:none;
}
.pcard:hover .pimg::after{transform:scaleX(1);}

/* ─── Ripple effect universel ─── */
.btn,.badd,.ghost-btn,.tab,.ftab,.shop-pill,.promo-chip{
    position:relative;overflow:hidden;
}
.ripple-wave{
    position:absolute;border-radius:50%;
    background:rgba(255,255,255,.5);
    width:60px;height:60px;margin-left:-30px;margin-top:-30px;
    transform:scale(0);animation:rippleOut .55s linear;
    pointer-events:none;
}
@keyframes rippleOut{
    to{transform:scale(8);opacity:0}
}

/* ─── Add to cart button feedback ─── */
.badd{transition:all .28s cubic-bezier(.34,1.56,.64,1);}
.badd.is-adding{transform:scale(.95);opacity:.8;}
.badd.is-added{
    background:linear-gradient(135deg,#00c87a,#009958)!important;
    transform:scale(1.04);
}

/* ─── Flying cart item ─── */
.cart-fly-dot{
    position:fixed;width:44px;height:44px;border-radius:50%;
    background:linear-gradient(135deg,var(--neon),var(--cyan));
    display:flex;align-items:center;justify-content:center;
    font-size:20px;z-index:9999;pointer-events:none;
    box-shadow:0 6px 22px rgba(0,168,107,.38);
}

/* ─── Cart icon spring bounce ─── */
@keyframes cartSpring{
    0%  {transform:scale(1) rotate(0)}
    20% {transform:scale(1.4) rotate(-14deg)}
    45% {transform:scale(.88) rotate(8deg)}
    65% {transform:scale(1.15) rotate(-4deg)}
    82% {transform:scale(.97) rotate(1deg)}
    100%{transform:scale(1) rotate(0)}
}
.cart-bounce{animation:cartSpring .55s cubic-bezier(.36,.07,.19,.97) forwards;}

/* ─── Badge count pop ─── */
@keyframes badgePop{
    0%  {transform:scale(0) rotate(-25deg);opacity:0}
    55% {transform:scale(1.3) rotate(6deg)}
    100%{transform:scale(1) rotate(0);opacity:1}
}
.ccnt.badge-pop{animation:badgePop .4s cubic-bezier(.34,1.56,.64,1) forwards;}

/* ─── Stagger reveal produits ─── */
@keyframes cardReveal{
    from{opacity:0;transform:translateY(20px) scale(.96)}
    to  {opacity:1;transform:translateY(0)   scale(1)}
}
.pcard.f-reveal{animation:cardReveal .42s cubic-bezier(.34,1.4,.64,1) both;}

/* ─── Scroll reveal général ─── */
.s-hidden{
    opacity:0;transform:translateY(22px);
    transition:opacity .44s ease, transform .44s cubic-bezier(.34,1.28,.64,1);
}
.s-visible{opacity:1;transform:translateY(0);}

/* ─── Tab slide ─── */
@keyframes panelFadeSlide{
    from{opacity:0;transform:translateX(14px)}
    to  {opacity:1;transform:translateX(0)}
}
.panel.show{animation:panelFadeSlide .3s cubic-bezier(.23,1,.32,1);}

/* ─── Toast spring ─── */
@keyframes toastSpring{
    0%  {opacity:0;transform:translateY(30px) scale(.86)}
    60% {transform:translateY(-4px) scale(1.03)}
    100%{opacity:1;transform:translateY(0) scale(1)}
}
.toast{animation:toastSpring .44s cubic-bezier(.34,1.56,.64,1);}

/* ─── Quantity btn pop ─── */
@keyframes qPop{
    0%  {transform:scale(1)}
    40% {transform:scale(1.35)}
    100%{transform:scale(1)}
}
.qbtn.q-pop{animation:qPop .28s cubic-bezier(.34,1.56,.64,1);}

/* ─── Stat counter reveal ─── */
@keyframes statReveal{
    0%  {transform:scale(.6);opacity:0}
    65% {transform:scale(1.2)}
    100%{transform:scale(1);opacity:1}
}
.stat-val.s-pop{animation:statReveal .52s cubic-bezier(.34,1.56,.64,1) forwards;}

/* ─── Order card hover border glow ─── */
.order-card{transition:border-color .25s ease, box-shadow .25s ease;}
.order-card:hover{
    border-color:rgba(0,168,107,.28);
    box-shadow:0 6px 22px rgba(0,168,107,.1);
}

/* ─── Panel slide-in lateral (notif) spring ─── */
.notif-panel{transition:transform .4s cubic-bezier(.34,1.2,.64,1);}

/* ─── Price highlight pulse on hover ─── */
@keyframes priceGlow{
    0%,100%{color:var(--neon)}
    50%{color:var(--cyan)}
}
.pcard:hover .pprice{animation:priceGlow 1.4s ease infinite;}

/* ─── Floating cart bounce on open/close ─── */
@keyframes cfloatBounce{
    0%  {transform:scale(.88);opacity:.6}
    65% {transform:scale(1.04)}
    100%{transform:scale(1);opacity:1}
}
.cfloat.f-bounce{animation:cfloatBounce .38s cubic-bezier(.34,1.56,.64,1);}

/* ─── Section titles fade up on scroll ─── */
.oc-section-title, .chtitle, .stat-lbl{
    transition:color .2s ease;
}

/* ══════════════════════════════════════════════════════
   ANDROID NATIVE BOTTOM NAVIGATION BAR
══════════════════════════════════════════════════════ */
.tabs{display:none!important;}

.android-nav{
    position:fixed;bottom:0;left:0;right:0;z-index:890;
    background:#fff;
    border-top:1px solid rgba(0,0,0,.08);
    box-shadow:0 -4px 24px rgba(0,0,0,.09);
    display:flex;align-items:stretch;
    height:62px;
    padding-bottom:env(safe-area-inset-bottom,0px);
    overflow-x:auto;overflow-y:hidden;
    -webkit-overflow-scrolling:touch;
    scrollbar-width:none;
}
.android-nav::-webkit-scrollbar{display:none;}

.nav-item{
    flex:1;min-width:56px;
    display:flex;flex-direction:column;
    align-items:center;justify-content:center;
    gap:3px;padding:6px 2px 8px;
    border:none;background:transparent;
    cursor:pointer;position:relative;
    -webkit-tap-highlight-color:transparent;
    transition:none;
}

/* ── Indicator pill (Material 3) ── */
.nav-item::before{
    content:'';position:absolute;
    top:6px;left:50%;
    transform:translateX(-50%) scaleX(0);
    width:52px;height:28px;
    background:rgba(0,168,107,.12);
    border-radius:14px;
    transition:transform .3s cubic-bezier(.34,1.4,.64,1),
               background .2s ease;
}
.nav-item.active::before{transform:translateX(-50%) scaleX(1);}

/* ── Icon ── */
.nav-item .ni{
    font-size:19px;color:var(--muted);
    position:relative;z-index:1;
    transition:color .22s ease,
               transform .32s cubic-bezier(.34,1.56,.64,1);
}
.nav-item.active .ni{
    color:var(--neon);
    transform:translateY(-2px) scale(1.12);
}

/* ── Label ── */
.nav-item .nl{
    font-family:var(--fh);font-size:8.5px;font-weight:900;
    color:var(--muted);letter-spacing:.3px;
    white-space:nowrap;position:relative;z-index:1;
    transition:color .22s ease;
}
.nav-item.active .nl{color:var(--neon);}

/* ── Badge ── */
.nav-badge{
    position:absolute;top:4px;
    left:calc(50% + 6px);
    min-width:15px;height:15px;
    background:var(--red);color:#fff;
    font-size:8px;font-weight:900;font-family:var(--fh);
    border-radius:8px;padding:0 4px;
    display:flex;align-items:center;justify-content:center;
    border:1.5px solid #fff;
    animation:badgePop .38s cubic-bezier(.34,1.56,.64,1) forwards;
}
.nav-badge.hidden{display:none;}

/* ── Ripple on nav items ── */
.nav-item{overflow:hidden;}

/* ── Ajuster les panels pour la nav bar ── */
.wrap{padding-bottom:130px!important;}
.cfloat{bottom:76px!important;}
@media(min-width:768px){
    .cfloat{bottom:80px!important;right:20px!important;}
}

/* ══════════════════════════════════════════════════════
   PANELS — CONFIDENTIALITÉ · À PROPOS · DROITS D'AUTEUR
══════════════════════════════════════════════════════ */

/* ── Info panel chrome ── */
.info-panel-hero{
    padding:28px 16px 20px;
    background:linear-gradient(135deg,rgba(0,168,107,.06),rgba(25,118,210,.04));
    border-radius:0 0 28px 28px;
    border-bottom:1px solid var(--bord);
    margin-bottom:16px;
    text-align:center;
}
.info-panel-icon{
    width:64px;height:64px;border-radius:20px;
    background:linear-gradient(135deg,var(--neon),var(--cyan));
    display:flex;align-items:center;justify-content:center;
    font-size:26px;color:#fff;margin:0 auto 12px;
    box-shadow:var(--glow);
}
.info-panel-title{
    font-family:var(--fh);font-size:20px;font-weight:900;
    color:var(--text);margin-bottom:5px;
}
.info-panel-sub{
    font-family:var(--fh);font-size:11px;font-weight:700;
    color:var(--muted);
}

/* ── Settings-style rows ── */
.info-section{
    margin-bottom:14px;
}
.info-section-title{
    font-family:var(--fh);font-size:9px;font-weight:900;
    color:var(--muted);text-transform:uppercase;letter-spacing:1.5px;
    padding:0 16px 6px;
}
.info-row{
    display:flex;align-items:center;gap:13px;
    padding:13px 16px;
    background:#fff;
    border-bottom:1px solid rgba(0,0,0,.05);
    cursor:pointer;transition:background .15s ease;
    -webkit-tap-highlight-color:transparent;
}
.info-row:first-of-type{border-radius:12px 12px 0 0;}
.info-row:last-of-type{border-radius:0 0 12px 12px;border-bottom:none;}
.info-row:only-of-type{border-radius:12px;border-bottom:none;}
.info-row:active{background:rgba(0,168,107,.04);}
.info-row-icon{
    width:38px;height:38px;border-radius:10px;
    display:flex;align-items:center;justify-content:center;
    font-size:16px;flex-shrink:0;
}
.info-row-icon.green {background:rgba(0,168,107,.1);color:var(--neon);}
.info-row-icon.blue  {background:rgba(25,118,210,.1);color:var(--blue);}
.info-row-icon.red   {background:rgba(229,57,53,.1); color:var(--red);}
.info-row-icon.gold  {background:rgba(249,168,37,.1);color:var(--gold);}
.info-row-icon.purple{background:rgba(126,87,194,.1);color:var(--purple);}
.info-row-icon.cyan  {background:rgba(0,151,167,.1); color:var(--cyan);}
.info-row-body{flex:1;min-width:0;}
.info-row-label{
    font-family:var(--fh);font-size:13px;font-weight:900;
    color:var(--text);
}
.info-row-desc{
    font-family:var(--fh);font-size:10px;font-weight:700;
    color:var(--muted);margin-top:2px;line-height:1.4;
}
.info-row-arrow{color:var(--muted);font-size:11px;}
.info-row-value{
    font-family:var(--fh);font-size:10px;font-weight:900;
    color:var(--neon);
}

/* ── Expandable text block ── */
.info-expand{
    background:#fff;border-radius:12px;
    border:1px solid var(--bord);overflow:hidden;
    margin-bottom:10px;
}
.info-expand-head{
    display:flex;align-items:center;justify-content:space-between;
    padding:13px 14px;cursor:pointer;
    -webkit-tap-highlight-color:transparent;
}
.info-expand-head-title{
    font-family:var(--fh);font-size:12px;font-weight:900;color:var(--text);
    display:flex;align-items:center;gap:8px;
}
.info-expand-chevron{
    font-size:11px;color:var(--muted);
    transition:transform .25s ease;
}
.info-expand.open .info-expand-chevron{transform:rotate(180deg);}
.info-expand-body{
    max-height:0;overflow:hidden;
    transition:max-height .35s cubic-bezier(.23,1,.32,1);
}
.info-expand.open .info-expand-body{max-height:600px;}
.info-expand-content{
    padding:4px 14px 14px;
    font-family:var(--fh);font-size:11px;font-weight:700;
    color:var(--text2);line-height:1.75;border-top:1px solid var(--bord);
}

/* ══════════════════════════════════════════════════════
   HERO ANIMATION BOISSONS
══════════════════════════════════════════════════════ */
.drink-hero{
    position:relative;overflow:hidden;
    background:linear-gradient(135deg,
        rgba(0,168,107,.1) 0%,
        rgba(0,151,167,.08) 40%,
        rgba(25,118,210,.07) 100%);
    border-radius:24px;margin:10px 0 16px;
    border:1px solid rgba(0,168,107,.14);
    min-height:160px;
    display:flex;align-items:center;
    box-shadow:0 8px 28px rgba(0,168,107,.1);
}
/* Bulles de fond */
.hero-bubble{
    position:absolute;border-radius:50%;
    background:radial-gradient(circle at 30% 30%,rgba(255,255,255,.7),rgba(0,168,107,.18));
    animation:bubbleRise var(--dur,4s) ease-in-out var(--del,0s) infinite;
    opacity:.6;
}
@keyframes bubbleRise{
    0%   {transform:translateY(0) scale(1);opacity:.5}
    50%  {opacity:.8}
    100% {transform:translateY(-120px) scale(.5);opacity:0}
}
/* Vague de fond */
.hero-wave{
    position:absolute;bottom:-2px;left:-5%;width:110%;height:48px;
    background:linear-gradient(90deg,rgba(0,168,107,.12),rgba(0,151,167,.1),rgba(0,168,107,.12));
    border-radius:50% 50% 0 0;
    animation:waveFloat 3.8s ease-in-out infinite;
}
.hero-wave.w2{
    bottom:-4px;animation-delay:.9s;opacity:.6;
    background:linear-gradient(90deg,rgba(25,118,210,.1),rgba(0,168,107,.08),rgba(25,118,210,.1));
}
@keyframes waveFloat{
    0%,100%{transform:translateX(0) scaleY(1)}
    50%    {transform:translateX(-14px) scaleY(1.08)}
}
/* Contenu texte hero */
.hero-content{
    position:relative;z-index:2;padding:18px 16px;flex:1;
}
.hero-kicker{
    font-family:var(--fh);font-size:10px;font-weight:900;
    letter-spacing:1.8px;text-transform:uppercase;
    color:var(--neon);margin-bottom:5px;
    animation:fadeUp .5s ease both;
}
.hero-title{
    font-family:var(--fh);font-size:22px;font-weight:900;
    color:var(--text);line-height:1.15;margin-bottom:7px;
    animation:fadeUp .5s .08s ease both;
}
.hero-title span{color:var(--neon);}
.hero-sub{
    font-family:var(--fh);font-size:11px;font-weight:700;
    color:var(--text2);margin-bottom:12px;
    animation:fadeUp .5s .16s ease both;
}
.hero-cta{
    display:inline-flex;align-items:center;gap:7px;
    padding:9px 16px;border-radius:20px;
    background:linear-gradient(135deg,var(--neon),var(--cyan));
    color:#fff;font-family:var(--fh);font-size:11px;font-weight:900;
    letter-spacing:.5px;box-shadow:var(--glow);border:none;cursor:pointer;
    animation:fadeUp .5s .24s ease both;
    -webkit-tap-highlight-color:transparent;
    transition:transform .2s cubic-bezier(.34,1.56,.64,1);
}
.hero-cta:active{transform:scale(.95);}
/* Bouteilles flottantes */
.hero-bottles{
    position:relative;z-index:2;
    display:flex;flex-direction:column;align-items:center;
    padding-right:14px;gap:6px;flex-shrink:0;
}
.hero-showcase{
    position:relative;z-index:2;
    display:flex;align-items:center;gap:8px;
    padding:16px 14px 16px 0;flex-shrink:0;
}
.hero-carousel{
    position:relative;width:152px;overflow:hidden;
}
.hero-carousel-track{
    display:flex;transition:transform .45s cubic-bezier(.22,1,.36,1);
    touch-action:pan-y;
}
.hero-carousel-slide{
    min-width:100%;display:flex;justify-content:center;
}
.hero-photo-stack{
    position:relative;width:132px;height:146px;
}
.hero-photo-card{
    position:absolute;display:flex;align-items:flex-end;justify-content:flex-start;
    overflow:hidden;border-radius:20px;background:#fff;border:1px solid rgba(255,255,255,.65);
    box-shadow:0 14px 34px rgba(0,40,30,.18),0 0 0 1px rgba(0,168,107,.08);
    animation:heroCardFloat var(--adur,4.2s) ease-in-out var(--adel,0s) infinite;
    transform-origin:center;
}
.hero-photo-card img{
    width:100%;height:100%;object-fit:cover;display:block;
    transform:scale(1.05);
}
.hero-photo-card::after{
    content:'';position:absolute;inset:auto 0 0 0;height:55%;
    background:linear-gradient(180deg,rgba(0,0,0,0),rgba(0,0,0,.46));
}
.hero-photo-card.is-main{width:82px;height:126px;right:18px;top:10px;z-index:3;}
.hero-photo-card.is-left{width:62px;height:96px;left:0;top:36px;z-index:2;transform:rotate(-9deg);}
.hero-photo-card.is-right{width:58px;height:86px;right:0;bottom:2px;z-index:1;transform:rotate(8deg);}
.hero-photo-meta{
    position:absolute;left:10px;right:10px;bottom:10px;z-index:2;
    font-family:var(--fh);color:#fff;
}
.hero-photo-name{
    font-size:10px;font-weight:900;line-height:1.2;
    text-shadow:0 2px 7px rgba(0,0,0,.25);
}
.hero-photo-price{
    font-size:9px;font-weight:700;opacity:.92;margin-top:3px;
}
.hero-orbit{
    position:absolute;width:30px;height:30px;border-radius:50%;
    display:flex;align-items:center;justify-content:center;
    background:rgba(255,255,255,.7);backdrop-filter:blur(8px);
    box-shadow:0 6px 18px rgba(0,0,0,.08);
    color:var(--neon);font-size:14px;font-weight:900;
    animation:orbitPulse 2.8s ease-in-out infinite;
}
.hero-orbit.orbit-top{top:-2px;left:20px;}
.hero-orbit.orbit-bottom{right:4px;bottom:-6px;animation-delay:.8s;}
.hero-carousel-dots{
    display:flex;justify-content:center;gap:6px;margin-top:10px;
}
.hero-carousel-dot{
    width:8px;height:8px;border-radius:50%;border:none;
    background:rgba(26,46,58,.18);padding:0;cursor:pointer;
}
.hero-carousel-dot.active{background:var(--neon);box-shadow:0 0 0 4px rgba(0,168,107,.12);}
@keyframes heroCardFloat{
    0%,100%{transform:translateY(0) rotate(var(--rot,0deg))}
    50%{transform:translateY(-8px) rotate(calc(var(--rot,0deg) + 2deg))}
}
@keyframes orbitPulse{
    0%,100%{transform:scale(1);box-shadow:0 6px 18px rgba(0,0,0,.08)}
    50%{transform:scale(1.08);box-shadow:0 10px 24px rgba(0,168,107,.18)}
}
.hero-bottle{
    font-size:36px;line-height:1;
    animation:bottleFloat var(--bdur,3.2s) ease-in-out var(--bdel,0s) infinite;
    filter:drop-shadow(0 4px 10px rgba(0,168,107,.25));
}
@keyframes bottleFloat{
    0%,100%{transform:translateY(0) rotate(0deg)}
    33%    {transform:translateY(-8px) rotate(3deg)}
    66%    {transform:translateY(-4px) rotate(-2deg)}
}
/* Gouttes d'eau */
.hero-drop{
    position:absolute;font-size:14px;
    animation:dropFall var(--ddur,2.4s) ease-in var(--ddel,0s) infinite;
    opacity:.6;
}
@keyframes dropFall{
    0%  {transform:translateY(-10px) scale(.8);opacity:.7}
    100%{transform:translateY(180px) scale(.4);opacity:0}
}

/* ══════════════════════════════════════════════════════
   PANELS CONTACT & LIVRAISON
══════════════════════════════════════════════════════ */
.whatsapp-btn{
    display:flex;align-items:center;justify-content:center;gap:10px;
    padding:16px;border-radius:16px;
    background:linear-gradient(135deg,#25d366,#128c7e);
    color:#fff;font-family:var(--fh);font-size:15px;font-weight:900;
    letter-spacing:.5px;text-decoration:none;border:none;cursor:pointer;
    box-shadow:0 8px 24px rgba(37,211,102,.3);
    transition:transform .25s cubic-bezier(.34,1.56,.64,1);
    -webkit-tap-highlight-color:transparent;
}
.whatsapp-btn:active{transform:scale(.97);}
.whatsapp-btn i{font-size:22px;}

.call-btn{
    display:flex;align-items:center;justify-content:center;gap:10px;
    padding:14px;border-radius:16px;
    background:rgba(25,118,210,.08);border:1.5px solid rgba(25,118,210,.22);
    color:var(--blue);font-family:var(--fh);font-size:14px;font-weight:900;
    text-decoration:none;
    transition:all .2s ease;
    -webkit-tap-highlight-color:transparent;
}
.call-btn:active{background:var(--blue);color:#fff;}

.zone-map{
    border-radius:16px;overflow:hidden;
    border:1px solid var(--bord);
    background:linear-gradient(135deg,rgba(25,118,210,.06),rgba(0,168,107,.04));
    padding:16px;margin-bottom:12px;
    position:relative;
}
.zone-item{
    display:flex;align-items:center;gap:10px;
    padding:10px 0;border-bottom:1px solid rgba(0,0,0,.05);
}
.zone-item:last-child{border-bottom:none;}
.zone-dot{
    width:10px;height:10px;border-radius:50%;flex-shrink:0;
}
.zone-dot.ok  {background:var(--neon);box-shadow:0 0 6px rgba(0,168,107,.5);}
.zone-dot.mid {background:var(--gold);box-shadow:0 0 6px rgba(249,168,37,.5);}
.zone-dot.far {background:var(--red); box-shadow:0 0 6px rgba(229,57,53,.5);}
.zone-name{font-family:var(--fh);font-size:12px;font-weight:900;color:var(--text);flex:1;}
.zone-delay{font-family:var(--fh);font-size:10px;font-weight:700;color:var(--muted);}
.zone-price{font-family:var(--fh);font-size:11px;font-weight:900;color:var(--neon);}

/* ── Export & Delete dans privacy ── */
.export-btn{
    display:flex;align-items:center;gap:10px;
    padding:14px 16px;border-radius:14px;
    background:linear-gradient(135deg,rgba(0,168,107,.08),rgba(0,151,167,.06));
    border:1.5px solid rgba(0,168,107,.2);
    font-family:var(--fh);font-size:13px;font-weight:900;color:var(--neon);
    cursor:pointer;width:100%;text-align:left;margin-bottom:10px;
    transition:all .22s cubic-bezier(.34,1.56,.64,1);
    -webkit-tap-highlight-color:transparent;
}
.export-btn:active{transform:scale(.98);}
.export-btn i{font-size:18px;}
.export-list{
    display:flex;flex-direction:column;gap:10px;
}
.delete-request-btn{
    display:flex;align-items:center;gap:10px;
    padding:14px 16px;border-radius:14px;
    background:rgba(229,57,53,.06);border:1.5px solid rgba(229,57,53,.2);
    font-family:var(--fh);font-size:13px;font-weight:900;color:var(--red);
    cursor:pointer;width:100%;text-align:left;
    transition:all .22s ease;
    -webkit-tap-highlight-color:transparent;
}
.delete-request-btn:active{background:rgba(229,57,53,.12);}
.delete-confirm-zone{
    display:none;margin-top:12px;padding:16px;
    border-radius:14px;background:rgba(229,57,53,.04);
    border:1px solid rgba(229,57,53,.18);
}
.delete-confirm-zone.show{display:block;}
.privacy-note{
    margin-top:10px;padding:12px 14px;border-radius:14px;
    background:linear-gradient(135deg,rgba(25,118,210,.05),rgba(0,168,107,.05));
    border:1px solid rgba(25,118,210,.12);
    font-family:var(--fh);font-size:11px;font-weight:700;color:var(--text2);line-height:1.7;
}
.request-status-badge{
    display:flex;align-items:center;gap:9px;margin:10px 0 0;
    padding:12px 14px;border-radius:14px;
    background:rgba(249,168,37,.08);border:1px solid rgba(249,168,37,.22);
    font-family:var(--fh);font-size:11px;font-weight:800;color:#8a5a00;
}
.request-status-badge i{font-size:16px;color:var(--gold);}
.more-grid{
    display:grid;grid-template-columns:1fr 1fr;gap:10px;
}
.more-link{
    display:flex;align-items:center;gap:10px;
    padding:14px;border-radius:16px;text-decoration:none;cursor:pointer;
    background:#fff;border:1px solid var(--bord);color:var(--text);
    box-shadow:0 10px 24px rgba(15,23,42,.05);
}
.more-link i{
    width:34px;height:34px;border-radius:12px;display:flex;align-items:center;justify-content:center;
    background:rgba(0,168,107,.08);color:var(--neon);
}
.wa-actions{
    display:flex;flex-wrap:wrap;gap:7px;margin-top:10px;
}
.wa-pill{
    display:inline-flex;align-items:center;gap:6px;
    padding:8px 10px;border-radius:999px;text-decoration:none;
    background:rgba(37,211,102,.1);border:1px solid rgba(37,211,102,.24);
    color:#14895a;font-family:var(--fh);font-size:10px;font-weight:900;
}
.wa-pill i{font-size:12px;}

/* ── Version chip ── */
.version-chip{
    display:inline-flex;align-items:center;gap:7px;
    padding:8px 14px;border-radius:20px;
    background:rgba(0,168,107,.07);border:1px solid rgba(0,168,107,.18);
    font-family:var(--fh);font-size:11px;font-weight:900;color:var(--neon);
    margin:0 auto;
}

/* ── Copyright footer inside panel ── */
.copyright-footer{
    text-align:center;padding:20px;
    font-family:var(--fh);font-size:10px;font-weight:700;
    color:var(--muted);line-height:1.8;
}
.copyright-footer strong{color:var(--text);}
.copyright-footer a{color:var(--neon);text-decoration:none;}
</style>
</head>
<body>

<!-- ── TOPBAR ── -->
<div class="topbar">
  <div class="topbar-row1">
    <div class="brand">
      <div class="brand-ico">💧</div>
      <div class="brand-txt"><span>ESPERANCE</span> H2O</div>
    </div>
    <div class="topbar-actions">
      <?php if($client_id): ?>
      <!-- Notifications -->
      <div class="notif-btn" onclick="openNotifPanel()" id="notif-btn" title="Notifications">
        <i class="fas fa-bell"></i>
        <div class="notif-badge <?= $unread_notifs?'':'hidden' ?>" id="notif-badge">
          <?= $unread_notifs>9?'9+':$unread_notifs ?>
        </div>
      </div>
      <!-- User -->
      <div class="user-mini">
        <div class="user-av"><?= strtoupper(mb_substr($client_name,0,1)) ?></div>
        <div class="user-name"><?= htmlspecialchars(mb_substr($client_name,0,10)) ?></div>
      </div>
      <!-- Logout -->
      <a href="?logout=1" class="logout-btn" title="Déconnexion" onclick="return confirmLogout()">
        <i class="fas fa-sign-out-alt"></i>
      </a>
      <?php else: ?>
      <a href="/../auth/login_unified.php" class="btn btn-n" style="font-size:11px;padding:7px 12px">
        <i class="fas fa-user"></i> Connexion
      </a>
      <?php endif; ?>
    </div>
  </div>
  <?php if($location_set && ($company_name||$city_name)): ?>
  <div class="loc-pill">
    <i class="fas fa-building"></i>
    <span><?= htmlspecialchars($company_name) ?></span>
    <?php if($city_name): ?>
    <span class="loc-sep">•</span>
    <i class="fas fa-map-marker-alt"></i>
    <span><?= htmlspecialchars($city_name) ?></span>
    <?php endif; ?>
    <a href="?company_id=0&city_id=0" style="margin-left:4px;color:var(--muted);font-size:9px;text-decoration:none"><i class="fas fa-pen"></i></a>
  </div>
  <?php endif; ?>
</div>

<?php if($client_id): ?>
<!-- ── TABS ── -->
<div class="tabs">
  <button class="tab on" id="tab-shop" onclick="switchTab('shop')">
    <i class="fas fa-store"></i> Commander
  </button>
  <button class="tab" id="tab-promotions" onclick="switchTab('promotions')">
    <i class="fas fa-tags"></i> Promotions
    <span class="tab-count" id="promotions-tab-count" <?= empty($promotionCampaigns)?'style="display:none"':'' ?>><?= count($promotionCampaigns) ?></span>
  </button>
  <button class="tab" id="tab-orders" onclick="switchTab('orders')">
    <i class="fas fa-box"></i> Mes commandes
    <span class="tab-count" id="orders-tab-count" style="display:none">0</span>
  </button>
</div>
<?php endif; ?>

<!-- ══════════ PANEL: BOUTIQUE ══════════ -->
<div class="panel show" id="panel-shop">
<div class="wrap">

<?php if(!$location_set): ?>
<div class="card">
  <div class="ch">
    <div class="chtitle"><i class="fas fa-map-marker-alt" style="color:var(--neon)"></i> Votre ville</div>
  </div>
  <div class="cbody">
    <form method="get">
      <div class="fg">
        <label>Société *</label>
        <select name="company_id" required onchange="this.form.submit()">
          <option value="">— Sélectionner —</option>
          <?php foreach($companies as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $company_id==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fg">
        <label>Ville *</label>
        <select name="city_id" required <?= !$company_id?'disabled':'' ?>>
          <option value="">— Sélectionner —</option>
          <?php foreach($cities as $city): ?>
          <option value="<?= $city['id'] ?>" <?= $city_id==$city['id']?'selected':'' ?>><?= htmlspecialchars($city['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-solid btn-full">
        <i class="fas fa-arrow-right"></i> CONTINUER
      </button>
    </form>
  </div>
</div>

<?php else: ?>

<?php if(!$client_id): ?>
<div class="card">
  <div class="ch">
    <div class="chtitle"><i class="fas fa-user" style="color:var(--neon)"></i> Identifiez-vous</div>
  </div>
  <div class="cbody">
    <div class="fg">
      <label>Email *</label>
      <input type="email" id="client-email" placeholder="Ex : client@mail.com" maxlength="190" autocomplete="email">
    </div>
    <div class="fg">
      <label>Mot de passe *</label>
      <input type="password" id="client-password" placeholder="Votre mot de passe" maxlength="150" autocomplete="current-password">
    </div>
    <button onclick="checkClient()" class="btn btn-solid btn-full" id="btn-check">
      <i class="fas fa-right-to-bracket"></i> SE CONNECTER
    </button>
    <div id="new-client" style="display:none;margin-top:12px">
      <div style="background:rgba(255,208,96,.07);border:1px solid rgba(255,208,96,.2);border-radius:10px;padding:10px;margin-bottom:10px">
        <p style="font-family:var(--fh);font-size:11px;font-weight:700;color:var(--gold)">
          <i class="fas fa-user-plus"></i> Nouveau client — Créez votre compte
        </p>
      </div>
      <div class="fg">
        <label>Nom complet *</label>
        <input type="text" id="client-name" placeholder="Ex : Jean KOUA" minlength="2">
      </div>
      <div class="fg">
        <label>Email *</label>
        <input type="email" id="client-register-email" placeholder="Ex : client@mail.com" maxlength="190" autocomplete="email">
      </div>
      <div class="fg">
        <label>Téléphone *</label>
        <input type="tel" id="client-phone" placeholder="Ex : 0708090605" maxlength="20" autocomplete="tel">
      </div>
      <div class="fg">
        <label>Mot de passe *</label>
        <input type="password" id="client-register-password" placeholder="Minimum 6 caractères" maxlength="150" autocomplete="new-password">
      </div>
      <button onclick="registerClient()" class="btn btn-solid btn-full" id="btn-register">
        <i class="fas fa-user-plus"></i> CRÉER MON COMPTE
      </button>
    </div>
    <div style="margin-top:12px;text-align:center">
      <button class="btn btn-n btn-full" type="button" onclick="toggleNewClientForm()" id="toggle-register-client">
        <i class="fas fa-user-plus"></i> Je suis nouveau client
      </button>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if($client_id): ?>

<!-- ── HERO ANIMÉ BOISSONS ── -->
<div class="drink-hero" id="drink-hero">
  <!-- Bulles de fond -->
  <div class="hero-bubble" style="width:18px;height:18px;bottom:14%;left:8%;--dur:3.8s;--del:0s"></div>
  <div class="hero-bubble" style="width:10px;height:10px;bottom:20%;left:22%;--dur:4.6s;--del:.8s"></div>
  <div class="hero-bubble" style="width:14px;height:14px;bottom:8%;left:38%;--dur:3.2s;--del:1.4s"></div>
  <div class="hero-bubble" style="width:8px;height:8px;bottom:30%;left:52%;--dur:5s;--del:.3s"></div>
  <div class="hero-bubble" style="width:12px;height:12px;bottom:12%;left:65%;--dur:4.1s;--del:1.1s"></div>
  <!-- Gouttes flottantes -->
  <span class="hero-drop" style="left:18%;top:10%;--ddur:2.6s;--ddel:.5s">💧</span>
  <span class="hero-drop" style="left:44%;top:5%;--ddur:3.1s;--ddel:1.2s">💧</span>
  <span class="hero-drop" style="left:70%;top:12%;--ddur:2.2s;--ddel:.2s">💧</span>
  <!-- Vagues -->
  <div class="hero-wave"></div>
  <div class="hero-wave w2"></div>
  <!-- Contenu -->
  <div class="hero-content">
    <div class="hero-kicker"><i class="fas fa-tint"></i> Grand Master Delivery</div>
    <div class="hero-title">Boissons<br><span>fraîches</span> livrées<br>chez vous</div>
    <div class="hero-sub">Eau · Jus · Sodas · Pack famille</div>
    <button class="hero-cta" onclick="document.getElementById('shop-search').focus()">
      <i class="fas fa-search"></i> Commander maintenant
    </button>
  </div>
  <?php if(!empty($heroProducts)): ?>
  <div class="hero-showcase" aria-hidden="true">
    <div class="hero-carousel" id="hero-carousel">
      <div class="hero-carousel-track" id="hero-carousel-track">
        <?php foreach($heroProducts as $heroIndex => $heroProduct): ?>
        <div class="hero-carousel-slide">
          <div class="hero-photo-stack">
            <div class="hero-photo-card is-main" style="--adur:3.6s;--adel:0s;--rot:0deg">
              <img src="<?= htmlspecialchars((string)$heroProduct['image_url']) ?>" alt="<?= htmlspecialchars((string)$heroProduct['name']) ?>">
              <div class="hero-photo-meta">
                <div class="hero-photo-name"><?= htmlspecialchars((string)$heroProduct['name']) ?></div>
                <div class="hero-photo-price"><?= number_format((float)($heroProduct['promo']['promo_price'] ?? $heroProduct['price']),0,'','.') ?> CFA</div>
              </div>
            </div>
            <div class="hero-photo-card is-left" style="--adur:4.1s;--adel:.15s;--rot:-9deg">
              <img src="<?= htmlspecialchars((string)$heroProduct['image_url']) ?>" alt="<?= htmlspecialchars((string)$heroProduct['name']) ?>">
            </div>
            <div class="hero-photo-card is-right" style="--adur:4.4s;--adel:.25s;--rot:8deg">
              <img src="<?= htmlspecialchars((string)$heroProduct['image_url']) ?>" alt="<?= htmlspecialchars((string)$heroProduct['name']) ?>">
            </div>
            <div class="hero-orbit orbit-top">💧</div>
            <div class="hero-orbit orbit-bottom">❄️</div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="hero-carousel-dots" id="hero-carousel-dots">
        <?php foreach($heroProducts as $heroIndex => $heroProduct): ?>
        <button type="button" class="hero-carousel-dot <?= $heroIndex === 0 ? 'active' : '' ?>" data-hero-dot="<?= $heroIndex ?>" aria-label="Slide <?= $heroIndex + 1 ?>"></button>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php else: ?>
  <div class="hero-bottles">
    <div class="hero-bottle" style="--bdur:3.2s;--bdel:0s">🧴</div>
    <div class="hero-bottle" style="--bdur:4.1s;--bdel:.6s">🧃</div>
    <div class="hero-bottle" style="--bdur:3.6s;--bdel:1.1s">🥤</div>
  </div>
  <?php endif; ?>
</div>

<div class="loyalty-card">
  <div class="loyalty-top">
    <div>
      <div class="loyalty-kicker">Programme fidélité</div>
      <div class="loyalty-title">Statut <?= htmlspecialchars($loyaltyTier['label']) ?></div>
      <div class="loyalty-meta">Cumulez des points et profitez d'un service prioritaire sur vos commandes.</div>
    </div>
    <div class="loyalty-badge" style="color:<?= htmlspecialchars($loyaltyTier['color']) ?>">VIP <?= htmlspecialchars($loyaltyTier['label']) ?></div>
  </div>
  <div class="loyalty-grid">
    <div class="loyalty-stat"><strong><?= (int)$loyaltyProfile['points'] ?></strong><span>Points</span></div>
    <div class="loyalty-stat"><strong><?= number_format((float)$loyaltyProfile['total_spent'],0,'','.') ?></strong><span>CFA cumulés</span></div>
    <div class="loyalty-stat"><strong><?= (int)$loyaltyProfile['orders_count'] ?></strong><span>Commandes</span></div>
  </div>
</div>

<?php if(!empty($abandonedCartRecovery['reminded'])): ?>
<div class="offer-alert">
  <strong>Relance panier abandonné envoyée.</strong>
  <?php if(!empty($abandonedCartRecovery['email_sent'])): ?> Email expédié.<?php endif; ?>
  <?php if(!empty($abandonedCartRecovery['whatsapp_link'])): ?>
  <a href="<?= htmlspecialchars($abandonedCartRecovery['whatsapp_link']) ?>" target="_blank" rel="noopener" style="color:var(--gold);font-weight:900;text-decoration:none">Ouvrir WhatsApp</a>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Offres personnalisées supprimées du dashboard -->

<div class="shop-tools">
  <input type="search" id="shop-search" class="shop-search" placeholder="Rechercher un produit, une catégorie, une offre…" oninput="applyShopFilters()">
  <div class="shop-row" id="shop-category-row">
    <button type="button" class="shop-pill on" onclick="setShopCategory('all',this)">Toutes</button>
    <button type="button" class="shop-pill" onclick="setShopCategory('favorites',this)">Favoris</button>
    <?php foreach($shopCategories as $shopCategory): ?>
    <button type="button" class="shop-pill" onclick="setShopCategory('<?= htmlspecialchars(addslashes($shopCategory), ENT_QUOTES, 'UTF-8') ?>',this)"><?= htmlspecialchars($shopCategory) ?></button>
    <?php endforeach; ?>
  </div>
  <div class="shop-row" id="shop-sort-row">
    <button type="button" class="shop-pill on" onclick="setShopSort('popular',this)">Popularité</button>
    <button type="button" class="shop-pill" onclick="setShopSort('price_asc',this)">Prix croissant</button>
    <button type="button" class="shop-pill" onclick="setShopSort('price_desc',this)">Prix décroissant</button>
    <button type="button" class="shop-pill" onclick="setShopSort('rating',this)">Mieux notés</button>
    <button type="button" class="shop-pill" onclick="setShopSort('promo',this)">Promos</button>
  </div>
</div>

<?php if(!empty($recommendedProducts)): ?>
<div style="margin:10px 0 12px;font-family:var(--fh);font-size:13px;font-weight:900;color:var(--text);display:flex;align-items:center;justify-content:space-between">
  <span><i class="fas fa-bolt" style="color:var(--gold)"></i> Recommandés pour vous</span>
</div>
<div class="recommend-strip" id="recommend-strip">
  <?php foreach($recommendedProducts as $rec):
    $recIcon=drinkIcon($rec['name']);
    $recImg=$rec['image_url'] ?? '';
  ?>
  <div class="recommend-card">
    <div class="recommend-top">
      <div class="recommend-media"><?php if($recImg): ?><img src="<?= htmlspecialchars($recImg) ?>" alt="<?= htmlspecialchars($rec['name']) ?>"><?php else: ?><?= $recIcon ?><?php endif; ?></div>
      <div style="min-width:0;flex:1">
        <div class="recommend-name"><?= htmlspecialchars($rec['name']) ?></div>
        <div class="recommend-meta"><?= htmlspecialchars($rec['category'] ?: 'Produit') ?></div>
      </div>
    </div>
    <div class="rating-row">
      <span class="stars"><?= str_repeat('★', max(1,(int)round((float)($rec['avg_rating'] ?? 0)))) ?></span>
      <span class="rating-txt"><?= number_format((float)($rec['avg_rating'] ?? 0),1,',',' ') ?> · <?= (int)($rec['review_count'] ?? 0) ?> avis</span>
    </div>
    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px">
      <strong style="color:var(--neon2);font-size:14px"><?= number_format((float)($rec['promo']['promo_price'] ?? $rec['price']),0,'','.') ?> CFA</strong>
      <button class="btn btn-solid" style="padding:8px 12px;font-size:10px" onclick="quickAddRecommended(<?= (int)$rec['id'] ?>)">Ajouter</button>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div style="margin:10px 0 12px;font-family:var(--fh);font-size:13px;font-weight:900;color:var(--text);display:flex;align-items:center;justify-content:space-between">
  <span><i class="fas fa-wine-bottle" style="color:var(--neon)"></i> Nos boissons <span style="color:var(--muted);font-size:11px">(<?= count($products) ?> articles)</span></span>
</div>
<div class="pgrid" id="shop-grid">
<?php if(empty($products)): ?>
  <div class="empty" style="grid-column:1/-1">
    <i class="fas fa-box-open"></i>
    <h3>Aucun produit disponible</h3>
    <p>Stock épuisé pour votre zone</p>
  </div>
<?php else: foreach($products as $i=>$prod):
  $icon=drinkIcon($prod['name']);
  $low=(int)$prod['stock']<10;
  $promo = $prod['promo'] ?? null;
  $js_name=addslashes(htmlspecialchars($prod['name'],ENT_QUOTES,'UTF-8'));
  $img_url=$prod['image_url'] ?? '';
  $js_img=addslashes(htmlspecialchars($img_url,ENT_QUOTES,'UTF-8'));
?>
<div class="pcard"
     data-product-id="<?= (int)$prod['id'] ?>"
     data-name="<?= htmlspecialchars(strtolower($prod['name']), ENT_QUOTES, 'UTF-8') ?>"
     data-category="<?= htmlspecialchars(strtolower((string)($prod['category'] ?? '')), ENT_QUOTES, 'UTF-8') ?>"
     data-price="<?= (float)($prod['promo']['promo_price'] ?? $prod['price']) ?>"
     data-rating="<?= (float)($prod['avg_rating'] ?? 0) ?>"
     data-popularity="<?= (int)($prod['sold_count'] ?? 0) ?>"
     data-favorite="<?= !empty($prod['is_favorite']) ? '1' : '0' ?>"
     data-promo="<?= !empty($prod['promo']) ? (float)($prod['promo']['discount_percent'] ?? 0) : 0 ?>"
     style="animation:fadeUp .4s ease <?= $i*0.05 ?>s backwards">
  <div class="pimg">
    <button type="button" class="fav-btn <?= !empty($prod['is_favorite'])?'on':'' ?>" id="fav-<?= (int)$prod['id'] ?>" onclick="toggleFavorite(<?= (int)$prod['id'] ?>,event)">
      <i class="fas fa-heart"></i>
    </button>
    <span class="sbadge <?= $low?'slow':'sok' ?>">
      <?= $low?'⚠️ '.(int)$prod['stock']:'✅ Stock' ?>
    </span>
    <?php if($img_url): ?>
    <img src="<?= htmlspecialchars($img_url) ?>" alt="<?= htmlspecialchars($prod['name']) ?>">
    <?php else: ?>
    <div class="pemoji"><?= $icon ?></div>
    <?php endif; ?>
  </div>
  <div class="pbody">
    <div class="pname"><?= htmlspecialchars($prod['name']) ?></div>
    <div class="rating-row">
      <span class="stars"><?= str_repeat('★', max(1,(int)round((float)($prod['avg_rating'] ?? 0)))) ?></span>
      <span class="rating-txt"><?= number_format((float)($prod['avg_rating'] ?? 0),1,',',' ') ?> · <?= (int)($prod['review_count'] ?? 0) ?> avis</span>
    </div>
    <?php if($promo): ?>
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:6px">
      <span class="promo-badge main">PROMO</span>
      <?php if((float)$promo['discount_percent'] > 0): ?>
      <span class="promo-badge discount">-<?= rtrim(rtrim(number_format((float)$promo['discount_percent'],2,'.',''),'0'),'.') ?>%</span>
      <?php endif; ?>
      <?php if(($promo['promo_type'] ?? '') === 'flash'): ?>
      <span class="promo-badge flash">Flash</span>
      <?php endif; ?>
    </div>
    <?php if(($promo['promo_type'] ?? '') === 'quantity'): ?>
    <div class="pprice"><?= number_format((float)$prod['price'],0,'','.') ?> <small>CFA</small></div>
    <div style="font-size:10px;color:var(--gold);margin-top:3px"><?= htmlspecialchars($promo['summary'] ?: 'Offre quantité active') ?></div>
    <?php else: ?>
    <div class="promo-price-old"><?= number_format((float)$promo['old_price'],0,'','.') ?> CFA</div>
    <div class="pprice" style="color:var(--neon2)"><?= number_format((float)$promo['promo_price'],0,'','.') ?> <small>CFA</small></div>
    <?php if(!empty($promo['summary'])): ?>
    <div style="font-size:10px;color:var(--gold);margin-top:3px"><?= htmlspecialchars($promo['summary']) ?></div>
    <?php endif; ?>
    <?php endif; ?>
    <?php else: ?>
    <div class="pprice"><?= number_format((float)$prod['price'],0,'','.') ?> <small>CFA</small></div>
    <?php endif; ?>
    <div class="qrow">
      <button class="qbtn" onclick="qDec(<?= $prod['id'] ?>)"><i class="fas fa-minus" style="font-size:9px"></i></button>
      <input type="number" class="qin" id="qty-<?= $prod['id'] ?>" value="1" min="1" max="<?= (int)$prod['stock'] ?>">
      <button class="qbtn" onclick="qInc(<?= $prod['id'] ?>,<?= (int)$prod['stock'] ?>)"><i class="fas fa-plus" style="font-size:9px"></i></button>
      <span class="qmax">/ <?= (int)$prod['stock'] ?></span>
    </div>
    <div class="product-actions">
      <button class="ghost-btn" type="button" onclick="openReviews(<?= (int)$prod['id'] ?>,'<?= $js_name ?>')">
        <i class="fas fa-star"></i> Avis
      </button>
      <button class="badd" onclick="addToCart(<?= $prod['id'] ?>,'<?= $js_name ?>',<?= (float)$prod['price'] ?>,<?= (int)$prod['stock'] ?>,'<?= $icon ?>','<?= $js_img ?>')">
        <i class="fas fa-cart-plus"></i> AJOUTER
      </button>
    </div>
  </div>
</div>
<?php endforeach; endif; ?>
</div>
<?php endif; ?>

<?php endif; ?>
</div>
</div>

<!-- ══════════ PANEL: PROMOTIONS ══════════ -->
<div class="panel" id="panel-promotions">
<div class="wrap">
  <div class="promo-hero">
    <div class="promo-hero-top">
      <div>
        <div class="promo-hero-kicker">Promotions du jour</div>
        <h3>Promotions</h3>
        <p>Offres spéciales disponibles aujourd’hui</p>
      </div>
      <div class="promo-hero-stat">
        <strong id="promo-total-count"><?= count($promotionCampaigns) ?></strong>
        <span>offres disponibles</span>
      </div>
    </div>
  </div>

  <div class="promo-toolbar">
    <div class="promo-filters" id="promo-filters">
      <button class="promo-chip on" type="button" data-filter="all" onclick="setPromoFilter('all',this)">Toutes</button>
      <button class="promo-chip" type="button" data-filter="flash" onclick="setPromoFilter('flash',this)">Flash</button>
      <button class="promo-chip" type="button" data-filter="pack" onclick="setPromoFilter('pack',this)">Packs</button>
      <button class="promo-chip" type="button" data-filter="reduction" onclick="setPromoFilter('reduction',this)">Réduction</button>
      <button class="promo-chip" type="button" data-filter="nouveau" onclick="setPromoFilter('nouveau',this)">Nouveau</button>
    </div>
    <div class="promo-sorts" id="promo-sorts">
      <button class="promo-chip on" type="button" data-sort="best" onclick="setPromoSort('best',this)">Meilleures promos</button>
      <button class="promo-chip" type="button" data-sort="time" onclick="setPromoSort('time',this)">Temps restant</button>
      <button class="promo-chip" type="button" data-sort="price" onclick="setPromoSort('price',this)">Prix le plus bas</button>
      <button class="promo-chip" type="button" data-sort="popular" onclick="setPromoSort('popular',this)">Popularité</button>
    </div>
  </div>

  <div class="promo-grid" id="promo-grid"></div>
</div>
</div>

<!-- ══════════ PANEL: MES COMMANDES ══════════ -->
<div class="panel" id="panel-orders">
<div class="wrap">
  <!-- Stats -->
  <div class="stats-bar" id="orders-stats" style="display:none">
    <div class="stat-card">
      <div class="stat-val" id="st-total">0</div>
      <div class="stat-lbl">Total</div>
    </div>
    <div class="stat-card">
      <div class="stat-val" id="st-active" style="color:var(--cyan)">0</div>
      <div class="stat-lbl">En cours</div>
    </div>
    <div class="stat-card">
      <div class="stat-val" id="st-spent" style="color:var(--gold)">0</div>
      <div class="stat-lbl">CFA dépensé</div>
    </div>
  </div>

  <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px">
    <div style="font-family:var(--fh);font-size:15px;font-weight:900;color:var(--text)">
      📦 Mes commandes
    </div>
    <button class="btn btn-n" onclick="loadOrders(true)" style="font-size:11px;padding:7px 13px" id="refresh-btn">
      <i class="fas fa-sync-alt"></i> Actualiser
    </button>
  </div>

  <!-- Filter tabs -->
  <div class="filter-tabs" id="filter-tabs" style="display:none">
    <button class="ftab on" data-filter="all" onclick="filterOrders('all',this)">Toutes</button>
    <button class="ftab" data-filter="pending" onclick="filterOrders('pending',this)">⏳ Attente</button>
    <button class="ftab" data-filter="confirmed" onclick="filterOrders('confirmed',this)">✅ Confirmées</button>
    <button class="ftab" data-filter="delivering" onclick="filterOrders('delivering',this)">🚚 Livraison</button>
    <button class="ftab" data-filter="done" onclick="filterOrders('done',this)">🎉 Livrées</button>
    <button class="ftab" data-filter="cancelled" onclick="filterOrders('cancelled',this)">❌ Annulées</button>
  </div>

  <div id="orders-list">
    <div style="display:flex;flex-direction:column;gap:10px">
      <?php for($s=0;$s<3;$s++): ?>
      <div class="card" style="padding:15px">
        <div class="skel" style="width:60%;height:12px;margin-bottom:8px"></div>
        <div class="skel" style="width:40%;height:10px;margin-bottom:12px"></div>
        <div class="skel" style="width:80%;height:10px"></div>
      </div>
      <?php endfor; ?>
    </div>
  </div>
</div>
</div>

<!-- ══════════ PANEL: CONFIDENTIALITÉ ══════════ -->
<div class="panel" id="panel-privacy">
<div class="wrap">
  <div class="info-panel-hero">
    <div class="info-panel-icon"><i class="fas fa-shield-alt"></i></div>
    <div class="info-panel-title">Confidentialité</div>
    <div class="info-panel-sub">Vos données sont protégées et sécurisées</div>
  </div>

  <div class="info-section">
    <div class="info-section-title">Vos droits</div>
    <div class="info-expand" onclick="toggleExpand(this)">
      <div class="info-expand-head">
        <div class="info-expand-head-title"><i class="fas fa-user-shield" style="color:var(--neon)"></i> Données personnelles</div>
        <i class="fas fa-chevron-down info-expand-chevron"></i>
      </div>
      <div class="info-expand-body">
        <div class="info-expand-content">Nous collectons uniquement les informations nécessaires à la gestion de vos commandes : nom, téléphone, adresse de livraison. Ces données ne sont jamais vendues ni partagées avec des tiers sans votre consentement explicite.</div>
      </div>
    </div>
    <div class="info-expand" onclick="toggleExpand(this)">
      <div class="info-expand-head">
        <div class="info-expand-head-title"><i class="fas fa-database" style="color:var(--blue)"></i> Stockage des données</div>
        <i class="fas fa-chevron-down info-expand-chevron"></i>
      </div>
      <div class="info-expand-body">
        <div class="info-expand-content">Vos données sont stockées sur des serveurs sécurisés situés localement. L'accès est restreint au personnel autorisé d'Espérance H2O. Les données de commandes sont conservées pendant 3 ans conformément à la réglementation.</div>
      </div>
    </div>
    <div class="info-expand" onclick="toggleExpand(this)">
      <div class="info-expand-head">
        <div class="info-expand-head-title"><i class="fas fa-cookie-bite" style="color:var(--gold)"></i> Cookies & Sessions</div>
        <i class="fas fa-chevron-down info-expand-chevron"></i>
      </div>
      <div class="info-expand-body">
        <div class="info-expand-content">Cette application utilise des sessions PHP sécurisées pour maintenir votre connexion. Aucun cookie de tracking ou de publicité n'est utilisé. Les sessions expirent automatiquement après inactivité.</div>
      </div>
    </div>
  </div>

  <div class="info-section">
    <div class="info-section-title">Vos données</div>
    <div class="export-list">
      <button class="export-btn" onclick="downloadClientExport('json')">
        <i class="fas fa-file-code"></i>
        <div>
          <div style="font-size:13px">Exporter en JSON</div>
          <div style="font-size:10px;font-weight:700;color:var(--muted);margin-top:2px">Format technique complet</div>
        </div>
        <i class="fas fa-chevron-right" style="margin-left:auto;font-size:11px;color:var(--muted)"></i>
      </button>
      <button class="export-btn" onclick="downloadClientExport('csv')">
        <i class="fas fa-file-csv"></i>
        <div>
          <div style="font-size:13px">Exporter en CSV</div>
          <div style="font-size:10px;font-weight:700;color:var(--muted);margin-top:2px">Ouverture Excel / tableur</div>
        </div>
        <i class="fas fa-chevron-right" style="margin-left:auto;font-size:11px;color:var(--muted)"></i>
      </button>
      <button class="export-btn" onclick="downloadClientExport('pdf')">
        <i class="fas fa-file-pdf"></i>
        <div>
          <div style="font-size:13px">Exporter en PDF</div>
          <div style="font-size:10px;font-weight:700;color:var(--muted);margin-top:2px">Résumé imprimable</div>
        </div>
        <i class="fas fa-chevron-right" style="margin-left:auto;font-size:11px;color:var(--muted)"></i>
      </button>
    </div>

    <?php if($pendingDeletionRequest): ?>
    <div class="request-status-badge">
      <i class="fas fa-hourglass-half"></i>
      <div>
        <div>Demande de suppression déjà envoyée</div>
        <div style="font-size:10px;font-weight:700;opacity:.85;margin-top:2px">En attente depuis le <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string)$pendingDeletionRequest['requested_at']))) ?></div>
      </div>
    </div>
    <?php endif; ?>

    <?php if(!$pendingDeletionRequest): ?>
    <button class="delete-request-btn" onclick="document.getElementById('delete-confirm-zone').classList.toggle('show')">
      <i class="fas fa-user-times"></i>
      <div>
        <div style="font-size:13px">Demander la suppression</div>
        <div style="font-size:10px;font-weight:700;color:var(--muted);margin-top:2px">Envoie une demande à l'administrateur</div>
      </div>
    </button>
    <div class="delete-confirm-zone" id="delete-confirm-zone">
      <div style="font-family:var(--fh);font-size:12px;font-weight:700;color:var(--text);margin-bottom:12px;line-height:1.6">
        ⚠️ Cette demande sera transmise à l'administrateur. Votre compte sera supprimé sous 72h après vérification.
      </div>
      <div style="display:flex;gap:8px">
        <button class="btn btn-r btn-full" onclick="requestDeleteAccount()"><i class="fas fa-check"></i> Confirmer la demande</button>
        <button class="btn btn-n" onclick="document.getElementById('delete-confirm-zone').classList.remove('show')"><i class="fas fa-times"></i></button>
      </div>
    </div>
    <?php endif; ?>
    <div class="privacy-note">
      L'export crée maintenant de vrais fichiers JSON, CSV et PDF avec votre profil, vos commandes, vos notifications, vos favoris et vos avis. La suppression envoie une demande formelle à l'administration.
    </div>
  </div>
</div>
</div>

<!-- ══════════ PANEL: CONTACT & WHATSAPP ══════════ -->
<div class="panel" id="panel-contact">
<div class="wrap">
  <div class="info-panel-hero">
    <div class="info-panel-icon" style="background:linear-gradient(135deg,#25d366,#128c7e)"><i class="fab fa-whatsapp"></i></div>
    <div class="info-panel-title">Nous contacter</div>
    <div class="info-panel-sub">Support client · Commandes · Réclamations</div>
  </div>

  <div class="info-section">
    <div class="info-section-title">Contact direct</div>
    <a class="whatsapp-btn" href="https://wa.me/2250707003136?text=Bonjour%20Espérance%20H2O%2C%20j'ai%20une%20question%20concernant%20ma%20commande." target="_blank" rel="noopener">
      <i class="fab fa-whatsapp"></i>
      <div style="text-align:left">
        <div>WhatsApp — Espérance H2O</div>
        <div style="font-size:11px;font-weight:700;opacity:.9;margin-top:2px">+225 07 07 00 31 36</div>
      </div>
      <i class="fas fa-external-link-alt" style="margin-left:auto;font-size:12px;opacity:.8"></i>
    </a>
    <div style="margin-top:10px">
      <a class="call-btn" href="tel:+2250707003136">
        <i class="fas fa-phone-alt"></i>
        <div style="text-align:left">
          <div>Appeler directement</div>
          <div style="font-size:10px;font-weight:700;opacity:.75;margin-top:2px">+225 07 07 00 31 36</div>
        </div>
      </a>
    </div>
  </div>

  <div class="info-section">
    <div class="info-section-title">Horaires du support</div>
    <div style="border-radius:12px;overflow:hidden;border:1px solid var(--bord)">
      <div class="info-row">
        <div class="info-row-icon green"><i class="fas fa-clock"></i></div>
        <div class="info-row-body">
          <div class="info-row-label">Lun — Ven</div>
          <div class="info-row-desc">7h00 — 19h00</div>
        </div>
        <div class="info-row-value" style="color:var(--neon)">Ouvert</div>
      </div>
      <div class="info-row">
        <div class="info-row-icon gold"><i class="fas fa-clock"></i></div>
        <div class="info-row-body">
          <div class="info-row-label">Samedi</div>
          <div class="info-row-desc">8h00 — 17h00</div>
        </div>
        <div class="info-row-value" style="color:var(--gold)">Partiel</div>
      </div>
      <div class="info-row">
        <div class="info-row-icon red"><i class="fas fa-moon"></i></div>
        <div class="info-row-body">
          <div class="info-row-label">Dimanche</div>
          <div class="info-row-desc">Fermé · Urgences WhatsApp uniquement</div>
        </div>
        <div class="info-row-value" style="color:var(--red)">Fermé</div>
      </div>
    </div>
  </div>

  <div class="info-section">
    <div class="info-section-title">Que peut-on faire pour vous ?</div>
    <div style="border-radius:12px;overflow:hidden;border:1px solid var(--bord)">
      <?php foreach([
        ['icon'=>'fa-box','cls'=>'blue','l'=>'Suivi de commande','d'=>'Statut et heure de livraison'],
        ['icon'=>'fa-rotate-left','cls'=>'gold','l'=>'Modification commande','d'=>'Avant départ en livraison'],
        ['icon'=>'fa-star','cls'=>'purple','l'=>'Réclamation qualité','d'=>'Signaler un problème produit'],
        ['icon'=>'fa-truck-fast','cls'=>'cyan','l'=>'Livraison urgente','d'=>'Demande express'],
      ] as $item): ?>
      <div class="info-row">
        <div class="info-row-icon <?= $item['cls'] ?>"><i class="fas <?= $item['icon'] ?>"></i></div>
        <div class="info-row-body">
          <div class="info-row-label"><?= $item['l'] ?></div>
          <div class="info-row-desc"><?= $item['d'] ?></div>
        </div>
        <i class="fas fa-chevron-right info-row-arrow"></i>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
</div>

<!-- ══════════ PANEL: ZONE DE LIVRAISON ══════════ -->
<div class="panel" id="panel-delivery">
<div class="wrap">
  <div class="info-panel-hero">
    <div class="info-panel-icon" style="background:linear-gradient(135deg,var(--gold),var(--orange))"><i class="fas fa-map-marked-alt"></i></div>
    <div class="info-panel-title">Zone de livraison</div>
    <div class="info-panel-sub">Livraison à domicile · 7j/7</div>
  </div>

  <div class="info-section">
    <div class="info-section-title">Délais &amp; Tarifs</div>
    <div class="zone-map">
      <?php if($deliveryZones): ?>
      <?php foreach($deliveryZones as $z):
        $fee = (float)($z['delivery_fee'] ?? 0);
      ?>
      <div class="zone-item">
        <div class="zone-dot <?= deliveryZoneTone($fee) ?>"></div>
        <div class="zone-name"><?= htmlspecialchars((string)$z['zone_name']) ?></div>
        <div class="zone-delay"><?= htmlspecialchars((string)$z['delivery_delay_label']) ?></div>
        <div class="zone-price"><?= $fee <= 0 ? 'Gratuit' : number_format($fee,0,'','.') . ' CFA' ?></div>
      </div>
      <?php endforeach; ?>
      <?php else: ?>
      <div style="font-family:var(--fh);font-size:11px;font-weight:700;color:var(--muted)">
        Aucune zone configurée pour cette ville. Contactez-nous sur WhatsApp pour confirmer la livraison.
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="info-section">
    <div class="info-section-title">Informations importantes</div>
    <div style="border-radius:12px;overflow:hidden;border:1px solid var(--bord)">
      <div class="info-row">
        <div class="info-row-icon green"><i class="fas fa-box-open"></i></div>
        <div class="info-row-body">
          <div class="info-row-label">Commande minimum</div>
          <div class="info-row-desc">Aucun minimum · Toutes commandes acceptées</div>
        </div>
      </div>
      <div class="info-row">
        <div class="info-row-icon blue"><i class="fas fa-temperature-low"></i></div>
        <div class="info-row-body">
          <div class="info-row-label">Boissons fraîches garanties</div>
          <div class="info-row-desc">Livraison en glacière isotherme</div>
        </div>
        <div class="info-row-value" style="color:var(--cyan)">❄️</div>
      </div>
      <div class="info-row">
        <div class="info-row-icon gold"><i class="fas fa-money-bill-wave"></i></div>
        <div class="info-row-body">
          <div class="info-row-label">Paiement</div>
          <div class="info-row-desc">Cash à la livraison · Mobile Money</div>
        </div>
      </div>
    </div>
  </div>

  <div class="info-section">
    <a class="whatsapp-btn" href="https://wa.me/2250707003136?text=Bonjour%2C%20je%20voudrais%20vérifier%20si%20vous%20livrez%20dans%20ma%20zone." target="_blank" rel="noopener" style="text-decoration:none">
      <i class="fab fa-whatsapp"></i> Vérifier ma zone sur WhatsApp
    </a>
  </div>
</div>
</div>

<!-- ══════════ PANEL: PLUS ══════════ -->
<div class="panel" id="panel-more">
<div class="wrap">
  <div class="info-panel-hero">
    <div class="info-panel-icon" style="background:linear-gradient(135deg,var(--blue),var(--purple))"><i class="fas fa-ellipsis-h"></i></div>
    <div class="info-panel-title">Plus</div>
    <div class="info-panel-sub">Accès rapide aux informations utiles</div>
  </div>

  <div class="info-section">
    <div class="info-section-title">Raccourcis</div>
    <div class="more-grid">
      <button class="more-link" type="button" onclick="navSwitch('delivery')"><i class="fas fa-truck"></i><span>Zone de livraison</span></button>
      <button class="more-link" type="button" onclick="navSwitch('contact')"><i class="fab fa-whatsapp"></i><span>Nous contacter</span></button>
      <button class="more-link" type="button" onclick="navSwitch('privacy')"><i class="fas fa-shield-alt"></i><span>Confidentialité</span></button>
      <button class="more-link" type="button" onclick="navSwitch('about')"><i class="fas fa-info-circle"></i><span>À propos</span></button>
      <button class="more-link" type="button" onclick="navSwitch('legal')"><i class="fas fa-copyright"></i><span>Légal</span></button>
    </div>
  </div>
</div>
</div>

<!-- ══════════ PANEL: À PROPOS ══════════ -->
<div class="panel" id="panel-about">
<div class="wrap">
  <div class="info-panel-hero">
    <div class="info-panel-icon" style="background:linear-gradient(135deg,var(--blue),var(--cyan))"><i class="fas fa-tint"></i></div>
    <div class="info-panel-title">Espérance H2O</div>
    <div class="info-panel-sub">Distribution de boissons · Mobile First</div>
    <div style="margin-top:12px;display:flex;justify-content:center">
      <div class="version-chip"><i class="fas fa-tag"></i> Version 2.0</div>
    </div>
  </div>
  <div class="info-section">
    <div class="info-section-title">Application</div>
    <div style="border-radius:12px;overflow:hidden;border:1px solid var(--bord)">
      <div class="info-row">
        <div class="info-row-icon purple"><i class="fas fa-code"></i></div>
        <div class="info-row-body"><div class="info-row-label">Technologie</div><div class="info-row-desc">PHP · Vanilla JS · CSS3 · Mobile-First</div></div>
      </div>
      <div class="info-row">
        <div class="info-row-icon green"><i class="fas fa-shield-alt"></i></div>
        <div class="info-row-body"><div class="info-row-label">Sécurité</div><div class="info-row-desc">Sessions sécurisées · PDO · XSS protégé</div></div>
        <div class="info-row-value" style="color:var(--neon)">✓ OK</div>
      </div>
      <div class="info-row">
        <div class="info-row-icon blue"><i class="fas fa-mobile-alt"></i></div>
        <div class="info-row-body"><div class="info-row-label">Compatible</div><div class="info-row-desc">Android · iOS · PWA Ready</div></div>
      </div>
    </div>
  </div>
</div>
</div>

<!-- ══════════ PANEL: DROITS D'AUTEUR ══════════ -->
<div class="panel" id="panel-legal">
<div class="wrap">
  <div class="info-panel-hero">
    <div class="info-panel-icon" style="background:linear-gradient(135deg,var(--gold),var(--orange))"><i class="fas fa-copyright"></i></div>
    <div class="info-panel-title">Droits d'auteur</div>
    <div class="info-panel-sub">Mentions légales & licences</div>
  </div>

  <div class="info-section">
    <div class="info-section-title">Propriété intellectuelle</div>
    <div class="info-expand open" onclick="toggleExpand(this)">
      <div class="info-expand-head">
        <div class="info-expand-head-title"><i class="fas fa-copyright" style="color:var(--gold)"></i> Copyright</div>
        <i class="fas fa-chevron-down info-expand-chevron"></i>
      </div>
      <div class="info-expand-body">
        <div class="info-expand-content">© <?= date('Y') ?> Espérance H2O. Tous droits réservés. Toute reproduction, distribution ou modification de cette application, en tout ou en partie, sans autorisation écrite préalable est strictement interdite.</div>
      </div>
    </div>
    <div class="info-expand" onclick="toggleExpand(this)">
      <div class="info-expand-head">
        <div class="info-expand-head-title"><i class="fas fa-file-contract" style="color:var(--blue)"></i> Conditions d'utilisation</div>
        <i class="fas fa-chevron-down info-expand-chevron"></i>
      </div>
      <div class="info-expand-body">
        <div class="info-expand-content">L'utilisation de cette application est réservée aux clients enregistrés d'Espérance H2O. Toute utilisation frauduleuse, tentative d'accès non autorisé ou manipulation des données est susceptible de faire l'objet de poursuites judiciaires.</div>
      </div>
    </div>
    <div class="info-expand" onclick="toggleExpand(this)">
      <div class="info-expand-head">
        <div class="info-expand-head-title"><i class="fas fa-balance-scale" style="color:var(--purple)"></i> Responsabilité</div>
        <i class="fas fa-chevron-down info-expand-chevron"></i>
      </div>
      <div class="info-expand-body">
        <div class="info-expand-content">Espérance H2O s'engage à maintenir l'exactitude des informations publiées sur l'application. Cependant, nous ne pouvons garantir l'exactitude ou l'exhaustivité des informations, et déclinons toute responsabilité pour les erreurs ou omissions.</div>
      </div>
    </div>
  </div>

  <div class="info-section">
    <div class="info-section-title">Ressources tierces</div>
    <div style="border-radius:12px;overflow:hidden;border:1px solid var(--bord)">
      <div class="info-row">
        <div class="info-row-icon blue"><i class="fab fa-font-awesome"></i></div>
        <div class="info-row-body">
          <div class="info-row-label">Font Awesome 6.5</div>
          <div class="info-row-desc">Licence Free · fontawesome.com</div>
        </div>
        <div class="info-row-value">MIT</div>
      </div>
      <div class="info-row">
        <div class="info-row-icon green"><i class="fas fa-font"></i></div>
        <div class="info-row-body">
          <div class="info-row-label">Google Fonts</div>
          <div class="info-row-desc">Source Serif 4 · fonts.google.com</div>
        </div>
        <div class="info-row-value">OFL</div>
      </div>
      <div class="info-row">
        <div class="info-row-icon gold"><i class="fas fa-code"></i></div>
        <div class="info-row-body">
          <div class="info-row-label">PHP · MariaDB</div>
          <div class="info-row-desc">Langages et base de données open-source</div>
        </div>
        <div class="info-row-value">GPL</div>
      </div>
    </div>
  </div>

  <div class="copyright-footer">
    <strong>© <?= date('Y') ?> Espérance H2O</strong><br>
    Tous droits réservés · Version 2.0<br>
    Développé avec <i class="fas fa-heart" style="color:var(--red)"></i> pour votre satisfaction
  </div>
</div>
</div>

<!-- ── FLOATING CART ── -->
<div class="cfloat mini" id="cfloat">
  <div class="chd" onclick="toggleCart()">
    <div style="display:flex;align-items:center;gap:7px">
      <div class="chico"><i class="fas fa-shopping-cart"></i></div>
      <span class="chtxt">Panier</span>
    </div>
    <div style="display:flex;align-items:center;gap:5px">
      <div class="ccnt" id="cart-cnt">0</div>
      <i class="fas fa-chevron-up chtog" style="color:var(--muted);font-size:11px"></i>
    </div>
  </div>
  <div class="cbdy" id="cart-body">
    <div class="cempty">
      <i class="fas fa-shopping-basket"></i>
      <p style="font-family:var(--fh);font-size:10px;font-weight:900">Panier vide</p>
    </div>
  </div>
  <div class="cfot" id="cart-foot" style="display:none">
    <div class="ctrow">
      <span class="ctlbl">Total</span>
      <span class="ctamt" id="cart-tot">0 CFA</span>
    </div>
    <button class="btn btn-solid btn-full" onclick="openCheckout()">
      <i class="fas fa-check-circle"></i> COMMANDER
    </button>
  </div>
</div>

<!-- ── CHECKOUT MODAL ── -->
<div class="modal" id="co-modal">
  <div class="mbox">
    <div class="mhead">
      <div class="mtitle"><i class="fas fa-shopping-cart"></i> Finaliser la commande</div>
      <div class="mclose" onclick="closeCo()">×</div>
    </div>
    <div class="mbody" id="co-body"></div>
  </div>
</div>

<!-- ── ORDER DETAIL MODAL ── -->
<div class="modal" id="detail-modal">
  <div class="mbox">
    <div class="mhead">
      <div class="mtitle"><i class="fas fa-receipt"></i> Détail commande</div>
      <div class="mclose" onclick="closeDetail()">×</div>
    </div>
    <div class="mbody" id="detail-body">
      <div class="empty"><i class="fas fa-spinner fa-spin" style="opacity:.4;font-size:30px"></i></div>
    </div>
  </div>
</div>

<!-- ── REVIEWS MODAL ── -->
<div class="modal" id="reviews-modal">
  <div class="mbox">
    <div class="mhead">
      <div class="mtitle"><i class="fas fa-star"></i> Avis produit</div>
      <div class="mclose" onclick="closeReviews()">×</div>
    </div>
    <div class="mbody">
      <div class="review-summary">
        <div>
          <div id="reviews-product-name" style="font-family:var(--fh);font-size:13px;font-weight:900;color:var(--text)">Produit</div>
          <div id="reviews-stats" class="rating-txt">0 avis</div>
        </div>
        <div class="stars" id="reviews-stars">☆☆☆☆☆</div>
      </div>
      <div id="reviews-list"></div>
      <div class="review-form">
        <input type="hidden" id="review-product-id" value="0">
        <div class="fg">
          <label>Votre note</label>
          <select id="review-rating">
            <option value="5">★★★★★ - 5/5</option>
            <option value="4">★★★★☆ - 4/5</option>
            <option value="3">★★★☆☆ - 3/5</option>
            <option value="2">★★☆☆☆ - 2/5</option>
            <option value="1">★☆☆☆☆ - 1/5</option>
          </select>
        </div>
        <textarea id="review-text" class="review-textarea" placeholder="Partagez votre expérience sur ce produit…"></textarea>
        <button class="btn btn-solid btn-full" type="button" onclick="submitReview()"><i class="fas fa-paper-plane"></i> Publier mon avis</button>
      </div>
    </div>
  </div>
</div>

<!-- ── SUCCESS OVERLAY ── -->
<div class="success-overlay" id="success-overlay">
  <div class="success-box">
    <div class="confetti-layer" id="confetti-layer"></div>
    <div class="success-shell">
      <div class="success-head">
        <div class="success-status"><i class="fas fa-circle-check"></i> ✔ Commande confirmée</div>
        <div class="success-order-meta">
          <div class="snum"><i class="fas fa-receipt"></i> <span id="s-order-num">CMD-...</span></div>
          <div class="smeta-pill"><span class="audio-dot"></span> Voix automatique active</div>
        </div>
      </div>

      <div class="success-stage">
        <div class="loader-block" id="success-loader">
          <div class="loader-ring"></div>
          <div class="loader-text">Traitement de la commande...</div>
          <div class="loader-sub">Validation, tracking et notification en cours</div>
        </div>

        <div class="success-main" id="success-main">
          <div class="check-hero">
            <div class="check-circle">
              <i class="fas fa-check check-mark"></i>
            </div>
            <div class="success-copy">
              <h2>Votre commande a été bien reçue.</h2>
              <p>Elle sera traitée dans les plus brefs délais. ESPERANCE H2O vous remercie.</p>
            </div>
          </div>

          <div class="vehicle-zone">
            <div class="bike">🛵</div>
            <div class="truck-wrap">
              <div class="truck">
                <div class="truck-body">
                  <div class="truck-wheel w1"></div>
                  <div class="truck-wheel w2"></div>
                  <div class="vehicle-shadow"></div>
                </div>
                <div class="truck-cabin"></div>
              </div>
            </div>
            <div class="vehicle-road"></div>
          </div>

          <div class="prep-line">
            <i class="fas fa-box-open"></i>
            <span>Livraison en préparation...</span>
          </div>

          <div class="timeline-pro" id="success-timeline">
            <div class="tl-pro-step current" data-step="0">
              <div class="tl-pro-dot"><i class="fas fa-inbox"></i></div>
              <div class="tl-pro-title">Commande reçue</div>
              <div class="tl-pro-sub">🟢 Commande confirmée</div>
            </div>
            <div class="tl-pro-step" data-step="1">
              <div class="tl-pro-dot"><i class="fas fa-box"></i></div>
              <div class="tl-pro-title">Préparation</div>
              <div class="tl-pro-sub">🟡 Préparation</div>
            </div>
            <div class="tl-pro-step" data-step="2">
              <div class="tl-pro-dot"><i class="fas fa-truck-fast"></i></div>
              <div class="tl-pro-title">En livraison</div>
              <div class="tl-pro-sub">🔵 Livraison</div>
            </div>
            <div class="tl-pro-step" data-step="3">
              <div class="tl-pro-dot"><i class="fas fa-house-circle-check"></i></div>
              <div class="tl-pro-title">Livrée</div>
              <div class="tl-pro-sub">✅ Livrée</div>
            </div>
          </div>

          <div class="grid-premium">
            <div class="map-card">
              <h3>📍 Carte livraison</h3>
              <div class="fake-map">
                <div class="map-pin store"><i class="fas fa-store"></i> 🏪 ESPERANCE H2O</div>
                <div class="map-pin city"><i class="fas fa-location-dot"></i> 📍 Abidjan</div>
                <div class="map-pin home"><i class="fas fa-house"></i> 🏠 Votre position</div>
                <div class="route-dot"></div>
              </div>
            </div>

            <div style="display:flex;flex-direction:column;gap:12px">
              <div class="eta-card">
                <h3>⏱ Temps estimé</h3>
                <div class="eta-live">
                  <div class="eta-time">
                    <small>Temps estimé :</small>
                    <span class="eta-range">15 - 25 minutes</span>
                  </div>
                  <div class="eta-badge">⚡ Livraison rapide</div>
                </div>
                <div class="eta-progress"><div class="eta-progress-bar"></div></div>
              </div>

              <div class="notify-card">
                <h3>📱 Notifications</h3>
                <div class="notify-list">
                  <div class="notify-bubble"><i class="fas fa-bell"></i><div><strong>🔔 Notification envoyée</strong><small>Confirmation commande professionnelle</small></div></div>
                  <div class="notify-bubble"><i class="fas fa-comment-sms"></i><div><strong>📱 SMS envoyé</strong><small>Suivi de livraison disponible</small></div></div>
                  <div class="notify-bubble"><i class="fab fa-whatsapp"></i><div><strong>💬 WhatsApp envoyé</strong><small>Message transmis à votre numéro</small></div></div>
                </div>
              </div>
            </div>
          </div>

          <div class="thanks-card">
            <div class="thanks-pill">
              <i class="fas fa-droplet"></i>
              <div>
                <h3>💧 ESPERANCE H2O vous remercie</h3>
                <p>Votre commande a été bien reçue. Elle sera traitée dans les plus brefs délais. ESPERANCE H2O vous remercie.</p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="success-actions">
        <button class="btn btn-g success-btn" onclick="viewConfirmedOrder()"><i class="fas fa-box-open"></i> Voir ma commande</button>
        <button class="btn btn-n success-btn" onclick="continueShoppingFromSuccess()"><i class="fas fa-store"></i> Continuer mes achats</button>
        <button class="btn success-btn ok" onclick="closeSuccess(true)"><i class="fas fa-check"></i> OK j'ai compris</button>
      </div>
      <div class="success-close-hint" id="success-close-hint">Fermeture automatique dans 10s</div>
    </div>
  </div>
</div>

<!-- ── CONFIRM CANCEL DIALOG ── -->
<div class="confirm-modal" id="confirm-modal">
  <div class="confirm-box">
    <span class="confirm-ico">⚠️</span>
    <div class="confirm-title">Annuler la commande ?</div>
    <div class="confirm-sub" id="confirm-sub">Cette action est irréversible. La commande sera annulée définitivement.</div>
    <div class="confirm-btns">
      <button class="btn btn-g btn-full" onclick="closeConfirm()"><i class="fas fa-times"></i> Non</button>
      <button class="btn btn-r btn-full" id="confirm-yes-btn" onclick="doCancelOrder()"><i class="fas fa-check"></i> Oui, annuler</button>
    </div>
  </div>
</div>

<!-- ── NOTIFICATION PANEL ── -->
<div class="notif-overlay" id="notif-overlay" onclick="closeNotifPanel()"></div>
<div class="notif-panel" id="notif-panel">
  <div class="notif-header">
    <div class="notif-title"><i class="fas fa-bell"></i> Notifications</div>
    <div class="notif-actions">
      <button class="mark-read-btn" onclick="markAllRead()">Tout lire</button>
      <div class="notif-close" onclick="closeNotifPanel()">×</div>
    </div>
  </div>
  <div class="notif-list" id="notif-list">
    <div class="notif-empty">
      <i class="fas fa-bell-slash"></i>
      <p>Chargement…</p>
    </div>
  </div>
</div>

<div class="tstack" id="tstack"></div>

<script>
const SELF   = '<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>';
const CID    = <?= $client_id ?>;
const CO_ID  = <?= $company_id ?>;
const CI_ID  = <?= $city_id ?>;
let cart     = [];
let isOpen   = false;
let allOrders= [];
let currentFilter = 'all';
let cancelOrderId = null;
let unreadCount   = <?= $unread_notifs ?>;
const esc = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
let successCloseTimer = null;
let successCountdownTimer = null;
let successStageTimer = null;
let successSpeechUtterance = null;
let latestConfirmedOrderId = 0;
let latestConfirmedOrderNumber = '';
const PROMOTION_CAMPAIGNS = <?= json_encode($promotionCampaigns, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const PRODUCT_PROMOS = <?= json_encode($productPromoMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
let currentPromoFilter = 'all';
let currentPromoSort = 'best';
const PRODUCT_MAP = <?= json_encode(array_reduce($products, function(array $carry, array $prod): array {
    $carry[(int)$prod['id']] = [
        'id' => (int)$prod['id'],
        'name' => (string)$prod['name'],
        'price' => (float)$prod['price'],
        'stock' => (int)$prod['stock'],
        'icon' => drinkIcon((string)$prod['name']),
        'image_url' => (string)($prod['image_url'] ?? ''),
    ];
    return $carry;
}, []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
let currentShopCategory = 'all';
let currentShopSort = 'popular';
let favoriteIds = new Set(<?= json_encode(array_values($favoriteProductIds), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>);
let cartSyncTimer = null;
let appliedCoupon = null;
const CLIENT_EXPORT_NAME = 'esperance-h2o-client-export';
const ADMIN_WHATSAPP_NUMBER = '2250707003136';
const DELIVERY_ZONES = <?= json_encode(array_values(array_map(static function(array $zone): array {
    return [
        'id' => (int)$zone['id'],
        'zone_name' => (string)($zone['zone_name'] ?? ''),
        'delivery_delay_label' => (string)($zone['delivery_delay_label'] ?? ''),
        'delivery_fee' => (float)($zone['delivery_fee'] ?? 0),
        'notes' => (string)($zone['notes'] ?? ''),
    ];
}, $deliveryZones)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

/* ── Tab Switch (legacy compat) ── */
function switchTab(tab){ navSwitch(tab, document.getElementById('nav-'+tab)); }

/* ── Android Nav Switch ── */
function navSwitch(tab, btn){
    /* panels */
    document.querySelectorAll('.panel').forEach(p=>p.classList.remove('show'));
    const panel = document.getElementById('panel-'+tab);
    if(panel) panel.classList.add('show');
    /* nav items */
    document.querySelectorAll('.nav-item').forEach(b=>b.classList.remove('active'));
    let navBtn = btn || document.getElementById('nav-'+tab);
    if(!navBtn && ['delivery','contact','privacy','about','legal'].includes(tab)){
        navBtn = document.getElementById('nav-more');
    }
    if(navBtn){
        navBtn.classList.add('active');
        /* icon spring */
        const ico = navBtn.querySelector('.ni');
        if(ico){ ico.style.animation='none'; void ico.offsetWidth; ico.style.animation=''; }
    }
    /* legacy tab highlight */
    document.querySelectorAll('.tab').forEach(b=>b.classList.remove('on'));
    const legacyTab = document.getElementById('tab-'+tab);
    if(legacyTab) legacyTab.classList.add('on');
    /* side effects */
    if(tab==='orders')      loadOrders();
    if(tab==='promotions')  renderPromotions();
    /* floating cart: hide on info panels */
    const cf = document.getElementById('cfloat');
    if(cf){
        const infoTabs = ['privacy','about','legal','contact','delivery','more'];
        cf.style.display = infoTabs.includes(tab) ? 'none' : '';
    }
    /* scroll to top */
    window.scrollTo({top:0,behavior:'smooth'});
}

/* ── Expand/collapse info blocks ── */
function toggleExpand(el){
    el.classList.toggle('open');
}

async function fetchClientExportData(){
    if(!CID){toast("Identifiez-vous d'abord !",'warn');return;}
    const fd=new FormData();
    fd.append('action','export_data');
    try{
        const res=await fetch(SELF,{method:'POST',body:fd});
        const data=await res.json();
        if(!data.success || !data.data){
            toast(data.message||'Export impossible','error');
            return null;
        }
        return data;
    }catch(e){
        toast('Erreur réseau export','error');
        return null;
    }
}

function triggerBlobDownload(content, mime, filename){
    const blob=new Blob([content],{type:mime});
    const url=URL.createObjectURL(blob);
    const link=document.createElement('a');
    link.href=url;
    link.download=filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(()=>URL.revokeObjectURL(url),1200);
}

function exportDataToCsv(data){
    const rows=[['Section','Champ','Valeur']];
    const pushFlat=(section,obj)=>{
        Object.entries(obj||{}).forEach(([key,value])=>{
            rows.push([section,key, value==null ? '' : String(value)]);
        });
    };
    pushFlat('client', data.client||{});
    (data.orders||[]).forEach((row,idx)=>pushFlat(`order_${idx+1}`, row));
    (data.notifications||[]).forEach((row,idx)=>pushFlat(`notification_${idx+1}`, row));
    (data.favorites||[]).forEach((row,idx)=>pushFlat(`favorite_${idx+1}`, row));
    (data.reviews||[]).forEach((row,idx)=>pushFlat(`review_${idx+1}`, row));
    return '\uFEFF'+rows.map(cols=>cols.map(val=>{
        const txt=String(val??'').replace(/"/g,'""');
        return `"${txt}"`;
    }).join(';')).join('\n');
}

async function downloadClientExport(format){
    if(format==='pdf'){
        window.location.href=`${SELF}?download_export=pdf`;
        return;
    }
    const payload=await fetchClientExportData();
    if(!payload) return;
    if(format==='csv'){
        triggerBlobDownload(
            exportDataToCsv(payload.data),
            'text/csv;charset=utf-8;',
            (payload.filename || `${CLIENT_EXPORT_NAME}-${CID}.json`).replace(/\.json$/i,'.csv')
        );
        toast('Export CSV téléchargé','success');
        return;
    }
    triggerBlobDownload(
        JSON.stringify(payload.data,null,2),
        'application/json;charset=utf-8',
        payload.filename || `${CLIENT_EXPORT_NAME}-${CID}.json`
    );
    toast('Export JSON téléchargé','success');
}

async function requestDeleteAccount(){
    if(!CID){toast("Identifiez-vous d'abord !",'warn');return;}
    const btn=document.querySelector('#delete-confirm-zone .btn-r');
    if(btn){
        btn.disabled=true;
        btn.innerHTML='<div class="sp"></div>';
    }
    const fd=new FormData();
    fd.append('action','request_delete');
    try{
        const res=await fetch(SELF,{method:'POST',body:fd});
        const data=await res.json();
        if(btn){
            btn.disabled=false;
            btn.innerHTML='<i class="fas fa-check"></i> Confirmer la demande';
        }
        if(data.success){
            document.getElementById('delete-confirm-zone')?.classList.remove('show');
            toast(data.message||'Demande envoyée','success');
        }else{
            toast(data.message||'Envoi impossible','error');
        }
    }catch(e){
        if(btn){
            btn.disabled=false;
            btn.innerHTML='<i class="fas fa-check"></i> Confirmer la demande';
        }
        toast('Erreur réseau','error');
    }
}
async function logWhatsAppClick(clickType, orderId=0){
    if(!CID) return;
    const fd=new FormData();
    fd.append('action','log_whatsapp_click');
    fd.append('click_type',clickType);
    fd.append('order_id',orderId);
    fd.append('target_phone',ADMIN_WHATSAPP_NUMBER);
    try{ await fetch(SELF,{method:'POST',body:fd}); }catch(e){}
}
function buildOrderWhatsAppLink(order, mode='track'){
    const orderNumber=String(order?.order_number||'');
    const clientName=String(order?.client_name || 'Client');
    const address=String(order?.delivery_address||'');
    const messageMap={
        track:`Bonjour Espérance H2O, je souhaite suivre ma commande ${orderNumber}. Client: ${clientName}.`,
        edit:`Bonjour Espérance H2O, je souhaite modifier ma commande ${orderNumber} avant livraison.${address ? ` Adresse: ${address}.` : ''}`,
        issue:`Bonjour Espérance H2O, je signale un problème concernant la commande ${orderNumber}. Client: ${clientName}.`,
    };
    return `https://wa.me/${ADMIN_WHATSAPP_NUMBER}?text=${encodeURIComponent(messageMap[mode]||messageMap.track)}`;
}

/* ── Toast ── */
function toast(msg,type='success'){
    const C={success:'var(--neon)',error:'var(--red)',warn:'var(--gold)'};
    const IC={success:'fa-check-circle',error:'fa-times-circle',warn:'fa-exclamation-triangle'};
    const stack=document.getElementById('tstack');if(!stack)return;
    const el=document.createElement('div');
    el.className='toast'+(type==='error'?' err':type==='warn'?' warn':'');
    el.innerHTML=`<div class="tico" style="background:${C[type]}22;color:${C[type]}"><i class="fas ${IC[type]}"></i></div>
        <div class="ttxt"><strong style="color:${C[type]}">${msg}</strong></div>`;
    stack.prepend(el);
    setTimeout(()=>el.remove(),3500);
}

/* ── Qty ── */
function qDec(id){const el=document.getElementById('qty-'+id);if(+el.value>1)el.value--;}
function qInc(id,max){const el=document.getElementById('qty-'+id);if(+el.value<max)el.value++;}
function starsFor(v){
    const rounded=Math.max(0,Math.min(5,Math.round(Number(v||0))));
    return '★★★★★'.slice(0,rounded)+'☆☆☆☆☆'.slice(0,5-rounded);
}
function setShopCategory(category,btn){
    currentShopCategory=String(category||'all').toLowerCase();
    document.querySelectorAll('#shop-category-row .shop-pill').forEach(el=>el.classList.toggle('on',el===btn));
    applyShopFilters();
}
function setShopSort(sort,btn){
    currentShopSort=sort||'popular';
    document.querySelectorAll('#shop-sort-row .shop-pill').forEach(el=>el.classList.toggle('on',el===btn));
    applyShopFilters();
}
function applyShopFilters(){
    const grid=document.getElementById('shop-grid');
    if(!grid) return;
    const term=(document.getElementById('shop-search')?.value||'').trim().toLowerCase();
    const cards=[...grid.querySelectorAll('.pcard')];
    cards.forEach(card=>{
        const name=card.dataset.name||'';
        const category=card.dataset.category||'';
        const isFavorite=(card.dataset.favorite||'0')==='1';
        const matchesTerm=!term || name.includes(term) || category.includes(term);
        const matchesCategory=currentShopCategory==='all'
            ? true
            : currentShopCategory==='favorites'
                ? isFavorite
                : category===currentShopCategory;
        card.classList.toggle('hidden-by-filter', !(matchesTerm && matchesCategory));
    });
    cards.sort((a,b)=>{
        const getNum=(el,key)=>Number(el.dataset[key]||0);
        if(currentShopSort==='price_asc') return getNum(a,'price')-getNum(b,'price');
        if(currentShopSort==='price_desc') return getNum(b,'price')-getNum(a,'price');
        if(currentShopSort==='rating') return getNum(b,'rating')-getNum(a,'rating');
        if(currentShopSort==='promo') return getNum(b,'promo')-getNum(a,'promo');
        return getNum(b,'popularity')-getNum(a,'popularity');
    }).forEach(card=>grid.appendChild(card));
}
async function toggleFavorite(productId, ev){
    ev?.preventDefault();
    ev?.stopPropagation();
    if(!CID){toast("Identifiez-vous d'abord !",'warn');return;}
    const fd=new FormData();
    fd.append('action','toggle_favorite');
    fd.append('product_id',productId);
    try{
        const res=await fetch(SELF,{method:'POST',body:fd});
        const data=await res.json();
        if(!data.success){toast(data.message||'Erreur favoris','error');return;}
        const btn=document.getElementById('fav-'+productId);
        const card=document.querySelector(`.pcard[data-product-id="${productId}"]`);
        if(data.favorite){favoriteIds.add(Number(productId));}
        else{favoriteIds.delete(Number(productId));}
        btn?.classList.toggle('on',!!data.favorite);
        if(card){card.dataset.favorite=data.favorite?'1':'0';}
        applyShopFilters();
        toast(data.favorite?'Ajouté aux favoris':'Retiré des favoris','success');
    }catch(e){toast('Erreur réseau','error');}
}
function quickAddRecommended(productId){
    const p=PRODUCT_MAP[String(productId)] || PRODUCT_MAP[productId];
    if(!p){toast('Produit introuvable','error');return;}
    addToCart(Number(p.id), p.name, Number(p.price), Number(p.stock), p.icon, p.image_url);
}
async function openReviews(productId, productName){
    document.getElementById('reviews-modal').classList.add('show');
    document.getElementById('review-product-id').value=productId;
    document.getElementById('reviews-product-name').textContent=productName;
    document.getElementById('reviews-list').innerHTML='<div class="review-empty"><i class="fas fa-spinner fa-spin"></i></div>';
    const fd=new FormData();
    fd.append('action','get_product_reviews');
    fd.append('product_id',productId);
    try{
        const res=await fetch(SELF,{method:'POST',body:fd});
        const data=await res.json();
        if(data.success){renderReviews(data.reviews||[], data.stats||{});}
        else{document.getElementById('reviews-list').innerHTML=`<div class="review-empty">${esc(data.message||'Erreur')}</div>`;}
    }catch(e){document.getElementById('reviews-list').innerHTML='<div class="review-empty">Erreur réseau</div>';}
}
function closeReviews(){document.getElementById('reviews-modal').classList.remove('show');}
function renderReviews(reviews, stats){
    document.getElementById('reviews-stars').textContent=starsFor(stats.avg_rating||0);
    document.getElementById('reviews-stats').textContent=`${Number(stats.avg_rating||0).toFixed(1)} · ${Number(stats.review_count||0)} avis`;
    const list=document.getElementById('reviews-list');
    if(!reviews.length){
        list.innerHTML='<div class="review-empty">Aucun avis pour le moment</div>';
        return;
    }
    list.innerHTML=reviews.map(r=>`
      <div class="review-card">
        <div class="review-head">
          <div>
            <div class="review-name">${esc(r.client_name||'Client')}</div>
            <div class="stars">${starsFor(r.rating)}</div>
          </div>
          <div class="review-date">${new Date(String(r.created_at).replace(' ','T')).toLocaleDateString('fr-FR')}</div>
        </div>
        <div class="review-text">${esc(r.review_text||'')}</div>
      </div>
    `).join('');
}
async function submitReview(){
    if(!CID){toast("Identifiez-vous d'abord !",'warn');return;}
    const productId=Number(document.getElementById('review-product-id').value||0);
    const rating=Number(document.getElementById('review-rating').value||5);
    const reviewText=(document.getElementById('review-text').value||'').trim();
    if(!productId){toast('Produit invalide','error');return;}
    const fd=new FormData();
    fd.append('action','save_product_review');
    fd.append('product_id',productId);
    fd.append('rating',rating);
    fd.append('review_text',reviewText);
    try{
        const res=await fetch(SELF,{method:'POST',body:fd});
        const data=await res.json();
        if(data.success){
            renderReviews(data.reviews||[], data.stats||{});
            document.getElementById('review-text').value='';
            updateProductReviewBadge(productId, data.stats||{});
            toast('Avis publié','success');
        }else{toast(data.message||'Impossible de publier','error');}
    }catch(e){toast('Erreur réseau','error');}
}
function updateProductReviewBadge(productId, stats){
    const card=document.querySelector(`.pcard[data-product-id="${productId}"]`);
    if(!card) return;
    card.dataset.rating=Number(stats.avg_rating||0);
    const txt=card.querySelector('.rating-txt');
    const stars=card.querySelector('.stars');
    if(txt) txt.textContent=`${Number(stats.avg_rating||0).toFixed(1).replace('.',',')} · ${Number(stats.review_count||0)} avis`;
    if(stars) stars.textContent=starsFor(stats.avg_rating||0);
    applyShopFilters();
}
function persistCart(){
    try{
        const key=`eh2o_cart_${CID}_${CO_ID}_${CI_ID}`;
        if(!cart.length) localStorage.removeItem(key);
        else localStorage.setItem(key, JSON.stringify(cart));
    }catch(e){}
    scheduleCartSync();
}
function restoreCart(){
    try{
        const raw=localStorage.getItem(`eh2o_cart_${CID}_${CO_ID}_${CI_ID}`);
        if(!raw) return;
        const parsed=JSON.parse(raw);
        if(Array.isArray(parsed)){cart=parsed;renderCart();}
    }catch(e){}
}
function scheduleCartSync(){
    if(!CID || !CO_ID || !CI_ID) return;
    clearTimeout(cartSyncTimer);
    cartSyncTimer=setTimeout(syncCartState, 500);
}
async function syncCartState(){
    if(!CID || !CO_ID || !CI_ID) return;
    const fd=new FormData();
    fd.append('action','sync_cart');
    fd.append('company_id',CO_ID);
    fd.append('city_id',CI_ID);
    fd.append('cart', JSON.stringify(cart));
    try{ await fetch(SELF,{method:'POST',body:fd}); }catch(e){}
}
function reorderOrder(orderId){
    const order=(allOrders||[]).find(o=>Number(o.id)===Number(orderId));
    if(!order || !Array.isArray(order.items) || !order.items.length){toast('Commande indisponible','error');return;}
    order.items.forEach(it=>{
        const pid=Number(it.product_id||0);
        const p=PRODUCT_MAP[String(pid)] || PRODUCT_MAP[pid];
        if(!p || Number(p.stock||0)<=0) return;
        const qty=Math.min(Number(it.quantity||1), Number(p.stock||1));
        const ex=cart.find(c=>Number(c.product_id)===pid && c.item_type!=='pack');
        if(ex){
            ex.qty=Math.min(ex.qty+qty, Number(p.stock||1));
            const promo=PRODUCT_PROMOS[pid]||null;
            const pricing=resolveProductPromoPricing(promo, Number(p.price), ex.qty);
            ex.price=pricing.unit_price;
            ex.sub=pricing.total;
        }else{
            const promo=PRODUCT_PROMOS[pid]||null;
            const pricing=resolveProductPromoPricing(promo, Number(p.price), qty);
            cart.push({item_type:'product',promotion_id:promo?Number(promo.promotion_id||0):0,product_id:pid,product_name:p.name,price:pricing.unit_price,qty,sub:pricing.total,icon:p.icon,imageUrl:p.image_url,meta:promo?'PROMO':''});
        }
    });
    renderCart();
    resetAppliedCoupon(true);
    switchTab('shop');
    toast('Articles rechargés dans le panier','success');
}

function formatMoney(v){return new Intl.NumberFormat('fr-FR',{maximumFractionDigits:0}).format(Number(v||0));}
function calculateCartSubtotal(){
    return cart.reduce((s,i)=>s+Number(i.sub||0),0);
}
function getSelectedDeliveryZone(){
    const zoneId=Number(document.getElementById('delivery-zone')?.value||0);
    return DELIVERY_ZONES.find(z=>Number(z.id)===zoneId) || null;
}
function resetAppliedCoupon(silent=false){
    appliedCoupon=null;
    const input=document.getElementById('checkout-coupon-code');
    const box=document.getElementById('coupon-feedback');
    if(box){
        box.style.display='none';
        box.className='coupon-feedback';
        box.textContent='';
    }
    if(input && !silent){
        input.value='';
    }
}
function getPromoCountdown(endAt){
    if(!endAt) return '';
    const diff=Math.floor((new Date(endAt.replace(' ','T')).getTime()-Date.now())/1000);
    if(diff<=0) return '';
    const h=String(Math.floor(diff/3600)).padStart(2,'0');
    const m=String(Math.floor((diff%3600)/60)).padStart(2,'0');
    const s=String(diff%60).padStart(2,'0');
    return `${h}:${m}:${s}`;
}
function setPromoFilter(filter,btn){
    currentPromoFilter=filter;
    document.querySelectorAll('#promo-filters .promo-chip').forEach(el=>el.classList.toggle('on',el===btn));
    renderPromotions();
}
function setPromoSort(sort,btn){
    currentPromoSort=sort;
    document.querySelectorAll('#promo-sorts .promo-chip').forEach(el=>el.classList.toggle('on',el===btn));
    renderPromotions();
}
function resolveProductPromoPricing(promo, basePrice, qty){
    if(!promo) return {unit_price:Number(basePrice||0), total:Number(basePrice||0)*qty, old_price:Number(basePrice||0), summary:''};
    qty=Math.max(1,Number(qty||1));
    if(promo.promo_type==='quantity'){
        const buy=Number(promo.quantity_buy||0);
        const pay=Number(promo.quantity_pay||0);
        if(buy>0 && pay>0 && pay<buy && qty>=buy){
            const groups=Math.floor(qty/buy);
            const rem=qty%buy;
            const total=((groups*pay)+rem)*Number(basePrice||0);
            return {unit_price:total/qty,total,old_price:Number(basePrice||0),summary:`Achetez ${buy}, payez ${pay}`};
        }
        let discount=0;
        (promo.tiers||[]).forEach(t=>{if(qty>=Number(t.qty||0)) discount=Number(t.discount_percent||0);});
        if(discount>0){
            const total=qty*Number(basePrice||0)*(1-discount/100);
            return {unit_price:total/qty,total,old_price:Number(basePrice||0),summary:`${qty}+ = -${discount}%`};
        }
    }
    const unit=Number(promo.promo_price||basePrice||0);
    return {unit_price:unit,total:unit*qty,old_price:Number(promo.old_price||basePrice||0),summary:String(promo.summary||'')};
}
function renderPromotions(){
    const grid=document.getElementById('promo-grid');
    const totalEl=document.getElementById('promo-total-count');
    if(!grid) return;
    let rows=(PROMOTION_CAMPAIGNS||[]).map(p=>({...p,time_left:getPromoCountdown(p.ends_at)}));
    rows=rows.filter(p=>!p.ends_at || p.time_left);
    if(currentPromoFilter!=='all'){
        rows=rows.filter(p=>String(p.filter_tag||'').toLowerCase()===currentPromoFilter || String(p.promo_type||'').toLowerCase()===currentPromoFilter);
    }
    rows.sort((a,b)=>{
        if(currentPromoSort==='time'){
            const at=a.time_left?a.time_left.split(':').reduce((s,v)=>s*60+Number(v),0):999999;
            const bt=b.time_left?b.time_left.split(':').reduce((s,v)=>s*60+Number(v),0):999999;
            return at-bt;
        }
        if(currentPromoSort==='price'){
            return Number(a.promo_price||0)-Number(b.promo_price||0);
        }
        if(currentPromoSort==='popular'){
            return Number(b.stock||0)-Number(a.stock||0);
        }
        return Number(b.discount_percent||0)-Number(a.discount_percent||0);
    });
    if(totalEl) totalEl.textContent=String(rows.length);
    if(!rows.length){
        grid.innerHTML=`<div class="promo-empty"><i class="fas fa-tags" style="font-size:28px;margin-bottom:8px;display:block"></i><strong>Aucune promotion active</strong><p style="margin-top:6px">Les offres disponibles apparaîtront ici automatiquement.</p></div>`;
        return;
    }
    grid.innerHTML=rows.map(p=>{
        const media=(p.items&&p.items[0])||{};
        const img=media.image_url?`<img src="${esc(media.image_url)}" alt="${esc(p.title)}">`:esc(media.icon||'🎁');
        const badges=[
            `<span class="promo-badge main">${esc(p.badge_label||'PROMO')}</span>`,
            Number(p.discount_percent||0)>0?`<span class="promo-badge discount">-${formatMoney(p.discount_percent)}%</span>`:'',
            p.promo_type==='flash'?`<span class="promo-badge flash">Flash</span>`:'',
            p.promo_type==='pack'?`<span class="promo-badge pack">Pack</span>`:''
        ].join('');
        const packList=(p.items||[]).map(it=>`<li><span>${formatMoney(it.quantity)} x ${esc(it.product_name)}</span><strong>${formatMoney(it.product_price)} CFA</strong></li>`).join('');
        const timer=(p.promo_type==='flash' && p.time_left)?`<div class="promo-meta-line"><span class="promo-timer"><i class="fas fa-bolt"></i> Se termine dans : ${p.time_left}</span></div>`:'';
        const summary=p.promo_type==='quantity'
            ? `<div class="promo-card-sub">${esc(p.summary || 'Offre quantité')}</div>`
            : `<div class="promo-card-sub">${esc(p.subtitle || 'Offre spéciale')}</div>`;
        const actionDisabled=Number(p.stock||0)<=0;
        return `<div class="promo-card ${actionDisabled?'promo-disabled':''}">
            <div class="promo-card-head">
              <div class="promo-card-media">${img}</div>
              <div style="flex:1;min-width:0">
                <div class="promo-badges">${badges}</div>
                <div class="promo-card-title">${esc(p.title)}</div>
                ${summary}
              </div>
            </div>
            <div class="promo-card-body">
              ${Number(p.old_price||0)>0?`<div class="promo-price-old">Ancien prix : ${formatMoney(p.old_price)} CFA</div>`:''}
              <div class="promo-price-new">Prix promo : ${formatMoney(p.promo_price)} <small style="font-size:12px;color:var(--text2)">CFA</small></div>
              ${p.promo_type==='pack' ? `<ul class="promo-pack-list">${packList}</ul>` : ''}
              ${timer}
              <div class="promo-meta-line">
                <span class="promo-stock">Stock : ${formatMoney(p.stock)}</span>
                <span style="color:var(--muted)">${esc(p.promo_type==='pack'?'Pack promo':'Produit promo')}</span>
              </div>
              <div class="promo-actions">
                <div class="promo-qty">
                  <button class="qbtn" onclick="promoQtyDec(${p.id})"><i class="fas fa-minus" style="font-size:9px"></i></button>
                  <input type="number" class="qin" id="promo-qty-${p.id}" value="1" min="1" max="${Math.max(1, Number(p.stock||1))}" style="width:52px">
                  <button class="qbtn" onclick="promoQtyInc(${p.id},${Math.max(1, Number(p.stock||1))})"><i class="fas fa-plus" style="font-size:9px"></i></button>
                </div>
                <button class="badd" ${actionDisabled?'disabled':''} onclick="addPromotionToCart(${p.id})"><i class="fas fa-cart-plus"></i> ${actionDisabled?'EXPIRÉE':'AJOUTER'}</button>
              </div>
            </div>
          </div>`;
    }).join('');
}
function promoQtyDec(id){const el=document.getElementById('promo-qty-'+id);if(el && Number(el.value)>1)el.value=Number(el.value)-1;}
function promoQtyInc(id,max){const el=document.getElementById('promo-qty-'+id);if(el && Number(el.value)<Number(max||1))el.value=Number(el.value)+1;}
function addPromotionToCart(promotionId){
    const promo=(PROMOTION_CAMPAIGNS||[]).find(p=>Number(p.id)===Number(promotionId));
    if(!promo){toast('Promotion introuvable','error');return;}
    if(!CID){toast("Identifiez-vous d'abord !",'warn');return;}
    const qty=Math.max(1,Number(document.getElementById('promo-qty-'+promotionId)?.value||1));
    if(qty>Number(promo.stock||0)){toast('Stock promotion insuffisant','error');return;}
    if(promo.promo_type==='pack'){
        cart.push({
            item_type:'pack',
            promotion_id:Number(promo.id),
            product_id:0,
            product_name:promo.title,
            price:Number(promo.promo_price||0),
            qty,
            sub:Number(promo.promo_price||0)*qty,
            icon:'🎁',
            imageUrl:(promo.items&&promo.items[0]&&promo.items[0].image_url)||'',
            meta:'PACK PROMO'
        });
    }else{
        const first=promo.items&&promo.items[0]?promo.items[0]:null;
        if(!first){toast('Produit promotionnel introuvable','error');return;}
        const basePrice=Number(first.product_price||promo.old_price||promo.promo_price||0);
        const pricing=resolveProductPromoPricing(PRODUCT_PROMOS[first.product_id]||promo, basePrice, qty);
        const ex=cart.find(i=>i.item_type!=='pack' && Number(i.product_id)===Number(first.product_id));
        if(ex){
            const nextQty=ex.qty+qty;
            if(nextQty>Number(promo.stock||0)){toast('Quantité max atteinte !','warn');return;}
            const nextPricing=resolveProductPromoPricing(PRODUCT_PROMOS[first.product_id]||promo, basePrice, nextQty);
            ex.qty=nextQty;ex.price=nextPricing.unit_price;ex.sub=nextPricing.total;ex.meta='PROMO';
        }else{
            cart.push({
                item_type:'product',
                promotion_id:Number(promo.id),
                product_id:Number(first.product_id),
                product_name:first.product_name,
                price:pricing.unit_price,
                qty,
                sub:pricing.total,
                icon:first.icon||'🎁',
                imageUrl:first.image_url||'',
                meta:'PROMO'
            });
        }
    }
    renderCart();
    resetAppliedCoupon(true);
    toast(promo.title+' ajouté !','success');
}

/* ── Cart ── */
function addToCart(pid,pname,price,maxStock,icon,imageUrl=''){
    if(!CID){toast('Identifiez-vous d\'abord !','warn');return;}
    const qty=+(document.getElementById('qty-'+pid)?.value||1);
    if(qty>maxStock){toast('Stock insuffisant','error');return;}
    const promo=PRODUCT_PROMOS[pid]||null;
    const pricing=resolveProductPromoPricing(promo, price, qty);
    const ex=cart.find(i=>i.product_id===pid);
    if(ex){
        if(ex.qty+qty>maxStock){toast('Quantité max atteinte !','warn');return;}
        ex.qty+=qty;
        const updatedPricing=resolveProductPromoPricing(promo, price, ex.qty);
        ex.price=updatedPricing.unit_price;
        ex.sub=updatedPricing.total;
        ex.meta=promo?'PROMO':'';
        ex.promotion_id=promo?Number(promo.promotion_id||0):0;
    } else {
        cart.push({item_type:'product',promotion_id:promo?Number(promo.promotion_id||0):0,product_id:pid,product_name:pname,price:pricing.unit_price,qty,sub:pricing.total,icon,imageUrl,meta:promo?'PROMO':''});
    }
    document.getElementById('qty-'+pid).value=1;
    renderCart();
    resetAppliedCoupon(true);
    toast(pname+' ajouté !','success');
    if(!isOpen){isOpen=true;document.getElementById('cfloat').classList.remove('mini');}
}

function renderCart(){
    const body=document.getElementById('cart-body');
    const foot=document.getElementById('cart-foot');
    const cnt=document.getElementById('cart-cnt');
    const tot=document.getElementById('cart-tot');
    if(!cart.length){
        persistCart();
        body.innerHTML='<div class="cempty"><i class="fas fa-shopping-basket"></i><p style="font-family:var(--fh);font-size:10px;font-weight:900">Panier vide</p></div>';
        foot.style.display='none';cnt.textContent='0';return;
    }
    let html='',total=0,totalQty=0;
    cart.forEach((item,idx)=>{
        total+=item.sub;totalQty+=item.qty;
        html+=`<div class="ci"><div class="ciico">${item.imageUrl?`<img src="${esc(item.imageUrl)}" alt="${esc(item.product_name)}" style="width:100%;height:100%;object-fit:cover;border-radius:9px">`:item.icon}</div><div class="ciinf"><div class="ciname">${item.product_name}</div><div class="cisub">${item.qty} × ${item.price.toLocaleString('fr-FR')}${item.meta?` • <span style="color:var(--gold)">${esc(item.meta)}</span>`:''}</div></div><div class="ciprice">${item.sub.toLocaleString('fr-FR')}</div><button class="cid" onclick="removeFromCart(${idx})">×</button></div>`;
    });
    body.innerHTML=html;foot.style.display='block';cnt.textContent=totalQty;
    tot.textContent=total.toLocaleString('fr-FR')+' CFA';
    persistCart();
}

function removeFromCart(idx){const n=cart[idx].product_name;cart.splice(idx,1);renderCart();resetAppliedCoupon(true);toast(n+' retiré','success');}
function toggleCart(){isOpen=!isOpen;document.getElementById('cfloat').classList.toggle('mini',!isOpen);}

/* ── Checkout ── */
function openCheckout(state={}){
    if(!cart.length){toast('Panier vide !','warn');return;}
    if(!CID){toast('Identifiez-vous d\'abord !','warn');return;}
    const currentAddr=state.addr ?? document.getElementById('del-addr')?.value ?? '';
    const currentPay=state.pay ?? document.getElementById('pay-meth')?.value ?? 'cash';
    const currentNotes=state.notes ?? document.getElementById('ord-notes')?.value ?? '';
    const currentCode=state.couponCode ?? document.getElementById('checkout-coupon-code')?.value ?? appliedCoupon?.code ?? '';
    const currentZoneId=String(state.zoneId ?? document.getElementById('delivery-zone')?.value ?? '');
    const subtotal=calculateCartSubtotal();
    const discount=Number(appliedCoupon?.discount_amount||0);
    const selectedZone=DELIVERY_ZONES.find(z=>String(z.id)===String(currentZoneId)) || null;
    const deliveryFee=Number(selectedZone?.delivery_fee||0);
    const total=Math.max(0, subtotal-discount+deliveryFee);
    const zoneOptions=DELIVERY_ZONES.map(z=>`<option value="${z.id}" ${String(currentZoneId)===String(z.id)?'selected':''}>${esc(z.zone_name)} · ${esc(z.delivery_delay_label)} · ${formatMoney(z.delivery_fee)} CFA</option>`).join('');
    let rows=cart.map(i=>`
        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:6px 9px;border-radius:8px;border:1px solid rgba(255,255,255,.04);background:rgba(0,0,0,.12);margin-bottom:5px">
          <div style="display:flex;align-items:center;gap:7px">${i.imageUrl?`<img src="${esc(i.imageUrl)}" alt="${esc(i.product_name)}" style="width:26px;height:26px;border-radius:8px;object-fit:cover;border:1px solid rgba(255,255,255,.08)">`:`<span>${i.icon}</span>`}<span style="font-family:var(--fh);font-size:11px;font-weight:900;color:var(--text)">${i.product_name}${i.meta?` <small style="color:var(--gold)">${esc(i.meta)}</small>`:''}</span></div>
          <span style="font-family:var(--fh);font-size:10px;font-weight:700;color:var(--muted)">× ${i.qty}</span>
          <span style="font-family:var(--fh);font-size:11px;font-weight:900;color:var(--neon)">${i.sub.toLocaleString('fr-FR')}</span>
        </div>`).join('');
    document.getElementById('co-body').innerHTML=`
        <div style="margin-bottom:12px">${rows}</div>
        <div class="fg">
          <label>Coupon personnalisé</label>
          <div style="display:flex;gap:8px;align-items:center">
            <input type="text" id="checkout-coupon-code" placeholder="Ex: RECOV-AB12CD" value="${esc(currentCode)}" style="text-transform:uppercase">
            <button type="button" onclick="applyCheckoutCoupon()" class="btn btn-g"><i class="fas fa-ticket-alt"></i> Appliquer</button>
          </div>
          <div id="coupon-feedback" class="coupon-feedback" style="display:${appliedCoupon?'block':'none'};margin-top:8px;color:${appliedCoupon?'var(--neon)':'var(--muted)'}">${appliedCoupon?`Coupon ${esc(appliedCoupon.code)} appliqué: -${formatMoney(discount)} CFA`:''}</div>
        </div>
        <div style="background:linear-gradient(135deg,rgba(50,190,143,.14),rgba(6,182,212,.09));border:2px solid rgba(50,190,143,.3);border-radius:12px;padding:14px;text-align:center;margin-bottom:12px">
          <div style="font-family:var(--fh);font-size:10px;font-weight:700;color:var(--muted);margin-bottom:5px">Sous-total : ${formatMoney(subtotal)} CFA${discount>0?` · Remise coupon : -${formatMoney(discount)} CFA`:''}${deliveryFee>0?` · Livraison : ${formatMoney(deliveryFee)} CFA`:''}</div>
          <div style="font-family:var(--fh);font-size:10px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:2px;margin-bottom:4px">Total à payer</div>
          <div style="font-family:var(--fh);font-size:26px;font-weight:900;color:var(--neon)">${total.toLocaleString('fr-FR')} <small style="font-size:12px;color:var(--muted)">CFA</small></div>
        </div>
        <div class="fg"><label>Zone de livraison</label><select id="delivery-zone" onchange="refreshCheckoutTotals()"><option value="">Choisir une zone</option>${zoneOptions}</select>${selectedZone?`<div style="margin-top:6px;font-size:10px;color:var(--muted)">${esc(selectedZone.notes||'')}</div>`:''}</div>
        <div class="fg"><label>Adresse de livraison *</label><textarea id="del-addr" placeholder="Adresse complète, quartier, repère…">${esc(currentAddr)}</textarea></div>
        <div class="fg"><label>Mode de paiement</label><select id="pay-meth"><option value="cash" ${currentPay==='cash'?'selected':''}>💵 Espèces</option><option value="mobile_money" ${currentPay==='mobile_money'?'selected':''}>📱 Mobile Money</option></select></div>
        <div class="fg"><label>Instructions (optionnel)</label><textarea id="ord-notes" placeholder="Ex : Appeler avant la livraison…">${esc(currentNotes)}</textarea></div>
        <button onclick="submitOrder()" class="btn btn-solid btn-full" id="btn-order"><i class="fas fa-truck"></i> CONFIRMER ET ENVOYER</button>`;
    document.getElementById('co-modal').classList.add('show');
}
function closeCo(){document.getElementById('co-modal').classList.remove('show');}
function refreshCheckoutTotals(){
    openCheckout({
        addr: document.getElementById('del-addr')?.value ?? '',
        pay: document.getElementById('pay-meth')?.value ?? 'cash',
        notes: document.getElementById('ord-notes')?.value ?? '',
        couponCode: document.getElementById('checkout-coupon-code')?.value ?? appliedCoupon?.code ?? '',
        zoneId: document.getElementById('delivery-zone')?.value ?? '',
    });
}

async function applyCheckoutCoupon(){
    const input=document.getElementById('checkout-coupon-code');
    const box=document.getElementById('coupon-feedback');
    const state={
        addr: document.getElementById('del-addr')?.value ?? '',
        pay: document.getElementById('pay-meth')?.value ?? 'cash',
        notes: document.getElementById('ord-notes')?.value ?? '',
        couponCode: document.getElementById('checkout-coupon-code')?.value ?? '',
    };
    const code=(input?.value||'').trim().toUpperCase();
    if(!code){resetAppliedCoupon();toast('Code coupon requis','warn');return;}
    const payload=cart.map(i=>({item_type:i.item_type||'product',promotion_id:i.promotion_id||0,product_id:i.product_id,product_name:i.product_name,quantity:i.qty,unit_price:i.price,subtotal:i.sub}));
    const fd=new FormData();
    fd.append('action','validate_coupon');
    fd.append('coupon_code',code);
    fd.append('company_id',CO_ID);
    fd.append('city_id',CI_ID);
    fd.append('items',JSON.stringify(payload));
    try{
        const res=await fetch(SELF,{method:'POST',body:fd});
        const data=await res.json();
        if(data.success){
            appliedCoupon=data.coupon||null;
            if(box){
                box.style.display='block';
                box.className='coupon-feedback';
                box.style.color='var(--neon)';
                box.textContent=`Coupon ${data.coupon.code} appliqué : -${formatMoney(data.coupon.discount_amount)} CFA`;
            }
            toast('Coupon appliqué','success');
            openCheckout(state);
        }else{
            appliedCoupon=null;
            if(box){
                box.style.display='block';
                box.className='coupon-feedback';
                box.style.color='var(--red)';
                box.textContent=data.message||'Coupon invalide';
            }
            toast(data.message||'Coupon invalide','error');
        }
    }catch(e){toast('Erreur réseau','error');}
}

async function submitOrder(){
    const addr=document.getElementById('del-addr')?.value.trim();
    const pay=document.getElementById('pay-meth')?.value||'cash';
    const notes=document.getElementById('ord-notes')?.value.trim()||'';
    const deliveryZoneId=document.getElementById('delivery-zone')?.value||'';
    if(!addr){toast('Adresse obligatoire !','warn');return;}
    const btn=document.getElementById('btn-order');
    btn.disabled=true;btn.innerHTML='<div class="sp"></div> Envoi en cours…';
    const payload=cart.map(i=>({item_type:i.item_type||'product',promotion_id:i.promotion_id||0,product_id:i.product_id,product_name:i.product_name,quantity:i.qty,unit_price:i.price,subtotal:i.sub}));
    const fd=new FormData();
    fd.append('action','create_order');fd.append('company_id',CO_ID);fd.append('city_id',CI_ID);
    fd.append('client_id',CID);fd.append('delivery_address',addr);fd.append('payment_method',pay);
    fd.append('notes',notes);fd.append('items',JSON.stringify(payload));
    fd.append('delivery_zone_id',deliveryZoneId);
    fd.append('coupon_code', appliedCoupon?.code || (document.getElementById('checkout-coupon-code')?.value.trim().toUpperCase()||''));
    try{
        const res=await fetch(SELF,{method:'POST',body:fd});
        const data=await res.json();
        btn.disabled=false;btn.innerHTML='<i class="fas fa-truck"></i> CONFIRMER ET ENVOYER';
        if(data.success){
            latestConfirmedOrderId = +data.order_id || 0;
            latestConfirmedOrderNumber = data.order_number || '';
            appliedCoupon=null;
            cart=[];renderCart();closeCo();
            showSuccess(data.order_number, data.order_id);
            allOrders=[];// force reload
            updateNotifBadge(unreadCount+1);
        }else{toast(data.message||'Erreur inconnue','error');}
    }catch(err){btn.disabled=false;btn.innerHTML='<i class="fas fa-truck"></i> CONFIRMER ET ENVOYER';toast('Erreur réseau','error');}
}

function resetSuccessOverlay(){
    clearTimeout(successCloseTimer);
    clearInterval(successCountdownTimer);
    clearTimeout(successStageTimer);
    if('speechSynthesis' in window){
        window.speechSynthesis.cancel();
    }
    const loader=document.getElementById('success-loader');
    const main=document.getElementById('success-main');
    const hint=document.getElementById('success-close-hint');
    if(loader) loader.classList.remove('hidden');
    if(main) main.classList.remove('show');
    if(hint) hint.textContent='Fermeture automatique dans 10s';
    document.querySelectorAll('#success-timeline .tl-pro-step').forEach((step,idx)=>{
        step.classList.remove('done','current');
        if(idx===0) step.classList.add('current');
    });
}

function triggerConfetti(){
    const layer=document.getElementById('confetti-layer');
    if(!layer) return;
    layer.innerHTML='';
    const colors=['#7adfff','#3d8cff','#32be8f','#aef6ff','#90d4ff','#baf7ea'];
    for(let i=0;i<34;i++){
        const piece=document.createElement('span');
        piece.className='confetti-piece';
        piece.style.left=Math.random()*100+'%';
        piece.style.background=colors[i%colors.length];
        piece.style.animationDelay=(Math.random()*0.45)+'s';
        piece.style.setProperty('--tx', ((Math.random()*2-1)*180).toFixed(0)+'px');
        piece.style.transform=`translateY(0) rotate(${Math.random()*180}deg)`;
        layer.appendChild(piece);
    }
    setTimeout(()=>{layer.innerHTML='';},3200);
}

function playConfirmationDing(){
    try{
        const Ctx=window.AudioContext||window.webkitAudioContext;
        if(!Ctx) return;
        const ctx=new Ctx();
        const osc=ctx.createOscillator();
        const gain=ctx.createGain();
        osc.type='sine';
        osc.frequency.setValueAtTime(880,ctx.currentTime);
        osc.frequency.exponentialRampToValueAtTime(1320,ctx.currentTime+0.18);
        gain.gain.setValueAtTime(0.0001,ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.18,ctx.currentTime+0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001,ctx.currentTime+0.55);
        osc.connect(gain); gain.connect(ctx.destination);
        osc.start();
        osc.stop(ctx.currentTime+0.6);
    }catch(e){}
}

function speakConfirmation(){
    if(!('speechSynthesis' in window)) return;
    const text="Votre commande a été bien reçue. Elle sera traitée dans les plus brefs délais. Esperance H2O vous remercie.";
    const speakNow=()=>{
        try{
            window.speechSynthesis.cancel();
            successSpeechUtterance = new SpeechSynthesisUtterance(text);
            successSpeechUtterance.lang='fr-FR';
            successSpeechUtterance.rate=0.95;
            successSpeechUtterance.pitch=1;
            successSpeechUtterance.volume=1;
            const voices=window.speechSynthesis.getVoices()||[];
            const fr=voices.find(v=>/^fr/i.test(v.lang));
            if(fr) successSpeechUtterance.voice=fr;
            window.speechSynthesis.speak(successSpeechUtterance);
        }catch(e){}
    };
    speakNow();
    if(window.speechSynthesis.getVoices().length===0){
        window.speechSynthesis.onvoiceschanged = () => speakNow();
    }
}

function animateSuccessTimeline(){
    const steps=[...document.querySelectorAll('#success-timeline .tl-pro-step')];
    let idx=0;
    const advance=()=>{
        steps.forEach((step,i)=>{
            step.classList.toggle('done', i<idx);
            step.classList.toggle('current', i===idx);
        });
        idx++;
        if(idx<steps.length){
            successStageTimer=setTimeout(advance, 1800);
        }else{
            steps.forEach(step=>step.classList.remove('current'));
            steps.forEach(step=>step.classList.add('done'));
        }
    };
    successStageTimer=setTimeout(advance, 1400);
}

function startSuccessAutoClose(){
    let remaining=20;
    const hint=document.getElementById('success-close-hint');
    if(hint) hint.textContent=`Fermeture automatique dans ${remaining}s`;
    clearInterval(successCountdownTimer);
    successCountdownTimer=setInterval(()=>{
        remaining--;
        if(hint && remaining>=0) hint.textContent=`Fermeture automatique dans ${remaining}s`;
    },1000);
    clearTimeout(successCloseTimer);
    successCloseTimer=setTimeout(()=>closeSuccess(false),20000);
}

function showSuccess(orderNumber, orderId){
    resetSuccessOverlay();
    latestConfirmedOrderId = +orderId || latestConfirmedOrderId;
    latestConfirmedOrderNumber = orderNumber || latestConfirmedOrderNumber;
    document.getElementById('s-order-num').textContent=orderNumber;
    document.getElementById('success-overlay').classList.add('show');
    document.body.style.overflow='hidden';
    playConfirmationDing();
    triggerConfetti();
    setTimeout(()=>{
        document.getElementById('success-loader')?.classList.add('hidden');
        document.getElementById('success-main')?.classList.add('show');
        animateSuccessTimeline();
        speakConfirmation();
        startSuccessAutoClose();
    },1400);
}
function closeSuccess(manualOk){
    document.getElementById('success-overlay').classList.remove('show');
    document.body.style.overflow='';
    clearTimeout(successCloseTimer);
    clearInterval(successCountdownTimer);
    clearTimeout(successStageTimer);
    if('speechSynthesis' in window){
        window.speechSynthesis.cancel();
    }
}
function closeSuccessGoOrders(){closeSuccess(true);switchTab('orders');}
function viewConfirmedOrder(){
    switchTab('orders');
    closeNotifPanel();
    clearTimeout(successCloseTimer);
    if(latestConfirmedOrderId){
        setTimeout(()=>{
            openDetail(latestConfirmedOrderId);
        },500);
    }
}
function continueShoppingFromSuccess(){
    clearTimeout(successCloseTimer);
    const hint=document.getElementById('success-close-hint');
    if(hint) hint.textContent="Popup active. Appuyez sur 'OK j'ai compris' pour fermer.";
    switchTab('shop');
}

/* ── Load Orders ── */
async function loadOrders(force=false){
    const btn=document.getElementById('refresh-btn');
    if(btn){btn.disabled=true;btn.innerHTML='<div class="sp"></div>';}
    if(allOrders.length&&!force){renderOrders(allOrders);if(btn){btn.disabled=false;btn.innerHTML='<i class="fas fa-sync-alt"></i> Actualiser';}return;}
    const fd=new FormData();fd.append('action','get_orders');
    try{
        const res=await fetch(SELF,{method:'POST',body:fd});
        const data=await res.json();
        if(btn){btn.disabled=false;btn.innerHTML='<i class="fas fa-sync-alt"></i> Actualiser';}
        if(data.success){
            allOrders=data.orders||[];
            updateOrderStats(allOrders);
            renderOrders(allOrders);
        }else{document.getElementById('orders-list').innerHTML=`<div class="empty"><i class="fas fa-exclamation-triangle"></i><h3>Erreur</h3><p>${data.message}</p></div>`;}
    }catch(e){
        if(btn){btn.disabled=false;btn.innerHTML='<i class="fas fa-sync-alt"></i> Actualiser';}
        document.getElementById('orders-list').innerHTML='<div class="empty"><i class="fas fa-wifi" style="opacity:.1"></i><h3>Erreur réseau</h3></div>';
    }
}

function updateOrderStats(orders){
    const statBar=document.getElementById('orders-stats');
    const filterEl=document.getElementById('filter-tabs');
    if(orders.length){
        statBar.style.display='grid';
        filterEl.style.display='flex';
    }
    const active=orders.filter(o=>['pending','confirmed','delivering'].includes(o.status)).length;
    const spent=orders.filter(o=>o.status==='done').reduce((s,o)=>s+(+o.total_amount),0);
    document.getElementById('st-total').textContent=orders.length;
    document.getElementById('st-active').textContent=active;
    document.getElementById('st-spent').textContent=spent>999999?Math.round(spent/1000)+'k':Math.round(spent/1000)+'k';
    const tc=document.getElementById('orders-tab-count');
    if(orders.length){tc.style.display='flex';tc.textContent=orders.length;}
    /* ── Android nav badge ── */
    const nb=document.getElementById('nav-badge-orders');
    if(nb){
        const pending=orders.filter(o=>['pending','confirmed','delivering'].includes(o.status)).length;
        if(pending>0){nb.textContent=pending;nb.classList.remove('hidden');}
        else{nb.classList.add('hidden');}
    }
}

function filterOrders(filter,btn){
    currentFilter=filter;
    document.querySelectorAll('.ftab').forEach(b=>b.classList.remove('on'));
    btn.classList.add('on');
    renderOrders(allOrders);
}

function renderOrders(orders){
    const list=document.getElementById('orders-list');
    const filtered=currentFilter==='all'?orders:orders.filter(o=>o.status===currentFilter);
    if(!filtered.length){
        list.innerHTML=`<div class="empty"><i class="fas fa-box-open"></i><h3>${currentFilter==='all'?'Aucune commande':'Aucune commande ici'}</h3>
            <p>${currentFilter==='all'?'Vous n\'avez pas encore commandé':'Essayez un autre filtre'}</p>
            ${currentFilter==='all'?'<button class="btn btn-solid" onclick="switchTab(\'shop\')" style="margin-top:14px"><i class="fas fa-shopping-cart"></i> Commander</button>':''}</div>`;
        return;
    }
    list.innerHTML=filtered.map((order,i)=>buildOrderCard(order,i)).join('');
}

/* ── STATUS CONFIG ── */
const STATUS_CFG={
    pending:   {label:'En attente',  cls:'bdg-g', ico:'fa-clock',      color:'var(--gold)'},
    confirmed: {label:'Confirmée',   cls:'bdg-c', ico:'fa-check',      color:'var(--cyan)'},
    delivering:{label:'En livraison',cls:'bdg-n', ico:'fa-truck',      color:'var(--neon)'},
    done:      {label:'Livrée ✓',    cls:'bdg-n', ico:'fa-circle-check',color:'var(--neon)'},
    cancelled: {label:'Annulée',     cls:'bdg-r', ico:'fa-times-circle',color:'var(--red)'},
};
const TIMELINE_STEPS=[
    {key:'pending',   label:'Reçue',    ico:'fa-inbox'},
    {key:'confirmed', label:'Confirmée',ico:'fa-check'},
    {key:'delivering',label:'Livraison',ico:'fa-truck'},
    {key:'done',      label:'Livrée',   ico:'fa-house'},
];
const STATUS_ORDER={pending:0,confirmed:1,delivering:2,done:3,cancelled:-1};

function buildTimeline(status){
    if(status==='cancelled') return `
        <div style="text-align:center;padding:12px 0">
          <div style="display:inline-flex;align-items:center;gap:7px;padding:7px 14px;border-radius:10px;background:rgba(255,53,83,.1);border:1px solid rgba(255,53,83,.2)">
            <i class="fas fa-times-circle" style="color:var(--red)"></i>
            <span style="font-family:var(--fh);font-size:11px;font-weight:900;color:var(--red)">Commande annulée</span>
          </div>
        </div>`;
    const cur=STATUS_ORDER[status]??0;
    let html='<div class="timeline">';
    TIMELINE_STEPS.forEach((step,i)=>{
        const stepIdx=STATUS_ORDER[step.key];
        let dotClass='';
        if(stepIdx<cur) dotClass='done';
        else if(stepIdx===cur) dotClass='current';
        html+=`<div class="tl-step">
            <div class="tl-dot ${dotClass}"><i class="fas ${step.ico}" style="font-size:9px"></i></div>
            <div class="tl-lbl ${dotClass}">${step.label}</div>
        </div>`;
        if(i<TIMELINE_STEPS.length-1){
            html+=`<div style="flex:1;height:2px;background:${stepIdx<cur?'var(--neon)':'rgba(255,255,255,.06)'};margin-top:-18px;position:relative;z-index:0"></div>`;
        }
    });
    html+='</div>';
    return html;
}

function buildOrderCard(order,delay){
    const cfg=STATUS_CFG[order.status]||STATUS_CFG.pending;
    const items=order.items||[];
    const canCancel=['pending','confirmed'].includes(order.status);
    const couponInfo=order.coupon_code && Number(order.coupon_discount||0)>0
        ? `<div class="meta-pill" style="color:var(--gold);border-color:rgba(255,208,96,.18)"><i class="fas fa-ticket-alt"></i> Coupon ${esc(order.coupon_code)} · -${(+order.coupon_discount).toLocaleString('fr-FR')} CFA</div>`
        : '';
    const itemsHtml=items.slice(0,3).map(it=>{
        const ico=(it.product_name.toLowerCase().includes('eau')||it.product_name.toLowerCase().includes('water'))?'💧':'🫙';
        return `<div class="item-row"><div class="ir-ico">${it.image_url?`<img src="${esc(it.image_url)}" alt="${esc(it.product_name)}" style="width:100%;height:100%;object-fit:cover;border-radius:9px">`:ico}</div><div class="ir-name">${it.product_name}</div><div class="ir-qty">× ${it.quantity}</div><div class="ir-price">${(+it.subtotal).toLocaleString('fr-FR')}</div></div>`;
    }).join('');
    const moreItems=items.length>3?`<div style="font-family:var(--fh);font-size:9px;color:var(--muted);text-align:center;padding:4px">+ ${items.length-3} autres articles</div>`:'';
    const dateStr=new Date(order.created_at.replace(' ','T')).toLocaleString('fr-FR',{day:'2-digit',month:'2-digit',year:'2-digit',hour:'2-digit',minute:'2-digit'});
    const payIco=order.payment_method==='mobile_money'?'📱':'💵';
    const waActions=`<div class="wa-actions">
        <a class="wa-pill" href="${buildOrderWhatsAppLink(order,'track')}" target="_blank" rel="noopener" onclick="logWhatsAppClick('track',${Number(order.id||0)})"><i class="fab fa-whatsapp"></i> Suivre</a>
        ${canCancel?`<a class="wa-pill" href="${buildOrderWhatsAppLink(order,'edit')}" target="_blank" rel="noopener" onclick="logWhatsAppClick('edit',${Number(order.id||0)})"><i class="fab fa-whatsapp"></i> Modifier</a>`:''}
        <a class="wa-pill" href="${buildOrderWhatsAppLink(order,'issue')}" target="_blank" rel="noopener" onclick="logWhatsAppClick('issue',${Number(order.id||0)})"><i class="fab fa-whatsapp"></i> Signaler</a>
      </div>`;
    return `<div class="order-card" style="animation-delay:${delay*0.04}s">
        <div class="oc-header" onclick="toggleOC(${order.id})">
          <div class="oc-top">
            <div>
              <div class="oc-num">🧾 ${order.order_number}</div>
              <div class="oc-date"><i class="fas fa-calendar" style="font-size:7px"></i> ${dateStr}</div>
            </div>
            <div class="oc-right">
              <div class="oc-total">${(+order.total_amount).toLocaleString('fr-FR')} <small>CFA</small></div>
            </div>
          </div>
          <div class="oc-status">
            <span class="bdg ${cfg.cls}"><i class="fas ${cfg.ico}"></i> ${cfg.label}</span>
            <span style="font-family:var(--fh);font-size:9px;color:var(--muted)">${payIco} ${order.payment_method==='mobile_money'?'Mobile Money':'Espèces'} <i class="fas fa-chevron-down" style="font-size:8px;margin-left:4px"></i></span>
          </div>
        </div>
        <div class="oc-body" id="ocb-${order.id}" style="display:none">
          <div class="oc-section-title">Suivi de commande</div>
          ${buildTimeline(order.status)}
          <div class="oc-section-title" style="margin-top:10px">Articles commandés</div>
          ${itemsHtml}${moreItems}
          <div class="order-meta">
            ${order.delivery_address?`<div class="meta-pill addr"><i class="fas fa-location-dot"></i> ${order.delivery_address}</div>`:''}
            ${order.delivery_zone_name?`<div class="meta-pill"><i class="fas fa-map-marked-alt"></i> ${order.delivery_zone_name}</div>`:''}
            ${Number(order.delivery_fee||0)>0?`<div class="meta-pill"><i class="fas fa-coins"></i> Livraison ${(+order.delivery_fee).toLocaleString('fr-FR')} CFA</div>`:''}
            ${order.city_name?`<div class="meta-pill"><i class="fas fa-city"></i> ${order.city_name}</div>`:''}
            ${couponInfo}
            ${order.notes?`<div class="meta-pill"><i class="fas fa-sticky-note"></i> ${order.notes}</div>`:''}
          </div>
          ${waActions}
          <div style="display:flex;gap:7px;margin-top:12px">
            <button onclick="openDetail(${order.id})" class="btn btn-g" style="flex:1;font-size:10px;padding:9px">
              <i class="fas fa-eye"></i> Voir détail
            </button>
            <button onclick="reorderOrder(${order.id})" class="btn btn-n" style="flex:1;font-size:10px;padding:9px">
              <i class="fas fa-rotate-right"></i> Recommander
            </button>
            ${canCancel?`<button onclick="askCancel(${order.id},'${order.order_number}')" class="btn btn-r" style="flex:1;font-size:10px;padding:9px">
              <i class="fas fa-ban"></i> Annuler
            </button>`:''}
          </div>
        </div>
    </div>`;
}

function toggleOC(id){
    const body=document.getElementById('ocb-'+id);
    if(!body)return;
    const isVis=body.style.display!=='none';
    body.style.display=isVis?'none':'block';
}

/* ── Cancel ── */
function askCancel(orderId,orderNum){
    cancelOrderId=orderId;
    document.getElementById('confirm-sub').textContent=`La commande ${orderNum} sera annulée définitivement. Cette action est irréversible.`;
    document.getElementById('confirm-modal').classList.add('show');
}
function closeConfirm(){
    cancelOrderId=null;
    document.getElementById('confirm-modal').classList.remove('show');
}

async function doCancelOrder(){
    if(!cancelOrderId) return;
    const btn=document.getElementById('confirm-yes-btn');
    btn.disabled=true;btn.innerHTML='<div class="sp"></div>';
    const fd=new FormData();
    fd.append('action','cancel_order');fd.append('order_id',cancelOrderId);
    try{
        const res=await fetch(SELF,{method:'POST',body:fd});
        const data=await res.json();
        btn.disabled=false;btn.innerHTML='<i class="fas fa-check"></i> Oui, annuler';
        closeConfirm();
        if(data.success){
            toast('Commande annulée','success');
            allOrders=[];loadOrders(true);
            updateNotifBadge(unreadCount+1);
        }else{toast(data.message||'Impossible d\'annuler','error');}
    }catch(e){
        btn.disabled=false;btn.innerHTML='<i class="fas fa-check"></i> Oui, annuler';
        closeConfirm();toast('Erreur réseau','error');
    }
}

/* ── Order Detail ── */
async function openDetail(orderId){
    document.getElementById('detail-modal').classList.add('show');
    document.getElementById('detail-body').innerHTML='<div class="empty"><i class="fas fa-spinner fa-spin" style="opacity:.4;font-size:28px"></i></div>';
    const fd=new FormData();fd.append('action','get_order_detail');fd.append('order_id',orderId);
    try{
        const res=await fetch(SELF,{method:'POST',body:fd});
        const data=await res.json();
        if(data.success){renderDetail(data.order);}
        else{document.getElementById('detail-body').innerHTML=`<div class="empty"><p>${data.message}</p></div>`;}
    }catch(e){document.getElementById('detail-body').innerHTML='<div class="empty"><p>Erreur réseau</p></div>';}
}
function closeDetail(){document.getElementById('detail-modal').classList.remove('show');}

function renderDetail(order){
    const cfg=STATUS_CFG[order.status]||STATUS_CFG.pending;
    const items=order.items||[];
    const total=(+order.total_amount).toLocaleString('fr-FR');
    const couponDiscount=+order.coupon_discount||0;
    const dateStr=new Date(order.created_at.replace(' ','T')).toLocaleString('fr-FR',{weekday:'long',day:'2-digit',month:'long',year:'numeric',hour:'2-digit',minute:'2-digit'});
    const waTrackLink=buildOrderWhatsAppLink(order,'track');
    const waEditLink=buildOrderWhatsAppLink(order,'edit');
    const waIssueLink=buildOrderWhatsAppLink(order,'issue');
    const itemsHtml=items.map(it=>{
        const ico=(it.product_name.toLowerCase().includes('eau')||it.product_name.toLowerCase().includes('water'))?'💧':'🫙';
        const sub=(+it.subtotal).toLocaleString('fr-FR');
        const up=(+it.unit_price).toLocaleString('fr-FR');
        return `<div style="display:flex;align-items:center;gap:8px;padding:8px;border-radius:9px;background:rgba(0,0,0,.15);border:1px solid rgba(255,255,255,.05);margin-bottom:6px">
            ${it.image_url?`<img src="${esc(it.image_url)}" alt="${esc(it.product_name)}" style="width:44px;height:44px;border-radius:10px;object-fit:cover;border:1px solid rgba(255,255,255,.08)">`:`<span style="font-size:20px">${ico}</span>`}
            <div style="flex:1">
                <div style="font-family:var(--fh);font-size:12px;font-weight:900;color:var(--text)">${it.product_name}</div>
                <div style="font-family:var(--fh);font-size:9px;color:var(--muted)">${it.quantity} × ${up} CFA</div>
            </div>
            <div style="font-family:var(--fh);font-size:13px;font-weight:900;color:var(--neon)">${sub}</div>
        </div>`;
    }).join('');
    document.getElementById('detail-body').innerHTML=`
        <div style="margin-bottom:14px">
          <div style="font-family:var(--fh);font-size:18px;font-weight:900;color:var(--gold);letter-spacing:1px;margin-bottom:4px">${order.order_number}</div>
          <div style="font-family:var(--fh);font-size:10px;color:var(--muted)">${dateStr}</div>
          <div style="margin-top:8px"><span class="bdg ${cfg.cls}"><i class="fas ${cfg.ico}"></i> ${cfg.label}</span></div>
        </div>
        <div style="margin-bottom:14px">
          <div class="oc-section-title">Suivi</div>
          ${buildTimeline(order.status)}
        </div>
        <div style="margin-bottom:14px">
          <div class="oc-section-title">Articles (${items.length})</div>
          ${itemsHtml}
        </div>
        <div style="background:rgba(50,190,143,.08);border:1.5px solid rgba(50,190,143,.22);border-radius:12px;padding:12px;margin-bottom:14px">
          ${order.coupon_code && couponDiscount>0 ? `<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px"><span style="font-family:var(--fh);font-size:10px;color:var(--gold);text-transform:uppercase;letter-spacing:1px">Coupon ${esc(order.coupon_code)}</span><span style="font-family:var(--fh);font-size:13px;font-weight:900;color:var(--gold)">-${couponDiscount.toLocaleString('fr-FR')} CFA</span></div>` : ''}
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
            <span style="font-family:var(--fh);font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:1px">Total</span>
            <span style="font-family:var(--fh);font-size:20px;font-weight:900;color:var(--neon)">${total} CFA</span>
          </div>
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            ${order.company_name?`<span class="meta-pill"><i class="fas fa-building"></i> ${order.company_name}</span>`:''}
            ${order.city_name?`<span class="meta-pill"><i class="fas fa-city"></i> ${order.city_name}</span>`:''}
            <span class="meta-pill">${order.payment_method==='mobile_money'?'📱 Mobile Money':'💵 Espèces'}</span>
          </div>
        </div>
        ${order.delivery_address?`<div style="margin-bottom:12px"><div class="oc-section-title">Livraison</div>
          <div style="padding:10px;border-radius:9px;background:rgba(0,0,0,.2);border:1px solid rgba(255,255,255,.06);font-family:var(--fh);font-size:11px;font-weight:700;color:var(--text2)"><i class="fas fa-location-dot" style="color:var(--neon)"></i> ${order.delivery_address}</div>
          ${order.delivery_zone_name?`<div style="margin-top:6px;padding:9px;border-radius:9px;background:rgba(0,0,0,.15);border:1px solid rgba(255,255,255,.05);font-family:var(--fh);font-size:10px;font-weight:700;color:var(--muted)"><i class="fas fa-map-marked-alt"></i> ${order.delivery_zone_name}${Number(order.delivery_fee||0)>0?` · ${(+order.delivery_fee).toLocaleString('fr-FR')} CFA`:''}</div>`:''}
          ${order.notes?`<div style="margin-top:6px;padding:9px;border-radius:9px;background:rgba(0,0,0,.15);border:1px solid rgba(255,255,255,.05);font-family:var(--fh);font-size:10px;font-weight:700;color:var(--muted)"><i class="fas fa-sticky-note"></i> ${order.notes}</div>`:''}</div>`:''}
        <div class="wa-actions" style="margin-bottom:12px">
          <a class="wa-pill" href="${waTrackLink}" target="_blank" rel="noopener" onclick="logWhatsAppClick('track',${Number(order.id||0)})"><i class="fab fa-whatsapp"></i> Suivre ma commande</a>
          ${['pending','confirmed'].includes(order.status)?`<a class="wa-pill" href="${waEditLink}" target="_blank" rel="noopener" onclick="logWhatsAppClick('edit',${Number(order.id||0)})"><i class="fab fa-whatsapp"></i> Modifier</a>`:''}
          <a class="wa-pill" href="${waIssueLink}" target="_blank" rel="noopener" onclick="logWhatsAppClick('issue',${Number(order.id||0)})"><i class="fab fa-whatsapp"></i> Signaler un problème</a>
        </div>
        ${['pending','confirmed'].includes(order.status)?`<button onclick="closeDetail();askCancel(${order.id},'${order.order_number}')" class="btn btn-r btn-full"><i class="fas fa-ban"></i> ANNULER CETTE COMMANDE</button>`:''}`;
}

/* ── Notifications ── */
function updateNotifBadge(count){
    unreadCount=count;
    const badge=document.getElementById('notif-badge');
    if(!badge)return;
    if(count>0){badge.classList.remove('hidden');badge.textContent=count>9?'9+':count;}
    else{badge.classList.add('hidden');}
}

async function openNotifPanel(){
    document.getElementById('notif-panel').classList.add('open');
    document.getElementById('notif-overlay').classList.add('show');
    document.body.style.overflow='hidden';
    // Load
    const fd=new FormData();fd.append('action','get_notifications');
    const list=document.getElementById('notif-list');
    try{
        const res=await fetch(SELF,{method:'POST',body:fd});
        const data=await res.json();
        if(data.success){renderNotifications(data.notifications);}
        else{list.innerHTML='<div class="notif-empty"><i class="fas fa-exclamation-triangle"></i><p>Erreur</p></div>';}
    }catch(e){list.innerHTML='<div class="notif-empty"><i class="fas fa-wifi"></i><p>Erreur réseau</p></div>';}
}

function closeNotifPanel(){
    document.getElementById('notif-panel').classList.remove('open');
    document.getElementById('notif-overlay').classList.remove('show');
    document.body.style.overflow='';
}

const NOTIF_ICONS={order:'📦',status:'🔄',info:'💡',promo:'🎁'};
const NOTIF_CLS={order:'order',status:'status',info:'info',promo:'promo'};

function timeAgo(dateStr){
    const d=new Date(dateStr.replace(' ','T'));
    const diff=Math.floor((Date.now()-d)/1000);
    if(diff<60)return 'À l\'instant';
    if(diff<3600)return Math.floor(diff/60)+'min';
    if(diff<86400)return Math.floor(diff/3600)+'h';
    return Math.floor(diff/86400)+'j';
}

function renderNotifications(notifs){
    const list=document.getElementById('notif-list');
    if(!notifs.length){
        list.innerHTML='<div class="notif-empty"><i class="fas fa-bell-slash"></i><p>Aucune notification</p></div>';
        return;
    }
    list.innerHTML=notifs.map((n,i)=>`
        <div class="notif-item ${+n.is_read?'':'unread'}" style="animation-delay:${i*0.04}s" onclick="clickNotif(${n.id},${n.order_id||0})">
          <div class="notif-ico ${NOTIF_CLS[n.type]||'info'}">${NOTIF_ICONS[n.type]||'💡'}</div>
          <div class="notif-content">
            <div class="notif-item-title">${n.title}</div>
            <div class="notif-item-msg">${n.message}</div>
            <div class="notif-item-time"><i class="fas fa-clock" style="font-size:8px"></i> ${timeAgo(n.created_at)}</div>
          </div>
          <div class="notif-dot ${+n.is_read?'hidden':''}"></div>
        </div>`).join('');
}

async function clickNotif(notifId,orderId){
    if(orderId){
        closeNotifPanel();
        switchTab('orders');
        await markAllRead();
        setTimeout(()=>openDetail(orderId),400);
    } else {
        markAllRead();
    }
}

async function markAllRead(){
    const fd=new FormData();fd.append('action','mark_notifs_read');
    await fetch(SELF,{method:'POST',body:fd});
    updateNotifBadge(0);
    // update dots
    document.querySelectorAll('.notif-item').forEach(el=>{
        el.classList.remove('unread');
        el.querySelector('.notif-dot')?.classList.add('hidden');
    });
}

/* ── Client Auth ── */
async function checkClient(){
    const email=document.getElementById('client-email')?.value.trim();
    const password=document.getElementById('client-password')?.value||'';
    if(!email){toast('Email requis','warn');return;}
    if(!password){toast('Mot de passe requis','warn');return;}
    const btn=document.getElementById('btn-check');
    btn.disabled=true;btn.innerHTML='<div class="sp"></div>';
    const fd=new FormData();fd.append('action','login_client');fd.append('email',email);fd.append('password',password);
    try{
        const res=await fetch(SELF,{method:'POST',body:fd});
        const data=await res.json();
        btn.disabled=false;btn.innerHTML='<i class="fas fa-right-to-bracket"></i> SE CONNECTER';
        if(!data.success){toast(data.message||'Erreur','error');return;}
        toast('Connexion réussie','success');
        doSession(data.client_id,data.name,data.phone);
    }catch{btn.disabled=false;btn.innerHTML='<i class="fas fa-right-to-bracket"></i> SE CONNECTER';toast('Erreur réseau','error');}
}

async function doSession(id,name,phone){
    const fd=new FormData();fd.append('action','set_client_session');
    fd.append('client_id',id);fd.append('client_name',name);fd.append('client_phone',phone);
    await fetch(SELF,{method:'POST',body:fd});location.reload();
}

async function registerClient(){
    const name=document.getElementById('client-name')?.value.trim();
    const email=document.getElementById('client-register-email')?.value.trim();
    const phone=document.getElementById('client-phone')?.value.trim();
    const password=document.getElementById('client-register-password')?.value||'';
    if(!name||name.length<2){toast('Nom trop court','warn');return;}
    if(!email){toast('Email requis','warn');return;}
    if(!phone||phone.length<8){toast('Numéro invalide','warn');return;}
    if(!password||password.length<6){toast('Mot de passe trop court','warn');return;}
    const btn=document.getElementById('btn-register');
    btn.disabled=true;btn.innerHTML='<div class="sp"></div> Création…';
    const fd=new FormData();fd.append('action','create_client');
    fd.append('name',name);fd.append('email',email);fd.append('phone',phone);fd.append('password',password);fd.append('company_id',CO_ID);fd.append('city_id',CI_ID);
    try{
        const res=await fetch(SELF,{method:'POST',body:fd});
        const data=await res.json();
        btn.disabled=false;btn.innerHTML='<i class="fas fa-user-plus"></i> CRÉER MON COMPTE';
        if(data.success){toast('Bienvenue '+data.name+' !','success');doSession(data.client_id,data.name,data.phone);}
        else toast(data.message||'Erreur','error');
    }catch{btn.disabled=false;btn.innerHTML='<i class="fas fa-user-plus"></i> CRÉER MON COMPTE';toast('Erreur réseau','error');}
}
function toggleNewClientForm(){
    const box=document.getElementById('new-client');
    if(!box) return;
    const open=box.style.display==='block';
    box.style.display=open?'none':'block';
    if(!open){
        setTimeout(()=>document.getElementById('client-name')?.focus(),80);
    }
}

/* ── Logout confirm ── */
function confirmLogout(){
    return confirm('Êtes-vous sûr de vouloir vous déconnecter ?');
}

/* ── Modal close on backdrop ── */
document.getElementById('co-modal')?.addEventListener('click',function(e){if(e.target===this)closeCo();});
document.getElementById('detail-modal')?.addEventListener('click',function(e){if(e.target===this)closeDetail();});
document.getElementById('reviews-modal')?.addEventListener('click',function(e){if(e.target===this)closeReviews();});

renderPromotions();
restoreCart();
applyShopFilters();
setInterval(()=>{
    const promoPanel=document.getElementById('panel-promotions');
    if(promoPanel && promoPanel.classList.contains('show')){
        renderPromotions();
    }
},1000);

/* ── Auto-load orders on tab switch ── */
<?php if($client_id): ?>
setTimeout(()=>{
    const fd=new FormData();fd.append('action','get_orders');
    fetch(SELF,{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        if(d.success){
            allOrders=d.orders||[];
            updateOrderStats(allOrders);
        }
    }).catch(()=>{});
},600);
<?php endif; ?>

/* ══════════════════════════════════════════════════════
   FIGMA MICRO-INTERACTIONS ENGINE — ESPERANCE H2O 💧
══════════════════════════════════════════════════════ */
(function(){
'use strict';

/* ─── Ripple effect ─── */
function spawnRipple(e){
    const el = e.currentTarget;
    const r  = el.getBoundingClientRect();
    const rpl = document.createElement('span');
    rpl.className = 'ripple-wave';
    rpl.style.left = (e.clientX - r.left) + 'px';
    rpl.style.top  = (e.clientY - r.top)  + 'px';
    el.appendChild(rpl);
    rpl.addEventListener('animationend', () => rpl.remove(), {once:true});
}
function bindRipples(){
    document.querySelectorAll('.btn,.badd,.ghost-btn,.tab,.ftab,.shop-pill,.promo-chip')
        .forEach(el => { el.removeEventListener('click', spawnRipple); el.addEventListener('click', spawnRipple); });
}

/* ─── Flying dot to cart ─── */
function flyToCart(srcEl){
    const cart = document.querySelector('.chico');
    if(!cart || !srcEl) return;
    const s = srcEl.getBoundingClientRect();
    const d = cart.getBoundingClientRect();
    const dot = document.createElement('div');
    dot.className = 'cart-fly-dot';
    dot.textContent = '🧃';
    dot.style.cssText = `left:${s.left + s.width/2 - 22}px;top:${s.top + s.height/2 - 22}px;`;
    document.body.appendChild(dot);
    const dx = (d.left + d.width/2  - 22) - (s.left + s.width/2  - 22);
    const dy = (d.top  + d.height/2 - 22) - (s.top  + s.height/2 - 22);
    dot.animate([
        {transform:'translate(0,0) scale(1)',   opacity:1},
        {transform:`translate(${dx*.38}px,${dy*.3 - 55}px) scale(1.22)`, opacity:1, offset:.38},
        {transform:`translate(${dx}px,${dy}px) scale(.25)`, opacity:0}
    ],{duration:640, easing:'cubic-bezier(.4,0,.2,1)', fill:'forwards'})
    .onfinish = () => {
        dot.remove();
        /* cart spring */
        ['.chico','.ccnt'].forEach(sel => {
            const el = document.querySelector(sel);
            if(!el) return;
            const cls = sel === '.ccnt' ? 'badge-pop' : 'cart-bounce';
            el.classList.remove(cls); void el.offsetWidth; el.classList.add(cls);
            el.addEventListener('animationend', () => el.classList.remove(cls), {once:true});
        });
        /* cfloat bounce */
        const cf = document.querySelector('.cfloat');
        if(cf){ cf.classList.remove('f-bounce'); void cf.offsetWidth; cf.classList.add('f-bounce'); cf.addEventListener('animationend',()=>cf.classList.remove('f-bounce'),{once:true}); }
    };
}

/* ─── Add-to-cart button feedback ─── */
function bindAddBtns(){
    document.querySelectorAll('.badd').forEach(btn => {
        if(btn.dataset.figmaBound) return;
        btn.dataset.figmaBound = '1';
        btn.addEventListener('click', function(){
            const pcard = this.closest('.pcard');
            flyToCart(pcard ? pcard.querySelector('.pimg') : this);
            const orig = this.textContent;
            this.classList.add('is-adding');
            setTimeout(()=>{ this.classList.remove('is-adding'); this.classList.add('is-added'); this.textContent='✓ Ajouté!'; },180);
            setTimeout(()=>{ this.classList.remove('is-added'); this.textContent=orig; },1300);
        });
    });
}

/* ─── Stagger product cards ─── */
function staggerCards(){
    const io = new IntersectionObserver(entries => {
        entries.forEach(e => {
            if(!e.isIntersecting) return;
            const idx = Array.from(e.target.parentElement.children).indexOf(e.target);
            setTimeout(()=>e.target.classList.add('f-reveal'), idx * 55);
            io.unobserve(e.target);
        });
    },{threshold:.08});
    document.querySelectorAll('.pcard').forEach(c => { c.classList.remove('f-reveal'); io.observe(c); });
}

/* ─── Scroll reveal for blocks ─── */
function initScrollReveal(){
    const io = new IntersectionObserver(entries => {
        entries.forEach(e => { if(e.isIntersecting){ e.target.classList.add('s-visible'); io.unobserve(e.target); } });
    },{threshold:.07, rootMargin:'0px 0px -28px 0px'});
    document.querySelectorAll('.order-card,.stat-card,.promo-card,.recommend-card,.loyalty-card,.offer-card,.card:not(.cfloat)')
        .forEach(el => { el.classList.add('s-hidden'); io.observe(el); });
}

/* ─── Quantity button pop ─── */
function initQPop(){
    document.addEventListener('click', e => {
        const b = e.target.closest('.qbtn');
        if(!b) return;
        b.classList.remove('q-pop'); void b.offsetWidth; b.classList.add('q-pop');
        b.addEventListener('animationend',()=>b.classList.remove('q-pop'),{once:true});
    });
}

/* ─── Stat counter number animation ─── */
function animateCounters(){
    document.querySelectorAll('.stat-val').forEach(el => {
        const raw = el.textContent.trim();
        const num = parseFloat(raw.replace(/[^\d.]/g,''));
        if(isNaN(num)||num===0) return;
        const suffix = raw.replace(/[\d.]/g,'');
        el.classList.add('s-pop');
        let start = null;
        const dur = 900;
        function step(ts){
            if(!start) start=ts;
            const p = Math.min((ts-start)/dur, 1);
            const ease = 1-Math.pow(1-p,3);
            el.textContent = (Number.isInteger(num)?Math.round(num*ease):(num*ease).toFixed(1)) + suffix;
            if(p<1) requestAnimationFrame(step);
        }
        requestAnimationFrame(step);
    });
}

/* ─── Init ─── */
function init(){
    bindRipples();
    staggerCards();
    initScrollReveal();
    bindAddBtns();
    initQPop();

    /* stats counter on visibility */
    const statsBar = document.querySelector('.stats-bar');
    if(statsBar){
        new IntersectionObserver(entries=>{
            if(entries[0].isIntersecting) animateCounters();
        },{threshold:.5}).observe(statsBar);
    }

    /* re-init on tab switch */
    document.querySelectorAll('.tab,.nav-item').forEach(tab => {
        tab.addEventListener('click',()=>{
            setTimeout(()=>{ staggerCards(); bindRipples(); bindAddBtns(); },60);
        });
    });

    /* ripple on nav items */
    document.querySelectorAll('.nav-item').forEach(el => {
        el.addEventListener('click', spawnRipple);
    });

    /* badge promos at startup */
    const promoCount = <?= count($promotionCampaigns) ?>;
    const nbp = document.getElementById('nav-badge-promos');
    if(nbp && promoCount > 0){ nbp.textContent = promoCount; nbp.classList.remove('hidden'); }

    /* active pill enter animation */
    const activeNav = document.querySelector('.nav-item.active .ni');
    if(activeNav){ activeNav.style.animation='none'; void activeNav.offsetWidth; activeNav.style.animation=''; }

    const heroTrack=document.getElementById('hero-carousel-track');
    const heroDots=[...document.querySelectorAll('[data-hero-dot]')];
    if(heroTrack && heroDots.length>1){
        let heroIndex=0;
        let heroTimer=null;
        let heroStartX=0;
        const paintHero=()=>{
            heroTrack.style.transform=`translateX(-${heroIndex*100}%)`;
            heroDots.forEach((dot,idx)=>dot.classList.toggle('active',idx===heroIndex));
        };
        const queueHero=()=>{
            clearInterval(heroTimer);
            heroTimer=setInterval(()=>{
                heroIndex=(heroIndex+1)%heroDots.length;
                paintHero();
            },4200);
        };
        heroDots.forEach((dot,idx)=>dot.addEventListener('click',()=>{
            heroIndex=idx;
            paintHero();
            queueHero();
        }));
        heroTrack.addEventListener('touchstart',e=>{
            heroStartX=e.changedTouches[0]?.clientX||0;
        },{passive:true});
        heroTrack.addEventListener('touchend',e=>{
            const endX=e.changedTouches[0]?.clientX||0;
            const delta=endX-heroStartX;
            if(Math.abs(delta)>35){
                heroIndex=delta<0 ? (heroIndex+1)%heroDots.length : (heroIndex-1+heroDots.length)%heroDots.length;
                paintHero();
                queueHero();
            }
        },{passive:true});
        paintHero();
        queueHero();
    }
}

document.readyState==='loading'
    ? document.addEventListener('DOMContentLoaded', init)
    : init();

})();

console.log('%c 💧 ESPERANCE H2O v2 — MOBILE FIRST ','background:#f4f7fb;color:#00a86b;font-family:serif;padding:5px;border:1px solid #00a86b;border-radius:4px');
</script>
<?= render_legal_footer(['theme' => 'dark']) ?>

<!-- ══════════════════════════════════════════
     ANDROID NATIVE BOTTOM NAVIGATION BAR
══════════════════════════════════════════ -->
<?php if($client_id): ?>
<nav class="android-nav" id="android-nav" role="navigation" aria-label="Navigation principale">

  <button class="nav-item active" id="nav-shop" onclick="navSwitch('shop',this)" aria-label="Commander">
    <i class="fas fa-store ni"></i>
    <span class="nl">Commander</span>
  </button>

  <button class="nav-item" id="nav-promotions" onclick="navSwitch('promotions',this)" aria-label="Promotions">
    <i class="fas fa-percent ni"></i>
    <span class="nl">Promos</span>
    <span class="nav-badge hidden" id="nav-badge-promos"><?= count($promotionCampaigns) ?></span>
  </button>

  <button class="nav-item" id="nav-orders" onclick="navSwitch('orders',this)" aria-label="Mes commandes">
    <i class="fas fa-receipt ni"></i>
    <span class="nl">Commandes</span>
    <span class="nav-badge hidden" id="nav-badge-orders">0</span>
  </button>

  <button class="nav-item" id="nav-more" onclick="navSwitch('more',this)" aria-label="Plus">
    <i class="fas fa-ellipsis-h ni"></i>
    <span class="nl">Plus</span>
  </button>

</nav>
<?php endif; ?>

</body>
</html>
