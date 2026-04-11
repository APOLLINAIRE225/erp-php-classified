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

$pdo = DB::getConnection();
$current_user_id = $_SESSION['user_id'] ?? 0;
$user_role = $_SESSION['role'] ?? 'user';

$document_id = (int)($_GET['id'] ?? 0);

if (!$document_id) {
    header('Location: documents_erp_pro.php');
    exit;
}

/* =========================
   RÉCUPÉRATION DU DOCUMENT
========================= */
$stmt = $pdo->prepare("
    SELECT d.*
    FROM documents d
    WHERE d.id = ? AND d.deleted_at IS NULL
");
$stmt->execute([$document_id]);
$document = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$document) {
    header('Location: documents_erp_pro.php');
    exit;
}

/* =========================
   VÉRIFICATION DES PERMISSIONS
========================= */
$can_delete = false;

if (in_array($user_role, ['developer', 'admin'])) {
    $can_delete = true;
} elseif ($document['uploaded_by'] == $current_user_id) {
    $can_delete = true;
} else {
    $stmt = $pdo->prepare("
        SELECT can_delete FROM document_permissions 
        WHERE document_id = ? AND user_id = ?
    ");
    $stmt->execute([$document_id, $current_user_id]);
    $perm = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($perm && $perm['can_delete']) {
        $can_delete = true;
    }
}

if (!$can_delete) {
    die("Vous n'avez pas l'autorisation de supprimer ce document.");
}

/* =========================
   SUPPRESSION (SOFT DELETE)
========================= */
$stmt = $pdo->prepare("
    UPDATE documents 
    SET deleted_at = NOW()
    WHERE id = ?
");
$stmt->execute([$document_id]);

/* =========================
   LOG DE L'ACTION
========================= */
$stmt = $pdo->prepare("
    INSERT INTO document_logs (document_id, user_id, action, ip_address, user_agent)
    VALUES (?, ?, 'delete', ?, ?)
");
$stmt->execute([
    $document_id,
    $current_user_id,
    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
]);

/* =========================
   REDIRECTION
========================= */
header('Location: documents_erp_pro.php?deleted=1');
exit;
?>
