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
Middleware::role(['developer', 'admin', 'manager', 'user']);

$pdo = DB::getConnection();
$user_id = $_SESSION['user_id'] ?? 0;
$user_name = $_SESSION['username'] ?? '';
$user_role = $_SESSION['role'] ?? 'user';

$doc_id = (int)($_GET['id'] ?? 0);

if ($doc_id <= 0) {
    header("Location: documents_erp_pro.php");
    exit;
}

// Récupération du document
$stmt = $pdo->prepare("
    SELECT d.*, c.name as company_name, u.username as uploaded_by_name
    FROM documents d
    JOIN companies c ON c.id = d.company_id
    JOIN users u ON u.id = d.uploaded_by
    WHERE d.id = ? AND d.deleted_at IS NULL
");
$stmt->execute([$doc_id]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doc) {
    header("Location: documents_erp_pro.php");
    exit;
}

// Vérification des permissions
$has_access = false;
if (in_array($user_role, ['developer', 'admin'])) {
    $has_access = true;
} elseif ($doc['uploaded_by'] == $user_id) {
    $has_access = true;
} else {
    $stmt = $pdo->prepare("SELECT can_view FROM document_permissions WHERE document_id = ? AND user_id = ?");
    $stmt->execute([$doc_id, $user_id]);
    $perm = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($perm && $perm['can_view']) {
        $has_access = true;
    }
}

if (!$has_access) {
    die("❌ Accès refusé");
}

// Log de consultation
$stmt = $pdo->prepare("
    INSERT INTO document_logs (document_id, user_id, action, ip_address, user_agent, created_at)
    VALUES (?, ?, 'VIEW', ?, ?, NOW())
");
$stmt->execute([
    $doc_id,
    $user_id,
    $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
    $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
]);

// Incrémenter le compteur de vues
$pdo->prepare("UPDATE documents SET view_count = view_count + 1, last_accessed_at = NOW() WHERE id = ?")->execute([$doc_id]);

// Récupération des logs
$stmt = $pdo->prepare("
    SELECT dl.*, u.username
    FROM document_logs dl
    LEFT JOIN users u ON u.id = dl.user_id
    WHERE dl.document_id = ?
    ORDER BY dl.created_at DESC
    LIMIT 50
");
$stmt->execute([$doc_id]);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fonctions utilitaires
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

function getConfidentialityColor($level) {
    return match($level) {
        'public' => '#10b981',
        'internal' => '#3b82f6',
        'confidential' => '#f59e0b',
        'restricted' => '#ef4444',
        default => '#64748b'
    };
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($doc['title']) ?> | ESPERANCE H2O</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=Source+Serif+4:wght@700;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
@font-face {
    font-family:'C059';
    src: local('C059-Bold'),local('C059 Bold'),local('Century Schoolbook');
    font-weight:700 900; font-style:normal;
}
:root {
    --bg:#0f1726; --surf:#162033; --card:#1b263b; --card2:#22324a;
    --bord:rgba(148,163,184,0.18);
    --neon:#00a86b; --neon2:#00c87a;
    --red:#e53935; --orange:#f57c00; --blue:#1976d2; --gold:#f9a825;
    --purple:#a855f7; --cyan:#06b6d4;
    --text:#e8eef8; --text2:#bfd0e4; --muted:#8ea3bd;
    --glow:0 8px 24px rgba(0,168,107,0.18);
    --glow-r:0 8px 24px rgba(229,57,53,0.18);
    --glow-gold:0 8px 24px rgba(249,168,37,0.18);
    --fh:'C059','Source Serif 4','Playfair Display','Book Antiqua',Georgia,serif;
    --fb:'Inter','Segoe UI',system-ui,sans-serif;
}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
html{scroll-behavior:smooth;}
body{font-family:var(--fb);font-size:15px;line-height:1.7;background:var(--bg);color:var(--text);min-height:100vh;overflow-x:hidden;}
body::before{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;
    background:radial-gradient(ellipse 65% 42% at 4% 8%,rgba(50,190,143,0.08) 0%,transparent 62%),
    radial-gradient(ellipse 52% 36% at 96% 88%,rgba(61,140,255,0.07) 0%,transparent 62%);}
body::after{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;
    background-image:linear-gradient(rgba(50,190,143,0.022) 1px,transparent 1px),linear-gradient(90deg,rgba(50,190,143,0.022) 1px,transparent 1px);
    background-size:46px 46px;}
.wrap{position:relative;z-index:1;max-width:1400px;margin:0 auto;padding:16px 16px 48px;}

/* ═══ TOPBAR ═══ */
.topbar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;
    background:rgba(22,32,51,0.96);border:1px solid var(--bord);border-radius:18px;
    padding:18px 28px;margin-bottom:16px;backdrop-filter:blur(24px);}
.brand{display:flex;align-items:center;gap:16px;flex-shrink:0;}
.brand-ico{width:50px;height:50px;background:linear-gradient(135deg,var(--neon),var(--cyan));
    border-radius:14px;display:flex;align-items:center;justify-content:center;
    font-size:24px;color:#fff;box-shadow:0 0 26px rgba(50,190,143,0.5);animation:breathe 3.2s ease-in-out infinite;flex-shrink:0;}
@keyframes breathe{0%,100%{box-shadow:0 0 14px rgba(50,190,143,0.4);}50%{box-shadow:0 0 38px rgba(50,190,143,0.85);}}
.brand-txt h1{font-family:var(--fh);font-size:22px;font-weight:900;color:var(--text);letter-spacing:0.5px;line-height:1.2;}
.brand-txt p{font-size:11px;font-weight:700;color:var(--neon);letter-spacing:2.8px;text-transform:uppercase;margin-top:3px;}
.back-btn{display:inline-flex;align-items:center;gap:10px;padding:11px 22px;
    background:rgba(255,53,83,0.12);border:1.5px solid rgba(255,53,83,0.3);color:var(--red);
    border-radius:12px;font-family:var(--fh);font-size:13px;font-weight:900;text-decoration:none;
    transition:all 0.3s;letter-spacing:0.4px;}
.back-btn:hover{background:var(--red);color:#fff;box-shadow:var(--glow-r);transform:translateY(-2px);}

/* ═══ PANEL ═══ */
.panel{background:var(--card);border:1px solid var(--bord);border-radius:18px;overflow:hidden;margin-bottom:18px;transition:border-color 0.3s;animation:fadeUp .5s ease;}
.panel:hover{border-color:rgba(50,190,143,0.26);}
.ph{display:flex;align-items:center;justify-content:space-between;gap:12px;
    padding:18px 22px;border-bottom:1px solid rgba(255,255,255,0.05);background:rgba(0,0,0,0.18);}
.ph-title{font-family:var(--fh);font-size:18px;font-weight:900;color:var(--text);
    display:flex;align-items:center;gap:10px;letter-spacing:0.4px;flex-wrap:wrap;}
.dot{width:9px;height:9px;border-radius:50%;background:var(--neon);box-shadow:0 0 9px var(--neon);flex-shrink:0;animation:pdot 2.2s infinite;}
@keyframes pdot{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(.75)}}
.pb{padding:22px 26px;}

/* ═══ DOCUMENT HEADER ═══ */
.doc-header{display:flex;gap:26px;margin-bottom:28px;flex-wrap:wrap;}
.doc-icon{width:90px;height:90px;background:linear-gradient(135deg,var(--neon),var(--cyan));
    border-radius:18px;display:flex;align-items:center;justify-content:center;
    font-size:42px;color:#fff;box-shadow:var(--glow);flex-shrink:0;}
.doc-info{flex:1;min-width:0;}
.doc-title{font-family:var(--fh);font-size:28px;font-weight:900;color:var(--text);margin-bottom:10px;line-height:1.3;}
.doc-ref{font-family:'Courier New',monospace;font-size:13px;color:var(--cyan);
    background:rgba(6,182,212,0.1);padding:6px 14px;border-radius:20px;display:inline-block;margin-bottom:12px;}
.doc-badges{display:flex;gap:10px;flex-wrap:wrap;align-items:center;}
.badge{display:inline-flex;align-items:center;gap:6px;padding:7px 16px;border-radius:20px;
    font-family:var(--fb);font-size:12px;font-weight:800;letter-spacing:0.5px;white-space:nowrap;}
.badge-conf{color:#fff;border:1px solid;}
.badge-enc{background:rgba(255,208,96,0.14);color:var(--gold);border:1px solid rgba(255,208,96,0.3);}
.badge-ver{background:rgba(61,140,255,0.14);color:var(--blue);border:1px solid rgba(61,140,255,0.3);}

/* ═══ ACTIONS ═══ */
.doc-actions{display:flex;gap:12px;flex-wrap:wrap;}
.btn{display:inline-flex;align-items:center;gap:8px;padding:12px 22px;border-radius:12px;
    border:none;cursor:pointer;font-family:var(--fh);font-size:13px;font-weight:900;
    letter-spacing:0.4px;transition:all 0.28s;text-decoration:none;white-space:nowrap;}
.btn-neon{background:rgba(50,190,143,0.12);border:1.5px solid rgba(50,190,143,0.3);color:var(--neon);}
.btn-neon:hover{background:var(--neon);color:var(--bg);box-shadow:var(--glow);}
.btn-blue{background:rgba(61,140,255,0.12);border:1.5px solid rgba(61,140,255,0.3);color:var(--blue);}
.btn-blue:hover{background:var(--blue);color:#fff;}
.btn-red{background:rgba(255,53,83,0.12);border:1.5px solid rgba(255,53,83,0.3);color:var(--red);}
.btn-red:hover{background:var(--red);color:#fff;box-shadow:var(--glow-r);}

/* ═══ INFO GRID ═══ */
.info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:18px;margin-bottom:28px;}
.info-item{background:rgba(0,0,0,0.2);border:1px solid var(--bord);border-radius:12px;padding:16px 20px;transition:all 0.3s;}
.info-item:hover{background:rgba(50,190,143,0.05);border-color:rgba(50,190,143,0.3);}
.info-label{font-family:var(--fb);font-size:11px;font-weight:700;color:var(--muted);
    text-transform:uppercase;letter-spacing:1.2px;margin-bottom:8px;display:flex;align-items:center;gap:8px;}
.info-value{font-family:var(--fh);font-size:16px;font-weight:900;color:var(--text);line-height:1.4;}
.info-value small{font-family:var(--fb);font-size:13px;color:var(--text2);font-weight:500;}

/* ═══ LOGS TABLE ═══ */
.logs-table{width:100%;border-collapse:collapse;}
.logs-table th{font-family:var(--fh);font-size:12px;font-weight:900;color:var(--muted);
    text-transform:uppercase;letter-spacing:1.2px;padding:12px 14px;
    border-bottom:1px solid rgba(255,255,255,0.06);text-align:left;background:rgba(0,0,0,0.15);}
.logs-table td{font-family:var(--fb);font-size:13px;font-weight:500;color:var(--text2);
    padding:13px 14px;border-bottom:1px solid rgba(255,255,255,0.04);line-height:1.55;}
.logs-table tr:last-child td{border-bottom:none;}
.logs-table tbody tr{transition:all 0.25s;}
.logs-table tbody tr:hover{background:rgba(50,190,143,0.04);}
.action-badge{font-family:var(--fb);font-size:11px;font-weight:800;padding:5px 12px;border-radius:20px;white-space:nowrap;display:inline-block;}
.action-upload{background:rgba(50,190,143,0.14);color:var(--neon);}
.action-view{background:rgba(61,140,255,0.14);color:var(--blue);}
.action-download{background:rgba(255,208,96,0.14);color:var(--gold);}
.action-edit{background:rgba(168,85,247,0.14);color:var(--purple);}
.action-delete{background:rgba(255,53,83,0.14);color:var(--red);}

@keyframes fadeUp{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:translateY(0)}}
@media(max-width:768px){
    body{line-height:1.5}
    .wrap{padding:8px 8px 28px;max-width:680px}
    .topbar{position:sticky;top:0;z-index:80;padding:10px 12px;border-radius:16px;margin-bottom:12px;background:rgba(22,32,51,.97);backdrop-filter:blur(16px)}
    .brand{gap:10px}
    .brand-ico{width:38px;height:38px;border-radius:12px;font-size:18px}
    .brand-txt h1{font-size:16px}
    .brand-txt p{font-size:9px;letter-spacing:1.4px}
    .back-btn{width:100%;justify-content:center;padding:10px 12px;font-size:11px;border-radius:12px}
    .panel{border-radius:16px;margin-bottom:12px}
    .ph{padding:12px 14px}
    .ph-title{font-size:14px}
    .pb{padding:12px}
    .doc-header{flex-direction:column;gap:14px;margin-bottom:16px}
    .doc-icon{width:62px;height:62px;border-radius:16px;font-size:28px}
    .doc-title{font-size:18px}
    .doc-ref{font-size:11px;padding:4px 10px}
    .doc-badges{gap:8px}
    .badge{padding:5px 10px;font-size:10px}
    .doc-actions{flex-direction:column;gap:8px}
    .doc-actions .btn{width:100%;justify-content:center;padding:10px 12px;font-size:11px;border-radius:12px}
    .info-grid{grid-template-columns:1fr;gap:10px;margin-bottom:16px}
    .info-item{padding:12px 14px;border-radius:12px}
    .info-label{font-size:10px}
    .info-value{font-size:14px}
    .logs-table th,.logs-table td{padding:10px 8px;font-size:10px}
    .action-badge{font-size:9px;padding:4px 8px}
}
</style>
</head>
<body>
<div class="wrap">

<!-- ══════ TOPBAR ══════ -->
<div class="topbar">
    <div class="brand">
        <div class="brand-ico"><i class="fas fa-shield-alt"></i></div>
        <div class="brand-txt">
            <h1>Documents Cryptés</h1>
            <p>ESPERANCE H2O &nbsp;·&nbsp; Sécurité Maximale</p>
        </div>
    </div>
    <a href="documents_erp_pro.php" class="back-btn">
        <i class="fas fa-arrow-left"></i> Retour aux Documents
    </a>
</div>

<!-- ══════ DOCUMENT PRINCIPAL ══════ -->
<div class="panel">
    <div class="ph">
        <div class="ph-title">
            <div class="dot"></div>
            <?= htmlspecialchars($doc['title']) ?>
        </div>
    </div>
    <div class="pb">
        
        <div class="doc-header">
            <div class="doc-icon">
                <i class="fas fa-file-<?= strpos($doc['mime_type'],'pdf')!==false?'pdf':
                    (strpos($doc['mime_type'],'word')!==false?'word':
                    (strpos($doc['mime_type'],'excel')!==false?'excel':
                    (strpos($doc['mime_type'],'image')!==false?'image':'alt'))) ?>"></i>
            </div>
            <div class="doc-info">
                <div class="doc-title"><?= htmlspecialchars($doc['file_name']) ?></div>
                <div class="doc-ref"><i class="fas fa-hashtag"></i> <?= htmlspecialchars($doc['reference_code']) ?></div>
                <div class="doc-badges">
                    <span class="badge badge-conf" style="background:<?= getConfidentialityColor($doc['confidentiality_level']) ?>;border-color:<?= getConfidentialityColor($doc['confidentiality_level']) ?>">
                        <i class="fas fa-<?= $doc['confidentiality_level']==='public'?'globe':
                            ($doc['confidentiality_level']==='internal'?'building':
                            ($doc['confidentiality_level']==='confidential'?'lock':'shield-alt')) ?>"></i>
                        <?= ucfirst($doc['confidentiality_level']) ?>
                    </span>
                    <?php if($doc['encryption_status']): ?>
                    <span class="badge badge-enc">
                        <i class="fas fa-key"></i> Chiffré
                    </span>
                    <?php endif; ?>
                    <span class="badge badge-ver">
                        <i class="fas fa-code-branch"></i> Version <?= $doc['version'] ?>
                    </span>
                </div>
            </div>
        </div>

        <div class="doc-actions">
            <a href="document_download.php?id=<?= $doc['id'] ?>" class="btn btn-neon">
                <i class="fas fa-download"></i> Télécharger
            </a>
            <?php if(in_array($user_role,['developer','admin']) || $doc['uploaded_by']==$user_id): ?>
            <a href="document_edit.php?id=<?= $doc['id'] ?>" class="btn btn-blue">
                <i class="fas fa-edit"></i> Modifier
            </a>
            <a href="document_delete.php?id=<?= $doc['id'] ?>" class="btn btn-red" onclick="return confirm('Supprimer ce document ?')">
                <i class="fas fa-trash"></i> Supprimer
            </a>
            <?php endif; ?>
        </div>

    </div>
</div>

<!-- ══════ INFORMATIONS DÉTAILLÉES ══════ -->
<div class="panel">
    <div class="ph">
        <div class="ph-title"><div class="dot" style="background:var(--cyan);box-shadow:0 0 9px var(--cyan)"></div> Informations Détaillées</div>
    </div>
    <div class="pb">
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label"><i class="fas fa-building"></i> Société</div>
                <div class="info-value"><?= htmlspecialchars($doc['company_name']) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label"><i class="fas fa-folder"></i> Catégorie</div>
                <div class="info-value"><?= htmlspecialchars($doc['category'] ?: 'Non classé') ?></div>
            </div>
            <div class="info-item">
                <div class="info-label"><i class="fas fa-hdd"></i> Taille</div>
                <div class="info-value"><?= formatFileSize($doc['file_size']) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label"><i class="fas fa-file-code"></i> Format</div>
                <div class="info-value"><small><?= htmlspecialchars($doc['mime_type']) ?></small></div>
            </div>
            <div class="info-item">
                <div class="info-label"><i class="fas fa-user-circle"></i> Téléversé par</div>
                <div class="info-value"><?= htmlspecialchars($doc['uploaded_by_name']) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label"><i class="fas fa-calendar-plus"></i> Date de création</div>
                <div class="info-value"><?= date('d/m/Y à H:i',strtotime($doc['created_at'])) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label"><i class="fas fa-calendar-check"></i> Dernière modification</div>
                <div class="info-value"><?= date('d/m/Y à H:i',strtotime($doc['updated_at'])) ?></div>
            </div>
            <?php if($doc['expires_at']): ?>
            <div class="info-item">
                <div class="info-label"><i class="fas fa-calendar-times"></i> Date d'expiration</div>
                <div class="info-value" style="<?= strtotime($doc['expires_at'])<time()?'color:var(--red)':'' ?>">
                    <?= date('d/m/Y',strtotime($doc['expires_at'])) ?>
                </div>
            </div>
            <?php endif; ?>
            <div class="info-item">
                <div class="info-label"><i class="fas fa-fingerprint"></i> Hash SHA-256</div>
                <div class="info-value"><small style="word-break:break-all;font-family:monospace;font-size:11px;color:var(--muted)"><?= substr($doc['file_hash'],0,32) ?>...</small></div>
            </div>
        </div>
    </div>
</div>

<!-- ══════ HISTORIQUE D'ACCÈS ══════ -->
<div class="panel">
    <div class="ph">
        <div class="ph-title">
            <div class="dot" style="background:var(--gold);box-shadow:0 0 9px var(--gold)"></div>
            Historique d'Accès
        </div>
        <span style="font-family:var(--fb);font-size:11px;font-weight:800;padding:5px 14px;border-radius:20px;
            background:rgba(255,208,96,0.12);color:var(--gold)">
            <?= count($logs) ?> entrée(s)
        </span>
    </div>
    <div class="pb">
        <div style="overflow-x:auto">
        <table class="logs-table">
            <thead>
                <tr>
                    <th>Action</th>
                    <th>Utilisateur</th>
                    <th>IP</th>
                    <th>Date & Heure</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($logs as $log):
                    $actionClass = strtolower($log['action']);
                ?>
                <tr>
                    <td>
                        <span class="action-badge action-<?= $actionClass ?>">
                            <i class="fas fa-<?= match($log['action']) {
                                'UPLOAD'=>'cloud-upload-alt',
                                'VIEW'=>'eye',
                                'DOWNLOAD'=>'download',
                                'EDIT'=>'edit',
                                'DELETE'=>'trash',
                                default=>'circle'
                            } ?>"></i>
                            <?= htmlspecialchars($log['action']) ?>
                        </span>
                    </td>
                    <td><strong><?= htmlspecialchars($log['username'] ?: 'Inconnu') ?></strong></td>
                    <td style="font-family:monospace;color:var(--cyan);font-size:12px"><?= htmlspecialchars($log['ip_address']) ?></td>
                    <td style="color:var(--muted)"><?= date('d/m/Y à H:i:s',strtotime($log['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($logs)): ?>
                <tr>
                    <td colspan="4" style="text-align:center;padding:28px;color:var(--muted)">
                        <i class="fas fa-history" style="font-size:32px;display:block;margin-bottom:8px;opacity:.3"></i>
                        Aucun historique
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

</div><!-- /wrap -->
</body>
</html>
