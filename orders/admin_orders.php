<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

/**
 * ADMIN DASHBOARD COMMANDES — ESPERANCE H2O v4
 * ✅ Polling AJAX silencieux (sans scintillement)
 * ✅ Export imprimable complet filtré par société/ville/client
 * ✅ Texte agrandi pour meilleure lisibilité
 */
session_start();
ini_set('display_errors', 0);
error_reporting(0);

require_once APP_ROOT . '/app/core/DB.php';
use App\Core\DB;

if (empty($_SESSION['user_id'])) {
    header('Location: /../auth/login_unified.php'); exit;
}

$pdo = DB::getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$admin_name = $_SESSION['username'] ?? 'Admin';
$admin_role = $_SESSION['role'] ?? 'admin';

/* ── Tables ── */
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS order_status_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL, old_status VARCHAR(30), new_status VARCHAR(30),
        changed_by VARCHAR(100), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(order_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cashier_notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type ENUM('new_order','status_change','payment','info','alert') DEFAULT 'info',
        title VARCHAR(255) NOT NULL, message TEXT NOT NULL,
        order_id INT DEFAULT NULL, order_number VARCHAR(60) DEFAULT NULL,
        client_name VARCHAR(200) DEFAULT NULL, amount DECIMAL(12,2) DEFAULT 0,
        is_read TINYINT(1) DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(is_read)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch(Exception $e) {}

/* ══ AJAX ══ */
if (!empty($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $act = trim($_POST['action']);

    /* ── POLL SILENCIEUX : ne retourne que KPIs + nouvelles commandes ── */
    if ($act === 'poll_silent') {
        try {
            $since_id = (int)($_POST['since_id'] ?? 0);
            $kpi = $pdo->query("SELECT COUNT(*) AS total,
                SUM(status='pending') AS pending, SUM(status='confirmed') AS confirmed,
                SUM(status='delivering') AS delivering, SUM(status='done') AS done,
                SUM(status='cancelled') AS cancelled,
                COALESCE(SUM(CASE WHEN status='done' THEN total_amount END),0) AS revenue,
                SUM(DATE(created_at)=CURDATE()) AS today_count,
                COALESCE(SUM(CASE WHEN DATE(created_at)=CURDATE() THEN total_amount END),0) AS today_revenue
                FROM orders")->fetch(PDO::FETCH_ASSOC);
            $max_id = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM orders")->fetchColumn();
            $new_orders = [];
            if ($since_id > 0) {
                $sn = $pdo->prepare("SELECT o.id,o.order_number,o.total_amount,o.status,o.created_at,
                    c.name AS client_name FROM orders o LEFT JOIN clients c ON o.client_id=c.id
                    WHERE o.id>? ORDER BY o.id DESC LIMIT 10");
                $sn->execute([$since_id]);
                $new_orders = $sn->fetchAll(PDO::FETCH_ASSOC);
                foreach ($new_orders as $no) {
                    try {
                        $pdo->prepare("INSERT INTO cashier_notifications(type,title,message,order_id,order_number,client_name,amount)VALUES(?,?,?,?,?,?,?)")
                            ->execute(['new_order','Nouvelle commande',
                                'Commande '.$no['order_number'].' de '.($no['client_name']??'Client').' — '.number_format((float)$no['total_amount'],0,'','.').' CFA',
                                $no['id'],$no['order_number'],$no['client_name']??'',$no['total_amount']]);
                    } catch(Exception $e) {}
                }
            }
            echo json_encode(['success'=>true,'kpi'=>$kpi,'max_id'=>$max_id,'new_orders'=>$new_orders]);
        } catch(Exception $e) {
            echo json_encode(['success'=>false]);
        }
        exit;
    }

    if ($act === 'get_orders') {
        try {
            $status   = trim($_POST['status'] ?? '');
            $search   = trim($_POST['search'] ?? '');
            $company  = (int)($_POST['company_id'] ?? 0);
            $city     = (int)($_POST['city_id'] ?? 0);
            $page     = max(1,(int)($_POST['page'] ?? 1));
            $pp = 25; $offset = ($page-1)*$pp;

            $where = ['1=1']; $params = [];
            if ($status)  { $where[] = 'o.status=?'; $params[] = $status; }
            if ($company) { $where[] = 'o.company_id=?'; $params[] = $company; }
            if ($city)    { $where[] = 'o.city_id=?'; $params[] = $city; }
            if ($search)  {
                $where[] = '(o.order_number LIKE ? OR c.name LIKE ? OR c.phone LIKE ?)';
                $lk = '%'.$search.'%';
                $params[] = $lk; $params[] = $lk; $params[] = $lk;
            }
            $ws = implode(' AND ', $where);

            $stC = $pdo->prepare("SELECT COUNT(*) FROM orders o LEFT JOIN clients c ON o.client_id=c.id WHERE $ws");
            $stC->execute($params);
            $total = (int)$stC->fetchColumn();

            $st = $pdo->prepare("SELECT o.*, c.name AS client_name, c.phone AS client_phone,
                ci.name AS city_name, co.name AS company_name
                FROM orders o
                LEFT JOIN clients c ON o.client_id=c.id
                LEFT JOIN cities ci ON o.city_id=ci.id
                LEFT JOIN companies co ON o.company_id=co.id
                WHERE $ws ORDER BY o.created_at DESC LIMIT $pp OFFSET $offset");
            $st->execute($params);
            $orders = $st->fetchAll(PDO::FETCH_ASSOC);

            if ($orders) {
                $ids  = implode(',', array_map('intval', array_column($orders, 'id')));
                $itms = $pdo->query("SELECT * FROM order_items WHERE order_id IN($ids)")->fetchAll(PDO::FETCH_ASSOC);
                $im = [];
                foreach ($itms as $it) $im[$it['order_id']][] = $it;
                foreach ($orders as &$o) $o['items'] = $im[$o['id']] ?? [];
            }

            $kpi = $pdo->query("SELECT COUNT(*) AS total,
                SUM(status='pending') AS pending, SUM(status='confirmed') AS confirmed,
                SUM(status='delivering') AS delivering, SUM(status='done') AS done,
                SUM(status='cancelled') AS cancelled,
                COALESCE(SUM(CASE WHEN status='done' THEN total_amount END),0) AS revenue,
                SUM(DATE(created_at)=CURDATE()) AS today_count,
                COALESCE(SUM(CASE WHEN DATE(created_at)=CURDATE() THEN total_amount END),0) AS today_revenue
                FROM orders")->fetch(PDO::FETCH_ASSOC);

            $max_id = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM orders")->fetchColumn();

            echo json_encode(['success'=>true,'orders'=>$orders,'total'=>$total,'page'=>$page,
                'per_page'=>$pp,'kpi'=>$kpi,'max_id'=>$max_id]);
        } catch(Exception $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }

    /* ── EXPORT IMPRIMABLE COMPLET ── */
    if ($act === 'get_print_orders') {
        try {
            $status  = trim($_POST['status'] ?? '');
            $search  = trim($_POST['search'] ?? '');
            $company = (int)($_POST['company_id'] ?? 0);
            $city    = (int)($_POST['city_id'] ?? 0);

            $where = ['1=1']; $params = [];
            if ($status)  { $where[] = 'o.status=?'; $params[] = $status; }
            if ($company) { $where[] = 'o.company_id=?'; $params[] = $company; }
            if ($city)    { $where[] = 'o.city_id=?'; $params[] = $city; }
            if ($search)  {
                $where[] = '(o.order_number LIKE ? OR c.name LIKE ? OR c.phone LIKE ?)';
                $lk = '%'.$search.'%';
                $params[] = $lk; $params[] = $lk; $params[] = $lk;
            }
            $ws = implode(' AND ', $where);

            $st = $pdo->prepare("SELECT o.*, c.name AS client_name, c.phone AS client_phone,
                ci.name AS city_name, co.name AS company_name
                FROM orders o
                LEFT JOIN clients c ON o.client_id=c.id
                LEFT JOIN cities ci ON o.city_id=ci.id
                LEFT JOIN companies co ON o.company_id=co.id
                WHERE $ws ORDER BY o.created_at DESC LIMIT 200");
            $st->execute($params);
            $orders = $st->fetchAll(PDO::FETCH_ASSOC);

            if ($orders) {
                $ids  = implode(',', array_map('intval', array_column($orders, 'id')));
                $itms = $pdo->query("SELECT * FROM order_items WHERE order_id IN($ids) ORDER BY order_id,id")->fetchAll(PDO::FETCH_ASSOC);
                $im = [];
                foreach ($itms as $it) $im[$it['order_id']][] = $it;
                foreach ($orders as &$o) $o['items'] = $im[$o['id']] ?? [];
            }

            $co_name = ''; $ci_name = '';
            if ($company) { $r=$pdo->prepare("SELECT name FROM companies WHERE id=?");$r->execute([$company]);$co_name=$r->fetchColumn()??''; }
            if ($city)    { $r=$pdo->prepare("SELECT name FROM cities WHERE id=?");$r->execute([$city]);$ci_name=$r->fetchColumn()??''; }

            echo json_encode(['success'=>true,'orders'=>$orders,
                'filters'=>['status'=>$status,'search'=>$search,'company'=>$co_name,'city'=>$ci_name],
                'generated_at'=>date('d/m/Y H:i:s'),'admin'=>$admin_name]);
        } catch(Exception $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }

    if ($act === 'update_status') {
        try {
            $oid = (int)($_POST['order_id'] ?? 0);
            $status = trim($_POST['status'] ?? '');
            if (!$oid || !in_array($status, ['pending','confirmed','delivering','done','cancelled'])) {
                echo json_encode(['success'=>false,'message'=>'Données invalides']); exit;
            }
            $old = $pdo->prepare("SELECT status,order_number,client_id,total_amount FROM orders WHERE id=? LIMIT 1");
            $old->execute([$oid]);
            $row = $old->fetch(PDO::FETCH_ASSOC);
            if (!$row) { echo json_encode(['success'=>false,'message'=>'Introuvable']); exit; }

            $pdo->prepare("UPDATE orders SET status=? WHERE id=?")->execute([$status,$oid]);

            $sL = ['confirmed'=>'Commande confirmée','delivering'=>'En livraison','done'=>'Commande livrée','cancelled'=>'Commande annulée'];
            $sM = ['confirmed'=>'Votre commande '.$row['order_number'].' a été confirmée.',
                   'delivering'=>'Votre commande '.$row['order_number'].' est en route !',
                   'done'=>'Votre commande '.$row['order_number'].' a été livrée. Merci !',
                   'cancelled'=>'Votre commande '.$row['order_number'].' a été annulée.'];
            if (isset($sL[$status])) {
                try { $pdo->prepare("INSERT INTO notifications(client_id,title,message,type,order_id)VALUES(?,?,?,?,?)")
                    ->execute([$row['client_id'],$sL[$status],$sM[$status],'status',$oid]); } catch(Exception $e) {}
            }
            try { $pdo->prepare("INSERT INTO cashier_notifications(type,title,message,order_id,order_number,amount)VALUES(?,?,?,?,?,?)")
                ->execute(['status_change','Statut modifié — '.($sL[$status]??$status),
                    'Commande '.$row['order_number'].' → '.($sL[$status]??$status).' (par '.$admin_name.')',
                    $oid,$row['order_number'],$row['total_amount']]); } catch(Exception $e) {}
            try { $pdo->prepare("INSERT INTO order_status_log(order_id,old_status,new_status,changed_by)VALUES(?,?,?,?)")
                ->execute([$oid,$row['status'],$status,$admin_name]); } catch(Exception $e) {}

            echo json_encode(['success'=>true]);
        } catch(Exception $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }

    if ($act === 'get_detail') {
        try {
            $oid = (int)($_POST['order_id'] ?? 0);
            $st = $pdo->prepare("SELECT o.*,c.name AS client_name,c.phone AS client_phone,
                ci.name AS city_name,co.name AS company_name
                FROM orders o LEFT JOIN clients c ON o.client_id=c.id
                LEFT JOIN cities ci ON o.city_id=ci.id LEFT JOIN companies co ON o.company_id=co.id
                WHERE o.id=? LIMIT 1");
            $st->execute([$oid]);
            $order = $st->fetch(PDO::FETCH_ASSOC);
            if (!$order) { echo json_encode(['success'=>false,'message'=>'Introuvable']); exit; }
            $si = $pdo->prepare("SELECT * FROM order_items WHERE order_id=?");
            $si->execute([$oid]); $order['items'] = $si->fetchAll(PDO::FETCH_ASSOC);
            try {
                $sl = $pdo->prepare("SELECT * FROM order_status_log WHERE order_id=? ORDER BY created_at DESC LIMIT 15");
                $sl->execute([$oid]); $order['log'] = $sl->fetchAll(PDO::FETCH_ASSOC);
            } catch(Exception $e) { $order['log'] = []; }
            echo json_encode(['success'=>true,'order'=>$order]);
        } catch(Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    if ($act === 'bulk_status') {
        try {
            $ids = json_decode($_POST['ids'] ?? '[]', true);
            $status = trim($_POST['status'] ?? '');
            if (empty($ids) || !in_array($status,['confirmed','delivering','done','cancelled'])) {
                echo json_encode(['success'=>false,'message'=>'Données invalides']); exit;
            }
            $ph = implode(',',array_fill(0,count($ids),'?'));
            $pdo->prepare("UPDATE orders SET status=? WHERE id IN($ph)")->execute(array_merge([$status],array_map('intval',$ids)));
            echo json_encode(['success'=>true,'updated'=>count($ids)]);
        } catch(Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    if ($act === 'get_stats') {
        try {
            $days = [];
            for ($i=6;$i>=0;$i--) {
                $d = date('Y-m-d',strtotime("-$i days"));
                $st = $pdo->prepare("SELECT COUNT(*) cnt,COALESCE(SUM(total_amount),0) rev FROM orders WHERE DATE(created_at)=? AND status!='cancelled'");
                $st->execute([$d]); $r = $st->fetch(PDO::FETCH_ASSOC);
                $days[] = ['date'=>date('d/m',strtotime($d)),'count'=>(int)$r['cnt'],'rev'=>(float)$r['rev']];
            }
            echo json_encode(['success'=>true,'days'=>$days]);
        } catch(Exception $e) { echo json_encode(['success'=>false]); }
        exit;
    }

    if ($act === 'get_cashier_notifs') {
        try {
            $since_id = (int)($_POST['since_id'] ?? 0);
            $page = max(1,(int)($_POST['page'] ?? 1));
            $pp = 30; $offset = ($page-1)*$pp;
            $notifs = $pdo->query("SELECT * FROM cashier_notifications ORDER BY created_at DESC LIMIT $pp OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);
            $unread = (int)$pdo->query("SELECT COUNT(*) FROM cashier_notifications WHERE is_read=0")->fetchColumn();
            $total  = (int)$pdo->query("SELECT COUNT(*) FROM cashier_notifications")->fetchColumn();
            $new_notifs = [];
            if ($since_id > 0) {
                $sn = $pdo->prepare("SELECT * FROM cashier_notifications WHERE id>? ORDER BY id DESC LIMIT 10");
                $sn->execute([$since_id]); $new_notifs = $sn->fetchAll(PDO::FETCH_ASSOC);
            }
            $max_nid = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM cashier_notifications")->fetchColumn();
            echo json_encode(['success'=>true,'notifs'=>$notifs,'unread'=>$unread,'total'=>$total,'new_notifs'=>$new_notifs,'max_id'=>$max_nid]);
        } catch(Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    if ($act === 'mark_notifs_read') {
        try {
            $ids = json_decode($_POST['ids'] ?? '[]', true);
            if (!empty($ids)) {
                $ph = implode(',',array_fill(0,count($ids),'?'));
                $pdo->prepare("UPDATE cashier_notifications SET is_read=1 WHERE id IN($ph)")->execute(array_map('intval',$ids));
            } else {
                $pdo->query("UPDATE cashier_notifications SET is_read=1");
            }
            echo json_encode(['success'=>true]);
        } catch(Exception $e) { echo json_encode(['success'=>false]); }
        exit;
    }

    if ($act === 'delete_notif') {
        try {
            $nid = (int)($_POST['notif_id'] ?? 0);
            if ($nid) $pdo->prepare("DELETE FROM cashier_notifications WHERE id=?")->execute([$nid]);
            echo json_encode(['success'=>true]);
        } catch(Exception $e) { echo json_encode(['success'=>false]); }
        exit;
    }

    if ($act === 'add_notif') {
        try {
            $type  = trim($_POST['type'] ?? 'info');
            $title = trim($_POST['title'] ?? '');
            $msg   = trim($_POST['message'] ?? '');
            if (!$title || !$msg) { echo json_encode(['success'=>false,'message'=>'Titre et message requis']); exit; }
            $pdo->prepare("INSERT INTO cashier_notifications(type,title,message)VALUES(?,?,?)")->execute([$type,$title,$msg]);
            echo json_encode(['success'=>true]);
        } catch(Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Action inconnue']); exit;
}

$companies = $pdo->query("SELECT id,name FROM companies ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$cities    = $pdo->query("SELECT id,name FROM cities ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$unread_cnt = (int)$pdo->query("SELECT COUNT(*) FROM cashier_notifications WHERE is_read=0")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Dashboard Commandes — ESPERANCE H2O</title>
<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:ital,wght@0,700;0,900;1,700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{
  --bg:#04090e; --card:#0c1b28; --card2:#0f2133; --bord:rgba(50,190,143,.13);
  --neon:#32be8f; --neon2:#19ffa3; --red:#ff3553; --gold:#ffd060;
  --cyan:#06b6d4; --blue:#3d8cff; --purple:#a78bfa; --orange:#ff9140;
  --text:#dff2ea; --text2:#b0d4c4; --muted:#5a7a6c;
  --gn:0 0 20px rgba(50,190,143,.38); --gr:0 0 20px rgba(255,53,83,.38);
  --fh:'Source Serif 4','Book Antiqua',Georgia,serif;
}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html,body{min-height:100vh}
body{font-family:var(--fh);font-weight:900;background:var(--bg);color:var(--text);overflow-x:hidden;font-size:15px}
body::before{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;
  background:radial-gradient(ellipse 55% 40% at 3% 4%,rgba(50,190,143,.065),transparent 55%),
             radial-gradient(ellipse 40% 30% at 97% 96%,rgba(61,140,255,.055),transparent 55%)}
body::after{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;
  background-image:linear-gradient(rgba(50,190,143,.012) 1px,transparent 1px),
                   linear-gradient(90deg,rgba(50,190,143,.012) 1px,transparent 1px);
  background-size:50px 50px}

@keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
@keyframes spin{to{transform:rotate(360deg)}}
@keyframes breathe{0%,100%{box-shadow:0 0 12px rgba(50,190,143,.3)}50%{box-shadow:0 0 32px rgba(50,190,143,.75)}}
@keyframes scan{0%{left:-80%}100%{left:110%}}
@keyframes ping{0%{transform:scale(1);opacity:.8}100%{transform:scale(2.1);opacity:0}}
@keyframes pop{0%{transform:scale(.65)}60%{transform:scale(1.15)}100%{transform:scale(1)}}
@keyframes toastIn{from{opacity:0;transform:translateX(110%)}to{opacity:1;transform:translateX(0)}}
@keyframes toastOut{from{opacity:1;transform:translateX(0)}to{opacity:0;transform:translateX(110%)}}
@keyframes slideUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
@keyframes glow{0%,100%{border-color:rgba(50,190,143,.2)}50%{border-color:rgba(50,190,143,.6);box-shadow:0 0 18px rgba(50,190,143,.25)}}

.wrap{position:relative;z-index:1;display:flex;flex-direction:column;min-height:100vh}

/* TOPBAR */
.topbar{background:rgba(4,9,14,.97);border-bottom:1px solid var(--bord);
  backdrop-filter:blur(18px);padding:10px 16px;display:flex;align-items:center;gap:10px;
  position:sticky;top:0;z-index:300}
.topbar::after{content:'';position:absolute;bottom:0;left:0;right:0;height:1px;
  background:linear-gradient(90deg,transparent,var(--neon),var(--cyan),var(--blue),transparent)}
.tb-logo{width:36px;height:36px;border-radius:10px;flex-shrink:0;
  background:linear-gradient(135deg,var(--neon),var(--cyan));
  display:flex;align-items:center;justify-content:center;font-size:19px;color:var(--bg);
  box-shadow:var(--gn);animation:breathe 3s ease-in-out infinite}
.tb-brand{font-size:14px;font-weight:900;color:var(--text);flex-shrink:0}
.tb-brand span{color:var(--neon)}
.tb-div{width:1px;height:24px;background:var(--bord);flex-shrink:0}
.tb-title{font-size:13px;font-weight:900;color:var(--text2);flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tb-sync{display:flex;align-items:center;gap:6px;padding:5px 11px;border-radius:20px;
  background:rgba(50,190,143,.06);border:1px solid rgba(50,190,143,.18);
  font-size:10px;font-weight:900;color:var(--muted);white-space:nowrap}
.tb-dot{width:7px;height:7px;border-radius:50%;background:var(--neon);position:relative;flex-shrink:0}
.tb-dot::after{content:'';position:absolute;inset:0;border-radius:50%;background:var(--neon);animation:ping 1.6s infinite}
.tb-dot.pulse{background:var(--gold)}
.tbtn{width:36px;height:36px;border-radius:9px;background:rgba(50,190,143,.05);border:1.5px solid var(--bord);
  display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:14px;
  color:var(--text2);transition:all .2s;flex-shrink:0;text-decoration:none}
.tbtn:hover{background:rgba(50,190,143,.13);color:var(--neon)}
.tbtn.notif-btn{position:relative}
.nbadge{position:absolute;top:-5px;right:-5px;min-width:16px;height:16px;border-radius:9px;
  padding:0 4px;background:var(--red);color:#fff;font-size:9px;font-weight:900;
  display:flex;align-items:center;justify-content:center;border:2px solid var(--bg);animation:pop .35s ease}
.tb-user{display:flex;align-items:center;gap:7px;padding:5px 11px;border-radius:20px;
  background:rgba(50,190,143,.05);border:1px solid var(--bord)}
.tb-av{width:26px;height:26px;border-radius:50%;background:linear-gradient(135deg,var(--neon),var(--cyan));
  display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:900;color:var(--bg)}
.tb-uname{font-size:12px;font-weight:900;color:var(--text)}

/* TAB NAV */
.tabnav{background:rgba(4,9,14,.95);border-bottom:1px solid rgba(50,190,143,.1);
  display:flex;overflow-x:auto;position:sticky;top:57px;z-index:290}
.tabnav::-webkit-scrollbar{display:none}
.tnav{display:flex;align-items:center;gap:7px;padding:13px 18px;border-bottom:3px solid transparent;
  cursor:pointer;font-size:13px;font-weight:900;color:var(--muted);white-space:nowrap;
  flex-shrink:0;transition:all .22s;text-decoration:none}
.tnav i{font-size:13px}
.tnbdg{background:var(--red);color:#fff;min-width:18px;height:18px;border-radius:9px;
  padding:0 5px;font-size:9px;display:flex;align-items:center;justify-content:center}
.tnav.on{color:var(--neon);border-bottom-color:var(--neon)}
.tnav:hover:not(.on){color:var(--text2)}

/* PANEL */
.panel{display:none;padding:16px;animation:fadeUp .28s ease}
.panel.on{display:block}

/* KPI */
.kpi-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:9px;margin-bottom:14px}
@media(min-width:700px){.kpi-grid{grid-template-columns:repeat(4,1fr)}}
.kpi{background:var(--card);border:1px solid var(--bord);border-radius:14px;padding:14px;
  position:relative;overflow:hidden;animation:fadeUp .4s ease backwards;cursor:default;
  transition:transform .22s}
.kpi::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--kc,var(--neon))}
.kpi:hover{transform:translateY(-2px)}
.kpi-ico{font-size:22px;margin-bottom:7px;display:block}
.kpi-val{font-size:26px;font-weight:900;color:var(--text);line-height:1;margin-bottom:3px}
.kpi-lbl{font-size:11px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:.8px}
.kpi-sub{font-size:11px;font-weight:700;color:var(--kc,var(--neon));margin-top:4px}

/* CHART */
.chart-card{background:var(--card);border:1px solid var(--bord);border-radius:14px;padding:14px;margin-bottom:14px}
.chart-ttl{font-size:13px;font-weight:900;color:var(--text2);margin-bottom:12px}
.bars{display:flex;align-items:flex-end;gap:6px;height:80px}
.bar-w{flex:1;display:flex;flex-direction:column;align-items:center;gap:3px;min-width:0}
.bar-v{font-size:10px;font-weight:900;color:var(--neon);text-align:center}
.bar{width:100%;background:linear-gradient(180deg,var(--neon),rgba(50,190,143,.2));
  border-radius:5px 5px 0 0;min-height:3px;transition:height .55s cubic-bezier(.23,1,.32,1);cursor:pointer}
.bar:hover{background:linear-gradient(180deg,var(--neon2),var(--neon))}
.bar-l{font-size:9px;font-weight:900;color:var(--muted);text-align:center}

/* FILTERS */
.filters{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:11px}
.srch{display:flex;align-items:center;gap:8px;flex:1;min-width:200px;
  background:rgba(0,0,0,.3);border:1.5px solid var(--bord);border-radius:11px;
  padding:10px 13px;transition:border-color .2s,box-shadow .2s}
.srch:focus-within{border-color:var(--neon);box-shadow:var(--gn)}
.srch i{color:rgba(50,190,143,.45);font-size:14px;flex-shrink:0}
.srch input{background:none;border:none;outline:none;width:100%;
  font-family:var(--fh);font-size:14px;font-weight:700;color:var(--text)}
.srch input::placeholder{color:var(--muted)}
.fsel{padding:10px 12px;background:rgba(0,0,0,.3);border:1.5px solid var(--bord);border-radius:11px;
  font-family:var(--fh);font-size:13px;font-weight:900;color:var(--text);cursor:pointer;
  appearance:none;transition:border-color .2s}
.fsel:focus{outline:none;border-color:var(--neon)}
.fsel option{background:#0c1b28}

/* STATUS PILLS */
.pills{display:flex;gap:6px;overflow-x:auto;padding-bottom:10px;margin-bottom:11px;-webkit-overflow-scrolling:touch}
.pills::-webkit-scrollbar{height:3px}
.pills::-webkit-scrollbar-thumb{background:var(--bord);border-radius:2px}
.pill{display:flex;align-items:center;gap:6px;padding:8px 14px;border-radius:20px;
  border:1.5px solid transparent;background:rgba(0,0,0,.28);cursor:pointer;
  font-size:12px;font-weight:900;white-space:nowrap;flex-shrink:0;transition:all .22s;color:var(--muted)}
.pill .pc{font-size:10px;background:rgba(255,255,255,.05);padding:1px 6px;border-radius:7px}
.pill.on{color:var(--text)}
.pill[data-s="all"].on{border-color:rgba(50,190,143,.38);background:rgba(50,190,143,.1);color:var(--neon)}
.pill[data-s="pending"].on{border-color:rgba(255,208,96,.38);background:rgba(255,208,96,.1);color:var(--gold)}
.pill[data-s="confirmed"].on{border-color:rgba(6,182,212,.38);background:rgba(6,182,212,.1);color:var(--cyan)}
.pill[data-s="delivering"].on{border-color:rgba(50,190,143,.38);background:rgba(50,190,143,.1);color:var(--neon)}
.pill[data-s="done"].on{border-color:rgba(61,140,255,.38);background:rgba(61,140,255,.1);color:var(--blue)}
.pill[data-s="cancelled"].on{border-color:rgba(255,53,83,.38);background:rgba(255,53,83,.1);color:var(--red)}

/* BULK */
.bulk{display:none;align-items:center;gap:8px;padding:9px 12px;border-radius:11px;
  background:rgba(50,190,143,.06);border:1.5px solid rgba(50,190,143,.2);margin-bottom:10px;
  animation:slideUp .22s ease;flex-wrap:wrap}
.bulk.on{display:flex}
.bulk-cnt{font-size:13px;font-weight:900;color:var(--neon)}
.bsel{padding:6px 10px;background:rgba(0,0,0,.3);border:1.5px solid var(--bord);border-radius:9px;
  font-family:var(--fh);font-size:12px;font-weight:900;color:var(--text);cursor:pointer;appearance:none}
.bgo{padding:6px 13px;border-radius:9px;border:none;cursor:pointer;
  font-family:var(--fh);font-size:12px;font-weight:900;
  background:linear-gradient(135deg,var(--neon),var(--cyan));color:var(--bg)}

/* ORDER CARD */
.ocard{background:var(--card);border:1px solid var(--bord);border-radius:13px;
  margin-bottom:8px;overflow:hidden;transition:border-color .28s;
  animation:fadeUp .32s ease backwards}
.ocard:hover{border-color:rgba(50,190,143,.24)}
.ocard.sel{border-color:rgba(50,190,143,.38);background:rgba(50,190,143,.03)}
.ocard.flash{animation:glow .6s ease}
.oc-top{display:flex;align-items:center;gap:9px;padding:13px 14px;cursor:pointer}
.oc-chk{width:20px;height:20px;border-radius:6px;flex-shrink:0;border:2px solid var(--bord);
  background:rgba(0,0,0,.28);display:flex;align-items:center;justify-content:center;
  cursor:pointer;transition:all .2s;font-size:12px}
.oc-chk.on{background:var(--neon);border-color:var(--neon);color:var(--bg)}
.oc-num{font-size:12px;font-weight:900;color:var(--gold);flex-shrink:0;min-width:110px;letter-spacing:.4px}
.oc-cli{flex:1;min-width:0}
.oc-cname{font-size:14px;font-weight:900;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.oc-ctel{font-size:11px;font-weight:700;color:var(--muted);margin-top:2px}
.oc-amt{font-size:15px;font-weight:900;color:var(--neon);flex-shrink:0}
.oc-amt small{font-size:10px;color:var(--muted)}
.oc-date{font-size:10px;font-weight:700;color:var(--muted);flex-shrink:0;text-align:right;min-width:50px}
.oc-meta{display:flex;align-items:center;gap:6px;padding:8px 14px;
  border-top:1px solid rgba(255,255,255,.04);background:rgba(0,0,0,.1);flex-wrap:wrap}
.oc-body{padding:14px;border-top:1px solid rgba(255,255,255,.04);display:none;background:rgba(0,0,0,.07)}
.oc-body.on{display:block;animation:fadeIn .2s ease}

/* STATUS */
.sbdg{font-size:11px;font-weight:900;padding:4px 10px;border-radius:10px;
  display:inline-flex;align-items:center;gap:4px;flex-shrink:0}
.s-pending   {background:rgba(255,208,96,.1);color:var(--gold);border:1px solid rgba(255,208,96,.2)}
.s-confirmed {background:rgba(6,182,212,.1);color:var(--cyan);border:1px solid rgba(6,182,212,.2)}
.s-delivering{background:rgba(50,190,143,.1);color:var(--neon);border:1px solid rgba(50,190,143,.2)}
.s-done      {background:rgba(61,140,255,.1);color:var(--blue);border:1px solid rgba(61,140,255,.2)}
.s-cancelled {background:rgba(255,53,83,.1);color:var(--red);border:1px solid rgba(255,53,83,.2)}
.ssel{padding:5px 9px;border-radius:9px;border:1.5px solid var(--bord);background:rgba(0,0,0,.3);
  font-family:var(--fh);font-size:12px;font-weight:900;color:var(--text);cursor:pointer;
  appearance:none;transition:border-color .2s}
.ssel:focus{outline:none;border-color:var(--neon)}
.ssel option{background:#0c1b28}
.mpill{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:8px;
  font-size:10px;font-weight:900;background:rgba(0,0,0,.2);border:1px solid rgba(255,255,255,.05);color:var(--muted)}

/* ITEMS */
.items-box{margin:9px 0}
.irow{display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:9px;
  background:rgba(0,0,0,.15);border:1px solid rgba(255,255,255,.04);margin-bottom:5px}
.irow-ico{font-size:18px;flex-shrink:0}
.irow-name{font-size:13px;font-weight:900;color:var(--text);flex:1;min-width:0;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.irow-qty{font-size:11px;font-weight:700;color:var(--muted);flex-shrink:0}
.irow-sub{font-size:13px;font-weight:900;color:var(--neon);flex-shrink:0}
.total-row{display:flex;align-items:center;justify-content:space-between;
  padding:10px 13px;border-radius:10px;margin-top:8px;
  background:rgba(50,190,143,.06);border:1.5px solid rgba(50,190,143,.15)}
.total-lbl{font-size:10px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:1.5px}
.total-val{font-size:18px;font-weight:900;color:var(--neon)}
.oc-actions{display:flex;gap:6px;margin-top:9px;flex-wrap:wrap}

/* BTNS */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;
  padding:9px 15px;border-radius:10px;border:1.5px solid transparent;cursor:pointer;
  font-family:var(--fh);font-size:12px;font-weight:900;letter-spacing:.3px;
  transition:all .2s;white-space:nowrap;text-decoration:none}
.btn:active{transform:scale(.95)}
.btn-n{background:rgba(50,190,143,.08);border-color:rgba(50,190,143,.22);color:var(--neon)}
.btn-n:hover{background:var(--neon);color:var(--bg)}
.btn-r{background:rgba(255,53,83,.08);border-color:rgba(255,53,83,.22);color:var(--red)}
.btn-r:hover{background:var(--red);color:#fff}
.btn-b{background:rgba(61,140,255,.08);border-color:rgba(61,140,255,.22);color:var(--blue)}
.btn-b:hover{background:var(--blue);color:#fff}
.btn-g{background:rgba(255,208,96,.08);border-color:rgba(255,208,96,.22);color:var(--gold)}
.btn-g:hover{background:var(--gold);color:var(--bg)}
.btn-solid{background:linear-gradient(135deg,var(--neon),var(--cyan));color:var(--bg);border:none;box-shadow:var(--gn)}
.btn-print{background:linear-gradient(135deg,#ff9140,#ffd060);color:var(--bg);border:none;font-size:13px;padding:9px 18px}
.btn-sm{padding:6px 12px;font-size:11px}
.btn-full{width:100%}

/* TIMELINE */
.tline{display:flex;align-items:center;padding:12px 2px;position:relative}
.tline::before{content:'';position:absolute;top:22px;left:16px;right:16px;height:2px;background:rgba(255,255,255,.05);z-index:0}
.tl-s{display:flex;flex-direction:column;align-items:center;gap:5px;flex:1;position:relative;z-index:1}
.tl-d{width:28px;height:28px;border-radius:50%;border:2px solid rgba(255,255,255,.07);
  background:var(--card2);display:flex;align-items:center;justify-content:center;font-size:11px;transition:all .4s}
.tl-d.done{background:var(--neon);border-color:var(--neon);color:var(--bg)}
.tl-d.cur{background:linear-gradient(135deg,var(--neon),var(--cyan));border-color:var(--neon);
  color:var(--bg);box-shadow:0 0 14px rgba(50,190,143,.55);animation:breathe 2s infinite}
.tl-d.can{background:var(--red);border-color:var(--red);color:#fff}
.tl-l{font-size:9px;font-weight:900;color:var(--muted);text-align:center;max-width:55px}
.tl-l.done,.tl-l.cur{color:var(--neon)}
.tl-l.can{color:var(--red)}
.tl-line{height:2px;flex:1;margin-top:-20px;z-index:0;transition:background .4s}
.tl-line.done{background:var(--neon)}
.tl-line.off{background:rgba(255,255,255,.05)}

/* LOG */
.log-row{display:flex;align-items:flex-start;gap:9px;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.04)}
.log-row:last-child{border:none}
.log-dot{width:8px;height:8px;border-radius:50%;background:var(--neon);flex-shrink:0;margin-top:3px}
.log-txt{font-size:12px;font-weight:700;color:var(--text2)}
.log-time{font-size:10px;font-weight:700;color:var(--muted);margin-top:2px}

/* MODAL */
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.87);z-index:500;
  align-items:flex-start;justify-content:center;padding:14px;
  backdrop-filter:blur(12px);overflow-y:auto}
.modal.on{display:flex;animation:fadeIn .2s ease}
.mbox{background:var(--card);border:1px solid var(--bord);border-radius:18px;
  width:100%;max-width:600px;margin:auto;box-shadow:0 24px 64px rgba(0,0,0,.8);
  overflow:hidden;position:relative}
.mbox::before{content:'';position:absolute;top:0;left:-80%;width:50%;height:2px;
  background:linear-gradient(90deg,transparent,var(--neon),transparent);animation:scan 3.5s linear infinite}
.mhead{display:flex;align-items:center;justify-content:space-between;gap:9px;
  padding:14px 17px;border-bottom:1px solid rgba(255,255,255,.05);background:rgba(0,0,0,.2)}
.mttl{font-size:14px;font-weight:900;color:var(--text)}
.mclose{width:30px;height:30px;border-radius:50%;background:rgba(255,53,83,.1);
  border:1.5px solid rgba(255,53,83,.22);color:var(--red);display:flex;align-items:center;
  justify-content:center;cursor:pointer;font-size:17px;transition:all .2s}
.mclose:active{background:var(--red);color:#fff}
.mbody{padding:17px;max-height:86vh;overflow-y:auto}

/* PRINT MODAL */
.pmodal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.92);z-index:600;
  align-items:flex-start;justify-content:center;padding:14px;
  backdrop-filter:blur(16px);overflow-y:auto}
.pmodal.on{display:flex;animation:fadeIn .2s ease}
.pbox{background:var(--card);border:1px solid rgba(255,145,64,.3);border-radius:18px;
  width:100%;max-width:900px;margin:auto;box-shadow:0 24px 64px rgba(0,0,0,.9);overflow:hidden}
.phead{display:flex;align-items:center;justify-content:space-between;gap:9px;
  padding:14px 17px;border-bottom:1px solid rgba(255,255,255,.05);background:rgba(255,145,64,.07)}
.phead-ttl{font-size:15px;font-weight:900;color:var(--orange)}
.pbody{padding:18px;max-height:80vh;overflow-y:auto}
.p-filters{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px;padding:12px;
  background:rgba(0,0,0,.2);border:1px solid var(--bord);border-radius:12px}
.p-filters label{font-size:10px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;display:block;margin-bottom:4px}
.p-filters select,.p-filters input{font-family:var(--fh);font-size:13px;font-weight:700;color:var(--text);
  background:rgba(0,0,0,.3);border:1.5px solid var(--bord);border-radius:9px;padding:8px 10px;width:100%}
.p-filters select:focus,.p-filters input:focus{outline:none;border-color:var(--orange)}
.p-filters select option{background:#0c1b28}
.p-fg{flex:1;min-width:150px}
.p-actions{display:flex;align-items:center;gap:8px;margin-bottom:14px;flex-wrap:wrap}
.p-summary{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}
.p-sum-it{background:rgba(0,0,0,.2);border:1px solid var(--bord);border-radius:10px;
  padding:9px 14px;text-align:center;flex:1;min-width:90px}
.p-sum-v{font-size:20px;font-weight:900;color:var(--neon)}
.p-sum-l{font-size:9px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:.8px}
.print-order{border:1px solid var(--bord);border-radius:12px;margin-bottom:10px;
  overflow:hidden;background:rgba(0,0,0,.12)}
.po-head{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:6px;
  padding:11px 14px;background:rgba(0,0,0,.2);border-bottom:1px solid rgba(255,255,255,.05)}
.po-num{font-size:14px;font-weight:900;color:var(--gold)}
.po-body{padding:12px 14px}
.po-cli{display:flex;align-items:center;gap:8px;margin-bottom:10px;padding:9px 11px;
  background:rgba(50,190,143,.04);border:1px solid rgba(50,190,143,.1);border-radius:9px}
.po-av{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--neon),var(--cyan));
  display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:900;color:var(--bg);flex-shrink:0}
.po-cname{font-size:14px;font-weight:900;color:var(--text)}
.po-ctel{font-size:12px;color:var(--muted)}
.po-addr{font-size:12px;font-weight:700;color:var(--text2);padding:7px 10px;
  background:rgba(0,0,0,.15);border-radius:8px;margin-bottom:9px;border:1px solid rgba(255,255,255,.05)}
.po-items{width:100%;border-collapse:collapse;font-size:12px;margin-bottom:8px}
.po-items th{font-size:10px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;
  padding:6px 8px;text-align:left;border-bottom:1px solid rgba(255,255,255,.07)}
.po-items td{padding:7px 8px;border-bottom:1px solid rgba(255,255,255,.04);font-weight:700;color:var(--text2)}
.po-items tr:last-child td{border:none}
.po-items td.right{text-align:right;font-weight:900;color:var(--neon)}
.po-total{display:flex;align-items:center;justify-content:space-between;
  padding:9px 12px;background:rgba(50,190,143,.08);border:1.5px solid rgba(50,190,143,.2);border-radius:9px}
.po-total-lbl{font-size:12px;font-weight:900;color:var(--muted);text-transform:uppercase}
.po-total-v{font-size:20px;font-weight:900;color:var(--neon)}
.po-note{margin-top:7px;padding:7px 10px;background:rgba(255,208,96,.05);
  border:1px solid rgba(255,208,96,.15);border-radius:8px;font-size:11px;color:var(--gold)}

/* CONFIRM */
.cmodal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.9);z-index:700;
  align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(12px)}
.cmodal.on{display:flex;animation:fadeIn .2s ease}
.cbox{background:var(--card);border:1px solid rgba(255,53,83,.25);border-radius:17px;
  width:100%;max-width:320px;padding:24px;text-align:center;box-shadow:0 22px 56px rgba(0,0,0,.8)}
.cbox-ico{font-size:44px;display:block;margin-bottom:10px}
.cbox-ttl{font-size:17px;font-weight:900;color:var(--text);margin-bottom:6px}
.cbox-sub{font-size:12px;font-weight:700;color:var(--muted);margin-bottom:16px;line-height:1.7}
.cbox-btns{display:flex;gap:8px}

/* NOTIFS */
.n-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:13px}
.nstat{background:var(--card);border:1px solid var(--bord);border-radius:11px;padding:10px 9px;text-align:center}
.nstat-v{font-size:20px;font-weight:900;color:var(--neon)}
.nstat-l{font-size:9px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-top:3px}
.n-toolbar{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:11px;flex-wrap:wrap}
.n-ttl{font-size:14px;font-weight:900;color:var(--text)}
.n-filters{display:flex;gap:5px;overflow-x:auto;padding-bottom:9px;margin-bottom:11px}
.n-filters::-webkit-scrollbar{display:none}
.nfpill{padding:6px 13px;border-radius:20px;border:1.5px solid var(--bord);background:rgba(0,0,0,.28);
  cursor:pointer;font-size:11px;font-weight:900;color:var(--muted);white-space:nowrap;
  flex-shrink:0;transition:all .2s}
.nfpill.on{background:rgba(50,190,143,.1);border-color:rgba(50,190,143,.28);color:var(--neon)}
.add-n-card{background:var(--card);border:1px solid var(--bord);border-radius:13px;padding:14px;margin-bottom:13px}
.add-n-ttl{font-size:13px;font-weight:900;color:var(--text2);margin-bottom:11px;display:flex;align-items:center;gap:7px}
.fg{margin-bottom:9px}
.fg label{font-size:10px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:1px;display:block;margin-bottom:5px}
.fg input,.fg select,.fg textarea{width:100%;font-family:var(--fh);font-size:13px;font-weight:700;
  color:var(--text);background:rgba(0,0,0,.28);border:1.5px solid var(--bord);
  border-radius:10px;padding:10px 12px;transition:border-color .2s;appearance:none}
.fg textarea{resize:vertical;min-height:72px}
.fg input:focus,.fg select:focus,.fg textarea:focus{outline:none;border-color:var(--neon)}
.fg input::placeholder,.fg textarea::placeholder{color:var(--muted)}
.fg select option{background:#0c1b28}
.fg-row{display:grid;grid-template-columns:1fr 1fr;gap:9px;margin-bottom:9px}
.ncard{background:var(--card);border:1px solid var(--bord);border-radius:12px;
  margin-bottom:8px;overflow:hidden;transition:border-color .25s;
  animation:slideUp .28s ease backwards}
.ncard.unread{border-color:rgba(50,190,143,.2);background:rgba(50,190,143,.035)}
.ncard:hover{border-color:rgba(50,190,143,.28)}
.nc-main{display:flex;align-items:flex-start;gap:10px;padding:12px 14px;cursor:pointer}
.nc-ico{width:36px;height:36px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:19px}
.nc-ico.new_order   {background:rgba(50,190,143,.13)}
.nc-ico.status_change{background:rgba(61,140,255,.13)}
.nc-ico.payment     {background:rgba(255,208,96,.13)}
.nc-ico.info        {background:rgba(167,139,250,.13)}
.nc-ico.alert       {background:rgba(255,53,83,.13)}
.nc-cont{flex:1;min-width:0}
.nc-title{font-size:13px;font-weight:900;color:var(--text);margin-bottom:4px}
.ncard.unread .nc-title{color:var(--neon)}
.nc-msg{font-size:12px;font-weight:700;color:var(--muted);line-height:1.55}
.nc-meta{display:flex;align-items:center;flex-wrap:wrap;gap:6px;margin-top:6px}
.nc-time{font-size:10px;font-weight:700;color:var(--muted)}
.nc-tag{display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:7px;
  font-size:9px;font-weight:900;background:rgba(0,0,0,.18);border:1px solid rgba(255,255,255,.05);color:var(--muted)}
.nc-udot{width:8px;height:8px;border-radius:50%;background:var(--neon);flex-shrink:0;margin-top:3px;animation:ping 2s infinite}
.nc-actions{display:flex;align-items:center;gap:6px;padding:8px 14px;
  border-top:1px solid rgba(255,255,255,.04);background:rgba(0,0,0,.08);flex-wrap:wrap}

/* PAGER */
.pager{display:flex;align-items:center;justify-content:center;gap:6px;padding:13px 0}
.pp{width:32px;height:32px;border-radius:8px;border:1.5px solid var(--bord);background:rgba(0,0,0,.2);
  color:var(--muted);display:flex;align-items:center;justify-content:center;cursor:pointer;
  font-size:13px;font-weight:900;transition:all .2s}
.pp:hover{border-color:rgba(50,190,143,.26);color:var(--text)}
.pp.on{background:rgba(50,190,143,.12);border-color:rgba(50,190,143,.35);color:var(--neon)}

/* EMPTY / SPINNER */
.empty{text-align:center;padding:50px 16px}
.empty i{font-size:46px;display:block;margin-bottom:13px;opacity:.08}
.empty h3{font-size:15px;font-weight:900;color:var(--text2);margin-bottom:5px}
.empty p{font-size:12px;font-weight:700;color:var(--muted)}
.sp{width:14px;height:14px;border:2px solid rgba(255,255,255,.12);border-top-color:currentColor;
  border-radius:50%;animation:spin .65s linear infinite;display:inline-block;vertical-align:middle}

/* TOAST */
.tstack{position:fixed;top:14px;right:14px;z-index:9999;
  display:flex;flex-direction:column;gap:7px;pointer-events:none;
  max-width:340px;width:calc(100vw - 28px)}
.tst{background:var(--card2);border:1px solid rgba(50,190,143,.18);border-radius:13px;
  padding:12px 14px;display:flex;align-items:flex-start;gap:9px;
  box-shadow:0 8px 26px rgba(0,0,0,.6);animation:toastIn .35s cubic-bezier(.23,1,.32,1);
  pointer-events:all;cursor:pointer;position:relative;overflow:hidden}
.tst.err{border-color:rgba(255,53,83,.25)}
.tst.warn{border-color:rgba(255,208,96,.25)}
.tst.neworder{border-color:rgba(50,190,143,.42);background:linear-gradient(135deg,rgba(50,190,143,.08),rgba(6,182,212,.04))}
.tst-bar{position:absolute;bottom:0;left:0;height:2px;width:100%}
.tst-ico{width:30px;height:30px;border-radius:8px;flex-shrink:0;
  display:flex;align-items:center;justify-content:center;font-size:16px;background:rgba(50,190,143,.1)}
.tst.err .tst-ico{background:rgba(255,53,83,.1)}
.tst.warn .tst-ico{background:rgba(255,208,96,.1)}
.tst-c{flex:1;min-width:0}
.tst-title{font-size:13px;font-weight:900;color:var(--text);margin-bottom:1px}
.tst-sub{font-size:11px;font-weight:700;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tst-x{position:absolute;top:8px;right:9px;font-size:15px;color:var(--muted);cursor:pointer}
.tst-x:hover{color:var(--red)}

::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-track{background:rgba(0,0,0,.07)}
::-webkit-scrollbar-thumb{background:rgba(50,190,143,.18);border-radius:3px}
@media(max-width:540px){.oc-date{display:none}}

/* ══════════════════════════════════════════ */
/* ZONE D'IMPRESSION — invisible à l'écran, visible uniquement à l'impression */
/* ══════════════════════════════════════════ */
#printzone { display:none; }

@media print {
  /* Cacher TOUT sauf la zone d'impression */
  body * { visibility:hidden !important; }
  #printzone, #printzone * { visibility:visible !important; }
  #printzone {
    display:block !important;
    position:fixed !important;
    top:0 !important; left:0 !important;
    width:100% !important;
    background:#fff !important;
    color:#000 !important;
    font-family:Arial,sans-serif !important;
    font-size:11pt !important;
    padding:10mm !important;
    box-sizing:border-box !important;
  }
  .pz-header { text-align:center; padding-bottom:8pt; border-bottom:2pt solid #007a44; margin-bottom:12pt; }
  .pz-header h1 { font-size:18pt; color:#007a44; margin:0 0 4pt; }
  .pz-header p { font-size:9pt; color:#555; margin:0; }
  .print-order {
    border:1.5pt solid #007a44 !important;
    border-radius:0 !important;
    margin-bottom:14pt !important;
    page-break-inside:avoid !important;
    background:#fff !important;
    overflow:visible !important;
  }
  .po-head {
    background:#e8f5ee !important;
    border-bottom:1pt solid #ccc !important;
    padding:7pt 9pt !important;
    display:flex !important;
    justify-content:space-between !important;
    flex-wrap:wrap !important;
    gap:4pt !important;
  }
  .po-num { color:#000 !important; font-size:13pt !important; font-weight:900 !important; }
  .sbdg { border:1pt solid #999 !important; background:#eee !important; color:#000 !important; font-size:9pt !important; padding:2pt 6pt !important; border-radius:4pt !important; }
  .po-body { padding:8pt 10pt !important; }
  .po-cli {
    background:#f5f5f5 !important; border:1pt solid #ddd !important;
    border-radius:4pt !important; padding:6pt 9pt !important;
    margin-bottom:8pt !important; display:flex !important; gap:8pt !important; align-items:center !important;
  }
  .po-av { background:#007a44 !important; color:#fff !important; width:28pt !important; height:28pt !important; border-radius:50% !important; display:flex !important; align-items:center !important; justify-content:center !important; font-size:13pt !important; font-weight:900 !important; flex-shrink:0 !important; }
  .po-cname { color:#000 !important; font-size:12pt !important; font-weight:900 !important; }
  .po-ctel { color:#444 !important; font-size:10pt !important; }
  .po-addr { background:#fffbe6 !important; border:1pt solid #ccc !important; border-radius:4pt !important; padding:6pt 9pt !important; margin-bottom:8pt !important; color:#000 !important; font-size:10pt !important; }
  .po-items { width:100% !important; border-collapse:collapse !important; font-size:10pt !important; margin-bottom:8pt !important; }
  .po-items th { background:#007a44 !important; color:#fff !important; padding:5pt 7pt !important; font-size:9pt !important; text-align:left !important; }
  .po-items th:nth-child(2) { text-align:center !important; }
  .po-items th:nth-child(3),.po-items th:nth-child(4) { text-align:right !important; }
  .po-items td { padding:5pt 7pt !important; border-bottom:0.5pt solid #ddd !important; color:#000 !important; }
  .po-items tr:last-child td { border-bottom:none !important; }
  .po-items td.right { text-align:right !important; font-weight:900 !important; color:#007a44 !important; }
  .po-total { background:#e8f5ee !important; border:2pt solid #007a44 !important; border-radius:4pt !important; padding:8pt 12pt !important; display:flex !important; justify-content:space-between !important; align-items:center !important; margin-top:4pt !important; }
  .po-total-lbl { color:#333 !important; font-size:11pt !important; font-weight:900 !important; text-transform:uppercase !important; }
  .po-total-v { color:#007a44 !important; font-size:20pt !important; font-weight:900 !important; }
  .po-note { background:#fffce0 !important; border:1pt solid #ccc !important; border-radius:4pt !important; padding:5pt 8pt !important; color:#555 !important; font-size:9pt !important; margin-top:5pt !important; }
  .mpill { background:#eee !important; color:#333 !important; border:1pt solid #ccc !important; padding:2pt 6pt !important; border-radius:4pt !important; font-size:8pt !important; display:inline-flex !important; gap:3pt !important; }
  .pz-summary { background:#f0f0f0 !important; border:1pt solid #ccc !important; padding:7pt 10pt !important; margin-bottom:12pt !important; border-radius:4pt !important; display:flex !important; gap:14pt !important; flex-wrap:wrap !important; }
  .pz-sum-item { text-align:center !important; }
  .pz-sum-v { font-size:16pt !important; font-weight:900 !important; color:#007a44 !important; }
  .pz-sum-l { font-size:8pt !important; color:#555 !important; text-transform:uppercase !important; }
}
</style>
</head>
<body>
<div class="wrap">

<!-- TOPBAR -->
<div class="topbar">
  <div class="tb-logo">💧</div>
  <div class="tb-brand"><span>ESPERANCE</span> H2O</div>
  <div class="tb-div"></div>
  <div class="tb-title" id="tb-title">Dashboard Commandes</div>
  <div class="tb-sync"><div class="tb-dot" id="tb-dot"></div><span id="tb-time">Live</span></div>
  <div class="tbtn" onclick="doRefresh()" title="Actualiser"><i class="fas fa-sync-alt" id="ri"></i></div>
  <div class="tbtn" id="xl-btn" onclick="exportExcel()" title="Export Excel — Bons de livraison" style="font-size:15px">📊</div>
  <div class="tb-user"><div class="tb-av"><?= strtoupper(mb_substr($admin_name,0,1)) ?></div><div class="tb-uname"><?= htmlspecialchars(mb_substr($admin_name,0,14)) ?></div></div>
  <a href="login_unified.php?logout=1" class="tbtn" style="color:rgba(255,53,83,.65)"><i class="fas fa-sign-out-alt"></i></a>
</div>

<!-- TABNAV -->
<div class="tabnav">
  <div class="tnav on" id="tnav-orders" onclick="goTab('orders')"><i class="fas fa-box"></i> Commandes <span class="tnbdg" id="tnb-pending" style="display:none">0</span></div>
  <div class="tnav" id="tnav-stats" onclick="goTab('stats')"><i class="fas fa-chart-bar"></i> Statistiques</div>
  <div class="tnav" id="tnav-notifs" onclick="goTab('notifs')"><i class="fas fa-bell"></i> Notifs Caisse <span class="tnbdg" id="tnb-notifs" style="<?= $unread_cnt>0?'':'display:none' ?>"><?= $unread_cnt ?></span></div>
  <a class="tnav" href="/../dashboard/index.php"><i class="fas fa-gauge-high"></i> Dashboard</a>
</div>

<!-- PANEL ORDERS -->
<div class="panel on" id="panel-orders">
  <div class="kpi-grid">
    <div class="kpi" style="--kc:var(--gold);animation-delay:.00s"><span class="kpi-ico">⏳</span><div class="kpi-val" id="kv-pending">—</div><div class="kpi-lbl">En attente</div></div>
    <div class="kpi" style="--kc:var(--cyan);animation-delay:.05s"><span class="kpi-ico">✅</span><div class="kpi-val" id="kv-confirmed">—</div><div class="kpi-lbl">Confirmées</div></div>
    <div class="kpi" style="--kc:var(--neon);animation-delay:.10s"><span class="kpi-ico">🚚</span><div class="kpi-val" id="kv-delivering">—</div><div class="kpi-lbl">En livraison</div></div>
    <div class="kpi" style="--kc:var(--blue);animation-delay:.15s"><span class="kpi-ico">🎉</span><div class="kpi-val" id="kv-done">—</div><div class="kpi-lbl">Livrées</div></div>
    <div class="kpi" style="--kc:var(--neon);animation-delay:.20s"><span class="kpi-ico">💰</span><div class="kpi-val" id="kv-rev">—</div><div class="kpi-lbl">CA Total CFA</div></div>
    <div class="kpi" style="--kc:var(--purple);animation-delay:.25s"><span class="kpi-ico">📅</span><div class="kpi-val" id="kv-today">—</div><div class="kpi-lbl">Aujourd'hui</div><div class="kpi-sub" id="ks-today"></div></div>
    <div class="kpi" style="--kc:var(--orange);animation-delay:.30s"><span class="kpi-ico">📋</span><div class="kpi-val" id="kv-total">—</div><div class="kpi-lbl">Total commandes</div></div>
    <div class="kpi" style="--kc:var(--red);animation-delay:.35s"><span class="kpi-ico">❌</span><div class="kpi-val" id="kv-cancelled">—</div><div class="kpi-lbl">Annulées</div></div>
  </div>
  <div class="filters">
    <div class="srch"><i class="fas fa-search"></i><input type="text" id="s-inp" placeholder="N° commande, client, téléphone…" oninput="debS()"></div>
    <select class="fsel" id="f-co" onchange="doLoad(true)">
      <option value="">🏢 Toutes sociétés</option>
      <?php foreach($companies as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
    </select>
    <select class="fsel" id="f-ci" onchange="doLoad(true)">
      <option value="">🏙️ Toutes villes</option>
      <?php foreach($cities as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="pills">
    <div class="pill on" data-s="all" onclick="setSt('',this)"><i class="fas fa-list"></i> Tout <span class="pc" id="pc-all">0</span></div>
    <div class="pill" data-s="pending" onclick="setSt('pending',this)"><i class="fas fa-clock"></i> Attente <span class="pc" id="pc-pend">0</span></div>
    <div class="pill" data-s="confirmed" onclick="setSt('confirmed',this)"><i class="fas fa-check"></i> Confirmées <span class="pc" id="pc-conf">0</span></div>
    <div class="pill" data-s="delivering" onclick="setSt('delivering',this)"><i class="fas fa-truck"></i> Livraison <span class="pc" id="pc-deli">0</span></div>
    <div class="pill" data-s="done" onclick="setSt('done',this)"><i class="fas fa-circle-check"></i> Livrées <span class="pc" id="pc-done">0</span></div>
    <div class="pill" data-s="cancelled" onclick="setSt('cancelled',this)"><i class="fas fa-ban"></i> Annulées <span class="pc" id="pc-canc">0</span></div>
  </div>
  <div class="bulk" id="bulk"><span class="bulk-cnt" id="bulk-cnt">0 sél.</span>
    <select class="bsel" id="bulk-sel"><option value="">Statut…</option><option value="confirmed">✅ Confirmer</option><option value="delivering">🚚 Expédier</option><option value="done">🎉 Livré</option><option value="cancelled">❌ Annuler</option></select>
    <button class="bgo" onclick="applyBulk()"><i class="fas fa-check"></i> Go</button>
    <button class="bgo" style="background:rgba(255,53,83,.2);color:var(--red)" onclick="clearSel()"><i class="fas fa-times"></i></button>
  </div>
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:9px;flex-wrap:wrap;gap:8px">
    <div style="font-size:13px;font-weight:900;color:var(--text2)"><i class="fas fa-box" style="color:var(--neon)"></i> Commandes <span style="color:var(--muted)" id="o-cnt"></span></div>
    <button class="btn btn-print" onclick="exportExcel()"><i class="fas fa-file-excel"></i> Exporter Excel (.xlsx)</button>
  </div>
  <div id="olist"><div class="empty"><i class="fas fa-spinner fa-spin" style="opacity:.2;font-size:40px"></i><h3>Chargement…</h3></div></div>
  <div class="pager" id="pager"></div>
</div>

<!-- PANEL STATS -->
<div class="panel" id="panel-stats">
  <div class="chart-card">
    <div class="chart-ttl">📊 Commandes — 7 derniers jours</div>
    <div class="bars" id="chart-bars"><div class="empty" style="width:100%"><i class="fas fa-spinner fa-spin" style="opacity:.18;font-size:24px"></i></div></div>
  </div>
  <div id="stats-extra"></div>
</div>

<!-- PANEL NOTIFS -->
<div class="panel" id="panel-notifs">
  <div class="n-stats">
    <div class="nstat"><div class="nstat-v" id="ns-total">—</div><div class="nstat-l">Total</div></div>
    <div class="nstat"><div class="nstat-v" id="ns-unread" style="color:var(--red)">—</div><div class="nstat-l">Non lues</div></div>
    <div class="nstat"><div class="nstat-v" id="ns-orders" style="color:var(--gold)">—</div><div class="nstat-l">Nouvelles cmds</div></div>
  </div>
  <div class="n-toolbar">
    <div class="n-ttl">🔔 Notifications Caisse <span style="font-size:11px;color:var(--muted)" id="n-last"></span></div>
    <div style="display:flex;gap:6px;flex-wrap:wrap">
      <button class="btn btn-n btn-sm" onclick="markAllRead()"><i class="fas fa-check-double"></i> Tout lire</button>
      <button class="btn btn-sm" style="background:rgba(255,53,83,.07);border-color:rgba(255,53,83,.2);color:var(--red)" onclick="clearRead()"><i class="fas fa-trash"></i> Vider lues</button>
      <button class="btn btn-n btn-sm" onclick="loadNotifs(true)"><i class="fas fa-sync-alt"></i></button>
    </div>
  </div>
  <div class="n-filters">
    <div class="nfpill on" data-nf="all" onclick="setNF('all',this)">Toutes</div>
    <div class="nfpill" data-nf="new_order" onclick="setNF('new_order',this)">🛎️ Nouvelles cmds</div>
    <div class="nfpill" data-nf="status_change" onclick="setNF('status_change',this)">🔄 Statuts</div>
    <div class="nfpill" data-nf="payment" onclick="setNF('payment',this)">💳 Paiements</div>
    <div class="nfpill" data-nf="alert" onclick="setNF('alert',this)">🚨 Alertes</div>
    <div class="nfpill" data-nf="info" onclick="setNF('info',this)">💡 Infos</div>
  </div>
  <div class="add-n-card">
    <div class="add-n-ttl"><i class="fas fa-plus-circle" style="color:var(--neon)"></i> Créer notification manuelle</div>
    <div class="fg-row">
      <div class="fg" style="margin:0"><label>Type</label><select id="nn-type"><option value="info">💡 Info</option><option value="alert">🚨 Alerte</option><option value="payment">💳 Paiement</option></select></div>
      <div class="fg" style="margin:0"><label>Titre *</label><input type="text" id="nn-title" placeholder="Titre…"></div>
    </div>
    <div class="fg"><label>Message *</label><textarea id="nn-msg" placeholder="Détails de la notification…"></textarea></div>
    <button class="btn btn-solid btn-full" onclick="addNotif()"><i class="fas fa-paper-plane"></i> ENVOYER</button>
  </div>
  <div id="nlist"><div class="empty"><i class="fas fa-spinner fa-spin" style="opacity:.2;font-size:40px"></i><h3>Chargement…</h3></div></div>
  <div class="pager" id="npager"></div>
</div>

</div><!-- /wrap -->

<!-- DETAIL MODAL -->
<div class="modal" id="dmodal">
  <div class="mbox">
    <div class="mhead"><div class="mttl"><i class="fas fa-receipt" style="color:var(--neon)"></i> Détail commande</div><div class="mclose" onclick="closeMod('dmodal')">×</div></div>
    <div class="mbody" id="dbody"><div class="empty"><i class="fas fa-spinner fa-spin" style="opacity:.2"></i></div></div>
  </div>
</div>

<!-- CONFIRM -->
<div class="cmodal" id="cmodal">
  <div class="cbox">
    <span class="cbox-ico" id="c-ico">⚠️</span>
    <div class="cbox-ttl" id="c-ttl">Confirmer</div>
    <div class="cbox-sub" id="c-sub">Cette action est irréversible.</div>
    <div class="cbox-btns">
      <button class="btn btn-g" style="flex:1" onclick="closeMod('cmodal')"><i class="fas fa-times"></i> Non</button>
      <button class="btn btn-r" style="flex:1" id="c-yes"><i class="fas fa-check"></i> Oui</button>
    </div>
  </div>
</div>

<!-- TOASTS -->
<div class="tstack" id="tstack"></div>

<script>
var SELF = location.pathname;
var curSt = '', curPg = 1, maxOid = 0, maxNid = 0;
var selIds = new Set(), sTmr = null, pollTmr = null;
var lastData = null, allNotifs = [], nFilter = 'all', nPage = 1;
var isRendering = false; /* flag pour éviter double render */

var SCFG = {
  pending:   {l:'En attente',   c:'s-pending',    i:'fa-clock',        col:'var(--gold)'},
  confirmed: {l:'Confirmée',    c:'s-confirmed',  i:'fa-check',        col:'var(--cyan)'},
  delivering:{l:'En livraison', c:'s-delivering', i:'fa-truck',        col:'var(--neon)'},
  done:      {l:'Livrée',       c:'s-done',       i:'fa-circle-check', col:'var(--blue)'},
  cancelled: {l:'Annulée',      c:'s-cancelled',  i:'fa-ban',          col:'var(--red)'}
};
var PAY = {cash:'💵 Espèces', mobile_money:'📱 Mobile Money'};
var NICO = {new_order:'🛎️',status_change:'🔄',payment:'💳',info:'💡',alert:'🚨'};

function ico(name){
  var s=(name||'').toLowerCase();
  if(s.includes('eau')||s.includes('water'))return '💧';
  if(s.includes('jus'))return '🍹'; if(s.includes('lait'))return '🥛';
  if(s.includes('bière'))return '🍺'; if(s.includes('soda'))return '🥤'; return '🫙';
}
function fmt(v){v=+v;return v>=1e6?(v/1e6).toFixed(1)+'M':v>=1000?(v/1000).toFixed(0)+'k':v.toLocaleString('fr-FR');}
function fmtFull(v){return (+v).toLocaleString('fr-FR');}
function fdate(s,full){
  var d=new Date((s||'').replace(' ','T'));
  if(full)return d.toLocaleString('fr-FR',{weekday:'long',day:'2-digit',month:'long',year:'numeric',hour:'2-digit',minute:'2-digit'});
  return d.toLocaleString('fr-FR',{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'});
}
function ago(s){
  var d=Math.floor((Date.now()-new Date((s||'').replace(' ','T')))/1000);
  if(d<60)return 'À l\'instant'; if(d<3600)return Math.floor(d/60)+'min';
  if(d<86400)return Math.floor(d/3600)+'h'; return Math.floor(d/86400)+'j';
}

/* ══ TOAST ══ */
function toast(title,sub,type,dur){
  type=type||'ok'; dur=dur||4500;
  var e=document.createElement('div');
  var clz='tst'+(type==='err'?' err':type==='warn'?' warn':type==='neworder'?' neworder':'');
  var icons={ok:'✅',err:'❌',warn:'⚠️',neworder:'🛎️'};
  var barC=type==='err'?'var(--red)':type==='warn'?'var(--gold)':'var(--neon)';
  e.className=clz;
  e.innerHTML='<div class="tst-ico">'+(icons[type]||'💡')+'</div>'+
    '<div class="tst-c"><div class="tst-title">'+title+'</div>'+(sub?'<div class="tst-sub">'+sub+'</div>':'')+'</div>'+
    '<div class="tst-x" onclick="this.parentNode.remove()">×</div>'+
    '<div class="tst-bar" style="background:'+barC+';transition:width '+dur+'ms linear"></div>';
  document.getElementById('tstack').appendChild(e);
  requestAnimationFrame(function(){requestAnimationFrame(function(){var b=e.querySelector('.tst-bar');if(b)b.style.width='0';});});
  e.onclick=function(ev){if(!ev.target.classList.contains('tst-x'))e.remove();};
  setTimeout(function(){e.style.animation='toastOut .3s ease forwards';setTimeout(function(){if(e.parentNode)e.remove();},300);},dur);
}

/* ══ TABS ══ */
function goTab(t){
  ['orders','stats','notifs'].forEach(function(x){
    document.getElementById('panel-'+x).classList.remove('on');
    document.getElementById('tnav-'+x).classList.remove('on');
  });
  document.getElementById('panel-'+t).classList.add('on');
  document.getElementById('tnav-'+t).classList.add('on');
  var titles={orders:'Dashboard Commandes',stats:'Statistiques',notifs:'Notifications Caisse'};
  document.getElementById('tb-title').textContent=titles[t]||t;
  if(t==='stats')loadChart();
  if(t==='notifs')loadNotifs(true);
}

/* ══════════════════════════════════════════════════════
   LOAD ORDERS — rendu complet seulement sur action user
   ══════════════════════════════════════════════════════ */
async function doLoad(force){
  if(isRendering)return;
  isRendering=true;
  var ri=document.getElementById('ri');
  if(force)ri.classList.add('fa-spin');
  var fd=new FormData();
  fd.append('action','get_orders');
  fd.append('status',curSt);
  fd.append('search',document.getElementById('s-inp').value.trim());
  fd.append('company_id',document.getElementById('f-co').value);
  fd.append('city_id',document.getElementById('f-ci').value);
  fd.append('page',curPg);
  try{
    var res=await fetch(SELF,{method:'POST',body:fd});
    var d=await res.json();
    isRendering=false;
    ri.classList.remove('fa-spin');
    if(!d.success){toast('Erreur',d.message||'','err');return;}
    lastData=d;
    if(d.max_id>0)maxOid=d.max_id;
    renderKPIs(d.kpi);
    renderOrders(d.orders);
    renderPager(d.total,d.page,d.per_page);
    updClock();
  }catch(ex){isRendering=false;ri.classList.remove('fa-spin');toast('Erreur réseau',ex.message,'err');}
}

/* ══════════════════════════════════════════════════════
   POLL SILENCIEUX — NE TOUCHE PAS à la liste d'ordres
   Toutes les 15s : KPIs + détection nouvelles commandes
   ══════════════════════════════════════════════════════ */
async function silentPoll(){
  var fd=new FormData();
  fd.append('action','poll_silent');
  if(maxOid>0)fd.append('since_id',maxOid);
  try{
    var res=await fetch(SELF,{method:'POST',body:fd});
    var d=await res.json();
    if(!d.success)return;
    /* Mise à jour silencieuse des KPIs uniquement */
    renderKPIs(d.kpi);
    if(d.max_id>0)maxOid=d.max_id;
    /* Nouvelles commandes → toast + son + reload complet */
    if(d.new_orders&&d.new_orders.length>0){
      d.new_orders.forEach(function(o){
        toast('🛎️ Nouvelle commande !',(o.client_name||'Client')+' — '+fmtFull(+o.total_amount)+' CFA','neworder',9000);
        try{var ac=new(window.AudioContext||window.webkitAudioContext)();var os=ac.createOscillator();var g=ac.createGain();os.connect(g);g.connect(ac.destination);os.type='sine';os.frequency.value=880;g.gain.setValueAtTime(.28,ac.currentTime);g.gain.exponentialRampToValueAtTime(.001,ac.currentTime+.22);os.start();os.stop(ac.currentTime+.22);}catch(e){}
        flashNbdg();
      });
      /* Reload la liste seulement si nouvelle commande */
      setTimeout(function(){doLoad(true);},500);
    }
    /* Pulse vert discret */
    var dot=document.getElementById('tb-dot');
    dot.classList.add('pulse');
    setTimeout(function(){dot.classList.remove('pulse');},600);
    updClock();
  }catch(ex){}
}

function updClock(){
  var now=new Date().toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
  document.getElementById('tb-time').textContent=now;
}

/* ══ KPIs ══ */
function renderKPIs(k){
  if(!k)return;
  document.getElementById('kv-pending').textContent=fmt(k.pending||0);
  document.getElementById('kv-confirmed').textContent=fmt(k.confirmed||0);
  document.getElementById('kv-delivering').textContent=fmt(k.delivering||0);
  document.getElementById('kv-done').textContent=fmt(k.done||0);
  document.getElementById('kv-rev').textContent=fmt(k.revenue||0);
  document.getElementById('kv-today').textContent=fmt(k.today_count||0);
  document.getElementById('kv-total').textContent=fmt(k.total||0);
  document.getElementById('kv-cancelled').textContent=fmt(k.cancelled||0);
  document.getElementById('ks-today').textContent=fmtFull(k.today_revenue||0)+' CFA';
  document.getElementById('pc-all').textContent=k.total||0;
  document.getElementById('pc-pend').textContent=k.pending||0;
  document.getElementById('pc-conf').textContent=k.confirmed||0;
  document.getElementById('pc-deli').textContent=k.delivering||0;
  document.getElementById('pc-done').textContent=k.done||0;
  document.getElementById('pc-canc').textContent=k.cancelled||0;
  var p=+(k.pending||0),tnb=document.getElementById('tnb-pending');
  if(p>0){tnb.textContent=p;tnb.style.display='flex';}else tnb.style.display='none';
}

/* ══ RENDER ORDERS ══ */
function renderOrders(orders){
  var list=document.getElementById('olist');
  document.getElementById('o-cnt').textContent='('+(orders?orders.length:0)+' affichées)';
  if(!orders||!orders.length){
    list.innerHTML='<div class="empty"><i class="fas fa-box-open"></i><h3>Aucune commande</h3><p>Aucun résultat pour ces filtres</p></div>';
    return;
  }
  list.innerHTML=orders.map(function(o,i){return buildOCard(o,i);}).join('');
}

function buildOCard(o,idx){
  var cfg=SCFG[o.status]||SCFG.pending;
  var items=o.items||[];
  var isSel=selIds.has(+o.id);
  var dt=fdate(o.created_at,false);
  var couponHtml=(o.coupon_code&&+o.coupon_discount>0)
    ? '<span class="mpill" style="color:var(--gold);border-color:rgba(255,208,96,.18)"><i class="fas fa-ticket-alt"></i> '+o.coupon_code+' · -'+fmtFull(+o.coupon_discount)+' CFA</span>'
    : '';
  var ih=items.slice(0,3).map(function(it){
    return '<div class="irow"><div class="irow-ico">'+ico(it.product_name)+'</div>'+
      '<div class="irow-name">'+it.product_name+'</div>'+
      '<div class="irow-qty">x'+it.quantity+'</div>'+
      '<div class="irow-sub">'+fmt(it.subtotal)+' CFA</div></div>';
  }).join('')+(items.length>3?'<div style="text-align:center;font-size:11px;color:var(--muted);padding:4px">+'+( items.length-3)+' autres articles</div>':'');
  var act='';
  if(o.status==='pending')    act+='<button class="btn btn-n btn-sm" onclick="qSt('+o.id+',\'confirmed\')"><i class="fas fa-check"></i> Confirmer</button>';
  if(o.status==='confirmed')  act+='<button class="btn btn-n btn-sm" onclick="qSt('+o.id+',\'delivering\')"><i class="fas fa-truck"></i> Expédier</button>';
  if(o.status==='delivering') act+='<button class="btn btn-b btn-sm" onclick="qSt('+o.id+',\'done\')"><i class="fas fa-circle-check"></i> Livré</button>';
  if(o.status==='pending'||o.status==='confirmed') act+='<button class="btn btn-r btn-sm" onclick="askCancel('+o.id+',\''+o.order_number+'\')"><i class="fas fa-ban"></i> Annuler</button>';
  act+='<button class="btn btn-g btn-sm" onclick="openDetail('+o.id+')"><i class="fas fa-eye"></i> Détail</button>';
  act+='<button class="btn btn-sm" style="background:rgba(50,190,143,.08);border-color:rgba(50,190,143,.22);color:var(--neon)" onclick="exportOne(\''+o.order_number+'\')"><i class="fas fa-file-excel"></i> Excel</button>';
  return '<div class="ocard'+(isSel?' sel':'')+'" id="row-'+o.id+'" style="animation-delay:'+idx*.03+'s">'+
    '<div class="oc-top" onclick="togBody('+o.id+',event)">'+
      '<div class="oc-chk'+(isSel?' on':'')+'" id="chk-'+o.id+'" onclick="togSel('+o.id+',event)">'+(isSel?'✓':'')+'</div>'+
      '<div class="oc-num">🧾 '+o.order_number+'</div>'+
      '<div class="oc-cli"><div class="oc-cname">'+(o.client_name||'—')+'</div>'+
      '<div class="oc-ctel"><i class="fas fa-phone" style="font-size:9px"></i> '+(o.client_phone||'—')+'</div></div>'+
      '<div class="oc-amt">'+fmtFull(+o.total_amount)+' <small>CFA</small></div>'+
      '<div class="oc-date">'+dt+'</div>'+
    '</div>'+
    '<div class="oc-meta">'+
      '<span class="sbdg '+cfg.c+'"><i class="fas '+cfg.i+'"></i> '+cfg.l+'</span>'+
      '<span class="mpill"><i class="fas fa-building"></i> '+(o.company_name||'—')+'</span>'+
      '<span class="mpill"><i class="fas fa-city"></i> '+(o.city_name||'—')+'</span>'+
      '<span class="mpill">'+(PAY[o.payment_method]||o.payment_method||'—')+'</span>'+
      couponHtml+
      '<select class="ssel" onchange="updSt('+o.id+',this.value,this)" onclick="event.stopPropagation()" style="margin-left:auto">'+
        Object.keys(SCFG).map(function(k){return '<option value="'+k+'"'+(k===o.status?' selected':'')+'>'+SCFG[k].l+'</option>';}).join('')+
      '</select>'+
      '<button class="btn btn-b btn-sm" onclick="openDetail('+o.id+');event.stopPropagation()"><i class="fas fa-eye"></i></button>'+
    '</div>'+
    '<div class="oc-body" id="body-'+o.id+'">'+
      '<div style="font-size:10px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:1.5px;margin-bottom:8px">Articles ('+items.length+')</div>'+
      '<div class="items-box">'+ih+'</div>'+
      (o.coupon_code&&+o.coupon_discount>0?'<div class="total-row" style="margin-top:0;margin-bottom:8px;background:rgba(255,208,96,.06);border-color:rgba(255,208,96,.18)"><span class="total-lbl" style="color:var(--gold)">Coupon '+o.coupon_code+'</span><span class="total-val" style="color:var(--gold)">-'+fmtFull(+o.coupon_discount)+' CFA</span></div>':'')+
      '<div class="total-row"><span class="total-lbl">Total</span><span class="total-val">'+fmtFull(+o.total_amount)+' CFA</span></div>'+
      (o.delivery_address?'<div style="margin-top:9px;padding:9px 11px;border-radius:10px;background:rgba(0,0,0,.16);border:1px solid rgba(255,255,255,.05);font-size:12px;font-weight:700;color:var(--text2)"><i class="fas fa-location-dot" style="color:var(--neon)"></i> '+o.delivery_address+'</div>':'')+
      (o.notes?'<div style="margin-top:6px;padding:8px 11px;border-radius:10px;background:rgba(255,208,96,.04);border:1px solid rgba(255,208,96,.1);font-size:11px;color:var(--gold)"><i class="fas fa-sticky-note"></i> '+o.notes+'</div>':'')+
      '<div class="oc-actions">'+act+'</div>'+
    '</div>'+
  '</div>';
}

function togBody(id,e){
  if(e.target.closest('.ssel')||e.target.closest('.oc-chk')||e.target.closest('.btn'))return;
  var b=document.getElementById('body-'+id);if(b)b.classList.toggle('on');
}

/* ══ SELECTION ══ */
function togSel(id,e){
  e.stopPropagation();var n=+id;
  if(selIds.has(n))selIds.delete(n);else selIds.add(n);
  var row=document.getElementById('row-'+id),chk=document.getElementById('chk-'+id);
  if(row)row.classList.toggle('sel',selIds.has(n));
  if(chk){chk.classList.toggle('on',selIds.has(n));chk.textContent=selIds.has(n)?'✓':'';}
  updBulk();
}
function updBulk(){
  document.getElementById('bulk-cnt').textContent=selIds.size+' sél.';
  document.getElementById('bulk').classList.toggle('on',selIds.size>0);
}
function clearSel(){
  selIds.clear();
  document.querySelectorAll('.ocard.sel').forEach(function(e){e.classList.remove('sel');});
  document.querySelectorAll('.oc-chk.on').forEach(function(e){e.classList.remove('on');e.textContent='';});
  updBulk();
}
async function applyBulk(){
  var st=document.getElementById('bulk-sel').value;
  if(!st||!selIds.size){toast('Sélectionnez un statut','','warn');return;}
  var fd=new FormData();fd.append('action','bulk_status');fd.append('ids',JSON.stringify([...selIds]));fd.append('status',st);
  try{var res=await fetch(SELF,{method:'POST',body:fd});var d=await res.json();
    if(d.success){toast(d.updated+' mise(s) à jour',SCFG[st].l,'ok');clearSel();doLoad(true);}
    else toast('Erreur',d.message||'','err');
  }catch(ex){toast('Erreur réseau','','err');}
}

/* ══ STATUS ══ */
async function updSt(id,st,el){
  if(el)el.disabled=true;
  var fd=new FormData();fd.append('action','update_status');fd.append('order_id',id);fd.append('status',st);
  try{
    var res=await fetch(SELF,{method:'POST',body:fd});var d=await res.json();
    if(el)el.disabled=false;
    if(d.success){
      toast('Statut mis à jour',SCFG[st].l,'ok',3500);
      var row=document.getElementById('row-'+id);if(row)row.classList.add('flash');
      setTimeout(function(){var r=document.getElementById('row-'+id);if(r)r.classList.remove('flash');},650);
      doLoad(true);
    }else{toast('Erreur',d.message||'','err');if(el)doLoad(true);}
  }catch(ex){if(el)el.disabled=false;toast('Erreur réseau','','err');}
}
function qSt(id,st){updSt(id,st,null);}

var pendCancel=null;
function askCancel(id,num){
  pendCancel=id;
  document.getElementById('c-ico').textContent='⚠️';
  document.getElementById('c-ttl').textContent='Annuler la commande ?';
  document.getElementById('c-sub').textContent='La commande '+num+' sera annulée définitivement.';
  document.getElementById('c-yes').onclick=function(){closeMod('cmodal');updSt(pendCancel,'cancelled',null);pendCancel=null;};
  document.getElementById('cmodal').classList.add('on');
}

/* ══ DETAIL ══ */
async function openDetail(id){
  document.getElementById('dmodal').classList.add('on');
  document.getElementById('dbody').innerHTML='<div class="empty"><i class="fas fa-spinner fa-spin" style="opacity:.2"></i></div>';
  var fd=new FormData();fd.append('action','get_detail');fd.append('order_id',id);
  try{
    var res=await fetch(SELF,{method:'POST',body:fd});var d=await res.json();
    if(d.success)renderDetail(d.order);
    else document.getElementById('dbody').innerHTML='<div class="empty"><p>'+d.message+'</p></div>';
  }catch(ex){document.getElementById('dbody').innerHTML='<div class="empty"><p>Erreur réseau</p></div>';}
}

function renderDetail(o){
  var cfg=SCFG[o.status]||SCFG.pending,items=o.items||[],log=o.log||[];
  var so={pending:0,confirmed:1,delivering:2,done:3,cancelled:-1};
  var cur=so[o.status]||0;
  var steps=[{k:'pending',l:'Reçue',i:'fa-inbox'},{k:'confirmed',l:'Confirmée',i:'fa-check'},{k:'delivering',l:'Livraison',i:'fa-truck'},{k:'done',l:'Livrée',i:'fa-house'}];
  var tl='<div class="tline">';
  steps.forEach(function(s,i){
    var si=so[s.k],dc='';
    if(o.status==='cancelled')dc='can'; else if(si<cur)dc='done'; else if(si===cur)dc='cur';
    tl+='<div class="tl-s"><div class="tl-d '+dc+'"><i class="fas '+s.i+'" style="font-size:10px"></i></div><div class="tl-l '+dc+'">'+s.l+'</div></div>';
    if(i<3){var ld=(si<cur&&o.status!=='cancelled');tl+='<div class="tl-line '+(ld?'done':'off')+'"></div>';}
  });
  tl+='</div>';
  var ih=items.map(function(it){return '<div class="irow"><div class="irow-ico">'+ico(it.product_name)+'</div><div class="irow-name">'+it.product_name+'</div><div class="irow-qty">x'+it.quantity+' @ '+fmtFull(it.unit_price||0)+' CFA</div><div class="irow-sub">'+fmtFull(it.subtotal)+' CFA</div></div>';}).join('');
  var lh=log.length?log.map(function(l){return '<div class="log-row"><div class="log-dot"></div><div><div class="log-txt">'+(SCFG[l.old_status]?SCFG[l.old_status].l:l.old_status||'?')+' → <strong style="color:var(--neon)">'+(SCFG[l.new_status]?SCFG[l.new_status].l:l.new_status||'?')+'</strong> — '+(l.changed_by||'admin')+'</div><div class="log-time">'+fdate(l.created_at,false)+'</div></div></div>';}).join(''):'<div style="font-size:12px;color:var(--muted);padding:5px">Aucun historique</div>';
  var stBtns=Object.keys(SCFG).filter(function(k){return k!==o.status;}).map(function(k){return '<button class="btn btn-sm" style="background:rgba(0,0,0,.18);border-color:var(--bord);color:var(--muted)" onclick="updSt('+o.id+',\''+k+'\',null);closeMod(\'dmodal\')"><i class="fas '+SCFG[k].i+'"></i> '+SCFG[k].l+'</button>';}).join('');
  document.getElementById('dbody').innerHTML=
    '<div style="margin-bottom:13px">'+
      '<div style="font-size:19px;font-weight:900;color:var(--gold);letter-spacing:1px">'+o.order_number+'</div>'+
      '<div style="font-size:11px;color:var(--muted);margin-top:3px">'+fdate(o.created_at,true)+'</div>'+
      '<div style="margin-top:8px;display:flex;flex-wrap:wrap;gap:6px"><span class="sbdg '+cfg.c+'"><i class="fas '+cfg.i+'"></i> '+cfg.l+'</span>'+
      '<span class="mpill"><i class="fas fa-building"></i> '+(o.company_name||'—')+'</span>'+
      '<span class="mpill"><i class="fas fa-city"></i> '+(o.city_name||'—')+'</span>'+
      '<span class="mpill">'+(PAY[o.payment_method]||o.payment_method||'—')+'</span></div></div>'+
    '<div style="padding:10px 13px;border-radius:11px;background:rgba(0,0,0,.16);border:1px solid rgba(255,255,255,.05);margin-bottom:13px;display:flex;align-items:center;gap:10px">'+
      '<div style="width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--neon),var(--cyan));display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:900;color:var(--bg);flex-shrink:0">'+((o.client_name||'?')[0]).toUpperCase()+'</div>'+
      '<div><div style="font-size:14px;font-weight:900;color:var(--text)">'+(o.client_name||'—')+'</div>'+
      '<div style="font-size:11px;color:var(--muted)"><i class="fas fa-phone" style="font-size:10px"></i> '+(o.client_phone||'—')+'</div></div></div>'+
    '<div style="margin-bottom:13px"><div style="font-size:10px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:1.5px;margin-bottom:7px">Suivi livraison</div>'+tl+'</div>'+
    '<div style="margin-bottom:13px"><div style="font-size:10px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:1.5px;margin-bottom:7px">Articles ('+items.length+')</div>'+
    '<div class="items-box">'+ih+'</div>'+
    (o.coupon_code&&+o.coupon_discount>0?'<div class="total-row" style="margin-top:0;margin-bottom:8px;background:rgba(255,208,96,.06);border-color:rgba(255,208,96,.18)"><span class="total-lbl" style="color:var(--gold)">Coupon '+o.coupon_code+'</span><span class="total-val" style="color:var(--gold)">-'+fmtFull(+o.coupon_discount)+' CFA</span></div>':'')+
    '<div class="total-row" style="margin-top:8px"><span class="total-lbl">Total</span><span class="total-val">'+fmtFull(+o.total_amount)+' CFA</span></div></div>'+
    (o.delivery_address?'<div style="margin-bottom:12px;padding:10px 12px;border-radius:10px;background:rgba(0,0,0,.16);border:1px solid rgba(255,255,255,.05)"><div style="font-size:10px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:1.5px;margin-bottom:5px">Adresse livraison</div><div style="font-size:12px;font-weight:700;color:var(--text2)"><i class="fas fa-location-dot" style="color:var(--neon)"></i> '+o.delivery_address+'</div>'+(o.notes?'<div style="margin-top:5px;font-size:11px;color:var(--gold)"><i class="fas fa-sticky-note"></i> '+o.notes+'</div>':'')+'</div>':'')+
    '<div style="margin-bottom:13px"><div style="font-size:10px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:1.5px;margin-bottom:7px">Changer le statut</div>'+
    '<div style="display:flex;flex-wrap:wrap;gap:6px">'+stBtns+'</div></div>'+
    '<div><div style="font-size:10px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:1.5px;margin-bottom:7px">Historique</div>'+lh+'</div>';
}

/* ══ PAGER ══ */
function renderPager(total,page,pp){
  var pages=Math.ceil(total/pp)||1,pg=document.getElementById('pager');
  if(pages<=1){pg.innerHTML='';return;}
  var h='<div class="pp" onclick="goPg('+(page-1)+')" '+(page===1?'style="opacity:.3;pointer-events:none"':'')+'><i class="fas fa-chevron-left" style="font-size:9px"></i></div>';
  for(var i=1;i<=pages;i++){if(i===1||i===pages||Math.abs(i-page)<=1){h+='<div class="pp'+(i===page?' on':'')+'" onclick="goPg('+i+')">'+i+'</div>';}else if(Math.abs(i-page)===2){h+='<span style="color:var(--muted);padding:0 4px">…</span>';}}
  h+='<div class="pp" onclick="goPg('+(page+1)+')" '+(page===pages?'style="opacity:.3;pointer-events:none"':'')+'><i class="fas fa-chevron-right" style="font-size:9px"></i></div>';
  pg.innerHTML=h;
}
function goPg(p){curPg=p;doLoad(true);}

/* ══ CHART ══ */
async function loadChart(){
  var fd=new FormData();fd.append('action','get_stats');
  try{
    var res=await fetch(SELF,{method:'POST',body:fd});var d=await res.json();
    if(!d.success)return;
    var max=Math.max.apply(null,d.days.map(function(x){return x.count;}))||1;
    document.getElementById('chart-bars').innerHTML=d.days.map(function(day){
      var p=Math.round(day.count/max*100);
      return '<div class="bar-w"><div class="bar-v">'+day.count+'</div>'+
        '<div class="bar" style="height:'+Math.max(p,2)+'%" title="'+day.count+' cmds — '+fmtFull(day.rev)+' CFA"></div>'+
        '<div class="bar-l">'+day.date+'</div></div>';
    }).join('');
  }catch(ex){}
}

/* ══════════════════════════════════════════════════════
   📊 EXPORT EXCEL — ouvre export_bons.php avec les filtres actifs
   ══════════════════════════════════════════════════════ */
function exportExcel(){
  var params = new URLSearchParams();
  var co = document.getElementById('f-co').value;
  var ci = document.getElementById('f-ci').value;
  var s  = document.getElementById('s-inp').value.trim();
  if (co) params.set('company_id', co);
  if (ci) params.set('city_id', ci);
  if (curSt) params.set('status', curSt);
  if (s)  params.set('search', s);
  var url = 'export_bons.php' + (params.toString() ? '?' + params.toString() : '');
  toast('📊 Export en cours…', 'Téléchargement du fichier Excel', 'ok', 4000);
  window.location.href = url;
}

/* Export Excel pour une seule commande (par numéro) */
function exportOne(orderNumber){
  var params = new URLSearchParams();
  params.set('search', orderNumber);
  var url = 'export_bons.php?' + params.toString();
  toast('📊 Export commande…', orderNumber, 'ok', 3000);
  window.location.href = url;
}

/* ══ NOTIFS ══ */
async function loadNotifs(force){
  var fd=new FormData();fd.append('action','get_cashier_notifs');fd.append('page',nPage);
  if(!force&&maxNid>0)fd.append('since_id',maxNid);
  try{
    var res=await fetch(SELF,{method:'POST',body:fd});var d=await res.json();
    if(!d.success)return;
    if(d.max_id>0)maxNid=d.max_id;
    allNotifs=d.notifs||[];
    document.getElementById('ns-total').textContent=d.total||0;
    document.getElementById('ns-unread').textContent=d.unread||0;
    document.getElementById('ns-orders').textContent=allNotifs.filter(function(n){return n.type==='new_order';}).length;
    updNbdg(d.unread);
    var now=new Date().toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
    document.getElementById('n-last').textContent='— '+now;
    renderNotifs(allNotifs);
    renderNPager(d.total,nPage,30);
    if(!force&&d.new_notifs&&d.new_notifs.length){
      d.new_notifs.forEach(function(n){
        if(!document.getElementById('tnav-notifs').classList.contains('on'))toast('🔔 '+n.title,n.message,n.type==='alert'?'warn':'ok',7000);
      });
    }
  }catch(ex){}
}

function renderNotifs(notifs){
  var filtered=nFilter==='all'?notifs:notifs.filter(function(n){return n.type===nFilter;});
  var list=document.getElementById('nlist');
  if(!filtered.length){list.innerHTML='<div class="empty"><i class="fas fa-bell-slash"></i><h3>Aucune notification</h3><p>Rien ici pour ce filtre</p></div>';return;}
  list.innerHTML=filtered.map(function(n,i){return buildNCard(n,i);}).join('');
}

function buildNCard(n,delay){
  var unread=!+n.is_read,ico2=NICO[n.type]||'💡',ta=ago(n.created_at),dt=fdate(n.created_at,false);
  var extra='';
  if(n.order_id&&n.order_number){
    extra='<div style="margin-top:5px">'+
      '<span class="nc-tag" style="color:var(--gold)"><i class="fas fa-receipt"></i> '+n.order_number+'</span>'+
      (n.client_name?'<span class="nc-tag" style="margin-left:5px"><i class="fas fa-user"></i> '+n.client_name+'</span>':'')+
      (+n.amount>0?'<span class="nc-tag" style="color:var(--neon);margin-left:5px"><i class="fas fa-coins"></i> '+fmtFull(+n.amount)+' CFA</span>':'')+
    '</div>';
  }
  return '<div class="ncard'+(unread?' unread':'')+'" id="ncard-'+n.id+'" style="animation-delay:'+delay*.03+'s">'+
    '<div class="nc-main" onclick="clickN('+n.id+','+(n.order_id||0)+')">'+
      '<div class="nc-ico '+n.type+'">'+ico2+'</div>'+
      '<div class="nc-cont">'+
        '<div class="nc-title">'+n.title+'</div>'+
        '<div class="nc-msg">'+n.message+'</div>'+
        '<div class="nc-meta"><span class="nc-time"><i class="fas fa-clock" style="font-size:9px"></i> '+ta+' — '+dt+'</span></div>'+
        extra+
      '</div>'+
      (unread?'<div class="nc-udot"></div>':'')+
    '</div>'+
    '<div class="nc-actions">'+
      (unread?'<button class="btn btn-n btn-sm" onclick="markOne('+n.id+')"><i class="fas fa-check"></i> Lu</button>':'')+
      (n.order_id?'<button class="btn btn-b btn-sm" onclick="goTab(\'orders\');setTimeout(function(){openDetail('+n.order_id+');},300)"><i class="fas fa-eye"></i> Voir cmd</button>':'')+
      '<button class="btn btn-r btn-sm" style="margin-left:auto" onclick="delNotif('+n.id+')"><i class="fas fa-trash"></i></button>'+
    '</div>'+
  '</div>';
}

function clickN(nid,oid){markOne(nid);if(oid){goTab('orders');setTimeout(function(){openDetail(oid);},300);}}

async function markOne(nid){
  var fd=new FormData();fd.append('action','mark_notifs_read');fd.append('ids',JSON.stringify([nid]));
  await fetch(SELF,{method:'POST',body:fd});
  var c=document.getElementById('ncard-'+nid);if(c)c.classList.remove('unread');
  loadNotifs(true);
}
async function markAllRead(){
  var fd=new FormData();fd.append('action','mark_notifs_read');fd.append('ids','[]');
  await fetch(SELF,{method:'POST',body:fd});
  toast('Toutes les notifications lues','','ok',2500);loadNotifs(true);
}
async function clearRead(){
  var ids=allNotifs.filter(function(n){return +n.is_read;}).map(function(n){return n.id;});
  if(!ids.length){toast('Aucune notification lue','','warn');return;}
  for(var i=0;i<ids.length;i++){
    var fd=new FormData();fd.append('action','delete_notif');fd.append('notif_id',ids[i]);
    await fetch(SELF,{method:'POST',body:fd});
  }
  toast(ids.length+' supprimées','','ok',2500);loadNotifs(true);
}
async function delNotif(nid){
  var fd=new FormData();fd.append('action','delete_notif');fd.append('notif_id',nid);
  await fetch(SELF,{method:'POST',body:fd});
  var c=document.getElementById('ncard-'+nid);
  if(c){c.style.transition='opacity .25s';c.style.opacity='0';setTimeout(function(){if(c.parentNode)c.remove();},260);}
  loadNotifs(true);
}
async function addNotif(){
  var type=document.getElementById('nn-type').value;
  var title=document.getElementById('nn-title').value.trim();
  var msg=document.getElementById('nn-msg').value.trim();
  if(!title||!msg){toast('Titre et message requis','','warn');return;}
  var fd=new FormData();fd.append('action','add_notif');fd.append('type',type);fd.append('title',title);fd.append('message',msg);
  try{
    var res=await fetch(SELF,{method:'POST',body:fd});var d=await res.json();
    if(d.success){toast('Notification créée','','ok',2500);document.getElementById('nn-title').value='';document.getElementById('nn-msg').value='';loadNotifs(true);}
    else toast('Erreur',d.message||'','err');
  }catch(ex){toast('Erreur réseau','','err');}
}

function setNF(f,el){nFilter=f;document.querySelectorAll('.nfpill').forEach(function(p){p.classList.remove('on');});el.classList.add('on');renderNotifs(allNotifs);}
function renderNPager(total,page,pp){
  var pages=Math.ceil(total/pp)||1,pg=document.getElementById('npager');
  if(pages<=1){pg.innerHTML='';return;}
  var h='';for(var i=1;i<=Math.min(pages,8);i++)h+='<div class="pp'+(i===page?' on':'')+'" onclick="goNPg('+i+')">'+i+'</div>';
  pg.innerHTML=h;
}
function goNPg(p){nPage=p;loadNotifs(true);}

function updNbdg(c){
  var b=document.getElementById('tnb-notifs');
  if(c>0){b.textContent=c>99?'99+':c;b.style.display='flex';}else b.style.display='none';
}
function flashNbdg(){
  var b=document.getElementById('tnb-notifs');
  var c=+(b.textContent.replace('+',''))||0;
  b.textContent=c+1;b.style.display='flex';b.style.animation='pop .35s ease';
  setTimeout(function(){b.style.animation='';},350);
}

/* ══ FILTRES / REFRESH ══ */
function setSt(s,el){curSt=s;curPg=1;document.querySelectorAll('.pill').forEach(function(p){p.classList.remove('on');});el.classList.add('on');doLoad(true);}
function debS(){clearTimeout(sTmr);sTmr=setTimeout(function(){curPg=1;doLoad(true);},380);}
function doRefresh(){doLoad(true);}

/* ══ MODAL CLOSE ══ */
function closeMod(id){var m=document.getElementById(id);if(m)m.classList.remove('on');}
document.addEventListener('click',function(e){
  ['dmodal','cmodal','pmodal'].forEach(function(id){var m=document.getElementById(id);if(m&&e.target===m)m.classList.remove('on');});
});

/* ══ DÉMARRAGE ══
   - 1er chargement complet de la liste
   - Puis polling silencieux toutes les 15s (KPIs seuls, pas de re-render liste)
   ══════════════ */
doLoad(true);
startPoll();

function startPoll(){
  clearInterval(pollTmr);
  pollTmr=setInterval(silentPoll, 15000);
}
</script>
</body>
</html>
