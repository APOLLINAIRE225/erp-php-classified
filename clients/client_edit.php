<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

// =========================
// REQUIRES ET AUTOLOAD
// =========================
require_once APP_ROOT . '/app/core/DB.php';
require_once APP_ROOT . '/app/core/Auth.php';
require_once APP_ROOT . '/app/core/Middleware.php';
require APP_ROOT . '/vendor/autoload.php';

// =========================
// IMPORTS
// =========================
use App\Core\DB;
use App\Core\Auth;
use App\Core\Middleware;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use FPDF\FPDF;

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

$id = $_GET['id'] ?? null;
if(!$id) die("ID manquant");

// Récupérer client
$stmt = $pdo->prepare("SELECT * FROM clients WHERE id=?");
$stmt->execute([$id]);
$client = $stmt->fetch(PDO::FETCH_ASSOC);
if(!$client) die("Client introuvable");

// Récupérer sociétés, villes
$companies = $pdo->query("SELECT * FROM companies ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$stmt = $pdo->prepare("SELECT * FROM cities WHERE company_id=? ORDER BY name");
$stmt->execute([$client['company_id']]);
$cities = $stmt->fetchAll(PDO::FETCH_ASSOC);

$message = '';

if($_SERVER['REQUEST_METHOD']==='POST'){
    $company_id = $_POST['company_id'];
    $city_id    = $_POST['city_id'];
    $name       = trim($_POST['name']);
    $phone      = trim($_POST['phone']);
    $id_type    = $_POST['id_type'];

    if($company_id && $city_id && $name && $phone){
        // Vérifier doublon téléphone
        $stmt = $pdo->prepare("SELECT id FROM clients WHERE phone=? AND city_id=? AND company_id=? AND id<>?");
        $stmt->execute([$phone,$city_id,$company_id,$id]);
        if($stmt->rowCount()==0){
            // Mettre à jour
            $stmt = $pdo->prepare("UPDATE clients SET company_id=?, city_id=?, name=?, phone=?, id_type=? WHERE id=?");
            $stmt->execute([$company_id,$city_id,$name,$phone,$id_type,$id]);
            $message = "✅ Client modifié avec succès.";
            $stmt = $pdo->prepare("SELECT * FROM clients WHERE id=?");
            $stmt->execute([$id]);
            $client = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $message = "⚠️ Ce téléphone existe déjà pour cette société/ville.";
        }
    } else {
        $message = "⚠️ Tous les champs sont obligatoires.";
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Modifier Client</title>
<style>
body{margin:0;padding:20px;background:#ecfeff;font-family:Segoe UI,Arial,sans-serif;color:#134e4a;}
select,input{padding:8px 12px;border-radius:6px;border:1px solid #14b8a6;font-weight:600;}
button{padding:8px 12px;border-radius:6px;background:#14b8a6;color:#fff;border:none;cursor:pointer;font-weight:600;}
.alert-message{margin-bottom:15px;padding:10px;border-radius:6px;background:#d1fae5;color:#065f46;font-weight:bold;}
</style>
</head>
<body>

<h2>✏️ Modifier Client</h2>
<?php if($message): ?>
<div class="alert-message"><?= $message ?></div>
<?php endif; ?>

<form method="post">
    <select name="company_id" required onchange="this.form.submit()">
        <option value="">— Société —</option>
        <?php foreach($companies as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $c['id']==$client['company_id']?'selected':'' ?>>
                <?= htmlspecialchars($c['name']) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <select name="city_id" required>
        <option value="">— Ville —</option>
        <?php foreach($cities as $v): ?>
            <option value="<?= $v['id'] ?>" <?= $v['id']==$client['city_id']?'selected':'' ?>>
                <?= htmlspecialchars($v['name']) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <input type="text" name="name" value="<?= htmlspecialchars($client['name']) ?>" required>
    <input type="text" name="phone" value="<?= htmlspecialchars($client['phone']) ?>" required>
    <select name="id_type">
        <option value="nouveau" <?= $client['id_type']=='nouveau'?'selected':'' ?>>Nouveau</option>
        <option value="ancien" <?= $client['id_type']=='ancien'?'selected':'' ?>>Ancien</option>
    </select>
    <button type="submit">Modifier</button>
</form>

<p><a href="<?= project_url('clients/clients_erp_pro.php') ?>">← Retour à la liste</a></p>
</body>
</html>
