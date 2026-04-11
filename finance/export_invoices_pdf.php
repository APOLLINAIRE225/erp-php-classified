<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

session_start();

/* MODE ENTREPRISE */
ini_set('display_errors', 0);
error_reporting(E_ALL);
ob_start();

/* =========================
   Dépendances
========================= */
require_once APP_ROOT . '/app/core/DB.php';
require_once APP_ROOT . '/app/core/Auth.php';
require_once APP_ROOT . '/app/core/Middleware.php';
require_once APP_ROOT . '/vendor/autoload.php';
require_once APP_ROOT . '/fpdf186/fpdf.php';

use App\Core\DB;
use App\Core\Auth;
use App\Core\Middleware;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/* =========================
   Sécurité
========================= */
Auth::check();
Middleware::role(['developer','admin','manager']);

$pdo  = DB::getConnection();
$user = $_SESSION['user'] ?? 'Système';

/* =========================
   Filtres
========================= */
$company_id = (int)($_GET['company_id'] ?? 0);
$city_id    = (int)($_GET['city_id'] ?? 0);
$search     = trim($_GET['search'] ?? '');
$date_start = $_GET['date_start'] ?? '';
$date_end   = $_GET['date_end'] ?? '';
$export     = $_GET['export'] ?? 'pdf';

/* =========================
   WHERE dynamique
========================= */
$where  = [];
$params = [];

if ($company_id > 0) {
    $where[] = "i.company_id = ?";
    $params[] = $company_id;
}

if ($city_id > 0) {
    $where[] = "i.city_id = ?";
    $params[] = $city_id;
}

if ($date_start && $date_end) {
    $where[] = "DATE(i.created_at) BETWEEN ? AND ?";
    $params[] = $date_start;
    $params[] = $date_end;
}

if ($search !== '') {
    $where[] = "(c.name LIKE ? OR i.id LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$whereSQL = $where ? 'WHERE '.implode(' AND ', $where) : '';

/* =========================
   Données
========================= */
$sql = "
SELECT 
    i.id,
    i.total,
    i.status,
    i.created_at,
    c.name  AS client,
    co.name AS company,
    ci.name AS city
FROM invoices i
JOIN clients c ON c.id=i.client_id
JOIN companies co ON co.id=i.company_id
JOIN cities ci ON ci.id=i.city_id
$whereSQL
ORDER BY i.created_at DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$data) {
    ob_end_clean();
    die('Aucune donnée');
}

/* =====================================================
   EXPORT EXCEL
===================================================== */
if ($export === 'excel') {

    $sheet = new Spreadsheet();
    $ws = $sheet->getActiveSheet();

    $headers = ['ID','Client','Entreprise','Ville','Total','Statut','Date'];
    $ws->fromArray($headers,null,'A1');
    $ws->fromArray($data,null,'A2');

    $writer = new Xlsx($sheet);

    ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="factures.xlsx"');
    $writer->save('php://output');
    exit;
}

/* =====================================================
   PDF ENTERPRISE
===================================================== */

class PDF extends FPDF {
    function Header() {
        if (file_exists(APP_ROOT . '/assets/logo.png')) {
            $this->Image(APP_ROOT . '/assets/logo.png',10,8,30);
        }
        $this->SetFont('Arial','B',14);
        $this->Cell(0,10,'RAPPORT DES FACTURES',0,1,'C');
        $this->Ln(5);
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial','I',8);
        $this->Cell(0,10,'Page '.$this->PageNo().'/{nb}',0,0,'C');
    }
}

$pdf = new PDF('L','mm','A4');
$pdf->AliasNbPages();
$pdf->AddPage();

/* Infos */
$pdf->SetFont('Arial','',10);
$pdf->Cell(0,8,"Exporté par : $user | ".date('d/m/Y H:i'),0,1);
$pdf->Ln(4);

/* Table */
$pdf->SetFillColor(20,184,166);
$pdf->SetTextColor(255,255,255);
$pdf->SetFont('Arial','B',10);

$cols = [
    ['ID',20],['Client',50],['Entreprise',45],
    ['Ville',35],['Total CFA',30],['Statut',30],['Date',40]
];

foreach ($cols as $c) {
    $pdf->Cell($c[1],8,$c[0],1,0,'C',true);
}
$pdf->Ln();

$pdf->SetFont('Arial','',10);
$pdf->SetTextColor(0,0,0);

$total = 0;

foreach ($data as $row) {
    $pdf->Cell(20,7,$row['id'],1);
    $pdf->Cell(50,7,$row['client'],1);
    $pdf->Cell(45,7,$row['company'],1);
    $pdf->Cell(35,7,$row['city'],1);
    $pdf->Cell(30,7,number_format($row['total'],0,' ',' '),1,0,'R');
    $pdf->Cell(30,7,$row['status'],1,0,'C');
    $pdf->Cell(40,7,date('d/m/Y H:i',strtotime($row['created_at'])),1,1);
    $total += $row['total'];
}

/* Totaux */
$pdf->Ln(5);
$pdf->SetFont('Arial','B',11);
$pdf->Cell(150,8,'TOTAL GÉNÉRAL',1);
$pdf->Cell(40,8,number_format($total,0,' ',' ')." CFA",1,1,'R');

ob_end_clean();
$pdf->Output('I','factures_enterprise.pdf');
exit;
