<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * SCRIPT DE VÉRIFICATION ET CORRECTION - BASE DE DONNÉES
 * ═══════════════════════════════════════════════════════════════════════════
 * À exécuter UNE FOIS pour corriger les problèmes de structure
 * ═══════════════════════════════════════════════════════════════════════════
 */

require_once APP_ROOT . '/app/core/DB.php';
use App\Core\DB;

$pdo = DB::getConnection();

echo "<h1>🔧 Vérification et Correction de la Base de Données</h1>";
echo "<hr>";

// ═══════════════════════════════════════════════════════════════════════════
// 1. VÉRIFIER ET CORRIGER TABLE PAYROLL
// ═══════════════════════════════════════════════════════════════════════════
echo "<h2>📋 Table PAYROLL</h2>";

try {
    // Vérifier si la table existe
    $stmt = $pdo->query("SHOW TABLES LIKE 'payroll'");
    if ($stmt->rowCount() == 0) {
        echo "⚠️ Table payroll n'existe pas. Création en cours...<br>";
        
        $pdo->exec("
        CREATE TABLE payroll (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            month VARCHAR(7) NOT NULL COMMENT 'Format: YYYY-MM',
            base_salary DECIMAL(12,2) NOT NULL,
            overtime_amount DECIMAL(12,2) DEFAULT 0,
            advances_deduction DECIMAL(12,2) DEFAULT 0,
            absences_deduction DECIMAL(12,2) DEFAULT 0,
            net_salary DECIMAL(12,2) NOT NULL,
            status ENUM('impaye', 'paye') DEFAULT 'impaye',
            payment_date DATETIME NULL,
            paid_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_payroll (employee_id, month),
            FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
            INDEX idx_month (month),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Paie des employés'
        ");
        
        echo "✅ Table payroll créée avec succès !<br>";
    } else {
        echo "✓ Table payroll existe<br>";
        
        // Vérifier et corriger la colonne status
        echo "Vérification de la colonne status...<br>";
        
        try {
            $pdo->exec("
                ALTER TABLE payroll 
                MODIFY COLUMN status ENUM('impaye', 'paye') DEFAULT 'impaye'
            ");
            echo "✅ Colonne status corrigée ! (valeurs: 'impaye', 'paye')<br>";
        } catch (Exception $e) {
            if (strpos($e->getMessage(), "Duplicate column name") !== false) {
                echo "✓ Colonne status déjà correcte<br>";
            } else {
                echo "⚠️ Attention: " . $e->getMessage() . "<br>";
            }
        }
        
        // Mettre à jour les anciennes valeurs si nécessaire
        $stmt = $pdo->query("SELECT COUNT(*) FROM payroll WHERE status NOT IN ('impaye', 'paye')");
        $count = $stmt->fetchColumn();
        
        if ($count > 0) {
            echo "⚠️ Trouvé $count ligne(s) avec status invalide. Correction...<br>";
            $pdo->exec("UPDATE payroll SET status = 'impaye' WHERE status NOT IN ('impaye', 'paye')");
            echo "✅ Lignes corrigées !<br>";
        }
    }
    
    // Afficher la structure actuelle
    echo "<br><strong>Structure actuelle de payroll:</strong><br>";
    $stmt = $pdo->query("DESCRIBE payroll");
    echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "<tr>";
        echo "<td>" . $row['Field'] . "</td>";
        echo "<td>" . $row['Type'] . "</td>";
        echo "<td>" . $row['Null'] . "</td>";
        echo "<td>" . $row['Key'] . "</td>";
        echo "<td>" . ($row['Default'] ?? 'NULL') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
} catch (Exception $e) {
    echo "❌ Erreur: " . $e->getMessage() . "<br>";
}

echo "<hr>";

// ═══════════════════════════════════════════════════════════════════════════
// 2. VÉRIFIER ET CRÉER TABLE PAYMENT_TRANSACTIONS
// ═══════════════════════════════════════════════════════════════════════════
echo "<h2>💰 Table PAYMENT_TRANSACTIONS</h2>";

try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'payment_transactions'");
    if ($stmt->rowCount() == 0) {
        echo "⚠️ Table payment_transactions n'existe pas. Création en cours...<br>";
        
        $pdo->exec("
        CREATE TABLE payment_transactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            payroll_id INT NOT NULL,
            employee_id INT NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            payment_method ENUM('especes', 'mobile_money', 'virement', 'cheque') DEFAULT 'especes',
            paid_by INT NOT NULL,
            paid_at DATETIME NOT NULL,
            signature_data TEXT NULL COMMENT 'Signature électronique base64',
            notes TEXT NULL,
            receipt_number VARCHAR(50) NOT NULL UNIQUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_payroll (payroll_id),
            INDEX idx_employee (employee_id),
            INDEX idx_receipt (receipt_number),
            FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
            FOREIGN KEY (paid_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Transactions de paiement main à main'
        ");
        
        echo "✅ Table payment_transactions créée avec succès !<br>";
    } else {
        echo "✓ Table payment_transactions existe<br>";
    }
    
} catch (Exception $e) {
    echo "❌ Erreur: " . $e->getMessage() . "<br>";
}

echo "<hr>";

// ═══════════════════════════════════════════════════════════════════════════
// 3. STATISTIQUES
// ═══════════════════════════════════════════════════════════════════════════
echo "<h2>📊 Statistiques</h2>";

try {
    // Nombre d'employés actifs
    $stmt = $pdo->query("SELECT COUNT(*) FROM employees WHERE status = 'actif'");
    $active_employees = $stmt->fetchColumn();
    echo "👥 Employés actifs: <strong>$active_employees</strong><br>";
    
    // Paies en attente
    $stmt = $pdo->query("SELECT COUNT(*) FROM payroll WHERE status = 'impaye'");
    $unpaid = $stmt->fetchColumn();
    echo "💸 Paies en attente: <strong>$unpaid</strong><br>";
    
    // Paies payées
    $stmt = $pdo->query("SELECT COUNT(*) FROM payroll WHERE status = 'paye'");
    $paid = $stmt->fetchColumn();
    echo "✅ Paies effectuées: <strong>$paid</strong><br>";
    
    // Total transactions
    $stmt = $pdo->query("SELECT COUNT(*) FROM payment_transactions");
    $transactions = $stmt->fetchColumn();
    echo "🧾 Transactions enregistrées: <strong>$transactions</strong><br>";
    
} catch (Exception $e) {
    echo "❌ Erreur: " . $e->getMessage() . "<br>";
}

echo "<hr>";
echo "<h2>✅ Vérification Terminée !</h2>";
echo "<p>Votre base de données est maintenant prête à fonctionner correctement.</p>";
echo "<p><a href='employees_unified_rh_pro.php'>← Retour à la page RH</a> | ";
echo "<a href='cashier_payment_pro.php'>💰 Aller à la Caisse</a></p>";
?>
<style>
body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    padding: 30px;
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: #333;
}
h1 { color: #fff; }
h2 { 
    background: #fff; 
    padding: 10px 15px; 
    border-radius: 8px;
    color: #667eea;
    margin-top: 20px;
}
table {
    background: white;
    margin: 10px 0;
}
hr {
    border: none;
    border-top: 2px solid rgba(255,255,255,0.3);
    margin: 30px 0;
}
a {
    color: #667eea;
    font-weight: bold;
    text-decoration: none;
    padding: 10px 20px;
    background: white;
    border-radius: 5px;
    display: inline-block;
    margin: 5px;
}
a:hover {
    background: #f0f0f0;
}
</style>
