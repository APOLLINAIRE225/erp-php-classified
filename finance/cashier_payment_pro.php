<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

/**
 * ════════════════════════════════════════════════════════════════
 * CAISSE PAIEMENT TICKET THERMAL — Dark Neon v3.0
 * ESPERANCE H2O · Police C059 Bold
 * ════════════════════════════════════════════════════════════════
 * ✅ Format ticket thermal 80mm (imprimantes de caisse)
 * ✅ Signature électronique employé sur ticket
 * ✅ Génération PDF Dompdf
 * ✅ Dark Neon — même charte admin_nasa / attendance
 * ✅ C059 Bold (Courier Prime) pour toutes les écritures
 * ✅ Export Excel paiements
 * ✅ Historique transactions
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
use Dompdf\Dompdf;
use Dompdf\Options;

Auth::check();
Middleware::role(['developer', 'admin', 'manager']);

$pdo       = DB::getConnection();
$user_id   = $_SESSION['user_id']   ?? 0;
$user_name = $_SESSION['username']  ?? 'Admin';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

date_default_timezone_set('Africa/Abidjan');

$success_msg = '';
$error_msg   = '';
$view        = $_GET['view'] ?? 'cashier';

/* ═══════════════════════════════════════════
   DB SETUP
═══════════════════════════════════════════ */
$pdo->exec("
CREATE TABLE IF NOT EXISTS payment_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payroll_id INT NOT NULL,
    employee_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_method ENUM('especes','mobile_money','virement','cheque') DEFAULT 'especes',
    paid_by INT NOT NULL,
    paid_at DATETIME NOT NULL,
    signature_data TEXT NULL,
    notes TEXT NULL,
    receipt_number VARCHAR(50) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_payroll (payroll_id),
    INDEX idx_employee (employee_id),
    INDEX idx_receipt (receipt_number),
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (paid_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/* ═══════════════════════════════════════════
   PAIEMENT
═══════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'process_payment') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error_msg = "Token CSRF invalide — veuillez rafraîchir la page";
    } else {
        try {
            $pdo->beginTransaction();

            $payroll_id    = (int)$_POST['payroll_id'];
            $employee_id   = (int)$_POST['employee_id'];
            $amount        = (float)$_POST['amount'];
            $method        = $_POST['payment_method'];
            $signature     = $_POST['signature'] ?? null;
            $notes         = trim($_POST['notes'] ?? '');

            if ($payroll_id <= 0 || $employee_id <= 0 || $amount <= 0) throw new Exception("Données invalides");
            if (empty($signature)) throw new Exception("La signature de l'employé est requise !");

            $st = $pdo->prepare("SELECT status FROM payroll WHERE id=? AND employee_id=?");
            $st->execute([$payroll_id, $employee_id]);
            $pr = $st->fetch(PDO::FETCH_ASSOC);
            if (!$pr) throw new Exception("Paie introuvable !");
            if ($pr['status'] === 'paye') throw new Exception("Cette paie a déjà été payée !");

            $receipt = 'REC-' . date('Ymd') . '-' . str_pad($employee_id, 4, '0', STR_PAD_LEFT) . '-' . strtoupper(bin2hex(random_bytes(3)));

            $pdo->prepare("
                INSERT INTO payment_transactions
                (payroll_id, employee_id, amount, payment_method, paid_by, paid_at, signature_data, notes, receipt_number)
                VALUES (?,?,?,?,?,NOW(),?,?,?)
            ")->execute([$payroll_id, $employee_id, $amount, $method, $user_id, $signature, $notes, $receipt]);

            $tid = $pdo->lastInsertId();

            $pdo->prepare("UPDATE payroll SET status='paye', payment_date=NOW(), paid_by=? WHERE id=?")
                ->execute([$user_id, $payroll_id]);

            $pdo->commit();
            $success_msg = "PAIEMENT EFFECTUÉ !|Montant : " . number_format($amount,0,'','.')." FCFA|Reçu : $receipt|$tid";

        } catch (Exception $e) {
            $pdo->rollBack();
            $error_msg = "Erreur : " . $e->getMessage();
        }
    }
}

/* ═══════════════════════════════════════════
   IMPRESSION TICKET PDF (80mm DOMPDF)
═══════════════════════════════════════════ */
if (isset($_GET['print_receipt'])) {
    $tid = (int)$_GET['print_receipt'];
    $st  = $pdo->prepare("
        SELECT pt.*, e.employee_code, e.full_name, c.name as category_name, p.title as position_title,
               pr.month, pr.base_salary, pr.overtime_amount, pr.advances_deduction, pr.absences_deduction, pr.net_salary,
               u.username as paid_by_name
        FROM payment_transactions pt
        JOIN employees e ON pt.employee_id=e.id
        JOIN categories c ON e.category_id=c.id
        JOIN positions  p ON e.position_id=p.id
        JOIN payroll pr ON pt.payroll_id=pr.id
        JOIN users u ON pt.paid_by=u.id
        WHERE pt.id=?
    ");
    $st->execute([$tid]);
    $tr = $st->fetch(PDO::FETCH_ASSOC);

    if ($tr) {
        $pm_labels = ['especes'=>'ESPÈCES','mobile_money'=>'MOBILE MONEY','virement'=>'VIREMENT','cheque'=>'CHÈQUE'];
        $sig_html  = $tr['signature_data']
            ? '<img src="'.$tr['signature_data'].'" style="max-width:100%;max-height:50px;display:block;margin:0 auto" />'
            : '<em style="color:#999">Non signée</em>';

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
        @page{margin:0;size:80mm auto;}
        *{margin:0;padding:0;box-sizing:border-box;}
        body{font-family:"Courier Prime","Courier New",monospace;font-weight:700;font-size:9pt;line-height:1.4;color:#000;width:80mm;padding:5mm;}
        .center{text-align:center;}
        .co{font-size:15pt;font-weight:900;text-align:center;letter-spacing:2px;}
        .title{font-size:11pt;font-weight:900;text-align:center;text-transform:uppercase;margin:6px 0;}
        .rn{display:block;text-align:center;background:#000;color:#fff;font-weight:900;font-size:10pt;padding:4px 8px;margin:5px auto;width:fit-content;}
        .sep{border:none;border-top:2px dashed #000;margin:8px 0;}
        .sep1{border:none;border-top:1px dashed #000;margin:6px 0;}
        .row{display:flex;justify-content:space-between;margin:3px 0;font-size:8.5pt;}
        .row b{font-weight:900;}
        .drow{display:flex;justify-content:space-between;margin:4px 0;font-size:9pt;font-weight:700;}
        .total-box{background:#000;color:#fff;padding:10px;text-align:center;margin:10px 0;}
        .total-lbl{font-size:9pt;font-weight:700;margin-bottom:4px;}
        .total-amt{font-size:18pt;font-weight:900;font-family:"Courier Prime","Courier New",monospace;}
        .sig-box{border:2px solid #000;padding:5px;text-align:center;min-height:60px;margin:8px 0;}
        .sig-name{text-align:center;font-weight:900;border-top:1px solid #000;padding-top:4px;margin-top:4px;}
        .foot{text-align:center;font-size:7.5pt;font-weight:700;margin-top:10px;border-top:2px dashed #000;padding-top:8px;}
        </style></head><body>
        <div class="co">ESPERANCE H2O</div>
        <div class="title">REÇU DE PAIEMENT</div>
        <div class="center"><span class="rn">'.htmlspecialchars($tr['receipt_number']).'</span></div>
        <hr class="sep">
        <div class="row"><span>Date :</span><b>'.date('d/m/Y H:i',strtotime($tr['paid_at'])).'</b></div>
        <div class="row"><span>Employé :</span><b>'.htmlspecialchars($tr['full_name']).'</b></div>
        <div class="row"><span>Code :</span><b>'.htmlspecialchars($tr['employee_code']).'</b></div>
        <div class="row"><span>Poste :</span><b>'.htmlspecialchars($tr['position_title']).'</b></div>
        <div class="row"><span>Période :</span><b>'.date('m/Y',strtotime($tr['month'].'-01')).'</b></div>
        <div class="row"><span>Mode :</span><b>'.($pm_labels[$tr['payment_method']]??strtoupper($tr['payment_method'])).'</b></div>
        <hr class="sep">
        <div class="drow"><span>Salaire de base</span><b>+'.number_format($tr['base_salary'],0,'','.').'</b></div>';

        if ($tr['overtime_amount']  > 0) $html .= '<div class="drow"><span>Heures sup.</span><b>+'.number_format($tr['overtime_amount'],0,'','.') .'</b></div>';
        if ($tr['advances_deduction']> 0) $html .= '<div class="drow"><span>Avances</span><b>-'.number_format($tr['advances_deduction'],0,'','.') .'</b></div>';
        if ($tr['absences_deduction']> 0) $html .= '<div class="drow"><span>Absences</span><b>-'.number_format($tr['absences_deduction'],0,'','.') .'</b></div>';

        $html .= '<div class="total-box">
            <div class="total-lbl">MONTANT NET PAYÉ</div>
            <div class="total-amt">'.number_format($tr['amount'],0,'','.').' F</div>
        </div>';

        if ($tr['notes']) $html .= '<div style="border:1px solid #000;padding:5px;font-size:8pt;font-weight:700;margin:6px 0"><b>Notes :</b><br>'.nl2br(htmlspecialchars($tr['notes'])).'</div>';

        $html .= '<div style="font-weight:900;font-size:9pt;text-align:center;margin:8px 0">SIGNATURE EMPLOYÉ</div>
        <div class="sig-box">'.$sig_html.'</div>
        <div class="sig-name">'.htmlspecialchars($tr['full_name']).'</div>
        <hr class="sep1">
        <div class="row"><span>Payé par :</span><b>'.htmlspecialchars($tr['paid_by_name']).'</b></div>
        <div class="foot">
            <b>ESPERANCE H2O</b><br>
            Reçu généré le '.date('d/m/Y H:i').'<br><br>
            Ce reçu atteste du paiement<br>
            effectué en main propre<br>
            Document officiel — À conserver
        </div>
        </body></html>';

        $opts = new Options();
        $opts->set('isHtml5ParserEnabled', true);
        $opts->set('defaultFont', 'Courier');
        $dompdf = new Dompdf($opts);
        $dompdf->loadHtml($html);
        $dompdf->setPaper([0, 0, 226.77, 841.89], 'portrait');
        $dompdf->render();
        $dompdf->stream("ticket_".$tr['receipt_number'].".pdf", ['Attachment'=>false]);
        exit;
    } else {
        die("Transaction introuvable");
    }
}

/* ═══════════════════════════════════════════
   EXPORT EXCEL
═══════════════════════════════════════════ */
if (isset($_GET['export_payments'])) {
    $month = $_GET['month'] ?? date('Y-m');
    $st = $pdo->prepare("
        SELECT pt.*, e.employee_code, e.full_name, c.name as category_name, p.title as position_title,
               pr.month, u.username as paid_by_name
        FROM payment_transactions pt
        JOIN employees e ON pt.employee_id=e.id
        JOIN categories c ON e.category_id=c.id
        JOIN positions  p ON e.position_id=p.id
        JOIN payroll pr ON pt.payroll_id=pr.id
        JOIN users u ON pt.paid_by=u.id
        WHERE pr.month=? ORDER BY pt.paid_at DESC
    ");
    $st->execute([$month]);
    $payments = $st->fetchAll(PDO::FETCH_ASSOC);

    $ss    = new Spreadsheet();
    $sheet = $ss->getActiveSheet();
    $sheet->setTitle('Paiements '.$month);
    $sheet->setCellValue('A1','RAPPORT DES PAIEMENTS — '.strtoupper($month));
    $sheet->mergeCells('A1:J1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF081420');
    $sheet->getStyle('A1')->getFont()->getColor()->setARGB('FF32be8f');
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getRowDimension(1)->setRowHeight(28);

    $headers = ['N° Reçu','Date/Heure','Code','Nom Employé','Catégorie','Poste','Montant','Mode','Payé par','Notes'];
    $sheet->fromArray($headers, null, 'A3');
    $sheet->getStyle('A3:J3')->getFont()->setBold(true);
    $sheet->getStyle('A3:J3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0d1e2c');
    $sheet->getStyle('A3:J3')->getFont()->getColor()->setARGB('FF32be8f');
    $sheet->getStyle('A3:J3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $row = 4; $total = 0;
    $pm = ['especes'=>'Espèces','mobile_money'=>'Mobile Money','virement'=>'Virement','cheque'=>'Chèque'];
    foreach ($payments as $p) {
        $sheet->fromArray([$p['receipt_number'],date('d/m/Y H:i',strtotime($p['paid_at'])),
            $p['employee_code'],$p['full_name'],$p['category_name'],$p['position_title'],
            number_format($p['amount'],0).' FCFA',$pm[$p['payment_method']]??$p['payment_method'],
            $p['paid_by_name'],$p['notes']?:'-'],null,"A$row");
        $sheet->getStyle("A$row:J$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $total += $p['amount']; $row++;
    }
    $row++;
    $sheet->setCellValue("I$row","TOTAL :"); $sheet->setCellValue("J$row",number_format($total,0).' FCFA');
    $sheet->getStyle("I$row:J$row")->getFont()->setBold(true)->setSize(12);
    foreach(range('A','J') as $c) $sheet->getColumnDimension($c)->setAutoSize(true);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="paiements_'.$month.'_'.date('Ymd').'.xlsx"');
    header('Cache-Control: max-age=0');
    (new Xlsx($ss))->save('php://output'); exit;
}

/* ═══════════════════════════════════════════
   DONNÉES
═══════════════════════════════════════════ */
$current_month = $_GET['month'] ?? date('Y-m');

$st = $pdo->prepare("
    SELECT pr.*, e.employee_code, e.full_name, c.name as category_name, p.title as position_title
    FROM payroll pr
    JOIN employees e ON pr.employee_id=e.id
    JOIN categories c ON e.category_id=c.id
    JOIN positions  p ON e.position_id=p.id
    WHERE pr.month=? AND pr.status='impaye' ORDER BY c.name, e.full_name
");
$st->execute([$current_month]);
$unpaid_payrolls = $st->fetchAll(PDO::FETCH_ASSOC);

$st = $pdo->prepare("
    SELECT pt.*, e.employee_code, e.full_name, u.username as paid_by_name
    FROM payment_transactions pt
    JOIN employees e ON pt.employee_id=e.id
    JOIN users u ON pt.paid_by=u.id
    JOIN payroll pr ON pt.payroll_id=pr.id
    WHERE pr.month=? ORDER BY pt.paid_at DESC
");
$st->execute([$current_month]);
$payment_history = $st->fetchAll(PDO::FETCH_ASSOC);

$total_to_pay  = array_sum(array_column($unpaid_payrolls,'net_salary'));
$total_paid    = array_sum(array_column($payment_history,'amount'));
$count_unpaid  = count($unpaid_payrolls);
$count_paid    = count($payment_history);

// Parse success
$success_parts = $success_msg ? explode('|',$success_msg) : [];
$error_parts   = $error_msg   ? explode('|',$error_msg)   : [];
$new_tid       = !empty($success_parts[3]) ? (int)$success_parts[3] : 0;

$csrf = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Caisse Paiements — ESPERANCE H2O</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Courier+Prime:ital,wght@0,400;0,700;1,400;1,700&family=Playfair+Display:wght@700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/signature_pad@4.0.0/dist/signature_pad.umd.min.js"></script>
<style>
/* ═══════════════════════════════════════════════════════
   DARK NEON — CAISSE — C059 BOLD (Courier Prime)
   Même charte admin_nasa / attendance / rh_pro
═══════════════════════════════════════════════════════ */
:root {
    --bg:#0f1726;
    --surf:#162033;
    --card:#1b263b;
    --card2:#22324a;
    --bord:   rgba(50,190,143,0.14);
    --bord2:  rgba(50,190,143,0.30);
    --neon:#00a86b;
    --neon2:#00c87a;
    --red:#e53935;
    --orange:#f57c00;
    --blue:#1976d2;
    --gold:#f9a825;
    --purple: #a855f7;
    --cyan:   #06b6d4;
    --pink:   #ec4899;
    --text:#e8eef8;
    --text2:#bfd0e4;
    --muted:#8ea3bd;
    --glow:0 8px 24px rgba(0,168,107,0.18);
    --glow-r:0 8px 24px rgba(229,57,53,0.18);
    /* ── POLICES C059 BOLD ── */
    --fc: 'Courier Prime', 'Courier New', monospace;  /* C059 Bold = Courier Prime Bold */
    --fh: 'Playfair Display', Georgia, serif;         /* Titres déco */
}

/* ── Reset ── */
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
html { scroll-behavior:smooth; }

/* ── TOUT le texte en Courier Prime Bold par défaut ── */
body {
    font-family: var(--fc);
    font-weight: 700;          /* C059 BOLD partout */
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    overflow-x: hidden;
}

/* Grid BG */
body::before {
    content:'';position:fixed;inset:0;z-index:0;pointer-events:none;
    background:
        radial-gradient(ellipse 65% 42% at 4% 8%,  rgba(50,190,143,0.07) 0%,transparent 62%),
        radial-gradient(ellipse 52% 36% at 96% 88%, rgba(61,140,255,0.06) 0%,transparent 62%),
        radial-gradient(ellipse 40% 30% at 50% 50%, rgba(168,85,247,0.03) 0%,transparent 70%);
}
body::after {
    content:'';position:fixed;inset:0;z-index:0;pointer-events:none;
    background-image:
        linear-gradient(rgba(50,190,143,0.018) 1px,transparent 1px),
        linear-gradient(90deg,rgba(50,190,143,0.018) 1px,transparent 1px);
    background-size:48px 48px;
}

.wrap { position:relative;z-index:1;max-width:1900px;margin:0 auto;padding:14px 16px 56px; }

/* Scrollbar */
::-webkit-scrollbar { width:7px;height:7px; }
::-webkit-scrollbar-track { background:var(--surf); }
::-webkit-scrollbar-thumb { background:rgba(50,190,143,0.4);border-radius:4px; }

/* ── ANIMATIONS ── */
@keyframes fadeUp   { from{opacity:0;transform:translateY(22px)}to{opacity:1;transform:translateY(0)} }
@keyframes breathe  { 0%,100%{box-shadow:0 0 16px rgba(50,190,143,0.4)}50%{box-shadow:0 0 42px rgba(50,190,143,0.85)} }
@keyframes pdot     { 0%,100%{opacity:1;transform:scale(1)}50%{opacity:.3;transform:scale(.6)} }
@keyframes pulse-r  { 0%,100%{box-shadow:0 0 0 0 rgba(255,53,83,.4)}50%{box-shadow:0 0 0 6px transparent} }
@keyframes scanBar  { 0%{left:-100%}100%{left:110%} }
@keyframes zoomIn   { from{opacity:0;transform:scale(.88)}to{opacity:1;transform:scale(1)} }
@keyframes toast-in { from{opacity:0;transform:translateX(50px)}to{opacity:1;transform:translateX(0)} }
@keyframes toast-out{ from{opacity:1}to{opacity:0;transform:translateX(50px)} }
@keyframes tickPulse{ 0%,100%{opacity:1}50%{opacity:.4} }

/* ══ TOPBAR ══ */
.topbar {
    display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;
    background:rgba(22,32,51,0.96);border:1px solid var(--bord);border-radius:18px;
    padding:16px 24px;margin-bottom:12px;backdrop-filter:blur(28px);
    box-shadow:0 4px 32px rgba(0,0,0,0.4);position:relative;overflow:hidden;
    animation:fadeUp .4s ease;
}
.topbar::after {
    content:'';position:absolute;top:0;left:-100%;width:40%;height:2px;
    background:linear-gradient(90deg,transparent,var(--neon),transparent);
    animation:scanBar 3.5s linear infinite;
}
.brand { display:flex;align-items:center;gap:14px; }
.brand-ico {
    width:48px;height:48px;
    background:linear-gradient(135deg,var(--neon),var(--cyan));
    border-radius:13px;display:flex;align-items:center;justify-content:center;
    font-size:22px;color:var(--bg);box-shadow:var(--glow);
    animation:breathe 3.5s ease-in-out infinite;flex-shrink:0;
}
.brand-txt h1 {
    font-family:var(--fc);font-size:20px;font-weight:900;color:var(--text);
    letter-spacing:2px;text-transform:uppercase;line-height:1.2;
}
.brand-txt p {
    font-family:var(--fc);font-size:10px;font-weight:700;color:var(--neon);
    letter-spacing:3px;text-transform:uppercase;
}
.clock-val {
    font-family:var(--fc);font-size:28px;font-weight:900;color:var(--gold);
    letter-spacing:5px;text-shadow:0 0 22px rgba(255,208,96,.55);
}
.clock-sub {
    font-family:var(--fc);font-size:10px;font-weight:700;color:var(--muted);
    letter-spacing:1.5px;text-transform:uppercase;margin-top:2px;
}

/* ══ MONTH SELECTOR ══ */
.month-sel {
    font-family:var(--fc);font-weight:900;font-size:12px;
    padding:9px 14px;background:rgba(0,0,0,.3);border:1.5px solid var(--bord);
    border-radius:10px;color:var(--text);letter-spacing:1px;
    appearance:none;cursor:pointer;transition:all .28s;
}
.month-sel:focus { outline:none;border-color:var(--gold); }
.month-sel option { background:#1b263b; }

/* ══ TAB NAV ══ */
.tab-nav {
    display:flex;align-items:center;flex-wrap:wrap;gap:6px;
    background:rgba(8,20,32,.88);border:1px solid var(--bord);border-radius:14px;
    padding:10px 16px;margin-bottom:16px;backdrop-filter:blur(20px);
}
.tab-lnk {
    display:flex;align-items:center;gap:7px;padding:9px 16px;border-radius:11px;
    border:1.5px solid var(--bord);background:rgba(50,190,143,.04);color:var(--text2);
    font-family:var(--fc);font-size:12px;font-weight:900;text-decoration:none;
    letter-spacing:.8px;text-transform:uppercase;cursor:pointer;transition:all .28s;white-space:nowrap;
}
.tab-lnk:hover  { background:rgba(50,190,143,.1);color:var(--text);border-color:var(--bord2);transform:translateY(-2px); }
.tab-lnk.active { background:linear-gradient(135deg,rgba(50,190,143,.2),rgba(6,182,212,.12));
    color:#fff;border-color:rgba(50,190,143,.45);box-shadow:0 0 16px rgba(50,190,143,.2); }
.tab-badge {
    display:inline-flex;align-items:center;justify-content:center;
    min-width:18px;height:18px;border-radius:9px;font-size:9px;font-weight:900;
    background:var(--red);color:#fff;padding:0 4px;animation:pulse-r 1.5s infinite;
}

/* ══ ALERTS ══ */
.alert {
    display:flex;align-items:flex-start;gap:14px;padding:16px 20px;border-radius:13px;
    margin-bottom:14px;border:1.5px solid;animation:fadeUp .3s ease;
}
.alert-success { background:rgba(50,190,143,.08);border-color:rgba(50,190,143,.28);color:var(--neon); }
.alert-error   { background:rgba(255,53,83,.08);border-color:rgba(255,53,83,.28);color:var(--red); }
.alert-ico  { font-size:20px;flex-shrink:0;margin-top:1px; }
.alert-main { font-family:var(--fc);font-size:14px;font-weight:900;letter-spacing:.5px; }
.alert-sub  { font-size:12px;opacity:.8;margin-top:4px; }
.alert-pill {
    display:inline-block;margin-top:6px;margin-right:6px;padding:3px 10px;border-radius:8px;
    background:rgba(255,255,255,.08);font-family:var(--fc);font-size:11px;font-weight:900;
}

/* ══ KPI STRIP ══ */
.kpi-strip { display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:16px; }
.ks {
    background:var(--card);border:1px solid var(--bord);border-radius:15px;
    padding:20px 18px;display:flex;align-items:center;gap:14px;
    transition:all .3s;animation:fadeUp .4s ease backwards;
}
.ks:hover { transform:translateY(-4px);box-shadow:0 14px 32px rgba(0,0,0,.42);border-color:var(--bord2); }
.ks-ico { width:48px;height:48px;border-radius:13px;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0; }
.ks-val { font-family:var(--fc);font-size:28px;font-weight:900;line-height:1.1; }
.ks-lbl { font-family:var(--fc);font-size:10px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-top:2px; }

/* ══ PAYMENT CARDS GRID ══ */
.pay-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(370px,1fr));gap:22px;margin-bottom:24px; }

.pay-card {
    background:var(--card);border:1px solid var(--bord);border-radius:18px;
    overflow:hidden;transition:all .3s;animation:fadeUp .4s ease backwards;
}
.pay-card:hover { transform:translateY(-5px);box-shadow:0 18px 44px rgba(0,0,0,.5);border-color:rgba(50,190,143,.3); }

/* Card header strip */
.pay-head {
    background:linear-gradient(135deg,rgba(50,190,143,.15),rgba(6,182,212,.1));
    border-bottom:1px solid var(--bord);padding:18px 20px;position:relative;
}
.pay-head::before {
    content:'';position:absolute;left:0;top:0;width:4px;height:100%;
    background:linear-gradient(180deg,var(--neon),var(--cyan));border-radius:18px 0 0 0;
}
.emp-name { font-family:var(--fc);font-size:17px;font-weight:900;color:var(--text);letter-spacing:.5px; }
.emp-meta { font-family:var(--fc);font-size:11px;font-weight:700;color:var(--text2);margin-top:5px;letter-spacing:.3px; }
.emp-code-tag {
    display:inline-block;font-family:var(--fc);font-size:10px;font-weight:900;
    padding:2px 10px;border-radius:10px;background:rgba(50,190,143,.12);
    color:var(--neon);border:1px solid rgba(50,190,143,.25);letter-spacing:1.5px;margin-top:4px;
}

/* Amount display */
.amount-box {
    background:rgba(50,190,143,.07);border:1.5px solid rgba(50,190,143,.22);border-radius:13px;
    padding:18px;text-align:center;margin:16px 16px 14px;
}
.amount-lbl { font-family:var(--fc);font-size:10px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:1.5px;margin-bottom:6px; }
.amount-val { font-family:var(--fc);font-size:30px;font-weight:900;color:var(--neon);letter-spacing:2px; }

/* Breakdown */
.breakdown { margin:0 16px 14px;background:rgba(0,0,0,.18);border:1px solid var(--bord);border-radius:11px;padding:12px 14px; }
.brow { display:flex;justify-content:space-between;align-items:center;padding:5px 0;border-bottom:1px solid rgba(255,255,255,.04); }
.brow:last-child { border-bottom:none; }
.brow-lbl { font-family:var(--fc);font-size:12px;font-weight:700;color:var(--text2); }
.brow-val { font-family:var(--fc);font-size:13px;font-weight:900; }

/* Pay button */
.btn-pay {
    display:flex;align-items:center;justify-content:center;gap:10px;
    width:calc(100% - 32px);margin:0 16px 16px;
    padding:15px;border-radius:12px;border:none;cursor:pointer;
    font-family:var(--fc);font-size:13px;font-weight:900;letter-spacing:1px;text-transform:uppercase;
    background:linear-gradient(135deg,var(--neon),var(--neon2));color:var(--bg);
    box-shadow:0 4px 20px rgba(50,190,143,.35);transition:all .28s;
}
.btn-pay:hover { box-shadow:0 8px 32px rgba(50,190,143,.55);transform:translateY(-2px); }
.btn-pay:active { transform:scale(.97); }

/* ══ BTN ══ */
.btn {
    display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border-radius:10px;
    border:1.5px solid transparent;cursor:pointer;
    font-family:var(--fc);font-size:11px;font-weight:900;letter-spacing:.8px;text-transform:uppercase;
    transition:all .26s;text-decoration:none;white-space:nowrap;
}
.btn:active { transform:scale(.97); }
.btn-n  { background:rgba(50,190,143,.1);border-color:rgba(50,190,143,.3);color:var(--neon); }
.btn-n:hover  { background:var(--neon);color:var(--bg);box-shadow:var(--glow); }
.btn-c  { background:rgba(6,182,212,.1);border-color:rgba(6,182,212,.3);color:var(--cyan); }
.btn-c:hover  { background:var(--cyan);color:var(--bg); }
.btn-g  { background:rgba(255,208,96,.1);border-color:rgba(255,208,96,.3);color:var(--gold); }
.btn-g:hover  { background:var(--gold);color:var(--bg); }
.btn-r  { background:rgba(255,53,83,.1);border-color:rgba(255,53,83,.3);color:var(--red); }
.btn-r:hover  { background:var(--red);color:#fff;box-shadow:var(--glow-r); }
.btn-b  { background:rgba(61,140,255,.1);border-color:rgba(61,140,255,.3);color:var(--blue); }
.btn-b:hover  { background:var(--blue);color:#fff; }
.btn-p  { background:rgba(168,85,247,.1);border-color:rgba(168,85,247,.3);color:var(--purple); }
.btn-p:hover  { background:var(--purple);color:#fff; }
.btn-sm { padding:7px 13px;font-size:10px;border-radius:8px; }

/* ══ HISTORY TABLE ══ */
.card { background:var(--card);border:1px solid var(--bord);border-radius:16px;overflow:hidden;margin-bottom:16px;animation:fadeUp .4s ease; }
.card-head { display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;
    padding:16px 20px;border-bottom:1px solid rgba(255,255,255,.05);background:rgba(0,0,0,.2); }
.card-title { font-family:var(--fc);font-size:15px;font-weight:900;color:var(--text);letter-spacing:1px;
    text-transform:uppercase;display:flex;align-items:center;gap:10px; }
.card-title i { color:var(--neon); }

.tbl-wrap { overflow-x:auto;-webkit-overflow-scrolling:touch; }
table { width:100%;border-collapse:collapse;min-width:860px; }
table th {
    font-family:var(--fc);font-size:10px;font-weight:900;color:var(--muted);
    text-transform:uppercase;letter-spacing:1.2px;padding:12px 14px;
    border-bottom:1px solid rgba(255,255,255,.06);background:rgba(0,0,0,.2);text-align:left;
}
table td {
    font-family:var(--fc);font-size:13px;font-weight:700;color:var(--text2);
    padding:13px 14px;border-bottom:1px solid rgba(255,255,255,.04);vertical-align:middle;
}
tbody tr { transition:background .2s; }
tbody tr:hover td { background:rgba(50,190,143,.03); }
tbody tr:last-child td { border-bottom:none; }

/* Badges */
.bdg {
    font-family:var(--fc);font-size:10px;font-weight:900;padding:3px 10px;
    border-radius:12px;display:inline-flex;align-items:center;gap:4px;letter-spacing:.5px;
}
.bdg-n  { background:rgba(50,190,143,.12);color:var(--neon); }
.bdg-c  { background:rgba(6,182,212,.12);color:var(--cyan); }
.bdg-g  { background:rgba(255,208,96,.12);color:var(--gold); }
.bdg-p  { background:rgba(168,85,247,.12);color:var(--purple); }
.bdg-r  { background:rgba(255,53,83,.12);color:var(--red); }
.emp-code { font-family:var(--fc);font-size:12px;font-weight:900;color:var(--cyan);letter-spacing:2px; }
.emp-nm   { font-family:var(--fc);font-size:14px;font-weight:900;color:var(--text); }
.receipt-no { font-family:var(--fc);font-size:12px;font-weight:900;color:var(--gold);letter-spacing:1px; }

/* ══ MODAL ══ */
.modal {
    display:none;position:fixed;inset:0;background:rgba(0,0,0,.9);z-index:1000;
    align-items:center;justify-content:center;padding:16px;
    backdrop-filter:blur(12px);overflow-y:auto;
}
.modal.show { display:flex; }
.modal-box {
    background:var(--card);border:1px solid var(--bord);border-radius:18px;
    width:100%;max-width:680px;max-height:92vh;overflow-y:auto;
    animation:zoomIn .28s cubic-bezier(.23,1,.32,1);
    box-shadow:0 24px 60px rgba(0,0,0,.7);
}
.modal-head {
    display:flex;align-items:center;justify-content:space-between;
    padding:18px 22px;border-bottom:1px solid rgba(255,255,255,.06);
    background:rgba(0,0,0,.2);position:sticky;top:0;z-index:2;
}
.modal-title { font-family:var(--fc);font-size:16px;font-weight:900;color:var(--text);
    letter-spacing:1px;text-transform:uppercase;display:flex;align-items:center;gap:10px; }
.modal-title i { color:var(--neon); }
.modal-close {
    width:36px;height:36px;border-radius:50%;background:rgba(255,53,83,.1);
    border:1.5px solid rgba(255,53,83,.25);color:var(--red);
    display:flex;align-items:center;justify-content:center;cursor:pointer;
    font-size:20px;transition:all .25s;
}
.modal-close:hover { background:var(--red);color:#fff;transform:rotate(90deg); }
.modal-body { padding:22px; }
.modal-foot { display:flex;gap:10px;justify-content:flex-end;padding:16px 22px;
    border-top:1px solid rgba(255,255,255,.05); }

/* Employee info strip inside modal */
.emp-strip {
    background:linear-gradient(135deg,rgba(50,190,143,.14),rgba(6,182,212,.09));
    border:1px solid var(--bord);border-radius:13px;padding:18px;margin-bottom:18px;
    position:relative;overflow:hidden;
}
.emp-strip::before { content:'';position:absolute;left:0;top:0;width:4px;height:100%;background:linear-gradient(180deg,var(--neon),var(--cyan)); }
.es-name { font-family:var(--fc);font-size:18px;font-weight:900;color:var(--text);margin-bottom:5px;letter-spacing:.5px; }
.es-meta { font-family:var(--fc);font-size:11px;font-weight:700;color:var(--text2);letter-spacing:.3px; }

/* Amount in modal */
.modal-amount-box {
    background:rgba(50,190,143,.07);border:2px solid rgba(50,190,143,.3);border-radius:13px;
    padding:20px;text-align:center;margin-bottom:18px;
}
.modal-amount-lbl { font-family:var(--fc);font-size:10px;font-weight:900;color:var(--muted);
    text-transform:uppercase;letter-spacing:2px;margin-bottom:8px; }
.modal-amount-val { font-family:var(--fc);font-size:38px;font-weight:900;color:var(--neon);letter-spacing:3px; }

/* Form fields */
.fg { display:flex;flex-direction:column;gap:6px;margin-bottom:16px; }
.fg label {
    font-family:var(--fc);font-size:10px;font-weight:900;color:var(--muted);
    text-transform:uppercase;letter-spacing:1.2px;
}
.fg select,.fg textarea,.fg input {
    padding:10px 14px;background:rgba(0,0,0,.35);border:1.5px solid var(--bord);
    border-radius:10px;color:var(--text);font-family:var(--fc);font-size:13px;font-weight:700;
    transition:all .28s;
}
.fg select:focus,.fg textarea:focus,.fg input:focus {
    outline:none;border-color:var(--neon);box-shadow:0 0 14px rgba(50,190,143,.18);
}
.fg select option { background:#1b263b;color:var(--text); }
.fg textarea { resize:vertical;min-height:68px; }

/* SIGNATURE PAD */
.sig-wrap {
    border:2px dashed rgba(50,190,143,.3);border-radius:13px;padding:10px;
    background:rgba(0,0,0,.2);margin-bottom:14px;
}
.sig-hint {
    font-family:var(--fc);font-size:10px;font-weight:900;color:var(--muted);
    text-align:center;letter-spacing:1px;text-transform:uppercase;margin-bottom:8px;
}
#signature-pad {
    width:100%;height:180px;border-radius:10px;
    background:#fff;cursor:crosshair;display:block;
}
.sig-actions { display:flex;gap:8px;margin-top:10px; }

/* ══ EMPTY ══ */
.empty-st { text-align:center;padding:52px 20px;color:var(--muted); }
.empty-st i { font-size:64px;display:block;margin-bottom:16px;opacity:.08; }
.empty-st h3 { font-family:var(--fc);font-size:18px;font-weight:900;color:var(--text2);margin-bottom:8px;letter-spacing:1px; }
.empty-st p  { font-family:var(--fc);font-size:12px;font-weight:700; }

/* ══ TOAST ══ */
.toast-stack { position:fixed;bottom:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:8px;align-items:flex-end; }
.toast {
    background:var(--card2);border:1px solid rgba(50,190,143,.25);border-radius:12px;
    padding:12px 17px;min-width:250px;display:flex;align-items:center;gap:11px;
    box-shadow:0 8px 28px rgba(0,0,0,.55);animation:toast-in .4s cubic-bezier(.23,1,.32,1);
}
.toast.out { animation:toast-out .3s ease forwards; }
.toast-ico { width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0; }
.toast-txt strong { font-family:var(--fc);font-size:12px;font-weight:900;display:block;letter-spacing:.5px; }
.toast-txt span   { font-family:var(--fc);font-size:11px;font-weight:700;color:var(--muted); }

/* ══ CONFIRM MODAL ══ */
#confirm-overlay {
    display:none;position:fixed;inset:0;background:rgba(0,0,0,.9);z-index:2000;
    align-items:center;justify-content:center;backdrop-filter:blur(12px);
}
#confirm-overlay.show { display:flex; }
.confirm-box {
    background:var(--card);border:1px solid rgba(50,190,143,.25);border-radius:18px;
    padding:30px;max-width:400px;width:92%;text-align:center;
    animation:zoomIn .25s cubic-bezier(.23,1,.32,1);
}
.confirm-ico { font-size:52px;margin-bottom:14px;display:block; }
.confirm-title { font-family:var(--fc);font-size:17px;font-weight:900;color:var(--neon);letter-spacing:1px;margin-bottom:10px; }
.confirm-body  { font-family:var(--fc);font-size:12px;font-weight:700;color:var(--text2);line-height:1.7;margin-bottom:20px; }
.confirm-btns  { display:flex;gap:10px;justify-content:center; }

/* ══ RESPONSIVE ══ */
@media(max-width:900px) { .pay-grid{ grid-template-columns:1fr; } }
@media(max-width:600px) {
    .topbar { padding:12px 14px; }
    .clock-val { font-size:20px; }
    .kpi-strip { grid-template-columns:1fr 1fr; }
    .tab-lnk { padding:7px 11px;font-size:10px; }
}
</style>
</head>
<body>

<div class="wrap">

<!-- ══════ TOPBAR ══════ -->
<div class="topbar">
    <div class="brand">
        <div class="brand-ico"><i class="fas fa-receipt"></i></div>
        <div class="brand-txt">
            <h1>CAISSE PAIEMENTS</h1>
            <p>TICKET THERMAL 80MM &nbsp;·&nbsp; ESPERANCE H2O</p>
        </div>
    </div>

    <div style="text-align:center;flex-shrink:0">
        <div class="clock-val" id="clk">--:--:--</div>
        <div class="clock-sub" id="clkd">Chargement…</div>
    </div>

    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end">
        <select class="month-sel" onchange="window.location.href='?month='+this.value+'&view=<?= $view ?>'">
            <?php for($i=0;$i<12;$i++):
                $mv = date('Y-m', strtotime("-$i months"));
            ?>
            <option value="<?= $mv ?>" <?= $current_month===$mv?'selected':'' ?>>
                <?= date('F Y', strtotime($mv.'-01')) ?>
            </option>
            <?php endfor; ?>
        </select>
        <a href="?export_payments=1&month=<?= $current_month ?>" class="btn btn-n btn-sm"><i class="fas fa-file-excel"></i> Excel</a>
        <a href="<?= project_url('hr/employees_manager.php') ?>" class="btn btn-b btn-sm"><i class="fas fa-users"></i> RH</a>
        <a href="<?= project_url('dashboard/admin_nasa.php') ?>" class="btn btn-p btn-sm"><i class="fas fa-rocket"></i> Admin</a>
    </div>
</div>

<!-- ══════ ALERTS ══════ -->
<?php if($success_parts): ?>
<div class="alert alert-success" id="flash-ok">
    <i class="fas fa-check-circle alert-ico"></i>
    <div>
        <div class="alert-main"><?= htmlspecialchars($success_parts[0]) ?></div>
        <?php foreach(array_slice($success_parts,1,2) as $p): ?>
        <span class="alert-pill"><?= htmlspecialchars($p) ?></span>
        <?php endforeach; ?>
        <?php if($new_tid): ?>
        <div style="margin-top:10px">
            <a href="?print_receipt=<?= $new_tid ?>" target="_blank" class="btn btn-g btn-sm">
                <i class="fas fa-print"></i> IMPRIMER LE TICKET
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if($error_parts): ?>
<div class="alert alert-error" id="flash-err">
    <i class="fas fa-times-circle alert-ico"></i>
    <div><div class="alert-main"><?= htmlspecialchars($error_parts[0]) ?></div></div>
</div>
<?php endif; ?>

<!-- ══════ TAB NAV ══════ -->
<div class="tab-nav">
    <a href="?view=cashier&month=<?= $current_month ?>" class="tab-lnk <?= $view==='cashier'?'active':'' ?>">
        <i class="fas fa-hand-holding-usd"></i> Caisse
        <?php if($count_unpaid > 0): ?><span class="tab-badge"><?= $count_unpaid ?></span><?php endif; ?>
    </a>
    <a href="?view=history&month=<?= $current_month ?>" class="tab-lnk <?= $view==='history'?'active':'' ?>">
        <i class="fas fa-history"></i> Historique
        <?php if($count_paid > 0): ?><span class="tab-badge" style="background:var(--neon);animation:none"><?= $count_paid ?></span><?php endif; ?>
    </a>
    <div style="margin-left:auto;display:flex;gap:6px">
        <a href="<?= project_url('hr/employees_manager.php') ?>?view=payroll" class="tab-lnk"><i class="fas fa-coins"></i> Paie</a>
        <a href="<?= project_url('admin/admin_notifications.php') ?>" class="tab-lnk"><i class="fas fa-bell"></i> Notifs</a>
    </div>
</div>

<!-- ══ KPI ══ -->
<div class="kpi-strip">
    <div class="ks" style="animation-delay:.04s">
        <div class="ks-ico" style="background:rgba(255,53,83,.12);color:var(--red)"><i class="fas fa-exclamation-circle"></i></div>
        <div><div class="ks-val" style="color:var(--red)"><?= $count_unpaid ?></div><div class="ks-lbl">À Payer</div></div>
    </div>
    <div class="ks" style="animation-delay:.07s">
        <div class="ks-ico" style="background:rgba(255,208,96,.12);color:var(--gold)"><i class="fas fa-money-bill-wave"></i></div>
        <div>
            <div class="ks-val" style="color:var(--gold)"><?= number_format($total_to_pay,0,'','') ?></div>
            <div class="ks-lbl">Restant FCFA</div>
        </div>
    </div>
    <div class="ks" style="animation-delay:.10s">
        <div class="ks-ico" style="background:rgba(50,190,143,.12);color:var(--neon)"><i class="fas fa-check-circle"></i></div>
        <div><div class="ks-val" style="color:var(--neon)"><?= $count_paid ?></div><div class="ks-lbl">Payés</div></div>
    </div>
    <div class="ks" style="animation-delay:.13s">
        <div class="ks-ico" style="background:rgba(6,182,212,.12);color:var(--cyan)"><i class="fas fa-coins"></i></div>
        <div>
            <div class="ks-val" style="color:var(--cyan)"><?= number_format($total_paid,0,'','') ?></div>
            <div class="ks-lbl">Total Payé FCFA</div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════
     VIEW : CAISSE
══════════════════════════════════════ -->
<?php if ($view === 'cashier'): ?>

<?php if(count($unpaid_payrolls) > 0): ?>
<div class="pay-grid">
<?php foreach($unpaid_payrolls as $i => $pr): ?>
<div class="pay-card" style="animation-delay:<?= $i*0.06 ?>s">
    <div class="pay-head">
        <div class="emp-name"><?= htmlspecialchars($pr['full_name']) ?></div>
        <div class="emp-code-tag"><?= htmlspecialchars($pr['employee_code']) ?></div>
        <div class="emp-meta" style="margin-top:8px">
            <i class="fas fa-briefcase"></i> <?= htmlspecialchars($pr['position_title']) ?>
            &nbsp;·&nbsp;
            <i class="fas fa-layer-group"></i> <?= htmlspecialchars($pr['category_name']) ?>
        </div>
    </div>

    <div class="amount-box">
        <div class="amount-lbl">Net à payer</div>
        <div class="amount-val"><?= number_format($pr['net_salary'],0,'','.')  ?> <span style="font-size:16px;color:var(--muted)">FCFA</span></div>
    </div>

    <div class="breakdown">
        <div class="brow">
            <span class="brow-lbl"><i class="fas fa-plus-circle" style="color:var(--neon);font-size:10px"></i> Salaire de base</span>
            <span class="brow-val" style="color:var(--neon)">+<?= number_format($pr['base_salary'],0,'','.')  ?></span>
        </div>
        <?php if ($pr['overtime_amount'] > 0): ?>
        <div class="brow">
            <span class="brow-lbl"><i class="fas fa-clock" style="color:var(--cyan);font-size:10px"></i> Heures sup</span>
            <span class="brow-val" style="color:var(--cyan)">+<?= number_format($pr['overtime_amount'],0,'','.') ?></span>
        </div>
        <?php endif; ?>
        <?php if ($pr['advances_deduction'] > 0): ?>
        <div class="brow">
            <span class="brow-lbl"><i class="fas fa-minus-circle" style="color:var(--red);font-size:10px"></i> Avances</span>
            <span class="brow-val" style="color:var(--red)">-<?= number_format($pr['advances_deduction'],0,'','.')  ?></span>
        </div>
        <?php endif; ?>
        <?php if ($pr['absences_deduction'] > 0): ?>
        <div class="brow">
            <span class="brow-lbl"><i class="fas fa-user-slash" style="color:var(--orange);font-size:10px"></i> Absences</span>
            <span class="brow-val" style="color:var(--orange)">-<?= number_format($pr['absences_deduction'],0,'','.')  ?></span>
        </div>
        <?php endif; ?>
    </div>

    <button class="btn-pay" onclick='openPayModal(<?= htmlspecialchars(json_encode($pr),ENT_QUOTES) ?>)'>
        <i class="fas fa-hand-holding-usd"></i> EFFECTUER LE PAIEMENT
    </button>
</div>
<?php endforeach; ?>
</div>

<?php else: ?>
<div class="card">
    <div class="empty-st">
        <i class="fas fa-check-double"></i>
        <h3>TOUS LES PAIEMENTS EFFECTUÉS !</h3>
        <p>Aucun employé en attente pour <?= date('F Y', strtotime($current_month.'-01')) ?></p>
    </div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════
     VIEW : HISTORIQUE
══════════════════════════════════════ -->
<?php elseif ($view === 'history'): ?>

<div class="card">
    <div class="card-head">
        <div class="card-title"><i class="fas fa-history"></i> Historique des Paiements — <?= date('F Y', strtotime($current_month.'-01')) ?></div>
        <a href="?export_payments=1&month=<?= $current_month ?>" class="btn btn-n btn-sm"><i class="fas fa-file-excel"></i> Export Excel</a>
    </div>
    <?php if(count($payment_history) > 0): ?>
    <div class="tbl-wrap">
    <table>
        <thead>
            <tr><th>N° Reçu</th><th>Date / Heure</th><th>Employé</th><th>Montant</th><th>Mode</th><th>Payé par</th><th>Ticket</th></tr>
        </thead>
        <tbody>
        <?php
        $pm_labels = [
            'especes'      => ['bdg-n','💵 Espèces'],
            'mobile_money' => ['bdg-c','📱 Mobile Money'],
            'virement'     => ['bdg-g','🏦 Virement'],
            'cheque'       => ['bdg-p','📄 Chèque'],
        ];
        ?>
        <?php foreach($payment_history as $pt): ?>
        <?php $bl = $pm_labels[$pt['payment_method']] ?? ['bdg-n',$pt['payment_method']]; ?>
        <tr>
            <td><span class="receipt-no"><?= htmlspecialchars($pt['receipt_number']) ?></span></td>
            <td style="font-size:12px;font-weight:700;color:var(--text2)"><?= date('d/m/Y H:i',strtotime($pt['paid_at'])) ?></td>
            <td>
                <div class="emp-nm"><?= htmlspecialchars($pt['full_name']) ?></div>
                <div class="emp-code"><?= htmlspecialchars($pt['employee_code']) ?></div>
            </td>
            <td>
                <span style="font-family:var(--fc);font-size:16px;font-weight:900;color:var(--neon)">
                    <?= number_format($pt['amount'],0,'','.')  ?>
                </span>
                <span style="font-size:10px;color:var(--muted);font-weight:700"> FCFA</span>
            </td>
            <td><span class="bdg <?= $bl[0] ?>"><?= $bl[1] ?></span></td>
            <td style="font-weight:900;color:var(--text2)"><?= htmlspecialchars($pt['paid_by_name']) ?></td>
            <td>
                <a href="?print_receipt=<?= $pt['id'] ?>" target="_blank" class="btn btn-g btn-sm">
                    <i class="fas fa-receipt"></i> TICKET
                </a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php else: ?>
    <div class="empty-st"><i class="fas fa-inbox"></i><h3>AUCUN PAIEMENT</h3><p>Aucun paiement effectué ce mois</p></div>
    <?php endif; ?>
</div>

<?php endif; ?>
</div><!-- /wrap -->

<!-- ══════════════════════════════════════
     MODAL PAIEMENT
══════════════════════════════════════ -->
<div class="modal" id="payModal">
<div class="modal-box">
    <div class="modal-head">
        <div class="modal-title"><i class="fas fa-hand-holding-usd"></i> Effectuer le Paiement</div>
        <div class="modal-close" onclick="closePayModal()">×</div>
    </div>

    <form method="POST" id="payForm">
    <input type="hidden" name="action"      value="process_payment">
    <input type="hidden" name="csrf_token"  value="<?= $csrf ?>">
    <input type="hidden" name="payroll_id"  id="f-payroll-id">
    <input type="hidden" name="employee_id" id="f-employee-id">
    <input type="hidden" name="amount"      id="f-amount">
    <input type="hidden" name="signature"   id="f-signature">

    <div class="modal-body">
        <!-- Employé info -->
        <div class="emp-strip">
            <div class="es-name" id="m-emp-name"></div>
            <div class="es-meta" id="m-emp-meta"></div>
        </div>

        <!-- Montant -->
        <div class="modal-amount-box">
            <div class="modal-amount-lbl">MONTANT À PAYER</div>
            <div class="modal-amount-val" id="m-amount"></div>
        </div>

        <!-- Mode -->
        <div class="fg">
            <label><i class="fas fa-credit-card"></i> Mode de Paiement *</label>
            <select name="payment_method" required>
                <option value="especes">💵 Espèces (Cash)</option>
                <option value="mobile_money">📱 Mobile Money (Orange / MTN / Moov)</option>
                <option value="virement">🏦 Virement Bancaire</option>
                <option value="cheque">📄 Chèque</option>
            </select>
        </div>

        <!-- Notes -->
        <div class="fg">
            <label><i class="fas fa-sticky-note"></i> Notes (optionnel)</label>
            <textarea name="notes" placeholder="Remarques supplémentaires…"></textarea>
        </div>

        <!-- Signature -->
        <div class="fg">
            <label><i class="fas fa-signature"></i> Signature Employé * — Visible sur le ticket</label>
            <div class="sig-wrap">
                <div class="sig-hint">✍ Signer dans la zone ci-dessous</div>
                <canvas id="signature-pad"></canvas>
                <div class="sig-actions">
                    <button type="button" class="btn btn-r btn-sm" style="flex:1" onclick="clearSig()">
                        <i class="fas fa-eraser"></i> Effacer
                    </button>
                </div>
            </div>
            <span style="font-family:var(--fc);font-size:10px;font-weight:700;color:var(--muted)">
                <i class="fas fa-info-circle" style="color:var(--cyan)"></i>
                La signature apparaîtra sur le ticket de caisse thermique 80mm
            </span>
        </div>
    </div>

    <div class="modal-foot">
        <button type="button" class="btn btn-r" onclick="closePayModal()"><i class="fas fa-times"></i> Annuler</button>
        <button type="submit" class="btn btn-n"><i class="fas fa-check-double"></i> CONFIRMER LE PAIEMENT</button>
    </div>
    </form>
</div>
</div>

<!-- CONFIRM OVERLAY (dark neon) -->
<div id="confirm-overlay">
<div class="confirm-box">
    <span class="confirm-ico">💸</span>
    <div class="confirm-title" id="conf-title"></div>
    <div class="confirm-body"  id="conf-body"></div>
    <div class="confirm-btns">
        <button id="conf-ok" class="btn btn-n"><i class="fas fa-check"></i> OUI, PAYER</button>
        <button onclick="document.getElementById('confirm-overlay').classList.remove('show')" class="btn btn-r"><i class="fas fa-times"></i> Annuler</button>
    </div>
</div>
</div>

<!-- TOAST STACK -->
<div class="toast-stack" id="toast-stack"></div>

<script>
/* ── HORLOGE ── */
function tick() {
    const n = new Date();
    const e = document.getElementById('clk'), d = document.getElementById('clkd');
    if (e) e.textContent = n.toLocaleTimeString('fr-FR',{timeZone:'Africa/Abidjan',hour:'2-digit',minute:'2-digit',second:'2-digit'});
    if (d) d.textContent = n.toLocaleDateString('fr-FR',{timeZone:'Africa/Abidjan',weekday:'long',day:'numeric',month:'long',year:'numeric'});
}
tick(); setInterval(tick,1000);

/* ── TOAST ── */
function toast(msg,type='info',sub=''){
    const c={success:'var(--neon)',error:'var(--red)',info:'var(--cyan)',warn:'var(--gold)'};
    const ic={success:'fa-check-circle',error:'fa-times-circle',info:'fa-info-circle',warn:'fa-exclamation-triangle'};
    const stack=document.getElementById('toast-stack'); if(!stack)return;
    const t=document.createElement('div'); t.className='toast';
    t.innerHTML=`<div class="toast-ico" style="background:${c[type]}22;color:${c[type]}"><i class="fas ${ic[type]}"></i></div>
        <div class="toast-txt"><strong style="color:${c[type]}">${msg}</strong>${sub?`<span>${sub}</span>`:''}</div>`;
    stack.appendChild(t);
    setTimeout(()=>{t.classList.add('out');setTimeout(()=>t.remove(),350);},4500);
}

/* ── SIGNATURE PAD ── */
let sigPad = null;
function initSig(){
    const canvas=document.getElementById('signature-pad');
    sigPad=new SignaturePad(canvas,{backgroundColor:'rgb(255,255,255)',penColor:'rgb(4,9,14)',minWidth:1.5,maxWidth:3});
    function resize(){
        const r=Math.max(window.devicePixelRatio||1,1);
        canvas.width=canvas.offsetWidth*r; canvas.height=canvas.offsetHeight*r;
        canvas.getContext('2d').scale(r,r); sigPad.clear();
    }
    window.addEventListener('resize',resize); resize();
}
function clearSig(){ if(sigPad) sigPad.clear(); }

/* ── MODAL ── */
function openPayModal(pr){
    document.getElementById('f-payroll-id').value  = pr.id;
    document.getElementById('f-employee-id').value = pr.employee_id;
    document.getElementById('f-amount').value       = pr.net_salary;
    document.getElementById('m-emp-name').textContent = pr.full_name;
    document.getElementById('m-emp-meta').innerHTML =
        `<i class="fas fa-id-card"></i> ${pr.employee_code} &nbsp;·&nbsp; <i class="fas fa-briefcase"></i> ${pr.position_title} &nbsp;·&nbsp; <i class="fas fa-layer-group"></i> ${pr.category_name}`;
    document.getElementById('m-amount').textContent =
        new Intl.NumberFormat('fr-FR').format(pr.net_salary) + ' FCFA';
    document.getElementById('payModal').classList.add('show');
    document.body.style.overflow='hidden';
    if(!sigPad) initSig(); else sigPad.clear();
}
function closePayModal(){
    document.getElementById('payModal').classList.remove('show');
    document.body.style.overflow='';
    document.getElementById('payForm').reset();
    if(sigPad) sigPad.clear();
}

/* ── FORM SUBMIT ── */
document.getElementById('payForm').addEventListener('submit',function(e){
    e.preventDefault();
    if(!sigPad || sigPad.isEmpty()){
        toast('Signature requise !','warn','L\'employé doit signer pour valider la réception');
        return;
    }
    document.getElementById('f-signature').value = sigPad.toDataURL();
    const amount = document.getElementById('m-amount').textContent;
    const name   = document.getElementById('m-emp-name').textContent;
    const overlay= document.getElementById('confirm-overlay');
    document.getElementById('conf-title').textContent = 'Confirmer le paiement ?';
    document.getElementById('conf-body').innerHTML =
        `Vous allez verser <strong style="color:var(--neon)">${amount}</strong><br>à <strong style="color:var(--text)">${name}</strong><br><br>
         <span style="font-size:11px;color:var(--muted)">Un ticket avec signature sera généré</span>`;
    document.getElementById('conf-ok').onclick=()=>{
        overlay.classList.remove('show');
        this.submit();
    };
    overlay.classList.add('show');
});

/* ── CLOSE on ESC / outside ── */
document.addEventListener('keydown',e=>{
    if(e.key==='Escape'){
        closePayModal();
        document.getElementById('confirm-overlay').classList.remove('show');
    }
});
document.getElementById('payModal').addEventListener('click',e=>{
    if(e.target===document.getElementById('payModal')) closePayModal();
});

/* ── INIT ── */
window.addEventListener('DOMContentLoaded',()=>{
    <?php if($success_parts): ?>toast("<?= htmlspecialchars(addslashes($success_parts[0])) ?>","success","<?= !empty($success_parts[1])?htmlspecialchars(addslashes($success_parts[1])):'' ?>");<?php endif; ?>
    <?php if($error_parts):   ?>toast("<?= htmlspecialchars(addslashes($error_parts[0]))   ?>","error");<?php endif; ?>
    setTimeout(()=>{['flash-ok','flash-err'].forEach(id=>{const el=document.getElementById(id);if(el){el.style.transition='opacity .5s,transform .5s';el.style.opacity='0';el.style.transform='translateX(40px)';setTimeout(()=>el.remove(),500);}});},7000);
});

console.log('%c ESPERANCE H2O // CAISSE TICKET NEON v3.0 ','background:#04090e;color:#32be8f;font-family:"Courier New";padding:6px;border:1px solid #32be8f');
</script>
</body>
</html>
