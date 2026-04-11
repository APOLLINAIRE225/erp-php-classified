<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

/**
 * ════════════════════════════════════════════════════════════════
 * VISIONNEUSE POINTAGES ADMIN — Dark Neon v3.0
 * ESPERANCE H2O · Fichier PHP Principal (3/3)
 * ════════════════════════════════════════════════════════════════
 * 🗂️ Découpage : admin_attendance_style.css + admin_attendance_scripts.js
 * ✅ Style Dark Neon (même charte que admin_nasa.php)
 * ✅ Onglets page : Aujourd'hui · Semaine · Stats · Absents · Export
 * ✅ Onglets card : Arrivée · Départ · Comparer GPS
 * ✅ Recherche live + Vue grille/liste
 * ✅ Distance GPS Haversine (arrivée ↔ départ)
 * ✅ Auto-refresh configurable
 * ✅ Export CSV (BOM UTF-8 Windows)
 * ✅ Charts : présence, horaires, heures sup, départs
 * ✅ Onglet Absents (liste employés non pointés)
 * ✅ Historique semaine en tableau
 * ✅ Impression fiche individuelle
 * ✅ Confirmation dark neon (remplacement SweetAlert)
 * ✅ CSRF token + sécurité
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
date_default_timezone_set('Africa/Abidjan');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ═══════════════════════════
   ACTION : Suppression selfie
═══════════════════════════ */
$flash = ['type'=>'', 'msg'=>''];

if (isset($_POST['delete_selfie'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $flash = ['type'=>'error','msg'=>'Token CSRF invalide'];
    } else {
        try {
            $att_id = (int)$_POST['attendance_id'];
            $stype  = in_array($_POST['selfie_type'],['checkin','checkout']) ? $_POST['selfie_type'] : 'checkin';
            $col    = $stype === 'checkout' ? 'checkout_selfie_path' : 'selfie_path';
            $st     = $pdo->prepare("SELECT $col FROM attendance WHERE id=?");
            $st->execute([$att_id]);
            $row    = $st->fetch(PDO::FETCH_ASSOC);
            if ($row && $row[$col]) {
                if (file_exists($row[$col])) unlink($row[$col]);
                $pdo->prepare("UPDATE attendance SET $col=NULL WHERE id=?")->execute([$att_id]);
                $flash = ['type'=>'success','msg'=>'Selfie '.($stype==='checkout'?'départ':'arrivée').' supprimé'];
            }
        } catch (Exception $e) {
            $flash = ['type'=>'error','msg'=>$e->getMessage()];
        }
        // Redirect PRG
        header("Location: ".$_SERVER['PHP_SELF'].'?'.http_build_query(array_merge($_GET,['flash'=>base64_encode(json_encode($flash))])));
        exit;
    }
}

// Flash depuis GET (après redirect)
if (!empty($_GET['flash'])) {
    $flash = json_decode(base64_decode($_GET['flash']), true) ?: $flash;
}

/* ═══════════════════════════
   FILTRES
═══════════════════════════ */
$filter_date     = $_GET['date']     ?? date('Y-m-d');
$filter_employee = $_GET['employee'] ?? '';

/* ═══════════════════════════
   DONNÉES JOUR
═══════════════════════════ */
$q_day = "
    SELECT a.*,
        e.employee_code, e.full_name,
        c.name  AS category_name,
        p.title AS position_title,
        ov.hours        AS overtime_hours,
        ov.total_amount AS overtime_amount
    FROM attendance a
    JOIN employees e ON a.employee_id = e.id
    JOIN categories c ON e.category_id = c.id
    JOIN positions  p ON e.position_id  = p.id
    LEFT JOIN overtime ov ON ov.employee_id = e.id AND ov.work_date = a.work_date
    WHERE a.work_date = ?
";
$params = [$filter_date];
if ($filter_employee) { $q_day .= " AND a.employee_id = ?"; $params[] = $filter_employee; }
$q_day .= " ORDER BY a.check_in ASC";
$st = $pdo->prepare($q_day); $st->execute($params);
$attendances = $st->fetchAll(PDO::FETCH_ASSOC);

/* ═══════════════════════════
   EMPLOYÉS (filtre)
═══════════════════════════ */
$employees = $pdo->query("
    SELECT id, full_name, employee_code FROM employees
    WHERE status='actif' ORDER BY full_name
")->fetchAll(PDO::FETCH_ASSOC);

/* ═══════════════════════════
   ABSENTS DU JOUR
═══════════════════════════ */
$present_ids = array_column($attendances, 'employee_id');
$absents = [];
if (!empty($present_ids)) {
    $ph = implode(',', array_fill(0, count($present_ids), '?'));
    $st_abs = $pdo->prepare("
        SELECT e.id, e.full_name, e.employee_code, p.title AS position_title, c.name AS category_name
        FROM employees e
        JOIN categories c ON e.category_id = c.id
        JOIN positions  p ON e.position_id  = p.id
        WHERE e.status='actif' AND e.id NOT IN ($ph)
        ORDER BY e.full_name
    ");
    $st_abs->execute($present_ids);
    $absents = $st_abs->fetchAll(PDO::FETCH_ASSOC);
} else {
    $absents = $pdo->query("
        SELECT e.id, e.full_name, e.employee_code, p.title AS position_title, c.name AS category_name
        FROM employees e JOIN categories c ON e.category_id=c.id JOIN positions p ON e.position_id=p.id
        WHERE e.status='actif' ORDER BY e.full_name
    ")->fetchAll(PDO::FETCH_ASSOC);
}

/* ═══════════════════════════
   HISTORIQUE SEMAINE
═══════════════════════════ */
$week_start = date('Y-m-d', strtotime('monday this week', strtotime($filter_date)));
$week_end   = date('Y-m-d', strtotime('sunday this week', strtotime($filter_date)));
$st_week = $pdo->prepare("
    SELECT a.*, e.employee_code, e.full_name, c.name AS category_name, p.title AS position_title,
        ov.hours AS overtime_hours, ov.total_amount AS overtime_amount
    FROM attendance a
    JOIN employees e ON a.employee_id=e.id
    JOIN categories c ON e.category_id=c.id
    JOIN positions  p ON e.position_id=p.id
    LEFT JOIN overtime ov ON ov.employee_id=e.id AND ov.work_date=a.work_date
    WHERE a.work_date BETWEEN ? AND ?
    ORDER BY a.work_date DESC, e.full_name ASC
");
$st_week->execute([$week_start, $week_end]);
$week_data = $st_week->fetchAll(PDO::FETCH_ASSOC);

/* ═══════════════════════════
   STATS
═══════════════════════════ */
$total_present  = count($attendances);
$total_late     = count(array_filter($attendances, fn($a) => $a['status'] === 'retard'));
$total_ontime   = $total_present - $total_late;
$total_departed = count(array_filter($attendances, fn($a) => $a['check_out'] !== null));
$total_overtime = array_sum(array_column($attendances, 'overtime_amount'));
$total_penalty  = array_sum(array_column($attendances, 'penalty_amount'));
$total_absent   = count($absents);

// Distribution horaires
$hours_dist = [];
foreach ($attendances as $a) {
    $h = $a['check_in'] ? substr($a['check_in'], 0, 2).':00' : null;
    if ($h) $hours_dist[$h] = ($hours_dist[$h] ?? 0) + 1;
}
ksort($hours_dist);

// Heures sup par employé
$ot_by_emp = array_filter($attendances, fn($a) => ($a['overtime_hours']??0) > 0);
$ot_by_emp = array_values(array_map(fn($a) => ['name'=>$a['full_name'],'hours'=>$a['overtime_hours']], $ot_by_emp));

// Stats JSON pour charts JS
$stats_json = json_encode([
    'ontime'     => $total_ontime,
    'late'       => $total_late,
    'absent'     => $total_absent,
    'departed'   => $total_departed,
    'hours_dist' => $hours_dist,
    'ot_by_emp'  => $ot_by_emp,
]);

/* ═══════════════════════════
   CSRF TOKEN
═══════════════════════════ */
$csrf = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>Visionneuse Pointages — NEON v3.0 — ESPERANCE H2O</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="admin_attendance_style.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body>

<!-- Données JSON pour JS -->
<script type="application/json" id="stats-data"><?= $stats_json ?></script>
<script type="application/json" id="absent-data"><?= json_encode($absents) ?></script>
<script type="application/json" id="week-data"><?= json_encode($week_data) ?></script>
<script type="application/json" id="export-data"><?= json_encode($attendances) ?></script>

<div class="wrap">

<!-- ══════ TOPBAR ══════ -->
<div class="topbar">
    <div class="brand">
        <div class="brand-ico"><i class="fas fa-satellite-dish"></i></div>
        <div class="brand-txt">
            <h1>Visionneuse Pointages</h1>
            <p>NEON v3.0 &nbsp;·&nbsp; ESPERANCE H2O</p>
        </div>
    </div>

    <div style="text-align:center;flex-shrink:0">
        <div class="clock-val" id="clk">--:--:--</div>
        <div class="clock-lbl" id="clkd">Chargement…</div>
    </div>

    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end">
        <!-- Auto-refresh toggle -->
        <div id="ar-btn" class="ar-toggle" onclick="toggleAutoRefresh()" title="Auto-refresh (60s)">
            <div class="ar-dot"></div>
            <span>Auto <span id="ar-count"></span></span>
        </div>
        <button onclick="exportCSV()" class="btn btn-n btn-sm"><i class="fas fa-file-csv"></i> Export CSV</button>
        <a href="<?= project_url('dashboard/admin_nasa.php') ?>" class="btn btn-b btn-sm"><i class="fas fa-rocket"></i> Admin</a>
        <a href="<?= project_url('hr/employees_manager.php') ?>" class="btn btn-r btn-sm"><i class="fas fa-arrow-left"></i> RH</a>
    </div>
</div>

<!-- ══════ TAB NAV (PAGES) ══════ -->
<div class="tab-nav">
    <span style="font-family:var(--fh);font-size:10px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:1.1px;margin-right:4px;flex-shrink:0"><i class="fas fa-th"></i></span>

    <button class="tn page-tn active" data-tab="today" onclick="switchPageTab('today')">
        <i class="fas fa-calendar-day"></i> Aujourd'hui
        <span class="tb" id="count-total"><?= $total_present ?></span>
    </button>
    <button class="tn page-tn" data-tab="week" onclick="switchPageTab('week')">
        <i class="fas fa-calendar-week"></i> Semaine
        <span class="tb" style="background:var(--cyan)"><?= count($week_data) ?></span>
    </button>
    <button class="tn page-tn" data-tab="stats" onclick="switchPageTab('stats')">
        <i class="fas fa-chart-pie"></i> Statistiques
    </button>
    <button class="tn page-tn" data-tab="absents" onclick="switchPageTab('absents')">
        <i class="fas fa-user-slash"></i> Absents
        <?php if($total_absent): ?>
        <span class="tb"><?= $total_absent ?></span>
        <?php endif; ?>
    </button>
    <button class="tn page-tn" data-tab="export" onclick="switchPageTab('export')">
        <i class="fas fa-file-export"></i> Export
    </button>

    <div style="margin-left:auto;display:flex;gap:6px;flex-wrap:wrap">
        <span style="font-family:var(--fh);font-size:11px;font-weight:900;color:var(--muted);display:flex;align-items:center;gap:6px">
            <i class="fas fa-calendar" style="color:var(--neon)"></i>
            <?= date('d/m/Y', strtotime($filter_date)) ?>
        </span>
    </div>
</div>

<!-- ══════ FILTER BAR ══════ -->
<form method="GET" class="filter-bar">
    <div class="fg">
        <label><i class="fas fa-calendar"></i> Date</label>
        <input type="date" name="date" id="filter-date" value="<?= htmlspecialchars($filter_date) ?>" required>
    </div>
    <div class="fg">
        <label><i class="fas fa-user"></i> Employé</label>
        <select name="employee">
            <option value="">— Tous —</option>
            <?php foreach ($employees as $emp): ?>
            <option value="<?= $emp['id'] ?>" <?= $filter_employee == $emp['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($emp['full_name']) ?> (<?= $emp['employee_code'] ?>)
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="fg">
        <label><i class="fas fa-arrow-left"></i> Veille</label>
        <a href="?date=<?= date('Y-m-d', strtotime($filter_date.' -1 day')) ?>" class="btn btn-b btn-sm" style="height:40px">
            <i class="fas fa-chevron-left"></i> <?= date('d/m', strtotime($filter_date.' -1 day')) ?>
        </a>
    </div>
    <div class="fg">
        <label><i class="fas fa-arrow-right"></i> Lendemain</label>
        <a href="?date=<?= date('Y-m-d', strtotime($filter_date.' +1 day')) ?>" class="btn btn-b btn-sm" style="height:40px">
            <?= date('d/m', strtotime($filter_date.' +1 day')) ?> <i class="fas fa-chevron-right"></i>
        </a>
    </div>
    <button type="submit" class="btn btn-n" style="align-self:flex-end;height:40px"><i class="fas fa-filter"></i> Filtrer</button>
    <a href="?date=<?= date('Y-m-d') ?>" class="btn btn-g btn-sm" style="align-self:flex-end;height:40px"><i class="fas fa-today"></i> Aujourd'hui</a>
</form>

<!-- ══════ KPI STRIP ══════ -->
<div class="kpi-strip">
    <div class="ks" style="animation-delay:.05s">
        <div class="ks-ico" style="background:rgba(50,190,143,0.14);color:var(--neon)"><i class="fas fa-users"></i></div>
        <div><div class="ks-val" style="color:var(--neon)"><?= $total_present ?></div><div class="ks-lbl">Présents</div></div>
    </div>
    <div class="ks" style="animation-delay:.08s">
        <div class="ks-ico" style="background:rgba(6,182,212,0.14);color:var(--cyan)"><i class="fas fa-check-circle"></i></div>
        <div><div class="ks-val" style="color:var(--cyan)"><?= $total_ontime ?></div><div class="ks-lbl">À l'heure</div></div>
    </div>
    <div class="ks" style="animation-delay:.11s">
        <div class="ks-ico" style="background:rgba(255,53,83,0.14);color:var(--red)"><i class="fas fa-exclamation-circle"></i></div>
        <div><div class="ks-val" style="color:var(--red)"><?= $total_late ?></div><div class="ks-lbl">En retard</div></div>
    </div>
    <div class="ks" style="animation-delay:.14s">
        <div class="ks-ico" style="background:rgba(255,208,96,0.14);color:var(--gold)"><i class="fas fa-sign-out-alt"></i></div>
        <div><div class="ks-val" style="color:var(--gold)"><?= $total_departed ?></div><div class="ks-lbl">Partis</div></div>
    </div>
    <div class="ks" style="animation-delay:.17s">
        <div class="ks-ico" style="background:rgba(168,85,247,0.14);color:var(--purple)"><i class="fas fa-user-slash"></i></div>
        <div><div class="ks-val" style="color:var(--purple)"><?= $total_absent ?></div><div class="ks-lbl">Absents</div></div>
    </div>
    <div class="ks" style="animation-delay:.20s">
        <div class="ks-ico" style="background:rgba(255,53,83,0.14);color:var(--red)"><i class="fas fa-minus-circle"></i></div>
        <div><div class="ks-val" style="color:var(--red)"><?= $total_penalty > 0 ? '-'.number_format($total_penalty,0,'','.').'F' : '0' ?></div><div class="ks-lbl">Pénalités</div></div>
    </div>
    <div class="ks" style="animation-delay:.23s">
        <div class="ks-ico" style="background:rgba(50,190,143,0.14);color:var(--neon)"><i class="fas fa-coins"></i></div>
        <div><div class="ks-val" style="color:var(--neon)"><?= $total_overtime > 0 ? '+'.number_format($total_overtime,0,'','.').'F' : '0' ?></div><div class="ks-lbl">H.Sup FCFA</div></div>
    </div>
</div>

<!-- ══════════════════════════════════════
     PANEL : AUJOURD'HUI
══════════════════════════════════════ -->
<div id="panel-today" class="tab-panel active">

    <!-- Barre recherche + contrôles -->
    <div class="ctrl-bar">
        <input type="text" class="s-input" id="live-search" placeholder="🔍 Rechercher par nom, code, catégorie…" oninput="liveSearch()">
        <span id="search-count" style="font-family:var(--fh);font-size:12px;font-weight:900;color:var(--muted);white-space:nowrap"></span>
        <button id="view-btn" onclick="toggleView()" class="btn btn-c btn-sm"><i class="fas fa-list"></i> Liste</button>
        <button onclick="window.print()" class="btn btn-p btn-sm"><i class="fas fa-print"></i> Imprimer</button>
    </div>

    <?php if (!empty($attendances)): ?>
    <div class="att-grid" id="att-grid">
    <?php foreach ($attendances as $i => $att):
        $is_late    = $att['status'] === 'retard';
        $has_out    = !empty($att['check_out']);
        $has_gps_in = !empty($att['latitude']) && !empty($att['longitude']);
        $has_gps_out= !empty($att['checkout_latitude']) && !empty($att['checkout_longitude']);
        $has_selfie_in  = !empty($att['selfie_path']) && file_exists($att['selfie_path']);
        $has_selfie_out = !empty($att['checkout_selfie_path']) && file_exists($att['checkout_selfie_path']);
        $sp_cls = $is_late ? 'sp-late' : ($has_out ? 'sp-gone' : 'sp-work');
        $sp_txt = $is_late ? 'Retard' : ($has_out ? 'Parti' : 'En cours');
        $anim_delay = ($i * 0.06);
    ?>
    <div class="att-card"
         data-att="<?= $att['id'] ?>"
         data-name="<?= strtolower(htmlspecialchars($att['full_name'])) ?>"
         data-code="<?= strtolower(htmlspecialchars($att['employee_code'])) ?>"
         data-cat="<?= strtolower(htmlspecialchars($att['category_name'])) ?>"
         style="animation-delay:<?= $anim_delay ?>s">

        <!-- Card Header -->
        <div class="att-head <?= $is_late ? 'late' : '' ?>">
            <div class="emp-name"><?= htmlspecialchars($att['full_name']) ?></div>
            <div class="emp-meta" style="margin-top:6px">
                <span class="emp-code"><?= htmlspecialchars($att['employee_code']) ?></span>
                <span class="emp-pos"><?= htmlspecialchars($att['position_title']) ?></span>
                <span class="emp-cat"><?= htmlspecialchars($att['category_name']) ?></span>
            </div>
            <div class="status-pill <?= $sp_cls ?>"><?= $sp_txt ?></div>
        </div>

        <!-- Card Tabs (Arrivée / Départ / Comparer) -->
        <div class="card-tabs">
            <button class="ct active" data-panel="checkin" onclick="switchCardTab(<?= $att['id'] ?>,'checkin')">
                <i class="fas fa-sign-in-alt" style="color:var(--neon)"></i> Arrivée
            </button>
            <button class="ct departure" data-panel="checkout" onclick="switchCardTab(<?= $att['id'] ?>,'checkout')">
                <i class="fas fa-sign-out-alt" style="color:var(--gold)"></i> Départ
            </button>
            <?php if ($has_gps_in && $has_gps_out): ?>
            <button class="ct compare" data-panel="compare" onclick="switchCardTab(<?= $att['id'] ?>,'compare')">
                <i class="fas fa-map-marked-alt" style="color:var(--purple)"></i> Comparer
            </button>
            <?php endif; ?>
        </div>

        <!-- ── PANEL ARRIVÉE ── -->
        <div class="c-panel active" data-panel="checkin">
        <div class="card-body">

            <!-- Selfie arrivée -->
            <?php if ($has_selfie_in): ?>
            <div class="selfie-box" onclick="openFullscreen('fs-checkin-<?= $att['id'] ?>')">
                <img src="<?= htmlspecialchars($att['selfie_path']) ?>" alt="Selfie arrivée">
                <div class="selfie-overlay">
                    <span class="selfie-ts"><?= substr($att['check_in'],0,5) ?></span>
                    <div class="selfie-zoom"><i class="fas fa-expand-alt"></i></div>
                </div>
            </div>
            <?php else: ?>
            <div class="no-selfie"><i class="fas fa-user-circle"></i><span>Aucun selfie d'arrivée</span></div>
            <?php endif; ?>

            <!-- Heure arrivée -->
            <div class="time-blocks tb-single">
                <div class="time-block">
                    <div class="tb-label">⏰ Heure d'arrivée</div>
                    <div class="tb-value"><?= substr($att['check_in'],0,5) ?></div>
                    <div class="tb-sub"><?= date('d/m/Y', strtotime($att['work_date'])) ?></div>
                </div>
            </div>

            <!-- Status / pénalité -->
            <?php if ($is_late && $att['minutes_late'] > 0): ?>
            <div class="alert-box ab-red">
                <i class="fas fa-clock" style="font-size:20px;flex-shrink:0"></i>
                <div class="ab-txt">
                    <strong><?= $att['minutes_late'] ?> min de retard</strong>
                    <span>Pénalité : -<?= number_format($att['penalty_amount'],0,'','') ?> FCFA</span>
                </div>
            </div>
            <?php else: ?>
            <div class="alert-box ab-neon">
                <i class="fas fa-check-circle" style="font-size:20px;flex-shrink:0"></i>
                <div class="ab-txt"><strong>À l'heure</strong><span>Pas de pénalité</span></div>
            </div>
            <?php endif; ?>

            <!-- GPS arrivée -->
            <?php if ($has_gps_in): ?>
            <div class="info-row ir-cyan">
                <i class="fas fa-map-marker-alt" style="color:var(--cyan)"></i>
                <span>GPS : <?= number_format($att['latitude'],6) ?>, <?= number_format($att['longitude'],6) ?></span>
                <a href="https://www.google.com/maps?q=<?= $att['latitude'] ?>,<?= $att['longitude'] ?>" target="_blank"
                   style="margin-left:auto;color:var(--cyan);font-size:11px;font-weight:700;text-decoration:none">
                   <i class="fas fa-external-link-alt"></i> Maps
                </a>
            </div>
            <div class="map-box">
                <iframe src="https://www.google.com/maps/embed/v1/view?key=AIzaSyBFw0Qbyq9zTFTd-tUY6dZWTgaQzuU17R8&center=<?= $att['latitude'] ?>,<?= $att['longitude'] ?>&zoom=18&maptype=satellite"
                        allowfullscreen referrerpolicy="no-referrer-when-downgrade"></iframe>
            </div>
            <?php else: ?>
            <div class="alert-box ab-muted"><i class="fas fa-map-marker-slash"></i><div class="ab-txt"><strong>Aucune géolocalisation</strong></div></div>
            <?php endif; ?>

            <!-- Actions -->
            <div class="card-acts" style="margin-top:14px">
                <?php if ($has_selfie_in): ?>
                <button onclick="confirmDelete(<?= $att['id'] ?>,'<?= htmlspecialchars(addslashes($att['full_name'])) ?>','checkin')" class="btn btn-r btn-sm">
                    <i class="fas fa-trash"></i> Suppr. selfie
                </button>
                <?php endif; ?>
                <button onclick="printCard(<?= $att['id'] ?>)" class="btn btn-p btn-sm">
                    <i class="fas fa-print"></i> Imprimer
                </button>
            </div>
        </div>
        </div>

        <!-- ── PANEL DÉPART ── -->
        <div class="c-panel" data-panel="checkout">
        <div class="card-body">
        <?php if ($has_out): ?>

            <?php if ($has_selfie_out): ?>
            <div class="selfie-box" onclick="openFullscreen('fs-checkout-<?= $att['id'] ?>')">
                <img src="<?= htmlspecialchars($att['checkout_selfie_path']) ?>" alt="Selfie départ">
                <div class="selfie-overlay">
                    <span class="selfie-ts"><?= substr($att['check_out'],0,5) ?></span>
                    <div class="selfie-zoom"><i class="fas fa-expand-alt"></i></div>
                </div>
            </div>
            <?php else: ?>
            <div class="no-selfie"><i class="fas fa-user-circle"></i><span>Aucun selfie de départ</span></div>
            <?php endif; ?>

            <div class="time-blocks">
                <div class="time-block">
                    <div class="tb-label" style="color:var(--gold)">🏁 Départ</div>
                    <div class="tb-value" style="color:var(--gold)"><?= substr($att['check_out'],0,5) ?></div>
                </div>
                <?php if ($att['hours_worked']): ?>
                <div class="time-block">
                    <div class="tb-label">⏱️ Heures</div>
                    <div class="tb-value" style="color:var(--cyan)"><?= $att['hours_worked'] ?>h</div>
                </div>
                <?php endif; ?>
            </div>

            <?php if (($att['overtime_hours'] ?? 0) > 0): ?>
            <div class="alert-box ab-neon">
                <i class="fas fa-coins" style="font-size:20px;flex-shrink:0"></i>
                <div class="ab-txt">
                    <strong>Heures supplémentaires</strong>
                    <span><?= $att['overtime_hours'] ?>h — +<?= number_format($att['overtime_amount'],0,'','') ?> FCFA</span>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($has_gps_out): ?>
            <div class="info-row ir-gold">
                <i class="fas fa-map-marker-alt" style="color:var(--gold)"></i>
                <span>GPS : <?= number_format($att['checkout_latitude'],6) ?>, <?= number_format($att['checkout_longitude'],6) ?></span>
                <a href="https://www.google.com/maps?q=<?= $att['checkout_latitude'] ?>,<?= $att['checkout_longitude'] ?>" target="_blank"
                   style="margin-left:auto;color:var(--gold);font-size:11px;font-weight:700;text-decoration:none">
                   <i class="fas fa-external-link-alt"></i> Maps
                </a>
            </div>
            <div class="map-box">
                <iframe src="https://www.google.com/maps/embed/v1/view?key=AIzaSyBFw0Qbyq9zTFTd-tUY6dZWTgaQzuU17R8&center=<?= $att['checkout_latitude'] ?>,<?= $att['checkout_longitude'] ?>&zoom=18&maptype=satellite"
                        allowfullscreen referrerpolicy="no-referrer-when-downgrade"></iframe>
            </div>
            <?php endif; ?>

            <div class="card-acts" style="margin-top:14px">
                <?php if ($has_selfie_out): ?>
                <button onclick="confirmDelete(<?= $att['id'] ?>,'<?= htmlspecialchars(addslashes($att['full_name'])) ?>','checkout')" class="btn btn-r btn-sm">
                    <i class="fas fa-trash"></i> Suppr. selfie
                </button>
                <?php endif; ?>
                <button onclick="printCard(<?= $att['id'] ?>)" class="btn btn-p btn-sm">
                    <i class="fas fa-print"></i> Imprimer
                </button>
            </div>

        <?php else: ?>
            <div class="alert-box ab-gold" style="flex-direction:column;text-align:center;padding:36px 20px">
                <i class="fas fa-clock" style="font-size:44px;margin-bottom:12px"></i>
                <div class="ab-txt">
                    <strong style="font-size:15px">Pas encore parti</strong>
                    <span><?= htmlspecialchars($att['full_name']) ?> n'a pas pointé son départ</span>
                </div>
            </div>
        <?php endif; ?>
        </div>
        </div>

        <!-- ── PANEL COMPARER (GPS arrivée vs départ) ── -->
        <?php if ($has_gps_in && $has_gps_out): ?>
        <div class="c-panel" data-panel="compare">
        <div class="card-body">
            <div class="compare-grid">
                <div>
                    <div class="cg-header cg-in">📍 Arrivée</div>
                    <div class="cg-map">
                        <iframe src="https://www.google.com/maps/embed/v1/view?key=AIzaSyBFw0Qbyq9zTFTd-tUY6dZWTgaQzuU17R8&center=<?= $att['latitude'] ?>,<?= $att['longitude'] ?>&zoom=18&maptype=satellite"
                                allowfullscreen referrerpolicy="no-referrer-when-downgrade"></iframe>
                    </div>
                </div>
                <div>
                    <div class="cg-header cg-out">🏁 Départ</div>
                    <div class="cg-map">
                        <iframe src="https://www.google.com/maps/embed/v1/view?key=AIzaSyBFw0Qbyq9zTFTd-tUY6dZWTgaQzuU17R8&center=<?= $att['checkout_latitude'] ?>,<?= $att['checkout_longitude'] ?>&zoom=18&maptype=satellite"
                                allowfullscreen referrerpolicy="no-referrer-when-downgrade"></iframe>
                    </div>
                </div>
            </div>
            <!-- Distance calculée par JS -->
            <div class="dist-badge" data-dist-target="1"
                 data-lat1="<?= $att['latitude'] ?>" data-lon1="<?= $att['longitude'] ?>"
                 data-lat2="<?= $att['checkout_latitude'] ?>" data-lon2="<?= $att['checkout_longitude'] ?>">
                <i class="fas fa-ruler-horizontal"></i> Calcul distance…
            </div>
        </div>
        </div>
        <?php endif; ?>

    </div><!-- /att-card -->

    <!-- Modals Fullscreen selfies -->
    <?php if ($has_selfie_in): ?>
    <div class="modal-fs" id="fs-checkin-<?= $att['id'] ?>" onclick="closeFullscreen('fs-checkin-<?= $att['id'] ?>')">
        <div class="modal-fs-content" onclick="event.stopPropagation()">
            <div class="modal-close" onclick="closeFullscreen('fs-checkin-<?= $att['id'] ?>')"><i class="fas fa-times"></i></div>
            <img src="<?= htmlspecialchars($att['selfie_path']) ?>" alt="Selfie arrivée">
            <div class="modal-info">
                <h3 style="color:var(--neon)"><i class="fas fa-sign-in-alt"></i> <?= htmlspecialchars($att['full_name']) ?> — Arrivée</h3>
                <div class="modal-grid">
                    <div><strong>Code</strong> <?= $att['employee_code'] ?></div>
                    <div><strong>Date</strong> <?= date('d/m/Y', strtotime($att['work_date'])) ?></div>
                    <div><strong>Heure</strong> <span style="font-family:var(--fh);font-size:16px;color:var(--neon)"><?= substr($att['check_in'],0,5) ?></span></div>
                    <div><strong>Statut</strong>
                        <span class="bdg <?= $is_late?'bdg-r':'bdg-n' ?>"><?= $is_late?'Retard':'À l\'heure' ?></span>
                    </div>
                    <?php if ($has_gps_in): ?>
                    <div style="grid-column:1/-1">
                        <strong>GPS</strong>
                        <?= number_format($att['latitude'],6) ?>, <?= number_format($att['longitude'],6) ?>
                        <a href="https://www.google.com/maps?q=<?= $att['latitude'] ?>,<?= $att['longitude'] ?>" target="_blank"
                           style="color:var(--cyan);font-weight:700;margin-left:8px;font-size:12px;text-decoration:none">
                           <i class="fas fa-external-link-alt"></i> Google Maps
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($has_selfie_out): ?>
    <div class="modal-fs" id="fs-checkout-<?= $att['id'] ?>" onclick="closeFullscreen('fs-checkout-<?= $att['id'] ?>')">
        <div class="modal-fs-content" onclick="event.stopPropagation()">
            <div class="modal-close" onclick="closeFullscreen('fs-checkout-<?= $att['id'] ?>')"><i class="fas fa-times"></i></div>
            <img src="<?= htmlspecialchars($att['checkout_selfie_path']) ?>" alt="Selfie départ">
            <div class="modal-info">
                <h3 style="color:var(--gold)"><i class="fas fa-sign-out-alt"></i> <?= htmlspecialchars($att['full_name']) ?> — Départ</h3>
                <div class="modal-grid">
                    <div><strong>Code</strong> <?= $att['employee_code'] ?></div>
                    <div><strong>Date</strong> <?= date('d/m/Y', strtotime($att['work_date'])) ?></div>
                    <div><strong>Départ</strong> <span style="font-family:var(--fh);font-size:16px;color:var(--gold)"><?= $att['check_out'] ? substr($att['check_out'],0,5) : '—' ?></span></div>
                    <div><strong>Heures</strong> <span style="color:var(--cyan)"><?= $att['hours_worked'] ?? '—' ?>h</span></div>
                    <?php if ($has_gps_out): ?>
                    <div style="grid-column:1/-1">
                        <strong>GPS</strong>
                        <?= number_format($att['checkout_latitude'],6) ?>, <?= number_format($att['checkout_longitude'],6) ?>
                        <a href="https://www.google.com/maps?q=<?= $att['checkout_latitude'] ?>,<?= $att['checkout_longitude'] ?>" target="_blank"
                           style="color:var(--gold);font-weight:700;margin-left:8px;font-size:12px;text-decoration:none">
                           <i class="fas fa-external-link-alt"></i> Google Maps
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php endforeach; ?>
    </div><!-- /att-grid -->

    <?php else: ?>
    <div class="empty-state">
        <i class="fas fa-calendar-times"></i>
        <h3>Aucun pointage ce jour</h3>
        <p>Aucun employé n'a pointé le <?= date('d/m/Y', strtotime($filter_date)) ?></p>
    </div>
    <?php endif; ?>
</div><!-- /panel-today -->

<!-- ══════════════════════════════════════
     PANEL : SEMAINE
══════════════════════════════════════ -->
<div id="panel-week" class="tab-panel">
    <div class="panel">
        <div class="ph">
            <div class="ph-title">
                <div class="dot c"></div>
                Semaine du <?= date('d/m', strtotime($week_start)) ?> au <?= date('d/m/Y', strtotime($week_end)) ?>
            </div>
            <span style="font-family:var(--fh);font-size:11px;color:var(--muted)"><?= count($week_data) ?> entrée(s)</span>
        </div>
        <div class="pb">
        <div class="tbl-wrap">
        <table class="week-table">
            <thead>
            <tr>
                <th>Date</th><th>Employé</th><th>Arrivée</th><th>Départ</th>
                <th>Heures</th><th>Statut</th><th>Pénalité</th><th>H.Sup</th>
            </tr>
            </thead>
            <tbody id="week-tbody">
                <tr><td colspan="8" style="text-align:center;padding:28px;color:var(--muted)">Chargement…</td></tr>
            </tbody>
        </table>
        </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════
     PANEL : STATS
══════════════════════════════════════ -->
<div id="panel-stats" class="tab-panel">
    <div class="stats-panel">
        <div class="stat-box">
            <h3><div class="dot n"></div> Répartition présence</h3>
            <div class="chart-wrap"><canvas id="chart-presence"></canvas></div>
        </div>
        <div class="stat-box">
            <h3><div class="dot c"></div> Distribution horaires d'arrivée</h3>
            <div class="chart-wrap"><canvas id="chart-horaires"></canvas></div>
        </div>
        <div class="stat-box">
            <h3><div class="dot g"></div> Heures supplémentaires par employé</h3>
            <div class="chart-wrap"><canvas id="chart-ot"></canvas></div>
        </div>
        <div class="stat-box">
            <h3><div class="dot p"></div> État des départs</h3>
            <div class="chart-wrap"><canvas id="chart-depart"></canvas></div>
        </div>
    </div>

    <!-- Barres progression -->
    <div class="panel">
        <div class="ph"><div class="ph-title"><div class="dot n"></div> Résumé journalier</div></div>
        <div class="pb">
            <?php
            $total_emp = $total_present + $total_absent;
            $pct_present = $total_emp ? round(($total_present/$total_emp)*100) : 0;
            $pct_late    = $total_present ? round(($total_late/$total_present)*100) : 0;
            $pct_dept    = $total_present ? round(($total_departed/$total_present)*100) : 0;
            $pct_ot      = $total_present ? count(array_filter($attendances,fn($a)=>($a['overtime_hours']??0)>0)) : 0;
            ?>
            <div class="prog-row">
                <span class="prog-label">Taux présence</span>
                <div class="prog-bar"><div class="prog-fill" style="width:<?= $pct_present ?>%;background:var(--neon)"></div></div>
                <span class="prog-val"><?= $pct_present ?>%</span>
            </div>
            <div class="prog-row">
                <span class="prog-label">Retards</span>
                <div class="prog-bar"><div class="prog-fill" style="width:<?= $pct_late ?>%;background:var(--red)"></div></div>
                <span class="prog-val"><?= $pct_late ?>%</span>
            </div>
            <div class="prog-row">
                <span class="prog-label">Ont pointé départ</span>
                <div class="prog-bar"><div class="prog-fill" style="width:<?= $pct_dept ?>%;background:var(--gold)"></div></div>
                <span class="prog-val"><?= $pct_dept ?>%</span>
            </div>
            <div class="prog-row">
                <span class="prog-label">Avec H.Sup</span>
                <div class="prog-bar"><div class="prog-fill" style="width:<?= $total_present?round(($pct_ot/$total_present)*100):0 ?>%;background:var(--purple)"></div></div>
                <span class="prog-val"><?= $pct_ot ?></span>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════
     PANEL : ABSENTS
══════════════════════════════════════ -->
<div id="panel-absents" class="tab-panel">
    <div class="panel">
        <div class="ph">
            <div class="ph-title"><div class="dot r"></div> Employés absents — <?= date('d/m/Y', strtotime($filter_date)) ?></div>
            <span style="font-family:var(--fh);font-size:13px;font-weight:900;color:var(--red)"><?= $total_absent ?> absent(s)</span>
        </div>
        <div class="pb">
            <div id="absents-list" class="absent-grid"></div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════
     PANEL : EXPORT
══════════════════════════════════════ -->
<div id="panel-export" class="tab-panel">
    <div class="panel">
        <div class="ph"><div class="ph-title"><div class="dot n"></div> Export et rapports</div></div>
        <div class="pb">
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px">
                <div style="background:rgba(50,190,143,0.06);border:1px solid rgba(50,190,143,0.2);border-radius:13px;padding:20px;text-align:center">
                    <i class="fas fa-file-csv" style="font-size:36px;color:var(--neon);display:block;margin-bottom:12px"></i>
                    <div style="font-family:var(--fh);font-size:14px;font-weight:900;color:var(--text);margin-bottom:6px">Export CSV Jour</div>
                    <div style="font-size:12px;color:var(--muted);margin-bottom:14px">Tous les pointages du <?= date('d/m/Y',strtotime($filter_date)) ?> — Compatible Excel Windows</div>
                    <button onclick="exportCSV()" class="btn btn-n btn-full"><i class="fas fa-download"></i> Télécharger CSV</button>
                </div>
                <div style="background:rgba(6,182,212,0.06);border:1px solid rgba(6,182,212,0.2);border-radius:13px;padding:20px;text-align:center">
                    <i class="fas fa-calendar-week" style="font-size:36px;color:var(--cyan);display:block;margin-bottom:12px"></i>
                    <div style="font-family:var(--fh);font-size:14px;font-weight:900;color:var(--text);margin-bottom:6px">Rapport Semaine</div>
                    <div style="font-size:12px;color:var(--muted);margin-bottom:14px">Du <?= date('d/m',strtotime($week_start)) ?> au <?= date('d/m/Y',strtotime($week_end)) ?></div>
                    <button onclick="exportWeekCSV()" class="btn btn-c btn-full"><i class="fas fa-download"></i> Télécharger CSV</button>
                </div>
                <div style="background:rgba(168,85,247,0.06);border:1px solid rgba(168,85,247,0.2);border-radius:13px;padding:20px;text-align:center">
                    <i class="fas fa-print" style="font-size:36px;color:var(--purple);display:block;margin-bottom:12px"></i>
                    <div style="font-family:var(--fh);font-size:14px;font-weight:900;color:var(--text);margin-bottom:6px">Impression</div>
                    <div style="font-size:12px;color:var(--muted);margin-bottom:14px">Imprimer la liste du jour</div>
                    <button onclick="window.print()" class="btn btn-p btn-full"><i class="fas fa-print"></i> Imprimer</button>
                </div>
            </div>

            <!-- Stats récap export -->
            <div style="margin-top:18px;background:rgba(0,0,0,0.15);border:1px solid var(--bord);border-radius:12px;padding:16px">
                <div style="font-family:var(--fh);font-size:12px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:12px">Contenu de l'export</div>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;font-size:13px;color:var(--text2)">
                    <div>✅ Nom & code employé</div>
                    <div>✅ Heure arrivée/départ</div>
                    <div>✅ Heures travaillées</div>
                    <div>✅ Statut & retards</div>
                    <div>✅ Pénalités FCFA</div>
                    <div>✅ Heures supplémentaires</div>
                    <div>✅ Coordonnées GPS arrivée</div>
                    <div>✅ Coordonnées GPS départ</div>
                </div>
            </div>
        </div>
    </div>
</div>

</div><!-- /wrap -->

<!-- TOAST STACK -->
<div class="toast-stack" id="toast-stack"></div>

<!-- CONFIRM MODAL (dark neon, remplace SweetAlert) -->
<div id="confirm-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.88);z-index:3000;align-items:center;justify-content:center;backdrop-filter:blur(10px)">
<div style="background:var(--card);border:1px solid rgba(255,53,83,0.3);border-radius:18px;padding:28px;max-width:380px;width:92%;text-align:center;animation:zoomIn .25s ease">
    <div style="width:56px;height:56px;background:rgba(255,53,83,0.12);border:2px solid rgba(255,53,83,0.3);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:24px;color:var(--red)">
        <i class="fas fa-trash"></i>
    </div>
    <h3 id="confirm-title" style="font-family:var(--fh);font-size:18px;font-weight:900;color:var(--red);margin-bottom:10px"></h3>
    <p id="confirm-body" style="font-size:13px;color:var(--text2);margin-bottom:22px;line-height:1.6"></p>
    <div style="display:flex;gap:10px;justify-content:center">
        <button id="confirm-ok" class="btn btn-r"><i class="fas fa-check"></i> Supprimer</button>
        <button onclick="closeConfirm()" class="btn btn-b"><i class="fas fa-times"></i> Annuler</button>
    </div>
</div>
</div>

<form id="delete-form" method="POST" style="display:none">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="attendance_id" id="df-id">
    <input type="hidden" name="selfie_type" id="df-type">
    <input type="hidden" name="delete_selfie" value="1">
</form>

<script>
/* ── Export semaine CSV (données côté PHP) ── */
function exportWeekCSV() {
    const el = document.getElementById('week-data');
    if (!el) return;
    const rows = JSON.parse(el.textContent || '[]');
    if (!rows.length) { toast('Aucune donnée cette semaine','warn'); return; }
    const headers = ['Date','Employé','Code','Arrivée','Départ','Heures','Statut','Retard(min)','Pénalité','H.Sup','Montant Sup'];
    const lines = ['\xEF\xBB\xBF' + headers.join(';')];
    rows.forEach(r => {
        lines.push([
            r.work_date, r.full_name, r.employee_code,
            r.check_in||'', r.check_out||'', r.hours_worked||'',
            r.status||'', r.minutes_late||0, r.penalty_amount||0,
            r.overtime_hours||0, r.overtime_amount||0
        ].map(v=>`"${String(v).replace(/"/g,'""')}"`).join(';'));
    });
    const blob = new Blob([lines.join('\n')],{type:'text/csv;charset=utf-8;'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `pointages_semaine_<?= date('Y-Ww', strtotime($filter_date)) ?>.csv`;
    a.click(); toast('Export semaine téléchargé !','success');
}

/* ── Confirm delete (dark neon) ── */
function confirmDelete(id, name, type) {
    const typeText = type==='checkout' ? 'départ' : 'arrivée';
    document.getElementById('confirm-title').textContent = `Supprimer le selfie de ${typeText} ?`;
    document.getElementById('confirm-body').textContent = `Êtes-vous sûr de supprimer le selfie de ${typeText} de ${name} ? Cette action est irréversible.`;
    document.getElementById('df-id').value = id;
    document.getElementById('df-type').value = type;
    document.getElementById('confirm-ok').onclick = () => { document.getElementById('delete-form').submit(); };
    const ov = document.getElementById('confirm-overlay');
    ov.style.display = 'flex';
}
function closeConfirm() { document.getElementById('confirm-overlay').style.display = 'none'; }
document.getElementById('confirm-overlay').addEventListener('click', e => {
    if (e.target === document.getElementById('confirm-overlay')) closeConfirm();
});

/* Flash message */
<?php if ($flash['type']): ?>
window.addEventListener('DOMContentLoaded', () => {
    setTimeout(() => toast('<?= htmlspecialchars(addslashes($flash['msg'])) ?>','<?= $flash['type'] ?>'), 400);
});
<?php endif; ?>
</script>

<script src="admin_attendance_scripts.js"></script>
</body>
</html>
