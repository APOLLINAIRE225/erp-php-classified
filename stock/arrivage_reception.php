<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

/**
 * ═══════════════════════════════════════════════════════════════
 * RÉCEPTION D'ARRIVAGE v2.0 — ESPERANCE H2O
 * Style : Dark Neon · C059 Bold
 * + Onglet LOGS complet (tracker toutes actions)
 * + Export Excel filtré (Société / Ville / Produit / Date)
 * + Correction typos dans les logs (edit inline)
 * + Table arrivage_logs auto-créée
 * ═══════════════════════════════════════════════════════════════
 */
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once APP_ROOT . '/app/core/DB.php';
require_once APP_ROOT . '/app/core/Auth.php';
require_once APP_ROOT . '/app/core/Middleware.php';
require APP_ROOT . '/vendor/autoload.php';

use App\Core\DB;
use App\Core\Auth;
use App\Core\Middleware;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

Auth::check();
Middleware::role(['developer', 'admin', 'manager']);

$pdo = DB::getConnection();
$pdo->exec("SET NAMES utf8mb4");

/* ─── Tables ─── */
$pdo->exec("CREATE TABLE IF NOT EXISTS arrivages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    city_id INT NOT NULL,
    arrivage_number VARCHAR(50) NOT NULL UNIQUE,
    delivery_date DATE NOT NULL,
    supplier VARCHAR(255),
    notes TEXT,
    status ENUM('draft','validated','cancelled') NOT NULL DEFAULT 'draft',
    validated_by INT DEFAULT NULL,
    validated_at DATETIME DEFAULT NULL,
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_company_city (company_id, city_id),
    INDEX idx_status (status),
    INDEX idx_delivery_date (delivery_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS arrivage_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    arrivage_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity_ordered INT NOT NULL,
    quantity_received INT NOT NULL DEFAULT 0,
    quantity_broken INT NOT NULL DEFAULT 0,
    quantity_extra INT NOT NULL DEFAULT 0,
    unit_type ENUM('carton','bouteille','piece') NOT NULL DEFAULT 'carton',
    notes TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_arrivage (arrivage_id),
    INDEX idx_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS arrivage_logs (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    company_id    INT NOT NULL,
    city_id       INT NOT NULL,
    arrivage_id   INT DEFAULT NULL,
    user_id       INT NOT NULL,
    username      VARCHAR(120) NOT NULL,
    action        VARCHAR(80) NOT NULL,
    entity_type   VARCHAR(60) DEFAULT NULL,
    entity_id     INT DEFAULT NULL,
    old_value     TEXT DEFAULT NULL,
    new_value     TEXT DEFAULT NULL,
    details       TEXT DEFAULT NULL,
    ip_address    VARCHAR(45) DEFAULT NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_company_city (company_id, city_id),
    INDEX idx_arrivage (arrivage_id),
    INDEX idx_user (user_id),
    INDEX idx_action (action),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* ─── Identité utilisateur (toujours fraîche depuis DB) ─── */
$user_id = $_SESSION['user_id'] ?? 0;
$st_u = $pdo->prepare("SELECT username, role FROM users WHERE id=?");
$st_u->execute([$user_id]);
$__u = $st_u->fetch(PDO::FETCH_ASSOC);
$user_name = $__u['username'] ?? ($_SESSION['username'] ?? 'Admin');
$user_role = $__u['role']     ?? ($_SESSION['role']     ?? '');

/* ─── Logger ─── */
function logArrivage($pdo, $cid, $vid, $uid, $uname, $action, $arr_id=null,
                     $etype=null, $eid=null, $old=null, $new=null, $details=null) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
    $pdo->prepare("INSERT INTO arrivage_logs
        (company_id,city_id,arrivage_id,user_id,username,action,entity_type,entity_id,old_value,new_value,details,ip_address)
        VALUES(?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$cid,$vid,$arr_id,$uid,$uname,$action,$etype,$eid,$old,$new,$details,$ip]);
}

/* ─── Session localisation ─── */
if (!isset($_SESSION['arrivage_company_id'])) $_SESSION['arrivage_company_id'] = 0;
if (!isset($_SESSION['arrivage_city_id']))    $_SESSION['arrivage_city_id']    = 0;

if (isset($_GET['company_id'])) $_SESSION['arrivage_company_id'] = (int)$_GET['company_id'];
if (isset($_GET['confirm_location'], $_GET['city_id'])) {
    $_SESSION['arrivage_city_id'] = (int)$_GET['city_id'];
    header("Location: arrivage_reception.php"); exit;
}

$company_id   = $_SESSION['arrivage_company_id'];
$city_id      = $_SESSION['arrivage_city_id'];
$location_set = ($company_id > 0 && $city_id > 0);

/* ─── Référentiels ─── */
$companies = $pdo->query("SELECT id,name FROM companies ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$cities = [];
if ($company_id) {
    $st = $pdo->prepare("SELECT id,name FROM cities WHERE company_id=? ORDER BY name");
    $st->execute([$company_id]); $cities = $st->fetchAll(PDO::FETCH_ASSOC);
}
$all_cities    = $pdo->query("SELECT id,name,company_id FROM cities ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$all_companies = $companies;

$products = [];
if ($location_set) {
    $st = $pdo->prepare("SELECT id,name,price,category FROM products WHERE company_id=? ORDER BY name");
    $st->execute([$company_id]); $products = $st->fetchAll(PDO::FETCH_ASSOC);
}
$all_products = $pdo->query("SELECT id,name,category,company_id FROM products ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$view_mode        = $_GET['view'] ?? 'list';
$arrivages        = [];
$current_arrivage = null;

/* ═══════════════════════════════════════════════
   ACTIONS POST
═══════════════════════════════════════════════ */

if (isset($_POST['create_arrivage']) && $location_set) {
    $delivery_date    = $_POST['delivery_date'] ?? date('Y-m-d');
    $supplier         = trim($_POST['supplier'] ?? '');
    $notes            = trim($_POST['notes'] ?? '');
    $arrivage_number  = 'ARR-' . date('Ymd', strtotime($delivery_date)) . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
    $pdo->prepare("INSERT INTO arrivages (company_id,city_id,arrivage_number,delivery_date,supplier,notes,created_by) VALUES(?,?,?,?,?,?,?)")
        ->execute([$company_id,$city_id,$arrivage_number,$delivery_date,$supplier,$notes,$user_id]);
    $new_id = $pdo->lastInsertId();
    logArrivage($pdo,$company_id,$city_id,$user_id,$user_name,'CREATION_ARRIVAGE',$new_id,
        'arrivage',$new_id,null,$arrivage_number,"Fournisseur: $supplier | Date: $delivery_date");
    header("Location: arrivage_reception.php?view=edit&id=$new_id"); exit;
}

if (isset($_POST['add_item']) && $location_set) {
    $arrivage_id      = (int)$_POST['arrivage_id'];
    $product_id       = (int)$_POST['product_id'];
    $quantity_ordered = (int)$_POST['quantity_ordered'];
    $unit_type        = $_POST['unit_type'] ?? 'carton';
    $pdo->prepare("INSERT INTO arrivage_items (arrivage_id,product_id,quantity_ordered,unit_type) VALUES(?,?,?,?)")
        ->execute([$arrivage_id,$product_id,$quantity_ordered,$unit_type]);
    $item_id = $pdo->lastInsertId();
    $pname = $pdo->prepare("SELECT name FROM products WHERE id=?");
    $pname->execute([$product_id]); $pname = $pname->fetchColumn();
    logArrivage($pdo,$company_id,$city_id,$user_id,$user_name,'AJOUT_PRODUIT',$arrivage_id,
        'item',$item_id,null,"$pname x$quantity_ordered $unit_type","Ajout produit dans arrivage #$arrivage_id");
    header("Location: arrivage_reception.php?view=edit&id=$arrivage_id"); exit;
}

if (isset($_POST['update_reception']) && $location_set) {
    $item_id           = (int)$_POST['item_id'];
    $quantity_received = (int)$_POST['quantity_received'];
    $quantity_broken   = (int)$_POST['quantity_broken'];
    $quantity_extra    = (int)$_POST['quantity_extra'];
    $notes_item        = trim($_POST['item_notes'] ?? '');
    /* Lire ancien état pour log */
    $old = $pdo->prepare("SELECT ai.*,p.name product_name,a.arrivage_number FROM arrivage_items ai JOIN products p ON p.id=ai.product_id JOIN arrivages a ON a.id=ai.arrivage_id WHERE ai.id=?");
    $old->execute([$item_id]); $old = $old->fetch(PDO::FETCH_ASSOC);
    $pdo->prepare("UPDATE arrivage_items SET quantity_received=?,quantity_broken=?,quantity_extra=?,notes=? WHERE id=?")
        ->execute([$quantity_received,$quantity_broken,$quantity_extra,$notes_item,$item_id]);
    $old_str = "reçu={$old['quantity_received']} cassé={$old['quantity_broken']} extra={$old['quantity_extra']}";
    $new_str = "reçu=$quantity_received cassé=$quantity_broken extra=$quantity_extra";
    logArrivage($pdo,$company_id,$city_id,$user_id,$user_name,'MAJ_RECEPTION',$old['arrivage_id'],
        'item',$item_id,$old_str,$new_str,"Produit: {$old['product_name']} | Arrivage: {$old['arrivage_number']}");
    header("Location: arrivage_reception.php?view=edit&id={$old['arrivage_id']}"); exit;
}

if (isset($_POST['validate_arrivage']) && $location_set) {
    $arrivage_id = (int)$_POST['arrivage_id'];
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT ai.*,p.name product_name FROM arrivage_items ai JOIN products p ON p.id=ai.product_id WHERE ai.arrivage_id=?");
        $st->execute([$arrivage_id]); $items = $st->fetchAll(PDO::FETCH_ASSOC);
        $st2 = $pdo->prepare("SELECT * FROM arrivages WHERE id=?");
        $st2->execute([$arrivage_id]); $arrivage = $st2->fetch(PDO::FETCH_ASSOC);
        foreach ($items as $item) {
            $quantity_net = $item['quantity_received'] - $item['quantity_broken'] + $item['quantity_extra'];
            if ($quantity_net > 0) {
                $reference = "ARRIVAGE-{$arrivage['arrivage_number']}";
                $pdo->prepare("INSERT INTO stock_movements (product_id,reference,company_id,city_id,type,quantity,movement_date) VALUES(?,?,?,?,'entry',?,?)")
                    ->execute([$item['product_id'],$reference,$company_id,$city_id,$quantity_net,$arrivage['delivery_date']]);
                logArrivage($pdo,$company_id,$city_id,$user_id,$user_name,'STOCK_ENTREE',$arrivage_id,
                    'stock_movement',null,null,"+$quantity_net {$item['unit_type']}",
                    "Produit: {$item['product_name']} | Réf: $reference");
            }
        }
        $pdo->prepare("UPDATE arrivages SET status='validated',validated_by=?,validated_at=NOW() WHERE id=?")
            ->execute([$user_id,$arrivage_id]);
        logArrivage($pdo,$company_id,$city_id,$user_id,$user_name,'VALIDATION_ARRIVAGE',$arrivage_id,
            'arrivage',$arrivage_id,'draft','validated',"Arrivage {$arrivage['arrivage_number']} validé — ".count($items)." produit(s)");
        $pdo->commit();
        $success_message = "✅ Arrivage {$arrivage['arrivage_number']} validé et stock mis à jour !";
    } catch (Exception $e) {
        $pdo->rollBack();
        $error_message = "❌ Erreur : " . $e->getMessage();
    }
}

if (isset($_POST['cancel_arrivage']) && $location_set) {
    $arrivage_id = (int)$_POST['arrivage_id'];
    $motif = trim($_POST['cancel_motif'] ?? 'Annulation manuelle');
    $st2 = $pdo->prepare("SELECT arrivage_number FROM arrivages WHERE id=?");
    $st2->execute([$arrivage_id]); $arr_num = $st2->fetchColumn();
    $pdo->prepare("UPDATE arrivages SET status='cancelled',updated_at=NOW() WHERE id=? AND status='draft'")
        ->execute([$arrivage_id]);
    logArrivage($pdo,$company_id,$city_id,$user_id,$user_name,'ANNULATION_ARRIVAGE',$arrivage_id,
        'arrivage',$arrivage_id,'draft','cancelled',"Arrivage $arr_num annulé — Motif: $motif");
    $success_message = "Arrivage annulé.";
}

if (isset($_POST['delete_item'])) {
    $item_id = (int)$_POST['item_id'];
    $old = $pdo->prepare("SELECT ai.*,p.name pname,a.arrivage_number anum FROM arrivage_items ai JOIN products p ON p.id=ai.product_id JOIN arrivages a ON a.id=ai.arrivage_id WHERE ai.id=?");
    $old->execute([$item_id]); $old = $old->fetch(PDO::FETCH_ASSOC);
    $pdo->prepare("DELETE FROM arrivage_items WHERE id=?")->execute([$item_id]);
    logArrivage($pdo,$company_id,$city_id,$user_id,$user_name,'SUPPRESSION_PRODUIT',$old['arrivage_id'],
        'item',$item_id,"{$old['pname']} x{$old['quantity_ordered']}",null,"Retiré de l'arrivage {$old['anum']}");
    header("Location: arrivage_reception.php?view=edit&id={$old['arrivage_id']}"); exit;
}

/* ── CORRECTION TYPO LOG (vue seule, contenu texte uniquement) ── */
if (isset($_POST['edit_log_details'])) {
    $log_id  = (int)$_POST['log_id'];
    $new_det = trim($_POST['new_details'] ?? '');
    /* Lire ancien détail pour audit de l'audit */
    $st_old = $pdo->prepare("SELECT details, action FROM arrivage_logs WHERE id=?");
    $st_old->execute([$log_id]); $old_log = $st_old->fetch(PDO::FETCH_ASSOC);
    /* Seul admin/developer peut modifier */
    if (in_array($user_role, ['admin','developer'])) {
        $pdo->prepare("UPDATE arrivage_logs SET details=? WHERE id=?")->execute([$new_det,$log_id]);
        logArrivage($pdo,$company_id,$city_id,$user_id,$user_name,'CORRECTION_LOG',null,
            'log',$log_id,$old_log['details'],$new_det,"Correction typo sur log [{$old_log['action']}]");
    }
    $view_mode = 'logs';
}

/* ═══════════════════════════════════════════════
   EXPORT EXCEL
═══════════════════════════════════════════════ */
if (isset($_GET['export_excel']) && $location_set) {
    $f_company  = (int)($_GET['f_company']  ?? 0);
    $f_city     = (int)($_GET['f_city']     ?? 0);
    $f_product  = (int)($_GET['f_product']  ?? 0);
    $f_date_from = $_GET['f_date_from'] ?? '';
    $f_date_to   = $_GET['f_date_to']   ?? '';
    $f_status    = $_GET['f_status']    ?? '';

    /* Requête principale */
    $sql = "SELECT
        a.arrivage_number, a.delivery_date, a.supplier, a.status, a.created_at, a.validated_at,
        co.name company_name, ci.name city_name,
        p.name product_name, p.category product_category,
        ai.quantity_ordered, ai.quantity_received, ai.quantity_broken, ai.quantity_extra,
        ai.unit_type, ai.notes item_notes,
        (ai.quantity_received - ai.quantity_broken + ai.quantity_extra) AS quantity_net,
        (ai.quantity_received - ai.quantity_broken + ai.quantity_extra - ai.quantity_ordered) AS difference,
        u.username created_by, v.username validated_by
        FROM arrivages a
        JOIN arrivage_items ai ON ai.arrivage_id = a.id
        JOIN companies co ON co.id = a.company_id
        JOIN cities    ci ON ci.id = a.city_id
        JOIN products  p  ON p.id  = ai.product_id
        LEFT JOIN users u ON u.id = a.created_by
        LEFT JOIN users v ON v.id = a.validated_by
        WHERE 1=1";
    $params = [];

    if ($f_company) { $sql .= " AND a.company_id=?"; $params[] = $f_company; }
    if ($f_city)    { $sql .= " AND a.city_id=?";    $params[] = $f_city; }
    if ($f_product) { $sql .= " AND ai.product_id=?"; $params[] = $f_product; }
    if ($f_date_from) { $sql .= " AND a.delivery_date >= ?"; $params[] = $f_date_from; }
    if ($f_date_to)   { $sql .= " AND a.delivery_date <= ?"; $params[] = $f_date_to; }
    if ($f_status)    { $sql .= " AND a.status=?"; $params[] = $f_status; }
    $sql .= " ORDER BY a.delivery_date DESC, a.arrivage_number, p.name";

    $st = $pdo->prepare($sql); $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    /* Construction Excel avec openpyxl via PHP shell — on utilise PhpSpreadsheet */
    $wb = new Spreadsheet();
    $wb->getProperties()
        ->setTitle("Arrivages ESPERANCE H2O")
        ->setCreator("Caisse Pro v2")
        ->setDescription("Export arrivages " . date('d/m/Y H:i'));

    /* ── Feuille 1 : Détail arrivages ── */
    $sh = $wb->getActiveSheet();
    $sh->setTitle('Arrivages Détail');

    /* En-tête */
    $headers = ['N° Arrivage','Date Livraison','Fournisseur','Société','Ville',
        'Produit','Catégorie','Unité','Commandé','Reçu','Cassé','Extra','Net Stock','Différence',
        'Statut','Créé par','Validé par','Date Création','Date Validation','Notes'];
    foreach ($headers as $ci => $h) {
        $cell = $sh->getCell([$ci+1, 1]);
        $cell->setValue($h);
        $cell->getStyle()->applyFromArray([
            'font'      => ['bold'=>true,'color'=>['argb'=>'FFFFFFFF'],'size'=>11,'name'=>'Arial'],
            'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FF0D1E2C']],
            'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER,'wrapText'=>true],
            'borders'   => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['argb'=>'FF32BE8F']]],
        ]);
    }
    $sh->getRowDimension(1)->setRowHeight(22);

    $STATUS_LABELS = ['draft'=>'Brouillon','validated'=>'Validé','cancelled'=>'Annulé'];
    $row = 2;
    foreach ($rows as $r) {
        $diff = (int)$r['difference'];
        $net  = (int)$r['quantity_net'];
        $data = [
            $r['arrivage_number'],
            date('d/m/Y', strtotime($r['delivery_date'])),
            $r['supplier'] ?? '',
            $r['company_name'],
            $r['city_name'],
            $r['product_name'],
            $r['product_category'] ?? '',
            $r['unit_type'],
            (int)$r['quantity_ordered'],
            (int)$r['quantity_received'],
            (int)$r['quantity_broken'],
            (int)$r['quantity_extra'],
            $net,
            $diff,
            $STATUS_LABELS[$r['status']] ?? $r['status'],
            $r['created_by'] ?? '',
            $r['validated_by'] ?? '',
            $r['created_at'] ? date('d/m/Y H:i', strtotime($r['created_at'])) : '',
            $r['validated_at'] ? date('d/m/Y H:i', strtotime($r['validated_at'])) : '',
            $r['item_notes'] ?? '',
        ];
        foreach ($data as $ci => $val) {
            $cell = $sh->getCell([$ci+1, $row]);
            $cell->setValue($val);
            /* Couleur fond alternée */
            $bg = ($row % 2 === 0) ? 'FF0D1E2C' : 'FF081420';
            $style = ['fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>$bg]],
                      'font'=>['color'=>['argb'=>'FFE0F2EA'],'size'=>10,'name'=>'Arial'],
                      'borders'=>['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['argb'=>'FF1A3040']]]];
            /* Couleur spéciale Différence */
            if ($ci === 13) {
                $style['font']['color']['argb'] = $diff > 0 ? 'FFFFD060' : ($diff < 0 ? 'FFFF3553' : 'FF32BE8F');
                $style['font']['bold'] = true;
            }
            /* Couleur Net stock */
            if ($ci === 12) { $style['font']['color']['argb'] = 'FF19FFA3'; $style['font']['bold'] = true; }
            /* Statut */
            if ($ci === 14) {
                $style['font']['color']['argb'] = $r['status']==='validated' ? 'FF32BE8F' :
                    ($r['status']==='cancelled' ? 'FFFF3553' : 'FFFFD060');
                $style['font']['bold'] = true;
            }
            $cell->getStyle()->applyFromArray($style);
        }
        $sh->getRowDimension($row)->setRowHeight(16);
        $row++;
    }

    /* Largeurs colonnes */
    $widths = [18,14,18,16,14,20,14,10,11,11,11,11,11,12,12,14,14,18,18,30];
    foreach ($widths as $ci => $w) $sh->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ci+1))->setWidth($w);
    $sh->freezePane('A2');
    $sh->setAutoFilter('A1:T1');

    /* ── Feuille 2 : Résumé par produit ── */
    $sh2 = $wb->createSheet();
    $sh2->setTitle('Résumé Produits');
    $h2 = ['Produit','Catégorie','Société','Ville','Total Commandé','Total Reçu','Total Cassé','Total Extra','Net Stock','Nb Arrivages'];
    foreach ($h2 as $ci => $h) {
        $c = $sh2->getCell([$ci+1,1]);
        $c->setValue($h);
        $c->getStyle()->applyFromArray([
            'font'=>['bold'=>true,'color'=>['argb'=>'FFFFFFFF'],'size'=>11,'name'=>'Arial'],
            'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FF19323C']],
            'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER],
            'borders'=>['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['argb'=>'FF32BE8F']]],
        ]);
    }
    /* Agrégation */
    $agg = [];
    foreach ($rows as $r) {
        $key = $r['product_name'].'|'.$r['company_name'].'|'.$r['city_name'];
        if (!isset($agg[$key])) $agg[$key] = ['product'=>$r['product_name'],'cat'=>$r['product_category']??'','co'=>$r['company_name'],'ci'=>$r['city_name'],'ord'=>0,'rec'=>0,'brk'=>0,'ext'=>0,'net'=>0,'nb'=>0];
        $agg[$key]['ord'] += (int)$r['quantity_ordered'];
        $agg[$key]['rec'] += (int)$r['quantity_received'];
        $agg[$key]['brk'] += (int)$r['quantity_broken'];
        $agg[$key]['ext'] += (int)$r['quantity_extra'];
        $agg[$key]['net'] += (int)$r['quantity_net'];
        $agg[$key]['nb']++;
    }
    $r2 = 2;
    foreach ($agg as $a) {
        $sh2->fromArray([$a['product'],$a['cat'],$a['co'],$a['ci'],$a['ord'],$a['rec'],$a['brk'],$a['ext'],$a['net'],$a['nb']], null, "A$r2");
        $bg = ($r2 % 2 === 0) ? 'FF0D1E2C' : 'FF081420';
        $sh2->getStyle("A$r2:J$r2")->applyFromArray([
            'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>$bg]],
            'font'=>['color'=>['argb'=>'FFE0F2EA'],'size'=>10,'name'=>'Arial'],
        ]);
        /* Net en vert */
        $sh2->getCell([9,$r2])->getStyle()->applyFromArray(['font'=>['color'=>['argb'=>'FF19FFA3'],'bold'=>true]]);
        $r2++;
    }
    /* Totaux */
    if ($r2 > 2) {
        $sh2->setCellValue("E$r2", "=SUM(E2:E".($r2-1).")");
        $sh2->setCellValue("F$r2", "=SUM(F2:F".($r2-1).")");
        $sh2->setCellValue("G$r2", "=SUM(G2:G".($r2-1).")");
        $sh2->setCellValue("H$r2", "=SUM(H2:H".($r2-1).")");
        $sh2->setCellValue("I$r2", "=SUM(I2:I".($r2-1).")");
        $sh2->getCell([1,$r2])->setValue("TOTAL");
        $sh2->getStyle("A$r2:J$r2")->applyFromArray([
            'font'=>['bold'=>true,'color'=>['argb'=>'FFFFD060'],'size'=>11],
            'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FF19323C']],
        ]);
    }
    foreach ([24,14,16,14,14,14,14,14,12,12] as $ci => $w) $sh2->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ci+1))->setWidth($w);
    $sh2->freezePane('A2');

    /* ── Feuille 3 : Logs d'activité ── */
    $sh3 = $wb->createSheet();
    $sh3->setTitle('Logs Activité');
    $h3 = ['Date/Heure','Utilisateur','Action','Type Entité','Arrivage','Ancienne Valeur','Nouvelle Valeur','Détails','IP'];
    foreach ($h3 as $ci => $h) {
        $c = $sh3->getCell([$ci+1,1]);
        $c->setValue($h);
        $c->getStyle()->applyFromArray([
            'font'=>['bold'=>true,'color'=>['argb'=>'FFFFFFFF'],'size'=>11,'name'=>'Arial'],
            'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FF122030']],
            'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER],
            'borders'=>['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['argb'=>'FF3D8CFF']]],
        ]);
    }
    /* Requête logs */
    $log_sql = "SELECT al.*, a.arrivage_number FROM arrivage_logs al LEFT JOIN arrivages a ON a.id=al.arrivage_id WHERE al.company_id=?";
    $log_params = [$f_company ?: $company_id];
    if ($f_city)     { $log_sql .= " AND al.city_id=?"; $log_params[] = $f_city ?: $city_id; }
    if ($f_date_from){ $log_sql .= " AND DATE(al.created_at)>=?"; $log_params[] = $f_date_from; }
    if ($f_date_to)  { $log_sql .= " AND DATE(al.created_at)<=?"; $log_params[] = $f_date_to; }
    $log_sql .= " ORDER BY al.created_at DESC LIMIT 2000";
    $st_log = $pdo->prepare($log_sql); $st_log->execute($log_params);
    $log_rows = $st_log->fetchAll(PDO::FETCH_ASSOC);
    $rl = 2;
    foreach ($log_rows as $lr) {
        $sh3->fromArray([
            date('d/m/Y H:i:s', strtotime($lr['created_at'])),
            $lr['username'],
            $lr['action'],
            $lr['entity_type'] ?? '',
            $lr['arrivage_number'] ?? '',
            $lr['old_value'] ?? '',
            $lr['new_value'] ?? '',
            $lr['details'] ?? '',
            $lr['ip_address'] ?? '',
        ], null, "A$rl");
        $bg = ($rl % 2 === 0) ? 'FF0D1E2C' : 'FF081420';
        $sh3->getStyle("A$rl:I$rl")->applyFromArray([
            'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>$bg]],
            'font'=>['color'=>['argb'=>'FFB8D8CC'],'size'=>10,'name'=>'Arial'],
        ]);
        /* Action colorée */
        $action_color = match(true) {
            str_contains($lr['action'],'VALIDATION') => 'FF32BE8F',
            str_contains($lr['action'],'SUPPRESSION')||str_contains($lr['action'],'ANNULATION') => 'FFFF3553',
            str_contains($lr['action'],'STOCK')      => 'FF19FFA3',
            str_contains($lr['action'],'CREATION')   => 'FF3D8CFF',
            str_contains($lr['action'],'CORRECTION') => 'FFFFD060',
            default => 'FFB8D8CC'
        };
        $sh3->getCell([3,$rl])->getStyle()->applyFromArray(['font'=>['color'=>['argb'=>$action_color],'bold'=>true]]);
        $rl++;
    }
    foreach ([18,14,22,14,18,22,22,36,14] as $ci => $w) $sh3->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ci+1))->setWidth($w);
    $sh3->freezePane('A2');
    $sh3->setAutoFilter('A1:I1');

    /* Activer feuille 1 */
    $wb->setActiveSheetIndex(0);

    /* ── Envoi avec encodage Windows-compatible (UTF-8 + BOM via xlsx) ── */
    $filename = 'Arrivages_ESPERANCE_H2O_' . date('Ymd_His') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0, no-cache, no-store');
    header('Pragma: no-cache');
    (new Xlsx($wb))->save('php://output');
    exit;
}

/* ─── Chargement données vue ─── */
if ($location_set) {
    $st = $pdo->prepare("SELECT a.*, u.username created_by_name, v.username validated_by_name,
        (SELECT COUNT(*) FROM arrivage_items WHERE arrivage_id=a.id) items_count
        FROM arrivages a LEFT JOIN users u ON u.id=a.created_by LEFT JOIN users v ON v.id=a.validated_by
        WHERE a.company_id=? AND a.city_id=? ORDER BY a.created_at DESC LIMIT 100");
    $st->execute([$company_id,$city_id]);
    $arrivages = $st->fetchAll(PDO::FETCH_ASSOC);
}

if ($view_mode === 'edit' && isset($_GET['id'])) {
    $arrivage_id = (int)$_GET['id'];
    $st = $pdo->prepare("SELECT a.*,u.username created_by_name FROM arrivages a LEFT JOIN users u ON u.id=a.created_by WHERE a.id=? AND a.company_id=? AND a.city_id=?");
    $st->execute([$arrivage_id,$company_id,$city_id]);
    $current_arrivage = $st->fetch(PDO::FETCH_ASSOC);
    if ($current_arrivage) {
        $st2 = $pdo->prepare("SELECT ai.*,p.name product_name,p.category FROM arrivage_items ai JOIN products p ON p.id=ai.product_id WHERE ai.arrivage_id=? ORDER BY p.name");
        $st2->execute([$arrivage_id]);
        $current_arrivage['items'] = $st2->fetchAll(PDO::FETCH_ASSOC);
    }
}

/* ─── Logs filtrés ─── */
$logs        = [];
$log_filter  = [];
if ($view_mode === 'logs' && $location_set) {
    $lf_action   = $_GET['lf_action']   ?? '';
    $lf_user     = $_GET['lf_user']     ?? '';
    $lf_from     = $_GET['lf_from']     ?? '';
    $lf_to       = $_GET['lf_to']       ?? date('Y-m-d');
    $lf_arrivage = $_GET['lf_arrivage'] ?? '';
    $lf_product  = (int)($_GET['lf_product'] ?? 0);

    $lsql = "SELECT al.*, a.arrivage_number, a.supplier FROM arrivage_logs al
             LEFT JOIN arrivages a ON a.id=al.arrivage_id
             WHERE al.company_id=? AND al.city_id=?";
    $lparams = [$company_id,$city_id];
    if ($lf_action)   { $lsql .= " AND al.action=?"; $lparams[] = $lf_action; }
    if ($lf_user)     { $lsql .= " AND al.username LIKE ?"; $lparams[] = "%$lf_user%"; }
    if ($lf_from)     { $lsql .= " AND DATE(al.created_at)>=?"; $lparams[] = $lf_from; }
    if ($lf_to)       { $lsql .= " AND DATE(al.created_at)<=?"; $lparams[] = $lf_to; }
    if ($lf_arrivage) { $lsql .= " AND a.arrivage_number LIKE ?"; $lparams[] = "%$lf_arrivage%"; }
    $lsql .= " ORDER BY al.created_at DESC LIMIT 500";
    $st_l = $pdo->prepare($lsql); $st_l->execute($lparams);
    $logs = $st_l->fetchAll(PDO::FETCH_ASSOC);

    /* Listes pour filtres */
    $st_users = $pdo->prepare("SELECT DISTINCT username FROM arrivage_logs WHERE company_id=? AND city_id=? ORDER BY username");
    $st_users->execute([$company_id,$city_id]);
    $log_users = $st_users->fetchAll(PDO::FETCH_COLUMN);
    $st_actions = $pdo->prepare("SELECT DISTINCT action FROM arrivage_logs WHERE company_id=? AND city_id=? ORDER BY action");
    $st_actions->execute([$company_id,$city_id]);
    $log_actions = $st_actions->fetchAll(PDO::FETCH_COLUMN);
}

/* ─── KPI rapides ─── */
$kpi = ['total'=>0,'validated'=>0,'draft'=>0,'cancelled'=>0,'items_total'=>0];
if ($location_set) {
    $st = $pdo->prepare("SELECT status, COUNT(*) nb FROM arrivages WHERE company_id=? AND city_id=? GROUP BY status");
    $st->execute([$company_id,$city_id]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $k) { $kpi[$k['status']] = (int)$k['nb']; $kpi['total'] += (int)$k['nb']; }
    $st2 = $pdo->prepare("SELECT COUNT(*) FROM arrivage_items ai JOIN arrivages a ON a.id=ai.arrivage_id WHERE a.company_id=? AND a.city_id=?");
    $st2->execute([$company_id,$city_id]);
    $kpi['items_total'] = (int)$st2->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Réception Arrivage — ESPERANCE H2O</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=Source+Serif+4:wght@700;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
@font-face{font-family:'C059';src:local('C059-Bold'),local('C059 Bold'),local('Century Schoolbook');font-weight:700 900;}
:root{
    --bg:#04090e;--surf:#081420;--card:#0d1e2c;--card2:#122030;
    --bord:rgba(50,190,143,0.16);
    --neon:#32be8f;--neon2:#19ffa3;
    --red:#ff3553;--orange:#ff9140;--blue:#3d8cff;--gold:#ffd060;
    --purple:#a855f7;--cyan:#06b6d4;
    --text:#e0f2ea;--text2:#b8d8cc;--muted:#5a8070;
    --glow:0 0 26px rgba(50,190,143,0.45);
    --glow-r:0 0 26px rgba(255,53,83,0.45);
    --glow-gold:0 0 26px rgba(255,208,96,0.4);
    --glow-cyan:0 0 26px rgba(6,182,212,0.4);
    --glow-blue:0 0 26px rgba(61,140,255,0.4);
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
.wrap{position:relative;z-index:1;max-width:1700px;margin:0 auto;padding:16px 16px 48px;}

/* TOPBAR */
.topbar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;
    background:rgba(8,20,32,0.94);border:1px solid var(--bord);border-radius:18px;
    padding:18px 28px;margin-bottom:16px;backdrop-filter:blur(24px);}
.brand{display:flex;align-items:center;gap:16px;}
.brand-ico{width:50px;height:50px;background:linear-gradient(135deg,var(--gold),var(--orange));
    border-radius:14px;display:flex;align-items:center;justify-content:center;
    font-size:24px;color:#fff;box-shadow:var(--glow-gold);animation:breathe 3.2s ease-in-out infinite;}
@keyframes breathe{0%,100%{box-shadow:0 0 14px rgba(255,208,96,0.4);}50%{box-shadow:0 0 38px rgba(255,208,96,0.85);}}
.brand-txt h1{font-family:var(--fh);font-size:22px;font-weight:900;color:var(--text);letter-spacing:0.5px;}
.brand-txt p{font-size:11px;font-weight:700;color:var(--gold);letter-spacing:2.8px;text-transform:uppercase;margin-top:3px;}
.user-badge{display:flex;align-items:center;gap:10px;background:linear-gradient(135deg,var(--gold),var(--orange));
    color:var(--bg);padding:11px 22px;border-radius:32px;font-family:var(--fh);font-size:14px;font-weight:900;box-shadow:var(--glow-gold);}
.clock-d{font-family:var(--fh);font-size:30px;font-weight:900;color:var(--gold);letter-spacing:4px;text-shadow:0 0 22px rgba(255,208,96,0.55);}
.clock-sub{font-size:11px;color:var(--muted);letter-spacing:1.5px;text-transform:uppercase;margin-top:4px;}

/* NAV */
.nav-bar{display:flex;align-items:center;flex-wrap:wrap;gap:8px;
    background:rgba(8,20,32,0.90);border:1px solid var(--bord);border-radius:16px;
    padding:14px 22px;margin-bottom:18px;backdrop-filter:blur(20px);}
.nb{display:flex;align-items:center;gap:8px;padding:10px 18px;border-radius:12px;
    border:1.5px solid var(--bord);background:rgba(255,208,96,0.07);color:var(--text2);
    font-family:var(--fh);font-size:13px;font-weight:900;text-decoration:none;white-space:nowrap;
    letter-spacing:0.4px;transition:all 0.28s cubic-bezier(0.23,1,0.32,1);}
.nb:hover{background:var(--gold);color:var(--bg);border-color:var(--gold);box-shadow:var(--glow-gold);transform:translateY(-2px);}
.nb.active,.nb.active:hover{background:var(--gold);color:var(--bg);border-color:var(--gold);box-shadow:var(--glow-gold);}
.nb.green{border-color:rgba(50,190,143,0.3);color:var(--neon);background:rgba(50,190,143,0.07);}
.nb.green.active,.nb.green:hover{background:var(--neon);color:var(--bg);border-color:var(--neon);box-shadow:var(--glow);}
.nb.red{border-color:rgba(255,53,83,0.3);color:var(--red);background:rgba(255,53,83,0.07);}
.nb.red:hover{background:var(--red);color:#fff;border-color:var(--red);box-shadow:var(--glow-r);}
.nb.blue{border-color:rgba(61,140,255,0.3);color:var(--blue);background:rgba(61,140,255,0.07);}
.nb.blue.active,.nb.blue:hover{background:var(--blue);color:#fff;border-color:var(--blue);box-shadow:var(--glow-blue);}
.nb.cyan{border-color:rgba(6,182,212,0.3);color:var(--cyan);background:rgba(6,182,212,0.07);}
.nb.cyan.active,.nb.cyan:hover{background:var(--cyan);color:var(--bg);border-color:var(--cyan);box-shadow:var(--glow-cyan);}
.nb.purple{border-color:rgba(168,85,247,0.3);color:var(--purple);background:rgba(168,85,247,0.07);}
.nb.purple.active,.nb.purple:hover{background:var(--purple);color:#fff;border-color:var(--purple);}

/* KPI STRIP */
.kpi-strip{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:20px;}
.ks{background:var(--card);border:1px solid var(--bord);border-radius:16px;padding:20px 18px;
    display:flex;align-items:center;gap:14px;transition:all 0.3s;}
.ks:hover{transform:translateY(-4px);box-shadow:0 14px 32px rgba(0,0,0,0.38),var(--glow);}
.ks-ico{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;}
.ks-val{font-family:var(--fh);font-size:24px;font-weight:900;color:var(--text);line-height:1;}
.ks-lbl{font-family:var(--fb);font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-top:4px;}

/* PANEL */
.panel{background:var(--card);border:1px solid var(--bord);border-radius:18px;overflow:hidden;margin-bottom:18px;transition:border-color 0.3s;animation:fadeUp .4s ease backwards;}
.panel:hover{border-color:rgba(50,190,143,0.26);}
@keyframes fadeUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
.ph{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;
    padding:18px 22px;border-bottom:1px solid rgba(255,255,255,0.05);background:rgba(0,0,0,0.18);}
.ph-title{font-family:var(--fh);font-size:15px;font-weight:900;color:var(--text);
    display:flex;align-items:center;gap:10px;letter-spacing:0.4px;}
.dot{width:9px;height:9px;border-radius:50%;background:var(--neon);box-shadow:0 0 9px var(--neon);flex-shrink:0;animation:pdot 2.2s infinite;}
.dot.r{background:var(--red);box-shadow:0 0 9px var(--red);}
.dot.g{background:var(--gold);box-shadow:0 0 9px var(--gold);}
.dot.o{background:var(--orange);box-shadow:0 0 9px var(--orange);}
.dot.b{background:var(--blue);box-shadow:0 0 9px var(--blue);}
.dot.c{background:var(--cyan);box-shadow:0 0 9px var(--cyan);}
.dot.p{background:var(--purple);box-shadow:0 0 9px var(--purple);}
@keyframes pdot{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(.75)}}
.pb{padding:20px 22px;}

/* FORM */
.f-label{font-family:var(--fh);font-size:12px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:1.2px;display:block;margin-bottom:7px;}
.f-select,.f-input{width:100%;padding:12px 16px;background:rgba(0,0,0,0.3);border:1.5px solid var(--bord);border-radius:12px;color:var(--text);font-family:var(--fb);font-size:14px;font-weight:600;margin-bottom:12px;transition:all 0.3s;appearance:none;}
.f-select:focus,.f-input:focus{outline:none;border-color:var(--neon);box-shadow:var(--glow);background:rgba(50,190,143,0.05);}
.f-select option{background:#0d1e2c;color:var(--text);}
.f-textarea{width:100%;padding:12px 16px;background:rgba(0,0,0,0.3);border:1.5px solid var(--bord);border-radius:12px;color:var(--text);font-family:var(--fb);font-size:14px;margin-bottom:12px;transition:all 0.3s;resize:vertical;min-height:72px;}
.f-textarea:focus{outline:none;border-color:var(--cyan);box-shadow:var(--glow-cyan);}
.fgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;margin-bottom:16px;}
.fgrid3{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:14px;}
.fgrid4{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:14px;}

/* BOUTONS */
.btn{display:inline-flex;align-items:center;gap:8px;padding:12px 22px;border-radius:12px;border:none;cursor:pointer;font-family:var(--fh);font-size:13px;font-weight:900;letter-spacing:0.4px;transition:all 0.28s;text-decoration:none;white-space:nowrap;}
.btn-neon{background:rgba(50,190,143,0.12);border:1.5px solid rgba(50,190,143,0.3);color:var(--neon);}
.btn-neon:hover{background:var(--neon);color:var(--bg);box-shadow:var(--glow);}
.btn-gold{background:rgba(255,208,96,0.12);border:1.5px solid rgba(255,208,96,0.3);color:var(--gold);}
.btn-gold:hover{background:var(--gold);color:var(--bg);box-shadow:var(--glow-gold);}
.btn-red{background:rgba(255,53,83,0.12);border:1.5px solid rgba(255,53,83,0.3);color:var(--red);}
.btn-red:hover{background:var(--red);color:#fff;box-shadow:var(--glow-r);}
.btn-blue{background:rgba(61,140,255,0.12);border:1.5px solid rgba(61,140,255,0.3);color:var(--blue);}
.btn-blue:hover{background:var(--blue);color:#fff;box-shadow:var(--glow-blue);}
.btn-cyan{background:rgba(6,182,212,0.12);border:1.5px solid rgba(6,182,212,0.3);color:var(--cyan);}
.btn-cyan:hover{background:var(--cyan);color:var(--bg);box-shadow:var(--glow-cyan);}
.btn-orange{background:rgba(255,145,64,0.12);border:1.5px solid rgba(255,145,64,0.3);color:var(--orange);}
.btn-orange:hover{background:var(--orange);color:#fff;}
.btn-purple{background:rgba(168,85,247,0.12);border:1.5px solid rgba(168,85,247,0.3);color:var(--purple);}
.btn-purple:hover{background:var(--purple);color:#fff;}
.btn-full{width:100%;justify-content:center;padding:15px;font-size:15px;}
.btn-sm{padding:8px 14px;font-size:12px;border-radius:9px;}
.btn-xs{padding:5px 10px;font-size:11px;border-radius:7px;}

/* TABLE */
.tbl{width:100%;border-collapse:collapse;}
.tbl th{font-family:var(--fh);font-size:12px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:1.2px;padding:12px 14px;border-bottom:1px solid rgba(255,255,255,0.06);text-align:left;background:rgba(0,0,0,0.15);white-space:nowrap;}
.tbl td{font-family:var(--fb);font-size:13px;font-weight:500;color:var(--text2);padding:13px 14px;border-bottom:1px solid rgba(255,255,255,0.04);line-height:1.55;vertical-align:middle;}
.tbl tr:last-child td{border-bottom:none;}
.tbl tbody tr{transition:all 0.25s;}
.tbl tbody tr:hover{background:rgba(50,190,143,0.04);}

/* BADGES */
.bdg{font-family:var(--fb);font-size:10px;font-weight:800;padding:4px 11px;border-radius:20px;letter-spacing:0.5px;display:inline-block;}
.bdg-g{background:rgba(50,190,143,0.14);color:var(--neon);}
.bdg-r{background:rgba(255,53,83,0.14);color:var(--red);}
.bdg-gold{background:rgba(255,208,96,0.14);color:var(--gold);}
.bdg-blue{background:rgba(61,140,255,0.14);color:var(--blue);}
.bdg-cyan{background:rgba(6,182,212,0.14);color:var(--cyan);}
.bdg-orange{background:rgba(255,145,64,0.14);color:var(--orange);}
.bdg-purple{background:rgba(168,85,247,0.14);color:var(--purple);}
.bdg-muted{background:rgba(90,128,112,0.14);color:var(--muted);}

/* ALERT */
.alert{display:flex;align-items:center;gap:16px;flex-wrap:wrap;border-radius:14px;padding:16px 22px;margin-bottom:18px;}
.alert.success{background:rgba(50,190,143,0.08);border:1px solid rgba(50,190,143,0.25);}
.alert.error{background:rgba(255,53,83,0.08);border:1px solid rgba(255,53,83,0.25);}
.alert i{font-size:22px;flex-shrink:0;}
.alert.success i{color:var(--neon);}
.alert.error i{color:var(--red);}
.alert span{font-weight:700;font-size:14px;}
.alert.success span{color:var(--neon);}
.alert.error span{color:var(--red);}

/* ITEM RECEPTION ROW */
.item-row{background:var(--card2);border:1px solid var(--bord);border-radius:16px;padding:20px;margin-bottom:16px;transition:all 0.3s;}
.item-row:hover{border-color:rgba(50,190,143,0.3);box-shadow:0 8px 24px rgba(0,0,0,0.3);}
.cmp-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:14px 0;}
.cmp-box{text-align:center;padding:14px 10px;background:rgba(0,0,0,0.25);border-radius:12px;border:1px solid var(--bord);}
.cmp-lbl{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;}
.cmp-val{font-family:var(--fh);font-size:26px;font-weight:900;}

/* LOGS */
.log-item{display:flex;gap:12px;align-items:flex-start;padding:12px 0;border-bottom:1px solid rgba(255,255,255,0.04);}
.log-item:last-child{border-bottom:none;}
.log-item:hover{background:rgba(50,190,143,0.03);margin:0 -8px;padding:12px 8px;border-radius:8px;}
.log-time{font-family:var(--fh);font-size:11px;font-weight:900;color:var(--muted);white-space:nowrap;flex-shrink:0;min-width:75px;}
.log-action{font-family:var(--fb);font-size:10px;font-weight:800;padding:3px 10px;border-radius:10px;flex-shrink:0;white-space:nowrap;}
.log-desc{font-family:var(--fb);font-size:12px;color:var(--text2);line-height:1.6;flex:1;}

/* LOG EDIT INLINE */
.log-edit-btn{background:none;border:none;cursor:pointer;color:var(--muted);font-size:12px;padding:3px 6px;border-radius:6px;transition:all 0.2s;flex-shrink:0;}
.log-edit-btn:hover{color:var(--gold);background:rgba(255,208,96,0.1);}
.log-edit-form{display:none;margin-top:8px;}
.log-edit-form textarea{width:100%;padding:8px 12px;background:rgba(0,0,0,0.3);border:1.5px solid rgba(255,208,96,0.3);border-radius:10px;color:var(--text);font-family:var(--fb);font-size:12px;resize:vertical;min-height:60px;}
.log-edit-form textarea:focus{outline:none;border-color:var(--gold);box-shadow:var(--glow-gold);}

/* FILTRES EXPORT */
.filter-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;margin-bottom:16px;padding:18px 20px;background:rgba(0,0,0,0.25);border-radius:14px;border:1px solid var(--bord);}

/* SECTION TITLE */
.sec-title{display:flex;align-items:center;gap:14px;margin:24px 0 14px;}
.sec-title h2{font-family:var(--fh);font-size:17px;font-weight:900;color:var(--text);letter-spacing:0.5px;}
.sec-title::after{content:'';flex:1;height:1px;background:linear-gradient(90deg,var(--bord),transparent);}
.sec-line{width:4px;height:22px;border-radius:4px;background:linear-gradient(to bottom,var(--gold),var(--orange));flex-shrink:0;}

/* NET RESULT BOX */
.net-box{margin-top:12px;padding:12px 18px;border-radius:10px;display:flex;justify-content:space-between;align-items:center;font-family:var(--fh);font-weight:900;}

/* EMPTY */
.empty-st{text-align:center;padding:48px 20px;color:var(--muted);}
.empty-st i{font-size:52px;display:block;margin-bottom:14px;opacity:.12;}
.empty-st p{font-size:14px;opacity:.5;margin-bottom:16px;}

/* MODAL */
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.75);z-index:1000;align-items:center;justify-content:center;backdrop-filter:blur(4px);}
.modal.show{display:flex;}
.modal-box{background:var(--card);border:1px solid var(--bord);border-radius:20px;padding:30px;max-width:520px;width:92%;animation:mzoom .22s ease;}
@keyframes mzoom{from{opacity:0;transform:scale(0.9)}to{opacity:1;transform:scale(1)}}
.modal-box h2{font-family:var(--fh);font-size:19px;font-weight:900;color:var(--text);margin-bottom:20px;}

/* LOC BOX */
.loc-box{background:var(--card);border:1px solid var(--bord);border-radius:18px;padding:40px;text-align:center;}
.loc-box h2{font-family:var(--fh);font-size:22px;font-weight:900;color:var(--text);margin-bottom:8px;}
.loc-box p{font-size:14px;color:var(--muted);margin-bottom:28px;}

@media(max-width:1100px){.kpi-strip{grid-template-columns:repeat(3,1fr);}}
@media(max-width:720px){.kpi-strip{grid-template-columns:repeat(2,1fr);}.cmp-grid{grid-template-columns:repeat(2,1fr);}.fgrid,.fgrid3,.fgrid4{grid-template-columns:1fr;}.wrap{padding:12px;}}
</style>
<link rel="stylesheet" href="/stock/stock_mobile_overrides.css">
</head>
<body>
<div class="wrap">

<!-- TOPBAR -->
<div class="topbar">
    <div class="brand">
        <div class="brand-ico"><i class="fas fa-truck-loading"></i></div>
        <div class="brand-txt">
            <h1>Réception Arrivage</h1>
            <p>ESPERANCE H2O &nbsp;·&nbsp; Gestion Livraisons</p>
        </div>
    </div>
    <div style="text-align:center">
        <div class="clock-d" id="clk">--:--:--</div>
        <div class="clock-sub" id="clkd">Chargement…</div>
    </div>
    <div class="user-badge"><i class="fas fa-user-shield"></i> <?= htmlspecialchars($user_name) ?> <span style="opacity:.6;font-size:11px">[<?= htmlspecialchars($user_role) ?>]</span></div>
</div>

<!-- NAV -->
<div class="nav-bar">
    <a href="?view=list"  class="nb <?= $view_mode==='list' ?'active':'' ?>"><i class="fas fa-list"></i> Arrivages</a>
    <a href="?view=new"   class="nb green <?= $view_mode==='new' ?'active':'' ?>"><i class="fas fa-plus-circle"></i> Nouveau</a>
    <a href="?view=logs"  class="nb blue <?= $view_mode==='logs' ?'active':'' ?>"><i class="fas fa-history"></i> Logs &amp; Tracker</a>
    <a href="?view=export" class="nb cyan <?= $view_mode==='export' ?'active':'' ?>"><i class="fas fa-file-excel"></i> Export Excel</a>
    <a href="<?= project_url('stock/stock_update_fixed.php') ?>" class="nb"><i class="fas fa-warehouse"></i> Appro</a>
    <a href="<?= project_url('finance/caisse_complete_enhanced.php') ?>" class="nb"><i class="fas fa-cash-register"></i> Caisse</a>
    <a href="<?= project_url('dashboard/index.php') ?>" class="nb red"><i class="fas fa-home"></i> Accueil</a>
</div>

<!-- ALERTES -->
<?php if(isset($error_message)):?><div class="alert error"><i class="fas fa-exclamation-triangle"></i><span><?=htmlspecialchars($error_message)?></span></div><?php endif;?>
<?php if(isset($success_message)):?><div class="alert success"><i class="fas fa-check-circle"></i><span><?=htmlspecialchars($success_message)?></span></div><?php endif;?>

<?php if(!$location_set): ?>
<div class="loc-box">
    <h2><i class="fas fa-map-marker-alt" style="color:var(--gold)"></i> &nbsp;Sélectionnez votre localisation</h2>
    <p>Choisissez votre société et votre magasin pour accéder à la réception</p>
    <form method="get" style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap">
        <select name="company_id" class="f-select" style="max-width:250px" required onchange="this.form.submit()">
            <option value="">— Société —</option>
            <?php foreach($companies as $c):?>
            <option value="<?=$c['id']?>" <?=$company_id==$c['id']?'selected':''?>><?=htmlspecialchars($c['name'])?></option>
            <?php endforeach;?>
        </select>
        <select name="city_id" class="f-select" style="max-width:250px" required>
            <option value="">— Magasin —</option>
            <?php foreach($cities as $c):?>
            <option value="<?=$c['id']?>" <?=$city_id==$c['id']?'selected':''?>><?=htmlspecialchars($c['name'])?></option>
            <?php endforeach;?>
        </select>
        <button type="submit" name="confirm_location" class="btn btn-neon"><i class="fas fa-check"></i> Valider</button>
    </form>
</div>

<?php else: ?>

<!-- KPI -->
<div class="kpi-strip">
    <div class="ks"><div class="ks-ico" style="background:rgba(61,140,255,0.14);color:var(--blue)"><i class="fas fa-truck"></i></div>
        <div><div class="ks-val" style="color:var(--blue)"><?=$kpi['total']?></div><div class="ks-lbl">Total Arrivages</div></div></div>
    <div class="ks"><div class="ks-ico" style="background:rgba(50,190,143,0.14);color:var(--neon)"><i class="fas fa-check-double"></i></div>
        <div><div class="ks-val" style="color:var(--neon)"><?=$kpi['validated']?></div><div class="ks-lbl">Validés</div></div></div>
    <div class="ks"><div class="ks-ico" style="background:rgba(255,208,96,0.14);color:var(--gold)"><i class="fas fa-pencil-alt"></i></div>
        <div><div class="ks-val" style="color:var(--gold)"><?=$kpi['draft']?></div><div class="ks-lbl">Brouillons</div></div></div>
    <div class="ks"><div class="ks-ico" style="background:rgba(255,53,83,0.14);color:var(--red)"><i class="fas fa-ban"></i></div>
        <div><div class="ks-val" style="color:var(--red)"><?=$kpi['cancelled']?></div><div class="ks-lbl">Annulés</div></div></div>
    <div class="ks"><div class="ks-ico" style="background:rgba(255,145,64,0.14);color:var(--orange)"><i class="fas fa-boxes"></i></div>
        <div><div class="ks-val" style="color:var(--orange)"><?=$kpi['items_total']?></div><div class="ks-lbl">Lignes Produits</div></div></div>
</div>

<?php /* ══════════════════════════════
         VUE : LISTE
══════════════════════════════ */
if($view_mode==='list'): ?>
<div class="panel">
    <div class="ph">
        <div class="ph-title"><div class="dot b"></div> Historique des arrivages</div>
        <span class="bdg bdg-blue"><?=count($arrivages)?> arrivage(s)</span>
    </div>
    <div class="pb">
    <?php if(empty($arrivages)):?>
    <div class="empty-st"><i class="fas fa-truck-loading"></i><p>Aucun arrivage enregistré</p>
        <a href="?view=new" class="btn btn-neon"><i class="fas fa-plus"></i> Premier arrivage</a></div>
    <?php else:?>
    <div style="overflow-x:auto">
    <table class="tbl">
        <thead><tr><th>N° Arrivage</th><th>Date Livraison</th><th>Fournisseur</th><th>Produits</th><th>Statut</th><th>Créé par</th><th>Validé par</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach($arrivages as $arr):
            $sc = match($arr['status']){
                'validated'=>'bdg-g','cancelled'=>'bdg-r',default=>'bdg-gold'};
            $sl = match($arr['status']){
                'validated'=>'✅ Validé','cancelled'=>'❌ Annulé',default=>'📝 Brouillon'};
        ?>
        <tr>
            <td><strong style="color:var(--cyan)"><?=htmlspecialchars($arr['arrivage_number'])?></strong></td>
            <td><?=date('d/m/Y',strtotime($arr['delivery_date']))?></td>
            <td><?=htmlspecialchars($arr['supplier']??'—')?></td>
            <td><span class="bdg bdg-blue"><?=$arr['items_count']?> produit(s)</span></td>
            <td><span class="bdg <?=$sc?>"><?=$sl?></span></td>
            <td style="color:var(--neon)"><?=htmlspecialchars($arr['created_by_name']??'—')?></td>
            <td style="color:var(--muted)"><?=htmlspecialchars($arr['validated_by_name']??'—')?></td>
            <td>
                <a href="?view=edit&id=<?=$arr['id']?>" class="btn btn-blue btn-xs"><i class="fas fa-eye"></i> Ouvrir</a>
                <?php if($arr['status']==='draft'):?>
                <button onclick="openCancelModal(<?=$arr['id']?>, '<?=addslashes($arr['arrivage_number'])?>')" class="btn btn-red btn-xs"><i class="fas fa-ban"></i></button>
                <?php endif;?>
            </td>
        </tr>
        <?php endforeach;?>
        </tbody>
    </table>
    </div>
    <?php endif;?>
    </div>
</div>

<?php /* ══════════════════════════════
         VUE : NOUVEAU
══════════════════════════════ */
elseif($view_mode==='new'): ?>
<div class="panel">
    <div class="ph"><div class="ph-title"><div class="dot g"></div> Créer un nouvel arrivage</div></div>
    <div class="pb">
        <form method="post">
            <div class="fgrid">
                <div><label class="f-label"><i class="fas fa-calendar"></i> Date de livraison *</label>
                    <input type="date" name="delivery_date" class="f-input" value="<?=date('Y-m-d')?>" required></div>
                <div><label class="f-label"><i class="fas fa-industry"></i> Fournisseur</label>
                    <input type="text" name="supplier" class="f-input" placeholder="Ex: Grand Master"></div>
            </div>
            <label class="f-label"><i class="fas fa-sticky-note"></i> Notes / Observations</label>
            <textarea name="notes" class="f-textarea" placeholder="Ex: Arrivage spécial, condition particulières…"></textarea>
            <button type="submit" name="create_arrivage" class="btn btn-neon"><i class="fas fa-check"></i> Créer l'arrivage</button>
        </form>
    </div>
</div>

<?php /* ══════════════════════════════
         VUE : ÉDITION
══════════════════════════════ */
elseif($view_mode==='edit' && $current_arrivage): ?>
<div class="panel">
    <div class="ph">
        <div class="ph-title">
            <div class="dot <?=$current_arrivage['status']==='validated'?'':'g'?>"></div>
            <i class="fas fa-clipboard-list"></i> &nbsp;<?=htmlspecialchars($current_arrivage['arrivage_number'])?>
            <?php
            $sc2=match($current_arrivage['status']){'validated'=>'bdg-g','cancelled'=>'bdg-r',default=>'bdg-gold'};
            $sl2=match($current_arrivage['status']){'validated'=>'✅ Validé','cancelled'=>'❌ Annulé',default=>'📝 Brouillon'};
            ?><span class="bdg <?=$sc2?>"><?=$sl2?></span>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
            <a href="?view=list" class="btn btn-blue btn-sm"><i class="fas fa-arrow-left"></i> Retour</a>
            <a href="?view=logs&lf_arrivage=<?=urlencode($current_arrivage['arrivage_number'])?>" class="btn btn-purple btn-sm"><i class="fas fa-history"></i> Logs</a>
        </div>
    </div>
    <div class="pb">
        <div style="background:rgba(0,0,0,0.2);border-radius:12px;padding:14px 18px;margin-bottom:20px;font-size:13px;display:flex;gap:24px;flex-wrap:wrap">
            <span><strong style="color:var(--muted)">Date livraison:</strong> <strong><?=date('d/m/Y',strtotime($current_arrivage['delivery_date']))?></strong></span>
            <?php if($current_arrivage['supplier']):?><span><strong style="color:var(--muted)">Fournisseur:</strong> <strong><?=htmlspecialchars($current_arrivage['supplier'])?></strong></span><?php endif;?>
            <span><strong style="color:var(--muted)">Créé par:</strong> <span style="color:var(--neon)"><?=htmlspecialchars($current_arrivage['created_by_name'])?></span></span>
            <span><strong style="color:var(--muted)">Créé le:</strong> <?=date('d/m/Y H:i',strtotime($current_arrivage['created_at']))?></span>
        </div>

        <?php if($current_arrivage['status']==='draft'):?>
        <!-- Ajout produit -->
        <div style="background:rgba(6,182,212,0.05);border:1px solid rgba(6,182,212,0.2);border-radius:14px;padding:20px;margin-bottom:20px">
            <div style="font-family:var(--fh);font-size:14px;font-weight:900;color:var(--cyan);margin-bottom:14px"><i class="fas fa-plus-circle"></i> Ajouter un produit</div>
            <form method="post">
                <input type="hidden" name="arrivage_id" value="<?=$current_arrivage['id']?>">
                <div class="fgrid3">
                    <div><label class="f-label">Produit *</label>
                        <select name="product_id" class="f-select" required>
                            <option value="">— Sélectionner —</option>
                            <?php foreach($products as $p):?>
                            <option value="<?=$p['id']?>"><?=htmlspecialchars($p['name'])?><?php if($p['category']):?> (<?=htmlspecialchars($p['category'])?>)<?php endif;?></option>
                            <?php endforeach;?>
                        </select></div>
                    <div><label class="f-label">Quantité commandée *</label>
                        <input type="number" name="quantity_ordered" class="f-input" min="1" placeholder="Ex: 600" required></div>
                    <div><label class="f-label">Unité</label>
                        <select name="unit_type" class="f-select">
                            <option value="carton">📦 Carton</option>
                            <option value="bouteille">🍾 Bouteille</option>
                            <option value="piece">🔹 Pièce</option>
                        </select></div>
                </div>
                <button type="submit" name="add_item" class="btn btn-cyan btn-sm"><i class="fas fa-plus"></i> Ajouter au bon</button>
            </form>
        </div>
        <?php endif;?>

        <!-- Produits -->
        <?php if(empty($current_arrivage['items'])):?>
        <div class="empty-st"><i class="fas fa-box-open"></i><p>Aucun produit ajouté à cet arrivage</p></div>
        <?php else:?>
        <?php foreach($current_arrivage['items'] as $item):
            $net  = $item['quantity_received'] - $item['quantity_broken'] + $item['quantity_extra'];
            $diff = $net - $item['quantity_ordered'];
            $net_color = $diff<0?'var(--red)':($diff>0?'var(--gold)':'var(--neon)');
        ?>
        <div class="item-row">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px">
                <div>
                    <strong style="font-family:var(--fh);font-size:16px;color:var(--text)"><?=htmlspecialchars($item['product_name'])?></strong>
                    <?php if($item['category']):?><span class="bdg bdg-muted" style="margin-left:8px"><?=htmlspecialchars($item['category'])?></span><?php endif;?>
                </div>
                <?php if($current_arrivage['status']==='draft'):?>
                <form method="post" onsubmit="return confirm('Supprimer ce produit ?')">
                    <input type="hidden" name="item_id" value="<?=$item['id']?>">
                    <button type="submit" name="delete_item" class="btn btn-red btn-xs"><i class="fas fa-trash"></i> Retirer</button>
                </form>
                <?php endif;?>
            </div>
            <div class="cmp-grid">
                <div class="cmp-box"><div class="cmp-lbl">📋 Commandé</div><div class="cmp-val" style="color:var(--blue)"><?=$item['quantity_ordered']?></div><small style="color:var(--muted)"><?=$item['unit_type']?></small></div>
                <div class="cmp-box"><div class="cmp-lbl">✅ Reçu</div><div class="cmp-val" style="color:var(--neon)"><?=$item['quantity_received']?></div><small style="color:var(--muted)"><?=$item['unit_type']?></small></div>
                <div class="cmp-box"><div class="cmp-lbl">💔 Cassés</div><div class="cmp-val" style="color:var(--red)"><?=$item['quantity_broken']?></div><small style="color:var(--muted)"><?=$item['unit_type']?></small></div>
                <div class="cmp-box"><div class="cmp-lbl">➕ Extra</div><div class="cmp-val" style="color:var(--orange)"><?=$item['quantity_extra']?></div><small style="color:var(--muted)"><?=$item['unit_type']?></small></div>
            </div>
            <div class="net-box" style="background:rgba(<?=$diff<0?'255,53,83':($diff>0?'255,208,96':'50,190,143')?>,0.07);border:1px solid rgba(<?=$diff<0?'255,53,83':($diff>0?'255,208,96':'50,190,143')?>,0.25)">
                <span style="font-size:13px;color:var(--muted)">Net stock:</span>
                <strong style="color:<?=$net_color?>;font-size:18px"><?=$net?> <?=$item['unit_type']?>
                <?php if($diff>0):?><span style="font-size:13px">(+<?=$diff?> surplus)</span>
                <?php elseif($diff<0):?><span style="font-size:13px">(<?=$diff?> manquant)</span>
                <?php else:?><span style="font-size:13px">✓ Conforme</span><?php endif;?></strong>
            </div>
            <?php if($current_arrivage['status']==='draft'):?>
            <form method="post" style="margin-top:14px">
                <input type="hidden" name="item_id" value="<?=$item['id']?>">
                <div class="fgrid3">
                    <div><label class="f-label">Reçu</label><input type="number" name="quantity_received" class="f-input" value="<?=$item['quantity_received']?>" min="0"></div>
                    <div><label class="f-label">Cassés</label><input type="number" name="quantity_broken" class="f-input" value="<?=$item['quantity_broken']?>" min="0"></div>
                    <div><label class="f-label">Extra</label><input type="number" name="quantity_extra" class="f-input" value="<?=$item['quantity_extra']?>" min="0"></div>
                </div>
                <label class="f-label">Notes produit</label>
                <textarea name="item_notes" class="f-textarea" rows="2" placeholder="Ex: Manque 2 cartons, bouteilles abîmées…"><?=htmlspecialchars($item['notes']??'')?></textarea>
                <button type="submit" name="update_reception" class="btn btn-neon btn-sm"><i class="fas fa-save"></i> Enregistrer</button>
            </form>
            <?php elseif($item['notes']):?>
            <div style="margin-top:10px;font-size:12px;color:var(--muted);font-style:italic"><i class="fas fa-comment"></i> <?=nl2br(htmlspecialchars($item['notes']))?></div>
            <?php endif;?>
        </div>
        <?php endforeach;?>

        <?php if($current_arrivage['status']==='draft' && !empty($current_arrivage['items'])):?>
        <form method="post" onsubmit="return confirm('⚠️ Valider cet arrivage ? Le stock sera mis à jour automatiquement et cette action est irréversible.')">
            <input type="hidden" name="arrivage_id" value="<?=$current_arrivage['id']?>">
            <button type="submit" name="validate_arrivage" class="btn btn-neon btn-full" style="margin-top:10px;font-size:16px;background:linear-gradient(135deg,var(--neon),var(--blue));color:var(--bg);border:none;box-shadow:var(--glow)">
                <i class="fas fa-check-double"></i> VALIDER L'ARRIVAGE &amp; METTRE À JOUR LE STOCK
            </button>
        </form>
        <?php endif;?>
        <?php endif;?>
    </div>
</div>

<?php /* ══════════════════════════════
         VUE : LOGS & TRACKER
══════════════════════════════ */
elseif($view_mode==='logs'): ?>

<?php
$lf_action   = $_GET['lf_action']   ?? '';
$lf_user     = $_GET['lf_user']     ?? '';
$lf_from     = $_GET['lf_from']     ?? '';
$lf_to       = $_GET['lf_to']       ?? date('Y-m-d');
$lf_arrivage = $_GET['lf_arrivage'] ?? '';

/* Stats logs */
$nb_by_action = [];
if(!empty($logs)){
    foreach($logs as $l){ $nb_by_action[$l['action']] = ($nb_by_action[$l['action']]??0)+1; }
    arsort($nb_by_action);
}
?>

<!-- Filtres logs -->
<div class="panel">
    <div class="ph"><div class="ph-title"><div class="dot b"></div> <i class="fas fa-filter"></i> &nbsp;Filtres Tracker</div></div>
    <div class="pb">
        <form method="get" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end">
            <input type="hidden" name="view" value="logs">
            <div style="flex:1;min-width:140px">
                <label class="f-label">Action</label>
                <select name="lf_action" class="f-select" style="margin:0">
                    <option value="">— Toutes —</option>
                    <?php foreach(($log_actions??[]) as $a):?>
                    <option value="<?=htmlspecialchars($a)?>" <?=$lf_action===$a?'selected':''?>><?=htmlspecialchars($a)?></option>
                    <?php endforeach;?>
                </select>
            </div>
            <div style="flex:1;min-width:130px">
                <label class="f-label">Utilisateur</label>
                <input type="text" name="lf_user" class="f-input" style="margin:0" placeholder="Nom…" value="<?=htmlspecialchars($lf_user)?>">
            </div>
            <div style="flex:1;min-width:130px">
                <label class="f-label">Arrivage N°</label>
                <input type="text" name="lf_arrivage" class="f-input" style="margin:0" placeholder="ARR-…" value="<?=htmlspecialchars($lf_arrivage)?>">
            </div>
            <div style="flex:1;min-width:130px">
                <label class="f-label">Du</label>
                <input type="date" name="lf_from" class="f-input" style="margin:0" value="<?=htmlspecialchars($lf_from)?>">
            </div>
            <div style="flex:1;min-width:130px">
                <label class="f-label">Au</label>
                <input type="date" name="lf_to" class="f-input" style="margin:0" value="<?=htmlspecialchars($lf_to)?>">
            </div>
            <button type="submit" class="btn btn-blue btn-sm"><i class="fas fa-search"></i> Filtrer</button>
            <a href="?view=logs" class="btn btn-red btn-sm"><i class="fas fa-times"></i> Reset</a>
        </form>
    </div>
</div>

<!-- Stats rapides -->
<?php if(!empty($nb_by_action)):?>
<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px">
    <?php
    $action_colors = [
        'VALIDATION_ARRIVAGE' => ['var(--neon)','rgba(50,190,143,0.12)'],
        'CREATION_ARRIVAGE'   => ['var(--blue)','rgba(61,140,255,0.12)'],
        'AJOUT_PRODUIT'       => ['var(--cyan)','rgba(6,182,212,0.12)'],
        'MAJ_RECEPTION'       => ['var(--gold)','rgba(255,208,96,0.12)'],
        'STOCK_ENTREE'        => ['var(--neon2)','rgba(25,255,163,0.12)'],
        'SUPPRESSION_PRODUIT' => ['var(--red)','rgba(255,53,83,0.12)'],
        'ANNULATION_ARRIVAGE' => ['var(--red)','rgba(255,53,83,0.12)'],
        'CORRECTION_LOG'      => ['var(--orange)','rgba(255,145,64,0.12)'],
    ];
    foreach($nb_by_action as $act => $cnt):
        [$col,$bg] = $action_colors[$act] ?? ['var(--muted)','rgba(90,128,112,0.12)'];
    ?>
    <div style="background:<?=$bg?>;border:1px solid <?=$col?>;border-radius:10px;padding:8px 14px;display:flex;align-items:center;gap:8px;cursor:pointer"
         onclick="document.querySelector('[name=lf_action]').value='<?=htmlspecialchars($act)?>'; document.querySelector('.nb.blue').parentElement.querySelector('form').submit();">
        <span style="font-family:var(--fh);font-size:11px;font-weight:900;color:<?=$col?>"><?=htmlspecialchars($act)?></span>
        <span style="background:<?=$col?>;color:var(--bg);padding:2px 8px;border-radius:10px;font-size:10px;font-weight:900"><?=$cnt?></span>
    </div>
    <?php endforeach;?>
</div>
<?php endif;?>

<!-- Timeline logs -->
<div class="panel">
    <div class="ph">
        <div class="ph-title"><div class="dot b"></div> <i class="fas fa-history"></i> &nbsp;Journal complet des actions</div>
        <span class="bdg bdg-blue"><?=count($logs)?> événement(s)</span>
    </div>
    <div class="pb">
    <?php if(empty($logs)):?>
    <div class="empty-st"><i class="fas fa-history"></i><p>Aucune action trouvée pour ces filtres</p></div>
    <?php else:?>
    <div style="max-height:650px;overflow-y:auto;padding-right:4px">
    <?php foreach($logs as $lg):
        $ac = $lg['action'];
        [$ac_col,$ac_bg] = $action_colors[$ac] ?? ['var(--muted)','rgba(90,128,112,0.12)'];
        $can_edit = in_array($user_role,['admin','developer']);
    ?>
    <div class="log-item">
        <div class="log-time"><?=date('H:i:s',strtotime($lg['created_at']))?><br>
            <span style="font-size:10px;color:var(--muted)"><?=date('d/m',strtotime($lg['created_at']))?></span></div>
        <span class="log-action" style="background:<?=$ac_bg?>;color:<?=$ac_col?>;border:1px solid <?=$ac_col?>36"><?=htmlspecialchars($ac)?></span>
        <div class="log-desc">
            <strong style="color:var(--cyan)"><?=htmlspecialchars($lg['username'])?></strong>
            <?php if($lg['arrivage_number']):?> · <span class="bdg bdg-gold" style="font-size:9px"><?=htmlspecialchars($lg['arrivage_number'])?></span><?php endif;?>
            <?php if($lg['entity_type']):?> · <span style="color:var(--muted);font-size:11px">[<?=htmlspecialchars($lg['entity_type'])?>]</span><?php endif;?>
            <?php if($lg['old_value'] && $lg['new_value']):?>
            <div style="margin-top:5px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                <span style="background:rgba(255,53,83,0.1);border:1px solid rgba(255,53,83,0.2);padding:2px 8px;border-radius:6px;font-size:11px;color:var(--red)">
                    <i class="fas fa-times"></i> <?=htmlspecialchars(mb_strimwidth($lg['old_value'],0,60,'…'))?>
                </span>
                <i class="fas fa-arrow-right" style="color:var(--muted);font-size:10px"></i>
                <span style="background:rgba(50,190,143,0.1);border:1px solid rgba(50,190,143,0.2);padding:2px 8px;border-radius:6px;font-size:11px;color:var(--neon)">
                    <i class="fas fa-check"></i> <?=htmlspecialchars(mb_strimwidth($lg['new_value'],0,60,'…'))?>
                </span>
            </div>
            <?php endif;?>
            <?php if($lg['details']):?>
            <div style="margin-top:4px;font-size:11px;color:var(--muted);font-style:italic" id="log-det-<?=$lg['id']?>">
                <?=htmlspecialchars($lg['details'])?>
            </div>
            <?php if($can_edit):?>
            <button class="log-edit-btn" onclick="toggleLogEdit(<?=$lg['id']?>, <?=htmlspecialchars(json_encode($lg['details']))?> )" title="Corriger typo">
                <i class="fas fa-pencil-alt"></i>
            </button>
            <form method="post" class="log-edit-form" id="log-form-<?=$lg['id']?>">
                <input type="hidden" name="log_id" value="<?=$lg['id']?>">
                <textarea name="new_details" rows="2"><?=htmlspecialchars($lg['details'])?></textarea>
                <div style="display:flex;gap:8px;margin-top:6px">
                    <button type="submit" name="edit_log_details" class="btn btn-gold btn-xs"><i class="fas fa-save"></i> Sauvegarder</button>
                    <button type="button" onclick="toggleLogEdit(<?=$lg['id']?>)" class="btn btn-red btn-xs"><i class="fas fa-times"></i></button>
                </div>
            </form>
            <?php endif;?>
            <?php endif;?>
            <div style="margin-top:4px;font-size:10px;color:var(--muted);opacity:.5">IP: <?=htmlspecialchars($lg['ip_address']??'?')?></div>
        </div>
    </div>
    <?php endforeach;?>
    </div>
    <?php endif;?>
    </div>
</div>

<?php /* ══════════════════════════════
         VUE : EXPORT EXCEL
══════════════════════════════ */
elseif($view_mode==='export'): ?>
<div class="panel">
    <div class="ph">
        <div class="ph-title"><div class="dot c"></div> <i class="fas fa-file-excel"></i> &nbsp;Export Excel Filtré</div>
        <span class="bdg bdg-cyan">3 feuilles · Compatible Windows</span>
    </div>
    <div class="pb">
        <p style="font-size:13px;color:var(--muted);margin-bottom:20px">
            Exportez vos arrivages avec filtres précis. Le fichier Excel contient <strong style="color:var(--text)">3 feuilles</strong> :
            <span class="bdg bdg-blue">Arrivages Détail</span>
            <span class="bdg bdg-g">Résumé Produits</span>
            <span class="bdg bdg-purple">Logs Activité</span>
        </p>
        <form method="get">
            <input type="hidden" name="export_excel" value="1">
            <div style="font-family:var(--fh);font-size:13px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:1.2px;margin-bottom:12px">🔍 Filtres</div>
            <div class="filter-grid">
                <div>
                    <label class="f-label">Société</label>
                    <select name="f_company" class="f-select" style="margin:0" onchange="this.form.submit()">
                        <option value="">— Toutes —</option>
                        <?php foreach($all_companies as $c):?>
                        <option value="<?=$c['id']?>" <?=($_GET['f_company']??0)==$c['id']?'selected':''?>><?=htmlspecialchars($c['name'])?></option>
                        <?php endforeach;?>
                    </select>
                </div>
                <div>
                    <label class="f-label">Ville / Magasin</label>
                    <select name="f_city" class="f-select" style="margin:0">
                        <option value="">— Toutes —</option>
                        <?php foreach($all_cities as $c):?>
                        <option value="<?=$c['id']?>" <?=($_GET['f_city']??0)==$c['id']?'selected':''?>><?=htmlspecialchars($c['name'])?></option>
                        <?php endforeach;?>
                    </select>
                </div>
                <div>
                    <label class="f-label">Produit</label>
                    <select name="f_product" class="f-select" style="margin:0">
                        <option value="">— Tous —</option>
                        <?php foreach($all_products as $p):?>
                        <option value="<?=$p['id']?>" <?=($_GET['f_product']??0)==$p['id']?'selected':''?>><?=htmlspecialchars($p['name'])?></option>
                        <?php endforeach;?>
                    </select>
                </div>
                <div>
                    <label class="f-label">Statut</label>
                    <select name="f_status" class="f-select" style="margin:0">
                        <option value="">— Tous —</option>
                        <option value="draft" <?=($_GET['f_status']??'')==='draft'?'selected':''?>>📝 Brouillon</option>
                        <option value="validated" <?=($_GET['f_status']??'')==='validated'?'selected':''?>>✅ Validé</option>
                        <option value="cancelled" <?=($_GET['f_status']??'')==='cancelled'?'selected':''?>>❌ Annulé</option>
                    </select>
                </div>
                <div>
                    <label class="f-label">Date du</label>
                    <input type="date" name="f_date_from" class="f-input" style="margin:0" value="<?=htmlspecialchars($_GET['f_date_from']??'')?>">
                </div>
                <div>
                    <label class="f-label">Date au</label>
                    <input type="date" name="f_date_to" class="f-input" style="margin:0" value="<?=htmlspecialchars($_GET['f_date_to']??date('Y-m-d'))?>">
                </div>
            </div>
            <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:6px">
                <button type="submit" class="btn btn-neon" style="font-size:15px;padding:14px 28px">
                    <i class="fas fa-download"></i> &nbsp;Télécharger Excel (.xlsx)
                </button>
                <a href="?export_excel=1&f_date_from=2020-01-01&f_date_to=<?=date('Y-m-d')?>" 
                   class="btn btn-gold" style="font-size:14px;padding:14px 24px">
                    <i class="fas fa-globe"></i> &nbsp;Export Global (tout)
                </a>
                <div style="display:flex;flex-direction:column;gap:6px;font-size:12px;color:var(--muted);justify-content:center">
                    <span><i class="fas fa-check" style="color:var(--neon)"></i> Encodage UTF-8 · Compatible Excel Windows</span>
                    <span><i class="fas fa-check" style="color:var(--neon)"></i> Fond sombre Dark Neon + en-têtes colorés</span>
                    <span><i class="fas fa-check" style="color:var(--neon)"></i> Filtres automatiques + Volet figé ligne 1</span>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Aperçu des colonnes -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px">
    <?php
    $sheets_info = [
        ['Arrivages Détail','blue','fas fa-table','20 colonnes détaillées : N° arrivage, dates, fournisseur, société, ville, produit, qté commandée/reçue/cassée/extra, net stock, différence, statut, créé par, validé par…'],
        ['Résumé Produits','neon','fas fa-chart-bar','10 colonnes agrégées par produit : totaux commandé / reçu / cassé / extra / net avec ligne TOTAL automatique en bas'],
        ['Logs Activité','purple','fas fa-history','9 colonnes : date/heure, utilisateur, action, type entité, arrivage, anciennes et nouvelles valeurs, détails, IP'],
    ];
    foreach($sheets_info as [$sn,$sc,$si,$sd]):
    ?>
    <div style="background:var(--card2);border:1px solid var(--bord);border-radius:14px;padding:18px">
        <div style="font-family:var(--fh);font-size:14px;font-weight:900;color:var(--<?=$sc?>);margin-bottom:8px">
            <i class="<?=$si?>"></i> &nbsp;<?=$sn?>
        </div>
        <p style="font-size:12px;color:var(--muted);line-height:1.6"><?=$sd?></p>
    </div>
    <?php endforeach;?>
</div>

<?php endif;?>

<?php endif; // location_set ?>

</div><!-- /wrap -->

<!-- MODAL ANNULER ARRIVAGE -->
<div id="modal-cancel" class="modal">
    <div class="modal-box">
        <h2 style="color:var(--red)"><i class="fas fa-ban"></i> &nbsp;Annuler l'arrivage</h2>
        <p style="font-size:13px;color:var(--muted);margin-bottom:18px">L'arrivage <strong id="cancel-num" style="color:var(--text)"></strong> sera marqué comme annulé. Le stock NE sera PAS affecté.</p>
        <form method="post">
            <input type="hidden" name="arrivage_id" id="cancel-id">
            <label class="f-label">Motif d'annulation</label>
            <input type="text" name="cancel_motif" class="f-input" placeholder="Ex: Erreur de saisie, doublon…">
            <div style="display:flex;gap:12px;margin-top:10px">
                <button type="submit" name="cancel_arrivage" class="btn btn-red" style="flex:1;justify-content:center"><i class="fas fa-ban"></i> Confirmer</button>
                <button type="button" class="btn btn-neon" style="flex:1;justify-content:center" onclick="closeModal('modal-cancel')"><i class="fas fa-arrow-left"></i> Retour</button>
            </div>
        </form>
    </div>
</div>

<script>
/* Horloge */
function tick(){
    const n=new Date();
    document.getElementById('clk').textContent=n.toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
    document.getElementById('clkd').textContent=n.toLocaleDateString('fr-FR',{weekday:'long',day:'numeric',month:'long',year:'numeric'});
}
tick(); setInterval(tick,1000);

/* Modals */
function openModal(id){document.getElementById(id).classList.add('show');}
function closeModal(id){document.getElementById(id).classList.remove('show');}
document.querySelectorAll('.modal').forEach(m=>m.addEventListener('click',e=>{if(e.target===m)m.classList.remove('show');}));

function openCancelModal(id, num){
    document.getElementById('cancel-id').value=id;
    document.getElementById('cancel-num').textContent=num;
    openModal('modal-cancel');
}

/* Toggle éditeur typo log */
function toggleLogEdit(id, val){
    const form=document.getElementById('log-form-'+id);
    if(!form)return;
    const visible=form.style.display==='block';
    form.style.display=visible?'none':'block';
    if(!visible && val!==undefined){
        form.querySelector('textarea').value=val;
        form.querySelector('textarea').focus();
    }
}

console.log('🚀 Arrivage Reception v2.0 — ESPERANCE H2O — Logs + Export Excel');
</script>
</body>
</html>
