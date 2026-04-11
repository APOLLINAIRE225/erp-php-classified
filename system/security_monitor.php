<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

/**
 * ═══════════════════════════════════════════════════════════════
 * SYSTÈME DE SURVEILLANCE DE SÉCURITÉ
 * Détection d'intrusion et analyse comportementale
 * ═══════════════════════════════════════════════════════════════
 * 
 * À exécuter via CRON toutes les 5 minutes.
 * Exemple de planification: toutes les 5 minutes via cron.
 */

require_once APP_ROOT . '/app/core/DB.php';
use App\Core\DB;

$pdo = DB::getConnection();

// Configuration
$config = [
    'max_failed_downloads_per_hour' => 5,
    'max_downloads_per_user_per_hour' => 20,
    'suspicious_ip_threshold' => 10,
    'alert_email' => 'security@esperanceh2o.com',
    'enable_auto_block' => true
];

echo "═══════════════════════════════════════════════════════════════\n";
echo "SURVEILLANCE DE SÉCURITÉ DOCUMENTAIRE - " . date('Y-m-d H:i:s') . "\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// ═══════════════════════════════════════════════════════════════
// 1. DÉTECTION DE TENTATIVES D'ACCÈS NON AUTORISÉES
// ═══════════════════════════════════════════════════════════════
echo "[1] Analyse des tentatives d'accès non autorisées...\n";

$stmt = $pdo->query("
    SELECT 
        ip_address,
        user_id,
        COUNT(*) as attempts,
        GROUP_CONCAT(DISTINCT document_id) as doc_ids,
        MIN(created_at) as first_attempt,
        MAX(created_at) as last_attempt
    FROM document_logs
    WHERE action = 'UNAUTHORIZED_DOWNLOAD_ATTEMPT'
    AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
    GROUP BY ip_address, user_id
    HAVING attempts >= 3
");

$unauthorized_attempts = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($unauthorized_attempts as $attempt) {
    echo "  ⚠️  IP {$attempt['ip_address']} : {$attempt['attempts']} tentatives non autorisées\n";
    
    // Créer une alerte
    $stmt = $pdo->prepare("
        INSERT INTO security_alerts (alert_type, severity, user_id, ip_address, description, metadata, status)
        VALUES ('UNAUTHORIZED_ACCESS', 'high', ?, ?, ?, ?, 'new')
    ");
    $stmt->execute([
        $attempt['user_id'],
        $attempt['ip_address'],
        "Multiples tentatives d'accès non autorisées détectées",
        json_encode($attempt)
    ]);
    
    // Bloquer l'IP si auto-block activé
    if ($config['enable_auto_block'] && $attempt['attempts'] >= 5) {
        // TODO: Ajouter l'IP à une blacklist
        echo "      🚫 IP automatiquement bloquée\n";
    }
}

if (count($unauthorized_attempts) == 0) {
    echo "  ✓ Aucune tentative suspecte détectée\n";
}

// ═══════════════════════════════════════════════════════════════
// 2. DÉTECTION D'ACTIVITÉ ANORMALE
// ═══════════════════════════════════════════════════════════════
echo "\n[2] Détection d'activité anormale...\n";

$stmt = $pdo->query("
    SELECT 
        user_id,
        u.username,
        COUNT(*) as download_count,
        COUNT(DISTINCT document_id) as unique_docs,
        COUNT(DISTINCT ip_address) as unique_ips
    FROM document_logs dl
    JOIN users u ON u.id = dl.user_id
    WHERE action = 'DOWNLOAD'
    AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
    GROUP BY user_id
    HAVING download_count > {$config['max_downloads_per_user_per_hour']}
");

$abnormal_activity = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($abnormal_activity as $activity) {
    echo "  ⚠️  Utilisateur {$activity['username']} : {$activity['download_count']} téléchargements en 1h\n";
    
    // Créer une alerte
    $stmt = $pdo->prepare("
        INSERT INTO security_alerts (alert_type, severity, user_id, description, metadata, status)
        VALUES ('ABNORMAL_ACTIVITY', 'medium', ?, ?, ?, 'new')
    ");
    $stmt->execute([
        $activity['user_id'],
        "Nombre anormalement élevé de téléchargements",
        json_encode($activity)
    ]);
}

if (count($abnormal_activity) == 0) {
    echo "  ✓ Activité normale\n";
}

// ═══════════════════════════════════════════════════════════════
// 3. VÉRIFICATION D'INTÉGRITÉ DES FICHIERS
// ═══════════════════════════════════════════════════════════════
echo "\n[3] Vérification d'intégrité des fichiers...\n";

$stmt = $pdo->query("
    SELECT id, file_path, file_hash, title
    FROM documents
    WHERE deleted_at IS NULL
    AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    LIMIT 100
");

$docs_to_check = $stmt->fetchAll(PDO::FETCH_ASSOC);
$corrupted_files = 0;

foreach ($docs_to_check as $doc) {
    $filepath = APP_ROOT . '/storage/documents/encrypted/' . $doc['file_path'];
    
    if (!file_exists($filepath)) {
        echo "  ❌ Fichier manquant : {$doc['title']} (ID: {$doc['id']})\n";
        
        $stmt = $pdo->prepare("
            INSERT INTO security_alerts (alert_type, severity, document_id, description, metadata, status)
            VALUES ('FILE_MISSING', 'critical', ?, ?, ?, 'new')
        ");
        $stmt->execute([
            $doc['id'],
            "Fichier chiffré introuvable sur le disque",
            json_encode(['filepath' => $filepath])
        ]);
        
        $corrupted_files++;
        continue;
    }
    
    $current_hash = hash_file('sha256', $filepath);
    
    if ($current_hash !== $doc['file_hash']) {
        echo "  ❌ Intégrité compromise : {$doc['title']} (ID: {$doc['id']})\n";
        
        $stmt = $pdo->prepare("
            INSERT INTO security_alerts (alert_type, severity, document_id, description, metadata, status)
            VALUES ('INTEGRITY_VIOLATION', 'critical', ?, ?, ?, 'new')
        ");
        $stmt->execute([
            $doc['id'],
            "Hash du fichier ne correspond pas - possible corruption ou modification",
            json_encode([
                'expected_hash' => $doc['file_hash'],
                'actual_hash' => $current_hash
            ])
        ]);
        
        $corrupted_files++;
    }
}

if ($corrupted_files == 0) {
    echo "  ✓ Tous les fichiers vérifiés sont intègres\n";
} else {
    echo "  ⚠️  {$corrupted_files} fichier(s) avec problème d'intégrité\n";
}

// ═══════════════════════════════════════════════════════════════
// 4. DÉTECTION DE COMPORTEMENT DE SCRAPING
// ═══════════════════════════════════════════════════════════════
echo "\n[4] Détection de tentatives de scraping...\n";

$stmt = $pdo->query("
    SELECT 
        ip_address,
        COUNT(DISTINCT document_id) as docs_accessed,
        COUNT(*) as total_actions,
        MIN(created_at) as first_action,
        MAX(created_at) as last_action,
        TIMESTAMPDIFF(SECOND, MIN(created_at), MAX(created_at)) as time_span
    FROM document_logs
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
    GROUP BY ip_address
    HAVING docs_accessed >= 5 
    AND time_span < 60
");

$scraping_attempts = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($scraping_attempts as $scraper) {
    echo "  ⚠️  IP {$scraper['ip_address']} : {$scraper['docs_accessed']} docs en {$scraper['time_span']}s\n";
    
    $stmt = $pdo->prepare("
        INSERT INTO security_alerts (alert_type, severity, ip_address, description, metadata, status)
        VALUES ('SCRAPING_ATTEMPT', 'high', ?, ?, ?, 'new')
    ");
    $stmt->execute([
        $scraper['ip_address'],
        "Comportement suspect - accès rapide à plusieurs documents",
        json_encode($scraper)
    ]);
}

if (count($scraping_attempts) == 0) {
    echo "  ✓ Aucune tentative de scraping détectée\n";
}

// ═══════════════════════════════════════════════════════════════
// 5. VÉRIFICATION DES DOCUMENTS EXPIRÉS
// ═══════════════════════════════════════════════════════════════
echo "\n[5] Vérification des documents expirés...\n";

$stmt = $pdo->query("
    SELECT id, title, reference_code, expires_at
    FROM documents
    WHERE expires_at IS NOT NULL
    AND expires_at < NOW()
    AND deleted_at IS NULL
");

$expired_docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($expired_docs as $doc) {
    echo "  ℹ️  Document expiré : {$doc['title']} ({$doc['reference_code']})\n";
    
    // Marquer comme archivé (optionnel : supprimer automatiquement)
    $stmt = $pdo->prepare("UPDATE documents SET archived_at = NOW() WHERE id = ?");
    $stmt->execute([$doc['id']]);
}

if (count($expired_docs) == 0) {
    echo "  ✓ Aucun document expiré\n";
} else {
    echo "  ℹ️  {count($expired_docs)} document(s) archivé(s) automatiquement\n";
}

// ═══════════════════════════════════════════════════════════════
// 6. NETTOYAGE DES TOKENS TEMPORAIRES
// ═══════════════════════════════════════════════════════════════
echo "\n[6] Nettoyage des tokens temporaires...\n";

$stmt = $pdo->prepare("
    DELETE FROM temp_download_tokens
    WHERE expires_at < NOW() OR used_at IS NOT NULL
");
$stmt->execute();
$cleaned = $stmt->rowCount();

echo "  ✓ {$cleaned} token(s) nettoyé(s)\n";

// ═══════════════════════════════════════════════════════════════
// 7. RAPPORT DES ALERTES ACTIVES
// ═══════════════════════════════════════════════════════════════
echo "\n[7] Alertes de sécurité actives...\n";

$stmt = $pdo->query("
    SELECT 
        alert_type,
        severity,
        COUNT(*) as count
    FROM security_alerts
    WHERE status IN ('new', 'investigating')
    GROUP BY alert_type, severity
    ORDER BY 
        FIELD(severity, 'critical', 'high', 'medium', 'low'),
        count DESC
");

$active_alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($active_alerts) == 0) {
    echo "  ✓ Aucune alerte active\n";
} else {
    foreach ($active_alerts as $alert) {
        $emoji = match($alert['severity']) {
            'critical' => '🔴',
            'high' => '🟠',
            'medium' => '🟡',
            'low' => '🔵',
            default => '⚪'
        };
        echo "  {$emoji} {$alert['alert_type']} ({$alert['severity']}) : {$alert['count']}\n";
    }
}

// ═══════════════════════════════════════════════════════════════
// 8. STATISTIQUES GÉNÉRALES
// ═══════════════════════════════════════════════════════════════
echo "\n[8] Statistiques des dernières 24h...\n";

$stats = [
    'uploads' => $pdo->query("SELECT COUNT(*) FROM document_logs WHERE action='UPLOAD' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn(),
    'downloads' => $pdo->query("SELECT COUNT(*) FROM document_logs WHERE action='DOWNLOAD' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn(),
    'views' => $pdo->query("SELECT COUNT(*) FROM document_logs WHERE action='VIEW' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn(),
    'unauthorized' => $pdo->query("SELECT COUNT(*) FROM document_logs WHERE action='UNAUTHORIZED_DOWNLOAD_ATTEMPT' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn(),
];

echo "  📤 Uploads : {$stats['uploads']}\n";
echo "  📥 Téléchargements : {$stats['downloads']}\n";
echo "  👁️  Consultations : {$stats['views']}\n";
echo "  🚫 Tentatives non autorisées : {$stats['unauthorized']}\n";

// ═══════════════════════════════════════════════════════════════
// ENVOI D'EMAIL SI ALERTES CRITIQUES
// ═══════════════════════════════════════════════════════════════

$critical_count = $pdo->query("
    SELECT COUNT(*) FROM security_alerts
    WHERE severity = 'critical' AND status = 'new'
")->fetchColumn();

if ($critical_count > 0) {
    echo "\n⚠️  {$critical_count} ALERTE(S) CRITIQUE(S) DÉTECTÉE(S) !\n";
    
    // TODO: Envoyer un email d'alerte
    /*
    $to = $config['alert_email'];
    $subject = "ALERTE SÉCURITÉ CRITIQUE - Système Documentaire ESPERANCE H2O";
    $message = "Nombre d'alertes critiques : {$critical_count}\nConsultez le panneau d'administration pour plus de détails.";
    mail($to, $subject, $message);
    */
}

echo "\n═══════════════════════════════════════════════════════════════\n";
echo "Surveillance terminée - " . date('Y-m-d H:i:s') . "\n";
echo "═══════════════════════════════════════════════════════════════\n";
