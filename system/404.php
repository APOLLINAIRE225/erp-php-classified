<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';
require_once dirname(__DIR__) . '/legal/legal_bootstrap.php';

// 404.php - Page d'erreur 404 professionnelle animée

http_response_code(404);

?>

<!doctype html>

<html lang="fr">

<head>

<meta charset="utf-8" />

<meta name="viewport" content="width=device-width,initial-scale=1" />

<title>404 — Page non trouvée · ESPERANCEH20</title>

<meta name="robots" content="noindex, nofollow">

<link rel="icon" href="/logo.jpg" />

<style>

  /* ===== Reset minimal ===== */

  *{box-sizing:border-box;margin:0;padding:0}

  html,body{height:100%}

  body{

    font-family: "Poppins", system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial;

    background: linear-gradient(180deg,#0f1724 0%,#071028 60%,#03040b 100%);

    color:#fff;

    -webkit-font-smoothing:antialiased;

    -moz-osx-font-smoothing:grayscale;

    display:flex;

    align-items:center;

    justify-content:center;

    padding:20px;

  }

  /* ===== Container ===== */

  .notfound-wrap{

    width:100%;

    max-width:1100px;

    background: linear-gradient(180deg, rgba(255,255,255,0.03), rgba(255,255,255,0.02));

    border-radius:18px;

    box-shadow: 0 10px 40px rgba(2,6,23,0.7), inset 0 1px 0 rgba(255,255,255,0.02);

    overflow:hidden;

    display:grid;

    grid-template-columns: 1fr 460px;

    gap:0;

  }

  /* Mobile: single column */

  @media (max-width:900px){

    .notfound-wrap{ grid-template-columns: 1fr; padding:0; border-radius:14px;}

    .side { order:2; border-left: none; border-top: 1px solid rgba(255,255,255,0.03); }

  }

  /* ===== Left: big message ===== */

  .main {

    padding:48px 48px;

    position:relative;

    min-height:380px;

    display:flex;

    flex-direction:column;

    justify-content:center;

    gap:18px;

  }

  .label {

    display:inline-block;

    font-size:0.9rem;

    text-transform:uppercase;

    letter-spacing:1px;

    color: rgba(255,255,255,0.6);

    margin-bottom:6px;

  }

  /* Big 404 - rouge vif with glitch */

  .code {

    font-weight:900;

    font-size:clamp(4rem, 12vw, 8.5rem);

    line-height:0.9;

    color:#ff1f1f; /* rouge vif */

    position:relative;

    display:inline-block;

    text-shadow:

        0 2px 0 rgba(0,0,0,0.6),

        0 10px 40px rgba(255,31,31,0.07);

    transform: translateZ(0);

  }

  /* Glitch layers */

  .code::before,

  .code::after{

    content:attr(data-text);

    position:absolute;

    left:0; top:0;

    width:100%; height:100%;

    overflow:hidden;

    clip-path: inset(0 0 0 0);

    opacity:0.85;

  }

  .code::before{ 

    color:#ff6b6b;

    transform:translate(6px,-6px);

    mix-blend-mode: screen;

    animation: glitch-anim-1 2.8s infinite linear;

  }

  .code::after{

    color:#ff0000;

    transform:translate(-5px,5px);

    mix-blend-mode: multiply;

    animation: glitch-anim-2 2.4s infinite linear;

  }

  @keyframes glitch-anim-1 {

    0% { clip-path: inset(0 0 0 0); transform: translate(6px,-6px) skewX(-2deg); opacity:0.85;}

    10% { clip-path: inset(20% 0 65% 0); transform: translate(-10px,-2px) skewX(3deg); opacity:0.9;}

    20% { clip-path: inset(0 0 30% 0); transform: translate(4px,-6px) skewX(-1deg); opacity:0.85;}

    30% { clip-path: inset(50% 0 10% 0); transform: translate(8px,-10px) skewX(6deg); opacity:0.9;}

    100% { clip-path: inset(0 0 0 0); transform: translate(6px,-6px) skewX(-2deg); }

  }

  @keyframes glitch-anim-2 {

    0% { clip-path: inset(0 0 0 0); transform: translate(-5px,5px) skewX(1deg); opacity:0.8;}

    15% { clip-path: inset(30% 0 40% 0); transform: translate(-12px,10px) skewX(-3deg); opacity:0.9;}

    35% { clip-path: inset(10% 0 30% 0); transform: translate(-4px,3px) skewX(2deg); opacity:0.85;}

    100% { clip-path: inset(0 0 0 0); transform: translate(-5px,5px); }

  }

  .headline {

    font-size: clamp(1.1rem, 2.2vw, 1.6rem);

    font-weight:700;

    color:#fff;

    letter-spacing:0.2px;

  }

  .desc {

    color: rgba(255,255,255,0.8);

    font-size:1rem;

    max-width:56ch;

    line-height:1.6;

  }

  /* Buttons row */

  .actions {

    margin-top:8px;

    display:flex;

    gap:12px;

    flex-wrap:wrap;

  }

  .btn {

    display:inline-flex;

    align-items:center;

    gap:10px;

    background: linear-gradient(90deg,#ff1f1f 0%, #ff6b6b 100%);

    color:white;

    text-decoration:none;

    border:0;

    padding:12px 18px;

    border-radius:12px;

    font-weight:700;

    box-shadow: 0 8px 30px rgba(255,31,31,0.12);

    transition: transform .18s ease, box-shadow .18s ease;

  }

  .btn.secondary {

    background: rgba(255,255,255,0.06);

    color: #fff;

    border: 1px solid rgba(255,255,255,0.06);

    box-shadow:none;

    font-weight:600;

  }

  .btn:hover{ transform: translateY(-4px); box-shadow: 0 18px 40px rgba(255,31,31,0.14); }

  .btn.secondary:hover{ transform: translateY(-3px); }

  .meta {

    margin-top:12px;

    color: rgba(255,255,255,0.6);

    font-size:0.9rem;

  }

  /* ===== Right: illustration / logo / small card ===== */

  .side{

    padding:28px;

    border-left: 1px solid rgba(255,255,255,0.03);

    display:flex;

    flex-direction:column;

    align-items:center;

    justify-content:center;

    gap:18px;

    background:

      radial-gradient(1200px 400px at 10% 10%, rgba(255,31,31,0.035), transparent 8%),

      radial-gradient(800px 250px at 90% 90%, rgba(0,200,255,0.02), transparent 10%),

      transparent;

  }

  .logo-wrap{

    display:flex;

    align-items:center;

    gap:12px;

    width:100%;

  }

  .logo-wrap img{

    width:72px;

    height:72px;

    object-fit:cover;

    border-radius:12px;

    box-shadow: 0 8px 30px rgba(2,6,23,0.6);

    background:linear-gradient(180deg, #fff, #eee);

  }

  .logo-text{

    font-weight:800;

    color:#fff;

    font-size:1.1rem;

    letter-spacing:0.3px;

  }

  .card {

    width:100%;

    max-width:360px;

    background: linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));

    border-radius:12px;

    padding:16px;

    border: 1px solid rgba(255,255,255,0.03);

    box-shadow: 0 10px 30px rgba(0,0,0,0.6);

    text-align:left;

  }

  .card h4{ color:#fff; font-size:1rem; margin-bottom:6px; font-weight:700; }

  .card p{ color: rgba(255,255,255,0.8); font-size:0.95rem; line-height:1.45; }

  /* small helpful links */

  .small-links{

    display:flex;

    width:100%;

    gap:8px;

    margin-top:8px;

    flex-wrap:wrap;

  }

  .small-links a{

    display:inline-block;

    text-decoration:none;

    color: rgba(255,255,255,0.9);

    background: rgba(255,255,255,0.02);

    padding:8px 10px;

    border-radius:8px;

    border:1px solid rgba(255,255,255,0.02);

    font-size:0.9rem;

  }

  /* particles canvas (subtle) */

  #pCanvas{

    position:absolute;

    left:0; top:0;

    width:100%; height:100%;

    z-index:0;

    pointer-events:none;

    opacity:0.55;

    mix-blend-mode: screen;

  }

  /* Accessibility focus */

  a:focus, button:focus { outline: 3px solid rgba(255,31,31,0.3); outline-offset:4px; border-radius:8px; }

  /* tiny responsive tweaks */

  @media (max-width:500px){

    .code{font-size:clamp(3.2rem, 18vw, 6.2rem)}

    .main{padding:28px}

    .side{padding:18px}

    .logo-wrap img{width:56px;height:56px}

  }

</style>

<body>

  <div class="notfound-wrap" role="main" aria-labelledby="nf-head">

    <canvas id="pCanvas" aria-hidden="true"></canvas>

    <div class="main" style="position:relative;z-index:2;">

      <span class="label">Erreur</span>

      <div class="code" id="nf-code" data-text="404">404</div>

      <div class="headline" id="nf-head">Page introuvable</div>

      <p class="desc">

        Désolé — la page que vous recherchez n'existe pas, a été déplacée, ou le lien est incorrect.

        Vérifiez l'URL ou utilisez les boutons ci-dessous pour revenir à une page active.

      </p>

      <div class="actions" aria-label="Actions">

        <a class="btn" href="<?= project_url('dashboard/index.php') ?>" title="Retour à l'accueil" id="btn-home">

          Accueil

        </a>

        <a class="btn secondary" href="<?= project_url('stock/stock_update_fixed.php') ?>" title="Appro" id="btn-contact">

          Approvisionement

        </a>

        <a class="btn secondary" href="javascript:void(0)" id="btn-report" aria-haspopup="dialog" title="Signaler le problème">

          Signaler

        </a>

      </div>

      <div class="meta" aria-hidden="false">

        <strong>Astuce :</strong> appuie sur <kbd>Ctrl</kbd> + <kbd>R</kbd> pour recharger — si le problème persiste, contacte-nous.

      </div>

    </div>

    <aside class="side" aria-label="Informations">

      <div class="logo-wrap">

        <img src="/logo.png" alt="ESPERANCEH20">

        <div>

          <div class="logo-text">ESPERANCE H20</div>

          <div style="font-size:0.85rem;color:rgba(255,255,255,0.7)">Sercice interne</div>

        </div>

      </div>

      <div class="card" role="complementary" aria-labelledby="help-h">

        <h4 id="help-h">Que faire ensuite ?</h4>

        <p>Tu peux retourner à l'accueil, parcourir les services, ou envoyer un message à notre équipe pour signaler un lien cassé.</p>

        <div class="small-links" style="margin-top:10px">

          <a href="<?= project_url('stock/stock_update_fixed.php') ?>">Approvisionement</a>

          <a href="<?= project_url('finance/caisse_complete_enhanced.php') ?>">Caisse</a>

          <a href="<?= project_url('auth/logout.php') ?>">Deconexion</a>

          <a href="<?= project_url('stock/stocks_erp_pro.php') ?>">stock</a>

        </div>

      </div>

      <div style="width:100%;text-align:center;margin-top:12px">

        <small style="color:rgba(255,255,255,0.6)">Si tu penses que c'est une erreur du serveur, note l'heure et contacte support.</small>

      </div>

    </aside>

  </div>

<script>

/* Subtle particle field for background (low-cost, performant) */

(() => {

  const canvas = document.getElementById('pCanvas');

  if(!canvas) return;

  const ctx = canvas.getContext('2d');

  function resize(){

    canvas.width = canvas.clientWidth || window.innerWidth;

    canvas.height = canvas.clientHeight || window.innerHeight;

  }

  window.addEventListener('resize', resize);

  resize();

  const particles = [];

  const COUNT = Math.max(18, Math.min(60, Math.floor((canvas.width*canvas.height)/60000)));

  for(let i=0;i<COUNT;i++){

    particles.push({

      x: Math.random() * canvas.width,

      y: Math.random() * canvas.height,

      r: 0.6 + Math.random()*2.2,

      vx: (Math.random()-0.5)*0.2,

      vy: (Math.random()-0.5)*0.2,

      alpha: 0.15 + Math.random()*0.3

    });

  }

  function tick(){

    ctx.clearRect(0,0,canvas.width,canvas.height);

    for(const p of particles){

      p.x += p.vx;

      p.y += p.vy;

      if(p.x < -10) p.x = canvas.width + 10;

      if(p.x > canvas.width + 10) p.x = -10;

      if(p.y < -10) p.y = canvas.height + 10;

      if(p.y > canvas.height + 10) p.y = -10;

      const g = ctx.createRadialGradient(p.x, p.y, 0, p.x, p.y, p.r*6);

      g.addColorStop(0, 'rgba(255,31,31,'+ (p.alpha*0.9) +')'); // same rouge tint

      g.addColorStop(1, 'rgba(255,31,31,0)');

      ctx.fillStyle = g;

      ctx.beginPath();

      ctx.arc(p.x, p.y, p.r*6, 0, Math.PI*2);

      ctx.fill();

    }

    requestAnimationFrame(tick);

  }

  tick();

})();

/* small UX: open report dialog (basic) */

document.getElementById('btn-report').addEventListener('click', function(){

  const url = window.location.href;

  const subject = encodeURIComponent("Lien cassé sur le site");

  const body = encodeURIComponent("URL rencontrée: " + url + "\n\nDécris brièvement le problème:\n");

  // open user's mail client

  window.location.href = `mailto:contact@tonclinique.tld?subject=${subject}&body=${body}`;

});

/* keyboard shortcut: press H to home, R to reload */

window.addEventListener('keydown', (e) => {

  if(e.key === 'h' || e.key === 'H') window.location.href = '/';

  if(e.key === 'r' || e.key === 'R') location.reload();

});

<?= render_legal_footer(['theme' => 'dark']) ?>

<script>

</body>

</html>
