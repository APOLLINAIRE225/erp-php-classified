<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * DIAGNOSTIC PAIEMENT - Trouver le problème
 * ═══════════════════════════════════════════════════════════════════════════
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once APP_ROOT . '/app/core/DB.php';
use App\Core\DB;

$pdo = DB::getConnection();

echo "<h1>🔍 Diagnostic Système de Paiement</h1>";
echo "<style>
body { font-family: Arial; padding: 20px; background: #f5f5f5; }
h2 { background: #667eea; color: white; padding: 10px; border-radius: 5px; }
.success { background: #d1fae5; padding: 10px; margin: 10px 0; border-left: 4px solid #10b981; }
.error { background: #fee2e2; padding: 10px; margin: 10px 0; border-left: 4px solid #ef4444; }
.warning { background: #fef3c7; padding: 10px; margin: 10px 0; border-left: 4px solid #f59e0b; }
table { background: white; width: 100%; border-collapse: collapse; margin: 10px 0; }
th, td { padding: 8px; border: 1px solid #ddd; text-align: left; }
th { background: #667eea; color: white; }
code { background: #f1f1f1; padding: 2px 6px; border-radius: 3px; }
</style>";

echo "<h2>1️⃣ Vérification Table PAYROLL</h2>";

// Vérifier colonnes payroll
$stmt = $pdo->query("DESCRIBE payroll");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table><tr><th>Colonne</th><th>Type</th><th>Null</th><th>Default</th></tr>";
foreach ($columns as $col) {
    echo "<tr>";
    echo "<td><strong>" . $col['Field'] . "</strong></td>";
    echo "<td>" . $col['Type'] . "</td>";
    echo "<td>" . $col['Null'] . "</td>";
    echo "<td>" . ($col['Default'] ?? 'NULL') . "</td>";
    echo "</tr>";
}
echo "</table>";

// Vérifier colonnes critiques
$required_columns = ['id', 'employee_id', 'month', 'base_salary', 'net_salary', 'status', 'overtime_amount', 'advances_deduction', 'absences_deduction', 'payment_date', 'paid_by'];
$existing_columns = array_column($columns, 'Field');

echo "<h3>Colonnes Requises:</h3>";
foreach ($required_columns as $req_col) {
    if (in_array($req_col, $existing_columns)) {
        echo "<div class='success'>✅ <code>$req_col</code> existe</div>";
    } else {
        echo "<div class='error'>❌ <code>$req_col</code> MANQUANTE !</div>";
    }
}

echo "<h2>2️⃣ Vérification Table PAYMENT_TRANSACTIONS</h2>";

try {
    $stmt = $pdo->query("DESCRIBE payment_transactions");
    $pt_columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<div class='success'>✅ Table payment_transactions existe</div>";
    echo "<table><tr><th>Colonne</th><th>Type</th></tr>";
    foreach ($pt_columns as $col) {
        echo "<tr><td><strong>" . $col['Field'] . "</strong></td><td>" . $col['Type'] . "</td></tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "<div class='error'>❌ Table payment_transactions n'existe pas ou erreur: " . $e->getMessage() . "</div>";
}

echo "<h2>3️⃣ Test d'Insertion</h2>";

try {
    // Vérifier qu'il y a des paies en attente
    $stmt = $pdo->query("SELECT COUNT(*) FROM payroll WHERE status = 'impaye'");
    $count = $stmt->fetchColumn();
    
    if ($count > 0) {
        echo "<div class='success'>✅ Il y a <strong>$count</strong> paie(s) en attente</div>";
        
        // Récupérer une paie pour test
        $stmt = $pdo->query("SELECT p.*, e.full_name FROM payroll p JOIN employees e ON p.employee_id = e.id WHERE p.status = 'impaye' LIMIT 1");
        $test_payroll = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo "<div class='warning'>📋 Exemple de paie à payer:<br>";
        echo "Employé: <strong>" . $test_payroll['full_name'] . "</strong><br>";
        echo "Montant: <strong>" . number_format($test_payroll['net_salary'], 0) . " FCFA</strong><br>";
        echo "Status actuel: <strong>" . $test_payroll['status'] . "</strong></div>";
        
    } else {
        echo "<div class='warning'>⚠️ Aucune paie en attente (status = 'impaye')</div>";
    }
    
} catch (Exception $e) {
    echo "<div class='error'>❌ Erreur lors de la vérification: " . $e->getMessage() . "</div>";
}

echo "<h2>4️⃣ Vérification Variables POST</h2>";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "<div class='success'>✅ Requête POST reçue</div>";
    echo "<h3>Données POST reçues:</h3>";
    echo "<pre>";
    print_r($_POST);
    echo "</pre>";
} else {
    echo "<div class='warning'>ℹ️ Aucune requête POST (normal pour la page de diagnostic)</div>";
}

echo "<h2>5️⃣ Test de Transaction Manuelle</h2>";

echo "<form method='POST' action=''>";
echo "<p>Voulez-vous tester manuellement l'insertion d'une transaction de paiement ?</p>";
echo "<button type='submit' name='test_insert' style='background: #10b981; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer;'>🧪 Tester Insertion</button>";
echo "</form>";

if (isset($_POST['test_insert'])) {
    echo "<h3>🧪 Test d'insertion...</h3>";
    
    try {
        $pdo->beginTransaction();
        
        // Récupérer une paie
        $stmt = $pdo->query("SELECT * FROM payroll WHERE status = 'impaye' LIMIT 1");
        $payroll = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$payroll) {
            throw new Exception("Aucune paie en attente à tester !");
        }
        
        echo "<div class='success'>✅ Paie trouvée (ID: {$payroll['id']})</div>";
        
        // Générer numéro reçu
        $receipt_number = 'TEST-' . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3)));
        
        echo "<div class='success'>✅ Numéro reçu généré: <code>$receipt_number</code></div>";
        
        // Insérer transaction
        $stmt = $pdo->prepare("
            INSERT INTO payment_transactions 
            (payroll_id, employee_id, amount, payment_method, paid_by, paid_at, signature_data, notes, receipt_number)
            VALUES (?, ?, ?, 'especes', 1, NOW(), 'data:image/png;base64,test', 'Test de diagnostic', ?)
        ");
        $stmt->execute([
            $payroll['id'],
            $payroll['employee_id'],
            $payroll['net_salary'],
            $receipt_number
        ]);
        
        $transaction_id = $pdo->lastInsertId();
        echo "<div class='success'>✅ Transaction créée (ID: $transaction_id)</div>";
        
        // Mettre à jour payroll
        $stmt = $pdo->prepare("UPDATE payroll SET status = 'paye', payment_date = NOW(), paid_by = 1 WHERE id = ?");
        $stmt->execute([$payroll['id']]);
        
        echo "<div class='success'>✅ Paie marquée comme payée</div>";
        
        $pdo->commit();
        
        echo "<div class='success' style='font-size: 18px; font-weight: bold;'>
        🎉 TEST RÉUSSI ! Le système fonctionne correctement !<br><br>
        Le problème vient probablement:<br>
        - Du CSRF token invalide<br>
        - Des données POST manquantes<br>
        - D'une erreur silencieuse dans le code
        </div>";
        
        echo "<p><a href='cashier_payment_pro.php' style='background: #667eea; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>💰 Aller à la Caisse</a></p>";
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "<div class='error'>❌ ERREUR lors du test: " . $e->getMessage() . "</div>";
        echo "<div class='error'><pre>" . $e->getTraceAsString() . "</pre></div>";
    }
}

echo "<h2>6️⃣ Vérification CSRF Token</h2>";
if (isset($_SESSION['csrf_token'])) {
    echo "<div class='success'>✅ CSRF Token existe: <code>" . substr($_SESSION['csrf_token'], 0, 20) . "...</code></div>";
} else {
    echo "<div class='error'>❌ CSRF Token manquant dans la session !</div>";
}

echo "<h2>7️⃣ Recommandations</h2>";
echo "<div class='warning'>
<h3>Si le test manuel fonctionne mais pas depuis la page caisse:</h3>
<ol>
<li>Vérifiez les logs d'erreur PHP</li>
<li>Ouvrez la console du navigateur (F12) et regardez les erreurs JavaScript</li>
<li>Vérifiez que le formulaire envoie bien toutes les données (Network tab dans F12)</li>
<li>Vérifiez que le CSRF token est bien envoyé</li>
</ol>
</div>";

echo "<hr>";
echo "<p><a href='cashier_payment_pro.php'>← Retour à la Caisse</a></p>";
?>
