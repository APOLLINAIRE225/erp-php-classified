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
Middleware::role(['developer','manager']);


$message = "";

// ➕ Création de clé
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $company_id = intval($_POST['company_id']);

    if ($company_id > 0) {
        $accessKey = strtoupper(bin2hex(random_bytes(6)));

        $stmt = $pdo->prepare("
            INSERT INTO cash_access_keys
            (access_key, company_id, valid_date, created_by)
            VALUES (?, ?, CURDATE(), ?)
        ");
        $stmt->execute([$accessKey, $company_id, $_SESSION['user_id']]);

        $message = "Clé créée avec succès : <strong>$accessKey</strong>";
    }
}

// 📄 Liste entreprises
$companies = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll();

// 📋 Clés du jour
$keys = $pdo->query("
    SELECT k.access_key, c.name AS company, k.start_time, k.end_time, k.is_used
    FROM cash_access_keys k
    JOIN companies c ON c.id = k.company_id
    WHERE k.valid_date = CURDATE()
    ORDER BY k.created_at DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Admin – Clé d'accès caisse</title>

<style>
body{
    font-family:Segoe UI,monospace;
    background:#f4f4f9;
    margin:20px;
}
h1{
    color:#134e4a;
}
.card{
    background:#fff;
    padding:15px;
    border-radius:8px;
    box-shadow:0 2px 6px rgba(0,0,0,.08);
    max-width:600px;
}
.avertisement{
    color:red
    
}
.mac{
     color:blue
}
label{
    font-weight:600;
    font-size:13px;
    margin-bottom:4px;
    display:block;
}
select, input{
    width:100%;
    padding:8px;
    margin-bottom:10px;
    border:1px solid #ccc;
    border-radius:5px;
    font-size:13px;
}
button{
    background:#149ba6;
    color:#fff;
    border:none;
    padding:8px 14px;
    border-radius:5px;
    cursor:pointer;
    font-weight:600;
}
button:hover{
    background:#0d7a7c;
}
.alert{
    margin:10px 0;
    padding:8px;
    background:#e6fffa;
    border-left:4px solid #149ba6;
    font-size:13px;
}
table{
    width:100%;
    border-collapse:collapse;
    margin-top:20px;
}
th,td{
    border:1px solid #ccc;
    padding:6px;
    font-size:12px;
}
th{
    background:#149ba6;
    color:#fff;
}
.used{
    color:red;
    font-weight:bold;
}
.valid{
    color:green;
    font-weight:bold;
}
.small{
    font-size:11px;
    color:#555;
}
.btn-back {

  display: inline-flex;

  align-items: center;

  gap: 8px;



  padding: 12px 22px;

  border-radius: 30px;



  background: linear-gradient(135deg, #4f46e5, #6366f1);

  color: #fff;

  font-weight: 600;

  text-decoration: none;



  box-shadow: 0 10px 25px rgba(79, 70, 229, 0.35);

  transition: all 0.3s ease;

}



/* Animation au survol */

.btn-back:hover {

  transform: translateX(-6px);

  box-shadow: 0 15px 35px rgba(79, 70, 229, 0.55);

  background: linear-gradient(135deg, #4338ca, #4f46e5);

}



/* Effet au clic */

.btn-back:active {

  transform: scale(0.95);

}
</style>
</head>

<body>

<p>

  <a href="<?= project_url('dashboard/index.php') ?>" class="btn-back">

    ← Retour au dashboard

  </a>
<h1>🔐 Administration – Clé d'accès caisse</h1>
<marquee><h2 class="mac"> Cette page crée uniquement les clés d'access pour les caisses de l'entreprise ESPERANCE H20 | Toute utilisasion sans permision sera rigouresement punis</h2></marquee>
<hr>
<h1 class="avertisement">Chaque clés n'es valide qu'une seule journéé et pas de plus. | Vous aurez besoin de cette clé pour accedé a la caisse chaque jour.</h1>
<div class="card">
    <form method="post">
        <label>Entreprise</label>
        <select name="company_id" required>
            <option value="">-- Sélectionner --</option>
            <?php foreach($companies as $c): ?>
                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <button type="submit">➕ Générer la clé du jour</button>
    </form>

    <?php if($message): ?>
        <div class="alert"><?= $message ?></div>
    <?php endif; ?>
</div>

<h2>📋 Clés générées aujourd’hui</h2>

<table>
<tr>
    <th>Entreprise</th>
    <th>Clé</th>
    <th>Heure</th>
    <th>Statut</th>
</tr>

<?php foreach($keys as $k): ?>
<tr>
    <td><?= htmlspecialchars($k['company']) ?></td>
    <td><strong><?= $k['access_key'] ?></strong></td>
    <td class="small"><?= $k['start_time'] ?> → <?= $k['end_time'] ?></td>
    <td class="<?= $k['is_used'] ? 'used' : 'valid' ?>">
        <?= $k['is_used'] ? 'Utilisée' : 'Valide' ?>
    </td>
</tr>
<?php endforeach; ?>
</table>

<script>
// 🧠 UX simple
document.querySelector("form").addEventListener("submit", () => {
    return confirm("Créer une nouvelle clé caisse pour aujourd’hui ?");
});
</script>

</body>
</html>
