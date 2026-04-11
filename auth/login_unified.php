<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';
require_once dirname(__DIR__) . '/legal/legal_bootstrap.php';

session_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once APP_ROOT . '/app/core/DB.php';
use App\Core\DB;

$pdo = DB::getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

function ensureClientAuthSchema(PDO $pdo): void {
    $existing = [];
    try {
        $st = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clients'");
        $existing = $st ? $st->fetchAll(PDO::FETCH_COLUMN) : [];
    } catch (Throwable $e) {
        $existing = [];
    }

    if (!in_array('email', $existing, true)) {
        $pdo->exec("ALTER TABLE clients ADD COLUMN email VARCHAR(190) NULL AFTER name");
    }
    if (!in_array('password_hash', $existing, true)) {
        $after = in_array('email', $existing, true) ? 'email' : 'phone';
        $pdo->exec("ALTER TABLE clients ADD COLUMN password_hash VARCHAR(255) NULL AFTER {$after}");
    }
}
ensureClientAuthSchema($pdo);

/* ── Already logged in? Redirect immediately ── */
if (!empty($_SESSION['user_id']))     { header('Location: ' . project_url('dashboard/index.php')); exit; }
if (!empty($_SESSION['employee_id'])) { header('Location: ' . project_url('hr/employee_portal.php')); exit; }
if (!empty($_SESSION['client_id']))   { header('Location: ' . project_url('orders/commande_mobile.php')); exit; }

function cl(string $v, int $max = 255): string {
    return mb_substr(trim(strip_tags($v)), 0, $max);
}

function normalize_phone(string $value): string {
    return preg_replace('/\D+/', '', $value) ?? '';
}

function normalize_person_name(string $value): string {
    $value = trim(mb_strtolower($value, 'UTF-8'));
    $map = [
        'à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a',
        'ç'=>'c',
        'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
        'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i',
        'ñ'=>'n',
        'ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o',
        'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u',
        'ý'=>'y','ÿ'=>'y',
        'œ'=>'oe','æ'=>'ae'
    ];
    $value = strtr($value, $map);
    $value = preg_replace("/[^a-z0-9]+/u", ' ', $value) ?? $value;
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return trim($value);
}

function phones_match(string $inputPhone, string $storedPhone): bool {
    $a = normalize_phone($inputPhone);
    $b = normalize_phone($storedPhone);
    if ($a === '' || $b === '') {
        return false;
    }
    if ($a === $b) {
        return true;
    }
    if (strlen($a) >= 8 && str_ends_with($b, $a)) {
        return true;
    }
    if (strlen($b) >= 8 && str_ends_with($a, $b)) {
        return true;
    }
    return false;
}

/* ── AJAX: load cities ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    if ($_POST['ajax'] === 'cities') {
        $coid = (int)($_POST['company_id'] ?? 0);
        if (!$coid) { echo '{"cities":[]}'; exit; }
        $st = $pdo->prepare("SELECT id, name FROM cities WHERE company_id = ? ORDER BY name");
        $st->execute([$coid]);
        echo json_encode(['cities' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }
    exit;
}

/* ══════════════════════════════════════════════
   RATE LIMITING — max 10 attempts / IP / 15 min
══════════════════════════════════════════════ */
function checkRateLimit(PDO $pdo, string $ip): bool {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            ip VARCHAR(45) NOT NULL,
            attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ip_time (ip, attempted_at)
        )");
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE ip=? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
        );
        $st->execute([$ip]);
        return (int)$st->fetchColumn() < 10;
    } catch (Exception $e) { return true; }
}

function logAttempt(PDO $pdo, string $ip): void {
    try {
        $pdo->prepare("INSERT INTO login_attempts (ip) VALUES (?)")->execute([$ip]);
        $pdo->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    } catch (Exception $e) {}
}

$error         = '';
$show_register = false;
$action        = cl($_POST['action'] ?? '', 30);
$client_ip     = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0')[0];

/* ══════════════════════════════════════════════
   UNIFIED AUTO-DETECT LOGIN
   identifier  → username OR employee_code OR phone
   secret      → password (admin/employee) OR full name (client)
══════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'unified_login') {

    $identifier = cl($_POST['identifier'] ?? '', 150);
    $secret     = trim($_POST['secret']   ?? '');

    if (!$identifier || !$secret) {
        $error = 'Veuillez remplir tous les champs.';
    } elseif (!checkRateLimit($pdo, $client_ip)) {
        $error = 'Trop de tentatives. Réessayez dans 15 minutes.';
    } else {
        $found = false;

        /* 1 ── ADMIN */
        $st = $pdo->prepare("SELECT id,username,password,role FROM users WHERE username=? LIMIT 1");
        $st->execute([$identifier]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && password_verify($secret, $row['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']      = (int)$row['id'];
            $_SESSION['username']     = $row['username'];
            $_SESSION['role']         = $row['role'];
            $_SESSION['account_type'] = 'admin';
            $found = true;
            header('Location: ' . project_url('dashboard/index.php')); exit;
        }

        /* 2 ── EMPLOYEE */
        if (!$found) {
            $st = $pdo->prepare(
                "SELECT id,full_name,password FROM employees
                 WHERE employee_code=? AND status='actif' LIMIT 1"
            );
            $st->execute([$identifier]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['password']) && password_verify($secret, $row['password'])) {
                session_regenerate_id(true);
                $_SESSION['employee_id']   = (int)$row['id'];
                $_SESSION['employee_name'] = $row['full_name'];
                $_SESSION['account_type']  = 'employee';
                $found = true;
                header('Location: ' . project_url('hr/employee_portal.php')); exit;
            }
        }

        /* 3 ── CLIENT (email + password) */
        if (!$found) {
            $st = $pdo->prepare("SELECT id,name,phone,email,password_hash,company_id,city_id FROM clients WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) LIMIT 1");
            $st->execute([$identifier]);
            $client = $st->fetch(PDO::FETCH_ASSOC);
            if ($client && !empty($client['password_hash']) && password_verify($secret, $client['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['client_id']    = (int)$client['id'];
                $_SESSION['client_name']  = $client['name'];
                $_SESSION['client_phone'] = $client['phone'];
                $_SESSION['order_company_id'] = (int)($client['company_id'] ?? 0);
                $_SESSION['order_city_id'] = (int)($client['city_id'] ?? 0);
                $_SESSION['account_type'] = 'client';
                $found = true;
                header('Location: ' . project_url('orders/commande_mobile.php')); exit;
            }
        }

        /* Nothing matched — generic message (don't hint which field failed) */
        if (!$found) {
            logAttempt($pdo, $client_ip);
            $error = 'Identifiants incorrects. Vérifiez vos informations.';
        }
    }
}

/* ── CLIENT REGISTRATION ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'register_client') {
    $show_register = true;
    $name          = cl($_POST['name']       ?? '', 150);
    $email         = cl($_POST['email']      ?? '', 190);
    $phone         = cl($_POST['phone']      ?? '', 30);
    $password      = (string)($_POST['password'] ?? '');
    $company_id    = (int)($_POST['company_id'] ?? 0);
    $city_id       = (int)($_POST['city_id']    ?? 0);

    if (!$name || !$phone || !$email || !$password) {
        $error = 'Nom, email, téléphone et mot de passe requis.';
    } elseif (strlen($name) < 2) {
        $error = 'Nom trop court (minimum 2 caractères).';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Adresse email invalide.';
    } elseif (strlen($phone) < 8) {
        $error = 'Numéro invalide (minimum 8 chiffres).';
    } elseif (strlen($password) < 6) {
        $error = 'Mot de passe trop court (minimum 6 caractères).';
    } elseif ($company_id <= 0) {
        $error = 'Veuillez sélectionner votre société.';
    } elseif ($city_id <= 0) {
        $error = 'Veuillez sélectionner votre ville.';
    } else {
        $chk = $pdo->prepare("SELECT id FROM cities WHERE id=? AND company_id=? LIMIT 1");
        $chk->execute([$city_id, $company_id]);
        if (!$chk->fetch()) {
            $error = 'Combinaison société/ville invalide.';
        } else {
            $dup = $pdo->prepare("SELECT id FROM clients WHERE LOWER(TRIM(email))=LOWER(TRIM(?)) LIMIT 1");
            $dup->execute([$email]);
            if ($dup->fetch()) {
                $error = 'Cet email est déjà enregistré. Connectez-vous.';
                $show_register = false;
            } else {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare(
                    "INSERT INTO clients (name,email,password_hash,phone,company_id,city_id) VALUES (?,?,?,?,?,?)"
                )->execute([$name, $email, $passwordHash, $phone, $company_id, $city_id]);
                session_regenerate_id(true);
                $_SESSION['client_id']    = (int)$pdo->lastInsertId();
                $_SESSION['client_name']  = $name;
                $_SESSION['client_phone'] = $phone;
                $_SESSION['order_company_id'] = $company_id;
                $_SESSION['order_city_id'] = $city_id;
                $_SESSION['account_type'] = 'client';
                header('Location: ' . project_url('orders/commande_mobile.php')); exit;
            }
        }
    }
}

/* ── Preload companies ── */
try {
    $companies = $pdo->query("SELECT id,name FROM companies ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $companies = []; }

$reg_company_id = (int)($_POST['company_id'] ?? 0);
$reg_city_id    = (int)($_POST['city_id']    ?? 0);
$reg_cities     = [];
if ($reg_company_id > 0) {
    $st = $pdo->prepare("SELECT id,name FROM cities WHERE company_id=? ORDER BY name");
    $st->execute([$reg_company_id]);
    $reg_cities = $st->fetchAll(PDO::FETCH_ASSOC);
}

$POST_name  = htmlspecialchars($_POST['name']  ?? '');
$POST_email = htmlspecialchars($_POST['email'] ?? '');
$POST_phone = htmlspecialchars($_POST['phone'] ?? '');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Connexion — ESPERANCE H2O</title>
<meta name="theme-color" content="#10b981">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="ESPERANCEH²O">
<link rel="manifest" href="/hr/employee_manifest.json">
<link rel="icon" href="/hr/employee-app-icon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/hr/employee-app-icon-192.png">
<link rel="apple-touch-startup-image" href="/hr/employee-startup-1284x2778.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Mono:wght@500&family=Syne:wght@700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent;}
html{height:100%;-webkit-text-size-adjust:100%;text-size-adjust:100%;}

:root{
    --bg:#EEF0F4;
    --surface:#FFFFFF;
    --panel:#F5F6F9;
    --border:#DDE1E9;

    --text:#1A1F2E;
    --text2:#4A5368;
    --muted:#8A93A8;

    --amber:#E8860A;
    --amber-d:#C5700A;
    --amber-l:#FDF3E3;

    --blue:#2563EB;
    --blue-l:#EFF4FF;

    --green:#16A34A;
    --green-l:#EDFBF3;

    --red:#DC2626;
    --red-l:#FEF2F2;

    --sh-sm:0 1px 3px rgba(0,0,0,.07),0 1px 2px rgba(0,0,0,.04);
    --sh-lg:0 12px 40px rgba(0,0,0,.09),0 2px 8px rgba(0,0,0,.05);

    --r-sm:6px;--r-md:10px;--r-lg:16px;--r-xl:22px;
    --f:  'DM Sans',sans-serif;
    --fm: 'DM Mono',monospace;
    --fh: 'Syne',sans-serif;
}

body{
    font-family:var(--f);background:var(--bg);color:var(--text);
    min-height:100vh;display:flex;flex-direction:column;
    align-items:center;justify-content:center;
    padding:20px 16px 32px;position:relative;overflow-x:hidden;
}
.pwa-splash{
    position:fixed;inset:0;z-index:20000;display:flex;align-items:center;justify-content:center;padding:24px;
    background:linear-gradient(180deg,#0f172a 0%, #111827 100%);color:#e2e8f0;transition:opacity .35s ease, visibility .35s ease;
}
.pwa-splash.hide{opacity:0;visibility:hidden;pointer-events:none;}
.pwa-splash-box{
    width:min(100%,360px);text-align:center;padding:28px 22px;border-radius:28px;
    background:rgba(15,23,42,.6);border:1px solid rgba(255,255,255,.08);box-shadow:0 24px 60px rgba(0,0,0,.35);
}
.pwa-splash-logo{
    width:88px;height:88px;margin:0 auto 16px;border-radius:24px;overflow:hidden;box-shadow:0 20px 45px rgba(16,185,129,.25);
}
.pwa-splash-logo img{width:100%;height:100%;object-fit:cover;}
.pwa-splash-title{font-family:var(--fh);font-size:22px;font-weight:800;margin-bottom:8px;}
.pwa-splash-text{font-size:13px;color:#94a3b8;line-height:1.7;}
.pwa-splash-bar{margin-top:18px;height:8px;border-radius:999px;background:rgba(148,163,184,.18);overflow:hidden;}
.pwa-splash-bar::after{content:'';display:block;width:44%;height:100%;background:linear-gradient(90deg,#10b981,#0ea5e9);animation:loginSplash 1.15s ease-in-out infinite;}
@keyframes loginSplash{0%{transform:translateX(-110%)}100%{transform:translateX(260%)}}
.network-badge{
    position:fixed;left:50%;bottom:18px;transform:translateX(-50%);z-index:21000;display:none;align-items:center;gap:8px;
    padding:11px 16px;border-radius:999px;background:rgba(220,38,38,.96);color:#fff;font-size:13px;font-weight:800;
    box-shadow:0 18px 40px rgba(0,0,0,.22);
}
.network-badge.show{display:inline-flex;}
body::before{
    content:'';position:fixed;inset:0;z-index:0;pointer-events:none;
    background-image:
        linear-gradient(var(--border) 1px,transparent 1px),
        linear-gradient(90deg,var(--border) 1px,transparent 1px);
    background-size:36px 36px;opacity:.5;
}
body::after{
    content:'';position:fixed;top:0;left:0;right:0;height:3px;z-index:100;
    background:linear-gradient(90deg,var(--blue),var(--amber),var(--green));
}

.wrap{position:relative;z-index:1;width:100%;max-width:420px;animation:up .35s cubic-bezier(.23,1,.32,1) both;}
@keyframes up{from{opacity:0;transform:translateY(18px);}to{opacity:1;transform:translateY(0);}}

/* HEADER */
.hdr{
    display:flex;align-items:center;gap:12px;margin-bottom:16px;
    padding:13px 16px;background:var(--surface);
    border:1px solid var(--border);border-radius:var(--r-lg);box-shadow:var(--sh-sm);
}
.logo{
    width:44px;height:44px;flex-shrink:0;border-radius:var(--r-md);
    background:linear-gradient(135deg,#1e3a5f,#2563EB);
    display:flex;align-items:center;justify-content:center;font-size:21px;
    box-shadow:0 4px 12px rgba(37,99,235,.28);
}
.logo-name{font-family:var(--fh);font-size:16px;font-weight:800;color:var(--text);letter-spacing:-.2px;line-height:1.2;}
.logo-name em{font-style:normal;color:var(--amber);}
.logo-tag{font-size:10px;color:var(--muted);font-family:var(--fm);margin-top:2px;}
.pill{
    margin-left:auto;display:flex;align-items:center;gap:5px;
    padding:4px 10px;background:var(--green-l);border:1px solid rgba(22,163,74,.2);
    border-radius:20px;font-size:10px;font-weight:600;color:var(--green);
    white-space:nowrap;font-family:var(--fm);
}
.dot{width:6px;height:6px;border-radius:50%;background:var(--green);animation:blink 2s ease-in-out infinite;}
@keyframes blink{0%,100%{opacity:1;}50%{opacity:.25;}}

/* CARD */
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-xl);box-shadow:var(--sh-lg);overflow:hidden;}

/* CARD TOP */
.card-top{
    padding:20px 22px 0;
    display:flex;align-items:center;gap:10px;
}
.c-icon{
    width:38px;height:38px;border-radius:var(--r-md);flex-shrink:0;
    background:var(--amber-l);border:1px solid rgba(232,134,10,.2);
    display:flex;align-items:center;justify-content:center;font-size:17px;color:var(--amber-d);
}
.c-title{font-family:var(--fh);font-size:15px;font-weight:800;color:var(--text);}
.c-sub{font-size:11px;color:var(--muted);margin-top:2px;}

/* CARD BODY */
.card-body{padding:18px 22px 20px;}

/* HOW IT WORKS */
.steps{
    display:flex;margin-bottom:18px;
    border:1px solid var(--border);border-radius:var(--r-md);overflow:hidden;
}
.step{
    flex:1;display:flex;flex-direction:column;align-items:center;
    padding:10px 6px;text-align:center;background:var(--panel);
    font-size:10px;color:var(--text2);font-weight:600;gap:4px;
    position:relative;
}
.step:not(:last-child)::after{
    content:'›';position:absolute;right:-5px;top:50%;transform:translateY(-50%);
    font-size:15px;color:var(--muted);z-index:1;
}
.step i{font-size:15px;}
.step.s1 i{color:var(--blue);}
.step.s2 i{color:var(--amber);}
.step.s3 i{color:var(--green);}

/* ALERT */
.alert{
    display:flex;align-items:flex-start;gap:9px;
    padding:11px 13px;border-radius:var(--r-md);
    margin-bottom:14px;font-size:13px;line-height:1.5;
    animation:dn .25s ease both;
}
@keyframes dn{from{opacity:0;transform:translateY(-6px);}to{opacity:1;transform:translateY(0);}}
.alert.err{background:var(--red-l);border:1px solid rgba(220,38,38,.2);color:var(--red);}
.alert.tip{background:var(--blue-l);border:1px solid rgba(37,99,235,.15);color:#1d4ed8;}
.alert i{font-size:14px;flex-shrink:0;margin-top:1px;}
.tip-label{font-weight:700;margin-bottom:4px;display:block;}
.tip-list{list-style:none;display:flex;flex-direction:column;gap:3px;}
.tip-list li{display:flex;align-items:flex-start;gap:6px;font-size:12px;}
.tip-list li::before{content:'•';flex-shrink:0;margin-top:0;}
.tip-list b{font-weight:700;}

/* FIELD */
.fg{margin-bottom:13px;}
.fg label{display:block;font-size:12px;font-weight:600;color:var(--text2);margin-bottom:6px;letter-spacing:.1px;}
.iw{position:relative;}
.iw .fi{
    position:absolute;left:12px;top:50%;transform:translateY(-50%);
    font-size:13px;color:var(--muted);pointer-events:none;z-index:1;transition:color .2s;
}
.iw:focus-within .fi{color:var(--amber);}
.eye-btn{
    position:absolute;right:11px;top:50%;transform:translateY(-50%);
    border:none;background:none;cursor:pointer;color:var(--muted);
    font-size:14px;padding:5px;transition:color .2s;
}
.eye-btn:hover{color:var(--amber);}

input[type=text],input[type=password],input[type=tel],select{
    width:100%;font-family:var(--f);font-size:14px;font-weight:500;
    color:var(--text);background:var(--panel);
    border:1.5px solid var(--border);border-radius:var(--r-md);
    padding:12px 14px;-webkit-appearance:none;appearance:none;
    transition:border-color .2s,box-shadow .2s,background .2s;line-height:1.4;
}
.iw input,.iw select{padding-left:38px;}
.iw input[type=password]{padding-right:40px;}
input:focus,select:focus{
    outline:none;border-color:var(--amber);
    background:var(--surface);box-shadow:0 0 0 3px rgba(232,134,10,.12);
}
input::placeholder{color:var(--muted);font-weight:400;}
select option{color:var(--text);background:var(--surface);}
input:-webkit-autofill{
    -webkit-box-shadow:0 0 0 1000px var(--panel) inset !important;
    -webkit-text-fill-color:var(--text) !important;
    transition:background-color 5000s;
}

/* BUTTON */
.btn{
    width:100%;font-family:var(--f);font-size:14px;font-weight:700;
    display:flex;align-items:center;justify-content:center;gap:8px;
    padding:13px 20px;min-height:50px;border:none;border-radius:var(--r-md);
    cursor:pointer;transition:transform .15s,box-shadow .2s;
    letter-spacing:.2px;position:relative;overflow:hidden;margin-top:4px;
}
.btn::before{
    content:'';position:absolute;inset:0;
    background:rgba(255,255,255,.14);
    transform:translateX(-110%) skewX(-15deg);transition:transform .5s;
}
.btn:hover::before{transform:translateX(110%) skewX(-15deg);}
.btn:active{transform:scale(.98);}
.btn-main{
    background:linear-gradient(135deg,#E8860A,#D97706);color:#fff;
    box-shadow:0 3px 14px rgba(232,134,10,.35);
}
.btn-main:hover{box-shadow:0 5px 22px rgba(232,134,10,.5);}
.btn-out{background:none;border:1.5px solid var(--border);color:var(--text2);}
.btn-out:hover{border-color:var(--amber);color:var(--amber);}

/* DIVIDER */
.or{display:flex;align-items:center;gap:10px;margin:15px 0;font-size:11px;color:var(--muted);font-weight:500;letter-spacing:.5px;text-transform:uppercase;}
.or::before,.or::after{content:'';flex:1;height:1px;background:var(--border);}

/* SWITCH */
.sw{text-align:center;font-size:13px;color:var(--muted);margin-top:3px;}
.sw button{
    background:none;border:none;cursor:pointer;color:var(--amber);
    font-weight:700;font-size:13px;font-family:var(--f);
    padding:4px 6px;border-radius:4px;transition:background .2s;
}
.sw button:hover{background:var(--amber-l);}

/* VIEWS */
.view{animation:up .25s ease both;}
.view[hidden]{display:none;}

/* FOOTER */
.card-foot{
    display:flex;align-items:center;justify-content:center;gap:14px;flex-wrap:wrap;
    padding:11px 20px;border-top:1px solid var(--border);background:var(--panel);
}
.badge{display:flex;align-items:center;gap:4px;font-size:10px;color:var(--muted);font-weight:600;font-family:var(--fm);}
.badge i{font-size:11px;color:var(--green);}
.legal-box{
    margin-top:14px;
    padding:14px 16px;
    background:rgba(255,255,255,.72);
    border:1px solid var(--border);
    border-radius:var(--r-lg);
    box-shadow:var(--sh-sm);
}
.legal-title{
    font-size:11px;
    text-transform:uppercase;
    letter-spacing:.08em;
    color:var(--muted);
    font-weight:800;
    margin-bottom:10px;
}
.legal-links{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:10px;
}
.legal-link{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    min-height:44px;
    padding:10px 12px;
    border-radius:var(--r-md);
    background:var(--surface);
    border:1px solid var(--border);
    color:var(--text2);
    text-decoration:none;
    font-size:12px;
    font-weight:700;
    transition:transform .15s, border-color .2s, color .2s, box-shadow .2s;
}
.legal-link:hover{
    transform:translateY(-1px);
    border-color:rgba(232,134,10,.35);
    color:var(--amber);
    box-shadow:0 8px 20px rgba(232,134,10,.12);
}

/* SPINNER */
.sp{width:16px;height:16px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;display:inline-block;}
@keyframes spin{to{transform:rotate(360deg);}}

/* RESPONSIVE */
@media(max-width:380px){
    .card-body,.card-top{padding-left:14px;padding-right:14px;}
    .logo-name{font-size:14px;}
    .steps{display:none;}
}
@media(max-width:480px){
    .legal-links{grid-template-columns:1fr;}
}
@media(min-width:500px){
    body{padding:32px 20px 40px;}
    .card-body{padding:22px 28px 24px;}
    .card-top{padding:22px 28px 0;}
}
</style>
</head>
<body>
<div class="pwa-splash" id="loginSplash">
  <div class="pwa-splash-box">
    <div class="pwa-splash-logo"><img src="/hr/employee-app-icon.svg" alt="ESPERANCEH²O"></div>
    <div class="pwa-splash-title">ESPERANCEH²O</div>
    <div class="pwa-splash-text">Connexion sécurisée au portail installé. Après déconnexion, l’application reste en mode plein écran si elle est installée.</div>
    <div class="pwa-splash-bar"></div>
  </div>
</div>
<div class="network-badge" id="networkBadge"><i class="fas fa-wifi"></i> Mode hors ligne</div>
<div class="wrap">

  <!-- HEADER -->
  <div class="hdr">
    <div class="logo">💧</div>
    <div>
      <div class="logo-name">ESPE<em>RANCE</em> H2O</div>
      <div class="logo-tag">Gestion stock & livraison</div>
    </div>
    <div class="pill"><div class="dot"></div>EN LIGNE</div>
  </div>

  <!-- CARD -->
  <div class="card">

    <div class="card-top">
      <div class="c-icon"><i class="fas fa-shield-halved"></i></div>
      <div>
        <div class="c-title">Portail d'accès unique</div>
        <div class="c-sub">Détection automatique du compte</div>
      </div>
    </div>

    <div class="card-body">

      <!-- HOW IT WORKS -->
      <div class="steps">
        <div class="step s1"><i class="fas fa-keyboard"></i><span>Entrez vos identifiants</span></div>
        <div class="step s2"><i class="fas fa-magnifying-glass"></i><span>Détection automatique</span></div>
        <div class="step s3"><i class="fas fa-arrow-right-to-bracket"></i><span>Redirection sécurisée</span></div>
      </div>

      <?php if ($error): ?>
      <div class="alert err">
        <i class="fas fa-exclamation-circle"></i>
        <span><?= htmlspecialchars($error) ?></span>
      </div>
      <?php endif; ?>

      <!-- ══ LOGIN VIEW ══ -->
      <div class="view" id="v-login" <?= $show_register ? 'hidden' : '' ?>>

        <div class="alert tip">
          <i class="fas fa-circle-info"></i>
          <div>
            <span class="tip-label">Comment accéder à votre espace :</span>
            <ul class="tip-list">
              <li><b>Client</b> → email + mot de passe</li>
              <li><b>Employé</b> → code EMP-xxx + mot de passe</li>
              <li><b>Responsable</b> → identifiant + mot de passe</li>
            </ul>
          </div>
        </div>

        <form method="POST" onsubmit="handleSubmit(this,'btn-login')">
          <input type="hidden" name="action" value="unified_login">

          <div class="fg">
            <label>Identifiant</label>
            <div class="iw">
              <i class="fas fa-user fi"></i>
              <input type="text" name="identifier"
                     placeholder="Email client / Code employé / Nom d'utilisateur"
                     autocomplete="username" maxlength="150" required autofocus>
            </div>
          </div>

          <div class="fg">
            <label>Mot de passe</label>
            <div class="iw">
              <i class="fas fa-lock fi"></i>
              <input type="password" id="pw-main" name="secret"
                     placeholder="Votre mot de passe"
                     autocomplete="current-password" maxlength="150" required>
              <button type="button" class="eye-btn" onclick="togglePw('pw-main',this)" tabindex="-1">
                <i class="fas fa-eye"></i>
              </button>
            </div>
          </div>

          <button type="submit" class="btn btn-main" id="btn-login">
            <i class="fas fa-sign-in-alt"></i> Accéder à mon espace
          </button>
        </form>

        <div class="or">Nouveau client</div>
        <div class="sw">
          Pas encore de compte ? <button type="button" onclick="showReg(true)">Créer mon compte →</button>
        </div>
      </div><!-- /v-login -->

      <!-- ══ REGISTER VIEW ══ -->
      <div class="view" id="v-register" <?= !$show_register ? 'hidden' : '' ?>>

        <form method="POST" onsubmit="handleSubmit(this,'btn-reg')">
          <input type="hidden" name="action" value="register_client">

          <div class="fg">
            <label>Nom complet</label>
            <div class="iw">
                <i class="fas fa-user fi"></i>
              <input type="text" name="name" placeholder="Ex : Jean KOUA"
                     autocomplete="name" maxlength="150" minlength="2" required
                     value="<?= $show_register ? $POST_name : '' ?>" <?= $show_register ? 'autofocus' : '' ?>>
            </div>
          </div>

          <div class="fg">
            <label>Adresse email</label>
            <div class="iw">
              <i class="fas fa-envelope fi"></i>
              <input type="email" name="email" placeholder="Ex : client@mail.com"
                     autocomplete="email" maxlength="190" required
                     value="<?= $show_register ? $POST_email : '' ?>">
            </div>
          </div>

          <div class="fg">
            <label>Numéro de téléphone</label>
            <div class="iw">
              <i class="fas fa-phone fi"></i>
              <input type="tel" name="phone" placeholder="Ex : 0708090605"
                     autocomplete="tel" inputmode="numeric" maxlength="20" required
                     value="<?= $show_register ? $POST_phone : '' ?>">
            </div>
          </div>

          <div class="fg">
            <label>Mot de passe</label>
            <div class="iw">
              <i class="fas fa-lock fi"></i>
              <input type="password" id="pw-reg" name="password" placeholder="Minimum 6 caractères"
                     autocomplete="new-password" maxlength="150" minlength="6" required>
              <button type="button" class="eye-btn" onclick="togglePw('pw-reg',this)" tabindex="-1">
                <i class="fas fa-eye"></i>
              </button>
            </div>
          </div>

          <div class="fg">
            <label>Société</label>
            <div class="iw">
              <i class="fas fa-building fi"></i>
              <select name="company_id" id="sel-company" required onchange="loadCities(this.value)">
                <option value="">— Sélectionner votre société —</option>
                <?php foreach ($companies as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $reg_company_id==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="fg">
            <label>Ville de livraison</label>
            <div class="iw">
              <i class="fas fa-map-marker-alt fi"></i>
              <select name="city_id" id="sel-city" required>
                <?php if ($reg_cities): ?>
                  <option value="">— Sélectionner —</option>
                  <?php foreach ($reg_cities as $ci): ?>
                  <option value="<?= $ci['id'] ?>" <?= $reg_city_id==$ci['id']?'selected':'' ?>><?= htmlspecialchars($ci['name']) ?></option>
                  <?php endforeach; ?>
                <?php else: ?>
                  <option value="">— Choisir la société d'abord —</option>
                <?php endif; ?>
              </select>
            </div>
          </div>

          <button type="submit" class="btn btn-main" id="btn-reg">
            <i class="fas fa-user-plus"></i> Créer mon compte
          </button>
        </form>

        <div class="or">Déjà inscrit ?</div>
        <div class="sw">
          <button type="button" onclick="showReg(false)">← Retour à la connexion</button>
        </div>
      </div><!-- /v-register -->

    </div><!-- /card-body -->

    <!-- CARD FOOTER -->
    <div class="card-foot">
      <div class="badge"><i class="fas fa-lock"></i> SSL</div>
      <div class="badge"><i class="fas fa-shield-alt"></i> Anti-injection</div>
      <div class="badge"><i class="fas fa-fingerprint"></i> Session sécurisée</div>
      <div class="badge"><i class="fas fa-ban"></i> Anti-brute force</div>
    </div>

  </div><!-- /card -->

  <div class="legal-box">
    <div class="legal-title">Informations légales</div>
    <div class="legal-links">
      <a class="legal-link" href="<?= htmlspecialchars(project_url('legal/privacy.php')) ?>">
        <i class="fas fa-user-shield"></i> Confidentialité
      </a>
      <a class="legal-link" href="<?= htmlspecialchars(project_url('legal/about.php')) ?>">
        <i class="fas fa-building"></i> À propos
      </a>
      <a class="legal-link" href="<?= htmlspecialchars(project_url('legal/copyright.php')) ?>">
        <i class="fas fa-copyright"></i> Droits d'auteur
      </a>
    </div>
  </div>

  <?= render_legal_footer(['theme' => 'light', 'compact' => true]) ?>
</div><!-- /wrap -->

<script>
function hideLoginSplash(){
    var el=document.getElementById('loginSplash');
    if(!el) return;
    el.classList.add('hide');
    setTimeout(function(){ if(el && el.parentNode) el.parentNode.removeChild(el); }, 450);
}
function updateNetworkBadge(){
    var badge=document.getElementById('networkBadge');
    if(!badge) return;
    badge.classList.toggle('show', !navigator.onLine);
}
function showReg(show) {
    var vl=document.getElementById('v-login');
    var vr=document.getElementById('v-register');
    if(show){ vl.hidden=true; vr.hidden=false; focus(vr); }
    else    { vr.hidden=true; vl.hidden=false; focus(vl); }
}
function focus(el){
    setTimeout(function(){
        var inp=el.querySelector('input:not([type=hidden])');
        if(inp) inp.focus();
    },60);
}
function togglePw(id,btn){
    var inp=document.getElementById(id);
    var ico=btn.querySelector('i');
    if(inp.type==='password'){ inp.type='text'; ico.className='fas fa-eye-slash'; }
    else { inp.type='password'; ico.className='fas fa-eye'; }
}
function loadCities(cid){
    var sel=document.getElementById('sel-city');
    if(!sel) return;
    if(!cid){ sel.innerHTML="<option value=''>— Choisir la société d'abord —</option>"; return; }
    sel.innerHTML="<option>Chargement…</option>"; sel.disabled=true;
    var fd=new FormData(); fd.append('ajax','cities'); fd.append('company_id',cid);
    fetch(window.location.pathname,{method:'POST',body:fd})
        .then(function(r){return r.json();})
        .then(function(d){
            sel.disabled=false;
            sel.innerHTML=d.cities&&d.cities.length
                ? "<option value=''>— Sélectionner votre ville —</option>"+d.cities.map(function(c){return "<option value='"+c.id+"'>"+c.name+"</option>";}).join('')
                : "<option value=''>Aucune ville disponible</option>";
        })
        .catch(function(){ sel.disabled=false; sel.innerHTML="<option value=''>Erreur — réessayez</option>"; });
}
function handleSubmit(form,btnId){
    var btn=document.getElementById(btnId);
    if(!btn) return true;
    btn.disabled=true;
    btn.innerHTML='<div class="sp"></div> Vérification en cours…';
    return true;
}
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function(){
        navigator.serviceWorker.register('/hr/employee-sw.js').catch(function(){});
    });
}
window.addEventListener('load', function(){ setTimeout(hideLoginSplash, 350); });
setTimeout(hideLoginSplash, 2200);
window.addEventListener('online', updateNetworkBadge);
window.addEventListener('offline', updateNetworkBadge);
updateNetworkBadge();
</script>
</body>
</html>
