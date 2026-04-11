<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';
require_once dirname(__DIR__) . '/legal/legal_bootstrap.php';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Installer ESPERANCEH²O</title>
<meta name="theme-color" content="#10b981">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="ESPERANCEH²O">
<link rel="manifest" href="/hr/employee_manifest.json">
<link rel="icon" href="/hr/employee-app-icon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/hr/employee-app-icon-192.png">
<link rel="apple-touch-startup-image" href="/hr/employee-startup-1284x2778.png">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{
    font-family:Inter,system-ui,sans-serif;
    min-height:100vh;
    background:
        radial-gradient(circle at top, rgba(16,185,129,.18), transparent 25%),
        linear-gradient(180deg,#0f172a 0%, #111827 100%);
    color:#e2e8f0;
    padding:18px;
}
.wrap{max-width:960px;margin:0 auto}
.hero,.panel{
    background:rgba(15,23,42,.7);
    border:1px solid rgba(255,255,255,.08);
    border-radius:24px;
    box-shadow:0 24px 60px rgba(0,0,0,.3);
    backdrop-filter:blur(14px);
}
.hero{padding:26px;margin-bottom:18px}
.top{display:flex;gap:16px;align-items:center;flex-wrap:wrap}
.logo{width:88px;height:88px;border-radius:26px;overflow:hidden;box-shadow:0 22px 42px rgba(16,185,129,.25)}
.logo img{width:100%;height:100%;object-fit:cover}
h1{font-size:30px;font-weight:800;line-height:1.1;margin-bottom:8px}
.sub{color:#94a3b8;line-height:1.7;font-size:14px;max-width:620px}
.actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:20px}
.btn{
    border:none;text-decoration:none;cursor:pointer;
    display:inline-flex;align-items:center;justify-content:center;gap:8px;
    min-height:50px;padding:0 18px;border-radius:14px;font-size:14px;font-weight:800;
}
.btn-main{background:linear-gradient(135deg,#10b981,#0ea5e9);color:#fff}
.btn-alt{background:rgba(255,255,255,.06);color:#e2e8f0;border:1px solid rgba(255,255,255,.1)}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.panel{padding:22px}
.panel h2{font-size:18px;font-weight:800;margin-bottom:12px}
.list{display:grid;gap:10px}
.item{
    display:flex;gap:12px;align-items:flex-start;
    padding:14px;border-radius:16px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.06)
}
.item i{color:#34d399;margin-top:3px}
.muted{color:#94a3b8;line-height:1.7;font-size:14px}
.badge{
    position:fixed;left:50%;bottom:18px;transform:translateX(-50%);
    display:none;align-items:center;gap:8px;padding:11px 16px;border-radius:999px;
    background:rgba(220,38,38,.96);color:#fff;font-size:13px;font-weight:800;
}
.badge.show{display:inline-flex}
@media (max-width:768px){
    .grid{grid-template-columns:1fr}
    h1{font-size:24px}
    .hero,.panel{padding:18px}
}
</style>
</head>
<body>
<div class="wrap">
    <div class="hero">
        <div class="top">
            <div class="logo"><img src="/hr/employee-app-icon.svg" alt="ESPERANCEH²O"></div>
            <div>
                <h1>Installer ESPERANCEH²O</h1>
                <p class="sub">Installez le portail employé comme une vraie application sur Android ou iPhone. Après installation, l’ouverture se fait en plein écran avec splash screen, mode hors ligne, verrou PIN et synchronisation locale des pointages.</p>
            </div>
        </div>
        <div class="actions">
            <button id="installBtn" class="btn btn-main"><i class="fas fa-download"></i> Installer maintenant</button>
            <a href="/auth/login_unified.php" class="btn btn-alt"><i class="fas fa-right-to-bracket"></i> Ouvrir la connexion</a>
            <a href="/hr/employee_portal.php" class="btn btn-alt"><i class="fas fa-gauge-high"></i> Ouvrir le portail</a>
        </div>
    </div>

    <div class="grid">
        <div class="panel">
            <h2>Android</h2>
            <div class="list">
                <div class="item"><i class="fas fa-check-circle"></i><div><strong>Chrome / Edge / Samsung Internet</strong><div class="muted">Cliquez sur <strong>Installer maintenant</strong> si le bouton apparaît.</div></div></div>
                <div class="item"><i class="fas fa-mobile-screen"></i><div><strong>Si le bouton n’apparaît pas</strong><div class="muted">Utilisez le menu du navigateur puis <strong>Ajouter à l’écran d’accueil</strong>.</div></div></div>
                <div class="item"><i class="fas fa-expand"></i><div><strong>Plein écran</strong><div class="muted">Une fois installée, l’application s’ouvre sans la barre Chrome.</div></div></div>
            </div>
        </div>
        <div class="panel">
            <h2>iPhone</h2>
            <div class="list">
                <div class="item"><i class="fas fa-safari"></i><div><strong>Ouvrez dans Safari</strong><div class="muted">L’installation iPhone fonctionne depuis Safari, pas depuis les navigateurs intégrés.</div></div></div>
                <div class="item"><i class="fas fa-share-nodes"></i><div><strong>Partager</strong><div class="muted">Touchez le bouton <strong>Partager</strong> puis <strong>Sur l’écran d’accueil</strong>.</div></div></div>
                <div class="item"><i class="fas fa-shield-halved"></i><div><strong>PIN et hors ligne</strong><div class="muted">Après installation, vous pouvez activer un PIN local et conserver les derniers pointages vus hors connexion.</div></div></div>
            </div>
        </div>
    </div>
</div>
<?= render_legal_footer(['theme' => 'dark']) ?>
<div class="badge" id="networkBadge"><i class="fas fa-wifi"></i> Mode hors ligne</div>
<script>
let deferredInstallPrompt=null;
const installBtn=document.getElementById('installBtn');

window.addEventListener('beforeinstallprompt', event=>{
    event.preventDefault();
    deferredInstallPrompt=event;
});

installBtn.addEventListener('click', async ()=>{
    if(!deferredInstallPrompt){
        alert('Si le bouton d’installation ne se lance pas automatiquement, utilisez le menu du navigateur puis "Ajouter à l’écran d’accueil".');
        return;
    }
    deferredInstallPrompt.prompt();
    await deferredInstallPrompt.userChoice.catch(()=>null);
    deferredInstallPrompt=null;
});

function updateNetworkBadge(){
    document.getElementById('networkBadge')?.classList.toggle('show', !navigator.onLine);
}

if('serviceWorker' in navigator){
    window.addEventListener('load', ()=>navigator.serviceWorker.register('/hr/employee-sw.js').catch(()=>{}));
}

window.addEventListener('online', updateNetworkBadge);
window.addEventListener('offline', updateNetworkBadge);
updateNetworkBadge();
</script>
</body>
</html>
