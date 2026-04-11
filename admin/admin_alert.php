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
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use FPDF\FPDF;

/*-------------------------------
| SÉCURITÉ
-------------------------------*/
Auth::check();
Middleware::role(['developer','manager']);

$pdo = DB::getConnection();


// Marquer comme vue
if(isset($_GET['seen'])){
    $id = (int)$_GET['seen'];
    $stmt = $pdo->prepare("UPDATE admin_alerts SET seen=1 WHERE id=?");
    $stmt->execute([$id]);
    header("Location: admin_alert.php");
    exit;
}

// Supprimer alerte
if(isset($_GET['delete'])){
    $id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM admin_alerts WHERE id=?");
    $stmt->execute([$id]);
    header("Location: admin_alert.php");
    exit;
}

// Récupération alertes
$alerts = $pdo->query("
    SELECT a.*, u.username
    FROM admin_alerts a
    JOIN users u ON u.id=a.user_id
    ORDER BY a.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Compteur non lues
$unread = $pdo->query("
    SELECT COUNT(*) FROM admin_alerts WHERE seen=0
")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Alertes Administrateur</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
body{
    font-family:Segoe UI,sans-serif;
    background:#f4f4f9;
    margin:20px;
}
h1{
    color:#134e4a;
}
.badge{
    background:red;
    color:white;
    padding:4px 8px;
    border-radius:12px;
    font-size:12px;
}
table{
    width:100%;
    border-collapse:collapse;
    margin-top:20px;
    background:#fff;
    box-shadow:0 5px 15px rgba(0,0,0,.1);
}
th,td{
    border:1px solid #ddd;
    padding:8px;
    font-size:13px;
}
th{
    background:#149ba6;
    color:#fff;
}
tr.unread{
    background:#fff7ed;
    font-weight:bold;
}
.actions a{
    margin-right:8px;
    text-decoration:none;
}
.btn{
    padding:4px 8px;
    border-radius:4px;
    font-size:12px;
}
.btn-view{background:#10b981;color:#fff;}
.btn-del{background:#ef4444;color:#fff;}
.footer{
    margin-top:15px;
    font-size:12px;
    color:#666;
}
</style>
</head>
<body>

<h1>
    <i class="fa-solid fa-bell"></i>
    Alertes Administrateur
    <?php if($unread>0): ?>
        <span class="badge"><?= $unread ?></span>
    <?php endif; ?>
</h1>

<table>
<thead>
<tr>
    <th>Date</th>
    <th>Utilisateur</th>
    <th>Message</th>
    <th>État</th>
    <th>Actions</th>
</tr>
</thead>
<tbody>
<?php if(!$alerts): ?>
<tr><td colspan="5">Aucune alerte</td></tr>
<?php endif; ?>

<?php foreach($alerts as $a): ?>
<tr class="<?= $a['seen'] ? '' : 'unread' ?>">
    <td><?= $a['created_at'] ?></td>
    <td><?= htmlspecialchars($a['username']) ?></td>
    <td><?= htmlspecialchars($a['message']) ?></td>
    <td><?= $a['seen'] ? 'Vue' : 'Nouvelle' ?></td>
    <td class="actions">
        <?php if(!$a['seen']): ?>
            <a class="btn btn-view" href="?seen=<?= $a['id'] ?>">
                <i class="fa fa-eye"></i>
            </a>
        <?php endif; ?>
        <a class="btn btn-del" href="?delete=<?= $a['id'] ?>"
           onclick="return confirm('Supprimer cette alerte ?')">
            <i class="fa fa-trash"></i>
        </a>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<div class="footer">
    &copy; <?= date('Y') ?> Esperance H2O — Sécurité & Audit Entreprise
</div>

</body>
</html>
