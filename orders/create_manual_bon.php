<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * CRÉATION MANUELLE BON DE LIVRAISON - ESPERANCE H2O
 * ═══════════════════════════════════════════════════════════════════════════
 * ✅ Formulaire complet pour créer un bon sans commande préalable
 * ✅ Sélection client ou création rapide
 * ✅ Ajout multiple d'articles
 * ✅ Génération automatique numéro de bon
 * ✅ Impression PDF immédiate
 */

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
Middleware::role(['developer', 'admin', 'manager']);

$pdo = DB::getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

date_default_timezone_set('Africa/Abidjan');

$success_msg = '';
$error_msg = '';

// ═══════════════════════════════════════════════════════════════════════
// TRAITEMENT CRÉATION BON MANUEL
// ═══════════════════════════════════════════════════════════════════════
if (isset($_POST['create_manual_bon'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error_msg = "❌ Token CSRF invalide";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Récupérer ou créer le client
            $client_id = (int)($_POST['client_id'] ?? 0);
            
            // Si création rapide client
            if (!$client_id && !empty($_POST['new_client_name'])) {
                $stmt = $pdo->prepare("INSERT INTO clients (name, phone, email, address) VALUES (?, ?, ?, ?)");
                $stmt->execute([
                    trim($_POST['new_client_name']),
                    trim($_POST['new_client_phone'] ?? ''),
                    trim($_POST['new_client_email'] ?? ''),
                    trim($_POST['new_client_address'] ?? '')
                ]);
                $client_id = $pdo->lastInsertId();
            }
            
            if (!$client_id) {
                throw new Exception("Veuillez sélectionner ou créer un client");
            }
            
            // Données commande
            $company_id = (int)$_POST['company_id'];
            $city_id = (int)$_POST['city_id'];
            $payment_method = $_POST['payment_method'] ?? 'cash';
            $delivery_address = trim($_POST['delivery_address'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            
            // Articles
            $products = $_POST['products'] ?? [];
            $quantities = $_POST['quantities'] ?? [];
            $prices = $_POST['prices'] ?? [];
            
            if (empty($products) || empty(array_filter($products))) {
                throw new Exception("Veuillez ajouter au moins un article");
            }
            
            // Calculer total
            $total_amount = 0;
            $valid_items = [];
            
            foreach ($products as $idx => $product_name) {
                $product_name = trim($product_name);
                $qty = (int)($quantities[$idx] ?? 0);
                $price = (float)($prices[$idx] ?? 0);
                
                if ($product_name && $qty > 0 && $price > 0) {
                    $subtotal = $qty * $price;
                    $total_amount += $subtotal;
                    
                    $valid_items[] = [
                        'product_name' => $product_name,
                        'quantity' => $qty,
                        'unit_price' => $price,
                        'subtotal' => $subtotal
                    ];
                }
            }
            
            if (empty($valid_items)) {
                throw new Exception("Aucun article valide");
            }
            
            // Générer numéro de commande unique
            $order_number = 'MAN-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            
            // Insérer commande
            $stmt = $pdo->prepare("
                INSERT INTO orders (
                    order_number, client_id, company_id, city_id,
                    total_amount, payment_method, delivery_address,
                    notes, status, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'confirmed', NOW())
            ");
            
            $stmt->execute([
                $order_number,
                $client_id,
                $company_id,
                $city_id,
                $total_amount,
                $payment_method,
                $delivery_address,
                $notes
            ]);
            
            $order_id = $pdo->lastInsertId();
            
            // Insérer articles - GESTION ROBUSTE
            foreach ($valid_items as $item) {
                $product_id = null;
                
                try {
                    // Essayer de trouver le produit existant
                    $stmt = $pdo->prepare("SELECT id FROM products WHERE name = ? LIMIT 1");
                    $stmt->execute([$item['product_name']]);
                    $product_id = $stmt->fetchColumn();
                    
                    // Si le produit n'existe pas, tenter de le créer
                    if (!$product_id) {
                        try {
                            // Vérifier quels champs existent dans la table products
                            $cols = $pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN);
                            
                            if (in_array('category_id', $cols)) {
                                // Avec category_id
                                $stmt = $pdo->prepare("INSERT INTO products (name, price, category_id) VALUES (?, ?, 1)");
                                $stmt->execute([$item['product_name'], $item['unit_price']]);
                            } else {
                                // Sans category_id
                                $stmt = $pdo->prepare("INSERT INTO products (name, price) VALUES (?, ?)");
                                $stmt->execute([$item['product_name'], $item['unit_price']]);
                            }
                            $product_id = $pdo->lastInsertId();
                        } catch (Exception $e) {
                            // Si création échoue, utiliser un produit par défaut ou générique
                            $product_id = 1; // ID produit par défaut
                        }
                    }
                } catch (Exception $e) {
                    // Si recherche échoue, utiliser ID par défaut
                    $product_id = 1;
                }
                
                // Insérer l'article de commande avec product_id trouvé ou par défaut
                $stmt = $pdo->prepare("
                    INSERT INTO order_items (order_id, product_id, product_name, quantity, unit_price, subtotal)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $order_id,
                    $product_id,
                    $item['product_name'],
                    $item['quantity'],
                    $item['unit_price'],
                    $item['subtotal']
                ]);
            }
            
            $pdo->commit();
            
            $success_msg = "✅ <strong>BON DE LIVRAISON CRÉÉ !</strong><br>
                           📄 N° <strong style='color:#667eea;'>{$order_number}</strong><br>
                           💰 Montant: <strong>" . number_format($total_amount, 0, ',', ' ') . " FCFA</strong><br>
                           <a href='export_bons.php?search={$order_number}' target='_blank' class='btn btn-primary' style='margin-top:10px;display:inline-block;text-decoration:none;'>
                               <i class='fas fa-download'></i> Télécharger Excel
                           </a>
                           <a href='" . project_url('orders/admin_orders.php') . "' class='btn btn-info' style='margin-top:10px;display:inline-block;margin-left:10px;text-decoration:none;'>
                               <i class='fas fa-arrow-left'></i> Retour Dashboard
                           </a>";
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_msg = "❌ Erreur: " . $e->getMessage();
        }
    }
}

// Récupérer données pour formulaire
$clients = $pdo->query("SELECT id, name, phone FROM clients ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$companies = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$cities = $pdo->query("SELECT id, name FROM cities ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$products = $pdo->query("SELECT DISTINCT name FROM products ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Créer Bon de Livraison Manuel - ESPERANCE H2O</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
:root{
  --bg:#0f1726; --card:#1b263b; --card2:#22324a; --bord:rgba(50,190,143,.13);
  --neon:#00a86b; --neon2:#00c87a; --red:#e53935; --gold:#f9a825;
  --cyan:#06b6d4; --blue:#1976d2; --purple:#a78bfa; --orange:#f57c00;
  --text:#dff2ea; --text2:#b0d4c4; --muted:#5a7a6c;
  --gn:0 0 20px rgba(50,190,143,.38); --gr:0 0 20px rgba(255,53,83,.38);
  --fh:'Poppins',sans-serif;
}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html,body{min-height:100vh}
body{font-family:var(--fh);font-weight:700;background:var(--bg);color:var(--text);overflow-x:hidden;font-size:15px}
body::before{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;
  background:radial-gradient(ellipse 55% 40% at 3% 4%,rgba(50,190,143,.065),transparent 55%),
             radial-gradient(ellipse 40% 30% at 97% 96%,rgba(61,140,255,.055),transparent 55%)}
body::after{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;
  background-image:linear-gradient(rgba(50,190,143,.012) 1px,transparent 1px),
                   linear-gradient(90deg,rgba(50,190,143,.012) 1px,transparent 1px);
  background-size:50px 50px}

@keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
@keyframes breathe{0%,100%{box-shadow:0 0 12px rgba(50,190,143,.3)}50%{box-shadow:0 0 32px rgba(50,190,143,.75)}}
@keyframes scan{0%{left:-80%}100%{left:110%}}
@keyframes slideIn{from{opacity:0;transform:translateX(-20px)}to{opacity:1;transform:translateX(0)}}

.container{position:relative;z-index:1;max-width:1000px;margin:0 auto;padding:20px}

.header{background:rgba(4,9,14,.97);border:1px solid var(--bord);border-radius:18px;
  backdrop-filter:blur(18px);padding:20px 25px;margin-bottom:20px;
  display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:15px;
  box-shadow:0 10px 40px rgba(0,0,0,0.3);position:relative;overflow:hidden}
.header::after{content:'';position:absolute;bottom:0;left:0;right:0;height:1px;
  background:linear-gradient(90deg,transparent,var(--neon),var(--cyan),var(--blue),transparent)}
.header h1{font-size:24px;font-weight:900;color:var(--text);display:flex;align-items:center;gap:12px}
.header h1 i{color:var(--neon)}

.btn{padding:12px 24px;border:1.5px solid transparent;border-radius:10px;font-weight:700;
  cursor:pointer;display:inline-flex;align-items:center;gap:8px;transition:all .3s;
  font-size:14px;text-decoration:none;font-family:var(--fh);letter-spacing:.3px}
.btn:active{transform:scale(.95)}
.btn-primary{background:rgba(50,190,143,.08);border-color:rgba(50,190,143,.22);color:var(--neon)}
.btn-primary:hover{background:var(--neon);color:var(--bg)}
.btn-danger{background:rgba(255,53,83,.08);border-color:rgba(255,53,83,.22);color:var(--red)}
.btn-danger:hover{background:var(--red);color:#fff}
.btn-info{background:rgba(61,140,255,.08);border-color:rgba(61,140,255,.22);color:var(--blue)}
.btn-info:hover{background:var(--blue);color:#fff}
.btn-warning{background:rgba(255,208,96,.08);border-color:rgba(255,208,96,.22);color:var(--gold)}
.btn-warning:hover{background:var(--gold);color:var(--bg)}
.btn-solid{background:linear-gradient(135deg,var(--neon),var(--cyan));color:var(--bg);border:none;box-shadow:var(--gn)}

.card{background:var(--card);border:1px solid var(--bord);border-radius:18px;
  padding:30px;margin-bottom:20px;box-shadow:0 10px 30px rgba(0,0,0,0.2);
  animation:fadeUp .4s ease backwards;position:relative;overflow:hidden}
.card::before{content:'';position:absolute;top:0;left:-80%;width:50%;height:2px;
  background:linear-gradient(90deg,transparent,var(--neon),transparent);animation:scan 3.5s linear infinite}

.card-title{font-size:18px;font-weight:900;color:var(--text);margin-bottom:25px;
  display:flex;align-items:center;gap:10px}
.card-title i{color:var(--neon);font-size:22px}

.form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px;margin-bottom:25px}

.form-group{margin-bottom:20px}

label{display:block;font-size:11px;font-weight:900;color:var(--muted);
  margin-bottom:8px;text-transform:uppercase;letter-spacing:1px}

input,select,textarea{width:100%;padding:14px;border:1.5px solid var(--bord);
  border-radius:10px;font-size:15px;font-family:var(--fh);font-weight:600;
  transition:all .3s;background:rgba(0,0,0,.3);color:var(--text)}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--neon);
  box-shadow:0 0 0 4px rgba(50,190,143,0.1)}
input::placeholder,textarea::placeholder{color:var(--muted)}
textarea{resize:vertical;min-height:100px}
select option{background:var(--card2)}

.items-section{background:rgba(0,0,0,.2);padding:20px;border-radius:15px;
  margin-bottom:25px;border:1px solid var(--bord)}

.item-row{display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:10px;
  margin-bottom:10px;align-items:end}

.btn-add-item{background:rgba(50,190,143,.06);color:var(--neon);
  border:2px dashed rgba(50,190,143,.3);padding:12px 20px;border-radius:10px;
  font-weight:900;cursor:pointer;display:flex;align-items:center;justify-content:center;
  gap:8px;transition:all .3s;width:100%;text-transform:uppercase;letter-spacing:.5px}
.btn-add-item:hover{background:rgba(50,190,143,.12);border-color:rgba(50,190,143,.5)}

.btn-remove{background:rgba(255,53,83,.06);color:var(--red);
  border:2px solid rgba(255,53,83,.2);padding:12px 16px;border-radius:10px;
  cursor:pointer;transition:all .3s}
.btn-remove:hover{background:var(--red);color:#fff}

.total-display{background:linear-gradient(135deg,rgba(50,190,143,.15),rgba(6,182,212,.1));
  padding:25px;border-radius:15px;text-align:center;margin:20px 0;
  border:2px solid rgba(50,190,143,.3);box-shadow:0 0 20px rgba(50,190,143,.2)}

.total-label{font-size:12px;color:var(--text2);font-weight:900;
  margin-bottom:8px;text-transform:uppercase;letter-spacing:2px}

.total-value{font-size:42px;font-weight:900;color:var(--neon);
  font-family:'Courier New',monospace;text-shadow:0 0 20px rgba(50,190,143,.4)}

.alert{padding:20px 25px;border-radius:15px;margin-bottom:20px;
  animation:slideIn 0.3s ease;display:flex;align-items:center;gap:15px;
  border-left:5px solid;font-size:15px}

.alert-success{background:linear-gradient(135deg,rgba(50,190,143,.15),rgba(16,185,129,.1));
  border-left-color:var(--neon);color:var(--text)}

.alert-error{background:linear-gradient(135deg,rgba(255,53,83,.15),rgba(239,68,68,.1));
  border-left-color:var(--red);color:#fee2e2}

.client-toggle{background:rgba(0,0,0,.2);padding:20px;border-radius:15px;
  margin-bottom:20px;border:1px solid var(--bord)}

.toggle-btns{display:flex;gap:10px;margin-bottom:20px}

.toggle-btn{flex:1;padding:14px;border:2px solid var(--bord);border-radius:10px;
  background:rgba(0,0,0,.3);cursor:pointer;font-weight:900;transition:all .3s;
  color:var(--muted);display:flex;align-items:center;justify-content:center;gap:8px}
.toggle-btn:hover{border-color:rgba(50,190,143,.3)}
.toggle-btn.active{background:rgba(50,190,143,.12);border-color:var(--neon);color:var(--neon)}

.toggle-content{display:none;animation:fadeIn .3s ease}
.toggle-content.active{display:block}

@media (max-width:768px){
  .form-grid{grid-template-columns:1fr}
  .item-row{grid-template-columns:1fr}
  .header h1{font-size:18px}
}

::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-track{background:rgba(0,0,0,.07)}
::-webkit-scrollbar-thumb{background:rgba(50,190,143,.18);border-radius:3px}
</style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1>
            <i class="fas fa-file-invoice"></i>
            Créer Bon de Livraison Manuel
        </h1>
        <a href="<?= project_url('orders/admin_orders.php') ?>" class="btn btn-danger">
            <i class="fas fa-arrow-left"></i> Retour
        </a>
    </div>

    <?php if ($success_msg): ?>
    <div class="alert alert-success">
        <?= $success_msg ?>
    </div>
    <?php endif; ?>

    <?php if ($error_msg): ?>
    <div class="alert alert-error">
        <?= $error_msg ?>
    </div>
    <?php endif; ?>

    <form method="POST" id="manualForm">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        
        <!-- CLIENT -->
        <div class="card">
            <div class="card-title">
                <i class="fas fa-user"></i>
                1. Client
            </div>
            
            <div class="client-toggle">
                <div class="toggle-btns">
                    <button type="button" class="toggle-btn active" onclick="toggleClient('existing')">
                        <i class="fas fa-users"></i> Client Existant
                    </button>
                    <button type="button" class="toggle-btn" onclick="toggleClient('new')">
                        <i class="fas fa-user-plus"></i> Nouveau Client
                    </button>
                </div>
                
                <div class="toggle-content active" id="existing-client">
                    <div class="form-group">
                        <label>Sélectionner Client</label>
                        <select name="client_id" id="client_select">
                            <option value="">-- Choisir un client --</option>
                            <?php foreach ($clients as $client): ?>
                            <option value="<?= $client['id'] ?>">
                                <?= htmlspecialchars($client['name']) ?> 
                                <?= $client['phone'] ? '(' . htmlspecialchars($client['phone']) . ')' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="toggle-content" id="new-client">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Nom du Client *</label>
                            <input type="text" name="new_client_name" placeholder="Nom complet">
                        </div>
                        <div class="form-group">
                            <label>Téléphone</label>
                            <input type="tel" name="new_client_phone" placeholder="+225 XX XX XX XX XX">
                        </div>
                        <div class="form-group">
                            <label>Email</label>
                            <input type="email" name="new_client_email" placeholder="email@example.com">
                        </div>
                        <div class="form-group">
                            <label>Adresse</label>
                            <input type="text" name="new_client_address" placeholder="Adresse complète">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- INFOS COMMANDE -->
        <div class="card">
            <div class="card-title">
                <i class="fas fa-info-circle"></i>
                2. Informations Commande
            </div>
            
            <div class="form-grid">
                <div class="form-group">
                    <label>Société *</label>
                    <select name="company_id" required>
                        <option value="">-- Choisir --</option>
                        <?php foreach ($companies as $company): ?>
                        <option value="<?= $company['id'] ?>"><?= htmlspecialchars($company['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Ville *</label>
                    <select name="city_id" required>
                        <option value="">-- Choisir --</option>
                        <?php foreach ($cities as $city): ?>
                        <option value="<?= $city['id'] ?>"><?= htmlspecialchars($city['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Mode de Paiement *</label>
                    <select name="payment_method" required>
                        <option value="cash">💵 Espèces</option>
                        <option value="mobile_money">📱 Mobile Money</option>
                        <option value="bank_transfer">🏦 Virement</option>
                        <option value="cheque">📄 Chèque</option>
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <label>Adresse de Livraison</label>
                <textarea name="delivery_address" placeholder="Adresse complète de livraison..."></textarea>
            </div>
            
            <div class="form-group">
                <label>Notes / Remarques</label>
                <textarea name="notes" rows="3" placeholder="Instructions spéciales, remarques..."></textarea>
            </div>
        </div>

        <!-- ARTICLES -->
        <div class="card">
            <div class="card-title">
                <i class="fas fa-box"></i>
                3. Articles
            </div>
            
            <div class="items-section">
                <div id="items-container">
                    <div class="item-row">
                        <div class="form-group" style="margin: 0;">
                            <label>Article</label>
                            <input type="text" name="products[]" list="products-list" placeholder="Nom du produit" required>
                        </div>
                        <div class="form-group" style="margin: 0;">
                            <label>Quantité</label>
                            <input type="number" name="quantities[]" min="1" value="1" placeholder="Qté" required onchange="updateTotal()">
                        </div>
                        <div class="form-group" style="margin: 0;">
                            <label>Prix Unitaire (FCFA)</label>
                            <input type="number" name="prices[]" min="0" step="1" placeholder="Prix" required onchange="updateTotal()">
                        </div>
                        <div class="form-group" style="margin: 0;">
                            <label>&nbsp;</label>
                            <button type="button" class="btn-remove" onclick="removeItem(this)" style="display: none;">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
                
                <datalist id="products-list">
                    <?php foreach ($products as $product): ?>
                    <option value="<?= htmlspecialchars($product) ?>">
                    <?php endforeach; ?>
                </datalist>
                
                <button type="button" class="btn-add-item" onclick="addItem()">
                    <i class="fas fa-plus"></i> Ajouter un article
                </button>
            </div>
            
            <div class="total-display">
                <div class="total-label">MONTANT TOTAL</div>
                <div class="total-value" id="total-display">0 FCFA</div>
            </div>
        </div>

        <!-- SUBMIT -->
        <div class="card">
            <button type="submit" name="create_manual_bon" class="btn btn-primary" style="width: 100%; font-size: 18px; padding: 18px;">
                <i class="fas fa-check-circle"></i>
                CRÉER LE BON DE LIVRAISON
            </button>
        </div>
    </form>
</div>

<script>
let itemCount = 1;

function toggleClient(type) {
    document.querySelectorAll('.toggle-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.toggle-content').forEach(content => content.classList.remove('active'));
    
    if (type === 'existing') {
        document.querySelector('.toggle-btn:first-child').classList.add('active');
        document.getElementById('existing-client').classList.add('active');
        document.getElementById('client_select').required = true;
        document.querySelector('[name="new_client_name"]').required = false;
    } else {
        document.querySelector('.toggle-btn:last-child').classList.add('active');
        document.getElementById('new-client').classList.add('active');
        document.getElementById('client_select').required = false;
        document.querySelector('[name="new_client_name"]').required = true;
    }
}

function addItem() {
    itemCount++;
    const container = document.getElementById('items-container');
    const newItem = document.createElement('div');
    newItem.className = 'item-row';
    newItem.innerHTML = `
        <div class="form-group" style="margin: 0;">
            <label>Article</label>
            <input type="text" name="products[]" list="products-list" placeholder="Nom du produit" required>
        </div>
        <div class="form-group" style="margin: 0;">
            <label>Quantité</label>
            <input type="number" name="quantities[]" min="1" value="1" placeholder="Qté" required onchange="updateTotal()">
        </div>
        <div class="form-group" style="margin: 0;">
            <label>Prix Unitaire (FCFA)</label>
            <input type="number" name="prices[]" min="0" step="1" placeholder="Prix" required onchange="updateTotal()">
        </div>
        <div class="form-group" style="margin: 0;">
            <label>&nbsp;</label>
            <button type="button" class="btn-remove" onclick="removeItem(this)">
                <i class="fas fa-trash"></i>
            </button>
        </div>
    `;
    container.appendChild(newItem);
    updateRemoveButtons();
}

function removeItem(btn) {
    btn.closest('.item-row').remove();
    itemCount--;
    updateRemoveButtons();
    updateTotal();
}

function updateRemoveButtons() {
    const rows = document.querySelectorAll('.item-row');
    rows.forEach((row, index) => {
        const removeBtn = row.querySelector('.btn-remove');
        if (removeBtn) {
            removeBtn.style.display = rows.length > 1 ? 'block' : 'none';
        }
    });
}

function updateTotal() {
    let total = 0;
    const quantities = document.querySelectorAll('[name="quantities[]"]');
    const prices = document.querySelectorAll('[name="prices[]"]');
    
    for (let i = 0; i < quantities.length; i++) {
        const qty = parseInt(quantities[i].value) || 0;
        const price = parseFloat(prices[i].value) || 0;
        total += qty * price;
    }
    
    document.getElementById('total-display').textContent = 
        new Intl.NumberFormat('fr-FR').format(total) + ' FCFA';
}

// Validation formulaire
document.getElementById('manualForm').addEventListener('submit', function(e) {
    const clientSelect = document.getElementById('client_select');
    const newClientName = document.querySelector('[name="new_client_name"]');
    
    if (document.getElementById('existing-client').classList.contains('active')) {
        if (!clientSelect.value) {
            e.preventDefault();
            Swal.fire({
                icon: 'warning',
                title: 'Client requis',
                text: 'Veuillez sélectionner un client',
                confirmButtonColor: '#10b981'
            });
            return;
        }
    } else {
        if (!newClientName.value.trim()) {
            e.preventDefault();
            Swal.fire({
                icon: 'warning',
                title: 'Nom du client requis',
                text: 'Veuillez entrer le nom du nouveau client',
                confirmButtonColor: '#10b981'
            });
            return;
        }
    }
    
    const products = document.querySelectorAll('[name="products[]"]');
    let hasValidProduct = false;
    
    products.forEach(input => {
        if (input.value.trim()) hasValidProduct = true;
    });
    
    if (!hasValidProduct) {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'Articles requis',
            text: 'Veuillez ajouter au moins un article',
            confirmButtonColor: '#10b981'
        });
    }
});

// Init
updateRemoveButtons();
updateTotal();
</script>

</body>
</html>
