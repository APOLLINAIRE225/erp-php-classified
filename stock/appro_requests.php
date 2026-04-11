<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

/**
 * ═══════════════════════════════════════════════════════════════
 * APPRO REQUESTS — ESPERANCE H2O  [ENHANCED v3.0]
 * ✅ Auto-confirmation AJAX 2min + countdown live
 * ✅ Onglet Notifications (auto-confirmées tracées)
 * ✅ Export Excel Windows multi-feuilles par catégorie
 * ✅ Contrôle strict audit-trail complet
 * ═══════════════════════════════════════════════════════════════
 */
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once APP_ROOT . '/app/core/DB.php';
require_once APP_ROOT . '/app/core/Auth.php';
require_once APP_ROOT . '/app/core/Middleware.php';

use App\Core\DB;
use App\Core\Auth;
use App\Core\Middleware;

Auth::check();
Middleware::role(['developer', 'admin', 'caissiere', 'cashier', 'user']);

$pdo = DB::getConnection();

/* ─── Auto-install tables ─── */
$pdo->exec("CREATE TABLE IF NOT EXISTS appro_requests (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  company_id      INT NOT NULL,
  city_id         INT NOT NULL,
  product_id      INT NOT NULL,
  requested_by    INT NOT NULL,
  quantity        DECIMAL(10,2) NOT NULL,
  unit_type       ENUM('detail','carton') NOT NULL DEFAULT 'detail',
  note            TEXT,
  status          ENUM('en_attente','confirmee','rejetee','annulee') NOT NULL DEFAULT 'en_attente',
  admin_id        INT DEFAULT NULL,
  admin_note      TEXT,
  created_at      DATETIME DEFAULT NOW(),
  updated_at      DATETIME DEFAULT NOW() ON UPDATE NOW()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS appro_request_history (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  request_id  INT NOT NULL,
  user_id     INT NOT NULL,
  action      VARCHAR(80) NOT NULL,
  details     TEXT,
  ip_address  VARCHAR(45),
  created_at  DATETIME DEFAULT NOW()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ─── NOUVELLE TABLE: Notifications ─── */
$pdo->exec("CREATE TABLE IF NOT EXISTS appro_notifications (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  company_id  INT NOT NULL,
  city_id     INT NOT NULL,
  request_id  INT NOT NULL,
  type        ENUM('auto_confirmee','alerte','info') NOT NULL DEFAULT 'auto_confirmee',
  message     TEXT,
  is_read     TINYINT(1) NOT NULL DEFAULT 0,
  triggered_by VARCHAR(40) DEFAULT 'SYSTEM',
  created_at  DATETIME DEFAULT NOW()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ─── Infos utilisateur ─── */
$user_id   = $_SESSION['user_id'] ?? 0;
$user_name = $_SESSION['username'] ?? $_SESSION['user_name'] ?? '';
$user_role = $_SESSION['role'] ?? 'user';

if (!$user_name) {
    $st = $pdo->prepare("SELECT username, role FROM users WHERE id=?");
    $st->execute([$user_id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r) { $user_name = $r['username']; $user_role = $r['role'] ?? $user_role; }
}

$is_admin = in_array($user_role, ['developer', 'admin']);

/* ─── Logger historique ─── */
function logHistory($pdo, $request_id, $user_id, $action, $details = '') {
    $st = $pdo->prepare("INSERT INTO appro_request_history
        (request_id, user_id, action, details, ip_address) VALUES (?,?,?,?,?)");
    $st->execute([$request_id, $user_id, $action, $details,
        $_SERVER['REMOTE_ADDR'] ?? '?']);
}

/* ─── Localisation ─── */
if (!isset($_SESSION['appro_company_id'])) $_SESSION['appro_company_id'] = $_SESSION['caisse_company_id'] ?? 0;
if (!isset($_SESSION['appro_city_id']))    $_SESSION['appro_city_id']    = $_SESSION['caisse_city_id']    ?? 0;
if (isset($_GET['company_id'])) $_SESSION['appro_company_id'] = (int)$_GET['company_id'];
if (isset($_GET['confirm_location'], $_GET['city_id'])) {
    $_SESSION['appro_city_id'] = (int)$_GET['city_id'];
    header("Location: appro_requests.php"); exit;
}

$company_id   = (int)$_SESSION['appro_company_id'];
$city_id      = (int)$_SESSION['appro_city_id'];
$location_set = ($company_id > 0 && $city_id > 0);

/* ════════════════════════════════════════════════════════
   ██  EXCEL EXPORT HANDLER  ██
════════════════════════════════════════════════════════ */
if (isset($_GET['export']) && $_GET['export'] === 'xlsx' && $location_set) {

    $export_type    = $_GET['etype'] ?? 'history';   // history | requests
    $filter_cat     = $_GET['ecat']  ?? '';           // '' = toutes catégories
    $filter_status  = $_GET['estat'] ?? '';
    $date_from      = $_GET['dfrom'] ?? '';
    $date_to        = $_GET['dto']   ?? '';

    /* ── Récupère données history ── */
    $params_h = [$company_id, $city_id];
    $where_h  = "WHERE ar.company_id=? AND ar.city_id=?";
    if ($filter_cat)    { $where_h .= " AND p.category=?"; $params_h[] = $filter_cat; }
    if ($filter_status) { $where_h .= " AND ar.status=?";  $params_h[] = $filter_status; }
    if ($date_from)     { $where_h .= " AND arh.created_at >= ?"; $params_h[] = $date_from . ' 00:00:00'; }
    if ($date_to)       { $where_h .= " AND arh.created_at <= ?"; $params_h[] = $date_to . ' 23:59:59'; }

    $sql_h = "SELECT arh.created_at, arh.action, u.username user_name,
        ar.id request_id, p.name product_name, COALESCE(p.category,'Non catégorisé') category,
        ar.quantity, ar.unit_type, ar.status, COALESCE(ar.admin_note,'') admin_note, arh.details,
        arh.ip_address
        FROM appro_request_history arh
        JOIN appro_requests ar ON ar.id=arh.request_id
        JOIN products p ON p.id=ar.product_id
        LEFT JOIN users u ON u.id=arh.user_id
        $where_h ORDER BY p.category, arh.created_at DESC LIMIT 5000";
    $st = $pdo->prepare($sql_h);
    $st->execute($params_h);
    $hist_rows = $st->fetchAll(PDO::FETCH_ASSOC);

    /* ── Récupère données demandes ── */
    $params_r = [$company_id, $city_id];
    $where_r  = "WHERE ar.company_id=? AND ar.city_id=?";
    if ($filter_cat)    { $where_r .= " AND p.category=?"; $params_r[] = $filter_cat; }
    if ($filter_status) { $where_r .= " AND ar.status=?";  $params_r[] = $filter_status; }
    if ($date_from)     { $where_r .= " AND ar.created_at >= ?"; $params_r[] = $date_from . ' 00:00:00'; }
    if ($date_to)       { $where_r .= " AND ar.created_at <= ?"; $params_r[] = $date_to . ' 23:59:59'; }

    $sql_r = "SELECT ar.id, ar.created_at, ar.updated_at,
        p.name product_name, COALESCE(p.category,'Non catégorisé') category,
        u.username requester_name, ar.quantity, ar.unit_type,
        ar.status, COALESCE(ar.note,'') note, COALESCE(ar.admin_note,'') admin_note,
        COALESCE(a.username,'—') admin_name
        FROM appro_requests ar
        JOIN products p ON p.id=ar.product_id
        JOIN users u ON u.id=ar.requested_by
        LEFT JOIN users a ON a.id=ar.admin_id
        $where_r ORDER BY p.category, ar.created_at DESC LIMIT 5000";
    $st2 = $pdo->prepare($sql_r);
    $st2->execute($params_r);
    $req_rows = $st2->fetchAll(PDO::FETCH_ASSOC);

    /* ── Builder XLSX inline ── */
    function buildXLSX(array $sheets): string {
        /* sheets = [ ['title'=>'...', 'headers'=>[], 'rows'=>[], 'col_styles'=>[]], ... ] */

        $esc = fn($v) => htmlspecialchars((string)$v, ENT_XML1, 'UTF-8');
        $shared_strings = [];
        $si_cache = [];

        $getSI = function(string $s) use (&$shared_strings, &$si_cache): int {
            if (!isset($si_cache[$s])) {
                $si_cache[$s] = count($shared_strings);
                $shared_strings[] = $s;
            }
            return $si_cache[$s];
        };

        /* ─ styles.xml ─ */
        $styles_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="6">
    <font><sz val="11"/><name val="Arial"/></font>
    <font><sz val="11"/><b/><name val="Arial"/><color rgb="FFFFFFFF"/></font>
    <font><sz val="11"/><b/><name val="Arial"/></font>
    <font><sz val="10"/><name val="Arial"/><color rgb="FF5A8070"/></font>
    <font><sz val="11"/><name val="Arial"/><color rgb="FF32be8f"/></font>
    <font><sz val="11"/><name val="Arial"/><color rgb="FFff3553"/></font>
  </fonts>
  <fills count="7">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF0d1e2c"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF081420"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF32be8f"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFff3553"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF122030"/></patternFill></fill>
  </fills>
  <borders count="2">
    <border><left/><right/><top/><bottom/><diagonal/></border>
    <border><left style="thin"><color rgb="FF1a3040"/></left><right style="thin"><color rgb="FF1a3040"/></right><top style="thin"><color rgb="FF1a3040"/></top><bottom style="thin"><color rgb="FF1a3040"/></bottom><diagonal/></border>
  </borders>
  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
  <cellXfs count="9">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0"><alignment wrapText="1" vertical="center"/></xf>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0"><alignment wrapText="1" vertical="center"/></xf>
    <xf numFmtId="0" fontId="3" fillId="6" borderId="1" xfId="0"><alignment wrapText="1" vertical="center"/></xf>
    <xf numFmtId="0" fontId="4" fillId="6" borderId="1" xfId="0"><alignment wrapText="1" vertical="center"/></xf>
    <xf numFmtId="0" fontId="5" fillId="6" borderId="1" xfId="0"><alignment wrapText="1" vertical="center"/></xf>
    <xf numFmtId="14" fontId="0" fillId="6" borderId="1" xfId="0"><alignment vertical="center"/></xf>
    <xf numFmtId="0" fontId="1" fillId="4" borderId="1" xfId="0"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="1" fillId="5" borderId="1" xfId="0"><alignment horizontal="center" vertical="center"/></xf>
  </cellXfs>
</styleSheet>';

        /* ─ worksheet builder ─ */
        $buildSheet = function(array $sheet_def) use ($getSI, $esc): string {
            $headers = $sheet_def['headers'];
            $rows    = $sheet_def['rows'];
            $col_styles = $sheet_def['col_styles'] ?? [];
            $nc = count($headers);

            /* Estimate col widths */
            $widths = array_fill(0, $nc, 12);
            foreach ($headers as $i => $h) {
                $widths[$i] = max($widths[$i], mb_strlen($h) + 3);
            }
            foreach (array_slice($rows, 0, 50) as $row) {
                foreach ($row as $i => $v) {
                    $widths[$i] = min(55, max($widths[$i] ?? 12, mb_strlen((string)$v) + 2));
                }
            }

            $col_letters = [];
            for ($i = 0; $i < $nc; $i++) {
                $col_letters[] = $i < 26 ? chr(65 + $i) : chr(64 + intdiv($i, 26)) . chr(65 + ($i % 26));
            }

            $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<sheetView showGridLines="0" workbookViewId="0"/>
<sheetFormatPr defaultRowHeight="18" customHeight="1"/>
<cols>';
            foreach ($widths as $i => $w) {
                $cn = $i + 1;
                $xml .= "<col min=\"$cn\" max=\"$cn\" width=\"$w\" customWidth=\"1\"/>";
            }
            $xml .= '</cols><sheetData>';

            /* Header row */
            $xml .= '<row r="1" ht="22" customHeight="1">';
            foreach ($headers as $ci => $h) {
                $cl = $col_letters[$ci] . '1';
                $si = $getSI($h);
                $xml .= "<c r=\"$cl\" t=\"s\" s=\"1\"><v>$si</v></c>";
            }
            $xml .= '</row>';

            /* Data rows */
            foreach ($rows as $ri => $row) {
                $rn = $ri + 2;
                $s_row = ($rn % 2 === 0) ? 3 : 2;
                $xml .= "<row r=\"$rn\" ht=\"18\">";
                $vals = array_values($row);
                foreach ($vals as $ci => $v) {
                    $cl = $col_letters[$ci] . $rn;
                    $cs = $col_styles[$ci] ?? $s_row;
                    $sv = (string)$v;
                    if (is_numeric($v) && !empty($v)) {
                        $xml .= "<c r=\"$cl\" s=\"$cs\"><v>" . $esc($v) . "</v></c>";
                    } else {
                        $si = $getSI($sv);
                        $xml .= "<c r=\"$cl\" t=\"s\" s=\"$cs\"><v>$si</v></c>";
                    }
                }
                $xml .= '</row>';
            }
            $xml .= '</sheetData>';

            /* AutoFilter */
            if (!empty($rows)) {
                $lastCol = $col_letters[$nc - 1];
                $lastRow = count($rows) + 1;
                $xml .= "<autoFilter ref=\"A1:{$lastCol}{$lastRow}\"/>";
            }
            $xml .= '</worksheet>';
            return $xml;
        };

        /* ─ Build all sheets ─ */
        $sheet_xmls = [];
        $sheet_titles = [];
        foreach ($sheets as $sd) {
            $sheet_xmls[]   = $buildSheet($sd);
            $sheet_titles[] = $sd['title'];
        }

        /* ─ sharedStrings.xml ─ */
        $ss_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($shared_strings) . '" uniqueCount="' . count($shared_strings) . '">';
        foreach ($shared_strings as $s) {
            $ss_xml .= '<si><t xml:space="preserve">' . $esc($s) . '</t></si>';
        }
        $ss_xml .= '</sst>';

        /* ─ workbook.xml ─ */
        $wb_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
 xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
<sheets>';
        foreach ($sheet_titles as $i => $t) {
            $sid = $i + 1;
            $wb_xml .= "<sheet name=\"" . $esc(mb_substr($t, 0, 31)) . "\" sheetId=\"$sid\" r:id=\"rId$sid\"/>";
        }
        $wb_xml .= '</sheets></workbook>';

        /* ─ workbook.xml.rels ─ */
        $wb_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($sheet_titles as $i => $t) {
            $sid = $i + 1;
            $wb_rels .= "<Relationship Id=\"rId$sid\"
 Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\"
 Target=\"worksheets/sheet{$sid}.xml\"/>";
        }
        $wb_rels .= "<Relationship Id=\"rId" . (count($sheet_titles)+1) . "\"
 Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings\"
 Target=\"sharedStrings.xml\"/>
<Relationship Id=\"rId" . (count($sheet_titles)+2) . "\"
 Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles\"
 Target=\"styles.xml\"/>
</Relationships>";

        /* ─ Content Types ─ */
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        foreach ($sheet_titles as $i => $t) {
            $sid = $i + 1;
            $ct .= "<Override PartName=\"/xl/worksheets/sheet{$sid}.xml\"
 ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
        }
        $ct .= '</Types>';

        /* ─ _rels/.rels ─ */
        $root_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1"
   Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"
   Target="xl/workbook.xml"/>
</Relationships>';

        /* ─ assemble ZIP in memory ─ */
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', $ct);
        $zip->addFromString('_rels/.rels', $root_rels);
        $zip->addFromString('xl/workbook.xml', $wb_xml);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wb_rels);
        $zip->addFromString('xl/styles.xml', $styles_xml);
        $zip->addFromString('xl/sharedStrings.xml', $ss_xml);
        foreach ($sheet_xmls as $i => $sxml) {
            $zip->addFromString("xl/worksheets/sheet" . ($i+1) . ".xml", $sxml);
        }
        $zip->close();
        $content = file_get_contents($tmp);
        unlink($tmp);
        return $content;
    } // end buildXLSX

    /* ─── Préparer les feuilles ─── */
    $sheets = [];

    // ── Feuille 1 : RÉSUMÉ ──
    $status_labels = ['en_attente'=>'En attente','confirmee'=>'Confirmée','rejetee'=>'Rejetée','annulee'=>'Annulée'];
    $unit_labels   = ['detail'=>'Détail','carton'=>'Carton'];
    $action_labels = ['SOUMISSION'=>'Soumission','MODIFICATION'=>'Modification',
        'ANNULATION'=>'Annulation','CONFIRMATION'=>'Confirmation Admin',
        'AUTO_CONFIRMATION'=>'Auto-confirmation (2min)','REJET'=>'Rejet'];

    // ── Feuille 2 : TOUTES LES DEMANDES ──
    $req_headers = ['#ID','Produit','Catégorie','Quantité','Unité','Demandé par',
        'Date demande','Statut','Note','Note Admin','Validé par'];
    $req_data = [];
    foreach ($req_rows as $r) {
        $req_data[] = [
            '#' . $r['id'],
            $r['product_name'],
            $r['category'],
            $r['quantity'] + 0,
            $unit_labels[$r['unit_type']] ?? $r['unit_type'],
            $r['requester_name'],
            date('d/m/Y H:i', strtotime($r['created_at'])),
            $status_labels[$r['status']] ?? $r['status'],
            $r['note'],
            $r['admin_note'],
            $r['admin_name'],
        ];
    }
    $sheets[] = [
        'title'   => 'Toutes les demandes',
        'headers' => $req_headers,
        'rows'    => $req_data,
    ];

    // ── Feuilles par catégorie (demandes) ──
    $req_by_cat = [];
    foreach ($req_rows as $r) {
        $cat = $r['category'] ?: 'Non catégorisé';
        $req_by_cat[$cat][] = $r;
    }
    foreach ($req_by_cat as $cat => $cat_rows) {
        $cat_data = [];
        foreach ($cat_rows as $r) {
            $cat_data[] = [
                '#' . $r['id'],
                $r['product_name'],
                $r['quantity'] + 0,
                $unit_labels[$r['unit_type']] ?? $r['unit_type'],
                $r['requester_name'],
                date('d/m/Y H:i', strtotime($r['created_at'])),
                $status_labels[$r['status']] ?? $r['status'],
                $r['note'],
                $r['admin_note'],
                $r['admin_name'],
            ];
        }
        $sheets[] = [
            'title'   => mb_substr('📦 ' . $cat, 0, 31),
            'headers' => ['#ID','Produit','Quantité','Unité','Demandé par','Date','Statut','Note','Note Admin','Validé par'],
            'rows'    => $cat_data,
        ];
    }

    // ── Feuille : HISTORIQUE COMPLET ──
    $hist_headers = ['Date & Heure','Req #','Action','Utilisateur','Produit','Catégorie','Quantité','Unité','Statut','Détails','IP'];
    $hist_data = [];
    foreach ($hist_rows as $h) {
        $hist_data[] = [
            date('d/m/Y H:i:s', strtotime($h['created_at'])),
            '#' . $h['request_id'],
            $action_labels[$h['action']] ?? $h['action'],
            $h['user_name'] ?? '?',
            $h['product_name'],
            $h['category'],
            $h['quantity'] + 0,
            $unit_labels[$h['unit_type']] ?? $h['unit_type'],
            $status_labels[$h['status']] ?? $h['status'],
            mb_strimwidth($h['details'], 0, 200, '…'),
            $h['ip_address'],
        ];
    }
    $sheets[] = [
        'title'   => '📋 Historique complet',
        'headers' => $hist_headers,
        'rows'    => $hist_data,
    ];

    // ── Feuilles historique par catégorie ──
    $hist_by_cat = [];
    foreach ($hist_rows as $h) {
        $cat = $h['category'] ?: 'Non catégorisé';
        $hist_by_cat[$cat][] = $h;
    }
    foreach ($hist_by_cat as $cat => $cat_rows) {
        $cat_data = [];
        foreach ($cat_rows as $h) {
            $cat_data[] = [
                date('d/m/Y H:i:s', strtotime($h['created_at'])),
                '#' . $h['request_id'],
                $action_labels[$h['action']] ?? $h['action'],
                $h['user_name'] ?? '?',
                $h['product_name'],
                $h['quantity'] + 0,
                $unit_labels[$h['unit_type']] ?? $h['unit_type'],
                $status_labels[$h['status']] ?? $h['status'],
                mb_strimwidth($h['details'], 0, 150, '…'),
            ];
        }
        $sheets[] = [
            'title'   => mb_substr('📊 ' . $cat, 0, 31),
            'headers' => ['Date & Heure','Req #','Action','Utilisateur','Produit','Quantité','Unité','Statut','Détails'],
            'rows'    => $cat_data,
        ];
    }

    // ── Feuille : AUTO-CONFIRMATIONS ──
    $st_ac = $pdo->prepare("SELECT n.created_at, n.request_id, p.name product_name,
        COALESCE(p.category,'Non catégorisé') category,
        ar.quantity, ar.unit_type, n.message, n.triggered_by
        FROM appro_notifications n
        JOIN appro_requests ar ON ar.id=n.request_id
        JOIN products p ON p.id=ar.product_id
        WHERE n.company_id=? AND n.city_id=? AND n.type='auto_confirmee'
        ORDER BY n.created_at DESC LIMIT 2000");
    $st_ac->execute([$company_id, $city_id]);
    $auto_rows = $st_ac->fetchAll(PDO::FETCH_ASSOC);
    $auto_data = [];
    foreach ($auto_rows as $a) {
        $auto_data[] = [
            date('d/m/Y H:i:s', strtotime($a['created_at'])),
            '#' . $a['request_id'],
            $a['product_name'],
            $a['category'],
            $a['quantity'] + 0,
            $unit_labels[$a['unit_type']] ?? $a['unit_type'],
            $a['message'],
            $a['triggered_by'],
        ];
    }
    $sheets[] = [
        'title'   => '🤖 Auto-confirmations',
        'headers' => ['Date & Heure','Req #','Produit','Catégorie','Quantité','Unité','Message','Déclenché par'],
        'rows'    => $auto_data,
    ];

    /* ─── Output ─── */
    $xlsx_content = buildXLSX($sheets);
    $fname = 'ESPERANCE_H2O_Appro_' . date('Y-m-d_H-i') . '.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Content-Length: ' . strlen($xlsx_content));
    header('Cache-Control: max-age=0');
    header('Pragma: public');
    echo $xlsx_content;
    exit;
}

/* ════════════════════════════════════════════════════════
   ██  AJAX HANDLER  ██
════════════════════════════════════════════════════════ */
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=UTF-8');
    $ajax_cid = (int)($_GET['company_id'] ?? $company_id);
    $ajax_vid = (int)($_GET['city_id']    ?? $city_id);

    /* ─── AUTO-CONFIRM: Confirme toutes les demandes > 2 minutes ─── */
    if ($_GET['ajax'] === 'auto_confirm') {
        $confirmed = [];
        $st = $pdo->prepare("SELECT ar.*, p.name product_name, p.category product_category
            FROM appro_requests ar JOIN products p ON p.id=ar.product_id
            WHERE ar.company_id=? AND ar.city_id=? AND ar.status='en_attente'
              AND ar.created_at <= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
            FOR UPDATE");
        // Wrap in try/catch since FOR UPDATE might fail outside transaction
        try {
            $pdo->beginTransaction();
            $st->execute([$ajax_cid, $ajax_vid]);
            $pending = $st->fetchAll(PDO::FETCH_ASSOC);

            foreach ($pending as $req) {
                $ref = "APPRO-AUTO-{$req['id']}";

                /* Update status */
                $pdo->prepare("UPDATE appro_requests SET status='confirmee',
                    admin_id=NULL, admin_note='Auto-confirmé après 2 minutes (aucune action admin)',
                    updated_at=NOW() WHERE id=?")
                    ->execute([$req['id']]);

                /* Stock movement */
                $pdo->prepare("INSERT INTO stock_movements
                    (product_id, reference, company_id, city_id, type, quantity, movement_date)
                    VALUES (?, ?, ?, ?, 'entry', ?, NOW())")
                    ->execute([$req['product_id'], $ref, $req['company_id'], $req['city_id'], $req['quantity']]);

                /* History log */
                logHistory($pdo, $req['id'], 0, 'AUTO_CONFIRMATION',
                    "⚙️ AUTO-CONFIRMÉ par le système (délai 2min dépassé) — Produit: {$req['product_name']} — Qté: {$req['quantity']} {$req['unit_type']} — Stock +{$req['quantity']}");

                /* Notification record */
                $pdo->prepare("INSERT INTO appro_notifications
                    (company_id, city_id, request_id, type, message, is_read, triggered_by)
                    VALUES (?,?,?,'auto_confirmee',?,0,'SYSTEM')")
                    ->execute([$req['company_id'], $req['city_id'], $req['id'],
                        "Auto-confirmé: {$req['product_name']} +{$req['quantity']} {$req['unit_type']}"]);

                $confirmed[] = [
                    'id'           => (int)$req['id'],
                    'product_name' => $req['product_name'],
                    'category'     => $req['product_category'] ?? '',
                    'quantity'     => (float)$req['quantity'],
                    'unit_type'    => $req['unit_type'],
                ];
            }
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['error' => $e->getMessage(), 'confirmed' => []]);
            exit;
        }

        /* Unread notif count */
        $st_n = $pdo->prepare("SELECT COUNT(*) FROM appro_notifications WHERE company_id=? AND city_id=? AND is_read=0");
        $st_n->execute([$ajax_cid, $ajax_vid]);
        $unread = (int)$st_n->fetchColumn();

        echo json_encode(['confirmed' => $confirmed, 'count' => count($confirmed), 'unread_notif' => $unread]);
        exit;
    }

    /* ─── GET PENDING LIST with timestamps ─── */
    if ($_GET['ajax'] === 'get_pending') {
        $st = $pdo->prepare("SELECT ar.id, ar.created_at, ar.quantity, ar.unit_type,
            p.name product_name, u.username requester_name,
            TIMESTAMPDIFF(SECOND, ar.created_at, NOW()) age_seconds
            FROM appro_requests ar
            JOIN products p ON p.id=ar.product_id
            JOIN users u ON u.id=ar.requested_by
            WHERE ar.company_id=? AND ar.city_id=? AND ar.status='en_attente'
            ORDER BY ar.created_at ASC");
        $st->execute([$ajax_cid, $ajax_vid]);
        $pending = $st->fetchAll(PDO::FETCH_ASSOC);

        $st_n = $pdo->prepare("SELECT COUNT(*) FROM appro_notifications WHERE company_id=? AND city_id=? AND is_read=0");
        $st_n->execute([$ajax_cid, $ajax_vid]);
        $unread = (int)$st_n->fetchColumn();

        echo json_encode(['pending' => $pending, 'unread_notif' => $unread]);
        exit;
    }

    /* ─── MARK NOTIFICATIONS READ ─── */
    if ($_GET['ajax'] === 'mark_read') {
        $pdo->prepare("UPDATE appro_notifications SET is_read=1 WHERE company_id=? AND city_id=?")
            ->execute([$ajax_cid, $ajax_vid]);
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['error' => 'unknown ajax action']);
    exit;
}

/* ════════════════════════════════════════════════════════
   ACTIONS POST (unchanged from original + additions)
════════════════════════════════════════════════════════ */
$success_message = $error_message = '';
$view = $_GET['view'] ?? 'list';

/* ─ Sociétés / Villes ─ */
$companies = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$cities = [];
if ($company_id) {
    $st = $pdo->prepare("SELECT id, name FROM cities WHERE company_id=? ORDER BY name");
    $st->execute([$company_id]);
    $cities = $st->fetchAll(PDO::FETCH_ASSOC);
}

/* ─ Soumettre demande ─ */
if (isset($_POST['submit_request']) && $location_set) {
    $product_id = (int)$_POST['product_id'];
    $quantity   = (float)$_POST['quantity'];
    $unit_type  = $_POST['unit_type'] === 'carton' ? 'carton' : 'detail';
    $note       = trim($_POST['note'] ?? '');
    if ($product_id > 0 && $quantity > 0) {
        $st = $pdo->prepare("INSERT INTO appro_requests
            (company_id, city_id, product_id, requested_by, quantity, unit_type, note, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'en_attente')");
        $st->execute([$company_id, $city_id, $product_id, $user_id, $quantity, $unit_type, $note]);
        $req_id = $pdo->lastInsertId();
        $st2 = $pdo->prepare("SELECT name FROM products WHERE id=?");
        $st2->execute([$product_id]);
        $pname = $st2->fetchColumn();
        logHistory($pdo, $req_id, $user_id, 'SOUMISSION',
            "Demande créée par $user_name — Produit: $pname — Qté: $quantity $unit_type — Note: $note — ⚠️ Auto-confirmation dans 2 minutes si aucune action admin");
        $success_message = "✅ Demande envoyée ! Auto-confirmation dans 2 minutes si aucune action admin.";
        $view = 'list';
    } else {
        $error_message = "❌ Veuillez sélectionner un produit et indiquer une quantité valide.";
    }
}

/* ─ Modifier demande ─ */
if (isset($_POST['update_request'])) {
    $req_id   = (int)$_POST['request_id'];
    $quantity = (float)$_POST['quantity'];
    $unit_type = $_POST['unit_type'] === 'carton' ? 'carton' : 'detail';
    $note     = trim($_POST['note'] ?? '');
    $st = $pdo->prepare("SELECT * FROM appro_requests WHERE id=? AND status='en_attente'");
    $st->execute([$req_id]);
    $req = $st->fetch(PDO::FETCH_ASSOC);
    if ($req && ($req['requested_by'] == $user_id || $is_admin)) {
        $pdo->prepare("UPDATE appro_requests SET quantity=?, unit_type=?, note=?, updated_at=NOW() WHERE id=?")
            ->execute([$quantity, $unit_type, $note, $req_id]);
        logHistory($pdo, $req_id, $user_id, 'MODIFICATION',
            "Modifié par $user_name — Qté: {$req['quantity']} {$req['unit_type']} → $quantity $unit_type — Note: $note");
        $success_message = "✅ Demande #$req_id modifiée. Le timer 2min repart.";
    } else {
        $error_message = "❌ Impossible de modifier (déjà traitée ou non autorisé).";
    }
    $view = 'list';
}

/* ─ Annuler demande ─ */
if (isset($_POST['cancel_request'])) {
    $req_id = (int)$_POST['request_id'];
    $motif  = trim($_POST['cancel_motif'] ?? 'Annulé par la caissière');
    $st = $pdo->prepare("SELECT * FROM appro_requests WHERE id=? AND status='en_attente'");
    $st->execute([$req_id]);
    $req = $st->fetch(PDO::FETCH_ASSOC);
    if ($req && ($req['requested_by'] == $user_id || $is_admin)) {
        $pdo->prepare("UPDATE appro_requests SET status='annulee', admin_note=?, updated_at=NOW() WHERE id=?")
            ->execute([$motif, $req_id]);
        logHistory($pdo, $req_id, $user_id, 'ANNULATION', "Annulé par $user_name — Motif: $motif");
        $success_message = "✅ Demande #$req_id annulée.";
    } else {
        $error_message = "❌ Impossible d'annuler.";
    }
    $view = 'list';
}

/* ─ Confirmer (admin) ─ */
if (isset($_POST['confirm_request']) && $is_admin) {
    $req_id     = (int)$_POST['request_id'];
    $admin_note = trim($_POST['admin_note'] ?? '');
    $st = $pdo->prepare("SELECT ar.*, p.name product_name FROM appro_requests ar
        JOIN products p ON p.id=ar.product_id WHERE ar.id=? AND ar.status='en_attente'");
    $st->execute([$req_id]);
    $req = $st->fetch(PDO::FETCH_ASSOC);
    if ($req) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE appro_requests SET status='confirmee', admin_id=?, admin_note=?, updated_at=NOW() WHERE id=?")
                ->execute([$user_id, $admin_note, $req_id]);
            $ref = "APPRO-REQ-{$req_id}";
            $pdo->prepare("INSERT INTO stock_movements (product_id, reference, company_id, city_id, type, quantity, movement_date)
                VALUES (?, ?, ?, ?, 'entry', ?, NOW())")
                ->execute([$req['product_id'], $ref, $req['company_id'], $req['city_id'], $req['quantity']]);
            logHistory($pdo, $req_id, $user_id, 'CONFIRMATION',
                "✅ Confirmé MANUELLEMENT par $user_name (Admin) — Stock +{$req['quantity']} {$req['unit_type']} [{$req['product_name']}] — Note: $admin_note");
            $pdo->commit();
            $success_message = "✅ Appro #{$req_id} confirmée manuellement ! Stock +{$req['quantity']}.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_message = "❌ Erreur: " . $e->getMessage();
        }
    } else {
        $error_message = "❌ Demande introuvable ou déjà traitée.";
    }
    $view = 'list';
}

/* ─ Rejeter (admin) ─ */
if (isset($_POST['reject_request']) && $is_admin) {
    $req_id     = (int)$_POST['request_id'];
    $admin_note = trim($_POST['admin_note'] ?? '');
    $st = $pdo->prepare("SELECT * FROM appro_requests WHERE id=? AND status='en_attente'");
    $st->execute([$req_id]);
    $req = $st->fetch(PDO::FETCH_ASSOC);
    if ($req) {
        $pdo->prepare("UPDATE appro_requests SET status='rejetee', admin_id=?, admin_note=?, updated_at=NOW() WHERE id=?")
            ->execute([$user_id, $admin_note, $req_id]);
        logHistory($pdo, $req_id, $user_id, 'REJET', "❌ Rejeté par $user_name — Motif: $admin_note");
        $success_message = "Demande #$req_id rejetée.";
    } else {
        $error_message = "❌ Demande introuvable.";
    }
    $view = 'list';
}

/* ─ Chargement produits ─ */
$products = [];
if ($location_set) {
    $st = $pdo->prepare("SELECT p.id, p.name, p.category,
        COALESCE(SUM(CASE WHEN sm.type='initial'    THEN sm.quantity END),0)+
        COALESCE(SUM(CASE WHEN sm.type='entry'      THEN sm.quantity END),0)-
        COALESCE(SUM(CASE WHEN sm.type='exit'       THEN sm.quantity END),0)+
        COALESCE(SUM(CASE WHEN sm.type='adjustment' THEN sm.quantity END),0) AS stock_actuel
        FROM products p LEFT JOIN stock_movements sm ON sm.product_id=p.id AND sm.company_id=? AND sm.city_id=?
        WHERE p.company_id=? GROUP BY p.id ORDER BY p.name");
    $st->execute([$company_id, $city_id, $company_id]);
    $products = $st->fetchAll(PDO::FETCH_ASSOC);
}

/* ─ Liste catégories ─ */
$categories = [];
if ($location_set) {
    $st = $pdo->prepare("SELECT DISTINCT COALESCE(p.category,'Non catégorisé') cat
        FROM appro_requests ar JOIN products p ON p.id=ar.product_id
        WHERE ar.company_id=? AND ar.city_id=? ORDER BY cat");
    $st->execute([$company_id, $city_id]);
    $categories = array_column($st->fetchAll(PDO::FETCH_ASSOC), 'cat');
}

/* ─ Demandes ─ */
$requests = [];
if ($location_set) {
    if ($is_admin) {
        $st = $pdo->prepare("SELECT ar.*, p.name product_name, p.category product_category,
            u.username requester_name, a.username admin_name,
            c.name city_name, co.name company_name,
            TIMESTAMPDIFF(SECOND, ar.created_at, NOW()) age_seconds
            FROM appro_requests ar JOIN products p ON p.id=ar.product_id
            JOIN users u ON u.id=ar.requested_by LEFT JOIN users a ON a.id=ar.admin_id
            LEFT JOIN cities c ON c.id=ar.city_id LEFT JOIN companies co ON co.id=ar.company_id
            WHERE ar.company_id=? AND ar.city_id=?
            ORDER BY FIELD(ar.status,'en_attente','confirmee','rejetee','annulee'), ar.created_at DESC");
        $st->execute([$company_id, $city_id]);
    } else {
        $st = $pdo->prepare("SELECT ar.*, p.name product_name, p.category product_category,
            u.username requester_name, a.username admin_name,
            c.name city_name, co.name company_name,
            TIMESTAMPDIFF(SECOND, ar.created_at, NOW()) age_seconds
            FROM appro_requests ar JOIN products p ON p.id=ar.product_id
            JOIN users u ON u.id=ar.requested_by LEFT JOIN users a ON a.id=ar.admin_id
            LEFT JOIN cities c ON c.id=ar.city_id LEFT JOIN companies co ON co.id=ar.company_id
            WHERE ar.company_id=? AND ar.city_id=? AND ar.requested_by=?
            ORDER BY FIELD(ar.status,'en_attente','confirmee','rejetee','annulee'), ar.created_at DESC");
        $st->execute([$company_id, $city_id, $user_id]);
    }
    $requests = $st->fetchAll(PDO::FETCH_ASSOC);
}

/* ─ Stats ─ */
$stats = ['en_attente'=>0,'confirmee'=>0,'rejetee'=>0,'annulee'=>0];
foreach ($requests as $r) { $stats[$r['status']] = ($stats[$r['status']] ?? 0) + 1; }

/* ─ Notifications ─ */
$notifications = [];
$unread_count  = 0;
if ($location_set) {
    $st = $pdo->prepare("SELECT n.*, p.name product_name, COALESCE(p.category,'Non catégorisé') category,
        ar.quantity, ar.unit_type
        FROM appro_notifications n
        JOIN appro_requests ar ON ar.id=n.request_id
        JOIN products p ON p.id=ar.product_id
        WHERE n.company_id=? AND n.city_id=?
        ORDER BY n.created_at DESC LIMIT 200");
    $st->execute([$company_id, $city_id]);
    $notifications = $st->fetchAll(PDO::FETCH_ASSOC);
    $unread_count  = count(array_filter($notifications, fn($n) => !$n['is_read']));
}

/* ─ Historique demande ─ */
$req_history = []; $viewed_req = null;
if ($view === 'history' && isset($_GET['req_id'])) {
    $hid = (int)$_GET['req_id'];
    $st  = $pdo->prepare("SELECT ar.*, p.name product_name, u.username requester_name
        FROM appro_requests ar JOIN products p ON p.id=ar.product_id JOIN users u ON u.id=ar.requested_by WHERE ar.id=?");
    $st->execute([$hid]);
    $viewed_req = $st->fetch(PDO::FETCH_ASSOC);
    if ($viewed_req) {
        $st2 = $pdo->prepare("SELECT arh.*, u.username user_name FROM appro_request_history arh
            LEFT JOIN users u ON u.id=arh.user_id WHERE arh.request_id=? ORDER BY arh.created_at ASC");
        $st2->execute([$hid]);
        $req_history = $st2->fetchAll(PDO::FETCH_ASSOC);
    }
}

/* ─ Historique global ─ */
$global_history = [];
if ($view === 'full_history' && $location_set) {
    $fcat = $_GET['fcat'] ?? '';
    $fsta = $_GET['fsta'] ?? '';
    $fdfr = $_GET['fdfr'] ?? '';
    $fdto = $_GET['fdto'] ?? '';
    $params = [$company_id, $city_id];
    $extra  = '';
    if ($fcat) { $extra .= " AND p.category=?"; $params[] = $fcat; }
    if ($fsta) { $extra .= " AND ar.status=?";  $params[] = $fsta; }
    if ($fdfr) { $extra .= " AND arh.created_at >= ?"; $params[] = $fdfr . ' 00:00:00'; }
    if ($fdto) { $extra .= " AND arh.created_at <= ?"; $params[] = $fdto . ' 23:59:59'; }
    $st = $pdo->prepare("SELECT arh.*, u.username user_name, ar.product_id,
        p.name product_name, COALESCE(p.category,'Non catégorisé') product_category,
        ar.quantity, ar.unit_type, ar.status
        FROM appro_request_history arh
        JOIN appro_requests ar ON ar.id=arh.request_id
        JOIN products p ON p.id=ar.product_id LEFT JOIN users u ON u.id=arh.user_id
        WHERE ar.company_id=? AND ar.city_id=? $extra
        ORDER BY arh.created_at DESC LIMIT 300");
    $st->execute($params);
    $global_history = $st->fetchAll(PDO::FETCH_ASSOC);
}

/* ─ Mark notifications read when viewing tab ─ */
if ($view === 'notifications' && $location_set) {
    $pdo->prepare("UPDATE appro_notifications SET is_read=1 WHERE company_id=? AND city_id=?")
        ->execute([$company_id, $city_id]);
    $unread_count = 0;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Demandes Appro — ESPERANCE H2O</title>
<meta name="theme-color" content="#10b981">
<link rel="manifest" href="/stock/stock_manifest.json">
<link rel="icon" href="/stock/stock-app-icon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/stock/stock-app-icon-192.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=Source+Serif+4:wght@700;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
@font-face{font-family:'C059';src:local('C059-Bold'),local('C059 Bold'),local('Century Schoolbook');font-weight:700 900;font-style:normal;}
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
.wrap{position:relative;z-index:1;max-width:1480px;margin:0 auto;padding:16px 16px 48px;}

/* TOPBAR */
.topbar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;
    background:rgba(8,20,32,0.94);border:1px solid var(--bord);border-radius:18px;
    padding:18px 28px;margin-bottom:16px;backdrop-filter:blur(24px);}
.brand{display:flex;align-items:center;gap:16px;flex-shrink:0;}
.brand-ico{width:50px;height:50px;background:linear-gradient(135deg,var(--cyan),var(--blue));
    border-radius:14px;display:flex;align-items:center;justify-content:center;
    font-size:24px;color:#fff;box-shadow:0 0 26px rgba(6,182,212,0.5);animation:breathe 3.2s ease-in-out infinite;flex-shrink:0;}
@keyframes breathe{0%,100%{box-shadow:0 0 14px rgba(6,182,212,0.4);}50%{box-shadow:0 0 38px rgba(6,182,212,0.85);}}
.brand-txt h1{font-family:var(--fh);font-size:22px;font-weight:900;color:var(--text);letter-spacing:0.5px;line-height:1.2;}
.brand-txt p{font-size:11px;font-weight:700;color:var(--cyan);letter-spacing:2.8px;text-transform:uppercase;margin-top:3px;}
.clock-d{font-family:var(--fh);font-size:30px;font-weight:900;color:var(--gold);letter-spacing:5px;text-shadow:0 0 22px rgba(255,208,96,0.55);line-height:1;}
.clock-sub{font-size:11px;color:var(--muted);letter-spacing:1.5px;text-transform:uppercase;margin-top:5px;}
.user-badge{display:flex;align-items:center;gap:10px;background:linear-gradient(135deg,var(--cyan),var(--blue));
    color:#fff;padding:11px 22px;border-radius:32px;font-family:var(--fh);font-size:14px;font-weight:900;
    box-shadow:0 0 20px rgba(6,182,212,0.4);flex-shrink:0;}

/* NAV */
.nav-bar{display:flex;align-items:center;flex-wrap:wrap;gap:8px;
    background:rgba(8,20,32,0.90);border:1px solid var(--bord);border-radius:16px;
    padding:14px 22px;margin-bottom:18px;backdrop-filter:blur(20px);}
.nb{display:flex;align-items:center;gap:8px;padding:10px 18px;border-radius:12px;
    border:1.5px solid var(--bord);background:rgba(6,182,212,0.07);color:var(--text2);
    font-family:var(--fh);font-size:13px;font-weight:900;text-decoration:none;white-space:nowrap;
    letter-spacing:0.4px;transition:all 0.28s cubic-bezier(0.23,1,0.32,1);}
.nb:hover{background:var(--cyan);color:var(--bg);border-color:var(--cyan);transform:translateY(-2px);}
.nb.active{background:var(--cyan);color:var(--bg);border-color:var(--cyan);}
.nb.green{border-color:rgba(50,190,143,0.3);color:var(--neon);}
.nb.green:hover,.nb.green.active{background:var(--neon);color:var(--bg);border-color:var(--neon);box-shadow:var(--glow);}
.nb.gold{border-color:rgba(255,208,96,0.3);color:var(--gold);}
.nb.gold:hover,.nb.gold.active{background:var(--gold);color:var(--bg);border-color:var(--gold);}
.nb.red{border-color:rgba(255,53,83,0.3);color:var(--red);}
.nb.red:hover{background:var(--red);color:#fff;border-color:var(--red);}
.nb.purple-nb{border-color:rgba(168,85,247,0.3);color:var(--purple);}
.nb.purple-nb:hover,.nb.purple-nb.active{background:var(--purple);color:#fff;border-color:var(--purple);}
.nb.orange-nb{border-color:rgba(255,145,64,0.3);color:var(--orange);}
.nb.orange-nb:hover,.nb.orange-nb.active{background:var(--orange);color:#fff;border-color:var(--orange);}

/* ALERT */
.alert{display:flex;align-items:center;gap:16px;flex-wrap:wrap;border-radius:14px;padding:16px 22px;margin-bottom:18px;}
.alert.success{background:rgba(50,190,143,0.08);border:1px solid rgba(50,190,143,0.25);}
.alert.error  {background:rgba(255,53,83,0.08); border:1px solid rgba(255,53,83,0.25);}
.alert i{font-size:22px;flex-shrink:0;}
.alert.success i{color:var(--neon);}
.alert.error i{color:var(--red);}
.alert span{font-family:var(--fb);font-size:14px;font-weight:700;line-height:1.6;}
.alert.success span{color:var(--neon);}
.alert.error span{color:var(--red);}

/* KPI */
.kpi-strip{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px;}
.ks{background:var(--card);border:1px solid var(--bord);border-radius:16px;padding:20px 18px;
    display:flex;align-items:center;gap:14px;transition:all 0.3s;}
.ks:hover{transform:translateY(-4px);box-shadow:0 14px 32px rgba(0,0,0,0.38);}
.ks-ico{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;}
.ks-val{font-family:var(--fh);font-size:26px;font-weight:900;color:var(--text);line-height:1;}
.ks-lbl{font-family:var(--fb);font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-top:4px;}

/* PANEL */
.panel{background:var(--card);border:1px solid var(--bord);border-radius:18px;overflow:hidden;margin-bottom:18px;transition:border-color 0.3s;}
.panel:hover{border-color:rgba(6,182,212,0.26);}
.ph{display:flex;align-items:center;justify-content:space-between;gap:12px;
    padding:18px 22px;border-bottom:1px solid rgba(255,255,255,0.05);background:rgba(0,0,0,0.18);}
.ph-title{font-family:var(--fh);font-size:15px;font-weight:900;color:var(--text);
    display:flex;align-items:center;gap:10px;letter-spacing:0.4px;flex-wrap:wrap;}
.dot{width:9px;height:9px;border-radius:50%;background:var(--neon);box-shadow:0 0 9px var(--neon);flex-shrink:0;animation:pdot 2.2s infinite;}
@keyframes pdot{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(.75)}}
.dot.c{background:var(--cyan);box-shadow:0 0 9px var(--cyan);}
.dot.r{background:var(--red);box-shadow:0 0 9px var(--red);}
.dot.g{background:var(--gold);box-shadow:0 0 9px var(--gold);}
.dot.o{background:var(--orange);box-shadow:0 0 9px var(--orange);}
.dot.b{background:var(--blue);box-shadow:0 0 9px var(--blue);}
.dot.p{background:var(--purple);box-shadow:0 0 9px var(--purple);}
.pbadge{font-family:var(--fb);font-size:11px;font-weight:800;padding:5px 14px;border-radius:20px;white-space:nowrap;}
.pbadge.c{background:rgba(6,182,212,0.12);color:var(--cyan);}
.pbadge.r{background:rgba(255,53,83,0.12);color:var(--red);}
.pbadge.g{background:rgba(255,208,96,0.12);color:var(--gold);}
.pbadge.gr{background:rgba(50,190,143,0.12);color:var(--neon);}
.pbadge.p{background:rgba(168,85,247,0.12);color:var(--purple);}
.pbadge.o{background:rgba(255,145,64,0.12);color:var(--orange);}
.pb{padding:20px 22px;}

/* FORM */
.f-select,.f-input,.f-textarea{width:100%;padding:12px 16px;background:rgba(0,0,0,0.3);
    border:1.5px solid var(--bord);border-radius:12px;color:var(--text);
    font-family:var(--fb);font-size:14px;font-weight:600;margin-bottom:12px;
    transition:all 0.3s;appearance:none;-webkit-appearance:none;}
.f-select:focus,.f-input:focus,.f-textarea:focus{outline:none;border-color:var(--cyan);box-shadow:0 0 18px rgba(6,182,212,0.25);background:rgba(6,182,212,0.04);}
.f-select option{background:#0d1e2c;color:var(--text);}
.f-textarea{resize:vertical;min-height:80px;}
.f-label{font-family:var(--fh);font-size:12px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:1.2px;display:block;margin-bottom:7px;}
.f-row{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.f-row3{display:grid;grid-template-columns:2fr 1fr 1fr;gap:16px;}
.f-row4{display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:12px;}

/* BTN */
.btn{display:inline-flex;align-items:center;gap:8px;padding:12px 22px;border-radius:12px;
    border:none;cursor:pointer;font-family:var(--fh);font-size:13px;font-weight:900;
    letter-spacing:0.4px;transition:all 0.28s;text-decoration:none;white-space:nowrap;}
.btn-cyan{background:rgba(6,182,212,0.12);border:1.5px solid rgba(6,182,212,0.3);color:var(--cyan);}
.btn-cyan:hover{background:var(--cyan);color:var(--bg);box-shadow:0 0 20px rgba(6,182,212,0.4);}
.btn-neon{background:rgba(50,190,143,0.12);border:1.5px solid rgba(50,190,143,0.3);color:var(--neon);}
.btn-neon:hover{background:var(--neon);color:var(--bg);box-shadow:var(--glow);}
.btn-gold{background:rgba(255,208,96,0.12);border:1.5px solid rgba(255,208,96,0.3);color:var(--gold);}
.btn-gold:hover{background:var(--gold);color:var(--bg);box-shadow:var(--glow-gold);}
.btn-red{background:rgba(255,53,83,0.12);border:1.5px solid rgba(255,53,83,0.3);color:var(--red);}
.btn-red:hover{background:var(--red);color:#fff;box-shadow:var(--glow-r);}
.btn-blue{background:rgba(61,140,255,0.12);border:1.5px solid rgba(61,140,255,0.3);color:var(--blue);}
.btn-blue:hover{background:var(--blue);color:#fff;}
.btn-purple{background:rgba(168,85,247,0.12);border:1.5px solid rgba(168,85,247,0.3);color:var(--purple);}
.btn-purple:hover{background:var(--purple);color:#fff;}
.btn-orange{background:rgba(255,145,64,0.12);border:1.5px solid rgba(255,145,64,0.3);color:var(--orange);}
.btn-orange:hover{background:var(--orange);color:#fff;}
.btn-sm{padding:8px 14px;font-size:12px;border-radius:9px;}
.btn-xs{padding:5px 10px;font-size:11px;border-radius:7px;}
.btn-full{width:100%;justify-content:center;padding:15px;font-size:15px;border-radius:14px;}

/* TABLE */
.tbl{width:100%;border-collapse:collapse;}
.tbl th{font-family:var(--fh);font-size:12px;font-weight:900;color:var(--muted);
    text-transform:uppercase;letter-spacing:1.2px;padding:12px 14px;
    border-bottom:1px solid rgba(255,255,255,0.06);text-align:left;background:rgba(0,0,0,0.15);white-space:nowrap;}
.tbl td{font-family:var(--fb);font-size:13px;font-weight:500;color:var(--text2);
    padding:13px 14px;border-bottom:1px solid rgba(255,255,255,0.04);line-height:1.55;vertical-align:middle;}
.tbl tr:last-child td{border-bottom:none;}
.tbl tbody tr{transition:all 0.25s;}
.tbl tbody tr:hover{background:rgba(6,182,212,0.04);}
.tbl td strong{font-family:var(--fh);font-weight:900;color:var(--text);}

/* STATUS */
.status-badge{display:inline-flex;align-items:center;gap:6px;font-family:var(--fb);font-size:11px;font-weight:800;
    padding:5px 13px;border-radius:20px;letter-spacing:0.5px;white-space:nowrap;}
.s-attente  {background:rgba(255,208,96,0.16);color:var(--gold);border:1px solid rgba(255,208,96,0.3);}
.s-confirmee{background:rgba(50,190,143,0.16);color:var(--neon);border:1px solid rgba(50,190,143,0.3);}
.s-rejetee  {background:rgba(255,53,83,0.16);color:var(--red);border:1px solid rgba(255,53,83,0.3);}
.s-annulee  {background:rgba(90,128,112,0.16);color:var(--muted);border:1px solid rgba(90,128,112,0.3);}
.s-auto     {background:rgba(168,85,247,0.16);color:var(--purple);border:1px solid rgba(168,85,247,0.3);}

/* UNIT */
.unit-detail{background:rgba(6,182,212,0.12);color:var(--cyan);border:1px solid rgba(6,182,212,0.25);
    font-size:11px;font-weight:800;padding:4px 11px;border-radius:20px;display:inline-flex;align-items:center;gap:5px;}
.unit-carton{background:rgba(255,145,64,0.12);color:var(--orange);border:1px solid rgba(255,145,64,0.25);
    font-size:11px;font-weight:800;padding:4px 11px;border-radius:20px;display:inline-flex;align-items:center;gap:5px;}

/* UNIT TOGGLE */
.unit-toggle{display:flex;gap:10px;margin-bottom:12px;}
.unit-opt{flex:1;position:relative;}
.unit-opt input[type=radio]{position:absolute;opacity:0;width:0;height:0;}
.unit-opt label{display:flex;flex-direction:column;align-items:center;gap:6px;padding:16px 12px;
    border:2px solid var(--bord);border-radius:14px;cursor:pointer;transition:all 0.28s;background:rgba(0,0,0,0.2);}
.unit-opt label i{font-size:22px;color:var(--muted);}
.unit-opt label span{font-family:var(--fh);font-size:13px;font-weight:900;color:var(--muted);letter-spacing:0.4px;}
.unit-opt label small{font-family:var(--fb);font-size:11px;color:var(--muted);opacity:.7;text-align:center;line-height:1.3;}
.unit-opt input[type=radio]:checked + label{border-color:var(--cyan);background:rgba(6,182,212,0.08);box-shadow:0 0 18px rgba(6,182,212,0.2);}
.unit-opt input[type=radio]:checked + label i,.unit-opt input[type=radio]:checked + label span{color:var(--cyan);}

/* MODAL */
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.75);z-index:1000;
    align-items:center;justify-content:center;backdrop-filter:blur(4px);}
.modal.show{display:flex;}
.modal-box{background:var(--card);border:1px solid var(--bord);border-radius:20px;
    padding:30px;max-width:580px;width:92%;max-height:88vh;overflow-y:auto;animation:mzoom .25s ease;}
@keyframes mzoom{from{opacity:0;transform:scale(0.9)}to{opacity:1;transform:scale(1)}}
.modal-box h2{font-family:var(--fh);font-size:20px;font-weight:900;color:var(--text);margin-bottom:22px;line-height:1.3;}
.modal-box h2.danger{color:var(--red);}
.modal-box h2.confirm{color:var(--neon);}
.modal-box h2.gold{color:var(--gold);}
.modal-btns{display:flex;gap:12px;margin-top:20px;flex-wrap:wrap;}
.modal-btns>*{flex:1;justify-content:center;}

/* TIMELINE */
.timeline{padding:10px 0;}
.tl-item{display:flex;gap:18px;margin-bottom:0;position:relative;}
.tl-item::before{content:'';position:absolute;left:20px;top:48px;bottom:0;width:2px;
    background:linear-gradient(to bottom,var(--bord),transparent);}
.tl-item:last-child::before{display:none;}
.tl-icon{width:42px;height:42px;border-radius:50%;display:flex;align-items:center;justify-content:center;
    font-size:16px;flex-shrink:0;margin-top:4px;border:2px solid;}
.tl-content{flex:1;padding-bottom:24px;}
.tl-header{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:6px;}
.tl-action{font-family:var(--fh);font-size:14px;font-weight:900;color:var(--text);}
.tl-time{font-family:var(--fb);font-size:11px;color:var(--muted);white-space:nowrap;flex-shrink:0;}
.tl-user{font-family:var(--fb);font-size:12px;font-weight:700;color:var(--cyan);margin-bottom:4px;}
.tl-details{font-family:var(--fb);font-size:12px;color:var(--text2);background:rgba(0,0,0,0.2);
    border:1px solid var(--bord);border-radius:10px;padding:10px 14px;line-height:1.65;}

/* LOC BOX */
.loc-box{background:var(--card);border:1px solid var(--bord);border-radius:18px;padding:40px;text-align:center;}
.loc-box h2{font-family:var(--fh);font-size:22px;font-weight:900;color:var(--text);margin-bottom:8px;}
.loc-box p{font-family:var(--fb);font-size:14px;color:var(--muted);margin-bottom:28px;}

/* EMPTY STATE */
.empty-state{text-align:center;padding:48px 24px;color:var(--muted);}
.empty-state i{font-size:52px;display:block;margin-bottom:16px;opacity:.15;}
.empty-state h3{font-family:var(--fh);font-size:18px;font-weight:900;margin-bottom:8px;color:var(--text2);}
.empty-state p{font-family:var(--fb);font-size:14px;opacity:.6;}

/* ADMIN BANNER */
.admin-banner{background:linear-gradient(135deg,rgba(50,190,143,0.08),rgba(6,182,212,0.08));
    border:1px solid rgba(50,190,143,0.2);border-radius:14px;padding:14px 20px;margin-bottom:18px;
    display:flex;align-items:center;gap:14px;flex-wrap:wrap;}
.admin-banner i{font-size:22px;color:var(--neon);flex-shrink:0;}
.admin-banner span{font-family:var(--fh);font-size:13px;font-weight:900;color:var(--neon);}
.admin-banner small{font-family:var(--fb);font-size:12px;color:var(--muted);}

/* BADGES NAV */
.pending-badge{display:inline-flex;align-items:center;justify-content:center;
    width:22px;height:22px;background:var(--red);color:#fff;border-radius:50%;
    font-size:11px;font-weight:900;margin-left:4px;animation:pulse-r 1.5s ease infinite;}
.notif-badge{display:inline-flex;align-items:center;justify-content:center;
    width:22px;height:22px;background:var(--purple);color:#fff;border-radius:50%;
    font-size:11px;font-weight:900;margin-left:4px;animation:pulse-p 1.5s ease infinite;}
@keyframes pulse-r{0%,100%{box-shadow:0 0 0 0 rgba(255,53,83,0.4);}50%{box-shadow:0 0 0 6px transparent;}}
@keyframes pulse-p{0%,100%{box-shadow:0 0 0 0 rgba(168,85,247,0.4);}50%{box-shadow:0 0 0 6px transparent;}}

/* ══ COUNTDOWN TIMER ══ */
.countdown-cell{font-family:var(--fh);font-weight:900;font-size:13px;white-space:nowrap;}
.ct-urgent{color:var(--red);animation:blink-r 1s ease infinite;}
.ct-warn  {color:var(--orange);}
.ct-safe  {color:var(--gold);}
.ct-done  {color:var(--purple);}
@keyframes blink-r{0%,100%{opacity:1}50%{opacity:0.4}}
.ct-bar{width:60px;height:5px;background:rgba(255,255,255,0.1);border-radius:3px;margin-top:4px;overflow:hidden;}
.ct-bar-fill{height:100%;border-radius:3px;transition:width 1s linear;}

/* ══ AUTO-CONFIRM TOAST ══ */
.toast-stack{position:fixed;bottom:24px;right:24px;z-index:9999;display:flex;flex-direction:column;gap:10px;align-items:flex-end;}
.toast{background:var(--card2);border:1px solid rgba(168,85,247,0.3);border-radius:14px;
    padding:14px 20px;min-width:280px;max-width:380px;
    display:flex;align-items:center;gap:14px;
    box-shadow:0 8px 32px rgba(0,0,0,0.5);
    animation:toast-in 0.4s cubic-bezier(0.23,1,0.32,1) forwards;}
.toast.removing{animation:toast-out 0.4s ease forwards;}
@keyframes toast-in{from{opacity:0;transform:translateX(60px)}to{opacity:1;transform:translateX(0)}}
@keyframes toast-out{from{opacity:1;transform:translateX(0)}to{opacity:0;transform:translateX(60px)}}
.toast-ico{width:36px;height:36px;border-radius:10px;background:rgba(168,85,247,0.15);
    display:flex;align-items:center;justify-content:center;color:var(--purple);font-size:18px;flex-shrink:0;}
.toast-txt{flex:1;}
.toast-txt strong{font-family:var(--fh);font-size:13px;font-weight:900;color:var(--purple);display:block;}
.toast-txt span{font-family:var(--fb);font-size:12px;color:var(--muted);line-height:1.5;}

/* NOTIF CARD */
.notif-card{display:flex;align-items:flex-start;gap:14px;padding:16px 18px;
    border-radius:14px;border:1px solid;margin-bottom:10px;transition:all 0.3s;}
.notif-card.unread{border-color:rgba(168,85,247,0.25);background:rgba(168,85,247,0.05);}
.notif-card.read  {border-color:var(--bord);background:rgba(0,0,0,0.1);opacity:.75;}
.notif-ico{width:40px;height:40px;border-radius:11px;display:flex;align-items:center;justify-content:center;
    font-size:18px;flex-shrink:0;}
.notif-title{font-family:var(--fh);font-size:14px;font-weight:900;color:var(--text);line-height:1.3;margin-bottom:4px;}
.notif-meta{font-family:var(--fb);font-size:12px;color:var(--muted);display:flex;gap:12px;flex-wrap:wrap;}
.notif-dot{width:8px;height:8px;border-radius:50%;background:var(--purple);box-shadow:0 0 8px var(--purple);flex-shrink:0;margin-top:5px;}

/* EXPORT BAR */
.export-bar{background:rgba(8,20,32,0.9);border:1px solid var(--bord);border-radius:14px;
    padding:14px 20px;margin-bottom:16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
.export-bar label{font-family:var(--fh);font-size:12px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:1px;}
.export-bar .f-select,.export-bar .f-input{margin-bottom:0;width:auto;flex:1;min-width:130px;}

/* ROW FLASH ANIMATION */
@keyframes row-flash-confirm{
    0%  {background:rgba(168,85,247,0.25);}
    100%{background:transparent;}
}
.row-auto-confirmed{animation:row-flash-confirm 3s ease forwards;}

.panel{animation:fadeUp .45s ease backwards;}
@keyframes fadeUp{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:translateY(0)}}
@media(max-width:1100px){.f-row3{grid-template-columns:1fr 1fr;}.kpi-strip{grid-template-columns:repeat(2,1fr);}.f-row4{grid-template-columns:1fr 1fr;}}
@media(max-width:720px){.wrap{padding:12px;}.f-row{grid-template-columns:1fr;}.kpi-strip{grid-template-columns:repeat(2,1fr);gap:10px;}}
.stock-install-fab,.stock-network-badge{position:fixed;z-index:20000;border:none;border-radius:999px;padding:12px 16px;font-size:13px;font-weight:800;display:flex;align-items:center;gap:8px;box-shadow:0 18px 38px rgba(0,0,0,.24)}
.stock-install-fab{right:16px;bottom:18px;background:linear-gradient(135deg,#10b981,#0ea5e9);color:#fff;cursor:pointer}
.stock-network-badge{left:50%;transform:translateX(-50%);bottom:18px;background:rgba(255,53,83,.96);color:#fff;display:none}
.stock-network-badge.show{display:flex}
img,canvas,iframe,svg{max-width:100%;height:auto}
body{overflow-x:hidden}
@media(max-width:768px){.nav-bar{overflow-x:auto;white-space:nowrap;-webkit-overflow-scrolling:touch;padding-bottom:6px}.cards-grid,.stats-grid,.grid-2{grid-template-columns:1fr !important}.user-badge{width:100%;justify-content:center}}
</style>
<link rel="stylesheet" href="/stock/stock_mobile_overrides.css">
</head>
<body>
<button type="button" class="stock-install-fab" id="stockInstallBtn"><i class="fas fa-download"></i> Installer Stock</button>
<div class="stock-network-badge" id="stockNetworkBadge"><i class="fas fa-wifi"></i> Hors ligne</div>
<div class="wrap">

<!-- TOPBAR -->
<div class="topbar">
    <div class="brand">
        <div class="brand-ico"><i class="fas fa-truck-loading"></i></div>
        <div class="brand-txt">
            <h1>Demandes d'Appro</h1>
            <p>ESPERANCE H2O &nbsp;·&nbsp; Approvisionnement</p>
        </div>
    </div>
    <div style="text-align:center;flex-shrink:0">
        <div class="clock-d" id="clk">--:--:--</div>
        <div class="clock-sub" id="clkd">Chargement…</div>
    </div>
    <div class="user-badge">
        <i class="fas <?= $is_admin ? 'fa-user-shield' : 'fa-user' ?>"></i>
        <?= htmlspecialchars($user_name) ?>
        <?php if($is_admin): ?>
        &nbsp;<span style="font-size:10px;background:rgba(255,255,255,0.15);padding:2px 8px;border-radius:10px">ADMIN</span>
        <?php endif; ?>
    </div>
</div>

<!-- NAV -->
<div class="nav-bar">
    <a href="?view=list" class="nb <?= $view==='list'?'active':'' ?>">
        <i class="fas fa-list"></i> Demandes
        <?php if($stats['en_attente'] > 0 && $is_admin): ?>
        <span class="pending-badge" id="nav-pending-badge"><?= $stats['en_attente'] ?></span>
        <?php endif; ?>
    </a>
    <?php if(!$is_admin): ?>
    <a href="?view=new" class="nb green <?= $view==='new'?'active':'' ?>"><i class="fas fa-plus-circle"></i> Nouvelle demande</a>
    <?php endif; ?>
    <!-- 🔔 NOTIFICATIONS TAB -->
    <a href="?view=notifications" class="nb purple-nb <?= $view==='notifications'?'active':'' ?>" id="nav-notif-link">
        <i class="fas fa-bell"></i> Notifications
        <?php if($unread_count > 0): ?>
        <span class="notif-badge" id="nav-notif-badge"><?= $unread_count ?></span>
        <?php endif; ?>
    </a>
    <a href="?view=full_history" class="nb gold <?= $view==='full_history'?'active':'' ?>"><i class="fas fa-history"></i> Historique</a>
    <a href="<?= project_url('finance/caisse_complete_enhanced.php') ?>" class="nb"><i class="fas fa-cash-register"></i> Caisse</a>
    <a href="<?= project_url('stock/stock_update_fixed.php') ?>" class="nb"><i class="fas fa-warehouse"></i> Appro direct</a>
    <a href="<?= project_url('dashboard/index.php') ?>" class="nb red"><i class="fas fa-home"></i> Accueil</a>
</div>

<!-- ALERTES -->
<?php if($error_message): ?>
<div class="alert error"><i class="fas fa-exclamation-triangle"></i><span><?= htmlspecialchars($error_message) ?></span></div>
<?php endif; ?>
<?php if($success_message): ?>
<div class="alert success"><i class="fas fa-check-circle"></i><span><?= htmlspecialchars($success_message) ?></span></div>
<?php endif; ?>

<?php if(!$location_set): ?>
<!-- SÉLECTION LOC -->
<div class="loc-box">
    <h2><i class="fas fa-map-marker-alt" style="color:var(--cyan)"></i> &nbsp;Sélectionnez votre localisation</h2>
    <p>Choisissez votre société et votre magasin</p>
    <form method="get" style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap">
        <select name="company_id" class="f-select" style="max-width:250px" required onchange="this.form.submit()">
            <option value="">— Société —</option>
            <?php foreach($companies as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $company_id==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="city_id" class="f-select" style="max-width:250px" required>
            <option value="">— Magasin —</option>
            <?php foreach($cities as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $city_id==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" name="confirm_location" class="btn btn-cyan" style="height:fit-content">
            <i class="fas fa-check"></i> Valider
        </button>
    </form>
</div>

<?php else: ?>

<!-- ADMIN BANNER -->
<?php if($is_admin && $stats['en_attente'] > 0): ?>
<div class="admin-banner">
    <i class="fas fa-bell"></i>
    <div>
        <span id="banner-pending-txt"><?= $stats['en_attente'] ?> demande(s) en attente de validation !</span><br>
        <small>⚙️ Auto-confirmation automatique 2 minutes après soumission si aucune action. Confirmez manuellement pour garder le contrôle.</small>
    </div>
    <span style="font-family:var(--fb);font-size:11px;background:rgba(255,208,96,0.12);color:var(--gold);padding:5px 12px;border-radius:20px;margin-left:auto">
        <i class="fas fa-robot"></i> Timer: 2min
    </span>
</div>
<?php endif; ?>

<!-- KPI -->
<div class="kpi-strip">
    <div class="ks">
        <div class="ks-ico" style="background:rgba(255,208,96,0.14);color:var(--gold)"><i class="fas fa-clock"></i></div>
        <div><div class="ks-val" style="color:var(--gold)" id="kpi-pending"><?= $stats['en_attente'] ?></div><div class="ks-lbl">En attente</div></div>
    </div>
    <div class="ks">
        <div class="ks-ico" style="background:rgba(50,190,143,0.14);color:var(--neon)"><i class="fas fa-check-circle"></i></div>
        <div><div class="ks-val" style="color:var(--neon)"><?= $stats['confirmee'] ?></div><div class="ks-lbl">Confirmées</div></div>
    </div>
    <div class="ks">
        <div class="ks-ico" style="background:rgba(168,85,247,0.14);color:var(--purple)"><i class="fas fa-robot"></i></div>
        <div><div class="ks-val" style="color:var(--purple)" id="kpi-auto"><?= count($notifications) ?></div><div class="ks-lbl">Auto-confirmées</div></div>
    </div>
    <div class="ks">
        <div class="ks-ico" style="background:rgba(255,53,83,0.14);color:var(--red)"><i class="fas fa-times-circle"></i></div>
        <div><div class="ks-val" style="color:var(--red)"><?= $stats['rejetee'] ?></div><div class="ks-lbl">Rejetées</div></div>
    </div>
</div>

<?php if($view === 'new' && !$is_admin): ?>
<!-- ══════ VUE: NOUVELLE DEMANDE ══════ -->
<div class="panel">
    <div class="ph">
        <div class="ph-title"><div class="dot c"></div> Nouvelle Demande d'Approvisionnement</div>
        <div style="background:rgba(168,85,247,0.1);border:1px solid rgba(168,85,247,0.25);border-radius:10px;padding:8px 14px;font-size:12px;color:var(--purple);font-family:var(--fh);font-weight:900">
            <i class="fas fa-robot"></i> Auto-confirm si pas d'action admin dans 2min
        </div>
    </div>
    <div class="pb">
        <form method="post">
            <div class="f-row3">
                <div>
                    <label class="f-label"><i class="fas fa-box"></i> Produit</label>
                    <select name="product_id" class="f-select" required id="prod-select" onchange="updateStock(this)">
                        <option value="">— Sélectionner un produit —</option>
                        <?php foreach($products as $p): ?>
                        <option value="<?= $p['id'] ?>" data-stock="<?= (int)$p['stock_actuel'] ?>">
                            <?= htmlspecialchars($p['name']) ?> (Stock: <?= (int)$p['stock_actuel'] ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="f-label"><i class="fas fa-hashtag"></i> Quantité</label>
                    <input type="number" name="quantity" class="f-input" min="1" step="1" placeholder="Ex: 10" required>
                </div>
                <div>
                    <label class="f-label"><i class="fas fa-info-circle"></i> Stock actuel</label>
                    <div id="stock-info" style="padding:12px 16px;background:rgba(0,0,0,0.3);border:1.5px solid var(--bord);border-radius:12px;
                        font-family:var(--fh);font-size:18px;font-weight:900;color:var(--muted);text-align:center;margin-bottom:12px">—</div>
                </div>
            </div>
            <label class="f-label"><i class="fas fa-boxes"></i> Type d'unité</label>
            <div class="unit-toggle">
                <div class="unit-opt"><input type="radio" name="unit_type" id="u-detail" value="detail" checked>
                    <label for="u-detail"><i class="fas fa-cube"></i><span>Côté Détail</span><small>Unités individuelles</small></label></div>
                <div class="unit-opt"><input type="radio" name="unit_type" id="u-carton" value="carton">
                    <label for="u-carton"><i class="fas fa-box-open"></i><span>Côté Carton</span><small>Par carton / lot</small></label></div>
            </div>
            <label class="f-label"><i class="fas fa-comment-alt"></i> Note / Urgence</label>
            <textarea name="note" class="f-textarea" placeholder="Ex: Stock critique, besoin urgent…"></textarea>
            <div style="display:flex;gap:12px;flex-wrap:wrap">
                <button type="submit" name="submit_request" class="btn btn-cyan btn-full" style="flex:2">
                    <i class="fas fa-paper-plane"></i> Envoyer (auto-confirm dans 2min si admin inactif)
                </button>
                <a href="?view=list" class="btn btn-red" style="flex:1;justify-content:center">
                    <i class="fas fa-times"></i> Annuler
                </a>
            </div>
        </form>
    </div>
</div>

<?php elseif($view === 'notifications'): ?>
<!-- ══════ VUE: NOTIFICATIONS ══════ -->
<div class="panel">
    <div class="ph">
        <div class="ph-title"><div class="dot p"></div> <i class="fas fa-robot" style="color:var(--purple)"></i> Notifications — Auto-confirmations & Alertes Système</div>
        <div style="display:flex;gap:10px">
            <span class="pbadge p"><?= count($notifications) ?> entrée(s)</span>
            <?php if($unread_count > 0): ?>
            <span class="pbadge r"><?= $unread_count ?> non lue(s)</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="pb">
        <?php if(empty($notifications)): ?>
        <div class="empty-state">
            <i class="fas fa-bell-slash"></i>
            <h3>Aucune notification</h3>
            <p>Les auto-confirmations et alertes système apparaîtront ici.</p>
        </div>
        <?php else: ?>
        <!-- Filtre catégorie -->
        <div style="display:flex;gap:10px;margin-bottom:18px;flex-wrap:wrap;align-items:center">
            <span style="font-family:var(--fh);font-size:12px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:1px">Filtrer :</span>
            <button onclick="filterNotif('')" class="btn btn-purple btn-sm" id="fn-all">
                <i class="fas fa-globe"></i> Toutes
            </button>
            <button onclick="filterNotif('auto_confirmee')" class="btn btn-sm" style="background:rgba(168,85,247,0.1);border:1.5px solid rgba(168,85,247,0.3);color:var(--purple)" id="fn-auto">
                <i class="fas fa-robot"></i> Auto-confirmées
            </button>
            <?php foreach(array_unique(array_column($notifications,'category')) as $nc): ?>
            <button onclick="filterNotif('', '<?= htmlspecialchars(addslashes($nc)) ?>')"
                class="btn btn-sm" style="background:rgba(6,182,212,0.07);border:1.5px solid var(--bord);color:var(--text2)">
                <i class="fas fa-tag"></i> <?= htmlspecialchars($nc) ?>
            </button>
            <?php endforeach; ?>
        </div>
        <div id="notif-list">
            <?php foreach($notifications as $n): ?>
            <div class="notif-card <?= $n['is_read'] ? 'read' : 'unread' ?>"
                 data-type="<?= $n['type'] ?>"
                 data-cat="<?= htmlspecialchars($n['category']) ?>">
                <?php if(!$n['is_read']): ?><div class="notif-dot" style="margin-top:10px;flex-shrink:0"></div><?php endif; ?>
                <div class="notif-ico" style="background:rgba(168,85,247,0.1);color:var(--purple)">
                    <i class="fas fa-robot"></i>
                </div>
                <div style="flex:1">
                    <div class="notif-title"><?= htmlspecialchars($n['message']) ?></div>
                    <div class="notif-meta">
                        <span><i class="fas fa-clock"></i> <?= date('d/m/Y H:i:s', strtotime($n['created_at'])) ?></span>
                        <span><i class="fas fa-tag"></i> <?= htmlspecialchars($n['category']) ?></span>
                        <span><i class="fas fa-hashtag"></i> Req #<?= $n['request_id'] ?></span>
                        <span><i class="fas fa-cubes"></i> <?= $n['quantity']+0 ?> <?= $n['unit_type'] ?></span>
                        <span class="status-badge s-auto" style="padding:2px 9px;font-size:10px">
                            <i class="fas fa-robot"></i> Auto-confirmé
                        </span>
                    </div>
                </div>
                <a href="?view=history&req_id=<?= $n['request_id'] ?>" class="btn btn-purple btn-xs" title="Voir historique">
                    <i class="fas fa-history"></i>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif($view === 'history' && $viewed_req): ?>
<!-- ══════ VUE: HISTORIQUE DEMANDE ══════ -->
<div class="panel">
    <div class="ph">
        <div class="ph-title">
            <div class="dot p"></div>
            Historique — Demande #<?= $viewed_req['id'] ?>
            <span style="font-family:var(--fb);font-size:12px;color:var(--muted);font-weight:500">
                <?= htmlspecialchars($viewed_req['product_name']) ?> · <?= $viewed_req['quantity'] ?> <?= $viewed_req['unit_type'] ?> · par <?= htmlspecialchars($viewed_req['requester_name']) ?>
            </span>
        </div>
        <a href="?view=list" class="btn btn-red btn-sm"><i class="fas fa-arrow-left"></i> Retour</a>
    </div>
    <div class="pb">
        <?php if(empty($req_history)): ?>
        <div class="empty-state"><i class="fas fa-history"></i><h3>Aucun historique</h3></div>
        <?php else: ?>
        <div class="timeline">
            <?php foreach($req_history as $h):
                $icons = [
                    'SOUMISSION'        => ['fas fa-paper-plane','var(--cyan)','rgba(6,182,212,0.15)'],
                    'MODIFICATION'      => ['fas fa-edit','var(--gold)','rgba(255,208,96,0.15)'],
                    'ANNULATION'        => ['fas fa-ban','var(--muted)','rgba(90,128,112,0.15)'],
                    'CONFIRMATION'      => ['fas fa-user-check','var(--neon)','rgba(50,190,143,0.15)'],
                    'AUTO_CONFIRMATION' => ['fas fa-robot','var(--purple)','rgba(168,85,247,0.15)'],
                    'REJET'             => ['fas fa-times-circle','var(--red)','rgba(255,53,83,0.15)'],
                ];
                $ic = $icons[$h['action']] ?? ['fas fa-circle','var(--blue)','rgba(61,140,255,0.15)'];
            ?>
            <div class="tl-item">
                <div class="tl-icon" style="background:<?= $ic[2] ?>;border-color:<?= $ic[1] ?>;color:<?= $ic[1] ?>">
                    <i class="<?= $ic[0] ?>"></i>
                </div>
                <div class="tl-content">
                    <div class="tl-header">
                        <span class="tl-action" style="color:<?= $ic[1] ?>"><?= htmlspecialchars($h['action']) ?></span>
                        <span class="tl-time"><?= date('d/m/Y H:i:s', strtotime($h['created_at'])) ?></span>
                    </div>
                    <div class="tl-user"><i class="fas fa-user-circle"></i>
                        <?= $h['action']==='AUTO_CONFIRMATION' ? '<span style="color:var(--purple)">⚙️ SYSTÈME</span>' : htmlspecialchars($h['user_name'] ?? 'Inconnu') ?>
                    </div>
                    <?php if($h['details']): ?>
                    <div class="tl-details"><?= nl2br(htmlspecialchars($h['details'])) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif($view === 'full_history'): ?>
<!-- ══════ VUE: HISTORIQUE GLOBAL ══════ -->
<!-- EXPORT BAR -->
<div class="export-bar">
    <span style="font-family:var(--fh);font-size:14px;font-weight:900;color:var(--neon)"><i class="fas fa-file-excel"></i> Exporter Excel</span>
    <select id="exp-cat" class="f-select" style="max-width:200px;margin-bottom:0">
        <option value="">Toutes catégories</option>
        <?php foreach($categories as $cat): ?>
        <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
        <?php endforeach; ?>
    </select>
    <select id="exp-stat" class="f-select" style="max-width:170px;margin-bottom:0">
        <option value="">Tous statuts</option>
        <option value="en_attente">En attente</option>
        <option value="confirmee">Confirmées</option>
        <option value="rejetee">Rejetées</option>
        <option value="annulee">Annulées</option>
    </select>
    <input type="date" id="exp-dfr" class="f-input" style="max-width:150px;margin-bottom:0" placeholder="Date début">
    <input type="date" id="exp-dto" class="f-input" style="max-width:150px;margin-bottom:0" placeholder="Date fin">
    <button onclick="doExport()" class="btn btn-neon" style="flex-shrink:0">
        <i class="fas fa-download"></i> Télécharger XLSX
    </button>
</div>

<!-- FILTRES HISTORIQUE -->
<form method="get" style="display:none" id="hist-filter-form">
    <input type="hidden" name="view" value="full_history">
</form>
<div style="display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap;align-items:center">
    <span style="font-family:var(--fh);font-size:12px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:1px">Filtres :</span>
    <?php foreach($categories as $cat): ?>
    <a href="?view=full_history&fcat=<?= urlencode($cat) ?><?= isset($_GET['fsta'])?'&fsta='.urlencode($_GET['fsta']):'' ?>"
       class="btn btn-sm <?= ($_GET['fcat']??'')===$cat?'btn-cyan':'btn-blue' ?>" style="font-size:11px;padding:5px 12px">
        <i class="fas fa-tag"></i> <?= htmlspecialchars($cat) ?>
    </a>
    <?php endforeach; ?>
    <?php if(!empty($_GET['fcat'])): ?>
    <a href="?view=full_history" class="btn btn-red btn-sm" style="font-size:11px;padding:5px 12px"><i class="fas fa-times"></i> Reset</a>
    <?php endif; ?>
</div>

<div class="panel">
    <div class="ph">
        <div class="ph-title"><div class="dot p"></div> Historique Global
            <?php if(!empty($_GET['fcat'])): ?>
            <span class="pbadge c">📦 <?= htmlspecialchars($_GET['fcat']) ?></span>
            <?php endif; ?>
        </div>
        <span class="pbadge p"><?= count($global_history) ?> entrée(s)</span>
    </div>
    <div class="pb">
        <?php if(empty($global_history)): ?>
        <div class="empty-state"><i class="fas fa-history"></i><h3>Aucun historique</h3><p>Aucune action enregistrée.</p></div>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table class="tbl">
            <thead>
                <tr>
                    <th>Date & Heure</th>
                    <th>Req #</th>
                    <th>Action</th>
                    <th>Utilisateur</th>
                    <th>Produit</th>
                    <th>Catégorie</th>
                    <th>Qté</th>
                    <th>Détails</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($global_history as $h):
                    $amap = [
                        'SOUMISSION'        => ['var(--cyan)',  'fas fa-paper-plane'],
                        'MODIFICATION'      => ['var(--gold)',  'fas fa-edit'],
                        'ANNULATION'        => ['var(--muted)', 'fas fa-ban'],
                        'CONFIRMATION'      => ['var(--neon)',  'fas fa-user-check'],
                        'AUTO_CONFIRMATION' => ['var(--purple)','fas fa-robot'],
                        'REJET'             => ['var(--red)',   'fas fa-times'],
                    ];
                    $am = $amap[$h['action']] ?? ['var(--blue)','fas fa-circle'];
                ?>
                <tr>
                    <td style="white-space:nowrap;color:var(--muted);font-size:12px">
                        <?= date('d/m/Y', strtotime($h['created_at'])) ?><br>
                        <strong style="color:var(--text);font-size:13px"><?= date('H:i:s', strtotime($h['created_at'])) ?></strong>
                    </td>
                    <td><strong style="color:var(--cyan)">#<?= $h['request_id'] ?></strong></td>
                    <td>
                        <span style="display:inline-flex;align-items:center;gap:6px;font-family:var(--fb);font-size:11px;font-weight:800;padding:5px 12px;border-radius:20px;
                            background:<?= 'rgba(0,0,0,0.2)' ?>;color:<?= $am[0] ?>;border:1px solid <?= $am[0] ?>">
                            <i class="<?= $am[1] ?>"></i> <?= htmlspecialchars($h['action']) ?>
                        </span>
                    </td>
                    <td>
                        <?php if($h['action']==='AUTO_CONFIRMATION'): ?>
                        <span style="color:var(--purple);font-weight:900"><i class="fas fa-robot"></i> SYSTÈME</span>
                        <?php else: ?>
                        <strong><?= htmlspecialchars($h['user_name'] ?? '?') ?></strong>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($h['product_name']) ?></td>
                    <td><span style="font-size:11px;padding:3px 10px;border-radius:15px;background:rgba(6,182,212,0.08);color:var(--cyan);font-weight:700">
                        <?= htmlspecialchars($h['product_category']) ?></span></td>
                    <td>
                        <span class="<?= $h['unit_type']==='carton'?'unit-carton':'unit-detail' ?>">
                            <i class="fas <?= $h['unit_type']==='carton'?'fa-box-open':'fa-cube' ?>"></i>
                            <?= $h['quantity']+0 ?> <?= $h['unit_type'] ?>
                        </span>
                    </td>
                    <td style="max-width:300px;font-size:12px;color:var(--muted);line-height:1.55">
                        <?= htmlspecialchars(mb_strimwidth($h['details'], 0, 120, '…')) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php else: ?>
<!-- ══════ VUE: LISTE DES DEMANDES ══════ -->

<!-- EXPORT BAR (sur la liste aussi) -->
<div class="export-bar">
    <span style="font-family:var(--fh);font-size:13px;font-weight:900;color:var(--neon)"><i class="fas fa-file-excel"></i> Export</span>
    <select id="exp-cat2" class="f-select" style="max-width:180px;margin-bottom:0">
        <option value="">Toutes catégories</option>
        <?php foreach($categories as $cat): ?>
        <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
        <?php endforeach; ?>
    </select>
    <select id="exp-stat2" class="f-select" style="max-width:160px;margin-bottom:0">
        <option value="">Tous statuts</option>
        <option value="en_attente">En attente</option>
        <option value="confirmee">Confirmées</option>
        <option value="rejetee">Rejetées</option>
        <option value="annulee">Annulées</option>
    </select>
    <button onclick="doExport2()" class="btn btn-neon btn-sm" style="flex-shrink:0">
        <i class="fas fa-download"></i> Export XLSX complet
    </button>
    <?php if(!$is_admin): ?>
    <a href="?view=new" class="btn btn-cyan btn-sm" style="margin-left:auto;flex-shrink:0"><i class="fas fa-plus-circle"></i> Nouvelle demande</a>
    <?php endif; ?>
</div>

<div class="panel">
    <div class="ph">
        <div class="ph-title">
            <div class="dot c"></div>
            <?= $is_admin ? 'Toutes les demandes' : 'Mes demandes' ?>
        </div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <span class="pbadge c"><?= count($requests) ?> demande(s)</span>
            <?php if($stats['en_attente'] > 0 && $is_admin): ?>
            <span class="pbadge r"><i class="fas fa-exclamation-circle"></i> <?= $stats['en_attente'] ?> en attente</span>
            <?php endif; ?>
            <?php if($stats['en_attente'] > 0): ?>
            <span class="pbadge p"><i class="fas fa-robot"></i> Auto-confirm 2min</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="pb">
        <?php if(empty($requests)): ?>
        <div class="empty-state">
            <i class="fas fa-truck-loading"></i>
            <h3>Aucune demande</h3>
            <p><?= $is_admin ? 'Aucune demande pour ce magasin.' : 'Vous n\'avez pas encore soumis de demande.' ?></p>
            <?php if(!$is_admin): ?>
            <a href="?view=new" class="btn btn-cyan" style="margin-top:16px;display:inline-flex"><i class="fas fa-plus-circle"></i> Créer une demande</a>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table class="tbl">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Produit</th>
                    <th>Qté / Unité</th>
                    <th>Demandé par</th>
                    <th>Date</th>
                    <th>⏱ Countdown</th>
                    <th>Statut</th>
                    <th>Note admin</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="requests-tbody">
                <?php foreach($requests as $r):
                    $age_sec = (int)($r['age_seconds'] ?? 0);
                    $remaining = max(0, 120 - $age_sec); // 120s = 2min
                    $pct_used = min(100, ($age_sec / 120) * 100);
                    $is_pending = ($r['status'] === 'en_attente');
                ?>
                <tr id="row-<?= $r['id'] ?>" data-req-id="<?= $r['id'] ?>"
                    data-created="<?= $r['created_at'] ?>"
                    data-status="<?= $r['status'] ?>"
                    style="<?= $is_pending && $is_admin ? 'background:rgba(255,208,96,0.03)' : '' ?>">
                    <td><strong style="color:var(--cyan)">#<?= $r['id'] ?></strong></td>
                    <td>
                        <strong><?= htmlspecialchars($r['product_name']) ?></strong>
                        <?php if($r['product_category']): ?>
                        <br><small style="color:var(--muted)"><?= htmlspecialchars($r['product_category']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <strong style="font-family:var(--fh);font-size:16px;color:var(--text)"><?= $r['quantity']+0 ?></strong>
                        <span class="<?= $r['unit_type']==='carton'?'unit-carton':'unit-detail' ?>" style="margin-left:4px">
                            <i class="fas <?= $r['unit_type']==='carton'?'fa-box-open':'fa-cube' ?>"></i>
                            <?= $r['unit_type'] ?>
                        </span>
                    </td>
                    <td>
                        <strong><?= htmlspecialchars($r['requester_name']) ?></strong>
                        <?php if($r['note']): ?>
                        <br><small style="color:var(--muted);font-style:italic"><?= htmlspecialchars(mb_strimwidth($r['note'],0,45,'…')) ?></small>
                        <?php endif; ?>
                    </td>
                    <td style="color:var(--muted);font-size:12px;white-space:nowrap">
                        <?= date('d/m/Y', strtotime($r['created_at'])) ?><br>
                        <strong style="color:var(--text);font-size:12px"><?= date('H:i', strtotime($r['created_at'])) ?></strong>
                    </td>
                    <!-- COUNTDOWN -->
                    <td class="countdown-cell" id="ct-<?= $r['id'] ?>">
                        <?php if($is_pending): ?>
                        <div class="ct-display">
                            <span id="ct-txt-<?= $r['id'] ?>" class="<?= $remaining<=30?'ct-urgent':($remaining<=60?'ct-warn':'ct-safe') ?>">
                                <?php
                                    if($remaining <= 0) echo '⚙️ Imminente';
                                    else printf('%d:%02d', intdiv($remaining,60), $remaining%60);
                                ?>
                            </span>
                            <div class="ct-bar" style="margin-top:4px">
                                <div class="ct-bar-fill" id="ct-bar-<?= $r['id'] ?>"
                                     style="width:<?= $pct_used ?>%;background:<?= $remaining<=30?'var(--red)':($remaining<=60?'var(--orange)':'var(--gold)') ?>">
                                </div>
                            </div>
                        </div>
                        <?php else: ?>
                        <span style="color:var(--muted);font-size:11px">—</span>
                        <?php endif; ?>
                    </td>
                    <td id="status-cell-<?= $r['id'] ?>">
                        <?php
                        $smap = [
                            'en_attente'=>['s-attente','⏳ En attente'],
                            'confirmee' =>['s-confirmee','✅ Confirmée'],
                            'rejetee'   =>['s-rejetee','❌ Rejetée'],
                            'annulee'   =>['s-annulee','🚫 Annulée'],
                        ];
                        [$sc,$sl] = $smap[$r['status']] ?? ['s-attente',$r['status']];
                        ?>
                        <span class="status-badge <?= $sc ?>"><?= $sl ?></span>
                        <?php if($r['admin_name'] && $r['status']!=='en_attente'): ?>
                        <br><small style="color:var(--muted);font-size:11px;margin-top:4px;display:block">par <?= htmlspecialchars($r['admin_name']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td style="max-width:160px;font-size:12px;color:var(--muted)" id="anote-<?= $r['id'] ?>">
                        <?= $r['admin_note'] ? htmlspecialchars(mb_strimwidth($r['admin_note'],0,55,'…')) : '—' ?>
                    </td>
                    <td id="actions-<?= $r['id'] ?>">
                        <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                            <a href="?view=history&req_id=<?= $r['id'] ?>" class="btn btn-purple btn-xs" title="Historique">
                                <i class="fas fa-history"></i>
                            </a>
                            <?php if($is_pending): ?>
                                <?php if(!$is_admin && $r['requested_by'] == $user_id): ?>
                                <button onclick="openEditModal(<?= htmlspecialchars(json_encode($r)) ?>)"
                                        class="btn btn-gold btn-xs" title="Modifier"><i class="fas fa-edit"></i></button>
                                <button onclick="openCancelModal(<?= $r['id'] ?>)"
                                        class="btn btn-red btn-xs" title="Annuler"><i class="fas fa-times"></i></button>
                                <?php elseif($is_admin): ?>
                                <button onclick="openConfirmModal(<?= htmlspecialchars(json_encode($r)) ?>)"
                                        class="btn btn-neon btn-xs" title="Confirmer">
                                    <i class="fas fa-check"></i> Appro
                                </button>
                                <button onclick="openRejectModal(<?= $r['id'] ?>)"
                                        class="btn btn-red btn-xs" title="Rejeter"><i class="fas fa-times"></i></button>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
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
<?php endif; ?>

</div><!-- /wrap -->

<!-- TOAST STACK -->
<div class="toast-stack" id="toast-stack"></div>

<!-- ══════ MODALS ══════ -->

<!-- Modal Modifier (Caissière) -->
<div id="modal-edit" class="modal">
    <div class="modal-box">
        <h2 class="gold"><i class="fas fa-edit"></i> &nbsp;Modifier la demande</h2>
        <form method="post" id="edit-form">
            <input type="hidden" name="request_id" id="edit-req-id">
            <label class="f-label">Produit</label>
            <div id="edit-prod-name" style="padding:12px 16px;background:rgba(0,0,0,0.3);border:1.5px solid var(--bord);border-radius:12px;font-family:var(--fh);font-weight:900;color:var(--cyan);margin-bottom:14px">—</div>
            <label class="f-label">Quantité</label>
            <input type="number" name="quantity" id="edit-qty" class="f-input" min="1" step="1" required>
            <label class="f-label">Type d'unité</label>
            <div class="unit-toggle">
                <div class="unit-opt"><input type="radio" name="unit_type" id="eu-detail" value="detail" checked>
                    <label for="eu-detail"><i class="fas fa-cube"></i><span>Côté Détail</span></label></div>
                <div class="unit-opt"><input type="radio" name="unit_type" id="eu-carton" value="carton">
                    <label for="eu-carton"><i class="fas fa-box-open"></i><span>Côté Carton</span></label></div>
            </div>
            <label class="f-label">Note</label>
            <textarea name="note" id="edit-note" class="f-textarea" placeholder="Note ou précision…"></textarea>
            <div class="modal-btns">
                <button type="submit" name="update_request" class="btn btn-gold"><i class="fas fa-save"></i> Enregistrer</button>
                <button type="button" class="btn btn-red" onclick="closeModal('modal-edit')"><i class="fas fa-times"></i> Annuler</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Annuler -->
<div id="modal-cancel" class="modal">
    <div class="modal-box">
        <h2 class="danger"><i class="fas fa-ban"></i> &nbsp;Annuler la demande</h2>
        <div style="background:rgba(255,53,83,0.06);border:1px solid rgba(255,53,83,0.2);border-radius:12px;padding:14px 18px;margin-bottom:20px">
            <p style="font-family:var(--fb);font-size:13px;color:var(--text2);line-height:1.7">Vous allez annuler cette demande. Elle ne sera plus auto-confirmée ni traitée.</p>
        </div>
        <form method="post">
            <input type="hidden" name="request_id" id="cancel-req-id">
            <label class="f-label">Motif d'annulation</label>
            <input type="text" name="cancel_motif" class="f-input" placeholder="Ex: Plus nécessaire, déjà reçu…">
            <div class="modal-btns">
                <button type="submit" name="cancel_request" class="btn btn-red"><i class="fas fa-ban"></i> Confirmer</button>
                <button type="button" class="btn btn-neon" onclick="closeModal('modal-cancel')"><i class="fas fa-arrow-left"></i> Retour</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Confirmer (Admin) -->
<div id="modal-confirm" class="modal">
    <div class="modal-box">
        <h2 class="confirm"><i class="fas fa-check-circle"></i> &nbsp;Confirmer l'Approvisionnement</h2>
        <div style="background:rgba(50,190,143,0.06);border:1px solid rgba(50,190,143,0.2);border-radius:12px;padding:16px 20px;margin-bottom:20px">
            <div id="confirm-details" style="font-family:var(--fh);font-size:15px;font-weight:900;color:var(--text);line-height:1.9">—</div>
            <p style="font-family:var(--fb);font-size:12px;color:var(--muted);margin-top:8px;line-height:1.6">
                ✅ En confirmant manuellement, le stock est mis à jour. Le timer auto est annulé.
            </p>
        </div>
        <form method="post">
            <input type="hidden" name="request_id" id="confirm-req-id">
            <label class="f-label">Note admin (optionnel)</label>
            <textarea name="admin_note" class="f-textarea" placeholder="Ex: Livraison prévue demain…"></textarea>
            <div class="modal-btns">
                <button type="submit" name="confirm_request" class="btn btn-neon"><i class="fas fa-check-circle"></i> Confirmer & Approvisionner</button>
                <button type="button" class="btn btn-red" onclick="closeModal('modal-confirm')"><i class="fas fa-times"></i> Annuler</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Rejeter (Admin) -->
<div id="modal-reject" class="modal">
    <div class="modal-box">
        <h2 class="danger"><i class="fas fa-times-circle"></i> &nbsp;Rejeter la demande</h2>
        <form method="post">
            <input type="hidden" name="request_id" id="reject-req-id">
            <label class="f-label">Motif du rejet <span style="color:var(--red)">*</span></label>
            <textarea name="admin_note" class="f-textarea" placeholder="Ex: Stock suffisant, budget…" required></textarea>
            <div class="modal-btns">
                <button type="submit" name="reject_request" class="btn btn-red"><i class="fas fa-times-circle"></i> Rejeter</button>
                <button type="button" class="btn btn-neon" onclick="closeModal('modal-reject')"><i class="fas fa-arrow-left"></i> Retour</button>
            </div>
        </form>
    </div>
</div>

<script>
/* ══════════════════════════════════════════════
   CONFIG GLOBALE
══════════════════════════════════════════════ */
const COMPANY_ID = <?= (int)$company_id ?>;
const CITY_ID    = <?= (int)$city_id ?>;
const IS_ADMIN   = <?= $is_admin ? 'true' : 'false' ?>;
const AUTO_DELAY = 120; // secondes

/* ══ Horloge ══ */
function tick(){
    const n = new Date();
    document.getElementById('clk').textContent =
        n.toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
    document.getElementById('clkd').textContent =
        n.toLocaleDateString('fr-FR',{weekday:'long',day:'numeric',month:'long',year:'numeric'});
}
tick(); setInterval(tick, 1000);

/* ══ Modals ══ */
function openModal(id){ document.getElementById(id).classList.add('show'); }
function closeModal(id){ document.getElementById(id).classList.remove('show'); }
document.querySelectorAll('.modal').forEach(m =>
    m.addEventListener('click', e => { if(e.target===m) m.classList.remove('show'); })
);

/* ══ Stock info ══ */
function updateStock(sel){
    const opt = sel.options[sel.selectedIndex];
    const stock = opt.dataset.stock;
    const info = document.getElementById('stock-info');
    if(!info || stock===undefined) return;
    const n = parseInt(stock);
    info.textContent = n + ' unités';
    info.style.color = n<=5?'var(--red)':(n<=15?'var(--gold)':'var(--neon)');
}

/* ══ Modal Modifier ══ */
function openEditModal(req){
    document.getElementById('edit-req-id').value  = req.id;
    document.getElementById('edit-prod-name').textContent = req.product_name;
    document.getElementById('edit-qty').value     = req.quantity;
    document.getElementById('edit-note').value    = req.note || '';
    const utype = req.unit_type || 'detail';
    document.getElementById('eu-detail').checked  = utype==='detail';
    document.getElementById('eu-carton').checked  = utype==='carton';
    openModal('modal-edit');
}
function openCancelModal(id){
    document.getElementById('cancel-req-id').value = id;
    openModal('modal-cancel');
}
function openConfirmModal(req){
    document.getElementById('confirm-req-id').value = req.id;
    const unit = req.unit_type==='carton'
        ? `<span style="color:var(--orange)">📦 ${req.quantity} carton(s)</span>`
        : `<span style="color:var(--cyan)">🔹 ${req.quantity} unité(s) détail</span>`;
    document.getElementById('confirm-details').innerHTML =
        `<i class="fas fa-box" style="color:var(--neon)"></i> ${req.product_name}<br>${unit}
        <br><small style="color:var(--muted);font-family:var(--fb);font-size:12px">Demandé par: ${req.requester_name}</small>`;
    openModal('modal-confirm');
}
function openRejectModal(id){
    document.getElementById('reject-req-id').value = id;
    openModal('modal-reject');
}

/* ══════════════════════════════════════════════
   🔔 TOAST SYSTEM
══════════════════════════════════════════════ */
function showToast(title, msg, color='var(--purple)', icon='fa-robot'){
    const stack = document.getElementById('toast-stack');
    if(!stack) return;
    const t = document.createElement('div');
    t.className = 'toast';
    t.innerHTML = `
        <div class="toast-ico" style="background:rgba(168,85,247,0.12);color:${color};border-radius:10px">
            <i class="fas ${icon}"></i>
        </div>
        <div class="toast-txt">
            <strong style="color:${color}">${title}</strong>
            <span>${msg}</span>
        </div>`;
    stack.appendChild(t);
    setTimeout(() => {
        t.classList.add('removing');
        setTimeout(() => t.remove(), 500);
    }, 5000);
}

/* ══════════════════════════════════════════════
   ⏱ COUNTDOWN TIMERS — Live
══════════════════════════════════════════════ */
// Map: reqId → {created: timestamp_ms, row_el, ct_txt, ct_bar, processed}
const countdownMap = {};

function initCountdowns(){
    document.querySelectorAll('tr[data-req-id]').forEach(row => {
        const id     = row.dataset.reqId;
        const status = row.dataset.status;
        const created= row.dataset.created;
        if(status !== 'en_attente' || !created) return;
        const createdMs = new Date(created.replace(' ','T')).getTime();
        countdownMap[id] = {
            createdMs,
            row,
            ctTxt : document.getElementById('ct-txt-'+id),
            ctBar : document.getElementById('ct-bar-'+id),
            processed: false,
        };
    });
}

function tickCountdowns(){
    const now = Date.now();
    for(const [id, cd] of Object.entries(countdownMap)){
        if(cd.processed) continue;
        const age = (now - cd.createdMs) / 1000;
        const remaining = Math.max(0, AUTO_DELAY - age);
        const pct = Math.min(100, (age / AUTO_DELAY) * 100);

        if(cd.ctTxt){
            if(remaining <= 0){
                cd.ctTxt.textContent = '⚙️ Imminente';
                cd.ctTxt.className   = 'ct-urgent';
            } else {
                const m = Math.floor(remaining/60);
                const s = Math.floor(remaining%60);
                cd.ctTxt.textContent = `${m}:${s.toString().padStart(2,'0')}`;
                cd.ctTxt.className   = remaining<=30?'ct-urgent':(remaining<=60?'ct-warn':'ct-safe');
            }
        }
        if(cd.ctBar){
            cd.ctBar.style.width = pct + '%';
            cd.ctBar.style.background = remaining<=30?'var(--red)':(remaining<=60?'var(--orange)':'var(--gold)');
        }
    }
}

/* ══════════════════════════════════════════════
   🤖 AJAX AUTO-CONFIRM POLL
══════════════════════════════════════════════ */
let pollActive = true;

async function pollAutoConfirm(){
    if(!pollActive || !COMPANY_ID || !CITY_ID) return;
    try {
        const r = await fetch(`?ajax=auto_confirm&company_id=${COMPANY_ID}&city_id=${CITY_ID}`,{
            method:'GET', headers:{'X-Requested-With':'XMLHttpRequest'}
        });
        if(!r.ok) return;
        const data = await r.json();
        if(data.error){ console.warn('Auto-confirm error:', data.error); return; }

        /* Traiter les confirmations */
        if(data.confirmed && data.confirmed.length > 0){
            data.confirmed.forEach(req => {
                markRowAutoConfirmed(req.id, req.product_name, req.quantity, req.unit_type);
                showToast(
                    `⚙️ Auto-confirmé #${req.id}`,
                    `${req.product_name} — +${req.quantity} ${req.unit_type}`,
                    'var(--purple)', 'fa-robot'
                );
                if(countdownMap[req.id]){
                    countdownMap[req.id].processed = true;
                    const ctTxt = document.getElementById('ct-txt-'+req.id);
                    if(ctTxt){ ctTxt.textContent='✅ Auto-confirmé'; ctTxt.className='ct-done'; }
                    const ctBar = document.getElementById('ct-bar-'+req.id);
                    if(ctBar){ ctBar.style.width='100%'; ctBar.style.background='var(--purple)'; }
                }
            });

            /* Update KPI pending */
            const kpiPending = document.getElementById('kpi-pending');
            if(kpiPending){
                const cur = parseInt(kpiPending.textContent) || 0;
                kpiPending.textContent = Math.max(0, cur - data.confirmed.length);
            }
        }

        /* Update notif badge */
        if(data.unread_notif > 0){
            let badge = document.getElementById('nav-notif-badge');
            const navLink = document.getElementById('nav-notif-link');
            if(!badge && navLink){
                badge = document.createElement('span');
                badge.id = 'nav-notif-badge';
                badge.className = 'notif-badge';
                navLink.appendChild(badge);
            }
            if(badge) badge.textContent = data.unread_notif;
        }

    } catch(e){ console.warn('Poll error:', e); }
}

function markRowAutoConfirmed(reqId, productName, qty, unit){
    const row = document.getElementById('row-'+reqId);
    if(!row) return;
    row.classList.add('row-auto-confirmed');
    row.dataset.status = 'confirmee';

    /* Status cell */
    const sc = document.getElementById('status-cell-'+reqId);
    if(sc) sc.innerHTML = `<span class="status-badge s-auto"><i class="fas fa-robot"></i> Auto-confirmée</span>
        <br><small style="color:var(--purple);font-size:11px;margin-top:4px;display:block">⚙️ SYSTÈME</small>`;

    /* Admin note */
    const an = document.getElementById('anote-'+reqId);
    if(an) an.textContent = 'Auto-confirmé après 2min';

    /* Actions */
    const ac = document.getElementById('actions-'+reqId);
    if(ac){
        const hist = ac.querySelector('a.btn-purple');
        ac.innerHTML = '';
        if(hist) ac.appendChild(hist);
    }
}

/* ══════════════════════════════════════════════
   📊 EXPORT EXCEL
══════════════════════════════════════════════ */
function doExport(){
    const cat  = document.getElementById('exp-cat')?.value  || '';
    const stat = document.getElementById('exp-stat')?.value || '';
    const dfr  = document.getElementById('exp-dfr')?.value  || '';
    const dto  = document.getElementById('exp-dto')?.value  || '';
    const url  = `?export=xlsx&ecat=${encodeURIComponent(cat)}&estat=${encodeURIComponent(stat)}&dfrom=${dfr}&dto=${dto}`;
    showToast('📊 Export en cours…', 'Génération du fichier Excel multi-feuilles', 'var(--neon)', 'fa-file-excel');
    window.location.href = url;
}
function doExport2(){
    const cat  = document.getElementById('exp-cat2')?.value  || '';
    const stat = document.getElementById('exp-stat2')?.value || '';
    const url  = `?export=xlsx&ecat=${encodeURIComponent(cat)}&estat=${encodeURIComponent(stat)}`;
    showToast('📊 Export en cours…', 'Génération du fichier Excel multi-feuilles', 'var(--neon)', 'fa-file-excel');
    window.location.href = url;
}

/* ══════════════════════════════════════════════
   🔔 FILTRE NOTIFICATIONS
══════════════════════════════════════════════ */
function filterNotif(type='', cat=''){
    document.querySelectorAll('#notif-list .notif-card').forEach(card => {
        const t = card.dataset.type || '';
        const c = card.dataset.cat  || '';
        const showType = !type || t === type;
        const showCat  = !cat  || c === cat;
        card.style.display = (showType && showCat) ? '' : 'none';
    });
}

/* ══ INIT ══ */
initCountdowns();
setInterval(tickCountdowns, 1000);

/* Poll toutes les 15 secondes */
if(COMPANY_ID && CITY_ID){
    setTimeout(pollAutoConfirm, 3000);        // Premier poll après 3s
    setInterval(pollAutoConfirm, 15000);      // Puis toutes les 15s
}

/* Visibilité — pause quand onglet caché */
document.addEventListener('visibilitychange', () => {
    pollActive = document.visibilityState === 'visible';
    if(pollActive) pollAutoConfirm(); // Sondage immédiat au retour
});

console.log('🚀 Appro v3.0 — Auto-confirm 2min | Notifications | Export Excel — ESPERANCE H2O');
let stockDeferredInstallPrompt=null;
window.addEventListener('beforeinstallprompt',e=>{e.preventDefault();stockDeferredInstallPrompt=e;});
document.getElementById('stockInstallBtn')?.addEventListener('click',async()=>{if(!stockDeferredInstallPrompt){window.location.href='/stock/install_stock_app.php';return;}stockDeferredInstallPrompt.prompt();await stockDeferredInstallPrompt.userChoice.catch(()=>null);stockDeferredInstallPrompt=null;});
function updateStockNetworkBadge(){document.getElementById('stockNetworkBadge')?.classList.toggle('show',!navigator.onLine);}
window.addEventListener('online',updateStockNetworkBadge);window.addEventListener('offline',updateStockNetworkBadge);updateStockNetworkBadge();
if('serviceWorker' in navigator){window.addEventListener('load',()=>navigator.serviceWorker.register('/stock/stock-sw.js').catch(()=>{}));}
</script>
</body>
</html>
