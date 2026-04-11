<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

session_start();
ini_set('display_errors', 0);
error_reporting(0);

require_once APP_ROOT . '/app/core/DB.php';
require_once APP_ROOT . '/app/core/Auth.php';
require_once APP_ROOT . '/app/core/Middleware.php';

use App\Core\DB;
use App\Core\Auth;
use App\Core\Middleware;

Auth::check();
Middleware::role(['developer','admin','manager']);

$pdo = DB::getConnection();

$id   = (int)($_GET['id'] ?? 0);
$back = $_GET['back'] ?? 'versement.php';

// Validate back URL to prevent open redirect (must be relative)
if (!preg_match('/^versement\.php/', $back)) {
    $back = 'versement.php';
}

if ($id) {
    // Fetch versement to get invoice_id for status resync
    $st = $pdo->prepare("SELECT invoice_id FROM versements WHERE id=?");
    $st->execute([$id]);
    $v = $st->fetch(PDO::FETCH_ASSOC);

    if ($v) {
        $pdo->prepare("DELETE FROM versements WHERE id=?")->execute([$id]);

        // Resync invoice status
        $invoice_id = (int)$v['invoice_id'];
        $st = $pdo->prepare("SELECT total FROM invoices WHERE id=?");
        $st->execute([$invoice_id]);
        $total = (float)$st->fetchColumn();

        $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM versements WHERE invoice_id=?");
        $st->execute([$invoice_id]);
        $paid = (float)$st->fetchColumn();

        $reste = $total - $paid;
        if ($reste <= 0)       $statut = 'Payée';
        elseif ($paid > 0)     $statut = 'Partielle';
        else                   $statut = 'Impayée';

        $pdo->prepare("UPDATE invoices SET status=? WHERE id=?")->execute([$statut, $invoice_id]);
    }
}

header("Location: " . $back . "&flash=" . urlencode("success:Versement supprimé."));
exit;
