<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once APP_ROOT . '/app/core/DB.php';
require_once APP_ROOT . '/app/core/Auth.php';
require_once APP_ROOT . '/app/core/Middleware.php';
require_once APP_ROOT . '/app/core/CryptoSecure.php';

use App\Core\DB;
use App\Core\Auth;
use App\Core\Middleware;
use App\Core\CryptoSecure;

Auth::check();
Middleware::role(['developer', 'admin', 'manager', 'user']);

$pdo = DB::getConnection();
$user_id = $_SESSION['user_id'] ?? 0;
$user_name = $_SESSION['username'] ?? '';

$success = $error = '';

// Récupération des sociétés
$companies = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Traitement de l'upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['document'])) {
    
    $company_id = (int)$_POST['company_id'];
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $category = trim($_POST['category']);
    $confidentiality = $_POST['confidentiality_level'];
    $reference = trim($_POST['reference_code']) ?: 'DOC-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 6));
    $expires_at = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;
    
    $file = $_FILES['document'];
    
    // Validation
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = "Erreur lors de l'upload du fichier.";
    } elseif ($company_id <= 0) {
        $error = "Veuillez sélectionner une société.";
    } elseif (empty($title)) {
        $error = "Le titre est obligatoire.";
    } elseif ($file['size'] > 50 * 1024 * 1024) { // 50 MB max
        $error = "Fichier trop volumineux (max 50 MB).";
    } else {
        
        try {
            $pdo->beginTransaction();
            
            // ═══════════════════════════════════════════
            // GÉNÉRATION DE LA CLÉ DE CHIFFREMENT UNIQUE
            // ═══════════════════════════════════════════
            $encryption_key = CryptoSecure::generateKey();
            
            // Préparation des chemins
            $uploadDir = APP_ROOT . '/storage/documents/encrypted';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0700, true);
            }
            
            $originalName = $file['name'];
            $mimeType = mime_content_type($file['tmp_name']);
            $fileSize = $file['size'];
            
            // Nom chiffré aléatoire (impossible à deviner)
            $encryptedFileName = bin2hex(random_bytes(32)) . '.enc';
            $encryptedPath = $uploadDir . '/' . $encryptedFileName;
            
            // ═══════════════════════════════════════════
            // CHIFFREMENT DU FICHIER
            // ═══════════════════════════════════════════
            $cryptoResult = CryptoSecure::encryptFile(
                $file['tmp_name'],
                $encryptedPath,
                $encryption_key
            );
            
            if (!$cryptoResult['success']) {
                throw new Exception("Échec du chiffrement : " . ($cryptoResult['error'] ?? 'Erreur inconnue'));
            }
            
            // Hash du fichier chiffré (pour vérification d'intégrité)
            $file_hash = $cryptoResult['file_hash'];
            
            // ═══════════════════════════════════════════
            // INSERTION EN BASE DE DONNÉES
            // ═══════════════════════════════════════════
            $stmt = $pdo->prepare("
                INSERT INTO documents (
                    company_id, title, description, category, reference_code,
                    file_name, file_path, mime_type, file_size, file_hash,
                    confidentiality_level, uploaded_by, encryption_status,
                    encryption_key, encryption_iv, encryption_tag, encryption_salt,
                    expires_at, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $company_id,
                $title,
                $description,
                $category,
                $reference,
                $originalName,
                $encryptedFileName, // Stocke uniquement le nom chiffré
                $mimeType,
                $fileSize,
                $file_hash,
                $confidentiality,
                $user_id,
                $encryption_key,
                $cryptoResult['iv'],
                $cryptoResult['tag'],
                $cryptoResult['salt'],
                $expires_at
            ]);
            
            $document_id = $pdo->lastInsertId();
            
            // ═══════════════════════════════════════════
            // LOG D'ACCÈS SÉCURISÉ
            // ═══════════════════════════════════════════
            $stmt = $pdo->prepare("
                INSERT INTO document_logs (document_id, user_id, action, ip_address, user_agent, created_at)
                VALUES (?, ?, 'UPLOAD', ?, ?, NOW())
            ");
            $stmt->execute([
                $document_id,
                $user_id,
                $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            ]);
            
            // ═══════════════════════════════════════════
            // SUPPRESSION SÉCURISÉE DU FICHIER TEMPORAIRE
            // ═══════════════════════════════════════════
            CryptoSecure::secureDelete($file['tmp_name']);
            
            $pdo->commit();
            
            $success = "✅ Document chiffré et téléversé avec succès ! Référence : <strong>$reference</strong>";
            
        } catch (Exception $e) {
            $pdo->rollBack();
            if (isset($encryptedPath) && file_exists($encryptedPath)) {
                CryptoSecure::secureDelete($encryptedPath);
            }
            $error = "❌ Erreur : " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Upload Document Crypté | ESPERANCE H2O</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=Source+Serif+4:wght@700;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
@font-face{font-family:'C059';src:local('C059-Bold'),local('C059 Bold'),local('Century Schoolbook');font-weight:700 900;font-style:normal}
:root{--bg:#0f1726;--surf:#162033;--card:#1b263b;--card2:#22324a;--bord:rgba(148,163,184,0.18);--neon:#00a86b;--neon2:#00c87a;--red:#e53935;--orange:#f57c00;--blue:#1976d2;--gold:#f9a825;--purple:#a855f7;--cyan:#06b6d4;--text:#e8eef8;--text2:#bfd0e4;--muted:#8ea3bd;--glow:0 8px 24px rgba(0,168,107,0.18);--glow-r:0 8px 24px rgba(229,57,53,0.18);--glow-gold:0 8px 24px rgba(249,168,37,0.18);--fh:'C059','Source Serif 4','Playfair Display','Book Antiqua',Georgia,serif;--fb:'Inter','Segoe UI',system-ui,sans-serif}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
html{scroll-behavior:smooth}
body{font-family:var(--fb);font-size:15px;line-height:1.7;background:var(--bg);color:var(--text);min-height:100vh;overflow-x:hidden;padding:40px 20px}
body::before{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;background:radial-gradient(ellipse 65% 42% at 4% 8%,rgba(50,190,143,0.08) 0%,transparent 62%),radial-gradient(ellipse 52% 36% at 96% 88%,rgba(61,140,255,0.07) 0%,transparent 62%)}
body::after{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;background-image:linear-gradient(rgba(50,190,143,0.022) 1px,transparent 1px),linear-gradient(90deg,rgba(50,190,143,0.022) 1px,transparent 1px);background-size:46px 46px}

.container{position:relative;z-index:1;max-width:900px;margin:0 auto}
.card{background:var(--card);border:1px solid var(--bord);border-radius:20px;padding:40px;box-shadow:0 25px 50px -12px rgba(0,0,0,0.4);animation:slideUp .6s ease;backdrop-filter:blur(24px)}
@keyframes slideUp{from{opacity:0;transform:translateY(30px)}to{opacity:1;transform:translateY(0)}}
.header{text-align:center;margin-bottom:40px}
.header h1{font-family:var(--fh);font-size:32px;font-weight:900;color:var(--text);margin-bottom:10px;display:flex;align-items:center;justify-content:center;gap:15px}
.header h1 i{font-size:40px;background:linear-gradient(135deg,var(--neon),var(--cyan));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.security-badge{display:inline-flex;align-items:center;gap:8px;background:linear-gradient(135deg,rgba(255,208,96,0.2),rgba(255,145,64,0.2));border:1px solid rgba(255,208,96,0.4);color:var(--gold);padding:10px 20px;border-radius:30px;font-size:13px;font-weight:700;margin-top:15px;box-shadow:var(--glow-gold)}
.alert{padding:15px 20px;border-radius:12px;margin-bottom:25px;display:flex;align-items:center;gap:12px;font-weight:600}
.alert-success{background:rgba(50,190,143,0.14);color:var(--neon);border:1px solid rgba(50,190,143,0.3)}
.alert-error{background:rgba(255,53,83,0.14);color:var(--red);border:1px solid rgba(255,53,83,0.3)}
.form-group{margin-bottom:25px}
.form-label{display:block;font-family:var(--fh);font-weight:900;color:var(--text);margin-bottom:8px;font-size:14px;letter-spacing:0.4px}
.form-label .required{color:var(--red);margin-left:4px}
.form-control,.form-select,.form-textarea{width:100%;padding:14px 18px;background:rgba(15,23,38,0.72);border:1.5px solid var(--bord);border-radius:12px;color:var(--text);font-size:15px;font-weight:500;transition:all 0.3s;font-family:var(--fb)}
.form-control:focus,.form-select:focus,.form-textarea:focus{outline:none;border-color:var(--neon);box-shadow:var(--glow);background:rgba(50,190,143,0.05)}
.form-select option{background:#1b263b;color:var(--text)}
.form-textarea{resize:vertical;min-height:100px}
.file-upload-area{border:3px dashed var(--bord);border-radius:16px;padding:40px;text-align:center;background:rgba(0,0,0,0.2);transition:all 0.3s;cursor:pointer}
.file-upload-area:hover{border-color:var(--neon);background:rgba(50,190,143,0.05)}
.file-upload-area.dragover{border-color:var(--cyan);background:rgba(6,182,212,0.08);box-shadow:0 0 26px rgba(6,182,212,0.3)}
.file-upload-icon{font-size:48px;color:var(--neon);margin-bottom:15px}
.file-upload-text{font-family:var(--fh);font-size:18px;font-weight:900;color:var(--text);margin-bottom:8px}
.file-upload-hint{font-size:13px;color:var(--muted)}
.file-info{background:rgba(50,190,143,0.08);border:1px solid rgba(50,190,143,0.3);border-radius:12px;padding:15px 20px;display:flex;align-items:center;justify-content:space-between;margin-top:15px}
.file-info-name{font-family:var(--fh);font-weight:900;color:var(--text)}
.file-info-remove{background:rgba(255,53,83,0.12);border:1.5px solid rgba(255,53,83,0.3);color:var(--red);padding:8px 16px;border-radius:8px;cursor:pointer;font-family:var(--fh);font-weight:900;transition:all 0.3s;font-size:13px}
.file-info-remove:hover{background:var(--red);color:#fff;transform:scale(1.05)}
.btn{padding:16px 32px;border:none;border-radius:12px;font-size:16px;font-family:var(--fh);font-weight:900;letter-spacing:0.4px;cursor:pointer;transition:all 0.3s;display:inline-flex;align-items:center;gap:10px;text-decoration:none}
.btn-primary{background:rgba(50,190,143,0.12);border:1.5px solid rgba(50,190,143,0.3);color:var(--neon);box-shadow:0 8px 20px rgba(50,190,143,0.3)}
.btn-primary:hover{background:var(--neon);color:var(--bg);box-shadow:var(--glow);transform:translateY(-3px)}
.btn-secondary{background:rgba(255,255,255,0.04);border:1.5px solid var(--bord);color:var(--text2)}
.btn-secondary:hover{background:rgba(255,255,255,0.1);border-color:var(--text2)}
.form-actions{display:flex;gap:15px;margin-top:30px}
.form-actions button{flex:1}
.encryption-info{background:linear-gradient(135deg,rgba(255,208,96,0.08),rgba(255,145,64,0.08));border:1px solid rgba(255,208,96,0.3);border-radius:16px;padding:20px;margin-bottom:30px}
.encryption-info h3{font-family:var(--fh);font-size:18px;font-weight:900;color:var(--gold);margin-bottom:12px;display:flex;align-items:center;gap:10px}
.encryption-info ul{list-style:none;padding:0}
.encryption-info li{padding:8px 0;color:var(--text2);font-weight:600;font-size:14px;display:flex;align-items:center;gap:10px}
.encryption-info li i{color:var(--gold)}
.confidentiality-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}
.confidentiality-option{position:relative}
.confidentiality-option input[type="radio"]{position:absolute;opacity:0}
.confidentiality-label{display:block;padding:16px 20px;border:1.5px solid var(--bord);border-radius:12px;cursor:pointer;transition:all 0.3s;text-align:center;font-family:var(--fh);font-weight:900;background:rgba(0,0,0,0.2);color:var(--text2)}
.confidentiality-option input[type="radio"]:checked+.confidentiality-label{border-color:var(--neon);background:rgba(50,190,143,0.1);box-shadow:var(--glow);color:var(--neon)}
@media(max-width:768px){
    body{line-height:1.5;padding:12px 8px 24px}
    .container{max-width:680px}
    .card{padding:16px 14px;border-radius:18px}
    .header{margin-bottom:18px}
    .header h1{font-size:20px;gap:10px;line-height:1.25}
    .header h1 i{font-size:26px}
    .security-badge{padding:7px 12px;font-size:11px;border-radius:999px;margin-top:10px}
    .alert{padding:12px 14px;font-size:12px;border-radius:12px;margin-bottom:14px}
    .encryption-info{padding:14px;border-radius:14px;margin-bottom:16px}
    .encryption-info h3{font-size:14px}
    .encryption-info li{font-size:12px;padding:5px 0}
    .form-group{margin-bottom:16px}
    .form-label{font-size:11px}
    .form-control,.form-select,.form-textarea{padding:11px 12px;font-size:13px;border-radius:12px}
    .form-textarea{min-height:92px}
    .file-upload-area{padding:22px 14px;border-radius:14px}
    .file-upload-icon{font-size:34px;margin-bottom:10px}
    .file-upload-text{font-size:14px}
    .file-upload-hint{font-size:11px}
    .file-info{padding:12px 14px;border-radius:12px;gap:8px;align-items:flex-start;flex-direction:column}
    .file-info-name{font-size:12px}
    .file-info-remove{padding:7px 12px;font-size:11px}
    .confidentiality-grid{grid-template-columns:1fr;gap:8px}
    .confidentiality-label{padding:12px 14px;font-size:12px;border-radius:12px}
    .form-actions{flex-direction:column;gap:8px;margin-top:18px}
    .btn{width:100%;justify-content:center;padding:11px 12px;font-size:12px;border-radius:12px}
}
</style>
</head>
<body>

<div class="container">
    <div class="card">
        <div class="header">
            <h1>
                <i class="fas fa-shield-alt"></i>
                Upload Document Sécurisé
            </h1>
            <div class="security-badge">
                <i class="fas fa-lock"></i>
                Chiffrement AES-256-GCM Militaire
            </div>
        </div>

        <?php if($success): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle fa-lg"></i>
            <span><?= $success ?></span>
        </div>
        <?php endif; ?>

        <?php if($error): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-triangle fa-lg"></i>
            <span><?= $error ?></span>
        </div>
        <?php endif; ?>

        <div class="encryption-info">
            <h3><i class="fas fa-key"></i> Sécurité Maximale Garantie</h3>
            <ul>
                <li><i class="fas fa-check-circle"></i> Chiffrement AES-256-GCM (niveau militaire)</li>
                <li><i class="fas fa-check-circle"></i> Clé unique générée pour chaque document</li>
                <li><i class="fas fa-check-circle"></i> Fichier impossible à lire sans authentification</li>
                <li><i class="fas fa-check-circle"></i> Détection automatique des modifications</li>
                <li><i class="fas fa-check-circle"></i> Logs d'accès complets et signés</li>
            </ul>
        </div>

        <form method="post" enctype="multipart/form-data" id="uploadForm">
            
            <div class="form-group">
                <label class="form-label">
                    <i class="fas fa-cloud-upload-alt"></i> Fichier
                    <span class="required">*</span>
                </label>
                <div class="file-upload-area" id="dropZone">
                    <div class="file-upload-icon">
                        <i class="fas fa-cloud-upload-alt"></i>
                    </div>
                    <div class="file-upload-text">Glissez-déposez votre fichier ici</div>
                    <div class="file-upload-hint">ou cliquez pour parcourir (Max 50 MB)</div>
                    <input type="file" name="document" id="fileInput" style="display:none" required>
                </div>
                <div id="fileInfo" style="display:none"></div>
            </div>

            <div class="form-group">
                <label class="form-label">Société <span class="required">*</span></label>
                <select name="company_id" class="form-select" required>
                    <option value="">— Sélectionner une société —</option>
                    <?php foreach($companies as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Titre du document <span class="required">*</span></label>
                <input type="text" name="title" class="form-control" placeholder="Ex: Contrat Commercial 2024" required>
            </div>

            <div class="form-group">
                <label class="form-label">Catégorie</label>
                <input type="text" name="category" class="form-control" placeholder="Ex: Contrats, Factures, RH..." list="categories">
                <datalist id="categories">
                    <option value="Contrats">
                    <option value="Factures">
                    <option value="Ressources Humaines">
                    <option value="Juridique">
                    <option value="Comptabilité">
                    <option value="Technique">
                </datalist>
            </div>

            <div class="form-group">
                <label class="form-label">Référence (auto-généré si vide)</label>
                <input type="text" name="reference_code" class="form-control" placeholder="DOC-20240217-ABC123">
            </div>

            <div class="form-group">
                <label class="form-label">Niveau de confidentialité <span class="required">*</span></label>
                <div class="confidentiality-grid">
                    <div class="confidentiality-option">
                        <input type="radio" name="confidentiality_level" value="public" id="conf-public" required>
                        <label for="conf-public" class="confidentiality-label">
                            <i class="fas fa-globe"></i> Public
                        </label>
                    </div>
                    <div class="confidentiality-option">
                        <input type="radio" name="confidentiality_level" value="internal" id="conf-internal">
                        <label for="conf-internal" class="confidentiality-label">
                            <i class="fas fa-building"></i> Interne
                        </label>
                    </div>
                    <div class="confidentiality-option">
                        <input type="radio" name="confidentiality_level" value="confidential" id="conf-confidential" checked>
                        <label for="conf-confidential" class="confidentiality-label">
                            <i class="fas fa-lock"></i> Confidentiel
                        </label>
                    </div>
                    <div class="confidentiality-option">
                        <input type="radio" name="confidentiality_level" value="restricted" id="conf-restricted">
                        <label for="conf-restricted" class="confidentiality-label">
                            <i class="fas fa-shield-alt"></i> Restreint
                        </label>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Description</label>
                <textarea name="description" class="form-textarea" placeholder="Description détaillée du document..."></textarea>
            </div>

            <div class="form-group">
                <label class="form-label"><i class="fas fa-calendar-times"></i> Date d'expiration (optionnel)</label>
                <input type="date" name="expires_at" class="form-control">
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-shield-alt"></i>
                    Chiffrer et Téléverser
                </button>
                <a href="documents_erp_pro.php" class="btn btn-secondary">
                    <i class="fas fa-times"></i>
                    Annuler
                </a>
            </div>
        </form>
    </div>
</div>

<script>
const dropZone = document.getElementById('dropZone');
const fileInput = document.getElementById('fileInput');
const fileInfo = document.getElementById('fileInfo');

// Click to upload
dropZone.addEventListener('click', () => fileInput.click());

// Drag & Drop
dropZone.addEventListener('dragover', (e) => {
    e.preventDefault();
    dropZone.classList.add('dragover');
});

dropZone.addEventListener('dragleave', () => {
    dropZone.classList.remove('dragover');
});

dropZone.addEventListener('drop', (e) => {
    e.preventDefault();
    dropZone.classList.remove('dragover');
    
    const files = e.dataTransfer.files;
    if (files.length > 0) {
        fileInput.files = files;
        displayFileInfo(files[0]);
    }
});

fileInput.addEventListener('change', (e) => {
    if (e.target.files.length > 0) {
        displayFileInfo(e.target.files[0]);
    }
});

function displayFileInfo(file) {
    const size = (file.size / 1024 / 1024).toFixed(2);
    fileInfo.innerHTML = `
        <div class="file-info">
            <div>
                <div class="file-info-name">
                    <i class="fas fa-file"></i> ${file.name}
                </div>
                <small style="color:#64748b">${size} MB</small>
            </div>
            <button type="button" class="file-info-remove" onclick="clearFile()">
                <i class="fas fa-times"></i> Retirer
            </button>
        </div>
    `;
    fileInfo.style.display = 'block';
    dropZone.style.display = 'none';
}

function clearFile() {
    fileInput.value = '';
    fileInfo.style.display = 'none';
    dropZone.style.display = 'block';
}

// Animation au submit
document.getElementById('uploadForm').addEventListener('submit', function(e) {
    const btn = this.querySelector('button[type="submit"]');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Chiffrement en cours...';
    btn.disabled = true;
});
</script>

</body>
</html>
