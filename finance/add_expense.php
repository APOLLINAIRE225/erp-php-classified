<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once APP_ROOT . '/app/core/DB.php';
require_once APP_ROOT . '/app/core/Auth.php';
require_once APP_ROOT . '/app/core/Middleware.php';

use App\Core\DB;
use App\Core\Auth;
use App\Core\Middleware;

/* ================== SÉCURITÉ ================== */
Auth::check();
Middleware::role(['developer','manager']);

$pdo = DB::getConnection();


if($_SERVER['REQUEST_METHOD']==='POST'){
    $stmt = $pdo->prepare("
        INSERT INTO expenses(company_id,city_id,category,amount,note)
        VALUES (?,?,?,?,?)
    ");
    $stmt->execute([
        $_POST['company_id'],
        $_POST['city_id'],
        $_POST['category'],
        $_POST['amount'],
        $_POST['note']
    ]);
    header("Location: " . project_url('finance/caisse_complete_enhanced.php') . "?ok=1");
    exit;
}
?>

<form method="post">
    <h2>Ajouter une dépense</h2>

    <select name="company_id" required>
        <?php foreach($pdo->query("SELECT * FROM companies") as $c): ?>
            <option value="<?= $c['id'] ?>"><?= $c['name'] ?></option>
        <?php endforeach ?>
    </select>

    <select name="city_id" required></select>

    <input type="text" name="category" placeholder="Catégorie" required>
    <input type="number" name="amount" placeholder="Montant CFA" required>
    <textarea name="note" placeholder="Note"></textarea>

    <button type="submit">💾 Enregistrer</button>
</form>
