<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

/**
 * ═══════════════════════════════════════════════════════════════════
 * DASHBOARD RH — ESPERANCE H2O  v4.0
 * Police C059 Bold · Navigation complète · Responsive
 * ═══════════════════════════════════════════════════════════════════
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
Middleware::role(['developer', 'admin', 'manager','staff','employee']);

$pdo = DB::getConnection();

if (empty($_SESSION['csrf_token']))
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

date_default_timezone_set('Africa/Abidjan');

$today = date('Y-m-d');
$month = date('Y-m');

/* ── Stats du jour ── */
$s = $pdo->prepare("SELECT COUNT(*) total_present,
    SUM(status='retard') total_late,
    SUM(check_out IS NOT NULL) total_departed,
    SUM(check_out IS NULL) still_working
    FROM attendance WHERE work_date=?");
$s->execute([$today]);
$ds = $s->fetch(PDO::FETCH_ASSOC);

$total_emp  = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE status='actif'")->fetchColumn();
$total_abs  = max(0, $total_emp - ($ds['total_present'] ?? 0));

$s = $pdo->prepare("SELECT COALESCE(SUM(penalty_amount),0) FROM attendance WHERE work_date LIKE ?");
$s->execute([$month."%"]); $penalties = (float)$s->fetchColumn();

$s = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM overtime WHERE work_date LIKE ?");
$s->execute([$month."%"]); $overtime = (float)$s->fetchColumn();

$pend_perm = (int)$pdo->query("SELECT COUNT(*) FROM permissions WHERE status='en_attente'")->fetchColumn();
$pend_adv  = (int)$pdo->query("SELECT COUNT(*) FROM advances WHERE status='en_attente'")->fetchColumn();

/* ── Présences du jour ── */
$s = $pdo->prepare("SELECT a.*,e.full_name,e.employee_code,p.title pos
    FROM attendance a JOIN employees e ON a.employee_id=e.id
    JOIN positions p ON e.position_id=p.id
    WHERE a.work_date=? ORDER BY a.check_in ASC LIMIT 12");
$s->execute([$today]); $today_att = $s->fetchAll(PDO::FETCH_ASSOC);

/* ── Graphique retards du mois ── */
$s = $pdo->prepare("SELECT work_date, COUNT(*) late_count FROM attendance
    WHERE work_date LIKE ? AND status='retard' GROUP BY work_date ORDER BY work_date ASC");
$s->execute([$month."%"]); $monthly_lates = $s->fetchAll(PDO::FETCH_ASSOC);

/* ── Graphique 7 jours ── */
$s = $pdo->prepare("SELECT work_date, COUNT(*) present,
    SUM(status='retard') late FROM attendance
    WHERE work_date>=DATE_SUB(CURDATE(),INTERVAL 7 DAY)
    GROUP BY work_date ORDER BY work_date ASC");
$s->execute(); $week_att = $s->fetchAll(PDO::FETCH_ASSOC);

/* ── Top retardataires ── */
$s = $pdo->prepare("SELECT e.full_name, COUNT(*) late_count, SUM(a.penalty_amount) total_penalty
    FROM attendance a JOIN employees e ON a.employee_id=e.id
    WHERE a.work_date LIKE ? AND a.status='retard'
    GROUP BY a.employee_id ORDER BY late_count DESC LIMIT 5");
$s->execute([$month."%"]); $top_late = $s->fetchAll(PDO::FETCH_ASSOC);

/* ── Top heures sup ── */
$s = $pdo->prepare("SELECT e.full_name, SUM(o.hours) total_hours, SUM(o.total_amount) total_amount
    FROM overtime o JOIN employees e ON o.employee_id=e.id
    WHERE o.work_date LIKE ? GROUP BY o.employee_id ORDER BY total_hours DESC LIMIT 5");
$s->execute([$month."%"]); $top_ot = $s->fetchAll(PDO::FETCH_ASSOC);

/* ── Permissions récentes ── */
$recent_perm = $pdo->query("SELECT p.*,e.full_name FROM permissions p
    JOIN employees e ON p.employee_id=e.id ORDER BY p.created_at DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);

/* ── Avances récentes ── */
$recent_adv = $pdo->query("SELECT a.*,e.full_name FROM advances a
    JOIN employees e ON a.employee_id=e.id ORDER BY a.created_at DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);

/* ══ STOCK ══ */

/* Stats stock globales */
$s = $pdo->query("SELECT COUNT(DISTINCT p.id) total_products, COALESCE(SUM(s.quantity),0) total_stock
    FROM products p LEFT JOIN stocks s ON s.product_id=p.id");
$stock_stats = $s->fetch(PDO::FETCH_ASSOC);

/* Mouvements stock 30 jours (graphique area) */
$stock_movements = $pdo->query("
    SELECT DATE(sm.movement_date) m_date,
        SUM(CASE WHEN sm.type='entry' THEN sm.quantity ELSE 0 END) entries,
        SUM(CASE WHEN sm.type='exit'  THEN sm.quantity ELSE 0 END) exits
    FROM stock_movements sm
    JOIN products p ON p.id=sm.product_id
    GROUP BY m_date ORDER BY m_date ASC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
/* Top produits vendus - depuis stock_movements type='exit' (VENTE-xxx) */
$top_products = $pdo->query("
    SELECT p.name, SUM(sm.quantity) total_sold
    FROM stock_movements sm
    JOIN products p ON p.id = sm.product_id
    WHERE sm.type = 'exit'
      AND sm.reference LIKE 'VENTE-%'
    GROUP BY sm.product_id
    ORDER BY total_sold DESC
    LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);
/* Alertes stock bas */
$low_stock = $pdo->query("
    SELECT p.name, s.quantity, p.alert_quantity
    FROM stocks s JOIN products p ON p.id=s.product_id
    WHERE s.quantity <= p.alert_quantity
    ORDER BY s.quantity ASC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);

/* Mouvements récents */
$recent_movements = $pdo->query("
    SELECT p.name, sm.type, sm.quantity, sm.movement_date
    FROM stock_movements sm JOIN products p ON p.id=sm.product_id
    ORDER BY sm.movement_date DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

$days_in_month  = (int)date('t');
$current_day    = (int)date('j');
$month_progress = round($current_day / $days_in_month * 100);

$user_name = $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>RH Dashboard — ESPERANCE H2O</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=Source+Serif+4:wght@700;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
/* ══════════════════════════════════════════════
   FONT C059  (serif natif système + fallbacks)
══════════════════════════════════════════════ */
@font-face {
    font-family: 'C059';
    src: local('C059-Bold'), local('C059 Bold'), local('C059BT-Bold'),
         local('Century Schoolbook'), local('Century Old Style Std');
    font-weight: 700 900;
    font-style: normal;
}

:root {
    --bg    : #04090e;
    --surf  : #081420;
    --card  : #0d1e2c;
    --bord  : rgba(50,190,143,0.16);
    --neon  : #32be8f;
    --neon2 : #19ffa3;
    --red   : #ff3553;
    --orange: #ff9140;
    --blue  : #3d8cff;
    --gold  : #ffd060;
    --text  : #e0f2ea;
    --text2 : #b8d8cc;
    --muted : #5a8070;
    --glow      : 0 0 26px rgba(50,190,143,0.45);
    --glow-r    : 0 0 26px rgba(255,53,83,0.45);
    --glow-gold : 0 0 26px rgba(255,208,96,0.4);

    /* C059 en priorité absolue */
    --fh: 'C059','Source Serif 4','Playfair Display','Book Antiqua',
          'Palatino Linotype', Georgia, serif;
    --fb: 'Inter', 'Segoe UI', system-ui, sans-serif;
}

*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
html { scroll-behavior:smooth; }

body {
    font-family: var(--fb);
    font-size: 15px;
    line-height: 1.7;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    overflow-x: hidden;
}

/* Fond ambiance */
body::before {
    content:''; position:fixed; inset:0; z-index:0; pointer-events:none;
    background:
        radial-gradient(ellipse 65% 42% at 4%  8%,  rgba(50,190,143,0.08) 0%, transparent 62%),
        radial-gradient(ellipse 52% 36% at 96% 88%, rgba(61,140,255,0.07) 0%, transparent 62%),
        radial-gradient(ellipse 38% 28% at 52% 52%, rgba(255,53,83,0.04)  0%, transparent 70%);
}
body::after {
    content:''; position:fixed; inset:0; z-index:0; pointer-events:none;
    background-image:
        linear-gradient(rgba(50,190,143,0.022) 1px, transparent 1px),
        linear-gradient(90deg, rgba(50,190,143,0.022) 1px, transparent 1px);
    background-size: 46px 46px;
}

.wrap {
    position:relative; z-index:1;
    max-width: 1640px;
    margin: 0 auto;
    padding: 18px 18px 48px;
}

/* ══════════════════════════════════════════════
   TOPBAR
══════════════════════════════════════════════ */
.topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 18px;
    background: rgba(8,20,32,0.94);
    border: 1px solid var(--bord);
    border-radius: 18px;
    padding: 20px 30px;
    margin-bottom: 18px;
    backdrop-filter: blur(24px);
}

.brand { display:flex; align-items:center; gap:18px; flex-shrink:0; }
.brand-ico {
    width:52px; height:52px;
    background: linear-gradient(135deg, var(--neon), var(--blue));
    border-radius:14px;
    display:flex; align-items:center; justify-content:center;
    font-size:25px; color:#fff;
    box-shadow: var(--glow);
    animation: breathe 3.2s ease-in-out infinite;
    flex-shrink:0;
}
@keyframes breathe {
    0%,100% { box-shadow: 0 0 14px rgba(50,190,143,0.4); }
    50%      { box-shadow: 0 0 38px rgba(50,190,143,0.85); }
}
.brand-txt h1 {
    font-family: var(--fh);
    font-size: 22px;
    font-weight: 900;
    color: var(--text);
    letter-spacing: 0.5px;
    line-height: 1.25;
}
.brand-txt p {
    font-family: var(--fb);
    font-size: 11px;
    font-weight: 700;
    color: var(--neon);
    letter-spacing: 2.8px;
    text-transform: uppercase;
    margin-top: 4px;
}

.clock { text-align:center; flex-shrink:0; }
.clock-time {
    font-family: var(--fh);
    font-size: 34px;
    font-weight: 900;
    color: var(--neon);
    letter-spacing: 5px;
    text-shadow: 0 0 24px rgba(50,190,143,0.55);
    line-height: 1;
}
.clock-date {
    font-family: var(--fb);
    font-size: 12px;
    font-weight: 600;
    color: var(--muted);
    letter-spacing: 1.5px;
    text-transform: uppercase;
    margin-top: 6px;
}

.user-badge {
    display:flex; align-items:center; gap:10px;
    background: linear-gradient(135deg, var(--neon), var(--blue));
    color: var(--bg);
    padding: 11px 22px;
    border-radius: 32px;
    font-family: var(--fh);
    font-size: 14px;
    font-weight: 900;
    box-shadow: var(--glow);
    flex-shrink:0;
    letter-spacing: 0.5px;
}

/* ══════════════════════════════════════════════
   BARRE DE NAVIGATION
══════════════════════════════════════════════ */
.nav-bar {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    background: rgba(8,20,32,0.90);
    border: 1px solid var(--bord);
    border-radius: 16px;
    padding: 16px 24px;
    margin-bottom: 20px;
    backdrop-filter: blur(20px);
}

/* ── Combobox ── */
.nav-select {
    flex: 1 1 240px;
    min-width: 200px;
    max-width: 340px;
    padding: 12px 42px 12px 16px;
    background: rgba(50,190,143,0.07);
    border: 1.5px solid var(--bord);
    border-radius: 12px;
    color: var(--text);
    font-family: var(--fb);
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    appearance: none;
    -webkit-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9' viewBox='0 0 14 9'%3E%3Cpath d='M1 1l6 6 6-6' stroke='%2332be8f' stroke-width='2' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 15px center;
    background-size: 13px;
    transition: all 0.3s;
    line-height: 1.5;
}
.nav-select:focus {
    outline: none;
    border-color: var(--neon);
    box-shadow: var(--glow);
    background-color: rgba(50,190,143,0.1);
}
.nav-select option { background: #0d1e2c; color: var(--text); padding: 10px 14px; font-size:14px; }
.nav-select optgroup { color: var(--neon); font-family: var(--fb); font-size:12px; font-style:normal; font-weight:800; letter-spacing:1px; }

.sep { width:1px; height:36px; background:var(--bord); flex-shrink:0; }

/* ── Boutons nav ── */
.nb {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 11px 20px;
    border-radius: 12px;
    border: 1.5px solid var(--bord);
    background: rgba(50,190,143,0.07);
    color: var(--text2);
    font-family: var(--fh);
    font-size: 13px;
    font-weight: 900;
    text-decoration: none;
    white-space: nowrap;
    letter-spacing: 0.4px;
    line-height: 1.4;
    transition: all 0.28s cubic-bezier(0.23,1,0.32,1);
}
.nb i { font-size: 14px; flex-shrink:0; }
.nb:hover {
    background: var(--neon);
    color: var(--bg);
    border-color: var(--neon);
    box-shadow: var(--glow);
    transform: translateY(-2px);
}
.nb.red  { border-color:rgba(255,53,83,0.3);   color:var(--red);  background:rgba(255,53,83,0.07);  }
.nb.red:hover  { background:var(--red);  color:#fff;         border-color:var(--red);  box-shadow:var(--glow-r);    }
.nb.gold { border-color:rgba(255,208,96,0.3);  color:var(--gold); background:rgba(255,208,96,0.07); }
.nb.gold:hover { background:var(--gold); color:var(--bg);    border-color:var(--gold); box-shadow:var(--glow-gold); }

/* ══════════════════════════════════════════════
   COUNTDOWN BANNER
══════════════════════════════════════════════ */
.cdbanner {
    display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap;
    gap:28px;
    background: linear-gradient(135deg, rgba(50,190,143,0.08), rgba(61,140,255,0.08));
    border: 1px solid var(--bord);
    border-radius: 18px;
    padding: 26px 34px;
    margin-bottom: 22px;
    position:relative; overflow:hidden;
}
.cdbanner::before {
    content:''; position:absolute; left:0; top:0; bottom:0; width:5px;
    background: linear-gradient(to bottom, var(--neon), var(--blue));
    border-radius: 5px 0 0 5px;
}

.cd-label {
    font-family: var(--fh);
    font-size: 12px; font-weight:900;
    color: var(--muted); text-transform:uppercase; letter-spacing:2.5px;
    margin-bottom: 12px;
}
.cd-units { display:flex; gap:12px; }
.cd-unit {
    text-align:center;
    background: rgba(0,0,0,0.38);
    border: 1px solid var(--bord);
    border-radius: 13px;
    padding: 14px 20px;
    min-width: 76px;
}
.cd-val {
    font-family: var(--fh);
    font-size: 32px; font-weight:900;
    color: var(--neon); line-height:1; display:block;
    text-shadow: 0 0 18px rgba(50,190,143,0.55);
}
.cd-lab {
    font-family: var(--fb);
    font-size: 10px; font-weight:700;
    color: var(--muted); text-transform:uppercase; letter-spacing:1.5px;
    margin-top: 6px; display:block;
}

.prog-wrap { flex:1 1 260px; max-width:320px; }
.prog-head { display:flex; justify-content:space-between; font-family:var(--fb); font-size:13px; font-weight:700; color:var(--muted); margin-bottom:10px; }
.prog-bar  { height:9px; background:rgba(255,255,255,0.06); border-radius:20px; overflow:hidden; }
.prog-fill { height:100%; background:linear-gradient(90deg,var(--neon),var(--blue)); border-radius:20px; position:relative; }
.prog-fill::after { content:''; position:absolute; right:0; top:0; bottom:0; width:22px; background:#fff; opacity:.22; filter:blur(4px); animation:shine 2.5s ease-in-out infinite; }
@keyframes shine { 0%,100%{opacity:.12} 50%{opacity:.42} }
.prog-sub  { font-family:var(--fb); font-size:13px; font-weight:600; color:var(--muted); margin-top:9px; line-height:1.6; }

.fin-sum { text-align:right; }
.fin-lbl { font-family:var(--fh); font-size:11px; font-weight:900; color:var(--muted); text-transform:uppercase; letter-spacing:2px; margin-bottom:8px; line-height:1.5; }
.fin-penalty { font-family:var(--fh); font-size:30px; font-weight:900; color:var(--red); line-height:1; }
.fin-penalty span { font-size:16px; }
.fin-ot { font-family:var(--fb); font-size:14px; font-weight:700; color:var(--muted); margin-top:9px; line-height:1.6; }
.fin-ot span { color:var(--neon); }

/* ══════════════════════════════════════════════
   KPI GRID
══════════════════════════════════════════════ */
.kpi-grid {
    display:grid;
    grid-template-columns: repeat(4, 1fr);
    gap:16px;
    margin-bottom:22px;
}

.kpi {
    background: var(--card);
    border: 1px solid var(--bord);
    border-radius: 18px;
    padding: 26px 22px;
    position:relative; overflow:hidden;
    cursor:default;
    transition: all 0.38s cubic-bezier(0.23,1,0.32,1);
}
.kpi::after {
    content:''; position:absolute; top:0; left:0; right:0; height:2px;
    background: linear-gradient(90deg, transparent, var(--kc, var(--neon)), transparent);
    opacity:0; transition:opacity 0.3s;
}
.kpi:hover::after { opacity:1; }
.kpi:hover {
    transform: translateY(-7px);
    box-shadow: 0 24px 48px rgba(0,0,0,0.48), 0 0 28px var(--ks, rgba(50,190,143,0.28));
    border-color: rgba(50,190,143,0.28);
}
.kpi.red    { --kc:var(--red);    --ks:rgba(255,53,83,0.28);   }
.kpi.orange { --kc:var(--orange); --ks:rgba(255,145,64,0.28);  }
.kpi.blue   { --kc:var(--blue);   --ks:rgba(61,140,255,0.28);  }
.kpi.gold   { --kc:var(--gold);   --ks:rgba(255,208,96,0.28);  }

.kpi-top { display:flex; align-items:flex-start; justify-content:space-between; gap:10px; margin-bottom:20px; }
.kpi-ico {
    width:50px; height:50px; border-radius:14px;
    display:flex; align-items:center; justify-content:center;
    font-size:22px; flex-shrink:0;
}
.ico-g { background:rgba(50,190,143,0.14); color:var(--neon);   }
.ico-r { background:rgba(255,53,83,0.14);  color:var(--red);    }
.ico-o { background:rgba(255,145,64,0.14); color:var(--orange); }
.ico-b { background:rgba(61,140,255,0.14); color:var(--blue);   }
.ico-d { background:rgba(255,208,96,0.14); color:var(--gold);   }

.kpi-tag {
    font-family: var(--fb);
    font-size:11px; font-weight:800;
    padding:5px 13px; border-radius:20px;
    white-space:nowrap; line-height:1.5;
    letter-spacing:0.4px;
}
.tg  { background:rgba(50,190,143,0.12); color:var(--neon);   }
.tgr { background:rgba(255,53,83,0.12);  color:var(--red);    }
.tgo { background:rgba(255,145,64,0.12); color:var(--orange); }
.tgb { background:rgba(61,140,255,0.12); color:var(--blue);   }
.tgd { background:rgba(255,208,96,0.12); color:var(--gold);   }

.kpi-val {
    font-family: var(--fh);
    font-size: 44px; font-weight:900;
    color: var(--text); line-height:1; margin-bottom:10px;
}
.kpi-lbl {
    font-family: var(--fh);
    font-size: 13px; font-weight:900;
    color: var(--text2);
    text-transform: uppercase; letter-spacing:1.2px; line-height:1.5;
}
.kpi-sub {
    font-family: var(--fb);
    font-size: 12px; font-weight:500; color:var(--muted);
    margin-top:16px; padding-top:14px;
    border-top:1px solid rgba(255,255,255,0.05);
    line-height:1.65;
}

/* ══════════════════════════════════════════════
   ALERTE
══════════════════════════════════════════════ */
.alert-box {
    display:flex; align-items:center; gap:18px; flex-wrap:wrap;
    background:rgba(255,208,96,0.07);
    border:1px solid rgba(255,208,96,0.22);
    border-radius:14px; padding:18px 26px; margin-bottom:22px;
}
.alert-box i { color:var(--gold); font-size:24px; flex-shrink:0; }
.alert-box span {
    font-family: var(--fb);
    font-size:14px; font-weight:700; color:var(--gold);
    line-height:1.65; flex:1;
}
.alert-box a {
    font-family: var(--fh);
    font-size:13px; font-weight:900;
    color:var(--gold); text-decoration:none;
    border:1px solid rgba(255,208,96,0.3);
    padding:9px 20px; border-radius:10px;
    white-space:nowrap; transition:all 0.3s; letter-spacing:0.4px;
}
.alert-box a:hover { background:var(--gold); color:var(--bg); }

/* ══════════════════════════════════════════════
   QUICK ACTIONS
══════════════════════════════════════════════ */
.qa-grid {
    display:grid;
    grid-template-columns: repeat(4, 1fr);
    gap:14px; margin-bottom:22px;
}
.qa {
    background:var(--card); border:1px solid var(--bord);
    border-radius:16px; padding:24px 16px;
    text-align:center; text-decoration:none;
    color:var(--text2);
    display:flex; flex-direction:column; align-items:center; gap:13px;
    transition:all 0.32s cubic-bezier(0.23,1,0.32,1);
}
.qa:hover {
    transform:translateY(-5px);
    border-color:rgba(50,190,143,0.36);
    box-shadow:0 16px 36px rgba(0,0,0,0.38), var(--glow);
    color:var(--neon);
}
.qa-ico {
    width:54px; height:54px; border-radius:16px;
    display:flex; align-items:center; justify-content:center;
    font-size:24px; transition:transform 0.3s; flex-shrink:0;
}
.qa:hover .qa-ico { transform:scale(1.2); }
.qa-lbl {
    font-family: var(--fh);
    font-size:13px; font-weight:900;
    line-height:1.5; text-align:center; letter-spacing:0.4px;
}
.qa-lbl small {
    display:block; font-family:var(--fb);
    font-size:11px; font-weight:600;
    color:var(--muted); margin-top:4px; letter-spacing:0;
}

/* ══════════════════════════════════════════════
   PANELS + GRILLES
══════════════════════════════════════════════ */
.row2 { display:grid; grid-template-columns:1fr 1fr; gap:18px; margin-bottom:18px; }
.row3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:18px; margin-bottom:18px; }

.panel {
    background:var(--card); border:1px solid var(--bord);
    border-radius:18px; overflow:hidden; transition:border-color 0.3s;
}
.panel:hover { border-color:rgba(50,190,143,0.26); }

.ph {
    display:flex; align-items:center; justify-content:space-between; gap:14px;
    padding:20px 24px;
    border-bottom:1px solid rgba(255,255,255,0.05);
    background:rgba(0,0,0,0.18);
    flex-wrap:wrap;
}
.ph-title {
    font-family: var(--fh);
    font-size:15px; font-weight:900;
    color:var(--text);
    display:flex; align-items:center; gap:11px;
    letter-spacing:0.4px; line-height:1.4;
}
.dot {
    width:9px; height:9px; border-radius:50%;
    background:var(--neon); box-shadow:0 0 9px var(--neon);
    flex-shrink:0; animation:pdot 2.2s infinite;
}
@keyframes pdot { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.5;transform:scale(.75)} }
.dot.r { background:var(--red);    box-shadow:0 0 9px var(--red);    }
.dot.g { background:var(--gold);   box-shadow:0 0 9px var(--gold);   }
.dot.b { background:var(--blue);   box-shadow:0 0 9px var(--blue);   }

.pbadge {
    font-family:var(--fb); font-size:11px; font-weight:800;
    padding:5px 14px; border-radius:20px; white-space:nowrap;
    background:rgba(50,190,143,0.12); color:var(--neon); letter-spacing:0.5px;
}
.pbadge.r { background:rgba(255,53,83,0.12);  color:var(--red);  }
.pbadge.g { background:rgba(255,208,96,0.12); color:var(--gold); }
.pbadge.b { background:rgba(61,140,255,0.12); color:var(--blue); }

.ph-link {
    font-family:var(--fh); font-size:12px; font-weight:900;
    color:var(--neon); text-decoration:none; white-space:nowrap;
    letter-spacing:0.5px; transition:color 0.2s;
}
.ph-link:hover { color:var(--neon2); }

.pb { padding:18px 20px; }
.chart-box { position:relative; height:260px; width:100%; }

/* ── Attendance list ── */
.att-list { list-style:none; }
.att-item {
    display:flex; align-items:center; gap:16px;
    padding:14px 6px;
    border-bottom:1px solid rgba(255,255,255,0.04);
    border-radius:8px; transition:all 0.25s;
}
.att-item:last-child { border-bottom:none; }
.att-item:hover { background:rgba(50,190,143,0.05); padding-left:13px; }

.av {
    width:42px; height:42px; border-radius:12px; flex-shrink:0;
    background:linear-gradient(135deg,var(--neon),var(--blue));
    display:flex; align-items:center; justify-content:center;
    font-family:var(--fh); font-weight:900; font-size:14px; color:var(--bg);
}
.av.late { background:linear-gradient(135deg,var(--red),var(--orange)); }

.ai { flex:1; min-width:0; }
.ai-name {
    font-family:var(--fh); font-size:14px; font-weight:900;
    color:var(--text); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; line-height:1.35;
}
.ai-pos {
    font-family:var(--fb); font-size:12px; font-weight:500;
    color:var(--muted); margin-top:4px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; line-height:1.5;
}

.at-right { text-align:right; flex-shrink:0; }
.at-time {
    font-family:var(--fh); font-size:18px; font-weight:900;
    color:var(--neon); line-height:1;
}
.at-time.late { color:var(--red); }
.at-badge {
    font-family:var(--fb); font-size:10px; font-weight:800;
    padding:3px 10px; border-radius:10px;
    display:inline-block; margin-top:6px; letter-spacing:0.5px;
}
.ab-p { background:rgba(50,190,143,0.14); color:var(--neon); }
.ab-r { background:rgba(255,53,83,0.14);  color:var(--red);  }
.ab-o { background:rgba(61,140,255,0.14); color:var(--blue); }

/* ── Ranking ── */
.rank-item {
    display:flex; align-items:center; gap:14px;
    padding:14px 6px;
    border-bottom:1px solid rgba(255,255,255,0.04);
    border-radius:8px; transition:all 0.25s;
}
.rank-item:last-child { border-bottom:none; }
.rank-item:hover { background:rgba(255,53,83,0.04); padding-left:12px; }

.rk-n {
    font-family:var(--fh); font-size:22px; font-weight:900;
    width:34px; text-align:center; color:var(--muted);
}
.rk-n.top { color:var(--red); text-shadow:0 0 14px rgba(255,53,83,0.6); }

.rk-info { flex:1; min-width:0; }
.rk-name {
    font-family:var(--fh); font-size:14px; font-weight:900;
    color:var(--text); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; line-height:1.35;
}
.rk-cnt {
    font-family:var(--fb); font-size:12px; font-weight:600;
    color:var(--muted); margin-top:4px; line-height:1.5;
}
.rk-bar-w { flex:1; height:6px; background:rgba(255,255,255,0.06); border-radius:20px; overflow:hidden; }
.rk-bar   { height:100%; background:linear-gradient(90deg,var(--red),var(--orange)); border-radius:20px; }
.rk-amt {
    font-family:var(--fh); font-size:13px; font-weight:900;
    color:var(--red); text-align:right; flex-shrink:0; min-width:90px; line-height:1.4;
}

/* ── Overtime ── */
.ot-item {
    display:flex; align-items:center; justify-content:space-between; gap:16px;
    padding:14px 6px;
    border-bottom:1px solid rgba(255,255,255,0.04);
    border-radius:8px; transition:all 0.25s;
}
.ot-item:last-child { border-bottom:none; }
.ot-item:hover { background:rgba(50,190,143,0.04); padding-left:12px; }
.ot-name {
    font-family:var(--fh); font-size:14px; font-weight:900;
    color:var(--text); line-height:1.35;
}
.ot-hrs {
    font-family:var(--fb); font-size:12px; font-weight:600;
    color:var(--muted); margin-top:4px; line-height:1.5;
}
.ot-amt {
    font-family:var(--fh); font-size:17px; font-weight:900;
    color:var(--neon); white-space:nowrap; text-align:right;
}

/* ── Requests ── */
.req-item {
    display:flex; align-items:center; gap:14px;
    padding:14px 6px;
    border-bottom:1px solid rgba(255,255,255,0.04);
    border-radius:8px; transition:all 0.25s;
}
.req-item:last-child { border-bottom:none; }
.req-item:hover { background:rgba(50,190,143,0.04); padding-left:12px; }

.req-info { flex:1; min-width:0; }
.req-name {
    font-family:var(--fh); font-size:14px; font-weight:900;
    color:var(--text); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; line-height:1.35;
}
.req-det {
    font-family:var(--fb); font-size:12px; font-weight:500;
    color:var(--muted); margin-top:5px; line-height:1.6;
}

.rs {
    font-family:var(--fb); font-size:11px; font-weight:800;
    padding:5px 13px; border-radius:20px;
    flex-shrink:0; white-space:nowrap; letter-spacing:0.4px;
}
.rs-w { background:rgba(255,208,96,0.14); color:var(--gold);  }
.rs-a { background:rgba(50,190,143,0.14); color:var(--neon);  }
.rs-r { background:rgba(255,53,83,0.14);  color:var(--red);   }

/* ── Empty ── */
.empty {
    text-align:center; padding:40px 20px;
    font-family:var(--fb); font-size:14px; font-weight:500;
    color:var(--muted); line-height:1.8;
}
.empty i { font-size:44px; display:block; margin-bottom:14px; opacity:.22; }

/* ══════════════════════════════════════════════
   SECTION TITRE
══════════════════════════════════════════════ */
.section-title {
    display: flex;
    align-items: center;
    gap: 14px;
    margin: 32px 0 16px;
}
.section-title h2 {
    font-family: var(--fh);
    font-size: 18px;
    font-weight: 900;
    color: var(--text);
    letter-spacing: 0.5px;
    line-height: 1.3;
}
.section-title::after {
    content:'';
    flex:1; height:1px;
    background: linear-gradient(90deg, var(--bord), transparent);
}
.section-line {
    width: 4px; height: 24px;
    border-radius: 4px;
    background: linear-gradient(to bottom, var(--orange), var(--gold));
    flex-shrink:0;
}

/* ── Stock KPI mini ── */
.stock-kpi-row {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    margin-bottom: 18px;
}
.skpi {
    background: var(--card);
    border: 1px solid var(--bord);
    border-radius: 16px;
    padding: 20px 22px;
    display: flex;
    align-items: center;
    gap: 16px;
    transition: all 0.3s;
}
.skpi:hover { transform:translateY(-4px); box-shadow:0 14px 32px rgba(0,0,0,0.38), var(--glow); border-color:rgba(50,190,143,0.28); }
.skpi-ico {
    width:46px; height:46px; border-radius:13px;
    display:flex; align-items:center; justify-content:center;
    font-size:21px; flex-shrink:0;
}
.skpi-val {
    font-family: var(--fh);
    font-size: 28px; font-weight:900; color:var(--text); line-height:1;
}
.skpi-lbl {
    font-family: var(--fb);
    font-size: 12px; font-weight:700; color:var(--muted);
    text-transform:uppercase; letter-spacing:1px; margin-top:5px; line-height:1.5;
}

/* ── Tableau mouvements ── */
.mv-table { width:100%; border-collapse:collapse; }
.mv-table th {
    font-family: var(--fh);
    font-size: 12px; font-weight:900;
    color: var(--muted);
    text-transform:uppercase; letter-spacing:1.2px;
    padding: 10px 14px;
    border-bottom: 1px solid rgba(255,255,255,0.06);
    text-align:left;
    background: rgba(0,0,0,0.15);
}
.mv-table td {
    font-family: var(--fb);
    font-size: 13px; font-weight:500;
    color: var(--text2);
    padding: 13px 14px;
    border-bottom: 1px solid rgba(255,255,255,0.04);
    line-height:1.5;
}
.mv-table tr:last-child td { border-bottom:none; }
.mv-table tbody tr { transition:all 0.25s; }
.mv-table tbody tr:hover { background:rgba(50,190,143,0.04); }
.mv-table td strong { font-family:var(--fh); font-weight:900; color:var(--text); }

.badge-entry { background:rgba(50,190,143,0.14); color:var(--neon);  font-family:var(--fb); font-size:10px; font-weight:800; padding:4px 11px; border-radius:20px; letter-spacing:0.5px; }
.badge-exit  { background:rgba(255,53,83,0.14);  color:var(--red);   font-family:var(--fb); font-size:10px; font-weight:800; padding:4px 11px; border-radius:20px; letter-spacing:0.5px; }
.badge-alert { background:rgba(255,208,96,0.14); color:var(--gold);  font-family:var(--fb); font-size:10px; font-weight:800; padding:4px 11px; border-radius:20px; letter-spacing:0.5px; }

/* ══════════════════════════════════════════════
   ANIMATIONS
══════════════════════════════════════════════ */
@keyframes fadeUp { from{opacity:0;transform:translateY(30px)} to{opacity:1;transform:translateY(0)} }
.kpi:nth-child(1){animation:fadeUp .5s ease .04s backwards}
.kpi:nth-child(2){animation:fadeUp .5s ease .09s backwards}
.kpi:nth-child(3){animation:fadeUp .5s ease .14s backwards}
.kpi:nth-child(4){animation:fadeUp .5s ease .19s backwards}
.kpi:nth-child(5){animation:fadeUp .5s ease .24s backwards}
.kpi:nth-child(6){animation:fadeUp .5s ease .29s backwards}
.kpi:nth-child(7){animation:fadeUp .5s ease .34s backwards}
.kpi:nth-child(8){animation:fadeUp .5s ease .39s backwards}
.panel,.qa,.cdbanner{animation:fadeUp .55s ease .08s backwards}

/* ══════════════════════════════════════════════
   RESPONSIVE
══════════════════════════════════════════════ */
@media (max-width:1280px) {
    .kpi-grid { grid-template-columns:repeat(4,1fr); }
    .qa-grid  { grid-template-columns:repeat(4,1fr); }
    .row3     { grid-template-columns:1fr 1fr; }
}

@media (max-width:960px) {
    .kpi-grid { grid-template-columns:repeat(2,1fr); }
    .qa-grid  { grid-template-columns:repeat(2,1fr); }
    .row2     { grid-template-columns:1fr; }
    .row3     { grid-template-columns:1fr; }
    .topbar   { padding:16px 20px; }
    .nav-bar  { padding:14px 18px; }
    .nav-select { max-width:100%; }
    .cdbanner { padding:20px 22px; }
    .sep      { display:none; }
}

@media (max-width:680px) {
    .wrap     { padding:12px 12px 36px; }
    .topbar   { padding:14px 16px; gap:12px; }
    .brand-ico{ width:44px; height:44px; font-size:21px; }
    .brand-txt h1 { font-size:18px; }
    .brand-txt p  { font-size:10px; letter-spacing:2px; }
    .clock-time   { font-size:26px; letter-spacing:3px; }
    .clock-date   { font-size:11px; }
    .user-badge   { padding:9px 16px; font-size:13px; }
    .kpi-grid { grid-template-columns:repeat(2,1fr); gap:12px; }
    .kpi      { padding:20px 16px; }
    .kpi-val  { font-size:36px; }
    .kpi-lbl  { font-size:12px; }
    .qa-grid  { grid-template-columns:repeat(2,1fr); gap:10px; }
    .qa       { padding:20px 12px; gap:10px; }
    .qa-ico   { width:46px; height:46px; font-size:21px; }
    .qa-lbl   { font-size:12px; }
    .cd-units { gap:8px; }
    .cd-unit  { min-width:62px; padding:11px 13px; }
    .cd-val   { font-size:26px; }
    .nav-bar  { flex-direction:column; align-items:stretch; }
    .nb       { justify-content:center; }
    .cdbanner { flex-direction:column; align-items:flex-start; gap:20px; }
    .fin-sum  { text-align:left; }
    .ph       { padding:16px 18px; }
    .ph-title { font-size:14px; }
    .pb       { padding:16px 16px; }
}

@media (max-width:420px) {
    .kpi-grid { grid-template-columns:1fr; }
    .qa-grid  { grid-template-columns:1fr 1fr; }
    .topbar   { flex-direction:column; align-items:flex-start; }
    .clock    { align-self:center; }
}
</style>
</head>
<body>
<div class="wrap">

<!-- ══════════════ TOPBAR ══════════════ -->
<div class="topbar">
    <div class="brand">
        <div class="brand-ico"><i class="fas fa-id-badge"></i></div>
        <div class="brand-txt">
            <h1>Gestion des stock  | ESPERANCE H20</h1>
            <p>ESPERANCE H2O &nbsp;·&nbsp; Ressources Humaines</p>
        </div>
    </div>

    <div class="clock">
        <div class="clock-time" id="clockTime">--:--:--</div>
        <div class="clock-date" id="clockDate">Chargement…</div>
    </div>

    <div class="user-badge">
        <i class="fas fa-user-shield"></i>
        <?= htmlspecialchars($user_name) ?>
    </div>
</div>

<!-- ══════════════ BARRE NAVIGATION ══════════════ -->
<div class="nav-bar">

    <!-- COMBOBOX complet avec vrais noms de fichiers -->
    <select class="nav-select" onchange="if(this.value) window.location=this.value">
        <option value="">🚀 Navigation rapide…</option>

        <optgroup label="──── ERP ────">
            <option value="<?= project_url('finance/caisse_complete_enhanced.php') ?>">💰 Caisse</option>
            <option value="<?= project_url('stock/stock_tracking.php') ?>">📈 Tracker le stock — Rapports</option>
            <option value="<?= project_url('stock/stocks_erp_pro.php') ?>">📊 Stocks</option>
            <option value="<?= project_url('stock/stock_update_fixed.php') ?>">📦 Approvisionnement</option>
            <option value="<?= project_url('stock/stock_update_fixed.php') ?>">🔄 Appro | Mise à jour stock</option>
            <option value="<?= project_url('stock/products_erp_pro.php') ?>">➕ Ajout produits</option>
            <option value="<?= project_url('clients/clients_erp_pro.php') ?>">👥 Clients</option>
            <option value="<?= project_url('documents/documents_erp_pro.php') ?>">📂 Centre d'archive digital</option>
        </optgroup>

        <optgroup label="──── RH ────">
            <option value="<?= project_url('hr/attendance_rh.php') ?>">📋 Employé présent</option>
            <option value="<?= project_url('hr/admin_attendance_viewer_pro.php') ?>">🛰 Visionneuse Pointages PRO</option>
            <option value="<?= project_url('hr/employees_manager.php') ?>">⚙️ Gestion RH Pro</option>
            <option value="<?= project_url('dashboard/index.php') ?>">👤 Portail Employé — Voir les commandes</option>
        </optgroup>

        <optgroup label="──── Administration ────">
            <option value="<?= project_url('dashboard/admin_nasa.php') ?>">🔧 Administrateur</option>
            <option value="<?= project_url('system/backup_db.php') ?>">💾 Sauvegarde DB</option>
            <option value="<?= project_url('system/restore.php') ?>">♻️ Restauration DB</option>
            <option value="<?= project_url('auth/profile.php') ?>">👤 Mon Profil</option>
            <option value="<?= project_url('auth/logout.php') ?>">🚪 Déconnexion</option>
        </optgroup>
    </select>

    <div class="sep"></div>

    <a href="<?= project_url('hr/admin_attendance_viewer_pro.php') ?>" class="nb">
        <i class="fas fa-satellite-dish"></i> Visionneuse
    </a>
     <a href="<?= project_url('stock/arrivage_reception.php') ?>" class="nb">
        <i class="fas fa-truck"></i> Reception d'arrivage
    </a>
    <a href="<?= project_url('messaging/messagerie.php') ?>" class="nb">
        <i class="fas fa-users-cog"></i> Messagerie
    </a>
    <a href="<?= project_url('hr/attendance_rh.php') ?>" class="nb">
        <i class="fas fa-calendar-check"></i> Présences
    </a>
    <a href="<?= project_url('hr/employees_manager.php') ?>" class="nb">
        <i class="fas fa-users-cog"></i> Gestion RH
    </a>
     <a href="<?= project_url('stock/appro_requests.php') ?>" class="nb">
        <i class="fas fa-users-cog"></i> demande d'appro
    </a>
     <a href="<?= project_url('dashboard/agent_erp.php') ?>" class="nb">
        <i class="fas fa-ai"></i> AI mode
    </a>
    <a href="<?= project_url('finance/caisse_complete_enhanced.php') ?>" class="nb gold">
        <i class="fas fa-cash-register"></i> Caisse
    </a>
    <a href="<?= project_url('auth/logout.php') ?>" class="nb red">
        <i class="fas fa-sign-out-alt"></i> Déco
    </a>
</div>

<!-- ══════════════ COUNTDOWN ══════════════ -->
<div class="cdbanner">
    <div>
        <div class="cd-label">⏱ Fin du mois dans</div>
        <div class="cd-units">
            <div class="cd-unit"><span class="cd-val" id="cdD">--</span><span class="cd-lab">Jours</span></div>
            <div class="cd-unit"><span class="cd-val" id="cdH">--</span><span class="cd-lab">Heures</span></div>
            <div class="cd-unit"><span class="cd-val" id="cdM">--</span><span class="cd-lab">Min</span></div>
            <div class="cd-unit"><span class="cd-val" id="cdS">--</span><span class="cd-lab">Sec</span></div>
        </div>
    </div>

    <div class="prog-wrap">
        <div class="prog-head">
            <span><?= date('F Y') ?></span>
            <span><?= $month_progress ?>%</span>
        </div>
        <div class="prog-bar">
            <div class="prog-fill" style="width:<?= $month_progress ?>%"></div>
        </div>
        <div class="prog-sub">Jour <?= $current_day ?> sur <?= $days_in_month ?></div>
    </div>

    <div class="fin-sum">
        <div class="fin-lbl">Pénalités ce mois</div>
        <div class="fin-penalty">
            -<?= number_format($penalties, 0, ',', ' ') ?> <span>FCFA</span>
        </div>
        <div class="fin-ot">
            Heures sup : <span>+<?= number_format($overtime, 0, ',', ' ') ?> FCFA</span>
        </div>
    </div>
</div>

<!-- ══════════════ KPI CARDS ══════════════ -->
<div class="kpi-grid">

    <div class="kpi">
        <div class="kpi-top">
            <div class="kpi-ico ico-g"><i class="fas fa-users"></i></div>
            <span class="kpi-tag tg">Actifs</span>
        </div>
        <div class="kpi-val counter"><?= $total_emp ?></div>
        <div class="kpi-lbl">Employés Total</div>
        <div class="kpi-sub">📅 Présents aujourd'hui : <?= $ds['total_present'] ?? 0 ?></div>
    </div>

    <div class="kpi">
        <div class="kpi-top">
            <div class="kpi-ico ico-g"><i class="fas fa-fingerprint"></i></div>
            <span class="kpi-tag tg">✅ Présents</span>
        </div>
        <div class="kpi-val counter"><?= $ds['total_present'] ?? 0 ?></div>
        <div class="kpi-lbl">Présents Aujourd'hui</div>
        <div class="kpi-sub">
            🔵 En poste : <?= $ds['still_working'] ?? 0 ?>
            &nbsp;·&nbsp;
            🏁 Partis : <?= $ds['total_departed'] ?? 0 ?>
        </div>
    </div>

    <div class="kpi red">
        <div class="kpi-top">
            <div class="kpi-ico ico-r"><i class="fas fa-user-clock"></i></div>
            <span class="kpi-tag tgr">⚠️ Retards</span>
        </div>
        <div class="kpi-val counter" style="color:var(--red)"><?= $ds['total_late'] ?? 0 ?></div>
        <div class="kpi-lbl">Retards Aujourd'hui</div>
        <div class="kpi-sub">🔴 Absents : <?= $total_abs ?></div>
    </div>

    <div class="kpi orange">
        <div class="kpi-top">
            <div class="kpi-ico ico-o"><i class="fas fa-sign-out-alt"></i></div>
            <span class="kpi-tag tgo">Départs</span>
        </div>
        <div class="kpi-val counter" style="color:var(--orange)"><?= $ds['total_departed'] ?? 0 ?></div>
        <div class="kpi-lbl">Ont Pointé Départ</div>
        <div class="kpi-sub">⏳ Encore en poste : <?= $ds['still_working'] ?? 0 ?></div>
    </div>

    <div class="kpi red">
        <div class="kpi-top">
            <div class="kpi-ico ico-r"><i class="fas fa-minus-circle"></i></div>
            <span class="kpi-tag tgr">Ce mois</span>
        </div>
        <div class="kpi-val" style="color:var(--red);font-size:30px">
            <?= number_format($penalties, 0, ',', ' ') ?>
        </div>
        <div class="kpi-lbl">Pénalités FCFA</div>
        <div class="kpi-sub">📉 Total retards ce mois</div>
    </div>

    <div class="kpi">
        <div class="kpi-top">
            <div class="kpi-ico ico-g"><i class="fas fa-coins"></i></div>
            <span class="kpi-tag tg">Bonus</span>
        </div>
        <div class="kpi-val" style="color:var(--neon);font-size:30px">
            <?= number_format($overtime, 0, ',', ' ') ?>
        </div>
        <div class="kpi-lbl">Heures Sup FCFA</div>
        <div class="kpi-sub">📈 Montant à payer ce mois</div>
    </div>

    <div class="kpi gold">
        <div class="kpi-top">
            <div class="kpi-ico ico-d"><i class="fas fa-file-alt"></i></div>
            <span class="kpi-tag tgd">En attente</span>
        </div>
        <div class="kpi-val counter" style="color:var(--gold)"><?= $pend_perm ?></div>
        <div class="kpi-lbl">Permissions</div>
        <div class="kpi-sub">📋 À traiter rapidement</div>
    </div>

    <div class="kpi blue">
        <div class="kpi-top">
            <div class="kpi-ico ico-b"><i class="fas fa-hand-holding-usd"></i></div>
            <span class="kpi-tag tgb">En attente</span>
        </div>
        <div class="kpi-val counter" style="color:var(--blue)"><?= $pend_adv ?></div>
        <div class="kpi-lbl">Avances</div>
        <div class="kpi-sub">💰 Demandes à valider</div>
    </div>

</div><!-- /kpi-grid -->

<!-- ══════════════ ALERTE ══════════════ -->
<?php if($pend_perm > 0 || $pend_adv > 0): ?>
<div class="alert-box">
    <i class="fas fa-bell fa-beat-fade"></i>
    <span>
        <?php if($pend_perm > 0): ?><strong><?= $pend_perm ?> permission(s)</strong> en attente de validation<?php endif; ?>
        <?php if($pend_perm > 0 && $pend_adv > 0): ?> &nbsp;·&nbsp; <?php endif; ?>
        <?php if($pend_adv  > 0): ?><strong><?= $pend_adv ?> avance(s)</strong> en attente de validation<?php endif; ?>
    </span>
    <a href="<?= project_url('hr/employees_manager.php') ?>">Traiter maintenant →</a>
</div>
<?php endif; ?>

<!-- ══════════════ QUICK ACTIONS ══════════════ -->
<div class="qa-grid">
    <a href="<?= project_url('finance/caisse_complete_enhanced.php') ?>" class="qa">
        <div class="qa-ico" style="background:rgba(255,208,96,0.12);color:var(--gold)"><i class="fas fa-cash-register"></i></div>
        <span class="qa-lbl">Caisse</span>
    </a>
    <a href="<?= project_url('stock/stock_tracking.php') ?>" class="qa">
        <div class="qa-ico" style="background:rgba(50,190,143,0.12);color:var(--neon)"><i class="fas fa-chart-line"></i></div>
        <span class="qa-lbl">Tracker le stock</span>
    </a>
    <a href="<?= project_url('orders/admin_orders.php') ?>" class="qa">
        <div class="qa-ico" style="background:rgba(61,140,255,0.12);color:var(--blue)"><i class="fas fa-shopping-cart"></i></div>
        <span class="qa-lbl">Voir les commandes</span>
    </a>
    <a href="<?= project_url('stock/stock_update_fixed.php') ?>" class="qa">
        <div class="qa-ico" style="background:rgba(255,145,64,0.12);color:var(--orange)"><i class="fas fa-truck"></i></div>
        <span class="qa-lbl">Appro</span>
    </a>
    <a href="<?= project_url('dashboard/admin_nasa.php') ?>" class="qa">
        <div class="qa-ico" style="background:rgba(168,85,247,0.12);color:#a855f7"><i class="fas fa-user-shield"></i></div>
        <span class="qa-lbl">Administrateur</span>
    </a>
    <a href="<?= project_url('clients/clients_erp_pro.php') ?>" class="qa">
        <div class="qa-ico" style="background:rgba(61,140,255,0.12);color:var(--blue)"><i class="fas fa-users"></i></div>
        <span class="qa-lbl">Clients</span>
    </a>
    <a href="<?= project_url('hr/attendance_rh.php') ?>" class="qa">
        <div class="qa-ico" style="background:rgba(50,190,143,0.12);color:var(--neon)"><i class="fas fa-user-check"></i></div>
        <span class="qa-lbl">Employé présent</span>
    </a>
    <a href="<?= project_url('documents/documents_erp_pro.php') ?>" class="qa">
        <div class="qa-ico" style="background:rgba(255,208,96,0.12);color:var(--gold)"><i class="fas fa-archive"></i></div>
        <span class="qa-lbl">Centre d'archive digital</span>
    </a>
    <a href="<?= project_url('auth/profile.php') ?>" class="qa">
        <div class="qa-ico" style="background:rgba(50,190,143,0.12);color:var(--neon)"><i class="fas fa-user-circle"></i></div>
        <span class="qa-lbl">Mon Profil</span>
    </a>
     <a href="<?= project_url('cdn/index.php') ?>" class="qa">
        <div class="qa-ico" style="background:rgba(50,190,143,0.12);color:var(--neon)"><i class="fas fa-user-circle"></i></div>
        <span class="qa-lbl">H²0 CDN</span>
    </a>
    <a href="<?= project_url('hr/admin_attendance_viewer_pro.php') ?>" class="qa">
        <div class="qa-ico" style="background:rgba(61,140,255,0.12);color:var(--blue)"><i class="fas fa-satellite-dish"></i></div>
        <span class="qa-lbl">Attendance</span>
    </a>
    <a href="<?= project_url('hr/employees_manager.php') ?>?tab=permissions" class="qa">
        <div class="qa-ico" style="background:rgba(255,208,96,0.12);color:var(--gold)"><i class="fas fa-file-signature"></i></div>
        <span class="qa-lbl">Permissions
            <small><?= $pend_perm ?> en attente</small>
        </span>
    </a>
    <a href="<?= project_url('hr/employees_manager.php') ?>?tab=advances" class="qa">
        <div class="qa-ico" style="background:rgba(255,53,83,0.12);color:var(--red)"><i class="fas fa-money-bill-wave"></i></div>
        <span class="qa-lbl">Avances
            <small><?= $pend_adv ?> en attente</small>
        </span>
    </a>
</div>

<!-- ══════════════ GRAPHIQUES ══════════════ -->
<div class="row2">
    <div class="panel">
        <div class="ph">
            <div class="ph-title"><div class="dot"></div> Présences — 7 Derniers Jours</div>
            <span class="pbadge">Cette semaine</span>
        </div>
        <div class="pb"><div class="chart-box"><canvas id="weekChart"></canvas></div></div>
    </div>
    <div class="panel">
        <div class="ph">
            <div class="ph-title"><div class="dot r"></div> Retards du Mois</div>
            <span class="pbadge r">Ce mois</span>
        </div>
        <div class="pb"><div class="chart-box"><canvas id="lateChart"></canvas></div></div>
    </div>
</div>

<!-- ══════════════ PANELS DONNÉES ══════════════ -->
<div class="row3">

    <!-- Pointages du jour -->
    <div class="panel">
        <div class="ph">
            <div class="ph-title"><div class="dot"></div> Pointages Aujourd'hui</div>
            <span class="pbadge"><?= $ds['total_present'] ?? 0 ?> présents</span>
        </div>
        <div class="pb">
            <?php if(empty($today_att)): ?>
            <div class="empty"><i class="fas fa-moon"></i>Aucun pointage aujourd'hui</div>
            <?php else: ?>
            <ul class="att-list">
                <?php foreach($today_att as $a): $late=($a['status']=='retard'); ?>
                <li class="att-item">
                    <div class="av <?= $late?'late':'' ?>"><?= strtoupper(mb_substr($a['full_name'],0,2)) ?></div>
                    <div class="ai">
                        <div class="ai-name"><?= htmlspecialchars($a['full_name']) ?></div>
                        <div class="ai-pos"><?= htmlspecialchars($a['pos']) ?></div>
                    </div>
                    <div class="at-right">
                        <div class="at-time <?= $late?'late':'' ?>"><?= substr($a['check_in'],0,5) ?></div>
                        <span class="at-badge <?= $late?'ab-r':($a['check_out']?'ab-o':'ab-p') ?>">
                            <?= $late?'RETARD':($a['check_out']?'PARTI':'EN POSTE') ?>
                        </span>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>

    <!-- Top Retardataires -->
    <div class="panel">
        <div class="ph">
            <div class="ph-title"><div class="dot r"></div> 🏆 Top Retardataires</div>
            <span class="pbadge r">Ce mois</span>
        </div>
        <div class="pb">
            <?php if(empty($top_late)): ?>
            <div class="empty"><i class="fas fa-trophy"></i>Aucun retard ce mois ! 🎉</div>
            <?php else: $mx=$top_late[0]['late_count']; ?>
            <?php foreach($top_late as $i=>$lt): ?>
            <div class="rank-item">
                <div class="rk-n <?= $i==0?'top':'' ?>"><?= $i+1 ?></div>
                <div class="rk-info">
                    <div class="rk-name"><?= htmlspecialchars($lt['full_name']) ?></div>
                    <div class="rk-cnt"><?= $lt['late_count'] ?> retard(s) ce mois</div>
                </div>
                <div class="rk-bar-w">
                    <div class="rk-bar" style="width:<?= ($lt['late_count']/$mx)*100 ?>%"></div>
                </div>
                <div class="rk-amt">-<?= number_format($lt['total_penalty'],0) ?> F</div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Heures Sup -->
    <div class="panel">
        <div class="ph">
            <div class="ph-title"><div class="dot g"></div> 💰 Heures Supplémentaires</div>
            <span class="pbadge g">Ce mois</span>
        </div>
        <div class="pb">
            <?php if(empty($top_ot)): ?>
            <div class="empty"><i class="fas fa-clock"></i>Aucune heure sup ce mois</div>
            <?php else: ?>
            <?php foreach($top_ot as $ot): ?>
            <div class="ot-item">
                <div>
                    <div class="ot-name"><?= htmlspecialchars($ot['full_name']) ?></div>
                    <div class="ot-hrs"><?= $ot['total_hours'] ?>h supplémentaires</div>
                </div>
                <div class="ot-amt">+<?= number_format($ot['total_amount'],0) ?> F</div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- ══════════════ PERMISSIONS + AVANCES ══════════════ -->
<div class="row2">

    <div class="panel">
        <div class="ph">
            <div class="ph-title"><div class="dot g"></div> Permissions Récentes</div>
            <a href="<?= project_url('hr/employees_manager.php') ?>?tab=permissions" class="ph-link">Voir tout →</a>
        </div>
        <div class="pb">
            <?php if(empty($recent_perm)): ?>
            <div class="empty"><i class="fas fa-file-alt"></i>Aucune demande de permission</div>
            <?php else: ?>
            <?php foreach($recent_perm as $p): ?>
            <div class="req-item">
                <div class="req-info">
                    <div class="req-name"><?= htmlspecialchars($p['full_name']) ?></div>
                    <div class="req-det">
                        Du <?= date('d/m/Y',strtotime($p['start_date'])) ?>
                        au <?= date('d/m/Y',strtotime($p['end_date'])) ?>
                        &nbsp;·&nbsp; <?= mb_substr(htmlspecialchars($p['reason']),0,36) ?>…
                    </div>
                </div>
                <?php
                $sc = $p['status'];
                $cls = $sc=='en_attente'?'rs-w':($sc=='approuve'?'rs-a':'rs-r');
                $lbl = $sc=='en_attente'?'⏳ Attente':($sc=='approuve'?'✅ Approuvé':($sc=='annule'?'❌ Annulé':'❌ Rejeté'));
                ?>
                <span class="rs <?= $cls ?>"><?= $lbl ?></span>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="panel">
        <div class="ph">
            <div class="ph-title"><div class="dot b"></div> Avances Récentes</div>
            <a href="<?= project_url('hr/employees_manager.php') ?>?tab=advances" class="ph-link">Voir tout →</a>
        </div>
        <div class="pb">
            <?php if(empty($recent_adv)): ?>
            <div class="empty"><i class="fas fa-money-bill"></i>Aucune demande d'avance</div>
            <?php else: ?>
            <?php foreach($recent_adv as $adv): ?>
            <div class="req-item">
                <div class="req-info">
                    <div class="req-name"><?= htmlspecialchars($adv['full_name']) ?></div>
                    <div class="req-det">
                        <strong><?= number_format($adv['amount'],0,',',' ') ?> FCFA</strong>
                        &nbsp;·&nbsp; <?= mb_substr(htmlspecialchars($adv['reason']),0,36) ?>…
                    </div>
                </div>
                <?php
                $sc = $adv['status'];
                $cls = $sc=='en_attente'?'rs-w':($sc=='approuve'?'rs-a':'rs-r');
                $lbl = $sc=='en_attente'?'⏳ Attente':($sc=='approuve'?'✅ Approuvé':($sc=='annule'?'❌ Annulé':'❌ Rejeté'));
                ?>
                <span class="rs <?= $cls ?>"><?= $lbl ?></span>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- ══════════════════════════════════════════════
     SECTION MOUVEMENTS DE STOCK
══════════════════════════════════════════════ -->
<div class="section-title">
    <div class="section-line"></div>
    <h2>📦 Mouvements de Stock</h2>
</div>

<!-- KPI Stock -->
<div class="stock-kpi-row">

    <div class="skpi">
        <div class="skpi-ico" style="background:rgba(255,145,64,0.14);color:var(--orange)">
            <i class="fas fa-boxes"></i>
        </div>
        <div>
            <div class="skpi-val"><?= number_format($stock_stats['total_products']) ?></div>
            <div class="skpi-lbl">Produits en catalogue</div>
        </div>
    </div>

    <div class="skpi">
        <div class="skpi-ico" style="background:rgba(50,190,143,0.14);color:var(--neon)">
            <i class="fas fa-warehouse"></i>
        </div>
        <div>
            <div class="skpi-val"><?= number_format($stock_stats['total_stock']) ?></div>
            <div class="skpi-lbl">Unités en stock total</div>
        </div>
    </div>

    <div class="skpi">
        <div class="skpi-ico" style="background:rgba(255,208,96,0.14);color:var(--gold)">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        <div>
            <div class="skpi-val" style="color:var(--gold)"><?= count($low_stock) ?></div>
            <div class="skpi-lbl">Alertes stock bas</div>
        </div>
    </div>

</div>

<!-- Graphiques Stock -->
<div class="row2" style="margin-bottom:18px">

    <!-- Area chart mouvements -->
    <div class="panel">
        <div class="ph">
            <div class="ph-title">
                <div class="dot" style="background:var(--orange);box-shadow:0 0 9px var(--orange)"></div>
                Mouvements — 30 Derniers Jours
            </div>
            <span class="pbadge" style="background:rgba(255,145,64,0.12);color:var(--orange)">Entrées / Sorties</span>
        </div>
        <div class="pb"><div class="chart-box"><canvas id="movChart"></canvas></div></div>
    </div>

    <!-- Top produits -->
    <div class="panel">
        <div class="ph">
            <div class="ph-title">
                <div class="dot g"></div>
                🏆 Top Produits Vendus
            </div>
            <span class="pbadge g">Classement</span>
        </div>
        <div class="pb"><div class="chart-box"><canvas id="topProdChart"></canvas></div></div>
    </div>

</div>

<!-- Tables Stock -->
<div class="row2" style="margin-bottom:32px">

    <!-- Alertes stock bas -->
    <div class="panel">
        <div class="ph">
            <div class="ph-title">
                <div class="dot" style="background:var(--gold);box-shadow:0 0 9px var(--gold)"></div>
                ⚠️ Alertes Stock Bas
            </div>
            <span class="pbadge" style="background:rgba(255,208,96,0.12);color:var(--gold)"><?= count($low_stock) ?> alertes</span>
        </div>
        <div class="pb">
            <?php if(empty($low_stock)): ?>
            <div class="empty"><i class="fas fa-check-circle" style="color:var(--neon)"></i>Aucune alerte de stock ! 🎉</div>
            <?php else: ?>
            <table class="mv-table">
                <thead>
                    <tr>
                        <th>Produit</th>
                        <th>Quantité actuelle</th>
                        <th>Seuil alerte</th>
                        <th>Statut</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($low_stock as $item): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($item['name']) ?></strong></td>
                        <td style="font-family:var(--fh);font-size:16px;font-weight:900;color:var(--red)"><?= $item['quantity'] ?></td>
                        <td><?= $item['alert_quantity'] ?></td>
                        <td><span class="badge-alert">⚠️ ALERTE</span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Mouvements récents -->
    <div class="panel">
        <div class="ph">
            <div class="ph-title">
                <div class="dot" style="background:var(--blue);box-shadow:0 0 9px var(--blue)"></div>
                Mouvements Récents
            </div>
            <a href="<?= project_url('stock/stock_tracking.php') ?>" class="ph-link">Voir tout →</a>
        </div>
        <div class="pb">
            <?php if(empty($recent_movements)): ?>
            <div class="empty"><i class="fas fa-exchange-alt"></i>Aucun mouvement récent</div>
            <?php else: ?>
            <table class="mv-table">
                <thead>
                    <tr>
                        <th>Produit</th>
                        <th>Type</th>
                        <th>Qté</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($recent_movements as $mv): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($mv['name']) ?></strong></td>
                        <td>
                            <?php if($mv['type']=='entry'): ?>
                            <span class="badge-entry">↓ ENTRÉE</span>
                            <?php else: ?>
                            <span class="badge-exit">↑ SORTIE</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-family:var(--fh);font-weight:900;color:<?= $mv['type']=='entry'?'var(--neon)':'var(--red)' ?>"><?= $mv['quantity'] ?></td>
                        <td style="color:var(--muted)"><?= date('d/m H:i', strtotime($mv['movement_date'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

</div>

</div><!-- /wrap -->

<script>
/* ─ Horloge ─ */
function tick(){
    const n=new Date();
    document.getElementById('clockTime').textContent=
        n.toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
    document.getElementById('clockDate').textContent=
        n.toLocaleDateString('fr-FR',{weekday:'long',day:'numeric',month:'long',year:'numeric'});
}
tick(); setInterval(tick,1000);

/* ─ Countdown fin de mois ─ */
function countdown(){
    const n=new Date();
    const e=new Date(n.getFullYear(),n.getMonth()+1,0,23,59,59,999);
    const ms=e-n; if(ms<=0)return;
    const pad=v=>String(v).padStart(2,'0');
    document.getElementById('cdD').textContent=pad(Math.floor(ms/86400000));
    document.getElementById('cdH').textContent=pad(Math.floor((ms%86400000)/3600000));
    document.getElementById('cdM').textContent=pad(Math.floor((ms%3600000)/60000));
    document.getElementById('cdS').textContent=pad(Math.floor((ms%60000)/1000));
}
countdown(); setInterval(countdown,1000);

/* ─ Chart defaults ─ */
Chart.defaults.color='#5a8070';
Chart.defaults.borderColor='rgba(255,255,255,0.04)';
Chart.defaults.font.family="'Inter',sans-serif";
Chart.defaults.font.size=12;
const tip={backgroundColor:'rgba(8,20,32,0.97)',borderColor:'rgba(50,190,143,0.28)',
    borderWidth:1,padding:14,cornerRadius:10,
    titleFont:{weight:'700',size:13},bodyFont:{size:12}};

/* ─ Présences 7 jours ─ */
const wd=<?= json_encode($week_att) ?>;
new Chart(document.getElementById('weekChart'),{
    type:'bar',
    data:{
        labels:wd.map(d=>{const dt=new Date(d.work_date+'T00:00');
            return dt.toLocaleDateString('fr-FR',{weekday:'short',day:'numeric'});}),
        datasets:[
            {label:'Présents',data:wd.map(d=>d.present),
                backgroundColor:'rgba(50,190,143,0.78)',borderRadius:9,borderSkipped:false},
            {label:'Retards', data:wd.map(d=>d.late),
                backgroundColor:'rgba(255,53,83,0.78)',  borderRadius:9,borderSkipped:false}
        ]
    },
    options:{responsive:true,maintainAspectRatio:false,
        plugins:{legend:{position:'top',labels:{usePointStyle:true,padding:18,color:'#5a8070'}},tooltip:tip},
        scales:{x:{grid:{display:false},ticks:{color:'#5a8070',font:{size:11}}},
                y:{beginAtZero:true,grid:{color:'rgba(255,255,255,0.04)'},ticks:{color:'#5a8070',stepSize:1}}}}
});

/* ─ Retards du mois ─ */
const ld=<?= json_encode($monthly_lates) ?>;
new Chart(document.getElementById('lateChart'),{
    type:'line',
    data:{
        labels:ld.map(d=>{const dt=new Date(d.work_date+'T00:00');
            return dt.toLocaleDateString('fr-FR',{day:'numeric',month:'short'});}),
        datasets:[{
            label:'Retards',data:ld.map(d=>d.late_count),
            backgroundColor:'rgba(255,53,83,0.1)',borderColor:'#ff3553',borderWidth:2.5,
            fill:true,tension:0.45,
            pointBackgroundColor:'#ff3553',pointBorderColor:'#04090e',
            pointBorderWidth:2,pointRadius:5,pointHoverRadius:8
        }]
    },
    options:{responsive:true,maintainAspectRatio:false,
        plugins:{legend:{display:false},tooltip:{...tip,borderColor:'rgba(255,53,83,0.28)'}},
        scales:{x:{grid:{display:false},ticks:{color:'#5a8070',font:{size:11}}},
                y:{beginAtZero:true,grid:{color:'rgba(255,255,255,0.04)'},ticks:{color:'#5a8070',stepSize:1}}}}
});

/* ─ Mouvements de stock (area) ─ */
const md=<?= json_encode($stock_movements) ?>;
if(md.length > 0) {
new Chart(document.getElementById('movChart'),{
    type:'line',
    data:{
        labels:md.map(d=>{const dt=new Date(d.m_date+'T00:00');
            return dt.toLocaleDateString('fr-FR',{day:'numeric',month:'short'});}),
        datasets:[
            {
                label:'Entrées',data:md.map(d=>d.entries),
                backgroundColor:'rgba(50,190,143,0.12)',borderColor:'#32be8f',
                borderWidth:2.5,fill:true,tension:0.42,
                pointBackgroundColor:'#32be8f',pointBorderColor:'#04090e',
                pointBorderWidth:2,pointRadius:4,pointHoverRadius:7
            },
            {
                label:'Sorties',data:md.map(d=>d.exits),
                backgroundColor:'rgba(255,53,83,0.12)',borderColor:'#ff3553',
                borderWidth:2.5,fill:true,tension:0.42,
                pointBackgroundColor:'#ff3553',pointBorderColor:'#04090e',
                pointBorderWidth:2,pointRadius:4,pointHoverRadius:7
            }
        ]
    },
    options:{responsive:true,maintainAspectRatio:false,
        plugins:{
            legend:{position:'top',labels:{usePointStyle:true,padding:18,color:'#5a8070'}},
            tooltip:{...tip,mode:'index',intersect:false}
        },
        scales:{
            x:{grid:{display:false},ticks:{color:'#5a8070',font:{size:11}}},
            y:{beginAtZero:true,grid:{color:'rgba(255,255,255,0.04)'},ticks:{color:'#5a8070',stepSize:1}}
        }}
});
} else {
    const c=document.getElementById('movChart');
    if(c){c.parentElement.innerHTML='<div class="empty"><i class="fas fa-exchange-alt"></i>Aucun mouvement dans les 30 derniers jours</div>';}
}

/* ─ Top produits (barres horizontales) ─ */
const tp=<?= json_encode($top_products) ?>;
if(tp.length > 0) {
new Chart(document.getElementById('topProdChart'),{
    type:'bar',
    data:{
        labels:tp.map(d=>d.name.length>18?d.name.substring(0,18)+'…':d.name),
        datasets:[{
            label:'Quantité vendue',
            data:tp.map(d=>d.total_sold),
            backgroundColor:[
                'rgba(50,190,143,0.80)','rgba(61,140,255,0.80)',
                'rgba(255,145,64,0.80)','rgba(255,208,96,0.80)',
                'rgba(168,85,247,0.80)','rgba(255,53,83,0.80)',
                'rgba(26,255,163,0.80)','rgba(255,209,102,0.80)'
            ],
            borderRadius:9,borderSkipped:false
        }]
    },
    options:{
        indexAxis:'y',
        responsive:true,maintainAspectRatio:false,
        plugins:{
            legend:{display:false},
            tooltip:{...tip}
        },
        scales:{
            x:{beginAtZero:true,grid:{color:'rgba(255,255,255,0.04)'},ticks:{color:'#5a8070',font:{size:11}}},
            y:{grid:{display:false},ticks:{color:'#c0d8cc',font:{size:12,weight:'600'}}}
        }}
});
} else {
    const c=document.getElementById('topProdChart');
    if(c){c.parentElement.innerHTML='<div class="empty"><i class="fas fa-crown"></i>Aucune vente enregistrée</div>';}
}

/* ─ Counter animation ─ */
document.querySelectorAll('.counter').forEach(el=>{
    const t=parseInt(el.textContent.replace(/\s/g,''))||0;
    if(!t)return;
    let v=0; const step=Math.max(1,Math.ceil(t/60));
    el.textContent='0';
    const tm=setInterval(()=>{v=Math.min(v+step,t);el.textContent=v;if(v>=t)clearInterval(tm);},16);
});

setTimeout(()=>location.reload(),300000);
console.log('🚀 RH Dashboard v4.0 — C059 Bold — ESPERANCE H2O');
</script>
</body>
</html>
