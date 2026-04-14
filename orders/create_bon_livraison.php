<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * CRÉATION MANUELLE BON DE LIVRAISON - ESPERANCE H2O v2
 * ═══════════════════════════════════════════════════════════════════════════
 * ✅ Panier instantané avec sidebar fixe
 * ✅ Vérification stock en temps réel avant ajout
 * ✅ Catalogue produits avec stock visible
 * ✅ Sélection client ou création rapide
 * ✅ Génération numéro bon automatique
 * ✅ Validation stock côté serveur
 */
session_start();
ini_set('display_errors', 0);
error_reporting(0);

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
$error_msg   = '';

/* ════════════════════════════════════════════════════
   AJAX HANDLERS
════════════════════════════════════════════════════ */
if (!empty($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $act = trim($_POST['action']);

    /* ── Recherche produits + stock ── */
    if ($act === 'search_products') {
        try {
            $q = trim($_POST['q'] ?? '');
            $company_id = (int)($_POST['company_id'] ?? 0);

            // Try to get stock info — handles both with/without stock table
            try {
                if ($company_id) {
                    $st = $pdo->prepare("
                        SELECT p.id, p.name, p.price,
                            COALESCE(s.quantity, 0) AS stock,
                            COALESCE(s.unit, 'unité') AS unit,
                            c.name AS category
                        FROM products p
                        LEFT JOIN stock s ON s.product_id = p.id AND s.company_id = ?
                        LEFT JOIN categories c ON c.id = p.category_id
                        WHERE p.name LIKE ? AND (p.active IS NULL OR p.active = 1)
                        ORDER BY p.name LIMIT 30");
                    $st->execute([$company_id, '%'.$q.'%']);
                } else {
                    $st = $pdo->prepare("
                        SELECT p.id, p.name, p.price,
                            COALESCE(SUM(s.quantity), 0) AS stock,
                            'unité' AS unit,
                            c.name AS category
                        FROM products p
                        LEFT JOIN stock s ON s.product_id = p.id
                        LEFT JOIN categories c ON c.id = p.category_id
                        WHERE p.name LIKE ? AND (p.active IS NULL OR p.active = 1)
                        GROUP BY p.id ORDER BY p.name LIMIT 30");
                    $st->execute(['%'.$q.'%']);
                }
                $products = $st->fetchAll(PDO::FETCH_ASSOC);
            } catch(Exception $e2) {
                // Fallback: no stock table
                $st = $pdo->prepare("SELECT p.id, p.name, p.price, 999 AS stock, 'unité' AS unit, '' AS category
                    FROM products p WHERE p.name LIKE ? LIMIT 30");
                $st->execute(['%'.$q.'%']);
                $products = $st->fetchAll(PDO::FETCH_ASSOC);
            }

            echo json_encode(['success' => true, 'products' => $products]);
        } catch(Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    /* ── Vérifier stock d'un produit ── */
    if ($act === 'check_stock') {
        try {
            $pid = (int)($_POST['product_id'] ?? 0);
            $qty = (int)($_POST['quantity']   ?? 1);
            $cid = (int)($_POST['company_id'] ?? 0);
            try {
                $col = $cid ? 'AND s.company_id=?' : '';
                $params = $cid ? [$pid, $cid] : [$pid];
                $st = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM stock s WHERE product_id=? $col");
                $st->execute($params);
                $stock = (int)$st->fetchColumn();
            } catch(Exception $e2) {
                $stock = 999; // No stock table
            }
            $ok = $stock >= $qty;
            echo json_encode(['success' => true, 'stock' => $stock, 'ok' => $ok,
                'message' => $ok ? null : "Stock insuffisant ({$stock} disponible)."]);
        } catch(Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    /* ── Recherche clients ── */
    if ($act === 'search_clients') {
        try {
            $q = trim($_POST['q'] ?? '');
            $st = $pdo->prepare("SELECT id,name,phone,address FROM clients WHERE name LIKE ? OR phone LIKE ? ORDER BY name LIMIT 20");
            $st->execute(['%'.$q.'%', '%'.$q.'%']);
            echo json_encode(['success' => true, 'clients' => $st->fetchAll(PDO::FETCH_ASSOC)]);
        } catch(Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    /* ── Récupérer produit par ID ── */
    if ($act === 'get_product') {
        try {
            $pid = (int)($_POST['product_id'] ?? 0);
            $cid = (int)($_POST['company_id'] ?? 0);
            $st = $pdo->prepare("SELECT id,name,price FROM products WHERE id=? LIMIT 1");
            $st->execute([$pid]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if (!$p) { echo json_encode(['success'=>false,'message'=>'Introuvable']); exit; }
            try {
                $col = $cid ? 'AND s.company_id=?' : '';
                $sp = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM stock s WHERE product_id=? $col");
                $sp->execute($cid ? [$pid,$cid] : [$pid]);
                $p['stock'] = (int)$sp->fetchColumn();
            } catch(Exception $e2) { $p['stock'] = 999; }
            echo json_encode(['success'=>true,'product'=>$p]);
        } catch(Exception $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }

    /* ── Créer le bon ── */
    if ($act === 'create_bon') {
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
            echo json_encode(['success'=>false,'message'=>'Token CSRF invalide']); exit;
        }
        try {
            $pdo->beginTransaction();

            // Client
            $client_id = (int)($_POST['client_id'] ?? 0);
            if (!$client_id && !empty(trim($_POST['new_client_name'] ?? ''))) {
                $st = $pdo->prepare("INSERT INTO clients(name,phone,email,address)VALUES(?,?,?,?)");
                $st->execute([trim($_POST['new_client_name']),trim($_POST['new_client_phone']??''),trim($_POST['new_client_email']??''),trim($_POST['new_client_address']??'')]);
                $client_id = (int)$pdo->lastInsertId();
            }
            if (!$client_id) throw new Exception("Client requis");

            $company_id = (int)($_POST['company_id'] ?? 0);
            $city_id    = (int)($_POST['city_id']    ?? 0);
            if (!$company_id) throw new Exception("Société requise");
            if (!$city_id)    throw new Exception("Ville requise");

            // Items from cart JSON
            $items = json_decode($_POST['cart_items'] ?? '[]', true);
            if (empty($items)) throw new Exception("Panier vide");

            // Validate stock server-side
            foreach ($items as $item) {
                if (!empty($item['product_id'])) {
                    try {
                        $col = $company_id ? 'AND company_id=?' : '';
                        $sp = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM stock WHERE product_id=? $col");
                        $sp->execute($company_id ? [$item['product_id'], $company_id] : [$item['product_id']]);
                        $avail = (int)$sp->fetchColumn();
                        if ($avail < $item['quantity']) {
                            throw new Exception("Stock insuffisant pour «{$item['product_name']}» (dispo: {$avail})");
                        }
                    } catch(PDOException $e2) {} // No stock table → skip
                }
            }

            $total = array_sum(array_map(function($i){ return (float)$i['quantity'] * (float)$i['unit_price']; }, $items));
            $order_number = 'MAN-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(3)));

            $st = $pdo->prepare("INSERT INTO orders(order_number,client_id,company_id,city_id,total_amount,payment_method,delivery_address,notes,status,created_at)VALUES(?,?,?,?,?,?,?,?,'confirmed',NOW())");
            $st->execute([$order_number,$client_id,$company_id,$city_id,$total,$_POST['payment_method']??'cash',trim($_POST['delivery_address']??''),trim($_POST['notes']??'')]);
            $order_id = (int)$pdo->lastInsertId();

            foreach ($items as $item) {
                $pid = null;
                if (!empty($item['product_id'])) {
                    $pid = (int)$item['product_id'];
                } else {
                    try {
                        $sp = $pdo->prepare("SELECT id FROM products WHERE name=? LIMIT 1");
                        $sp->execute([$item['product_name']]);
                        $pid = $sp->fetchColumn() ?: null;
                        if (!$pid) {
                            $cols = $pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN);
                            if (in_array('category_id', $cols)) {
                                $pdo->prepare("INSERT INTO products(name,price,category_id)VALUES(?,?,1)")->execute([$item['product_name'],$item['unit_price']]);
                            } else {
                                $pdo->prepare("INSERT INTO products(name,price)VALUES(?,?)")->execute([$item['product_name'],$item['unit_price']]);
                            }
                            $pid = (int)$pdo->lastInsertId();
                        }
                    } catch(Exception $e2) { $pid = 1; }
                }
                $sub = (float)$item['quantity'] * (float)$item['unit_price'];
                $pdo->prepare("INSERT INTO order_items(order_id,product_id,product_name,quantity,unit_price,subtotal)VALUES(?,?,?,?,?,?)")
                    ->execute([$order_id,$pid,$item['product_name'],$item['quantity'],$item['unit_price'],$sub]);

                // Deduct stock
                if ($pid && $company_id) {
                    try {
                        $pdo->prepare("UPDATE stock SET quantity=quantity-? WHERE product_id=? AND company_id=?")->execute([$item['quantity'],$pid,$company_id]);
                    } catch(Exception $e2) {}
                }
            }

            // Cashier notification
            try {
                $client_name = $pdo->prepare("SELECT name FROM clients WHERE id=? LIMIT 1");
                $client_name->execute([$client_id]);
                $cname = $client_name->fetchColumn() ?: 'Client';
                $pdo->prepare("INSERT INTO cashier_notifications(type,title,message,order_id,order_number,client_name,amount)VALUES(?,?,?,?,?,?,?)")
                    ->execute(['new_order','📄 Bon manuel créé','Bon '.$order_number.' de '.$cname.' — '.number_format($total,0,'','.').' CFA (créé manuellement)',$order_id,$order_number,$cname,$total]);
            } catch(Exception $e2) {}

            $pdo->commit();
            echo json_encode(['success'=>true,'order_number'=>$order_number,'order_id'=>$order_id,'total'=>$total]);
        } catch(Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }
}

/* ── Page data ── */
$clients   = $pdo->query("SELECT id,name,phone FROM clients ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$companies = $pdo->query("SELECT id,name FROM companies ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$cities    = $pdo->query("SELECT id,name,company_id FROM cities ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Initial products with stock
try {
    $init_products = $pdo->query("
        SELECT p.id, p.name, p.price,
            COALESCE(SUM(s.quantity),0) AS stock,
            'unité' AS unit, c.name AS category
        FROM products p
        LEFT JOIN stock s ON s.product_id=p.id
        LEFT JOIN categories c ON c.id=p.category_id
        WHERE p.active IS NULL OR p.active=1
        GROUP BY p.id ORDER BY p.name LIMIT 80")
        ->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {
    $init_products = $pdo->query("SELECT id,name,price,999 AS stock,'unité' AS unit,'' AS category FROM products ORDER BY name LIMIT 80")->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Bon de Livraison Manuel — ESPERANCE H2O</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{
  --bg:#0f1726;--surf:#162033;--card:#1b263b;--card2:#22324a;
  --bord:rgba(50,190,143,.12);--bord2:rgba(50,190,143,.07);
  --neon:#00a86b;--neon2:#1aff9c;--red:#e53935;--gold:#f9a825;
  --cyan:#06b6d4;--blue:#1976d2;--purple:#a78bfa;--orange:#ff8c42;
  --text:#e2f4ed;--text2:#aecfbf;--muted:#466258;
  --gn:0 0 22px rgba(50,190,143,.35);--gr:0 0 22px rgba(255,53,83,.35);
  --fd:'Syne',sans-serif;--fb:'DM Sans',sans-serif;
  --cart-w:360px;
}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html{height:100%;scroll-behavior:smooth}
body{font-family:var(--fb);background:var(--bg);color:var(--text);min-height:100vh;overflow-x:hidden}
body::before{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;
  background:radial-gradient(ellipse 60% 42% at 0% 0%,rgba(50,190,143,.06),transparent 55%),
             radial-gradient(ellipse 44% 34% at 100% 100%,rgba(61,140,255,.05),transparent 55%)}
body::after{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;
  background-image:linear-gradient(rgba(50,190,143,.011) 1px,transparent 1px),
                   linear-gradient(90deg,rgba(50,190,143,.011) 1px,transparent 1px);
  background-size:50px 50px}

@keyframes fadeUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
@keyframes slideLeft{from{opacity:0;transform:translateX(20px)}to{opacity:1;transform:translateX(0)}}
@keyframes breathe{0%,100%{box-shadow:0 0 12px rgba(50,190,143,.28)}50%{box-shadow:0 0 32px rgba(50,190,143,.72)}}
@keyframes scan{0%{left:-80%}100%{left:110%}}
@keyframes spin{to{transform:rotate(360deg)}}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
@keyframes cartBounce{0%{transform:scale(1)}40%{transform:scale(1.18)}70%{transform:scale(.94)}100%{transform:scale(1)}}
@keyframes stockWarn{0%,100%{background:rgba(255,53,83,.08)}50%{background:rgba(255,53,83,.22)}}
@keyframes rowIn{from{opacity:0;transform:translateX(-10px)}to{opacity:1;transform:translateX(0)}}
@keyframes ping{0%{transform:scale(1);opacity:.8}100%{transform:scale(2.1);opacity:0}}

/* ─── LAYOUT ─── */
.page{position:relative;z-index:1;display:flex;flex-direction:column;min-height:100vh}
.layout{display:flex;flex:1;gap:0}
.main-col{flex:1;min-width:0;padding:16px;padding-right:calc(var(--cart-w) + 24px)}
@media(max-width:900px){.main-col{padding-right:16px;}}

/* ─── TOPBAR ─── */
.topbar{background:rgba(4,9,14,.97);border-bottom:1px solid var(--bord);
  backdrop-filter:blur(18px);padding:10px 16px;display:flex;align-items:center;
  gap:10px;position:sticky;top:0;z-index:200}
.topbar::after{content:'';position:absolute;bottom:0;left:0;right:0;height:1px;
  background:linear-gradient(90deg,transparent,var(--neon),var(--cyan),transparent)}
.tb-logo{width:32px;height:32px;border-radius:9px;
  background:linear-gradient(135deg,var(--neon),var(--cyan));
  display:flex;align-items:center;justify-content:center;font-size:16px;color:var(--bg);
  box-shadow:var(--gn);animation:breathe 3s ease-in-out infinite;flex-shrink:0}
.tb-title{font-family:var(--fd);font-size:14px;font-weight:800;color:var(--text);flex:1}
.tb-title span{color:var(--neon)}
.tbtn{width:32px;height:32px;border-radius:8px;background:rgba(50,190,143,.05);
  border:1.5px solid var(--bord);display:flex;align-items:center;justify-content:center;
  cursor:pointer;font-size:13px;color:var(--text2);transition:all .2s;
  text-decoration:none;flex-shrink:0}
.tbtn:hover{background:rgba(50,190,143,.12);color:var(--neon)}
.cart-toggle-btn{
  display:none;width:40px;height:40px;border-radius:10px;
  background:rgba(50,190,143,.1);border:1.5px solid rgba(50,190,143,.3);
  color:var(--neon);font-size:16px;align-items:center;justify-content:center;
  cursor:pointer;position:relative;flex-shrink:0}
.cart-toggle-btn .cbdg{position:absolute;top:-6px;right:-6px;width:18px;height:18px;
  border-radius:9px;background:var(--red);color:#fff;font-size:9px;font-weight:700;
  display:flex;align-items:center;justify-content:center;border:2px solid var(--bg)}
@media(max-width:900px){.cart-toggle-btn{display:flex}}

/* ─── SECTION HEADERS ─── */
.sec-head{display:flex;align-items:center;gap:10px;margin-bottom:14px;padding-bottom:10px;
  border-bottom:1px solid var(--bord2)}
.sec-num{width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,var(--neon),var(--cyan));
  display:flex;align-items:center;justify-content:center;font-family:var(--fd);
  font-size:13px;font-weight:800;color:var(--bg);flex-shrink:0}
.sec-ttl{font-family:var(--fd);font-size:15px;font-weight:800;color:var(--text)}
.sec-sub{font-size:10px;font-weight:500;color:var(--muted);margin-left:auto}

/* ─── CARDS ─── */
.card{background:var(--card);border:1px solid var(--bord);border-radius:15px;
  padding:18px;margin-bottom:14px;animation:fadeUp .35s ease backwards;
  position:relative;overflow:hidden}
.card::before{content:'';position:absolute;top:0;left:-80%;width:50%;height:1.5px;
  background:linear-gradient(90deg,transparent,var(--neon),transparent);
  animation:scan 3.5s linear infinite}

/* ─── FORM ─── */
.fgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}
.fg{margin-bottom:0}
.fg label{display:block;font-size:9px;font-weight:700;color:var(--muted);
  margin-bottom:5px;text-transform:uppercase;letter-spacing:1px}
.fg input,.fg select,.fg textarea{width:100%;padding:11px 13px;border:1.5px solid var(--bord);
  border-radius:10px;font-size:13px;font-family:var(--fb);font-weight:500;
  background:rgba(0,0,0,.28);color:var(--text);transition:all .22s;appearance:none}
.fg input:focus,.fg select:focus,.fg textarea:focus{outline:none;border-color:var(--neon);
  box-shadow:0 0 0 3px rgba(50,190,143,.09)}
.fg input::placeholder,.fg textarea::placeholder{color:var(--muted)}
.fg select option{background:var(--card2)}
.fg textarea{resize:vertical;min-height:80px}

/* ─── CLIENT TOGGLE ─── */
.ctoggle{display:flex;gap:6px;margin-bottom:14px}
.ctbtn{flex:1;padding:11px;border:1.5px solid var(--bord);border-radius:10px;
  background:rgba(0,0,0,.28);cursor:pointer;font-size:11px;font-weight:700;
  color:var(--muted);display:flex;align-items:center;justify-content:center;gap:6px;
  transition:all .22s;font-family:var(--fb)}
.ctbtn:hover{border-color:rgba(50,190,143,.28)}
.ctbtn.on{background:rgba(50,190,143,.1);border-color:rgba(50,190,143,.32);color:var(--neon)}
.ctblock{display:none;animation:fadeIn .25s ease}
.ctblock.on{display:block}

/* ─── PRODUCT CATALOG ─── */
.catalog-search{display:flex;align-items:center;gap:8px;
  background:rgba(0,0,0,.3);border:1.5px solid var(--bord);border-radius:11px;
  padding:10px 13px;margin-bottom:12px;transition:border-color .22s,box-shadow .22s}
.catalog-search:focus-within{border-color:var(--neon);box-shadow:0 0 0 3px rgba(50,190,143,.08)}
.catalog-search i{color:rgba(50,190,143,.45);font-size:14px;flex-shrink:0}
.catalog-search input{background:none;border:none;outline:none;width:100%;
  font-family:var(--fb);font-size:13px;font-weight:500;color:var(--text)}
.catalog-search input::placeholder{color:var(--muted)}
.catalog-filters{display:flex;gap:5px;overflow-x:auto;padding-bottom:8px;margin-bottom:12px}
.catalog-filters::-webkit-scrollbar{display:none}
.cfpill{padding:5px 12px;border-radius:18px;border:1.5px solid var(--bord);
  background:rgba(0,0,0,.28);cursor:pointer;font-size:9px;font-weight:700;
  color:var(--muted);white-space:nowrap;flex-shrink:0;transition:all .2s}
.cfpill.on{background:rgba(50,190,143,.1);border-color:rgba(50,190,143,.28);color:var(--neon)}
.product-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;
  max-height:420px;overflow-y:auto;padding-right:4px}
.product-grid::-webkit-scrollbar{width:4px}
.product-grid::-webkit-scrollbar-thumb{background:rgba(50,190,143,.18);border-radius:2px}

/* ─── PRODUCT CARD ─── */
.pcard{background:var(--card2);border:1.5px solid var(--bord);border-radius:12px;
  padding:13px;cursor:pointer;transition:all .22s;position:relative;overflow:hidden;
  animation:fadeUp .3s ease backwards}
.pcard:hover{border-color:rgba(50,190,143,.35);transform:translateY(-2px);
  box-shadow:0 8px 24px rgba(0,0,0,.4)}
.pcard.out-of-stock{opacity:.45;cursor:not-allowed}
.pcard.out-of-stock:hover{transform:none;box-shadow:none;border-color:rgba(255,53,83,.25)}
.pcard.low-stock{border-color:rgba(255,208,96,.22)}
.pcard.low-stock:hover{border-color:rgba(255,208,96,.4)}
.pcard-ico{font-size:26px;margin-bottom:7px;display:block}
.pcard-name{font-size:11px;font-weight:700;color:var(--text);margin-bottom:4px;
  line-height:1.35;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
.pcard-price{font-family:var(--fd);font-size:13px;font-weight:800;color:var(--neon)}
.pcard-price small{font-size:9px;color:var(--muted);font-family:var(--fb);font-weight:500}
.pcard-stock{display:inline-flex;align-items:center;gap:3px;margin-top:5px;
  padding:2px 7px;border-radius:6px;font-size:8px;font-weight:700}
.pcard-stock.ok{background:rgba(50,190,143,.1);color:var(--neon);border:1px solid rgba(50,190,143,.2)}
.pcard-stock.low{background:rgba(255,208,96,.1);color:var(--gold);border:1px solid rgba(255,208,96,.2)}
.pcard-stock.empty{background:rgba(255,53,83,.1);color:var(--red);border:1px solid rgba(255,53,83,.2)}
.pcard-add{position:absolute;bottom:10px;right:10px;width:26px;height:26px;border-radius:50%;
  background:linear-gradient(135deg,var(--neon),var(--cyan));color:var(--bg);
  display:flex;align-items:center;justify-content:center;font-size:13px;
  opacity:0;transition:opacity .2s;pointer-events:none}
.pcard:hover:not(.out-of-stock) .pcard-add{opacity:1}
.pcard-cat{font-size:8px;font-weight:700;color:var(--muted);margin-bottom:4px;text-transform:uppercase;letter-spacing:.8px}

/* Custom qty input on card */
.pcard-qty{display:flex;align-items:center;gap:4px;margin-top:8px;opacity:0;
  transform:translateY(4px);transition:all .22s}
.pcard:hover:not(.out-of-stock) .pcard-qty{opacity:1;transform:translateY(0)}
.pcard-qty button{width:22px;height:22px;border-radius:6px;border:1px solid var(--bord);
  background:rgba(0,0,0,.3);color:var(--text2);cursor:pointer;font-size:12px;
  display:flex;align-items:center;justify-content:center;transition:all .15s}
.pcard-qty button:hover{background:var(--neon);color:var(--bg);border-color:var(--neon)}
.pcard-qty input{width:36px;height:22px;text-align:center;border-radius:6px;
  border:1px solid var(--bord);background:rgba(0,0,0,.3);color:var(--text);
  font-size:11px;font-family:var(--fb);font-weight:700;padding:0 4px}
.pcard-qty input:focus{outline:none;border-color:var(--neon)}

/* ─── CART SIDEBAR ─── */
.cart-sidebar{
  width:var(--cart-w);flex-shrink:0;
  position:fixed;top:53px;right:0;bottom:0;
  background:rgba(7,18,28,.98);border-left:1px solid var(--bord);
  backdrop-filter:blur(18px);display:flex;flex-direction:column;
  z-index:150;transition:transform .35s cubic-bezier(.23,1,.32,1);
}
.cart-sidebar::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;
  background:linear-gradient(90deg,var(--neon),var(--cyan),var(--blue))}
@media(max-width:900px){
  .cart-sidebar{transform:translateX(100%);top:0;z-index:400;width:min(var(--cart-w),100vw)}
  .cart-sidebar.open{transform:translateX(0)}
  .cart-overlay{display:block!important}
}
.cart-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);
  z-index:390;backdrop-filter:blur(4px)}

.cart-head{padding:14px 16px;border-bottom:1px solid var(--bord);
  display:flex;align-items:center;justify-content:space-between}
.cart-head-title{font-family:var(--fd);font-size:14px;font-weight:800;color:var(--text);
  display:flex;align-items:center;gap:8px}
.cart-count-badge{background:linear-gradient(135deg,var(--neon),var(--cyan));color:var(--bg);
  min-width:20px;height:20px;border-radius:10px;padding:0 5px;font-size:10px;font-weight:800;
  display:flex;align-items:center;justify-content:center;animation:cartBounce .35s ease}
.cart-close-mobile{display:none;width:28px;height:28px;border-radius:50%;
  background:rgba(255,53,83,.1);border:1.5px solid rgba(255,53,83,.2);
  color:var(--red);align-items:center;justify-content:center;cursor:pointer;font-size:14px}
@media(max-width:900px){.cart-close-mobile{display:flex}}

.cart-body{flex:1;overflow-y:auto;padding:10px}
.cart-body::-webkit-scrollbar{width:3px}
.cart-body::-webkit-scrollbar-thumb{background:rgba(50,190,143,.18);border-radius:2px}

.cart-empty{text-align:center;padding:40px 16px;color:var(--muted)}
.cart-empty i{font-size:44px;display:block;margin-bottom:12px;opacity:.1}
.cart-empty p{font-size:11px;font-weight:500}
.cart-empty small{font-size:10px;color:var(--muted);display:block;margin-top:4px}

/* ─── CART ITEM ─── */
.ci{background:var(--card2);border:1px solid var(--bord);border-radius:11px;
  padding:11px 12px;margin-bottom:7px;animation:rowIn .28s ease backwards;
  position:relative;overflow:hidden}
.ci:hover{border-color:rgba(50,190,143,.22)}
.ci-top{display:flex;align-items:flex-start;gap:9px;margin-bottom:8px}
.ci-ico{font-size:20px;flex-shrink:0;margin-top:1px}
.ci-info{flex:1;min-width:0}
.ci-name{font-size:12px;font-weight:700;color:var(--text);line-height:1.3;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ci-price{font-size:10px;font-weight:500;color:var(--muted);margin-top:2px}
.ci-stock-warn{font-size:9px;font-weight:700;color:var(--red);
  display:flex;align-items:center;gap:3px;margin-top:2px}
.ci-del{width:24px;height:24px;border-radius:50%;border:1.5px solid rgba(255,53,83,.2);
  background:rgba(255,53,83,.07);color:var(--red);display:flex;align-items:center;
  justify-content:center;cursor:pointer;font-size:12px;transition:all .2s;flex-shrink:0}
.ci-del:hover{background:var(--red);color:#fff}
.ci-bottom{display:flex;align-items:center;justify-content:space-between}
.ci-qty{display:flex;align-items:center;gap:5px}
.ci-qty button{width:24px;height:24px;border-radius:7px;border:1.5px solid var(--bord);
  background:rgba(0,0,0,.3);color:var(--text2);cursor:pointer;font-size:14px;
  display:flex;align-items:center;justify-content:center;transition:all .15s;font-weight:700}
.ci-qty button:hover{background:var(--neon);color:var(--bg);border-color:var(--neon)}
.ci-qty input{width:42px;height:24px;text-align:center;border-radius:7px;
  border:1.5px solid var(--bord);background:rgba(0,0,0,.3);color:var(--text);
  font-size:12px;font-family:var(--fb);font-weight:700}
.ci-qty input:focus{outline:none;border-color:var(--neon)}
.ci-subtotal{font-family:var(--fd);font-size:14px;font-weight:800;color:var(--neon)}
.ci-stock-bar{height:2px;background:var(--bord);border-radius:1px;margin-top:7px;overflow:hidden}
.ci-stock-bar-fill{height:100%;border-radius:1px;transition:width .4s ease}

/* ─── CART FOOTER ─── */
.cart-footer{border-top:1px solid var(--bord);padding:13px 16px}
.cart-subtotal-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:4px}
.cart-subtotal-lbl{font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:1px}
.cart-subtotal-val{font-size:11px;font-weight:700;color:var(--text2)}
.cart-total-row{display:flex;justify-content:space-between;align-items:center;
  padding:9px 0;border-top:1px solid var(--bord2);margin-bottom:12px}
.cart-total-lbl{font-family:var(--fd);font-size:13px;font-weight:800;color:var(--text)}
.cart-total-val{font-family:var(--fd);font-size:22px;font-weight:800;color:var(--neon)}
.cart-total-val small{font-size:11px;color:var(--muted);font-family:var(--fb);font-weight:500}

/* ─── BUTTONS ─── */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;
  padding:10px 18px;border-radius:10px;border:1.5px solid transparent;cursor:pointer;
  font-family:var(--fb);font-size:12px;font-weight:700;letter-spacing:.3px;
  transition:all .22s;white-space:nowrap;text-decoration:none}
.btn:active{transform:scale(.95)}
.btn-n{background:rgba(50,190,143,.08);border-color:rgba(50,190,143,.22);color:var(--neon)}
.btn-n:hover{background:var(--neon);color:var(--bg)}
.btn-r{background:rgba(255,53,83,.08);border-color:rgba(255,53,83,.22);color:var(--red)}
.btn-r:hover{background:var(--red);color:#fff}
.btn-b{background:rgba(61,140,255,.08);border-color:rgba(61,140,255,.22);color:var(--blue)}
.btn-b:hover{background:var(--blue);color:#fff}
.btn-g{background:rgba(255,208,96,.08);border-color:rgba(255,208,96,.22);color:var(--gold)}
.btn-g:hover{background:var(--gold);color:var(--bg)}
.btn-solid{background:linear-gradient(135deg,var(--neon),var(--cyan));color:var(--bg);
  border:none;box-shadow:var(--gn);font-size:13px;padding:13px 24px}
.btn-solid:hover{box-shadow:0 6px 28px rgba(50,190,143,.55)}
.btn-solid:disabled{opacity:.45;cursor:not-allowed;box-shadow:none;transform:none}
.btn-full{width:100%}

/* ─── STATUS BADGE ─── */
.stock-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;
  border-radius:8px;font-size:9px;font-weight:700}
.sb-ok{background:rgba(50,190,143,.1);color:var(--neon);border:1px solid rgba(50,190,143,.2)}
.sb-low{background:rgba(255,208,96,.1);color:var(--gold);border:1px solid rgba(255,208,96,.2)}
.sb-out{background:rgba(255,53,83,.1);color:var(--red);border:1px solid rgba(255,53,83,.2)}

/* ─── MODAL SUCCESS ─── */
.smodal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.9);z-index:600;
  align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(14px)}
.smodal.on{display:flex;animation:fadeIn .25s ease}
.sbox{background:var(--card);border:1px solid rgba(50,190,143,.28);border-radius:20px;
  width:100%;max-width:380px;padding:28px;text-align:center;
  box-shadow:0 28px 70px rgba(0,0,0,.8),0 0 60px rgba(50,190,143,.08)}
.sbox-ico{font-size:56px;display:block;margin-bottom:14px;
  filter:drop-shadow(0 0 20px rgba(50,190,143,.4))}
.sbox-num{font-family:var(--fd);font-size:20px;font-weight:800;color:var(--gold);
  letter-spacing:1.5px;margin-bottom:6px}
.sbox-amt{font-family:var(--fd);font-size:32px;font-weight:800;color:var(--neon);
  margin-bottom:16px}
.sbox-btns{display:flex;flex-direction:column;gap:8px}

/* ─── TOAST ─── */
.tstack{position:fixed;top:14px;right:14px;z-index:9999;
  display:flex;flex-direction:column;gap:6px;pointer-events:none;max-width:300px}
.tst{background:var(--card2);border:1px solid rgba(50,190,143,.18);border-radius:12px;
  padding:10px 12px;display:flex;align-items:flex-start;gap:8px;
  box-shadow:0 8px 26px rgba(0,0,0,.6);pointer-events:all;cursor:pointer;
  position:relative;overflow:hidden;
  animation:slideLeft .32s cubic-bezier(.23,1,.32,1)}
.tst.err{border-color:rgba(255,53,83,.28)}
.tst.warn{border-color:rgba(255,208,96,.25)}
.tst-bar{position:absolute;bottom:0;left:0;height:2px;width:100%}
.tst-ico{width:26px;height:26px;border-radius:7px;flex-shrink:0;
  display:flex;align-items:center;justify-content:center;font-size:14px;
  background:rgba(50,190,143,.1)}
.tst.err .tst-ico{background:rgba(255,53,83,.1)}
.tst.warn .tst-ico{background:rgba(255,208,96,.1)}
.tst-c{flex:1;min-width:0}
.tst-title{font-size:12px;font-weight:700;color:var(--text);margin-bottom:1px}
.tst-sub{font-size:9px;font-weight:500;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tst-x{position:absolute;top:6px;right:8px;font-size:12px;color:var(--muted);cursor:pointer}
.tst-x:hover{color:var(--red)}

/* ─── EMPTY / SP ─── */
.empty{text-align:center;padding:30px 16px}
.empty i{font-size:36px;display:block;margin-bottom:10px;opacity:.08}
.empty p{font-size:11px;font-weight:500;color:var(--muted)}
.sp{width:14px;height:14px;border:2px solid rgba(255,255,255,.12);border-top-color:currentColor;
  border-radius:50%;animation:spin .65s linear infinite;display:inline-block;vertical-align:middle}
.loading-overlay{position:absolute;inset:0;background:rgba(4,9,14,.6);
  display:flex;align-items:center;justify-content:center;border-radius:12px;
  backdrop-filter:blur(4px);z-index:10}

::-webkit-scrollbar{width:4px;height:4px}
::-webkit-scrollbar-track{background:rgba(0,0,0,.07)}
::-webkit-scrollbar-thumb{background:rgba(50,190,143,.16);border-radius:2px}
@media(max-width:600px){.fgrid{grid-template-columns:1fr}.product-grid{grid-template-columns:repeat(2,1fr)}}
</style>
</head>
<body>

<!-- TOPBAR -->
<div class="topbar">
  <div class="tb-logo">💧</div>
  <div class="tb-title"><span>ESPERANCE</span> H2O — Bon de Livraison</div>
  <div class="cart-toggle-btn" id="cart-toggle-btn" onclick="toggleCartMobile()">
    <i class="fas fa-shopping-cart"></i>
    <div class="cbdg" id="cart-mobile-badge">0</div>
  </div>
  <a href="admin_orders.php" class="tbtn" title="Retour dashboard"><i class="fas fa-arrow-left"></i></a>
</div>

<!-- CART OVERLAY (mobile) -->
<div class="cart-overlay" id="cart-overlay" onclick="toggleCartMobile()"></div>

<div class="page">
<div class="layout">

<!-- ════ MAIN COLUMN ════ -->
<div class="main-col">

<!-- ── 1. CLIENT ── -->
<div class="card" style="animation-delay:.00s">
  <div class="sec-head">
    <div class="sec-num">1</div>
    <div class="sec-ttl">Client</div>
  </div>
  <div class="ctoggle">
    <button type="button" class="ctbtn on" id="ctbtn-exist" onclick="setClientMode('exist')"><i class="fas fa-users"></i> Client existant</button>
    <button type="button" class="ctbtn" id="ctbtn-new" onclick="setClientMode('new')"><i class="fas fa-user-plus"></i> Nouveau client</button>
  </div>
  <div class="ctblock on" id="cb-exist">
    <div style="display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap">
      <div style="flex:1;min-width:200px">
        <div class="catalog-search" style="margin-bottom:8px">
          <i class="fas fa-search"></i>
          <input type="text" id="client-search" placeholder="Rechercher par nom ou téléphone…" oninput="searchClients()" autocomplete="off">
        </div>
        <div id="client-results" style="display:none;background:var(--card2);border:1px solid var(--bord);border-radius:10px;max-height:200px;overflow-y:auto;margin-bottom:8px"></div>
      </div>
      <div id="selected-client-card" style="display:none;background:rgba(50,190,143,.07);border:1.5px solid rgba(50,190,143,.22);border-radius:10px;padding:11px 13px;min-width:200px;flex:1">
        <div style="font-size:9px;font-weight:700;color:var(--neon);text-transform:uppercase;letter-spacing:1px;margin-bottom:5px">Client sélectionné</div>
        <div id="sel-client-name" style="font-size:13px;font-weight:700;color:var(--text)"></div>
        <div id="sel-client-phone" style="font-size:10px;color:var(--muted);margin-top:2px"></div>
        <button onclick="clearClient()" style="margin-top:7px;font-size:9px;color:var(--red);background:none;border:none;cursor:pointer;font-family:var(--fb);font-weight:700"><i class="fas fa-times"></i> Changer</button>
      </div>
    </div>
    <input type="hidden" id="client_id" value="">
  </div>
  <div class="ctblock" id="cb-new">
    <div class="fgrid">
      <div class="fg"><label>Nom *</label><input type="text" id="nc-name" placeholder="Nom complet"></div>
      <div class="fg"><label>Téléphone</label><input type="tel" id="nc-phone" placeholder="+225 XX XX XX XX XX"></div>
      <div class="fg"><label>Email</label><input type="email" id="nc-email" placeholder="email@exemple.com"></div>
      <div class="fg"><label>Adresse</label><input type="text" id="nc-addr" placeholder="Adresse complète"></div>
    </div>
  </div>
</div>

<!-- ── 2. INFOS COMMANDE ── -->
<div class="card" style="animation-delay:.06s">
  <div class="sec-head">
    <div class="sec-num">2</div>
    <div class="sec-ttl">Informations</div>
  </div>
  <div class="fgrid" style="margin-bottom:12px">
    <div class="fg">
      <label>Société *</label>
      <select id="company_id" onchange="onCompanyChange()">
        <option value="">— Choisir —</option>
        <?php foreach($companies as $c): ?>
        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="fg">
      <label>Ville *</label>
      <select id="city_id">
        <option value="">— Choisir —</option>
        <?php foreach($cities as $c): ?>
        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="fg">
      <label>Paiement *</label>
      <select id="payment_method">
        <option value="cash">💵 Espèces</option>
        <option value="mobile_money">📱 Mobile Money</option>
        <option value="bank_transfer">🏦 Virement</option>
        <option value="cheque">📄 Chèque</option>
      </select>
    </div>
  </div>
  <div class="fg" style="margin-bottom:10px">
    <label>Adresse de livraison</label>
    <textarea id="delivery_address" placeholder="Adresse complète de livraison…" rows="2"></textarea>
  </div>
  <div class="fg">
    <label>Notes / Remarques</label>
    <textarea id="notes" placeholder="Instructions spéciales…" rows="2"></textarea>
  </div>
</div>

<!-- ── 3. CATALOGUE PRODUITS ── -->
<div class="card" style="animation-delay:.12s">
  <div class="sec-head">
    <div class="sec-num">3</div>
    <div class="sec-ttl">Catalogue Produits</div>
    <span class="sec-sub" id="prod-count">— produits</span>
  </div>
  <div class="catalog-search">
    <i class="fas fa-search"></i>
    <input type="text" id="prod-search" placeholder="Rechercher un produit…" oninput="filterProducts()" autocomplete="off">
    <span id="prod-search-sp" style="display:none"><span class="sp" style="color:var(--neon)"></span></span>
  </div>
  <div class="catalog-filters" id="cat-filters">
    <div class="cfpill on" data-cat="" onclick="setCatFilter('',this)">Tout</div>
  </div>
  <div class="product-grid" id="product-grid">
    <div class="empty" style="grid-column:1/-1"><i class="fas fa-spinner fa-spin" style="opacity:.15"></i><p>Chargement…</p></div>
  </div>
</div>

<!-- ── 4. ARTICLE LIBRE ── -->
<div class="card" style="animation-delay:.18s">
  <div class="sec-head">
    <div class="sec-num">4</div>
    <div class="sec-ttl">Ajouter article libre</div>
    <span class="sec-sub">Hors catalogue</span>
  </div>
  <div class="fgrid" style="align-items:flex-end">
    <div class="fg"><label>Nom de l'article</label><input type="text" id="free-name" placeholder="Nom du produit…"></div>
    <div class="fg"><label>Quantité</label><input type="number" id="free-qty" value="1" min="1" placeholder="1"></div>
    <div class="fg"><label>Prix unitaire (FCFA)</label><input type="number" id="free-price" value="" min="0" step="1" placeholder="0"></div>
    <div class="fg">
      <label>&nbsp;</label>
      <button class="btn btn-n btn-full" onclick="addFreeItem()"><i class="fas fa-plus"></i> Ajouter au panier</button>
    </div>
  </div>
</div>

</div><!-- /main-col -->

<!-- ════ CART SIDEBAR ════ -->
<div class="cart-sidebar" id="cart-sidebar">
  <div class="cart-head">
    <div class="cart-head-title">
      🛒 Panier
      <span class="cart-count-badge" id="cart-badge">0</span>
    </div>
    <div class="cart-close-mobile" onclick="toggleCartMobile()">×</div>
  </div>

  <div class="cart-body" id="cart-body">
    <div class="cart-empty">
      <i class="fas fa-shopping-cart"></i>
      <p>Votre panier est vide</p>
      <small>Cliquez sur un produit pour l'ajouter</small>
    </div>
  </div>

  <div class="cart-footer">
    <div class="cart-subtotal-row">
      <span class="cart-subtotal-lbl">Sous-total</span>
      <span class="cart-subtotal-val" id="cart-subtotal">0 CFA</span>
    </div>
    <div class="cart-subtotal-row" style="margin-bottom:8px">
      <span class="cart-subtotal-lbl" id="cart-items-count">0 articles</span>
      <span></span>
    </div>
    <div class="cart-total-row">
      <span class="cart-total-lbl">TOTAL</span>
      <span class="cart-total-val"><span id="cart-total">0</span> <small>CFA</small></span>
    </div>
    <button class="btn btn-solid btn-full" id="submit-btn" onclick="submitBon()" disabled>
      <i class="fas fa-check-circle"></i> CRÉER LE BON
    </button>
    <button class="btn btn-r btn-full" style="margin-top:6px;font-size:11px;padding:8px" onclick="clearCart()">
      <i class="fas fa-trash"></i> Vider le panier
    </button>
  </div>
</div>

</div><!-- /layout -->
</div><!-- /page -->

<!-- SUCCESS MODAL -->
<div class="smodal" id="smodal">
  <div class="sbox">
    <span class="sbox-ico">✅</span>
    <div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:1.5px;margin-bottom:8px">Bon créé avec succès</div>
    <div class="sbox-num" id="s-num"></div>
    <div class="sbox-amt"><span id="s-amt"></span> <small style="font-size:14px;color:var(--muted)">CFA</small></div>
    <div class="sbox-btns">
      <a id="s-export-btn" href="#" target="_blank" class="btn btn-solid btn-full"><i class="fas fa-file-excel"></i> Télécharger Excel</a>
      <a href="admin_orders.php" class="btn btn-b btn-full"><i class="fas fa-arrow-left"></i> Retour Dashboard</a>
      <button class="btn btn-n btn-full" onclick="resetForm()"><i class="fas fa-plus"></i> Nouveau bon</button>
    </div>
  </div>
</div>

<!-- TOAST -->
<div class="tstack" id="tstack"></div>

<!-- Hidden CSRF -->
<input type="hidden" id="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

<!-- Cities JSON for JS -->
<script>
var ALL_CITIES = <?= json_encode($cities) ?>;
var ALL_PRODUCTS = <?= json_encode($init_products) ?>;
var SELF = location.pathname;
</script>

<script>
/* ════════════════════════════════════════════════════
   ESPERANCE H2O — BON DE LIVRAISON v2
   Panier instantané + Vérification stock
════════════════════════════════════════════════════ */

/* ── STATE ── */
var cart = [];          // [{id, product_id, product_name, unit_price, quantity, stock, is_free}]
var clientMode = 'exist';
var selectedClient = null;
var allProducts = ALL_PRODUCTS;
var filteredProducts = ALL_PRODUCTS;
var catFilter = '';
var searchTmr = null;
var clientTmr = null;

function pico(name) {
    var s = (name||'').toLowerCase();
    if(s.includes('eau')||s.includes('water')) return '💧';
    if(s.includes('jus')||s.includes('juice')) return '🍹';
    if(s.includes('lait')) return '🥛';
    if(s.includes('bière')) return '🍺';
    if(s.includes('soda')) return '🥤';
    if(s.includes('bidon')) return '🪣';
    if(s.includes('bouteille')) return '🍾';
    if(s.includes('gallon')) return '🫙';
    return '📦';
}
function fmt(v) {
    return (+v).toLocaleString('fr-FR');
}
function stockClass(s) {
    s = +s;
    if(s <= 0) return 'empty';
    if(s <= 5) return 'low';
    return 'ok';
}
function stockLabel(s) {
    s = +s;
    if(s <= 0) return '❌ Épuisé';
    if(s <= 5) return '⚠️ '+s+' restant'+(s>1?'s':'');
    return '✓ '+s+' en stock';
}

/* ── TOAST ── */
function toast(title, sub, type, dur) {
    type = type||'ok'; dur = dur||4000;
    var e = document.createElement('div');
    var clz = 'tst'+(type==='err'?' err':type==='warn'?' warn':'');
    var icons = {ok:'✅', err:'❌', warn:'⚠️'};
    var barC = type==='err'?'var(--red)':type==='warn'?'var(--gold)':'var(--neon)';
    e.className = clz;
    e.innerHTML = '<div class="tst-ico">'+(icons[type]||'💡')+'</div>'+
        '<div class="tst-c"><div class="tst-title">'+title+'</div>'+(sub?'<div class="tst-sub">'+sub+'</div>':'')+'</div>'+
        '<div class="tst-x" onclick="this.parentNode.remove()">×</div>'+
        '<div class="tst-bar" style="background:'+barC+';transition:width '+dur+'ms linear"></div>';
    document.getElementById('tstack').appendChild(e);
    requestAnimationFrame(function(){requestAnimationFrame(function(){e.querySelector('.tst-bar').style.width='0';});});
    e.onclick = function(ev){if(!ev.target.classList.contains('tst-x'))e.remove();};
    setTimeout(function(){e.style.opacity='0';e.style.transform='translateX(110%)';e.style.transition='all .3s';setTimeout(function(){if(e.parentNode)e.remove();},300);}, dur);
}

/* ── CLIENT ── */
function setClientMode(mode) {
    clientMode = mode;
    document.getElementById('ctbtn-exist').classList.toggle('on', mode==='exist');
    document.getElementById('ctbtn-new').classList.toggle('on', mode==='new');
    document.getElementById('cb-exist').classList.toggle('on', mode==='exist');
    document.getElementById('cb-new').classList.toggle('on', mode==='new');
}

var clientSearchTmr = null;
function searchClients() {
    clearTimeout(clientSearchTmr);
    var q = document.getElementById('client-search').value.trim();
    if(q.length < 1) { document.getElementById('client-results').style.display='none'; return; }
    clientSearchTmr = setTimeout(async function() {
        var fd = new FormData(); fd.append('action','search_clients'); fd.append('q',q);
        try {
            var res = await fetch(SELF,{method:'POST',body:fd});
            var d = await res.json();
            if(!d.success) return;
            var list = document.getElementById('client-results');
            if(!d.clients.length){ list.innerHTML='<div style="padding:12px;font-size:11px;color:var(--muted);text-align:center">Aucun client trouvé</div>'; }
            else {
                list.innerHTML = d.clients.map(function(c){
                    return '<div onclick="selectClient('+c.id+',\''+c.name.replace(/'/g,"\\'")+'\',\''+( c.phone||'').replace(/'/g,"\\'")+'\',\''+( c.address||'').replace(/'/g,"\\'")+'\')'+'"'+
                        ' style="padding:10px 13px;cursor:pointer;border-bottom:1px solid rgba(255,255,255,.04);transition:background .2s" onmouseover="this.style.background=\'rgba(50,190,143,.08)\'" onmouseout="this.style.background=\'\'">'+
                        '<div style="font-size:12px;font-weight:700;color:var(--text)">'+c.name+'</div>'+
                        (c.phone?'<div style="font-size:10px;color:var(--muted)">'+c.phone+'</div>':'')+
                    '</div>';
                }).join('');
            }
            list.style.display='block';
        } catch(ex) {}
    }, 300);
}
function selectClient(id, name, phone, address) {
    selectedClient = {id:id, name:name, phone:phone, address:address};
    document.getElementById('client_id').value = id;
    document.getElementById('selected-client-card').style.display='block';
    document.getElementById('sel-client-name').textContent = name;
    document.getElementById('sel-client-phone').textContent = phone||'';
    document.getElementById('client-results').style.display='none';
    document.getElementById('client-search').value='';
    // Auto-fill delivery address if empty
    if (address && !document.getElementById('delivery_address').value) {
        document.getElementById('delivery_address').value = address;
    }
    toast('Client sélectionné', name, 'ok', 2500);
}
function clearClient() {
    selectedClient = null;
    document.getElementById('client_id').value='';
    document.getElementById('selected-client-card').style.display='none';
}

/* ── COMPANY CHANGE — filter cities ── */
function onCompanyChange() {
    var cid = +document.getElementById('company_id').value;
    var sel = document.getElementById('city_id');
    var cur = sel.value;
    sel.innerHTML = '<option value="">— Choisir —</option>';
    ALL_CITIES.forEach(function(c) {
        if(!cid || !c.company_id || +c.company_id === cid) {
            var opt = document.createElement('option');
            opt.value = c.id; opt.textContent = c.name;
            if(c.id == cur) opt.selected = true;
            sel.appendChild(opt);
        }
    });
    // Reload products for this company's stock
    loadProductsForCompany(cid);
}

/* ── PRODUCTS CATALOG ── */
async function loadProductsForCompany(cid) {
    var fd = new FormData(); fd.append('action','search_products'); fd.append('q',''); fd.append('company_id',cid||0);
    try {
        var res = await fetch(SELF,{method:'POST',body:fd});
        var d = await res.json();
        if(d.success) {
            allProducts = d.products;
            filteredProducts = d.products;
            buildCatFilters(d.products);
            renderProducts(d.products);
        }
    } catch(ex) {}
}

function buildCatFilters(products) {
    var cats = {};
    products.forEach(function(p){ if(p.category) cats[p.category]=1; });
    var ckeys = Object.keys(cats).filter(Boolean);
    var cf = document.getElementById('cat-filters');
    cf.innerHTML = '<div class="cfpill on" data-cat="" onclick="setCatFilter(\'\',this)">Tout</div>';
    ckeys.forEach(function(c){
        var d = document.createElement('div');
        d.className='cfpill'; d.setAttribute('data-cat',c);
        d.onclick = function(){ setCatFilter(c, d); };
        d.textContent = c;
        cf.appendChild(d);
    });
}

function setCatFilter(cat, el) {
    catFilter = cat;
    document.querySelectorAll('.cfpill').forEach(function(p){p.classList.remove('on');});
    el.classList.add('on');
    applyFilter();
}

function filterProducts() {
    clearTimeout(searchTmr);
    searchTmr = setTimeout(applyFilter, 250);
}

function applyFilter() {
    var q = document.getElementById('prod-search').value.toLowerCase();
    filteredProducts = allProducts.filter(function(p) {
        var matchQ = !q || p.name.toLowerCase().includes(q);
        var matchC = !catFilter || p.category === catFilter;
        return matchQ && matchC;
    });
    renderProducts(filteredProducts);
}

function renderProducts(products) {
    var grid = document.getElementById('product-grid');
    document.getElementById('prod-count').textContent = products.length + ' produits';
    if(!products.length) {
        grid.innerHTML = '<div class="empty" style="grid-column:1/-1"><i class="fas fa-box-open"></i><p>Aucun produit trouvé</p></div>';
        return;
    }
    grid.innerHTML = products.map(function(p, i) {
        var s = +p.stock;
        var sc = stockClass(s);
        var sl = stockLabel(s);
        var disabled = s <= 0 ? ' out-of-stock' : (s<=5?' low-stock':'');
        return '<div class="pcard'+disabled+'" id="pcard-'+p.id+'" style="animation-delay:'+(i*0.025)+'s" onclick="addToCartById('+p.id+',event)">'+
            '<span class="pcard-ico">'+pico(p.name)+'</span>'+
            (p.category?'<div class="pcard-cat">'+p.category+'</div>':'')+
            '<div class="pcard-name">'+p.name+'</div>'+
            '<div class="pcard-price">'+fmt(p.price)+' <small>CFA</small></div>'+
            '<div class="pcard-stock '+sc+'">'+sl+'</div>'+
            '<div class="pcard-qty" onclick="event.stopPropagation()">'+
                '<button onclick="pcardQtyChg(-1,\''+p.id+'\')" type="button">−</button>'+
                '<input type="number" id="pqty-'+p.id+'" value="1" min="1" max="'+(s>0?s:1)+'" style="'+(!s?'opacity:.4':'')+'">'+
                '<button onclick="pcardQtyChg(1,\''+p.id+'\')" type="button">+</button>'+
            '</div>'+
            '<div class="pcard-add"><i class="fas fa-plus" style="font-size:11px"></i></div>'+
        '</div>';
    }).join('');
}

function pcardQtyChg(delta, pid) {
    var inp = document.getElementById('pqty-'+pid);
    if(!inp) return;
    var v = Math.max(1, (+inp.value||1)+delta);
    inp.value = v;
}

async function addToCartById(pid, e) {
    if(e && e.target.closest('.pcard-qty')) return;
    var prod = allProducts.find(function(p){ return +p.id===+pid; });
    if(!prod) return;
    if(+prod.stock <= 0) { toast('Stock épuisé', prod.name+' — Produit non disponible', 'err', 4000); return; }

    var qtyInp = document.getElementById('pqty-'+pid);
    var qty = qtyInp ? Math.max(1, +qtyInp.value||1) : 1;

    // Check if already in cart
    var existing = cart.find(function(c){ return c.product_id==pid; });
    var alreadyQty = existing ? existing.quantity : 0;
    var needed = alreadyQty + qty;

    if(+prod.stock < needed) {
        toast('Stock insuffisant', 'Dispo: '+prod.stock+' — Dans panier: '+alreadyQty, 'warn', 4000);
        if(alreadyQty>=+prod.stock) return;
        qty = Math.max(1, +prod.stock - alreadyQty);
        if(qty<=0) return;
    }

    if(existing) {
        existing.quantity += qty;
        renderCart();
        toast('Quantité mise à jour', prod.name+' × '+existing.quantity, 'ok', 2000);
    } else {
        cart.push({
            id: Date.now(),
            product_id: pid,
            product_name: prod.name,
            unit_price: +prod.price,
            quantity: qty,
            stock: +prod.stock,
            is_free: false
        });
        renderCart();
        toast('Ajouté au panier', prod.name+' × '+qty+' — '+fmt(prod.price)+' CFA', 'ok', 2200);
    }

    // Reset qty input
    if(qtyInp) qtyInp.value = 1;
    // Animate card
    var card = document.getElementById('pcard-'+pid);
    if(card) { card.style.transition='transform .15s,border-color .2s';card.style.transform='scale(.96)';card.style.borderColor='var(--neon)';setTimeout(function(){card.style.transform='';card.style.borderColor='';},180); }
}

/* ── FREE ITEM ── */
function addFreeItem() {
    var name  = document.getElementById('free-name').value.trim();
    var qty   = Math.max(1, +(document.getElementById('free-qty').value)||1);
    var price = +(document.getElementById('free-price').value)||0;
    if(!name) { toast('Nom requis', 'Entrez le nom de l\'article', 'warn'); return; }
    if(price <= 0) { toast('Prix requis', 'Entrez un prix supérieur à 0', 'warn'); return; }
    cart.push({ id:Date.now(), product_id:null, product_name:name, unit_price:price, quantity:qty, stock:9999, is_free:true });
    renderCart();
    toast('Article ajouté', name+' × '+qty, 'ok', 2200);
    document.getElementById('free-name').value='';
    document.getElementById('free-qty').value=1;
    document.getElementById('free-price').value='';
}

/* ── RENDER CART ── */
function renderCart() {
    var body = document.getElementById('cart-body');
    var n = cart.reduce(function(s,c){return s+c.quantity;},0);
    var total = cart.reduce(function(s,c){return s+(c.quantity*c.unit_price);},0);

    // Update header
    document.getElementById('cart-badge').textContent = n;
    document.getElementById('cart-mobile-badge').textContent = n;
    document.getElementById('cart-total').textContent = fmt(total);
    document.getElementById('cart-subtotal').textContent = fmt(total)+' CFA';
    document.getElementById('cart-items-count').textContent = n+' article'+(n>1?'s':'');
    document.getElementById('submit-btn').disabled = cart.length === 0;

    // Badge bounce
    var badge = document.getElementById('cart-badge');
    badge.style.animation='none';
    requestAnimationFrame(function(){ badge.style.animation='cartBounce .35s ease'; });

    if(!cart.length) {
        body.innerHTML = '<div class="cart-empty"><i class="fas fa-shopping-cart"></i><p>Votre panier est vide</p><small>Cliquez sur un produit pour l\'ajouter</small></div>';
        return;
    }

    body.innerHTML = cart.map(function(item, idx) {
        var sub = item.quantity * item.unit_price;
        var stockPct = item.is_free ? 100 : Math.min(100, Math.round(item.stock>0?(item.quantity/item.stock*100):100));
        var stockOk = item.is_free || item.quantity <= item.stock;
        var stockColor = item.is_free ? 'var(--neon)' : (stockOk?'var(--neon)':stockPct<20?'var(--red)':'var(--gold)');
        return '<div class="ci" style="animation-delay:'+(idx*0.04)+'s">'+
            '<div class="ci-top">'+
                '<div class="ci-ico">'+pico(item.product_name)+'</div>'+
                '<div class="ci-info">'+
                    '<div class="ci-name">'+item.product_name+(item.is_free?' <span style="font-size:8px;color:var(--purple);background:rgba(167,139,250,.1);padding:1px 5px;border-radius:4px;margin-left:3px">Libre</span>':'')+'</div>'+
                    '<div class="ci-price">'+fmt(item.unit_price)+' CFA / unité</div>'+
                    (!item.is_free && item.quantity > item.stock ? '<div class="ci-stock-warn"><i class="fas fa-exclamation-triangle"></i> Dépasse le stock ('+item.stock+')</div>':'')+''+
                    (!item.is_free ? '<div style="margin-top:3px"><span class="stock-badge sb-'+stockClass(item.stock)+'">Stock: '+item.stock+'</span></div>' : '')+
                '</div>'+
                '<div class="ci-del" onclick="removeFromCart('+item.id+')"><i class="fas fa-trash"></i></div>'+
            '</div>'+
            '<div class="ci-bottom">'+
                '<div class="ci-qty">'+
                    '<button onclick="cartQtyChg('+item.id+',-1)" type="button">−</button>'+
                    '<input type="number" value="'+item.quantity+'" min="1" max="'+(item.is_free?9999:item.stock||9999)+'" onchange="cartQtySet('+item.id+',this.value)">'+
                    '<button onclick="cartQtyChg('+item.id+',1)" type="button">+</button>'+
                '</div>'+
                '<div class="ci-subtotal">'+fmt(sub)+' <span style="font-size:9px;font-weight:500;color:var(--muted)">CFA</span></div>'+
            '</div>'+
            '<div class="ci-stock-bar"><div class="ci-stock-bar-fill" style="width:'+(item.is_free?100:Math.min(100,Math.round(item.quantity/(item.stock||1)*100)))+'%;background:'+stockColor+'"></div></div>'+
        '</div>';
    }).join('');
}

function removeFromCart(id) {
    cart = cart.filter(function(c){return c.id!==id;});
    renderCart();
}
function clearCart() {
    if(!cart.length) return;
    cart = [];
    renderCart();
    toast('Panier vidé', '', 'ok', 2000);
}
function cartQtyChg(id, delta) {
    var item = cart.find(function(c){return c.id===id;});
    if(!item) return;
    var newQ = Math.max(1, item.quantity+delta);
    if(!item.is_free && newQ > item.stock) { toast('Stock insuffisant','Max: '+item.stock+' unités','warn',3000); newQ=item.stock; }
    item.quantity = newQ;
    renderCart();
}
function cartQtySet(id, val) {
    var item = cart.find(function(c){return c.id===id;});
    if(!item) return;
    var newQ = Math.max(1, +val||1);
    if(!item.is_free && newQ > item.stock) { toast('Stock insuffisant','Max: '+item.stock+' unités','warn',3000); newQ=item.stock; }
    item.quantity = newQ;
    renderCart();
}

/* ── SUBMIT BON ── */
async function submitBon() {
    // Validate client
    var clientId = '';
    var newClientName = '';
    if(clientMode === 'exist') {
        clientId = document.getElementById('client_id').value;
        if(!clientId) { toast('Client requis','Sélectionnez un client existant','warn'); return; }
    } else {
        newClientName = document.getElementById('nc-name').value.trim();
        if(!newClientName) { toast('Nom client requis','Entrez le nom du nouveau client','warn'); return; }
    }
    var companyId = document.getElementById('company_id').value;
    if(!companyId) { toast('Société requise','Sélectionnez une société','warn'); return; }
    var cityId = document.getElementById('city_id').value;
    if(!cityId) { toast('Ville requise','Sélectionnez une ville','warn'); return; }
    if(!cart.length) { toast('Panier vide','Ajoutez des articles au panier','warn'); return; }

    // Check stock warnings
    var stockErr = cart.filter(function(i){return !i.is_free && i.quantity>i.stock;});
    if(stockErr.length) {
        toast('Stock insuffisant','Corrigez les quantités en rouge','err',5000); return;
    }

    var btn = document.getElementById('submit-btn');
    btn.disabled = true;
    btn.innerHTML = '<span class="sp" style="color:var(--bg)"></span> Création…';

    var fd = new FormData();
    fd.append('action','create_bon');
    fd.append('csrf_token', document.getElementById('csrf_token').value);
    fd.append('client_id', clientId);
    fd.append('new_client_name', newClientName);
    var ncPhone = document.getElementById('nc-phone'); fd.append('new_client_phone', ncPhone ? ncPhone.value : '');
    var ncEmail = document.getElementById('nc-email'); fd.append('new_client_email', ncEmail ? ncEmail.value : '');
    var ncAddr  = document.getElementById('nc-addr');  fd.append('new_client_address', ncAddr  ? ncAddr.value  : '');
    fd.append('company_id', companyId);
    fd.append('city_id', cityId);
    fd.append('payment_method', document.getElementById('payment_method').value);
    fd.append('delivery_address', document.getElementById('delivery_address').value);
    fd.append('notes', document.getElementById('notes').value);
    fd.append('cart_items', JSON.stringify(cart.map(function(i){
        return {product_id:i.product_id,product_name:i.product_name,quantity:i.quantity,unit_price:i.unit_price};
    })));

    try {
        var res = await fetch(SELF,{method:'POST',body:fd});
        var d = await res.json();
        if(d.success) {
            document.getElementById('s-num').textContent = d.order_number;
            document.getElementById('s-amt').textContent = fmt(d.total);
            document.getElementById('s-export-btn').href = 'export_bons.php?search='+d.order_number;
            document.getElementById('smodal').classList.add('on');
            cart = [];
            renderCart();
        } else {
            toast('Erreur', d.message||'', 'err', 6000);
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check-circle"></i> CRÉER LE BON';
        }
    } catch(ex) {
        toast('Erreur réseau', ex.message, 'err');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check-circle"></i> CRÉER LE BON';
    }
}

function resetForm() {
    document.getElementById('smodal').classList.remove('on');
    clearClient();
    document.getElementById('delivery_address').value='';
    document.getElementById('notes').value='';
    document.getElementById('submit-btn').disabled=true;
    document.getElementById('submit-btn').innerHTML='<i class="fas fa-check-circle"></i> CRÉER LE BON';
}

/* ── MOBILE CART ── */
function toggleCartMobile() {
    var sb = document.getElementById('cart-sidebar');
    var ov = document.getElementById('cart-overlay');
    sb.classList.toggle('open');
    ov.classList.toggle('block');
    document.body.style.overflow = sb.classList.contains('open') ? 'hidden' : '';
}

/* ── INIT ── */
(function() {
    buildCatFilters(ALL_PRODUCTS);
    renderProducts(ALL_PRODUCTS);
    renderCart();

    // Load clients into search
    var initClients = <?= json_encode(array_map(function($c){ return ['id'=>$c['id'],'name'=>$c['name'],'phone'=>$c['phone']??'']; }, $clients)) ?>;
    document.getElementById('client-search').addEventListener('focus', function(){
        if(initClients.length > 0 && !document.getElementById('client_id').value) {
            // Show first few clients on focus if empty
            var q = this.value.trim();
            if(!q) {
                var list = document.getElementById('client-results');
                list.innerHTML = initClients.slice(0,8).map(function(c){
                    return '<div onclick="selectClient('+c.id+',\''+c.name.replace(/'/g,"\\'")+'\',\''+(c.phone||'').replace(/'/g,"\\'")+'\',\'\')'+'"'+
                        ' style="padding:10px 13px;cursor:pointer;border-bottom:1px solid rgba(255,255,255,.04);transition:background .2s" onmouseover="this.style.background=\'rgba(50,190,143,.08)\'" onmouseout="this.style.background=\'\'">'+
                        '<div style="font-size:12px;font-weight:700;color:var(--text)">'+c.name+'</div>'+
                        (c.phone?'<div style="font-size:10px;color:var(--muted)">'+c.phone+'</div>':'')+
                    '</div>';
                }).join('');
                if(initClients.length>8) list.innerHTML += '<div style="padding:8px 13px;font-size:10px;color:var(--muted);text-align:center">+ '+(initClients.length-8)+' autres — tapez pour filtrer</div>';
                list.style.display='block';
            }
        }
    });
    document.addEventListener('click',function(e){
        if(!e.target.closest('#cb-exist')) document.getElementById('client-results').style.display='none';
    });
})();
</script>

</body>
</html>
