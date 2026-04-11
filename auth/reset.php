
     <?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once APP_ROOT . '/app/core/DB.php';
require_once APP_ROOT . '/app/core/Auth.php';
require_once APP_ROOT . '/app/core/Middleware.php';

use App\Core\DB;
use App\Core\Auth;
use App\Core\Middleware;

/* =========================
   SÉCURITÉ
========================= */
Auth::check();
Middleware::role(['developer']);

/* =========================
   CONNEXION
========================= */
$pdo = DB::getConnection();

/* =========================
   LISTES
========================= */
$companies = $pdo->query("SELECT id,name FROM companies ORDER BY name")
                 ->fetchAll(PDO::FETCH_ASSOC);

$company_id = (int)($_POST['company_id'] ?? 0);
$city_id    = (int)($_POST['city_id'] ?? 0);

$cities = [];

if ($company_id) {
    $stmt = $pdo->prepare("SELECT id,name FROM cities WHERE company_id=? ORDER BY name");
    $stmt->execute([$company_id]);
    $cities = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$message = '';

/* =========================
   RESET
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset'])) {

    if (!$company_id || !$city_id) {
        $message = "❌ Sélection invalide.";
    } else {

        try {

            $pdo->beginTransaction();

            /* ========= 1. FACTURES ========= */

            $stmt = $pdo->prepare("
                SELECT id FROM invoices
                WHERE company_id=? AND city_id=?
            ");
            $stmt->execute([$company_id,$city_id]);
            $invoice_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if ($invoice_ids) {

                $in = implode(',', array_fill(0,count($invoice_ids),'?'));

                $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id IN ($in)")
                    ->execute($invoice_ids);

                $pdo->prepare("DELETE FROM invoice_payments WHERE invoice_id IN ($in)")
                    ->execute($invoice_ids);

                $pdo->prepare("DELETE FROM invoices WHERE id IN ($in)")
                    ->execute($invoice_ids);
            }

            /* ========= 2. CLIENTS ========= */

            $stmt = $pdo->prepare("
                SELECT id FROM clients
                WHERE company_id=? AND city_id=?
            ");
            $stmt->execute([$company_id,$city_id]);
            $client_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if ($client_ids) {

                $in = implode(',', array_fill(0,count($client_ids),'?'));

                $pdo->prepare("DELETE FROM client_balances WHERE client_id IN ($in)")
                    ->execute($client_ids);

               
            }

            /* ========= 3. STOCK ========= */

            $pdo->prepare("
                DELETE FROM stock_movements
                WHERE company_id=? AND city_id=?
            ")->execute([$company_id,$city_id]);

            $pdo->prepare("
                DELETE FROM stocks
                WHERE city_id=?
            ")->execute([$city_id]);

            /* ========= 4. AUTRES TABLES LIÉES ========= */

            $tables_company_city = [
                'supplies',
                'expenses'
            ];

            foreach ($tables_company_city as $table) {
                $pdo->prepare("
                    DELETE FROM $table
                    WHERE company_id=? AND city_id=?
                ")->execute([$company_id,$city_id]);
            }

            $tables_city_only = [
                'sales',
                'arrivals'
            ];

            foreach ($tables_city_only as $table) {
                $pdo->prepare("
                    DELETE FROM $table
                    WHERE city_id=?
                ")->execute([$city_id]);
            }

            $pdo->commit();

            $message = "✅ Reset terminé pour cette société / ville uniquement.";

        } catch (Exception $e) {

            $pdo->rollBack();
            $message = "❌ ERREUR : " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Reset Saison</title>

<style>
body{
    margin:0;
    padding:40px;
    font-family:Segoe UI;
    background:#f0fdfa;
    color:#065f46;
}

.box{
    background:#ffffff;
    padding:30px;
    border-radius:10px;
    max-width:600px;
    margin:auto;
    box-shadow:0 4px 15px rgba(0,0,0,0.1);
}

h2{
    margin-bottom:20px;
}

select,button{
    padding:10px;
    margin:10px 0;
    width:100%;
    border-radius:6px;
    border:1px solid #14b8a6;
}

button{
    background:#14b8a6;
    color:white;
    font-weight:bold;
    cursor:pointer;
}

button:hover{
    opacity:0.9;
}

.msg{
    margin-top:15px;
    font-weight:bold;
}
</style>
</head>
<body>

<div class="box">

<h2>🔄 Réinitialisation par Ville</h2>

<form method="POST">

<label>Société</label>
<select name="company_id" onchange="this.form.submit()" required>
<option value="">-- Choisir --</option>
<?php foreach ($companies as $c): ?>
<option value="<?= $c['id'] ?>"
<?= $company_id==$c['id']?'selected':'' ?>>
<?= htmlspecialchars($c['name']) ?>
</option>
<?php endforeach; ?>
</select>

<label>Ville</label>
<select name="city_id" required>
<option value="">-- Choisir --</option>
<?php foreach ($cities as $v): ?>
<option value="<?= $v['id'] ?>"
<?= $city_id==$v['id']?'selected':'' ?>>
<?= htmlspecialchars($v['name']) ?>
</option>
<?php endforeach; ?>
</select>

<button type="submit" name="reset"
onclick="return confirm('⚠️ CONFIRMER LE RESET POUR CETTE VILLE ?')">
🔥 Réinitialiser
</button>

</form>

<?php if ($message): ?>
<div class="msg"><?= $message ?></div>
<?php endif; ?>

</div>

</body>
</html>
