<?php require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php'; ?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Installer Stock ESPERANCEH²O</title>
<meta name="theme-color" content="#10b981">
<link rel="manifest" href="/stock/stock_manifest.json">
<link rel="icon" href="/stock/stock-app-icon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/stock/stock-app-icon-192.png">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*{box-sizing:border-box;margin:0;padding:0}body{font-family:Inter,system-ui,sans-serif;min-height:100vh;background:radial-gradient(circle at top,rgba(16,185,129,.16),transparent 24%),linear-gradient(180deg,#0f172a 0%,#111827 100%);color:#e2e8f0;padding:18px}
.wrap{max-width:960px;margin:0 auto}.hero,.panel{background:rgba(15,23,42,.72);border:1px solid rgba(255,255,255,.08);border-radius:24px;box-shadow:0 24px 60px rgba(0,0,0,.3);backdrop-filter:blur(14px)}.hero{padding:26px;margin-bottom:18px}
.top{display:flex;gap:16px;align-items:center;flex-wrap:wrap}.logo{width:88px;height:88px;border-radius:26px;overflow:hidden}.logo img{width:100%;height:100%;object-fit:cover}
h1{font-size:30px;font-weight:800;line-height:1.1;margin-bottom:8px}.sub{color:#94a3b8;line-height:1.7;font-size:14px;max-width:640px}.actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:20px}
.btn{border:none;text-decoration:none;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:50px;padding:0 18px;border-radius:14px;font-size:14px;font-weight:800}.btn-main{background:linear-gradient(135deg,#10b981,#0ea5e9);color:#fff}.btn-alt{background:rgba(255,255,255,.06);color:#e2e8f0;border:1px solid rgba(255,255,255,.1)}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}.panel{padding:22px}.panel h2{font-size:18px;font-weight:800;margin-bottom:12px}.list{display:grid;gap:10px}.item{display:flex;gap:12px;align-items:flex-start;padding:14px;border-radius:16px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.06)}.item i{color:#34d399;margin-top:3px}.muted{color:#94a3b8;line-height:1.7;font-size:14px}
@media(max-width:768px){.grid{grid-template-columns:1fr}h1{font-size:24px}.hero,.panel{padding:18px}}
</style>
<link rel="stylesheet" href="/stock/stock_mobile_overrides.css">
</head>
<body>
<div class="wrap">
  <div class="hero">
    <div class="top">
      <div class="logo"><img src="/stock/stock-app-icon.svg" alt="ESPERANCEH²O"></div>
      <div>
        <h1>Installer Stock ESPERANCEH²O</h1>
        <p class="sub">Version installable Windows, macOS, Linux, Android et iPhone pour consulter rapidement le stock, les alertes et les mouvements comme une application.</p>
      </div>
    </div>
    <div class="actions">
      <button id="installBtn" class="btn btn-main"><i class="fas fa-download"></i> Installer maintenant</button>
      <a href="/stock/stock_tracking.php" class="btn btn-alt"><i class="fas fa-chart-line"></i> Ouvrir le suivi</a>
      <a href="/stock/stock_update_fixed.php" class="btn btn-alt"><i class="fas fa-warehouse"></i> Ouvrir la gestion</a>
    </div>
  </div>
  <div class="grid">
    <div class="panel">
      <h2>Desktop</h2>
      <div class="list">
        <div class="item"><i class="fas fa-desktop"></i><div><strong>Windows / macOS / Linux</strong><div class="muted">Utilisez Chrome ou Edge puis cliquez sur Installer maintenant.</div></div></div>
        <div class="item"><i class="fas fa-window-maximize"></i><div><strong>Mode application</strong><div class="muted">Le système stock s’ouvre comme une vraie app avec fenêtre dédiée.</div></div></div>
      </div>
    </div>
    <div class="panel">
      <h2>Mobile</h2>
      <div class="list">
        <div class="item"><i class="fas fa-mobile-screen"></i><div><strong>Android</strong><div class="muted">Ajoutez à l’écran d’accueil ou installez depuis le prompt.</div></div></div>
        <div class="item"><i class="fas fa-mobile-alt"></i><div><strong>iPhone</strong><div class="muted">Ouvrez dans Safari puis partagez vers l’écran d’accueil.</div></div></div>
      </div>
    </div>
  </div>
</div>
<script>
let deferredInstallPrompt=null;
const btn=document.getElementById('installBtn');
window.addEventListener('beforeinstallprompt',e=>{e.preventDefault();deferredInstallPrompt=e;});
btn.addEventListener('click',async()=>{if(!deferredInstallPrompt){alert('Utilisez Installer ou Ajouter à l’écran d’accueil depuis le navigateur.');return;}deferredInstallPrompt.prompt();await deferredInstallPrompt.userChoice.catch(()=>null);deferredInstallPrompt=null;});
if('serviceWorker' in navigator){window.addEventListener('load',()=>navigator.serviceWorker.register('/stock/stock-sw.js').catch(()=>{}));}
</script>
</body>
</html>
