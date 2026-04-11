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
Middleware::role(['developer']);

$pdo = DB::getConnection();
$message = "";

/* ================== TRAITEMENT ================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_restore'])) {

    // 1️⃣ Vérif champs
    if (
        empty($_POST['admin_password']) ||
        empty($_POST['confirm_phrase']) ||
        empty($_FILES['sql_file']['tmp_name'])
    ) {
        $message = "❌ Tous les champs sont obligatoires";
    } else {

        // 2️⃣ Phrase obligatoire
        $requiredPhrase = "JE COMPRENDS LE RISQUE";
        if (trim($_POST['confirm_phrase']) !== $requiredPhrase) {
            $message = "❌ Phrase de confirmation incorrecte";
        } else {

            // 3️⃣ Vérif mot de passe admin
            $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($_POST['admin_password'], $user['password'])) {
                $message = "❌ Mot de passe administrateur invalide";
            } else {

                // 4️⃣ Vérif fichier
                $file = $_FILES['sql_file'];
                if (pathinfo($file['name'], PATHINFO_EXTENSION) !== 'sql') {
                    $message = "❌ Fichier SQL invalide";
                } else {

                    // 5️⃣ RESTORE (NUCLÉAIRE)
                    try {
                        $sql = file_get_contents($file['tmp_name']);
                        $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
                        $pdo->exec($sql);
                        $pdo->exec("SET FOREIGN_KEY_CHECKS=1");

                        $message = "✅ BASE DE DONNÉES RESTAURÉE AVEC SUCCÈS";
                    } catch (Exception $e) {
                        $message = "❌ ERREUR SQL : " . $e->getMessage();
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Danger Zone – Restauration DB</title>

<style>
/* ================== STYLE ENTREPRISE ================== */
body{
    margin:0;
    background:#020617;
    font-family:system-ui,-apple-system;
    color:#e5e7eb;
}
.wrapper{
    max-width:720px;
    margin:70px auto;
    background:#020617;
    border:2px solid #dc2626;
    border-radius:22px;
    padding:35px;
    box-shadow:0 0 40px rgba(220,38,38,.4);
}
h1{
    text-align:center;
    color:#fecaca;
    margin-bottom:10px;
}
.sub{
    text-align:center;
    color:#fca5a5;
    font-size:14px;
}
input[type=file],
input[type=password],
input[type=text]{
    width:100%;
    padding:14px;
    background:#020617;
    border:1px solid #334155;
    border-radius:12px;
    color:#fff;
    margin-top:12px;
}
label{
    margin-top:18px;
    display:block;
    font-size:13px;
    color:#99f6e4;
}
button{
    margin-top:25px;
    width:100%;
    padding:16px;
    border:none;
    border-radius:14px;
    background:linear-gradient(135deg,#dc2626,#991b1b);
    color:#fff;
    font-size:17px;
    cursor:pointer;
}
button:hover{opacity:.9}

.alert{
    margin-bottom:20px;
    padding:14px;
    border-radius:12px;
    background:#450a0a;
    color:#fecaca;
    border:1px solid #dc2626;
    text-align:center;
}

/* ================== MODALE ================== */
#modal{
    display:none;
    position:fixed;
    inset:0;
    background:rgba(0,0,0,.92);
}
.modal-box{
    background:#020617;
    border:2px solid #dc2626;
    border-radius:20px;
    max-width:520px;
    margin:15% auto;
    padding:35px;
    text-align:center;
}
.timer{
    font-size:34px;
    color:#f87171;
}
.btns{
    display:flex;
    gap:10px;
    margin-top:20px;
}
.btns button{
    flex:1;
}
.cancel{
    background:#020617;
    border:1px solid #334155;
}
</style>
</head>

<body>

<!-- COMBO BOX CUSTOM FIXE AVEC ICONS -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">

<div class="top-right-dropdown">
    <button class="dropdown-btn"><i class="fa fa-bars"></i> Menu <i class="fa fa-caret-down"></i></button>
    <ul class="dropdown-content">
        <li onclick="navigate('<?= project_url('dashboard/index.php') ?>')"><i class="fa fa-chart-line"></i> Dashboard</li>
        <li onclick="navigate('<?= project_url('finance/caisse_complete_enhanced.php') ?>')"><i class="fa fa-wallet"></i> Caisse</li>
        <li onclick="navigate('<?= project_url('finance/versement.php') ?>')"><i class="fa fa-money-bill-wave"></i> Versement</li>
        <li onclick="navigate('<?= project_url('finance/facture.php') ?>')"><i class="fa fa-file-invoice"></i> Facture</li>
        <li onclick="navigate('<?= project_url('finance/depenses.php') ?>')"><i class="fa fa-receipt"></i> Dépense</li>
        <li onclick="navigate('<?= project_url('clients/clients_erp_pro.php') ?>')"><i class="fa fa-users"></i> Clients</li>
        <li onclick="navigate('<?= project_url('stock/stocks_erp_pro.php') ?>')"><i class="fa fa-boxes-stacked"></i> Stocks</li>
        <li onclick="navigate('<?= project_url('auth/logout.php') ?>')"><i class="fa fa-right-from-bracket"></i> Logout</li>
    </ul>
</div>

<style>
/* CONTENEUR FIXE */
.top-right-dropdown {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 9999;
    font-family: Arial, sans-serif;
}

/* BOUTON PRINCIPAL */
.dropdown-btn {
    background: rgba(0,0,0,0.45);
    backdrop-filter: blur(10px);
    color: #fff;
    border: none;
    padding: 10px 15px;
    border-radius: 12px;
    cursor: pointer;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 5px;
    transition: all 0.3s ease;
    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
}

.dropdown-btn:hover {
    background: rgba(255,255,255,0.15);
    transform: scale(1.05);
}

/* MENU DERROULANT */
.dropdown-content {
    display: none;
    position: absolute;
    right: 0;
    top: 45px;
    background: rgba(0,0,0,0.85);
    backdrop-filter: blur(10px);
    border-radius: 12px;
    min-width: 180px;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
    padding: 5px 0;
}

/* ITEMS */
.dropdown-content li {
    list-style: none;
    padding: 10px 15px;
    color: #fff;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 10px;
    transition: all 0.2s ease;
}

.dropdown-content li:hover {
    background: rgba(255,255,255,0.15);
}

/* FA ICONS */
.dropdown-content li i {
    width: 20px;
    text-align: center;
}
</style>

<script>
function navigate(url) {
    window.location.href = url;
}

// Toggle dropdown
const btn = document.querySelector('.dropdown-btn');
const menu = document.querySelector('.dropdown-content');

btn.addEventListener('click', () => {
    menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
});

// Fermer menu si click à l'extérieur
window.addEventListener('click', function(e){
    if(!btn.contains(e.target) && !menu.contains(e.target)){
        menu.style.display = 'none';
    }
});
</script>


<div class="wrapper">
<h1>⚠️ DANGER ZONE</h1>
<p class="sub">Restauration complète de la base de données</p>

<?php if($message): ?>
<div class="alert"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<form id="restoreForm" method="post" enctype="multipart/form-data">
<input type="hidden" name="do_restore" value="1">

<label>Fichier de sauvegarde (.sql)</label>
<input type="file" name="sql_file" accept=".sql" required>

<label>Mot de passe administrateur</label>
<input type="password" name="admin_password" required>

<label>Phrase obligatoire</label>
<input type="text" name="confirm_phrase"
placeholder="PHRASE DE RESTORATION" required>

<button type="submit">🔥 LANCER LA RESTAURATION</button>
</form>
</div>

<!-- ================== MODALE ================== -->
<div id="modal">
<div class="modal-box">
<h2 style="color:#fecaca">CONFIRMATION FINALE</h2>
<p style="color:#fee2e2">
Toutes les données actuelles seront <b>DÉFINITIVEMENT PERDUES</b>.
</p>

<p>Début dans <span class="timer" id="count">15</span> secondes</p>

<div class="btns">
<button class="cancel" onclick="cancelRestore()">❌ Annuler</button>
<button onclick="confirmRestore()">🔥 J’assume</button>
</div>
</div>
</div>

<script>
/* ================== LOGIQUE PRO ================== */
let countdown = 15;
let interval;
let armed = false;
const form = document.getElementById('restoreForm');

form.addEventListener('submit', e => {
    e.preventDefault();
    document.getElementById('modal').style.display = 'block';
});

function confirmRestore(){
    if(armed) return;
    armed = true;

    interval = setInterval(()=>{
        countdown--;
        document.getElementById('count').textContent = countdown;

        if(countdown <= 0){
            clearInterval(interval);
            form.submit(); // 🔥 SEULEMENT ICI
        }
    },1000);
}

function cancelRestore(){
    clearInterval(interval);
    countdown = 15;
    armed = false;
    document.getElementById('count').textContent = countdown;
    document.getElementById('modal').style.display = 'none';
}
</script>

</body>
</html>
