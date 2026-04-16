<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

// =========================
// REQUIRES ET AUTOLOAD
// =========================
require_once APP_ROOT . '/app/core/DB.php';
require_once APP_ROOT . '/app/core/Auth.php';
require_once APP_ROOT . '/app/core/Middleware.php';
require_once PROJECT_ROOT . '/messaging/app_alerts.php';
require APP_ROOT . '/vendor/autoload.php';
require_once APP_ROOT . "/fpdf186/fpdf.php";
require_once APP_ROOT . "/phpqrcode/qrlib.php"; // QRCode

// =========================
// IMPORTS
// =========================
use App\Core\DB;
use App\Core\Auth;
use App\Core\Middleware;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
//use FPDF\FPDF;

// =========================
// SESSION & ERREURS
// =========================
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

// =========================
// CONNEXION PDO
// =========================
$pdo = DB::getConnection();
if (!$pdo) {
    die("❌ Impossible de se connecter à la base de données !");
}

// =========================
// SÉCURITÉ
// =========================
Auth::check();
Middleware::role(['developer','admin','manager']);



// Vérifie si l'ID de facture est passé
$invoice_id = $_GET['id'] ?? 0;
if (!$invoice_id) {
    die("Facture non spécifiée !");
}

// Commence transaction pour sécurité
$pdo->beginTransaction();

try {
    $infoStmt = $pdo->prepare("
        SELECT i.total, c.name AS client_name
        FROM invoices i
        LEFT JOIN clients c ON c.id=i.client_id
        WHERE i.id=?
        LIMIT 1
    ");
    $infoStmt->execute([$invoice_id]);
    $invoiceInfo = $infoStmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'client_name' => 'Client inconnu'];

    // Supprime les articles de la facture
    $stmt = $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id = ?");
    $stmt->execute([$invoice_id]);

    // Supprime la facture
    $stmt = $pdo->prepare("DELETE FROM invoices WHERE id = ?");
    $stmt->execute([$invoice_id]);

    $pdo->commit();

    try {
        appAlertNotifyRoles($pdo, appAlertOpsRoles(), [
            'title' => 'Facture annulée',
            'body' => mb_strimwidth(
                sprintf(
                    '🚫 Facture #%d supprimée · Client %s · Montant %s FCFA',
                    (int)$invoice_id,
                    (string)($invoiceInfo['client_name'] ?? 'Client inconnu'),
                    number_format((float)($invoiceInfo['total'] ?? 0), 0, '', '.')
                ),
                0,
                180,
                '…',
                'UTF-8'
            ),
            'url' => project_url('finance/facture.php'),
            'tag' => 'invoice-cancelled-' . (int)$invoice_id,
            'unread' => 1,
        ], [
            'event_type' => 'invoice_cancelled',
            'event_key' => 'invoice-cancelled-' . (int)$invoice_id,
            'actor_user_id' => (int)($_SESSION['user_id'] ?? 0),
            'invoice_id' => (int)$invoice_id,
            'invoice_total' => (float)($invoiceInfo['total'] ?? 0),
            'client_name' => (string)($invoiceInfo['client_name'] ?? ''),
        ]);
    } catch (Throwable $e) {
        error_log('[INVOICE DELETE ALERT] ' . $e->getMessage());
    }

    // Redirection vers facture.php avec succès
    header("Location: facture.php?msg=deleted");
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    die("Erreur lors de la suppression : " . $e->getMessage());
}
?>
