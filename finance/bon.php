<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

/**
 * ═══════════════════════════════════════════════════════════════
 * CAISSE ULTRA PRO — ESPERANCE H2O — DARK NEON EDITION 🔥
 * ═══════════════════════════════════════════════════════════════
 * ✅ Bons du jour (confirmés) + Historique (traités) + LOGS
 * ✅ Mise à jour stock automatique lors de l'impression
 * ✅ Vérification double facturation
 * ✅ Onglets: Bons du jour | Historique | Logs complets
 * ✅ Dark Neon C059 style
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
Middleware::role(['developer', 'admin', 'manager', 'staff']);

$pdo = DB::getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$user_name = $_SESSION['username'] ?? 'Caissiere';
$user_id = $_SESSION['user_id'] ?? 0;

// Création table logs si nécessaire
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS caisse_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            action VARCHAR(50) NOT NULL,
            old_status VARCHAR(20),
            new_status VARCHAR(20),
            user_name VARCHAR(100),
            user_id INT,
            details TEXT,
            ip_address VARCHAR(45),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX(order_id), INDEX(created_at)
        ) ENGINE=InnoDB
    ");
} catch(Exception $e) {}

$companies = $pdo->query("SELECT id,name FROM companies ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$cities    = $pdo->query("SELECT id,name FROM cities ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

/* ══════════════════════════════════════════════════════════
   FONCTION LOG
══════════════════════════════════════════════════════════ */
function logAction($pdo, $order_id, $action, $old_status, $new_status, $details = '') {
    global $user_name, $user_id;
    try {
        $stmt = $pdo->prepare("
            INSERT INTO caisse_logs (order_id, action, old_status, new_status, user_name, user_id, details, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $order_id,
            $action,
            $old_status,
            $new_status,
            $user_name,
            $user_id,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? 'Unknown'
        ]);
    } catch(Exception $e) {}
}

/* ══════════════════════════════════════════════════════════
   AJAX HANDLERS
══════════════════════════════════════════════════════════ */
if (!empty($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $act = trim($_POST['action']);

    // GET ORDERS
    if ($act === 'get_orders') {
        try {
            $tab     = trim($_POST['tab'] ?? 'today');
            $company = (int)($_POST['company_id'] ?? 0);
            $city    = (int)($_POST['city_id'] ?? 0);
            $search  = trim($_POST['search'] ?? '');
            $date    = trim($_POST['date'] ?? date('Y-m-d'));
            $hd      = trim($_POST['heure_d'] ?? '');
            $hf      = trim($_POST['heure_f'] ?? '');
            $since   = (int)($_POST['since_id'] ?? 0);

            if ($tab === 'today') {
                $statWhere = "o.status = 'confirmed'";
            } else {
                $statWhere = "o.status IN ('delivering','done')";
            }

            $where  = [$statWhere, "DATE(o.created_at) = ?"];
            $params = [$date];
            if ($company) { $where[] = 'o.company_id=?'; $params[] = $company; }
            if ($city)    { $where[] = 'o.city_id=?';    $params[] = $city; }
            if ($hd)      { $where[] = "TIME(o.created_at) >= ?"; $params[] = $hd.':00'; }
            if ($hf)      { $where[] = "TIME(o.created_at) <= ?"; $params[] = $hf.':59'; }
            if ($search)  {
                $where[] = '(o.order_number LIKE ? OR c.name LIKE ? OR c.phone LIKE ?)';
                $lk = '%'.$search.'%';
                array_push($params, $lk, $lk, $lk);
            }
            $ws = implode(' AND ', $where);

            $st = $pdo->prepare("
                SELECT o.id, o.order_number, o.status, o.total_amount,
                       o.payment_method, o.delivery_address, o.notes, o.created_at,
                       o.invoiced_at, o.invoiced_by,
                       c.name AS client_name, c.phone AS client_phone,
                       ci.name AS city_name, co.name AS company_name
                FROM orders o
                LEFT JOIN clients   c  ON o.client_id  = c.id
                LEFT JOIN cities    ci ON o.city_id    = ci.id
                LEFT JOIN companies co ON o.company_id = co.id
                WHERE $ws ORDER BY o.created_at DESC LIMIT 300
            ");
            $st->execute($params);
            $orders = $st->fetchAll(PDO::FETCH_ASSOC);

            if ($orders) {
                $ids  = implode(',', array_map('intval', array_column($orders,'id')));
                $itms = $pdo->query("SELECT * FROM order_items WHERE order_id IN($ids) ORDER BY order_id,id")->fetchAll(PDO::FETCH_ASSOC);
                $im   = [];
                foreach ($itms as $it) $im[$it['order_id']][] = $it;
                foreach ($orders as &$o) $o['items'] = $im[$o['id']] ?? [];
            }

            $new_orders = [];
            if ($since > 0) {
                $sn = $pdo->prepare("
                    SELECT o.id,o.order_number,o.total_amount,o.created_at,c.name AS client_name
                    FROM orders o LEFT JOIN clients c ON o.client_id=c.id
                    WHERE o.id > ? AND o.status='confirmed' AND DATE(o.created_at)=CURDATE()
                    ORDER BY o.id DESC LIMIT 10
                ");
                $sn->execute([$since]);
                $new_orders = $sn->fetchAll(PDO::FETCH_ASSOC);
            }

            $max_id = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM orders WHERE status='confirmed'")->fetchColumn();

            $stats = $pdo->query("
                SELECT SUM(status='confirmed') AS confirmed,
                       SUM(status='delivering') AS delivering,
                       SUM(status='done' AND DATE(created_at)=CURDATE()) AS done_today,
                       COALESCE(SUM(CASE WHEN status='done' AND DATE(created_at)=CURDATE() THEN total_amount END),0) AS ca_jour
                FROM orders
            ")->fetch(PDO::FETCH_ASSOC);

            echo json_encode(['success'=>true,'orders'=>$orders,'new_orders'=>$new_orders,'max_id'=>$max_id,'stats'=>$stats]);
        } catch(Exception $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }

    // GET LOGS
    if ($act === 'get_logs') {
        try {
            $date = trim($_POST['date'] ?? date('Y-m-d'));
            $stmt = $pdo->prepare("
                SELECT l.*, o.order_number, c.name as client_name
                FROM caisse_logs l
                LEFT JOIN orders o ON l.order_id = o.id
                LEFT JOIN clients c ON o.client_id = c.id
                WHERE DATE(l.created_at) = ?
                ORDER BY l.created_at DESC
                LIMIT 200
            ");
            $stmt->execute([$date]);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success'=>true,'logs'=>$logs]);
        } catch(Exception $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }

    // MARK STATUS
    if ($act === 'mark_status') {
        try {
            $oid = (int)($_POST['order_id'] ?? 0);
            $to  = trim($_POST['to'] ?? '');
            if (!$oid || !in_array($to, ['delivering','done'])) {
                echo json_encode(['success'=>false,'message'=>'Invalide']); exit;
            }
            
            // Récup ancien statut
            $old = $pdo->query("SELECT status FROM orders WHERE id=$oid")->fetchColumn();
            
            $pdo->prepare("UPDATE orders SET status=? WHERE id=?")->execute([$to,$oid]);
            
            // Log
            logAction($pdo, $oid, 'STATUS_CHANGE', $old, $to, "Passage de $old à $to");
            
            echo json_encode(['success'=>true]);
        } catch(Exception $e) { 
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]); 
        }
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Action inconnue']); exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Facturation des bon de livraison | ESPERANCE H2O</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=Source+Serif+4:wght@700;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
@font-face{font-family:'C059';src:local('C059-Bold'),local('C059 Bold'),local('Century Schoolbook');font-weight:700 900;font-style:normal}
:root{--bg:#0f1726;--surf:#162033;--card:#1b263b;--card2:#22324a;--bord:rgba(148,163,184,0.18);--neon:#00a86b;--neon2:#00c87a;--red:#e53935;--orange:#f57c00;--blue:#1976d2;--gold:#f9a825;--purple:#a855f7;--cyan:#06b6d4;--text:#e8eef8;--text2:#bfd0e4;--muted:#8ea3bd;--glow:0 8px 24px rgba(0,168,107,0.18);--glow-r:0 8px 24px rgba(229,57,53,0.18);--glow-gold:0 8px 24px rgba(249,168,37,0.18);--fh:'C059','Source Serif 4','Playfair Display','Book Antiqua',Georgia,serif;--fb:'Inter','Segoe UI',system-ui,sans-serif}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
html{scroll-behavior:smooth}
body{font-family:var(--fb);font-size:15px;line-height:1.7;background:var(--bg);color:var(--text);min-height:100vh;overflow-x:hidden}
body::before{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;background:radial-gradient(ellipse 65% 42% at 4% 8%,rgba(50,190,143,0.08) 0%,transparent 62%),radial-gradient(ellipse 52% 36% at 96% 88%,rgba(61,140,255,0.07) 0%,transparent 62%)}
body::after{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;background-image:linear-gradient(rgba(50,190,143,0.022) 1px,transparent 1px),linear-gradient(90deg,rgba(50,190,143,0.022) 1px,transparent 1px);background-size:46px 46px}
.wrap{position:relative;z-index:1;max-width:1800px;margin:0 auto;padding:0 0 48px}

/* TOPBAR */
.topbar{background:rgba(22,32,51,0.96);border-bottom:1px solid var(--bord);backdrop-filter:blur(24px);padding:14px 22px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;position:sticky;top:0;z-index:500}
.topbar::after{content:'';position:absolute;bottom:-1px;left:0;right:0;height:1px;background:linear-gradient(90deg,transparent,var(--neon) 40%,var(--cyan) 60%,transparent);opacity:.55}
.brand{display:flex;align-items:center;gap:14px}
.brand-ico{width:42px;height:42px;background:linear-gradient(135deg,var(--neon),var(--cyan));border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;color:#fff;box-shadow:0 0 26px rgba(50,190,143,0.5);animation:breathe 3.2s ease-in-out infinite;flex-shrink:0}
@keyframes breathe{0%,100%{box-shadow:0 0 14px rgba(50,190,143,0.4)}50%{box-shadow:0 0 38px rgba(50,190,143,0.85)}}
.brand-txt h1{font-family:var(--fh);font-size:20px;font-weight:900;color:var(--text);letter-spacing:0.5px;line-height:1.2}
.brand-txt p{font-size:10px;font-weight:700;color:var(--neon);letter-spacing:2.8px;text-transform:uppercase;margin-top:3px}
.divider{width:1px;height:24px;background:var(--bord);flex-shrink:0}
.live-badge{display:flex;align-items:center;gap:5px;padding:5px 12px;border-radius:20px;background:rgba(50,190,143,0.06);border:1px solid var(--bord);font-size:11px;font-weight:700;color:var(--neon);white-space:nowrap;flex-shrink:0}
.live-dot{width:7px;height:7px;border-radius:50%;background:var(--neon);position:relative;flex-shrink:0}
.live-dot::after{content:'';position:absolute;inset:-1px;border-radius:50%;background:var(--neon);animation:ping 1.8s infinite}
@keyframes ping{0%{transform:scale(1);opacity:.7}100%{transform:scale(2.4);opacity:0}}
.tb-spacer{flex:1}
.tbtn{width:38px;height:38px;border-radius:10px;background:rgba(50,190,143,0.05);border:1.5px solid var(--bord);display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--text2);font-size:15px;transition:all .17s;text-decoration:none;flex-shrink:0}
.tbtn:hover{background:rgba(50,190,143,0.13);color:var(--neon);border-color:var(--bord)}
.tb-user{display:flex;align-items:center;gap:8px;padding:6px 14px;border-radius:20px;background:rgba(50,190,143,0.05);border:1px solid var(--bord);flex-shrink:0}
.tb-av{width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,var(--neon),var(--cyan));display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:900;color:#04090e}
.tb-un{font-size:12px;font-weight:700}

/* STATS */
.kpi-strip{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;padding:16px;background:rgba(27,38,59,0.9);border-bottom:1px solid rgba(50,190,143,0.07)}
.ks{background:var(--card);border:1px solid var(--bord);border-radius:14px;padding:16px;display:flex;align-items:center;gap:12px;transition:all 0.3s}
.ks:hover{transform:translateY(-4px);box-shadow:0 14px 32px rgba(0,0,0,0.38)}
.ks-ico{width:48px;height:48px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:24px;flex-shrink:0}
.ks-val{font-family:var(--fh);font-size:26px;font-weight:900;color:var(--text);line-height:1}
.ks-lbl{font-family:var(--fb);font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-top:5px}

/* TABS */
.tabs-nav{background:rgba(22,32,51,0.98);border-bottom:1px solid rgba(50,190,143,0.07);display:flex;padding:0 16px;overflow-x:auto;position:sticky;top:73px;z-index:400}
.tabs-nav::-webkit-scrollbar{display:none}
.tab{display:flex;align-items:center;gap:8px;padding:13px 18px;border-bottom:3px solid transparent;cursor:pointer;font-family:var(--fh);font-size:12px;color:var(--muted);white-space:nowrap;flex-shrink:0;transition:all .2s;font-weight:900}
.tab i{font-size:14px}
.tab.active{color:var(--neon);border-bottom-color:var(--neon)}
.tab:hover:not(.active){color:var(--text2)}
.tbdg{background:var(--orange);color:#fff;min-width:18px;height:18px;border-radius:10px;padding:0 6px;font-size:10px;display:inline-flex;align-items:center;justify-content:center;font-weight:700}

/* PANELS */
.panel{display:none;padding:16px;animation:fadeUp .3s ease}
.panel.active{display:block}
@keyframes fadeUp{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:translateY(0)}}

/* FILTERS */
.filters{background:var(--card);border:1px solid var(--bord);border-radius:14px;padding:16px;margin-bottom:16px;display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end}
.filter-group{flex:1;min-width:180px}
.filter-group label{display:block;font-family:var(--fh);font-size:10px;font-weight:900;color:var(--muted);margin-bottom:8px;text-transform:uppercase;letter-spacing:1px}
.f-input,.f-select{width:100%;padding:11px 14px;background:rgba(15,23,38,0.72);border:1.5px solid var(--bord);border-radius:10px;color:var(--text);font-family:var(--fb);font-size:13px;font-weight:600;transition:all 0.3s}
.f-input:focus,.f-select:focus{outline:none;border-color:var(--neon);box-shadow:var(--glow);background:rgba(50,190,143,0.05)}
.f-select option{background:#1b263b;color:var(--text)}
.btn-filter{background:rgba(50,190,143,0.12);border:1.5px solid rgba(50,190,143,0.3);color:var(--neon);padding:11px 22px;border-radius:10px;font-family:var(--fh);font-weight:900;cursor:pointer;transition:all 0.3s;display:flex;align-items:center;gap:8px;white-space:nowrap}
.btn-filter:hover{background:var(--neon);color:var(--bg);box-shadow:var(--glow)}

/* CARDS GRID */
.docs-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(360px,1fr));gap:16px}
.doc-card{background:var(--card2);border:1px solid var(--bord);border-radius:16px;overflow:hidden;transition:all 0.3s;position:relative;cursor:pointer}
.doc-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--neon),var(--cyan))}
.doc-card:hover{transform:translateY(-5px);border-color:rgba(50,190,143,0.4);box-shadow:0 14px 32px rgba(0,0,0,0.4)}
.doc-card.delivering::before{background:linear-gradient(90deg,var(--cyan),var(--blue))}
.doc-card.delivering{border-color:rgba(6,182,212,0.12)}
.doc-card.done::before{background:linear-gradient(90deg,var(--blue),var(--purple))}
.doc-card.done{border-color:rgba(59,130,246,0.12)}
.doc-card.invoiced{opacity:0.6}
.doc-card.invoiced::after{content:'✓ FACTURÉ';position:absolute;top:50%;left:50%;transform:translate(-50%,-50%) rotate(-15deg);font-family:var(--fh);font-size:48px;font-weight:900;color:var(--neon);opacity:0.15;pointer-events:none}

.oc-top{display:flex;align-items:flex-start;gap:10px;padding:14px 16px 10px}
.oc-meta{flex:1;min-width:0}
.oc-num{font-family:var(--fh);font-size:12px;color:var(--gold);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:900}
.oc-sbdg{display:inline-flex;align-items:center;gap:5px;margin-top:4px;padding:4px 10px;border-radius:8px;font-size:10px;font-weight:800}
.s-confirmed{background:rgba(50,190,143,0.1);color:var(--neon);border:1px solid rgba(50,190,143,0.2)}
.s-delivering{background:rgba(6,182,212,0.1);color:var(--cyan);border:1px solid rgba(6,182,212,0.2)}
.s-done{background:rgba(59,130,246,0.1);color:var(--blue);border:1px solid rgba(59,130,246,0.2)}
.oc-time{text-align:right;flex-shrink:0}
.oc-th{font-size:14px;font-weight:700;color:var(--text2);font-family:monospace}
.oc-ago{font-size:10px;color:var(--muted);margin-top:2px}

.oc-cli{display:flex;align-items:center;gap:12px;padding:0 16px 12px}
.oc-av{width:40px;height:40px;border-radius:50%;flex-shrink:0;font-size:16px;font-family:var(--fh);font-weight:900;display:flex;align-items:center;justify-content:center;color:#04090e}
.av-a{background:linear-gradient(135deg,#32be8f,#06b6d4)}
.av-b{background:linear-gradient(135deg,#ffd060,#ff9140)}
.av-c{background:linear-gradient(135deg,#a855f7,#3d8cff)}
.oc-name{font-family:var(--fh);font-size:14px;font-weight:900}
.oc-phone{font-size:11px;color:var(--muted);margin-top:2px}
.oc-pills{display:flex;flex-wrap:wrap;gap:5px;margin-top:5px}
.pill{display:inline-flex;align-items:center;gap:3px;padding:3px 8px;border-radius:8px;font-size:10px;font-weight:700;background:rgba(0,0,0,0.22);border:1px solid rgba(255,255,255,0.05);color:var(--muted)}

.oc-art{padding:0 16px 12px;border-top:1px solid rgba(255,255,255,0.04);padding-top:10px}
.oc-art-ttl{font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.7px;margin-bottom:6px}
.art-chip{display:flex;align-items:center;padding:5px 9px;border-radius:8px;background:rgba(0,0,0,0.18);border:1px solid rgba(255,255,255,0.04);margin-bottom:4px}
.art-name{font-size:13px;color:var(--text2);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-right:8px}
.art-qty{font-size:11px;color:var(--muted);margin-right:10px;white-space:nowrap;flex-shrink:0}
.art-sub{font-size:12px;font-weight:700;color:var(--neon);white-space:nowrap;flex-shrink:0}
.art-more{font-size:11px;color:var(--muted);padding:3px 0 0 9px}

.oc-foot{display:flex;align-items:center;justify-content:space-between;padding:10px 16px;border-top:1px solid rgba(255,255,255,0.04);background:rgba(0,0,0,0.12)}
.oc-total{font-family:var(--fh);font-size:20px;font-weight:900;color:var(--neon)}
.oc-total small{font-size:10px;color:var(--muted);font-family:var(--fb)}
.oc-btns{display:flex;gap:6px}

/* BUTTONS */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:8px 14px;border-radius:9px;border:1.5px solid transparent;cursor:pointer;font-family:var(--fb);font-size:11px;font-weight:700;transition:all .17s;white-space:nowrap;text-decoration:none}
.btn:active{transform:scale(.93)}
.btn-print{background:linear-gradient(135deg,var(--neon),var(--cyan));color:#04090e;border:none;box-shadow:0 3px 12px rgba(50,190,143,0.22);font-family:var(--fh);font-weight:900}
.btn-print:hover{box-shadow:0 5px 18px rgba(50,190,143,0.38);transform:translateY(-1px)}
.btn-cyan{background:rgba(6,182,212,0.12);border-color:rgba(6,182,212,0.3);color:var(--cyan)}
.btn-cyan:hover{background:var(--cyan);color:#04090e}
.btn-blue{background:rgba(59,130,246,0.12);border-color:rgba(59,130,246,0.3);color:var(--blue)}
.btn-blue:hover{background:var(--blue);color:#fff}
.btn-sm{padding:6px 11px;font-size:10px;border-radius:8px}

/* LOGS TABLE */
.logs-table{width:100%;border-collapse:collapse}
.logs-table th{font-family:var(--fh);font-size:11px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:1px;padding:12px 14px;border-bottom:1px solid rgba(255,255,255,0.06);text-align:left;background:rgba(0,0,0,0.15)}
.logs-table td{font-family:var(--fb);font-size:12px;font-weight:500;color:var(--text2);padding:12px 14px;border-bottom:1px solid rgba(255,255,255,0.04);line-height:1.55}
.logs-table tbody tr{transition:all 0.25s}
.logs-table tbody tr:hover{background:rgba(50,190,143,0.04)}
.action-badge{font-family:var(--fb);font-size:10px;font-weight:800;padding:5px 12px;border-radius:20px;white-space:nowrap;display:inline-block}
.action-status{background:rgba(50,190,143,0.14);color:var(--neon)}
.action-invoice{background:rgba(255,208,96,0.14);color:var(--gold)}
.action-view{background:rgba(61,140,255,0.14);color:var(--blue)}

/* EMPTY */
.empty-state{background:var(--card);padding:60px 20px;border-radius:16px;text-align:center;border:1px solid var(--bord);grid-column:1/-1}
.empty-state i{font-size:64px;color:var(--muted);opacity:0.15;margin-bottom:20px}
.empty-state h3{font-family:var(--fh);font-size:20px;color:var(--text);margin-bottom:10px;font-weight:900}
.empty-state p{font-size:13px;color:var(--muted)}

/* SPINNER */
.spin{display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.1);border-top-color:currentColor;border-radius:50%;animation:sp .6s linear infinite;vertical-align:middle}
@keyframes sp{to{transform:rotate(360deg)}}

/* RESPONSIVE */
@media(max-width:768px){
    .wrap{padding:0 0 24px}
    .docs-grid{grid-template-columns:1fr}
    .kpi-strip{grid-template-columns:repeat(2,1fr)}
}
</style>
</head>
<body>
<div class="wrap">

<!-- TOPBAR -->
<div class="topbar">
    <div class="brand">
        <div class="brand-ico">🧾</div>
        <div class="brand-txt">
            <h1>Bon de livraison </h1>
            <p>ESPERANCE H2O · FACTURATION des bons des livraison</p>
        </div>
    </div>
    <div class="divider"></div>
    <div class="live-badge"><div class="live-dot"></div><span id="tb-clock">--:--:--</span></div>
    <div class="tb-spacer"></div>
    <div class="tbtn" onclick="doRefresh()" title="Actualiser"><i class="fas fa-sync-alt" id="ri"></i></div>
    <a href="<?= project_url('orders/admin_orders.php') ?>" class="tbtn" title="Dashboard"><i class="fas fa-gauge-high"></i></a>
    <div class="tb-user">
        <div class="tb-av"><?= strtoupper(mb_substr($user_name,0,1)) ?></div>
        <div class="tb-un"><?= htmlspecialchars(mb_substr($user_name,0,13)) ?></div>
    </div>
    <a href="<?= project_url('auth/logout.php') ?>" class="tbtn" style="color:var(--red)" title="Déconnexion"><i class="fas fa-right-from-bracket"></i></a>
</div>

<!-- STATS -->
<div class="kpi-strip">
    <div class="ks">
        <div class="ks-ico" style="background:rgba(255,208,96,0.14);color:var(--gold)"><i class="fas fa-clock"></i></div>
        <div><div class="ks-val" style="color:var(--gold)" id="sv-co">—</div><div class="ks-lbl">Confirmés</div></div>
    </div>
    <div class="ks">
        <div class="ks-ico" style="background:rgba(6,182,212,0.14);color:var(--cyan)"><i class="fas fa-truck"></i></div>
        <div><div class="ks-val" style="color:var(--cyan)" id="sv-de">—</div><div class="ks-lbl">En livraison</div></div>
    </div>
    <div class="ks">
        <div class="ks-ico" style="background:rgba(61,140,255,0.14);color:var(--blue)"><i class="fas fa-check-circle"></i></div>
        <div><div class="ks-val" style="color:var(--blue)" id="sv-dn">—</div><div class="ks-lbl">Livrés auj.</div></div>
    </div>
    <div class="ks">
        <div class="ks-ico" style="background:rgba(50,190,143,0.14);color:var(--neon)"><i class="fas fa-coins"></i></div>
        <div><div class="ks-val" style="color:var(--neon);font-size:20px" id="sv-ca">—</div><div class="ks-lbl">CA FCFA</div></div>
    </div>
</div>

<!-- TABS -->
<div class="tabs-nav">
    <div class="tab active" id="tab-today" onclick="goTab('today')">
        <i class="fas fa-sun"></i> Bons du jour
        <span class="tbdg" id="bdg-today" style="display:none"></span>
    </div>
    <div class="tab" id="tab-hist" onclick="goTab('hist')">
        <i class="fas fa-clock-rotate-left"></i> Historique traités
    </div>
    <div class="tab" id="tab-logs" onclick="goTab('logs')">
        <i class="fas fa-list-check"></i> Logs complets
    </div>
</div>

<!-- PANEL TODAY -->
<div class="panel active" id="panel-today">
    <div class="filters">
        <div class="filter-group">
            <label><i class="fas fa-magnifying-glass"></i> Recherche</label>
            <input type="text" class="f-input" id="s-today" placeholder="Client, tél, N° bon…" oninput="deb('today')">
        </div>
        <div class="filter-group">
            <label><i class="fas fa-building"></i> Société</label>
            <select class="f-select" id="co-today" onchange="loadOrders('today')">
                <option value="">Toutes</option>
                <?php foreach($companies as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label><i class="fas fa-city"></i> Ville</label>
            <select class="f-select" id="ci-today" onchange="loadOrders('today')">
                <option value="">Toutes</option>
                <?php foreach($cities as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label><i class="fas fa-calendar"></i> Date</label>
            <input type="date" class="f-input" id="d-today" value="<?= date('Y-m-d') ?>" onchange="loadOrders('today')">
        </div>
        <button class="btn-filter" onclick="loadOrders('today')">
            <i class="fas fa-filter"></i> Filtrer
        </button>
    </div>
    <div class="docs-grid" id="grid-today">
        <div class="empty-state"><div class="spin" style="font-size:28px;color:var(--neon)"></div><h3 style="margin-top:16px">Chargement…</h3></div>
    </div>
</div>

<!-- PANEL HISTORIQUE -->
<div class="panel" id="panel-hist">
    <div class="filters">
        <div class="filter-group">
            <label><i class="fas fa-magnifying-glass"></i> Recherche</label>
            <input type="text" class="f-input" id="s-hist" placeholder="Client, tél, N° bon…" oninput="deb('hist')">
        </div>
        <div class="filter-group">
            <label><i class="fas fa-building"></i> Société</label>
            <select class="f-select" id="co-hist" onchange="loadOrders('hist')">
                <option value="">Toutes</option>
                <?php foreach($companies as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label><i class="fas fa-city"></i> Ville</label>
            <select class="f-select" id="ci-hist" onchange="loadOrders('hist')">
                <option value="">Toutes</option>
                <?php foreach($cities as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label><i class="fas fa-calendar"></i> Date</label>
            <input type="date" class="f-input" id="d-hist" value="<?= date('Y-m-d') ?>" onchange="loadOrders('hist')">
        </div>
        <button class="btn-filter" onclick="loadOrders('hist')">
            <i class="fas fa-filter"></i> Filtrer
        </button>
    </div>
    <div class="docs-grid" id="grid-hist">
        <div class="empty-state"><div class="spin" style="font-size:28px;color:var(--neon)"></div><h3 style="margin-top:16px">Chargement…</h3></div>
    </div>
</div>

<!-- PANEL LOGS -->
<div class="panel" id="panel-logs">
    <div class="filters">
        <div class="filter-group">
            <label><i class="fas fa-calendar"></i> Date</label>
            <input type="date" class="f-input" id="d-logs" value="<?= date('Y-m-d') ?>" onchange="loadLogs()">
        </div>
        <button class="btn-filter" onclick="loadLogs()">
            <i class="fas fa-filter"></i> Filtrer
        </button>
    </div>
    <div style="background:var(--card);border:1px solid var(--bord);border-radius:14px;overflow:hidden">
        <div style="overflow-x:auto">
            <table class="logs-table" id="logs-table">
                <thead>
                    <tr>
                        <th>Date/Heure</th>
                        <th>Bon N°</th>
                        <th>Client</th>
                        <th>Action</th>
                        <th>Ancien → Nouveau</th>
                        <th>Utilisateur</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody id="logs-tbody">
                    <tr><td colspan="7" style="text-align:center;padding:32px"><div class="spin" style="font-size:24px;color:var(--neon)"></div></td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

</div>

<script>
var SELF=location.pathname, curTab='today', maxOid=0, pollTmr=null;
var debTmr={today:null,hist:null};
var cache={};

var PAY={cash:'💵 Espèces',mobile_money:'📱 Mobile Money'};
var SL={confirmed:'Confirmé',delivering:'En livraison',done:'Livré'};
var SI={confirmed:'fa-check',delivering:'fa-truck',done:'fa-circle-check'};

function avClass(n){var c=(n||'A')[0].toUpperCase().charCodeAt(0);return c%3===0?'av-a':c%3===1?'av-b':'av-c';}
function fmt(v){v=+v;return v>=1e6?(v/1e6).toFixed(1)+' M':v>=1e3?(v/1e3).toFixed(0)+' k':v.toLocaleString('fr-FR');}
function fmtF(v){return (+v).toLocaleString('fr-FR');}
function fdate(s){return new Date((s||'').replace(' ','T')).toLocaleString('fr-FR',{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'});}
function ftime(s){return new Date((s||'').replace(' ','T')).toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit'});}
function ago(s){var d=Math.floor((Date.now()-new Date((s||'').replace(' ','T')))/1000);
  if(d<60)return 'À l\'instant';if(d<3600)return Math.floor(d/60)+' min';return Math.floor(d/3600)+' h';}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

// HORLOGE
setInterval(()=>{document.getElementById('tb-clock').textContent=new Date().toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit',second:'2-digit'});},1000);

// STATS
function renderStats(s){
  if(!s)return;
  document.getElementById('sv-co').textContent=s.confirmed||0;
  document.getElementById('sv-de').textContent=s.delivering||0;
  document.getElementById('sv-dn').textContent=s.done_today||0;
  document.getElementById('sv-ca').textContent=fmt(s.ca_jour||0);
}

// TABS
function goTab(t){
  curTab=t;
  ['today','hist','logs'].forEach(x=>{
    var p=document.getElementById('panel-'+x);
    var tb=document.getElementById('tab-'+x);
    if(p)p.classList.toggle('active',x===t);
    if(tb)tb.classList.toggle('active',x===t);
  });
  if(t==='today'){var b=document.getElementById('bdg-today');b.style.display='none';b.textContent='';}
  if(t==='logs')loadLogs();
  else loadOrders(t);
}

// LOAD ORDERS
async function loadOrders(tab, silent){
  var gid=tab==='today'?'today':'hist';
  if(!silent){
    document.getElementById('grid-'+gid).innerHTML='<div class="empty-state" style="grid-column:1/-1"><span class="spin" style="font-size:28px;color:var(--neon)"></span><h3 style="margin-top:16px">Chargement…</h3></div>';
  }
  var fd=new FormData();
  fd.append('action','get_orders');
  fd.append('tab', tab==='today'?'today':'history');
  fd.append('company_id', document.getElementById('co-'+tab).value);
  fd.append('city_id',    document.getElementById('ci-'+tab).value);
  fd.append('search',     document.getElementById('s-'+tab).value.trim());
  fd.append('date',       document.getElementById('d-'+tab).value||'<?= date('Y-m-d') ?>');
  if(silent&&maxOid>0) fd.append('since_id',maxOid);
  try{
    var res=await fetch(SELF,{method:'POST',body:fd}); var d=await res.json();
    if(!d.success){if(!silent)alert('Erreur: '+(d.message||''));return;}
    if(d.max_id>0)maxOid=d.max_id;
    renderStats(d.stats);
    (d.orders||[]).forEach(o=>cache[o.id]=o);
    renderGrid(gid, d.orders||[], tab);
    if(silent&&d.new_orders&&d.new_orders.length>0){
      beep();
      var b=document.getElementById('bdg-today');
      b.textContent=parseInt(b.textContent||0)+d.new_orders.length;
      b.style.display='inline-flex';
    }
  }catch(ex){if(!silent)alert('Erreur réseau: '+ex.message);}
}

function renderGrid(gid,orders,tab){
  var el=document.getElementById('grid-'+gid);
  if(!orders.length){
    var msg=tab==='today'?'Aucun bon confirmé':'Aucun bon traité';
    el.innerHTML='<div class="empty-state" style="grid-column:1/-1"><i class="fas fa-'+(tab==='today'?'clock':'box')+'"></i><h3>Rien à afficher</h3><p>'+msg+'</p></div>';
    return;
  }
  el.innerHTML=orders.map((o,i)=>buildCard(o,i,tab)).join('');
}

function buildCard(o,i,tab){
  var items=o.items||[];
  var iHtml=items.slice(0,3).map(it=>'<div class="art-chip"><div class="art-name">'+esc(it.product_name)+'</div><div class="art-qty">× '+it.quantity+'</div><div class="art-sub">'+fmtF(it.subtotal)+'</div></div>').join('')+(items.length>3?'<div class="art-more">+ '+(items.length-3)+' autres</div>':'');

  var btns='';
  if(o.status==='confirmed')
    btns+='<button class="btn btn-cyan btn-sm" onclick="markSt('+o.id+',\'delivering\',event)" title="En livraison"><i class="fas fa-truck"></i></button>';
  if(o.status==='confirmed'||o.status==='delivering')
    btns+='<button class="btn btn-blue btn-sm" onclick="markSt('+o.id+',\'done\',event)" title="Livré"><i class="fas fa-circle-check"></i></button>';
  btns+='<button class="btn btn-print btn-sm" onclick="openTicket('+o.id+',event)"><i class="fas fa-print"></i> Ticket</button>';

  var invoicedClass = o.invoiced_at ? ' invoiced' : '';
  
  return '<div class="doc-card '+o.status+invoicedClass+'" id="ocard-'+o.id+'" style="animation-delay:'+i*.03+'s" onclick="openTicket('+o.id+')">'+
    '<div class="oc-top">'+
      '<div class="oc-meta">'+
        '<div class="oc-num">🧾 '+esc(o.order_number)+'</div>'+
        '<span class="oc-sbdg s-'+o.status+'"><i class="fas '+SI[o.status]+'"></i> '+SL[o.status]+'</span>'+
        (o.invoiced_at ? '<div style="font-size:9px;color:var(--neon);margin-top:3px"><i class="fas fa-check-circle"></i> Facturé le '+fdate(o.invoiced_at)+'</div>' : '')+
      '</div>'+
      '<div class="oc-time"><div class="oc-th">'+ftime(o.created_at)+'</div><div class="oc-ago">'+ago(o.created_at)+'</div></div>'+
    '</div>'+
    '<div class="oc-cli">'+
      '<div class="oc-av '+avClass(o.client_name)+'">'+(o.client_name||'?')[0].toUpperCase()+'</div>'+
      '<div>'+
        '<div class="oc-name">'+esc(o.client_name||'—')+'</div>'+
        '<div class="oc-phone"><i class="fas fa-phone" style="font-size:9px"></i> '+esc(o.client_phone||'—')+'</div>'+
        '<div class="oc-pills">'+
          '<span class="pill">'+esc(o.company_name||'—')+'</span>'+
          '<span class="pill">'+esc(o.city_name||'—')+'</span>'+
          '<span class="pill">'+(PAY[o.payment_method]||esc(o.payment_method||'—'))+'</span>'+
        '</div>'+
      '</div>'+
    '</div>'+
    '<div class="oc-art"><div class="oc-art-ttl">Articles ('+items.length+')</div>'+iHtml+'</div>'+
    '<div class="oc-foot" onclick="event.stopPropagation()">'+
      '<div class="oc-total">'+fmtF(o.total_amount)+' <small>FCFA</small></div>'+
      '<div class="oc-btns">'+btns+'</div>'+
    '</div>'+
  '</div>';
}

// LOAD LOGS
async function loadLogs(){
  document.getElementById('logs-tbody').innerHTML='<tr><td colspan="7" style="text-align:center;padding:32px"><div class="spin" style="font-size:24px;color:var(--neon)"></div></td></tr>';
  var fd=new FormData();
  fd.append('action','get_logs');
  fd.append('date',document.getElementById('d-logs').value||'<?= date('Y-m-d') ?>');
  try{
    var res=await fetch(SELF,{method:'POST',body:fd}); var d=await res.json();
    if(!d.success){alert('Erreur: '+(d.message||''));return;}
    renderLogs(d.logs||[]);
  }catch(ex){alert('Erreur réseau: '+ex.message);}
}

function renderLogs(logs){
  var tbody=document.getElementById('logs-tbody');
  if(!logs.length){
    tbody.innerHTML='<tr><td colspan="7" style="text-align:center;padding:32px;color:var(--muted)">Aucun log pour cette date</td></tr>';
    return;
  }
  tbody.innerHTML=logs.map(l=>{
    var actionClass = l.action.includes('STATUS') ? 'action-status' : l.action.includes('INVOICE') ? 'action-invoice' : 'action-view';
    return '<tr>'+
      '<td>'+fdate(l.created_at)+'</td>'+
      '<td style="font-family:monospace;color:var(--gold)">'+esc(l.order_number||'—')+'</td>'+
      '<td>'+esc(l.client_name||'—')+'</td>'+
      '<td><span class="action-badge '+actionClass+'">'+esc(l.action)+'</span></td>'+
      '<td>'+(l.old_status?'<span style="color:var(--muted)">'+esc(l.old_status)+'</span> → ':'')+
        (l.new_status?'<span style="color:var(--neon)">'+esc(l.new_status)+'</span>':'—')+'</td>'+
      '<td>'+esc(l.user_name||'—')+'</td>'+
      '<td style="font-family:monospace;font-size:11px;color:var(--muted)">'+esc(l.ip_address||'—')+'</td>'+
    '</tr>';
  }).join('');
}

// CHANGE STATUS
async function markSt(oid,to,e){
  if(e)e.stopPropagation();
  var fd=new FormData();fd.append('action','mark_status');fd.append('order_id',oid);fd.append('to',to);
  try{
    var r=await fetch(SELF,{method:'POST',body:fd}); var d=await r.json();
    if(d.success){
      if(cache[oid])cache[oid].status=to;
      loadOrders(curTab);
    }else alert('Erreur: '+(d.message||''));
  }catch(ex){alert('Erreur: '+ex.message);}
}

// OPEN TICKET
function openTicket(oid,e){
  if(e)e.stopPropagation();
  window.open('ticket.php?order_id='+oid,'_blank');
}

// BEEP
function beep(){
  try{var ac=new(window.AudioContext||window.webkitAudioContext)();
    [880,1100].forEach((f,i)=>{var o=ac.createOscillator(),g=ac.createGain();
      o.connect(g);g.connect(ac.destination);o.type='sine';o.frequency.value=f;
      g.gain.setValueAtTime(.2,ac.currentTime+i*.18);
      g.gain.exponentialRampToValueAtTime(.001,ac.currentTime+i*.18+.2);
      o.start(ac.currentTime+i*.18);o.stop(ac.currentTime+i*.18+.25);});}catch(e){}
}

function deb(tab){clearTimeout(debTmr[tab]);debTmr[tab]=setTimeout(()=>loadOrders(tab),380);}
function doRefresh(){
  var ri=document.getElementById('ri');ri.classList.add('fa-spin');
  loadOrders(curTab).then(()=>setTimeout(()=>ri.classList.remove('fa-spin'),400));
}

function startPoll(){clearInterval(pollTmr);pollTmr=setInterval(()=>loadOrders('today',true),15000);}

(async()=>{await loadOrders('today');startPoll();})();
</script>
</body>
</html>
