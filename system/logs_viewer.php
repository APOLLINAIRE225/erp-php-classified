<?php
/**
 * ════════════════════════════════════════════════════════════════
 * LOGS VIEWER — ESPERANCE H2O
 * Temps réel · Apache2 + MySQL · Dark Neon v1.0
 * SSE (Server-Sent Events) — aucune dépendance externe
 * ════════════════════════════════════════════════════════════════
 */

session_start();

/* ── Sécurité : admin seulement ── */
// if (empty($_SESSION['user_id'])) { header('Location: login_unified.php'); exit; }

/* ════════════════════════════════════════
   SSE STREAM — appelé en boucle par le JS
════════════════════════════════════════ */
if (isset($_GET['stream'])) {
    $log   = $_GET['log']   ?? 'apache_error';
    $since = (int)($_GET['since'] ?? 0); // timestamp dernière lecture

    // Chemins des logs
    $logs = [
        'apache_error'  => ['/var/log/apache2/error.log',   '/var/log/httpd/error_log'],
        'apache_access' => ['/var/log/apache2/access.log',  '/var/log/httpd/access_log'],
        'mysql_error'   => ['/var/log/mysql/error.log',     '/var/log/mysql/mysql.err', '/var/log/mysqld.log'],
        'php_error'     => ['/var/log/php/error.log',       '/var/log/php8.2-fpm.log', '/var/log/php8.1-fpm.log','/var/log/php8.0-fpm.log'],
        'syslog'        => ['/var/log/syslog',              '/var/log/messages'],
    ];

    $paths = $logs[$log] ?? $logs['apache_error'];
    $file  = null;
    foreach ($paths as $p) { if (file_exists($p) && is_readable($p)) { $file = $p; break; } }

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    header('Connection: keep-alive');

    if (!$file) {
        echo "data: " . json_encode(['error' => 'Fichier log introuvable ou accès refusé', 'paths' => $paths]) . "\n\n";
        flush(); exit;
    }

    /* Lire les N dernières lignes (tail) */
    $lines = tailFile($file, 120);
    $out   = [];
    foreach ($lines as $line) {
        if (trim($line) === '') continue;
        $out[] = parseLine($line, $log);
    }
    echo "data: " . json_encode(['lines' => $out, 'file' => $file, 'size' => formatBytes(filesize($file))]) . "\n\n";
    flush();
    exit;
}

/* ════════════════════════════════════════
   POLL — nouvelles lignes depuis offset
════════════════════════════════════════ */
if (isset($_GET['poll'])) {
    header('Content-Type: application/json; charset=utf-8');
    $log    = $_GET['log']    ?? 'apache_error';
    $offset = (int)($_GET['offset'] ?? 0);

    $logs = [
        'apache_error'  => ['/var/log/apache2/error.log',  '/var/log/httpd/error_log'],
        'apache_access' => ['/var/log/apache2/access.log', '/var/log/httpd/access_log'],
        'mysql_error'   => ['/var/log/mysql/error.log',    '/var/log/mysql/mysql.err', '/var/log/mysqld.log'],
        'php_error'     => ['/var/log/php/error.log',      '/var/log/php8.2-fpm.log', '/var/log/php8.1-fpm.log','/var/log/php8.0-fpm.log'],
        'syslog'        => ['/var/log/syslog',             '/var/log/messages'],
    ];

    $paths = $logs[$log] ?? $logs['apache_error'];
    $file  = null;
    foreach ($paths as $p) { if (file_exists($p) && is_readable($p)) { $file = $p; break; } }

    if (!$file) { echo json_encode(['lines'=>[],'offset'=>0,'error'=>'Fichier introuvable']); exit; }

    $size = filesize($file);
    if ($offset === 0) { $offset = max(0, $size - 32768); } // démarrer depuis les 32KB derniers

    if ($size <= $offset) { echo json_encode(['lines'=>[],'offset'=>$offset,'size'=>formatBytes($size)]); exit; }

    $fh   = fopen($file, 'r');
    fseek($fh, $offset);
    $raw  = fread($fh, min($size - $offset, 65536));
    $newOffset = ftell($fh);
    fclose($fh);

    $rawLines = explode("\n", $raw);
    // skip first (peut être partielle) si offset > 0 et était déjà dans un log
    if ($offset > 0) array_shift($rawLines);

    $out = [];
    foreach ($rawLines as $line) {
        if (trim($line) === '') continue;
        $out[] = parseLine($line, $log);
    }

    echo json_encode(['lines' => $out, 'offset' => $newOffset, 'file' => $file, 'size' => formatBytes($size)]);
    exit;
}

/* ════════════════════════════════════════
   CLEAR — vider un log (dangereux, admin)
════════════════════════════════════════ */
if (isset($_GET['clear']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    // Désactivé par sécurité en prod — décommenter si besoin
    echo json_encode(['ok' => false, 'msg' => 'Fonction désactivée en production']);
    exit;
}

/* ════════════════════════════════════════
   HELPERS
════════════════════════════════════════ */
function tailFile(string $file, int $n = 100): array {
    $lines = []; $fh = fopen($file, 'r'); if (!$fh) return [];
    fseek($fh, 0, SEEK_END);
    $size = ftell($fh); $buf = ''; $pos = $size;
    while (count($lines) < $n && $pos > 0) {
        $read = min(4096, $pos); $pos -= $read;
        fseek($fh, $pos);
        $buf = fread($fh, $read) . $buf;
        $lines = explode("\n", $buf);
    }
    fclose($fh);
    $lines = array_filter($lines, fn($l) => trim($l) !== '');
    return array_slice(array_values($lines), -$n);
}

function parseLine(string $line, string $type): array {
    $level = 'info';
    $lo    = strtolower($line);

    // Détecter le niveau
    if (preg_match('/\b(emerg|crit|alert)\b/i', $line))          $level = 'crit';
    elseif (preg_match('/\b(error|err|fatal|exception)\b/i', $line)) $level = 'error';
    elseif (preg_match('/\b(warn|warning)\b/i', $line))           $level = 'warn';
    elseif (preg_match('/\b(notice|info|debug)\b/i', $line))      $level = 'info';

    // HTTP status codes
    if ($type === 'apache_access') {
        if (preg_match('/ (5\d\d) /', $line))      $level = 'error';
        elseif (preg_match('/ (4\d\d) /', $line))  $level = 'warn';
        elseif (preg_match('/ (2\d\d|3\d\d) /', $line)) $level = 'ok';
    }

    // Extraire timestamp si présent
    $ts = '';
    if (preg_match('/\[([^\]]{6,30})\]/', $line, $m)) $ts = $m[1];
    elseif (preg_match('/^(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2})/', $line, $m)) $ts = $m[1];

    return ['line' => $line, 'level' => $level, 'ts' => $ts, 'time' => time()];
}

function formatBytes(int $bytes): string {
    if ($bytes >= 1048576) return round($bytes/1048576, 1) . ' MB';
    if ($bytes >= 1024)    return round($bytes/1024, 1)    . ' KB';
    return $bytes . ' B';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Logs Serveur — ESPERANCE H2O</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Courier+Prime:wght@400;700&family=JetBrains+Mono:wght@400;700&family=Playfair+Display:wght@700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{
    --bg:#020609;--surf:#06101a;--card:#0a1825;--card2:#0e2030;
    --bord:rgba(50,190,143,.12);--bord2:rgba(50,190,143,.28);
    --neon:#32be8f;--neon2:#19ffa3;--red:#ff3553;--orange:#ff9140;
    --gold:#ffd060;--cyan:#06b6d4;--blue:#3d8cff;--purple:#a855f7;
    --text:#d8eee4;--text2:#a8c8b8;--muted:#4a7060;
    --fc:'Courier Prime','Courier New',monospace;
    --mono:'JetBrains Mono','Courier New',monospace;
    --fh:'Playfair Display',Georgia,serif;
}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
html,body{height:100%;overflow:hidden;}
body{font-family:var(--fc);font-weight:700;background:var(--bg);color:var(--text);display:flex;flex-direction:column;}

/* BG */
body::before{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;
    background:radial-gradient(ellipse 80% 60% at 0% 0%,rgba(50,190,143,.06) 0%,transparent 55%),
               radial-gradient(ellipse 60% 50% at 100% 100%,rgba(61,140,255,.05) 0%,transparent 55%);}

/* ANIMS */
@keyframes breathe{0%,100%{box-shadow:0 0 14px rgba(50,190,143,.3)}50%{box-shadow:0 0 40px rgba(50,190,143,.75)}}
@keyframes scan{0%{left:-100%}100%{left:110%}}
@keyframes blink{0%,100%{opacity:1}50%{opacity:0}}
@keyframes fadeIn{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:translateY(0)}}
@keyframes spin{to{transform:rotate(360deg)}}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
@keyframes newLine{0%{background:rgba(50,190,143,.12)}100%{background:transparent}}

/* ── TOPBAR ── */
.topbar{
    position:relative;z-index:10;
    display:flex;align-items:center;justify-content:space-between;
    padding:0 18px;height:52px;flex-shrink:0;
    background:rgba(6,16,26,.97);border-bottom:1px solid var(--bord);
    overflow:hidden;
}
.topbar::after{content:'';position:absolute;top:0;left:-60%;width:40%;height:2px;
    background:linear-gradient(90deg,transparent,var(--neon),var(--cyan),transparent);animation:scan 4s linear infinite;}
.tb-brand{display:flex;align-items:center;gap:10px;}
.tb-ico{width:32px;height:32px;border-radius:9px;background:linear-gradient(135deg,var(--neon),var(--cyan));
    display:flex;align-items:center;justify-content:center;font-size:16px;color:var(--bg);
    box-shadow:0 0 14px rgba(50,190,143,.4);animation:breathe 3.5s ease-in-out infinite;}
.tb-title{font-family:var(--fh);font-size:15px;font-weight:900;color:var(--text);}
.tb-title span{color:var(--neon);}
.tb-right{display:flex;align-items:center;gap:10px;}
.live-badge{display:flex;align-items:center;gap:6px;font-size:9px;font-weight:900;
    color:var(--neon);letter-spacing:1.5px;text-transform:uppercase;}
.live-dot{width:7px;height:7px;border-radius:50%;background:var(--neon);
    box-shadow:0 0 7px var(--neon);animation:blink 1s infinite;}
.live-dot.paused{background:var(--orange);box-shadow:0 0 7px var(--orange);animation:none;}
.tb-btn{padding:6px 12px;border-radius:8px;border:1.5px solid var(--bord);background:none;
    font-family:var(--fc);font-size:9px;font-weight:900;color:var(--muted);
    cursor:pointer;text-transform:uppercase;letter-spacing:.8px;transition:all .22s;
    display:flex;align-items:center;gap:5px;}
.tb-btn:hover{color:var(--text);border-color:var(--bord2);}
.tb-btn.active{background:rgba(50,190,143,.1);border-color:rgba(50,190,143,.3);color:var(--neon);}
.tb-btn.danger{border-color:rgba(255,53,83,.25);}
.tb-btn.danger:hover{background:rgba(255,53,83,.1);color:var(--red);border-color:var(--red);}

/* ── LAYOUT ── */
.layout{display:flex;flex:1;overflow:hidden;position:relative;z-index:1;}

/* ── SIDEBAR ── */
.sidebar{
    width:220px;flex-shrink:0;
    background:rgba(6,16,26,.98);border-right:1px solid var(--bord);
    display:flex;flex-direction:column;overflow:hidden;
}
.sb-section{padding:10px 12px 6px;font-size:9px;font-weight:900;color:var(--muted);
    text-transform:uppercase;letter-spacing:1.5px;border-bottom:1px solid rgba(255,255,255,.04);}
.log-btn{
    display:flex;align-items:center;gap:10px;padding:11px 14px;cursor:pointer;
    border:none;background:none;width:100%;text-align:left;
    font-family:var(--fc);font-weight:700;font-size:11px;color:var(--text2);
    border-left:3px solid transparent;transition:all .22s;border-bottom:1px solid rgba(255,255,255,.025);
}
.log-btn:hover{background:rgba(50,190,143,.05);color:var(--text);}
.log-btn.on{background:rgba(50,190,143,.08);border-left-color:var(--neon);color:#fff;}
.log-btn i{font-size:14px;flex-shrink:0;width:16px;text-align:center;}
.lb-info{flex:1;min-width:0;}
.lb-name{font-weight:900;font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.lb-path{font-size:9px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px;}
.lb-cnt{min-width:20px;height:18px;border-radius:9px;background:rgba(255,53,83,.18);color:var(--red);
    font-size:9px;font-weight:900;display:flex;align-items:center;justify-content:center;padding:0 5px;}
.lb-cnt.ok{background:rgba(50,190,143,.12);color:var(--neon);}

/* STATS in sidebar */
.sb-stats{padding:12px;border-top:1px solid var(--bord);margin-top:auto;}
.stat-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:7px;}
.stat-lbl{font-size:9px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;}
.stat-val{font-size:11px;font-weight:900;color:var(--text);}
.stat-val.green{color:var(--neon);}
.stat-val.red{color:var(--red);}
.stat-val.gold{color:var(--gold);}

/* ── MAIN LOG AREA ── */
.main{flex:1;display:flex;flex-direction:column;overflow:hidden;}

/* LOG HEADER */
.log-header{
    display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;
    padding:10px 16px;border-bottom:1px solid var(--bord);
    background:rgba(6,16,26,.95);flex-shrink:0;
}
.lh-title{font-family:var(--fh);font-size:16px;font-weight:900;color:var(--text);display:flex;align-items:center;gap:8px;}
.lh-title i{color:var(--neon);}
.lh-file{font-size:10px;font-weight:700;color:var(--muted);font-family:var(--mono);}
.lh-controls{display:flex;align-items:center;gap:7px;flex-wrap:wrap;}

/* FILTER TABS */
.filter-tabs{display:flex;gap:4px;padding:8px 14px;border-bottom:1px solid rgba(255,255,255,.04);flex-shrink:0;flex-wrap:wrap;}
.ftab{padding:5px 12px;border-radius:8px;border:1.5px solid var(--bord);background:none;cursor:pointer;
    font-family:var(--fc);font-size:9px;font-weight:900;color:var(--muted);text-transform:uppercase;
    letter-spacing:.7px;transition:all .22s;display:flex;align-items:center;gap:5px;}
.ftab:hover{color:var(--text2);border-color:var(--bord2);}
.ftab.on{background:rgba(50,190,143,.1);color:var(--neon);border-color:rgba(50,190,143,.35);}
.ftab.on-e{background:rgba(255,53,83,.08);color:var(--red);border-color:rgba(255,53,83,.3);}
.ftab.on-w{background:rgba(255,208,96,.08);color:var(--gold);border-color:rgba(255,208,96,.3);}

/* SEARCH */
.search-bar{display:flex;align-items:center;gap:8px;padding:8px 14px;border-bottom:1px solid rgba(255,255,255,.04);flex-shrink:0;}
.search-wrap{flex:1;position:relative;}
.search-wrap i{position:absolute;left:11px;top:50%;transform:translateY(-50%);font-size:12px;color:var(--muted);}
.search-wrap input{
    width:100%;padding:8px 11px 8px 32px;background:rgba(0,0,0,.4);
    border:1.5px solid var(--bord);border-radius:9px;
    color:var(--text);font-family:var(--mono);font-size:12px;font-weight:400;
    transition:border-color .22s;
}
.search-wrap input:focus{outline:none;border-color:var(--neon);}
.search-wrap input::placeholder{color:var(--muted);}
.search-cnt{font-size:10px;font-weight:900;color:var(--muted);white-space:nowrap;}

/* LOG TERMINAL */
.terminal{
    flex:1;overflow-y:auto;padding:8px 0;
    font-family:var(--mono);font-size:12px;font-weight:400;
    line-height:1.65;
    background:var(--bg);
}
.terminal::-webkit-scrollbar{width:6px;}
.terminal::-webkit-scrollbar-track{background:var(--surf);}
.terminal::-webkit-scrollbar-thumb{background:rgba(50,190,143,.3);border-radius:4px;}

.log-line{
    display:flex;align-items:flex-start;gap:0;padding:2px 14px;
    border-left:3px solid transparent;
    transition:background .15s;
    cursor:default;
}
.log-line:hover{background:rgba(255,255,255,.03);}
.log-line.new{animation:newLine 1.5s ease forwards;}
.log-line.level-error,.log-line.level-crit{border-left-color:var(--red);}
.log-line.level-warn{border-left-color:var(--gold);}
.log-line.level-ok{border-left-color:var(--neon);}
.log-line.level-info{border-left-color:transparent;}
.log-line.hidden{display:none;}

.ln-num{color:rgba(74,112,96,.5);font-size:10px;min-width:40px;padding-top:1px;user-select:none;flex-shrink:0;text-align:right;padding-right:10px;}
.ln-lvl{flex-shrink:0;width:14px;height:14px;border-radius:3px;margin-top:2px;margin-right:8px;
    display:flex;align-items:center;justify-content:center;font-size:8px;}
.level-error .ln-lvl,.level-crit .ln-lvl{background:rgba(255,53,83,.2);color:var(--red);}
.level-warn  .ln-lvl{background:rgba(255,208,96,.15);color:var(--gold);}
.level-ok    .ln-lvl{background:rgba(50,190,143,.15);color:var(--neon);}
.level-info  .ln-lvl{background:rgba(255,255,255,.05);color:var(--muted);}

.ln-ts{color:var(--muted);font-size:10px;flex-shrink:0;padding-right:8px;padding-top:1px;min-width:0;}
.ln-text{flex:1;color:var(--text2);word-break:break-all;white-space:pre-wrap;}
.level-error .ln-text,.level-crit .ln-text{color:#ffb3be;}
.level-warn  .ln-text{color:#ffe8a0;}
.level-ok    .ln-text{color:#a8f0d8;}

/* Highlight search */
.hl{background:rgba(255,208,96,.25);color:var(--gold);border-radius:2px;padding:0 1px;}

/* Empty / loading */
.t-center{display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;gap:14px;color:var(--muted);}
.t-center i{font-size:48px;opacity:.08;}
.t-center p{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;}

/* STATUSBAR */
.statusbar{
    display:flex;align-items:center;gap:14px;padding:6px 16px;flex-shrink:0;
    background:rgba(6,16,26,.98);border-top:1px solid var(--bord);
    font-size:9px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;
    flex-wrap:wrap;
}
.sb-item{display:flex;align-items:center;gap:5px;}
.sb-item i{font-size:9px;}
.sb-sep{width:1px;height:12px;background:var(--bord);flex-shrink:0;}
.sb-errors{color:var(--red);}
.sb-warns{color:var(--gold);}

/* TOAST */
.toasts{position:fixed;top:60px;right:16px;z-index:999;display:flex;flex-direction:column;gap:6px;}
.toast{background:var(--card2);border:1px solid var(--bord2);border-radius:10px;
    padding:9px 13px;display:flex;align-items:center;gap:9px;
    box-shadow:0 8px 28px rgba(0,0,0,.6);font-size:10px;font-weight:900;
    animation:fadeIn .3s ease;}

/* Mobile */
@media(max-width:700px){
    .sidebar{display:none;}
    .lb-path{display:none;}
    .ln-num{display:none;}
    .ln-ts{display:none;}
}
@media(max-width:480px){
    .log-header{padding:8px 10px;}
    .filter-tabs{padding:6px 10px;}
    .search-bar{padding:6px 10px;}
    .terminal{font-size:11px;}
    .log-line{padding:2px 8px;}
}
</style>
</head>
<body>

<!-- TOPBAR -->
<div class="topbar">
  <div class="tb-brand">
    <div class="tb-ico">⚙️</div>
    <div class="tb-title"><span>ESPERANCE</span> H2O — Logs Serveur</div>
  </div>
  <div class="tb-right">
    <div class="live-badge"><div class="live-dot" id="live-dot"></div><span id="live-status">LIVE</span></div>
    <button class="tb-btn" onclick="togglePause()" id="btn-pause"><i class="fas fa-pause"></i> Pause</button>
    <button class="tb-btn" onclick="scrollBottom()"><i class="fas fa-arrow-down"></i> Bas</button>
    <button class="tb-btn" onclick="clearScreen()"><i class="fas fa-trash-alt"></i> Vider</button>
    <a href="<?= project_url('dashboard/index.php') ?>" class="tb-btn"><i class="fas fa-home"></i> Retour</a>
  </div>
</div>

<!-- LAYOUT -->
<div class="layout">

  <!-- SIDEBAR -->
  <div class="sidebar">
    <div class="sb-section">Journaux Apache</div>
    <button class="log-btn on" id="lb-apache_error" onclick="switchLog('apache_error')">
      <i class="fas fa-server" style="color:var(--red)"></i>
      <div class="lb-info"><div class="lb-name">Error Log</div><div class="lb-path">/var/log/apache2/error.log</div></div>
      <div class="lb-cnt" id="cnt-apache_error">0</div>
    </button>
    <button class="log-btn" id="lb-apache_access" onclick="switchLog('apache_access')">
      <i class="fas fa-globe" style="color:var(--neon)"></i>
      <div class="lb-info"><div class="lb-name">Access Log</div><div class="lb-path">/var/log/apache2/access.log</div></div>
      <div class="lb-cnt ok" id="cnt-apache_access">0</div>
    </button>

    <div class="sb-section">MySQL</div>
    <button class="log-btn" id="lb-mysql_error" onclick="switchLog('mysql_error')">
      <i class="fas fa-database" style="color:var(--cyan)"></i>
      <div class="lb-info"><div class="lb-name">MySQL Error</div><div class="lb-path">/var/log/mysql/error.log</div></div>
      <div class="lb-cnt" id="cnt-mysql_error">0</div>
    </button>

    <div class="sb-section">Système</div>
    <button class="log-btn" id="lb-php_error" onclick="switchLog('php_error')">
      <i class="fas fa-code" style="color:var(--purple)"></i>
      <div class="lb-info"><div class="lb-name">PHP Errors</div><div class="lb-path">/var/log/php/error.log</div></div>
      <div class="lb-cnt" id="cnt-php_error">0</div>
    </button>
    <button class="log-btn" id="lb-syslog" onclick="switchLog('syslog')">
      <i class="fas fa-terminal" style="color:var(--gold)"></i>
      <div class="lb-info"><div class="lb-name">Syslog</div><div class="lb-path">/var/log/syslog</div></div>
      <div class="lb-cnt ok" id="cnt-syslog">0</div>
    </button>

    <!-- Stats -->
    <div class="sb-stats">
      <div class="stat-row"><span class="stat-lbl">Lignes totales</span><span class="stat-val" id="st-total">—</span></div>
      <div class="stat-row"><span class="stat-lbl">Erreurs</span><span class="stat-val red" id="st-errors">0</span></div>
      <div class="stat-row"><span class="stat-lbl">Warnings</span><span class="stat-val gold" id="st-warns">0</span></div>
      <div class="stat-row"><span class="stat-lbl">Taille fichier</span><span class="stat-val green" id="st-size">—</span></div>
      <div class="stat-row"><span class="stat-lbl">Intervalle</span><span class="stat-val green">2s</span></div>
    </div>
  </div>

  <!-- MAIN -->
  <div class="main">

    <!-- LOG HEADER -->
    <div class="log-header">
      <div>
        <div class="lh-title" id="lh-title"><i class="fas fa-server"></i> Apache Error Log</div>
        <div class="lh-file" id="lh-file">Chargement…</div>
      </div>
      <div class="lh-controls">
        <button class="tb-btn" onclick="copyAll()"><i class="fas fa-copy"></i> Copier tout</button>
        <button class="tb-btn" onclick="exportTxt()"><i class="fas fa-download"></i> Exporter</button>
      </div>
    </div>

    <!-- FILTER TABS -->
    <div class="filter-tabs">
      <button class="ftab on" data-f="all" onclick="setFilter('all',this)"><i class="fas fa-list"></i> Tout <span id="fc-all">0</span></button>
      <button class="ftab" data-f="error" onclick="setFilter('error',this)"><i class="fas fa-times-circle"></i> Erreurs <span id="fc-error">0</span></button>
      <button class="ftab" data-f="warn"  onclick="setFilter('warn',this)"><i class="fas fa-exclamation-triangle"></i> Warnings <span id="fc-warn">0</span></button>
      <button class="ftab" data-f="ok"    onclick="setFilter('ok',this)"><i class="fas fa-check-circle"></i> OK <span id="fc-ok">0</span></button>
      <button class="ftab" data-f="info"  onclick="setFilter('info',this)"><i class="fas fa-info-circle"></i> Info <span id="fc-info">0</span></button>
    </div>

    <!-- SEARCH -->
    <div class="search-bar">
      <div class="search-wrap">
        <i class="fas fa-search"></i>
        <input type="text" id="search-input" placeholder="Filtrer les logs… (regex supporté)" oninput="applySearch()">
      </div>
      <div class="search-cnt" id="search-cnt"></div>
    </div>

    <!-- TERMINAL -->
    <div class="terminal" id="terminal">
      <div class="t-center"><i class="fas fa-terminal"></i><p>Chargement des logs…</p></div>
    </div>

    <!-- STATUSBAR -->
    <div class="statusbar">
      <div class="sb-item"><i class="fas fa-circle" style="color:var(--neon)"></i><span id="sb-log">apache_error</span></div>
      <div class="sb-sep"></div>
      <div class="sb-item sb-errors"><i class="fas fa-times-circle"></i><span id="sb-errors">0 erreurs</span></div>
      <div class="sb-sep"></div>
      <div class="sb-item sb-warns"><i class="fas fa-exclamation-triangle"></i><span id="sb-warns">0 warnings</span></div>
      <div class="sb-sep"></div>
      <div class="sb-item"><i class="fas fa-file-alt"></i><span id="sb-size">—</span></div>
      <div class="sb-sep"></div>
      <div class="sb-item"><i class="fas fa-clock"></i><span id="sb-time">—</span></div>
    </div>

  </div><!-- /main -->
</div><!-- /layout -->

<div class="toasts" id="toasts"></div>

<script>
/* ══════════════════════════════════════════════
   ÉTAT GLOBAL
══════════════════════════════════════════════ */
var currentLog   = 'apache_error';
var currentFilter= 'all';
var searchQuery  = '';
var isPaused     = false;
var autoScroll   = true;
var pollOffset   = 0;
var pollInterval = null;
var allLines     = [];   // {raw, level, ts, n}
var lineCounter  = 0;
var errorCount   = 0;
var warnCount    = 0;

var LOG_LABELS = {
    apache_error:  { title: 'Apache Error Log',  icon: 'fa-server',   color: 'var(--red)' },
    apache_access: { title: 'Apache Access Log',  icon: 'fa-globe',    color: 'var(--neon)' },
    mysql_error:   { title: 'MySQL Error Log',    icon: 'fa-database', color: 'var(--cyan)' },
    php_error:     { title: 'PHP Error Log',      icon: 'fa-code',     color: 'var(--purple)' },
    syslog:        { title: 'Syslog Système',     icon: 'fa-terminal', color: 'var(--gold)' },
};

/* ══════════════════════════════════════════════
   SWITCH LOG
══════════════════════════════════════════════ */
function switchLog(log) {
    currentLog  = log;
    pollOffset  = 0;
    allLines    = [];
    lineCounter = 0;
    errorCount  = 0;
    warnCount   = 0;

    document.querySelectorAll('.log-btn').forEach(function(b){ b.classList.remove('on'); });
    var lb = document.getElementById('lb-' + log);
    if (lb) lb.classList.add('on');

    var cfg = LOG_LABELS[log] || { title: log, icon: 'fa-file-alt', color: 'var(--neon)' };
    document.getElementById('lh-title').innerHTML = '<i class="fas ' + cfg.icon + '" style="color:' + cfg.color + '"></i> ' + cfg.title;
    document.getElementById('sb-log').textContent = log;
    document.getElementById('lh-file').textContent = 'Chargement…';

    var term = document.getElementById('terminal');
    term.innerHTML = '<div class="t-center"><i class="fas fa-spinner" style="animation:spin 1s linear infinite;opacity:.3;font-size:36px"></i><p>Chargement…</p></div>';

    updateCounts();
    loadLog();
    clearInterval(pollInterval);
    pollInterval = setInterval(pollNew, 2000);
}

/* ══════════════════════════════════════════════
   CHARGEMENT INITIAL (120 dernières lignes)
══════════════════════════════════════════════ */
function loadLog() {
    fetch(window.location.pathname + '?poll=1&log=' + currentLog + '&offset=0')
        .then(function(r){ return r.json(); })
        .then(function(data){
            if (data.error) { showError(data.error); return; }
            pollOffset = data.offset;
            if (data.file) {
                document.getElementById('lh-file').textContent = data.file;
                document.getElementById('st-size').textContent = data.size || '—';
                document.getElementById('sb-size').textContent = data.size || '—';
            }
            var lines = data.lines || [];
            if (!lines.length) {
                document.getElementById('terminal').innerHTML =
                    '<div class="t-center"><i class="fas fa-file-alt"></i><p>Log vide ou aucun accès</p></div>';
                return;
            }
            document.getElementById('terminal').innerHTML = '';
            lines.forEach(function(l){ appendLine(l, false); });
            updateCounts();
            scrollBottom();
        })
        .catch(function(e){ showError('Erreur réseau: ' + e.message); });
}

/* ══════════════════════════════════════════════
   POLL — nouvelles lignes
══════════════════════════════════════════════ */
function pollNew() {
    if (isPaused) return;
    fetch(window.location.pathname + '?poll=1&log=' + currentLog + '&offset=' + pollOffset)
        .then(function(r){ return r.json(); })
        .then(function(data){
            if (!data) return;
            if (data.offset) pollOffset = data.offset;
            if (data.size) {
                document.getElementById('st-size').textContent = data.size;
                document.getElementById('sb-size').textContent = data.size;
            }
            var lines = data.lines || [];
            if (lines.length) {
                lines.forEach(function(l){ appendLine(l, true); });
                updateCounts();
                if (autoScroll) scrollBottom();
                // Badge notification
                var cnt = document.getElementById('cnt-' + currentLog);
                if (cnt) { cnt.textContent = lines.length; setTimeout(function(){ cnt.textContent = '0'; }, 2000); }
            }
            // Update time
            document.getElementById('sb-time').textContent = new Date().toLocaleTimeString('fr-FR');
        })
        .catch(function(){});
}

/* ══════════════════════════════════════════════
   APPEND LINE
══════════════════════════════════════════════ */
function appendLine(l, isNew) {
    lineCounter++;
    if (l.level === 'error' || l.level === 'crit') errorCount++;
    if (l.level === 'warn')  warnCount++;

    var obj = { raw: l.line, level: l.level, ts: l.ts, n: lineCounter };
    allLines.push(obj);
    // Limite mémoire
    if (allLines.length > 5000) allLines.shift();

    var el = buildLineEl(obj, isNew);
    applyVisibility(el, obj);
    document.getElementById('terminal').appendChild(el);
    // Limite DOM
    var term = document.getElementById('terminal');
    while (term.children.length > 3000) {
        if (term.children[0].classList.contains('t-center')) break;
        term.removeChild(term.children[0]);
    }
}

function buildLineEl(obj, isNew) {
    var div = document.createElement('div');
    div.className = 'log-line level-' + obj.level + (isNew ? ' new' : '');
    div.dataset.level = obj.level;
    div.dataset.raw   = obj.raw;

    var lvlIco = { error:'×', crit:'!', warn:'▲', ok:'✓', info:'·' }[obj.level] || '·';

    var tsHtml = obj.ts ? '<span class="ln-ts">' + escHtml(obj.ts.substring(0,19)) + '</span>' : '';
    var rawTxt = highlightSearch(escHtml(obj.raw));

    div.innerHTML =
        '<span class="ln-num">' + obj.n + '</span>' +
        '<span class="ln-lvl">' + lvlIco + '</span>' +
        tsHtml +
        '<span class="ln-text">' + rawTxt + '</span>';

    return div;
}

/* ══════════════════════════════════════════════
   FILTER & SEARCH
══════════════════════════════════════════════ */
function setFilter(f, btn) {
    currentFilter = f;
    document.querySelectorAll('.ftab').forEach(function(b){ b.classList.remove('on','on-e','on-w'); });
    if (f === 'error') btn.classList.add('on-e');
    else if (f === 'warn') btn.classList.add('on-w');
    else btn.classList.add('on');
    applyFilters();
}

function applySearch() {
    searchQuery = document.getElementById('search-input').value;
    applyFilters();
}

function applyFilters() {
    var term = document.getElementById('terminal');
    var nodes = term.querySelectorAll('.log-line');
    var visible = 0;
    var regex = null;
    if (searchQuery) {
        try { regex = new RegExp(searchQuery, 'gi'); } catch(e) { regex = new RegExp(escRegex(searchQuery), 'gi'); }
    }
    nodes.forEach(function(el) {
        applyVisibility(el, { level: el.dataset.level, raw: el.dataset.raw }, regex);
        if (!el.classList.contains('hidden')) visible++;
        // Re-highlight
        var span = el.querySelector('.ln-text');
        if (span) span.innerHTML = highlightSearch(escHtml(el.dataset.raw), regex);
    });
    document.getElementById('search-cnt').textContent = searchQuery ? visible + ' résultats' : '';
}

function applyVisibility(el, obj, regex) {
    regex = regex || (searchQuery ? tryRegex(searchQuery) : null);
    var show = true;
    if (currentFilter !== 'all') {
        if (currentFilter === 'error' && obj.level !== 'error' && obj.level !== 'crit') show = false;
        else if (currentFilter !== 'error' && obj.level !== currentFilter) show = false;
    }
    if (show && regex) {
        show = regex.test(obj.raw);
        regex.lastIndex = 0;
    }
    el.classList.toggle('hidden', !show);
}

function tryRegex(s) {
    try { return new RegExp(s, 'gi'); } catch(e) { return new RegExp(escRegex(s), 'gi'); }
}

function highlightSearch(html, regex) {
    if (!searchQuery) return html;
    regex = regex || tryRegex(searchQuery);
    return html.replace(regex, function(m){ regex.lastIndex = 0; return '<span class="hl">' + m + '</span>'; });
}

/* ══════════════════════════════════════════════
   UPDATE COUNTERS
══════════════════════════════════════════════ */
function updateCounts() {
    var total = allLines.length;
    var fcAll   = allLines.length;
    var fcErr   = allLines.filter(function(l){ return l.level==='error'||l.level==='crit'; }).length;
    var fcWarn  = allLines.filter(function(l){ return l.level==='warn'; }).length;
    var fcOk    = allLines.filter(function(l){ return l.level==='ok'; }).length;
    var fcInfo  = allLines.filter(function(l){ return l.level==='info'; }).length;

    setText('fc-all',   fcAll);
    setText('fc-error', fcErr);
    setText('fc-warn',  fcWarn);
    setText('fc-ok',    fcOk);
    setText('fc-info',  fcInfo);
    setText('st-total', total);
    setText('st-errors', errorCount);
    setText('st-warns',  warnCount);
    setText('sb-errors', errorCount + ' erreur' + (errorCount!==1?'s':''));
    setText('sb-warns',  warnCount  + ' warning' + (warnCount!==1?'s':''));
}

function setText(id, v) { var el = document.getElementById(id); if (el) el.textContent = v; }

/* ══════════════════════════════════════════════
   ACTIONS
══════════════════════════════════════════════ */
function togglePause() {
    isPaused = !isPaused;
    var dot = document.getElementById('live-dot');
    var sts = document.getElementById('live-status');
    var btn = document.getElementById('btn-pause');
    dot.classList.toggle('paused', isPaused);
    sts.textContent = isPaused ? 'PAUSÉ' : 'LIVE';
    btn.innerHTML   = isPaused ? '<i class="fas fa-play"></i> Reprendre' : '<i class="fas fa-pause"></i> Pause';
    if (!isPaused) pollNew();
}

function scrollBottom() {
    var t = document.getElementById('terminal');
    t.scrollTop = t.scrollHeight;
    autoScroll = true;
}

function clearScreen() {
    document.getElementById('terminal').innerHTML = '';
    allLines    = [];
    lineCounter = 0;
    errorCount  = 0;
    warnCount   = 0;
    updateCounts();
    toast('Écran vidé', '🗑️');
}

function copyAll() {
    var text = allLines.map(function(l){ return l.raw; }).join('\n');
    navigator.clipboard.writeText(text).then(function(){ toast('Copié !', '📋'); });
}

function exportTxt() {
    var text = allLines.map(function(l){ return l.raw; }).join('\n');
    var blob = new Blob([text], { type: 'text/plain' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = currentLog + '_' + new Date().toISOString().slice(0,19).replace(/:/g,'-') + '.log';
    a.click();
    toast('Export téléchargé', '💾');
}

function showError(msg) {
    document.getElementById('terminal').innerHTML =
        '<div class="t-center"><i class="fas fa-exclamation-triangle" style="color:var(--red);opacity:.5"></i>' +
        '<p>' + escHtml(msg) + '</p>' +
        '<p style="font-size:9px;margin-top:6px;color:var(--muted)">Vérifiez les permissions : sudo chmod a+r /var/log/apache2/*.log</p>' +
        '</div>';
}

/* ── Auto-scroll detection ── */
document.getElementById('terminal').addEventListener('scroll', function() {
    var t = this;
    autoScroll = (t.scrollHeight - t.scrollTop - t.clientHeight) < 40;
});

/* ══════════════════════════════════════════════
   TOAST
══════════════════════════════════════════════ */
function toast(msg, ico) {
    var el = document.createElement('div');
    el.className = 'toast';
    el.innerHTML = '<span style="font-size:16px">' + (ico||'ℹ️') + '</span><span style="font-size:11px;font-weight:900">' + msg + '</span>';
    document.getElementById('toasts').prepend(el);
    setTimeout(function(){ el.remove(); }, 3000);
}

/* ══════════════════════════════════════════════
   UTILS
══════════════════════════════════════════════ */
function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
function escRegex(s) {
    return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/* ══════════════════════════════════════════════
   KEYBOARD SHORTCUTS
══════════════════════════════════════════════ */
document.addEventListener('keydown', function(e) {
    if (e.ctrlKey || e.metaKey) {
        if (e.key === 'f') { e.preventDefault(); document.getElementById('search-input').focus(); }
        if (e.key === 'End') { e.preventDefault(); scrollBottom(); }
        if (e.key === ' ') { e.preventDefault(); togglePause(); }
    }
    if (e.key === 'Escape') { document.getElementById('search-input').value=''; applySearch(); }
});

/* ══════════════════════════════════════════════
   INIT
══════════════════════════════════════════════ */
switchLog('apache_error');
document.getElementById('sb-time').textContent = new Date().toLocaleTimeString('fr-FR');
</script>
</body>
</html>
