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

$pdo = DB::getConnection();
$current_user_id = $_SESSION['user_id'] ?? 0;
$user_role = $_SESSION['role'] ?? 'user';

$document_id = (int)($_GET['id'] ?? 0);
$message = '';
$messageType = '';

if (!$document_id) {
    header('Location: documents_erp_pro.php');
    exit;
}

// Récupération du document
$stmt = $pdo->prepare("SELECT d.*, c.name as company_name FROM documents d JOIN companies c ON c.id = d.company_id WHERE d.id = ? AND d.deleted_at IS NULL");
$stmt->execute([$document_id]);
$document = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$document) {
    header('Location: documents_erp_pro.php');
    exit;
}

// Vérification des permissions
$can_edit = false;
if (in_array($user_role, ['developer', 'admin'])) {
    $can_edit = true;
} elseif ($document['uploaded_by'] == $current_user_id) {
    $can_edit = true;
} else {
    $stmt = $pdo->prepare("SELECT can_edit FROM document_permissions WHERE document_id = ? AND user_id = ?");
    $stmt->execute([$document_id, $current_user_id]);
    $perm = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($perm && $perm['can_edit']) $can_edit = true;
}

if (!$can_edit) {
    die("❌ Vous n'avez pas l'autorisation de modifier ce document.");
}

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title']);
    $description = trim($_POST['description'] ?? '');
    $category = trim($_POST['category']);
    $confidentiality = $_POST['confidentiality_level'];
    $expires_at = $_POST['expires_at'] ?? null;
    
    if ($title && $category) {
        $stmt = $pdo->prepare("UPDATE documents SET title = ?, description = ?, category = ?, confidentiality_level = ?, expires_at = ?, version = version + 1, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$title, $description, $category, $confidentiality, $expires_at ?: null, $document_id]);
        
        // Log
        $stmt = $pdo->prepare("INSERT INTO document_logs (document_id, user_id, action, ip_address, user_agent) VALUES (?, ?, 'EDIT', ?, ?)");
        $stmt->execute([$document_id, $current_user_id, $_SERVER['REMOTE_ADDR'] ?? 'Unknown', $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown']);
        
        $message = "✅ Document mis à jour avec succès";
        $messageType = 'success';
        
        // Recharger
        $stmt = $pdo->prepare("SELECT d.*, c.name as company_name FROM documents d JOIN companies c ON c.id = d.company_id WHERE d.id = ?");
        $stmt->execute([$document_id]);
        $document = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $message = "❌ Tous les champs obligatoires doivent être remplis";
        $messageType = 'error';
    }
}

$categories = ['Contrats','Factures','Devis','RH','Juridique','Comptabilité','Marketing','Technique','Administratif','Autre'];

function formatFileSize($bytes) {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Éditer - <?= htmlspecialchars($document['title']) ?> | ESPERANCE H2O</title>
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
.wrap{position:relative;z-index:1;max-width:1400px;margin:0 auto;padding:16px 16px 48px}
.topbar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;background:rgba(8,20,32,0.94);border:1px solid var(--bord);border-radius:18px;padding:18px 28px;margin-bottom:16px;backdrop-filter:blur(24px)}
.brand{display:flex;align-items:center;gap:16px;flex-shrink:0}
.brand-ico{width:50px;height:50px;background:linear-gradient(135deg,var(--neon),var(--cyan));border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:24px;color:#fff;box-shadow:0 0 26px rgba(50,190,143,0.5);animation:breathe 3.2s ease-in-out infinite;flex-shrink:0}
@keyframes breathe{0%,100%{box-shadow:0 0 14px rgba(50,190,143,0.4)}50%{box-shadow:0 0 38px rgba(50,190,143,0.85)}}
.brand-txt h1{font-family:var(--fh);font-size:22px;font-weight:900;color:var(--text);letter-spacing:0.5px;line-height:1.2}
.brand-txt p{font-size:11px;font-weight:700;color:var(--neon);letter-spacing:2.8px;text-transform:uppercase;margin-top:3px}
.back-btn{display:inline-flex;align-items:center;gap:10px;padding:11px 22px;background:rgba(255,53,83,0.12);border:1.5px solid rgba(255,53,83,0.3);color:var(--red);border-radius:12px;font-family:var(--fh);font-size:13px;font-weight:900;text-decoration:none;transition:all 0.3s;letter-spacing:0.4px}
.back-btn:hover{background:var(--red);color:#fff;box-shadow:var(--glow-r);transform:translateY(-2px)}
.alert{padding:16px 24px;border-radius:12px;margin-bottom:20px;display:flex;align-items:center;gap:12px;font-weight:600}
.alert-success{background:rgba(50,190,143,0.14);color:var(--neon);border:1px solid rgba(50,190,143,0.3)}
.alert-error{background:rgba(255,53,83,0.14);color:var(--red);border:1px solid rgba(255,53,83,0.3)}
.layout{display:grid;grid-template-columns:450px 1fr;gap:20px}
.panel{background:var(--card);border:1px solid var(--bord);border-radius:18px;overflow:hidden;margin-bottom:18px;transition:border-color 0.3s;animation:fadeUp .5s ease}
.panel:hover{border-color:rgba(50,190,143,0.26)}
.ph{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:18px 22px;border-bottom:1px solid rgba(255,255,255,0.05);background:rgba(0,0,0,0.18)}
.ph-title{font-family:var(--fh);font-size:18px;font-weight:900;color:var(--text);display:flex;align-items:center;gap:10px;letter-spacing:0.4px}
.dot{width:9px;height:9px;border-radius:50%;background:var(--neon);box-shadow:0 0 9px var(--neon);flex-shrink:0;animation:pdot 2.2s infinite}
@keyframes pdot{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(.75)}}
.pb{padding:22px 26px}
.doc-info{background:rgba(50,190,143,0.05);border:1px solid var(--bord);border-radius:12px;padding:18px;margin-bottom:20px}
.info-item{display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid rgba(255,255,255,0.05)}
.info-item:last-child{border-bottom:none}
.info-label{font-family:var(--fb);font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:1px}
.info-value{font-family:var(--fh);font-size:14px;font-weight:900;color:var(--text)}
.form-group{margin-bottom:20px}
.form-label{display:block;font-family:var(--fh);font-size:13px;font-weight:900;color:var(--text);margin-bottom:8px;letter-spacing:0.4px}
.required{color:var(--red)}
.f-input,.f-select,.f-textarea{width:100%;padding:12px 16px;background:rgba(0,0,0,0.3);border:1.5px solid var(--bord);border-radius:12px;color:var(--text);font-family:var(--fb);font-size:14px;font-weight:600;transition:all 0.3s}
.f-input:focus,.f-select:focus,.f-textarea:focus{outline:none;border-color:var(--neon);box-shadow:var(--glow);background:rgba(50,190,143,0.05)}
.f-select option{background:#0d1e2c;color:var(--text)}
.f-textarea{resize:vertical;min-height:100px}
.version-box{background:rgba(255,208,96,0.08);border:1px solid rgba(255,208,96,0.3);border-radius:12px;padding:15px;margin-bottom:20px}
.version-box strong{color:var(--gold);display:flex;align-items:center;gap:8px;font-family:var(--fh)}
.version-box p{font-size:13px;color:var(--text2);margin-top:8px}
.btn{display:inline-flex;align-items:center;gap:8px;padding:14px 28px;border-radius:12px;border:none;cursor:pointer;font-family:var(--fh);font-size:14px;font-weight:900;letter-spacing:0.4px;transition:all 0.28s;text-decoration:none}
.btn-neon{background:rgba(50,190,143,0.12);border:1.5px solid rgba(50,190,143,0.3);color:var(--neon)}
.btn-neon:hover{background:var(--neon);color:var(--bg);box-shadow:var(--glow);transform:translateY(-2px)}
.btn-gray{background:rgba(255,255,255,0.05);border:1.5px solid var(--bord);color:var(--text2)}
.btn-gray:hover{background:rgba(255,255,255,0.1)}
.btn-group{display:flex;gap:12px;justify-content:flex-end;margin-top:20px}
@keyframes fadeUp{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:translateY(0)}}
@media(max-width:1024px){.layout{grid-template-columns:1fr}.wrap{padding:12px}}
</style>
</head>
<body>
<div class="wrap">

<!-- TOPBAR -->
<div class="topbar">
    <div class="brand">
        <div class="brand-ico"><i class="fas fa-shield-alt"></i></div>
        <div class="brand-txt">
            <h1>Éditer Document</h1>
            <p>ESPERANCE H2O &nbsp;·&nbsp; Modification Sécurisée</p>
        </div>
    </div>
    <a href="document_view.php?id=<?= $document_id ?>" class="back-btn">
        <i class="fas fa-arrow-left"></i> Retour
    </a>
</div>

<?php if($message): ?>
<div class="alert alert-<?= $messageType ?>">
    <i class="fas fa-<?= $messageType==='success'?'check-circle':'times-circle' ?>"></i>
    <?= $message ?>
</div>
<?php endif; ?>

<div class="layout">
    
    <!-- SIDEBAR INFO -->
    <div>
        <div class="panel">
            <div class="ph">
                <div class="ph-title"><div class="dot" style="background:var(--cyan);box-shadow:0 0 9px var(--cyan)"></div> Informations</div>
            </div>
            <div class="pb">
                <div class="doc-info">
                    <div class="info-item">
                        <span class="info-label">Référence</span>
                        <span class="info-value"><?= htmlspecialchars($document['reference_code']) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Taille</span>
                        <span class="info-value"><?= formatFileSize($document['file_size']) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Format</span>
                        <span class="info-value" style="font-size:11px;font-family:monospace"><?= htmlspecialchars($document['mime_type']) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Version actuelle</span>
                        <span class="info-value" style="color:var(--cyan)">v<?= $document['version'] ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Créé le</span>
                        <span class="info-value" style="font-size:12px"><?= date('d/m/Y H:i',strtotime($document['created_at'])) ?></span>
                    </div>
                    <?php if($document['encryption_status']): ?>
                    <div class="info-item">
                        <span class="info-label">Sécurité</span>
                        <span class="info-value" style="color:var(--gold)"><i class="fas fa-key"></i> Chiffré</span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- FORM EDIT -->
    <div>
        <div class="panel">
            <div class="ph">
                <div class="ph-title"><div class="dot"></div> Modifier les Métadonnées</div>
            </div>
            <div class="pb">
                
                <form method="post">
                    <div class="form-group">
                        <label class="form-label">Titre <span class="required">*</span></label>
                        <input type="text" name="title" class="f-input" value="<?= htmlspecialchars($document['title']) ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="f-textarea"><?= htmlspecialchars($document['description']) ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Catégorie <span class="required">*</span></label>
                        <select name="category" class="f-select" required>
                            <?php foreach($categories as $cat): ?>
                            <option value="<?= htmlspecialchars($cat) ?>" <?= $document['category']==$cat?'selected':'' ?>><?= htmlspecialchars($cat) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Niveau de Confidentialité <span class="required">*</span></label>
                        <select name="confidentiality_level" class="f-select" required>
                            <option value="public" <?= $document['confidentiality_level']=='public'?'selected':'' ?>>🌐 Public</option>
                            <option value="internal" <?= $document['confidentiality_level']=='internal'?'selected':'' ?>>🏢 Interne</option>
                            <option value="confidential" <?= $document['confidentiality_level']=='confidential'?'selected':'' ?>>🔒 Confidentiel</option>
                            <option value="restricted" <?= $document['confidentiality_level']=='restricted'?'selected':'' ?>>🛡️ Restreint</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Date d'Expiration</label>
                        <input type="date" name="expires_at" class="f-input" 
                               value="<?= $document['expires_at'] ? date('Y-m-d',strtotime($document['expires_at'])) : '' ?>" 
                               min="<?= date('Y-m-d') ?>">
                    </div>
                    
                    <div class="version-box">
                        <strong><i class="fas fa-info-circle"></i> Version automatiquement incrémentée</strong>
                        <p>Version actuelle : v<?= $document['version'] ?> → Nouvelle version : v<?= $document['version'] + 1 ?></p>
                    </div>
                    
                    <div class="btn-group">
                        <a href="document_view.php?id=<?= $document_id ?>" class="btn btn-gray">
                            <i class="fas fa-times"></i> Annuler
                        </a>
                        <button type="submit" class="btn btn-neon">
                            <i class="fas fa-save"></i> Enregistrer
                        </button>
                    </div>
                </form>
                
            </div>
        </div>
    </div>
    
</div>

</div>
</body>
</html>
