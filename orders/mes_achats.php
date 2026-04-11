<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

/**
 * ════════════════════════════════════════════════════════════════
 * MES ACHATS — ESPERANCE H2O
 * Historique commandes client · Annulation · Statuts
 * Dark Neon · C059 Bold · Autonome
 * ════════════════════════════════════════════════════════════════
 */

session_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once APP_ROOT . '/app/core/DB.php';
use App\Core\DB;

$pdo = DB::getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

/* Auth client */
if (!isset($_SESSION['client_id'])) {
    header('Location: login.php?tab=client'); exit;
}

$client_id    = (int)$_SESSION['client_id'];
$client_name  = $_SESSION['client_name']  ?? '';
$client_phone = $_SESSION['client_phone'] ?? '';

/* ════════════════════════════════════════
   ACTIONS POST
════════════════════════════════════════ */
$msg_ok  = '';
$msg_err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = trim($_POST['action'] ?? '');
    $order_id = (int)($_POST['order_id'] ?? 0);

    /* ── Annuler une commande ── */
    if ($action === 'cancel_order' && $order_id > 0) {
        try {
            /* Vérifier que la commande appartient au client ET est annulable */
            $st = $pdo->prepare(
                "SELECT id, status FROM orders
                 WHERE id = ? AND client_id = ? LIMIT 1"
            );
            $st->execute([$order_id, $client_id]);
            $order = $st->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                $msg_err = 'Commande introuvable.';
            } elseif (!in_array($order['status'], ['pending', 'confirmed'])) {
                $msg_err = 'Cette commande ne peut plus être annulée (déjà en livraison ou terminée).';
            } else {
                $pdo->prepare("UPDATE orders SET status='cancelled' WHERE id = ? AND client_id = ?")
                    ->execute([$order_id, $client_id]);
                $msg_ok = 'Commande annulée avec succès.';
            }
        } catch (Exception $e) {
            $msg_err = 'Erreur lors de l\'annulation.';
        }
    }
}

/* ════════════════════════════════════════
   DONNÉES
════════════════════════════════════════ */
/* Toutes mes commandes */
try {
    $st = $pdo->prepare(
        "SELECT o.*, ci.name AS city_name
         FROM orders o
         LEFT JOIN cities ci ON o.city_id = ci.id
         WHERE o.client_id = ?
         ORDER BY o.created_at DESC"
    );
    $st->execute([$client_id]);
    $orders = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $orders = [];
}

/* Articles de chaque commande */
$order_items = [];
if ($orders) {
    $ids = implode(',', array_map('intval', array_column($orders,'id')));
    try {
        $items_all = $pdo->query(
            "SELECT * FROM order_items WHERE order_id IN ($ids) ORDER BY id"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($items_all as $it) {
            $order_items[$it['order_id']][] = $it;
        }
    } catch (Exception $e) { /* table vide */ }
}

/* Stats */
$total_orders    = count($orders);
$total_spent     = array_sum(array_column($orders,'total_amount'));
$pending_count   = count(array_filter($orders, fn($o) => in_array($o['status'],['pending','confirmed'])));
$done_count      = count(array_filter($orders, fn($o) => $o['status']==='done'));

/* Labels/couleurs statuts */
$status_cfg = [
    'pending'    => ['label'=>'En attente',    'class'=>'bdg-g', 'ico'=>'fa-clock',         'can_cancel'=>true],
    'confirmed'  => ['label'=>'Confirmée',     'class'=>'bdg-c', 'ico'=>'fa-check',         'can_cancel'=>true],
    'delivering' => ['label'=>'En livraison',  'class'=>'bdg-b', 'ico'=>'fa-truck',         'can_cancel'=>false],
    'done'       => ['label'=>'Livrée',        'class'=>'bdg-n', 'ico'=>'fa-circle-check',  'can_cancel'=>false],
    'cancelled'  => ['label'=>'Annulée',       'class'=>'bdg-r', 'ico'=>'fa-times-circle',  'can_cancel'=>false],
];

$pay_labels = [
    'cash'          => '💵 Espèces',
    'mobile_money'  => '📱 Mobile Money',
    'bank_transfer' => '🏦 Virement',
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mes Achats — ESPERANCE H2O</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Courier+Prime:wght@400;700&family=Playfair+Display:wght@700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{
    --bg:#04090e;--surf:#081420;--card:#0d1e2c;--card2:#122030;
    --bord:rgba(50,190,143,.13);--bord2:rgba(50,190,143,.3);
    --neon:#32be8f;--neon2:#19ffa3;--red:#ff3553;--orange:#ff9140;
    --blue:#3d8cff;--gold:#ffd060;--purple:#a855f7;--cyan:#06b6d4;
    --text:#e0f2ea;--text2:#b8d8cc;--muted:#5a8070;
    --glow:0 0 28px rgba(50,190,143,.5);
    --fc:'Courier Prime','Courier New',monospace;
    --fh:'Playfair Display',Georgia,serif;
}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
html{scroll-behavior:smooth;}
body{font-family:var(--fc);font-weight:700;background:var(--bg);color:var(--text);min-height:100vh;overflow-x:hidden;}
body::before{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;
  background:radial-gradient(ellipse 70% 50% at 5% 10%,rgba(50,190,143,.08) 0%,transparent 60%),
             radial-gradient(ellipse 55% 40% at 95% 90%,rgba(61,140,255,.07) 0%,transparent 60%);}
body::after{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;
  background-image:linear-gradient(rgba(50,190,143,.016) 1px,transparent 1px),
                   linear-gradient(90deg,rgba(50,190,143,.016) 1px,transparent 1px);
  background-size:52px 52px;}
.wrap{position:relative;z-index:1;max-width:900px;margin:0 auto;padding:14px 16px 60px;}
::-webkit-scrollbar{width:7px;}::-webkit-scrollbar-track{background:var(--surf);}::-webkit-scrollbar-thumb{background:rgba(50,190,143,.4);border-radius:4px;}

/* ANIMS */
@keyframes fadeUp {from{opacity:0;transform:translateY(22px)}to{opacity:1;transform:translateY(0)}}
@keyframes breathe{0%,100%{box-shadow:0 0 18px rgba(50,190,143,.35)}50%{box-shadow:0 0 46px rgba(50,190,143,.8)}}
@keyframes scan   {0%{left:-100%}100%{left:110%}}
@keyframes zoomIn {from{opacity:0;transform:scale(.88)}to{opacity:1;transform:scale(1)}}
@keyframes shake  {0%,100%{transform:translateX(0)}20%,60%{transform:translateX(-8px)}40%,80%{transform:translateX(8px)}}
@keyframes pdot   {0%,100%{opacity:1}50%{opacity:.2}}
@keyframes spin   {to{transform:rotate(360deg)}}
@keyframes truck  {0%,100%{transform:translateX(0)}50%{transform:translateX(6px)}}

/* TOPBAR */
.topbar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;
  background:rgba(8,20,32,.96);border:1px solid var(--bord);border-radius:18px;
  padding:15px 22px;margin-bottom:14px;position:relative;overflow:hidden;animation:fadeUp .4s ease;}
.topbar::after{content:'';position:absolute;top:0;left:-60%;width:40%;height:2px;
  background:linear-gradient(90deg,transparent,var(--neon),var(--cyan),transparent);animation:scan 4s linear infinite;}
.tb-brand{display:flex;align-items:center;gap:12px;}
.tb-ico{width:46px;height:46px;border-radius:12px;background:linear-gradient(135deg,var(--neon),var(--cyan));
  display:flex;align-items:center;justify-content:center;font-size:22px;color:var(--bg);
  box-shadow:var(--glow);animation:breathe 3.5s ease-in-out infinite;flex-shrink:0;}
.tb-title{font-family:var(--fh);font-size:18px;font-weight:900;color:var(--text);letter-spacing:.5px;}
.tb-title span{color:var(--neon);}
.tb-sub{font-family:var(--fc);font-size:10px;font-weight:700;color:var(--muted);letter-spacing:2px;text-transform:uppercase;}
.tb-user{display:flex;align-items:center;gap:8px;}
.user-av{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--neon),var(--cyan));
  display:flex;align-items:center;justify-content:center;font-size:16px;color:var(--bg);font-weight:900;}
.user-name{font-family:var(--fc);font-size:12px;font-weight:900;color:var(--text);}
.user-phone{font-family:var(--fc);font-size:10px;font-weight:700;color:var(--muted);}

/* BTNS */
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:10px;
  border:1.5px solid transparent;cursor:pointer;font-family:var(--fc);font-size:10px;font-weight:900;
  letter-spacing:.8px;text-transform:uppercase;transition:all .25s;text-decoration:none;white-space:nowrap;}
.btn:active{transform:scale(.97);}
.btn-n{background:rgba(50,190,143,.1);border-color:rgba(50,190,143,.3);color:var(--neon);}
.btn-n:hover{background:var(--neon);color:var(--bg);}
.btn-r{background:rgba(255,53,83,.1);border-color:rgba(255,53,83,.3);color:var(--red);}
.btn-r:hover{background:var(--red);color:#fff;}
.btn-g{background:rgba(255,208,96,.1);border-color:rgba(255,208,96,.3);color:var(--gold);}
.btn-g:hover{background:var(--gold);color:var(--bg);}
.btn-c{background:rgba(6,182,212,.1);border-color:rgba(6,182,212,.3);color:var(--cyan);}
.btn-c:hover{background:var(--cyan);color:var(--bg);}

/* ALERTS */
.alert{display:flex;align-items:flex-start;gap:10px;padding:12px 16px;border-radius:12px;
  margin-bottom:14px;font-family:var(--fc);font-size:12px;font-weight:900;animation:fadeUp .3s ease;}
.alert-ok{background:rgba(50,190,143,.08);border:1.5px solid rgba(50,190,143,.28);color:var(--neon);}
.alert-err{background:rgba(255,53,83,.08);border:1.5px solid rgba(255,53,83,.28);color:var(--red);animation:shake .4s ease;}
.alert i{flex-shrink:0;font-size:15px;margin-top:1px;}

/* KPI */
.kpi{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;margin-bottom:14px;}
.ks{background:var(--card);border:1px solid var(--bord);border-radius:14px;
  padding:16px 14px;display:flex;align-items:center;gap:12px;transition:all .3s;animation:fadeUp .4s ease backwards;}
.ks:hover{transform:translateY(-4px);box-shadow:0 12px 30px rgba(0,0,0,.4);border-color:var(--bord2);}
.ks-ico{width:42px;height:42px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:19px;flex-shrink:0;}
.ks-val{font-family:var(--fc);font-size:24px;font-weight:900;line-height:1.1;}
.ks-lbl{font-family:var(--fc);font-size:9px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-top:2px;}

/* TAB NAV */
.tab-nav{display:flex;gap:6px;background:rgba(0,0,0,.2);border:1px solid var(--bord);border-radius:13px;
  padding:6px;margin-bottom:14px;}
.tnb{flex:1;padding:9px 8px;border-radius:9px;border:1.5px solid transparent;background:none;cursor:pointer;
  font-family:var(--fc);font-size:10px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;
  transition:all .25s;display:flex;align-items:center;justify-content:center;gap:5px;}
.tnb:hover{color:var(--text2);border-color:var(--bord);}
.tnb.on{background:linear-gradient(135deg,rgba(50,190,143,.18),rgba(6,182,212,.1));
  color:#fff;border-color:rgba(50,190,143,.4);}
.tnb .cnt{background:var(--red);color:#fff;width:18px;height:18px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:900;}
.tnb .cnt-n{background:var(--neon);color:var(--bg);}

/* ORDER CARD */
.order-card{background:var(--card);border:1px solid var(--bord);border-radius:16px;
  overflow:hidden;margin-bottom:12px;transition:all .3s;animation:fadeUp .4s ease backwards;}
.order-card:hover{border-color:var(--bord2);box-shadow:0 10px 30px rgba(0,0,0,.4);}
.oc-head{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;
  padding:14px 18px;border-bottom:1px solid rgba(255,255,255,.05);background:rgba(0,0,0,.22);cursor:pointer;}
.oc-num{font-family:var(--fc);font-size:13px;font-weight:900;color:var(--gold);letter-spacing:1px;}
.oc-date{font-family:var(--fc);font-size:10px;font-weight:700;color:var(--muted);}
.oc-total{font-family:var(--fc);font-size:18px;font-weight:900;color:var(--neon);}
.oc-total small{font-size:10px;color:var(--muted);}
.oc-body{padding:14px 18px;}

/* Statut stepper */
.stepper{display:flex;align-items:center;gap:0;margin-bottom:14px;overflow-x:auto;padding-bottom:4px;}
.step{display:flex;flex-direction:column;align-items:center;gap:4px;flex:1;min-width:60px;position:relative;}
.step::after{content:'';position:absolute;top:14px;left:50%;width:100%;height:2px;background:var(--bord2);z-index:0;}
.step:last-child::after{display:none;}
.step-dot{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;
  font-size:12px;border:2px solid var(--bord);background:var(--card2);z-index:1;transition:all .3s;}
.step-dot.done{background:rgba(50,190,143,.18);border-color:var(--neon);color:var(--neon);}
.step-dot.active{background:var(--neon);border-color:var(--neon);color:var(--bg);box-shadow:var(--glow);}
.step-dot.cancelled{background:rgba(255,53,83,.18);border-color:var(--red);color:var(--red);}
.step-lbl{font-family:var(--fc);font-size:8px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;text-align:center;}
.step-lbl.active{color:var(--neon);}
.step-lbl.cancelled{color:var(--red);}

/* Items table */
.items-table{width:100%;border-collapse:collapse;margin-bottom:10px;}
.items-table th{font-family:var(--fc);font-size:9px;font-weight:900;color:var(--muted);
  text-transform:uppercase;letter-spacing:1px;padding:6px 8px;border-bottom:1px solid rgba(255,255,255,.05);text-align:left;}
.items-table td{font-family:var(--fc);font-size:12px;font-weight:700;color:var(--text2);padding:7px 8px;border-bottom:1px solid rgba(255,255,255,.04);}
.items-table tbody tr:last-child td{border-bottom:none;}
.items-table .td-price{font-weight:900;color:var(--neon);}

/* Meta info */
.meta-row{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px;}
.meta-chip{font-family:var(--fc);font-size:10px;font-weight:900;padding:3px 10px;border-radius:9px;
  display:inline-flex;align-items:center;gap:4px;}

/* BADGES */
.bdg{font-family:var(--fc);font-size:10px;font-weight:900;padding:3px 10px;border-radius:11px;display:inline-flex;align-items:center;gap:4px;}
.bdg-n{background:rgba(50,190,143,.12);color:var(--neon);}
.bdg-r{background:rgba(255,53,83,.12);color:var(--red);}
.bdg-g{background:rgba(255,208,96,.12);color:var(--gold);}
.bdg-c{background:rgba(6,182,212,.12);color:var(--cyan);}
.bdg-b{background:rgba(61,140,255,.12);color:var(--blue);}
.bdg-p{background:rgba(168,85,247,.12);color:var(--purple);}

/* Cancel btn */
.cancel-form{display:inline;}
.btn-cancel{display:inline-flex;align-items:center;gap:5px;padding:7px 13px;border-radius:9px;
  border:1.5px solid rgba(255,53,83,.3);background:rgba(255,53,83,.08);color:var(--red);
  font-family:var(--fc);font-size:10px;font-weight:900;letter-spacing:.8px;text-transform:uppercase;
  cursor:pointer;transition:all .25s;}
.btn-cancel:hover{background:var(--red);color:#fff;box-shadow:0 0 18px rgba(255,53,83,.4);}

/* EMPTY */
.empty{text-align:center;padding:52px 20px;color:var(--muted);}
.empty i{font-size:64px;display:block;margin-bottom:14px;opacity:.08;}
.empty h3{font-family:var(--fh);font-size:18px;font-weight:900;color:var(--text2);margin-bottom:7px;}
.empty p{font-family:var(--fc);font-size:11px;font-weight:700;}

/* MODAL CONFIRM */
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.9);z-index:1000;
  align-items:center;justify-content:center;backdrop-filter:blur(12px);}
.modal.show{display:flex;}
.mbox{background:var(--card);border:1px solid var(--bord);border-radius:18px;
  padding:28px;max-width:380px;width:92%;text-align:center;
  animation:zoomIn .25s cubic-bezier(.23,1,.32,1);box-shadow:0 28px 70px rgba(0,0,0,.75);}
.m-ico{font-size:52px;margin-bottom:14px;display:block;}
.m-title{font-family:var(--fc);font-size:16px;font-weight:900;color:var(--red);letter-spacing:1px;margin-bottom:10px;}
.m-body{font-family:var(--fc);font-size:12px;font-weight:700;color:var(--text2);line-height:1.7;margin-bottom:20px;}
.m-btns{display:flex;gap:10px;justify-content:center;}

/* RESPONSIVE */
@media(max-width:600px){
    .kpi{grid-template-columns:1fr 1fr;}
    .stepper{gap:0;}
    .step-lbl{font-size:7px;}
}
</style>
</head>
<body>
<div class="wrap">

<!-- ── TOPBAR ── -->
<div class="topbar">
  <div class="tb-brand">
    <div class="tb-ico">💧</div>
    <div>
      <div class="tb-title"><span>ESPERANCE</span> H2O</div>
      <div class="tb-sub">Mes achats</div>
    </div>
  </div>
  <div class="tb-user">
    <div class="user-av"><?= strtoupper(mb_substr($client_name,0,1)) ?></div>
    <div>
      <div class="user-name"><?= htmlspecialchars($client_name) ?></div>
      <div class="user-phone"><i class="fas fa-phone" style="font-size:8px;color:var(--neon)"></i> &nbsp;<?= htmlspecialchars($client_phone) ?></div>
    </div>
    <a href="order_online.php" class="btn btn-n" style="margin-left:6px"><i class="fas fa-shopping-cart"></i> Commander</a>
    <a href="login.php?action=logout_client" class="btn btn-r"><i class="fas fa-sign-out-alt"></i></a>
  </div>
</div>

<?php
/* Logout via GET */
if (isset($_GET['action']) && $_GET['action'] === 'logout_client') {
    session_unset();
    session_destroy();
    header('Location: login.php?tab=client');
    exit;
}
?>

<!-- ── ALERTS ── -->
<?php if ($msg_ok): ?>
<div class="alert alert-ok"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($msg_ok) ?></div>
<?php endif; ?>
<?php if ($msg_err): ?>
<div class="alert alert-err"><i class="fas fa-times-circle"></i> <?= htmlspecialchars($msg_err) ?></div>
<?php endif; ?>

<!-- ── KPI ── -->
<div class="kpi">
  <div class="ks" style="animation-delay:.04s">
    <div class="ks-ico" style="background:rgba(50,190,143,.12);color:var(--neon)"><i class="fas fa-receipt"></i></div>
    <div><div class="ks-val" style="color:var(--neon)"><?= $total_orders ?></div><div class="ks-lbl">Total commandes</div></div>
  </div>
  <div class="ks" style="animation-delay:.08s">
    <div class="ks-ico" style="background:rgba(255,208,96,.12);color:var(--gold)"><i class="fas fa-coins"></i></div>
    <div><div class="ks-val" style="color:var(--gold)"><?= number_format($total_spent,0,'','.')  ?></div><div class="ks-lbl">Total dépensé CFA</div></div>
  </div>
  <div class="ks" style="animation-delay:.12s">
    <div class="ks-ico" style="background:rgba(6,182,212,.12);color:var(--cyan)"><i class="fas fa-clock"></i></div>
    <div><div class="ks-val" style="color:var(--cyan)"><?= $pending_count ?></div><div class="ks-lbl">En cours</div></div>
  </div>
  <div class="ks" style="animation-delay:.16s">
    <div class="ks-ico" style="background:rgba(50,190,143,.12);color:var(--neon)"><i class="fas fa-circle-check"></i></div>
    <div><div class="ks-val" style="color:var(--neon)"><?= $done_count ?></div><div class="ks-lbl">Livrées</div></div>
  </div>
</div>

<!-- ── TAB NAV ── -->
<?php
$active_orders    = array_filter($orders, fn($o) => in_array($o['status'],['pending','confirmed','delivering']));
$done_orders      = array_filter($orders, fn($o) => $o['status']==='done');
$cancelled_orders = array_filter($orders, fn($o) => $o['status']==='cancelled');
?>
<div class="tab-nav">
  <button class="tnb on" id="tab-all"       onclick="switchT('all')">
    <i class="fas fa-list"></i> Tout
    <span class="cnt cnt-n"><?= $total_orders ?></span>
  </button>
  <button class="tnb" id="tab-active"   onclick="switchT('active')">
    <i class="fas fa-clock"></i> En cours
    <?php if($pending_count): ?><span class="cnt"><?= $pending_count ?></span><?php endif; ?>
  </button>
  <button class="tnb" id="tab-done"     onclick="switchT('done')">
    <i class="fas fa-circle-check"></i> Livrées
    <?php if($done_count): ?><span class="cnt cnt-n"><?= $done_count ?></span><?php endif; ?>
  </button>
  <button class="tnb" id="tab-cancelled" onclick="switchT('cancelled')">
    <i class="fas fa-times-circle"></i> Annulées
  </button>
</div>

<!-- ── COMMANDES ── -->
<?php if (empty($orders)): ?>
<div class="empty">
  <i class="fas fa-receipt"></i>
  <h3>Aucune commande</h3>
  <p>Vous n'avez pas encore passé de commande</p>
  <a href="order_online.php" class="btn btn-n" style="margin-top:16px;display:inline-flex">
    <i class="fas fa-shopping-cart"></i> Passer une commande
  </a>
</div>
<?php else: ?>

<?php foreach ($orders as $i => $order):
  $cfg  = $status_cfg[$order['status']] ?? $status_cfg['pending'];
  $items= $order_items[$order['id']] ?? [];
  $steps= [
    ['key'=>'pending',    'lbl'=>'En attente', 'ico'=>'fa-clock'],
    ['key'=>'confirmed',  'lbl'=>'Confirmée',  'ico'=>'fa-check'],
    ['key'=>'delivering', 'lbl'=>'Livraison',  'ico'=>'fa-truck'],
    ['key'=>'done',       'lbl'=>'Livrée',     'ico'=>'fa-circle-check'],
  ];
  $status_order = ['pending'=>0,'confirmed'=>1,'delivering'=>2,'done'=>3,'cancelled'=>-1];
  $cur = $status_order[$order['status']] ?? 0;
  $is_cancelled = $order['status'] === 'cancelled';
  $group = in_array($order['status'],['pending','confirmed','delivering']) ? 'active' : $order['status'];
?>
<div class="order-card" style="animation-delay:<?= $i*0.05 ?>s"
     data-group="<?= $group ?>" id="order-<?= $order['id'] ?>">

  <!-- HEAD -->
  <div class="oc-head" onclick="toggleOrder(<?= $order['id'] ?>)">
    <div>
      <div class="oc-num"><?= htmlspecialchars($order['order_number']) ?></div>
      <div class="oc-date">
        <i class="fas fa-calendar" style="font-size:9px;color:var(--muted)"></i>
        <?= date('d/m/Y à H:i', strtotime($order['created_at'])) ?>
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
      <span class="bdg <?= $cfg['class'] ?>">
        <i class="fas <?= $cfg['ico'] ?>"></i> <?= $cfg['label'] ?>
      </span>
      <span class="oc-total"><?= number_format((float)$order['total_amount'],0,'','.')  ?> <small>CFA</small></span>
      <i class="fas fa-chevron-down" id="chev-<?= $order['id'] ?>" style="color:var(--muted);font-size:13px;transition:transform .3s"></i>
    </div>
  </div>

  <!-- BODY -->
  <div class="oc-body" id="body-<?= $order['id'] ?>" style="display:none">

    <!-- STEPPER STATUT -->
    <?php if (!$is_cancelled): ?>
    <div class="stepper">
      <?php foreach ($steps as $s):
        $sidx = $status_order[$s['key']];
        $cls  = $sidx < $cur ? 'done' : ($sidx === $cur ? 'active' : '');
      ?>
      <div class="step">
        <div class="step-dot <?= $cls ?>"><i class="fas <?= $s['ico'] ?>"></i></div>
        <div class="step-lbl <?= $cls ?>"><?= $s['lbl'] ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div style="text-align:center;padding:10px 0 14px">
      <span class="bdg bdg-r"><i class="fas fa-times-circle"></i> Commande annulée</span>
    </div>
    <?php endif; ?>

    <!-- META -->
    <div class="meta-row">
      <?php if (!empty($order['city_name'])): ?>
      <span class="meta-chip bdg-c"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($order['city_name']) ?></span>
      <?php endif; ?>
      <span class="meta-chip bdg-g"><?= $pay_labels[$order['payment_method']] ?? $order['payment_method'] ?></span>
    </div>

    <?php if (!empty($order['delivery_address'])): ?>
    <div style="font-family:var(--fc);font-size:11px;font-weight:700;color:var(--text2);margin-bottom:12px;
                padding:8px 12px;background:rgba(0,0,0,.18);border-radius:9px;border:1px solid var(--bord)">
      <i class="fas fa-location-dot" style="color:var(--neon);font-size:10px"></i>
      &nbsp;<?= htmlspecialchars($order['delivery_address']) ?>
    </div>
    <?php endif; ?>

    <!-- ARTICLES -->
    <?php if ($items): ?>
    <table class="items-table">
      <thead>
        <tr>
          <th>Article</th><th style="text-align:center">Qté</th>
          <th style="text-align:right">PU (CFA)</th><th style="text-align:right">Total</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($items as $it): ?>
      <tr>
        <td><?= htmlspecialchars($it['product_name']) ?></td>
        <td style="text-align:center"><?= (int)$it['quantity'] ?></td>
        <td style="text-align:right"><?= number_format((float)$it['unit_price'],0,'','.')  ?></td>
        <td class="td-price" style="text-align:right"><?= number_format((float)$it['subtotal'],0,'','.')  ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

    <?php if (!empty($order['notes'])): ?>
    <div style="font-family:var(--fc);font-size:11px;font-weight:700;color:var(--muted);
                padding:7px 12px;background:rgba(0,0,0,.15);border-radius:9px;margin-bottom:10px">
      <i class="fas fa-comment" style="color:var(--muted);font-size:9px"></i>
      &nbsp;<?= htmlspecialchars($order['notes']) ?>
    </div>
    <?php endif; ?>

    <!-- ACTION ANNULER -->
    <?php if ($cfg['can_cancel']): ?>
    <div style="display:flex;justify-content:flex-end;margin-top:8px">
      <button class="btn-cancel" onclick="askCancel(<?= $order['id'] ?>,'<?= htmlspecialchars(addslashes($order['order_number'])) ?>')">
        <i class="fas fa-times-circle"></i> ANNULER CETTE COMMANDE
      </button>
    </div>
    <?php endif; ?>

  </div><!-- /oc-body -->
</div><!-- /order-card -->
<?php endforeach; ?>
<?php endif; ?>

</div><!-- /wrap -->

<!-- ── MODAL CONFIRMATION ANNULATION ── -->
<div class="modal" id="cancel-modal">
  <div class="mbox">
    <span class="m-ico">⚠️</span>
    <div class="m-title">Confirmer l'annulation ?</div>
    <div class="m-body" id="cancel-body">Voulez-vous vraiment annuler cette commande ?</div>
    <div class="m-btns">
      <form method="POST" id="cancel-form">
        <input type="hidden" name="action" value="cancel_order">
        <input type="hidden" name="order_id" id="cancel-order-id">
        <button type="submit" class="btn btn-r"><i class="fas fa-times-circle"></i> OUI, ANNULER</button>
      </form>
      <button class="btn btn-n" onclick="closeCancelModal()"><i class="fas fa-arrow-left"></i> NON, GARDER</button>
    </div>
  </div>
</div>

<script>
/* ── TOGGLE ORDER BODY ── */
function toggleOrder(id) {
    const body = document.getElementById('body-'+id);
    const chev = document.getElementById('chev-'+id);
    if (!body) return;
    const open = body.style.display !== 'none';
    body.style.display = open ? 'none' : 'block';
    if (chev) chev.style.transform = open ? '' : 'rotate(180deg)';
}

/* ── TAB FILTER ── */
function switchT(group) {
    document.querySelectorAll('.tnb').forEach(b => b.classList.remove('on'));
    document.getElementById('tab-'+group)?.classList.add('on');

    document.querySelectorAll('.order-card').forEach(card => {
        if (group === 'all') {
            card.style.display = 'block';
        } else {
            card.style.display = card.dataset.group === group ? 'block' : 'none';
        }
    });
}

/* ── CANCEL MODAL ── */
function askCancel(orderId, orderNum) {
    document.getElementById('cancel-order-id').value = orderId;
    document.getElementById('cancel-body').textContent =
        'Voulez-vous vraiment annuler la commande ' + orderNum + ' ?';
    document.getElementById('cancel-modal').classList.add('show');
}
function closeCancelModal() {
    document.getElementById('cancel-modal').classList.remove('show');
}
document.getElementById('cancel-modal')?.addEventListener('click', function(e) {
    if (e.target === this) closeCancelModal();
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeCancelModal(); });

/* Auto-ouvrir la première commande en cours */
const first = document.querySelector('.order-card[data-group="active"]');
if (first) {
    const id = first.id.replace('order-','');
    toggleOrder(id);
}

console.log('%c ESPERANCE H2O // MES ACHATS — DARK NEON v3.0 ','background:#04090e;color:#32be8f;font-family:"Courier New";padding:5px;border:1px solid #32be8f');
</script>
</body>
</html>
