<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once APP_ROOT . '/app/core/DB.php';
require_once APP_ROOT . '/app/core/Auth.php';
require_once APP_ROOT . '/app/core/Middleware.php';
require APP_ROOT . '/vendor/autoload.php';

use App\Core\DB;
use App\Core\Auth;
use App\Core\Middleware;

/* =========================
   SÉCURITÉ
========================= */
Auth::check();
Middleware::role(['developer','admin','manager']);

$pdo = DB::getConnection();


/* =========================
   Données
========================= */
$expense_id = (int)($_GET['id'] ?? 0);
if($expense_id <= 0){
    die("ID dépense invalide");
}

/* =========================
   Vérifier existence
========================= */
$check = $pdo->prepare("SELECT id FROM expenses WHERE id=?");
$check->execute([$expense_id]);

if(!$check->fetch()){
    die("Dépense introuvable");
}
log_action(
    $pdo,
    $_SESSION['user_id'],
    'DEPENSE',
    "Suppression dépense ID #$expense_id"
);
/* =========================
   Suppression
========================= */
$delete = $pdo->prepare("DELETE FROM expenses WHERE id=?");
$delete->execute([$expense_id]);

/* =========================
   LOG ENTREPRISE
========================= */
$user_id = $_SESSION['user_id'];
$ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';

/* Localisation simple (sans API externe) */
$location = 'Inconnu';
if($ip !== 'UNKNOWN'){
    if(strpos($ip, '192.168.') === 0 || $ip === '127.0.0.1'){
        $location = 'Réseau local';
    }
}

$log = $pdo->prepare("
    INSERT INTO logs (user_id, action, ip, location)
    VALUES (?, ?, ?, ?)
");
$log->execute([
    $user_id,
    "Suppression dépense ID #$expense_id",
    $ip,
    $location
]);

/* =========================
   Redirection
========================= */
header("Location: " . project_url('finance/caisse_complete_enhanced.php') . "?msg=depense_supprimee");
exit;
