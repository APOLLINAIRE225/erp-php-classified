<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

/**
 * ═══════════════════════════════════════════════════════════════
 * SUIVI DES STOCKS PRO v2.0 — ESPERANCE H2O
 * Style: Dark Neon · C059 Bold · Onglets · Export + Ventes Annulées
 * ═══════════════════════════════════════════════════════════════
 */
session_start();
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

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
Middleware::role(['developer', 'admin', 'manager', 'staff']);

$pdo = DB::getConnection();

$user_id   = $_SESSION['user_id'] ?? 0;
$user_name = $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'Utilisateur';
if (!$user_name || $user_name === 'Utilisateur') {
    $st = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $st->execute([$user_id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r) $user_name = $r['username'];
}

/* ── Logger ── */
function logAction($pdo,$uid,$type,$desc,$pid=null,$iid=null,$amt=null,$qty=null) {
    try {
        $ip  = $_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? 'unknown';
        $ua  = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $sid = session_id();
        $st  = $pdo->prepare("INSERT INTO cash_log
            (user_id,session_id,action_type,action_description,product_id,invoice_id,amount,quantity,ip_address,user_agent)
            VALUES(?,?,?,?,?,?,?,?,?,?)");
        $st->execute([$uid,$sid,$type,$desc,$pid,$iid,$amt,$qty,$ip,$ua]);
    } catch(Exception $e){}
}

/* ── Helpers stock ── */
function getStockAtDate($pdo,$pid,$coid,$cid,$date) {
    $st = $pdo->prepare("
        SELECT COALESCE(SUM(CASE WHEN type='initial'    THEN quantity END),0)
             + COALESCE(SUM(CASE WHEN type='entry'      THEN quantity END),0)
             - COALESCE(SUM(CASE WHEN type='exit'       THEN quantity END),0)
             + COALESCE(SUM(CASE WHEN type='adjustment' THEN quantity END),0) s
        FROM stock_movements
        WHERE product_id=? AND company_id=? AND city_id=? AND DATE(movement_date)<=?");
    $st->execute([$pid,$coid,$cid,$date]);
    return (int)$st->fetchColumn();
}
function getInitialDefined($pdo,$pid,$coid,$cid) {
    $st = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM stock_movements
        WHERE product_id=? AND company_id=? AND city_id=? AND type='initial'");
    $st->execute([$pid,$coid,$cid]);
    return (int)$st->fetchColumn();
}
/* Ventes annulées pour un produit sur une période */
function getVentesAnnulees($pdo,$pid,$coid,$cid,$ds,$de) {
    $st = $pdo->prepare("
        SELECT COALESCE(SUM(quantity),0) ann
        FROM stock_movements
        WHERE product_id=? AND company_id=? AND city_id=?
          AND type='entry' AND reference LIKE 'ANNULATION-VENTE-%'
          AND DATE(movement_date) BETWEEN ? AND ?");
    $st->execute([$pid,$coid,$cid,$ds,$de]);
    return (int)$st->fetchColumn();
}

/* ── Session localisation ── */
if (!isset($_SESSION['caisse_company_id'])) $_SESSION['caisse_company_id'] = 0;
if (!isset($_SESSION['caisse_city_id']))    $_SESSION['caisse_city_id']    = 0;
if (isset($_GET['company_id']))   { $_SESSION['caisse_company_id'] = (int)$_GET['company_id']; }
if (isset($_GET['confirm_location'],$_GET['city_id'])) {
    $_SESSION['caisse_city_id'] = (int)$_GET['city_id'];
    logAction($pdo,$user_id,'LOCATION_SET','Localisation confirmée — stock_tracking');
    header("Location: stock_tracking.php"); exit;
}

$company_id   = $_SESSION['caisse_company_id'];
$city_id      = $_SESSION['caisse_city_id'];
$location_set = ($company_id > 0 && $city_id > 0);

$view            = $_GET['view']       ?? 'global';
$date_start      = $_GET['date_start'] ?? date('Y-m-01');
$date_end        = $_GET['date_end']   ?? date('Y-m-d');
$selected_product= $_GET['product_id'] ?? 'all';
$search_q        = trim($_GET['search'] ?? '');
$sort_col        = $_GET['sort']       ?? 'name';
$sort_dir        = $_GET['dir']        ?? 'asc';

/* ── Listes ── */
$companies = $pdo->query("SELECT id,name FROM companies ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$cities = [];
if ($company_id) {
    $st = $pdo->prepare("SELECT id,name FROM cities WHERE company_id=? ORDER BY name");
    $st->execute([$company_id]); $cities = $st->fetchAll(PDO::FETCH_ASSOC);
}
$products = [];
if ($location_set) {
    $st = $pdo->prepare("SELECT id,name,price,alert_quantity FROM products WHERE company_id=? ORDER BY name");
    $st->execute([$company_id]); $products = $st->fetchAll(PDO::FETCH_ASSOC);
}
$company_name = ''; $city_name = '';
foreach ($companies as $c) if ($c['id']==$company_id) $company_name=$c['name'];
if ($city_id) {
    $st = $pdo->prepare("SELECT name FROM cities WHERE id=?"); $st->execute([$city_id]);
    $city_name = $st->fetchColumn();
}

/* ── Log page view ── */
if ($location_set) logAction($pdo,$user_id,'PAGE_VIEW',"Suivi stock — onglet: $view");

/* ══════════════════════════════════════════════════
   EXPORT EXCEL — PRODUIT INDIVIDUEL (+ Ventes Annulées)
══════════════════════════════════════════════════ */
if (isset($_GET['export_product']) && $location_set) {
    $pid = (int)$_GET['export_product'];
    $st  = $pdo->prepare("SELECT name,price,alert_quantity FROM products WHERE id=?");
    $st->execute([$pid]); $prod = $st->fetch(PDO::FETCH_ASSOC);

    $stock_ini_def = getInitialDefined($pdo,$pid,$company_id,$city_id);
    $date_before   = date('Y-m-d',strtotime($date_start.' -1 day'));
    $stock_debut   = getStockAtDate($pdo,$pid,$company_id,$city_id,$date_before);

    $st = $pdo->prepare("
        SELECT sm.*, c.name client_name, i.id invoice_number
        FROM stock_movements sm
        LEFT JOIN invoices i ON sm.reference=CONCAT('VENTE-',i.id) OR sm.reference=CONCAT('DUPLICATA-',i.id)
        LEFT JOIN clients c ON i.client_id=c.id
        WHERE sm.product_id=? AND sm.company_id=? AND sm.city_id=?
          AND DATE(sm.movement_date) BETWEEN ? AND ?
        ORDER BY sm.movement_date ASC, sm.id ASC");
    $st->execute([$pid,$company_id,$city_id,$date_start,$date_end]);
    $mvts = $st->fetchAll(PDO::FETCH_ASSOC);

    $sp = new Spreadsheet();
    $sh = $sp->getActiveSheet(); $sh->setTitle('Suivi Stock');

    /* Titre */
    $sh->mergeCells('A1:I1');
    $sh->setCellValue('A1','SUIVI DE STOCK — '.strtoupper($prod['name']));
    $sh->getStyle('A1')->getFont()->setBold(true)->setSize(16)->getColor()->setARGB('FF1a1a2e');
    $sh->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sh->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF32be8f');

    $sh->mergeCells('A2:I2');
    $sh->setCellValue('A2',"$city_name · Période: $date_start → $date_end");
    $sh->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $sh->mergeCells('A3:I3');
    $sh->setCellValue('A3',"Stock initial saison: $stock_ini_def u | Stock début période: $stock_debut u");
    $sh->getStyle('A3')->getFont()->setBold(true);

    /* En-têtes colonnes avec VENTES ANNULÉES */
    $headers = ['Date','Client','N° Facture','Référence','Type','Quantité','Ventes Annulées','Stock Courant','Note'];
    $sh->fromArray($headers,null,'A5');
    $sh->getStyle('A5:I5')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
    $sh->getStyle('A5:I5')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0d1e2c');

    $row = 6; $running = $stock_debut; $total_ann = 0;

    foreach ($mvts as $mvt) {
        $is_ann = ($mvt['type']==='entry' && str_starts_with($mvt['reference'],'ANNULATION-VENTE-'));
        $ann_qty = $is_ann ? (int)$mvt['quantity'] : 0;
        if ($ann_qty) $total_ann += $ann_qty;

        if ($mvt['type']==='initial')   { $running += $mvt['quantity']; $type_label='STOCK INITIAL'; $color='FF3d8cff'; $qty_str='+'.$mvt['quantity']; }
        elseif ($mvt['type']==='entry' && !$is_ann) { $running += $mvt['quantity']; $type_label='ARRIVAGE'; $color='FF32be8f'; $qty_str='+'.$mvt['quantity']; }
        elseif ($is_ann)                { $running += $mvt['quantity']; $type_label='✗ VENTE ANNULÉE'; $color='FFff3553'; $qty_str='+'.$mvt['quantity']; }
        elseif ($mvt['type']==='adjustment') {
            $running += $mvt['quantity'];
            $type_label = $mvt['quantity']>0?'AJUSTEMENT +':'AJUSTEMENT −';
            $color = $mvt['quantity']>0?'FF32be8f':'FFffd060';
            $qty_str = ($mvt['quantity']>0?'+':'').$mvt['quantity'];
        } else { $running -= $mvt['quantity']; $type_label='SORTIE VENTE'; $color='FFff3553'; $qty_str='-'.$mvt['quantity']; }

        $sh->setCellValue("A$row", date('d/m/Y H:i',strtotime($mvt['movement_date'])));
        $sh->setCellValue("B$row", $mvt['client_name']??'-');
        $sh->setCellValue("C$row", $mvt['invoice_number']?'#'.$mvt['invoice_number']:'-');
        $sh->setCellValue("D$row", $mvt['reference']??'-');
        $sh->setCellValue("E$row", $type_label);
        $sh->setCellValue("F$row", $qty_str);
        $sh->setCellValue("G$row", $ann_qty ? '+' . $ann_qty : '—');
        $sh->setCellValue("H$row", $running);
        $sh->setCellValue("I$row", $is_ann ? 'Retour stock annulation facture' : '');
        $sh->getStyle("E$row:F$row")->getFont()->getColor()->setARGB($color);
        if ($is_ann) $sh->getStyle("A$row:I$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('1Fff3553');
        $row++;
    }

    /* Ligne totaux */
    $row++;
    $sh->setCellValue("A$row",'STOCK FINAL'); $sh->setCellValue("H$row",$running);
    $sh->setCellValue("F$row",'Total ventes annulées:'); $sh->setCellValue("G$row","+$total_ann");
    $sh->getStyle("A$row:I$row")->getFont()->setBold(true);
    $sh->getStyle("A$row:I$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF081420');
    $sh->getStyle("A$row:I$row")->getFont()->getColor()->setARGB('FF32be8f');

    foreach(range('A','I') as $c) $sh->getColumnDimension($c)->setAutoSize(true);
    logAction($pdo,$user_id,'EXPORT_STOCK_PRODUCT',"Export produit: ".$prod['name'],$pid);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="stock_'.str_replace(' ','_',$prod['name']).'_'.date('Y-m-d').'.xlsx"');
    header('Cache-Control: max-age=0');
    (new Xlsx($sp))->save('php://output'); exit;
}

/* ══════════════════════════════════════════════════
   EXPORT EXCEL — RAPPORT GLOBAL (+ Ventes Annulées)
══════════════════════════════════════════════════ */
if (isset($_GET['export_global']) && $location_set) {
    $sp = new Spreadsheet();
    $sh = $sp->getActiveSheet(); $sh->setTitle('Rapport Global');

    $sh->mergeCells('A1:J1');
    $sh->setCellValue('A1','RAPPORT GLOBAL DE STOCK — ESPERANCE H2O');
    $sh->getStyle('A1')->getFont()->setBold(true)->setSize(16)->getColor()->setARGB('FF1a1a2e');
    $sh->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sh->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFffd060');

    $sh->mergeCells('A2:J2');
    $sh->setCellValue('A2',"$city_name · Période: $date_start → $date_end · Exporté le ".date('d/m/Y H:i'));
    $sh->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    /* En-têtes avec Ventes Annulées */
    $headers = ['Produit','Prix (FCFA)','Stock Initial','Arrivages','Ventes Annulées ↩','Ajustements','Sorties Ventes','Stock Actuel','Valeur (FCFA)','Statut'];
    $sh->fromArray($headers,null,'A4');
    $sh->getStyle('A4:J4')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
    $sh->getStyle('A4:J4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0d1e2c');

    $row = 5; $total_valeur = 0;
    foreach ($products as $p) {
        $pid = $p['id'];
        $ini = getInitialDefined($pdo,$pid,$company_id,$city_id);
        $ann = getVentesAnnulees($pdo,$pid,$company_id,$city_id,$date_start,$date_end);

        $st = $pdo->prepare("SELECT
            COALESCE(SUM(CASE WHEN type='entry'  AND reference NOT LIKE 'ANNULATION-VENTE-%' THEN quantity END),0) entrees,
            COALESCE(SUM(CASE WHEN type='exit'   THEN quantity END),0) sorties,
            COALESCE(SUM(CASE WHEN type='adjustment' THEN quantity END),0) ajust
            FROM stock_movements
            WHERE product_id=? AND company_id=? AND city_id=?
              AND DATE(movement_date) BETWEEN ? AND ?");
        $st->execute([$pid,$company_id,$city_id,$date_start,$date_end]);
        $m = $st->fetch(PDO::FETCH_ASSOC);

        $sf = getStockAtDate($pdo,$pid,$company_id,$city_id,date('Y-m-d'));
        $val = $sf * $p['price'];
        $total_valeur += $val;

        $statut = $sf<=0?'RUPTURE':($sf<=$p['alert_quantity']?'ALERTE':'OK');

        $sh->setCellValue("A$row",$p['name']);
        $sh->setCellValue("B$row",$p['price']);
        $sh->setCellValue("C$row",$ini);
        $sh->setCellValue("D$row",(int)$m['entrees']);
        $sh->setCellValue("E$row",$ann?"+$ann":'—');   /* ★ VENTES ANNULÉES */
        $sh->setCellValue("F$row",($m['ajust']>=0?'+':'').(int)$m['ajust']);
        $sh->setCellValue("G$row",(int)$m['sorties']);
        $sh->setCellValue("H$row",$sf);
        $sh->setCellValue("I$row",$val);
        $sh->setCellValue("J$row",$statut);

        /* Couleur statut */
        $sc = $statut==='RUPTURE'?'FFff3553':($statut==='ALERTE'?'FFffd060':'FF32be8f');
        $sh->getStyle("J$row")->getFont()->getColor()->setARGB($sc)->setBold(true);
        /* Ventes annulées en rouge si > 0 */
        if ($ann) $sh->getStyle("E$row")->getFont()->getColor()->setARGB('FFff3553')->setBold(true);
        $row++;
    }

    /* Ligne total */
    $row++;
    $sh->setCellValue("A$row",'TOTAL VALEUR STOCK');
    $sh->setCellValue("I$row",$total_valeur);
    $sh->getStyle("A$row:J$row")->getFont()->setBold(true);
    $sh->getStyle("A$row:J$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0d1e2c');
    $sh->getStyle("A$row:J$row")->getFont()->getColor()->setARGB('FFffd060');

    foreach(range('A','J') as $c) $sh->getColumnDimension($c)->setAutoSize(true);
    logAction($pdo,$user_id,'EXPORT_STOCK_GLOBAL','Export rapport global stock');

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="rapport_global_stock_'.date('Y-m-d').'.xlsx"');
    header('Cache-Control: max-age=0');
    (new Xlsx($sp))->save('php://output'); exit;
}

/* ══════════════════════════════════════════════════
   EXPORT CSV RAPIDE
══════════════════════════════════════════════════ */
if (isset($_GET['export_csv']) && $location_set) {
    logAction($pdo,$user_id,'EXPORT_STOCK_CSV','Export CSV rapport global');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment;filename="stock_'.date('Y-m-d').'.csv"');
    $out = fopen('php://output','w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8
    // PHP 8.1+ : paramètre $escape obligatoire — on utilise "" pour désactiver l'échappement double-quote
    fputcsv($out,['Produit','Prix','Stock Initial','Arrivages','Ventes Annulées','Ajustements','Sorties','Stock Actuel','Valeur','Statut'],';','"','\\');
    foreach ($products as $p) {
        $pid = $p['id'];
        $ini = getInitialDefined($pdo,$pid,$company_id,$city_id);
        $ann = getVentesAnnulees($pdo,$pid,$company_id,$city_id,$date_start,$date_end);
        $st = $pdo->prepare("SELECT
            COALESCE(SUM(CASE WHEN type='entry' AND reference NOT LIKE 'ANNULATION-VENTE-%' THEN quantity END),0) e,
            COALESCE(SUM(CASE WHEN type='exit'  THEN quantity END),0) s,
            COALESCE(SUM(CASE WHEN type='adjustment' THEN quantity END),0) a
            FROM stock_movements WHERE product_id=? AND company_id=? AND city_id=?
            AND DATE(movement_date) BETWEEN ? AND ?");
        $st->execute([$pid,$company_id,$city_id,$date_start,$date_end]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
        $sf = getStockAtDate($pdo,$pid,$company_id,$city_id,date('Y-m-d'));
        $statut = $sf<=0?'RUPTURE':($sf<=$p['alert_quantity']?'ALERTE':'OK');
        fputcsv($out,[
            $p['name'],
            $p['price'],
            $ini,
            (int)$m['e'],
            $ann > 0 ? "+$ann" : '0',
            (int)$m['a'],
            (int)$m['s'],
            $sf,
            $sf * $p['price'],
            $statut
        ],';','"','\\');
    }
    fclose($out); exit;
}

/* ══════════════════════════════════════════════════
   DONNÉES AFFICHAGE
══════════════════════════════════════════════════ */

/* Vue: produit individuel */
$stock_data = null;
if ($location_set && $view === 'produit' && $selected_product !== 'all') {
    $pid = (int)$selected_product;
    $st  = $pdo->prepare("SELECT name,price,alert_quantity FROM products WHERE id=?");
    $st->execute([$pid]); $pi = $st->fetch(PDO::FETCH_ASSOC);

    $ini_def  = getInitialDefined($pdo,$pid,$company_id,$city_id);
    $deb      = date('Y-m-d',strtotime($date_start.' -1 day'));
    $stk_deb  = getStockAtDate($pdo,$pid,$company_id,$city_id,$deb);

    $st = $pdo->prepare("
        SELECT sm.*, c.name client_name, i.id invoice_number
        FROM stock_movements sm
        LEFT JOIN invoices i ON sm.reference=CONCAT('VENTE-',i.id) OR sm.reference=CONCAT('DUPLICATA-',i.id)
        LEFT JOIN clients c ON i.client_id=c.id
        WHERE sm.product_id=? AND sm.company_id=? AND sm.city_id=?
          AND DATE(sm.movement_date) BETWEEN ? AND ?
        ORDER BY sm.movement_date ASC, sm.id ASC");
    $st->execute([$pid,$company_id,$city_id,$date_start,$date_end]);
    $mvts = $st->fetchAll(PDO::FETCH_ASSOC);

    /* Calculs totaux */
    $tot_arr=$tot_sor=$tot_adj=$tot_ann=0;
    foreach ($mvts as $mv) {
        $is_a = ($mv['type']==='entry' && str_starts_with($mv['reference'],'ANNULATION-VENTE-'));
        if ($mv['type']==='entry'      && !$is_a) $tot_arr += $mv['quantity'];
        if ($mv['type']==='exit')                 $tot_sor += $mv['quantity'];
        if ($mv['type']==='adjustment')           $tot_adj += $mv['quantity'];
        if ($is_a)                                $tot_ann += $mv['quantity'];
    }

    $stock_data = compact('pi','ini_def','stk_deb','mvts','tot_arr','tot_sor','tot_adj','tot_ann','pid');
}

/* Vue: rapport global */
$global_report = [];
if ($location_set && $view === 'global') {
    $allowed = ['name','price','stock_final','valeur','sorties'];
    $sc = in_array($sort_col,$allowed)?$sort_col:'name';
    $sd = $sort_dir==='desc'?'DESC':'ASC';

    foreach ($products as $p) {
        $pid = $p['id'];
        $q   = strtolower($search_q);
        if ($q && !str_contains(strtolower($p['name']),$q)) continue;

        $ini = getInitialDefined($pdo,$pid,$company_id,$city_id);
        $ann = getVentesAnnulees($pdo,$pid,$company_id,$city_id,$date_start,$date_end);

        $st = $pdo->prepare("SELECT
            COALESCE(SUM(CASE WHEN type='entry' AND reference NOT LIKE 'ANNULATION-VENTE-%' THEN quantity END),0) entrees,
            COALESCE(SUM(CASE WHEN type='exit'  THEN quantity END),0) sorties,
            COALESCE(SUM(CASE WHEN type='adjustment' THEN quantity END),0) ajust
            FROM stock_movements WHERE product_id=? AND company_id=? AND city_id=?
            AND DATE(movement_date) BETWEEN ? AND ?");
        $st->execute([$pid,$company_id,$city_id,$date_start,$date_end]);
        $m = $st->fetch(PDO::FETCH_ASSOC);

        $sf  = getStockAtDate($pdo,$pid,$company_id,$city_id,date('Y-m-d'));
        $val = $sf * $p['price'];

        $global_report[] = [
            'id'=>$pid,'name'=>$p['name'],'price'=>$p['price'],
            'alert_qty'=>$p['alert_quantity'],'ini'=>$ini,'ann'=>$ann,
            'entrees'=>(int)$m['entrees'],'sorties'=>(int)$m['sorties'],'ajust'=>(int)$m['ajust'],
            'stock_final'=>$sf,'valeur'=>$val
        ];
    }
    /* Tri */
    usort($global_report, fn($a,$b) => $sd==='ASC'
        ? ($a[$sc] <=> $b[$sc])
        : ($b[$sc] <=> $a[$sc]));
}

/* Vue: ventes annulées (toutes) */
$all_annulations = [];
if ($location_set && $view === 'annulations') {
    $st = $pdo->prepare("
        SELECT sm.*, p.name product_name, p.price product_price,
               REPLACE(sm.reference,'ANNULATION-VENTE-','') invoice_id
        FROM stock_movements sm
        JOIN products p ON p.id=sm.product_id
        WHERE sm.company_id=? AND sm.city_id=?
          AND sm.type='entry' AND sm.reference LIKE 'ANNULATION-VENTE-%'
          AND DATE(sm.movement_date) BETWEEN ? AND ?
        ORDER BY sm.movement_date DESC");
    $st->execute([$company_id,$city_id,$date_start,$date_end]);
    $all_annulations = $st->fetchAll(PDO::FETCH_ASSOC);
}

/* Vue: alertes stock bas */
$alerts = [];
if ($location_set && $view === 'alertes') {
    foreach($products as $p) {
        $sf = getStockAtDate($pdo,$p['id'],$company_id,$city_id,date('Y-m-d'));
        if ($sf <= $p['alert_quantity']) {
            $alerts[] = array_merge($p,['stock_actuel'=>$sf,'manque'=>max(0,$p['alert_quantity']-$sf+1)]);
        }
    }
}

/* Vue: tendances (ventes par jour) */
$tendances = [];
if ($location_set && $view === 'tendances') {
    $st = $pdo->prepare("
        SELECT DATE(movement_date) jour,
               SUM(CASE WHEN type='exit' THEN quantity END) sorties,
               SUM(CASE WHEN type='entry' AND reference NOT LIKE 'ANNULATION-VENTE-%' THEN quantity END) entrees,
               SUM(CASE WHEN reference LIKE 'ANNULATION-VENTE-%' THEN quantity END) annulations
        FROM stock_movements
        WHERE company_id=? AND city_id=?
          AND DATE(movement_date) BETWEEN ? AND ?
        GROUP BY DATE(movement_date)
        ORDER BY DATE(movement_date) ASC");
    $st->execute([$company_id,$city_id,$date_start,$date_end]);
    $tendances = $st->fetchAll(PDO::FETCH_ASSOC);
}

/* KPI globaux */
$kpi = ['total_val'=>0,'ruptures'=>0,'alertes'=>0,'ann_periode'=>0,'produits'=>count($products)];
if ($location_set) {
    foreach ($products as $p) {
        $sf = getStockAtDate($pdo,$p['id'],$company_id,$city_id,date('Y-m-d'));
        $kpi['total_val'] += $sf * $p['price'];
        if ($sf <= 0)                      $kpi['ruptures']++;
        elseif ($sf <= $p['alert_quantity']) $kpi['alertes']++;
    }
    $st = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM stock_movements
        WHERE company_id=? AND city_id=? AND type='entry' AND reference LIKE 'ANNULATION-VENTE-%'
        AND DATE(movement_date) BETWEEN ? AND ?");
    $st->execute([$company_id,$city_id,$date_start,$date_end]);
    $kpi['ann_periode'] = (int)$st->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Suivi Stock | Desormai rien n'échappe a votre controle — ESPERANCE H2O</title>
<meta name="theme-color" content="#10b981">
<link rel="manifest" href="/stock/stock_manifest.json">
<link rel="icon" href="/stock/stock-app-icon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/stock/stock-app-icon-192.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=Source+Serif+4:wght@700;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
@font-face{font-family:'C059';src:local('C059-Bold'),local('C059 Bold'),local('Century Schoolbook');font-weight:700 900;}
:root{
    --bg:#04090e;--surf:#081420;--card:#0d1e2c;--card2:#112030;
    --bord:rgba(50,190,143,0.16);
    --neon:#32be8f;--neon2:#19ffa3;
    --red:#ff3553;--orange:#ff9140;--blue:#3d8cff;--gold:#ffd060;
    --purple:#a855f7;--teal:#06b6d4;
    --text:#e0f2ea;--text2:#b8d8cc;--muted:#5a8070;
    --glow:0 0 26px rgba(50,190,143,0.45);
    --glow-r:0 0 26px rgba(255,53,83,0.45);
    --glow-gold:0 0 26px rgba(255,208,96,0.4);
    --glow-blue:0 0 26px rgba(61,140,255,0.4);
    --fh:'C059','Source Serif 4','Playfair Display','Book Antiqua',Georgia,serif;
    --fb:'Inter','Segoe UI',system-ui,sans-serif;
}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
html{scroll-behavior:smooth;}
body{font-family:var(--fb);font-size:15px;line-height:1.7;background:var(--bg);color:var(--text);min-height:100vh;overflow-x:hidden;}
body::before{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;
    background:radial-gradient(ellipse 65% 42% at 3% 8%,rgba(50,190,143,.08) 0%,transparent 62%),
    radial-gradient(ellipse 52% 36% at 97% 88%,rgba(61,140,255,.07) 0%,transparent 62%),
    radial-gradient(ellipse 40% 28% at 80% 20%,rgba(255,145,64,.05) 0%,transparent 60%);}
body::after{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;
    background-image:linear-gradient(rgba(50,190,143,.022) 1px,transparent 1px),linear-gradient(90deg,rgba(50,190,143,.022) 1px,transparent 1px);
    background-size:46px 46px;}
.wrap{position:relative;z-index:1;max-width:1680px;margin:0 auto;padding:16px 16px 48px;}

/* ── TOPBAR ── */
.topbar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;
    background:rgba(8,20,32,.94);border:1px solid var(--bord);border-radius:18px;
    padding:18px 28px;margin-bottom:16px;backdrop-filter:blur(24px);}
.brand{display:flex;align-items:center;gap:16px;flex-shrink:0;}
.brand-ico{width:50px;height:50px;background:linear-gradient(135deg,var(--teal),var(--blue));
    border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:24px;color:#fff;
    animation:breathe 3.2s ease-in-out infinite;flex-shrink:0;}
@keyframes breathe{0%,100%{box-shadow:0 0 14px rgba(6,182,212,.4);}50%{box-shadow:0 0 38px rgba(6,182,212,.85);}}
.brand-txt h1{font-family:var(--fh);font-size:22px;font-weight:900;color:var(--text);line-height:1.2;}
.brand-txt p{font-size:11px;font-weight:700;color:var(--teal);letter-spacing:2.8px;text-transform:uppercase;margin-top:3px;}
.user-badge{display:flex;align-items:center;gap:10px;background:linear-gradient(135deg,var(--teal),var(--blue));
    color:var(--bg);padding:11px 22px;border-radius:32px;font-family:var(--fh);font-size:14px;font-weight:900;flex-shrink:0;}

/* ── NAV ── */
.nav-bar{display:flex;align-items:center;flex-wrap:wrap;gap:8px;
    background:rgba(8,20,32,.90);border:1px solid var(--bord);border-radius:16px;
    padding:14px 22px;margin-bottom:18px;backdrop-filter:blur(20px);}
.nb{display:flex;align-items:center;gap:8px;padding:10px 18px;border-radius:12px;
    border:1.5px solid var(--bord);background:rgba(6,182,212,.07);color:var(--text2);
    font-family:var(--fh);font-size:13px;font-weight:900;text-decoration:none;white-space:nowrap;
    letter-spacing:.4px;transition:all .28s cubic-bezier(.23,1,.32,1);cursor:pointer;}
.nb:hover,.nb.active{background:var(--teal);color:var(--bg);border-color:var(--teal);
    box-shadow:0 0 22px rgba(6,182,212,.5);transform:translateY(-2px);}
.nb.r{border-color:rgba(255,53,83,.3);color:var(--red);background:rgba(255,53,83,.07);}
.nb.r:hover,.nb.r.active{background:var(--red);color:#fff;border-color:var(--red);box-shadow:var(--glow-r);}
.nb.g{border-color:rgba(50,190,143,.3);color:var(--neon);background:rgba(50,190,143,.07);}
.nb.g:hover,.nb.g.active{background:var(--neon);color:var(--bg);border-color:var(--neon);box-shadow:var(--glow);}

/* ── KPI ── */
.kpi-strip{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:20px;}
.ks{background:var(--card);border:1px solid var(--bord);border-radius:16px;padding:18px 16px;
    display:flex;align-items:center;gap:13px;transition:all .3s;animation:fadeUp .5s ease backwards;}
.ks:nth-child(n){animation-delay:calc(var(--i,.05s))}
.ks:hover{transform:translateY(-4px);box-shadow:0 14px 32px rgba(0,0,0,.38),var(--glow);}
.ks-ico{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;}
.ks-val{font-family:var(--fh);font-size:22px;font-weight:900;color:var(--text);line-height:1;}
.ks-lbl{font-family:var(--fb);font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-top:4px;}
@keyframes fadeUp{from{opacity:0;transform:translateY(22px)}to{opacity:1;transform:translateY(0)}}

/* ── FILTRES ── */
.filter-panel{background:var(--card);border:1px solid var(--bord);border-radius:18px;
    padding:20px 24px;margin-bottom:20px;animation:fadeUp .5s ease .05s backwards;}
.f-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;align-items:end;}
.f-group{display:flex;flex-direction:column;gap:7px;}
.f-label{font-family:var(--fh);font-size:12px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:1.2px;}
.f-input,.f-select{padding:12px 16px;background:rgba(0,0,0,.3);border:1.5px solid var(--bord);
    border-radius:12px;color:var(--text);font-family:var(--fb);font-size:14px;font-weight:600;
    transition:all .3s;appearance:none;-webkit-appearance:none;width:100%;}
.f-input::placeholder{color:var(--muted);}
.f-input:focus,.f-select:focus{outline:none;border-color:var(--neon);box-shadow:var(--glow);background:rgba(50,190,143,.05);}
.f-select option{background:#0d1e2c;color:var(--text);}

/* ── PANEL ── */
.panel{background:var(--card);border:1px solid var(--bord);border-radius:18px;
    overflow:hidden;margin-bottom:20px;transition:border-color .3s;animation:fadeUp .55s ease .08s backwards;}
.panel:hover{border-color:rgba(50,190,143,.24);}
.ph{display:flex;align-items:center;justify-content:space-between;gap:12px;
    padding:18px 24px;border-bottom:1px solid rgba(255,255,255,.05);
    background:rgba(0,0,0,.18);flex-wrap:wrap;}
.ph-title{font-family:var(--fh);font-size:15px;font-weight:900;color:var(--text);
    display:flex;align-items:center;gap:10px;letter-spacing:.4px;}
.dot{width:9px;height:9px;border-radius:50%;background:var(--neon);box-shadow:0 0 9px var(--neon);
    flex-shrink:0;animation:pdot 2.2s infinite;}
@keyframes pdot{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(.75)}}
.dot.r{background:var(--red);box-shadow:0 0 9px var(--red);}
.dot.g{background:var(--gold);box-shadow:0 0 9px var(--gold);}
.dot.o{background:var(--orange);box-shadow:0 0 9px var(--orange);}
.dot.b{background:var(--blue);box-shadow:0 0 9px var(--blue);}
.dot.p{background:var(--purple);box-shadow:0 0 9px var(--purple);}
.dot.t{background:var(--teal);box-shadow:0 0 9px var(--teal);}
.pbadge{font-family:var(--fb);font-size:11px;font-weight:800;padding:5px 14px;border-radius:20px;
    white-space:nowrap;letter-spacing:.5px;background:rgba(50,190,143,.12);color:var(--neon);}
.pbadge.r{background:rgba(255,53,83,.12);color:var(--red);}
.pbadge.g{background:rgba(255,208,96,.12);color:var(--gold);}
.pbadge.b{background:rgba(61,140,255,.12);color:var(--blue);}
.pbadge.p{background:rgba(168,85,247,.12);color:var(--purple);}
.pbadge.t{background:rgba(6,182,212,.12);color:var(--teal);}
.pb{padding:20px 22px;}

/* ── KPI CARDS (résumé produit) ── */
.sum-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:20px;}
.sum-card{border-radius:14px;padding:18px 16px;text-align:center;position:relative;overflow:hidden;}
.sum-card::before{content:'';position:absolute;inset:0;opacity:.08;background:currentColor;}
.sum-val{font-family:var(--fh);font-size:28px;font-weight:900;line-height:1;margin-bottom:6px;}
.sum-lbl{font-family:var(--fb);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;opacity:.8;}

/* ── TABLE ── */
.tbl-wrap{overflow-x:auto;}
.tbl-wrap::-webkit-scrollbar{height:6px;}
.tbl-wrap::-webkit-scrollbar-thumb{background:var(--neon);border-radius:10px;}
.tbl{width:100%;border-collapse:collapse;min-width:720px;}
.tbl th{font-family:var(--fh);font-size:11px;font-weight:900;color:var(--muted);text-transform:uppercase;
    letter-spacing:1.2px;padding:12px 14px;border-bottom:1px solid rgba(255,255,255,.06);
    text-align:left;background:rgba(0,0,0,.18);white-space:nowrap;}
.tbl th a{color:inherit;text-decoration:none;display:flex;align-items:center;gap:4px;}
.tbl th a:hover{color:var(--teal);}
.tbl td{font-family:var(--fb);font-size:13px;font-weight:500;color:var(--text2);
    padding:13px 14px;border-bottom:1px solid rgba(255,255,255,.04);line-height:1.55;}
.tbl tr:last-child td{border-bottom:none;}
.tbl tbody tr{transition:all .25s;}
.tbl tbody tr:hover{background:rgba(50,190,143,.04);}
.tbl td strong{font-family:var(--fh);font-weight:900;color:var(--text);}
.tbl td.ann-col{background:rgba(255,53,83,.03);} /* Colonne ventes annulées */
th.ann-col-h{color:var(--red)!important;}

/* ── BADGES ── */
.bdg{font-family:var(--fb);font-size:10px;font-weight:800;padding:4px 11px;border-radius:20px;letter-spacing:.5px;display:inline-block;white-space:nowrap;}
.bdg-g{background:rgba(50,190,143,.14);color:var(--neon);}
.bdg-r{background:rgba(255,53,83,.14);color:var(--red);}
.bdg-gold{background:rgba(255,208,96,.14);color:var(--gold);}
.bdg-b{background:rgba(61,140,255,.14);color:var(--blue);}
.bdg-p{background:rgba(168,85,247,.14);color:var(--purple);}
.bdg-t{background:rgba(6,182,212,.14);color:var(--teal);}
.bdg-ann{background:rgba(255,53,83,.2);color:var(--red);border:1px solid rgba(255,53,83,.4);font-size:11px;padding:5px 12px;}

/* ── BOUTONS ── */
.btn{display:inline-flex;align-items:center;gap:8px;padding:12px 22px;border-radius:12px;
    border:none;cursor:pointer;font-family:var(--fh);font-size:13px;font-weight:900;
    letter-spacing:.4px;transition:all .28s;text-decoration:none;white-space:nowrap;}
.btn-teal{background:rgba(6,182,212,.12);border:1.5px solid rgba(6,182,212,.3);color:var(--teal);}
.btn-teal:hover{background:var(--teal);color:var(--bg);}
.btn-neon{background:rgba(50,190,143,.12);border:1.5px solid rgba(50,190,143,.3);color:var(--neon);}
.btn-neon:hover{background:var(--neon);color:var(--bg);box-shadow:var(--glow);}
.btn-gold{background:rgba(255,208,96,.12);border:1.5px solid rgba(255,208,96,.3);color:var(--gold);}
.btn-gold:hover{background:var(--gold);color:var(--bg);box-shadow:var(--glow-gold);}
.btn-red{background:rgba(255,53,83,.12);border:1.5px solid rgba(255,53,83,.3);color:var(--red);}
.btn-red:hover{background:var(--red);color:#fff;box-shadow:var(--glow-r);}
.btn-blue{background:rgba(61,140,255,.12);border:1.5px solid rgba(61,140,255,.3);color:var(--blue);}
.btn-blue:hover{background:var(--blue);color:#fff;box-shadow:var(--glow-blue);}
.btn-sm{padding:8px 14px;font-size:12px;border-radius:9px;}

/* ── LOC BOX ── */
.loc-box{background:var(--card);border:1px solid var(--bord);border-radius:18px;padding:40px;text-align:center;}
.loc-box h2{font-family:var(--fh);font-size:22px;font-weight:900;color:var(--text);margin-bottom:8px;}
.loc-box p{font-family:var(--fb);font-size:14px;color:var(--muted);margin-bottom:28px;}

/* ── INFO BOX ── */
.info-box{background:rgba(6,182,212,.07);border:1px solid rgba(6,182,212,.22);
    border-radius:12px;padding:14px 18px;margin-bottom:18px;}
.info-box strong{font-family:var(--fh);font-size:13px;font-weight:900;color:var(--teal);display:block;margin-bottom:5px;}

/* ── CHART ── */
.chart-wrap{height:300px;position:relative;}

/* ── RESPONSIVE ── */
@media(max-width:1100px){.kpi-strip{grid-template-columns:repeat(3,1fr);}}
@media(max-width:760px){
    .wrap{padding:12px 12px 36px;}
    .topbar{padding:14px 16px;}
    .kpi-strip{grid-template-columns:repeat(2,1fr);gap:10px;}
    .brand-txt h1{font-size:18px;}
    .nav-bar{padding:12px 14px;}
    .nb{padding:9px 13px;font-size:12px;}
    .sum-row{grid-template-columns:repeat(2,1fr);}
    .ph{flex-direction:column;align-items:flex-start;}
}
.stock-install-fab,.stock-network-badge{position:fixed;z-index:20000;border:none;border-radius:999px;padding:12px 16px;font-size:13px;font-weight:800;display:flex;align-items:center;gap:8px;box-shadow:0 18px 38px rgba(0,0,0,.24)}
.stock-install-fab{right:16px;bottom:18px;background:linear-gradient(135deg,#10b981,#0ea5e9);color:#fff;cursor:pointer}
.stock-network-badge{left:50%;transform:translateX(-50%);bottom:18px;background:rgba(255,53,83,.96);color:#fff;display:none}
.stock-network-badge.show{display:flex}
img,canvas,iframe,svg{max-width:100%;height:auto}
body{overflow-x:hidden}
@media(max-width:768px){.nav-bar{overflow-x:auto;white-space:nowrap;-webkit-overflow-scrolling:touch;padding-bottom:6px}.kpi-strip,.split,.split-2,.grid-2,.chart-grid{grid-template-columns:1fr !important}.tbl-wrap,table{display:block;overflow-x:auto}.user-badge{width:100%;justify-content:center}}
</style>
<link rel="stylesheet" href="/stock/stock_mobile_overrides.css">
</head>
<body>
<button type="button" class="stock-install-fab" id="stockInstallBtn"><i class="fas fa-download"></i> Installer Stock</button>
<div class="stock-network-badge" id="stockNetworkBadge"><i class="fas fa-wifi"></i> Hors ligne</div>
<div class="wrap">

<!-- ══ TOPBAR ══ -->
<div class="topbar">
    <div class="brand">
        <div class="brand-ico"><i class="fas fa-chart-area"></i></div>
        <div class="brand-txt">
            <h1>Suivi des Stocks</h1>
            <p>ESPERANCE H2O &nbsp;·&nbsp; <?= $city_name ? htmlspecialchars($city_name) : 'Site non sélectionné' ?></p>
        </div>
    </div>
    <div class="user-badge">
        <i class="fas fa-user-shield"></i>
        <?= htmlspecialchars($user_name) ?>
    </div>
</div>

<!-- ══ NAV ══ -->
<div class="nav-bar">
    <?php $base="?view=%s&date_start=$date_start&date_end=$date_end"; ?>
    <a href="<?= sprintf($base,'global') ?>"     class="nb g  <?= $view==='global'?'active':'' ?>"><i class="fas fa-chart-pie"></i> Vue Globale</a>
    <a href="<?= sprintf($base,'produit') ?>"    class="nb    <?= $view==='produit'?'active':'' ?>"><i class="fas fa-cube"></i> Produit</a>
    <a href="<?= sprintf($base,'annulations') ?>" class="nb r <?= $view==='annulations'?'active':'' ?>"><i class="fas fa-ban"></i> Ventes Annulées</a>
    <a href="<?= sprintf($base,'alertes') ?>"    class="nb    <?= $view==='alertes'?'active':'' ?>"><i class="fas fa-bell"></i> Alertes</a>
    <a href="<?= sprintf($base,'tendances') ?>"  class="nb    <?= $view==='tendances'?'active':'' ?>"><i class="fas fa-chart-line"></i> Tendances</a>
    <a href="<?= project_url('stock/stock_update_fixed.php') ?>"             class="nb"><i class="fas fa-warehouse"></i> Gestion</a>
    <a href="<?= project_url('finance/caisse_complete_enhanced.php') ?>"        class="nb"><i class="fas fa-cash-register"></i> Caisse</a>
    <a href="<?= project_url('dashboard/index.php') ?>"               class="nb"><i class="fas fa-id-badge"></i> RH</a>
    <a href="<?= project_url('dashboard/index.php') ?>"                          class="nb r"><i class="fas fa-home"></i> Accueil</a>
</div>

<?php if(!$location_set): ?>
<!-- ══ LOCALISATION ══ -->
<div class="loc-box">
    <h2><i class="fas fa-map-marker-alt" style="color:var(--teal)"></i> &nbsp;Sélectionnez votre localisation</h2>
    <p>Choisissez votre société et votre magasin</p>
    <form method="get" style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap">
        <select name="company_id" class="f-select" style="max-width:240px" required onchange="this.form.submit()">
            <option value="">— Société —</option>
            <?php foreach($companies as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $company_id==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="city_id" class="f-select" style="max-width:240px" required>
            <option value="">— Magasin —</option>
            <?php foreach($cities as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $city_id==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" name="confirm_location" class="btn btn-teal" style="padding:12px 28px">
            <i class="fas fa-check"></i> Valider
        </button>
    </form>
</div>

<?php else: ?>

<!-- ══ KPI STRIP ══ -->
<div class="kpi-strip">
    <div class="ks" style="--i:.05s">
        <div class="ks-ico" style="background:rgba(6,182,212,.14);color:var(--teal)"><i class="fas fa-boxes"></i></div>
        <div><div class="ks-val"><?= $kpi['produits'] ?></div><div class="ks-lbl">Produits</div></div>
    </div>
    <div class="ks" style="--i:.1s">
        <div class="ks-ico" style="background:rgba(50,190,143,.14);color:var(--neon)"><i class="fas fa-coins"></i></div>
        <div><div class="ks-val" style="color:var(--neon)"><?= number_format($kpi['total_val'],0,',',' ') ?></div><div class="ks-lbl">Valeur stock (FCFA)</div></div>
    </div>
    <div class="ks" style="--i:.15s">
        <div class="ks-ico" style="background:rgba(255,53,83,.14);color:var(--red)"><i class="fas fa-exclamation-circle"></i></div>
        <div><div class="ks-val" style="color:var(--red)"><?= $kpi['ruptures'] ?></div><div class="ks-lbl">Ruptures</div></div>
    </div>
    <div class="ks" style="--i:.2s">
        <div class="ks-ico" style="background:rgba(255,208,96,.14);color:var(--gold)"><i class="fas fa-bell"></i></div>
        <div><div class="ks-val" style="color:var(--gold)"><?= $kpi['alertes'] ?></div><div class="ks-lbl">Alertes stock</div></div>
    </div>
    <div class="ks" style="--i:.25s">
        <div class="ks-ico" style="background:rgba(255,53,83,.14);color:var(--red)"><i class="fas fa-ban"></i></div>
        <div><div class="ks-val" style="color:var(--red)"><?= $kpi['ann_periode'] ?></div><div class="ks-lbl">Unités annulées (période)</div></div>
    </div>
</div>

<!-- ══ FILTRES ══ -->
<div class="filter-panel">
    <form method="get">
        <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
        <div class="f-grid">
            <?php if($view==='produit'): ?>
            <div class="f-group">
                <label class="f-label">Produit</label>
                <select name="product_id" class="f-select" onchange="this.form.submit()">
                    <option value="all">— Tous (rapport global) —</option>
                    <?php foreach($products as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= $selected_product==$p['id']?'selected':'' ?>><?= htmlspecialchars($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <?php if($view==='global'): ?>
            <div class="f-group">
                <label class="f-label">Rechercher un produit</label>
                <input type="text" name="search" class="f-input" value="<?= htmlspecialchars($search_q) ?>" placeholder="Nom du produit…">
            </div>
            <?php endif; ?>
            <div class="f-group">
                <label class="f-label">Date début</label>
                <input type="date" name="date_start" class="f-input" value="<?= $date_start ?>">
            </div>
            <div class="f-group">
                <label class="f-label">Date fin</label>
                <input type="date" name="date_end" class="f-input" value="<?= $date_end ?>">
            </div>
            <div class="f-group" style="justify-content:flex-end;flex-direction:row;gap:8px;align-items:flex-end;flex-wrap:wrap">
                <button type="submit" class="btn btn-teal"><i class="fas fa-search"></i> Filtrer</button>
                <?php if($view==='global'): ?>
                <a href="?view=global&date_start=<?=$date_start?>&date_end=<?=$date_end?>&export_global=1" class="btn btn-neon"><i class="fas fa-file-excel"></i> Excel</a>
                <a href="?view=global&date_start=<?=$date_start?>&date_end=<?=$date_end?>&export_csv=1" class="btn btn-gold"><i class="fas fa-file-csv"></i> CSV</a>
                <?php elseif($view==='produit' && $selected_product!=='all'): ?>
                <a href="?view=produit&product_id=<?=$selected_product?>&date_start=<?=$date_start?>&date_end=<?=$date_end?>&export_product=<?=$selected_product?>" class="btn btn-neon"><i class="fas fa-file-excel"></i> Excel</a>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<!-- ══════════════════════════════════════════
     VUE: RAPPORT GLOBAL
══════════════════════════════════════════ -->
<?php if($view === 'global'): ?>
<div class="panel">
    <div class="ph">
        <div class="ph-title"><div class="dot t"></div> Rapport Global — Tous les Produits</div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <span class="pbadge t"><?= count($global_report) ?> produits</span>
            <span class="pbadge"><?= $date_start ?> → <?= $date_end ?></span>
        </div>
    </div>
    <div class="pb">
        <?php
        $tot_val = array_sum(array_column($global_report,'valeur'));
        $tot_ann = array_sum(array_column($global_report,'ann'));
        $tot_sor = array_sum(array_column($global_report,'sorties'));
        ?>
        <?php if($tot_ann > 0): ?>
        <div style="background:rgba(255,53,83,.07);border:1px solid rgba(255,53,83,.22);border-radius:12px;padding:14px 20px;margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
            <span style="font-family:var(--fb);font-size:13px;font-weight:700;color:var(--red)"><i class="fas fa-ban"></i> Total unités annulées sur la période</span>
            <span style="font-family:var(--fh);font-size:20px;font-weight:900;color:var(--red)">+<?= $tot_ann ?> unités</span>
        </div>
        <?php endif; ?>

        <div class="tbl-wrap">
        <table class="tbl">
            <thead>
                <tr>
                    <?php
                    $cols = [
                        'name'       => 'Produit',
                        'price'      => 'Prix',
                        ''           => 'Stock Initial',
                        ''           => 'Arrivages',
                        ''           => '❌ Ventes Annulées',
                        ''           => 'Ajustements',
                        'sorties'    => 'Sorties',
                        'stock_final'=> 'Stock Actuel',
                        'valeur'     => 'Valeur',
                        ''           => 'Statut',
                    ];
                    $i=0;
                    foreach($cols as $k=>$label):
                        $is_ann = $label==='❌ Ventes Annulées';
                        echo '<th'.($is_ann?' class="ann-col-h"':'').'>';
                        if($k) {
                            $nd = ($sort_col===$k && $sort_dir==='asc')?'desc':'asc';
                            $ico = ($sort_col===$k)?($sort_dir==='asc'?'↑':'↓'):'⇅';
                            echo "<a href=\"?view=global&date_start=$date_start&date_end=$date_end&sort=$k&dir=$nd&search=".urlencode($search_q)."\">$label <span style='opacity:.5'>$ico</span></a>";
                        } else echo $label;
                        echo '</th>';
                    endforeach;
                    ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach($global_report as $r):
                    $sf = $r['stock_final'];
                    if($sf<=0)                  {$et='RUPTURE';$ec='bdg-r';}
                    elseif($sf<=$r['alert_qty']){$et='ALERTE'; $ec='bdg-gold';}
                    else                         {$et='OK';     $ec='bdg-g';}
                ?>
                <tr>
                    <td>
                        <a href="?view=produit&product_id=<?=$r['id']?>&date_start=<?=$date_start?>&date_end=<?=$date_end?>"
                           style="font-family:var(--fh);font-weight:900;color:var(--teal);text-decoration:none">
                           <?= htmlspecialchars($r['name']) ?>
                        </a>
                    </td>
                    <td style="font-family:var(--fh);font-weight:900;color:var(--gold)"><?= number_format($r['price'],0,',',' ') ?></td>
                    <td><span class="bdg bdg-b"><?= $r['ini'] ?></span></td>
                    <td><span class="bdg bdg-g">+<?= $r['entrees'] ?></span></td>
                    <!-- ★ VENTES ANNULÉES -->
                    <td class="ann-col">
                        <?php if($r['ann']>0): ?>
                        <span class="bdg bdg-ann"><i class="fas fa-undo"></i> +<?= $r['ann'] ?></span>
                        <?php else: ?><span style="color:var(--muted)">—</span><?php endif; ?>
                    </td>
                    <td>
                        <?php if($r['ajust']>0):?><span class="bdg bdg-g">+<?=$r['ajust']?></span>
                        <?php elseif($r['ajust']<0):?><span class="bdg bdg-gold"><?=$r['ajust']?></span>
                        <?php else:?><span style="color:var(--muted)">0</span><?php endif;?>
                    </td>
                    <td><span class="bdg bdg-r">-<?= $r['sorties'] ?></span></td>
                    <td><strong style="font-family:var(--fh);font-size:17px;color:<?=$sf<=0?'var(--red)':($sf<=$r['alert_qty']?'var(--gold)':'var(--text)') ?>"><?= $sf ?></strong></td>
                    <td style="font-family:var(--fh);font-weight:900;color:var(--neon)"><?= number_format($r['valeur'],0,',',' ') ?></td>
                    <td><span class="bdg <?= $ec ?>"><?= $et ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($global_report)): ?>
                <tr><td colspan="10" style="text-align:center;padding:32px;color:var(--muted)">
                    <i class="fas fa-search" style="font-size:36px;display:block;margin-bottom:10px;opacity:.2"></i>
                    Aucun produit trouvé
                </td></tr>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr style="background:rgba(0,0,0,.2)">
                    <td colspan="7" style="font-family:var(--fh);font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:1px;font-size:11px">TOTAUX PÉRIODE</td>
                    <td><strong style="font-family:var(--fh);font-size:15px;color:var(--text)"><?= array_sum(array_column($global_report,'stock_final')) ?> u</strong></td>
                    <td><strong style="font-family:var(--fh);font-size:15px;color:var(--neon)"><?= number_format($tot_val,0,',',' ') ?> FCFA</strong></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════
     VUE: PRODUIT INDIVIDUEL
══════════════════════════════════════════ -->
<?php if($view === 'produit'): ?>
<?php if(!$stock_data && $selected_product==='all'): ?>
<div class="panel">
    <div class="pb" style="text-align:center;padding:40px">
        <i class="fas fa-hand-point-up" style="font-size:44px;color:var(--teal);display:block;margin-bottom:14px;opacity:.5"></i>
        <p style="font-family:var(--fh);font-size:16px;color:var(--muted)">Sélectionnez un produit dans le filtre ci-dessus</p>
    </div>
</div>
<?php elseif($stock_data): $d=$stock_data; ?>

<!-- Résumé KPI -->
<div class="sum-row">
    <div class="sum-card" style="color:var(--blue);background:rgba(61,140,255,.08);border:1px solid rgba(61,140,255,.2)">
        <div class="sum-val" style="color:var(--blue)"><?= $d['ini_def'] ?></div>
        <div class="sum-lbl">Stock Initial Saison</div>
    </div>
    <div class="sum-card" style="color:var(--neon);background:rgba(50,190,143,.08);border:1px solid rgba(50,190,143,.2)">
        <div class="sum-val" style="color:var(--neon)">+<?= $d['tot_arr'] ?></div>
        <div class="sum-lbl">Arrivages</div>
    </div>
    <div class="sum-card" style="color:var(--red);background:rgba(255,53,83,.08);border:1px solid rgba(255,53,83,.2)">
        <div class="sum-val" style="color:var(--red)">+<?= $d['tot_ann'] ?></div>
        <div class="sum-lbl">Ventes Annulées ↩</div>
    </div>
    <div class="sum-card" style="color:var(--gold);background:rgba(255,208,96,.08);border:1px solid rgba(255,208,96,.2)">
        <div class="sum-val" style="color:var(--gold)"><?= $d['tot_adj']>=0?'+':'' ?><?= $d['tot_adj'] ?></div>
        <div class="sum-lbl">Ajustements</div>
    </div>
    <div class="sum-card" style="color:var(--red);background:rgba(255,53,83,.08);border:1px solid rgba(255,53,83,.2)">
        <div class="sum-val" style="color:var(--red)">-<?= $d['tot_sor'] ?></div>
        <div class="sum-lbl">Sorties Ventes</div>
    </div>
    <div class="sum-card" style="color:var(--teal);background:rgba(6,182,212,.08);border:1px solid rgba(6,182,212,.2)">
        <?php $sf_p = $d['stk_deb']+$d['tot_arr']+$d['tot_adj']+$d['tot_ann']-$d['tot_sor']; ?>
        <div class="sum-val" style="color:var(--teal)"><?= $sf_p ?></div>
        <div class="sum-lbl">Stock Fin Période</div>
    </div>
</div>

<div class="panel">
    <div class="ph">
        <div class="ph-title">
            <div class="dot t"></div>
            <?= htmlspecialchars($d['pi']['name']) ?>
            <span style="font-family:var(--fb);font-size:12px;color:var(--muted)">(<?= number_format($d['pi']['price'],0,',',' ') ?> FCFA)</span>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <span class="pbadge t">Stock début: <?= $d['stk_deb'] ?></span>
            <a href="?view=produit&product_id=<?=$d['pid']?>&date_start=<?=$date_start?>&date_end=<?=$date_end?>&export_product=<?=$d['pid']?>"
               class="btn btn-neon btn-sm"><i class="fas fa-file-excel"></i> Excel</a>
        </div>
    </div>
    <div class="pb">
        <?php if($d['tot_ann']>0): ?>
        <div style="background:rgba(255,53,83,.07);border:1px solid rgba(255,53,83,.22);border-radius:12px;padding:12px 18px;margin-bottom:16px">
            <span style="font-family:var(--fh);font-weight:900;color:var(--red)"><i class="fas fa-ban"></i> Attention: <?= $d['tot_ann'] ?> unité(s) retournées suite à annulations de ventes sur cette période</span>
        </div>
        <?php endif; ?>

        <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr>
                <th>Date</th><th>Client</th><th>Référence</th><th>Type</th>
                <th>Quantité</th><th class="ann-col-h">Vente Annulée</th><th>Stock Courant</th>
            </tr></thead>
            <tbody>
                <?php
                $running = $d['stk_deb'];
                if(empty($d['mvts'])): ?>
                <tr><td colspan="7" style="text-align:center;padding:32px;color:var(--muted)">
                    <i class="fas fa-inbox" style="font-size:40px;display:block;margin-bottom:12px;opacity:.2"></i>
                    Aucun mouvement durant cette période
                </td></tr>
                <?php else: foreach($d['mvts'] as $mv):
                    $is_ann = ($mv['type']==='entry' && str_starts_with($mv['reference'],'ANNULATION-VENTE-'));
                    if($mv['type']==='initial')   { $running+=$mv['quantity']; $tb='<span class="bdg bdg-b">INITIAL</span>'; $qb='<span class="bdg bdg-b">+'.$mv['quantity'].'</span>'; }
                    elseif($mv['type']==='entry' && !$is_ann) { $running+=$mv['quantity']; $tb='<span class="bdg bdg-g">ARRIVAGE</span>'; $qb='<span class="bdg bdg-g">+'.$mv['quantity'].'</span>'; }
                    elseif($is_ann) { $running+=$mv['quantity']; $tb='<span class="bdg bdg-ann">VENTE ANNULÉE</span>'; $qb='<span class="bdg bdg-ann">+'.$mv['quantity'].'</span>'; }
                    elseif($mv['type']==='adjustment') {
                        $running+=$mv['quantity'];
                        $tb=$mv['quantity']>0?'<span class="bdg bdg-g">AJUST +</span>':'<span class="bdg bdg-gold">AJUST −</span>';
                        $qb=$mv['quantity']>0?'<span class="bdg bdg-g">+'.$mv['quantity'].'</span>':'<span class="bdg bdg-gold">'.$mv['quantity'].'</span>';
                    } else { $running-=$mv['quantity']; $tb='<span class="bdg bdg-r">VENTE</span>'; $qb='<span class="bdg bdg-r">-'.$mv['quantity'].'</span>'; }
                ?>
                <tr <?= $is_ann?'style="background:rgba(255,53,83,.04)"':'' ?>>
                    <td style="color:var(--muted)"><?= date('d/m/Y H:i',strtotime($mv['movement_date'])) ?></td>
                    <td><?= $mv['client_name']?htmlspecialchars($mv['client_name']):'—' ?></td>
                    <td style="font-size:12px;color:var(--muted)"><?= htmlspecialchars(mb_substr($mv['reference'],0,42)) ?></td>
                    <td><?= $tb ?></td>
                    <td><?= $qb ?></td>
                    <td class="ann-col">
                        <?php if($is_ann): ?>
                        <span class="bdg bdg-ann"><i class="fas fa-undo"></i> +<?= $mv['quantity'] ?></span>
                        <?php else: ?><span style="color:var(--muted)">—</span><?php endif; ?>
                    </td>
                    <td><strong style="font-family:var(--fh);font-size:15px;color:<?=$running<=$d['pi']['alert_quantity']?'var(--gold)':'var(--text)' ?>"><?= $running ?></strong></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<?php endif; endif; ?>

<!-- ══════════════════════════════════════════
     VUE: VENTES ANNULÉES
══════════════════════════════════════════ -->
<?php if($view === 'annulations'): ?>
<div class="panel">
    <div class="ph">
        <div class="ph-title"><div class="dot r"></div> Historique Ventes Annulées — Retours Stock</div>
        <span class="pbadge r"><?= count($all_annulations) ?> entrée(s)</span>
    </div>
    <div class="pb">
        <?php if(empty($all_annulations)): ?>
        <div style="text-align:center;padding:40px;color:var(--muted)">
            <i class="fas fa-ban" style="font-size:44px;display:block;margin-bottom:14px;opacity:.2"></i>
            <p>Aucune vente annulée sur cette période</p>
        </div>
        <?php else: ?>
        <?php
        $total_val_ann = array_sum(array_map(fn($r)=>$r['quantity']*$r['product_price'],$all_annulations));
        $total_u_ann   = array_sum(array_column($all_annulations,'quantity'));
        ?>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px">
            <div style="background:rgba(255,53,83,.07);border:1px solid rgba(255,53,83,.2);border-radius:12px;padding:16px 20px;text-align:center">
                <div style="font-family:var(--fh);font-size:26px;font-weight:900;color:var(--red)"><?= $total_u_ann ?></div>
                <div style="font-family:var(--fb);font-size:12px;font-weight:700;color:var(--muted);margin-top:4px">Unités retournées en stock</div>
            </div>
            <div style="background:rgba(255,53,83,.07);border:1px solid rgba(255,53,83,.2);border-radius:12px;padding:16px 20px;text-align:center">
                <div style="font-family:var(--fh);font-size:26px;font-weight:900;color:var(--red)"><?= number_format($total_val_ann,0,',',' ') ?> FCFA</div>
                <div style="font-family:var(--fb);font-size:12px;font-weight:700;color:var(--muted);margin-top:4px">Valeur marchandise retournée</div>
            </div>
        </div>
        <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr>
                <th>Date annulation</th><th>Facture</th><th>Produit</th>
                <th>Unités retournées</th><th>Valeur retour</th><th>Référence</th>
            </tr></thead>
            <tbody>
                <?php foreach($all_annulations as $a): ?>
                <tr style="background:rgba(255,53,83,.02)">
                    <td style="color:var(--muted)"><?= date('d/m/Y H:i',strtotime($a['movement_date'])) ?></td>
                    <td><span class="bdg bdg-ann"><i class="fas fa-ban"></i> #<?= $a['invoice_id'] ?></span></td>
                    <td><strong><?= htmlspecialchars($a['product_name']) ?></strong></td>
                    <td><span class="bdg bdg-ann"><i class="fas fa-undo"></i> +<?= (int)$a['quantity'] ?></span></td>
                    <td style="font-family:var(--fh);font-weight:900;color:var(--red)"><?= number_format($a['quantity']*$a['product_price'],0,',',' ') ?> FCFA</td>
                    <td style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($a['reference']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════
     VUE: ALERTES STOCK BAS
══════════════════════════════════════════ -->
<?php if($view === 'alertes'): ?>
<div class="panel">
    <div class="ph">
        <div class="ph-title"><div class="dot g"></div> Alertes Stock — Produits sous le seuil</div>
        <span class="pbadge r"><?= count($alerts) ?> produit(s)</span>
    </div>
    <div class="pb">
        <?php if(empty($alerts)): ?>
        <div style="text-align:center;padding:40px">
            <i class="fas fa-check-circle" style="font-size:48px;color:var(--neon);display:block;margin-bottom:14px;opacity:.6"></i>
            <p style="font-family:var(--fh);font-size:16px;color:var(--muted)">🎉 Tous les stocks sont au-dessus du seuil d'alerte !</p>
        </div>
        <?php else: ?>
        <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr>
                <th>Produit</th><th>Prix</th><th>Stock Actuel</th>
                <th>Seuil Alerte</th><th>Manque</th><th>Statut</th><th>Action</th>
            </tr></thead>
            <tbody>
                <?php foreach($alerts as $a): ?>
                <tr <?= $a['stock_actuel']<=0?'style="background:rgba(255,53,83,.04)"':'' ?>>
                    <td><strong><?= htmlspecialchars($a['name']) ?></strong></td>
                    <td style="font-family:var(--fh);font-weight:900;color:var(--gold)"><?= number_format($a['price'],0,',',' ') ?> FCFA</td>
                    <td><strong style="font-family:var(--fh);font-size:18px;color:<?=$a['stock_actuel']<=0?'var(--red)':'var(--gold)'?>"><?= $a['stock_actuel'] ?></strong></td>
                    <td><?= $a['alert_quantity'] ?></td>
                    <td><span class="bdg bdg-r">−<?= $a['manque'] ?> u</span></td>
                    <td>
                        <?php if($a['stock_actuel']<=0): ?>
                        <span class="bdg bdg-r">🚨 RUPTURE</span>
                        <?php else: ?>
                        <span class="bdg bdg-gold">⚠️ ALERTE</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="stock_update_fixed.php?view=arrivage" class="btn btn-neon btn-sm">
                            <i class="fas fa-truck-loading"></i> Approvisionner
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════
     VUE: TENDANCES
══════════════════════════════════════════ -->
<?php if($view === 'tendances'): ?>
<div class="panel">
    <div class="ph">
        <div class="ph-title"><div class="dot b"></div> Tendances des Mouvements</div>
        <span class="pbadge b"><?= count($tendances) ?> jour(s)</span>
    </div>
    <div class="pb">
        <?php if(empty($tendances)): ?>
        <div style="text-align:center;padding:40px;color:var(--muted)">
            <i class="fas fa-chart-line" style="font-size:44px;display:block;margin-bottom:14px;opacity:.2"></i>
            <p>Aucun mouvement sur cette période</p>
        </div>
        <?php else: ?>
        <div class="chart-wrap" style="margin-bottom:24px"><canvas id="tendChart"></canvas></div>
        <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr>
                <th>Jour</th><th>Sorties (ventes)</th>
                <th class="ann-col-h">Ventes Annulées</th><th>Entrées</th><th>Solde</th>
            </tr></thead>
            <tbody>
                <?php foreach($tendances as $t):
                    $solde = (int)$t['entrees'] + (int)$t['annulations'] - (int)$t['sorties'];
                ?>
                <tr>
                    <td><strong><?= date('d/m/Y',strtotime($t['jour'])) ?></strong></td>
                    <td><span class="bdg bdg-r">-<?= (int)$t['sorties'] ?></span></td>
                    <td class="ann-col">
                        <?php if((int)$t['annulations']>0): ?>
                        <span class="bdg bdg-ann"><i class="fas fa-undo"></i> +<?= (int)$t['annulations'] ?></span>
                        <?php else: ?><span style="color:var(--muted)">—</span><?php endif; ?>
                    </td>
                    <td><span class="bdg bdg-g">+<?= (int)$t['entrees'] ?></span></td>
                    <td>
                        <span class="bdg <?= $solde>=0?'bdg-g':'bdg-r' ?>"><?= $solde>=0?'+':'' ?><?= $solde ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php endif; // location_set ?>
</div><!-- /wrap -->

<script>
<?php if($view==='tendances' && !empty($tendances)): ?>
Chart.defaults.color='#5a8070';
Chart.defaults.borderColor='rgba(255,255,255,0.04)';
Chart.defaults.font.family="'Inter',sans-serif";
const tc = document.getElementById('tendChart');
if(tc){
    new Chart(tc,{type:'line',data:{
        labels:[<?= implode(',',array_map(fn($t)=>'"'.date('d/m',strtotime($t['jour'])).'"',$tendances)) ?>],
        datasets:[
            {label:'Sorties Ventes',data:[<?= implode(',',array_column($tendances,'sorties')) ?>],
             borderColor:'#ff3553',backgroundColor:'rgba(255,53,83,0.1)',borderWidth:2.5,
             pointBackgroundColor:'#ff3553',tension:0.4,fill:true},
            {label:'Ventes Annulées',data:[<?= implode(',',array_column($tendances,'annulations')) ?>],
             borderColor:'#ffd060',backgroundColor:'rgba(255,208,96,0.1)',borderWidth:2,
             pointBackgroundColor:'#ffd060',tension:0.4,fill:true},
            {label:'Entrées Arrivages',data:[<?= implode(',',array_column($tendances,'entrees')) ?>],
             borderColor:'#32be8f',backgroundColor:'rgba(50,190,143,0.1)',borderWidth:2.5,
             pointBackgroundColor:'#32be8f',tension:0.4,fill:true}
        ]},
        options:{responsive:true,maintainAspectRatio:false,
            plugins:{legend:{position:'top',labels:{padding:16,usePointStyle:true,color:'#b8d8cc'}},
                tooltip:{backgroundColor:'rgba(8,20,32,0.97)',padding:14,cornerRadius:10,
                    callbacks:{label:ctx=>`${ctx.dataset.label}: ${ctx.parsed.y} unités`}}},
            scales:{
                x:{grid:{color:'rgba(255,255,255,0.04)'},ticks:{color:'#5a8070'}},
                y:{beginAtZero:true,grid:{color:'rgba(255,255,255,0.04)'},ticks:{color:'#5a8070'}}
            }
        }
    });
}
<?php endif; ?>
console.log('🚀 Suivi Stock Pro v2.0 — ESPERANCE H2O');
let stockDeferredInstallPrompt=null;
window.addEventListener('beforeinstallprompt',e=>{e.preventDefault();stockDeferredInstallPrompt=e;});
document.getElementById('stockInstallBtn')?.addEventListener('click',async()=>{if(!stockDeferredInstallPrompt){window.location.href='/stock/install_stock_app.php';return;}stockDeferredInstallPrompt.prompt();await stockDeferredInstallPrompt.userChoice.catch(()=>null);stockDeferredInstallPrompt=null;});
function updateStockNetworkBadge(){document.getElementById('stockNetworkBadge')?.classList.toggle('show',!navigator.onLine);}
window.addEventListener('online',updateStockNetworkBadge);window.addEventListener('offline',updateStockNetworkBadge);updateStockNetworkBadge();
if('serviceWorker' in navigator){window.addEventListener('load',()=>navigator.serviceWorker.register('/stock/stock-sw.js').catch(()=>{}));}
</script>
</body>
</html>
