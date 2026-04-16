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

Auth::check();
Middleware::role(['developer', 'admin']);

$pdo = DB::getConnection();

echo "<!DOCTYPE html>
<html lang='fr'>
<head>
<meta charset='UTF-8'>
<title>DEBUG - Commandes</title>
<style>
body { font-family: monospace; padding: 20px; background: #1e293b; color: #10b981; }
h2 { color: #3b82f6; border-bottom: 2px solid #3b82f6; padding-bottom: 10px; }
table { width: 100%; border-collapse: collapse; margin: 20px 0; background: #0f172a; }
th, td { border: 1px solid #334155; padding: 10px; text-align: left; }
th { background: #1e293b; color: #3b82f6; }
.success { color: #10b981; }
.error { color: #ef4444; }
.warning { color: #f59e0b; }
pre { background: #0f172a; padding: 15px; border-radius: 8px; overflow-x: auto; }
</style>
</head>
<body>
<h1>🔍 DEBUG - SYSTÈME DE COMMANDES</h1>
";

// 1. Vérifier la table orders
echo "<h2>1️⃣ VÉRIFICATION TABLE 'orders'</h2>";
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM orders");
    $count = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p class='success'>✅ Table 'orders' existe</p>";
    echo "<p>Total commandes: <strong>{$count['total']}</strong></p>";
    
    if ($count['total'] > 0) {
        echo "<h3>Dernières commandes:</h3>";
        $stmt = $pdo->query("SELECT * FROM orders ORDER BY id DESC LIMIT 5");
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<table>";
        echo "<tr><th>ID</th><th>Order Number</th><th>Client ID</th><th>Company ID</th><th>City ID</th><th>Total</th><th>Status</th><th>Date</th></tr>";
        foreach ($orders as $order) {
            echo "<tr>";
            echo "<td>{$order['id']}</td>";
            echo "<td>{$order['order_number']}</td>";
            echo "<td>{$order['client_id']}</td>";
            echo "<td>{$order['company_id']}</td>";
            echo "<td>{$order['city_id']}</td>";
            echo "<td>{$order['total_amount']}</td>";
            echo "<td>{$order['status']}</td>";
            echo "<td>{$order['created_at']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p class='warning'>⚠️ Aucune commande dans la base de données</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Erreur: " . $e->getMessage() . "</p>";
}

// 2. Vérifier la table order_items
echo "<h2>2️⃣ VÉRIFICATION TABLE 'order_items'</h2>";
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM order_items");
    $count = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p class='success'>✅ Table 'order_items' existe</p>";
    echo "<p>Total articles: <strong>{$count['total']}</strong></p>";
    
    if ($count['total'] > 0) {
        echo "<h3>Derniers articles:</h3>";
        $stmt = $pdo->query("SELECT * FROM order_items ORDER BY id DESC LIMIT 5");
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<table>";
        echo "<tr><th>ID</th><th>Order ID</th><th>Product Name</th><th>Quantity</th><th>Unit Price</th><th>Subtotal</th></tr>";
        foreach ($items as $item) {
            echo "<tr>";
            echo "<td>{$item['id']}</td>";
            echo "<td>{$item['order_id']}</td>";
            echo "<td>{$item['product_name']}</td>";
            echo "<td>{$item['quantity']}</td>";
            echo "<td>{$item['unit_price']}</td>";
            echo "<td>{$item['subtotal']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Erreur: " . $e->getMessage() . "</p>";
}

// 3. Vérifier la vue orders_summary
echo "<h2>3️⃣ VÉRIFICATION VUE 'orders_summary'</h2>";
try {
    $stmt = $pdo->query("SELECT * FROM orders_summary LIMIT 5");
    $summary = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<p class='success'>✅ Vue 'orders_summary' existe</p>";
    
    if (count($summary) > 0) {
        echo "<table>";
        echo "<tr><th>Order Number</th><th>Client Name</th><th>Phone</th><th>Items Count</th><th>Total</th><th>Status</th></tr>";
        foreach ($summary as $order) {
            echo "<tr>";
            echo "<td>{$order['order_number']}</td>";
            echo "<td>{$order['client_name']}</td>";
            echo "<td>{$order['client_phone']}</td>";
            echo "<td>{$order['items_count']}</td>";
            echo "<td>{$order['total_amount']}</td>";
            echo "<td>{$order['status']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Erreur vue: " . $e->getMessage() . "</p>";
}

// 4. Vérifier les clients
echo "<h2>4️⃣ VÉRIFICATION CLIENTS</h2>";
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM clients");
    $count = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p class='success'>✅ Total clients: <strong>{$count['total']}</strong></p>";
    
    $stmt = $pdo->query("SELECT * FROM clients ORDER BY id DESC LIMIT 5");
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<table>";
    echo "<tr><th>ID</th><th>Name</th><th>Phone</th><th>Company ID</th><th>City ID</th><th>Type</th></tr>";
    foreach ($clients as $client) {
        echo "<tr>";
        echo "<td>{$client['id']}</td>";
        echo "<td>{$client['name']}</td>";
        echo "<td>{$client['phone']}</td>";
        echo "<td>{$client['company_id']}</td>";
        echo "<td>{$client['city_id']}</td>";
        echo "<td>{$client['id_type']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "<p class='error'>❌ Erreur: " . $e->getMessage() . "</p>";
}

// 5. Tester la requête de l'admin
echo "<h2>5️⃣ TEST REQUÊTE ADMIN (orders_api.php)</h2>";
$company_id = 1; // Change si besoin
$city_id = 1;    // Change si besoin

echo "<p>Test avec Company ID: <strong>$company_id</strong>, City ID: <strong>$city_id</strong></p>";

try {
    $sql = "
        SELECT 
            o.*,
            c.name as client_name,
            c.phone as client_phone,
            u.username as created_by_name,
            (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as items_count
        FROM orders o
        LEFT JOIN clients c ON o.client_id = c.id
        LEFT JOIN users u ON o.created_by = u.id
        WHERE o.company_id = ? AND o.city_id = ?
        ORDER BY o.created_at DESC
        LIMIT 100
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$company_id, $city_id]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p class='success'>✅ Requête exécutée avec succès</p>";
    echo "<p>Résultats trouvés: <strong>" . count($orders) . "</strong></p>";
    
    if (count($orders) > 0) {
        echo "<table>";
        echo "<tr><th>Order Number</th><th>Client</th><th>Phone</th><th>Items</th><th>Total</th><th>Status</th></tr>";
        foreach ($orders as $order) {
            echo "<tr>";
            echo "<td>{$order['order_number']}</td>";
            echo "<td>{$order['client_name']}</td>";
            echo "<td>{$order['client_phone']}</td>";
            echo "<td>{$order['items_count']}</td>";
            echo "<td>{$order['total_amount']}</td>";
            echo "<td>{$order['status']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p class='warning'>⚠️ Aucune commande pour Company ID=$company_id et City ID=$city_id</p>";
        
        // Chercher dans toutes les companies
        echo "<h3>Recherche dans TOUTES les sociétés:</h3>";
        $stmt = $pdo->query("
            SELECT o.*, c.name as client_name, co.name as company_name, ci.name as city_name
            FROM orders o
            LEFT JOIN clients c ON o.client_id = c.id
            LEFT JOIN companies co ON o.company_id = co.id
            LEFT JOIN cities ci ON o.city_id = ci.id
            ORDER BY o.id DESC
            LIMIT 10
        ");
        $all_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($all_orders) > 0) {
            echo "<table>";
            echo "<tr><th>Order #</th><th>Client</th><th>Company</th><th>City</th><th>Total</th></tr>";
            foreach ($all_orders as $order) {
                echo "<tr>";
                echo "<td>{$order['order_number']}</td>";
                echo "<td>{$order['client_name']}</td>";
                echo "<td>{$order['company_name']} (ID: {$order['company_id']})</td>";
                echo "<td>{$order['city_name']} (ID: {$order['city_id']})</td>";
                echo "<td>{$order['total_amount']}</td>";
                echo "</tr>";
            }
            echo "</table>";
            echo "<p class='warning'>⚠️ Les commandes existent mais pour d'autres sociétés/villes!</p>";
        }
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Erreur requête: " . $e->getMessage() . "</p>";
}

// 6. Vérifier les sessions
echo "<h2>6️⃣ VÉRIFICATION SESSIONS ADMIN</h2>";
echo "<pre>";
echo "SESSION orders_company_id: " . ($_SESSION['orders_company_id'] ?? 'NON DÉFINI') . "\n";
echo "SESSION orders_city_id: " . ($_SESSION['orders_city_id'] ?? 'NON DÉFINI') . "\n";
echo "</pre>";

// 7. Diagnostic final
echo "<h2>7️⃣ DIAGNOSTIC</h2>";
$total_orders = $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$total_clients = $pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();

if ($total_orders == 0) {
    echo "<p class='warning'>⚠️ PROBLÈME: Aucune commande dans la base</p>";
    echo "<p>SOLUTIONS:</p>";
    echo "<ul>";
    echo "<li>1. Va sur <a href='order_client.php' style='color: #3b82f6;'>order_client.php</a> et passe une commande</li>";
    echo "<li>2. Vérifie que l'interface client fonctionne</li>";
    echo "<li>3. Vérifie les logs d'erreurs PHP</li>";
    echo "</ul>";
} else {
    echo "<p class='success'>✅ Il y a des commandes dans la base ($total_orders)</p>";
    echo "<p class='warning'>⚠️ PROBLÈME: Filtrage Company/City incorrect</p>";
    echo "<p>SOLUTIONS:</p>";
    echo "<ul>";
    echo "<li>1. Vérifie que tu sélectionnes la BONNE société et ville dans l'admin</li>";
    echo "<li>2. Les commandes existent peut-être pour d'autres Company ID / City ID</li>";
    echo "<li>3. Regarde le tableau ci-dessus pour voir où sont les commandes</li>";
    echo "</ul>";
}

echo "<hr>";
echo "<p><a href='orders_admin.php' style='color: #3b82f6;'>← Retour à l'Admin</a></p>";
echo "<p><a href='order_client.php' style='color: #10b981;'>→ Interface Client</a></p>";

echo "</body></html>";
?>
