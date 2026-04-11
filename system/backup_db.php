<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

// =========================
// BOOTSTRAP CORE
// =========================
require_once APP_ROOT . '/app/core/DB.php';
require_once APP_ROOT . '/app/core/Auth.php';
require_once APP_ROOT . '/app/core/Middleware.php';

use App\Core\DB;
use App\Core\Auth;
use App\Core\Middleware;

// =========================
// SESSION & SÉCURITÉ
// =========================
session_start();
Auth::check();
Middleware::role(['developer']);


// =========================
// TEST CONNEXION PDO (SECURITÉ)
// =========================
$pdo = DB::getConnection();

// =========================
// PARAMÈTRES DB (ALIGNÉS DB.php)
// =========================
$dbHost = 'localhost';
$dbName = 'ESPERANCEH20';
$dbUser = 'root';
$dbPass = 'ESPERANCEH20';

// =========================
// DOSSIER BACKUP
// =========================
$backupDir = APP_ROOT . '/backups/';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}

// =========================
// NOM FICHIER
// =========================
$filename = 'backup_' . $dbName . '_' . date('Y-m-d_H-i-s') . '.sql';
$filepath = $backupDir . $filename;

// =========================
// COMMANDE MYSQLDUMP
// =========================
$command = sprintf(
    'mysqldump --host=%s --user=%s --password=%s %s > %s',
    escapeshellarg($dbHost),
    escapeshellarg($dbUser),
    escapeshellarg($dbPass),
    escapeshellarg($dbName),
    escapeshellarg($filepath)
);

// =========================
// EXECUTION
// =========================
exec($command, $output, $status);

if ($status !== 0 || !file_exists($filepath)) {
    die("❌ Échec du backup de la base de données");
}

// =========================
// TÉLÉCHARGEMENT
// =========================
header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Content-Length: ' . filesize($filepath));
readfile($filepath);
exit;
