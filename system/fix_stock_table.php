<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

/****************************************************************
* SCRIPT DE CORRECTION - TABLE stock_movements
* Ajoute le type 'initial' au champ type
****************************************************************/

session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once APP_ROOT . '/app/core/DB.php';
require_once APP_ROOT . '/app/core/Auth.php';
require_once APP_ROOT . '/app/core/Middleware.php';

use App\Core\DB;
use App\Core\Auth;
use App\Core\Middleware;

Auth::check();
Middleware::role(['developer', 'admin']);

$pdo = DB::getConnection();

echo "<!DOCTYPE html>
<html lang='fr'>
<head>
<meta charset='UTF-8'>
<meta name='viewport' content='width=device-width, initial-scale=1.0'>
<title>Fix Stock Movements Table</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    font-family: 'Segoe UI', sans-serif;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    min-height: 100vh;
    padding: 40px;
    display: flex;
    justify-content: center;
    align-items: center;
}
.container {
    background: white;
    padding: 40px;
    border-radius: 20px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
    max-width: 800px;
    width: 100%;
}
h1 {
    color: #0f172a;
    margin-bottom: 30px;
    font-size: 28px;
}
.step {
    background: #f8fafc;
    padding: 20px;
    margin-bottom: 20px;
    border-radius: 12px;
    border-left: 4px solid #3b82f6;
}
.step h3 {
    color: #1e40af;
    margin-bottom: 10px;
    font-size: 18px;
}
.success {
    background: #d1fae5;
    border-left-color: #10b981;
}
.success h3 {
    color: #065f46;
}
.error {
    background: #fee2e2;
    border-left-color: #ef4444;
}
.error h3 {
    color: #991b1b;
}
.warning {
    background: #fef3c7;
    border-left-color: #f59e0b;
}
.warning h3 {
    color: #92400e;
}
code {
    background: #0f172a;
    color: #10b981;
    padding: 2px 8px;
    border-radius: 4px;
    font-family: 'Courier New', monospace;
}
pre {
    background: #0f172a;
    color: #10b981;
    padding: 15px;
    border-radius: 8px;
    overflow-x: auto;
    margin: 15px 0;
    font-size: 14px;
}
.btn {
    display: inline-block;
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: white;
    padding: 12px 24px;
    border-radius: 10px;
    text-decoration: none;
    font-weight: 600;
    margin-top: 20px;
    transition: all 0.3s;
}
.btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(59, 130, 246, 0.4);
}
</style>
</head>
<body>
<div class='container'>
<h1>🔧 Correction Table stock_movements</h1>
";

try {
    // Étape 1: Vérifier la structure actuelle
    echo "<div class='step'>
        <h3>📋 Étape 1: Vérification structure actuelle</h3>";
    
    $stmt = $pdo->query("DESCRIBE stock_movements");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $typeColumn = null;
    foreach ($columns as $col) {
        if ($col['Field'] === 'type') {
            $typeColumn = $col;
            break;
        }
    }
    
    if (!$typeColumn) {
        throw new Exception("Colonne 'type' introuvable dans la table stock_movements");
    }
    
    echo "<p><strong>Type actuel:</strong> <code>{$typeColumn['Type']}</code></p>";
    
    // Vérifier si 'initial' est déjà présent
    if (strpos($typeColumn['Type'], 'initial') !== false) {
        echo "</div><div class='step success'>
            <h3>✅ Le type 'initial' est déjà présent!</h3>
            <p>La table est déjà correctement configurée.</p>
        </div>";
    } else {
        echo "</div>";
        
        // Étape 2: Appliquer la correction
        echo "<div class='step warning'>
            <h3>⚠️ Étape 2: Application de la correction</h3>
            <p>Ajout du type 'initial' au champ type...</p>
        </div>";
        
        // Déterminer si c'est un ENUM ou VARCHAR
        if (strpos($typeColumn['Type'], 'enum') !== false) {
            // C'est un ENUM
            $sql = "ALTER TABLE stock_movements MODIFY COLUMN type ENUM('initial', 'entry', 'exit') NOT NULL";
            echo "<div class='step'>
                <h3>🔄 Modification ENUM détectée</h3>
                <pre>$sql</pre>
            </div>";
        } else {
            // C'est probablement un VARCHAR
            $sql = "ALTER TABLE stock_movements MODIFY COLUMN type VARCHAR(20) NOT NULL";
            echo "<div class='step'>
                <h3>🔄 Modification VARCHAR</h3>
                <pre>$sql</pre>
            </div>";
        }
        
        // Exécuter la modification
        $pdo->exec($sql);
        
        echo "<div class='step success'>
            <h3>✅ Étape 3: Correction appliquée avec succès!</h3>
            <p>La colonne 'type' accepte maintenant les valeurs: <code>initial</code>, <code>entry</code>, <code>exit</code></p>
        </div>";
        
        // Vérification finale
        $stmt = $pdo->query("DESCRIBE stock_movements");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($columns as $col) {
            if ($col['Field'] === 'type') {
                echo "<div class='step success'>
                    <h3>📊 Structure finale</h3>
                    <p><strong>Type:</strong> <code>{$col['Type']}</code></p>
                    <p><strong>Null:</strong> <code>{$col['Null']}</code></p>
                    <p><strong>Default:</strong> <code>{$col['Default']}</code></p>
                </div>";
                break;
            }
        }
    }
    
    echo "<div class='step success'>
        <h3>🎉 Tout est prêt!</h3>
        <p>Vous pouvez maintenant utiliser le stock initial sans problème.</p>
        <a href='stock_update_fixed.php' class='btn'>📦 Retour à la Gestion de Stock</a>
    </div>";
    
} catch (Exception $e) {
    echo "<div class='step error'>
        <h3>❌ Erreur</h3>
        <p>{$e->getMessage()}</p>
        <pre>{$e->getTraceAsString()}</pre>
    </div>";
    
    echo "<div class='step warning'>
        <h3>🔧 Solution manuelle</h3>
        <p>Exécutez cette commande SQL manuellement dans phpMyAdmin:</p>
        <pre>ALTER TABLE stock_movements 
MODIFY COLUMN type ENUM('initial', 'entry', 'exit') NOT NULL;</pre>
        <p>Ou si c'est un VARCHAR:</p>
        <pre>ALTER TABLE stock_movements 
MODIFY COLUMN type VARCHAR(20) NOT NULL;</pre>
    </div>";
}

echo "</div>
</body>
</html>";
?>
