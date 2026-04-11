<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

/**
 * ═══════════════════════════════════════════════════════════════
 * DEBUG STOCK — ESPERANCE H2O
 * ═══════════════════════════════════════════════════════════════
 * Script temporaire pour diagnostiquer pourquoi le stock
 * ne se met pas à jour lors de la facturation.
 *
 * 🔴 SUPPRIMER CE FICHIER APRÈS DIAGNOSTIC !
 */
session_start();
require_once APP_ROOT . '/app/core/DB.php';
require_once APP_ROOT . '/app/core/Auth.php';
require_once APP_ROOT . '/app/core/Middleware.php';

use App\Core\DB;
use App\Core\Auth;
use App\Core\Middleware;

Auth::check();
Middleware::role(['developer', 'admin', 'manager']);

$pdo = DB::getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$order_id = (int)($_GET['order_id'] ?? 0);

?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>🔍 Debug Stock</title>
<style>
body{font-family:monospace;background:#04090e;color:#e0f2ea;padding:30px;line-height:1.8}
h2{color:#32be8f;border-bottom:1px solid #32be8f;padding-bottom:8px;margin:24px 0 12px}
.ok{color:#32be8f} .warn{color:#ffd060} .err{color:#ff3553}
.box{background:#0d1e2c;border:1px solid rgba(50,190,143,0.2);border-radius:10px;padding:16px;margin:12px 0}
table{width:100%;border-collapse:collapse;margin:8px 0}
th{text-align:left;color:#5a8070;border-bottom:1px solid #1a3040;padding:6px 10px}
td{padding:6px 10px;border-bottom:1px solid rgba(255,255,255,0.04)}
.badge{display:inline-block;padding:2px 8px;border-radius:6px;font-size:11px}
.badge-ok{background:rgba(50,190,143,0.15);color:#32be8f}
.badge-err{background:rgba(255,53,83,0.15);color:#ff3553}
.badge-warn{background:rgba(255,208,96,0.15);color:#ffd060}
input{background:#0d1e2c;border:1px solid rgba(50,190,143,0.3);color:#e0f2ea;padding:8px 14px;border-radius:8px;font-family:monospace}
button{background:#32be8f;color:#04090e;border:none;padding:9px 20px;border-radius:8px;cursor:pointer;font-weight:900}
</style>
</head>
<body>

<h1>🔍 Debug Stock — ESPERANCE H2O</h1>

<form method="get">
    <label>Order ID à tester :</label><br><br>
    <input type="number" name="order_id" value="<?= $order_id ?>" placeholder="Ex: 42">
    &nbsp;
    <button type="submit">Analyser</button>
</form>

<?php if (!$order_id): ?>
<div class="box warn">⚠️ Entre un order_id pour commencer le diagnostic.</div>
<?php exit; endif; ?>

<?php

echo "<h2>1️⃣ Données de la commande #$order_id</h2>";

try {
    $stmt = $pdo->prepare("
        SELECT o.*, c.name AS client_name
        FROM orders o
        LEFT JOIN clients c ON c.id = o.client_id
        WHERE o.id = ?
    ");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        echo "<div class='box err'>❌ Commande #$order_id introuvable en base !</div>";
        exit;
    }

    echo "<div class='box'><table>";
    foreach (['id','order_number','status','total_amount','invoiced_at','invoiced_by','client_name'] as $k) {
        $v = $order[$k] ?? '—';
        $cls = '';
        if ($k === 'invoiced_at') $cls = $v !== '—' ? 'err' : 'ok';
        echo "<tr><th>$k</th><td class='$cls'>$v</td></tr>";
    }
    echo "</table></div>";

    if (!empty($order['invoiced_at'])) {
        echo "<div class='box warn'>⚠️ Cette commande est déjà marquée <b>invoiced_at = {$order['invoiced_at']}</b><br>
        Le stock a donc déjà été (ou devrait avoir été) mis à jour lors de la première ouverture.<br>
        Pour re-tester, tu peux faire : <code>UPDATE orders SET invoiced_at=NULL, invoiced_by=NULL WHERE id=$order_id;</code></div>";
    }

} catch (Exception $e) {
    echo "<div class='box err'>❌ Erreur: " . $e->getMessage() . "</div>";
    exit;
}

echo "<h2>2️⃣ Articles de la commande (order_items)</h2>";

try {
    $stmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ? ORDER BY id");
    $stmt->execute([$order_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$items) {
        echo "<div class='box err'>❌ Aucun article trouvé dans order_items pour order_id=$order_id !</div>";
        exit;
    }

    echo "<div class='box'><table><tr><th>id</th><th>product_name</th><th>quantity</th><th>unit_price</th><th>subtotal</th></tr>";
    foreach ($items as $it) {
        echo "<tr>
            <td>{$it['id']}</td>
            <td><b>{$it['product_name']}</b></td>
            <td>{$it['quantity']}</td>
            <td>" . number_format($it['unit_price'] ?? 0, 0, ',', ' ') . "</td>
            <td>" . number_format($it['subtotal'] ?? 0, 0, ',', ' ') . "</td>
        </tr>";
    }
    echo "</table></div>";

} catch (Exception $e) {
    echo "<div class='box err'>❌ Erreur order_items: " . $e->getMessage() . "</div>";
    exit;
}

echo "<h2>3️⃣ Correspondance avec la table products</h2>";
echo "<p class='warn'>C'est souvent ici que ça bloque — les noms doivent être IDENTIQUES (majuscules, espaces, accents)</p>";

$all_match = true;
echo "<div class='box'><table><tr><th>Nom dans order_items</th><th>Trouvé dans products ?</th><th>Stock actuel</th><th>product_id</th></tr>";
foreach ($items as $it) {
    $name = $it['product_name'] ?? '';
    try {
        $sp = $pdo->prepare("SELECT id, name, stock FROM products WHERE name = ? LIMIT 1");
        $sp->execute([$name]);
        $prod = $sp->fetch(PDO::FETCH_ASSOC);

        if ($prod) {
            echo "<tr>
                <td>«$name»</td>
                <td><span class='badge badge-ok'>✓ Trouvé</span></td>
                <td>{$prod['stock']}</td>
                <td>{$prod['id']}</td>
            </tr>";
        } else {
            $all_match = false;
            // Chercher des produits similaires
            $sp2 = $pdo->prepare("SELECT id, name, stock FROM products WHERE name LIKE ? LIMIT 5");
            $sp2->execute(['%' . $name . '%']);
            $similar = $sp2->fetchAll(PDO::FETCH_ASSOC);
            $sug = $similar ? ' — Similaires: ' . implode(', ', array_column($similar, 'name')) : ' — Aucun similaire trouvé';
            echo "<tr>
                <td>«$name»</td>
                <td><span class='badge badge-err'>❌ INTROUVABLE$sug</span></td>
                <td>—</td>
                <td>—</td>
            </tr>";
        }
    } catch (Exception $e) {
        echo "<tr><td>$name</td><td class='err'>Erreur: " . $e->getMessage() . "</td><td>—</td><td>—</td></tr>";
    }
}
echo "</table></div>";

if (!$all_match) {
    echo "<div class='box err'>
    ❌ <b>PROBLÈME TROUVÉ :</b> Certains produits ne sont pas trouvés par leur nom exact.<br><br>
    <b>Solutions :</b><br>
    1. Corriger les noms dans <code>order_items</code> pour qu'ils correspondent à <code>products.name</code><br>
    2. OU modifier <code>updateStock()</code> pour chercher par <code>product_id</code> au lieu du nom (recommandé)<br><br>
    <b>Vérifie si order_items a une colonne product_id :</b><br>
    <code>DESCRIBE order_items;</code>
    </div>";
}

echo "<h2>4️⃣ Structure de la table order_items</h2>";
try {
    $cols = $pdo->query("DESCRIBE order_items")->fetchAll(PDO::FETCH_ASSOC);
    echo "<div class='box'><table><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    foreach ($cols as $col) {
        $highlight = in_array($col['Field'], ['product_id','product_name']) ? 'style="color:var(--gold,#ffd060)"' : '';
        echo "<tr $highlight>
            <td><b>{$col['Field']}</b></td>
            <td>{$col['Type']}</td>
            <td>{$col['Null']}</td>
            <td>{$col['Key']}</td>
            <td>{$col['Default']}</td>
        </tr>";
    }
    echo "</table></div>";

    $has_product_id = in_array('product_id', array_column($cols, 'Field'));
    if ($has_product_id) {
        echo "<div class='box ok'>✅ <b>order_items.product_id existe</b> — On peut (et doit) chercher par ID au lieu du nom !</div>";
    } else {
        echo "<div class='box warn'>⚠️ Pas de colonne product_id dans order_items — on est obligé de chercher par nom.</div>";
    }

} catch (Exception $e) {
    echo "<div class='box err'>Erreur DESCRIBE: " . $e->getMessage() . "</div>";
}

echo "<h2>5️⃣ Vérification table products (premiers 20)</h2>";
try {
    $prods = $pdo->query("SELECT id, name, stock FROM products ORDER BY name LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
    echo "<div class='box'><table><tr><th>id</th><th>name</th><th>stock</th></tr>";
    foreach ($prods as $p) {
        echo "<tr><td>{$p['id']}</td><td>{$p['name']}</td><td>{$p['stock']}</td></tr>";
    }
    echo "</table></div>";
} catch (Exception $e) {
    echo "<div class='box err'>Erreur products: " . $e->getMessage() . "</div>";
}

echo "<h2>6️⃣ Logs caisse pour cette commande</h2>";
try {
    $stmt = $pdo->prepare("SELECT * FROM caisse_logs WHERE order_id = ? ORDER BY created_at DESC LIMIT 20");
    $stmt->execute([$order_id]);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($logs) {
        echo "<div class='box'><table><tr><th>Date</th><th>Action</th><th>Old</th><th>New</th><th>Details</th><th>User</th></tr>";
        foreach ($logs as $l) {
            $cls = str_contains($l['action'],'ERROR') ? 'err' : (str_contains($l['action'],'STOCK') ? 'ok' : '');
            echo "<tr class='$cls'>
                <td>{$l['created_at']}</td>
                <td><b>{$l['action']}</b></td>
                <td>{$l['old_status']}</td>
                <td>{$l['new_status']}</td>
                <td>{$l['details']}</td>
                <td>{$l['user_name']}</td>
            </tr>";
        }
        echo "</table></div>";
    } else {
        echo "<div class='box warn'>⚠️ Aucun log pour cette commande — le ticket n'a peut-être jamais été ouvert, ou la table caisse_logs n'existe pas encore.</div>";
    }
} catch (Exception $e) {
    echo "<div class='box err'>Erreur logs: " . $e->getMessage() . "</div>";
}

echo "<h2>7️⃣ Résumé du diagnostic</h2>";
echo "<div class='box'>";
echo "<p><b>Commande :</b> #$order_id — {$order['order_number']}</p>";
echo "<p><b>Déjà facturé :</b> " . (!empty($order['invoiced_at']) ? "<span class='err'>OUI ({$order['invoiced_at']})</span>" : "<span class='ok'>NON</span>") . "</p>";
echo "<p><b>Correspondance noms produits :</b> " . ($all_match ? "<span class='ok'>✅ OK</span>" : "<span class='err'>❌ PROBLÈME — voir section 3</span>") . "</p>";
echo "</div>";

echo "<div class='box err' style='margin-top:30px'>🔴 <b>SUPPRIMER CE FICHIER (debug_stock.php) APRÈS UTILISATION !</b></div>";
?>
</body>
</html>
