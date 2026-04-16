<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

// ════════════════════════════════════════════════════════════════
//  ESPERANCEH20 ERP — API GATEWAY
//  Bridges clean REST URLs to your existing API files
//  https://api.esperanceh20.com/v1/{resource}
// ════════════════════════════════════════════════════════════════

$legacyRoot = '/home/kali/Desktop/ESPERANCEH20';
define('ERP_ROOT', is_dir($legacyRoot) ? $legacyRoot : PROJECT_ROOT);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-API-Key, X-Requested-With');
header('Vary: Origin');
header('X-Powered-By: EsperanceH20-ERP/2.0');

function gateway_allowed_origins(): array {
    $raw = trim((string)($_SERVER['API_ALLOWED_ORIGINS'] ?? getenv('API_ALLOWED_ORIGINS') ?: ''));
    if ($raw === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        return $host !== '' ? [$scheme . '://' . $host] : [];
    }
    $origins = [];
    foreach (explode(',', $raw) as $origin) {
        $origin = trim($origin);
        if ($origin !== '') {
            $origins[] = $origin;
        }
    }
    return array_values(array_unique($origins));
}

function gateway_origin_allowed(?string $origin): bool {
    if ($origin === null || trim($origin) === '') {
        return true;
    }
    return in_array(trim($origin), gateway_allowed_origins(), true);
}

$requestOrigin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
if ($requestOrigin !== '' && gateway_origin_allowed($requestOrigin)) {
    header('Access-Control-Allow-Origin: ' . $requestOrigin);
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    if (!gateway_origin_allowed($requestOrigin)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Origin non autorisée']);
        exit;
    }
    http_response_code(204);
    exit;
}

// ── Load ERP config ──────────────────────────────────────────
$config_candidates = [
    ERP_ROOT . '/includes/config.php',
    ERP_ROOT . '/includes/db.php',
    ERP_ROOT . '/includes/database.php',
    ERP_ROOT . '/config.php',
];
foreach ($config_candidates as $f) {
    if (file_exists($f)) { require_once $f; break; }
}

// ── Log request ──────────────────────────────────────────────
$log = ERP_ROOT . '/logs/api_gateway.log';
if (is_writable(dirname($log))) {
    $line = date('[Y-m-d H:i:s]') . ' ' . $_SERVER['REQUEST_METHOD'] .
        ' ' . $_SERVER['REQUEST_URI'] .
        ' IP:' . ($_SERVER['REMOTE_ADDR'] ?? '?') . "\n";
    file_put_contents($log, $line, FILE_APPEND | LOCK_EX);
}

// ── Parse route ──────────────────────────────────────────────
$uri      = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$segments = array_values(array_filter(explode('/', trim($uri, '/'))));
// segments: [v1, resource, id?, action?]
$version  = isset($segments[0]) && in_array($segments[0], ['v1','v2']) ? array_shift($segments) : 'v1';
$resource = $segments[0] ?? '';
$id       = $segments[1] ?? null;
$action   = $segments[2] ?? null;
$method   = $_SERVER['REQUEST_METHOD'];

// ── Auth ─────────────────────────────────────────────────────
function gateway_valid_tokens(): array {
    $raw = trim((string)($_SERVER['API_GATEWAY_TOKENS'] ?? getenv('API_GATEWAY_TOKENS') ?: ''));
    if ($raw === '') {
        $single = trim((string)($_SERVER['API_GATEWAY_TOKEN'] ?? getenv('API_GATEWAY_TOKEN') ?: ''));
        $raw = $single;
    }
    $tokens = [];
    foreach (explode(',', $raw) as $token) {
        $token = trim($token);
        if ($token !== '') {
            $tokens[] = $token;
        }
    }
    return array_values(array_unique($tokens));
}

function gateway_extract_token(): string {
    $auth = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if ($auth !== '' && stripos($auth, 'Bearer ') === 0) {
        return trim(substr($auth, 7));
    }
    $apiKey = trim((string)($_SERVER['HTTP_X_API_KEY'] ?? ''));
    if ($apiKey !== '') {
        return $apiKey;
    }
    return trim((string)($_GET['token'] ?? ''));
}

function require_auth() {
    $validTokens = gateway_valid_tokens();
    if (!$validTokens) {
        http_response_code(500);
        echo json_encode(['error' => 'API token non configuré côté serveur']);
        exit;
    }

    $token = gateway_extract_token();
    if ($token === '') {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized', 'hint' => 'Authorization: Bearer TOKEN']);
        exit;
    }

    foreach ($validTokens as $valid) {
        if (hash_equals($valid, $token)) {
            return;
        }
    }
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized', 'hint' => 'Token invalide']);
    exit;
}

// ── Response helper ──────────────────────────────────────────
function ok($data, $code=200, $extra=[]) {
    http_response_code($code);
    echo json_encode(array_merge(
        ['success'=>true,'timestamp'=>date('c'),'version'=>'2.0'],
        $extra,
        is_array($data) ? $data : ['data'=>$data]
    ), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
    exit;
}
function err($msg, $code=400) {
    http_response_code($code);
    echo json_encode(['success'=>false,'error'=>$msg,'timestamp'=>date('c')]);
    exit;
}

// ── DB query helper ──────────────────────────────────────────
function q($sql, $p=[]) {
    global $pdo, $conn;
    if (isset($pdo)) {
        $s=$pdo->prepare($sql); $s->execute($p);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }
    if (isset($conn)) {
        // mysqli with basic param binding
        if ($p) {
            $stmt = $conn->prepare($sql);
            $types = str_repeat('s', count($p));
            $stmt->bind_param($types, ...$p);
            $stmt->execute();
            $r = $stmt->get_result();
            return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
        }
        $r = $conn->query($sql);
        return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    }
    return [];
}

function q1($sql, $p=[]) {
    $r = q($sql, $p);
    return $r[0] ?? null;
}

function body() {
    return json_decode(file_get_contents('php://input'), true) ?? $_POST;
}

// ════════════════════════════════════════════════════════════════
//  ROUTES
// ════════════════════════════════════════════════════════════════
switch ($resource) {

// ── / and /health ─────────────────────────────────────────────
case '':
case 'health':
    ok([
        'service'   => 'EsperanceH20 ERP API',
        'status'    => 'online',
        'base_url'  => 'https://api.esperanceh20.com/v1',
        'docs'      => 'https://docs.esperanceh20.com',
        'endpoints' => [
            'GET  /v1/health',
            'GET  /v1/products[?search=&limit=&offset=]',
            'GET  /v1/products/{id}',
            'POST /v1/products',
            'PUT  /v1/products/{id}',
            'DEL  /v1/products/{id}',
            'GET  /v1/clients[?search=]',
            'GET  /v1/clients/{id}',
            'POST /v1/clients',
            'GET  /v1/orders[?status=&limit=]',
            'GET  /v1/orders/{id}',
            'POST /v1/orders',
            'PUT  /v1/orders/{id}/status',
            'GET  /v1/stock',
            'POST /v1/stock/update',
            'GET  /v1/employees',
            'GET  /v1/employees/{id}',
            'GET  /v1/invoices[?client_id=]',
            'GET  /v1/invoices/{id}',
            'POST /v1/invoices',
            'GET  /v1/caisse/balance',
            'GET  /v1/caisse/operations',
            'POST /v1/caisse/operation',
            'GET  /v1/notifications',
            'POST /v1/notifications/mark-read',
            'GET  /v1/stats/dashboard',
            'GET  /v1/stats/sales[?period=]',
            'GET  /v1/depenses',
            'GET  /v1/livraisons',
            'GET  /v1/appros',
        ]
    ]);

// ── PRODUCTS ──────────────────────────────────────────────────
case 'products':
    require_auth();
    // Forward to existing file for complex ops
    if ($method === 'GET' && !$id) {
        $limit  = min((int)($_GET['limit']??50),200);
        $offset = (int)($_GET['offset']??0);
        $search = trim($_GET['search']??'');
        $cat    = trim($_GET['category']??'');
        $sql = "SELECT * FROM products";
        $where=[]; $p=[];
        if($search){ $where[]="(name LIKE ? OR reference LIKE ? OR description LIKE ?)"; $p[]="%$search%"; $p[]="%$search%"; $p[]="%$search%"; }
        if($cat)   { $where[]="category = ?"; $p[]=$cat; }
        if($where) $sql.=' WHERE '.implode(' AND ',$where);
        $sql.=" ORDER BY name ASC LIMIT ? OFFSET ?"; $p[]=$limit; $p[]=$offset;
        $rows=q($sql,$p);
        $total=q1("SELECT COUNT(*) as n FROM products".[" WHERE ".implode(' AND ',array_slice($where,0,-1)) ?: ''])[' n']??0;
        ok(['products'=>$rows,'count'=>count($rows),'limit'=>$limit,'offset'=>$offset]);
    }
    if ($method === 'GET' && $id) {
        $row=q1("SELECT * FROM products WHERE id=?",[$id]);
        if(!$row) err("Product #$id not found",404);
        ok(['product'=>$row]);
    }
    if ($method === 'POST') {
        $b=body();
        // Delegate to existing products API
        $_POST = $b;
        $_GET['action']='add';
        ob_start();
        include_once ERP_ROOT.'/stock_api.php';
        $out=ob_get_clean();
        $json=json_decode($out,true);
        $json ? ok($json) : ok(['message'=>'Product created','raw'=>$out],201);
    }
    err("Method not allowed",405);

// ── CLIENTS ───────────────────────────────────────────────────
case 'clients':
    require_auth();
    if ($method==='GET' && !$id) {
        $search=trim($_GET['search']??'');
        $sql="SELECT * FROM clients";
        $p=[];
        if($search){ $sql.=" WHERE (name LIKE ? OR email LIKE ? OR phone LIKE ?)"; $p[]="%$search%";$p[]="%$search%";$p[]="%$search%"; }
        $sql.=" ORDER BY name ASC LIMIT 200";
        $rows=q($sql,$p);
        ok(['clients'=>$rows,'count'=>count($rows)]);
    }
    if ($method==='GET' && $id) {
        $row=q1("SELECT * FROM clients WHERE id=?",[$id]);
        if(!$row) err("Client #$id not found",404);
        // Get client orders too
        $orders=q("SELECT id,total,status,created_at FROM orders WHERE client_id=? ORDER BY created_at DESC LIMIT 10",[$id]);
        ok(['client'=>$row,'recent_orders'=>$orders]);
    }
    if ($method==='POST') {
        $b=body();
        ok(['message'=>'Client endpoint — connect to your clients_erp_pro.php handler','received'=>$b],201);
    }
    err("Method not allowed",405);

// ── ORDERS ────────────────────────────────────────────────────
case 'orders':
    require_auth();
    // Delegate to existing orders_api.php
    if (file_exists(ERP_ROOT.'/orders_api.php') && $method==='POST') {
        $_POST = body();
        ob_start();
        include_once ERP_ROOT.'/orders_api.php';
        $out=ob_get_clean();
        $json=json_decode($out,true);
        $json ? ok($json,201) : ok(['message'=>'Order processed','raw'=>$out],201);
    }
    if ($method==='GET' && !$id) {
        $status=trim($_GET['status']??'');
        $limit=min((int)($_GET['limit']??50),200);
        $sql="SELECT o.*, c.name as client_name FROM orders o LEFT JOIN clients c ON c.id=o.client_id";
        $p=[];
        if($status){ $sql.=" WHERE o.status=?"; $p[]=$status; }
        $sql.=" ORDER BY o.created_at DESC LIMIT ?"; $p[]=$limit;
        $rows=q($sql,$p);
        ok(['orders'=>$rows,'count'=>count($rows)]);
    }
    if ($method==='GET' && $id) {
        $row=q1("SELECT o.*, c.name as client_name, c.phone as client_phone 
                 FROM orders o LEFT JOIN clients c ON c.id=o.client_id WHERE o.id=?",[$id]);
        if(!$row) err("Order #$id not found",404);
        $items=q("SELECT oi.*, p.name as product_name 
                  FROM order_items oi LEFT JOIN products p ON p.id=oi.product_id 
                  WHERE oi.order_id=?",[$id]);
        ok(['order'=>$row,'items'=>$items]);
    }
    if ($method==='PUT' && $id && $action==='status') {
        $b=body();
        $st=$b['status']??err("status required");
        q("UPDATE orders SET status=?, updated_at=NOW() WHERE id=?",[$st,$id]);
        ok(['message'=>"Order #$id status → $st"]);
    }
    err("Method not allowed",405);

// ── STOCK ─────────────────────────────────────────────────────
case 'stock':
    require_auth();
    // Delegate to existing stock_api.php if available
    if (file_exists(ERP_ROOT.'/stock_api.php') && ($action==='update'||$method==='POST')) {
        $_POST=body(); $_GET['action']='update';
        ob_start();
        include_once ERP_ROOT.'/stock_api.php';
        $out=ob_get_clean();
        $json=json_decode($out,true);
        $json ? ok($json) : ok(['message'=>'Stock updated','raw'=>$out]);
    }
    $sql="SELECT p.id, p.name, p.reference, p.stock_qty as quantity,
                 p.stock_min as min_stock, p.price, p.category,
                 CASE WHEN p.stock_qty<=p.stock_min THEN 'LOW'
                      WHEN p.stock_qty=0 THEN 'OUT'
                      ELSE 'OK' END as status
          FROM products p ORDER BY p.name ASC LIMIT 500";
    $rows=q($sql);
    if(!$rows) $rows=q("SELECT * FROM stock ORDER BY updated_at DESC LIMIT 500");
    $low=array_filter($rows,fn($r)=>($r['status']??'')!=='OK');
    ok(['stock'=>$rows,'total'=>count($rows),'low_stock'=>count($low)]);

// ── EMPLOYEES ─────────────────────────────────────────────────
case 'employees':
    require_auth();
    if ($method==='GET' && !$id) {
        $rows=q("SELECT id,nom,prenom,email,telephone,poste,departement,salaire,date_embauche,statut 
                 FROM employes ORDER BY nom,prenom LIMIT 200");
        if(!$rows) $rows=q("SELECT id,name,email,role,department,created_at FROM employees LIMIT 200");
        ok(['employees'=>$rows,'count'=>count($rows)]);
    }
    if ($method==='GET' && $id) {
        $row=q1("SELECT * FROM employes WHERE id=?",[$id])
          ??q1("SELECT * FROM employees WHERE id=?",[$id]);
        if(!$row) err("Employee #$id not found",404);
        ok(['employee'=>$row]);
    }
    err("Method not allowed",405);

// ── INVOICES ──────────────────────────────────────────────────
case 'invoices':
    require_auth();
    if ($method==='GET' && !$id) {
        $client=trim($_GET['client_id']??'');
        $sql="SELECT f.*, c.name as client_name FROM factures f 
              LEFT JOIN clients c ON c.id=f.client_id";
        $p=[];
        if($client){ $sql.=" WHERE f.client_id=?"; $p[]=$client; }
        $sql.=" ORDER BY f.created_at DESC LIMIT 100";
        $rows=q($sql,$p);
        ok(['invoices'=>$rows,'count'=>count($rows)]);
    }
    if ($method==='GET' && $id) {
        $row=q1("SELECT f.*,c.name as client_name,c.email,c.address
                 FROM factures f LEFT JOIN clients c ON c.id=f.client_id WHERE f.id=?",[$id]);
        if(!$row) err("Invoice #$id not found",404);
        $items=q("SELECT * FROM facture_items WHERE facture_id=?",[$id]);
        ok(['invoice'=>$row,'items'=>$items,
            'pdf_url'=>"https://esperanceh20.com/invoice_pdf.php?id=$id"]);
    }
    err("Method not allowed",405);

// ── CAISSE ────────────────────────────────────────────────────
case 'caisse':
    require_auth();
    if ($action==='balance'||(!$action&&$method==='GET')) {
        $entrees=q1("SELECT COALESCE(SUM(montant),0) as total FROM caisse WHERE type='entree'");
        $sorties=q1("SELECT COALESCE(SUM(montant),0) as total FROM caisse WHERE type='sortie'");
        $e=(float)($entrees['total']??0);
        $s=(float)($sorties['total']??0);
        ok(['balance'=>$e-$s,'entrees'=>$e,'sorties'=>$s,'currency'=>'FCFA']);
    }
    if ($action==='operations') {
        $rows=q("SELECT * FROM caisse ORDER BY created_at DESC LIMIT 100");
        ok(['operations'=>$rows,'count'=>count($rows)]);
    }
    if ($action==='operation'&&$method==='POST') {
        $b=body();
        ok(['message'=>'Caisse operation — connect to your caisse.php handler','received'=>$b],201);
    }
    err("Endpoint not found",404);

// ── NOTIFICATIONS ─────────────────────────────────────────────
case 'notifications':
    require_auth();
    // Delegate to existing notifications_api.php
    if (file_exists(ERP_ROOT.'/notifications_api.php') && $method==='POST') {
        $_POST=body();
        ob_start();
        include_once ERP_ROOT.'/notifications_api.php';
        $out=ob_get_clean();
        $json=json_decode($out,true);
        $json ? ok($json) : ok(['raw'=>$out]);
    }
    if ($method==='GET') {
        $rows=q("SELECT * FROM notifications WHERE read_at IS NULL ORDER BY created_at DESC LIMIT 30");
        ok(['notifications'=>$rows,'unread'=>count($rows)]);
    }
    if ($method==='POST' && $action==='mark-read') {
        $b=body(); $ids=$b['ids']??[];
        if($ids) {
            $ph=implode(',',array_fill(0,count($ids),'?'));
            q("UPDATE notifications SET read_at=NOW() WHERE id IN ($ph)",$ids);
        }
        ok(['message'=>count($ids).' notifications marked as read']);
    }
    err("Method not allowed",405);

// ── DEPENSES ─────────────────────────────────────────────────
case 'depenses':
    require_auth();
    $rows=q("SELECT * FROM depenses ORDER BY created_at DESC LIMIT 100");
    ok(['depenses'=>$rows,'count'=>count($rows)]);

// ── LIVRAISONS ────────────────────────────────────────────────
case 'livraisons':
    require_auth();
    $rows=q("SELECT bl.*, c.name as client_name FROM bons_livraison bl 
             LEFT JOIN clients c ON c.id=bl.client_id ORDER BY bl.created_at DESC LIMIT 100");
    ok(['livraisons'=>$rows,'count'=>count($rows)]);

// ── APPROS ────────────────────────────────────────────────────
case 'appros':
    require_auth();
    $rows=q("SELECT * FROM appro_requests ORDER BY created_at DESC LIMIT 100");
    ok(['appros'=>$rows,'count'=>count($rows)]);

// ── STATS / DASHBOARD ─────────────────────────────────────────
case 'stats':
    require_auth();
    $sub=$action??($id??'dashboard');
    if ($sub==='dashboard') {
        $tables=['products','clients','orders','employes'=>'employees','factures'=>'invoices'];
        $counts=[];
        foreach(['products','clients','orders'] as $t) {
            $r=q1("SELECT COUNT(*) as n FROM $t");
            $counts[$t]=(int)($r['n']??0);
        }
        foreach(['employes'=>'employees','factures'=>'invoices'] as $t=>$alias) {
            $r=q1("SELECT COUNT(*) as n FROM $t");
            $counts[$alias]=(int)($r['n']??0);
        }
        $revenue=q1("SELECT COALESCE(SUM(total),0) as total FROM factures WHERE MONTH(created_at)=MONTH(NOW())");
        $counts['revenue_month']=(float)($revenue['total']??0);
        ok(['dashboard'=>$counts,'currency'=>'FCFA','generated_at'=>date('c')]);
    }
    if ($sub==='sales') {
        $period=$_GET['period']??'month';
        $sql = match($period) {
            'week'  => "SELECT DATE(created_at) as date, COUNT(*) as orders, SUM(total) as revenue FROM orders WHERE created_at >= DATE_SUB(NOW(),INTERVAL 7 DAY) GROUP BY DATE(created_at) ORDER BY date",
            'year'  => "SELECT MONTH(created_at) as month, COUNT(*) as orders, SUM(total) as revenue FROM orders WHERE YEAR(created_at)=YEAR(NOW()) GROUP BY MONTH(created_at) ORDER BY month",
            default => "SELECT DATE(created_at) as date, COUNT(*) as orders, SUM(total) as revenue FROM orders WHERE MONTH(created_at)=MONTH(NOW()) GROUP BY DATE(created_at) ORDER BY date"
        };
        $rows=q($sql);
        ok(['sales'=>$rows,'period'=>$period,'currency'=>'FCFA']);
    }
    err("Unknown stats endpoint",404);

default:
    err("Endpoint not found: /{$version}/{$resource} — See /v1/health for full list",404);
}
