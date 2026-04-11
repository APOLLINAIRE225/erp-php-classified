<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * SCRIPT DE DIAGNOSTIC - VÉRIFICATION DES DÉPARTS
 * ═══════════════════════════════════════════════════════════════════════════
 */

session_start();
require_once APP_ROOT . '/app/core/DB.php';
use App\Core\DB;

$pdo = DB::getConnection();

echo "<html><head><meta charset='UTF-8'>";
echo "<style>
body { font-family: monospace; background: #1e293b; color: #fff; padding: 20px; }
.success { color: #10b981; }
.error { color: #ef4444; }
.warning { color: #f59e0b; }
.info { color: #3b82f6; }
table { border-collapse: collapse; width: 100%; margin: 20px 0; background: #334155; }
th, td { border: 1px solid #475569; padding: 10px; text-align: left; }
th { background: #1e293b; }
h2 { color: #10b981; border-bottom: 2px solid #10b981; padding-bottom: 10px; }
</style></head><body>";

echo "<h1>🔍 DIAGNOSTIC DES DÉPARTS</h1>";
echo "<p>Date: " . date('Y-m-d H:i:s') . "</p>";

echo "<hr>";

// ═══════════════════════════════════════════════════════════════════════
// 1. VÉRIFIER LA STRUCTURE DE LA TABLE
// ═══════════════════════════════════════════════════════════════════════
echo "<h2>1️⃣ STRUCTURE DE LA TABLE ATTENDANCE</h2>";

$columns = $pdo->query("DESCRIBE attendance")->fetchAll(PDO::FETCH_ASSOC);
echo "<table>";
echo "<tr><th>Colonne</th><th>Type</th><th>Null</th><th>Défaut</th></tr>";

$required_columns = ['check_out', 'checkout_latitude', 'checkout_longitude', 'checkout_selfie_path'];
$found_columns = [];

foreach($columns as $col) {
    $is_required = in_array($col['Field'], $required_columns);
    $class = $is_required ? 'success' : '';
    
    if($is_required) {
        $found_columns[] = $col['Field'];
    }
    
    echo "<tr class='$class'>";
    echo "<td><strong>{$col['Field']}</strong></td>";
    echo "<td>{$col['Type']}</td>";
    echo "<td>{$col['Null']}</td>";
    echo "<td>{$col['Default']}</td>";
    echo "</tr>";
}
echo "</table>";

$missing = array_diff($required_columns, $found_columns);
if(empty($missing)) {
    echo "<p class='success'>✅ Toutes les colonnes requises sont présentes !</p>";
} else {
    echo "<p class='error'>❌ Colonnes manquantes : " . implode(', ', $missing) . "</p>";
}

// ═══════════════════════════════════════════════════════════════════════
// 2. COMPTER LES POINTAGES
// ═══════════════════════════════════════════════════════════════════════
echo "<h2>2️⃣ STATISTIQUES DES POINTAGES</h2>";

$stats = $pdo->query("
    SELECT 
        COUNT(*) as total_pointages,
        SUM(CASE WHEN check_in IS NOT NULL THEN 1 ELSE 0 END) as avec_arrivee,
        SUM(CASE WHEN check_out IS NOT NULL THEN 1 ELSE 0 END) as avec_depart,
        SUM(CASE WHEN checkout_latitude IS NOT NULL THEN 1 ELSE 0 END) as avec_gps_depart,
        SUM(CASE WHEN checkout_selfie_path IS NOT NULL THEN 1 ELSE 0 END) as avec_selfie_depart
    FROM attendance
    WHERE work_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAYS)
")->fetch(PDO::FETCH_ASSOC);

echo "<table>";
echo "<tr><th>Statistique</th><th>Valeur</th></tr>";
echo "<tr><td>Total pointages (7 derniers jours)</td><td class='info'><strong>{$stats['total_pointages']}</strong></td></tr>";
echo "<tr><td>Avec arrivée (check_in)</td><td class='success'><strong>{$stats['avec_arrivee']}</strong></td></tr>";
echo "<tr><td>Avec départ (check_out)</td><td class='" . ($stats['avec_depart'] > 0 ? 'success' : 'error') . "'><strong>{$stats['avec_depart']}</strong></td></tr>";
echo "<tr><td>Avec GPS départ</td><td class='" . ($stats['avec_gps_depart'] > 0 ? 'success' : 'warning') . "'><strong>{$stats['avec_gps_depart']}</strong></td></tr>";
echo "<tr><td>Avec selfie départ</td><td class='" . ($stats['avec_selfie_depart'] > 0 ? 'success' : 'warning') . "'><strong>{$stats['avec_selfie_depart']}</strong></td></tr>";
echo "</table>";

if($stats['avec_depart'] == 0) {
    echo "<p class='error'>❌ AUCUN DÉPART ENREGISTRÉ dans les 7 derniers jours !</p>";
    echo "<p class='warning'>⚠️ Cause possible : Les employés n'ont pas encore pointé leur départ avec la nouvelle version du portail.</p>";
} else {
    echo "<p class='success'>✅ Il y a {$stats['avec_depart']} départ(s) enregistré(s) !</p>";
}

// ═══════════════════════════════════════════════════════════════════════
// 3. EXEMPLES DE DONNÉES
// ═══════════════════════════════════════════════════════════════════════
echo "<h2>3️⃣ EXEMPLES DE DONNÉES (5 DERNIERS POINTAGES)</h2>";

$examples = $pdo->query("
    SELECT 
        a.id,
        e.full_name,
        a.work_date,
        a.check_in,
        a.check_out,
        a.checkout_latitude,
        a.checkout_longitude,
        a.checkout_selfie_path,
        CASE 
            WHEN a.check_out IS NULL THEN '❌ PAS DE DÉPART'
            WHEN a.checkout_latitude IS NULL THEN '⚠️ DÉPART SANS GPS'
            WHEN a.checkout_selfie_path IS NULL THEN '⚠️ DÉPART SANS SELFIE'
            ELSE '✅ DÉPART COMPLET'
        END as status_depart
    FROM attendance a
    JOIN employees e ON a.employee_id = e.id
    ORDER BY a.created_at DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

echo "<table>";
echo "<tr><th>ID</th><th>Employé</th><th>Date</th><th>Arrivée</th><th>Départ</th><th>GPS Départ</th><th>Selfie</th><th>Status</th></tr>";

foreach($examples as $ex) {
    $gps = $ex['checkout_latitude'] ? "Oui ({$ex['checkout_latitude']}, {$ex['checkout_longitude']})" : "<span class='error'>Non</span>";
    $selfie = $ex['checkout_selfie_path'] ? "<span class='success'>Oui</span>" : "<span class='error'>Non</span>";
    
    echo "<tr>";
    echo "<td>{$ex['id']}</td>";
    echo "<td>{$ex['full_name']}</td>";
    echo "<td>{$ex['work_date']}</td>";
    echo "<td>{$ex['check_in']}</td>";
    echo "<td>" . ($ex['check_out'] ?: "<span class='error'>NULL</span>") . "</td>";
    echo "<td>{$gps}</td>";
    echo "<td>{$selfie}</td>";
    echo "<td>{$ex['status_depart']}</td>";
    echo "</tr>";
}

echo "</table>";

// ═══════════════════════════════════════════════════════════════════════
// 4. VÉRIFIER LES FICHIERS SELFIES
// ═══════════════════════════════════════════════════════════════════════
echo "<h2>4️⃣ VÉRIFICATION DES FICHIERS SELFIES</h2>";

$selfies = $pdo->query("
    SELECT checkout_selfie_path 
    FROM attendance 
    WHERE checkout_selfie_path IS NOT NULL
    LIMIT 10
")->fetchAll(PDO::FETCH_COLUMN);

if(empty($selfies)) {
    echo "<p class='warning'>⚠️ Aucun selfie de départ trouvé dans la base de données</p>";
} else {
    echo "<p class='info'>📁 Selfies de départ trouvés : " . count($selfies) . "</p>";
    echo "<ul>";
    foreach($selfies as $path) {
        $exists = file_exists($path);
        $class = $exists ? 'success' : 'error';
        $status = $exists ? '✅ Existe' : '❌ Fichier manquant';
        echo "<li class='$class'>{$path} → {$status}</li>";
    }
    echo "</ul>";
}

// ═══════════════════════════════════════════════════════════════════════
// 5. RECOMMANDATIONS
// ═══════════════════════════════════════════════════════════════════════
echo "<h2>5️⃣ RECOMMANDATIONS</h2>";

echo "<div style='background: #334155; padding: 20px; border-radius: 10px;'>";

if($stats['avec_depart'] == 0) {
    echo "<p class='error'><strong>🔴 PROBLÈME IDENTIFIÉ :</strong></p>";
    echo "<p>Les employés n'ont pas encore pointé de départ avec GPS + Photo.</p>";
    echo "<br>";
    echo "<p class='success'><strong>💡 SOLUTION :</strong></p>";
    echo "<ol>";
    echo "<li>Vérifier que le fichier <code>employee_portal_corrected.php</code> est bien installé</li>";
    echo "<li>Vérifier que les employés utilisent la nouvelle version du portail</li>";
    echo "<li>Faire pointer UN employé test pour vérifier que tout fonctionne</li>";
    echo "<li>Vérifier que les colonnes checkout_* existent bien (✅ déjà fait ci-dessus)</li>";
    echo "</ol>";
} else {
    echo "<p class='success'><strong>✅ SYSTÈME FONCTIONNEL !</strong></p>";
    echo "<p>Des départs sont enregistrés. Si l'interface n'affiche rien, vérifier :</p>";
    echo "<ol>";
    echo "<li>La date sélectionnée dans le filtre</li>";
    echo "<li>L'employé sélectionné dans le filtre</li>";
    echo "<li>Les erreurs PHP dans les logs</li>";
    echo "</ol>";
}

echo "</div>";

// ═══════════════════════════════════════════════════════════════════════
// 6. TEST REQUÊTE SQL
// ═══════════════════════════════════════════════════════════════════════
echo "<h2>6️⃣ TEST REQUÊTE SQL (AUJOURD'HUI)</h2>";

$today = date('Y-m-d');
$test_query = "
    SELECT 
        a.id,
        e.full_name,
        a.work_date,
        a.check_in,
        a.check_out,
        a.checkout_latitude,
        a.checkout_longitude,
        a.checkout_selfie_path
    FROM attendance a
    JOIN employees e ON a.employee_id = e.id
    WHERE a.work_date = '$today'
    ORDER BY a.check_in ASC
";

echo "<p><strong>Requête SQL :</strong></p>";
echo "<pre style='background: #1e293b; padding: 15px; border-radius: 5px;'>{$test_query}</pre>";

$test_results = $pdo->query($test_query)->fetchAll(PDO::FETCH_ASSOC);

if(empty($test_results)) {
    echo "<p class='warning'>⚠️ Aucun pointage aujourd'hui ({$today})</p>";
    echo "<p>Essayez de changer la date dans l'interface admin pour voir les jours précédents.</p>";
} else {
    echo "<p class='success'>✅ " . count($test_results) . " pointage(s) trouvé(s) aujourd'hui</p>";
    echo "<table>";
    echo "<tr><th>ID</th><th>Employé</th><th>Arrivée</th><th>Départ</th><th>GPS Départ</th></tr>";
    foreach($test_results as $r) {
        echo "<tr>";
        echo "<td>{$r['id']}</td>";
        echo "<td>{$r['full_name']}</td>";
        echo "<td>{$r['check_in']}</td>";
        echo "<td>" . ($r['check_out'] ?: "<span class='error'>NULL</span>") . "</td>";
        echo "<td>" . ($r['checkout_latitude'] ? "✅" : "<span class='error'>❌</span>") . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

echo "<hr>";
echo "<h2>📋 RÉSUMÉ</h2>";
echo "<div style='background: #334155; padding: 20px; border-radius: 10px;'>";

$score = 0;
$total = 4;

if(empty($missing)) {
    echo "<p class='success'>✅ Structure de la base de données OK</p>";
    $score++;
} else {
    echo "<p class='error'>❌ Colonnes manquantes dans la base de données</p>";
}

if($stats['avec_depart'] > 0) {
    echo "<p class='success'>✅ Il y a des départs enregistrés</p>";
    $score++;
} else {
    echo "<p class='error'>❌ Aucun départ enregistré</p>";
}

if($stats['avec_gps_depart'] > 0) {
    echo "<p class='success'>✅ GPS de départ enregistré</p>";
    $score++;
} else {
    echo "<p class='warning'>⚠️ Aucun GPS de départ</p>";
}

if($stats['avec_selfie_depart'] > 0) {
    echo "<p class='success'>✅ Selfies de départ enregistrés</p>";
    $score++;
} else {
    echo "<p class='warning'>⚠️ Aucun selfie de départ</p>";
}

$percentage = ($score / $total) * 100;
$color = $percentage == 100 ? 'success' : ($percentage >= 50 ? 'warning' : 'error');

echo "<br><h3 class='$color'>Score: {$score}/{$total} ({$percentage}%)</h3>";

if($percentage == 100) {
    echo "<p class='success'>🎉 Tout est parfait ! Les départs devraient s'afficher dans l'interface.</p>";
} elseif($percentage >= 50) {
    echo "<p class='warning'>⚠️ Système partiellement fonctionnel. Vérifier les recommandations ci-dessus.</p>";
} else {
    echo "<p class='error'>❌ Système non fonctionnel. Suivre les recommandations ci-dessus.</p>";
}

echo "</div>";

echo "</body></html>";
?>
