<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

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
Middleware::role(['developer','admin','manager','user']);

$pdo = DB::getConnection();
$current_user_id = $_SESSION['user_id'] ?? 0;
$user_role = $_SESSION['role'] ?? 'user';

/* FILTRES */
$company_id = $_GET['company_id'] ?? '';
$category = $_GET['category'] ?? '';
$confidentiality = $_GET['confidentiality'] ?? '';
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 12;

/* SOCIÉTÉS */
$companies = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

/* CATÉGORIES */
$categories = $pdo->query("SELECT DISTINCT category FROM documents WHERE deleted_at IS NULL ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);

/* STATS */
$statsQuery = "
    SELECT 
        COUNT(*) as total,
        COALESCE(SUM(CASE WHEN confidentiality_level='public' THEN 1 ELSE 0 END), 0) as public_count,
        COALESCE(SUM(CASE WHEN confidentiality_level='confidential' THEN 1 ELSE 0 END), 0) as confidential_count,
        COALESCE(SUM(CASE WHEN confidentiality_level='restricted' THEN 1 ELSE 0 END), 0) as restricted_count,
        COALESCE(SUM(file_size), 0) as total_size,
        COALESCE(SUM(CASE WHEN expires_at IS NOT NULL AND expires_at <= DATE_ADD(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END), 0) as expiring
    FROM documents WHERE deleted_at IS NULL
";
$statsResult = $pdo->query($statsQuery)->fetch(PDO::FETCH_ASSOC);
$stats = [
    'total' => (int)($statsResult['total'] ?? 0),
    'public' => (int)($statsResult['public_count'] ?? 0),
    'confidential' => (int)($statsResult['confidential_count'] ?? 0),
    'restricted' => (int)($statsResult['restricted_count'] ?? 0),
    'size' => (int)($statsResult['total_size'] ?? 0),
    'expiring' => (int)($statsResult['expiring'] ?? 0)
];

/* DOCUMENTS */
$sqlWhere = "WHERE d.deleted_at IS NULL";
$params = [];

if ($company_id) { $sqlWhere .= " AND d.company_id = ?"; $params[] = $company_id; }
if ($category) { $sqlWhere .= " AND d.category = ?"; $params[] = $category; }
if ($confidentiality) { $sqlWhere .= " AND d.confidentiality_level = ?"; $params[] = $confidentiality; }
if ($search) {
    $sqlWhere .= " AND (d.title LIKE ? OR d.description LIKE ? OR d.reference_code LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm; $params[] = $searchTerm; $params[] = $searchTerm;
}

if (!in_array($user_role, ['developer', 'admin'])) {
    $sqlWhere .= " AND (d.uploaded_by = ? OR EXISTS (SELECT 1 FROM document_permissions dp WHERE dp.document_id = d.id AND dp.user_id = ? AND dp.can_view = 1))";
    $params[] = $current_user_id; $params[] = $current_user_id;
}

$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM documents d $sqlWhere");
$totalStmt->execute($params);
$totalDocs = $totalStmt->fetchColumn();
$offset = ($page - 1) * $perPage;
$totalPages = ceil($totalDocs / $perPage);

$sql = "SELECT d.*, c.name as company_name, u.username as uploaded_by_name
    FROM documents d
    JOIN companies c ON c.id = d.company_id
    JOIN users u ON u.id = d.uploaded_by
    $sqlWhere ORDER BY d.created_at DESC LIMIT $offset, $perPage";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

function formatFileSize($bytes) {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

function getFileIcon($mime_type) {
    if (strpos($mime_type,'pdf')!==false) return 'fa-file-pdf';
    if (strpos($mime_type,'word')!==false) return 'fa-file-word';
    if (strpos($mime_type,'excel')!==false||strpos($mime_type,'sheet')!==false) return 'fa-file-excel';
    if (strpos($mime_type,'image')!==false) return 'fa-file-image';
    if (strpos($mime_type,'zip')!==false||strpos($mime_type,'archive')!==false) return 'fa-file-archive';
    return 'fa-file-alt';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Documents Cryptés | ESPERANCE H2O</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=Source+Serif+4:wght@700;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
@font-face{font-family:'C059';src:local('C059-Bold'),local('C059 Bold'),local('Century Schoolbook');font-weight:700 900;font-style:normal}
:root{--bg:#04090e;--surf:#081420;--card:#0d1e2c;--card2:#122030;--bord:rgba(50,190,143,0.16);--neon:#32be8f;--neon2:#19ffa3;--red:#ff3553;--orange:#ff9140;--blue:#3d8cff;--gold:#ffd060;--purple:#a855f7;--cyan:#06b6d4;--text:#e0f2ea;--text2:#b8d8cc;--muted:#5a8070;--glow:0 0 26px rgba(50,190,143,0.45);--glow-r:0 0 26px rgba(255,53,83,0.45);--glow-gold:0 0 26px rgba(255,208,96,0.4);--fh:'C059','Source Serif 4','Playfair Display','Book Antiqua',Georgia,serif;--fb:'Inter','Segoe UI',system-ui,sans-serif}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
html{scroll-behavior:smooth}
body{font-family:var(--fb);font-size:15px;line-height:1.7;background:var(--bg);color:var(--text);min-height:100vh;overflow-x:hidden}
body::before{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;background:radial-gradient(ellipse 65% 42% at 4% 8%,rgba(50,190,143,0.08) 0%,transparent 62%),radial-gradient(ellipse 52% 36% at 96% 88%,rgba(61,140,255,0.07) 0%,transparent 62%)}
body::after{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;background-image:linear-gradient(rgba(50,190,143,0.022) 1px,transparent 1px),linear-gradient(90deg,rgba(50,190,143,0.022) 1px,transparent 1px);background-size:46px 46px}
.wrap{position:relative;z-index:1;max-width:1580px;margin:0 auto;padding:16px 16px 48px}
.topbar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;background:rgba(8,20,32,0.94);border:1px solid var(--bord);border-radius:18px;padding:18px 28px;margin-bottom:16px;backdrop-filter:blur(24px)}
.brand{display:flex;align-items:center;gap:16px;flex-shrink:0}
.brand-ico{width:50px;height:50px;background:linear-gradient(135deg,var(--neon),var(--cyan));border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:24px;color:#fff;box-shadow:0 0 26px rgba(50,190,143,0.5);animation:breathe 3.2s ease-in-out infinite;flex-shrink:0}
@keyframes breathe{0%,100%{box-shadow:0 0 14px rgba(50,190,143,0.4)}50%{box-shadow:0 0 38px rgba(50,190,143,0.85)}}
.brand-txt h1{font-family:var(--fh);font-size:22px;font-weight:900;color:var(--text);letter-spacing:0.5px;line-height:1.2}
.brand-txt p{font-size:11px;font-weight:700;color:var(--neon);letter-spacing:2.8px;text-transform:uppercase;margin-top:3px}
.nav-bar{display:flex;align-items:center;flex-wrap:wrap;gap:8px;background:rgba(8,20,32,0.90);border:1px solid var(--bord);border-radius:16px;padding:14px 22px;margin-bottom:18px;backdrop-filter:blur(20px)}
.nb{display:flex;align-items:center;gap:8px;padding:10px 18px;border-radius:12px;border:1.5px solid var(--bord);background:rgba(50,190,143,0.07);color:var(--text2);font-family:var(--fh);font-size:13px;font-weight:900;text-decoration:none;white-space:nowrap;letter-spacing:0.4px;transition:all 0.28s cubic-bezier(0.23,1,0.32,1)}
.nb:hover{background:var(--neon);color:var(--bg);border-color:var(--neon);box-shadow:var(--glow);transform:translateY(-2px)}
.nb.active{background:var(--neon);color:var(--bg);border-color:var(--neon);box-shadow:var(--glow)}
.kpi-strip{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin-bottom:20px}
.ks{background:var(--card);border:1px solid var(--bord);border-radius:16px;padding:20px 18px;display:flex;align-items:center;gap:14px;transition:all 0.3s;animation:fadeUp .5s ease}
.ks:hover{transform:translateY(-4px);box-shadow:0 14px 32px rgba(0,0,0,0.38)}
.ks-ico{width:52px;height:52px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:26px;flex-shrink:0}
.ks-val{font-family:var(--fh);font-size:28px;font-weight:900;color:var(--text);line-height:1}
.ks-lbl{font-family:var(--fb);font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-top:6px}
.panel{background:var(--card);border:1px solid var(--bord);border-radius:18px;overflow:hidden;margin-bottom:18px;transition:border-color 0.3s;animation:fadeUp .5s ease}
.panel:hover{border-color:rgba(50,190,143,0.26)}
.ph{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:18px 22px;border-bottom:1px solid rgba(255,255,255,0.05);background:rgba(0,0,0,0.18)}
.ph-title{font-family:var(--fh);font-size:18px;font-weight:900;color:var(--text);display:flex;align-items:center;gap:10px;letter-spacing:0.4px;flex-wrap:wrap}
.dot{width:9px;height:9px;border-radius:50%;background:var(--neon);box-shadow:0 0 9px var(--neon);flex-shrink:0;animation:pdot 2.2s infinite}
@keyframes pdot{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(.75)}}
.pb{padding:22px 26px}
.form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:18px}
.f-select,.f-input{width:100%;padding:12px 16px;background:rgba(0,0,0,0.3);border:1.5px solid var(--bord);border-radius:12px;color:var(--text);font-family:var(--fb);font-size:14px;font-weight:600;transition:all 0.3s}
.f-select:focus,.f-input:focus{outline:none;border-color:var(--neon);box-shadow:var(--glow);background:rgba(50,190,143,0.05)}
.f-select option{background:#0d1e2c;color:var(--text)}
.btn{display:inline-flex;align-items:center;gap:8px;padding:12px 22px;border-radius:12px;border:none;cursor:pointer;font-family:var(--fh);font-size:13px;font-weight:900;letter-spacing:0.4px;transition:all 0.28s;text-decoration:none;white-space:nowrap}
.btn-neon{background:rgba(50,190,143,0.12);border:1.5px solid rgba(50,190,143,0.3);color:var(--neon)}
.btn-neon:hover{background:var(--neon);color:var(--bg);box-shadow:var(--glow);transform:translateY(-2px)}
.docs-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:18px;margin-top:20px}
.doc-card{background:var(--card2);border:1px solid var(--bord);border-radius:16px;padding:20px;transition:all 0.3s;position:relative;overflow:hidden}
.doc-card::before{content:'';position:absolute;top:0;left:0;width:4px;height:100%;background:linear-gradient(135deg,var(--neon),var(--cyan));transform:scaleY(0);transition:transform 0.3s}
.doc-card:hover::before{transform:scaleY(1)}
.doc-card:hover{transform:translateY(-5px);border-color:rgba(50,190,143,0.4);box-shadow:0 14px 32px rgba(0,0,0,0.4)}
.doc-head{display:flex;align-items:flex-start;gap:14px;margin-bottom:14px}
.doc-icon{font-size:48px;flex-shrink:0;background:linear-gradient(135deg,var(--neon),var(--cyan));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.doc-info{flex:1;min-width:0}
.doc-title{font-family:var(--fh);font-size:16px;font-weight:900;color:var(--text);margin-bottom:6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.doc-ref{font-family:monospace;font-size:11px;color:var(--cyan);background:rgba(6,182,212,0.1);padding:4px 10px;border-radius:12px;display:inline-block}
.conf-badge{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:20px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:0.5px;color:#fff;margin-bottom:12px}
.enc-badge{position:absolute;top:12px;right:12px;background:rgba(255,208,96,0.2);border:1px solid rgba(255,208,96,0.4);color:var(--gold);padding:5px 10px;border-radius:8px;font-size:10px;font-weight:800;display:flex;align-items:center;gap:5px}
.doc-meta{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:12px}
.meta-i{display:flex;align-items:center;gap:5px;font-size:12px;color:var(--muted)}
.meta-i i{color:var(--neon);font-size:11px}
.doc-foot{display:flex;justify-content:space-between;align-items:center;padding-top:12px;border-top:1px solid rgba(255,255,255,0.05)}
.doc-actions{display:flex;gap:8px}
.act-btn{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;border:none;cursor:pointer;transition:all 0.3s;text-decoration:none;font-size:14px}
.act-view{background:rgba(61,140,255,0.12);border:1px solid rgba(61,140,255,0.3);color:var(--blue)}
.act-view:hover{background:var(--blue);color:#fff;transform:translateY(-2px)}
.act-dl{background:rgba(50,190,143,0.12);border:1px solid rgba(50,190,143,0.3);color:var(--neon)}
.act-dl:hover{background:var(--neon);color:var(--bg);transform:translateY(-2px)}
.act-del{background:rgba(255,53,83,0.12);border:1px solid rgba(255,53,83,0.3);color:var(--red)}
.act-del:hover{background:var(--red);color:#fff;transform:translateY(-2px)}
.doc-date{font-size:11px;color:var(--muted)}
.pagination{display:flex;justify-content:center;gap:10px;margin-top:24px;flex-wrap:wrap}
.pg-link{padding:10px 18px;border-radius:12px;background:rgba(50,190,143,0.07);border:1.5px solid var(--bord);color:var(--text2);text-decoration:none;font-family:var(--fh);font-size:13px;font-weight:900;transition:all 0.3s}
.pg-link:hover{background:var(--neon);color:var(--bg);border-color:var(--neon);transform:translateY(-2px)}
.pg-link.active{background:var(--neon);color:var(--bg);border-color:var(--neon)}
.empty-st{text-align:center;padding:48px 20px;color:var(--muted)}
.empty-st i{font-size:64px;display:block;margin-bottom:18px;opacity:.15}
.empty-st h3{font-family:var(--fh);font-size:22px;font-weight:900;color:var(--text2);margin-bottom:10px}
.empty-st p{font-size:14px;opacity:.6;margin-bottom:24px}
@keyframes fadeUp{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:translateY(0)}}
@media(max-width:768px){.wrap{padding:12px}.kpi-strip{grid-template-columns:repeat(2,1fr)}.docs-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">

<!-- TOPBAR -->
<div class="topbar">
    <div class="brand">
        <div class="brand-ico"><i class="fas fa-shield-alt"></i></div>
        <div class="brand-txt">
            <h1>Documents Cryptés</h1>
            <p>ESPERANCE H2O &nbsp;·&nbsp; AES-256-GCM</p>
        </div>
    </div>
</div>

<!-- NAV -->
<div class="nav-bar">
    <a href="<?= project_url('dashboard/index.php') ?>" class="nb"><i class="fas fa-home"></i> Accueil</a>
    <a href="<?= project_url('finance/caisse_complete_enhanced.php') ?>" class="nb"><i class="fas fa-cash-register"></i> Caisse</a>
    <a href="<?= project_url('documents/documents_erp_pro.php') ?>" class="nb active"><i class="fas fa-folder-open"></i> Documents</a>
    <a href="<?= project_url('auth/profile.php') ?>" class="nb"><i class="fas fa-user-circle"></i> Profil</a>
</div>

<!-- KPI -->
<div class="kpi-strip">
    <div class="ks">
        <div class="ks-ico" style="background:rgba(50,190,143,0.14);color:var(--neon)"><i class="fas fa-file-alt"></i></div>
        <div><div class="ks-val" style="color:var(--neon)"><?= $stats['total'] ?></div><div class="ks-lbl">Total Docs</div></div>
    </div>
    <div class="ks">
        <div class="ks-ico" style="background:rgba(61,140,255,0.14);color:var(--blue)"><i class="fas fa-globe"></i></div>
        <div><div class="ks-val" style="color:var(--blue)"><?= $stats['public'] ?></div><div class="ks-lbl">Publics</div></div>
    </div>
    <div class="ks">
        <div class="ks-ico" style="background:rgba(255,208,96,0.14);color:var(--gold)"><i class="fas fa-lock"></i></div>
        <div><div class="ks-val" style="color:var(--gold)"><?= $stats['confidential'] ?></div><div class="ks-lbl">Confidentiels</div></div>
    </div>
    <div class="ks">
        <div class="ks-ico" style="background:rgba(255,53,83,0.14);color:var(--red)"><i class="fas fa-shield-alt"></i></div>
        <div><div class="ks-val" style="color:var(--red)"><?= $stats['restricted'] ?></div><div class="ks-lbl">Restreints</div></div>
    </div>
    <div class="ks">
        <div class="ks-ico" style="background:rgba(168,85,247,0.14);color:var(--purple)"><i class="fas fa-hdd"></i></div>
        <div><div class="ks-val" style="color:var(--purple)"><?= formatFileSize($stats['size']) ?></div><div class="ks-lbl">Stockés</div></div>
    </div>
    <div class="ks">
        <div class="ks-ico" style="background:rgba(255,145,64,0.14);color:var(--orange)"><i class="fas fa-clock"></i></div>
        <div><div class="ks-val" style="color:var(--orange)"><?= $stats['expiring'] ?></div><div class="ks-lbl">Expirent</div></div>
    </div>
</div>

<!-- FILTRES -->
<div class="panel">
    <div class="ph">
        <div class="ph-title"><div class="dot"></div> Filtres & Recherche</div>
        <a href="document_upload.php" class="btn btn-neon"><i class="fas fa-cloud-upload-alt"></i> Upload</a>
    </div>
    <div class="pb">
        <form method="get">
            <div class="form-grid">
                <select name="company_id" class="f-select" onchange="this.form.submit()">
                    <option value="">🏢 Toutes sociétés</option>
                    <?php foreach($companies as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $company_id==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="category" class="f-select" onchange="this.form.submit()">
                    <option value="">📁 Toutes catégories</option>
                    <?php foreach($categories as $cat): ?>
                    <option value="<?= htmlspecialchars($cat) ?>" <?= $category==$cat?'selected':'' ?>><?= htmlspecialchars($cat) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="confidentiality" class="f-select" onchange="this.form.submit()">
                    <option value="">🔒 Tous niveaux</option>
                    <option value="public" <?= $confidentiality=='public'?'selected':'' ?>>Public</option>
                    <option value="internal" <?= $confidentiality=='internal'?'selected':'' ?>>Interne</option>
                    <option value="confidential" <?= $confidentiality=='confidential'?'selected':'' ?>>Confidentiel</option>
                    <option value="restricted" <?= $confidentiality=='restricted'?'selected':'' ?>>Restreint</option>
                </select>
                <input type="search" name="search" class="f-input" placeholder="🔍 Rechercher..." value="<?= htmlspecialchars($search) ?>">
                <button type="submit" class="btn btn-neon"><i class="fas fa-search"></i> Filtrer</button>
            </div>
        </form>
    </div>
</div>

<!-- DOCUMENTS -->
<?php if(count($documents) > 0): ?>
<div class="docs-grid">
    <?php foreach($documents as $d):
        $confColors = ['public'=>'#10b981','internal'=>'#3b82f6','confidential'=>'#f59e0b','restricted'=>'#ef4444'];
        $confCol = $confColors[$d['confidentiality_level']] ?? '#64748b';
    ?>
    <div class="doc-card">
        <?php if($d['encryption_status']): ?>
        <div class="enc-badge"><i class="fas fa-key"></i> Chiffré</div>
        <?php endif; ?>
        
        <div class="doc-head">
            <div class="doc-icon"><i class="fas <?= getFileIcon($d['mime_type']) ?>"></i></div>
            <div class="doc-info">
                <div class="doc-title" title="<?= htmlspecialchars($d['title']) ?>"><?= htmlspecialchars($d['title']) ?></div>
                <div class="doc-ref"><?= htmlspecialchars($d['reference_code']) ?></div>
            </div>
        </div>
        
        <div class="conf-badge" style="background:<?= $confCol ?>">
            <i class="fas fa-<?= $d['confidentiality_level']==='public'?'globe':($d['confidentiality_level']==='internal'?'building':($d['confidentiality_level']==='confidential'?'lock':'shield-alt')) ?>"></i>
            <?= ucfirst($d['confidentiality_level']) ?>
        </div>
        
        <div class="doc-meta">
            <div class="meta-i"><i class="fas fa-building"></i> <?= htmlspecialchars($d['company_name']) ?></div>
            <div class="meta-i"><i class="fas fa-folder"></i> <?= htmlspecialchars($d['category'] ?: 'N/A') ?></div>
            <div class="meta-i"><i class="fas fa-hdd"></i> <?= formatFileSize($d['file_size']) ?></div>
            <div class="meta-i"><i class="fas fa-code-branch"></i> v<?= $d['version'] ?></div>
        </div>
        
        <div class="doc-foot">
            <div class="doc-actions">
                <a href="document_view.php?id=<?= $d['id'] ?>" class="act-btn act-view" title="Voir"><i class="fas fa-eye"></i></a>
                <a href="document_download.php?id=<?= $d['id'] ?>" class="act-btn act-dl" title="Télécharger"><i class="fas fa-download"></i></a>
                <?php if(in_array($user_role,['developer','admin'])||$d['uploaded_by']==$current_user_id): ?>
                <a href="document_delete.php?id=<?= $d['id'] ?>" class="act-btn act-del" onclick="return confirm('Supprimer?')" title="Supprimer"><i class="fas fa-trash"></i></a>
                <?php endif; ?>
            </div>
            <div class="doc-date"><i class="far fa-calendar"></i> <?= date('d/m/Y',strtotime($d['created_at'])) ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php if($totalPages > 1): ?>
<div class="pagination">
    <?php for($p=1; $p<=$totalPages; $p++): ?>
    <a href="?company_id=<?= $company_id ?>&category=<?= urlencode($category) ?>&confidentiality=<?= $confidentiality ?>&search=<?= urlencode($search) ?>&page=<?= $p ?>" 
       class="pg-link <?= $p==$page?'active':'' ?>"><?= $p ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<?php else: ?>
<div class="panel">
    <div class="pb">
        <div class="empty-st">
            <i class="fas fa-folder-open"></i>
            <h3>Aucun document trouvé</h3>
            <p>Commencez par téléverser vos premiers documents sécurisés</p>
            <a href="document_upload.php" class="btn btn-neon"><i class="fas fa-cloud-upload-alt"></i> Upload Document</a>
        </div>
    </div>
</div>
<?php endif; ?>

</div>
</body>
</html>
