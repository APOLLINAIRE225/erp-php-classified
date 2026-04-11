<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

/**
 * EXPORT BONS DE LIVRAISON — ESPERANCE H2O
 * Génère un fichier .xlsx propre, compatible Windows/Excel
 * Aucune dépendance externe — utilise ZipArchive natif PHP
 *
 * Paramètres GET :
 *   company_id  (int, optionnel)
 *   city_id     (int, optionnel)
 *   status      (string, optionnel)
 *   search      (string, optionnel)
 */
session_start();
ini_set('display_errors', 0);
error_reporting(0);

require_once APP_ROOT . '/app/core/DB.php';
use App\Core\DB;

if (empty($_SESSION['user_id'])) {
    http_response_code(403); die('Non autorisé');
}

$pdo = DB::getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ── Filtres ── */
$status  = trim($_GET['status']  ?? '');
$search  = trim($_GET['search']  ?? '');
$company = (int)($_GET['company_id'] ?? 0);
$city    = (int)($_GET['city_id']    ?? 0);

/* ── Requête ── */
$where  = ['1=1']; $params = [];
if ($status)  { $where[] = 'o.status=?';  $params[] = $status; }
if ($company) { $where[] = 'o.company_id=?'; $params[] = $company; }
if ($city)    { $where[] = 'o.city_id=?';    $params[] = $city; }
if ($search)  {
    $where[] = '(o.order_number LIKE ? OR c.name LIKE ? OR c.phone LIKE ?)';
    $lk = '%'.$search.'%';
    $params[] = $lk; $params[] = $lk; $params[] = $lk;
}
$ws = implode(' AND ', $where);

$st = $pdo->prepare("
    SELECT o.id, o.order_number, o.status, o.total_amount, o.payment_method,
           o.delivery_address, o.notes, o.created_at,
           c.name AS client_name, c.phone AS client_phone,
           ci.name AS city_name, co.name AS company_name
    FROM orders o
    LEFT JOIN clients  c  ON o.client_id  = c.id
    LEFT JOIN cities   ci ON o.city_id    = ci.id
    LEFT JOIN companies co ON o.company_id = co.id
    WHERE $ws
    ORDER BY o.created_at DESC
    LIMIT 500
");
$st->execute($params);
$orders = $st->fetchAll(PDO::FETCH_ASSOC);

if ($orders) {
    $ids  = implode(',', array_map('intval', array_column($orders, 'id')));
    $itms = $pdo->query("SELECT * FROM order_items WHERE order_id IN($ids) ORDER BY order_id, id")->fetchAll(PDO::FETCH_ASSOC);
    $im   = [];
    foreach ($itms as $it) $im[$it['order_id']][] = $it;
    foreach ($orders as &$o) $o['items'] = $im[$o['id']] ?? [];
}

/* ── Labels statut ── */
$SL = [
    'pending'   => 'En attente',
    'confirmed' => 'Confirmée',
    'delivering'=> 'En livraison',
    'done'      => 'Livrée',
    'cancelled' => 'Annulée',
];
$PAY = [
    'cash'        => 'Espèces',
    'mobile_money'=> 'Mobile Money',
];

/* ══════════════════════════════════════════════════════════════
   GÉNÉRATEUR XLSX PUR PHP (ZipArchive)
   ══════════════════════════════════════════════════════════════ */

/* Escape XML */
function xesc(string $s): string {
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/* Shared Strings table */
$sst = [];
$sstIdx = [];
function addStr(string $s): int {
    global $sst, $sstIdx;
    if (!isset($sstIdx[$s])) {
        $sstIdx[$s] = count($sst);
        $sst[]      = $s;
    }
    return $sstIdx[$s];
}

/* Style IDs — définis dans xl/styles.xml (voir ci-dessous) */
const ST_DEFAULT  = 0;   // Normal
const ST_HEADER   = 1;   // Fond vert foncé, blanc gras
const ST_SUBHEAD  = 2;   // Fond vert clair, gras
const ST_MONEY    = 3;   // Nombre # ##0
const ST_MONEYBOLD= 4;   // Nombre gras vert
const ST_BOLD     = 5;   // Gras
const ST_MUTED    = 6;   // Gris
const ST_ORDNUM   = 7;   // Or/bold
const ST_WRAP     = 8;   // Wrap text
const ST_CENTER   = 9;   // Centré
const ST_TITLE    = 10;  // Titre grand

/* Helpers cellules */
function cellS(string $col, int $row, string $val, int $style=0): string {
    $ref = $col.$row;
    $si  = addStr($val);
    return "<c r=\"{$ref}\" t=\"s\" s=\"{$style}\"><v>{$si}</v></c>";
}
function cellN(string $col, int $row, float $val, int $style=0): string {
    $ref = $col.$row;
    return "<c r=\"{$ref}\" s=\"{$style}\"><v>{$val}</v></c>";
}
function cellF(string $col, int $row, string $formula, int $style=0): string {
    $ref = $col.$row;
    return "<c r=\"{$ref}\" s=\"{$style}\"><f>{$formula}</f></c>";
}
function cellBlank(string $col, int $row, int $style=0): string {
    return "<c r=\"{$col}{$row}\" s=\"{$style}\"/>";
}

/* ══ Feuille 1 : Bons de Livraison ══ */
/* Colonnes :
   A  N° Commande
   B  Date
   C  Client
   D  Téléphone
   E  Adresse de livraison
   F  Société
   G  Ville
   H  Paiement
   I  Statut
   J  Nb articles
   K  Total CFA
   (vide)
   M  Article | N Qté | O PU | P Sous-total
*/

$sheet1Rows = [];
$r = 1;

/* Titre principal */
$sheet1Rows[] = "<row r=\"{$r}\"><c r=\"A{$r}\" t=\"s\" s=\"".ST_TITLE."\"><v>".addStr('ESPERANCE H2O — Bons de Livraison')."</v></c></row>";
$r++;

/* Ligne info filtres */
$filterStr = 'Export du '.date('d/m/Y H:i');
if ($company) {
    $cn = $pdo->prepare("SELECT name FROM companies WHERE id=?"); $cn->execute([$company]);
    $filterStr .= '   |   Société : '.($cn->fetchColumn()?:'');
}
if ($city) {
    $cv = $pdo->prepare("SELECT name FROM cities WHERE id=?"); $cv->execute([$city]);
    $filterStr .= '   |   Ville : '.($cv->fetchColumn()?:'');
}
if ($status && isset($SL[$status])) $filterStr .= '   |   Statut : '.$SL[$status];
if ($search) $filterStr .= '   |   Recherche : "'.$search.'"';
$filterStr .= '   |   '.count($orders).' commande(s)';

$sheet1Rows[] = "<row r=\"{$r}\"><c r=\"A{$r}\" t=\"s\" s=\"".ST_MUTED."\"><v>".addStr($filterStr)."</v></c></row>";
$r++;
$sheet1Rows[] = "<row r=\"{$r}\"/>";
$r++;

/* En-têtes colonnes commandes */
$hdrs = ['A'=>'N° Commande','B'=>'Date','C'=>'Client','D'=>'Téléphone',
         'E'=>'Adresse de livraison','F'=>'Société','G'=>'Ville',
         'H'=>'Paiement','I'=>'Statut','J'=>'Nb Articles','K'=>'Total CFA'];
$hRow = "<row r=\"{$r}\">";
foreach ($hdrs as $col => $lbl) {
    $hRow .= cellS($col, $r, $lbl, ST_HEADER);
}
$hRow .= "</row>";
$sheet1Rows[] = $hRow;
$r++;
$headerRow = $r - 1; /* pour la formule résumé */

/* Lignes commandes */
$firstDataRow = $r;
$totStart = 0;
$totEnd   = 0;
foreach ($orders as $o) {
    $items    = $o['items'] ?? [];
    $nbItems  = count($items);
    $totalQty = array_sum(array_column($items, 'quantity'));

    /* Ligne de la commande */
    $oRow  = "<row r=\"{$r}\">";
    $oRow .= cellS('A', $r, $o['order_number'],                      ST_ORDNUM);
    $oRow .= cellS('B', $r, date('d/m/Y H:i', strtotime($o['created_at'])), ST_DEFAULT);
    $oRow .= cellS('C', $r, $o['client_name']  ?? '—',               ST_BOLD);
    $oRow .= cellS('D', $r, $o['client_phone'] ?? '—',               ST_DEFAULT);
    $oRow .= cellS('E', $r, $o['delivery_address'] ?? '',            ST_WRAP);
    $oRow .= cellS('F', $r, $o['company_name'] ?? '—',               ST_DEFAULT);
    $oRow .= cellS('G', $r, $o['city_name']    ?? '—',               ST_DEFAULT);
    $oRow .= cellS('H', $r, $PAY[$o['payment_method']] ?? $o['payment_method'] ?? '—', ST_DEFAULT);
    $oRow .= cellS('I', $r, $SL[$o['status']]  ?? $o['status'],      ST_CENTER);
    $oRow .= cellN('J', $r, $totalQty,                               ST_CENTER);
    $oRow .= cellN('K', $r, (float)$o['total_amount'],               ST_MONEY);
    $oRow .= "</row>";
    $sheet1Rows[] = $oRow;

    if (!$totStart) $totStart = $r;
    $totEnd = $r;
    $r++;

    /* Sous-lignes articles */
    /* Sous-en-tête articles */
    $sheet1Rows[] = "<row r=\"{$r}\">"
        . cellBlank('A',$r)
        . cellBlank('B',$r)
        . cellS('C',$r,'Article',                  ST_SUBHEAD)
        . cellS('D',$r,'Quantité',                  ST_SUBHEAD)
        . cellS('E',$r,'Prix unitaire (CFA)',        ST_SUBHEAD)
        . cellS('F',$r,'Sous-total (CFA)',           ST_SUBHEAD)
        . "</row>";
    $r++;

    if (empty($items)) {
        $sheet1Rows[] = "<row r=\"{$r}\"><c r=\"C{$r}\" t=\"s\" s=\"".ST_MUTED."\"><v>".addStr('Aucun article')."</v></c></row>";
        $r++;
    } else {
        foreach ($items as $it) {
            $sheet1Rows[] = "<row r=\"{$r}\">"
                . cellBlank('A',$r)
                . cellBlank('B',$r)
                . cellS('C', $r, $it['product_name'],          ST_DEFAULT)
                . cellN('D', $r, (float)$it['quantity'],       ST_CENTER)
                . cellN('E', $r, (float)($it['unit_price']??0), ST_MONEY)
                . cellN('F', $r, (float)$it['subtotal'],       ST_MONEY)
                . "</row>";
            $r++;
        }
    }

    /* Notes si présentes */
    if (!empty($o['notes'])) {
        $sheet1Rows[] = "<row r=\"{$r}\"><c r=\"C{$r}\" t=\"s\" s=\"".ST_MUTED."\"><v>".addStr('Note : '.$o['notes'])."</v></c></row>";
        $r++;
    }

    /* Séparateur */
    $sheet1Rows[] = "<row r=\"{$r}\"/>";
    $r++;
}

/* Ligne TOTAL GÉNÉRAL */
$sheet1Rows[] = "<row r=\"{$r}\">"
    . cellS('J', $r, 'TOTAL GÉNÉRAL',             ST_MONEYBOLD)
    . cellF('K', $r, "SUM(K{$firstDataRow}:K{$totEnd})", ST_MONEYBOLD)
    . "</row>";
$r++;

/* ══ Feuille 2 : Résumé par statut ══ */
$stats = $pdo->query("
    SELECT status, COUNT(*) AS nb,
           COALESCE(SUM(total_amount),0) AS total
    FROM orders
    GROUP BY status
    ORDER BY FIELD(status,'pending','confirmed','delivering','done','cancelled')
")->fetchAll(PDO::FETCH_ASSOC);

$s2 = [];
$s2r = 1;
$s2[] = "<row r=\"{$s2r}\"><c r=\"A{$s2r}\" t=\"s\" s=\"".ST_TITLE."\"><v>".addStr('Résumé par statut')."</v></c></row>";
$s2r++;
$s2[] = "<row r=\"{$s2r}\"/>";
$s2r++;
$s2[] = "<row r=\"{$s2r}\">"
    . cellS('A',$s2r,'Statut',    ST_HEADER)
    . cellS('B',$s2r,'Nb commandes',ST_HEADER)
    . cellS('C',$s2r,'Total CFA',   ST_HEADER)
    . "</row>";
$s2r++;
$statStart = $s2r;
foreach ($stats as $st2) {
    $s2[] = "<row r=\"{$s2r}\">"
        . cellS('A',$s2r, $SL[$st2['status']] ?? $st2['status'], ST_DEFAULT)
        . cellN('B',$s2r, (float)$st2['nb'],    ST_CENTER)
        . cellN('C',$s2r, (float)$st2['total'], ST_MONEY)
        . "</row>";
    $s2r++;
}
$statEnd = $s2r - 1;
$s2[] = "<row r=\"{$s2r}\">"
    . cellS('A',$s2r,'TOTAL',ST_MONEYBOLD)
    . cellF('B',$s2r,"SUM(B{$statStart}:B{$statEnd})", ST_MONEYBOLD)
    . cellF('C',$s2r,"SUM(C{$statStart}:C{$statEnd})", ST_MONEYBOLD)
    . "</row>";
$s2r++;

/* Stats supplémentaires */
$s2r++;
$s2[] = "<row r=\"{$s2r}\"><c r=\"A{$s2r}\" t=\"s\" s=\"".ST_SUBHEAD."\"><v>".addStr('Statistiques du jour')."</v></c></row>";
$s2r++;
$todayStats = $pdo->query("
    SELECT COUNT(*) AS nb,
           COALESCE(SUM(CASE WHEN status!='cancelled' THEN total_amount END),0) AS ca
    FROM orders WHERE DATE(created_at)=CURDATE()
")->fetch(PDO::FETCH_ASSOC);
$s2[] = "<row r=\"{$s2r}\">"
    . cellS('A',$s2r,'Commandes aujourd\'hui', ST_DEFAULT)
    . cellN('B',$s2r, (float)$todayStats['nb'], ST_CENTER)
    . "</row>";
$s2r++;
$s2[] = "<row r=\"{$s2r}\">"
    . cellS('A',$s2r,'CA aujourd\'hui (CFA)', ST_DEFAULT)
    . cellN('C',$s2r, (float)$todayStats['ca'], ST_MONEY)
    . "</row>";

/* ══ CONSTRUCTION DU FICHIER XLSX ══ */

/* --- Shared Strings XML --- */
/* (généré après avoir parcouru toutes les lignes) */

/* --- styles.xml --- */
$stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="6">
    <font><sz val="11"/><name val="Arial"/></font>
    <font><sz val="11"/><name val="Arial"/><b/><color rgb="FFFFFFFF"/></font>    <!-- 1 blanc gras -->
    <font><sz val="11"/><name val="Arial"/><b/><color rgb="FF005C30"/></font>    <!-- 2 vert gras -->
    <font><sz val="11"/><name val="Arial"/><color rgb="FF888888"/></font>        <!-- 3 gris -->
    <font><sz val="11"/><name val="Arial"/><b/><color rgb="FF7B5A00"/></font>    <!-- 4 or gras -->
    <font><sz val="16"/><name val="Arial"/><b/><color rgb="FF005C30"/></font>    <!-- 5 titre -->
  </fonts>
  <fills count="4">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF007A44"/></patternFill></fill>  <!-- 2 vert foncé -->
    <fill><patternFill patternType="solid"><fgColor rgb="FFD6EFE4"/></patternFill></fill>  <!-- 3 vert clair -->
  </fills>
  <borders count="2">
    <border><left/><right/><top/><bottom/><diagonal/></border>
    <border>
      <left style="thin"><color rgb="FFCCCCCC"/></left>
      <right style="thin"><color rgb="FFCCCCCC"/></right>
      <top style="thin"><color rgb="FFCCCCCC"/></top>
      <bottom style="thin"><color rgb="FFCCCCCC"/></bottom>
    </border>
  </borders>
  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
  <cellXfs count="11">
    <!-- 0 Default -->
    <xf numFmtId="0"   fontId="0" fillId="0" borderId="1" xfId="0"><alignment wrapText="0"/></xf>
    <!-- 1 Header: vert foncé, blanc gras -->
    <xf numFmtId="0"   fontId="1" fillId="2" borderId="1" xfId="0"><alignment horizontal="center" vertical="center"/></xf>
    <!-- 2 Subheader: vert clair, vert gras -->
    <xf numFmtId="0"   fontId="2" fillId="3" borderId="1" xfId="0"><alignment horizontal="left"/></xf>
    <!-- 3 Money: nombre avec séparateur -->
    <xf numFmtId="3"   fontId="0" fillId="0" borderId="1" xfId="0"><alignment horizontal="right"/></xf>
    <!-- 4 Money Bold Green -->
    <xf numFmtId="3"   fontId="2" fillId="3" borderId="1" xfId="0"><alignment horizontal="right"/></xf>
    <!-- 5 Bold -->
    <xf numFmtId="0"   fontId="2" fillId="0" borderId="1" xfId="0"/>
    <!-- 6 Muted gray -->
    <xf numFmtId="0"   fontId="3" fillId="0" borderId="0" xfId="0"><alignment wrapText="1"/></xf>
    <!-- 7 Order number: or gras -->
    <xf numFmtId="0"   fontId="4" fillId="0" borderId="1" xfId="0"><alignment horizontal="left"/></xf>
    <!-- 8 Wrap -->
    <xf numFmtId="0"   fontId="0" fillId="0" borderId="1" xfId="0"><alignment wrapText="1" vertical="top"/></xf>
    <!-- 9 Center -->
    <xf numFmtId="0"   fontId="0" fillId="0" borderId="1" xfId="0"><alignment horizontal="center"/></xf>
    <!-- 10 Title big -->
    <xf numFmtId="0"   fontId="5" fillId="0" borderId="0" xfId="0"><alignment horizontal="left"/></xf>
  </cellXfs>
</styleSheet>';

/* --- workbook.xml --- */
$workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
          xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Bons de Livraison" sheetId="1" r:id="rId1"/>
    <sheet name="Resume" sheetId="2" r:id="rId2"/>
  </sheets>
</workbook>';

/* --- workbook.xml.rels --- */
$workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>
  <Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>';

/* --- .rels --- */
$rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>';

/* --- [Content_Types].xml --- */
$contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml"  ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml"            ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml"   ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/worksheets/sheet2.xml"   ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/sharedStrings.xml"       ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>
  <Override PartName="/xl/styles.xml"              ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>';

/* --- sheet1.xml colonnes widths + print setup --- */
function buildSheet(array $rows, array $colWidths, string $title, int $freezeRow=5): string {
    $cwXml = '<cols>';
    foreach ($colWidths as $idx => $w) {
        $n = $idx + 1;
        $cwXml .= "<col min=\"{$n}\" max=\"{$n}\" width=\"{$w}\" customWidth=\"1\"/>";
    }
    $cwXml .= '</cols>';

    $rowsXml = implode("\n", $rows);

    $freeze = $freezeRow > 1
        ? "<sheetView tabSelected=\"1\" workbookViewId=\"0\"><pane ySplit=\"{$freezeRow}\" topLeftCell=\"A".($freezeRow+1)."\" activePane=\"bottomLeft\" state=\"frozen\"/></sheetView>"
        : "<sheetView tabSelected=\"1\" workbookViewId=\"0\"/>";

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <sheetViews>'.$freeze.'</sheetViews>
  <sheetFormatPr defaultRowHeight="15" customHeight="1"/>
  '.$cwXml.'
  <sheetData>
'.$rowsXml.'
  </sheetData>
  <pageSetup orientation="landscape" paperSize="9" fitToPage="1" fitToWidth="1" fitToHeight="0"/>
  <pageMargins left="0.5" right="0.5" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>
  <headerFooter>
    <oddHeader>&amp;L&amp;B ESPERANCE H2O — Bons de Livraison&amp;R&amp;D &amp;T</oddHeader>
    <oddFooter>&amp;CPage &amp;P / &amp;N</oddFooter>
  </headerFooter>
</worksheet>';
}

$sheet1Xml = buildSheet(
    $sheet1Rows,
    [18, 16, 22, 15, 30, 18, 14, 14, 14, 10, 16],  // largeurs colonnes A-K
    'Bons de Livraison',
    4
);

$sheet2Xml = buildSheet(
    $s2,
    [22, 14, 16],
    'Resume',
    3
);

/* --- sharedStrings.xml --- */
function buildSST(): string {
    global $sst;
    $total = count($sst);
    $xml   = "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n";
    $xml  .= "<sst xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\" count=\"{$total}\" uniqueCount=\"{$total}\">\n";
    foreach ($sst as $s) {
        $xml .= '<si><t xml:space="preserve">'.xesc($s).'</t></si>'."\n";
    }
    $xml .= '</sst>';
    return $xml;
}

/* ══ ASSEMBLAGE DU ZIP / XLSX ══ */
$tmpFile = sys_get_temp_dir().'/bons_'.uniqid().'.xlsx';

$zip = new ZipArchive();
if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500); die('Impossible de créer le fichier Excel');
}

$zip->addFromString('[Content_Types].xml',              $contentTypes);
$zip->addFromString('_rels/.rels',                      $rootRels);
$zip->addFromString('xl/workbook.xml',                  $workbookXml);
$zip->addFromString('xl/_rels/workbook.xml.rels',       $workbookRels);
$zip->addFromString('xl/styles.xml',                    $stylesXml);
$zip->addFromString('xl/worksheets/sheet1.xml',         $sheet1Xml);
$zip->addFromString('xl/worksheets/sheet2.xml',         $sheet2Xml);
$zip->addFromString('xl/sharedStrings.xml',             buildSST());
$zip->close();

/* ══ ENVOI DU FICHIER ══ */
$filename = 'bons_livraison_'.date('Y-m-d_H-i').'.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Content-Length: '.filesize($tmpFile));
header('Cache-Control: max-age=0');
header('Pragma: public');

readfile($tmpFile);
unlink($tmpFile);
exit;
