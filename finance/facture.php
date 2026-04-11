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

/* =========================
   FILTRES
========================= */
$company_id = (int)($_GET['company_id'] ?? 0);
$city_id    = (int)($_GET['city_id'] ?? 0);
$client_id  = (int)($_GET['client_id'] ?? 0);

/* =========================
   SOCIÉTÉS
========================= */
$companies = $pdo->query("
    SELECT id,name 
    FROM companies 
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   VILLES
========================= */
$cities = [];
if($company_id){
    $stmt = $pdo->prepare("
        SELECT id,name 
        FROM cities 
        WHERE company_id=? 
        ORDER BY name
    ");
    $stmt->execute([$company_id]);
    $cities = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* =========================
   CLIENTS
========================= */
$clients = [];
if($company_id && $city_id){
    $stmt = $pdo->prepare("
        SELECT id,name 
        FROM clients 
        WHERE company_id=? AND city_id=? 
        ORDER BY name
    ");
    $stmt->execute([$company_id,$city_id]);
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* =========================
   PRODUITS + STOCK
========================= */
$products = [];
if($company_id && $city_id){
    $stmt = $pdo->prepare("
        SELECT 
            p.id,
            p.name,
            p.price,
            COALESCE(s.quantity,0) AS stock
        FROM products p
        LEFT JOIN stocks s 
            ON s.product_id = p.id
           AND s.city_id = ?
        WHERE p.company_id = ?
        ORDER BY p.name
    ");
    $stmt->execute([$city_id,$company_id]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* =========================
   ENREGISTREMENT FACTURE
========================= */
$message = '';

if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_invoice'])){

    $client_id_post  = (int)($_POST['client_id'] ?? 0);
    $company_id_post = (int)($_POST['company_id'] ?? 0);
    $city_id_post    = (int)($_POST['city_id'] ?? 0);
    $items           = $_POST['items'] ?? [];

    if($client_id_post && $company_id_post && $city_id_post && count($items)){

        $pdo->beginTransaction();

        try{
            /* TOTAL FACTURE */
            $total_invoice = 0;
            foreach($items as $it){
                $total_invoice += $it['quantity'] * $it['price'];
            }

            /* INSERT FACTURE */
            $stmt = $pdo->prepare("
                INSERT INTO invoices (client_id,company_id,city_id,total,status)
                VALUES (?,?,?,?, 'Impayée')
            ");
            $stmt->execute([
                $client_id_post,
                $company_id_post,
                $city_id_post,
                $total_invoice
            ]);
            $invoice_id = $pdo->lastInsertId();

            /* ITEMS + SORTIE STOCK */
            foreach($items as $it){

                $pid   = (int)$it['product_id'];
                $qty   = (int)$it['quantity'];
                $price = (float)$it['price'];
                $line_total = $qty * $price;

                /* invoice_items */
                $stmt = $pdo->prepare("
                    INSERT INTO invoice_items
                    (invoice_id,product_id,quantity,price,total)
                    VALUES (?,?,?,?,?)
                ");
                $stmt->execute([
                    $invoice_id,
                    $pid,
                    $qty,
                    $price,
                    $line_total
                ]);

                /* UPDATE STOCK */
                $stmt = $pdo->prepare("
                    UPDATE stocks
                    SET quantity = quantity - ?
                    WHERE product_id=? AND city_id=?
                ");
                $stmt->execute([$qty,$pid,$city_id_post]);

                /* ENREGISTREMENT SORTIE */
                $stmt = $pdo->prepare("
                    INSERT INTO stock_movements
                    (product_id,company_id,city_id,quantity,type,reference)
                    VALUES (?,?,?,?, 'EXIT', ?)
                ");
                $stmt->execute([
                    $pid,
                    $company_id_post,
                    $city_id_post,
                    $qty,
                    'FACTURE #'.$invoice_id
                ]);
            }

            $pdo->commit();
            $message = "✅ Facture enregistrée + sortie stock OK";

        }catch(Exception $e){
            $pdo->rollBack();
            $message = "❌ Erreur : ".$e->getMessage();
        }

    }else{
        $message = "⚠️ Sélectionnez entreprise, ville, client et produits";
    }
}

/* =========================
   HISTORIQUE FACTURES
========================= */
$invoices = $pdo->query("
    SELECT 
        f.id,
        cl.name AS client_name,
        co.name AS company_name,
        ci.name AS city_name,
        f.total,
        f.status,
        f.created_at
    FROM invoices f
    JOIN clients cl ON cl.id=f.client_id
    JOIN companies co ON co.id=f.company_id
    JOIN cities ci ON ci.id=f.city_id
    ORDER BY f.created_at DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Factures</title>
<style>
body{margin:0;padding:20px;background:#ecfeff;font-family:Segoe UI,Arial,sans-serif;color:#134e4a;}
h2{margin-bottom:15px;}
select,input{padding:8px 12px;border-radius:6px;border:1px solid #14b8a6;font-weight:600;}
button{padding:8px 12px;border-radius:6px;background:#14b8a6;color:#fff;border:none;cursor:pointer;font-weight:600;}
.filters,form{display:flex;gap:15px;margin-bottom:20px;flex-wrap:wrap;}
table{border-collapse:collapse;width:100%;background:#fff;font-size:14px;}
th,td{border:1px solid #5eead4;padding:8px;}
thead tr:first-child th{background:#14b8a6;color:#fff;}
.header th{background:#99f6e4;}
.num{background:#ccfbf1;text-align:center;font-weight:bold;}
tbody tr:nth-child(even){background:#f0fdfa;}
tbody tr:hover td{background:#99f6e4;}
.alert-message{margin-bottom:15px;padding:10px;border-radius:6px;background:#d1fae5;color:#065f46;font-weight:bold;}
.actions a{margin-right:10px;color:#065f46;font-weight:bold;text-decoration:none;}
.actions a.delete{color:#991b1b;}
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
<script>
function addProductRow(productId,name,price,stock){
    const tbody = document.getElementById('invoice-items');
    const row = document.createElement('tr');
    row.innerHTML = `
    <td>${name}</td>
    <td><input type="number" name="items[${productId}][quantity]" value="1" min="1" max="${stock}" style="width:60px;"></td>
    <td><input type="number" name="items[${productId}][price]" value="${price}" step="0.01" readonly style="width:80px;"></td>
    <td class="line-total">${price}</td>
    <td><button type="button" onclick="this.closest('tr').remove();updateTotal()">Supprimer</button></td>
    <input type="hidden" name="items[${productId}][product_id]" value="${productId}">
    `;
    tbody.appendChild(row);
    updateTotal();
}
function updateTotal(){
    let total=0;
    document.querySelectorAll('.line-total').forEach(td=>{
        let row = td.parentElement;
        let qty = parseFloat(row.querySelector('input[name$="[quantity]"]').value);
        let price = parseFloat(row.querySelector('input[name$="[price]"]').value);
        td.innerText = (qty*price).toFixed(2);
        total += qty*price;
    });
    document.getElementById('total-invoice').innerText = total.toFixed(2);
}
document.addEventListener('input', e=>{ if(e.target.name.includes('[quantity]')) updateTotal(); });
</script>
</head>
<body>
<p>

  <a href="<?= project_url('finance/caisse_complete_enhanced.php') ?>" class="btn-back">

    ← retour a la caisse

  </a>

</p>
<h2>🧾 Facturation</h2>
<?php if($message): ?><div class="alert-message"><?= $message ?></div><?php endif; ?>

<form method="get" class="filters">
    <select name="company_id" onchange="this.form.submit()">
        <option value="">— Société —</option>
        <?php foreach($companies as $c): ?>
        <option value="<?= $c['id'] ?>" <?= $company_id==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
        <?php endforeach; ?>
    </select>

    <select name="city_id" onchange="this.form.submit()">
        <option value="">— Ville —</option>
        <?php foreach($cities as $v): ?>
        <option value="<?= $v['id'] ?>" <?= $city_id==$v['id']?'selected':'' ?>><?= htmlspecialchars($v['name']) ?></option>
        <?php endforeach; ?>
    </select>

    <select name="client_id" onchange="this.form.submit()">
        <option value="">— Client —</option>
        <?php foreach($clients as $cl): ?>
        <option value="<?= $cl['id'] ?>" <?= $client_id==$cl['id']?'selected':'' ?>><?= htmlspecialchars($cl['name']) ?></option>
        <?php endforeach; ?>
    </select>
</form>

<form method="post">
<input type="hidden" name="company_id" value="<?= $company_id ?>">
<input type="hidden" name="city_id" value="<?= $city_id ?>">
<input type="hidden" name="client_id" value="<?= $client_id ?>">

<h3>Produits disponibles</h3>
<?php foreach($products as $p): ?>
<button type="button" onclick="addProductRow('<?= $p['id'] ?>','<?= addslashes($p['name']) ?>','<?= $p['price'] ?>','<?= $p['stock'] ?>')">
    Ajouter: <?= htmlspecialchars($p['name']) ?> (Stock: <?= $p['stock'] ?>)
</button>
<?php endforeach; ?>

<h3>Facture</h3>
<table>
<thead>
<tr>
<th>Produit</th><th>Quantité</th><th>Prix</th><th>Total</th><th>Actions</th>
</tr>
</thead>
<tbody id="invoice-items">
<!-- Lignes ajoutées ici -->
</tbody>
<tfoot>
<tr>
<th colspan="3">Total Général</th>
<th id="total-invoice">0.00</th>
<th></th>
</tr>
</tfoot>
</table>
<br>
<button type="submit" name="save_invoice">Enregistrer Facture</button>
<button type="button" onclick="window.print()">Imprimer</button>
</form>

<h3>📄 Historique des 20 dernières factures</h3>
<table>
<thead>
<tr>
<th>#</th><th>Client</th><th>Société</th><th>Ville</th><th>Total</th><th>Date</th><th>Actions</th>
</tr>
</thead>
<tbody>
<?php $i=1; foreach($invoices as $inv): ?>
<tr>
<td class="num"><?= $i++ ?></td>
<td><?= htmlspecialchars($inv['client_name']) ?></td>
<td><?= htmlspecialchars($inv['company_name']) ?></td>
<td><?= htmlspecialchars($inv['city_name']) ?></td>
<td><?= $inv['total'] ?></td>
<td><?= $inv['created_at'] ?></td>
<td class="actions">
<a href="invoice_edit.php?id=<?= $inv['id'] ?>">Modifier</a> | 
<a href="invoice_delete.php?id=<?= $inv['id'] ?>" onclick="return confirm('Supprimer cette facture ?')">Supprimer</a> | 
<a href="invoice_pdf.php?id=<?= $inv['id'] ?>" target="_blank">PDF</a>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

</body>
</html>
