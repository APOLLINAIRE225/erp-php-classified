<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once APP_ROOT . '/app/core/DB.php';
require_once APP_ROOT . '/app/core/Auth.php';
require_once APP_ROOT . '/app/core/Middleware.php';
require_once APP_ROOT . '/app/core/CryptoSecure.php';

use App\Core\DB;
use App\Core\Auth;
use App\Core\Middleware;
use App\Core\CryptoSecure;

Auth::check();
Middleware::role(['developer', 'admin', 'manager', 'user']);

$pdo = DB::getConnection();
$user_id = $_SESSION['user_id'] ?? 0;
$user_name = $_SESSION['username'] ?? '';
$user_role = $_SESSION['role'] ?? 'user';

// Récupération du document
$doc_id = (int)($_GET['id'] ?? 0);

if ($doc_id <= 0) {
    die("❌ Document invalide");
}

// Vérification des permissions
$stmt = $pdo->prepare("
    SELECT d.*, c.name as company_name
    FROM documents d
    JOIN companies c ON c.id = d.company_id
    WHERE d.id = ? AND d.deleted_at IS NULL
");
$stmt->execute([$doc_id]);
$document = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$document) {
    die("❌ Document introuvable");
}

// Vérification des permissions d'accès
$has_access = false;

if (in_array($user_role, ['developer', 'admin'])) {
    // Admin a accès à tout
    $has_access = true;
} elseif ($document['uploaded_by'] == $user_id) {
    // Propriétaire du document
    $has_access = true;
} else {
    // Vérification des permissions explicites
    $stmt = $pdo->prepare("
        SELECT can_download FROM document_permissions
        WHERE document_id = ? AND user_id = ? AND can_view = 1
    ");
    $stmt->execute([$doc_id, $user_id]);
    $perm = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($perm && $perm['can_download']) {
        $has_access = true;
    }
}

if (!$has_access) {
    // LOG DE TENTATIVE D'ACCÈS NON AUTORISÉ
    $stmt = $pdo->prepare("
        INSERT INTO document_logs (document_id, user_id, action, ip_address, user_agent, metadata, created_at)
        VALUES (?, ?, 'UNAUTHORIZED_DOWNLOAD_ATTEMPT', ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $doc_id,
        $user_id,
        $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
        $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
        json_encode(['user' => $user_name, 'document' => $document['title']])
    ]);
    
    die("❌ Accès refusé - Vous n'avez pas la permission de télécharger ce document. Cette tentative a été enregistrée.");
}

// Vérification de l'expiration
if ($document['expires_at'] && strtotime($document['expires_at']) < time()) {
    die("❌ Ce document a expiré le " . date('d/m/Y', strtotime($document['expires_at'])));
}

// ═══════════════════════════════════════════════════
// DÉCHIFFREMENT DU FICHIER
// ═══════════════════════════════════════════════════

$encryptedFilePath = APP_ROOT . '/storage/documents/encrypted/' . $document['file_path'];

if (!file_exists($encryptedFilePath)) {
    die("❌ Fichier chiffré introuvable sur le serveur");
}

// Vérification de l'intégrité du fichier chiffré
$currentHash = CryptoSecure::hashFile($encryptedFilePath);
if (!hash_equals($document['file_hash'], $currentHash)) {
    // ⚠️ ALERTE SÉCURITÉ : Le fichier a été modifié !
    $stmt = $pdo->prepare("
        INSERT INTO document_logs (document_id, user_id, action, ip_address, user_agent, metadata, created_at)
        VALUES (?, ?, 'FILE_INTEGRITY_VIOLATION', ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $doc_id,
        $user_id,
        $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
        $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
        json_encode([
            'expected_hash' => $document['file_hash'],
            'actual_hash' => $currentHash,
            'file_path' => $document['file_path']
        ])
    ]);
    
    die("❌ ALERTE SÉCURITÉ : L'intégrité du fichier a été compromise. L'incident a été enregistré.");
}

// Déchiffrement vers un fichier temporaire
$decryptResult = CryptoSecure::decryptToTemp(
    $encryptedFilePath,
    $document['encryption_key'],
    300 // TTL: 5 minutes
);

if (!$decryptResult['success']) {
    die("❌ Échec du déchiffrement : " . ($decryptResult['error'] ?? 'Erreur inconnue'));
}

$tempFile = $decryptResult['temp_file'];

// ═══════════════════════════════════════════════════
// WATERMARKING POUR LES PDF (optionnel)
// ═══════════════════════════════════════════════════
if ($document['mime_type'] === 'application/pdf' && extension_loaded('imagick')) {
    try {
        // Ajout d'un watermark invisible avec les infos utilisateur
        $watermarkText = sprintf(
            "Téléchargé par: %s - %s - IP: %s",
            $user_name,
            date('Y-m-d H:i:s'),
            $_SERVER['REMOTE_ADDR'] ?? 'Unknown'
        );
        
        // Utilisation de FPDF pour ajouter un watermark
        require_once APP_ROOT . '/fpdf186/fpdf.php';
        
        class PDF_Watermark extends FPDF {
            public $watermark = '';
            
            function Header() {
                if ($this->watermark) {
                    $this->SetFont('Arial', '', 6);
                    $this->SetTextColor(200, 200, 200);
                    $this->SetY(-15);
                    $this->Cell(0, 10, $this->watermark, 0, 0, 'C');
                }
            }
        }
        
        // Note: Watermarking PDF complet nécessiterait une bibliothèque comme PDFtk
        // Pour une implémentation simple, on log juste le téléchargement
        
    } catch (Exception $e) {
        // Watermarking failed, continue with download
    }
}

// ═══════════════════════════════════════════════════
// LOG DU TÉLÉCHARGEMENT
// ═══════════════════════════════════════════════════
$stmt = $pdo->prepare("
    INSERT INTO document_logs (document_id, user_id, action, ip_address, user_agent, metadata, created_at)
    VALUES (?, ?, 'DOWNLOAD', ?, ?, ?, NOW())
");
$stmt->execute([
    $doc_id,
    $user_id,
    $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
    $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
    json_encode([
        'user' => $user_name,
        'filename' => $document['file_name'],
        'size' => $document['file_size'],
        'confidentiality' => $document['confidentiality_level']
    ])
]);

// Incrémenter le compteur de téléchargements
$pdo->prepare("UPDATE documents SET download_count = download_count + 1 WHERE id = ?")->execute([$doc_id]);

// ═══════════════════════════════════════════════════
// ENVOI DU FICHIER DÉCHIFFRÉ
// ═══════════════════════════════════════════════════

// Headers de sécurité
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Content-Security-Policy: default-src \'none\'');

// Headers de téléchargement
header('Content-Type: ' . $document['mime_type']);
header('Content-Disposition: attachment; filename="' . addslashes($document['file_name']) . '"');
header('Content-Length: ' . filesize($tempFile));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Envoi du fichier par chunks (évite les problèmes mémoire sur gros fichiers)
$handle = fopen($tempFile, 'rb');
while (!feof($handle)) {
    echo fread($handle, 8192); // 8KB chunks
    flush();
}
fclose($handle);

// Le fichier temporaire sera automatiquement supprimé par le shutdown function
// défini dans CryptoSecure::decryptToTemp()

exit;
