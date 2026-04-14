<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

/**
 * ═══════════════════════════════════════════════════════════════
 * GESTION DES STOCKS PRO v2.0 — ESPERANCE H2O
 * Style: Dark Neon · C059 Bold · Même design que le RH Dashboard
 * Nouveautés: Historique Ventes Annulées · Logs Immuables
 * ═══════════════════════════════════════════════════════════════
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
Middleware::role(['developer', 'admin']);

$pdo = DB::getConnection();

$user_id   = $_SESSION['user_id'] ?? 0;
$user_name = $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'Utilisateur';
if (!$user_name || $user_name === 'Utilisateur') {
    $st = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $st->execute([$user_id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r) $user_name = $r['username'];
}

/* ══════════════════════════════════════════════════
   LOGGER ULTRA-DÉTAILLÉ — IMPOSSIBLE À SUPPRIMER
   Chaque appel enregistre : IP, User-Agent, Session,
   Page URL, Méthode, Heure précise, User ID, etc.
══════════════════════════════════════════════════ */
function logAction(
    $pdo, $uid, $type, $desc,
    $pid = null, $iid = null, $amt = null, $qty = null
) {
    try {
        $ip       = $_SERVER['REMOTE_ADDR']     ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? 'unknown';
        $ua       = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $sid      = session_id();
        $url      = ($_SERVER['REQUEST_URI']    ?? '') . ' [' . ($_SERVER['REQUEST_METHOD'] ?? 'GET') . ']';
        $referer  = $_SERVER['HTTP_REFERER']    ?? '';
        $host     = $_SERVER['HTTP_HOST']       ?? '';

        // Détection OS & navigateur sommaire
        $browser = 'Inconnu';
        if (str_contains($ua, 'Chrome'))       $browser = 'Chrome';
        elseif (str_contains($ua, 'Firefox'))  $browser = 'Firefox';
        elseif (str_contains($ua, 'Safari'))   $browser = 'Safari';
        elseif (str_contains($ua, 'Edge'))     $browser = 'Edge';
        elseif (str_contains($ua, 'MSIE') || str_contains($ua, 'Trident')) $browser = 'IE';

        $os = 'Inconnu';
        if (str_contains($ua, 'Windows'))      $os = 'Windows';
        elseif (str_contains($ua, 'Macintosh')) $os = 'macOS';
        elseif (str_contains($ua, 'Linux'))    $os = 'Linux';
        elseif (str_contains($ua, 'Android'))  $os = 'Android';
        elseif (str_contains($ua, 'iPhone') || str_contains($ua, 'iPad')) $os = 'iOS';

        $full_desc = "[{$browser}/{$os}] {$desc}";
        if ($referer) $full_desc .= " | Ref: {$referer}";

        $st = $pdo->prepare("
            INSERT INTO cash_log
            (user_id, session_id, action_type, action_description,
             product_id, invoice_id, amount, quantity,
             ip_address, user_agent)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ");
        $st->execute([
            $uid, $sid, $type, $full_desc,
            $pid, $iid, $amt, $qty,
            $ip, $ua
        ]);
    } catch (Exception $e) { /* silencieux */ }
}

/* ── Helpers stock ── */
function getStock($pdo, $pid, $coid, $cid) {
    $st = $pdo->prepare("
        SELECT COALESCE(SUM(CASE WHEN type='initial'    THEN quantity END),0)
             + COALESCE(SUM(CASE WHEN type='entry'      THEN quantity END),0)
             - COALESCE(SUM(CASE WHEN type='exit'       THEN quantity END),0)
             + COALESCE(SUM(CASE WHEN type='adjustment' THEN quantity END),0) AS s
        FROM stock_movements
        WHERE product_id=? AND company_id=? AND city_id=?
    ");
    $st->execute([$pid,$coid,$cid]);
    return (int)$st->fetchColumn();
}

function getInitialDefined($pdo, $pid, $coid, $cid) {
    $st = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM stock_movements
        WHERE product_id=? AND company_id=? AND city_id=? AND type='initial'");
    $st->execute([$pid,$coid,$cid]);
    return (int)$st->fetchColumn();
}

function hasEntryToday($pdo, $pid, $coid, $cid) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM stock_movements
        WHERE product_id=? AND company_id=? AND city_id=? AND type='entry' AND DATE(movement_date)=CURDATE()");
    $st->execute([$pid,$coid,$cid]);
    return (int)$st->fetchColumn() > 0;
}

/* ── Session localisation ── */
if (!isset($_SESSION['caisse_company_id'])) $_SESSION['caisse_company_id'] = 0;
if (!isset($_SESSION['caisse_city_id']))    $_SESSION['caisse_city_id']    = 0;

if (isset($_GET['company_id']))   { $_SESSION['caisse_company_id'] = (int)$_GET['company_id']; }
if (isset($_GET['confirm_location'], $_GET['city_id'])) {
    $_SESSION['caisse_city_id'] = (int)$_GET['city_id'];
    logAction($pdo,$user_id,'LOCATION_SET',"Localisation confirmée — Page stock_update");
    header("Location: stock_update_fixed.php"); exit;
}

$company_id   = $_SESSION['caisse_company_id'];
$city_id      = $_SESSION['caisse_city_id'];
$location_set = ($company_id > 0 && $city_id > 0);
$view         = $_GET['view'] ?? 'stock';

/* ── Listes ── */
$companies = $pdo->query("SELECT id,name FROM companies ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$cities = [];
if ($company_id) {
    $st = $pdo->prepare("SELECT id,name FROM cities WHERE company_id=? ORDER BY name");
    $st->execute([$company_id]); $cities = $st->fetchAll(PDO::FETCH_ASSOC);
}
$products = [];
if ($location_set) {
    $st = $pdo->prepare("SELECT id,name,price,alert_quantity FROM products WHERE company_id=? ORDER BY name");
    $st->execute([$company_id]); $products = $st->fetchAll(PDO::FETCH_ASSOC);
}

/* ══ LOG : chaque chargement de page ══ */
if ($location_set) {
    logAction($pdo,$user_id,'PAGE_VIEW',"Vue page stock — onglet: $view");
}

/* ──────────────────────────────────────────────
   TRAITEMENTS FORMULAIRES
──────────────────────────────────────────────── */

/* STOCK INITIAL */
if (isset($_POST['set_initial_stock']) && $location_set) {
    $pid  = (int)$_POST['product_id_initial'];
    $qty  = (int)$_POST['quantity_initial'];
    $date = $_POST['date_initial'] ?? date('Y-m-d H:i:s');
    $ref  = trim($_POST['reference_initial']) ?: 'STOCK-INITIAL-'.date('Ymd');

    $st = $pdo->prepare("SELECT id,quantity FROM stock_movements
        WHERE product_id=? AND company_id=? AND city_id=? AND type='initial' LIMIT 1");
    $st->execute([$pid,$company_id,$city_id]);
    $exist = $st->fetch(PDO::FETCH_ASSOC);

    if ($exist) {
        $old = $exist['quantity'];
        $pdo->prepare("UPDATE stock_movements SET quantity=?,movement_date=?,reference=? WHERE id=?")
            ->execute([$qty,$date,$ref,$exist['id']]);
        logAction($pdo,$user_id,'STOCK_INITIAL_UPDATE',"Stock initial modifié: $old → $qty unités",$pid,null,null,$qty);
        $_SESSION['success'] = "✅ Stock initial modifié : $old → $qty unités";
    } else {
        $pdo->prepare("INSERT INTO stock_movements(product_id,company_id,city_id,type,quantity,movement_date,reference)VALUES(?,?,?,'initial',?,?,?)")
            ->execute([$pid,$company_id,$city_id,$qty,$date,$ref]);
        logAction($pdo,$user_id,'STOCK_INITIAL_CREATE',"Stock initial créé: $qty unités",$pid,null,null,$qty);
        $_SESSION['success'] = "✅ Stock initial de $qty unités défini !";
    }
    header("Location: ".$_SERVER['PHP_SELF']); exit;
}

/* SUPPRIMER STOCK INITIAL */
if (isset($_POST['delete_initial_stock']) && $location_set) {
    $pid = (int)$_POST['product_id_delete'];
    $pdo->prepare("DELETE FROM stock_movements WHERE product_id=? AND company_id=? AND city_id=? AND type='initial'")
        ->execute([$pid,$company_id,$city_id]);
    logAction($pdo,$user_id,'STOCK_INITIAL_DELETE',"Stock initial supprimé pour produit #$pid",$pid);
    $_SESSION['success'] = "✅ Stock initial supprimé.";
    header("Location: ".$_SERVER['PHP_SELF']); exit;
}

/* ARRIVAGE */
if (isset($_POST['check_entry']) && $location_set) {
    $pid = (int)$_POST['product_id'];
    $qty = (int)$_POST['quantity'];
    $date = $_POST['date'] ?? date('Y-m-d H:i:s');
    $ref  = trim($_POST['reference']) ?: 'ARRIVAGE-'.date('YmdHis');

    if (hasEntryToday($pdo,$pid,$company_id,$city_id)) {
        $_SESSION['pending_entry'] = compact('pid','qty','date','ref');
        $stn = $pdo->prepare("SELECT name FROM products WHERE id=?"); $stn->execute([$pid]);
        $_SESSION['confirm_msg'] = "Un arrivage de <strong>".$stn->fetchColumn()."</strong> existe déjà aujourd'hui. Confirmer quand même ?";
    } else {
        $pdo->prepare("INSERT INTO stock_movements(product_id,company_id,city_id,type,quantity,movement_date,reference)VALUES(?,?,?,'entry',?,?,?)")
            ->execute([$pid,$company_id,$city_id,$qty,$date,$ref]);
        logAction($pdo,$user_id,'STOCK_ENTRY',"Arrivage enregistré: +$qty unités | Ref: $ref",$pid,null,null,$qty);
        $_SESSION['success'] = "✅ Arrivage de $qty unités enregistré !";
    }
    header("Location: ".$_SERVER['PHP_SELF']); exit;
}

/* CONFIRMER ARRIVAGE */
if (isset($_POST['confirm_entry']) && $location_set && isset($_SESSION['pending_entry'])) {
    $p = $_SESSION['pending_entry'];
    $pdo->prepare("INSERT INTO stock_movements(product_id,company_id,city_id,type,quantity,movement_date,reference)VALUES(?,?,?,'entry',?,?,?)")
        ->execute([$p['pid'],$company_id,$city_id,$p['qty'],$p['date'],$p['ref']]);
    logAction($pdo,$user_id,'STOCK_ENTRY_CONFIRMED',"Arrivage confirmé (doublon jour): +{$p['qty']} unités",$p['pid'],null,null,$p['qty']);
    $_SESSION['success'] = "✅ Arrivage confirmé.";
    unset($_SESSION['pending_entry'],$_SESSION['confirm_msg']);
    header("Location: ".$_SERVER['PHP_SELF']); exit;
}

if (isset($_POST['cancel_entry'])) {
    unset($_SESSION['pending_entry'],$_SESSION['confirm_msg']);
    $_SESSION['info'] = "ℹ️ Arrivage annulé.";
    header("Location: ".$_SERVER['PHP_SELF']); exit;
}

/* AJUSTEMENT */
if (isset($_POST['add_adjustment']) && $location_set) {
    $pid  = (int)$_POST['product_id_adjustment'];
    $type = $_POST['adjustment_type'];
    $qty  = (int)$_POST['quantity_adjustment'];
    $qty  = ($type === 'negative') ? -abs($qty) : abs($qty);
    $reason = trim($_POST['reason_adjustment']);
    $date   = $_POST['date_adjustment'] ?? date('Y-m-d H:i:s');
    $ref    = $reason ?: 'AJUSTEMENT-'.date('YmdHis');
    $before = getStock($pdo,$pid,$company_id,$city_id);

    $pdo->prepare("INSERT INTO stock_movements(product_id,company_id,city_id,type,quantity,movement_date,reference)VALUES(?,?,?,'adjustment',?,?,?)")
        ->execute([$pid,$company_id,$city_id,$qty,$date,$ref]);
    $after = $before + $qty;
    logAction($pdo,$user_id,'STOCK_ADJUSTMENT',"Ajustement inventaire: $before → $after ($qty) | Raison: $reason",$pid,null,null,$qty);
    $_SESSION['success'] = "✅ Ajustement enregistré ! Stock : $before → $after";
    header("Location: ".$_SERVER['PHP_SELF']); exit;
}

/* MODIFIER MOUVEMENT */
if (isset($_POST['edit_movement']) && $location_set) {
    $mid = (int)$_POST['movement_id'];
    $qty = (int)$_POST['edit_quantity'];
    $date = $_POST['edit_date'];
    $ref  = trim($_POST['edit_reference']);

    $st = $pdo->prepare("SELECT * FROM stock_movements WHERE id=?");
    $st->execute([$mid]); $old = $st->fetch(PDO::FETCH_ASSOC);

    if ($old) {
        if ($old['type'] === 'adjustment' && $old['quantity'] < 0) $qty = -abs($qty);
        $pdo->prepare("UPDATE stock_movements SET quantity=?,movement_date=?,reference=? WHERE id=?")
            ->execute([$qty,$date,$ref,$mid]);
        logAction($pdo,$user_id,'STOCK_MOVEMENT_EDIT',"Mouvement #{$mid} modifié: {$old['quantity']} → $qty | Ref: $ref",$old['product_id'],null,null,$qty);
        $_SESSION['success'] = "✅ Mouvement modifié.";
    }
    header("Location: ".$_SERVER['PHP_SELF']); exit;
}

/* SUPPRIMER MOUVEMENT */
if (isset($_POST['delete_movement']) && $location_set) {
    $mid = (int)$_POST['movement_id'];
    $st  = $pdo->prepare("SELECT * FROM stock_movements WHERE id=?");
    $st->execute([$mid]); $mvt = $st->fetch(PDO::FETCH_ASSOC);
    if ($mvt) {
        $pdo->prepare("DELETE FROM stock_movements WHERE id=?")->execute([$mid]);
        logAction($pdo,$user_id,'STOCK_MOVEMENT_DELETE',"Mouvement supprimé: type={$mvt['type']} qty={$mvt['quantity']} ref={$mvt['reference']}",$mvt['product_id']);
        $_SESSION['success'] = "✅ Mouvement supprimé.";
    }
    header("Location: ".$_SERVER['PHP_SELF']); exit;
}

/* ──────────────────────────────────────────────
   DONNÉES PAR ONGLET
──────────────────────────────────────────────── */

/* Historique mouvements (onglet stock) */
$recent_movements = [];
if ($location_set && $view === 'stock') {
    $st = $pdo->prepare("SELECT sm.*,p.name product_name FROM stock_movements sm
        JOIN products p ON sm.product_id=p.id
        WHERE sm.company_id=? AND sm.city_id=?
        ORDER BY sm.movement_date DESC, sm.id DESC LIMIT 50");
    $st->execute([$company_id,$city_id]);
    $recent_movements = $st->fetchAll(PDO::FETCH_ASSOC);
}

/* Tableau stock actuel */
$stock_table = [];
if ($location_set && $view === 'stock') {
    $st = $pdo->prepare("
        SELECT p.id, p.name, p.price, p.alert_quantity,
            COALESCE(SUM(CASE WHEN sm.type='initial'    THEN sm.quantity END),0) init_stock,
            COALESCE(SUM(CASE WHEN sm.type='entry'      AND sm.reference NOT LIKE 'ANNULATION-VENTE-%' THEN sm.quantity END),0) entrees,
            COALESCE(SUM(CASE WHEN sm.type='entry'      AND sm.reference LIKE 'ANNULATION-VENTE-%' THEN sm.quantity END),0) ventes_ann,
            COALESCE(SUM(CASE WHEN sm.type='exit'       THEN sm.quantity END),0) sorties,
            COALESCE(SUM(CASE WHEN sm.type='adjustment' THEN sm.quantity END),0) ajust,
            COALESCE(SUM(CASE WHEN sm.type='initial'    THEN sm.quantity END),0)
           +COALESCE(SUM(CASE WHEN sm.type='entry'      THEN sm.quantity END),0)
           -COALESCE(SUM(CASE WHEN sm.type='exit'       THEN sm.quantity END),0)
           +COALESCE(SUM(CASE WHEN sm.type='adjustment' THEN sm.quantity END),0) AS stock_actuel
        FROM products p
        LEFT JOIN stock_movements sm ON sm.product_id=p.id AND sm.company_id=? AND sm.city_id=?
        WHERE p.company_id=?
        GROUP BY p.id ORDER BY p.name");
    $st->execute([$company_id,$city_id,$company_id]);
    $stock_table = $st->fetchAll(PDO::FETCH_ASSOC);
}

/* Historique ventes annulées */
$ventes_annulees = [];
if ($location_set && $view === 'annulations') {
    $st = $pdo->prepare("
        SELECT sm.*, p.name product_name, p.price product_price,
               sm.movement_date annul_date,
               REPLACE(sm.reference,'ANNULATION-VENTE-','') invoice_id
        FROM stock_movements sm
        JOIN products p ON p.id=sm.product_id
        WHERE sm.company_id=? AND sm.city_id=?
          AND sm.type='entry' AND sm.reference LIKE 'ANNULATION-VENTE-%'
        ORDER BY sm.movement_date DESC");
    $st->execute([$company_id,$city_id]);
    $ventes_annulees = $st->fetchAll(PDO::FETCH_ASSOC);
    logAction($pdo,$user_id,'VIEW_ANNULATIONS',"Consultation historique ventes annulées");
}

/* ══ LOGS IMMUABLES — Onglet lecture seule ══ */
$logs = [];
$log_filter_date  = $_GET['log_date']  ?? '';
$log_filter_type  = $_GET['log_type']  ?? '';
$log_filter_user  = $_GET['log_user']  ?? '';
$log_page         = max(1, (int)($_GET['lp'] ?? 1));
$log_per_page     = 50;
$log_offset       = ($log_page - 1) * $log_per_page;
$log_total        = 0;

if ($location_set && $view === 'logs') {
    logAction($pdo,$user_id,'VIEW_LOGS',"Consultation du journal de logs — page $log_page");

    $where = ["1=1"];
    $params = [];

    if ($log_filter_date) { $where[] = "DATE(cl.created_at)=?"; $params[] = $log_filter_date; }
    if ($log_filter_type) { $where[] = "cl.action_type LIKE ?"; $params[] = "%$log_filter_type%"; }
    if ($log_filter_user) { $where[] = "(u.username LIKE ? OR cl.ip_address LIKE ?)"; $params[] = "%$log_filter_user%"; $params[] = "%$log_filter_user%"; }

    $wc = implode(' AND ', $where);

    $cnt = $pdo->prepare("SELECT COUNT(*) FROM cash_log cl LEFT JOIN users u ON u.id=cl.user_id WHERE $wc");
    $cnt->execute($params);
    $log_total = (int)$cnt->fetchColumn();

    $st = $pdo->prepare("
        SELECT cl.*, u.username user_name
        FROM cash_log cl
        LEFT JOIN users u ON u.id=cl.user_id
        WHERE $wc
        ORDER BY cl.created_at DESC
        LIMIT $log_per_page OFFSET $log_offset");
    $st->execute($params);
    $logs = $st->fetchAll(PDO::FETCH_ASSOC);

    /* Types uniques pour le filtre */
    $log_types = $pdo->query("SELECT DISTINCT action_type FROM cash_log ORDER BY action_type")
                     ->fetchAll(PDO::FETCH_COLUMN);
}

/* KPI summary */
$kpi = ['products'=>0,'low_stock'=>0,'total_stock'=>0,'moves_today'=>0];
if ($location_set) {
    $kpi['products'] = count($products);
    foreach($products as $p) {
        $s = getStock($pdo,$p['id'],$company_id,$city_id);
        $kpi['total_stock'] += $s;
        if ($s <= $p['alert_quantity']) $kpi['low_stock']++;
    }
    $st = $pdo->prepare("SELECT COUNT(*) FROM stock_movements WHERE company_id=? AND city_id=? AND DATE(movement_date)=CURDATE()");
    $st->execute([$company_id,$city_id]);
    $kpi['moves_today'] = (int)$st->fetchColumn();
}

$company_name = '';
if ($company_id) {
    foreach($companies as $c) if ($c['id']==$company_id) $company_name = $c['name'];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Gestion Stock  — ESPERANCE H2O</title>
<meta name="theme-color" content="#10b981">
<link rel="manifest" href="/stock/stock_manifest.json">
<link rel="icon" href="/stock/stock-app-icon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/stock/stock-app-icon-192.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=Source+Serif+4:wght@700;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
@font-face {
    font-family:'C059';
    src:local('C059-Bold'),local('C059 Bold'),local('Century Schoolbook');
    font-weight:700 900; font-style:normal;
}
:root {
    --bg:#0f1726; --surf:#162033; --card:#1b263b; --card2:#22324a;
    --bord:rgba(148,163,184,0.18);
    --neon:#00a86b; --neon2:#00c87a;
    --red:#e53935; --orange:#f57c00;
    --blue:#1976d2; --gold:#f9a825;
    --purple:#a855f7; --teal:#06b6d4;
    --text:#e8eef8; --text2:#bfd0e4; --muted:#8ea3bd;
    --glow:0 8px 24px rgba(0,168,107,0.18);
    --glow-r:0 8px 24px rgba(229,57,53,0.18);
    --glow-gold:0 8px 24px rgba(249,168,37,0.18);
    --glow-blue:0 0 26px rgba(61,140,255,0.4);
    --fh:'C059','Source Serif 4','Playfair Display','Book Antiqua',Georgia,serif;
    --fb:'Inter','Segoe UI',system-ui,sans-serif;
}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
html{scroll-behavior:smooth;}
body{font-family:var(--fb);font-size:15px;line-height:1.7;background:var(--bg);color:var(--text);min-height:100vh;overflow-x:hidden;}
body::before{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;
    background:
        radial-gradient(ellipse 65% 42% at 3% 8%,  rgba(50,190,143,0.08) 0%,transparent 62%),
        radial-gradient(ellipse 52% 36% at 97% 88%, rgba(61,140,255,0.07)  0%,transparent 62%),
        radial-gradient(ellipse 40% 30% at 50% 50%, rgba(255,145,64,0.04)  0%,transparent 70%);}
body::after{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;
    background-image:linear-gradient(rgba(50,190,143,0.022) 1px,transparent 1px),linear-gradient(90deg,rgba(50,190,143,0.022) 1px,transparent 1px);
    background-size:46px 46px;}
.wrap{position:relative;z-index:1;max-width:1640px;margin:0 auto;padding:16px 16px 48px;}

/* ═══ TOPBAR ═══ */
.topbar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;
    background:rgba(22,32,51,0.96);border:1px solid var(--bord);border-radius:18px;
    padding:18px 28px;margin-bottom:16px;backdrop-filter:blur(24px);}
.brand{display:flex;align-items:center;gap:16px;flex-shrink:0;}
.brand-ico{width:50px;height:50px;background:linear-gradient(135deg,var(--orange),var(--teal));
    border-radius:14px;display:flex;align-items:center;justify-content:center;
    font-size:24px;color:#fff;animation:breathe 3.2s ease-in-out infinite;flex-shrink:0;}
@keyframes breathe{0%,100%{box-shadow:0 0 14px rgba(255,145,64,0.4);}50%{box-shadow:0 0 38px rgba(255,145,64,0.85);}}
.brand-txt h1{font-family:var(--fh);font-size:22px;font-weight:900;color:var(--text);letter-spacing:0.5px;line-height:1.2;}
.brand-txt p{font-size:11px;font-weight:700;color:var(--orange);letter-spacing:2.8px;text-transform:uppercase;margin-top:3px;}
.user-badge{display:flex;align-items:center;gap:10px;
    background:linear-gradient(135deg,var(--orange),var(--teal));
    color:var(--bg);padding:11px 22px;border-radius:32px;
    font-family:var(--fh);font-size:14px;font-weight:900;flex-shrink:0;}

/* ═══ NAV ═══ */
.nav-bar{display:flex;align-items:center;flex-wrap:wrap;gap:8px;
    background:rgba(27,38,59,0.9);border:1px solid var(--bord);border-radius:16px;
    padding:14px 22px;margin-bottom:18px;backdrop-filter:blur(20px);}
.nb{display:flex;align-items:center;gap:8px;padding:10px 18px;border-radius:12px;
    border:1.5px solid var(--bord);background:rgba(255,145,64,0.07);color:var(--text2);
    font-family:var(--fh);font-size:13px;font-weight:900;text-decoration:none;white-space:nowrap;
    letter-spacing:0.4px;transition:all 0.28s cubic-bezier(0.23,1,0.32,1);cursor:pointer;}
.nb:hover,.nb.active{background:var(--orange);color:var(--bg);border-color:var(--orange);
    box-shadow:0 0 22px rgba(255,145,64,0.45);transform:translateY(-2px);}
.nb.green{border-color:rgba(50,190,143,0.3);color:var(--neon);background:rgba(50,190,143,0.07);}
.nb.green:hover,.nb.green.active{background:var(--neon);color:var(--bg);border-color:var(--neon);box-shadow:var(--glow);}
.nb.red-b{border-color:rgba(255,53,83,0.3);color:var(--red);background:rgba(255,53,83,0.07);}
.nb.red-b:hover{background:var(--red);color:#fff;border-color:var(--red);box-shadow:var(--glow-r);}
.nb.blue-b{border-color:rgba(61,140,255,0.3);color:var(--blue);background:rgba(61,140,255,0.07);}
.nb.blue-b:hover,.nb.blue-b.active{background:var(--blue);color:#fff;border-color:var(--blue);box-shadow:var(--glow-blue);}
/* Logs — badge spécial "lecture seule" */
.nb.logs-b{border-color:rgba(168,85,247,0.3);color:var(--purple);background:rgba(168,85,247,0.07);}
.nb.logs-b:hover,.nb.logs-b.active{background:var(--purple);color:#fff;border-color:var(--purple);box-shadow:0 0 22px rgba(168,85,247,0.45);}

/* ═══ ALERTES ═══ */
.alert{display:flex;align-items:flex-start;gap:14px;flex-wrap:wrap;
    border-radius:14px;padding:16px 22px;margin-bottom:18px;animation:fadeUp .4s ease;}
@keyframes fadeUp{from{opacity:0;transform:translateY(-12px)}to{opacity:1;transform:translateY(0)}}
.alert.success{background:rgba(50,190,143,0.09);border:1px solid rgba(50,190,143,0.28);}
.alert.error  {background:rgba(255,53,83,0.09); border:1px solid rgba(255,53,83,0.28);}
.alert.warning{background:rgba(255,208,96,0.09);border:1px solid rgba(255,208,96,0.28);}
.alert.info   {background:rgba(61,140,255,0.09);border:1px solid rgba(61,140,255,0.28);}
.alert i{font-size:20px;flex-shrink:0;margin-top:2px;}
.alert.success i,.alert.success span{color:var(--neon);}
.alert.error   i,.alert.error   span{color:var(--red);}
.alert.warning i,.alert.warning span{color:var(--gold);}
.alert.info    i,.alert.info    span{color:var(--blue);}
.alert span{font-family:var(--fb);font-size:14px;font-weight:700;line-height:1.65;flex:1;}

/* ═══ CONFIRM BOX ═══ */
.confirm-box{background:rgba(255,208,96,0.08);border:1px solid rgba(255,208,96,0.28);
    border-radius:14px;padding:20px 24px;margin-bottom:18px;}
.confirm-box h3{font-family:var(--fh);font-size:16px;font-weight:900;color:var(--gold);
    display:flex;align-items:center;gap:10px;margin-bottom:10px;}
.confirm-box p{font-family:var(--fb);font-size:14px;color:var(--text2);line-height:1.65;}
.confirm-btns{display:flex;gap:12px;margin-top:16px;flex-wrap:wrap;}

/* ═══ KPI STRIP ═══ */
.kpi-strip{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px;}
.ks{background:var(--card);border:1px solid var(--bord);border-radius:16px;padding:20px 18px;
    display:flex;align-items:center;gap:14px;transition:all 0.3s;animation:fadeUp .5s ease backwards;}
.ks:nth-child(1){animation-delay:.05s}.ks:nth-child(2){animation-delay:.1s}
.ks:nth-child(3){animation-delay:.15s}.ks:nth-child(4){animation-delay:.2s}
.ks:hover{transform:translateY(-4px);box-shadow:0 14px 32px rgba(0,0,0,.38),var(--glow);}
.ks-ico{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;}
.ks-val{font-family:var(--fh);font-size:24px;font-weight:900;color:var(--text);line-height:1;}
.ks-lbl{font-family:var(--fb);font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-top:5px;}

/* ═══ PANEL ═══ */
.panel{background:var(--card);border:1px solid var(--bord);border-radius:18px;
    overflow:hidden;margin-bottom:20px;transition:border-color 0.3s;
    animation:fadeUp .55s ease .08s backwards;}
.panel:hover{border-color:rgba(50,190,143,0.24);}
.ph{display:flex;align-items:center;justify-content:space-between;gap:12px;
    padding:18px 24px;border-bottom:1px solid rgba(255,255,255,0.05);
    background:rgba(0,0,0,0.18);flex-wrap:wrap;}
.ph-title{font-family:var(--fh);font-size:15px;font-weight:900;color:var(--text);
    display:flex;align-items:center;gap:10px;letter-spacing:0.4px;line-height:1.4;}
.dot{width:9px;height:9px;border-radius:50%;background:var(--neon);box-shadow:0 0 9px var(--neon);
    flex-shrink:0;animation:pdot 2.2s infinite;}
@keyframes pdot{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(.75)}}
.dot.r{background:var(--red);box-shadow:0 0 9px var(--red);}
.dot.g{background:var(--gold);box-shadow:0 0 9px var(--gold);}
.dot.o{background:var(--orange);box-shadow:0 0 9px var(--orange);}
.dot.b{background:var(--blue);box-shadow:0 0 9px var(--blue);}
.dot.p{background:var(--purple);box-shadow:0 0 9px var(--purple);}
.dot.t{background:var(--teal);box-shadow:0 0 9px var(--teal);}
.pbadge{font-family:var(--fb);font-size:11px;font-weight:800;padding:5px 14px;border-radius:20px;
    white-space:nowrap;letter-spacing:0.5px;background:rgba(50,190,143,0.12);color:var(--neon);}
.pbadge.r{background:rgba(255,53,83,0.12);color:var(--red);}
.pbadge.g{background:rgba(255,208,96,0.12);color:var(--gold);}
.pbadge.o{background:rgba(255,145,64,0.12);color:var(--orange);}
.pbadge.b{background:rgba(61,140,255,0.12);color:var(--blue);}
.pbadge.p{background:rgba(168,85,247,0.12);color:var(--purple);}
.pb{padding:20px 22px;}

/* ═══ FORMULAIRES ═══ */
.f-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:18px;}
.f-grid.full>*{grid-column:1/-1;}
.f-group{display:flex;flex-direction:column;gap:7px;}
.f-label{font-family:var(--fh);font-size:12px;font-weight:900;color:var(--muted);
    text-transform:uppercase;letter-spacing:1.2px;}
.f-input,.f-select,.f-textarea{
    padding:12px 16px;background:rgba(0,0,0,.3);border:1.5px solid var(--bord);
    border-radius:12px;color:var(--text);font-family:var(--fb);font-size:14px;font-weight:600;
    transition:all .3s;appearance:none;-webkit-appearance:none;width:100%;}
.f-textarea{resize:vertical;min-height:80px;}
.f-input::placeholder,.f-textarea::placeholder{color:var(--muted);}
.f-input:focus,.f-select:focus,.f-textarea:focus{outline:none;border-color:var(--neon);box-shadow:var(--glow);background:rgba(50,190,143,.05);}
.f-select option{background:#1b263b;color:var(--text);}

.radio-wrap{display:flex;gap:12px;flex-wrap:wrap;}
.radio-opt{display:flex;align-items:center;gap:10px;padding:12px 18px;border-radius:12px;
    border:1.5px solid var(--bord);cursor:pointer;transition:all .3s;flex:1;min-width:160px;}
.radio-opt.positive{border-color:rgba(50,190,143,.3);background:rgba(50,190,143,.07);}
.radio-opt.negative{border-color:rgba(255,53,83,.3);background:rgba(255,53,83,.07);}
.radio-opt input[type=radio]{accent-color:var(--neon);width:16px;height:16px;}
.radio-opt.positive label{color:var(--neon);font-family:var(--fh);font-weight:900;cursor:pointer;}
.radio-opt.negative label{color:var(--red);font-family:var(--fh);font-weight:900;cursor:pointer;}

/* ═══ BOUTONS ═══ */
.btn{display:inline-flex;align-items:center;gap:8px;padding:12px 22px;border-radius:12px;
    border:none;cursor:pointer;font-family:var(--fh);font-size:13px;font-weight:900;
    letter-spacing:.4px;transition:all .28s;text-decoration:none;white-space:nowrap;}
.btn-neon  {background:rgba(50,190,143,.12);border:1.5px solid rgba(50,190,143,.3);color:var(--neon);}
.btn-neon:hover{background:var(--neon);color:var(--bg);box-shadow:var(--glow);}
.btn-orange{background:rgba(255,145,64,.12);border:1.5px solid rgba(255,145,64,.3);color:var(--orange);}
.btn-orange:hover{background:var(--orange);color:var(--bg);box-shadow:0 0 22px rgba(255,145,64,.45);}
.btn-red   {background:rgba(255,53,83,.12);border:1.5px solid rgba(255,53,83,.3);color:var(--red);}
.btn-red:hover{background:var(--red);color:#fff;box-shadow:var(--glow-r);}
.btn-blue  {background:rgba(61,140,255,.12);border:1.5px solid rgba(61,140,255,.3);color:var(--blue);}
.btn-blue:hover{background:var(--blue);color:#fff;box-shadow:var(--glow-blue);}
.btn-purple{background:rgba(168,85,247,.12);border:1.5px solid rgba(168,85,247,.3);color:var(--purple);}
.btn-purple:hover{background:var(--purple);color:#fff;}
.btn-gold  {background:rgba(255,208,96,.12);border:1.5px solid rgba(255,208,96,.3);color:var(--gold);}
.btn-gold:hover{background:var(--gold);color:var(--bg);box-shadow:var(--glow-gold);}
.btn-sm{padding:8px 14px;font-size:12px;border-radius:9px;}
.btn-xs{padding:5px 10px;font-size:11px;border-radius:7px;}

/* ═══ TABLE ═══ */
.tbl-wrap{overflow-x:auto;border-radius:12px;}
.tbl-wrap::-webkit-scrollbar{height:6px;}
.tbl-wrap::-webkit-scrollbar-thumb{background:var(--neon);border-radius:10px;}
.tbl{width:100%;border-collapse:collapse;min-width:700px;}
.tbl th{font-family:var(--fh);font-size:12px;font-weight:900;color:var(--muted);
    text-transform:uppercase;letter-spacing:1.2px;padding:12px 14px;
    border-bottom:1px solid rgba(255,255,255,.06);text-align:left;background:rgba(0,0,0,.18);}
.tbl td{font-family:var(--fb);font-size:13px;font-weight:500;color:var(--text2);
    padding:13px 14px;border-bottom:1px solid rgba(255,255,255,.04);line-height:1.55;}
.tbl tr:last-child td{border-bottom:none;}
.tbl tbody tr{transition:all .25s;}
.tbl tbody tr:hover{background:rgba(50,190,143,.04);}
.tbl td strong{font-family:var(--fh);font-weight:900;color:var(--text);}

/* ═══ BADGES ═══ */
.bdg{font-family:var(--fb);font-size:10px;font-weight:800;padding:4px 11px;border-radius:20px;letter-spacing:.5px;display:inline-block;white-space:nowrap;}
.bdg-g   {background:rgba(50,190,143,.14);color:var(--neon);}
.bdg-r   {background:rgba(255,53,83,.14); color:var(--red);}
.bdg-gold{background:rgba(255,208,96,.14);color:var(--gold);}
.bdg-o   {background:rgba(255,145,64,.14);color:var(--orange);}
.bdg-b   {background:rgba(61,140,255,.14);color:var(--blue);}
.bdg-p   {background:rgba(168,85,247,.14);color:var(--purple);}
.bdg-t   {background:rgba(6,182,212,.14); color:var(--teal);}
.bdg-ann {background:rgba(255,53,83,.2);  color:var(--red);border:1px solid rgba(255,53,83,.4);font-size:11px;padding:5px 12px;}

/* ═══ LOGS ═══ */
.log-readonly-banner{
    display:flex;align-items:center;gap:14px;
    background:rgba(168,85,247,.08);border:1px solid rgba(168,85,247,.25);
    border-radius:14px;padding:16px 22px;margin-bottom:20px;}
.log-readonly-banner i{color:var(--purple);font-size:22px;flex-shrink:0;}
.log-readonly-banner strong{font-family:var(--fh);font-size:15px;font-weight:900;color:var(--purple);display:block;margin-bottom:4px;}
.log-readonly-banner p{font-family:var(--fb);font-size:13px;color:var(--text2);line-height:1.65;margin:0;}

.log-filters{display:flex;gap:10px;align-items:center;flex-wrap:wrap;
    padding:16px 22px;border-bottom:1px solid rgba(255,255,255,.05);background:rgba(0,0,0,.12);}

.log-row{display:grid;grid-template-columns:auto auto 1fr;gap:12px;
    padding:14px 22px;border-bottom:1px solid rgba(255,255,255,.04);
    transition:all .25s;border-radius:4px;align-items:start;}
.log-row:hover{background:rgba(50,190,143,.03);}
.log-time{font-family:var(--fh);font-size:12px;font-weight:900;color:var(--muted);white-space:nowrap;}
.log-content{flex:1;}
.log-desc{font-family:var(--fb);font-size:13px;color:var(--text2);line-height:1.55;margin-bottom:6px;}
.log-meta{display:flex;gap:8px;flex-wrap:wrap;align-items:center;}
.log-meta-item{font-family:var(--fb);font-size:11px;color:var(--muted);
    background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.06);
    border-radius:6px;padding:3px 9px;display:flex;align-items:center;gap:4px;}

.pagination{display:flex;gap:8px;align-items:center;justify-content:center;padding:16px;flex-wrap:wrap;}
.page-btn{font-family:var(--fh);font-size:13px;font-weight:900;padding:9px 16px;border-radius:10px;
    border:1.5px solid var(--bord);background:rgba(50,190,143,.07);color:var(--muted);
    text-decoration:none;transition:all .25s;}
.page-btn:hover,.page-btn.active{background:var(--neon);color:var(--bg);border-color:var(--neon);}

/* ═══ MODAL ═══ */
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:1000;
    align-items:center;justify-content:center;backdrop-filter:blur(4px);}
.modal.show{display:flex;}
.mbox{background:var(--card);border:1px solid var(--bord);border-radius:20px;
    padding:30px;max-width:560px;width:92%;max-height:88vh;overflow-y:auto;
    animation:mzoom .25s ease;}
@keyframes mzoom{from{opacity:0;transform:scale(.9)}to{opacity:1;transform:scale(1)}}
.mbox h2{font-family:var(--fh);font-size:20px;font-weight:900;color:var(--text);
    margin-bottom:22px;display:flex;align-items:center;gap:12px;}
.mbox-close{position:absolute;top:18px;right:20px;background:none;border:none;
    color:var(--muted);font-size:22px;cursor:pointer;}
.mbox-close:hover{color:var(--red);}
.mbtns{display:flex;gap:12px;margin-top:22px;flex-wrap:wrap;}
.mbtns>*{flex:1;}

/* ═══ INFO BOX ═══ */
.info-box{background:rgba(61,140,255,.07);border:1px solid rgba(61,140,255,.22);
    border-radius:12px;padding:14px 18px;margin-bottom:18px;}
.info-box strong{font-family:var(--fh);font-size:13px;font-weight:900;color:var(--blue);display:block;margin-bottom:6px;}
.info-box p{font-family:var(--fb);font-size:13px;color:var(--text2);line-height:1.65;margin:0;}

.warn-box{background:rgba(255,208,96,.07);border:1px solid rgba(255,208,96,.22);
    border-radius:12px;padding:14px 18px;margin-bottom:18px;}
.warn-box strong{font-family:var(--fh);font-size:13px;font-weight:900;color:var(--gold);display:block;margin-bottom:6px;}
.warn-box p{font-family:var(--fb);font-size:13px;color:var(--text2);line-height:1.65;margin:0;}

/* ═══ SECTION TITLE ═══ */
.sec-title{display:flex;align-items:center;gap:14px;margin:28px 0 16px;}
.sec-title h3{font-family:var(--fh);font-size:16px;font-weight:900;color:var(--text2);letter-spacing:.5px;}
.sec-title::after{content:'';flex:1;height:1px;background:linear-gradient(90deg,var(--bord),transparent);}
.sec-line{width:4px;height:22px;border-radius:4px;background:linear-gradient(to bottom,var(--orange),var(--teal));flex-shrink:0;}

/* ═══ LOCALISATION ═══ */
.loc-box{background:var(--card);border:1px solid var(--bord);border-radius:18px;padding:40px;text-align:center;}
.loc-box h2{font-family:var(--fh);font-size:22px;font-weight:900;color:var(--text);margin-bottom:8px;}
.loc-box p{font-family:var(--fb);font-size:14px;color:var(--muted);margin-bottom:28px;}

/* ═══ RESPONSIVE ═══ */
@media(max-width:960px){
    .kpi-strip{grid-template-columns:repeat(2,1fr);}
    .f-grid{grid-template-columns:1fr;}
    .log-row{grid-template-columns:1fr;}
    .log-time{margin-bottom:4px;}
}
@media(max-width:640px){
    .wrap{padding:12px 12px 36px;}
    .topbar{padding:14px 16px;}
    .brand-txt h1{font-size:18px;}
    .kpi-strip{grid-template-columns:1fr 1fr;gap:10px;}
    .ks{padding:16px 14px;}
    .ks-val{font-size:20px;}
    .nav-bar{padding:12px 14px;}
    .nb{padding:9px 14px;font-size:12px;}
    .radio-wrap{flex-direction:column;}
    .radio-opt{flex:none;}
    .ph{flex-direction:column;align-items:flex-start;}
    .log-meta{gap:6px;}
}
.stock-install-fab,.stock-network-badge{position:fixed;z-index:20000;border:none;border-radius:999px;padding:12px 16px;font-size:13px;font-weight:800;display:flex;align-items:center;gap:8px;box-shadow:0 18px 38px rgba(0,0,0,.24)}
.stock-install-fab{right:16px;bottom:18px;background:linear-gradient(135deg,#10b981,#0ea5e9);color:#fff;cursor:pointer}
.stock-network-badge{left:50%;transform:translateX(-50%);bottom:18px;background:rgba(255,53,83,.96);color:#fff;display:none}
.stock-network-badge.show{display:flex}
img,canvas,iframe,svg{max-width:100%;height:auto}
body{overflow-x:hidden}
@media(max-width:768px){.nav-bar{overflow-x:auto;white-space:nowrap;-webkit-overflow-scrolling:touch;padding-bottom:6px}.kpi-strip,.split,.split-2,.grid-2{grid-template-columns:1fr !important}.tbl-wrap,table{display:block;overflow-x:auto}.user-badge{width:100%;justify-content:center}}
</style>
<link rel="stylesheet" href="/stock/stock_mobile_overrides.css">
</head>
<body>
<button type="button" class="stock-install-fab" id="stockInstallBtn"><i class="fas fa-download"></i> Installer Stock</button>
<div class="stock-network-badge" id="stockNetworkBadge"><i class="fas fa-wifi"></i> Hors ligne</div>
<div class="wrap">

<!-- ══════ TOPBAR ══════ -->
<div class="topbar">
    <div class="brand">
        <div class="brand-ico"><i class="fas fa-warehouse"></i></div>
        <div class="brand-txt">
            <h1>Gestion des Stocks</h1>
            <p>ESPERANCE H2O &nbsp;·&nbsp; <?= $company_name ? htmlspecialchars($company_name) : 'Aucun site sélectionné' ?></p>
        </div>
    </div>
    <div class="user-badge">
        <i class="fas fa-user-shield"></i>
        <?= htmlspecialchars($user_name) ?>
    </div>
</div>

<!-- ══════ NAVIGATION ══════ -->
<div class="nav-bar">
    <a href="?view=stock"       class="nb green <?= $view==='stock'?'active':'' ?>"><i class="fas fa-boxes"></i> Stock</a>
    <a href="?view=initial"     class="nb <?= $view==='initial'?'active':'' ?>"><i class="fas fa-database"></i> Stock Initial</a>
    <a href="?view=arrivage"    class="nb <?= $view==='arrivage'?'active':'' ?>"><i class="fas fa-truck-loading"></i> Arrivages</a>
    <a href="?view=ajustement"  class="nb <?= $view==='ajustement'?'active':'' ?>"><i class="fas fa-balance-scale"></i> Ajustements</a>
    <a href="?view=annulations" class="nb red-b <?= $view==='annulations'?'active':'' ?>"><i class="fas fa-ban"></i> Ventes Annulées</a>
    <a href="?view=logs"        class="nb logs-b <?= $view==='logs'?'active':'' ?>"><i class="fas fa-shield-alt"></i> Logs <span style="font-size:10px;opacity:.7">(🔒)</span></a>
    <a href="<?= project_url('stock/stock_tracking.php') ?>"         class="nb blue-b"><i class="fas fa-chart-line"></i> Suivi</a>
    <a href="<?= project_url('finance/caisse_complete_enhanced.php') ?>" class="nb blue-b"><i class="fas fa-cash-register"></i> Caisse</a>
    <a href="<?= project_url('dashboard/index.php') ?>"        class="nb"><i class="fas fa-id-badge"></i> RH</a>
    <a href="<?= project_url('dashboard/index.php') ?>"                   class="nb red-b"><i class="fas fa-home"></i> Accueil</a>
</div>

<!-- ══════ ALERTES SESSION ══════ -->
<?php foreach(['success'=>'success','error'=>'error','info'=>'info'] as $k=>$c): if(isset($_SESSION[$k])): ?>
<div class="alert <?= $c ?>">
    <i class="fas fa-<?= $k==='success'?'check-circle':($k==='error'?'exclamation-circle':'info-circle') ?>"></i>
    <span><?= $_SESSION[$k]; unset($_SESSION[$k]); ?></span>
</div>
<?php endif; endforeach; ?>

<?php if(isset($_SESSION['confirm_msg']) && isset($_SESSION['pending_entry'])): ?>
<div class="confirm-box">
    <h3><i class="fas fa-exclamation-triangle"></i> Confirmation requise</h3>
    <p><?= $_SESSION['confirm_msg'] ?></p>
    <div class="confirm-btns">
        <form method="post" style="display:inline">
            <button type="submit" name="confirm_entry" class="btn btn-orange"><i class="fas fa-check"></i> Oui, enregistrer</button>
        </form>
        <form method="post" style="display:inline">
            <button type="submit" name="cancel_entry" class="btn btn-red"><i class="fas fa-times"></i> Annuler</button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if(!$location_set): ?>
<!-- ══════ SÉLECTION LOCALISATION ══════ -->
<div class="loc-box">
    <h2><i class="fas fa-map-marker-alt" style="color:var(--orange)"></i> &nbsp;Sélectionnez votre localisation</h2>
    <p>Choisissez votre société et votre magasin pour accéder à la gestion de stock</p>
    <form method="get" style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap">
        <select name="company_id" class="f-select" style="max-width:250px" required onchange="this.form.submit()">
            <option value="">— Société —</option>
            <?php foreach($companies as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $company_id==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="city_id" class="f-select" style="max-width:250px" required>
            <option value="">— Magasin —</option>
            <?php foreach($cities as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $city_id==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" name="confirm_location" class="btn btn-orange" style="padding:12px 28px">
            <i class="fas fa-check"></i> Valider
        </button>
    </form>
</div>

<?php else: ?>

<!-- ══════ KPI STRIP ══════ -->
<div class="kpi-strip">
    <div class="ks">
        <div class="ks-ico" style="background:rgba(50,190,143,.14);color:var(--neon)"><i class="fas fa-boxes"></i></div>
        <div><div class="ks-val"><?= $kpi['products'] ?></div><div class="ks-lbl">Produits</div></div>
    </div>
    <div class="ks">
        <div class="ks-ico" style="background:rgba(61,140,255,.14);color:var(--blue)"><i class="fas fa-layer-group"></i></div>
        <div><div class="ks-val" style="color:var(--blue)"><?= number_format($kpi['total_stock']) ?></div><div class="ks-lbl">Unités en stock</div></div>
    </div>
    <div class="ks">
        <div class="ks-ico" style="background:rgba(255,208,96,.14);color:var(--gold)"><i class="fas fa-exclamation-triangle"></i></div>
        <div><div class="ks-val" style="color:var(--gold)"><?= $kpi['low_stock'] ?></div><div class="ks-lbl">Alertes stock bas</div></div>
    </div>
    <div class="ks">
        <div class="ks-ico" style="background:rgba(255,145,64,.14);color:var(--orange)"><i class="fas fa-exchange-alt"></i></div>
        <div><div class="ks-val" style="color:var(--orange)"><?= $kpi['moves_today'] ?></div><div class="ks-lbl">Mouvements today</div></div>
    </div>
</div>

<!-- ════════════════════════════════════════
     ONGLET: STOCK ACTUEL + HISTORIQUE
════════════════════════════════════════ -->
<?php if($view === 'stock'): ?>

<div class="panel">
    <div class="ph">
        <div class="ph-title"><div class="dot"></div> État du stock en temps réel</div>
        <span class="pbadge o"><?= count($stock_table) ?> produits</span>
    </div>
    <div class="pb">
        <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr>
                <th>Produit</th><th>Initial</th><th>Entrées</th>
                <th style="color:var(--red)">❌ Ventes Annulées</th>
                <th>Ajustements</th><th>Sorties</th>
                <th>Stock actuel</th><th>État</th>
            </tr></thead>
            <tbody>
                <?php foreach($stock_table as $r):
                    $s=(int)$r['stock_actuel']; $ann=(int)$r['ventes_ann']; $adj=(int)$r['ajust'];
                    if($s<=0)                            {$et='Rupture';$ec='bdg-r';}
                    elseif($s<=$r['alert_quantity'])     {$et='Alerte'; $ec='bdg-gold';}
                    else                                 {$et='OK';     $ec='bdg-g';}
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($r['name']) ?></strong></td>
                    <td><?= (int)$r['init_stock'] ?></td>
                    <td><span class="bdg bdg-g">+<?= (int)$r['entrees'] ?></span></td>
                    <td>
                        <?php if($ann>0): ?>
                        <span class="bdg bdg-ann"><i class="fas fa-undo"></i> +<?= $ann ?> Annulé</span>
                        <?php else: ?><span style="color:var(--muted)">—</span><?php endif; ?>
                    </td>
                    <td>
                        <?php if($adj>0):?><span class="bdg bdg-g">+<?=$adj?></span>
                        <?php elseif($adj<0):?><span class="bdg bdg-gold"><?=$adj?></span>
                        <?php else:?><span style="color:var(--muted)">0</span><?php endif;?>
                    </td>
                    <td><span class="bdg bdg-r">-<?= (int)$r['sorties'] ?></span></td>
                    <td><strong style="font-family:var(--fh);font-size:17px;color:<?= $s<=0?'var(--red)':($s<=$r['alert_quantity']?'var(--gold)':'var(--text)') ?>"><?= $s ?></strong></td>
                    <td><span class="bdg <?= $ec ?>"><?= $et ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<div class="panel">
    <div class="ph">
        <div class="ph-title"><div class="dot o"></div> Historique des mouvements (50 derniers)</div>
        <span class="pbadge o"><?= count($recent_movements) ?> entrées</span>
    </div>
    <div class="pb">
        <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr>
                <th>Date</th><th>Produit</th><th>Type</th><th>Quantité</th><th>Référence</th><th>Actions</th>
            </tr></thead>
            <tbody>
                <?php foreach($recent_movements as $mv):
                    $can = in_array($mv['type'],['entry','initial','adjustment']);
                    // Détecter annulation vente
                    $is_ann = ($mv['type']==='entry' && str_starts_with($mv['reference'],'ANNULATION-VENTE-'));
                    if($is_ann){
                        $tb = '<span class="bdg bdg-ann"><i class="fas fa-undo"></i> ANNULATION VENTE</span>';
                        $qb = '<span class="bdg bdg-ann">+'.abs((int)$mv['quantity']).'</span>';
                        $can = false;
                    } elseif($mv['type']==='initial'){
                        $tb='<span class="bdg bdg-b"><i class="fas fa-database"></i> INITIAL</span>';
                        $qb='<span class="bdg bdg-b">'.((int)$mv['quantity']).'</span>';
                    } elseif($mv['type']==='entry'){
                        $tb='<span class="bdg bdg-g"><i class="fas fa-truck-loading"></i> ARRIVAGE</span>';
                        $qb='<span class="bdg bdg-g">+'.((int)$mv['quantity']).'</span>';
                    } elseif($mv['type']==='adjustment'){
                        if((int)$mv['quantity']>0){$tb='<span class="bdg bdg-g"><i class="fas fa-plus-circle"></i> AJUST +</span>';$qb='<span class="bdg bdg-g">+'.(int)$mv['quantity'].'</span>';}
                        else{$tb='<span class="bdg bdg-gold"><i class="fas fa-minus-circle"></i> AJUST −</span>';$qb='<span class="bdg bdg-gold">'.(int)$mv['quantity'].'</span>';}
                    } else {
                        $tb='<span class="bdg bdg-r"><i class="fas fa-shopping-cart"></i> VENTE</span>';
                        $qb='<span class="bdg bdg-r">-'.(int)$mv['quantity'].'</span>';
                        $can=false;
                    }
                ?>
                <tr <?= $is_ann?'style="background:rgba(255,53,83,.03)"':'' ?>>
                    <td style="color:var(--muted)"><?= date('d/m H:i',strtotime($mv['movement_date'])) ?></td>
                    <td><strong><?= htmlspecialchars($mv['product_name']) ?></strong></td>
                    <td><?= $tb ?></td>
                    <td><?= $qb ?></td>
                    <td style="font-size:12px;color:var(--muted)"><?= htmlspecialchars(mb_substr($mv['reference'],0,40)) ?></td>
                    <td>
                        <?php if($can): ?>
                        <div style="display:flex;gap:6px;flex-wrap:wrap">
                            <button onclick='openEditModal(<?= json_encode($mv) ?>)' class="btn btn-gold btn-xs"><i class="fas fa-edit"></i></button>
                            <form method="post" style="display:inline" onsubmit="return confirm('Supprimer ce mouvement ?')">
                                <input type="hidden" name="movement_id" value="<?= $mv['id'] ?>">
                                <button type="submit" name="delete_movement" class="btn btn-red btn-xs"><i class="fas fa-trash"></i></button>
                            </form>
                        </div>
                        <?php else: ?>
                        <span style="color:var(--muted);font-size:11px"><i class="fas fa-lock"></i> Auto</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ════════════════════════════════════════
     ONGLET: STOCK INITIAL
════════════════════════════════════════ -->
<?php if($view === 'initial'): ?>
<div class="panel">
    <div class="ph">
        <div class="ph-title"><div class="dot b"></div> Stocks Initiaux Définis</div>
    </div>
    <div class="pb">
        <div class="info-box">
            <strong><i class="fas fa-info-circle"></i> Stock Initial de Saison</strong>
            <p>Le stock initial se définit UNE SEULE FOIS au début de la saison. Il sert de base de calcul pour tout l'historique. Modifiable uniquement en cas d'erreur de saisie.</p>
        </div>
        <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr><th>Produit</th><th>Stock Initial</th><th>Stock Actuel</th><th>Écart</th><th>Date</th><th>Actions</th></tr></thead>
            <tbody>
                <?php
                $st = $pdo->prepare("SELECT sm.*,p.name product_name FROM stock_movements sm
                    JOIN products p ON sm.product_id=p.id
                    WHERE sm.company_id=? AND sm.city_id=? AND sm.type='initial' ORDER BY p.name");
                $st->execute([$company_id,$city_id]);
                $inits = $st->fetchAll(PDO::FETCH_ASSOC);
                if(empty($inits)): ?>
                <tr><td colspan="6" style="text-align:center;padding:32px;color:var(--muted)">
                    <i class="fas fa-inbox" style="font-size:40px;display:block;margin-bottom:12px;opacity:.2"></i>
                    Aucun stock initial défini
                </td></tr>
                <?php else: foreach($inits as $in):
                    $cur = getStock($pdo,$in['product_id'],$company_id,$city_id);
                    $diff = $cur - $in['quantity'];
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($in['product_name']) ?></strong></td>
                    <td><span class="bdg bdg-b"><?= $in['quantity'] ?> u</span></td>
                    <td><strong style="font-family:var(--fh)"><?= $cur ?></strong></td>
                    <td>
                        <?php if($diff>0):?><span class="bdg bdg-g">+<?=$diff?></span>
                        <?php elseif($diff<0):?><span class="bdg bdg-r"><?=$diff?></span>
                        <?php else:?><span class="bdg" style="background:rgba(255,255,255,.05);color:var(--muted)">0</span><?php endif;?>
                    </td>
                    <td style="color:var(--muted)"><?= date('d/m/Y',strtotime($in['movement_date'])) ?></td>
                    <td>
                        <div style="display:flex;gap:6px;flex-wrap:wrap">
                            <button onclick="prefillInitial(<?= $in['product_id'] ?>,'<?= htmlspecialchars($in['product_name'],ENT_QUOTES) ?>',<?= $in['quantity'] ?>)"
                                    class="btn btn-gold btn-sm"><i class="fas fa-edit"></i> Modifier</button>
                            <form method="post" style="display:inline" onsubmit="return confirm('Supprimer le stock initial de <?= htmlspecialchars($in['product_name'],ENT_QUOTES) ?> ?')">
                                <input type="hidden" name="product_id_delete" value="<?= $in['product_id'] ?>">
                                <button type="submit" name="delete_initial_stock" class="btn btn-red btn-sm"><i class="fas fa-trash"></i></button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<div class="panel">
    <div class="ph"><div class="ph-title"><div class="dot b"></div> Définir / Modifier un Stock Initial</div></div>
    <div class="pb">
        <form method="post" id="form-initial" onsubmit="return confirmInitial()">
            <div class="f-grid">
                <div class="f-group">
                    <label class="f-label">Produit *</label>
                    <select name="product_id_initial" id="sel-initial" class="f-select" required onchange="onInitialChange()">
                        <option value="">— Sélectionner —</option>
                        <?php foreach($products as $p):
                            $cur = getStock($pdo,$p['id'],$company_id,$city_id);
                            $ini = getInitialDefined($pdo,$p['id'],$company_id,$city_id);
                        ?>
                        <option value="<?= $p['id'] ?>" data-cur="<?= $cur ?>" data-ini="<?= $ini ?>" data-name="<?= htmlspecialchars($p['name'],ENT_QUOTES) ?>">
                            <?= htmlspecialchars($p['name']) ?><?php if($ini>0) echo " (Init:$ini / Actuel:$cur)"; else echo " (Actuel:$cur)"; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="initial-info" style="font-size:12px;margin-top:5px;color:var(--muted)"></div>
                </div>
                <div class="f-group">
                    <label class="f-label">Quantité *</label>
                    <input type="number" name="quantity_initial" id="qty-initial" class="f-input" min="0" required placeholder="Ex: 100">
                </div>
                <div class="f-group">
                    <label class="f-label">Date</label>
                    <input type="datetime-local" name="date_initial" class="f-input" value="<?= date('Y-m-d\TH:i') ?>">
                </div>
                <div class="f-group">
                    <label class="f-label">Référence</label>
                    <input type="text" name="reference_initial" class="f-input" placeholder="Ex: Stock début saison 2026">
                </div>
            </div>
            <button type="submit" name="set_initial_stock" class="btn btn-blue" id="btn-initial">
                <i class="fas fa-save"></i> <span id="btn-initial-txt">Définir Stock Initial</span>
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ════════════════════════════════════════
     ONGLET: ARRIVAGE
════════════════════════════════════════ -->
<?php if($view === 'arrivage'): ?>
<div class="panel">
    <div class="ph"><div class="ph-title"><div class="dot o"></div> Enregistrer un Arrivage</div></div>
    <div class="pb">
        <form method="post">
            <div class="f-grid">
                <div class="f-group">
                    <label class="f-label">Produit *</label>
                    <select name="product_id" class="f-select" required>
                        <option value="">— Sélectionner —</option>
                        <?php foreach($products as $p): $s=getStock($pdo,$p['id'],$company_id,$city_id); ?>
                        <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (Stock: <?= $s ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="f-group">
                    <label class="f-label">Quantité *</label>
                    <input type="number" name="quantity" class="f-input" min="1" required placeholder="Ex: 50">
                </div>
                <div class="f-group">
                    <label class="f-label">Date & Heure</label>
                    <input type="datetime-local" name="date" class="f-input" value="<?= date('Y-m-d\TH:i') ?>">
                </div>
                <div class="f-group">
                    <label class="f-label">Référence / Note</label>
                    <input type="text" name="reference" class="f-input" placeholder="Ex: Livraison fournisseur X">
                </div>
            </div>
            <button type="submit" name="check_entry" class="btn btn-neon">
                <i class="fas fa-plus-circle"></i> Enregistrer l'arrivage
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ════════════════════════════════════════
     ONGLET: AJUSTEMENT
════════════════════════════════════════ -->
<?php if($view === 'ajustement'): ?>
<div class="panel">
    <div class="ph"><div class="ph-title"><div class="dot p"></div> Ajustement d'Inventaire</div></div>
    <div class="pb">
        <div class="warn-box">
            <strong><i class="fas fa-exclamation-triangle"></i> Ajustement d'Inventaire</strong>
            <p>Utilisez cette fonction pour corriger les écarts entre le stock théorique et réel (casse, vol, produits retrouvés, erreurs de comptage). N'impacte PAS le stock initial.</p>
        </div>
        <form method="post" onsubmit="return confirmAdjustment()">
            <div class="f-grid">
                <div class="f-group">
                    <label class="f-label">Produit *</label>
                    <select name="product_id_adjustment" id="adj-prod" class="f-select" required onchange="onAdjChange()">
                        <option value="">— Sélectionner —</option>
                        <?php foreach($products as $p): $s=getStock($pdo,$p['id'],$company_id,$city_id); ?>
                        <option value="<?= $p['id'] ?>" data-stock="<?= $s ?>" data-name="<?= htmlspecialchars($p['name'],ENT_QUOTES) ?>">
                            <?= htmlspecialchars($p['name']) ?> (Stock: <?= $s ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="adj-info" style="font-size:12px;margin-top:5px;color:var(--muted)"></div>
                </div>
                <div class="f-group">
                    <label class="f-label">Type d'ajustement *</label>
                    <div class="radio-wrap">
                        <div class="radio-opt positive">
                            <input type="radio" name="adjustment_type" id="adj-pos" value="positive" required>
                            <label for="adj-pos"><i class="fas fa-plus-circle"></i> Positif (retrouvés)</label>
                        </div>
                        <div class="radio-opt negative">
                            <input type="radio" name="adjustment_type" id="adj-neg" value="negative">
                            <label for="adj-neg"><i class="fas fa-minus-circle"></i> Négatif (casse/vol)</label>
                        </div>
                    </div>
                </div>
                <div class="f-group">
                    <label class="f-label">Quantité *</label>
                    <input type="number" name="quantity_adjustment" id="adj-qty" class="f-input" min="1" required placeholder="Ex: 5">
                </div>
                <div class="f-group">
                    <label class="f-label">Date & Heure</label>
                    <input type="datetime-local" name="date_adjustment" class="f-input" value="<?= date('Y-m-d\TH:i') ?>">
                </div>
                <div class="f-group" style="grid-column:1/-1">
                    <label class="f-label">Raison *</label>
                    <textarea name="reason_adjustment" class="f-textarea" required placeholder="Ex: Casse lors du transport, inventaire physique, produits retrouvés en réserve…"></textarea>
                </div>
            </div>
            <button type="submit" name="add_adjustment" class="btn btn-purple">
                <i class="fas fa-balance-scale"></i> Enregistrer l'ajustement
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ════════════════════════════════════════
     ONGLET: HISTORIQUE VENTES ANNULÉES ★
════════════════════════════════════════ -->
<?php if($view === 'annulations'): ?>
<div class="panel">
    <div class="ph">
        <div class="ph-title"><div class="dot r"></div> Historique des Ventes Annulées</div>
        <span class="pbadge r"><?= count($ventes_annulees) ?> annulation(s)</span>
    </div>
    <div class="pb">
        <div style="background:rgba(255,53,83,.07);border:1px solid rgba(255,53,83,.22);border-radius:12px;padding:16px 20px;margin-bottom:20px">
            <p style="font-family:var(--fb);font-size:13px;font-weight:700;color:var(--red);line-height:1.7">
                <i class="fas fa-info-circle"></i> &nbsp;Chaque vente annulée (suppression de facture dans la Caisse) crée une entrée <strong>ANNULATION-VENTE-{id}</strong>.
                Le stock est retourné et tracé ici en détail. Ces enregistrements sont <strong>immuables</strong>.
            </p>
        </div>

        <?php if(empty($ventes_annulees)): ?>
        <div style="text-align:center;padding:40px;color:var(--muted)">
            <i class="fas fa-ban" style="font-size:44px;display:block;margin-bottom:14px;opacity:.2"></i>
            <p style="font-family:var(--fb);font-size:14px">Aucune vente annulée enregistrée</p>
        </div>
        <?php else: ?>

        <?php
        /* Grouper par facture annulée */
        $grouped = [];
        foreach($ventes_annulees as $v) {
            $iid = $v['invoice_id'];
            if(!isset($grouped[$iid])) $grouped[$iid] = ['invoice_id'=>$iid,'date'=>$v['annul_date'],'items'=>[],'total_retour'=>0];
            $grouped[$iid]['items'][] = $v;
            $grouped[$iid]['total_retour'] += $v['quantity'] * $v['product_price'];
        }
        ?>

        <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr>
                <th>Date annulation</th><th>Facture</th><th>Produit</th>
                <th>Quantité retournée</th><th>Valeur retour</th><th>Référence</th>
            </tr></thead>
            <tbody>
                <?php foreach($ventes_annulees as $v): ?>
                <tr style="background:rgba(255,53,83,.02)">
                    <td style="color:var(--muted)"><?= date('d/m/Y H:i',strtotime($v['annul_date'])) ?></td>
                    <td><span class="bdg bdg-ann"><i class="fas fa-ban"></i> Facture #<?= $v['invoice_id'] ?></span></td>
                    <td><strong><?= htmlspecialchars($v['product_name']) ?></strong></td>
                    <td>
                        <span class="bdg bdg-ann">+<?= (int)$v['quantity'] ?> unité(s)</span>
                    </td>
                    <td style="font-family:var(--fh);font-weight:900;color:var(--red)">
                        <?= number_format($v['quantity']*$v['product_price'],0,',',' ') ?> FCFA
                    </td>
                    <td style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($v['reference']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <!-- Résumé par facture -->
        <div class="sec-title"><div class="sec-line"></div><h3>Résumé par facture annulée</h3></div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px">
            <?php foreach($grouped as $g): ?>
            <div style="background:var(--card2);border:1px solid rgba(255,53,83,.2);border-radius:14px;padding:18px 20px">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px">
                    <span class="bdg bdg-ann"><i class="fas fa-ban"></i> Facture #<?= $g['invoice_id'] ?></span>
                    <span style="font-family:var(--fb);font-size:12px;color:var(--muted)"><?= date('d/m/Y H:i',strtotime($g['date'])) ?></span>
                </div>
                <?php foreach($g['items'] as $it): ?>
                <div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid rgba(255,255,255,.04);font-family:var(--fb);font-size:13px">
                    <span style="color:var(--text2)"><?= htmlspecialchars($it['product_name']) ?></span>
                    <span style="color:var(--red);font-weight:700">+<?= $it['quantity'] ?> u</span>
                </div>
                <?php endforeach; ?>
                <div style="display:flex;justify-content:space-between;margin-top:12px;padding-top:10px;border-top:1px solid rgba(255,53,83,.2)">
                    <span style="font-family:var(--fh);font-size:12px;font-weight:900;color:var(--muted)">VALEUR RETOUR</span>
                    <span style="font-family:var(--fh);font-size:16px;font-weight:900;color:var(--red)"><?= number_format($g['total_retour'],0,',',' ') ?> FCFA</span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ════════════════════════════════════════
     ONGLET: LOGS — LECTURE SEULE 🔒
════════════════════════════════════════ -->
<?php if($view === 'logs'): ?>

<div class="log-readonly-banner">
    <i class="fas fa-shield-alt"></i>
    <div>
        <strong>🔒 Journal immuable — Lecture seule</strong>
        <p>Ce journal enregistre <strong>CHAQUE ACTION</strong> (chaque clic, chaque chargement de page) avec : IP, navigateur, OS, user-agent complet, session ID, méthode HTTP, URL, utilisateur et heure précise. Il est techniquement <strong>impossible de supprimer ces logs</strong> depuis l'interface.</p>
    </div>
</div>

<!-- Filtres logs -->
<div class="panel" style="margin-bottom:18px">
    <div class="ph"><div class="ph-title"><div class="dot p"></div> Filtres</div></div>
    <form method="get" style="padding:16px 22px;display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
        <input type="hidden" name="view" value="logs">
        <div class="f-group">
            <label class="f-label">Date</label>
            <input type="date" name="log_date" class="f-input" style="width:auto" value="<?= htmlspecialchars($log_filter_date) ?>">
        </div>
        <div class="f-group">
            <label class="f-label">Type d'action</label>
            <select name="log_type" class="f-select" style="width:auto">
                <option value="">— Tous —</option>
                <?php foreach(($log_types??[]) as $lt): ?>
                <option value="<?= $lt ?>" <?= $log_filter_type===$lt?'selected':'' ?>><?= htmlspecialchars($lt) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="f-group">
            <label class="f-label">Utilisateur / IP</label>
            <input type="text" name="log_user" class="f-input" style="width:auto" value="<?= htmlspecialchars($log_filter_user) ?>" placeholder="Nom ou IP…">
        </div>
        <button type="submit" class="btn btn-purple"><i class="fas fa-search"></i> Filtrer</button>
        <a href="?view=logs" class="btn btn-neon"><i class="fas fa-undo"></i> Réinitialiser</a>
    </form>
</div>

<div class="panel">
    <div class="ph">
        <div class="ph-title"><div class="dot p"></div> Journal des Actions</div>
        <span class="pbadge p"><?= number_format($log_total) ?> entrée(s)</span>
    </div>

    <?php if(empty($logs)): ?>
    <div style="text-align:center;padding:40px;color:var(--muted)">
        <i class="fas fa-scroll" style="font-size:44px;display:block;margin-bottom:14px;opacity:.2"></i>
        <p>Aucun log correspondant aux filtres</p>
    </div>
    <?php else: ?>

    <?php foreach($logs as $lg):
        /* Couleur par type */
        $lc = match(true) {
            str_contains($lg['action_type'],'DELETE') || str_contains($lg['action_type'],'ERROR')   => ['bdg-r','var(--red)'],
            str_contains($lg['action_type'],'SALE')   || str_contains($lg['action_type'],'PROCESS') => ['bdg-g','var(--neon)'],
            str_contains($lg['action_type'],'STOCK')                                                 => ['bdg-o','var(--orange)'],
            str_contains($lg['action_type'],'VIEW')   || str_contains($lg['action_type'],'PAGE')    => ['bdg-t','var(--teal)'],
            str_contains($lg['action_type'],'EXPORT')                                                => ['bdg-b','var(--blue)'],
            str_contains($lg['action_type'],'LOG')    || str_contains($lg['action_type'],'LOGIN')   => ['bdg-p','var(--purple)'],
            str_contains($lg['action_type'],'EXPENSE')|| str_contains($lg['action_type'],'CLIENT')  => ['bdg-gold','var(--gold)'],
            default                                                                                   => ['bdg','#fff'],
        };
        /* Détection OS/Browser depuis user_agent */
        $ua  = $lg['user_agent'] ?? '';
        $brw = 'Inconnu';
        if(str_contains($ua,'Chrome'))        $brw='Chrome';
        elseif(str_contains($ua,'Firefox'))   $brw='Firefox';
        elseif(str_contains($ua,'Safari'))    $brw='Safari';
        elseif(str_contains($ua,'Edge'))      $brw='Edge';
        $oss = 'Inconnu';
        if(str_contains($ua,'Windows'))       $oss='Windows';
        elseif(str_contains($ua,'Mac'))       $oss='macOS';
        elseif(str_contains($ua,'Linux'))     $oss='Linux';
        elseif(str_contains($ua,'Android'))   $oss='Android';
        elseif(str_contains($ua,'iPhone')||str_contains($ua,'iPad')) $oss='iOS';

        $mob = (str_contains($ua,'Mobile') || str_contains($ua,'Android') || str_contains($ua,'iPhone'));
    ?>
    <div class="log-row">
        <div class="log-time">
            <?= date('d/m H:i:s',strtotime($lg['created_at'])) ?>
        </div>
        <div>
            <span class="bdg <?= $lc[0] ?>"><?= htmlspecialchars($lg['action_type']) ?></span>
        </div>
        <div class="log-content">
            <div class="log-desc">
                <strong style="color:var(--text);font-family:var(--fh)"><?= htmlspecialchars($lg['user_name']??'Système') ?></strong>
                — <?= htmlspecialchars($lg['action_description']) ?>
                <?php if($lg['amount']): ?>
                &nbsp;<strong style="color:var(--gold)"><?= number_format($lg['amount'],0,',',' ') ?> FCFA</strong>
                <?php endif; ?>
                <?php if($lg['quantity']): ?>
                &nbsp;<span style="color:var(--neon)">Qté: <?= $lg['quantity'] ?></span>
                <?php endif; ?>
            </div>
            <div class="log-meta">
                <span class="log-meta-item">
                    <i class="fas fa-network-wired" style="color:var(--blue)"></i>
                    <?= htmlspecialchars($lg['ip_address']??'?') ?>
                </span>
                <span class="log-meta-item">
                    <i class="fas fa-<?= $mob?'mobile-alt':'desktop' ?>" style="color:var(--neon)"></i>
                    <?= $brw ?> / <?= $oss ?>
                    <?= $mob?' 📱':'' ?>
                </span>
                <span class="log-meta-item">
                    <i class="fas fa-key" style="color:var(--purple)"></i>
                    Session: <?= substr($lg['session_id']??'?',0,8) ?>…
                </span>
                <?php if($lg['product_id']): ?>
                <span class="log-meta-item">
                    <i class="fas fa-box" style="color:var(--orange)"></i>
                    Produit #<?= $lg['product_id'] ?>
                </span>
                <?php endif; ?>
                <?php if($lg['invoice_id']): ?>
                <span class="log-meta-item">
                    <i class="fas fa-receipt" style="color:var(--gold)"></i>
                    Facture #<?= $lg['invoice_id'] ?>
                </span>
                <?php endif; ?>
                <span class="log-meta-item" style="font-size:10px;opacity:.7" title="<?= htmlspecialchars(mb_substr($ua,0,200)) ?>">
                    <i class="fas fa-globe"></i>
                    <?= htmlspecialchars(mb_substr($ua,0,40)) ?>…
                </span>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Pagination -->
    <?php if($log_total > $log_per_page):
        $total_pages = ceil($log_total / $log_per_page);
        $base = "?view=logs&log_date=$log_filter_date&log_type=".urlencode($log_filter_type)."&log_user=".urlencode($log_filter_user);
    ?>
    <div class="pagination">
        <?php if($log_page > 1): ?>
        <a href="<?= $base ?>&lp=<?= $log_page-1 ?>" class="page-btn"><i class="fas fa-chevron-left"></i></a>
        <?php endif; ?>
        <?php for($i=max(1,$log_page-3); $i<=min($total_pages,$log_page+3); $i++): ?>
        <a href="<?= $base ?>&lp=<?= $i ?>" class="page-btn <?= $i===$log_page?'active':'' ?>"><?= $i ?></a>
        <?php endfor; ?>
        <?php if($log_page < $total_pages): ?>
        <a href="<?= $base ?>&lp=<?= $log_page+1 ?>" class="page-btn"><i class="fas fa-chevron-right"></i></a>
        <?php endif; ?>
        <span style="font-family:var(--fb);font-size:13px;color:var(--muted);padding:0 8px">
            Page <?= $log_page ?>/<?= $total_pages ?> — <?= number_format($log_total) ?> entrées
        </span>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>
<?php endif; ?>

<?php endif; // location_set ?>

</div><!-- /wrap -->

<!-- ════════════ MODAL ÉDITION MOUVEMENT ════════════ -->
<div id="modal-edit" class="modal">
    <div class="mbox" style="position:relative">
        <button class="mbox-close" onclick="closeModal()">×</button>
        <h2 style="color:var(--gold)"><i class="fas fa-edit"></i> Modifier le Mouvement</h2>
        <form method="post" id="edit-form">
            <input type="hidden" name="movement_id" id="em-id">
            <div class="f-grid">
                <div class="f-group">
                    <label class="f-label">Produit</label>
                    <input type="text" id="em-prod" class="f-input" readonly style="opacity:.6">
                </div>
                <div class="f-group">
                    <label class="f-label">Quantité *</label>
                    <input type="number" name="edit_quantity" id="em-qty" class="f-input" min="1" required>
                </div>
                <div class="f-group">
                    <label class="f-label">Date & Heure *</label>
                    <input type="datetime-local" name="edit_date" id="em-date" class="f-input" required>
                </div>
                <div class="f-group">
                    <label class="f-label">Référence</label>
                    <input type="text" name="edit_reference" id="em-ref" class="f-input">
                </div>
            </div>
            <div class="mbtns">
                <button type="submit" name="edit_movement" class="btn btn-gold"><i class="fas fa-save"></i> Enregistrer</button>
                <button type="button" class="btn btn-red" onclick="closeModal()"><i class="fas fa-times"></i> Annuler</button>
            </div>
        </form>
    </div>
</div>

<script>
/* ─ Modal ─ */
function openEditModal(mvt){
    document.getElementById('em-id').value   = mvt.id;
    document.getElementById('em-prod').value = mvt.product_name;
    document.getElementById('em-qty').value  = Math.abs(mvt.quantity);
    const dt = new Date(mvt.movement_date);
    const pad = v=>String(v).padStart(2,'0');
    document.getElementById('em-date').value =
        `${dt.getFullYear()}-${pad(dt.getMonth()+1)}-${pad(dt.getDate())}T${pad(dt.getHours())}:${pad(dt.getMinutes())}`;
    document.getElementById('em-ref').value  = mvt.reference || '';
    document.getElementById('modal-edit').classList.add('show');
}
function closeModal(){ document.getElementById('modal-edit').classList.remove('show'); }
document.getElementById('modal-edit').addEventListener('click', e => { if(e.target===document.getElementById('modal-edit')) closeModal(); });

/* ─ Stock initial ─ */
function onInitialChange(){
    const sel = document.getElementById('sel-initial');
    const opt = sel.options[sel.selectedIndex];
    const info = document.getElementById('initial-info');
    const btnTxt = document.getElementById('btn-initial-txt');
    const qtyI = document.getElementById('qty-initial');
    if(!opt.value){ info.textContent=''; return; }
    const ini = opt.dataset.ini, cur = opt.dataset.cur;
    if(parseInt(ini)>0){
        info.innerHTML = `<span style="color:var(--gold)">⚠️ Stock initial existant: <strong>${ini}</strong> — Stock actuel: <strong>${cur}</strong> — Vous allez MODIFIER</span>`;
        btnTxt.textContent='Modifier Stock Initial';
        qtyI.value = ini;
    } else {
        info.innerHTML = `<span style="color:var(--neon)">✅ Aucun stock initial. Stock actuel: <strong>${cur}</strong> — Vous allez CRÉER</span>`;
        btnTxt.textContent='Définir Stock Initial';
        qtyI.value = '';
    }
}
function prefillInitial(pid, pname, qty){
    const sel = document.getElementById('sel-initial');
    sel.value = pid; onInitialChange();
    document.getElementById('qty-initial').value = qty;
    sel.scrollIntoView({behavior:'smooth',block:'center'});
}
function confirmInitial(){
    const sel = document.getElementById('sel-initial');
    const opt = sel.options[sel.selectedIndex];
    if(!opt.value) return false;
    const ini=opt.dataset.ini, name=opt.dataset.name;
    const nq = document.getElementById('qty-initial').value;
    if(parseInt(ini)>0)
        return confirm(`⚠️ Modifier le stock initial de "${name}" ?\n\nAncien: ${ini}\nNouveau: ${nq}\n\nCela affectera l'historique !`);
    return confirm(`✅ Définir le stock initial de "${name}" à ${nq} unités ?`);
}

/* ─ Ajustement ─ */
function onAdjChange(){
    const sel = document.getElementById('adj-prod');
    const opt = sel.options[sel.selectedIndex];
    const info = document.getElementById('adj-info');
    if(opt.value)
        info.innerHTML = `<span style="color:var(--neon)">Stock actuel: <strong>${opt.dataset.stock}</strong> unités</span>`;
    else info.textContent='';
}
function confirmAdjustment(){
    const sel = document.getElementById('adj-prod');
    const opt = sel.options[sel.selectedIndex];
    if(!opt.value) return false;
    const cur=parseInt(opt.dataset.stock), name=opt.dataset.name;
    const qty=parseInt(document.getElementById('adj-qty').value);
    const type=document.querySelector('input[name="adjustment_type"]:checked')?.value;
    if(!type){ alert('Sélectionnez un type.'); return false; }
    const ns = type==='positive'?cur+qty:cur-qty;
    return confirm(`${type==='positive'?'✅':'⚠️'} Ajustement ${type==='positive'?'POSITIF':'NÉGATIF'} de "${name}" ?\n\nStock actuel: ${cur}\n${type==='positive'?'+':'-'}${qty} unités\nNouveau stock: ${ns}`);
}

console.log('🚀 Gestion Stock Pro v2.0 — ESPERANCE H2O');
let stockDeferredInstallPrompt=null;
window.addEventListener('beforeinstallprompt',e=>{e.preventDefault();stockDeferredInstallPrompt=e;});
document.getElementById('stockInstallBtn')?.addEventListener('click',async()=>{if(!stockDeferredInstallPrompt){window.location.href='/stock/install_stock_app.php';return;}stockDeferredInstallPrompt.prompt();await stockDeferredInstallPrompt.userChoice.catch(()=>null);stockDeferredInstallPrompt=null;});
function updateStockNetworkBadge(){document.getElementById('stockNetworkBadge')?.classList.toggle('show',!navigator.onLine);}
window.addEventListener('online',updateStockNetworkBadge);window.addEventListener('offline',updateStockNetworkBadge);updateStockNetworkBadge();
if('serviceWorker' in navigator){window.addEventListener('load',()=>navigator.serviceWorker.register('/stock/stock-sw.js').catch(()=>{}));}
</script>
</body>
</html>
