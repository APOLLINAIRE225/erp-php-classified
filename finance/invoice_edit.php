<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

// =========================
// REQUIRES ET AUTOLOAD
// =========================
require_once APP_ROOT . '/app/core/DB.php';
require_once APP_ROOT . '/app/core/Auth.php';
require_once APP_ROOT . '/app/core/Middleware.php';
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



$invoice_id = $_GET['id'] ?? 0;
if(!$invoice_id){
    die("Facture non spécifiée !");
}

/* =========================
   Récupération facture + client info
========================= */
$stmt = $pdo->prepare("
    SELECT f.id,f.total,f.created_at,f.client_id,f.company_id,f.city_id,
           cl.name AS client_name,
           co.name AS company_name,
           ci.name AS city_name
    FROM invoices f
    JOIN clients cl ON cl.id=f.client_id
    JOIN companies co ON co.id=f.company_id
    JOIN cities ci ON ci.id=f.city_id
    WHERE f.id = ?
");
$stmt->execute([$invoice_id]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);
if(!$invoice){
    die("Facture introuvable");
}

/* =========================
   Clients / sociétés / villes
========================= */
$clients = $pdo->query("SELECT id,name FROM clients ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$companies = $pdo->query("SELECT id,name FROM companies ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$cities = [];
if($invoice['company_id']){
    $stmt = $pdo->prepare("SELECT id,name FROM cities WHERE company_id=? ORDER BY name");
    $stmt->execute([$invoice['company_id']]);
    $cities = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* =========================
   Produits + articles existants
========================= */
$products = $pdo->query("SELECT id,name,price FROM products ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT i.id,i.product_id,i.quantity,i.price,i.total,p.name
    FROM invoice_items i
    JOIN products p ON p.id=i.product_id
    WHERE i.invoice_id = ?
");
$stmt->execute([$invoice_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   Mise à jour facture
========================= */
if($_SERVER['REQUEST_METHOD']=='POST'){
    $client_id = $_POST['client_id'];
    $company_id = $_POST['company_id'];
    $city_id = $_POST['city_id'];
    $products_post = $_POST['product_id'] ?? [];
    $quantities_post = $_POST['quantity'] ?? [];
    $prices_post = $_POST['price'] ?? [];

    // Calcul total
    $total = 0;
    foreach($products_post as $k=>$pid){
        $total += $quantities_post[$k] * $prices_post[$k];
    }

    $pdo->beginTransaction();
    try{
        // Update facture
        $stmt = $pdo->prepare("UPDATE invoices SET client_id=?, company_id=?, city_id=?, total=? WHERE id=?");
        $stmt->execute([$client_id,$company_id,$city_id,$total,$invoice_id]);

        // Supprime anciens articles
        $stmt = $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id=?");
        $stmt->execute([$invoice_id]);

        // Insert nouveaux articles
        $stmt = $pdo->prepare("INSERT INTO invoice_items(invoice_id,product_id,quantity,price,total) VALUES(?,?,?,?,?)");
        foreach($products_post as $k=>$pid){
            $stmt->execute([$invoice_id, $pid, $quantities_post[$k], $prices_post[$k], $quantities_post[$k]*$prices_post[$k]]);
        }

        $pdo->commit();
        header("Location: facture.php?msg=updated");
        exit;

    } catch(Exception $e){
        $pdo->rollBack();
        die("Erreur lors de la mise à jour : ".$e->getMessage());
    }
}

?>

<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Modifier Facture #<?= $invoice['id'] ?></title>
<style>
body{font-family:Segoe UI,Arial,sans-serif;background:#ecfeff;color:#134e4a;padding:20px;}
form{margin-bottom:20px;}
select,input{padding:6px 10px;border-radius:6px;border:1px solid #14b8a6;font-weight:600;margin-right:5px;}
table{border-collapse:collapse;width:100%;background:#fff;font-size:14px;}
th,td{border:1px solid #5eead4;padding:8px;}
thead tr:first-child th{background:#14b8a6;color:#fff;}
tbody tr:nth-child(even){background:#f0fdfa;}
tbody tr:hover td{background:#99f6e4;}
button{padding:5px 10px;border:none;border-radius:5px;background:#14b8a6;color:#fff;cursor:pointer;margin-right:5px;}
button:hover{background:#0d9488;}
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
</style>
</head>
<body>
<p>

  <a href="facture.php" class="btn-back">

    ← Retour sur facturation

  </a>

</p>


<h2>✏️ Modifier Facture #<?= $invoice['id'] ?></h2>

<form method="post">
    <label>Client:
        <select name="client_id" required>
            <?php foreach($clients as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $invoice['client_id']==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>

    <label>Société:
        <select name="company_id" required onchange="this.form.submit()">
            <?php foreach($companies as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $invoice['company_id']==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>

    <label>Ville:
        <select name="city_id" required>
            <?php foreach($cities as $v): ?>
            <option value="<?= $v['id'] ?>" <?= $invoice['city_id']==$v['id']?'selected':'' ?>><?= htmlspecialchars($v['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>

    <h3>Articles</h3>
    <table id="items_table">
        <thead>
            <tr>
                <th>Produit</th><th>Quantité</th><th>Prix</th><th>Total</th><th>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach($items as $i=>$it): ?>
            <tr>
                <td>
                    <select name="product_id[]">
                        <?php foreach($products as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $p['id']==$it['product_id']?'selected':'' ?>><?= htmlspecialchars($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td><input type="number" name="quantity[]" value="<?= $it['quantity'] ?>" min="1"></td>
                <td><input type="number" step="0.01" name="price[]" value="<?= $it['price'] ?>" min="0"></td>
                <td><?= number_format($it['total'],2) ?></td>
                <td><button type="button" onclick="removeRow(this)">Supprimer</button></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <button type="button" onclick="addRow()">Ajouter Produit</button>
    <br><br>
    <button type="submit">Mettre à jour Facture</button>
</form>

<script>
function addRow(){
    var table=document.getElementById('items_table').getElementsByTagName('tbody')[0];
    var row=table.insertRow();
    row.innerHTML=`<td>
        <select name="product_id[]"><?php foreach($products as $p): ?><option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option><?php endforeach; ?></select>
    </td>
    <td><input type="number" name="quantity[]" value="1" min="1"></td>
    <td><input type="number" step="0.01" name="price[]" value="0.00" min="0"></td>
    <td>0.00</td>
    <td><button type="button" onclick="removeRow(this)">Supprimer</button></td>`;
}
function removeRow(btn){btn.closest('tr').remove();}
</script>

</body>
</html>
