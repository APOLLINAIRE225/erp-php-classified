<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

// =========================
// IMPORTS
// =========================
use App\Core\DB;
use App\Core\Auth;
use App\Core\Middleware;

require_once APP_ROOT . '/app/core/DB.php';
require_once APP_ROOT . '/app/core/Auth.php';
require_once APP_ROOT . '/app/core/Middleware.php';
require APP_ROOT . '/vendor/autoload.php';

// =========================
// SESSION & ERREURS
// =========================
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

// =========================
// SÉCURITÉ
// =========================
Auth::check();
Middleware::role(['developer','admin','manager']);

// =========================
// CONNEXION PDO
// =========================
$pdo = DB::getConnection();
if (!$pdo) {
    die("❌ Connexion DB impossible");
}

// =========================
// FILTRES
// =========================
$company_id = (int)($_GET['company_id'] ?? 0);
$city_id    = (int)($_GET['city_id'] ?? 0);
$search     = trim($_GET['search'] ?? '');
$date_from  = $_GET['date_from'] ?? '';
$date_to    = $_GET['date_to'] ?? '';

// =========================
// AJOUT DÉPENSE
// =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_expense'])) {
    $stmt = $pdo->prepare("
        INSERT INTO expenses (company_id, city_id, category, amount, expense_date, note)
        VALUES (:company, :city, :category, :amount, :date, :note)
    ");

    $stmt->execute([
        ':company'  => (int)$_POST['company_id'],
        ':city'     => $_POST['city_id'] ?: null,
        ':category' => trim($_POST['category']),
        ':amount'   => (float)$_POST['amount'],
        ':date'     => $_POST['expense_date'],
        ':note'     => trim($_POST['note'] ?? '')
    ]);

    header("Location: depenses.php?company_id=" . (int)$_POST['company_id']);
    exit;
}

// =========================
// SUPPRESSION DÉPENSE
// =========================
if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("DELETE FROM expenses WHERE id = :id");
    $stmt->execute([':id' => (int)$_GET['delete']]);
    header("Location: depenses.php");
    exit;
}

// =========================
// SOCIÉTÉS
// =========================
$companies = $pdo->query("
    SELECT id, name FROM companies ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

// =========================
// VILLES
// =========================
$cities = [];
if ($company_id > 0) {
    $stmt = $pdo->prepare("
        SELECT id, name FROM cities WHERE company_id = :company
    ");
    $stmt->execute([':company' => $company_id]);
    $cities = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// =========================
// DÉPENSES
// =========================
$expenses = [];
$total_amount = 0;

if ($company_id > 0) {

    $sql = "
        SELECT
            id,
            category,
            amount,
            expense_date,
            city_id,
            note
        FROM expenses
        WHERE company_id = :company
    ";

    if ($city_id > 0) {
        $sql .= " AND city_id = :city ";
    }

    if ($search !== '') {
        $sql .= " AND category LIKE :search ";
    }

    if ($date_from !== '') {
        $sql .= " AND expense_date >= :date_from ";
    }

    if ($date_to !== '') {
        $sql .= " AND expense_date <= :date_to ";
    }

    $sql .= " ORDER BY expense_date DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':company', $company_id, PDO::PARAM_INT);

    if ($city_id > 0) {
        $stmt->bindValue(':city', $city_id, PDO::PARAM_INT);
    }
    if ($search !== '') {
        $stmt->bindValue(':search', "%$search%");
    }
    if ($date_from !== '') {
        $stmt->bindValue(':date_from', $date_from);
    }
    if ($date_to !== '') {
        $stmt->bindValue(':date_to', $date_to);
    }

    $stmt->execute();
    $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($expenses as $e) {
        $total_amount += $e['amount'];
    }
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Dépenses</title>

<style>
:root{
    --bg:#0b1220;
    --card:#101a2f;
    --glass:rgba(255,255,255,0.08);
    --accent:#3b82f6;
    --danger:#ef4444;
    --text:#e5e7eb;
    --muted:#9ca3af;
}

*{box-sizing:border-box;font-family:system-ui}

body{
    margin:0;
    background:linear-gradient(135deg,#0b1220,#020617);
    color:var(--text);
}

.container{
    max-width:1300px;
    margin:auto;
    padding:30px;
}

h1{
    font-size:26px;
    margin-bottom:20px;
}

/* ================= FILTERS ================= */
.filters{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(180px,1fr));
    gap:15px;
    background:var(--glass);
    padding:20px;
    border-radius:16px;
    backdrop-filter:blur(10px);
}

select,input{
    padding:10px;
    border-radius:10px;
    border:none;
    background:#020617;
    color:var(--text);
}

button{
    padding:10px 16px;
    border-radius:10px;
    border:none;
    cursor:pointer;
    background:var(--accent);
    color:white;
    font-weight:600;
}

button.danger{
    background:var(--danger);
}

/* ================= TABLE ================= */
.table-wrap{
    margin-top:25px;
    background:var(--glass);
    border-radius:16px;
    overflow:hidden;
}

table{
    width:100%;
    border-collapse:collapse;
}

th,td{
    padding:14px;
    text-align:left;
}

th{
    background:#020617;
    color:var(--muted);
    font-size:14px;
}

tr:hover{
    background:rgba(255,255,255,0.05);
}

/* ================= TOTAL ================= */
.total{
    margin-top:15px;
    font-size:20px;
    font-weight:700;
    text-align:right;
}

/* ================= MODAL ================= */
.modal{
    position:fixed;
    inset:0;
    display:none;
    background:rgba(0,0,0,0.6);
    align-items:center;
    justify-content:center;
}

.modal-content{
    background:var(--card);
    padding:25px;
    border-radius:16px;
    width:400px;
}

.modal-content h3{
    margin-top:0;
}

.modal-content input, .modal-content select{
    width:100%;
    margin-bottom:12px;
}
</style>
</head>

<body>

<div class="container">
<h1>💸 Gestion des dépenses</h1>

<!-- ================= FILTERS ================= -->
<form method="GET" class="filters">
    <select name="company_id" onchange="this.form.submit()">
        <option value="0">Entreprise</option>
        <?php foreach($companies as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $company_id==$c['id']?'selected':'' ?>>
                <?= htmlspecialchars($c['name']) ?>
            </option>
        <?php endforeach ?>
    </select>

    <select name="city_id" onchange="this.form.submit()">
        <option value="0">Ville</option>
        <?php foreach($cities as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $city_id==$c['id']?'selected':'' ?>>
                <?= htmlspecialchars($c['name']) ?>
            </option>
        <?php endforeach ?>
    </select>

    <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
    <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
    <input type="text" name="search" placeholder="Recherche..." value="<?= htmlspecialchars($search) ?>">
    <button type="submit">Filtrer</button>
    <button type="button" onclick="openModal()">+ Dépense</button>
</form>

<!-- ================= TABLE ================= -->
<div class="table-wrap">
<table>
<thead>
<tr>
    <th>Date</th>
    <th>Libellé</th>
    <th>Catégorie</th>
    <th>Ville</th>
    <th>Montant</th>
    <th></th>
</tr>
</thead>
<tbody>
<?php foreach($expenses as $e): ?>
<tr>
    <td><?= $e['expense_date'] ?></td>
    <td><?= htmlspecialchars($e['note']) ?></td>
    <td><?= htmlspecialchars($e['category']) ?></td>
    <td><?= htmlspecialchars($e['city'] ?? '-') ?></td>
    <td><?= number_format($e['amount'],0,' ',' ') ?> FCFA</td>
    <td>
        <button class="danger" onclick="confirmDelete(<?= $e['id'] ?>)">✖</button>
    </td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>

<div class="total">
    Total : <?= number_format($total_amount,0,' ',' ') ?> FCFA
</div>
</div>

<!-- ================= MODAL ADD ================= -->
<div class="modal" id="modal">
<div class="modal-content">
<h3>Ajouter une dépense</h3>

<form method="POST">
<input type="hidden" name="add_expense" value="1">
<input type="hidden" name="company_id" value="<?= $company_id ?>">

<select name="city_id">
    <option value="">Ville</option>
    <?php foreach($cities as $c): ?>
        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
    <?php endforeach ?>
</select>

<select name="category_id" required>
    <?php foreach($categories as $cat): ?>
        <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
    <?php endforeach ?>
</select>

<input type="text" name="label" placeholder="Libellé" required>
<input type="number" name="amount" placeholder="Montant" required>
<input type="date" name="expense_date" required>

<button type="submit">Enregistrer</button>
<button type="button" class="danger" onclick="closeModal()">Annuler</button>
</form>
</div>
</div>

<script>
function openModal(){
    document.getElementById('modal').style.display='flex';
}
function closeModal(){
    document.getElementById('modal').style.display='none';
}
function confirmDelete(id){
    if(confirm("Supprimer cette dépense ?")){
        window.location = "depense.php?delete="+id;
    }
}
</script>

</body>
</html>
