<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

session_start();

require_once APP_ROOT . '/app/core/DB.php';
require_once APP_ROOT . '/app/core/Auth.php';
require_once APP_ROOT . '/app/core/Middleware.php';

use App\Core\Auth;
use App\Core\DB;
use App\Core\Middleware;

Auth::check();
Middleware::role(['developer', 'admin', 'manager', 'informaticien', 'Superviseur', 'Directrice']);

$pdo = DB::getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function h(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function alertDomain(string $eventType): string
{
    $t = strtolower(trim($eventType));
    if (strpos($t, 'appro_') === 0) return 'appro';
    if (strpos($t, 'hr_') === 0 || strpos($t, 'attendance_') === 0) return 'hr';
    if (strpos($t, 'invoice_') === 0 || strpos($t, 'payment_') === 0 || strpos($t, 'finance_') === 0 || $t === 'low_stock') return 'finance';
    return 'other';
}

$since = date('Y-m-d H:i:s', time() - 86400);

$summaryStmt = $pdo->prepare("\n    SELECT\n        COUNT(*) total_events,\n        COALESCE(SUM(sent_webpush),0) sent_webpush,\n        COALESCE(SUM(sent_fcm),0) sent_fcm,\n        COALESCE(SUM(failed_webpush),0) failed_webpush,\n        COALESCE(SUM(failed_fcm),0) failed_fcm\n    FROM app_alert_logs\n    WHERE created_at >= ?\n");
$summaryStmt->execute([$since]);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$totalEvents = (int)($summary['total_events'] ?? 0);
$sentWebpush = (int)($summary['sent_webpush'] ?? 0);
$sentFcm = (int)($summary['sent_fcm'] ?? 0);
$failedWebpush = (int)($summary['failed_webpush'] ?? 0);
$failedFcm = (int)($summary['failed_fcm'] ?? 0);
$totalFailed = $failedWebpush + $failedFcm;
$totalSent = $sentWebpush + $sentFcm;
$failureRate = $totalSent > 0 ? round(($totalFailed / $totalSent) * 100, 2) : 0;

$topStmt = $pdo->prepare("\n    SELECT\n        event_type,\n        COUNT(*) total,\n        COALESCE(SUM(sent_webpush + sent_fcm),0) sent_total,\n        COALESCE(SUM(failed_webpush + failed_fcm),0) failed_total\n    FROM app_alert_logs\n    WHERE created_at >= ?\n    GROUP BY event_type\n    ORDER BY total DESC, failed_total DESC\n    LIMIT 12\n");
$topStmt->execute([$since]);
$topTypes = $topStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$domainSummary = ['hr' => 0, 'finance' => 0, 'appro' => 0, 'other' => 0];
$domainFail = ['hr' => 0, 'finance' => 0, 'appro' => 0, 'other' => 0];
foreach ($topTypes as $row) {
    $domain = alertDomain((string)$row['event_type']);
    $domainSummary[$domain] += (int)$row['total'];
    $domainFail[$domain] += (int)$row['failed_total'];
}

$failStmt = $pdo->prepare("\n    SELECT\n        id, created_at, event_type, title, body, target_roles, target_url,\n        sent_webpush, sent_fcm, failed_webpush, failed_fcm\n    FROM app_alert_logs\n    WHERE created_at >= ?\n      AND (failed_webpush > 0 OR failed_fcm > 0)\n    ORDER BY id DESC\n    LIMIT 80\n");
$failStmt->execute([$since]);
$failedRows = $failStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>Santé Notifications (24h)</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=Source+Serif+4:wght@700;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
@font-face {
    font-family: 'C059';
    src: local('C059-Bold'), local('C059 Bold'), local('C059BT-Bold'), local('Century Schoolbook'), local('Century Old Style Std');
    font-weight: 700 900;
    font-style: normal;
}
:root {
    --bg:#0f1726; --card:#1b263b; --surf:#162033; --bord:rgba(148,163,184,0.18);
    --neon:#00a86b; --cyan:#06b6d4; --blue:#2563eb; --purple:#7c3aed; --gold:#f59e0b; --red:#e53935;
    --text:#e8eef8; --text2:#bfd0e4; --muted:#8ea3bd;
    --fh:'C059','Source Serif 4','Playfair Display','Book Antiqua','Palatino Linotype',Georgia,serif;
    --fb:'Inter','Segoe UI',system-ui,sans-serif;
}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--fb);background:var(--bg);color:var(--text);min-height:100vh;line-height:1.45}
body::before{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;background:radial-gradient(ellipse 65% 42% at 5% 8%,rgba(0,168,107,.05) 0%,transparent 62%),radial-gradient(ellipse 52% 36% at 96% 88%,rgba(37,99,235,.04) 0%,transparent 62%),radial-gradient(ellipse 38% 28% at 52% 52%,rgba(245,158,11,.04) 0%,transparent 70%)}
.wrap{position:relative;z-index:1;max-width:1500px;margin:0 auto;padding:16px 14px 44px}
.top{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:14px}
.h1{font-family:var(--fh);font-size:26px;font-weight:900}
.meta{font-size:13px;color:var(--muted);margin-top:3px}
.actions{display:flex;gap:8px;flex-wrap:wrap}
.btn{display:inline-flex;gap:8px;align-items:center;text-decoration:none;border:1px solid var(--bord);border-radius:12px;background:linear-gradient(135deg,rgba(6,182,212,.18),rgba(37,99,235,.16));color:var(--text);padding:10px 13px;font-weight:800;font-size:12px}
.grid4{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:12px}
.kpi{background:linear-gradient(145deg,rgba(27,38,59,.96),rgba(22,32,51,.95));border:1px solid var(--bord);border-radius:13px;padding:10px 11px}
.kpi .v{font-family:var(--fh);font-size:21px;font-weight:900}
.kpi .l{font-size:11px;color:var(--text2);font-weight:700}
.panel{background:linear-gradient(145deg,rgba(27,38,59,.96),rgba(22,32,51,.95));border:1px solid var(--bord);border-radius:14px;padding:12px;margin-bottom:12px}
.p-title{font-family:var(--fh);font-size:18px;font-weight:900;margin-bottom:10px}
.split{display:grid;grid-template-columns:1.1fr 1fr;gap:12px}
.list{display:grid;gap:8px}
.item{background:rgba(15,23,38,.75);border:1px solid var(--bord);border-radius:11px;padding:9px 10px}
.item .row{display:flex;justify-content:space-between;gap:8px;align-items:center}
.badge{display:inline-flex;align-items:center;border-radius:999px;padding:4px 8px;font-size:10px;font-weight:900;letter-spacing:.04em;text-transform:uppercase}
.b-neon{background:rgba(0,168,107,.16);color:#6ef6bf;border:1px solid rgba(0,168,107,.35)}
.b-red{background:rgba(229,57,53,.16);color:#ff9f9f;border:1px solid rgba(229,57,53,.35)}
.b-cyan{background:rgba(6,182,212,.16);color:#8defff;border:1px solid rgba(6,182,212,.35)}
.b-purple{background:rgba(124,58,237,.16);color:#cbb1ff;border:1px solid rgba(124,58,237,.35)}
.b-blue{background:rgba(37,99,235,.16);color:#9ec5ff;border:1px solid rgba(37,99,235,.35)}
.small{font-size:12px;color:var(--text2)}
.fail-grid{display:grid;gap:8px}
.fail-card{background:rgba(15,23,38,.82);border:1px solid rgba(229,57,53,.28);border-radius:11px;padding:9px 10px}
.fail-head{display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:6px}
.fail-title{font-weight:800;font-size:13px}
.fail-meta{font-size:11px;color:var(--muted)}
.url{display:inline-flex;margin-top:7px;text-decoration:none;color:var(--text);font-size:11px;font-weight:800;padding:6px 9px;border-radius:9px;background:rgba(6,182,212,.18);border:1px solid rgba(6,182,212,.35)}
@media (max-width:1050px){.grid4{grid-template-columns:repeat(2,minmax(0,1fr))}.split{grid-template-columns:1fr}}
@media (max-width:760px){.h1{font-size:22px}.grid4{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">
    <div class="top">
        <div>
            <div class="h1">Santé Notifications (24h)</div>
            <div class="meta">Fenêtre analysée depuis <?= h($since) ?></div>
        </div>
        <div class="actions">
            <a class="btn" href="<?= h(project_url('admin/app_alert_history.php')) ?>"><i class="fas fa-timeline"></i> Historique</a>
            <a class="btn" href="<?= h(project_url('dashboard/index.php')) ?>"><i class="fas fa-gauge-high"></i> Dashboard</a>
        </div>
    </div>

    <div class="grid4">
        <div class="kpi"><div class="v"><?= number_format($totalEvents, 0, ',', ' ') ?></div><div class="l">Événements 24h</div></div>
        <div class="kpi"><div class="v"><?= number_format($sentWebpush, 0, ',', ' ') ?></div><div class="l">WebPush envoyés</div></div>
        <div class="kpi"><div class="v"><?= number_format($sentFcm, 0, ',', ' ') ?></div><div class="l">FCM envoyés</div></div>
        <div class="kpi"><div class="v" style="color:#ffb4b1"><?= number_format($totalFailed, 0, ',', ' ') ?> <span style="font-size:12px;color:var(--text2)">(<?= number_format($failureRate, 2, ',', ' ') ?>%)</span></div><div class="l">Échecs totaux</div></div>
    </div>

    <div class="split">
        <div class="panel">
            <div class="p-title">Top Event Types (24h)</div>
            <div class="list">
                <?php if (!$topTypes): ?>
                    <div class="item small">Aucun événement sur 24h.</div>
                <?php endif; ?>
                <?php foreach ($topTypes as $t): ?>
                    <?php
                        $domain = alertDomain((string)$t['event_type']);
                        $cls = $domain === 'hr' ? 'b-blue' : ($domain === 'finance' ? 'b-purple' : ($domain === 'appro' ? 'b-cyan' : 'b-neon'));
                    ?>
                    <div class="item">
                        <div class="row">
                            <span class="badge <?= h($cls) ?>"><?= h((string)$t['event_type']) ?></span>
                            <strong><?= number_format((int)$t['total'], 0, ',', ' ') ?></strong>
                        </div>
                        <div class="small" style="margin-top:6px">Envois: <?= number_format((int)$t['sent_total'], 0, ',', ' ') ?> · Échecs: <?= number_format((int)$t['failed_total'], 0, ',', ' ') ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="panel">
            <div class="p-title">Répartition Domaine</div>
            <div class="list">
                <?php foreach (['hr' => 'RH', 'finance' => 'Finance', 'appro' => 'Appro', 'other' => 'Autres'] as $key => $label): ?>
                    <div class="item">
                        <div class="row">
                            <strong><?= h($label) ?></strong>
                            <strong><?= number_format((int)$domainSummary[$key], 0, ',', ' ') ?></strong>
                        </div>
                        <div class="small" style="margin-top:6px">Échecs: <?= number_format((int)$domainFail[$key], 0, ',', ' ') ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="p-title">Derniers Échecs (24h)</div>
        <div class="fail-grid">
            <?php if (!$failedRows): ?>
                <div class="item small">Aucun échec WebPush/FCM enregistré sur la période.</div>
            <?php endif; ?>
            <?php foreach ($failedRows as $row): ?>
                <div class="fail-card">
                    <div class="fail-head">
                        <div>
                            <div class="fail-title">#<?= (int)$row['id'] ?> · <?= h((string)$row['event_type']) ?></div>
                            <div class="fail-meta"><?= h((string)$row['created_at']) ?> · rôles: <?= h((string)$row['target_roles']) ?></div>
                        </div>
                        <span class="badge b-red">WP <?= (int)$row['failed_webpush'] ?> · FCM <?= (int)$row['failed_fcm'] ?></span>
                    </div>
                    <div style="font-weight:800;font-size:13px;margin-bottom:4px"><?= h((string)$row['title']) ?></div>
                    <div class="small"><?= h((string)$row['body']) ?></div>
                    <?php if (!empty($row['target_url'])): ?>
                        <a class="url" href="<?= h((string)$row['target_url']) ?>">Ouvrir la cible</a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
</body>
</html>
