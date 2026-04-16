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

function alertDomain(string $eventType, string $targetUrl = '', string $payloadJson = ''): string
{
    $t = strtolower(trim($eventType));
    $u = strtolower(trim($targetUrl));
    $p = strtolower(trim($payloadJson));

    if (str_contains($u, '/finance/') || str_contains($u, 'view=tickets') || str_contains($p, '/finance/') || str_contains($p, 'view=tickets')) {
        return 'finance';
    }
    if (str_contains($u, 'view=appro') || str_contains($u, '/stock/appro') || str_contains($p, 'view=appro') || str_contains($p, '/stock/appro')) {
        return 'appro';
    }
    if (str_contains($u, '/hr/') || str_contains($p, '/hr/')) {
        return 'hr';
    }

    if (strpos($t, 'appro_') === 0) {
        return 'appro';
    }
    if (strpos($t, 'hr_') === 0 || strpos($t, 'attendance_') === 0) {
        return 'hr';
    }
    if (strpos($t, 'invoice_') === 0 || strpos($t, 'payment_') === 0 || strpos($t, 'finance_') === 0 || $t === 'low_stock') {
        return 'finance';
    }
    return 'other';
}

function eventBadgeClass(string $eventType, string $targetUrl = '', string $payloadJson = ''): string
{
    $t = strtolower(trim($eventType));
    if (strpos($t, 'failed') !== false || strpos($t, 'rejected') !== false || strpos($t, 'cancel') !== false) {
        return 'b-red';
    }
    if (strpos($t, 'approved') !== false || strpos($t, 'created') !== false || strpos($t, 'paid') !== false || strpos($t, 'confirmed') !== false) {
        return 'b-neon';
    }
    if (strpos($t, 'request') !== false || strpos($t, 'pending') !== false) {
        return 'b-gold';
    }

    return match (alertDomain($t, $targetUrl, $payloadJson)) {
        'appro' => 'b-cyan',
        'hr' => 'b-blue',
        'finance' => 'b-purple',
        default => 'b-muted',
    };
}

function domainSql(string $domain): string
{
    if ($domain === 'hr') {
        return "(event_type LIKE 'hr_%' OR event_type LIKE 'attendance_%' OR target_url LIKE '%/hr/%' OR payload_json LIKE '%/hr/%')";
    }
    if ($domain === 'finance') {
        return "(event_type LIKE 'finance_%' OR event_type LIKE 'invoice_%' OR event_type LIKE 'payment_%' OR event_type='low_stock' OR target_url LIKE '%/finance/%' OR target_url LIKE '%view=tickets%' OR payload_json LIKE '%/finance/%' OR payload_json LIKE '%view=tickets%')";
    }
    if ($domain === 'appro') {
        return "(event_type LIKE 'appro_%' OR target_url LIKE '%view=appro%' OR target_url LIKE '%/stock/appro%' OR payload_json LIKE '%view=appro%' OR payload_json LIKE '%/stock/appro%')";
    }
    return '1=1';
}

$eventType = trim((string)($_GET['event_type'] ?? ''));
$roleFilter = trim((string)($_GET['role'] ?? ''));
$domain = trim((string)($_GET['domain'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

$allowedDomains = ['', 'hr', 'finance', 'appro'];
if (!in_array($domain, $allowedDomains, true)) {
    $domain = '';
}

$where = ['1=1'];
$params = [];

$where[] = domainSql($domain);

if ($eventType !== '') {
    $where[] = 'event_type = ?';
    $params[] = $eventType;
}
if ($roleFilter !== '') {
    $where[] = 'target_roles LIKE ?';
    $params[] = '%' . $roleFilter . '%';
}

$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM app_alert_logs WHERE $whereSql");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$statStmt = $pdo->prepare("SELECT COUNT(*) total, COALESCE(SUM(sent_webpush),0) sw, COALESCE(SUM(sent_fcm),0) sf, COALESCE(SUM(failed_webpush),0) fw, COALESCE(SUM(failed_fcm),0) ff FROM app_alert_logs WHERE $whereSql");
$statStmt->execute($params);
$stats = $statStmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'sw' => 0, 'sf' => 0, 'fw' => 0, 'ff' => 0];

$stmt = $pdo->prepare("\n    SELECT *\n    FROM app_alert_logs\n    WHERE $whereSql\n    ORDER BY id DESC\n    LIMIT $perPage OFFSET $offset\n");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$typeWhere = ['1=1'];
$typeParams = [];
$typeWhere[] = domainSql($domain);
$typeSql = implode(' AND ', $typeWhere);
$typesStmt = $pdo->prepare("SELECT DISTINCT event_type FROM app_alert_logs WHERE $typeSql ORDER BY event_type");
$typesStmt->execute($typeParams);
$types = $typesStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

$pages = max(1, (int)ceil($total / $perPage));

$baseQuery = [
    'event_type' => $eventType,
    'role' => $roleFilter,
    'domain' => $domain,
];

$domainCards = [];
foreach (['hr', 'finance', 'appro'] as $d) {
    $dWhere = ['1=1', domainSql($d)];
    $dParams = [];
    if ($eventType !== '') {
        $dWhere[] = 'event_type = ?';
        $dParams[] = $eventType;
    }
    if ($roleFilter !== '') {
        $dWhere[] = 'target_roles LIKE ?';
        $dParams[] = '%' . $roleFilter . '%';
    }
    $dSql = implode(' AND ', $dWhere);
    $dStmt = $pdo->prepare("SELECT COUNT(*) FROM app_alert_logs WHERE $dSql");
    $dStmt->execute($dParams);
    $domainCards[$d] = (int)$dStmt->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>Historique Notifications App</title>
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
    --glow:0 8px 24px rgba(0,168,107,.18);
}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--fb);background:var(--bg);color:var(--text);min-height:100vh;line-height:1.45}
body::before{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;background:radial-gradient(ellipse 65% 42% at 5% 8%,rgba(0,168,107,.05) 0%,transparent 62%),radial-gradient(ellipse 52% 36% at 96% 88%,rgba(37,99,235,.04) 0%,transparent 62%),radial-gradient(ellipse 38% 28% at 52% 52%,rgba(245,158,11,.04) 0%,transparent 70%)}
.wrap{position:relative;z-index:1;max-width:1500px;margin:0 auto;padding:16px 14px 44px}
.top{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;margin-bottom:14px}
.h1{font-family:var(--fh);font-size:26px;font-weight:900;letter-spacing:.3px}
.meta{color:var(--muted);font-size:13px;margin-top:3px}
.actions{display:flex;gap:8px;flex-wrap:wrap}
.btn{display:inline-flex;gap:8px;align-items:center;text-decoration:none;border:1px solid var(--bord);border-radius:12px;background:linear-gradient(135deg,rgba(6,182,212,.18),rgba(37,99,235,.16));color:var(--text);padding:10px 13px;font-weight:800;font-size:12px}
.btn:hover{box-shadow:var(--glow)}
.kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:12px}
.kpi{background:linear-gradient(145deg,rgba(27,38,59,.96),rgba(22,32,51,.96));border:1px solid var(--bord);border-radius:13px;padding:10px 11px}
.kpi .v{font-family:var(--fh);font-size:20px;font-weight:900}
.kpi .l{font-size:11px;color:var(--text2);font-weight:700}
.domains{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px;margin-bottom:12px}
.domain{display:block;text-decoration:none;background:linear-gradient(140deg,rgba(15,23,38,.94),rgba(22,32,51,.95));border:1px solid var(--bord);border-radius:13px;padding:9px 10px;color:var(--text)}
.domain.active{border-color:rgba(0,168,107,.5);box-shadow:0 0 0 1px rgba(0,168,107,.3) inset}
.domain .n{font-family:var(--fh);font-size:18px;font-weight:900}
.domain .t{font-size:11px;color:var(--text2);font-weight:800;text-transform:uppercase;letter-spacing:.05em}
.panel{background:linear-gradient(145deg,rgba(27,38,59,.96),rgba(22,32,51,.95));border:1px solid var(--bord);border-radius:14px;padding:12px;margin-bottom:12px}
.filters{display:grid;grid-template-columns:1.2fr 1fr auto auto;gap:8px}
input,select{width:100%;background:rgba(15,23,38,.82);border:1px solid var(--bord);border-radius:11px;padding:10px 11px;color:var(--text);font-size:13px}
.filter-btn{background:linear-gradient(135deg,rgba(0,168,107,.2),rgba(6,182,212,.2));border:1px solid rgba(0,168,107,.4);color:var(--text);font-weight:800;border-radius:11px;padding:10px 13px;cursor:pointer}
.table-wrap{overflow:auto}
table{width:100%;border-collapse:separate;border-spacing:0 8px;min-width:1000px}
th{font-size:11px;text-transform:uppercase;color:var(--muted);font-weight:800;padding:0 10px 4px;text-align:left}
td{background:rgba(15,23,38,.78);border-top:1px solid var(--bord);border-bottom:1px solid var(--bord);padding:9px 10px;font-size:12px;vertical-align:top}
tr td:first-child{border-left:1px solid var(--bord);border-radius:10px 0 0 10px}
tr td:last-child{border-right:1px solid var(--bord);border-radius:0 10px 10px 0}
.badge{display:inline-flex;align-items:center;border-radius:999px;padding:4px 8px;font-size:10px;font-weight:900;letter-spacing:.04em;text-transform:uppercase}
.b-neon{background:rgba(0,168,107,.16);color:#6ef6bf;border:1px solid rgba(0,168,107,.35)}
.b-red{background:rgba(229,57,53,.16);color:#ff9f9f;border:1px solid rgba(229,57,53,.35)}
.b-gold{background:rgba(245,158,11,.16);color:#ffd983;border:1px solid rgba(245,158,11,.35)}
.b-cyan{background:rgba(6,182,212,.16);color:#8defff;border:1px solid rgba(6,182,212,.35)}
.b-blue{background:rgba(37,99,235,.16);color:#9ec5ff;border:1px solid rgba(37,99,235,.35)}
.b-purple{background:rgba(124,58,237,.16);color:#cbb1ff;border:1px solid rgba(124,58,237,.35)}
.b-muted{background:rgba(148,163,184,.16);color:#d3dde9;border:1px solid rgba(148,163,184,.35)}
.small{font-size:11px;color:var(--text2)}
.roles-wrap{
    display:block;
    max-width:220px;
    white-space:normal;
    overflow-wrap:anywhere;
    word-break:break-word;
    line-height:1.35;
}
.url-btn{display:inline-flex;padding:6px 9px;border-radius:9px;font-size:11px;font-weight:800;text-decoration:none;color:var(--text);background:rgba(6,182,212,.18);border:1px solid rgba(6,182,212,.35)}
pre.json{
    white-space:pre-wrap;
    overflow-wrap:anywhere;
    word-break:break-word;
    max-width:360px;
    max-height:140px;
    overflow:auto;
    font-size:10px;
    color:#d3deef;
    background:rgba(10,15,26,.7);
    border:1px solid rgba(148,163,184,.2);
    border-radius:8px;
    padding:7px;
}
.pager{display:flex;justify-content:flex-end;gap:8px;align-items:center;margin-top:8px}
.pager .pinfo{font-size:12px;color:var(--muted);margin-right:6px}
@media (max-width:1050px){
    .kpis{grid-template-columns:repeat(2,minmax(0,1fr))}
    .filters{grid-template-columns:1fr 1fr;}
}
@media (max-width:820px){
    .domains{grid-template-columns:1fr}
    .h1{font-size:22px}
    .table-wrap{overflow:visible}
    table,thead,tbody,tr,td{display:block;min-width:0}
    thead{display:none}
    tbody{display:grid;gap:10px}
    tr{background:rgba(15,23,38,.86);border:1px solid var(--bord);border-radius:12px;padding:8px}
    tr td{border:none;background:transparent;padding:5px 2px;border-radius:0!important;display:grid;grid-template-columns:92px 1fr;gap:8px;align-items:flex-start}
    tr td::before{content:attr(data-label);font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;font-weight:800}
    .roles-wrap{max-width:100%}
    pre.json{max-width:100%}
}
</style>
</head>
<body>
<div class="wrap">
    <div class="top">
        <div>
            <div class="h1">Historique Notifications App</div>
            <div class="meta"><?= number_format($total, 0, ',', ' ') ?> événement(s) · vue compacte mobile-first</div>
        </div>
        <div class="actions">
            <a class="btn" href="<?= h(project_url('admin/app_alert_health.php')) ?>"><i class="fas fa-heart-pulse"></i> Santé Notifications</a>
            <a class="btn" href="<?= h(project_url('dashboard/index.php')) ?>"><i class="fas fa-gauge-high"></i> Dashboard</a>
        </div>
    </div>

    <div class="kpis">
        <div class="kpi"><div class="v"><?= number_format((int)$stats['total'], 0, ',', ' ') ?></div><div class="l">Logs filtrés</div></div>
        <div class="kpi"><div class="v"><?= number_format((int)$stats['sw'], 0, ',', ' ') ?></div><div class="l">WebPush envoyés</div></div>
        <div class="kpi"><div class="v"><?= number_format((int)$stats['sf'], 0, ',', ' ') ?></div><div class="l">FCM envoyés</div></div>
        <div class="kpi"><div class="v" style="color:#ffb4b1"><?= number_format((int)$stats['fw'] + (int)$stats['ff'], 0, ',', ' ') ?></div><div class="l">Échecs (WebPush+FCM)</div></div>
    </div>

    <div class="domains">
        <?php
            $dBase = ['event_type' => $eventType, 'role' => $roleFilter, 'page' => 1];
            $allUrl = '?' . http_build_query(array_merge($dBase, ['domain' => '']));
            $hrUrl = '?' . http_build_query(array_merge($dBase, ['domain' => 'hr']));
            $finUrl = '?' . http_build_query(array_merge($dBase, ['domain' => 'finance']));
            $appUrl = '?' . http_build_query(array_merge($dBase, ['domain' => 'appro']));
        ?>
        <a class="domain <?= $domain === 'hr' ? 'active' : '' ?>" href="<?= h($hrUrl) ?>"><div class="t">RH</div><div class="n"><?= number_format($domainCards['hr'], 0, ',', ' ') ?></div></a>
        <a class="domain <?= $domain === 'finance' ? 'active' : '' ?>" href="<?= h($finUrl) ?>"><div class="t">Finance</div><div class="n"><?= number_format($domainCards['finance'], 0, ',', ' ') ?></div></a>
        <a class="domain <?= $domain === 'appro' ? 'active' : '' ?>" href="<?= h($appUrl) ?>"><div class="t">Appro</div><div class="n"><?= number_format($domainCards['appro'], 0, ',', ' ') ?></div></a>
    </div>

    <div class="panel">
        <form class="filters" method="get">
            <select name="event_type">
                <option value="">Tous les event_type</option>
                <?php foreach ($types as $type): ?>
                    <option value="<?= h((string)$type) ?>" <?= $eventType === (string)$type ? 'selected' : '' ?>><?= h((string)$type) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="role" value="<?= h($roleFilter) ?>" placeholder="Rôle ciblé (admin, manager...)">
            <input type="hidden" name="domain" value="<?= h($domain) ?>">
            <button class="filter-btn" type="submit"><i class="fas fa-filter"></i> Filtrer</button>
            <a class="btn" href="<?= h($allUrl) ?>"><i class="fas fa-rotate-left"></i> Reset</a>
        </form>
    </div>

    <div class="panel table-wrap">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Titre</th>
                    <th>Message</th>
                    <th>Rôles</th>
                    <th>Envois</th>
                    <th>URL</th>
                    <th>Détails</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="9" style="text-align:center;color:var(--muted)">Aucune notification trouvée avec ces filtres.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <?php $evType = (string)($row['event_type'] ?? 'generic'); ?>
                    <tr>
                        <td data-label="ID">#<?= (int)$row['id'] ?></td>
                        <td data-label="Date" class="small"><?= h((string)$row['created_at']) ?></td>
                        <td data-label="Type"><span class="badge <?= h(eventBadgeClass($evType, (string)($row['target_url'] ?? ''), (string)($row['payload_json'] ?? ''))) ?>"><?= h($evType) ?></span></td>
                        <td data-label="Titre"><?= h((string)$row['title']) ?></td>
                        <td data-label="Message" class="small"><?= h((string)$row['body']) ?></td>
                        <td data-label="Rôles" class="small"><span class="roles-wrap"><?= h((string)$row['target_roles']) ?></span></td>
                        <td data-label="Envois" class="small">
                            WP <?= (int)$row['sent_webpush'] ?> / <?= (int)$row['failed_webpush'] ?><br>
                            FCM <?= (int)$row['sent_fcm'] ?> / <?= (int)$row['failed_fcm'] ?>
                        </td>
                        <td data-label="URL">
                            <?php if (!empty($row['target_url'])): ?>
                                <a class="url-btn" href="<?= h((string)$row['target_url']) ?>">Ouvrir</a>
                            <?php else: ?>
                                <span class="small">—</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Détails">
                            <details>
                                <summary class="small" style="cursor:pointer">Payload JSON</summary>
                                <pre class="json"><?= h((string)$row['payload_json']) ?></pre>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="pager">
            <span class="pinfo">Page <?= $page ?> / <?= $pages ?></span>
            <?php if ($page > 1): ?>
                <a class="btn" href="?<?= h(http_build_query(array_merge($baseQuery, ['page' => $page - 1]))) ?>">Précédent</a>
            <?php endif; ?>
            <?php if ($page < $pages): ?>
                <a class="btn" href="?<?= h(http_build_query(array_merge($baseQuery, ['page' => $page + 1]))) ?>">Suivant</a>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
